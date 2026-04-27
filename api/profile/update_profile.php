<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/profile_account_helpers.php';
require_once __DIR__ . '/../../includes/profile_rate_limit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn() || !verifySession()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$token = trim((string)($_POST['csrf_token'] ?? ''));
if (!verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token. Refresh the page and try again.']);
    exit;
}

$uid = getCurrentUserId();
if (!$uid) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (ereview_profile_rate_limit_exceeded('update_profile', 25, 300)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many save attempts. Please wait a few minutes and try again.']);
    exit;
}

$input = [
    'full_name' => $_POST['full_name'] ?? '',
    'email' => $_POST['email'] ?? '',
    'phone' => $_POST['phone'] ?? '',
    'profile_bio' => $_POST['profile_bio'] ?? '',
    'password' => $_POST['password'] ?? '',
    'password_confirm' => $_POST['password_confirm'] ?? '',
    'remove_avatar' => !empty($_POST['remove_avatar']) && $_POST['remove_avatar'] !== '0',
];

$file = isset($_FILES['profile_image']) && is_array($_FILES['profile_image']) ? $_FILES['profile_image'] : null;

$result = ereview_profile_apply_update($conn, $uid, $input, $file);

if (!$result['ok']) {
    http_response_code(422);
    $errOut = ['ok' => false, 'error' => $result['error'] ?? 'Update failed'];
    if (!empty($result['field'])) {
        $errOut['field'] = $result['field'];
    }
    echo json_encode($errOut);
    exit;
}

require_once __DIR__ . '/../../includes/profile_avatar.php';
$payload = ereview_profile_json_payload($conn, $uid);
$payload['email_changed'] = !empty($result['email_changed']);
$payload['verification_email_sent'] = !empty($result['verification_email_sent']);
$payload['password_changed'] = !empty($result['password_changed']);
echo json_encode($payload);
