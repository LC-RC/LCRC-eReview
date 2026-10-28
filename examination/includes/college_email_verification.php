<?php
declare(strict_types=1);

/**
 * College Examination pending registration and email verification.
 * Independent of LMS pending_registrations / payment / packages.
 */

require_once __DIR__ . '/college_student_number.php';
require_once __DIR__ . '/college_verify_rate_limit.php';
require_once __DIR__ . '/college_student_create.php';

const COLLEGE_VERIFY_EXPIRY_HOURS = 24;
const COLLEGE_VERIFY_TXN_RETRIES = 3;
const COLLEGE_PUBLIC_GENERIC_VERIFY =
    'If this email can receive a college registration message, we sent one. Check your inbox and spam folder.';
const COLLEGE_PUBLIC_GENERIC_NUMBER =
    'If a verified college account exists for that email, we sent the student number to that address.';
const COLLEGE_PUBLIC_RATE_LIMITED = 'Too many attempts. Please wait and try again.';
const COLLEGE_PUBLIC_UNAVAILABLE = 'Registration is temporarily unavailable. Please try again later.';
const COLLEGE_VERIFY_INVALID = 'This verification link is invalid or has expired. You can request a new message from the registration page.';

function college_pending_table_ready(mysqli $conn): bool
{
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'college_pending_registrations'");
    $ok = $r && mysqli_fetch_row($r);
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool) $ok;
}

function college_registration_schema_ready(mysqli $conn): bool
{
    return college_pending_table_ready($conn)
        && college_student_number_tables_ready($conn)
        && college_verify_rate_limit_table_ready($conn);
}

function college_request_peer_is_local_proxy(): bool
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function college_public_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    if (!college_request_peer_is_local_proxy()) {
        return false;
    }
    $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $first = trim(explode(',', $forwarded)[0] ?? '');

    return $first === 'https';
}

/**
 * Optional HTTPS origin from mail_config.php (public_base_url). Never logs the value with tokens.
 */
function college_configured_public_base_url(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = '';
    $configFile = dirname(__DIR__, 2) . '/config/mail_config.php';
    if (!is_file($configFile)) {
        return $cached;
    }
    $config = require $configFile;
    $raw = is_array($config) ? trim((string) ($config['public_base_url'] ?? '')) : '';
    if ($raw !== '' && preg_match('#^https://[A-Za-z0-9.-]+(?::\d+)?(?:/.*)?$#', $raw)) {
        $cached = rtrim($raw, '/');
    }

    return $cached;
}

function college_public_verify_base_url(): string
{
    $configured = college_configured_public_base_url();
    if ($configured !== '') {
        return $configured;
    }
    $scheme = college_public_request_is_https() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (str_ends_with($base, '/examination')) {
        $base = substr($base, 0, -strlen('/examination'));
    }
    $base = rtrim((string) $base, '/');

    return $scheme . '://' . $host . $base;
}

function college_public_verify_url(string $rawToken): string
{
    $path = '/college_verify_email';
    if (function_exists('ereview_url')) {
        $path = ereview_url('college_verify_email');
        if (!str_starts_with($path, 'http')) {
            return rtrim(college_public_verify_base_url(), '/') . '/' . ltrim($path, '/') . '?token=' . rawurlencode($rawToken);
        }

        return $path . '?token=' . rawurlencode($rawToken);
    }

    return rtrim(college_public_verify_base_url(), '/') . $path . '?token=' . rawurlencode($rawToken);
}

function college_new_verify_token(): array
{
    $raw = bin2hex(random_bytes(32));

    return ['raw' => $raw, 'hash' => hash('sha256', $raw)];
}

