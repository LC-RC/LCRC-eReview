<?php
/**
 * Admin manual / complimentary LMS access grant (source=admin_manual).
 * Does NOT run commerce_fulfill_payment (avoids duplicate purchase grants).
 * By default, closes any open reviewable payment (needs_review / OCR failed + proof)
 * as manually_approved + fulfilled so Payment Verification is not double work.
 *
 * Supports Full LMS or by-topic (subject/lesson/... ) permissions - same choices as Student Access.
 */
declare(strict_types=1);

require_once __DIR__ . '/student_content_access.php';
require_once __DIR__ . '/commerce_activation.php';
if (is_file(__DIR__ . '/commerce_fulfillment.php')) {
    require_once __DIR__ . '/commerce_fulfillment.php';
}

/**
 * Insert or extend one admin_manual access_grants row for a content key.
 *
 * @return array{ok:bool,error?:string,grant_id?:int,already_active?:bool}
 */
function commerce_admin_upsert_manual_grant_row(
    mysqli $conn,
    int $userId,
    int $adminId,
    string $contentType,
    int $contentId,
    string $label,
    int $months
): array {
    $existing = null;
    $eq = mysqli_prepare(
        $conn,
        "SELECT grant_id
         FROM access_grants
         WHERE user_id = ?
           AND source = 'admin_manual'
           AND content_type = ?
           AND content_id = ?
           AND status = 'active'
           AND ends_at > NOW()
         ORDER BY ends_at DESC
         LIMIT 1"
    );
    if ($eq) {
        mysqli_stmt_bind_param($eq, 'isi', $userId, $contentType, $contentId);
        mysqli_stmt_execute($eq);
        $er = mysqli_stmt_get_result($eq);
        $existing = $er ? mysqli_fetch_assoc($er) : null;
        mysqli_stmt_close($eq);
    }

    if ($existing) {
        $grantId = (int) ($existing['grant_id'] ?? 0);
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
        return ['ok' => true, 'grant_id' => $grantId, 'already_active' => true];
    }

    $source = 'admin_manual';
    $gStatus = 'active';
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
        $contentType,
        $contentId,
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
    return ['ok' => true, 'grant_id' => $grantId, 'already_active' => false];
}

