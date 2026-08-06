<?php
/**
 * Commerce payment fulfillment (Phase 7).
 *
 * paid + (auto_verified|manually_approved) → access_grants + SCA upsert + fulfilled_at.
 * After successful commit: idempotent login activation via commerce_activation.php.
 * Does NOT: replace-all SCA, Free Access, OCR, gateways, or invent activation without grants.
 */

declare(strict_types=1);

require_once __DIR__ . '/commerce_catalog.php';
require_once __DIR__ . '/commerce_payment.php';
require_once __DIR__ . '/student_content_access.php';
require_once __DIR__ . '/commerce_notifications.php';
require_once __DIR__ . '/commerce_activation.php';

/**
 * Add duration to a unix timestamp (day/month).
 */
function commerce_fulfill_add_duration(int $baseTs, int $value, string $unit): int
{
    if ($value < 1) {
        $value = 1;
    }
    $unit = $unit === 'month' ? 'month' : 'day';
    try {
        $dt = new DateTime('@' . $baseTs);
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get() ?: 'UTC'));
        $dt->modify('+' . $value . ' ' . ($unit === 'month' ? 'months' : 'days'));
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        return $baseTs + ($unit === 'month' ? $value * 30 * 86400 : $value * 86400);
    }
}

/**
 * Current effective ends_at for stacking (active grant still in the future), or null.
 */
function commerce_fulfill_current_effective_ends_at(
    mysqli $conn,
    int $userId,
    string $contentType,
    int $contentId
): ?int {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT MAX(ends_at) AS mx FROM access_grants
         WHERE user_id = ?
           AND content_type = ?
           AND content_id = ?
           AND status = 'active'
           AND ends_at > NOW()"
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'isi', $userId, $contentType, $contentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row || empty($row['mx'])) {
        return null;
    }
    $ts = strtotime((string) $row['mx']);
    return $ts !== false ? $ts : null;
}

/**
 * Resolve content keys for one payment_item from snapshots (not live catalog prices).
 *
 * @return array{ok:bool,error?:string,contents?:list<array{content_type:string,content_id:int,label:string}>}
 */
function commerce_fulfill_resolve_item_contents(mysqli $conn, array $item): array
{
    $itemType = (string) ($item['item_type'] ?? '');
    $labelBase = (string) ($item['item_name'] ?? 'Item');

    if ($itemType === 'lesson') {
        $lessonId = (int) ($item['lesson_id'] ?? 0);
        if ($lessonId <= 0) {
            return ['ok' => false, 'error' => 'lesson_item_missing_lesson_id'];
        }
        if (!commerce_content_entity_exists($conn, 'lesson', $lessonId)) {
            return ['ok' => false, 'error' => 'lesson_entity_missing:' . $lessonId];
        }
        return [
            'ok' => true,
            'contents' => [[
                'content_type' => 'lesson',
                'content_id' => $lessonId,
                'label' => $labelBase,
            ]],
        ];
    }

    if ($itemType === 'package') {
        $scope = (string) ($item['package_access_scope'] ?? '');
        if ($scope === 'full_lms') {
            return [
                'ok' => true,
                'contents' => [[
                    'content_type' => 'full_lms',
                    'content_id' => 0,
                    'label' => $labelBase . ' (Full LMS)',
                ]],
            ];
        }
        if ($scope === 'mapped') {
            $raw = $item['package_content_snapshot_json'] ?? '[]';
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
            } elseif (is_array($raw)) {
                $decoded = $raw;
            } else {
                $decoded = [];
            }
            if (!is_array($decoded) || $decoded === []) {
                return ['ok' => false, 'error' => 'mapped_snapshot_empty'];
            }
            $normalized = commerce_normalize_content_map($decoded);
            if ($normalized === []) {
                return ['ok' => false, 'error' => 'mapped_snapshot_invalid'];
            }
            $contents = [];
            foreach ($normalized as $row) {
                $type = $row['content_type'];
                $cid = (int) $row['content_id'];
                if (!commerce_content_entity_exists($conn, $type, $cid)) {
                    return ['ok' => false, 'error' => 'mapped_orphaned:' . $type . ':' . $cid];
                }
                $contents[] = [
                    'content_type' => $type,
                    'content_id' => $cid,
                    'label' => $labelBase . ' / ' . $type . '#' . $cid,
                ];
            }
            return ['ok' => true, 'contents' => $contents];
        }
        return ['ok' => false, 'error' => 'unknown_package_scope'];
    }

    return ['ok' => false, 'error' => 'unsupported_item_type'];
}

