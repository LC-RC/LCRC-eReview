<?php
declare(strict_types=1);

/**
 * Lecturer analytics PDF (regular college exams). Read-only; does not change progress PDF.
 */
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/examination_monitor_helpers.php';
require_once dirname(__DIR__) . '/includes/examination_monitor_analytics_export.php';

$uid = (int)getCurrentUserId();
$scope = examination_monitor_parse_scope($_GET);
$pack = examination_monitor_analytics_export_context($conn, $uid, $scope);
if (empty($pack['ok'])) {
    $_SESSION['message'] = 'Assessment not found.';
    header('Location: ' . (string)($pack['redirect'] ?? 'professor_examination_monitor'));
    exit;
}

$ctx = $pack['ctx'];
examination_monitor_output_analytics_pdf(
    (string)$ctx['title'],
    examination_monitor_analytics_scope_label((string)$pack['section_filter']),
    is_array($pack['analytics'] ?? null) ? $pack['analytics'] : [],
    is_array($pack['progress_rows'] ?? null) ? $pack['progress_rows'] : []
);
exit;
