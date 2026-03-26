<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/notification_helpers.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();

$userId = getCurrentUserId() ?? 0;
$role = (string)(getCurrentUserRole() ?? '');
$action = strtolower(trim((string)($_REQUEST['action'] ?? 'list')));

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

notifications_seed_defaults($conn, (int)$userId, $role);

if ($action === 'list') {
    $items = notifications_list_for_user($conn, (int)$userId, 30);
    $unread = notifications_unread_count($conn, (int)$userId);
    echo json_encode([
        'ok' => true,
        'items' => $items,
        'unread_count' => $unread,
        'total_count' => count($items),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if ($action === 'mark_read') {
    $notificationId = sanitizeInt($_POST['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid notification id']);
        exit;
    }
    $ok = notifications_mark_read($conn, (int)$userId, (int)$notificationId);
    $unread = notifications_unread_count($conn, (int)$userId);
    echo json_encode(['ok' => $ok, 'unread_count' => $unread]);
    exit;
}

if ($action === 'mark_all_read') {
    $ok = notifications_mark_all_read($conn, (int)$userId);
    echo json_encode(['ok' => $ok, 'unread_count' => 0]);
    exit;
}

if ($action === 'mark_toast_shown') {
    $notificationId = sanitizeInt($_POST['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid notification id']);
        exit;
    }
    $ok = notifications_mark_toast_shown($conn, (int)$userId, (int)$notificationId);
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($action === 'delete') {
    $notificationId = sanitizeInt($_POST['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid notification id']);
        exit;
    }
    $ok = notifications_delete($conn, (int)$userId, (int)$notificationId);
    $unread = notifications_unread_count($conn, (int)$userId);
    echo json_encode(['ok' => $ok, 'unread_count' => $unread]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