/**
 * Insert one access_grant; duplicate unique key = already fulfilled for this line+content.
 *
 * @return array{ok:bool,duplicate?:bool,error?:string,grant_id?:int}
 */
function commerce_fulfill_insert_grant(
    mysqli $conn,
    int $userId,
    int $paymentId,
    int $paymentItemId,
    string $contentType,
    int $contentId,
    string $label,
    string $startsAt,
    string $endsAt,
    ?int $grantedBy
): array {
    $source = 'purchase';
    $status = 'active';
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO access_grants
          (user_id, source, payment_id, payment_item_id, free_access_request_id,
           content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
         VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'grant_prepare_failed'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'isiisissssi',
        $userId,
        $source,
        $paymentId,
        $paymentItemId,
        $contentType,
        $contentId,
        $label,
        $startsAt,
        $endsAt,
        $status,
        $grantedBy
    );
    $exec = mysqli_stmt_execute($stmt);
    if (!$exec) {
        $errno = mysqli_errno($conn);
        $err = mysqli_error($conn);
        mysqli_stmt_close($stmt);
        if ($errno === 1062 || commerce_mysqli_is_duplicate_key_error($errno, $err)) {
            return ['ok' => true, 'duplicate' => true];
        }
        return ['ok' => false, 'error' => 'grant_insert_failed:' . $err];
    }
    $gid = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return ['ok' => true, 'duplicate' => false, 'grant_id' => $gid];
}

/**
 * Optionally extend users.access_end for already-approved students (never shorten, never auto-approve).
 */
function commerce_fulfill_maybe_extend_access_end(mysqli $conn, int $userId, int $maxEndsTs): void
{
    if ($userId <= 0 || $maxEndsTs <= 0) {
        return;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT status, access_end FROM users WHERE user_id = ? AND role = 'student' LIMIT 1"
    );
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row || strtolower((string) ($row['status'] ?? '')) !== 'approved') {
        return;
    }
    $endRaw = trim((string) ($row['access_end'] ?? ''));
    // Empty access_end = unlimited account window; do not invent an end date.
    if ($endRaw === '') {
        return;
    }
    $curTs = strtotime($endRaw);
    if ($curTs === false) {
        return;
    }
    if ($curTs >= $maxEndsTs) {
        return; // do not shorten
    }
    $newEnd = date('Y-m-d H:i:s', $maxEndsTs);
    if ($newEnd === '' || strtotime($newEnd) === false) {
        return;
    }
    // Separate vars: mysqli_stmt_bind_param is by-reference; avoid binding one var twice.
    $newEndBind = $newEnd;
    $cmpEndBind = $newEnd;
    $uidBind = $userId;
    $upd = mysqli_prepare(
        $conn,
        "UPDATE users SET access_end = ?
         WHERE user_id = ? AND role = 'student' AND status = 'approved'
           AND access_end IS NOT NULL AND CAST(access_end AS CHAR) <> ''
           AND access_end < ?
         LIMIT 1"
    );
    if (!$upd) {
        return;
    }
    mysqli_stmt_bind_param($upd, 'sis', $newEndBind, $uidBind, $cmpEndBind);
    try {
        mysqli_stmt_execute($upd);
    } catch (Throwable $e) {
        // Never let account-window extend undo grant/SCA work in the caller transaction.
        error_log('commerce_fulfill_maybe_extend_access_end: ' . $e->getMessage());
    }
    mysqli_stmt_close($upd);
}

