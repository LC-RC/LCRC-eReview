<?php
/**
 * Login rate limiting – tracks failed attempts by IP and enforces lockout.
 * Requires $conn (mysqli) and db.php to be loaded.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

/** Max failed attempts before lockout */
const LOGIN_RATE_LIMIT_MAX_ATTEMPTS = 5;

/** Time window (seconds) – attempts older than this are ignored when counting */
const LOGIN_RATE_LIMIT_WINDOW_SECONDS = 900; // 15 minutes

/** Lockout duration (seconds) after exceeding max attempts */
const LOGIN_RATE_LIMIT_LOCKOUT_SECONDS = 900; // 15 minutes

/** Require CAPTCHA after this many failed attempts (within the window) */
const LOGIN_CAPTCHA_AFTER_ATTEMPTS = 2;

/**
 * Get current failed-attempt count for this IP (within the window). Used to show CAPTCHA.
 * @return int
 */
function getLoginAttemptCount() {
    global $conn;
    $ip = getLoginClientIp();
    $windowStart = date('Y-m-d H:i:s', time() - LOGIN_RATE_LIMIT_WINDOW_SECONDS);
    $stmt = @mysqli_prepare($conn, 'SELECT attempt_count FROM login_attempts WHERE ip_address = ? AND first_attempt_at >= ? LIMIT 1');
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, 'ss', $ip, $windowStart);
    if (!@mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return 0;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ? (int) $row['attempt_count'] : 0;
}

/**
 * Get client IP (supports X-Forwarded-For behind proxy).
 * @return string
 */
function getLoginClientIp() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $value = $_SERVER[$key];
            if (strpos($value, ',') !== false) {
                $value = trim(explode(',', $value)[0]);
            }
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * Check if the current client is rate limited.
 * @return array{0: bool, 1: int|null} [ is_rate_limited, locked_until_timestamp or null ]
 */
function isLoginRateLimited() {
    global $conn;
    $ip = getLoginClientIp();
    $ip = mysqli_real_escape_string($conn, $ip);

    $sql = "SELECT attempt_count, first_attempt_at, locked_until FROM login_attempts WHERE ip_address = ? LIMIT 1";
    $stmt = @mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [false, null];
    }
    mysqli_stmt_bind_param($stmt, 's', $ip);
    if (!@mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return [false, null];
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!$row) {
        return [false, null];
    }

    $now = time();
    $lockedUntil = $row['locked_until'] ? strtotime($row['locked_until']) : null;

    if ($lockedUntil !== null && $lockedUntil > $now) {
        return [true, (int) $lockedUntil];
    }

    return [false, null];
}

/**
 * Record a failed login attempt. Call only after a real failed credential check.
 * @return int|null Locked until Unix timestamp if lockout was applied, else null
 */
function recordFailedLoginAttempt() {
    global $conn;
    $ip = getLoginClientIp();
    $ipEsc = mysqli_real_escape_string($conn, $ip);
    $now = date('Y-m-d H:i:s');
    $windowStart = date('Y-m-d H:i:s', time() - LOGIN_RATE_LIMIT_WINDOW_SECONDS);
    $lockoutEnd = date('Y-m-d H:i:s', time() + LOGIN_RATE_LIMIT_LOCKOUT_SECONDS);

    $sql = "SELECT id, attempt_count, first_attempt_at FROM login_attempts WHERE ip_address = ? LIMIT 1";
    $stmt = @mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $ip);
    if (!@mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!$row) {
        $count = 1;
        $lockedUntil = $count >= LOGIN_RATE_LIMIT_MAX_ATTEMPTS ? $lockoutEnd : null;
        $ins = @mysqli_prepare($conn, "INSERT INTO login_attempts (ip_address, attempt_count, first_attempt_at, locked_until) VALUES (?, 1, ?, ?)");
        if ($ins) {
            mysqli_stmt_bind_param($ins, 'sss', $ip, $now, $lockedUntil);
            @mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
        }
        return $lockedUntil ? strtotime($lockedUntil) : null;
    }

    $firstAttempt = strtotime($row['first_attempt_at']);
    if ($firstAttempt < time() - LOGIN_RATE_LIMIT_WINDOW_SECONDS) {
        $count = 1;
        $lockedUntil = $count >= LOGIN_RATE_LIMIT_MAX_ATTEMPTS ? $lockoutEnd : null;
        $stmt = @mysqli_prepare($conn, "UPDATE login_attempts SET attempt_count = 1, first_attempt_at = ?, locked_until = ? WHERE ip_address = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sss', $now, $lockedUntil, $ip);
            @mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        return $lockedUntil ? strtotime($lockedUntil) : null;
    }

    $count = (int) $row['attempt_count'] + 1;
    $lockedUntil = $count >= LOGIN_RATE_LIMIT_MAX_ATTEMPTS ? $lockoutEnd : null;
    $stmt = @mysqli_prepare($conn, "UPDATE login_attempts SET attempt_count = ?, locked_until = ? WHERE ip_address = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'iss', $count, $lockedUntil, $ip);
        @mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    return $lockedUntil ? strtotime($lockedUntil) : null;
}

/**
 * Clear rate limit for the current client (e.g. after successful login).
 */
function clearLoginAttempts() {
    global $conn;
    $ip = getLoginClientIp();
    $stmt = @mysqli_prepare($conn, "DELETE FROM login_attempts WHERE ip_address = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $ip);
        @mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
