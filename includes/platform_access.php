<?php
declare(strict_types=1);

/**
 * Platform access: eReview (access_grants) vs College Examination (module flag).
 * One users.user_id; modules are independent.
 */

require_once __DIR__ . '/commerce_access_gate.php';

function ereview_platform_access_columns_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'college_examination_access'");
    $ready = (bool) ($chk && mysqli_fetch_assoc($chk));
    if ($chk) {
        mysqli_free_result($chk);
    }

    return $ready;
}

/**
 * @return array<string,mixed>|null
 */
function ereview_user_load_platform_row(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $cols = ereview_platform_access_columns_ready($conn)
        ? 'user_id, role, status, access_end, review_type, section, student_number, college_examination_access'
        : 'user_id, role, status, access_end, review_type, section, student_number';
    $st = mysqli_prepare($conn, "SELECT {$cols} FROM users WHERE user_id=? LIMIT 1");
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'i', $userId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    return $row ?: null;
}

/**
 * Login/session SELECTs often omit college_examination_access.
 * Reload the platform row when that flag is missing so dual-access routing works.
 *
 * @param array<string,mixed>|null $user
 * @return array<string,mixed>|null
 */
function ereview_user_ensure_platform_fields(mysqli $conn, int $userId, ?array $user): ?array
{
    if ($userId <= 0) {
        return null;
    }
    if ($user === null || $user === []) {
        return ereview_user_load_platform_row($conn, $userId);
    }
    if (!ereview_platform_access_columns_ready($conn)) {
        return $user;
    }
    if (array_key_exists('college_examination_access', $user)) {
        return $user;
    }
    $loaded = ereview_user_load_platform_row($conn, $userId);

    return $loaded ?: $user;
}

function ereview_user_college_examination_access_value(array $user): string
{
    if (!array_key_exists('college_examination_access', $user)) {
        return ($user['role'] ?? '') === 'college_student' ? 'active' : 'none';
    }
    $v = strtolower(trim((string) ($user['college_examination_access'] ?? 'none')));

    return in_array($v, ['none', 'active', 'suspended'], true) ? $v : 'none';
}

/**
 * SQL WHERE fragment for listing/searching college examinees (approved + module or legacy role).
 */
function ereview_sql_college_examinee_where(string $alias = 'u'): string
{
    $a = preg_replace('/[^a-z_]/', '', $alias) ?: 'u';

    return "({$a}.role='college_student' OR ({$a}.role='student' AND {$a}.college_examination_access='active')) AND {$a}.status='approved'";
}

/** @deprecated alias */
function ereview_sql_college_examinee_predicate(string $alias = 'u'): string
{
    return ereview_sql_college_examinee_where($alias);
}

/**
 * Gate College Examination examinee pages (DB-backed; not session role alone).
 */
function ereview_require_college_examination_portal(): void
{
    global $conn;
    require_once dirname(__DIR__) . '/auth.php';
    requireLogin();
    $userId = getCurrentUserId();
    if ($userId === null || $userId <= 0 || !ereview_user_has_college_examination_access($conn, $userId)) {
        $_SESSION['error'] = 'You do not have permission to access College Examination.';
        ereview_redirect('index');
        exit;
    }
    $_SESSION['active_portal'] = 'college_examination';
}

/**
 * Load examinee profile for assignment/eligibility (legacy role or module-enabled student).
 *
 * @return array<string,mixed>|null
 */
function ereview_load_college_examinee_user(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0 || !ereview_user_has_college_examination_access($conn, $userId)) {
        return null;
    }
    $st = mysqli_prepare(
        $conn,
        "SELECT user_id, role, status, review_type, TRIM(COALESCE(section,'')) AS section, student_number
         FROM users WHERE user_id=? LIMIT 1"
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'i', $userId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    return $row ?: null;
}

function ereview_user_has_ereview_access(mysqli $conn, int $userId, ?array $user = null): bool
{
    $user = ereview_user_ensure_platform_fields($conn, $userId, $user);
    if (!$user || ($user['role'] ?? '') !== 'student') {
        return false;
    }
    if (strtolower((string) ($user['status'] ?? '')) === 'rejected') {
        return false;
    }
    $gate = commerce_student_can_login($conn, $user);

    return !empty($gate['ok']);
}

