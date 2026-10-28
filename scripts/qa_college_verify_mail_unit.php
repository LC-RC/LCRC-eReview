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
$pass('does not use PHP mail fallback', str_contains($src, 'skipping PHP mail() fallback'));
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
$pass('failed confirm has error visual class', str_contains($regSrc, 'csr-confirm-card--failed'));
$pass('alert role is on the lead not the card', str_contains($regSrc, 'csr-confirm-lead" role="<?php echo $mailFailed ? \'alert\' : \'status\'; ?>"'));
$pass('resend form is outside the alert role on the lead', str_contains($regSrc, 'class="csr-confirm-resend"') && !preg_match('/role="alert"[\s\S]*csr-confirm-resend/', $regSrc));
$pass('confirmation has resend action', str_contains($regSrc, 'college_resend_verification'));
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
