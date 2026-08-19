<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/examination_monitor_helpers.php';

$pageTitle = 'Examination Monitor';
$uid = (int)getCurrentUserId();
$now = date('Y-m-d H:i:s');
$scope = examination_monitor_parse_scope($_GET);

if ($scope !== null && $scope['exam_type'] === 'college_exam' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_review_access') {
    $examIdPost = (int)($_POST['exam_id'] ?? 0);
    $ctxPost = examination_monitor_load_context($conn, $uid, 'college_exam', $examIdPost);
    if (!$ctxPost || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['message'] = 'Invalid security token.';
        header('Location: professor_examination_monitor?exam_type=regular&exam_id=' . $examIdPost);
        exit;
    }
    $exam = $ctxPost['row'];
    if (!empty($_POST['clear_review_schedule'])) {
        mysqli_query(
            $conn,
            'UPDATE college_exams SET review_sheet_available_from=NULL, review_sheet_available_until=NULL WHERE exam_id=' . $examIdPost . ' AND created_by=' . (int)$uid
        );
        $_SESSION['message'] = 'Review sheet access cleared.';
    } else {
        $fromSql = college_exam_parse_datetime_local($_POST['review_sheet_from'] ?? '');
        $untilSql = college_exam_parse_datetime_local($_POST['review_sheet_until'] ?? '');
        if ($fromSql === null) {
            $_SESSION['message'] = 'Choose a start date and time for review access, or use Clear schedule.';
        } elseif ($untilSql !== null && $untilSql < $fromSql) {
            $_SESSION['message'] = 'End time must be on or after the start time.';
        } else {
            if ($untilSql === null) {
                $ust = mysqli_prepare($conn, 'UPDATE college_exams SET review_sheet_available_from=?, review_sheet_available_until=NULL WHERE exam_id=? AND created_by=?');
                mysqli_stmt_bind_param($ust, 'sii', $fromSql, $examIdPost, $uid);
                mysqli_stmt_execute($ust);
                mysqli_stmt_close($ust);
            } else {
                $ust = mysqli_prepare($conn, 'UPDATE college_exams SET review_sheet_available_from=?, review_sheet_available_until=? WHERE exam_id=? AND created_by=?');
                mysqli_stmt_bind_param($ust, 'ssii', $fromSql, $untilSql, $examIdPost, $uid);
                mysqli_stmt_execute($ust);
                mysqli_stmt_close($ust);
            }
            $_SESSION['message'] = 'Review sheet schedule saved.';
        }
    }
    header('Location: professor_examination_monitor?exam_type=regular&exam_id=' . $examIdPost);
    exit;
}

if ($scope === null) {
    $filterExamType = examination_monitor_normalize_exam_type((string)($_GET['exam_type'] ?? ''));
    $filterSection = trim((string)($_GET['section'] ?? ''));
    $filterExamineeType = trim((string)($_GET['examinee_type'] ?? ''));
    $filterStatus = trim((string)($_GET['status'] ?? ''));
    $filterQ = trim((string)($_GET['q'] ?? ''));
    $assessments = examination_monitor_list_assessments($conn, $uid, [
        'exam_type' => $filterExamType,
        'section' => $filterSection,
        'examinee_type' => $filterExamineeType,
        'status' => $filterStatus,
        'q' => $filterQ,
    ]);
    $monitorFlash = $_SESSION['message'] ?? null;
    unset($_SESSION['message']);
    require dirname(__DIR__) . '/includes/examination_monitor_list_view.php';
    exit;
}

$ctx = examination_monitor_load_context($conn, $uid, $scope['exam_type'], $scope['assessment_id']);
if (!$ctx) {
    $_SESSION['message'] = 'Assessment not found.';
    header('Location: professor_examination_monitor');
    exit;
}

examination_monitor_finalize_expired($conn, $scope);
$metrics = examination_monitor_metrics($conn, $scope);
$students = examination_monitor_progress_rows($conn, $scope);
$totalStudents = examination_monitor_roster_count($conn, $scope);
$window = examination_monitor_running_finished($conn, $ctx, $metrics, $now);
$isRunning = $window['is_running'];
$isFinished = $window['is_finished'];
$allFinishedOpenExam = $window['all_finished_open'];

$totalTabLeaves = 0;
foreach ($students as $sx) {
    $totalTabLeaves += (int)($sx['tab_switch_count'] ?? 0);
}

$absentCount = 0;
if ($isFinished && $totalStudents > 0) {
    foreach ($students as $stRow) {
        $st = examination_monitor_normalized_attempt_status((string)($stRow['attempt_status'] ?? ''));
        if ($st !== 'submitted') {
            $absentCount++;
        }
    }
}

$reviewScheduleEligible = false;
$reviewAccessStatus = '';
$reviewFromLocal = '';
$reviewUntilLocal = '';
if ($ctx['supports_review_sheet']) {
    $exam = $ctx['row'];
    $reviewScheduleEligible = $totalStudents > 0
        && ($isFinished || ((int)($metrics['taking_count'] ?? 0) === 0 && (int)($metrics['submitted_count'] ?? 0) > 0 && (int)($metrics['submitted_count'] ?? 0) >= $totalStudents));
    $reviewAccessStatus = college_exam_review_access_status($exam, $now);
    $reviewFromLocal = college_exam_format_datetime_local($exam['review_sheet_available_from'] ?? null);
    $reviewUntilLocal = college_exam_format_datetime_local($exam['review_sheet_available_until'] ?? null);
}

$sectionRows = [];
$subjectAvgs = [];
$individualRows = [];
$assignmentMode = '';
if ($ctx['exam_type'] === 'diagnostic') {
    $batchId = (int)$ctx['assessment_id'];
    $sectionRows = diagnostic_exam_monitor_section_rows($conn, $batchId);
    $subjectAvgs = diagnostic_exam_monitor_subject_averages($conn, $batchId);
    $individualRows = diagnostic_exam_monitor_individual_rows($conn, $batchId);
    $assignmentMode = diagnostic_exam_normalize_assignment_mode((string)($ctx['row']['assignment_mode'] ?? 'sections'));
}

$liveUrl = examination_monitor_live_url($scope);
$pdfUrl = examination_monitor_pdf_url($scope);
$xlsxUrl = examination_monitor_xlsx_url($scope);
$scopeUrl = examination_monitor_scoped_url($scope);
$examIdSafe = (int)$ctx['assessment_id'];
$monitorFlash = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
$monitorCsrf = generateCSRFToken();

require dirname(__DIR__) . '/includes/examination_monitor_scoped_view.php';