function college_email_wrap_html(string $title, string $innerHtml): string
{
    $year = date('Y');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $safeTitle . '</title></head>';
    $html .= '<body style="margin:0;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f1f5f9;padding:24px;">';
    $html .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Verify your LCRC eReview College Examination email.</div>';
    $html .= '<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">';
    $html .= '<div style="background:linear-gradient(135deg,#1F58C3 0%,#1E40AF 100%);padding:24px;text-align:center;">';
    $html .= '<span style="color:#fff;font-size:18px;font-weight:700;">LCRC</span> <span style="color:#F59E0B;font-size:18px;font-weight:700;">eReview</span>';
    $html .= '<p style="margin:8px 0 0;color:#bfdbfe;font-size:12px;">LCRC Review School &amp; Training Center</p>';
    $html .= '</div><div style="padding:28px 24px;">';
    $html .= '<h1 style="margin:0 0 12px;font-size:20px;color:#0f172a;">' . $safeTitle . '</h1>';
    $html .= $innerHtml;
    $html .= '</div><div style="padding:12px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;font-size:11px;color:#64748b;">';
    $html .= '© ' . $year . ' LCRC eReview. College Examination. This message does not include a password.</div></div></body></html>';

    return $html;
}

/**
 * SMTP debug lines with credentials and long tokens removed.
 *
 * @param list<string> $debugLog
 */
function college_mail_sanitize_debug(array $debugLog): string
{
    $kept = [];
    foreach (array_slice($debugLog, -8) as $line) {
        $line = (string) $line;
        if (stripos($line, 'AUTH LOGIN') !== false) {
            continue;
        }
        $line = preg_replace('/token=[A-Fa-f0-9]+/i', 'token=[redacted]', $line) ?? $line;
        $line = preg_replace('/[A-Za-z0-9+\/=]{24,}/', '[redacted]', $line) ?? $line;
        $kept[] = $line;
    }

    return implode(' | ', $kept);
}

function college_mail_recipient_domain(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false) {
        return 'invalid';
    }

    return strtolower(substr($email, $at + 1));
}

/**
 * @param array<string,mixed> $config
 */
function college_mail_log_outcome(bool $delivered, array $config, array $debugLog, string $toEmail, string $reason = ''): void
{
    $host = is_array($config) ? (string) ($config['smtp_host'] ?? '') : '';
    $port = is_array($config) ? (int) ($config['smtp_port'] ?? 0) : 0;
    $secure = is_array($config) ? (string) ($config['smtp_secure'] ?? '') : '';
    $from = is_array($config) ? (string) ($config['from_email'] ?? $config['smtp_username'] ?? '') : '';
    $fromDomain = college_mail_recipient_domain($from);
    $toDomain = college_mail_recipient_domain($toEmail);
    $detail = $reason !== '' ? $reason : college_mail_sanitize_debug($debugLog);
    error_log(
        '[eReview college] SMTP delivered=' . ($delivered ? '1' : '0')
        . ' host=' . $host
        . ' port=' . $port
        . ' secure=' . $secure
        . ' from_domain=' . $fromDomain
        . ' to_domain=' . $toDomain
        . ($detail !== '' ? ' detail=' . $detail : '')
    );
}

