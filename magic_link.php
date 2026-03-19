<?php
/**
 * Magic link sign-in – token creation, validation, and email.
 * Requires $conn (mysqli).
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

const MAGIC_LINK_EXPIRY_MINUTES = 15;

function getMagicLinkBaseUrl() {
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
 * Create a magic link token for the given email. Returns sign-in URL or null.
 */
function createMagicLinkToken($email) {
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
        return null;
    }

    $userId = (int) $user['user_id'];
    $selector = bin2hex(random_bytes(8));
    $validator = random_bytes(32);
    $tokenHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + MAGIC_LINK_EXPIRY_MINUTES * 60);

    $del = @mysqli_prepare($conn, "DELETE FROM magic_link_tokens WHERE user_id = ?");
    if ($del) {
        mysqli_stmt_bind_param($del, 'i', $userId);
        @mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
    }

    $stmt = @mysqli_prepare($conn, "INSERT INTO magic_link_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 'isss', $userId, $selector, $tokenHash, $expiresAt);
    if (!@mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    mysqli_stmt_close($stmt);

    $validatorHex = bin2hex($validator);
    $tokenParam = $selector . '.' . $validatorHex;
    return getMagicLinkBaseUrl() . '/login.php?magic=' . rawurlencode($tokenParam);
}

/**
 * Validate magic token. Returns ['user_id' => int, 'token_id' => int] or null.
 */
function validateMagicLinkToken($rawToken) {
    global $conn;
    $rawToken = trim($rawToken);
    $rawToken = rawurldecode($rawToken);
    if (strpos($rawToken, '.') === false) return null;

    $parts = explode('.', $rawToken, 2);
    $selector = trim($parts[0]);
    $validatorPart = trim($parts[1] ?? '');
    if ($validatorPart === '' || strlen($validatorPart) !== 64 || !ctype_xdigit($validatorPart)) return null;

    $nowStr = date('Y-m-d H:i:s', time());
    $stmt = @mysqli_prepare($conn, "SELECT id, user_id, token_hash FROM magic_link_tokens WHERE selector = ? AND expires_at > ? LIMIT 1");
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

    $validator = @hex2bin(strtolower($validatorPart));
    if ($validator === false || strlen($validator) !== 32 || !hash_equals(hash('sha256', $validator), $row['token_hash'])) {
        return null;
    }
    return ['user_id' => (int) $row['user_id'], 'token_id' => (int) $row['id']];
}

function deleteMagicLinkToken($tokenId) {
    global $conn;
    $stmt = @mysqli_prepare($conn, "DELETE FROM magic_link_tokens WHERE id = ?");
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'i', $tokenId);
    @mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function sendMagicLinkEmail($toEmail, $magicUrl) {
    $subject = 'Your sign-in link – LCRC eReview';
    $body = "Hello,\r\n\r\n";
    $body .= "Use the link below to sign in to your LCRC eReview account (valid for " . MAGIC_LINK_EXPIRY_MINUTES . " minutes):\r\n\r\n";
    $body .= $magicUrl . "\r\n\r\n";
    $body .= "If you didn't request this, you can ignore this email.\r\n\r\n";
    $body .= "— LCRC eReview\r\n";

    $configFile = __DIR__ . '/config/mail_config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
        require_once __DIR__ . '/smtp_sender.php';
        if (is_array($config) && function_exists('isMailConfigValid') && isMailConfigValid($config)) {
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
        'X-Mailer: PHP/' . PHP_VERSION
    ];
    return @mail($toEmail, $subject, $body, implode("\r\n", $headers));
}
