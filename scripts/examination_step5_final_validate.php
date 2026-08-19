<?php
/**
 * Step 5 final validation — all 33 stub candidates + backups + destinations.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$backupDir = $root . '/backups/examination_cutover';

$candidates = [
    ['includes/college_schema.php', 'examination/includes/college_schema.php', true],
    ['includes/college_exam_helpers.php', 'examination/includes/college_exam_helpers.php', true],
    ['includes/college_upload_helpers.php', 'examination/includes/college_upload_helpers.php', true],
    ['includes/exam_monitor_progress_rows.php', 'examination/includes/exam_monitor_progress_rows.php', true],
    ['includes/exam_progress_report_pdf.php', 'examination/includes/exam_progress_report_pdf.php', true],
    ['includes/exam_progress_report_xlsx.php', 'examination/includes/exam_progress_report_xlsx.php', true],
    ['includes/college_take_exam_review_submitted_section.php', 'examination/includes/college_take_exam_review_submitted_section.php', true],
    ['professor_admin_dashboard.php', 'examination/professor/professor_admin_dashboard.php', false],
    ['professor_admin_sidebar.php', 'examination/professor/professor_admin_sidebar.php', false],
    ['professor_college_students.php', 'examination/professor/professor_college_students.php', false],
    ['professor_create_college_student.php', 'examination/professor/professor_create_college_student.php', false],
    ['professor_college_student_view.php', 'examination/professor/professor_college_student_view.php', false],
    ['professor_college_student_delete.php', 'examination/professor/professor_college_student_delete.php', false],
    ['professor_exams.php', 'examination/professor/professor_exams.php', false],
    ['professor_exam_edit.php', 'examination/professor/professor_exam_edit.php', false],
    ['professor_exam_monitor.php', 'examination/professor/professor_exam_monitor.php', false],
    ['professor_exam_monitor_live.php', 'examination/professor/professor_exam_monitor_live.php', false],
    ['professor_exam_monitor_pdf.php', 'examination/professor/professor_exam_monitor_pdf.php', false],
    ['professor_exam_monitor_xlsx.php', 'examination/professor/professor_exam_monitor_xlsx.php', false],
    ['professor_exam_review_sheet.php', 'examination/professor/professor_exam_review_sheet.php', false],
    ['professor_exam_ai.php', 'examination/professor/professor_exam_ai.php', false],
    ['professor_upload_tasks.php', 'examination/professor/professor_upload_tasks.php', false],
    ['professor_upload_task_monitor.php', 'examination/professor/professor_upload_task_monitor.php', false],
    ['professor_monitor.php', 'examination/professor/professor_monitor.php', false],
    ['college_student_dashboard.php', 'examination/examinee/college_student_dashboard.php', false],
    ['college_student_sidebar.php', 'examination/examinee/college_student_sidebar.php', false],
    ['college_exams.php', 'examination/examinee/college_exams.php', false],
    ['college_take_exam.php', 'examination/examinee/college_take_exam.php', false],
    ['college_exam_ajax.php', 'examination/examinee/college_exam_ajax.php', false],
    ['college_uploads.php', 'examination/examinee/college_uploads.php', false],
    ['college_upload_task.php', 'examination/examinee/college_upload_task.php', false],
    ['college_upload_file.php', 'examination/examinee/college_upload_file.php', false],
    ['college_exams_debug.php', 'examination/examinee/college_exams_debug.php', false],
];

function lint(string $f): bool {
    $o=[]; $c=0; exec('"'.PHP_BINARY.'" -l '.escapeshellarg($f).' 2>&1',$o,$c); return $c===0;
}

$report = ['pass'=>[], 'fail'=>[]];
foreach ($candidates as [$relRoot, $relDest, $isInclude]) {
    $rootF = $root.'/'.$relRoot;
    $destF = $root.'/'.$relDest;
    $bn = basename($relRoot);
    $backupF = $backupDir.'/'.str_replace(['/','\\'],'_', $relRoot).'.pre_stub';
    if (!is_file($backupF)) $backupF = $backupDir.'/'.$bn.'.pre_stub';
    if (!is_file($backupF) && str_starts_with($relRoot, 'includes/')) {
        $backupF = $backupDir.'/includes_'.basename($relRoot).'.pre_stub';
    }

    $issues = [];
    if (!is_file($rootF)) $issues[] = 'root missing';
    if (!is_file($destF)) $issues[] = 'dest missing';
    if (is_file($rootF) && !lint($rootF)) $issues[] = 'root syntax';
    if (is_file($destF) && !lint($destF)) $issues[] = 'dest syntax';
    if (is_file($rootF)) {
        $content = file_get_contents($rootF);
        $expect = $isInclude
            ? "require_once __DIR__ . '/../".$relDest."';"
            : "require __DIR__ . '/".$relDest."';";
        if (!str_contains($content, $relDest)) $issues[] = 'stub content';
    }
    if (!is_file($backupF)) $issues[] = 'backup missing';
    elseif (is_file($backupF) && is_file($destF)) {
        // backup should NOT equal stub; backup should be original full file
        if (filesize($backupF) < 80) $issues[] = 'backup too small';
    }

    if ($issues) $report['fail'][] = [$relRoot, $issues];
    else $report['pass'][] = $relRoot;
}

// Include chain
require $root.'/db.php';
require $root.'/includes/college_schema.php';
require $root.'/includes/college_exam_helpers.php';
require $root.'/includes/college_upload_helpers.php';
require $root.'/includes/exam_monitor_progress_rows.php';
require $root.'/includes/exam_progress_report_pdf.php';
require $root.'/includes/exam_progress_report_xlsx.php';
$chainOk = function_exists('college_exam_login_blocked_by_active_exam_session')
    && function_exists('exam_monitor_progress_export_rows')
    && function_exists('ereview_pdf_winansi')
    && function_exists('ereview_xlsx_col_letter');

echo json_encode([
    'stub_count_pass' => count($report['pass']),
    'stub_count_fail' => count($report['fail']),
    'failures' => $report['fail'],
    'include_chain' => $chainOk ? 'PASS' : 'FAIL',
], JSON_PRETTY_PRINT)."\n";
exit($report['fail'] || !$chainOk ? 1 : 0);