function college_send_html_email(string $toEmail, string $subject, string $html, string $plainFallback = ''): bool
{
    $configFile = dirname(__DIR__, 2) . '/config/mail_config.php';
    if (!is_file($configFile)) {
        error_log('[eReview college] mail_config.php missing; email not sent.');

        return false;
    }
    $config = require $configFile;
    require_once dirname(__DIR__, 2) . '/smtp_sender.php';
    $fromEmail = is_array($config) ? (string) ($config['from_email'] ?? $config['smtp_username'] ?? '') : '';
    $fromName = is_array($config) ? (string) ($config['from_name'] ?? 'LCRC eReview') : 'LCRC eReview';
    $looksLikeSmtp = is_array($config)
        && !empty($config['smtp_host'] ?? '')
        && !empty($config['smtp_username'] ?? '')
        && !empty($config['smtp_password'] ?? '');
    $strictValid = function_exists('isMailConfigValid') && is_array($config) && isMailConfigValid($config);
    if (!$looksLikeSmtp && !$strictValid) {
        college_mail_log_outcome(false, is_array($config) ? $config : [], [], $toEmail, 'smtp_not_configured');

        return false;
    }
    if ($plainFallback === '') {
        $plainFallback = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ["\n", "\n", "\n", "\n\n"], $html)), ENT_QUOTES, 'UTF-8');
        $plainFallback = trim(preg_replace("/\n{3,}/", "\n\n", $plainFallback) ?? $plainFallback);
    }
    $attempts = 3;
    $lastDebug = [];
    for ($i = 0; $i < $attempts; $i++) {
        $debugLog = [];
        try {
            if (function_exists('sendMailSmtpHtml') && sendMailSmtpHtml($toEmail, $subject, $html, $fromEmail, $fromName, $config, $debugLog)) {
                college_mail_log_outcome(true, is_array($config) ? $config : [], $debugLog, $toEmail, 'smtp_accepted');

                return true;
            }
            if (function_exists('sendMailSmtp') && sendMailSmtp($toEmail, $subject, $plainFallback, $fromEmail, $fromName, $config, $debugLog)) {
                college_mail_log_outcome(true, is_array($config) ? $config : [], $debugLog, $toEmail, 'smtp_accepted_plain');

                return true;
            }
        } catch (Throwable $e) {
            error_log('[eReview college] SMTP attempt failed: ' . $e->getMessage());
        }
        $lastDebug = $debugLog;
        $logBlob = implode("\n", $debugLog);
        if (stripos($logBlob, 'AUTH FAIL') !== false || stripos($logBlob, 'Username and Password not accepted') !== false) {
            college_mail_log_outcome(false, is_array($config) ? $config : [], $debugLog, $toEmail, 'smtp_auth_rejected');
            error_log('[eReview college] SMTP auth rejected. Update the App Password in config/mail_config.php');

            return false;
        }
        if ($i < $attempts - 1) {
            usleep(300000);
        }
    }
    college_mail_log_outcome(false, is_array($config) ? $config : [], $lastDebug, $toEmail, 'smtp_attempts_failed');
    error_log('[eReview college] SMTP attempts failed; skipping PHP mail() fallback.');

    return false;
}

function college_send_verification_email(string $toEmail, string $verificationUrl): bool
{
    $hours = (string) COLLEGE_VERIFY_EXPIRY_HOURS;
    $safeUrl = htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8');
    $inner = '<p style="margin:0 0 16px;color:#475569;line-height:1.6;">Confirm this email to finish College Examination registration. Your account and College Exam ID are created only after you verify.</p>';
    $inner .= '<p style="margin:0 0 24px;color:#475569;line-height:1.6;">This link expires in ' . $hours . ' hours and can be used once. If you did not register, ignore this message.</p>';
    $inner .= '<p style="text-align:center;margin:0 0 8px;"><a href="' . $safeUrl . '" style="display:inline-block;background:#1F58C3;color:#fff!important;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:600;font-size:15px;" role="button">Verify Email</a></p>';
    $inner .= '<p style="margin:16px 0 0;font-size:12px;color:#94a3b8;">If the button does not work, copy and paste this link into your browser:</p>';
    $inner .= '<p style="margin:4px 0 0;word-break:break-all;font-size:12px;color:#64748b;">' . $safeUrl . '</p>';
    $html = college_email_wrap_html('Verify your college registration', $inner);
    $plain = "LCRC eReview — College Examination\n\n"
        . "Confirm this email to finish registration. Your account and College Exam ID are created only after you verify.\n\n"
        . "Verify (expires in {$hours} hours, one-time use):\n{$verificationUrl}\n\n"
        . "If you did not register, ignore this message.\n";

    return college_send_html_email($toEmail, 'Verify your College Examination email - LCRC eReview', $html, $plain);
}

