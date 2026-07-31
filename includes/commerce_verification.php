<?php
/**
 * Commerce receipt verification (Phase 6).
 *
 * Runs OCR → extract → rule evaluation for pending GCash payments.
 * On auto_verified: sets status=paid, paid_at=NOW(), verification_status=auto_verified.
 * Leaves fulfilled_at NULL. NEVER touches access_grants, SCA, activate_user, or fulfillment.
 */

declare(strict_types=1);

require_once __DIR__ . '/commerce_catalog.php';
require_once __DIR__ . '/commerce_payment.php';
require_once __DIR__ . '/commerce_ocr.php';
require_once __DIR__ . '/commerce_ocr_extract.php';
require_once __DIR__ . '/commerce_fulfillment.php';

/** Max verification attempt rows before forcing needs_review. */
const COMMERCE_VERIFICATION_MAX_ATTEMPTS = 8;

/** Re-claim stuck processing rows older than this many minutes. */
const COMMERCE_VERIFICATION_PROCESSING_STUCK_MINUTES = 15;

/** Allow receipt paid_at up to this many minutes in the future (clock skew). */
const COMMERCE_VERIFICATION_FUTURE_SKEW_MINUTES = 5;

/**
 * Build a short human-readable verification summary (max 500 chars).
 *
 * @param list<string> $reasons
 */
function commerce_verification_build_summary(string $decision, array $reasons): string
{
    $parts = [$decision];
    foreach ($reasons as $r) {
        $r = trim((string) $r);
        if ($r !== '') {
            $parts[] = $r;
        }
    }
    $summary = implode('; ', $parts);
    if (strlen($summary) > 500) {
        $summary = substr($summary, 0, 497) . '...';
    }
    return $summary;
}

/**
 * Resolve proof absolute path; must remain under uploads/payment_proofs.
 *
 * @return array{ok:bool,path?:string,error?:string}
 */
function commerce_verification_resolve_proof_path(string $relPath): array
{
    $relPath = str_replace('\\', '/', trim($relPath));
    if ($relPath === '' || strpos($relPath, '..') !== false) {
        return ['ok' => false, 'error' => 'invalid_proof_path'];
    }
    $prefix = COMMERCE_PROOF_DIR_REL . '/';
    if (strpos($relPath, $prefix) !== 0) {
        return ['ok' => false, 'error' => 'proof_outside_dir'];
    }

    $root = dirname(__DIR__);
    $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    $realFile = realpath($abs);
    $realDir = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, COMMERCE_PROOF_DIR_REL));
    if ($realFile === false || $realDir === false) {
        return ['ok' => false, 'error' => 'proof_missing'];
    }
    $dirPrefix = $realDir . DIRECTORY_SEPARATOR;
    if (strpos($realFile, $dirPrefix) !== 0 && $realFile !== $realDir) {
        return ['ok' => false, 'error' => 'proof_outside_dir'];
    }
    if (!is_file($realFile) || !is_readable($realFile)) {
        return ['ok' => false, 'error' => 'proof_unreadable'];
    }
    return ['ok' => true, 'path' => $realFile];
}

/**
 * Count prior verification attempts for a payment.
 */
function commerce_verification_attempt_count(mysqli $conn, int $paymentId): int
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS c FROM payment_verification_attempts WHERE payment_id = ?'
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $paymentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (int) ($row['c'] ?? 0);
}

/**
 * Claim payment for verification processing.
 *
 * @param array{force?:bool,allow_stuck?:bool} $opts
 * @return array{ok:bool,error?:string,skipped?:bool,decision?:string,payment?:array<string,mixed>}
 */