/**
 * Fulfill a paid, eligible payment once.
 *
 * @param array{granted_by?:int|null} $opts
 * @return array{ok:bool,skipped?:bool,error?:string,grants_created?:int,payment?:array<string,mixed>}
 */
function commerce_fulfill_payment(mysqli $conn, int $paymentId, array $opts = []): array
{
    if ($paymentId <= 0) {
        return ['ok' => false, 'error' => 'invalid_payment_id'];
    }

    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment) {
        return ['ok' => false, 'error' => 'payment_not_found'];
    }

    if (!empty($payment['fulfilled_at'])) {
        // Repair path: commerce already fulfilled; ensure login activation if still pending.
        $repairUid = (int) ($payment['user_id'] ?? 0);
        $activation = ['ok' => false, 'skipped' => true, 'error' => 'not_attempted'];
        if ($repairUid > 0) {
            try {
                $activation = commerce_activate_user_after_commerce_success($conn, $repairUid, [
                    'source' => 'purchase',
                    'require_active_grant' => true,
                ]);
            } catch (Throwable $ae) {
                error_log('commerce_fulfill_payment activation repair: ' . $ae->getMessage());
                $activation = ['ok' => false, 'error' => 'activation_exception', 'activated' => false];
            }
        }
        return [
            'ok' => true,
            'skipped' => true,
            'error' => 'already_fulfilled',
            'grants_created' => 0,
            'payment' => $payment,
            'activation' => $activation,
        ];
    }

    $status = (string) ($payment['status'] ?? '');
    $vStatus = (string) ($payment['verification_status'] ?? '');
    if ($status !== 'paid') {
        return ['ok' => false, 'error' => 'not_paid', 'payment' => $payment];
    }
    if (!in_array($vStatus, ['auto_verified', 'manually_approved'], true)) {
        return ['ok' => false, 'error' => 'not_eligible_verification', 'payment' => $payment];
    }

    $purchaseType = (string) ($payment['purchase_type'] ?? '');
    if (!in_array($purchaseType, ['package', 'by_topic'], true)) {
        return ['ok' => false, 'error' => 'unsupported_purchase_type', 'payment' => $payment];
    }

    $userId = (int) ($payment['user_id'] ?? 0);
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'missing_user', 'payment' => $payment];
    }

    $items = commerce_get_payment_items($conn, $paymentId);
    if ($items === []) {
        return ['ok' => false, 'error' => 'no_payment_items', 'payment' => $payment];
    }

    $grantedBy = array_key_exists('granted_by', $opts) ? $opts['granted_by'] : null;
    if ($grantedBy !== null) {
        $grantedBy = (int) $grantedBy;
        if ($grantedBy <= 0) {
            $grantedBy = null;
        }
    }

    // Pre-resolve all contents (fail closed before writing).
    $plan = []; // list of {item, contents, duration_value, duration_unit}
    foreach ($items as $item) {
        $resolved = commerce_fulfill_resolve_item_contents($conn, $item);
        if (empty($resolved['ok'])) {
            return [
                'ok' => false,
                'error' => $resolved['error'] ?? 'resolve_failed',
                'payment' => $payment,
            ];
        }
        $durV = (int) ($item['duration_value'] ?? 0);
        $durU = (string) ($item['duration_unit'] ?? 'day');
        if ($durV < 1) {
            return ['ok' => false, 'error' => 'invalid_snapshot_duration', 'payment' => $payment];
        }
        $plan[] = [
            'item' => $item,
            'contents' => $resolved['contents'],
            'duration_value' => $durV,
            'duration_unit' => $durU === 'month' ? 'month' : 'day',
        ];
    }

    $nowTs = time();
    $startsAt = date('Y-m-d H:i:s', $nowTs);
    $scaRows = [];
    $maxEndsTs = $nowTs;
    $grantsCreated = 0;

    mysqli_begin_transaction($conn);
    try {
        foreach ($plan as $entry) {
            $item = $entry['item'];
            $paymentItemId = (int) $item['payment_item_id'];
            foreach ($entry['contents'] as $c) {
                $ctype = (string) $c['content_type'];
                $cid = (int) $c['content_id'];
                $label = (string) ($c['label'] ?? '');

                $currentEnds = commerce_fulfill_current_effective_ends_at($conn, $userId, $ctype, $cid);
                $baseTs = ($currentEnds !== null && $currentEnds > $nowTs) ? $currentEnds : $nowTs;
                $endsTs = commerce_fulfill_add_duration($baseTs, (int) $entry['duration_value'], (string) $entry['duration_unit']);
                $endsAt = date('Y-m-d H:i:s', $endsTs);
                if ($endsTs > $maxEndsTs) {
                    $maxEndsTs = $endsTs;
                }

                $ins = commerce_fulfill_insert_grant(
                    $conn,
                    $userId,
                    $paymentId,
                    $paymentItemId,
                    $ctype,
                    $cid,
                    $label,
                    $startsAt,
                    $endsAt,
                    $grantedBy
                );
                if (empty($ins['ok'])) {
                    throw new RuntimeException($ins['error'] ?? 'grant_failed');
                }
                if (empty($ins['duplicate'])) {
                    $grantsCreated++;
                }
                $scaRows[] = ['content_type' => $ctype, 'content_id' => $cid];
            }
        }

        if (!sca_upsert_permissions($conn, $userId, $scaRows, $grantedBy)) {
            throw new RuntimeException('sca_upsert_failed');
        }

        commerce_fulfill_maybe_extend_access_end($conn, $userId, $maxEndsTs);

        $mark = mysqli_prepare(
            $conn,
            'UPDATE payments SET fulfilled_at = IFNULL(fulfilled_at, NOW())
             WHERE payment_id = ? AND status = \'paid\' AND fulfilled_at IS NULL LIMIT 1'
        );
        if (!$mark) {
            throw new RuntimeException('fulfill_mark_prepare_failed');
        }
        mysqli_stmt_bind_param($mark, 'i', $paymentId);
        if (!mysqli_stmt_execute($mark)) {
            mysqli_stmt_close($mark);
            throw new RuntimeException('fulfill_mark_failed');
        }
        mysqli_stmt_close($mark);

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('commerce_fulfill_payment: ' . $e->getMessage());
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'grants_created' => 0,
            'payment' => commerce_get_payment($conn, $paymentId) ?: $payment,
        ];
    }

    // COMMIT succeeded — activate login (best-effort; never rolls back grants/ledger).
    $activation = ['ok' => false, 'error' => 'activation_not_attempted', 'activated' => false];
    try {
        $activation = commerce_activate_user_after_commerce_success($conn, $userId, [
            'access_end_ts' => $maxEndsTs,
            'source' => 'purchase',
            'require_active_grant' => true,
        ]);
        if (empty($activation['ok'])) {
            error_log(
                'commerce_fulfill_payment: activation failed after fulfill payment_id='
                . $paymentId . ' user_id=' . $userId
                . ' err=' . (string) ($activation['error'] ?? '')
            );
        }
    } catch (Throwable $ae) {
        error_log('commerce_fulfill_payment activation: ' . $ae->getMessage());
        $activation = ['ok' => false, 'error' => 'activation_exception', 'activated' => false];
    }

    // Notification is best-effort and must never undo fulfillment/activation.
    $notify = ['ok' => false, 'error' => 'notify_not_attempted', 'sent' => false];
    try {
        $notify = commerce_notify_payment_fulfilled($conn, $paymentId);
    } catch (Throwable $ne) {
        error_log('commerce_fulfill_payment notify: ' . $ne->getMessage());
        $notify = ['ok' => false, 'error' => 'notify_exception', 'sent' => false];
    }

    return [
        'ok' => true,
        'grants_created' => $grantsCreated,
        'payment' => commerce_get_payment($conn, $paymentId) ?: $payment,
        'activation' => $activation,
        'notify' => $notify,
    ];
}

