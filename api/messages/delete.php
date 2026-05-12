<?php
declare(strict_types=1);

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/messaging_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$messageId = (int)($_POST['message_id'] ?? 0);
if ($messageId <= 0) {
    ereview_msg_json(['ok' => false, 'error' => 'Invalid message.'], 422);
}
if (!ereview_msg_can_soft_delete($conn, $messageId, $userId, $role, 120)) {
    ereview_msg_json(['ok' => false, 'error' => 'Message can no longer be removed.'], 422);
}

$stmt = mysqli_prepare($conn, "UPDATE admin_reviewee_messages SET body='[message removed]' WHERE message_id=? LIMIT 1");
if (!$stmt) {
    ereview_msg_json(['ok' => false, 'error' => 'Could not remove message.'], 500);
}
mysqli_stmt_bind_param($stmt, 'i', $messageId);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
if (!$ok) {
    ereview_msg_json(['ok' => false, 'error' => 'Could not remove message.'], 500);
}
ereview_msg_json(['ok' => true]);
