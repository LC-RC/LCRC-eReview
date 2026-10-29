<?php
declare(strict_types=1);

/**
 * Dedicated College Examination password reset.
 * Does not use LMS forgot_password / reset_password / password_reset.php.
 * Tokens are hashed, single-use, and expire. No plaintext passwords.
 */

require_once __DIR__ . '/college_email_verification.php';
require_once __DIR__ . '/college_student_create.php';
require_once __DIR__ . '/college_auth_credentials.php';

const COLLEGE_RESET_EXPIRY_HOURS = 1;

function college_password_reset_schema_ready(mysqli $conn): bool
{
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'college_password_reset_tokens'");
    $ok = $r && mysqli_fetch_row($r);
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool) $ok;
}

/**
 * Fail closed when migration 059 has not been applied.
 * Never creates the table at request time (MySQL DDL would commit an open transaction).
 */
function college_password_reset_schema_ensure(mysqli $conn): bool
{
    return college_password_reset_schema_ready($conn);
}

function college_new_reset_token(): array
{
    $raw = bin2hex(random_bytes(32));

    return ['raw' => $raw, 'hash' => hash('sha256', strtolower($raw))];
}

function college_reset_token_hash(string $rawToken): string
{
    return hash('sha256', strtolower(trim($rawToken)));
}

function college_reset_token_format_valid(string $rawToken): bool
{
    return (bool) preg_match('/^[a-f0-9]{64}$/i', trim($rawToken));
}

/**
 * @param array<string,mixed>|null $row
 */
function college_reset_token_row_usable(?array $row, ?DateTimeImmutable $now = null): bool
{
    if (!is_array($row)) {
        return false;
    }
    if (!empty($row['used_at'])) {
        return false;
    }
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $expires = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        (string) ($row['expires_at'] ?? ''),
        new DateTimeZone('Asia/Manila')
    );

    return $expires instanceof DateTimeImmutable && $expires >= $now;
}

function college_public_reset_url(string $rawToken): string
{
    $path = '/college_reset_password';
    if (function_exists('ereview_url')) {
        $path = ereview_url('college_reset_password');
        if (!str_starts_with((string) $path, 'http')) {
            return rtrim(college_public_verify_base_url(), '/') . '/' . ltrim((string) $path, '/') . '?token=' . rawurlencode($rawToken);
        }

        return $path . '?token=' . rawurlencode($rawToken);
    }

    return rtrim(college_public_verify_base_url(), '/') . $path . '?token=' . rawurlencode($rawToken);
}

function college_send_password_reset_email(string $toEmail, string $resetUrl): bool
{
    $hours = (string) COLLEGE_RESET_EXPIRY_HOURS;
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $inner = '<p style="margin:0 0 16px;color:#475569;line-height:1.6;">A password reset was requested for a College Examination account. The link below expires in ' . $hours . ' hour and can be used once.</p>';
    $inner .= '<p style="text-align:center;margin:0 0 8px;"><a href="' . $safeUrl . '" style="display:inline-block;background:#1F58C3;color:#fff!important;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:600;font-size:15px;" role="button">Reset password</a></p>';
    $inner .= '<p style="margin:16px 0 0;font-size:12px;color:#94a3b8;">If the button does not work, copy and paste this link into your browser:</p>';
    $inner .= '<p style="margin:4px 0 0;word-break:break-all;font-size:12px;color:#64748b;">' . $safeUrl . '</p>';
    $inner .= '<p style="margin:16px 0 0;color:#475569;line-height:1.6;">If you did not request this, ignore this message. Your password will not change.</p>';
    $html = college_email_wrap_html('Reset your College Examination password', $inner);
    $plain = "LCRC eReview — College Examination\n\n"
        . "A password reset was requested. This link expires in {$hours} hour and can be used once.\n\n"
        . "{$resetUrl}\n\n"
        . "If you did not request this, ignore this message.\n";

    return college_send_html_email($toEmail, 'Reset your College Examination password - LCRC eReview', $html, $plain);
}

/**
 * @return array{ok:bool, generic?:bool, sent?:bool, delivered?:bool, error?:string}
 */
