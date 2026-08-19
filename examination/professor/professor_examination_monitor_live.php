<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/examination_monitor_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$uid = (int)getCurrentUserId();
$scope = examination_monitor_parse_scope($_GET);
if ($scope === null) {
    echo json_encode(['ok' => false, 'error' => 'Invalid scope']);
    exit;
}

$ctx = examination_monitor_load_context($conn, $uid, $scope['exam_type'], $scope['assessment_id']);
if (!$ctx) {
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

echo json_encode(examination_monitor_live_payload($conn, $ctx));
exit;
