<?php
/**
 * Session-bound College registration verification status.
 * Does not accept email/token from the request.
 */
require_once dirname(__DIR__) . '/session_config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/includes/college_email_verification.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'verified' => false]);
    exit;
}

$now = time();
$last = (int) ($_SESSION['college_status_poll_at'] ?? 0);
if ($last > 0 && ($now - $last) < 2 && isset($_SESSION['college_status_poll_last']) && is_array($_SESSION['college_status_poll_last'])) {
    $cached = $_SESSION['college_status_poll_last'];
    echo json_encode([
        'ok' => !empty($cached['ok']),
        'verified' => !empty($cached['verified']),
    ]);
    exit;
}

$status = college_public_confirm_verified_status($conn);
$payload = [
    'ok' => !empty($status['ok']),
    'verified' => !empty($status['verified']),
];
$_SESSION['college_status_poll_at'] = $now;
$_SESSION['college_status_poll_last'] = $payload;

echo json_encode($payload);
