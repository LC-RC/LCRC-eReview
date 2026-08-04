<?php
/**
 * Admin manual / complimentary LMS access grant (source=admin_manual).
 * Does NOT run commerce_fulfill_payment (avoids duplicate purchase grants).
 * By default, closes any open reviewable payment (needs_review / OCR failed + proof)
 * as manually_approved + fulfilled so Payment Verification is not double work.
 */
declare(strict_types=1);

require_once __DIR__ . '/student_content_access.php';
require_once __DIR__ . '/commerce_activation.php';
if (is_file(__DIR__ . '/commerce_fulfillment.php')) {
    require_once __DIR__ . '/commerce_fulfillment.php';
}

/**
 * Grant Full LMS access for N months via access_grants.source = admin_manual + SCA upsert.
 * Optionally activates login when status is pending (same helper as paid/FAR success).
 * Optionally closes a reviewable open payment (status only — no purchase fulfill).
 *
 * @param array{months?:int,activate_login?:bool,label?:string,close_open_payment?:bool} $opts
 * @return array{ok:bool,error?:string,grant_id?:int,activation?:array<string,mixed>,already_active?:bool,payment_close?:array<string,mixed>}
 */
function commerce_admin_grant_manual_access(
    mysqli $conn,
    int $userId,
    int $adminId,
    array $opts = []
): array {
    if ($userId <= 0 || $adminId <= 0) {
        return ['ok' => false, 'error' => 'invalid_ids'];
    }
    if (!commerce_schema_ready($conn)) {
        return ['ok' => false, 'error' => 'commerce_schema_missing'];
    }

    $months = (int) ($opts['months'] ?? 6);
    if ($months < 1) {
        $months = 1;
    }
    if ($months > 120) {
        $months = 120;
    }
    $activateLogin = array_key_exists('activate_login', $opts) ? (bool) $opts['activate_login'] : true;
    $closeOpenPayment = array_key_exists('close_open_payment', $opts) ? (bool) $opts['close_open_payment'] : true;
    $label = trim((string) ($opts['label'] ?? 'Administrative Access (Full LMS)'));
    if ($label === '') {
        $label = 'Administrative Access (Full LMS)';
    }
    if (strlen($label) > 200) {
        $label = substr($label, 0, 200);
    }

    $ust = mysqli_prepare(
        $conn,
        "SELECT user_id, role, status FROM users WHERE user_id = ? LIMIT 1"
    );
    if (!$ust) {
        return ['ok' => false, 'error' => 'user_prepare_failed'];
    }
    mysqli_stmt_bind_param($ust, 'i', $userId);
    mysqli_stmt_execute($ust);
    $ures = mysqli_stmt_get_result($ust);
    $user = $ures ? mysqli_fetch_assoc($ures) : null;
    mysqli_stmt_close($ust);
    if (!$user || (string) ($user['role'] ?? '') !== 'student') {
        return ['ok' => false, 'error' => 'not_student'];
    }
    if (strtolower((string) ($user['status'] ?? '')) === 'rejected') {
        return ['ok' => false, 'error' => 'rejected_student'];
    }

    // If an active admin_manual full_lms grant already covers the student, extend SCA / login only.
    $existing = null;
    $eq = mysqli_prepare(
        $conn,
        "SELECT grant_id, ends_at, status, source
         FROM access_grants
         WHERE user_id = ?
           AND source = 'admin_manual'
           AND content_type = 'full_lms'
           AND content_id = 0
           AND status = 'active'
           AND ends_at > NOW()
         ORDER BY ends_at DESC
         LIMIT 1"
    );
    if ($eq) {
        mysqli_stmt_bind_param($eq, 'i', $userId);
        mysqli_stmt_execute($eq);
        $er = mysqli_stmt_get_result($eq);
        $existing = $er ? mysqli_fetch_assoc($er) : null;
        mysqli_stmt_close($eq);
    }

    $source = 'admin_manual';
    $ctype = 'full_lms';
    $cid = 0;
    $gStatus = 'active';
    $grantId = 0;

    if ($existing) {
        $grantId = (int) ($existing['grant_id'] ?? 0);
        // Extend end date if the new window would be longer.
        $ext = mysqli_prepare(
            $conn,
            "UPDATE access_grants
             SET ends_at = GREATEST(ends_at, DATE_ADD(NOW(), INTERVAL ? MONTH)),
                 content_label = ?,
                 granted_by = ?,
                 updated_at = NOW()
             WHERE grant_id = ?
               AND status = 'active'
             LIMIT 1"
        );
        if ($ext) {
            mysqli_stmt_bind_param($ext, 'isii', $months, $label, $adminId, $grantId);
            mysqli_stmt_execute($ext);
            mysqli_stmt_close($ext);
        }
    } else {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO access_grants
              (user_id, source, payment_id, payment_item_id, free_access_request_id,
               content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
             VALUES (?, ?, NULL, NULL, NULL, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH), ?, ?)'
        );
        if (!$ins) {
            return ['ok' => false, 'error' => 'grant_prepare_failed'];
        }
        mysqli_stmt_bind_param(
            $ins,
            'issisisi',
            $userId,
            $source,
            $ctype,
            $cid,
            $label,
            $months,
            $gStatus,
            $adminId
        );
        if (!mysqli_stmt_execute($ins)) {
            $err = mysqli_error($conn);
            mysqli_stmt_close($ins);
            return ['ok' => false, 'error' => 'grant_insert_failed:' . $err];
        }
        $grantId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
    }

    if (!sca_upsert_permissions($conn, $userId, [['content_type' => 'full_lms', 'content_id' => 0]], $adminId)) {
        return ['ok' => false, 'error' => 'sca_upsert_failed', 'grant_id' => $grantId];
    }

    $activation = ['ok' => true, 'skipped' => true, 'activated' => false];
    if ($activateLogin) {
        $endTs = 0;
        $endsQ = mysqli_prepare(
            $conn,
            "SELECT UNIX_TIMESTAMP(ends_at) AS ets FROM access_grants WHERE grant_id = ? LIMIT 1"
        );
        if ($endsQ) {
            mysqli_stmt_bind_param($endsQ, 'i', $grantId);
            mysqli_stmt_execute($endsQ);
            $endsR = mysqli_stmt_get_result($endsQ);
            $endsRow = $endsR ? mysqli_fetch_assoc($endsR) : null;
            mysqli_stmt_close($endsQ);
            $endTs = (int) ($endsRow['ets'] ?? 0);
        }
        $activation = commerce_activate_user_after_commerce_success($conn, $userId, [
            'require_active_grant' => true,
            'access_end_ts' => $endTs,
            'access_months' => $months,
            'granted_by' => $adminId,
        ]);
        if ($endTs > 0 && function_exists('commerce_fulfill_maybe_extend_access_end')) {
            commerce_fulfill_maybe_extend_access_end($conn, $userId, $endTs);
        }
    }

    $paymentClose = ['ok' => true, 'skipped' => true];
    if ($closeOpenPayment && function_exists('commerce_close_reviewable_payment_after_admin_grant')) {
        $paymentClose = commerce_close_reviewable_payment_after_admin_grant(
            $conn,
            $userId,
            $adminId,
            $grantId
        );
        // Grant already succeeded — payment close failure must not undo access.
        if (empty($paymentClose['ok'])) {
            error_log(
                'commerce_admin_grant_manual_access payment_close_failed user=' . $userId
                . ' grant=' . $grantId
                . ' err=' . (string) ($paymentClose['error'] ?? '')
            );
        }
    }

    return [
        'ok' => true,
        'grant_id' => $grantId,
        'already_active' => (bool) $existing,
        'activation' => $activation,
        'payment_close' => $paymentClose,
    ];
}
