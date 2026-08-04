<?php
/**
 * Commerce → login activation (post-success only).
 *
 * Called AFTER successful payment fulfillment or FAR grant creation commits.
 * Does NOT fulfill payments, create grants, rewrite SCA, or touch payment ledger.
 * Idempotent: pending→approved once; never downgrades approved; never activates rejected.
 */

declare(strict_types=1);

require_once __DIR__ . '/commerce_access_gate.php';

/**
 * Max future ends_at among active commerce grants for a student, or null.
 */
function commerce_user_max_active_grant_ends_ts(mysqli $conn, int $userId): ?int
{
    if ($userId <= 0) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT MAX(ends_at) AS max_end
         FROM access_grants
         WHERE user_id = ?
           AND status = 'active'
           AND ends_at > NOW()
           AND source IN ('purchase', 'free_access', 'admin_manual')"
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row || empty($row['max_end'])) {
        return null;
    }
    $ts = strtotime((string) $row['max_end']);
    return ($ts !== false && $ts > 0) ? $ts : null;
}

/**
 * Activate student login after commerce content access is established.
 *
 * @param array{
 *   access_end_ts?:int|null,
 *   access_months?:int|null,
 *   source?:string,
 *   require_active_grant?:bool
 * } $opts
 * @return array{
 *   ok:bool,
 *   activated?:bool,
 *   already_approved?:bool,
 *   skipped?:bool,
 *   error?:string,
 *   user_status?:string
 * }
 */
function commerce_activate_user_after_commerce_success(mysqli $conn, int $userId, array $opts = []): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user_id'];
    }

    $requireGrant = array_key_exists('require_active_grant', $opts)
        ? (bool) $opts['require_active_grant']
        : true;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT user_id, role, status, access_start, access_end, access_months
         FROM users WHERE user_id = ? LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'user_prepare_failed'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $user = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$user || (string) ($user['role'] ?? '') !== 'student') {
        return ['ok' => false, 'error' => 'not_student'];
    }

    $status = strtolower((string) ($user['status'] ?? ''));
    if ($status === 'rejected') {
        return [
            'ok' => true,
            'skipped' => true,
            'activated' => false,
            'error' => 'rejected_not_activated',
            'user_status' => 'rejected',
        ];
    }

    $endTs = isset($opts['access_end_ts']) ? (int) $opts['access_end_ts'] : 0;
    if ($endTs <= 0) {
        $endTs = (int) (commerce_user_max_active_grant_ends_ts($conn, $userId) ?? 0);
    }

    if ($requireGrant && $endTs <= 0) {
        // No usable commerce grant window — do not invent login approval.
        return [
            'ok' => false,
            'error' => 'no_active_commerce_grant',
            'activated' => false,
            'user_status' => $status,
        ];
    }

    if ($status === 'approved') {
        // Already active: never downgrade; optionally extend account window (never shorten).
        if ($endTs > 0 && function_exists('commerce_fulfill_maybe_extend_access_end')) {
            commerce_fulfill_maybe_extend_access_end($conn, $userId, $endTs);
        } elseif ($endTs > 0) {
            $endRaw = trim((string) ($user['access_end'] ?? ''));
            if ($endRaw !== '') {
                $curTs = strtotime($endRaw);
                if ($curTs !== false && $curTs < $endTs) {
                    $newEnd = date('Y-m-d H:i:s', $endTs);
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
                    if ($upd) {
                        mysqli_stmt_bind_param($upd, 'sis', $newEndBind, $uidBind, $cmpEndBind);
                        try {
                            mysqli_stmt_execute($upd);
                        } catch (Throwable $e) {
                            error_log('commerce_activate extend: ' . $e->getMessage());
                        }
                        mysqli_stmt_close($upd);
                    }
                }
            }
        }
        return [
            'ok' => true,
            'activated' => false,
            'already_approved' => true,
            'skipped' => true,
            'user_status' => 'approved',
        ];
    }

    if ($status !== 'pending') {
        return [
            'ok' => true,
            'skipped' => true,
            'activated' => false,
            'error' => 'unexpected_status',
            'user_status' => $status,
        ];
    }

    $months = isset($opts['access_months']) ? (int) $opts['access_months'] : 0;
    if ($months < 1 && $endTs > time()) {
        $months = (int) max(1, (int) round(($endTs - time()) / (30 * 86400)));
    }
    if ($months < 1) {
        $months = 1;
    }

    $accessEnd = $endTs > 0 ? date('Y-m-d H:i:s', $endTs) : null;
    if ($accessEnd === null) {
        return ['ok' => false, 'error' => 'missing_access_end', 'activated' => false, 'user_status' => 'pending'];
    }

    $existingStart = trim((string) ($user['access_start'] ?? ''));
    $accessStart = ($existingStart !== '' && $existingStart !== '0000-00-00 00:00:00')
        ? $existingStart
        : date('Y-m-d H:i:s');

    $upd = mysqli_prepare(
        $conn,
        "UPDATE users SET
            status = 'approved',
            access_start = ?,
            access_end = ?,
            access_months = ?
         WHERE user_id = ?
           AND role = 'student'
           AND status = 'pending'
         LIMIT 1"
    );
    if (!$upd) {
        return ['ok' => false, 'error' => 'activate_prepare_failed', 'activated' => false];
    }
    mysqli_stmt_bind_param($upd, 'ssii', $accessStart, $accessEnd, $months, $userId);
    if (!mysqli_stmt_execute($upd)) {
        mysqli_stmt_close($upd);
        error_log('commerce_activate_user_after_commerce_success: update failed for user ' . $userId);
        return ['ok' => false, 'error' => 'activate_execute_failed', 'activated' => false];
    }
    $affected = mysqli_stmt_affected_rows($upd);
    mysqli_stmt_close($upd);

    if ($affected < 1) {
        // Race: another path approved concurrently.
        $check = mysqli_prepare($conn, "SELECT status FROM users WHERE user_id = ? LIMIT 1");
        $nowStatus = 'pending';
        if ($check) {
            mysqli_stmt_bind_param($check, 'i', $userId);
            mysqli_stmt_execute($check);
            $cr = mysqli_stmt_get_result($check);
            $crow = $cr ? mysqli_fetch_assoc($cr) : null;
            mysqli_stmt_close($check);
            $nowStatus = strtolower((string) ($crow['status'] ?? 'pending'));
        }
        return [
            'ok' => true,
            'activated' => false,
            'already_approved' => $nowStatus === 'approved',
            'skipped' => true,
            'user_status' => $nowStatus,
        ];
    }

    return [
        'ok' => true,
        'activated' => true,
        'already_approved' => false,
        'user_status' => 'approved',
    ];
}
