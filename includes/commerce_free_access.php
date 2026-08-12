<?php
/**
 * Free Access admin approval / rejection (Phase 8.1).
 *
 * Separate from payment/OCR/fulfillment. Grants source=free_access full_lms only.
 * After successful grant commit: idempotent login activation via commerce_activation.php.
 * Rejected/cancelled FAR does not activate login.
 */

declare(strict_types=1);

require_once __DIR__ . '/commerce_catalog.php';
require_once __DIR__ . '/student_content_access.php';
require_once __DIR__ . '/commerce_notifications.php';
require_once __DIR__ . '/commerce_activation.php';

/** Reasonable admin-selected Free Access duration bounds (months). */
const COMMERCE_FAR_DURATION_MONTHS_MIN = 1;
const COMMERCE_FAR_DURATION_MONTHS_MAX = 120;

/**
 * Best-effort login activation after a free_access grant exists (never rolls back FAR/grant).
 *
 * @param array<string,mixed>|null $grant
 * @return array<string,mixed>
 */
function commerce_far_activate_login_after_grant(mysqli $conn, int $userId, int $months, ?array $grant = null): array
{
    $endTs = 0;
    if ($grant && !empty($grant['ends_at'])) {
        $parsed = strtotime((string) $grant['ends_at']);
        if ($parsed !== false) {
            $endTs = (int) $parsed;
        }
    }
    try {
        return commerce_activate_user_after_commerce_success($conn, $userId, [
            'access_end_ts' => $endTs > 0 ? $endTs : null,
            'access_months' => $months > 0 ? $months : null,
            'source' => 'free_access',
            'require_active_grant' => true,
        ]);
    } catch (Throwable $e) {
        error_log('commerce_far_activate_login_after_grant: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'activation_exception', 'activated' => false];
    }
}

/**
 * @param mixed $raw
 * @return array{ok:bool,error?:string,months?:int}
 */
function commerce_far_validate_duration_months($raw): array
{
    if (is_int($raw)) {
        $months = $raw;
    } elseif (is_float($raw)) {
        if (floor($raw) != $raw) {
            return ['ok' => false, 'error' => 'Duration must be a whole number of months.'];
        }
        $months = (int) $raw;
    } else {
        $s = trim((string) $raw);
        if ($s === '' || !preg_match('/^\d+$/', $s)) {
            return ['ok' => false, 'error' => 'Duration in months is required (positive whole number).'];
        }
        $months = (int) $s;
    }
    if ($months < COMMERCE_FAR_DURATION_MONTHS_MIN || $months > COMMERCE_FAR_DURATION_MONTHS_MAX) {
        return [
            'ok' => false,
            'error' => 'Duration must be between ' . COMMERCE_FAR_DURATION_MONTHS_MIN
                . ' and ' . COMMERCE_FAR_DURATION_MONTHS_MAX . ' months.',
        ];
    }
    return ['ok' => true, 'months' => $months];
}

/**
 * @return ?array<string,mixed>
 */
function commerce_far_get_request(mysqli $conn, int $requestId): ?array
{
    if ($requestId <= 0) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT r.*, u.full_name, u.email, u.status AS user_status, u.role AS user_role
         FROM free_access_requests r
         INNER JOIN users u ON u.user_id = r.user_id
         WHERE r.request_id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $requestId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Existing grant for this FAR + full_lms (any status) - app-level idempotency.
 */
function commerce_far_existing_full_lms_grant(mysqli $conn, int $requestId): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM access_grants
         WHERE free_access_request_id = ?
           AND content_type = 'full_lms'
           AND content_id = 0
         ORDER BY grant_id ASC
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $requestId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Lock free_access_requests row for the duration of the approval transaction.
 *
 * @return ?array{request_id:int,status:string,user_id:int}
 */
function commerce_far_lock_request_for_update(mysqli $conn, int $requestId): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT request_id, status, user_id
         FROM free_access_requests
         WHERE request_id = ?
         LIMIT 1
         FOR UPDATE'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $requestId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return null;
    }
    return [
        'request_id' => (int) $row['request_id'],
        'status' => (string) $row['status'],
        'user_id' => (int) $row['user_id'],
    ];
}

