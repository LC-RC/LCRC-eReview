<?php
/**
 * Google OAuth callback: exchange code for token, get user email, check DB, then log in or show error.
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (isLoggedIn() && verifySession()) {
    header('Location: ' . (getCurrentUserRole() === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php'));
    exit;
}

$configFile = __DIR__ . '/config/google_oauth_config.php';
if (!file_exists($configFile)) {
    $_SESSION['error'] = 'Google Sign-In is not configured.';
    $_SESSION['error_type'] = 'invalid_credentials';
    header('Location: login.php');
    exit;
}

$config = require $configFile;
$clientId = is_array($config) ? trim($config['client_id'] ?? '') : '';
$clientSecret = is_array($config) ? trim($config['client_secret'] ?? '') : '';
if ($clientId === '' || $clientSecret === '') {
    $_SESSION['error'] = 'Google Sign-In is not configured.';
    $_SESSION['error_type'] = 'invalid_credentials';
    header('Location: login.php');
    exit;
}

$code = trim($_GET['code'] ?? '');
$state = trim($_GET['state'] ?? '');
$storedState = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);

if ($code === '' || $state === '' || !hash_equals($storedState, $state)) {
    $_SESSION['error'] = 'Invalid or expired Google sign-in request. Please try again.';
    $_SESSION['error_type'] = 'invalid_credentials';
    header('Location: login.php');
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME'] ?? '');
$base = str_replace('\\', '/', $base);
$redirectUri = rtrim($base, '/') . '/google_callback.php';

$tokenUrl = 'https://oauth2.googleapis.com/token';
$tokenPost = http_build_query([
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]);

$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $tokenPost,
    ],
]);
$tokenResponse = @file_get_contents($tokenUrl, false, $ctx);
$tokenData = $tokenResponse ? json_decode($tokenResponse, true) : null;
$accessToken = is_array($tokenData) ? ($tokenData['access_token'] ?? '') : '';

if ($accessToken === '') {
    $_SESSION['error'] = 'Google sign-in failed. Please try again.';
    $_SESSION['error_type'] = 'invalid_credentials';
    header('Location: login.php');
    exit;
}

$userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
$ctxGet = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer " . $accessToken . "\r\n",
    ],
]);
$userInfoResponse = @file_get_contents($userInfoUrl, false, $ctxGet);
$userInfo = $userInfoResponse ? json_decode($userInfoResponse, true) : null;
$email = is_array($userInfo) ? trim($userInfo['email'] ?? '') : '';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = 'Could not get your email from Google. Please try again or use email and password.';
    $_SESSION['error_type'] = 'invalid_credentials';
    header('Location: login.php');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT user_id, full_name, role, status, access_end FROM users WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if (!$user) {
    $_SESSION['error'] = 'This Google account is not linked to any LCRC eReview account. Please register first using the same email, then you can sign in with Google.';
    $_SESSION['error_type'] = 'google_no_account';
    header('Location: login.php');
    exit;
}

$hasEmailVerifiedCol = false;
$cols = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_verified'");
if ($cols && mysqli_fetch_assoc($cols)) $hasEmailVerifiedCol = true;
if ($hasEmailVerifiedCol) {
    $stmt = mysqli_prepare($conn, "SELECT email_verified FROM users WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $user['user_id']);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    mysqli_stmt_close($stmt);
    if ($row && (int)($row['email_verified'] ?? 1) === 0) {
        $_SESSION['error'] = 'Your account has not been verified yet. Please confirm your email before signing in.';
        $_SESSION['error_type'] = 'google_not_verified';
        header('Location: login.php');
        exit;
    }
}

if ($user['role'] !== 'admin' && strtolower($user['status']) !== 'approved') {
    $_SESSION['error'] = 'Your account is pending approval. An admin must approve your account before you can sign in. Please try again later or contact support.';
    $_SESSION['error_type'] = 'not_approved';
    header('Location: login.php');
    exit;
}
if ($user['role'] !== 'admin' && !empty($user['access_end'])) {
    $now = new DateTime('now');
    $end = new DateTime($user['access_end']);
    if ($now > $end) {
        $_SESSION['error'] = 'Your access has expired.';
        $_SESSION['error_type'] = 'access_expired';
        header('Location: login.php');
        exit;
    }
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['user_id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['created'] = time();
$_SESSION['last_activity'] = time();

$uid = (int) $user['user_id'];
$now = date('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
$upd = @mysqli_prepare($conn, 'UPDATE users SET last_login_at = ?, last_login_ip = ?, last_login_user_agent = ? WHERE user_id = ?');
if ($upd) {
    mysqli_stmt_bind_param($upd, 'sssi', $now, $ip, $ua, $uid);
    @mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
}
setUserPresenceStatus($uid, true);

$target = ($user['role'] === 'admin') ? 'admin_dashboard.php' : 'student_dashboard.php';
$fullName = trim($user['full_name'] ?? '');
$firstName = $fullName !== '' ? explode(' ', $fullName)[0] : 'User';
header('Location: auth_success.php?target=' . rawurlencode($target) . '&name=' . rawurlencode($firstName));
exit;