/**
 * Best-effort fulfill after auto_verified. Never undoes paid status.
 *
 * @return array{ok:bool,error?:string,skipped?:bool}
 */
function commerce_fulfill_after_auto_verify(mysqli $conn, int $paymentId): array
{
    try {
        return commerce_fulfill_payment($conn, $paymentId, ['granted_by' => null]);
    } catch (Throwable $e) {
        error_log('commerce_fulfill_after_auto_verify: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'fulfill_exception'];
    }
}

/**
 * Whether admin can manually approve/reject this payment.
 * Includes OCR-failed rows that still have uploaded proof (common when Tesseract is unavailable).
 *
 * @param array<string,mixed> $payment
 */
function commerce_payment_is_manual_reviewable(array $payment): bool
{
    if ((string) ($payment['status'] ?? '') !== 'pending_verification') {
        return false;
    }
    $v = (string) ($payment['verification_status'] ?? '');
    if ($v === 'needs_review') {
        return true;
    }
    // Failed / stuck OCR — admin override only when modern commerce proof exists.
    if (in_array($v, ['failed', 'processing', 'not_started'], true) && !empty($payment['proof_path'])) {
        return true;
    }
    return false;
}

/**
 * Close an open payment after admin_manual grant — no purchase grants / no fulfill.
 * 1) Reviewable pending_verification with proof → manually_approved + paid
 * 2) awaiting_proof with proof → same
 * 3) awaiting_proof without proof → only when $allowAwaitingWithoutProof (emergency override)
 *
 * @return array{ok:bool,skipped?:bool,error?:string,payment_id?:int,payment?:array<string,mixed>,mode?:string}
 */
function commerce_close_reviewable_payment_after_admin_grant(
    mysqli $conn,
    int $userId,
    int $adminId,
    int $grantId = 0,
    string $reviewNote = '',
    bool $allowAwaitingWithoutProof = false
): array {
    if ($userId <= 0 || $adminId <= 0) {
        return ['ok' => false, 'error' => 'invalid_ids'];
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM payments
         WHERE user_id = ?
           AND status IN ('pending_verification', 'awaiting_proof')
         ORDER BY payment_id DESC
         LIMIT 12"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'payment_lookup_failed'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $payment = null;
    $mode = '';
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $st = (string) ($row['status'] ?? '');
        if ($st === 'pending_verification' && commerce_payment_is_manual_reviewable($row)) {
            $payment = $row;
            $mode = 'reviewable_proof';
            break;
        }
        if ($payment === null && $st === 'awaiting_proof') {
            $payment = $row;
            $mode = 'awaiting_proof';
            // Prefer a reviewable proof payment if one appears later in the loop.
        }
    }
    mysqli_stmt_close($stmt);

    if (!$payment) {
        return ['ok' => true, 'skipped' => true, 'error' => 'no_reviewable_payment'];
    }

    $paymentId = (int) ($payment['payment_id'] ?? 0);
    if ($paymentId <= 0) {
        return ['ok' => true, 'skipped' => true, 'error' => 'no_reviewable_payment'];
    }

    $hasProof = trim((string) ($payment['proof_path'] ?? '')) !== '';

    // Default path: remind student to upload — do not silently approve no-proof payments.
    if ($mode === 'awaiting_proof' && !$hasProof && !$allowAwaitingWithoutProof) {
        return [
            'ok' => true,
            'skipped' => true,
            'error' => 'awaiting_proof_requires_override',
            'mode' => $mode,
            'payment_id' => $paymentId,
            'payment' => $payment,
        ];
    }

    $note = trim($reviewNote);
    if ($note === '') {
        if ($mode === 'awaiting_proof' && !$hasProof) {
            $note = 'Manually approved via administrative Grant Access (emergency without proof)'
                . ($grantId > 0 ? (' (grant #' . $grantId . ')') : '')
                . '. No payment proof was uploaded — access granted by admin without a second purchase grant.';
        } else {
            $note = 'Closed via administrative Grant Access'
                . ($grantId > 0 ? (' (grant #' . $grantId . ')') : '')
                . '. Access already granted — payment marked approved without creating a second purchase grant.';
        }
    }
    if (strlen($note) > 2000) {
        $note = substr($note, 0, 2000);
    }

    if ($mode === 'awaiting_proof') {
        $upd = mysqli_prepare(
            $conn,
            "UPDATE payments SET
                verification_status = 'manually_approved',
                status = 'paid',
                paid_at = IFNULL(paid_at, NOW()),
                reviewed_by = ?,
                reviewed_at = NOW(),
                review_note = ?,
                fulfilled_at = IFNULL(fulfilled_at, NOW())
             WHERE payment_id = ?
               AND user_id = ?
               AND status = 'awaiting_proof'
             LIMIT 1"
        );
    } else {
        $upd = mysqli_prepare(
            $conn,
            "UPDATE payments SET
                verification_status = 'manually_approved',
                status = 'paid',
                paid_at = IFNULL(paid_at, NOW()),
                reviewed_by = ?,
                reviewed_at = NOW(),
                review_note = ?,
                fulfilled_at = IFNULL(fulfilled_at, NOW())
             WHERE payment_id = ?
               AND user_id = ?
               AND status = 'pending_verification'
               AND verification_status IN ('needs_review','failed','processing','not_started')
               AND proof_path IS NOT NULL
               AND proof_path <> ''
             LIMIT 1"
        );
    }
    if (!$upd) {
        return ['ok' => false, 'error' => 'close_prepare_failed', 'payment_id' => $paymentId];
    }
    mysqli_stmt_bind_param($upd, 'isii', $adminId, $note, $paymentId, $userId);
    if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
        mysqli_stmt_close($upd);
        return [
            'ok' => false,
            'error' => 'close_race_or_state',
            'payment_id' => $paymentId,
            'payment' => commerce_get_payment($conn, $paymentId) ?: $payment,
        ];
    }
    mysqli_stmt_close($upd);

    return [
        'ok' => true,
        'skipped' => false,
        'mode' => $mode,
        'payment_id' => $paymentId,
        'payment' => commerce_get_payment($conn, $paymentId) ?: $payment,
    ];
}