function college_send_student_number_email(string $toEmail, string $studentNumber): bool
{
    $safeSn = htmlspecialchars($studentNumber, ENT_QUOTES, 'UTF-8');
    $inner = '<p style="margin:0 0 16px;color:#475569;line-height:1.6;">Your College Examination registration is verified.</p>';
    $inner .= '<p style="margin:0 0 8px;color:#475569;line-height:1.6;">Your College Exam ID is:</p>';
    $inner .= '<p style="margin:0 0 20px;font-size:22px;font-weight:700;letter-spacing:0.04em;color:#0f172a;">' . $safeSn . '</p>';
    $inner .= '<p style="margin:0;color:#475569;line-height:1.6;">Sign in with this email. Use this ID to identify published examination results. It does not change if your name, school, or email changes.</p>';
    $html = college_email_wrap_html('Your College Exam ID', $inner);

    return college_send_html_email($toEmail, 'Your College Exam ID - LCRC eReview', $html);
}

function college_users_select_email_for_update(mysqli $conn, string $email): array|false|null
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT user_id, role, status, email, email_verified, student_number, full_name, password, school, section, review_type
         FROM users WHERE email=? LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $email);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        return false;
    }
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function college_pending_upsert(mysqli $conn, array $data, string $tokenHash, string $expiresAt): bool
{
    $email = (string) $data['email'];
    $fullName = (string) $data['full_name'];
    $school = (string) $data['school'];
    $section = (string) $data['section'];
    $passwordHash = (string) $data['password_hash'];
    $profilePicture = (string) ($data['profile_picture'] ?? '');
    $useDefault = (int) $data['use_default_avatar'];
    $existingUserId = (int) ($data['existing_user_id'] ?? 0);
    if ($existingUserId < 0) {
        $existingUserId = 0;
    }
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO college_pending_registrations
            (email, full_name, school, section, password_hash, profile_picture, use_default_avatar,             existing_user_id, token_hash, expires_at, used_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, NULL, ?, ?)
         ON DUPLICATE KEY UPDATE
            full_name=VALUES(full_name),
            school=VALUES(school),
            section=VALUES(section),
            password_hash=VALUES(password_hash),
            profile_picture=VALUES(profile_picture),
            use_default_avatar=VALUES(use_default_avatar),
            existing_user_id=VALUES(existing_user_id),
            token_hash=VALUES(token_hash),
            expires_at=VALUES(expires_at),
            used_at=NULL,
            updated_at=VALUES(updated_at)'
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'ssssssiissss',
        $email,
        $fullName,
        $school,
        $section,
        $passwordHash,
        $profilePicture,
        $useDefault,
        $existingUserId,
        $tokenHash,
        $expiresAt,
        $now,
        $now
    );
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