function college_public_request_password_reset(mysqli $conn, string $email): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => true, 'generic' => true, 'sent' => false];
    }
    if (!college_password_reset_schema_ensure($conn) || !college_verify_rate_limit_table_ready($conn)) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $rate = college_verify_rate_limit_consume($conn, 'reset', $email);
    if (empty($rate['ok'])) {
        $err = (string) ($rate['error'] ?? 'unavailable');
        if ($err === 'rate_limited') {
            return ['ok' => false, 'error' => COLLEGE_PUBLIC_RATE_LIMITED];
        }

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }

    $send = false;
    $token = college_new_reset_token();
    $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
        ->modify('+' . COLLEGE_RESET_EXPIRY_HOURS . ' hours')
        ->format('Y-m-d H:i:s');
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $user = college_users_select_email_for_update($conn, $email);
    if ($user === false) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    if (is_array($user) && college_user_can_reset_college_password($user)) {
        $userId = (int) ($user['user_id'] ?? 0);
        $invalidate = mysqli_prepare(
            $conn,
            'UPDATE college_password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL'
        );
        if (!$invalidate) {
            mysqli_rollback($conn);

            return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
        }
        mysqli_stmt_bind_param($invalidate, 'i', $userId);
        if (!mysqli_stmt_execute($invalidate)) {
            mysqli_stmt_close($invalidate);
            mysqli_rollback($conn);

            return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
        }
        mysqli_stmt_close($invalidate);
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO college_password_reset_tokens (user_id, token_hash, expires_at, used_at, created_at) VALUES (?, ?, ?, NULL, ?)'
        );
        if (!$ins) {
            mysqli_rollback($conn);

            return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
        }
        mysqli_stmt_bind_param($ins, 'isss', $userId, $token['hash'], $expiresAt, $now);
        if (!mysqli_stmt_execute($ins)) {
            mysqli_stmt_close($ins);
            mysqli_rollback($conn);

            return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
        }
        mysqli_stmt_close($ins);
        $send = true;
    }
    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $delivered = false;
    if ($send) {
        $accountEmail = strtolower(trim((string) ($user['email'] ?? $email)));
        $delivered = college_send_password_reset_email($accountEmail, college_public_reset_url($token['raw']));
    }

    return ['ok' => true, 'generic' => true, 'sent' => $send, 'delivered' => $delivered];
}

/**
 * @return array{ok:bool, user_id?:int, reset_id?:int, error?:string}
 */
function college_public_peek_reset_token(mysqli $conn, string $rawToken): array
{
    if (!college_reset_token_format_valid($rawToken) || !college_password_reset_schema_ensure($conn)) {
        return ['ok' => false, 'error' => COLLEGE_RESET_INVALID];
    }
    $hash = college_reset_token_hash($rawToken);
    $stmt = mysqli_prepare(
        $conn,
        'SELECT reset_id, user_id, expires_at, used_at FROM college_password_reset_tokens WHERE token_hash=? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    mysqli_stmt_bind_param($stmt, 's', $hash);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!college_reset_token_row_usable($row)) {
        return ['ok' => false, 'error' => COLLEGE_RESET_INVALID];
    }

    return [
        'ok' => true,
        'user_id' => (int) ($row['user_id'] ?? 0),
        'reset_id' => (int) ($row['reset_id'] ?? 0),
    ];
}

/**
 * @return array{ok:bool, error?:string}
 */
function college_public_complete_password_reset(mysqli $conn, string $rawToken, string $password, string $confirm): array
{
    if (!college_password_meets_policy($password)) {
        return ['ok' => false, 'error' => college_password_policy_error()];
    }
    if ($confirm === '' || $confirm !== $password) {
        return ['ok' => false, 'error' => 'Passwords do not match.'];
    }
    if (!college_reset_token_format_valid($rawToken) || !college_password_reset_schema_ensure($conn)) {
        return ['ok' => false, 'error' => COLLEGE_RESET_INVALID];
    }
    $hash = college_reset_token_hash($rawToken);
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT reset_id, user_id, expires_at, used_at FROM college_password_reset_tokens WHERE token_hash=? LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    mysqli_stmt_bind_param($stmt, 's', $hash);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!college_reset_token_row_usable($row)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_RESET_INVALID];
    }
    $userId = (int) ($row['user_id'] ?? 0);
    $resetId = (int) ($row['reset_id'] ?? 0);
    $userStmt = mysqli_prepare(
        $conn,
        'SELECT user_id, role, status, email_verified, college_examination_access FROM users WHERE user_id=? LIMIT 1 FOR UPDATE'
    );
    if (!$userStmt) {
        $userStmt = mysqli_prepare($conn, 'SELECT user_id, role, status, email_verified FROM users WHERE user_id=? LIMIT 1 FOR UPDATE');
    }
    if (!$userStmt) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    mysqli_stmt_bind_param($userStmt, 'i', $userId);
    if (!mysqli_stmt_execute($userStmt)) {
        mysqli_stmt_close($userStmt);
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($userStmt));
    mysqli_stmt_close($userStmt);
    if (!is_array($user) || !college_user_can_reset_college_password($user)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_RESET_INVALID];
    }
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if (!college_auth_credentials_put($conn, $userId, $passwordHash)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    $use = mysqli_prepare(
        $conn,
        'UPDATE college_password_reset_tokens SET used_at=NOW() WHERE reset_id=? AND used_at IS NULL LIMIT 1'
    );
    if (!$use) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    mysqli_stmt_bind_param($use, 'i', $resetId);
    if (!mysqli_stmt_execute($use) || mysqli_stmt_affected_rows($use) !== 1) {
        mysqli_stmt_close($use);
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    mysqli_stmt_close($use);
    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => COLLEGE_PUBLIC_UNAVAILABLE];
    }
    college_password_reset_clear_remember_me($userId);

    return ['ok' => true];
}

function college_password_reset_clear_remember_me(int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    if (!function_exists('clearRememberMeForUserIdAndPortal')) {
        $rm = dirname(__DIR__, 2) . '/remember_me.php';
        if (is_file($rm)) {
            require_once $rm;
        }
    }
    if (function_exists('clearRememberMeForUserIdAndPortal')) {
        clearRememberMeForUserIdAndPortal($userId, 'college');
    }
}