/**
 * Grant LMS access for N months via access_grants.source = admin_manual + SCA.
 * Default permissions = Full LMS. Pass topic/subject rows for by-topic access.
 *
 * @param array{
 *   months?:int,
 *   activate_login?:bool,
 *   label?:string,
 *   close_open_payment?:bool,
 *   close_awaiting_without_proof?:bool,
 *   notify_student?:bool,
 *   permissions?:list<array{content_type?:string,content_id?:int|string}>
 * } $opts
 * @return array{ok:bool,error?:string,grant_id?:int,activation?:array<string,mixed>,already_active?:bool,payment_close?:array<string,mixed>,notify?:array<string,mixed>,scope?:string}
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
    // Emergency only - default false so Awaiting Payment / no proof stays open for Remind → upload.
    $closeAwaitingWithoutProof = !empty($opts['close_awaiting_without_proof']);
    // Default on for interactive Grant Access; scripts/restore should pass notify_student=false.
    $notifyStudent = array_key_exists('notify_student', $opts) ? (bool) $opts['notify_student'] : true;

    $permissions = sca_normalize_permission_payload(
        isset($opts['permissions']) && is_array($opts['permissions'])
            ? $opts['permissions']
            : [['content_type' => 'full_lms', 'content_id' => 0]]
    );
    if ($permissions === []) {
        return ['ok' => false, 'error' => 'no_permissions'];
    }

    $isFullLms = false;
    foreach ($permissions as $p) {
        if (($p['content_type'] ?? '') === 'full_lms' && (int) ($p['content_id'] ?? 0) === 0) {
            $isFullLms = true;
            break;
        }
    }
    if ($isFullLms) {
        $permissions = [['content_type' => 'full_lms', 'content_id' => 0]];
    }

    $defaultLabel = $isFullLms
        ? 'Administrative Access (Full LMS)'
        : ('Administrative Access (' . count($permissions) . ' topic' . (count($permissions) === 1 ? '' : 's') . ')');
    $label = trim((string) ($opts['label'] ?? $defaultLabel));
    if ($label === '') {
        $label = $defaultLabel;
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

    // By-topic grant must not keep a prior admin Full LMS grant (would re-expand SCA via commerce keys).
    if (!$isFullLms) {
        $rev = mysqli_prepare(
            $conn,
            "UPDATE access_grants
             SET status = 'revoked', updated_at = NOW()
             WHERE user_id = ?
               AND source = 'admin_manual'
               AND content_type = 'full_lms'
               AND content_id = 0
               AND status = 'active'"
        );
        if ($rev) {
            mysqli_stmt_bind_param($rev, 'i', $userId);
            mysqli_stmt_execute($rev);
            mysqli_stmt_close($rev);
        }
    }

    $grantId = 0;
    $alreadyActive = false;
    foreach ($permissions as $p) {
        $ctype = (string) $p['content_type'];
        $cid = (int) $p['content_id'];
        $rowLabel = $isFullLms
            ? $label
            : ($label . ' / ' . $ctype . '#' . $cid);
        if (strlen($rowLabel) > 200) {
            $rowLabel = substr($rowLabel, 0, 200);
        }
        $row = commerce_admin_upsert_manual_grant_row(
            $conn,
            $userId,
            $adminId,
            $ctype,
            $cid,
            $rowLabel,
            $months
        );
        if (empty($row['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($row['error'] ?? 'grant_failed'),
                'grant_id' => $grantId > 0 ? $grantId : null,
            ];
        }
        if ($grantId <= 0) {
            $grantId = (int) ($row['grant_id'] ?? 0);
        }
        if (!empty($row['already_active'])) {
            $alreadyActive = true;
        }
    }

    if ($grantId <= 0) {
        return ['ok' => false, 'error' => 'grant_failed'];
    }

    // By-topic: drop other admin_manual rows so SCA/login match the picker (keep purchase/FAR).
    if (!$isFullLms) {
        $keep = [];
        foreach ($permissions as $p) {
            $keep[(string) $p['content_type'] . ':' . (int) $p['content_id']] = true;
        }
        $listQ = mysqli_prepare(
            $conn,
            "SELECT grant_id, content_type, content_id
             FROM access_grants
             WHERE user_id = ?
               AND source = 'admin_manual'
               AND status = 'active'
               AND ends_at > NOW()"
        );
        if ($listQ) {
            mysqli_stmt_bind_param($listQ, 'i', $userId);
            mysqli_stmt_execute($listQ);
            $listR = mysqli_stmt_get_result($listQ);
            $revokeIds = [];
            while ($listR && ($gr = mysqli_fetch_assoc($listR))) {
                $key = (string) ($gr['content_type'] ?? '') . ':' . (int) ($gr['content_id'] ?? 0);
                if (!isset($keep[$key])) {
                    $revokeIds[] = (int) ($gr['grant_id'] ?? 0);
                }
            }
            mysqli_stmt_close($listQ);
            foreach ($revokeIds as $rid) {
                if ($rid <= 0) {
                    continue;
                }
                $rq = mysqli_prepare(
                    $conn,
                    "UPDATE access_grants SET status = 'revoked', updated_at = NOW() WHERE grant_id = ? LIMIT 1"
                );
                if ($rq) {
                    mysqli_stmt_bind_param($rq, 'i', $rid);
                    mysqli_stmt_execute($rq);
                    mysqli_stmt_close($rq);
                }
            }
        }
    }

    // Replace SCA with selection, but keep active purchase/FAR grant keys.
    if (!sca_save_user_permissions_preserving_commerce($conn, $userId, $permissions, $adminId)) {
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
            $grantId,
            '',
            $closeAwaitingWithoutProof
        );
        if (empty($paymentClose['ok'])) {
            error_log(
                'commerce_admin_grant_manual_access payment_close_failed user=' . $userId
                . ' grant=' . $grantId
                . ' err=' . (string) ($paymentClose['error'] ?? '')
            );
        }
    }

    $notify = ['ok' => true, 'skipped' => true, 'sent' => false, 'in_app' => false];
    if ($notifyStudent) {
        try {
            if (!function_exists('commerce_notify_admin_manual_grant')) {
                require_once __DIR__ . '/commerce_notifications.php';
            }
            $payRow = is_array($paymentClose['payment'] ?? null) ? $paymentClose['payment'] : [];
            // Only claim "proof not required" when the no-proof override actually closed the payment.
            $noProof = $closeAwaitingWithoutProof
                && empty($paymentClose['skipped'])
                && ((string) ($paymentClose['mode'] ?? '') === 'awaiting_proof')
                && trim((string) ($payRow['proof_path'] ?? '')) === '';
            $endsAt = '';
            $endsQ2 = mysqli_prepare(
                $conn,
                'SELECT ends_at FROM access_grants WHERE grant_id = ? LIMIT 1'
            );
            if ($endsQ2) {
                mysqli_stmt_bind_param($endsQ2, 'i', $grantId);
                mysqli_stmt_execute($endsQ2);
                $er2 = mysqli_stmt_get_result($endsQ2);
                $erow = $er2 ? mysqli_fetch_assoc($er2) : null;
                mysqli_stmt_close($endsQ2);
                $endsAt = trim((string) ($erow['ends_at'] ?? ''));
            }
            if ($endsAt === '') {
                $uEnd = mysqli_prepare($conn, 'SELECT access_end FROM users WHERE user_id = ? LIMIT 1');
                if ($uEnd) {
                    mysqli_stmt_bind_param($uEnd, 'i', $userId);
                    mysqli_stmt_execute($uEnd);
                    $ur = mysqli_stmt_get_result($uEnd);
                    $urow = $ur ? mysqli_fetch_assoc($ur) : null;
                    mysqli_stmt_close($uEnd);
                    $endsAt = trim((string) ($urow['access_end'] ?? ''));
                }
            }
            $notify = commerce_notify_admin_manual_grant($conn, $userId, $adminId, [
                'months' => $months,
                'scope' => $isFullLms ? 'full_lms' : 'by_topic',
                'no_proof' => $noProof,
                'payment_closed' => empty($paymentClose['skipped']),
                'ends_at' => $endsAt,
                'grant_id' => $grantId,
            ]);
        } catch (Throwable $e) {
            error_log('commerce_admin_grant_manual_access notify_failed user=' . $userId . ' ' . $e->getMessage());
            $notify = ['ok' => false, 'sent' => false, 'in_app' => false, 'error' => 'notify_exception'];
        }
    }

    return [
        'ok' => true,
        'grant_id' => $grantId,
        'already_active' => $alreadyActive,
        'activation' => $activation,
        'payment_close' => $paymentClose,
        'notify' => $notify,
        'scope' => $isFullLms ? 'full_lms' : 'by_topic',
    ];
}
