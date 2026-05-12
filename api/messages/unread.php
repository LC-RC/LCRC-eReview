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
    ereview_msg_json(['ok' => true, 'unread_total' => 0]);
}

$userId = (int)getCurrentUserId();
$role = (string)getCurrentUserRole();
if (!ereview_msg_is_admin_role($role) && !ereview_msg_is_reviewee_role($role)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$unreadTotal = ereview_msg_unread_total($conn, $role, $userId);

ereview_msg_json([
    'ok' => true,
    'unread_total' => $unreadTotal,
]);
