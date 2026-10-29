<?php
/**
 * DB-free checks for College vs LMS account namespace and College password reset.
 * Does not send email or write the database.
 *
 *   C:\xampp\php\php.exe scripts\qa_college_duplicate_reset_unit.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/examination/includes/college_email_verification.php';
require_once $root . '/examination/includes/college_password_reset.php';

$fail = 0;
$pass = static function (string $label, bool $ok, string $detail = '') use (&$fail): void {
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$pass('LMS-only student is not a College account', college_user_account_namespace(['role' => 'student']) === 'lms_only');
$pass('LMS-only with none access is not College', college_user_account_namespace([
    'role' => 'student',
    'college_examination_access' => 'none',
    'email_verified' => 1,
]) === 'lms_only');
$pass('LMS student with college access is College', college_user_account_namespace([
    'role' => 'student',
    'college_examination_access' => 'active',
    'email_verified' => 1,
]) === 'college');
$pass('verified college_student is College', college_user_account_namespace([
    'role' => 'college_student',
    'email_verified' => 1,
]) === 'college');
$pass('unverified college_student is not existing College', college_user_account_namespace([
    'role' => 'college_student',
    'email_verified' => 0,
]) === 'college_unverified');
$pass('admin is other namespace', college_user_account_namespace(['role' => 'admin']) === 'other');
$pass('professor is other namespace', college_user_account_namespace(['role' => 'professor_admin']) === 'other');
$pass('college helper matches namespace', college_user_is_college_account([
    'role' => 'college_student',
    'email_verified' => 1,
]) && !college_user_is_college_account(['role' => 'student', 'college_examination_access' => 'none']));
$pass('approved LMS-only can attach', college_user_can_attach_lms_to_college([
    'role' => 'student',
    'status' => 'approved',
    'college_examination_access' => 'none',
]));
$pass('rejected LMS-only cannot attach', !college_user_can_attach_lms_to_college([
    'role' => 'student',
    'status' => 'rejected',
    'college_examination_access' => 'none',
]));
$pass('archived LMS-only cannot attach', !college_user_can_attach_lms_to_college([
    'role' => 'student',
    'status' => 'archived',
    'college_examination_access' => 'none',
]));
$pass('pending LMS-only cannot attach', !college_user_can_attach_lms_to_college([
    'role' => 'student',
    'status' => 'pending',
    'college_examination_access' => 'none',
]));
$pass('staff cannot attach', !college_user_can_attach_lms_to_college([
    'role' => 'admin',
    'status' => 'approved',
]));
$pass('approved College can reset', college_user_can_reset_college_password([
    'role' => 'college_student',
    'email_verified' => 1,
    'status' => 'approved',
    'college_examination_access' => 'active',
]));
$pass('suspended College cannot reset', !college_user_can_reset_college_password([
    'role' => 'student',
    'email_verified' => 1,
    'status' => 'approved',
    'college_examination_access' => 'suspended',
]));
$pass('archived College cannot reset', !college_user_can_reset_college_password([
    'role' => 'college_student',
    'email_verified' => 1,
    'status' => 'archived',
    'college_examination_access' => 'active',
]));
$pass('LMS-only cannot reset via College flow', !college_user_can_reset_college_password([
    'role' => 'student',
    'status' => 'approved',
    'college_examination_access' => 'none',
]));

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$future = $now->modify('+1 hour')->format('Y-m-d H:i:s');
$past = $now->modify('-1 hour')->format('Y-m-d H:i:s');
$pass('missing reset row is unusable', !college_reset_token_row_usable(null, $now));
$pass('valid unused reset row is usable', college_reset_token_row_usable([
    'used_at' => null,
    'expires_at' => $future,
], $now));
$pass('used reset row is unusable', !college_reset_token_row_usable([
    'used_at' => $now->format('Y-m-d H:i:s'),
    'expires_at' => $future,
], $now));
$pass('expired reset row is unusable', !college_reset_token_row_usable([
    'used_at' => null,
    'expires_at' => $past,
], $now));
$pass('reset token format accepts 64 hex', college_reset_token_format_valid(str_repeat('ab', 32)));
$pass('reset token format rejects short values', !college_reset_token_format_valid('abc'));
$pass('reset token format rejects empty', !college_reset_token_format_valid(''));
$tok = college_new_reset_token();
$pass('new reset token is 64 hex raw', isset($tok['raw'], $tok['hash']) && preg_match('/^[a-f0-9]{64}$/', $tok['raw']) === 1);
$pass('new reset token stores sha256 hash', $tok['hash'] === hash('sha256', strtolower($tok['raw'])));

$src = (string) file_get_contents($root . '/examination/includes/college_email_verification.php');
$pass('register existing college does not send verification', str_contains($src, "'existing_college' => true"));
$attachFn = '';
if (preg_match('/function college_attach_lms_student_in_transaction\(.*?function college_insert_verified_college_student/s', $src, $m)) {
    $attachFn = $m[0];
}
$pass('LMS-only attach helper is present', $attachFn !== '');
$pass('LMS-only attach does not insert users', $attachFn !== '' && !preg_match('/INSERT\s+INTO\s+users/i', $attachFn));
$pass('LMS-only attach keeps student role', str_contains($attachFn, "role='student'") && str_contains($attachFn, "status='approved'"));
$pass('LMS-only attach does not change role to college_student', $attachFn !== '' && !preg_match('/role=[\'"]college_student/', $attachFn));
$pass('LMS-only attach does not overwrite password', $attachFn !== '' && !preg_match('/\bpassword\s*=/', $attachFn));
$pass('LMS-only attach does not overwrite profile fields', $attachFn !== '' && !str_contains($attachFn, 'full_name=?') && !str_contains($attachFn, 'school=?') && !str_contains($attachFn, 'section=?'));
$pass('LMS-only attach allocates student_number only if missing', str_contains($attachFn, "TRIM(student_number)=''") && substr_count($attachFn, 'student_number=?') === 1);
$pass('ineligible LMS-only is not attached', str_contains($src, 'college_user_can_attach_lms_to_college($user)'));
$pass('verify still rejects other account types', str_contains($src, 'This email is already used by another account type'));
$pass('verify other-account path uses namespace other', str_contains($src, "if (\$ns === 'other')"));

$resetSrc = (string) file_get_contents($root . '/examination/includes/college_password_reset.php');
$pass('college reset does not require LMS password_reset.php', !preg_match('/\b(require|include)(_once)?\b[^;]*password_reset\.php/', $resetSrc) && !str_contains($resetSrc, 'createPasswordResetToken'));
$pass('college reset emails hashed-token link only', str_contains($resetSrc, 'college_public_reset_url($token[\'raw\'])') && !preg_match('/password.*=.*\$token/', $resetSrc));
$pass('college reset writes only college_auth_credentials', str_contains($resetSrc, 'college_auth_credentials_put($conn, $userId, $passwordHash)') && !preg_match('/UPDATE\s+users\s+SET\s+password\s*=/i', $resetSrc));
$pass('college reset uses status-aware eligibility', str_contains($resetSrc, 'college_user_can_reset_college_password($user)'));
$pass('college reset invalidates College remember-me only', str_contains($resetSrc, 'clearRememberMeForUserIdAndPortal') && str_contains($resetSrc, "'college'") && str_contains($resetSrc, 'remember_me.php'));
$pass('college reset request is generic when no college account', str_contains($resetSrc, "return ['ok' => true, 'generic' => true, 'sent' => false];"));
$pass('college reset invalid/used/expired uses COLLEGE_RESET_INVALID', str_contains($resetSrc, 'COLLEGE_RESET_INVALID'));
$pass('college reset marks token used', str_contains($resetSrc, 'SET used_at=NOW()'));
$pass('college reset consumes reset rate limit', str_contains($resetSrc, "college_verify_rate_limit_consume(\$conn, 'reset'"));
$pass('reset email does not include a plaintext password', !preg_match('/Your password is|temporary password|plain(?:text)? password is/i', $resetSrc));

$regSrc = (string) file_get_contents($root . '/examination/student_registration.php');
$pass('registration existing modal has login and reset', str_contains($regSrc, 'id="csr-modal-existing"') && str_contains($regSrc, 'id="csr-existing-login"') && str_contains($regSrc, 'college_forgot_password'));
$pass('registration has one Already have an account line', substr_count($regSrc, 'Already have an account?') === 1);
$pass('registration login links use shared login', str_contains($regSrc, 'href="login"') && !str_contains($regSrc, 'login?portal=examination'));
$existingModal = '';
if (preg_match('/id="csr-modal-existing"[\s\S]*?id="csr-modal-email-sent"/', $regSrc, $em)) {
    $existingModal = $em[0];
}
$pass('existing modal does not show student number', $existingModal !== '' && !str_contains($existingModal, 'student_number') && !str_contains($existingModal, 'College Exam ID'));
$pass('registration CSRF still required', str_contains($regSrc, 'verifyCSRFToken'));

$forgotSrc = (string) file_get_contents($root . '/examination/college_forgot_password.php');
$pass('college forgot requires CSRF', str_contains($forgotSrc, 'verifyCSRFToken'));
$pass('college forgot uses dedicated reset helper', str_contains($forgotSrc, 'college_public_request_password_reset'));
$pass('college forgot PRG confirm', str_contains($forgotSrc, "college_public_safe_confirm_location('college_forgot_password'), true, 303)"));
$pass('college forgot generic copy avoids we sent', str_contains($forgotSrc, 'COLLEGE_PUBLIC_GENERIC_RESET') && !str_contains(COLLEGE_PUBLIC_GENERIC_RESET, 'we sent'));
$pass('college forgot login uses shared login', str_contains($forgotSrc, 'href="login"') && !str_contains($forgotSrc, 'login?portal=examination'));
$pass('college forgot form has one Login link', substr_count($forgotSrc, '>Login</a>') === 1);

$resetPage = (string) file_get_contents($root . '/examination/college_reset_password.php');
$pass('college reset page requires CSRF', str_contains($resetPage, 'verifyCSRFToken'));
$pass('college reset page completes via dedicated helper', str_contains($resetPage, 'college_public_complete_password_reset'));
$pass('college reset page does not echo user id', !str_contains($resetPage, '$peek[\'user_id\']') && !str_contains($resetPage, 'student_number'));
$pass('college reset login uses shared login', str_contains($resetPage, 'href="login"') && !str_contains($resetPage, 'login?portal=examination'));
$pass('college reset success has one Continue to login link', substr_count($resetPage, '>Continue to login</a>') === 1);

$lmsForgot = (string) file_get_contents($root . '/forgot_password.php');
$lmsReset = (string) file_get_contents($root . '/reset_password.php');
$lmsResetLib = (string) file_get_contents($root . '/password_reset.php');
$lmsReg = (string) file_get_contents($root . '/registration.php');
$pass('LMS forgot_password still uses createPasswordResetToken', str_contains($lmsForgot, 'createPasswordResetToken($email)'));
$pass('LMS reset_password still updates by user_id only', str_contains($lmsReset, 'UPDATE users SET password = ? WHERE user_id = ?'));
$pass('LMS reset_password clears LMS remember-me only', str_contains($lmsReset, 'clearRememberMeForUserIdAndPortal') && str_contains($lmsReset, "'lms'"));
$pass('LMS password_reset library still selects any email', str_contains($lmsResetLib, 'SELECT user_id FROM users WHERE email = ? LIMIT 1'));
$lmsLogin = (string) file_get_contents($root . '/login_process.php');
$pass('LMS login still verifies users.password', str_contains($lmsLogin, 'password_verify($password, $stored)'));
$pass('LMS registration still polls check_verification', str_contains($lmsReg, 'check_verification?email='));
$pass('LMS registration is not the College student_registration file', !str_contains($lmsReg, 'college_public_register_from_request'));

$rateSrc = (string) file_get_contents($root . '/examination/includes/college_verify_rate_limit.php');
$pass('rate limiter allows reset action', str_contains($rateSrc, "'reset'"));

$mig = (string) file_get_contents($root . '/migrations/059_college_password_reset_tokens.sql');
$pass('migration creates college_password_reset_tokens', str_contains($mig, 'CREATE TABLE IF NOT EXISTS `college_password_reset_tokens`'));
$pass('migration stores token_hash not raw token', str_contains($mig, '`token_hash` char(64)') && !str_contains($mig, '`token` varchar'));

echo ($fail === 0 ? "OK 0 failed\n" : "FAILED {$fail}\n");
exit($fail === 0 ? 0 : 1);
