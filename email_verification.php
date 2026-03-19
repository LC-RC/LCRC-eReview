<?php
/**
 * Email verification for registration: pending_registrations table, tokens, and email.
 * Requires $conn (mysqli). Table: pending_registrations (see add_email_verification.sql).
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

const EMAIL_VERIFICATION_EXPIRY_HOURS = 24;

function getVerificationBaseUrl() {
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
 * Create a pending registration and verification token. Returns verification URL or null.
 */
function createPendingRegistration($data) {
    global $conn;
    $email = trim($data['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $fullName = trim($data['full_name'] ?? '');
    $reviewType = in_array($data['review_type'] ?? '', ['reviewee', 'undergrad']) ? $data['review_type'] : 'reviewee';
    $school = trim($data['school'] ?? '');
    $schoolOther = isset($data['school_other']) ? trim($data['school_other']) : null;
    $paymentProof = trim($data['payment_proof'] ?? '');
    $passwordHash = $data['password_hash'] ?? '';
    if ($fullName === '' || $school === '' || $passwordHash === '') {
        return null;
    }
    if ($reviewType !== 'reviewee') {
        $schoolOther = null;
    }

    $check = mysqli_prepare($conn, "SELECT 1 FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($check, 's', $email);
    mysqli_stmt_execute($check);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
        mysqli_stmt_close($check);
        return null;
    }
    mysqli_stmt_close($check);

    $selector = bin2hex(random_bytes(16));
    $validator = random_bytes(32);
    $tokenHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + EMAIL_VERIFICATION_EXPIRY_HOURS * 3600);

    $stmt = mysqli_prepare($conn,
        "INSERT INTO pending_registrations (email, full_name, review_type, school, school_other, payment_proof, password_hash, selector, token_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 'ssssssssss', $email, $fullName, $reviewType, $school, $schoolOther, $paymentProof, $passwordHash, $selector, $tokenHash, $expiresAt);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    mysqli_stmt_close($stmt);

    $validatorHex = bin2hex($validator);
    $tokenParam = $selector . '.' . $validatorHex;
    return getVerificationBaseUrl() . '/verify_email.php?token=' . urlencode($tokenParam);
}

/**
 * Validate verification token. Returns pending row (assoc) or null.
 */
function validateVerificationToken($rawToken) {
    global $conn;
    $rawToken = trim($rawToken);
    $rawToken = rawurldecode($rawToken);
    if (strpos($rawToken, '.') === false) return null;
    $parts = explode('.', $rawToken, 2);
    $selector = trim($parts[0]);
    $validatorHex = trim($parts[1] ?? '');
    if (strlen($validatorHex) !== 64 || !ctype_xdigit($validatorHex)) return null;

    $nowStr = date('Y-m-d H:i:s', time());
    $stmt = mysqli_prepare($conn, "SELECT id, email, full_name, review_type, school, school_other, payment_proof, password_hash, token_hash FROM pending_registrations WHERE selector = ? AND expires_at > ? LIMIT 1");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 'ss', $selector, $nowStr);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$row) return null;

    $validator = hex2bin($validatorHex);
    if ($validator === false || strlen($validator) !== 32) return null;
    if (!hash_equals(hash('sha256', $validator), $row['token_hash'])) return null;
    return $row;
}

/**
 * Create user from pending row and delete pending. Returns user_id or null.
 */
function completeVerificationAndCreateUser($pendingRow) {
    global $conn;
    $email = $pendingRow['email'];
    $fullName = $pendingRow['full_name'];
    $reviewType = $pendingRow['review_type'];
    $school = $pendingRow['school'];
    $schoolOther = $pendingRow['school_other'];
    $paymentProof = $pendingRow['payment_proof'];
    $passwordHash = $pendingRow['password_hash'];
    $pendingId = (int) $pendingRow['id'];

    $hasEmailVerified = false;
    $cols = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_verified'");
    if ($cols && mysqli_fetch_assoc($cols)) $hasEmailVerified = true;

    $sql = $hasEmailVerified
        ? "INSERT INTO users (full_name, review_type, school, school_other, payment_proof, email, password, role, status, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, 'student', 'pending', 1)"
        : "INSERT INTO users (full_name, review_type, school, school_other, payment_proof, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'student', 'pending')";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 'sssssss', $fullName, $reviewType, $school, $schoolOther, $paymentProof, $email, $passwordHash);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $userId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $del = mysqli_prepare($conn, "DELETE FROM pending_registrations WHERE id = ?");
    if ($del) {
        mysqli_stmt_bind_param($del, 'i', $pendingId);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
    }
    return $userId;
}

/**
 * Send verification email with branded HTML (Verify Account button + secure link).
 */
function sendVerificationEmail($toEmail, $verificationUrl) {
    $subject = 'Verify your email – LCRC eReview';
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body style="margin:0;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f1f5f9;padding:24px;">';
    $html .= '<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">';
    $html .= '<div style="background:linear-gradient(135deg,#1F58C3 0%,#1E40AF 100%);padding:24px;text-align:center;">';
    $html .= '<span style="color:#fff;font-size:18px;font-weight:700;">LCRC</span> <span style="color:#F59E0B;font-size:18px;font-weight:700;">eReview</span>';
    $html .= '</div>';
    $html .= '<div style="padding:28px 24px;">';
    $html .= '<h1 style="margin:0 0 12px;font-size:20px;color:#0f172a;">Verify your account</h1>';
    $html .= '<p style="margin:0 0 20px;color:#475569;line-height:1.6;">Thank you for registering. Please confirm your email address to create your LCRC eReview account.</p>';
    $html .= '<p style="margin:0 0 24px;color:#475569;line-height:1.6;">Click the button below to verify and activate your account. This link expires in ' . EMAIL_VERIFICATION_EXPIRY_HOURS . ' hours.</p>';
    $html .= '<p style="text-align:center;margin:0 0 8px;">';
    $html .= '<a href="' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#1F58C3;color:#fff!important;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:600;font-size:15px;">Verify Account</a>';
    $html .= '</p>';
    $html .= '<p style="margin:0;font-size:12px;color:#94a3b8;">If the button does not work, copy and paste this link into your browser:</p>';
    $html .= '<p style="margin:4px 0 0;word-break:break-all;font-size:12px;color:#64748b;">' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '</p>';
    $html .= '</div>';
    $html .= '<div style="padding:12px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;font-size:11px;color:#64748b;">© ' . date('Y') . ' LCRC eReview. All rights reserved.</div>';
    $html .= '</div></body></html>';

    $configFile = __DIR__ . '/config/mail_config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
        require_once __DIR__ . '/smtp_sender.php';
        if (is_array($config) && function_exists('isMailConfigValid') && isMailConfigValid($config)) {
            $fromEmail = $config['from_email'] ?? $config['smtp_username'];
            $fromName = $config['from_name'] ?? 'LCRC eReview';
            if (function_exists('sendMailSmtpHtml')) {
                return sendMailSmtpHtml($toEmail, $subject, $html, $fromEmail, $fromName, $config);
            }
            $plain = strip_tags(str_replace(['<br>', '<br/>', '</p>'], ["\n", "\n", "\n"], $html));
            if (sendMailSmtp($toEmail, $subject, $plain, $fromEmail, $fromName, $config)) {
                return true;
            }
        }
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: LCRC eReview <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
    ];
    return @mail($toEmail, $subject, $html, implode("\r\n", $headers));
}