/**
 * Admin manual approve → paid → fulfill (same path as auto_verified).
 * Allowed for needs_review, and for failed/processing/not_started when proof_path exists.
 *
 * @return array{ok:bool,error?:string,payment?:array<string,mixed>,fulfill?:array<string,mixed>}
 */
function commerce_manual_approve_payment(
    mysqli $conn,
    int $paymentId,
    int $adminId,
    string $reviewNote = ''
): array {
    if ($paymentId <= 0 || $adminId <= 0) {
        return ['ok' => false, 'error' => 'invalid_ids'];
    }
    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment) {
        return ['ok' => false, 'error' => 'payment_not_found'];
    }
    if (!commerce_payment_is_manual_reviewable($payment)) {
        return ['ok' => false, 'error' => 'not_reviewable', 'payment' => $payment];
    }

    $note = trim($reviewNote);
    if (strlen($note) > 2000) {
        $note = substr($note, 0, 2000);
    }
    $noteOrNull = $note === '' ? null : $note;

    $upd = mysqli_prepare(
        $conn,
        "UPDATE payments SET
            verification_status = 'manually_approved',
            status = 'paid',
            paid_at = IFNULL(paid_at, NOW()),
            reviewed_by = ?,
            reviewed_at = NOW(),
            review_note = ?
         WHERE payment_id = ?
           AND status = 'pending_verification'
           AND verification_status IN ('needs_review','failed','processing','not_started')
           AND proof_path IS NOT NULL
           AND proof_path <> ''
         LIMIT 1"
    );
    if (!$upd) {
        return ['ok' => false, 'error' => 'approve_prepare_failed'];
    }
    mysqli_stmt_bind_param($upd, 'isi', $adminId, $noteOrNull, $paymentId);
    if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
        mysqli_stmt_close($upd);
        return ['ok' => false, 'error' => 'approve_race_or_state', 'payment' => commerce_get_payment($conn, $paymentId) ?: $payment];
    }
    mysqli_stmt_close($upd);

    $fulfill = commerce_fulfill_payment($conn, $paymentId, ['granted_by' => $adminId]);
    return [
        'ok' => true,
        'payment' => commerce_get_payment($conn, $paymentId) ?: $payment,
        'fulfill' => $fulfill,
    ];
}

