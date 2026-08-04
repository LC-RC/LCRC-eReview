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
            return [
                'ok' => false,
                'error' => 'Your account is waiting for access approval. You can sign in after an admin grants access or verifies payment.',
                'error_type' => 'no_active_access',
            ];
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
