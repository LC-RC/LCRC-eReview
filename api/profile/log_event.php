<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
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

$uid = getCurrentUserId();
if (!$uid) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (ereview_profile_rate_limit_exceeded('log_event', 150, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many requests.']);
    exit;
}

$token = trim((string)($_POST['csrf_token'] ?? ''));
if (!verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token.']);
    exit;
}

$event = strtolower(trim((string)($_POST['event'] ?? '')));
if ($event === '' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $event)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid event name.']);
    exit;
}

$meta = trim((string)($_POST['meta'] ?? ''));
if (strlen($meta) > 2000) {
    $meta = substr($meta, 0, 2000);
}

$base = dirname(__DIR__, 2);
$logDir = $base . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'profile_events.log';
$ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$line = gmdate('Y-m-d\TH:i:s\Z')
    . "\tuid=" . $uid
    . "\tip=" . $ip
    . "\tevent=" . $event
    . ($meta !== '' ? "\tmeta=" . str_replace(["\r", "\n", "\t"], ' ', $meta) : '')
    . "\n";

@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

echo json_encode(['ok' => true]);
