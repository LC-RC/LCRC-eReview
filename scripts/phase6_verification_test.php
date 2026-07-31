<?php
/**
 * Phase 6 — receipt OCR / verification acceptance tests (A–T).
 * Uses injectable OCR fixtures (CLI test mode). Leaves no test data.
 */
declare(strict_types=1);

define('COMMERCE_PAYMENT_TEST_MODE', true);
define('COMMERCE_OCR_TEST_MODE', true);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/commerce_payment.php';
require_once __DIR__ . '/../includes/commerce_verification.php';

function out(string $label, bool $ok, string $detail = ''): void
{
    echo '[' . ($ok ? 'PASS' : 'FAIL') . "] $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
}

$results = [];
$mark = static function (string $key, bool $ok, string $detail = '') use (&$results): void {
    $results[$key] = ['ok' => $ok, 'detail' => $detail];
    out($key, $ok, $detail);
};

echo "=== Phase 6 verification tests ===\n";

$basePay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$baseItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$baseAttempts = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
$baseGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$baseSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$basePkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$basePurch = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons WHERE is_purchasable=1'))[0] ?? 0);
$baseFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$baseGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);

echo "Baseline pay=$basePay items=$baseItems attempts=$baseAttempts grants=$baseGrants sca=$baseSca pkgs=$basePkg\n";

$createdUserIds = [];
$createdPackageIds = [];
$createdPaymentIds = [];
$proofFiles = [];
$settingsBackup = commerce_get_payment_settings($conn);

$GCASH_NAME = 'LCRC Review Center';
$GCASH_NUM = '09171234567';
$AMOUNT = 150000; // ₱1,500.00
$REF = 'P6REFABC12345';

mysqli_query(
    $conn,
    "UPDATE payment_settings SET
        gcash_account_name='" . mysqli_real_escape_string($conn, $GCASH_NAME) . "',
        gcash_number='" . mysqli_real_escape_string($conn, $GCASH_NUM) . "',
        ocr_confidence_threshold=85,
        receipt_max_age_days=7,
        vision_fallback_enabled=0
     WHERE setting_id=1"
);

function p6_user(mysqli $conn, string $email, string $path, ?int $pkgId, ?string $lessonsJson): int
{
    $hash = password_hash('TestPass1!', PASSWORD_DEFAULT);
    $name = 'Phase6 Test';
    $school = 'Test School';
    $review = 'reviewee';
    $proof = '';
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO users (full_name, review_type, enrollment_path, selected_package_id, selected_lesson_ids_json, school, school_other, payment_proof, email, password, role, status, email_verified)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 'student', 'pending', 1)"
    );
    mysqli_stmt_bind_param($stmt, 'sssisssss', $name, $review, $path, $pkgId, $lessonsJson, $school, $proof, $email, $hash);
    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('user insert failed: ' . mysqli_error($conn));
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id;
}

function p6_tiny_png(string $path): void
{
    $bin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    file_put_contents($path, $bin);
}

function p6_receipt_text(
    string $amountPesos,
    string $ref,
    string $recipient,
    string $paidAt,
    bool $success = true
): string {
    $lines = [];
    if ($success) {
        $lines[] = 'Payment Successful';
        $lines[] = 'You have sent money';
    } else {
        $lines[] = 'GCash Transfer Receipt';
    }
    $lines[] = 'Amount: ₱' . $amountPesos;
    $lines[] = 'Ref No. ' . $ref;
    $lines[] = 'To: ' . $recipient;
    $lines[] = 'Date: ' . $paidAt;
    return implode("\n", $lines);
}

function p6_set_ocr(string $text, float $confidence = 92.0, bool $ok = true, ?string $error = null): void
{
    $GLOBALS['commerce_test_ocr_result'] = [
        'ok' => $ok,
        'engine' => 'tesseract',
        'raw_text' => $text,
        'confidence' => $confidence,
        'error' => $error,
    ];
    unset($GLOBALS['commerce_test_vision_result']);
}

function p6_clear_ocr(): void
{
    unset($GLOBALS['commerce_test_ocr_result'], $GLOBALS['commerce_test_vision_result']);
}

/**
 * Create checkout, submit proof+ref, return payment_id.
 *
 * @return array{ok:bool,payment_id?:int,error?:string,payment?:array}
 */
