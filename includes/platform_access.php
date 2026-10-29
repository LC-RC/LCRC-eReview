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

    return "{$a}.status='approved' AND {$a}.college_examination_access<>'suspended' AND ({$a}.role='college_student' OR ({$a}.role='student' AND {$a}.college_examination_access='active'))";
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
        $row = ($userId !== null && $userId > 0) ? ereview_user_load_platform_row($conn, $userId) : null;
        $gate = $row ? ereview_user_can_authenticate($conn, $row) : ['ok' => false];
        if (empty($gate['ok'])) {
            ereview_force_student_session_end(
                (string) ($gate['error'] ?? 'You do not have permission to access College Examination.'),
                (string) ($gate['error_type'] ?? 'no_platform_access')
            );
        }
        $_SESSION['error'] = 'You do not have permission to access College Examination.';
        ereview_redirect('index');
        exit;
    }
    if (!ereview_session_can_enter_portal($conn, 'college_examination', $userId)) {
        if (ereview_session_can_enter_portal($conn, 'ereview', $userId)) {
            $_SESSION['error'] = 'Sign in with your College Examination password to open that portal.';
            ereview_redirect('student_dashboard');
            exit;
        }
        ereview_session_end_no_enterable_portal(true);
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
    if (strtolower((string) ($user['status'] ?? '')) === 'rejected'
        || strtolower((string) ($user['status'] ?? '')) === 'archived') {
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
 * Server-side LMS student session gate (status / grant / access_end).
 * Called from requireRole('student') so every student page and AJAX endpoint
 * that uses requireRole is covered centrally.
 *
 * Allowlist: scripts pending students may still need without full LMS access.
 */
function ereview_enforce_lms_student_session(?mysqli $conn = null): void
{
    global $conn;
    if ($conn === null) {
        $conn = $GLOBALS['conn'] ?? null;
    }
    if (!$conn instanceof mysqli) {
        return;
    }
    if (!isLoggedIn() || getCurrentUserRole() !== 'student') {
        return;
    }

    $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    // Payment proof / messages may be needed while resolving access issues.
    $allowWithoutFullAccess = [
        'student_payment_proof.php' => true,
        'logout.php' => true,
        'portal_select.php' => true,
        'auth_success.php' => true,
    ];
    if (isset($allowWithoutFullAccess[$script])) {
        // Still hard-block archived / rejected even on allowlisted pages.
        $uid = (int) (getCurrentUserId() ?? 0);
        $row = ereview_user_load_platform_row($conn, $uid);
        $st = strtolower((string) ($row['status'] ?? ''));
        if ($st === 'archived' || $st === 'rejected') {
            ereview_force_student_session_end(
                (string) ($st === 'archived'
                    ? 'Your account has been archived. Contact an administrator to restore access.'
                    : 'Your account has been rejected.'),
                $st
            );
        }
        return;
    }

    $uid = (int) (getCurrentUserId() ?? 0);
    $user = ereview_user_load_platform_row($conn, $uid);
    if (!$user) {
        ereview_force_student_session_end('Your session is no longer valid. Please sign in again.', 'invalid_user');
    }

    require_once __DIR__ . '/commerce_access_gate.php';
    $gate = commerce_student_can_login($conn, $user);
    if (!empty($gate['ok'])) {
        $skipCredential = [
            'portal_select.php' => true,
            'auth_success.php' => true,
            'logout.php' => true,
        ];
        if (!isset($skipCredential[$script])
            && !ereview_request_is_college_examination_surface()
            && !ereview_session_can_enter_portal($conn, 'ereview', $uid, $user)
        ) {
            ereview_redirect('portal_select');
            exit;
        }

        return;
    }

    $msg = (string) ($gate['error'] ?? 'Your account access is not active.');
    $type = (string) ($gate['error_type'] ?? 'access_denied');
    ereview_force_student_session_end($msg, $type);
}

/**
 * End LMS student session and reject the request (HTML redirect or JSON).
 */
/**
 * End native college-student sessions when archive/login eligibility fails.
 */
function ereview_enforce_college_student_session(?mysqli $conn = null): void
{
    global $conn;
    if ($conn === null) {
        $conn = $GLOBALS['conn'] ?? null;
    }
    if (!$conn instanceof mysqli) {
        return;
    }
    if (!isLoggedIn() || getCurrentUserRole() !== 'college_student') {
        return;
    }
    $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($script === 'logout.php') {
        return;
    }
    $uid = (int) (getCurrentUserId() ?? 0);
    $user = ereview_user_load_platform_row($conn, $uid);
    if (!$user) {
        ereview_force_student_session_end('Your session is no longer valid. Please sign in again.', 'invalid_user');
    }
    $gate = ereview_user_can_authenticate($conn, $user);
    if (!empty($gate['ok'])) {
        return;
    }
    ereview_force_student_session_end(
        (string) ($gate['error'] ?? 'Your account access is not active.'),
        (string) ($gate['error_type'] ?? 'access_denied')
    );
}

function ereview_force_student_session_end(string $message, string $errorType = 'access_denied'): void
{
    $_SESSION['error'] = $message;
    // Drop auth so archived/expired users cannot keep hopping between ungated assets.
    unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['full_name'], $_SESSION['email']);

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $wantsJson = ($xhr === 'xmlhttprequest')
        || (strpos($accept, 'application/json') !== false)
        || (isset($_SERVER['CONTENT_TYPE']) && stripos((string) $_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        || preg_match('/(?:^|[_\\/.-])(?:ajax|api)(?:\\.|$)/i', basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));

    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
        }
        echo json_encode([
            'ok' => false,
            'error' => $message,
            'error_type' => $errorType,
            'logged_out' => true,
        ]);
        exit;
    }

    if (function_exists('ereview_redirect')) {
        ereview_redirect('index');
    }
    header('Location: index');
    exit;
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

    if (($user['role'] ?? $role) === 'college_student') {
        $accountStatus = strtolower((string) ($user['status'] ?? ''));
        if ($accountStatus === 'pending') {
            return [
                'ok' => false,
                'error' => 'Your registration is pending approval. Please wait for a professor or administrator to approve your account before signing in.',
                'error_type' => 'pending_approval',
            ];
        }
        if ($accountStatus === 'rejected') {
            return [
                'ok' => false,
                'error' => 'Your registration was not approved. Contact your professor or administrator for assistance.',
                'error_type' => 'rejected',
            ];
        }
        if ($accountStatus === 'archived') {
            return [
                'ok' => false,
                'error' => 'Your account has been archived. Contact an administrator to restore access.',
                'error_type' => 'archived',
            ];
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

function ereview_request_is_college_examination_surface(): bool
{
    $script = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    if (str_starts_with($script, 'college_')) {
        return true;
    }
    $uri = str_replace('\\', '/', strtolower((string) ($_SERVER['SCRIPT_NAME'] ?? '')));

    return str_contains($uri, '/examination/');
}

function ereview_session_mark_password_auth(string $primaryStore, bool $alsoMatchedOther): void
{
    $primaryStore = $primaryStore === 'college' ? 'college' : 'lms';
    $stores = [$primaryStore];
    if ($alsoMatchedOther) {
        $stores[] = $primaryStore === 'college' ? 'lms' : 'college';
    }
    $_SESSION['auth_via'] = 'password_' . $primaryStore;
    $_SESSION['auth_identity'] = false;
    $_SESSION['auth_matched_stores'] = $stores;
    unset($_SESSION['active_portal']);
}

/**
 * Independently authorize password stores for the shared login form.
 * LMS success is users.password only. College success is college_auth_credentials
 * only. Matching one store never proves the other. A leftover LMS hash on a
 * College-only account is not an authorized LMS credential.
 *
 * @return array{lms:bool,college:bool,stores:list<string>}
 */
function ereview_password_login_authorized_stores(
    bool $lmsHashMatched,
    bool $collegeHashMatched,
    bool $isStaff,
    bool $hasEreviewAccess,
    bool $hasCollegeAccess
): array {
    $lms = $lmsHashMatched && ($isStaff || $hasEreviewAccess);
    $college = $collegeHashMatched && !$isStaff && $hasCollegeAccess;
    $stores = [];
    if ($lms) {
        $stores[] = 'lms';
    }
    if ($college) {
        $stores[] = 'college';
    }

    return ['lms' => $lms, 'college' => $college, 'stores' => $stores];
}

/**
 * Identity login (Google / magic link) proves the shared users profile only.
 * Policy: LMS is enterable after a valid identity login. College is never
 * authorized by identity login, even if a college_auth_credentials row exists.
 * Access flags alone never grant College. College requires a College password
 * (or College-scoped remember-me).
 */
function ereview_session_mark_identity_auth(string $via): void
{
    $via = strtolower(trim($via));
    if ($via === '') {
        $via = 'identity';
    }
    $_SESSION['auth_via'] = $via;
    $_SESSION['auth_identity'] = true;
    $_SESSION['auth_matched_stores'] = ['lms'];
    unset($_SESSION['active_portal']);
}

function ereview_session_mark_remember_auth(string $store): void
{
    $store = $store === 'college' ? 'college' : 'lms';
    $_SESSION['auth_via'] = 'remember_' . $store;
    $_SESSION['auth_identity'] = false;
    $_SESSION['auth_matched_stores'] = [$store];
    unset($_SESSION['active_portal']);
}

/**
 * Portal choice: access flags AND a matched credential store.
 * Missing auth_via is treated as LMS-only (never every portal).
 * Identity sessions still require 'college' in auth_matched_stores to enter College.
 *
 * @param list<string> $availablePortals
 */
function ereview_session_allows_portal_choice(string $portal, array $availablePortals): bool
{
    if (!in_array($portal, $availablePortals, true)) {
        return false;
    }
    $store = $portal === 'college_examination' ? 'college' : 'lms';
    if (!isset($_SESSION['auth_via'])) {
        return $store === 'lms';
    }
    $matched = $_SESSION['auth_matched_stores'] ?? [];
    if (!is_array($matched)) {
        return false;
    }

    return in_array($store, $matched, true);
}

function ereview_session_can_enter_portal(mysqli $conn, string $portal, int $userId, ?array $user = null): bool
{
    $available = ereview_user_available_portals($conn, $userId, $user);

    return ereview_session_allows_portal_choice($portal, $available);
}

/**
 * @return list<string>
 */
function ereview_user_enterable_portals(mysqli $conn, int $userId, ?array $user = null): array
{
    $enterable = [];
    foreach (ereview_user_available_portals($conn, $userId, $user) as $portal) {
        if (ereview_session_can_enter_portal($conn, $portal, $userId, $user)) {
            $enterable[] = $portal;
        }
    }

    return $enterable;
}

function ereview_session_end_no_enterable_portal(bool $preferCollegeLogin = false): void
{
    unset(
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['full_name'],
        $_SESSION['email'],
        $_SESSION['auth_via'],
        $_SESSION['auth_identity'],
        $_SESSION['auth_matched_stores'],
        $_SESSION['active_portal']
    );
    $_SESSION['error'] = 'Sign in with the password for this portal, or use Forgot password to set a College Examination password.';
    $_SESSION['error_type'] = 'invalid_credentials';
    unset($_SESSION['ereview_ui_portal']);
    header('Location: login');
    exit;
}

/**
 * Resolve post-login destination from portals the session can actually enter.
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
    $available = ereview_user_available_portals($conn, $userId, $user);
    $enterable = ereview_user_enterable_portals($conn, $userId, $user);
    if (count($enterable) === 0) {
        return ereview_url('login');
    }

    if (count($enterable) === 1 && ereview_session_can_enter_portal($conn, $enterable[0], $userId, $user)) {
        $_SESSION['active_portal'] = $enterable[0];

        return ereview_portal_dashboard_url($enterable[0]);
    }

    if (count($available) > 1) {
        return ereview_url('portal_select');
    }

    return ereview_url('login');
}