function college_mark_pending_used(mysqli $conn, int $pendingId): bool
{
    $stmt = mysqli_prepare($conn, 'UPDATE college_pending_registrations SET used_at=NOW(), updated_at=NOW() WHERE pending_id=? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $pendingId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

/**
 * @return array{ok:bool, generic?:bool, error?:string, sent?:bool}
 */
function college_public_register_from_request(mysqli $conn, array $post, ?array $files = null): array
{
    $prepared = college_student_prepare_registration_input($conn, $post, $files);
    if (empty($prepared['ok'])) {
        return ['ok' => false, 'error' => (string) ($prepared['error'] ?? 'Invalid registration details.')];
    }
    if (!college_registration_schema_ready($conn)) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $email = (string) $prepared['email'];
    $rate = college_verify_rate_limit_consume($conn, 'register', $email);
    if (empty($rate['ok'])) {
        $err = (string) ($rate['error'] ?? 'unavailable');
        if ($err === 'rate_limited') {
            return ['ok' => false, 'error' => COLLEGE_PUBLIC_RATE_LIMITED];
        }

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }

    $token = college_new_verify_token();
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
        ->modify('+' . COLLEGE_VERIFY_EXPIRY_HOURS . ' hours')
        ->format('Y-m-d H:i:s');
    $pending = [
        'email' => $email,
        'full_name' => (string) $prepared['full_name'],
        'school' => (string) $prepared['school'],
        'section' => (string) $prepared['section'],
        'password_hash' => password_hash((string) $prepared['password'], PASSWORD_DEFAULT),
        'profile_picture' => (string) ($prepared['profile_picture_path'] ?? ''),
        'use_default_avatar' => (int) ($prepared['use_default_avatar'] ?? 1),
        'existing_user_id' => null,
    ];
    $sendMail = false;
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $user = college_users_select_email_for_update($conn, $email);
    if ($user === false) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    if (is_array($user)) {
        $role = strtolower(trim((string) ($user['role'] ?? '')));
        $verified = (int) ($user['email_verified'] ?? 0) === 1;
        if ($role !== 'college_student' || $verified) {
            mysqli_commit($conn);

            return ['ok' => true, 'generic' => true, 'sent' => false];
        }
        $pending['existing_user_id'] = (int) $user['user_id'];
        $sendMail = true;
    } else {
        $sendMail = true;
    }
    $lockPending = mysqli_prepare($conn, 'SELECT pending_id FROM college_pending_registrations WHERE email=? LIMIT 1 FOR UPDATE');
    if (!$lockPending) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    mysqli_stmt_bind_param($lockPending, 's', $email);
    if (!mysqli_stmt_execute($lockPending)) {
        mysqli_stmt_close($lockPending);
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    mysqli_stmt_close($lockPending);
    if (!college_pending_upsert($conn, $pending, $token['hash'], $expiresAt)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $delivered = false;
    if ($sendMail) {
        $delivered = college_send_verification_email($email, college_public_verify_url($token['raw']));
    }

    return ['ok' => true, 'generic' => true, 'sent' => $sendMail, 'delivered' => $delivered];
}

/**
 * @return array{ok:bool, error?:string, generic?:bool, sent?:bool}
 */
function college_public_resend_verification(mysqli $conn, string $email): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => true, 'generic' => true, 'sent' => false];
    }
    if (!college_registration_schema_ready($conn)) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $rate = college_verify_rate_limit_consume($conn, 'resend', $email);
    if (empty($rate['ok'])) {
        $err = (string) ($rate['error'] ?? 'unavailable');
        if ($err === 'rate_limited') {
            return ['ok' => false, 'error' => COLLEGE_PUBLIC_RATE_LIMITED];
        }

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $token = college_new_verify_token();
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
        ->modify('+' . COLLEGE_VERIFY_EXPIRY_HOURS . ' hours')
        ->format('Y-m-d H:i:s');
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $user = college_users_select_email_for_update($conn, $email);
    if ($user === false) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $lockPending = mysqli_prepare($conn, 'SELECT pending_id, password_hash, full_name, school, section, profile_picture, use_default_avatar, existing_user_id FROM college_pending_registrations WHERE email=? LIMIT 1 FOR UPDATE');
    if (!$lockPending) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    mysqli_stmt_bind_param($lockPending, 's', $email);
    if (!mysqli_stmt_execute($lockPending)) {
        mysqli_stmt_close($lockPending);
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $pending = mysqli_fetch_assoc(mysqli_stmt_get_result($lockPending));
    mysqli_stmt_close($lockPending);

    $send = false;
    if (is_array($user)) {
        $role = strtolower(trim((string) ($user['role'] ?? '')));
        $verified = (int) ($user['email_verified'] ?? 0) === 1;
        if ($role === 'college_student' && !$verified) {
            $payload = [
                'email' => $email,
                'full_name' => (string) ($user['full_name'] ?? ''),
                'school' => (string) ($user['school'] ?? ''),
                'section' => (string) ($user['section'] ?? ''),
                'password_hash' => (string) ($user['password'] ?? 'x'),
                'profile_picture' => (string) ($user['profile_picture'] ?? ''),
                'use_default_avatar' => (int) ($user['use_default_avatar'] ?? 1),
                'existing_user_id' => (int) $user['user_id'],
            ];
            if (!college_pending_upsert($conn, $payload, $token['hash'], $expiresAt)) {
                mysqli_rollback($conn);

                return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
            }
            $send = true;
        }
    } elseif (is_array($pending)) {
        $payload = [
            'email' => $email,
            'full_name' => (string) ($pending['full_name'] ?? ''),
            'school' => (string) ($pending['school'] ?? ''),
            'section' => (string) ($pending['section'] ?? ''),
            'password_hash' => (string) ($pending['password_hash'] ?? ''),
            'profile_picture' => (string) ($pending['profile_picture'] ?? ''),
            'use_default_avatar' => (int) ($pending['use_default_avatar'] ?? 1),
            'existing_user_id' => isset($pending['existing_user_id']) ? (int) $pending['existing_user_id'] : null,
        ];
        if (!college_pending_upsert($conn, $payload, $token['hash'], $expiresAt)) {
            mysqli_rollback($conn);

            return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
        }
        $send = true;
    }
    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $delivered = false;
    if ($send) {
        $delivered = college_send_verification_email($email, college_public_verify_url($token['raw']));
    }

    return ['ok' => true, 'generic' => true, 'sent' => $send, 'delivered' => $delivered];
}

/**
 * @return array{ok:bool, error?:string, generic?:bool}
 */
function college_public_resend_student_number(mysqli $conn, string $email): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => true, 'generic' => true];
    }
    if (!college_verify_rate_limit_table_ready($conn) || !college_student_number_users_column_ready($conn)) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $rate = college_verify_rate_limit_consume($conn, 'resend_number', $email);
    if (empty($rate['ok'])) {
        $err = (string) ($rate['error'] ?? 'unavailable');
        if ($err === 'rate_limited') {
            return ['ok' => false, 'error' => COLLEGE_PUBLIC_RATE_LIMITED];
        }

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT email, student_number, email_verified, role FROM users WHERE email=? AND role='college_student' LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    mysqli_stmt_bind_param($stmt, 's', $email);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (is_array($row)
        && (int) ($row['email_verified'] ?? 0) === 1
        && trim((string) ($row['student_number'] ?? '')) !== ''
    ) {
        $accountEmail = (string) $row['email'];
        $sn = trim((string) $row['student_number']);
        college_send_student_number_email($accountEmail, $sn);
    }

    return ['ok' => true, 'generic' => true];
}

function college_insert_verified_college_student(
    mysqli $conn,
    array $pending,
    string $studentNumber
): array {
    $fullName = (string) ($pending['full_name'] ?? '');
    $school = (string) ($pending['school'] ?? '');
    $section = (string) ($pending['section'] ?? '');
    $hash = (string) ($pending['password_hash'] ?? '');
    $email = (string) ($pending['email'] ?? '');
    $profilePicturePath = (string) ($pending['profile_picture'] ?? '');
    $avatarUseDefault = (int) ($pending['use_default_avatar'] ?? 1);
    $status = 'approved';
    $hasEv = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_verified'");
    $evCol = $hasEv && mysqli_fetch_assoc($hasEv);
    if ($hasEv) {
        mysqli_free_result($hasEv);
    }
    $hasPp = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_picture'");
    $ppCol = $hasPp && mysqli_fetch_assoc($hasPp);
    if ($hasPp) {
        mysqli_free_result($hasPp);
    }
    $hasUa = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'use_default_avatar'");
    $uaCol = $hasUa && mysqli_fetch_assoc($hasUa);
    if ($hasUa) {
        mysqli_free_result($hasUa);
    }
    if (!$evCol || !college_student_number_users_column_ready($conn)) {
        return ['ok' => false, 'error' => 'Required user columns are missing.'];
    }
    if ($ppCol && $uaCol) {
        $ins = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, profile_picture, use_default_avatar, email, password, role, status, email_verified, student_number)
             VALUES (?, 'undergrad', ?, ?, NULL, NULL, ?, ?, ?, ?, 'college_student', ?, 1, ?)"
        );
        if (!$ins) {
            return ['ok' => false, 'error' => 'Could not create account.'];
        }
        mysqli_stmt_bind_param($ins, 'ssssissss', $fullName, $school, $section, $profilePicturePath, $avatarUseDefault, $email, $hash, $status, $studentNumber);
    } else {
        $ins = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, email, password, role, status, email_verified, student_number)
             VALUES (?, 'undergrad', ?, ?, NULL, NULL, ?, ?, 'college_student', ?, 1, ?)"
        );
        if (!$ins) {
            return ['ok' => false, 'error' => 'Could not create account.'];
        }
        mysqli_stmt_bind_param($ins, 'sssssss', $fullName, $school, $section, $email, $hash, $status, $studentNumber);
    }
    if (!mysqli_stmt_execute($ins)) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($ins);

        return ['ok' => false, 'error' => 'Could not create account.', 'errno' => $errno];
    }
    $userId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);
    $catalog = college_schools_find_by_name($conn, $school);
    if ($catalog && (int) ($catalog['school_id'] ?? 0) > 0) {
        college_schools_apply_user_fk($conn, $userId, (int) $catalog['school_id']);
    }

    return ['ok' => true, 'user_id' => $userId, 'student_number' => $studentNumber];
}

