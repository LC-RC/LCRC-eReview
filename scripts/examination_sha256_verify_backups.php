<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$backupDir = $root . '/backups/examination_cutover';

$candidates = [
    ['includes/college_schema.php', 'examination/includes/college_schema.php'],
    ['includes/college_exam_helpers.php', 'examination/includes/college_exam_helpers.php'],
    ['includes/college_upload_helpers.php', 'examination/includes/college_upload_helpers.php'],
    ['includes/exam_monitor_progress_rows.php', 'examination/includes/exam_monitor_progress_rows.php'],
    ['includes/exam_progress_report_pdf.php', 'examination/includes/exam_progress_report_pdf.php'],
    ['includes/exam_progress_report_xlsx.php', 'examination/includes/exam_progress_report_xlsx.php'],
    ['includes/college_take_exam_review_submitted_section.php', 'examination/includes/college_take_exam_review_submitted_section.php'],
    ['professor_admin_dashboard.php', 'examination/professor/professor_admin_dashboard.php'],
    ['professor_admin_sidebar.php', 'examination/professor/professor_admin_sidebar.php'],
    ['professor_college_students.php', 'examination/professor/professor_college_students.php'],
    ['professor_create_college_student.php', 'examination/professor/professor_create_college_student.php'],
    ['professor_college_student_view.php', 'examination/professor/professor_college_student_view.php'],
    ['professor_college_student_delete.php', 'examination/professor/professor_college_student_delete.php'],
    ['professor_exams.php', 'examination/professor/professor_exams.php'],
    ['professor_exam_edit.php', 'examination/professor/professor_exam_edit.php'],
    ['professor_exam_monitor.php', 'examination/professor/professor_exam_monitor.php'],
    ['professor_exam_monitor_live.php', 'examination/professor/professor_exam_monitor_live.php'],
    ['professor_exam_monitor_pdf.php', 'examination/professor/professor_exam_monitor_pdf.php'],
    ['professor_exam_monitor_xlsx.php', 'examination/professor/professor_exam_monitor_xlsx.php'],
    ['professor_exam_review_sheet.php', 'examination/professor/professor_exam_review_sheet.php'],
    ['professor_exam_ai.php', 'examination/professor/professor_exam_ai.php'],
    ['professor_upload_tasks.php', 'examination/professor/professor_upload_tasks.php'],
    ['professor_upload_task_monitor.php', 'examination/professor/professor_upload_task_monitor.php'],
    ['professor_monitor.php', 'examination/professor/professor_monitor.php'],
    ['college_student_dashboard.php', 'examination/examinee/college_student_dashboard.php'],
    ['college_student_sidebar.php', 'examination/examinee/college_student_sidebar.php'],
    ['college_exams.php', 'examination/examinee/college_exams.php'],
    ['college_take_exam.php', 'examination/examinee/college_take_exam.php'],
    ['college_exam_ajax.php', 'examination/examinee/college_exam_ajax.php'],
    ['college_uploads.php', 'examination/examinee/college_uploads.php'],
    ['college_upload_task.php', 'examination/examinee/college_upload_task.php'],
    ['college_upload_file.php', 'examination/examinee/college_upload_file.php'],
    ['college_exams_debug.php', 'examination/examinee/college_exams_debug.php'],
];

$pass = 0;
$fail = [];
foreach ($candidates as [$relRoot, $relDest]) {
    $destF = $root . '/' . $relDest;
    $bn = basename($relRoot);
    $backupF = $backupDir . '/' . str_replace(['/', '\\'], '_', $relRoot) . '.pre_stub';
    if (!is_file($backupF)) {
        $backupF = $backupDir . '/' . $bn . '.pre_stub';
    }
    if (!is_file($backupF) && str_starts_with($relRoot, 'includes/')) {
        $backupF = $backupDir . '/includes_' . $bn . '.pre_stub';
    }
    if (!is_file($backupF) || !is_file($destF)) {
        $fail[] = ['file' => $relRoot, 'reason' => 'missing backup or dest'];
        continue;
    }
    $bh = hash_file('sha256', $backupF);
    $dh = hash_file('sha256', $destF);
    if ($bh !== $dh) {
        $fail[] = ['file' => $relRoot, 'reason' => 'sha256 mismatch', 'backup' => $bh, 'dest' => $dh];
        continue;
    }
    $pass++;
}

echo json_encode([
    'total' => count($candidates),
    'pass' => $pass,
    'fail_count' => count($fail),
    'failures' => $fail,
], JSON_PRETTY_PRINT) . "\n";
exit($fail ? 1 : 0);