function p6_submit_pending(
    mysqli $conn,
    int $userId,
    int $pkgId,
    string $ref,
    array &$createdPaymentIds,
    array &$proofFiles,
    string $mime = 'image/png'
): array {
    $co = commerce_create_or_resume_checkout($conn, $userId, 'package', $pkgId, null);
    if (empty($co['ok'])) {
        return ['ok' => false, 'error' => $co['error'] ?? 'checkout_failed'];
    }
    $pid = (int) $co['payment']['payment_id'];
    $createdPaymentIds[] = $pid;

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p6_proof_' . $pid . '_' . bin2hex(random_bytes(4)) . '.png';
    p6_tiny_png($tmp);
    $proofFiles[] = $tmp;
    $file = [
        'name' => 'proof.png',
        'type' => $mime,
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmp),
    ];
    $sub = commerce_submit_payment_proof_and_reference($conn, $pid, $userId, $ref, $file);
    if (empty($sub['ok'])) {
        return ['ok' => false, 'error' => $sub['error'] ?? 'submit_failed', 'payment_id' => $pid];
    }
    // Track stored proof for cleanup
    $pay = commerce_get_payment($conn, $pid);
    if (!empty($pay['proof_path'])) {
        $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $pay['proof_path']);
        $proofFiles[] = $abs;
    }
    return ['ok' => true, 'payment_id' => $pid, 'payment' => $pay ?: $sub['payment']];
}

function p6_attempt_count(mysqli $conn, int $paymentId): int
{
    return commerce_verification_attempt_count($conn, $paymentId);
}

