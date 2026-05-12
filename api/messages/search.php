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
$q = trim((string)($_GET['q'] ?? ''));
if (!ereview_msg_can_access_thread($conn, $role, $userId, $threadStudentId)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}
if ($q === '') {
    ereview_msg_json(['ok' => true, 'messages' => []]);
}

$messages = ereview_msg_search_messages($conn, $threadStudentId, $q, 80);
ereview_msg_json(['ok' => true, 'messages' => $messages]);
