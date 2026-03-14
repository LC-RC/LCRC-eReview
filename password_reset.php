<?php
/**
 * Password reset – token creation, validation, and email.
 * Requires $conn (mysqli).
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

const PASSWORD_RESET_EXPIRY_HOURS = 1;

/** URL-safe base64 encode (no +, / or = so links are not corrupted by AVG/email/redirects). */
function passwordResetBase64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** URL-safe base64 decode. */
function passwordResetBase64UrlDecode($str) {
    $str = strtr($str, '-_', '+/');
    $str .= str_repeat('=', (4 - strlen($str) % 4) % 4);
    return base64_decode($str, true);
}

/**
 * Get base URL for reset links (no trailing slash).
 */
function getPasswordResetBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = dirname($script);
    if (strpos($base, '\\') !== false) {
        $base = str_replace('\\', '/', $base);
    }
    $base = rtrim($base, '/');
    return $scheme . '://' . $host . $base;
}

/**
 * Create a reset token for the given email. Returns reset link on success, null if user not found or error.
 * @param string $email
 * @return string|null Reset URL or null
 */
function createPasswordResetToken($email) {
    global $conn;
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = @mysqli_prepare($conn, "SELECT user_id FROM users WHERE email = ? LIMIT 1");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!$user) {
        return null; // Don't reveal whether email exists
    }

    $userId = (int) $user['user_id'];
    $selector = bin2hex(random_bytes(8));
    $validator = random_bytes(32);
    $tokenHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + PASSWORD_RESET_EXPIRY_HOURS * 3600);

    // Invalidate any existing tokens for this user
    $del = @mysqli_prepare($conn, "DELETE FROM password_reset_tokens WHERE user_id = ?");
    if ($del) {
        mysqli_stmt_bind_param($del, 'i', $userId);
        @mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
    }

    $stmt = @mysqli_prepare($conn, "INSERT INTO password_reset_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 'isss', $userId, $selector, $tokenHash, $expiresAt);
    if (!@mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    mysqli_stmt_close($stmt);

    // Use hex for the validator in the URL (no I/l/1, O/0) so email/AVG don't corrupt the link
    $validatorHex = bin2hex($validator);
    $tokenParam = $selector . '.' . $validatorHex;
    return getPasswordResetBaseUrl() . '/reset_password.php?token=' . $tokenParam;
}

/**
 * Validate token and return user_id if valid.
 * For 64-char hex tokens, allows a single corrupted character (email/link mangling).
 * @param string $rawToken Token from URL (selector.validator)
 * @return array|null ['user_id' => int] or null
 */
function validatePasswordResetToken($rawToken) {
    global $conn;
    $rawToken = trim($rawToken);
    $rawToken = rawurldecode($rawToken);
    if (strpos($rawToken, '.') === false) return null;

    $parts = explode('.', $rawToken, 2);
    $selector = trim($parts[0]);
    $validatorPart = trim($parts[1] ?? '');
    if ($validatorPart === '') return null;

    // Use PHP time for expiry so it matches the insert (avoids timezone mismatch with MySQL NOW())
    $nowStr = date('Y-m-d H:i:s', time());
    $hexChars = '0123456789abcdef';

    // New tokens: 64 hex chars — try direct match, then single-char correction
    if (strlen($validatorPart) === 64 && ctype_xdigit($validatorPart)) {
        $validatorPart = strtolower($validatorPart);
        $stmt = @mysqli_prepare($conn, "SELECT id, user_id, token_hash FROM password_reset_tokens WHERE selector = ? AND expires_at > ? LIMIT 1");
        if (!$stmt) return null;
        mysqli_stmt_bind_param($stmt, 'ss', $selector, $nowStr);
        if (!@mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return null;
        }
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        if (!$row) return null;

        $storedHash = $row['token_hash'];
        $validator = @hex2bin($validatorPart);
        if ($validator !== false && strlen($validator) === 32 && hash_equals(hash('sha256', $validator), $storedHash)) {
            return ['user_id' => (int) $row['user_id'], 'token_id' => (int) $row['id']];
        }
        // One character was likely corrupted (e.g. f→d); try correcting each position
        for ($i = 0; $i < 64; $i++) {
            $current = $validatorPart[$i];
            for ($k = 0; $k < 16; $k++) {
                $c = $hexChars[$k];
                if ($c === $current) continue;
                $candidate = $validatorPart;
                $candidate[$i] = $c;
                $v = @hex2bin($candidate);
                if ($v !== false && strlen($v) === 32 && hash_equals(hash('sha256', $v), $storedHash)) {
                    return ['user_id' => (int) $row['user_id'], 'token_id' => (int) $row['id']];
                }
            }
        }
        return null;
    }

    // Legacy tokens: base64url or standard base64
    $validatorPartNorm = str_replace(' ', '+', $validatorPart);
    $validator = @passwordResetBase64UrlDecode($validatorPartNorm);
    if ($validator === false || strlen($validator) !== 32) {
        $validator = @base64_decode($validatorPartNorm, true);
    }
    if ($validator === false || $validator === null || strlen($validator) !== 32) return null;

    $tokenHash = hash('sha256', $validator);
    $stmt = @mysqli_prepare($conn, "SELECT id, user_id, token_hash, expires_at FROM password_reset_tokens WHERE selector = ? AND expires_at > ? LIMIT 1");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 'ss', $selector, $nowStr);
    if (!@mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!$row || !hash_equals($tokenHash, $row['token_hash'])) {
        return null;
    }

    return ['user_id' => (int) $row['user_id'], 'token_id' => (int) $row['id']];
}

/**
 * Delete a reset token by ID (after successful password change).
 */
function deletePasswordResetToken($tokenId) {
    global $conn;
    $stmt = @mysqli_prepare($conn, "DELETE FROM password_reset_tokens WHERE id = ?");
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'i', $tokenId);
    @mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Send password reset email. Returns true if sent, false on failure.
 * Uses SMTP (Gmail etc.) when config/mail_config.php is set; otherwise falls back to PHP mail().
 */
function sendPasswordResetEmail($toEmail, $resetUrl) {
    $subject = 'Reset your password – LCRC eReview';
    $body = "Hello,\r\n\r\n";
    $body .= "You requested a password reset for your LCRC eReview account.\r\n\r\n";
    $body .= "Click the link below to set a new password (valid for " . PASSWORD_RESET_EXPIRY_HOURS . " hour(s)):\r\n\r\n";
    $body .= $resetUrl . "\r\n\r\n";
    $body .= "You can click the link directly. If your email shows a security notice (e.g. AVG), the link still works.\r\n\r\n";
    $body .= "If you didn't request this, you can ignore this email. Your password will not be changed.\r\n\r\n";
    $body .= "— LCRC eReview\r\n";

    $configFile = __DIR__ . '/config/mail_config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
        if (is_array($config) && !empty($config['smtp_password']) && !empty($config['smtp_username'])) {
            require_once __DIR__ . '/smtp_sender.php';
            $fromEmail = $config['from_email'] ?? $config['smtp_username'];
            $fromName = $config['from_name'] ?? 'LCRC eReview';
            if (sendMailSmtp($toEmail, $subject, $body, $fromEmail, $fromName, $config)) {
                return true;
            }
        }
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/plain; charset=UTF-8',
        'From: LCRC eReview <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
        'Reply-To: noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Mailer: PHP/' . PHP_VERSION
    ];
    return @mail($toEmail, $subject, $body, implode("\r\n", $headers));
}
