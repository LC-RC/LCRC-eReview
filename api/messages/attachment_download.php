<?php
declare(strict_types=1);

if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/messaging_helpers.php';

if (!isLoggedIn() || !verifySession()) {
    http_response_code(401);
    exit('Unauthorized');
}
if (!ereview_msg_tables_ready($conn) || !ereview_msg_attachments_table_ready($conn)) {
    http_response_code(404);
    exit('Not found');
}
$userId = (int)getCurrentUserId();
$role = (string)getCurrentUserRole();
if (!ereview_msg_is_admin_role($role) && !ereview_msg_is_reviewee_role($role)) {
    http_response_code(403);
    exit('Forbidden');
}
$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('Not found');
}
$stmt = mysqli_prepare($conn, "SELECT thread_student_id, stored_name, orig_name, mime_type FROM admin_reviewee_message_attachments WHERE storage_token=? LIMIT 1");
if (!$stmt) {
    http_response_code(404);
    exit('Not found');
}
mysqli_stmt_bind_param($stmt, 's', $token);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);
if (!$row) {
    http_response_code(404);
    exit('Not found');
}
$threadStudentId = (int)($row['thread_student_id'] ?? 0);
if (!ereview_msg_can_access_thread($conn, $role, $userId, $threadStudentId)) {
    http_response_code(403);
    exit('Forbidden');
}
$storageDir = ereview_msg_attachment_storage_dir();
if ($storageDir === '') {
    http_response_code(404);
    exit('Not found');
}
$path = $storageDir . '/' . (string)($row['stored_name'] ?? '');
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}
$mime = (string)($row['mime_type'] ?? 'application/octet-stream');
$name = (string)($row['orig_name'] ?? 'attachment');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . addslashes($name) . '"');
readfile($path);
exit;
