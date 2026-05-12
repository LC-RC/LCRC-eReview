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
    ereview_msg_json(['ok' => true, 'presence' => []]);
}

$role = (string)getCurrentUserRole();
if (!ereview_msg_is_admin_role($role) && !ereview_msg_is_reviewee_role($role)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$idsRaw = trim((string)($_GET['ids'] ?? ''));
if ($idsRaw === '') {
    ereview_msg_json(['ok' => true, 'presence' => []]);
}

$idParts = array_filter(array_map('trim', explode(',', $idsRaw)), static function ($v) {
    return $v !== '' && ctype_digit($v);
});
$requested = array_values(array_unique(array_map('intval', $idParts)));
$allowed = ereview_msg_filter_ids_for_presence_query($conn, $role, $requested);
$presence = ereview_msg_presence_map_for_ids($conn, $allowed);

ereview_msg_json([
    'ok' => true,
    'presence' => $presence,
]);