/**
 * @return array{ok:bool, error?:string, student_number?:string, email?:string, number_email_sent?:bool, existing?:bool}
 */
function college_complete_verification(mysqli $conn, string $rawToken): array
{
    $rawToken = trim($rawToken);
    if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
        return ['ok' => false, 'error' => COLLEGE_VERIFY_INVALID];
    }
    if (!college_registration_schema_ready($conn)) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $tokenHash = hash('sha256', strtolower($rawToken));
    $peek = mysqli_prepare($conn, 'SELECT email FROM college_pending_registrations WHERE token_hash=? LIMIT 1');
    if (!$peek) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    mysqli_stmt_bind_param($peek, 's', $tokenHash);
    mysqli_stmt_execute($peek);
    $peekRow = mysqli_fetch_assoc(mysqli_stmt_get_result($peek));
    mysqli_stmt_close($peek);
    if (!$peekRow) {
        return ['ok' => false, 'error' => COLLEGE_VERIFY_INVALID];
    }
    $email = strtolower(trim((string) ($peekRow['email'] ?? '')));
    $last = ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    for ($attempt = 0; $attempt < COLLEGE_VERIFY_TXN_RETRIES; $attempt++) {
        $last = college_complete_verification_once($conn, $tokenHash, $email);
        if (!empty($last['ok'])) {
            return $last;
        }
        $errno = (int) ($last['errno'] ?? 0);
        if ($errno === 1213 || $errno === 1205) {
            continue;
        }

        return $last;
    }

    return $last;
}

