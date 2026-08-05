<?php
/**
 * Single source of truth helpers: LMS student enrollment/login ↔ active access_grants.
 *
 * Active grant = status active, ends_at > NOW(), source in purchase|free_access|admin_manual.
 */
declare(strict_types=1);

require_once __DIR__ . '/commerce_catalog.php';

/**
 * SQL fragment: users row has an active commerce/admin grant.
 * Always pass a table-qualified column (e.g. users.user_id / u.user_id).
 * Bare `user_id` is rewritten to users.user_id — otherwise MySQL binds it to
 * access_grants.user_id inside EXISTS and every student looks "enrolled".
 */
function commerce_sql_user_has_active_grant(string $userIdExpr = 'users.user_id'): string
{
    $expr = trim($userIdExpr);
    if ($expr === '' || strcasecmp($expr, 'user_id') === 0) {
        $expr = 'users.user_id';
    }
    return "EXISTS (
        SELECT 1 FROM access_grants _ag
        WHERE _ag.user_id = {$expr}
          AND _ag.status = 'active'
          AND _ag.ends_at > NOW()
          AND _ag.source IN ('purchase','free_access','admin_manual')
    )";
}

function commerce_student_has_active_access(mysqli $conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    if (!function_exists('commerce_schema_ready') || !commerce_schema_ready($conn)) {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1 FROM access_grants
         WHERE user_id = ?
           AND status = 'active'
           AND ends_at > NOW()
           AND source IN ('purchase','free_access','admin_manual')
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ok = $res && (bool) mysqli_fetch_row($res);
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * Legacy enrolled signal: had LMS content access before commerce grants existed,
 * or still has a valid access window / prior approval markers.
 * True new registrations (pending, no SCA, no access window) return false.
 *
 * @param array<string,mixed> $user
 */
function commerce_student_has_legacy_enrollment_signal(mysqli $conn, int $userId, array $user = []): bool
{
    if ($userId <= 0) {
        return false;
    }

    $path = strtolower(trim((string) ($user['enrollment_path'] ?? '')));
    // Unpaid commerce funnel students must not auto-restore.
    if (in_array($path, ['package', 'by_topic', 'free_access'], true)) {
        // Only restore commerce-path students if they already had SCA (fulfilled before demote).
        $scaOnly = @mysqli_query(
            $conn,
            'SELECT 1 FROM student_content_permissions WHERE user_id = ' . (int) $userId . ' LIMIT 1'
        );
        return (bool) ($scaOnly && mysqli_fetch_row($scaOnly));
    }

    $sca = @mysqli_query(
        $conn,
        'SELECT 1 FROM student_content_permissions WHERE user_id = ' . (int) $userId . ' LIMIT 1'
    );
    if ($sca && mysqli_fetch_row($sca)) {
        return true;
    }

    $status = strtolower((string) ($user['status'] ?? ''));
    $end = trim((string) ($user['access_end'] ?? ''));
    if ($end !== '') {
        $endTs = strtotime($end);
        if ($endTs !== false && $endTs > time()) {
            return true;
        }
    }

    $start = trim((string) ($user['access_start'] ?? ''));
    if ($status === 'approved' && $start !== '') {
        return true;
    }

    return false;
}

/**
 * Backfill admin_manual grant + re-approve for legacy enrolled students missing grants.
 *
 * @return array{ok:bool,restored?:bool,skipped?:bool,error?:string}
 */
function commerce_student_try_restore_legacy_access(mysqli $conn, int $userId, array $user = []): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user'];
    }
    if (!commerce_schema_ready($conn)) {
        return ['ok' => true, 'skipped' => true];
    }
    if (commerce_student_has_active_access($conn, $userId)) {
        return ['ok' => true, 'skipped' => true, 'restored' => false];
    }
    // Login/session/CLI callers often pass a partial users row — reload for restore decisions.
    $needsReload = $user === []
        || !array_key_exists('role', $user)
        || !array_key_exists('enrollment_path', $user)
        || !array_key_exists('access_start', $user)
        || !array_key_exists('access_months', $user);
    if ($needsReload) {
        $ust = mysqli_prepare(
            $conn,
            'SELECT user_id, role, status, enrollment_path, access_start, access_end, access_months
             FROM users WHERE user_id = ? LIMIT 1'
        );
        if (!$ust) {
            return ['ok' => false, 'error' => 'user_prepare_failed'];
        }
        mysqli_stmt_bind_param($ust, 'i', $userId);
        mysqli_stmt_execute($ust);
        $ures = mysqli_stmt_get_result($ust);
        $loaded = $ures ? (mysqli_fetch_assoc($ures) ?: []) : [];
        mysqli_stmt_close($ust);
        if ($loaded !== []) {
            $user = $loaded;
        }
    }
    if ($user === [] || (string) ($user['role'] ?? '') !== 'student') {
        return ['ok' => false, 'error' => 'not_student'];
    }
    if (strtolower((string) ($user['status'] ?? '')) === 'rejected') {
        return ['ok' => false, 'error' => 'rejected'];
    }
    if (!commerce_student_has_legacy_enrollment_signal($conn, $userId, $user)) {
        return ['ok' => true, 'skipped' => true, 'restored' => false];
    }

    if (!function_exists('commerce_admin_grant_manual_access')) {
        $grantFile = __DIR__ . '/commerce_admin_manual_grant.php';
        if (is_file($grantFile)) {
            require_once $grantFile;
        }
    }
    if (!function_exists('commerce_admin_grant_manual_access')) {
        return ['ok' => false, 'error' => 'grant_helper_missing'];
    }

    $adminId = 0;
    $adminRes = @mysqli_query(
        $conn,
        "SELECT user_id FROM users WHERE role = 'admin' ORDER BY user_id ASC LIMIT 1"
    );
    if ($adminRes && ($adminRow = mysqli_fetch_row($adminRes))) {
        $adminId = (int) ($adminRow[0] ?? 0);
    }
    if ($adminId <= 0) {
        return ['ok' => false, 'error' => 'no_admin'];
    }

    $months = (int) ($user['access_months'] ?? 0);
    $endRaw = trim((string) ($user['access_end'] ?? ''));
    if ($months < 1 && $endRaw !== '') {
        $endTs = strtotime($endRaw);
        if ($endTs !== false && $endTs > time()) {
            $months = max(1, (int) ceil(($endTs - time()) / (30 * 86400)));
        }
    }
    if ($months < 1) {
        $months = 6;
    }
    if ($months > 120) {
        $months = 120;
    }

    $g = commerce_admin_grant_manual_access($conn, $userId, $adminId, [
        'months' => $months,
        'activate_login' => true,
        'close_open_payment' => false,
        'notify_student' => false,
        'label' => 'Legacy enrolled access restore',
    ]);
    if (empty($g['ok'])) {
        return ['ok' => false, 'error' => (string) ($g['error'] ?? 'restore_failed')];
    }

    if ($endRaw !== '') {
        $endTs = strtotime($endRaw);
        if ($endTs) {
            require_once __DIR__ . '/commerce_fulfillment.php';
            if (function_exists('commerce_fulfill_maybe_extend_access_end')) {
                commerce_fulfill_maybe_extend_access_end($conn, $userId, $endTs);
            }
        }
    }

    return ['ok' => true, 'restored' => true];
}