function commerce_verification_claim(mysqli $conn, array $payment, array $opts = []): array
{
    $paymentId = (int) ($payment['payment_id'] ?? 0);
    $force = !empty($opts['force']);
    $status = (string) ($payment['status'] ?? '');
    $vStatus = (string) ($payment['verification_status'] ?? '');
    $stuckMinutes = COMMERCE_VERIFICATION_PROCESSING_STUCK_MINUTES;

    // Already auto_verified — idempotent skip unless force
    if ($vStatus === 'auto_verified' && !$force) {
        return [
            'ok' => true,
            'skipped' => true,
            'decision' => 'skipped',
            'error' => 'skipped_idempotent',
            'payment' => $payment,
        ];
    }

    // needs_review without force
    if ($vStatus === 'needs_review' && !$force) {
        return [
            'ok' => true,
            'skipped' => true,
            'decision' => 'skipped',
            'error' => 'needs_review_no_force',
            'payment' => $payment,
        ];
    }

    // manually_* — never auto-claim
    if (in_array($vStatus, ['manually_approved', 'manually_rejected'], true) && !$force) {
        return [
            'ok' => true,
            'skipped' => true,
            'decision' => 'skipped',
            'error' => 'manual_decision_locked',
            'payment' => $payment,
        ];
    }

    // Normal path requires pending_verification (force may re-run paid/auto_verified)
    if ($status !== 'pending_verification' && !($force && in_array($status, ['pending_verification', 'paid'], true))) {
        return [
            'ok' => false,
            'error' => 'payment_not_pending',
            'decision' => 'skipped',
            'payment' => $payment,
        ];
    }

    // processing: only if stuck
    if ($vStatus === 'processing' && !$force) {
        $updated = strtotime((string) ($payment['updated_at'] ?? ''));
        $stuckAfter = time() - ($stuckMinutes * 60);
        if ($updated === false || $updated > $stuckAfter) {
            return [
                'ok' => true,
                'skipped' => true,
                'decision' => 'skipped',
                'error' => 'processing_in_progress',
                'payment' => $payment,
            ];
        }
    }

    // Claim rows that are claimable
    if ($force) {
        $sql = "UPDATE payments
                SET verification_status = 'processing'
                WHERE payment_id = ?
                  AND status IN ('pending_verification', 'paid')
                  AND verification_status IN (
                    'not_started','failed','needs_review','processing','auto_verified'
                  )
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => 'claim_prepare_failed'];
        }
        mysqli_stmt_bind_param($stmt, 'i', $paymentId);
    } else {
        // not_started / failed always; stuck processing by updated_at
        $sql = "UPDATE payments
                SET verification_status = 'processing'
                WHERE payment_id = ?
                  AND status = 'pending_verification'
                  AND (
                    verification_status IN ('not_started', 'failed')
                    OR (
                      verification_status = 'processing'
                      AND updated_at < (NOW() - INTERVAL ? MINUTE)
                    )
                  )
                LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => 'claim_prepare_failed'];
        }
        mysqli_stmt_bind_param($stmt, 'ii', $paymentId, $stuckMinutes);
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'claim_execute_failed'];
    }
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($affected < 1) {
        $fresh = commerce_get_payment($conn, $paymentId);
        return [
            'ok' => true,
            'skipped' => true,
            'decision' => 'skipped',
            'error' => 'claim_lost_race',
            'payment' => $fresh ?: $payment,
        ];
    }

    $fresh = commerce_get_payment($conn, $paymentId);
    return ['ok' => true, 'skipped' => false, 'payment' => $fresh ?: $payment];
}

/**
 * Persist verification attempt + payment OCR/match columns + decision status.
 *
 * @param array<string,mixed> $payment
 * @param array<string,mixed> $ocr
 * @param array<string,mixed> $extracted
 * @param array<string,bool|null> $matches
 * @param list<string> $flags
 * @param list<string> $reasons
 * @return array{ok:bool,decision:string,error?:string,payment?:array<string,mixed>}
 */
