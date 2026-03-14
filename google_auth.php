<?php
/**
 * Redirect to Google OAuth 2.0 consent screen. On return, user hits google_callback.php.
 */
require_once __DIR__ . '/session_config.php';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME'] ?? '');
$base = str_replace('\\', '/', $base);
$redirectUri = rtrim($base, '/') . '/google_callback.php';

$configFile = __DIR__ . '/config/google_oauth_config.php';
if (!file_exists($configFile)) {
    $_SESSION['error'] = 'Google Sign-In is not set up. Copy config/google_oauth_config.sample.php to config/google_oauth_config.php and add your Google OAuth Client ID and Secret. In Google Cloud Console, add this Authorized redirect URI: ' . $redirectUri;
    $_SESSION['error_type'] = 'google_not_configured';
    $_SESSION['google_redirect_uri'] = $redirectUri;
    header('Location: login.php');
    exit;
}

$config = require $configFile;
$clientId = is_array($config) ? trim($config['client_id'] ?? '') : '';
$isPlaceholder = ($clientId === '' || stripos($clientId, 'YOUR_GOOGLE') !== false);
if ($isPlaceholder) {
    $_SESSION['error'] = 'Google Sign-In is not set up. Edit config/google_oauth_config.php and add your Client ID and Secret from Google Cloud Console. Add this Authorized redirect URI in your OAuth client: ' . $redirectUri;
    $_SESSION['error_type'] = 'google_not_configured';
    $_SESSION['google_redirect_uri'] = $redirectUri;
    header('Location: login.php');
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = [
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
];
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;
