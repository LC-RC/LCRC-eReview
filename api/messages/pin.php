<?php
declare(strict_types=1);

if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/messaging_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ereview_msg_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}
if (!isLoggedIn() || !verifySession()) {
    ereview_msg_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}
$userId = (int)getCurrentUserId();
$role = (string)getCurrentUserRole();
if (!ereview_msg_is_admin_role($role)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}
if (!ereview_msg_pins_table_ready($conn)) {
    ereview_msg_json(['ok' => false, 'error' => 'Pin migration not installed (023).'], 503);
}
$studentId = (int)($_POST['thread_student_id'] ?? 0);
$pin = (int)($_POST['pin'] ?? 0) === 1;
if (!ereview_msg_can_access_thread($conn, $role, $userId, $studentId)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}
if ($pin) {
    $stmt = mysqli_prepare($conn, "INSERT INTO admin_reviewee_pinned_threads (admin_user_id, thread_student_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE created_at=CURRENT_TIMESTAMP");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $userId, $studentId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
} else {
    $stmt = mysqli_prepare($conn, "DELETE FROM admin_reviewee_pinned_threads WHERE admin_user_id=? AND thread_student_id=?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $userId, $studentId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
ereview_msg_json(['ok' => true]);