/**
 * Whether a learner may log in / keep a session.
 *
 * @param array<string,mixed> $user users row (needs user_id, role, status; access_end optional)
 * @return array{ok:bool,error?:string,error_type?:string}
 */
function commerce_student_can_login(mysqli $conn, array $user): array
{
    $role = (string) ($user['role'] ?? '');
    if ($role === '' || (function_exists('isStaffRole') && isStaffRole($role))) {
        return ['ok' => true];
    }
    // Non-LMS roles (e.g. college) keep status-only gate elsewhere.
    if ($role !== 'student') {
        $status = strtolower((string) ($user['status'] ?? ''));
        if ($status !== 'approved') {
            return [
                'ok' => false,
                'error' => 'Your account is not approved yet.',
                'error_type' => 'not_approved',
            ];
        }
        return ['ok' => true];
    }

    $status = strtolower((string) ($user['status'] ?? ''));
    if ($status === 'rejected') {
        return [
            'ok' => false,
            'error' => 'Your account has been rejected.',
            'error_type' => 'rejected',
        ];
    }

    $userId = (int) ($user['user_id'] ?? 0);
    $schemaReady = function_exists('commerce_schema_ready') && commerce_schema_ready($conn);

    if ($schemaReady) {
        if (!commerce_student_has_active_access($conn, $userId)) {
            // Previously enrolled students (SCA / access window) get a one-time grant restore.
            // Brand-new pending registrations without LMS history stay blocked.
            $restore = commerce_student_try_restore_legacy_access($conn, $userId, $user);
            if (empty($restore['restored']) || !commerce_student_has_active_access($conn, $userId)) {
                return [
                    'ok' => false,
                    'error' => 'Your account is waiting for access approval. You can sign in after an admin grants access or verifies payment.',
                    'error_type' => 'no_active_access',
                ];
            }
            // Reload access_end after restore for expiry check below.
            $refresh = mysqli_prepare($conn, 'SELECT access_end, status FROM users WHERE user_id = ? LIMIT 1');
            if ($refresh) {
                mysqli_stmt_bind_param($refresh, 'i', $userId);
                mysqli_stmt_execute($refresh);
                $rr = mysqli_stmt_get_result($refresh);
                $row = $rr ? mysqli_fetch_assoc($rr) : null;
                mysqli_stmt_close($refresh);
                if ($row) {
                    $user['access_end'] = $row['access_end'] ?? $user['access_end'] ?? null;
                    $user['status'] = $row['status'] ?? $user['status'] ?? null;
                    $status = strtolower((string) ($user['status'] ?? $status));
                }
            }
        }
    } else {
        // Pre-commerce schema fallback.
        if ($status !== 'approved') {
            return [
                'ok' => false,
                'error' => 'Your account is not approved yet.',
                'error_type' => 'not_approved',
            ];
        }
    }

    $end = trim((string) ($user['access_end'] ?? ''));
    if ($end !== '') {
        $endTs = strtotime($end);
        if ($endTs !== false && $endTs < time()) {
            return [
                'ok' => false,
                'error' => 'Your access has expired.',
                'error_type' => 'access_expired',
            ];
        }
    }

    return ['ok' => true];
}