/**
 * Approve a pending Free Access request.
 *
 * @return array{ok:bool,error?:string,skipped?:bool,request?:array,grant?:array}
 */
function commerce_far_approve(
    mysqli $conn,
    int $requestId,
    int $adminId,
    int $durationMonths,
    string $adminNote = ''
): array {
    if ($requestId <= 0 || $adminId <= 0) {
        return ['ok' => false, 'error' => 'invalid_ids'];
    }
    $dur = commerce_far_validate_duration_months($durationMonths);
    if (empty($dur['ok'])) {
        return ['ok' => false, 'error' => $dur['error'] ?? 'invalid_duration'];
    }
    $months = (int) $dur['months'];

    $note = trim($adminNote);
    if (strlen($note) > 2000) {
        $note = substr($note, 0, 2000);
    }
    $noteOrNull = $note === '' ? null : $note;

    $req = commerce_far_get_request($conn, $requestId);
    if (!$req) {
        return ['ok' => false, 'error' => 'request_not_found'];
    }
    if (($req['user_role'] ?? '') !== 'student') {
        return ['ok' => false, 'error' => 'invalid_student'];
    }

    $userId = (int) $req['user_id'];
    $status = (string) ($req['status'] ?? '');

    // Fast path: already approved and fulfilled - no transaction needed.
    if ($status === 'approved') {
        $existing = commerce_far_existing_full_lms_grant($conn, $requestId);
        if ($existing) {
            sca_upsert_permissions($conn, $userId, [['content_type' => 'full_lms', 'content_id' => 0]], $adminId);
            $activation = commerce_far_activate_login_after_grant($conn, $userId, $months, $existing);
            return [
                'ok' => true,
                'skipped' => true,
                'error' => 'already_approved',
                'request' => commerce_far_get_request($conn, $requestId) ?: $req,
                'grant' => $existing,
                'activation' => $activation,
            ];
        }
        // Approved but missing grant - repair path below (with row lock).
    } elseif ($status !== 'pending') {
        return ['ok' => false, 'error' => 'not_pending', 'request' => $req];
    }

    mysqli_begin_transaction($conn);
    $grantId = 0;
    try {
        // Serialize pending approve and approved-without-grant repair.
        $locked = commerce_far_lock_request_for_update($conn, $requestId);
        if (!$locked) {
            throw new RuntimeException('lock_failed');
        }
        $status = $locked['status'];
        if ((int) $locked['user_id'] !== $userId) {
            throw new RuntimeException('user_mismatch');
        }

        if ($status === 'pending') {
            $claim = mysqli_prepare(
                $conn,
                "UPDATE free_access_requests SET
                    status = 'approved',
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    admin_note = ?
                 WHERE request_id = ?
                   AND status = 'pending'
                 LIMIT 1"
            );
            if (!$claim) {
                throw new RuntimeException('claim_prepare_failed');
            }
            mysqli_stmt_bind_param($claim, 'isi', $adminId, $noteOrNull, $requestId);
            if (!mysqli_stmt_execute($claim)) {
                mysqli_stmt_close($claim);
                throw new RuntimeException('claim_execute_failed');
            }
            $affected = mysqli_stmt_affected_rows($claim);
            mysqli_stmt_close($claim);
            if ($affected < 1) {
                // Should be rare after FOR UPDATE; treat like lost race.
                $existing = commerce_far_existing_full_lms_grant($conn, $requestId);
                if ($existing) {
                    if (!sca_upsert_permissions($conn, $userId, [['content_type' => 'full_lms', 'content_id' => 0]], $adminId)) {
                        throw new RuntimeException('sca_upsert_failed');
                    }
                    mysqli_commit($conn);
                    $activation = commerce_far_activate_login_after_grant($conn, $userId, $months, $existing);
                    return [
                        'ok' => true,
                        'skipped' => true,
                        'error' => 'already_approved',
                        'request' => commerce_far_get_request($conn, $requestId) ?: $req,
                        'grant' => $existing,
                        'activation' => $activation,
                    ];
                }
                mysqli_commit($conn);
                return [
                    'ok' => false,
                    'error' => 'claim_lost_race',
                    'request' => commerce_far_get_request($conn, $requestId) ?: $req,
                ];
            }
        } elseif ($status === 'approved') {
            // Repair path (already locked). Fall through to grant create/skip.
        } else {
            mysqli_rollback($conn);
            return [
                'ok' => false,
                'error' => 'not_pending',
                'request' => commerce_far_get_request($conn, $requestId) ?: $req,
            ];
        }

        $existing = commerce_far_existing_full_lms_grant($conn, $requestId);
        if ($existing) {
            if (!sca_upsert_permissions($conn, $userId, [['content_type' => 'full_lms', 'content_id' => 0]], $adminId)) {
                throw new RuntimeException('sca_upsert_failed');
            }
            mysqli_commit($conn);
            $activation = commerce_far_activate_login_after_grant($conn, $userId, $months, $existing);
            return [
                'ok' => true,
                'skipped' => true,
                'error' => 'grant_already_exists',
                'request' => commerce_far_get_request($conn, $requestId) ?: $req,
                'grant' => $existing,
                'activation' => $activation,
            ];
        }

        // starts_at = NOW(); ends_at = NOW() + N calendar months (MySQL DATE_ADD).
        $source = 'free_access';
        $ctype = 'full_lms';
        $cid = 0;
        $label = 'Free Access (Full LMS)';
        $gStatus = 'active';

        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO access_grants
              (user_id, source, payment_id, payment_item_id, free_access_request_id,
               content_type, content_id, content_label, starts_at, ends_at, status, granted_by)
             VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH), ?, ?)'
        );
        if (!$ins) {
            throw new RuntimeException('grant_prepare_failed');
        }
        mysqli_stmt_bind_param(
            $ins,
            'isisisisi',
            $userId,
            $source,
            $requestId,
            $ctype,
            $cid,
            $label,
            $months,
            $gStatus,
            $adminId
        );
        $dupKey = false;
        $errno = 0;
        $err = '';
        try {
            $execOk = mysqli_stmt_execute($ins);
            if (!$execOk) {
                $errno = mysqli_errno($conn);
                $err = mysqli_error($conn);
            }
        } catch (Throwable $insEx) {
            $errno = (int) ($insEx->getCode() > 0 ? $insEx->getCode() : mysqli_errno($conn));
            $err = $insEx->getMessage();
            if ($errno !== 1062 && stripos($err, 'Duplicate entry') === false) {
                mysqli_stmt_close($ins);
                throw $insEx;
            }
            $execOk = false;
            $dupKey = true;
        }
        if (!$execOk) {
            mysqli_stmt_close($ins);
            // Defense-in-depth: concurrent repair lost the UNIQUE race.
            if ($dupKey || $errno === 1062 || ($err !== '' && stripos($err, 'Duplicate entry') !== false)) {
                $existing = commerce_far_existing_full_lms_grant($conn, $requestId);
                if (!$existing) {
                    throw new RuntimeException('grant_duplicate_but_missing:' . $err);
                }
                if (!sca_upsert_permissions($conn, $userId, [['content_type' => 'full_lms', 'content_id' => 0]], $adminId)) {
                    throw new RuntimeException('sca_upsert_failed');
                }
                mysqli_commit($conn);
                $activation = commerce_far_activate_login_after_grant($conn, $userId, $months, $existing);
                return [
                    'ok' => true,
                    'skipped' => true,
                    'error' => 'grant_duplicate_key',
                    'request' => commerce_far_get_request($conn, $requestId) ?: $req,
                    'grant' => $existing,
                    'activation' => $activation,
                ];
            }
            throw new RuntimeException('grant_insert_failed:' . $err);
        }
        $grantId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($ins);

        if (!sca_upsert_permissions($conn, $userId, [['content_type' => 'full_lms', 'content_id' => 0]], $adminId)) {
            throw new RuntimeException('sca_upsert_failed');
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('commerce_far_approve: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $grant = null;
    $gs = mysqli_prepare($conn, 'SELECT * FROM access_grants WHERE grant_id = ? LIMIT 1');
    if ($gs) {
        mysqli_stmt_bind_param($gs, 'i', $grantId);
        mysqli_stmt_execute($gs);
        $gr = mysqli_stmt_get_result($gs);
        $grant = $gr ? mysqli_fetch_assoc($gr) : null;
        mysqli_stmt_close($gs);
    }

    // COMMIT succeeded (real grant create) - activate login, then notify (both best-effort).
    $activation = commerce_far_activate_login_after_grant($conn, $userId, $months, is_array($grant) ? $grant : null);
    if (empty($activation['ok'])) {
        error_log(
            'commerce_far_approve: activation failed after grant request_id='
            . $requestId . ' user_id=' . $userId
            . ' err=' . (string) ($activation['error'] ?? '')
        );
    }

    $notify = ['ok' => false, 'error' => 'notify_not_attempted', 'sent' => false];
    try {
        $notify = commerce_notify_far_approved($conn, $requestId, $months);
    } catch (Throwable $ne) {
        error_log('commerce_far_approve notify: ' . $ne->getMessage());
        $notify = ['ok' => false, 'error' => 'notify_exception', 'sent' => false];
    }

    return [
        'ok' => true,
        'skipped' => false,
        'request' => commerce_far_get_request($conn, $requestId) ?: $req,
        'grant' => $grant,
        'activation' => $activation,
        'notify' => $notify,
    ];
}

/**
 * Reject a pending Free Access request.
 *
 * @return array{ok:bool,error?:string,skipped?:bool,request?:array}
 */
function commerce_far_reject(
    mysqli $conn,
    int $requestId,
    int $adminId,
    string $adminNote = ''
): array {
    if ($requestId <= 0 || $adminId <= 0) {
        return ['ok' => false, 'error' => 'invalid_ids'];
    }
    $req = commerce_far_get_request($conn, $requestId);
    if (!$req) {
        return ['ok' => false, 'error' => 'request_not_found'];
    }
    $status = (string) ($req['status'] ?? '');
    if ($status === 'rejected') {
        return [
            'ok' => true,
            'skipped' => true,
            'error' => 'already_rejected',
            'request' => $req,
        ];
    }
    if ($status !== 'pending') {
        return ['ok' => false, 'error' => 'not_pending', 'request' => $req];
    }

    $note = trim($adminNote);
    if (strlen($note) > 2000) {
        $note = substr($note, 0, 2000);
    }
    $noteOrNull = $note === '' ? null : $note;

    $upd = mysqli_prepare(
        $conn,
        "UPDATE free_access_requests SET
            status = 'rejected',
            reviewed_by = ?,
            reviewed_at = NOW(),
            admin_note = ?
         WHERE request_id = ?
           AND status = 'pending'
         LIMIT 1"
    );
    if (!$upd) {
        return ['ok' => false, 'error' => 'reject_prepare_failed'];
    }
    mysqli_stmt_bind_param($upd, 'isi', $adminId, $noteOrNull, $requestId);
    if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
        mysqli_stmt_close($upd);
        $fresh = commerce_far_get_request($conn, $requestId);
        if ($fresh && ($fresh['status'] ?? '') === 'rejected') {
            return ['ok' => true, 'skipped' => true, 'error' => 'already_rejected', 'request' => $fresh];
        }
        return ['ok' => false, 'error' => 'reject_race_or_state', 'request' => $fresh ?: $req];
    }
    mysqli_stmt_close($upd);

    // Rejection transition succeeded - notification is best-effort.
    $notify = ['ok' => false, 'error' => 'notify_not_attempted', 'sent' => false];
    try {
        $notify = commerce_notify_far_rejected($conn, $requestId);
    } catch (Throwable $ne) {
        error_log('commerce_far_reject notify: ' . $ne->getMessage());
        $notify = ['ok' => false, 'error' => 'notify_exception', 'sent' => false];
    }

    return [
        'ok' => true,
        'request' => commerce_far_get_request($conn, $requestId) ?: $req,
        'notify' => $notify,
    ];
}
