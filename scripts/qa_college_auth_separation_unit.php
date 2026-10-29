<?php
/**
 * DB-free checks for LMS vs College credential separation and portal selection.
 *
 *   C:\xampp\php\php.exe scripts\qa_college_auth_separation_unit.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/platform_access.php';
require_once $root . '/examination/includes/college_auth_credentials.php';

$fail = 0;
$pass = static function (string $label, bool $ok, string $detail = '') use (&$fail): void {
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$dummy = college_auth_unusable_lms_password_hash();
$pass('unusable LMS hash is bcrypt', (bool) preg_match('/^\$2[ayb]\$/', $dummy));
$pass('two unusable LMS hashes differ', $dummy !== college_auth_unusable_lms_password_hash());

$_SESSION = [];
$dual = ['ereview', 'college_examination'];
$pass('legacy session is LMS-only', ereview_session_allows_portal_choice('ereview', $dual) && !ereview_session_allows_portal_choice('college_examination', $dual));
$pass('unauthorized portal is never offered', !ereview_session_allows_portal_choice('ereview', ['college_examination']));

ereview_session_mark_password_auth('lms', false);
$pass('LMS password auth records lms store only', ($_SESSION['auth_via'] ?? '') === 'password_lms' && ($_SESSION['auth_matched_stores'] ?? []) === ['lms']);
$pass('LMS-only match can choose LMS', ereview_session_allows_portal_choice('ereview', $dual));
$pass('LMS-only match cannot choose College', !ereview_session_allows_portal_choice('college_examination', $dual));

ereview_session_mark_password_auth('college', false);
$pass('College password auth records college store only', ($_SESSION['auth_via'] ?? '') === 'password_college' && ($_SESSION['auth_matched_stores'] ?? []) === ['college']);
$pass('College-only match can choose College', ereview_session_allows_portal_choice('college_examination', $dual));
$pass('College-only match cannot choose LMS', !ereview_session_allows_portal_choice('ereview', $dual));

ereview_session_mark_password_auth('lms', true);
$pass('independently matching both stores can choose either', ereview_session_allows_portal_choice('ereview', $dual) && ereview_session_allows_portal_choice('college_examination', $dual));

ereview_session_mark_identity_auth('google', false);
$pass('Google identity without College credential is LMS-only', !empty($_SESSION['auth_identity']) && ereview_session_allows_portal_choice('ereview', $dual) && !ereview_session_allows_portal_choice('college_examination', $dual));
$pass('Google identity cannot invent a portal', !ereview_session_allows_portal_choice('ereview', ['college_examination']));
ereview_session_mark_identity_auth('google', true);
$pass('Google identity with College credential may enter College', ereview_session_allows_portal_choice('ereview', $dual) && ereview_session_allows_portal_choice('college_examination', $dual));

ereview_session_mark_identity_auth('magic', false);
$pass('magic-link without College credential cannot enter College', !ereview_session_allows_portal_choice('college_examination', ['college_examination']));
ereview_session_mark_identity_auth('magic', true);
$pass('magic-link with College credential may enter College', ereview_session_allows_portal_choice('college_examination', ['college_examination']));

ereview_session_mark_remember_auth('lms');
$pass('LMS remember-me cannot enter College', ($_SESSION['auth_via'] ?? '') === 'remember_lms' && !ereview_session_allows_portal_choice('college_examination', $dual));
ereview_session_mark_remember_auth('college');
$pass('College remember-me cannot enter LMS', ($_SESSION['auth_via'] ?? '') === 'remember_college' && !ereview_session_allows_portal_choice('ereview', $dual));

$lmsOnly = ['ereview'];
ereview_session_mark_password_auth('lms', false);
$pass('LMS-only user is not shown College', !ereview_session_allows_portal_choice('college_examination', $lmsOnly) && ereview_session_allows_portal_choice('ereview', $lmsOnly));
$collegeOnly = ['college_examination'];
ereview_session_mark_password_auth('college', false);
$pass('College-only user is not shown LMS', !ereview_session_allows_portal_choice('ereview', $collegeOnly) && ereview_session_allows_portal_choice('college_examination', $collegeOnly));

$sharedLms = ereview_password_login_authorized_stores(true, false, false, true, false);
$pass('shared login LMS-only password authorizes LMS', $sharedLms['stores'] === ['lms'] && $sharedLms['lms'] && !$sharedLms['college']);
$sharedCollege = ereview_password_login_authorized_stores(false, true, false, false, true);
$pass('shared login College-only password authorizes College', $sharedCollege['stores'] === ['college'] && !$sharedCollege['lms'] && $sharedCollege['college']);
$sharedBoth = ereview_password_login_authorized_stores(true, true, false, true, true);
$pass('shared login independent both-store match authorizes both', $sharedBoth['stores'] === ['lms', 'college']);
$leftover = ereview_password_login_authorized_stores(true, false, false, false, true);
$pass('shared login leftover LMS hash cannot authorize College-only', $leftover['stores'] === [] && !$leftover['lms'] && !$leftover['college']);
$neither = ereview_password_login_authorized_stores(false, false, false, true, true);
$pass('shared login matching neither store authorizes none', $neither['stores'] === []);
$staffCollege = ereview_password_login_authorized_stores(false, true, true, false, false);
$pass('shared login staff cannot use College credentials', $staffCollege['stores'] === []);
$staffLms = ereview_password_login_authorized_stores(true, true, true, false, false);
$pass('shared login staff LMS password ignores College hash', $staffLms['stores'] === ['lms'] && $staffLms['lms'] && !$staffLms['college']);
$lmsWrongCollege = ereview_password_login_authorized_stores(true, false, false, true, true);
$pass('shared login LMS password does not prove College', $lmsWrongCollege['stores'] === ['lms']);
$collegeWrongLms = ereview_password_login_authorized_stores(false, true, false, true, true);
$pass('shared login College password does not prove LMS', $collegeWrongLms['stores'] === ['college']);

$verifySrc = (string) file_get_contents($root . '/examination/includes/college_email_verification.php');
$pass('new College insert stores an unusable LMS hash', str_contains($verifySrc, 'college_auth_unusable_lms_password_hash()') && str_contains($verifySrc, '$lmsHash'));
$pass('new College insert writes College credential', str_contains($verifySrc, 'college_auth_credentials_put($conn, $userId, $hash)'));
$attachFn = '';
if (preg_match('/function college_attach_lms_student_in_transaction\(.*?function college_insert_verified_college_student/s', $verifySrc, $m)) {
    $attachFn = $m[0];
}
$pass('attach writes College credential from pending hash', str_contains($attachFn, 'college_auth_credentials_put($conn, $userId, $collegeHash)'));
$pass('attach still does not write users.password', $attachFn !== '' && !preg_match('/\bpassword\s*=/', $attachFn));
$pass('attach still does not overwrite profile fields', $attachFn !== '' && !str_contains($attachFn, 'full_name=?') && !str_contains($attachFn, 'school=?') && !str_contains($attachFn, 'section=?'));

$createSrc = (string) file_get_contents($root . '/examination/includes/college_student_create.php');
$pass('staff create writes dummy LMS hash', str_contains($createSrc, 'college_auth_unusable_lms_password_hash()') && str_contains($createSrc, '$lmsHash'));
$pass('staff create writes College credential', str_contains($createSrc, 'college_auth_credentials_put($conn, $newId, $collegeHash)'));
$pass('staff create forces approved status', str_contains($createSrc, "if (\$initialStatus !== 'approved')") && str_contains($createSrc, "\$initialStatus = 'approved';"));
$pass(
    'staff create inserts undergrad college_student with verified email',
    str_contains($createSrc, "VALUES (?, 'undergrad'")
    && str_contains($createSrc, "'college_student'")
    && str_contains($createSrc, 'email_verified, student_number')
    && str_contains($createSrc, "'college_student', ?, 1, ?)")
);

$platSrc = (string) file_get_contents($root . '/includes/platform_access.php');
$pass('College access requires approved status', str_contains($platSrc, "strtolower((string) (\$user['status'] ?? '')) !== 'approved'"));
$pass('native college_student role grants College access without LMS grant flag', str_contains($platSrc, "(\$user['role'] ?? '') === 'college_student'") && str_contains($platSrc, 'return true;'));
$pass('College access rejects suspended examination flag', str_contains($platSrc, "\$access === 'suspended'"));

$resetSrc = (string) file_get_contents($root . '/examination/includes/college_password_reset.php');
$pass('College reset does not update users.password', !preg_match('/UPDATE\s+users\s+SET\s+password\s*=/i', $resetSrc));
$pass('College reset upserts college_auth_credentials', str_contains($resetSrc, 'college_auth_credentials_put($conn, $userId, $passwordHash)'));
$pass('College reset does not copy users.password', !preg_match('/users\.password|SELECT\s+password\s+FROM\s+users/i', $resetSrc));

$lmsReset = (string) file_get_contents($root . '/reset_password.php');
$pass('LMS reset still updates users.password only', str_contains($lmsReset, 'UPDATE users SET password = ? WHERE user_id = ?') && !str_contains($lmsReset, 'college_auth_credentials'));
$pass('LMS reset does not write College credentials', !str_contains($lmsReset, 'college_auth_credentials_put'));

$lmsLogin = (string) file_get_contents($root . '/login_process.php');
$pass('shared login verifies users.password independently', str_contains($lmsLogin, 'password_verify($password, $stored)'));
$pass('shared login verifies college_auth_credentials independently', str_contains($lmsLogin, 'college_auth_credentials_verify($conn, (int) $user[\'user_id\'], $password)'));
$pass('shared login authorizes stores independently', str_contains($lmsLogin, 'ereview_password_login_authorized_stores'));
$pass('shared login does not copy hashes between stores', !preg_match('/college_auth_credentials_put|UPDATE users SET password/i', $lmsLogin) || str_contains($lmsLogin, '$lmsPlaintextMatched && !empty($authz[\'lms\'])'));
$pass('shared login leftover LMS hash requires ereview access', str_contains($lmsLogin, 'ereview_user_has_ereview_access($conn, (int) $user[\'user_id\'], $user)'));
$pass('shared login College match requires college access', str_contains($lmsLogin, 'ereview_user_has_college_examination_access($conn, (int) $user[\'user_id\'], $user)'));
$pass('shared login staff skip College verify', str_contains($lmsLogin, 'if (!$isStaff)') && str_contains($lmsLogin, 'college_auth_credentials_verify'));
$collegeVerifyAt = strpos($lmsLogin, 'college_auth_credentials_verify');
$authzAt = strpos($lmsLogin, 'ereview_password_login_authorized_stores');
$pass(
    'College credential verify runs before store authorization, not after LMS success',
    $collegeVerifyAt !== false && $authzAt !== false && $collegeVerifyAt < $authzAt
);
$pass('shared login plaintext upgrade only after LMS authorize', str_contains($lmsLogin, '$lmsPlaintextMatched && !empty($authz[\'lms\'])'));
$pass('shared login remember-me is portal scoped', str_contains($lmsLogin, "setRememberMeCookie(\$user['user_id'], \$authz['stores'][0])") && str_contains($lmsLogin, "\$_SESSION['pending_remember_me']"));
$pass('shared login keeps generic failure copy', str_contains($lmsLogin, 'Invalid email or password.'));

$collegeLogin = (string) file_get_contents($root . '/college_login_process.php');
$pass('college_login_process defers to shared login_process', str_contains($collegeLogin, "require __DIR__ . '/login_process.php'") && !str_contains($collegeLogin, 'college_auth_credentials_verify'));

$loginPage = (string) file_get_contents($root . '/login.php');
$pass('shared login page posts to login_process', str_contains($loginPage, 'action="login_process"'));
$pass('shared login page does not switch College skin', !str_contains($loginPage, '$collegePortal') && !str_contains($loginPage, 'college-examination-login') && !str_contains($loginPage, 'Examination Portal'));
$pass('shared login page uses original eReview branding', str_contains($loginPage, 'Welcome Back') && str_contains($loginPage, 'image%20assets/lms-logo.png'));
$pass('shared login page forgot-password stays LMS helper', str_contains($loginPage, 'href="forgot_password"') && !str_contains($loginPage, 'college_forgot_password'));
$pass('magic link marks identity auth with College credential check', str_contains($loginPage, "ereview_session_mark_identity_auth('magic', \$collegeCredReady)"));

$google = (string) file_get_contents($root . '/google_callback.php');
$pass('Google login marks identity auth with College credential check', str_contains($google, "ereview_session_mark_identity_auth('google', \$collegeCredReady)"));

$portalSelect = (string) file_get_contents($root . '/portal_select.php');
$pass('portal select requires login', str_contains($portalSelect, 'isLoggedIn()') && str_contains($portalSelect, 'verifySession()'));
$pass('portal select labels eReview LMS', str_contains($portalSelect, 'eReview LMS'));
$pass('portal select labels College Examination', str_contains($portalSelect, 'College Examination'));
$pass('portal select enforces server-side can_enter', str_contains($portalSelect, "ereview_session_can_enter_portal(\$conn, 'ereview'") && str_contains($portalSelect, "ereview_session_can_enter_portal(\$conn, 'college_examination'"));
$pass('portal select does not auto-enter without can_enter', str_contains($portalSelect, 'ereview_session_can_enter_portal($conn, $portals[0], $userId)'));
$pass('portal select posts CSRF', str_contains($portalSelect, 'verifyCSRFToken') && str_contains($portalSelect, 'csrf_token'));
$pass('portal select issues pending remember-me for chosen portal', str_contains($portalSelect, "setRememberMeCookie(\$userId, 'lms')") && str_contains($portalSelect, "setRememberMeCookie(\$userId, 'college')") && str_contains($portalSelect, 'pending_remember_me'));

$accessSrc = (string) file_get_contents($root . '/includes/platform_access.php');
$pass('post-login no longer forces College for dual-access', !str_contains($accessSrc, 'Examination-mode lock') && str_contains($accessSrc, "return ereview_url('portal_select')"));
$pass('single portal routes only when enterable', str_contains($accessSrc, 'if (count($enterable) === 1 && ereview_session_can_enter_portal($conn, $enterable[0], $userId, $user))'));
$pass('College portal gate checks session credential', str_contains($accessSrc, "ereview_session_can_enter_portal(\$conn, 'college_examination'"));
$pass('LMS session gate does not apply on College surfaces', str_contains($accessSrc, 'ereview_request_is_college_examination_surface()'));
$pass('identity policy requires a College credential row', str_contains($accessSrc, 'only when a college_auth_credentials row already exists'));
$pass('missing auth_via is LMS-only', str_contains($accessSrc, "return \$store === 'lms'"));
$pass('empty-enterable login redirect is shared login', str_contains($accessSrc, "header('Location: login');") && !str_contains($accessSrc, 'login?portal=examination'));

$rmSrc = (string) file_get_contents($root . '/remember_me.php');
$pass('remember-me stores portal scope', str_contains($rmSrc, 'function setRememberMeCookie($userId, $portal = \'lms\')') && str_contains($rmSrc, 'rememberMeHasPortalColumn'));
$pass('remember-me does not ALTER at request time', !str_contains($rmSrc, 'ALTER TABLE remember_tokens') && str_contains($rmSrc, 'function rememberMeHasPortalColumn'));
$pass('remember-me prunes per portal', str_contains($rmSrc, 'AND portal = ?') && str_contains($rmSrc, 'Prune per portal'));
$pass('remember-me has scoped clear', str_contains($rmSrc, 'function clearRememberMeForUserIdAndPortal'));
$pass('College-scoped clear does not fall back to all tokens', str_contains($rmSrc, "if (\$portal === 'lms')") && str_contains($rmSrc, 'college-scoped clears are a no-op'));
$pass('remember-me restore marks portal store', str_contains($rmSrc, 'ereview_session_mark_remember_auth($rememberPortal)'));

$mig = (string) file_get_contents($root . '/migrations/060_college_auth_credentials.sql');
$down = (string) file_get_contents($root . '/migrations/060_college_auth_credentials_DOWN.sql');
$pass('migration 060 creates college_auth_credentials', str_contains($mig, 'CREATE TABLE IF NOT EXISTS `college_auth_credentials`'));
$pass('migration 060 does not backfill users.password', !preg_match('/INSERT\s+INTO\s+`?college_auth_credentials/i', $mig) || !preg_match('/users\.password|FROM\s+`?users`?/i', $mig));
$pass('migration 060 has unique user_id', str_contains($mig, 'PRIMARY KEY (`user_id`)') && str_contains($mig, 'UNIQUE KEY `uq_cac_user`'));
$pass('migration 060 DOWN drops the table', str_contains($down, 'DROP TABLE IF EXISTS `college_auth_credentials`'));

$credSrc = (string) file_get_contents($root . '/examination/includes/college_auth_credentials.php');
$pass('College credential helper does not CREATE TABLE', !str_contains($credSrc, 'CREATE TABLE') && !str_contains($credSrc, 'schema_ensure'));
$pass('College credential put requires schema ready', str_contains($credSrc, 'college_auth_credentials_schema_ready($conn)'));

$mig061 = (string) file_get_contents($root . '/migrations/061_remember_tokens_portal.sql');
$down061 = (string) file_get_contents($root . '/migrations/061_remember_tokens_portal_DOWN.sql');
$pass('migration 061 adds remember_tokens.portal default lms', str_contains($mig061, "ADD COLUMN `portal` varchar(16) NOT NULL DEFAULT 'lms'"));
$pass('migration 061 DOWN drops portal column', str_contains($down061, 'DROP COLUMN `portal`'));

$prefs = (string) file_get_contents($root . '/account_preferences.php');
$help = (string) file_get_contents($root . '/help_center.php');
$pass('preferences College branch uses portal gate', substr_count($prefs, 'ereview_require_college_examination_portal()') >= 1);
$pass('help center College branch uses portal gate', substr_count($help, 'ereview_require_college_examination_portal()') >= 1);
$pass('exam dashboard still uses College portal gate', str_contains((string) file_get_contents($root . '/examination/examinee/college_student_dashboard.php'), 'ereview_require_college_examination_portal()'));
$pass('exam take still uses College portal gate', str_contains((string) file_get_contents($root . '/examination/examinee/college_take_exam.php'), 'ereview_require_college_examination_portal()'));
$pass('exam ajax still uses College portal gate', str_contains((string) file_get_contents($root . '/examination/examinee/college_exam_ajax.php'), 'ereview_require_college_examination_portal()'));

$lmsForgot = (string) file_get_contents($root . '/forgot_password.php');
$lmsResetLib = (string) file_get_contents($root . '/password_reset.php');
$pass('LMS forgot_password is unchanged helper', str_contains($lmsForgot, 'createPasswordResetToken($email)'));
$pass('LMS password_reset library still selects any email', str_contains($lmsResetLib, 'SELECT user_id FROM users WHERE email = ? LIMIT 1'));

echo ($fail === 0 ? "OK 0 failed\n" : "FAILED {$fail}\n");
exit($fail === 0 ? 0 : 1);
