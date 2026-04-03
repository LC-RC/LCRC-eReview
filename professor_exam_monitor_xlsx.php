<?php
/**
 * Excel (.xlsx) download: Student progress — finished exams only.
 */
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';
require_once __DIR__ . '/includes/exam_monitor_progress_rows.php';
require_once __DIR__ . '/includes/exam_progress_report_xlsx.php';

$uid = (int)getCurrentUserId();
$examId = (int)($_GET['exam_id'] ?? 0);

if ($examId <= 0) {
    $_SESSION['message'] = 'Invalid exam selected.';
    header('Location: professor_exams.php');
    exit;
}

$stmt = mysqli_prepare($conn, 'SELECT * FROM college_exams WHERE exam_id=? AND created_by=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'ii', $examId, $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$exam = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$exam) {
    $_SESSION['message'] = 'Exam not found.';
    header('Location: professor_exams.php');
    exit;
}

$examIdSafe = $examId;
$now = date('Y-m-d H:i:s');

college_exam_finalize_expired_in_progress($conn, $examIdSafe, 0, 0);

$examQuestionCount = 0;
$qc = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id={$examIdSafe}");
if ($qc) {
    $qrow = mysqli_fetch_assoc($qc);
    $examQuestionCount = (int)($qrow['c'] ?? 0);
    mysqli_free_result($qc);
}

$metrics = [
    'submitted_count' => 0,
];
$mq = @mysqli_query($conn, "
  SELECT SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) AS submitted_count
  FROM college_exam_attempts
  WHERE exam_id=" . (int)$examId
);
if ($mq) {
    $m = mysqli_fetch_assoc($mq);
    if ($m) {
        $metrics['submitted_count'] = (int)($m['submitted_count'] ?? 0);
    }
    mysqli_free_result($mq);
}

$allFinishedOpenExam = college_exam_finished_all_submitted_no_deadline($conn, $exam, (int)$metrics['submitted_count']);
$isFinished = (!empty($exam['deadline']) && (string)$exam['deadline'] < $now) || $allFinishedOpenExam;

if (!$isFinished) {
    $_SESSION['message'] = 'Excel report is only available after the exam is finished.';
    header('Location: professor_exam_monitor.php?exam_id=' . (int)$examIdSafe);
    exit;
}

$rows = exam_monitor_progress_export_rows($conn, $examId, $examQuestionCount, $isFinished);

$lookupRows = [];
$lq = @mysqli_query(
    $conn,
    "SELECT student_number, full_name, email FROM users
     WHERE role='college_student'
       AND student_number IS NOT NULL AND TRIM(student_number) <> ''
     ORDER BY student_number ASC"
);
if ($lq) {
    while ($lr = mysqli_fetch_assoc($lq)) {
        $lookupRows[] = [
            'student_number' => trim((string)($lr['student_number'] ?? '')),
            'full_name' => (string)($lr['full_name'] ?? ''),
            'email' => (string)($lr['email'] ?? ''),
        ];
    }
    mysqli_free_result($lq);
}

ereview_output_exam_progress_xlsx((string)$exam['title'], $rows, $lookupRows);
exit;
