<?php
/**
 * DB-free Phase 1 checks: PRG confirmation, generic copy, no open redirect, resend after SMTP.
 * Session put/get/clear run in-process. Labels prefixed "source:" are string assertions only
 * and do not simulate an HTTP request.
 * Does not send email or write the database.
 *
 *   C:\xampp\php\php.exe scripts\qa_college_registration_prg_unit.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/examination/includes/college_email_verification.php';

$fail = 0;
$pass = static function (string $label, bool $ok, string $detail = '') use (&$fail): void {
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

$_SESSION = [];
$_GET = [];

$loc = college_public_safe_confirm_location('student_registration');
$pass('confirm location is relative student_registration', str_starts_with($loc, 'student_registration'));
$pass('confirm location has confirm=1', str_contains($loc, 'confirm=1'));
$pass('confirm location has no email query', !preg_match('/email=/i', $loc) && !preg_match('/@/', $loc));
$pass('confirm location is not an absolute URL', preg_match('#^(https?:)?//#i', $loc) !== 1);

$hijack = college_public_safe_confirm_location('https://evil.example/phish');
$pass('external script name is ignored', str_starts_with($hijack, 'student_registration') && !str_contains($hijack, 'evil.example'));

$resendLoc = college_public_safe_confirm_location('college_resend_verification');
$pass('resend confirm location is local', str_starts_with($resendLoc, 'college_resend_verification') && str_contains($resendLoc, 'confirm=1'));

$put = college_public_confirm_session_put(COLLEGE_REG_CONFIRM_SESSION_KEY, '  Student@Example.COM ');
$pass('session stores normalized email', ($put['email'] ?? '') === 'student@example.com');
$pass('session payload has expiry', (int) ($put['exp'] ?? 0) > time());
$pass('session default mail_failed is false', empty($put['mail_failed']));

$got = college_public_confirm_session_get(COLLEGE_REG_CONFIRM_SESSION_KEY);
$pass('session peek returns stored email', is_array($got) && ($got['email'] ?? '') === 'student@example.com');
$pass('session peek default mail_failed is false', is_array($got) && empty($got['mail_failed']));
$pass('session peek default existing_college is false', is_array($got) && empty($got['existing_college']));

college_public_confirm_session_put(COLLEGE_REG_CONFIRM_SESSION_KEY, 'student@example.com', true);
$failedGot = college_public_confirm_session_get(COLLEGE_REG_CONFIRM_SESSION_KEY);
$pass('session stores mail_failed flag', is_array($failedGot) && !empty($failedGot['mail_failed']));
college_public_confirm_session_put(COLLEGE_REG_CONFIRM_SESSION_KEY, 'student@example.com', false);

$_GET['confirm'] = '1';
$pass('confirm query detected', college_public_confirm_query_requested());
$_GET['confirm'] = '0';
$pass('other confirm values ignored', !college_public_confirm_query_requested());

$bad = college_public_confirm_session_put(COLLEGE_REG_CONFIRM_SESSION_KEY, 'not-an-email');
$pass('invalid email is not stored', ($bad['email'] ?? 'x') === '');

$_SESSION[COLLEGE_REG_CONFIRM_SESSION_KEY] = ['email' => 'stale@example.com', 'exp' => time() - 10];
$pass('expired confirm session is discarded', college_public_confirm_session_get(COLLEGE_REG_CONFIRM_SESSION_KEY) === null);

$_SESSION = [];
college_public_confirm_session_put(COLLEGE_REG_CONFIRM_SESSION_KEY, 'old@example.com');
college_public_confirm_session_put(COLLEGE_RESEND_CONFIRM_SESSION_KEY, 'resend-old@example.com');
college_public_confirm_session_clear(COLLEGE_REG_CONFIRM_SESSION_KEY);
$pass('clear removes registration confirm flash', college_public_confirm_session_get(COLLEGE_REG_CONFIRM_SESSION_KEY) === null);
$pass('clear of register key does not drop resend flash', college_public_confirm_session_get(COLLEGE_RESEND_CONFIRM_SESSION_KEY) !== null);
college_public_confirm_session_put(COLLEGE_REG_CONFIRM_SESSION_KEY, 'new@example.com');
$afterPut = college_public_confirm_session_get(COLLEGE_REG_CONFIRM_SESSION_KEY);
$pass('put after clear stores the new confirmation', is_array($afterPut) && ($afterPut['email'] ?? '') === 'new@example.com');
college_public_confirm_session_clear(COLLEGE_RESEND_CONFIRM_SESSION_KEY);
$pass('clear removes resend confirm flash', college_public_confirm_session_get(COLLEGE_RESEND_CONFIRM_SESSION_KEY) === null);

$generic = COLLEGE_PUBLIC_GENERIC_VERIFY;
$pass('generic copy avoids we sent/queued/delivered', !preg_match('/we sent|we queued|was delivered|email sent/i', $generic));
$pass('generic copy stays role-neutral', !preg_match('/already registered|college_student|lms|verified account/i', $generic));

$mailFailedCopy = COLLEGE_PUBLIC_MAIL_FAILED;
$pass('mail-failed copy does not claim a send', !preg_match('/we sent|we queued|was delivered|email sent|check inbox/i', $mailFailedCopy));
$pass('mail-failed copy stays role-neutral', !preg_match('/already registered|college_student|lms|verified account/i', $mailFailedCopy));
$pass('mail-failed copy points to resend', str_contains(strtolower($mailFailedCopy), 'resend'));

$pass('no-send result is not treated as SMTP failure', !college_public_attempted_mail_failed(['ok' => true, 'generic' => true, 'sent' => false]));
$pass('missing sent is not treated as SMTP failure', !college_public_attempted_mail_failed(['ok' => true, 'generic' => true]));
$pass('attempted send with delivered=false is SMTP failure', college_public_attempted_mail_failed(['ok' => true, 'sent' => true, 'delivered' => false]));
$pass('attempted send with delivered=true is not SMTP failure', !college_public_attempted_mail_failed(['ok' => true, 'sent' => true, 'delivered' => true]));
$pass('ok=false is not treated as SMTP failure', !college_public_attempted_mail_failed(['ok' => false, 'sent' => true, 'delivered' => false]));

$src = (string) file_get_contents($root . '/examination/includes/college_email_verification.php');
$registerFn = '';
if (preg_match('/function college_public_register_from_request\(mysqli \$conn.*?\nfunction college_public_resend_verification/s', $src, $m)) {
    $registerFn = $m[0];
}
$upsertAt = strpos($registerFn, 'college_pending_upsert');
$sendAt = strpos($registerFn, 'college_send_verification_email');
$pass(
    'pending upsert happens before SMTP send',
    $registerFn !== '' && $upsertAt !== false && $sendAt !== false && $upsertAt < $sendAt
);
$pass('register does not delete users', $registerFn !== '' && !preg_match('/DELETE\s+FROM\s+users/i', $registerFn));
$pass('staff/other path still returns generic without send', str_contains($src, "return ['ok' => true, 'generic' => true, 'sent' => false];"));
$pass('existing college register returns existing_college', str_contains($src, "'existing_college' => true"));
$pass('register uses college account namespace helper', str_contains($src, 'college_user_account_namespace($user)'));
$pass('LMS-only is not treated as existing college', str_contains($src, "'lms_only'") && str_contains($src, "if (\$ns === 'college')") && !preg_match('/if \(\$role !== \'college_student\' \|\| \$verified\)/', $src));
$pass('PHP mail fallback still skipped', str_contains($src, 'skipping PHP mail() fallback'));
$pass('rate limit still consumed on register', str_contains($src, "college_verify_rate_limit_consume(\$conn, 'register'"));
$pass('resend still consumes resend rate limit', str_contains($src, "college_verify_rate_limit_consume(\$conn, 'resend'"));
$pass('token still hashed sha256', str_contains($src, "return ['raw' => \$raw, 'hash' => hash('sha256', \$raw)]"));
$pass('expiry hours unchanged', str_contains($src, 'const COLLEGE_VERIFY_EXPIRY_HOURS = 24'));

$regSrc = (string) file_get_contents($root . '/examination/student_registration.php');
$pass('POST register still requires CSRF', str_contains($regSrc, 'verifyCSRFToken'));
$pass('successful POST redirects 303', str_contains($regSrc, "header('Location: ' . college_public_safe_confirm_location('student_registration'), true, 303)"));
$regClearAt = strpos($regSrc, 'college_public_confirm_session_clear(COLLEGE_REG_CONFIRM_SESSION_KEY)');
$regCsrfAt = strpos($regSrc, 'verifyCSRFToken');
$regPutAt = strpos($regSrc, 'college_public_confirm_session_put(');
$pass(
    'source: register POST clears confirm before CSRF',
    $regClearAt !== false && $regCsrfAt !== false && $regClearAt < $regCsrfAt
);
$pass(
    'source: register success put happens after clear',
    $regClearAt !== false && $regPutAt !== false && $regClearAt < $regPutAt
);
$pass('GET confirm reads session only', str_contains($regSrc, 'college_public_confirm_session_get(COLLEGE_REG_CONFIRM_SESSION_KEY)'));
$pass('source: heading is not Check your email', !str_contains($regSrc, 'Check your email'));
$pass('source: heading is Continue verification', str_contains($regSrc, 'Continue verification'));
$pass('source: mail-failed heading is present', str_contains($regSrc, 'Verification email not sent'));
$pass('source: failed card uses LMS error modal', str_contains($regSrc, 'reg-error-card') && str_contains($regSrc, 'reg-error-backdrop'));
$pass('source: generic confirm uses LMS overlay modal', str_contains($regSrc, 'reg-modal-backdrop') && str_contains($regSrc, 'csr-modal-mail-icon'));
$pass('source: waiting modal offers continue to login', str_contains($regSrc, 'id="csr-waiting-continue"') && str_contains($regSrc, 'Continue to login'));
$pass('source: verified overlay is present', str_contains($regSrc, 'id="csr-modal-verified"'));
$pass('source: existing-account overlay is present', str_contains($regSrc, 'id="csr-modal-existing"') && str_contains($regSrc, 'Account already exists'));
$pass('source: existing-account offers college reset', str_contains($regSrc, 'college_forgot_password') && str_contains($regSrc, 'Reset password'));
$pass('source: register stores existing_college flash', str_contains($regSrc, '!empty($result[\'existing_college\'])'));
$pass('source: generic confirm does not say use the inbox', !str_contains($regSrc, 'Use the inbox for'));
$pass('GET confirm does not call register helper', substr_count($regSrc, 'college_public_register_from_request') === 1);
$pass('POST still calls register once', substr_count($regSrc, 'college_public_register_from_request($conn, $_POST, $_FILES)') === 1);
$pass('confirmation keeps resend form', str_contains($regSrc, "ereview_url('college_resend_verification')"));
$pass('lead can use generic or mail-failed copy', str_contains($regSrc, 'COLLEGE_PUBLIC_GENERIC_VERIFY') && str_contains($regSrc, 'COLLEGE_PUBLIC_MAIL_FAILED'));
$pass('register flags mail_failed only from attempted send helper', str_contains($regSrc, 'college_public_attempted_mail_failed($result)'));
$pass('no secrets in Location builder call', !preg_match('/Location:.*\$_POST\[.email/i', $regSrc));

$resendSrc = (string) file_get_contents($root . '/examination/college_resend_verification.php');
$pass('resend POST still requires CSRF', str_contains($resendSrc, 'verifyCSRFToken'));
$pass('resend success uses 303', str_contains($resendSrc, "college_public_safe_confirm_location('college_resend_verification'), true, 303)"));
$resendClearAt = strpos($resendSrc, 'college_public_confirm_session_clear(COLLEGE_RESEND_CONFIRM_SESSION_KEY)');
$resendCsrfAt = strpos($resendSrc, 'verifyCSRFToken');
$resendPutAt = strpos($resendSrc, 'college_public_confirm_session_put(');
$pass(
    'source: resend POST clears confirm before CSRF',
    $resendClearAt !== false && $resendCsrfAt !== false && $resendClearAt < $resendCsrfAt
);
$pass(
    'source: resend success put happens after clear',
    $resendClearAt !== false && $resendPutAt !== false && $resendClearAt < $resendPutAt
);
$pass('resend GET does not call resend helper', substr_count($resendSrc, 'college_public_resend_verification') === 1);
$pass('source: resend heading is not Check your email', !str_contains($resendSrc, 'Check your email'));
$pass('resend flags mail_failed only from attempted send helper', str_contains($resendSrc, 'college_public_attempted_mail_failed($result)'));
$pass('resend mail-failed keeps the form', str_contains($resendSrc, 'if ($mailFailed)') && str_contains($resendSrc, '$error = COLLEGE_PUBLIC_MAIL_FAILED;'));
$pass('resend mail-failed heading is present', str_contains($resendSrc, 'Verification email not sent'));
$pass('resend success does not claim link replacement', !preg_match('/new link replaces|invalidates the previous/i', $resendSrc));
$pass('resend email uses static top label', str_contains($resendSrc, 'reg-top-label') && !str_contains($resendSrc, 'float-label-wrap'));
$pass('resend waiting modal offers continue to login', str_contains($resendSrc, 'id="csr-waiting-continue"'));
$pass('resend verified overlay is present', str_contains($resendSrc, 'id="csr-modal-verified"'));

$smtpSrc = (string) file_get_contents($root . '/smtp_sender.php');
$pass('smtp TLS verify_peer still enabled', str_contains($smtpSrc, "'verify_peer' => true"));
$pass('smtp still does not disable peer verify', !str_contains($smtpSrc, "'verify_peer' => false"));

echo ($fail === 0 ? "OK 0 failed\n" : "FAILED {$fail}\n");
exit($fail === 0 ? 0 : 1);