function commerce_verification_persist(
    mysqli $conn,
    array $payment,
    string $decision,
    array $ocr,
    array $extracted,
    array $matches,
    array $flags,
    array $reasons,
    float $confidence
): array {
    $paymentId = (int) $payment['payment_id'];
    $engine = (string) ($ocr['engine'] ?? 'none');
    $rawText = (string) ($ocr['raw_text'] ?? '');
    $extractedJson = json_encode($extracted, JSON_UNESCAPED_UNICODE);
    if ($extractedJson === false) {
        $extractedJson = '{}';
    }
    $reasonsJson = json_encode($reasons, JSON_UNESCAPED_UNICODE);
    if ($reasonsJson === false) {
        $reasonsJson = '[]';
    }
    $flagsJson = json_encode(array_values(array_unique($flags)), JSON_UNESCAPED_UNICODE);
    if ($flagsJson === false) {
        $flagsJson = '[]';
    }
    $summary = commerce_verification_build_summary($decision, $reasons);

    // Nullable detected fields — bind as strings so SQL NULL is preserved cleanly.
    $detAmountSql = $extracted['amount_centavos'] !== null ? (string) (int) $extracted['amount_centavos'] : null;
    $detRef = $extracted['reference_raw'] !== null ? (string) $extracted['reference_raw'] : null;
    $detPaidAt = $extracted['paid_at'] !== null ? (string) $extracted['paid_at'] : null;
    $detRecipient = $extracted['recipient'] !== null ? (string) $extracted['recipient'] : null;

    $mAmount = !empty($matches['matched_amount']) ? 1 : 0;
    $mRef = !empty($matches['matched_reference']) ? 1 : 0;
    $mRecip = !empty($matches['matched_recipient']) ? 1 : 0;
    $mSuccess = !empty($matches['matched_success_text']) ? 1 : 0;
    $mDt = !empty($matches['matched_datetime_ok']) ? 1 : 0;

    mysqli_begin_transaction($conn);
    try {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO payment_verification_attempts
              (payment_id, engine, confidence, raw_text, extracted_json, decision, decision_reasons_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$ins) {
            throw new RuntimeException('attempt_insert_prepare_failed');
        }
        mysqli_stmt_bind_param(
            $ins,
            'isdssss',
            $paymentId,
            $engine,
            $confidence,
            $rawText,
            $extractedJson,
            $decision,
            $reasonsJson
        );
        if (!mysqli_stmt_execute($ins)) {
            mysqli_stmt_close($ins);
            throw new RuntimeException('attempt_insert_failed');
        }
        mysqli_stmt_close($ins);

        if ($decision === 'auto_verified') {
            // paid_at = NOW(); fulfilled_at left unchanged (NULL). No grants/SCA.
            $upd = mysqli_prepare(
                $conn,
                "UPDATE payments SET
                    status = 'paid',
                    paid_at = IFNULL(paid_at, NOW()),
                    verification_status = 'auto_verified',
                    verification_confidence = ?,
                    verification_summary = ?,
                    ocr_engine = ?,
                    ocr_raw_text = ?,
                    ocr_extracted_json = ?,
                    detected_amount_centavos = ?,
                    detected_reference = ?,
                    detected_paid_at = ?,
                    detected_recipient = ?,
                    matched_amount = ?,
                    matched_reference = ?,
                    matched_recipient = ?,
                    matched_success_text = ?,
                    matched_datetime_ok = ?,
                    suspicious_flags_json = ?
                 WHERE payment_id = ?
                 LIMIT 1"
            );
            if (!$upd) {
                throw new RuntimeException('payment_update_prepare_failed');
            }
            // types: d s s s s s s s s i i i i i s i  (amount as string → INT cast by MySQL)
            mysqli_stmt_bind_param(
                $upd,
                'dssssssssiiiiisi',
                $confidence,
                $summary,
                $engine,
                $rawText,
                $extractedJson,
                $detAmountSql,
                $detRef,
                $detPaidAt,
                $detRecipient,
                $mAmount,
                $mRef,
                $mRecip,
                $mSuccess,
                $mDt,
                $flagsJson,
                $paymentId
            );
        } elseif ($decision === 'failed') {
            $upd = mysqli_prepare(
                $conn,
                "UPDATE payments SET
                    verification_status = 'failed',
                    verification_confidence = ?,
                    verification_summary = ?,
                    ocr_engine = ?,
                    ocr_raw_text = ?,
                    ocr_extracted_json = ?,
                    detected_amount_centavos = ?,
                    detected_reference = ?,
                    detected_paid_at = ?,
                    detected_recipient = ?,
                    matched_amount = ?,
                    matched_reference = ?,
                    matched_recipient = ?,
                    matched_success_text = ?,
                    matched_datetime_ok = ?,
                    suspicious_flags_json = ?
                 WHERE payment_id = ?
                   AND status = 'pending_verification'
                 LIMIT 1"
            );
            if (!$upd) {
                throw new RuntimeException('payment_update_prepare_failed');
            }
            mysqli_stmt_bind_param(
                $upd,
                'dssssssssiiiiisi',
                $confidence,
                $summary,
                $engine,
                $rawText,
                $extractedJson,
                $detAmountSql,
                $detRef,
                $detPaidAt,
                $detRecipient,
                $mAmount,
                $mRef,
                $mRecip,
                $mSuccess,
                $mDt,
                $flagsJson,
                $paymentId
            );
        } else {
            // needs_review — status stays pending_verification (or paid if force re-run)
            $upd = mysqli_prepare(
                $conn,
                "UPDATE payments SET
                    verification_status = 'needs_review',
                    verification_confidence = ?,
                    verification_summary = ?,
                    ocr_engine = ?,
                    ocr_raw_text = ?,
                    ocr_extracted_json = ?,
                    detected_amount_centavos = ?,
                    detected_reference = ?,
                    detected_paid_at = ?,
                    detected_recipient = ?,
                    matched_amount = ?,
                    matched_reference = ?,
                    matched_recipient = ?,
                    matched_success_text = ?,
                    matched_datetime_ok = ?,
                    suspicious_flags_json = ?
                 WHERE payment_id = ?
                   AND status IN ('pending_verification', 'paid')
                 LIMIT 1"
            );
            if (!$upd) {
                throw new RuntimeException('payment_update_prepare_failed');
            }
            mysqli_stmt_bind_param(
                $upd,
                'dssssssssiiiiisi',
                $confidence,
                $summary,
                $engine,
                $rawText,
                $extractedJson,
                $detAmountSql,
                $detRef,
                $detPaidAt,
                $detRecipient,
                $mAmount,
                $mRef,
                $mRecip,
                $mSuccess,
                $mDt,
                $flagsJson,
                $paymentId
            );
        }

        if (!mysqli_stmt_execute($upd)) {
            $err = mysqli_error($conn);
            mysqli_stmt_close($upd);
            throw new RuntimeException('payment_update_failed: ' . $err);
        }
        mysqli_stmt_close($upd);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('commerce_verification_persist: ' . $e->getMessage());
        // Best-effort: release claim back to failed so worker can retry
        $pid = $paymentId;
        $failSummary = commerce_verification_build_summary('failed', ['persist_error']);
        $rel = mysqli_prepare(
            $conn,
            "UPDATE payments SET verification_status = 'failed', verification_summary = ?
             WHERE payment_id = ? AND verification_status = 'processing' LIMIT 1"
        );
        if ($rel) {
            mysqli_stmt_bind_param($rel, 'si', $failSummary, $pid);
            mysqli_stmt_execute($rel);
            mysqli_stmt_close($rel);
        }
        return ['ok' => false, 'decision' => 'failed', 'error' => 'persist_failed'];
    }

    $fresh = commerce_get_payment($conn, $paymentId);
    return [
        'ok' => true,
        'decision' => $decision,
        'payment' => $fresh ?: $payment,
    ];
}