/**
 * Admin manual reject. No grants / SCA / fulfilled_at.
 * Same reviewable states as manual approve.
 *
 * @return array{ok:bool,error?:string,payment?:array<string,mixed>}
 */
function commerce_manual_reject_payment(
    mysqli $conn,
    int $paymentId,
    int $adminId,
    string $reviewNote = ''
): array {
    if ($paymentId <= 0 || $adminId <= 0) {
        return ['ok' => false, 'error' => 'invalid_ids'];
    }
    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment) {
        return ['ok' => false, 'error' => 'payment_not_found'];
    }
    if (!commerce_payment_is_manual_reviewable($payment)) {
        return ['ok' => false, 'error' => 'not_reviewable', 'payment' => $payment];
    }

    $note = trim($reviewNote);
    if (strlen($note) > 2000) {
        $note = substr($note, 0, 2000);
    }
    $noteOrNull = $note === '' ? null : $note;

    $upd = mysqli_prepare(
        $conn,
        "UPDATE payments SET
            verification_status = 'manually_rejected',
            status = 'rejected',
            reviewed_by = ?,
            reviewed_at = NOW(),
            review_note = ?
         WHERE payment_id = ?
           AND status = 'pending_verification'
           AND verification_status IN ('needs_review','failed','processing','not_started')
           AND proof_path IS NOT NULL
           AND proof_path <> ''
         LIMIT 1"
    );
    if (!$upd) {
        return ['ok' => false, 'error' => 'reject_prepare_failed'];
    }
    mysqli_stmt_bind_param($upd, 'isi', $adminId, $noteOrNull, $paymentId);
    if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
        mysqli_stmt_close($upd);
        return ['ok' => false, 'error' => 'reject_race_or_state', 'payment' => commerce_get_payment($conn, $paymentId) ?: $payment];
    }
    mysqli_stmt_close($upd);

    // Rejection state committed via single UPDATE — notification is best-effort.
    $notify = ['ok' => false, 'error' => 'notify_not_attempted', 'sent' => false];
    try {
        $notify = commerce_notify_payment_rejected($conn, $paymentId);
    } catch (Throwable $ne) {
        error_log('commerce_manual_reject_payment notify: ' . $ne->getMessage());
        $notify = ['ok' => false, 'error' => 'notify_exception', 'sent' => false];
    }

    return [
        'ok' => true,
        'payment' => commerce_get_payment($conn, $paymentId) ?: $payment,
        'notify' => $notify,
    ];
}

/**
 * @return array{ok:bool,processed:int,results:list<array<string,mixed>>}
 */
function commerce_fulfill_pending_batch(mysqli $conn, int $limit = 20): array
{
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 100) {
        $limit = 100;
    }
    $sql = "SELECT payment_id FROM payments
            WHERE status = 'paid'
              AND verification_status IN ('auto_verified', 'manually_approved')
              AND fulfilled_at IS NULL
            ORDER BY paid_at ASC, payment_id ASC
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
        $results[] = array_merge(['payment_id' => $pid], commerce_fulfill_payment($conn, $pid));
    }
    return ['ok' => true, 'processed' => count($results), 'results' => $results];
}
