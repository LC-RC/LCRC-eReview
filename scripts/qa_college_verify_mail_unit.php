<?php
/**
 * DB-free checks for college verification URL/HTTPS and mail logging.
 * Does not send email or write the database.
 *
 *   C:\xampp\php\php.exe scripts\qa_college_verify_mail_unit.php
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

$_SERVER['HTTPS'] = 'off';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$pass('forwarded https trusted from local proxy', college_public_request_is_https());

$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
$pass('forwarded https ignored from public client', !college_public_request_is_https());

$_SERVER['HTTPS'] = 'off';
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$pass('plain http not forced https', !college_public_request_is_https());

$san = college_mail_sanitize_debug([
    'AUTH LOGIN',
    'C: AUTH LOGIN',
    'S: 235 2.7.0 Accepted',
    'token=abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
]);
$pass('sanitize drops AUTH LOGIN', !str_contains($san, 'AUTH LOGIN'));
$pass('sanitize redacts long secrets', !str_contains($san, 'abcdef0123') && str_contains($san, '[redacted]'));

$pass('recipient domain', college_mail_recipient_domain('Student@Example.COM') === 'example.com');

$src = (string) file_get_contents($root . '/examination/includes/college_email_verification.php');
$pass('register captures SMTP delivered', str_contains($src, "'delivered' => \$delivered"));
$pass('resend captures SMTP delivered', substr_count($src, "'delivered' => \$delivered") >= 2);
$pass('confirm session stores waiting flag default false', str_contains($src, "'waiting' => false") && str_contains($src, 'function college_public_confirm_session_ack_waiting'));
$pass(
    'generic success does not claim a send',
    str_contains($src, 'A verification message is issued only when this portal can send one')
    && !str_contains(COLLEGE_PUBLIC_GENERIC_VERIFY, 'we sent')
    && !str_contains(COLLEGE_PUBLIC_GENERIC_VERIFY, 'we queued')
);
$pass(
    'mail-failed copy does not claim a send',
    defined('COLLEGE_PUBLIC_MAIL_FAILED')
    && !str_contains(COLLEGE_PUBLIC_MAIL_FAILED, 'we sent')
    && str_contains(COLLEGE_PUBLIC_MAIL_FAILED, 'could not be sent')
);
$pass(
    'no-send is not classified as mail failed',
    !college_public_attempted_mail_failed(['ok' => true, 'sent' => false])
    && college_public_attempted_mail_failed(['ok' => true, 'sent' => true, 'delivered' => false])
);

$regSrc = (string) file_get_contents($root . '/examination/student_registration.php');
$pass('confirmation does not claim delivery', str_contains($regSrc, 'does not confirm that the provider accepted'));
$pass('generic confirm does not say use the inbox', !str_contains($regSrc, 'Use the inbox for'));
$pass('generic confirm hedges issued message', str_contains($regSrc, 'If a message was issued, check this address:'));
$pass('generic confirm uses LMS email-sent modal', str_contains($regSrc, 'id="csr-modal-email-sent"') && str_contains($regSrc, 'reg-modal-card'));
$pass('failed confirm uses LMS error modal', str_contains($regSrc, 'id="csr-modal-error"') && str_contains($regSrc, 'reg-error-card'));
$pass('generic lead uses status role', str_contains($regSrc, 'role="status"'));
$pass('failed message uses alert role', str_contains($regSrc, 'role="alert"'));
$pass('resend form is not inside the alert paragraph', str_contains($regSrc, 'class="csr-confirm-resend"') && !preg_match('/id="csr-error-message"[^>]*>[\s\S]*csr-confirm-resend[\s\S]*<\/p>/', $regSrc));
$pass('confirmation has resend action', str_contains($regSrc, 'college_resend_verification'));
$pass('confirm overlay keeps the registration form', str_contains($regSrc, 'id="reg-form"'));
$pass('waiting modal is present for generic confirm', str_contains($regSrc, 'id="csr-modal-waiting"') && str_contains($regSrc, 'Waiting for email verification'));
$pass('waiting modal does not offer continue to login', !str_contains($regSrc, 'id="csr-waiting-continue"') && !preg_match('/csr-modal-waiting[\s\S]{0,1200}Continue to login/', $regSrc));
$pass('waiting modal keeps resend action', preg_match('/id="csr-modal-waiting"[\s\S]*csr-confirm-resend[\s\S]*Resend verification/', $regSrc) === 1);
$pass('confirm page resolves verified state server-side', str_contains($regSrc, 'college_public_confirm_verified_status($conn)') && str_contains($regSrc, '$showConfirmVerified'));
$pass('confirm page restores waiting state from session', str_contains($regSrc, "\$confirm['waiting']") && str_contains($regSrc, '$showConfirmWaiting'));
$pass('existing-account modal is present', str_contains($regSrc, 'id="csr-modal-existing"') && str_contains($regSrc, 'college_forgot_password'));
$pass('verified overlay is present for generic confirm', str_contains($regSrc, 'id="csr-modal-verified"') && str_contains($regSrc, 'Email verified successfully!'));
$pass('verified overlay does not show student number', !preg_match('/csr-modal-verified[\s\S]*student_number|csr-verified-heading[\s\S]*College Exam ID/', $regSrc));
$pass('verified overlay has a single continue-to-login CTA', substr_count($regSrc, 'id="csr-verified-continue"') === 1);
$pass('confirm page exposes session status URL only on pending verify', str_contains($regSrc, 'data-csr-status-url') && str_contains($regSrc, 'college_registration_status'));
$pass('waiting copy does not claim inbox delivery', str_contains($regSrc, 'This tab does not confirm Inbox delivery'));

$jsSrc = (string) file_get_contents($root . '/assets/js/college-student-registration.js');
$pass('college JS does not poll check_verification', !str_contains($jsSrc, 'check_verification'));
$pass('college JS listens for same-origin verify signal', str_contains($jsSrc, "BroadcastChannel('csr-college-email-verified')") && str_contains($jsSrc, 'showVerifiedModal'));
$pass('college JS confirms success via session status endpoint', str_contains($jsSrc, 'confirmVerifiedFromServer') && str_contains($jsSrc, 'data-csr-status-url'));
$pass('college JS persists waiting state via status POST ack', str_contains($jsSrc, 'ackWaitingOnServer') && str_contains($jsSrc, 'action=ack_waiting') && str_contains($jsSrc, 'data-csr-csrf'));
$pass('college JS does not treat BroadcastChannel as verified', str_contains($jsSrc, 'confirmVerifiedFromServer();') && !preg_match('/BroadcastChannel[\s\S]{0,400}showVerifiedModal\(\)/', $jsSrc));
$pass('college JS keeps waiting on network failure', str_contains($jsSrc, ').catch(function () {})'));

$pass('pending confirm without user is not verified', college_public_confirm_status_decide(['email' => 'a@example.com', 'existing_college' => false, 'mail_failed' => false], null) === ['ok' => true, 'verified' => false]);
$pass('unverified user row is not verified', college_public_confirm_status_decide(['email' => 'a@example.com', 'existing_college' => false, 'mail_failed' => false], ['role' => 'college_student', 'email_verified' => 0]) === ['ok' => true, 'verified' => false]);
$pass('verified college_student is verified', college_public_confirm_status_decide(['email' => 'a@example.com', 'existing_college' => false, 'mail_failed' => false], ['role' => 'college_student', 'email_verified' => 1]) === ['ok' => true, 'verified' => true]);
$pass('verified LMS dual-access active is verified', college_public_confirm_status_decide(['email' => 'a@example.com', 'existing_college' => false, 'mail_failed' => false], ['role' => 'student', 'email_verified' => 1, 'college_examination_access' => 'active']) === ['ok' => true, 'verified' => true]);
$pass('LMS-only verified student is not college-verified', college_public_confirm_status_decide(['email' => 'a@example.com', 'existing_college' => false, 'mail_failed' => false], ['role' => 'student', 'email_verified' => 1, 'college_examination_access' => 'none']) === ['ok' => true, 'verified' => false]);
$pass('missing confirm session is not ok', college_public_confirm_status_decide(null, ['role' => 'college_student', 'email_verified' => 1]) === ['ok' => false, 'verified' => false]);
$pass('existing-college confirm is not treated as new verify', college_public_confirm_status_decide(['email' => 'a@example.com', 'existing_college' => true, 'mail_failed' => false], ['role' => 'college_student', 'email_verified' => 1]) === ['ok' => true, 'verified' => false]);
$pass('mail-failed confirm is not treated as verified', college_public_confirm_status_decide(['email' => 'a@example.com', 'existing_college' => false, 'mail_failed' => true], ['role' => 'college_student', 'email_verified' => 1]) === ['ok' => true, 'verified' => false]);
$pass('empty email confirm is not ok', college_public_confirm_status_decide(['email' => '', 'existing_college' => false, 'mail_failed' => false], ['role' => 'college_student', 'email_verified' => 1]) === ['ok' => false, 'verified' => false]);

$_SESSION = [];
college_public_confirm_session_put(COLLEGE_REG_CONFIRM_SESSION_KEY, 'pending@example.com');
$pass('ack waiting marks confirm session waiting', college_public_confirm_session_ack_waiting() && !empty((college_public_confirm_session_get(COLLEGE_REG_CONFIRM_SESSION_KEY) ?? [])['waiting']));
$pendingGet = college_public_confirm_session_get(COLLEGE_REG_CONFIRM_SESSION_KEY);
$pass('pending confirm without verified user stays unverified', college_public_confirm_status_decide($pendingGet, null) === ['ok' => true, 'verified' => false]);

$statusSrc = (string) file_get_contents($root . '/examination/college_registration_status.php');
$pass('status endpoint is GET JSON', str_contains($statusSrc, "Content-Type: application/json") && str_contains($statusSrc, 'college_public_confirm_verified_status'));
$pass('status endpoint accepts POST ack_waiting with CSRF', str_contains($statusSrc, "action !== 'ack_waiting'") && str_contains($statusSrc, 'college_public_confirm_session_ack_waiting'));
$pass('status endpoint ignores request email', !preg_match('/\$_GET\[.email.\]|\$_POST\[.email.\]|\$_REQUEST\[.email.\]/', $statusSrc));
$pass('status endpoint does not return student number or email', !str_contains($statusSrc, 'student_number') && !str_contains($statusSrc, "'email'"));
$pass('status endpoint does not accept tokens', !preg_match('/\$_GET\[.token.\]|\$_POST\[.token.\]|\$_REQUEST\[.token.\]/', $statusSrc));

$verifySrc = (string) file_get_contents($root . '/examination/college_verify_email.php');
$pass(
    'verify success signals same-origin tabs',
    str_contains($verifySrc, "BroadcastChannel('csr-college-email-verified')")
    && str_contains($verifySrc, 'postMessage({ ok: true })')
);
$pass('verify signal is success-only', preg_match('/if \(\$ok\):[\s\S]*BroadcastChannel[\s\S]*endif/', $verifySrc) === 1);
$pass('verify signal does not include token', !preg_match('/BroadcastChannel[\s\S]{0,500}\$_GET\[.token/', $verifySrc) && !preg_match('/postMessage\([^)]*token/', $verifySrc));
$pass('verify signal does not include student number', !preg_match('/postMessage\([^)]*student/', $verifySrc));
$pass('verify page still completes token verification', str_contains($verifySrc, 'college_complete_verification($conn'));
$pass('verify success uses backend College Exam ID', str_contains($verifySrc, '$studentNumber') && str_contains($verifySrc, '$examIdDisplay') && !str_contains($verifySrc, '2026-025'));
$pass('verify continue goes to shared login', str_contains($verifySrc, 'href="login"') && str_contains($verifySrc, 'Continue to login'));
$pass('verify emailed-ID note is mail-sent only', str_contains($verifySrc, '$mailSent') && str_contains($verifySrc, 'number_email_sent') && str_contains($verifySrc, 'has also been sent to your verified email'));
$pass('verify error state still offers resend and register', str_contains($verifySrc, 'college_resend_verification') && str_contains($verifySrc, 'student_registration'));
$pass('verify page does not change exam-ID allocation', !str_contains($verifySrc, 'college_student_number_allocate'));
$pass('registration POST success uses 303 redirect', str_contains($regSrc, ", true, 303)"));
$pass('registration uses attempted-mail-failed helper', str_contains($regSrc, 'college_public_attempted_mail_failed($result)'));
$pass('registration GET confirm does not call register', !preg_match('/college_public_register_from_request[\s\S]*confirm=1/', $regSrc) && str_contains($regSrc, 'college_public_confirm_session_get'));
$pass('registration has one body open', preg_match_all('/<body\b/i', $regSrc) === 1);
$pass('registration has one body close', preg_match_all('/<\/body>/i', $regSrc) === 1);

$resendSrc = (string) file_get_contents($root . '/examination/college_resend_verification.php');
$pass('resend success does not claim link replacement', !preg_match('/new link replaces|invalidates the previous/i', $resendSrc));
$pass('resend mail-failed heading is present', str_contains($resendSrc, 'Verification email not sent'));
$pass('resend email uses static top label', str_contains($resendSrc, 'reg-top-label') && !str_contains($resendSrc, 'float-label-wrap'));

$smtpSrc = (string) file_get_contents($root . '/smtp_sender.php');
$pass('smtp verify_peer enabled', str_contains($smtpSrc, "'verify_peer' => true"));
$pass('smtp verify_peer_name enabled', str_contains($smtpSrc, "'verify_peer_name' => true"));
$pass('smtp does not disable peer verify', !str_contains($smtpSrc, "'verify_peer' => false"));

echo ($fail === 0 ? "OK 0 failed\n" : "FAILED {$fail}\n");
exit($fail === 0 ? 0 : 1);