/**
 * Core verification pipeline for one payment.
 *
 * @param array{force?:bool} $opts
 * @return array{ok:bool,decision:string,error?:string,payment?:array<string,mixed>}
 */
function commerce_verify_payment(mysqli $conn, int $paymentId, array $opts = []): array
{
    if ($paymentId <= 0) {
        return ['ok' => false, 'decision' => 'failed', 'error' => 'invalid_payment_id'];
    }

    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment) {
        return ['ok' => false, 'decision' => 'failed', 'error' => 'payment_not_found'];
    }

    $purchaseType = (string) ($payment['purchase_type'] ?? '');
    if (!in_array($purchaseType, ['package', 'by_topic'], true)) {
        return [
            'ok' => false,
            'decision' => 'skipped',
            'error' => 'unsupported_purchase_type',
            'payment' => $payment,
        ];
    }

    // Idempotent: already auto_verified with same proof
    $force = !empty($opts['force']);
    if (
        (string) ($payment['verification_status'] ?? '') === 'auto_verified'
        && !$force
        && !empty($payment['proof_path'])
    ) {
        return [
            'ok' => true,
            'decision' => 'skipped',
            'error' => 'skipped_idempotent',
            'payment' => $payment,
        ];
    }

    $attemptCount = commerce_verification_attempt_count($conn, $paymentId);
    if ($attemptCount >= COMMERCE_VERIFICATION_MAX_ATTEMPTS && !$force) {
        // Cap retries — leave/push to needs_review without another OCR burn if already there
        if ((string) ($payment['verification_status'] ?? '') !== 'needs_review') {
            $sum = commerce_verification_build_summary('needs_review', ['max_attempts_exceeded']);
            $st = mysqli_prepare(
                $conn,
                "UPDATE payments SET verification_status = 'needs_review', verification_summary = ?
                 WHERE payment_id = ? AND status = 'pending_verification' LIMIT 1"
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'si', $sum, $paymentId);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
            }
            $payment = commerce_get_payment($conn, $paymentId) ?: $payment;
        }
        return [
            'ok' => true,
            'decision' => 'needs_review',
            'error' => 'max_attempts_exceeded',
            'payment' => $payment,
        ];
    }

    $claim = commerce_verification_claim($conn, $payment, $opts);
    if (!empty($claim['skipped'])) {
        return [
            'ok' => true,
            'decision' => (string) ($claim['decision'] ?? 'skipped'),
            'error' => $claim['error'] ?? 'skipped',
            'payment' => $claim['payment'] ?? $payment,
        ];
    }
    if (empty($claim['ok'])) {
        return [
            'ok' => false,
            'decision' => 'failed',
            'error' => $claim['error'] ?? 'claim_failed',
            'payment' => $payment,
        ];
    }
    $payment = $claim['payment'] ?? $payment;

    $settings = commerce_get_payment_settings($conn);
    $threshold = (float) ($settings['ocr_confidence_threshold'] ?? 85.0);
    $maxAgeDays = (int) ($settings['receipt_max_age_days'] ?? 7);
    if ($maxAgeDays < 1) {
        $maxAgeDays = 7;
    }
    $visionEnabled = !empty($settings['vision_fallback_enabled']);

    $proofRel = (string) ($payment['proof_path'] ?? '');
    $proofMime = (string) ($payment['proof_mime'] ?? '');
    $resolved = commerce_verification_resolve_proof_path($proofRel);
    if (empty($resolved['ok'])) {
        $ocr = [
            'ok' => false,
            'engine' => 'none',
            'raw_text' => '',
            'confidence' => 0.0,
            'error' => $resolved['error'] ?? 'proof_missing',
        ];
        $extracted = commerce_ocr_extract_from_text('');
        $flags = ['proof_unreadable'];
        $reasons = ['proof_path_invalid:' . ($resolved['error'] ?? 'unknown')];
        return commerce_verification_persist(
            $conn,
            $payment,
            'failed',
            $ocr,
            $extracted,
            [
                'matched_amount' => false,
                'matched_reference' => false,
                'matched_recipient' => false,
                'matched_success_text' => false,
                'matched_datetime_ok' => false,
            ],
            $flags,
            $reasons,
            0.0
        );
    }

    $absPath = (string) $resolved['path'];

    // Primary OCR
    $ocr = commerce_ocr_run_on_file($absPath, $proofMime);
    $flags = [];
    if (!empty($ocr['error']) && $ocr['error'] === 'tesseract_unavailable') {
        $flags[] = 'tesseract_unavailable';
    }
    if (!empty($ocr['error']) && $ocr['error'] === 'pdf_needs_review') {
        $flags[] = 'pdf_unreadable';
    }

    $needVision = false;
    if (empty($ocr['ok']) || trim((string) ($ocr['raw_text'] ?? '')) === '') {
        $needVision = true;
    } elseif ((float) ($ocr['confidence'] ?? 0) < $threshold) {
        $needVision = true;
    }

    if ($needVision && $visionEnabled) {
        $vision = commerce_ocr_run_vision_fallback($absPath, $proofMime);
        if (!empty($vision['ok']) && trim((string) ($vision['raw_text'] ?? '')) !== '') {
            $flags[] = 'vision_used';
            // Prefer vision when primary failed or confidence higher
            $useVision = empty($ocr['ok'])
                || trim((string) ($ocr['raw_text'] ?? '')) === ''
                || (float) ($vision['confidence'] ?? 0) >= (float) ($ocr['confidence'] ?? 0);
            if ($useVision) {
                $ocr = $vision;
            } else {
                // Keep primary text but note vision was attempted
                if (!empty($vision['vision_fields']) && is_array($vision['vision_fields'])) {
                    $ocr['vision_fields'] = $vision['vision_fields'];
                }
            }
        } elseif (!empty($vision['error'])) {
            $flags[] = 'vision_failed';
        }
    }

    $rawText = trim((string) ($ocr['raw_text'] ?? ''));
    $confidence = (float) ($ocr['confidence'] ?? 0);
    $extracted = commerce_ocr_extract_from_text($rawText);
    if (!empty($ocr['vision_fields']) && is_array($ocr['vision_fields'])) {
        $extracted = commerce_ocr_merge_vision_fields($extracted, $ocr['vision_fields']);
    }

    // Unreadable / empty with engine error → failed
    if ($rawText === '' && (empty($ocr['ok']) || !empty($ocr['error']))) {
        $flags[] = 'unreadable_receipt';
        if (!empty($ocr['error'])) {
            $flags[] = (string) $ocr['error'];
        }
        $reasons = ['no_ocr_text', (string) ($ocr['error'] ?? 'engine_error')];
        return commerce_verification_persist(
            $conn,
            $payment,
            'failed',
            $ocr,
            $extracted,
            [
                'matched_amount' => false,
                'matched_reference' => false,
                'matched_recipient' => false,
                'matched_success_text' => false,
                'matched_datetime_ok' => false,
            ],
            $flags,
            $reasons,
            $confidence
        );
    }

    // ---------- Evaluate rules ----------
    $expectedAmount = (int) ($payment['expected_amount_centavos'] ?? 0);
    $expectedRefNorm = commerce_normalize_gcash_reference((string) ($payment['gcash_reference_norm'] ?? $payment['gcash_reference'] ?? ''));
    $settingsName = trim((string) ($settings['gcash_account_name'] ?? ''));
    $settingsPhone = commerce_ocr_normalize_phone((string) ($settings['gcash_number'] ?? ''));

    $matchedAmount = ($extracted['amount_centavos'] !== null && (int) $extracted['amount_centavos'] === $expectedAmount);
    if (!$matchedAmount) {
        $flags[] = 'amount_mismatch';
    }

    $detRefNorm = (string) ($extracted['reference_norm'] ?? '');
    $matchedReference = ($detRefNorm !== '' && $expectedRefNorm !== '' && $detRefNorm === $expectedRefNorm);
    if (!$matchedReference) {
        $flags[] = 'reference_mismatch';
    }

    $matchedRecipient = false;
    $detRecipient = (string) ($extracted['recipient'] ?? '');
    if ($detRecipient !== '') {
        if ($settingsName !== '' && commerce_ocr_names_match($detRecipient, $settingsName)) {
            $matchedRecipient = true;
        }
        $detPhone = commerce_ocr_normalize_phone($detRecipient);
        if (!$matchedRecipient && $settingsPhone !== '' && $detPhone !== '') {
            // Match last 10 digits (PH mobile) or full digit string
            $a = substr($settingsPhone, -10);
            $b = substr($detPhone, -10);
            if ($a !== '' && $a === $b) {
                $matchedRecipient = true;
            } elseif ($settingsPhone === $detPhone) {
                $matchedRecipient = true;
            }
        }
        // Also search raw text for account name / phone if recipient line missed
        if (!$matchedRecipient && $settingsName !== '' && commerce_ocr_names_match($rawText, $settingsName)) {
            // rawText contains name as substring via names_match only if one contains other —
            // names_match on full receipt vs short name: short name contained in receipt text
            $matchedRecipient = true;
        }
        if (!$matchedRecipient && $settingsPhone !== '' && strpos(commerce_ocr_normalize_phone($rawText), substr($settingsPhone, -10)) !== false) {
            $matchedRecipient = true;
        }
    } else {
        // No recipient line — try settings name/phone anywhere in text
        if ($settingsName !== '' && strlen($settingsName) >= 3) {
            $matchedRecipient = (stripos($rawText, $settingsName) !== false);
        }
        if (!$matchedRecipient && $settingsPhone !== '') {
            $matchedRecipient = (strpos(commerce_ocr_normalize_phone($rawText), substr($settingsPhone, -10)) !== false);
        }
    }
    if (!$matchedRecipient) {
        $flags[] = 'recipient_mismatch';
    }

    $matchedSuccess = !empty($extracted['success_text_found']);
    if (!$matchedSuccess) {
        $flags[] = 'no_success_text';
    }

    $matchedDatetimeOk = false;
    $paidAtStr = $extracted['paid_at'] ?? null;
    if (is_string($paidAtStr) && $paidAtStr !== '') {
        $paidTs = strtotime($paidAtStr);
        if ($paidTs !== false) {
            $now = time();
            $futureLimit = $now + (COMMERCE_VERIFICATION_FUTURE_SKEW_MINUTES * 60);
            $oldest = $now - ($maxAgeDays * 86400);
            if ($paidTs > $futureLimit) {
                $flags[] = 'future_receipt';
            } elseif ($paidTs < $oldest) {
                $flags[] = 'expired_receipt';
            } else {
                $matchedDatetimeOk = true;
            }
        } else {
            $flags[] = 'datetime_unparseable';
        }
    } else {
        $flags[] = 'datetime_missing';
    }

    $duplicate = !empty($payment['duplicate_reference']);
    if ($duplicate) {
        $flags[] = 'duplicate_reference';
    }

    $confidenceOk = ($confidence >= $threshold);
    if (!$confidenceOk) {
        $flags[] = 'low_confidence';
    }

    $critical = [
        'amount_mismatch',
        'reference_mismatch',
        'recipient_mismatch',
        'no_success_text',
        'expired_receipt',
        'future_receipt',
        'duplicate_reference',
        'low_confidence',
        'unreadable_receipt',
    ];
    // tesseract_unavailable alone does not block auto if vision (or test OCR) produced usable text
    if (in_array('tesseract_unavailable', $flags, true) && $rawText === '') {
        $critical[] = 'tesseract_unavailable';
    }
    $hasCritical = false;
    foreach ($critical as $c) {
        if (in_array($c, $flags, true)) {
            $hasCritical = true;
            break;
        }
    }

    $matches = [
        'matched_amount' => $matchedAmount,
        'matched_reference' => $matchedReference,
        'matched_recipient' => $matchedRecipient,
        'matched_success_text' => $matchedSuccess,
        'matched_datetime_ok' => $matchedDatetimeOk,
    ];

    $reasons = [];
    foreach ($matches as $k => $v) {
        $reasons[] = $k . '=' . ($v ? '1' : '0');
    }
    $reasons[] = 'confidence=' . $confidence;
    $reasons[] = 'threshold=' . $threshold;
    if ($flags !== []) {
        $reasons[] = 'flags=' . implode(',', array_unique($flags));
    }

    $allMatched = $matchedAmount && $matchedReference && $matchedRecipient
        && $matchedSuccess && $matchedDatetimeOk;

    if ($allMatched && $confidenceOk && !$duplicate && !$hasCritical) {
        $decision = 'auto_verified';
    } elseif ($rawText === '') {
        $decision = 'failed';
    } else {
        $decision = 'needs_review';
    }

    // Remove duplicate flag entries
    $flags = array_values(array_unique($flags));

    $persisted = commerce_verification_persist(
        $conn,
        $payment,
        $decision,
        $ocr,
        $extracted,
        $matches,
        $flags,
        $reasons,
        $confidence
    );

    // Phase 7: after auto_verified+paid, attempt fulfillment. Never undo paid on fulfill failure.
    if (
        !empty($persisted['ok'])
        && ($persisted['decision'] ?? '') === 'auto_verified'
        && !empty($persisted['payment']['payment_id'])
    ) {
        $fulfill = commerce_fulfill_after_auto_verify($conn, (int) $persisted['payment']['payment_id']);
        $persisted['fulfill'] = $fulfill;
        if (!empty($fulfill['payment'])) {
            $persisted['payment'] = $fulfill['payment'];
        } else {
            $fresh = commerce_get_payment($conn, (int) $persisted['payment']['payment_id']);
            if ($fresh) {
                $persisted['payment'] = $fresh;
            }
        }
    }

    return $persisted;
}

