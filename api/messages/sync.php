<?php
declare(strict_types=1);

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/messaging_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ereview_msg_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}
if (!isLoggedIn() || !verifySession()) {
    ereview_msg_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}
if (!ereview_msg_tables_ready($conn)) {
    ereview_msg_json(['ok' => false, 'error' => 'Messaging migration is not installed. Run migration 021.'], 503);
}

$userId = (int)getCurrentUserId();
$role = (string)getCurrentUserRole();
if (!ereview_msg_is_admin_role($role) && !ereview_msg_is_reviewee_role($role)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$threadStudentId = (int)($_GET['thread_student_id'] ?? 0);
$afterMessageId = (int)($_GET['after_message_id'] ?? 0);

if (!ereview_msg_can_access_thread($conn, $role, $userId, $threadStudentId)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

ereview_msg_mark_read($conn, $role, $userId, $threadStudentId);

$messages = ereview_msg_fetch_new_messages_since($conn, $threadStudentId, $afterMessageId, 120);
$typing = ereview_msg_typing_peer($conn, $threadStudentId, $userId);
$unreadTotal = ereview_msg_unread_total($conn, $role, $userId);

ereview_msg_json([
    'ok' => true,
    'messages' => $messages,
    'typing' => $typing,
    'unread_total' => $unreadTotal,
]);