/**
 * If student is approved but has no active grant, demote to pending (clears login window).
 * Skips legacy enrolled students (SCA / access window) — restore grants instead of demoting.
 *
 * @return array{ok:bool,demoted?:bool,skipped?:bool,error?:string}
 */
function commerce_student_demote_if_no_active_grant(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user'];
    }
    if (!commerce_schema_ready($conn)) {
        return ['ok' => true, 'skipped' => true];
    }
    if (commerce_student_has_active_access($conn, $userId)) {
        return ['ok' => true, 'skipped' => true, 'demoted' => false];
    }

    $ust = mysqli_prepare(
        $conn,
        'SELECT user_id, role, status, enrollment_path, access_start, access_end, access_months
         FROM users WHERE user_id = ? AND role = \'student\' LIMIT 1'
    );
    $user = [];
    if ($ust) {
        mysqli_stmt_bind_param($ust, 'i', $userId);
        mysqli_stmt_execute($ust);
        $ures = mysqli_stmt_get_result($ust);
        $user = $ures ? (mysqli_fetch_assoc($ures) ?: []) : [];
        mysqli_stmt_close($ust);
    }
    if ($user === []) {
        return ['ok' => true, 'skipped' => true, 'demoted' => false];
    }

    // Never demote previously enrolled LMS students — backfill grants instead.
    if (commerce_student_has_legacy_enrollment_signal($conn, $userId, $user)) {
        $restore = commerce_student_try_restore_legacy_access($conn, $userId, $user);
        if (!empty($restore['restored']) || commerce_student_has_active_access($conn, $userId)) {
            return ['ok' => true, 'skipped' => true, 'demoted' => false];
        }
        // If restore failed, still do not wipe their enrollment window.
        return ['ok' => true, 'skipped' => true, 'demoted' => false];
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE users
         SET status = 'pending',
             access_start = NULL,
             access_end = NULL,
             access_months = NULL
         WHERE user_id = ?
           AND role = 'student'
           AND status = 'approved'
         LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'demote_prepare_failed'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'demote_failed'];
    }
    $affected = (int) mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    return ['ok' => true, 'demoted' => $affected > 0, 'skipped' => $affected === 0];
}