try {
    mysqli_query($conn, "INSERT INTO sellable_packages
        (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
        VALUES ('TEST_P6_FULL', 'Phase6 Full LMS', 'test', {$AMOUNT}, 'PHP', 6, 'month', 'full_lms', 1, 1, 1)");
    $pkgId = (int) mysqli_insert_id($conn);
    $createdPackageIds[] = $pkgId;

    $ts = (string) time();
    $paidAtRecent = date('Y-m-d H:i:s', time() - 3600);
    $paidAtOld = date('Y-m-d H:i:s', time() - (30 * 86400));
    $amountPesos = '1,500.00';

    // ---------- A happy path ----------
    $uA = p6_user($conn, "phase6.a.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uA;
    $refA = 'P6A' . substr($ts, -8) . 'XY';
    $subA = p6_submit_pending($conn, $uA, $pkgId, $refA, $createdPaymentIds, $proofFiles);
    p6_set_ocr(p6_receipt_text($amountPesos, $refA, $GCASH_NAME, $paidAtRecent), 92.0);
    $vA = commerce_verify_payment($conn, (int) $subA['payment_id']);
    $payA = commerce_get_payment($conn, (int) $subA['payment_id']);
    $mark(
        'A',
        ($vA['decision'] ?? '') === 'auto_verified'
            && ($payA['verification_status'] ?? '') === 'auto_verified'
            && ($payA['status'] ?? '') === 'paid'
            && !empty($payA['paid_at'])
            && empty($payA['fulfilled_at']),
        'decision=' . ($vA['decision'] ?? '') . ' status=' . ($payA['status'] ?? '') . ' fulfilled=' . ($payA['fulfilled_at'] ?? 'NULL')
    );

    // ---------- B wrong amount ----------
    $uB = p6_user($conn, "phase6.b.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uB;
    $refB = 'P6B' . substr($ts, -8) . 'XY';
    $subB = p6_submit_pending($conn, $uB, $pkgId, $refB, $createdPaymentIds, $proofFiles);
    p6_set_ocr(p6_receipt_text('999.00', $refB, $GCASH_NAME, $paidAtRecent), 92.0);
    $vB = commerce_verify_payment($conn, (int) $subB['payment_id']);
    $payB = commerce_get_payment($conn, (int) $subB['payment_id']);
    $mark(
        'B',
        ($vB['decision'] ?? '') === 'needs_review'
            && ($payB['status'] ?? '') === 'pending_verification'
            && ($payB['verification_status'] ?? '') === 'needs_review',
        'decision=' . ($vB['decision'] ?? '')
    );

    // ---------- C wrong reference ----------
    $uC = p6_user($conn, "phase6.c.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uC;
    $refC = 'P6C' . substr($ts, -8) . 'XY';
    $subC = p6_submit_pending($conn, $uC, $pkgId, $refC, $createdPaymentIds, $proofFiles);
    p6_set_ocr(p6_receipt_text($amountPesos, 'WRONGREF99999', $GCASH_NAME, $paidAtRecent), 92.0);
    $vC = commerce_verify_payment($conn, (int) $subC['payment_id']);
    $payC = commerce_get_payment($conn, (int) $subC['payment_id']);
    $mark('C', ($vC['decision'] ?? '') === 'needs_review' && ($payC['status'] ?? '') === 'pending_verification', ($vC['decision'] ?? ''));

    // ---------- D wrong recipient ----------
    $uD = p6_user($conn, "phase6.d.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uD;
    $refD = 'P6D' . substr($ts, -8) . 'XY';
    $subD = p6_submit_pending($conn, $uD, $pkgId, $refD, $createdPaymentIds, $proofFiles);
    p6_set_ocr(p6_receipt_text($amountPesos, $refD, 'Someone Else Inc', $paidAtRecent), 92.0);
    $vD = commerce_verify_payment($conn, (int) $subD['payment_id']);
    $mark('D', ($vD['decision'] ?? '') === 'needs_review', ($vD['decision'] ?? ''));

    // ---------- E no success text ----------
    $uE = p6_user($conn, "phase6.e.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uE;
    $refE = 'P6E' . substr($ts, -8) . 'XY';
    $subE = p6_submit_pending($conn, $uE, $pkgId, $refE, $createdPaymentIds, $proofFiles);
    p6_set_ocr(p6_receipt_text($amountPesos, $refE, $GCASH_NAME, $paidAtRecent, false), 92.0);
    $vE = commerce_verify_payment($conn, (int) $subE['payment_id']);
    $mark('E', ($vE['decision'] ?? '') === 'needs_review', ($vE['decision'] ?? ''));

    // ---------- F expired receipt ----------
    $uF = p6_user($conn, "phase6.f.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uF;
    $refF = 'P6F' . substr($ts, -8) . 'XY';
    $subF = p6_submit_pending($conn, $uF, $pkgId, $refF, $createdPaymentIds, $proofFiles);
    p6_set_ocr(p6_receipt_text($amountPesos, $refF, $GCASH_NAME, $paidAtOld), 92.0);
    $vF = commerce_verify_payment($conn, (int) $subF['payment_id']);
    $mark('F', in_array(($vF['decision'] ?? ''), ['needs_review', 'failed'], true), ($vF['decision'] ?? ''));

    // ---------- G unreadable ----------
    $uG = p6_user($conn, "phase6.g.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uG;
    $refG = 'P6G' . substr($ts, -8) . 'XY';
    $subG = p6_submit_pending($conn, $uG, $pkgId, $refG, $createdPaymentIds, $proofFiles);
    p6_set_ocr('', 0.0, false, 'tesseract_unavailable');
    $vG = commerce_verify_payment($conn, (int) $subG['payment_id']);
    $payG = commerce_get_payment($conn, (int) $subG['payment_id']);
    $mark(
        'G',
        ($vG['decision'] ?? '') === 'failed'
            && ($payG['status'] ?? '') === 'pending_verification'
            && ($payG['verification_status'] ?? '') === 'failed',
        'decision=' . ($vG['decision'] ?? '') . ' pay_status=' . ($payG['status'] ?? '')
    );

    // ---------- H low confidence ----------
    $uH = p6_user($conn, "phase6.h.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uH;
    $refH = 'P6H' . substr($ts, -8) . 'XY';
    $subH = p6_submit_pending($conn, $uH, $pkgId, $refH, $createdPaymentIds, $proofFiles);
    p6_set_ocr(p6_receipt_text($amountPesos, $refH, $GCASH_NAME, $paidAtRecent), 40.0);
    $vH = commerce_verify_payment($conn, (int) $subH['payment_id']);
    $mark('H', ($vH['decision'] ?? '') !== 'auto_verified' && ($vH['decision'] ?? '') === 'needs_review', ($vH['decision'] ?? ''));

    // ---------- I duplicate reference flag ----------
    $uI = p6_user($conn, "phase6.i.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uI;
    $refI = 'P6I' . substr($ts, -8) . 'XY';
    $subI = p6_submit_pending($conn, $uI, $pkgId, $refI, $createdPaymentIds, $proofFiles);
    mysqli_query($conn, 'UPDATE payments SET duplicate_reference=1 WHERE payment_id=' . (int) $subI['payment_id']);
    p6_set_ocr(p6_receipt_text($amountPesos, $refI, $GCASH_NAME, $paidAtRecent), 92.0);
    $vI = commerce_verify_payment($conn, (int) $subI['payment_id']);
    $mark('I', ($vI['decision'] ?? '') !== 'auto_verified', ($vI['decision'] ?? ''));

    // ---------- J concurrent claim ----------
    $uJ = p6_user($conn, "phase6.j.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uJ;
    $refJ = 'P6J' . substr($ts, -8) . 'XY';
    $subJ = p6_submit_pending($conn, $uJ, $pkgId, $refJ, $createdPaymentIds, $proofFiles);
    mysqli_query(
        $conn,
        "UPDATE payments SET verification_status='processing', updated_at=NOW() WHERE payment_id=" . (int) $subJ['payment_id']
    );
    p6_set_ocr(p6_receipt_text($amountPesos, $refJ, $GCASH_NAME, $paidAtRecent), 92.0);
    $vJ = commerce_verify_payment($conn, (int) $subJ['payment_id']);
    $payJ = commerce_get_payment($conn, (int) $subJ['payment_id']);
    $mark(
        'J',
        ($vJ['decision'] ?? '') === 'skipped'
            && ($payJ['verification_status'] ?? '') === 'processing',
        'decision=' . ($vJ['decision'] ?? '') . ' err=' . ($vJ['error'] ?? '')
    );

    // ---------- K idempotent re-run after auto_verified ----------
    $vK = commerce_verify_payment($conn, (int) $subA['payment_id']);
    $attemptsBeforeK = p6_attempt_count($conn, (int) $subA['payment_id']);
    $vK2 = commerce_verify_payment($conn, (int) $subA['payment_id']);
    $attemptsAfterK = p6_attempt_count($conn, (int) $subA['payment_id']);
    $mark(
        'K',
        ($vK['decision'] ?? '') === 'skipped'
            && ($vK2['decision'] ?? '') === 'skipped'
            && $attemptsAfterK === $attemptsBeforeK,
        'k1=' . ($vK['decision'] ?? '') . ' attempts=' . $attemptsAfterK
    );

    // ---------- L attempt audit rows ----------
    $attB = p6_attempt_count($conn, (int) $subB['payment_id']);
    $attG = p6_attempt_count($conn, (int) $subG['payment_id']);
    $attA = p6_attempt_count($conn, (int) $subA['payment_id']);
    $mark('L', $attA >= 1 && $attB >= 1 && $attG >= 1, "A=$attA B=$attB G=$attG");

    // ---------- M Free Access never enters verifier ----------
    $uFree = p6_user($conn, "phase6.free.{$ts}@example.com", 'free_access', null, null);
    $createdUserIds[] = $uFree;
    $farRef = 'FAR-P6-' . $ts;
    mysqli_query(
        $conn,
        "INSERT INTO free_access_requests (request_ref, user_id, status)
         VALUES ('" . mysqli_real_escape_string($conn, $farRef) . "', {$uFree}, 'pending')"
    );
    // Ensure no payment for free user
    $freePayCnt = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM payments WHERE user_id={$uFree}"))[0] ?? 0);
    // Calling verify on a non-package payment shouldn't happen; also ensure free_access_requests path didn't create payments
    $mark('M', $freePayCnt === 0, "free_payments=$freePayCnt");

    // ---------- N access_grants: auto_verified may fulfill (Phase 7); needs_review must not ----------
    $grantsA = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants WHERE payment_id=' . (int) $subA['payment_id']))[0] ?? 0);
    $grantsB = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants WHERE payment_id=' . (int) $subB['payment_id']))[0] ?? 0);
    $mark('N', $grantsB === 0, "A_grants=$grantsA B_grants=$grantsB (needs_review must be 0)");

    // ---------- O SCA: needs_review user unchanged; auto_verified may upsert ----------
    $scaB = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions WHERE user_id=' . (int) $uB))[0] ?? 0);
    $mark('O', $scaB === 0, "scaB=$scaB (needs_review must not write SCA)");

    // ---------- P login/activation files untouched ----------
    $root = dirname(__DIR__);
    $pOk = true;
    $pDetail = [];
    foreach (['activate_user.php', 'login.php', 'login_process.php'] as $f) {
        $path = $root . DIRECTORY_SEPARATOR . $f;
        if (!is_file($path)) {
            $pOk = false;
            $pDetail[] = "$f missing";
            continue;
        }
        $pDetail[] = "$f present";
    }
    $phase6Files = [
        'includes/commerce_ocr.php',
        'includes/commerce_ocr_extract.php',
        'includes/commerce_verification.php',
        'payment_checkout_submit.php',
        'scripts/commerce_verify_pending.php',
        'scripts/phase6_verification_test.php',
        'admin_commerce_payments.php',
    ];
    $joined = '';
    foreach ($phase6Files as $rel) {
        $p = $root . '/' . $rel;
        if (is_file($p)) {
            $joined .= file_get_contents($p);
        }
    }
    $noRequire = !preg_match('/\b(require|include)(_once)?\s*\(?\s*[\'\"][^\'\"]*(activate_user|login_process|login)\.php/', $joined);
    $noGrantWrite = !preg_match('/\b(INSERT\s+INTO\s+access_grants|UPDATE\s+student_content_permissions|INTO\s+student_content_permissions)\b/i', $joined);
    $pOk = $pOk && $noRequire && $noGrantWrite;
    $mark('P', $pOk, implode('; ', $pDetail) . '; no login/activate includes; no grant/SCA writes');

    // ---------- Q Vision fallback gating ----------
    $uQ = p6_user($conn, "phase6.q.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uQ;
    $refQ = 'P6Q' . substr($ts, -8) . 'XY';
    $subQ = p6_submit_pending($conn, $uQ, $pkgId, $refQ, $createdPaymentIds, $proofFiles);
    // Vision disabled: low-confidence primary should NOT set vision_used
    mysqli_query($conn, 'UPDATE payment_settings SET vision_fallback_enabled=0 WHERE setting_id=1');
    p6_set_ocr(p6_receipt_text($amountPesos, $refQ, $GCASH_NAME, $paidAtRecent), 40.0);
    $GLOBALS['commerce_test_vision_result'] = [
        'ok' => true,
        'engine' => 'vision_openai',
        'raw_text' => p6_receipt_text($amountPesos, $refQ, $GCASH_NAME, $paidAtRecent),
        'confidence' => 95.0,
    ];
    $vQ1 = commerce_verify_payment($conn, (int) $subQ['payment_id']);
    $payQ1 = commerce_get_payment($conn, (int) $subQ['payment_id']);
    $flagsQ1 = (string) ($payQ1['suspicious_flags_json'] ?? '');
    $noVision = strpos($flagsQ1, 'vision_used') === false;
    $notAutoWhenOff = ($vQ1['decision'] ?? '') !== 'auto_verified';

    // Force re-run with vision enabled — still must not auto_verify unless all rules pass;
    // here primary is low conf but vision text is good → may auto_verify WITH vision_used
    mysqli_query($conn, 'UPDATE payment_settings SET vision_fallback_enabled=1 WHERE setting_id=1');
    // Reset payment to claimable failed/not_started
    mysqli_query(
        $conn,
        "UPDATE payments SET status='pending_verification', verification_status='failed' WHERE payment_id=" . (int) $subQ['payment_id']
    );
    p6_set_ocr('', 0.0, false, 'tesseract_unavailable');
    $GLOBALS['commerce_test_vision_result'] = [
        'ok' => true,
        'engine' => 'vision_openai',
        'raw_text' => p6_receipt_text($amountPesos, $refQ, $GCASH_NAME, $paidAtRecent),
        'confidence' => 95.0,
    ];
    $vQ2 = commerce_verify_payment($conn, (int) $subQ['payment_id'], ['force' => true]);
    $payQ2 = commerce_get_payment($conn, (int) $subQ['payment_id']);
    $flagsQ2 = (string) ($payQ2['suspicious_flags_json'] ?? '');
    $visionUsed = strpos($flagsQ2, 'vision_used') !== false;
    $mark(
        'Q',
        $noVision && $notAutoWhenOff && $visionUsed && ($vQ2['decision'] ?? '') === 'auto_verified',
        "off_flags=$flagsQ1 off_dec={$vQ1['decision']} on_flags=$flagsQ2 on_dec={$vQ2['decision']}"
    );
    mysqli_query($conn, 'UPDATE payment_settings SET vision_fallback_enabled=0 WHERE setting_id=1');

    // ---------- R PDF handling ----------
    $uR = p6_user($conn, "phase6.r.{$ts}@example.com", 'package', $pkgId, null);
    $createdUserIds[] = $uR;
    $refR = 'P6R' . substr($ts, -8) . 'XY';
    $subR = p6_submit_pending($conn, $uR, $pkgId, $refR, $createdPaymentIds, $proofFiles);
    // Point mime to PDF and use fixture that mirrors PDF path
    mysqli_query($conn, "UPDATE payments SET proof_mime='application/pdf' WHERE payment_id=" . (int) $subR['payment_id']);
    p6_clear_ocr(); // real path: PDF returns pdf_needs_review without Imagick
    // Without fixture, tesseract path hits pdf mime first
    $vR = commerce_verify_payment($conn, (int) $subR['payment_id']);
    $payR = commerce_get_payment($conn, (int) $subR['payment_id']);
    $flagsR = (string) ($payR['suspicious_flags_json'] ?? '');
    $mark(
        'R',
        in_array(($vR['decision'] ?? ''), ['failed', 'needs_review'], true)
            && (strpos($flagsR, 'pdf') !== false || ($vR['decision'] ?? '') === 'failed'),
        'decision=' . ($vR['decision'] ?? '') . ' flags=' . $flagsR
    );

    // ---------- S suspicious flags recorded ----------
    $flagsB = (string) ($payB['suspicious_flags_json'] ?? '');
    // refresh B
    $payB2 = commerce_get_payment($conn, (int) $subB['payment_id']);
    $flagsB = (string) ($payB2['suspicious_flags_json'] ?? '');
    $mark('S', $flagsB !== '' && $flagsB !== '[]' && strpos($flagsB, 'amount_mismatch') !== false, $flagsB);

    // ---------- Confirm auto_verified → paid; user stays pending (Phase 7 may fulfill) ----------
    $payA2 = commerce_get_payment($conn, (int) $subA['payment_id']);
    $userA = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM users WHERE user_id={$uA}"));
    $mark(
        'A_NO_FULFILL',
        ($payA2['status'] ?? '') === 'paid'
            && ($userA['status'] ?? '') === 'pending',
        'user_status=' . ($userA['status'] ?? '') . ' fulfilled=' . ($payA2['fulfilled_at'] ?? 'NULL')
    );

    // ---------- T cleanup ----------
} catch (Throwable $e) {
    out('EXCEPTION', false, $e->getMessage());
    $results['EXCEPTION'] = ['ok' => false, 'detail' => $e->getMessage()];
}

// Cleanup
$paymentIds = array_values(array_unique(array_filter(array_map('intval', $createdPaymentIds))));
if ($paymentIds !== []) {
    $in = implode(',', $paymentIds);
    mysqli_query($conn, "DELETE FROM access_grants WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payment_verification_attempts WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payment_gcash_references WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payment_items WHERE payment_id IN ($in)");
    mysqli_query($conn, "DELETE FROM payments WHERE payment_id IN ($in)");
}
// Phase 7 may upsert SCA for test users — remove those rows on cleanup.
if ($createdUserIds !== []) {
    $uids = implode(',', array_map('intval', $createdUserIds));
    mysqli_query($conn, "DELETE FROM student_content_permissions WHERE user_id IN ($uids)");
    mysqli_query($conn, "DELETE FROM access_grants WHERE user_id IN ($uids) AND payment_id IS NULL");
}

$userIds = array_values(array_unique(array_filter(array_map('intval', $createdUserIds))));
if ($userIds !== []) {
    $uin = implode(',', $userIds);
    mysqli_query($conn, "DELETE FROM free_access_requests WHERE user_id IN ($uin)");
    mysqli_query($conn, "DELETE FROM users WHERE user_id IN ($uin) AND email LIKE 'phase6.%@example.com'");
}

foreach ($createdPackageIds as $pid) {
    mysqli_query($conn, 'DELETE FROM package_content_items WHERE package_id=' . (int) $pid);
    mysqli_query($conn, 'DELETE FROM sellable_packages WHERE package_id=' . (int) $pid . " AND code LIKE 'TEST_P6_%'");
}

// Restore settings
$name = mysqli_real_escape_string($conn, (string) ($settingsBackup['gcash_account_name'] ?? ''));
$num = mysqli_real_escape_string($conn, (string) ($settingsBackup['gcash_number'] ?? ''));
$thr = (float) ($settingsBackup['ocr_confidence_threshold'] ?? 85);
$age = (int) ($settingsBackup['receipt_max_age_days'] ?? 7);
$vis = !empty($settingsBackup['vision_fallback_enabled']) ? 1 : 0;
mysqli_query(
    $conn,
    "UPDATE payment_settings SET gcash_account_name='$name', gcash_number='$num',
     ocr_confidence_threshold=$thr, receipt_max_age_days=$age, vision_fallback_enabled=$vis WHERE setting_id=1"
);

$proofCleaned = 0;
$proofFailed = 0;
foreach (array_unique($proofFiles) as $pf) {
    if (is_string($pf) && $pf !== '' && is_file($pf)) {
        // Only delete under uploads/payment_proofs or temp
        $norm = str_replace('\\', '/', $pf);
        if (strpos($norm, '/payment_proofs/') !== false || strpos($norm, sys_get_temp_dir()) !== false || strpos($norm, 'p6_proof_') !== false) {
            if (@unlink($pf)) {
                $proofCleaned++;
            } else {
                $proofFailed++;
            }
        }
    }
}
// Sweep leftover TEST proofs matching phase6 users (already deleted) — also remove orphaned TEST_P6 files by glob
$proofDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payment_proofs';
if (is_dir($proofDir)) {
    foreach (glob($proofDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        if (!is_file($f) || basename($f) === '.gitkeep') {
            continue;
        }
        // Only remove files created in last hour that are tiny test pngs left dangling — safer: skip if payments still reference
        $rel = 'uploads/payment_proofs/' . basename($f);
        $chk = mysqli_query($conn, "SELECT COUNT(*) AS c FROM payments WHERE proof_path='" . mysqli_real_escape_string($conn, $rel) . "'");
        $c = $chk ? (int) (mysqli_fetch_assoc($chk)['c'] ?? 0) : 1;
        if ($c === 0 && filemtime($f) >= time() - 7200 && filesize($f) < 2048) {
            if (@unlink($f)) {
                $proofCleaned++;
            }
        }
    }
}

$endPay = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payments'))[0] ?? 0);
$endItems = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_items'))[0] ?? 0);
$endAttempts = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_verification_attempts'))[0] ?? 0);
$endGrants = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM access_grants'))[0] ?? 0);
$endSca = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM student_content_permissions'))[0] ?? 0);
$endPkg = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM sellable_packages'))[0] ?? 0);
$endPurch = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lessons WHERE is_purchasable=1'))[0] ?? 0);
$endFar = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM free_access_requests'))[0] ?? 0);
$endGcash = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM payment_gcash_references'))[0] ?? 0);

$cleanupOk = $endPay === $basePay && $endItems === $baseItems && $endAttempts === $baseAttempts
    && $endGrants === $baseGrants && $endSca === $baseSca && $endPkg === $basePkg
    && $endFar === $baseFar && $endGcash === $baseGcash && $proofFailed === 0;

$mark(
    'T',
    $cleanupOk,
    "pay=$endPay/$basePay items=$endItems/$baseItems attempts=$endAttempts/$baseAttempts grants=$endGrants/$baseGrants sca=$endSca/$baseSca pkgs=$endPkg/$basePkg far=$endFar/$baseFar gcash=$endGcash/$baseGcash proofs_removed=$proofCleaned fail=$proofFailed"
);

$pass = 0;
$fail = 0;
foreach ($results as $r) {
    if (!empty($r['ok'])) {
        $pass++;
    } else {
        $fail++;
    }
}
echo "=== Summary: $pass pass, $fail fail ===\n";
echo "DB after cleanup: pay=$endPay items=$endItems attempts=$endAttempts grants=$endGrants sca=$endSca pkgs=$endPkg far=$endFar gcash=$endGcash\n";
exit($fail > 0 ? 1 : 0);
