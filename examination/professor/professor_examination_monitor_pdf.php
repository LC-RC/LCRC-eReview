<?php
declare(strict_types=1);

/**
 * PDF download: per-examinee progress (name, email, status, score).
 */
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/examination_monitor_helpers.php';
require_once dirname(__DIR__) . '/includes/exam_progress_report_pdf.php';

$uid = (int)getCurrentUserId();
$scope = examination_monitor_parse_scope($_GET);
if ($scope === null) {
    $_SESSION['message'] = 'Invalid assessment selected.';
    header('Location: professor_examination_monitor');
    exit;
}

$ctx = examination_monitor_load_context($conn, $uid, $scope['exam_type'], $scope['assessment_id']);
if (!$ctx) {
    $_SESSION['message'] = 'Assessment not found.';
    header('Location: professor_examination_monitor');
    exit;
}

$now = date('Y-m-d H:i:s');
examination_monitor_finalize_expired($conn, $scope);
$sectionFilter = ($scope['exam_type'] === 'college_exam')
    ? examination_monitor_resolve_college_section_filter($conn, (int)$scope['assessment_id'], $_GET['section'] ?? '')['filter']
    : '';
$metrics = examination_monitor_metrics($conn, $scope);
$window = examination_monitor_running_finished($conn, $ctx, $metrics, $now);
$isFinished = $window['is_finished'];

$rows = examination_monitor_export_rows($conn, $ctx, $isFinished, $sectionFilter);
ereview_output_exam_progress_pdf((string)$ctx['title'], $rows);
exit;