/**
 * Whether the user may use the College Examination portal.
 */
function ereview_user_has_college_examination_access(mysqli $conn, int $userId, ?array $user = null): bool
{
    $user = ereview_user_ensure_platform_fields($conn, $userId, $user);
    if (!$user) {
        return false;
    }
    if (strtolower((string) ($user['status'] ?? '')) !== 'approved') {
        return false;
    }
    $access = ereview_user_college_examination_access_value($user);
    if ($access === 'suspended') {
        return false;
    }
    if (($user['role'] ?? '') === 'college_student') {
        return true;
    }

    return $access === 'active';
}

/**
 * @return list<string> 'ereview' | 'college_examination'
 */
function ereview_user_available_portals(mysqli $conn, int $userId, ?array $user = null): array
{
    $user = ereview_user_ensure_platform_fields($conn, $userId, $user);
    if (!$user) {
        return [];
    }
    if (function_exists('isStaffRole') && isStaffRole((string) ($user['role'] ?? ''))) {
        return [];
    }
    $portals = [];
    if (ereview_user_has_ereview_access($conn, $userId, $user)) {
        $portals[] = 'ereview';
    }
    if (ereview_user_has_college_examination_access($conn, $userId, $user)) {
        $portals[] = 'college_examination';
    }

    return $portals;
}

/**
 * @param array<string,mixed> $user
 * @return array{ok:bool,error?:string,error_type?:string}
 */
function ereview_user_can_authenticate(mysqli $conn, array $user): array
{
    $role = (string) ($user['role'] ?? '');
    if ($role !== '' && function_exists('isStaffRole') && isStaffRole($role)) {
        return ['ok' => true];
    }
    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid account.', 'error_type' => 'invalid_user'];
    }
    $user = ereview_user_ensure_platform_fields($conn, $userId, $user) ?? $user;
    if (ereview_user_has_ereview_access($conn, $userId, $user)) {
        return ['ok' => true];
    }
    if (ereview_user_has_college_examination_access($conn, $userId, $user)) {
        return ['ok' => true];
    }

    // Preserve legacy messaging for eReview students waiting on grants.
    if (($user['role'] ?? $role) === 'student') {
        $gate = commerce_student_can_login($conn, $user);
        if (empty($gate['ok'])) {
            return $gate;
        }
    }

    return [
        'ok' => false,
        'error' => 'Your account does not have access to any platform module.',
        'error_type' => 'no_platform_access',
    ];
}

function ereview_portal_dashboard_url(string $portal): string
{
    require_once __DIR__ . '/url_helpers.php';
    if ($portal === 'college_examination') {
        return ereview_url('college_student_dashboard');
    }

    return ereview_url('student_dashboard');
}

/**
 * Resolve post-login destination; may return portal selector URL.
 */
function ereview_user_post_login_url(mysqli $conn, int $userId, ?array $user = null): string
{
    $user = ereview_user_ensure_platform_fields($conn, $userId, $user);
    if (!$user) {
        return ereview_url('login');
    }
    $role = (string) ($user['role'] ?? '');
    if (function_exists('isStaffRole') && isStaffRole($role)) {
        return function_exists('dashboardUrlForRole') ? dashboardUrlForRole($role) : ereview_url('admin_dashboard');
    }
    $portals = ereview_user_available_portals($conn, $userId, $user);
    if (count($portals) === 0) {
        return ereview_url('login');
    }

    // Examination-mode lock: college_examination_access=active routes to the College
    // Examination portal. eReview grants/SCA stay intact and are not revoked.
    if (in_array('college_examination', $portals, true)) {
        $_SESSION['active_portal'] = 'college_examination';

        return ereview_portal_dashboard_url('college_examination');
    }

    if (count($portals) === 1) {
        $_SESSION['active_portal'] = $portals[0];

        return ereview_portal_dashboard_url($portals[0]);
    }

    return ereview_url('portal_select');
}