/**
 * @return array<string,mixed>
 */
function college_complete_verification_once(mysqli $conn, string $tokenHash, string $email): array
{
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $user = college_users_select_email_for_update($conn, $email);
    if ($user === false) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $pstmt = mysqli_prepare(
        $conn,
        'SELECT pending_id, email, full_name, school, section, password_hash, profile_picture, use_default_avatar, existing_user_id, token_hash, expires_at, used_at
         FROM college_pending_registrations WHERE token_hash=? LIMIT 1 FOR UPDATE'
    );
    if (!$pstmt) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    mysqli_stmt_bind_param($pstmt, 's', $tokenHash);
    if (!mysqli_stmt_execute($pstmt)) {
        mysqli_stmt_close($pstmt);
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $pending = mysqli_fetch_assoc(mysqli_stmt_get_result($pstmt));
    mysqli_stmt_close($pstmt);
    if (!$pending) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_VERIFY_INVALID];
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $expires = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($pending['expires_at'] ?? ''), new DateTimeZone('Asia/Manila'));
    if (!empty($pending['used_at']) || !$expires || $expires < $now) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_VERIFY_INVALID];
    }
    $pendingId = (int) $pending['pending_id'];
    $studentNumber = '';
    $userId = 0;
    $existing = false;

    if (is_array($user)) {
        $role = strtolower(trim((string) ($user['role'] ?? '')));
        $userId = (int) ($user['user_id'] ?? 0);
        $existingSn = trim((string) ($user['student_number'] ?? ''));
        if ($role !== 'college_student') {
            college_mark_pending_used($conn, $pendingId);
            mysqli_commit($conn);

            return ['ok' => false, 'error' => 'This email is already used by another account type. Contact an administrator.'];
        }
        $existing = true;
        if ((int) ($user['email_verified'] ?? 0) !== 1) {
            $upd = mysqli_prepare($conn, "UPDATE users SET email_verified=1 WHERE user_id=? AND role='college_student' LIMIT 1");
            if (!$upd) {
                mysqli_rollback($conn);

                return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
            }
            mysqli_stmt_bind_param($upd, 'i', $userId);
            if (!mysqli_stmt_execute($upd)) {
                mysqli_stmt_close($upd);
                mysqli_rollback($conn);

                return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
            }
            mysqli_stmt_close($upd);
        }
        if ($existingSn === '') {
            $alloc = college_student_number_allocate_in_transaction($conn);
            if (empty($alloc['ok'])) {
                $errno = (int) ($alloc['errno'] ?? 0);
                mysqli_rollback($conn);

                return ['ok' => false, 'error' => (string) ($alloc['error'] ?? COLLEGE_PUBLIC_UNAVAILABLE), 'errno' => $errno];
            }
            $studentNumber = (string) $alloc['student_number'];
            $snUpd = mysqli_prepare($conn, "UPDATE users SET student_number=? WHERE user_id=? AND (student_number IS NULL OR TRIM(student_number)='') LIMIT 1");
            if (!$snUpd) {
                mysqli_rollback($conn);

                return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
            }
            mysqli_stmt_bind_param($snUpd, 'si', $studentNumber, $userId);
            if (!mysqli_stmt_execute($snUpd)) {
                $errno = mysqli_errno($conn);
                mysqli_stmt_close($snUpd);
                mysqli_rollback($conn);

                return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE, 'errno' => $errno];
            }
            mysqli_stmt_close($snUpd);
        } else {
            $studentNumber = $existingSn;
        }
    } else {
        $alloc = college_student_number_allocate_in_transaction($conn);
        if (empty($alloc['ok'])) {
            $errno = (int) ($alloc['errno'] ?? 0);
            mysqli_rollback($conn);

            return ['ok' => false, 'error' => (string) ($alloc['error'] ?? COLLEGE_PUBLIC_UNAVAILABLE), 'errno' => $errno];
        }
        $studentNumber = (string) $alloc['student_number'];
        $created = college_insert_verified_college_student($conn, $pending, $studentNumber);
        if (empty($created['ok'])) {
            $errno = (int) ($created['errno'] ?? 0);
            mysqli_rollback($conn);

            return ['ok' => false, 'error' => (string) ($created['error'] ?? COLLEGE_PUBLIC_UNAVAILABLE), 'errno' => $errno];
        }
        $userId = (int) ($created['user_id'] ?? 0);
    }
    if (!college_mark_pending_used($conn, $pendingId)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $mailOk = $studentNumber !== '' && college_send_student_number_email($email, $studentNumber);

    return [
        'ok' => true,
        'user_id' => $userId,
        'email' => $email,
        'student_number' => $studentNumber,
        'number_email_sent' => $mailOk,
        'existing' => $existing,
    ];
}
