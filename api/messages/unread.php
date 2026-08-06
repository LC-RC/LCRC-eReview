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
if (!isLoggedIn()) {
    ereview_msg_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$userId = (int) getCurrentUserId();
$role = (string) getCurrentUserRole();
if (!ereview_msg_is_admin_role($role) && !ereview_msg_is_reviewee_role($role)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

// Warm schema cache while session can still be written.
$tablesReady = ereview_msg_tables_ready($conn);

// Badge polls are frequent — release session lock before unread COUNT.
if (function_exists('ereview_release_session_lock')) {
    ereview_release_session_lock();
}

if (!$tablesReady) {
    ereview_msg_json(['ok' => true, 'unread_total' => 0]);
}

$unreadTotal = ereview_msg_unread_total($conn, $role, $userId);

ereview_msg_json([
    'ok' => true,
    'unread_total' => $unreadTotal,
]);