/**
 * CLI/worker helper: verify a batch of pending payments.
 *
 * @return array{ok:bool,processed:int,results:list<array<string,mixed>>}
 */
function commerce_verify_pending_batch(mysqli $conn, int $limit = 20): array
{
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 100) {
        $limit = 100;
    }

    $stuck = COMMERCE_VERIFICATION_PROCESSING_STUCK_MINUTES;
    $sql = "SELECT payment_id FROM payments
            WHERE status = 'pending_verification'
              AND (
                verification_status IN ('not_started', 'failed')
                OR (
                  verification_status = 'processing'
                  AND updated_at < (NOW() - INTERVAL {$stuck} MINUTE)
                )
              )
            ORDER BY updated_at ASC
            LIMIT ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'processed' => 0, 'results' => []];
    }
    mysqli_stmt_bind_param($stmt, 'i', $limit);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ids = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $ids[] = (int) $row['payment_id'];
        }
        mysqli_free_result($res);
    }
    mysqli_stmt_close($stmt);

    $results = [];
    foreach ($ids as $pid) {
        $results[] = array_merge(
            ['payment_id' => $pid],
            commerce_verify_payment($conn, $pid)
        );
    }

    return [
        'ok' => true,
        'processed' => count($results),
        'results' => $results,
    ];
}
