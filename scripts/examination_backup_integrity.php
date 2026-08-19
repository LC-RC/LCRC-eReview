<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$backupDir = $root . '/backups/examination_cutover';
$stubs = json_decode(file_get_contents('php://stdin') ?: '[]', true);

$candidates = [
    'includes/college_schema.php', 'includes/college_exam_helpers.php', 'includes/college_upload_helpers.php',
    'includes/exam_monitor_progress_rows.php', 'includes/exam_progress_report_pdf.php',
    'includes/exam_progress_report_xlsx.php', 'includes/college_take_exam_review_submitted_section.php',
    'professor_admin_dashboard.php', 'professor_admin_sidebar.php', 'professor_college_students.php',
    'professor_create_college_student.php', 'professor_college_student_view.php', 'professor_college_student_delete.php',
    'professor_exams.php', 'professor_exam_edit.php', 'professor_exam_monitor.php', 'professor_exam_monitor_live.php',
    'professor_exam_monitor_pdf.php', 'professor_exam_monitor_xlsx.php', 'professor_exam_review_sheet.php',
    'professor_exam_ai.php', 'professor_upload_tasks.php', 'professor_upload_task_monitor.php', 'professor_monitor.php',
    'college_student_dashboard.php', 'college_student_sidebar.php', 'college_exams.php', 'college_take_exam.php',
    'college_exam_ajax.php', 'college_uploads.php', 'college_upload_task.php', 'college_upload_file.php',
    'college_exams_debug.php',
];

$report = ['backup_exists' => 0, 'backup_not_stub' => 0, 'backup_min_size' => 0, 'fail' => []];
foreach ($candidates as $relRoot) {
    $bn = basename($relRoot);
    $backupF = $backupDir . '/' . str_replace(['/', '\\'], '_', $relRoot) . '.pre_stub';
    if (!is_file($backupF)) {
        $backupF = $backupDir . '/' . $bn . '.pre_stub';
    }
    if (!is_file($backupF) && str_starts_with($relRoot, 'includes/')) {
        $backupF = $backupDir . '/includes_' . $bn . '.pre_stub';
    }
    $rootF = $root . '/' . $relRoot;
    if (!is_file($backupF)) {
        $report['fail'][] = ['file' => $relRoot, 'reason' => 'backup missing'];
        continue;
    }
    $report['backup_exists']++;
    $backupContent = file_get_contents($backupF);
    $rootContent = is_file($rootF) ? file_get_contents($rootF) : '';
    if (strlen($backupContent) >= 80) {
        $report['backup_min_size']++;
    } else {
        $report['fail'][] = ['file' => $relRoot, 'reason' => 'backup too small'];
    }
    if (!str_contains($backupContent, 'examination/')) {
        $report['backup_not_stub']++;
    } else {
        $report['fail'][] = ['file' => $relRoot, 'reason' => 'backup looks like stub'];
    }
    if ($rootContent !== '' && $backupContent === $rootContent) {
        $report['fail'][] = ['file' => $relRoot, 'reason' => 'root still equals backup (not stubbed?)'];
    }
}

echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
exit($report['fail'] ? 1 : 0);
