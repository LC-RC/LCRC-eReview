<?php
/**
 * Dedicated college regular exam client heartbeat endpoint (Phase 1 detect/alert only).
 * Independent of college_exam_ajax.php save/submit/timeout paths.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
ereview_require_college_examination_portal();
require_once dirname(__DIR__) . '/includes/college_exam_client_heartbeat.php';
require_once dirname(__DIR__) . '/includes/examination_ajax_support.php';

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    examination_ajax_json_exit(['ok' => false, 'error' => 'Method not allowed', 'restricted' => false, 'proof' => false], 405);
}

if (!college_exam_client_heartbeat_origin_ok()) {
    examination_ajax_json_exit(['ok' => false, 'error' => 'Invalid origin', 'restricted' => false, 'proof' => false], 403);
}

if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    examination_ajax_json_exit(['ok' => false, 'error' => 'Invalid request', 'restricted' => false, 'proof' => false], 403);
}

$userId = (int)getCurrentUserId();
if (function_exists('ereview_release_session_lock')) {
    ereview_release_session_lock();
}

$conn = $GLOBALS['conn'];
$attemptId = function_exists('sanitizeInt') ? sanitizeInt($_POST['attempt_id'] ?? 0) : (int)($_POST['attempt_id'] ?? 0);
$result = college_exam_client_heartbeat_process(
    $conn,
    $userId,
    $attemptId,
    (string)($_POST['instance_id'] ?? ''),
    (string)($_POST['browser_scope_id'] ?? ''),
    session_id() !== '' ? (string)session_id() : null,
    (string)($_POST['client_label'] ?? 'unknown')
);

$http = 200;
if (empty($result['ok'])) {
    $err = (string)($result['error'] ?? '');
    $http = (int)($result['http'] ?? 0);
    if ($http <= 0) {
        $http = $err === 'Forbidden' ? 403 : ($err === 'Attempt not found' ? 404 : 400);
    }
}
unset($result['http']);
examination_ajax_json_exit($result, $http);
