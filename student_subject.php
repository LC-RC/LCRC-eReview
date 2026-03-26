<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/quiz_helpers.php';
requireRole('student');

$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectId <= 0) { header('Location: student_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE subject_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
if (!$subject) { header('Location: student_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM lessons WHERE subject_id=? ORDER BY lesson_id ASC");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$lessons = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

$lessonsRows = [];
if ($lessons) {
    mysqli_data_seek($lessons, 0);
    while ($row = mysqli_fetch_assoc($lessons)) {
        $lessonsRows[] = $row;
    }
}

// Client-side Materials tab: search + sort (Alpine)
$lessonsForAlpine = [];
foreach ($lessonsRows as $l) {
    $lessonsForAlpine[] = [
        'id' => (int)($l['lesson_id'] ?? 0),
        'title' => (string)($l['title'] ?? ''),
        'desc' => (string)($l['description'] ?? ''),
        'href' => 'student_lesson_viewer.php?lesson_id=' . (int)($l['lesson_id']) . '&subject_id=' . (int)$subjectId,
    ];
}

$stmt = mysqli_prepare($conn, "SELECT * FROM quizzes WHERE subject_id=? ORDER BY quiz_id DESC");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$quizzesAll = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

// Load attempt data for Quizzers tab (in_progress + last score per quiz) for current student
$userId = getCurrentUserId();
$attemptsByQuiz = [];
$lastScoreByQuiz = [];
$attemptCountByQuiz = [];
$lastAttemptDateByQuiz = [];
$lastTimeSpentByQuiz = [];
$bestScoreByQuiz = [];
$bestScoreDateByQuiz = [];
if ($userId) {
    $quizIds = [];
    mysqli_data_seek($quizzesAll, 0);
    while ($row = mysqli_fetch_assoc($quizzesAll)) $quizIds[] = (int)$row['quiz_id'];
    mysqli_data_seek($quizzesAll, 0);
    if (!empty($quizIds)) {
        $ids = implode(',', array_map('intval', $quizIds));
        $ar = mysqli_query($conn, "SELECT attempt_id, quiz_id, status, score, correct_count, total_count, submitted_at, started_at FROM quiz_attempts WHERE user_id=".(int)$userId." AND quiz_id IN (".$ids.") ORDER BY attempt_id DESC");
        if ($ar) {
            while ($row = mysqli_fetch_assoc($ar)) {
                $qid = (int)$row['quiz_id'];
                if (!isset($lastAttemptDateByQuiz[$qid])) {
                    $lastAttemptDateByQuiz[$qid] = !empty($row['submitted_at']) ? $row['submitted_at'] : ($row['started_at'] ?? null);
                }
                if ($row['status'] === 'in_progress' && !isset($attemptsByQuiz[$qid])) {
                    $attemptsByQuiz[$qid] = (int)$row['attempt_id'];
                }
                if ($row['status'] === 'submitted') {
                    if (!isset($lastScoreByQuiz[$qid])) {
                        $lastScoreByQuiz[$qid] = ['score' => (float)($row['score'] ?? 0), 'correct' => (int)($row['correct_count'] ?? 0), 'total' => (int)($row['total_count'] ?? 0)];
                        $sub = strtotime($row['submitted_at'] ?? '0');
                        $start = strtotime($row['started_at'] ?? '0');
                        $lastTimeSpentByQuiz[$qid] = ($sub > 0 && $start > 0 && $sub >= $start) ? ($sub - $start) : 0;
                    }
                    $scorePct = (int)$row['total_count'] > 0 ? round(100 * (int)$row['correct_count'] / (int)$row['total_count']) : (float)($row['score'] ?? 0);
                    if (!isset($bestScoreByQuiz[$qid]) || $scorePct > $bestScoreByQuiz[$qid]) {
                        $bestScoreByQuiz[$qid] = $scorePct;
                        $bestScoreDateByQuiz[$qid] = !empty($row['submitted_at']) ? $row['submitted_at'] : ($row['started_at'] ?? null);
                    }
                }
                if (!isset($attemptCountByQuiz[$qid])) $attemptCountByQuiz[$qid] = 0;
                if ($row['status'] === 'submitted') $attemptCountByQuiz[$qid]++;
            }
        }
    }
}

// Quiz summary stats for this subject (total, passed, failed, average score)
$totalQuizzes = 0;
$quizzesPassed = 0;
$quizzesFailed = 0;
$scoreSum = 0;
$scoreCount = 0;
mysqli_data_seek($quizzesAll, 0);
while ($q = mysqli_fetch_assoc($quizzesAll)) {
    $totalQuizzes++;
    $qid = (int)$q['quiz_id'];
    $lastScore = $lastScoreByQuiz[$qid] ?? null;
    if ($lastScore !== null && (int)($lastScore['total'] ?? 0) > 0) {
        $pct = round(100 * (int)($lastScore['correct'] ?? 0) / (int)$lastScore['total']);
        $scoreSum += $pct;
        $scoreCount++;
        if ($pct >= 50) $quizzesPassed++; else $quizzesFailed++;
    }
}
mysqli_data_seek($quizzesAll, 0);
$averageScore = $scoreCount > 0 ? round($scoreSum / $scoreCount) : null;

// Build quiz rows once so Quizzers can render as list or cards
$quizRows = [];
mysqli_data_seek($quizzesAll, 0);
while ($q = mysqli_fetch_assoc($quizzesAll)) {
    $qid = (int)$q['quiz_id'];

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM quiz_questions WHERE quiz_id=?");
    mysqli_stmt_bind_param($stmt, 'i', $qid);
    mysqli_stmt_execute($stmt);
    $cResult = mysqli_stmt_get_result($stmt);
    $rc = mysqli_fetch_assoc($cResult);
    mysqli_stmt_close($stmt);
    $cnt = (int)($rc['cnt'] ?? 0);

    $timeLimitSeconds = getQuizTimeLimitSeconds($q);
    $inProgressAttemptId = $attemptsByQuiz[$qid] ?? null;
    $lastScore = $lastScoreByQuiz[$qid] ?? null;
    $lastAttemptDate = $lastAttemptDateByQuiz[$qid] ?? null;
    $attemptCount = (int)($attemptCountByQuiz[$qid] ?? 0);
    $scorePct = $lastScore !== null && (int)($lastScore['total'] ?? 0) > 0 ? round(100 * (int)$lastScore['correct'] / (int)$lastScore['total']) : null;
    $passed = $scorePct !== null && $scorePct >= 50;
    $takeUrl = 'student_take_quiz.php?quiz_id=' . $qid . '&subject_id=' . (int)$subjectId;
    $viewResultUrl = 'student_take_quiz.php?quiz_id=' . $qid . '&subject_id=' . (int)$subjectId . '&view_result=1';
    $rowStatus = ($cnt <= 0) ? 'no_questions' : ($inProgressAttemptId ? 'in_progress' : ($passed ? 'passed' : 'need_retake'));
    $notTaken = ($cnt > 0 && !$inProgressAttemptId && $attemptCount === 0);
    if ($notTaken) $rowStatus = 'not_taken';
    $statusBadgeClass = $rowStatus === 'passed' ? 'passed' : ($rowStatus === 'need_retake' ? 'failed' : ($rowStatus === 'in_progress' ? 'in-progress' : 'not-started'));
    $statusLabel = $rowStatus === 'need_retake' ? 'Failed' : ($rowStatus === 'not_taken' ? 'Not started' : ucfirst(str_replace('_', ' ', $rowStatus)));

    $quizRows[] = [
        'qid' => $qid,
        'title' => (string)($q['title'] ?? ''),
        'count' => $cnt,
        'duration' => formatTimeLimitSeconds($timeLimitSeconds),
        'in_progress_attempt_id' => $inProgressAttemptId ? (int)$inProgressAttemptId : null,
        'last_attempt_date' => $lastAttemptDate,
        'attempt_count' => $attemptCount,
        'score_pct' => $scorePct,
        'passed' => $passed,
        'take_url' => $takeUrl,
        'view_result_url' => $viewResultUrl,
        'row_status' => $rowStatus,
        'status_badge_class' => $statusBadgeClass,
        'status_label' => $statusLabel,
    ];
}
mysqli_data_seek($quizzesAll, 0);

$pageTitle = 'Subject - ' . $subject['subject_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .video-embed { aspect-ratio: 16/9; width: 100%; border: 0; border-radius: 8px; background: #000; }
    .separate-view.hidden { display: none !important; }

    /* Tab navigation: smooth hover and transition */
    .subject-tab-btn {
      transition: all 0.2s ease;
    }
    .subject-tab-btn:hover {
      transform: translateY(-1px);
    }
    .subject-tab-btn:active {
      transform: translateY(0);
    }

    /* Tab panel enter/leave */
    .tab-panel-enter { opacity: 0; transform: translateY(6px); }
    .tab-panel-enter-active { transition: opacity 0.25s ease, transform 0.25s ease; }
    .tab-panel-enter-to { opacity: 1; transform: translateY(0); }
    .tab-panel-leave { opacity: 1; transform: translateY(0); }
    .tab-panel-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
    .tab-panel-leave-to { opacity: 0; transform: translateY(-4px); }

    /* Quizzers: search and filter */
    .quiz-search-input, .quiz-status-select {
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }
    .quiz-search-input:focus, .quiz-status-select:focus {
      border-color: rgba(65, 84, 241, 0.6);
      box-shadow: 0 0 0 3px rgba(65, 84, 241, 0.15);
    }

    /* Quizzers table: rows and buttons */
    .quiz-table-row {
      transition: background-color 0.18s ease, box-shadow 0.22s ease, transform 0.18s ease;
    }
    .quiz-table-row:hover {
      background-color: rgba(51, 147, 255, 0.04);
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.55),
        0 10px 24px rgba(51, 147, 255, 0.5);
      transform: translateY(-1px);
    }
    .quiz-action-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 0.375rem;
      min-width: 8.5rem; height: 2.25rem; padding: 0 1rem; font-size: 0.8125rem; font-weight: 600;
      border-radius: 0.5rem; text-decoration: none;
      transition: transform 0.18s ease, box-shadow 0.22s ease, background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }
    .quiz-action-btn:hover {
      transform: translateY(-2px) scale(1.02);
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.75),
        0 12px 28px rgba(51, 147, 255, 0.6);
    }
    .quiz-action-btn:active {
      transform: translateY(0) scale(0.98);
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    /* App modal – dark gradient format (same style for all confirmations/alerts) */
    .quiz-confirm-overlay {
      position: fixed; inset: 0; z-index: 1200; display: flex; align-items: center; justify-content: center; padding: 1rem;
      background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(6px);
      opacity: 0; visibility: hidden; transition: opacity 0.2s ease, visibility 0.2s ease;
    }
    .quiz-confirm-overlay.show { opacity: 1; visibility: visible; }
    .quiz-confirm-card {
      background: linear-gradient(135deg, #1e293b 0%, #0f172a 50%, #0c0a14 100%);
      border-radius: 1rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
      max-width: 400px; width: 100%; overflow: hidden; padding: 1.75rem 1.5rem; text-align: center;
      transform: scale(0.95); transition: transform 0.2s ease;
      border: 1px solid rgba(255,255,255,0.08);
    }
    .quiz-confirm-overlay.show .quiz-confirm-card { transform: scale(1); }
    .quiz-confirm-card .quiz-confirm-icon-wrap {
      width: 4rem; height: 4rem; margin: 0 auto 1.25rem; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 0 0 4px rgba(255,255,255,0.1), inset 0 0 20px rgba(255,255,255,0.05);
    }
    .quiz-confirm-card .quiz-confirm-icon-wrap.info { background: rgba(59, 130, 246, 0.25); color: #93c5fd; }
    .quiz-confirm-card .quiz-confirm-icon-wrap.warning { background: rgba(245, 158, 11, 0.25); color: #fcd34d; }
    .quiz-confirm-card .quiz-confirm-icon-wrap.error { background: rgba(239, 68, 68, 0.3); color: #fca5a5; box-shadow: 0 0 0 4px rgba(255,255,255,0.1), inset 0 0 24px rgba(239, 68, 68, 0.2); }
    .quiz-confirm-card .quiz-confirm-icon-wrap i { font-size: 1.75rem; }
    .quiz-confirm-card .quiz-confirm-header {
      font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.5rem; line-height: 1.3;
    }
    .quiz-confirm-card .quiz-confirm-body {
      color: rgba(241, 245, 249, 0.85); font-size: 0.9375rem; line-height: 1.6; margin-bottom: 1.5rem;
    }
    .quiz-confirm-card .quiz-confirm-actions {
      display: flex; justify-content: center; gap: 0.75rem; flex-wrap: wrap;
    }
    .quiz-confirm-btn {
      padding: 0.625rem 1.5rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; cursor: pointer;
      transition: background 0.2s, color 0.2s, border-color 0.2s, transform 0.15s, opacity 0.2s;
    }
    .quiz-confirm-btn:active { transform: scale(0.98); }
    .quiz-confirm-btn-cancel {
      background: transparent; color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.4);
    }
    .quiz-confirm-btn-cancel:hover { background: rgba(255,255,255,0.06); color: #e2e8f0; border-color: rgba(255,255,255,0.2); }
    .quiz-confirm-btn-primary {
      background: transparent; color: #fff; border: 1px solid rgba(255,255,255,0.6);
    }
    .quiz-confirm-btn-primary:hover { background: rgba(255,255,255,0.1); border-color: #fff; }

    .quiz-action-btn:focus,
    .quiz-take-again-btn:focus,
    .quiz-resume-link:focus {
      outline: none;
      box-shadow:
        0 0 0 2px rgba(255, 255, 255, 1),
        0 0 0 4px rgba(51, 147, 255, 0.7);
    }
    /* Take Again / Take Quiz: force primary blue so button is always visible */
    .quiz-take-again-btn {
      background-color: #4154f1 !important;
      color: #fff !important;
    }
    .quiz-take-again-btn:hover {
      background-color: #2d3fc7 !important;
      color: #fff !important;
    }
    .quiz-action-placeholder { min-width: 8.5rem; height: 2.25rem; display: inline-flex; align-items: center; justify-content: center; }
    .quiz-score-badge {
      display: inline-flex; align-items: center; justify-content: center; min-width: 2.5rem;
      padding: 0.2rem 0.5rem; border-radius: 9999px; font-size: 0.8125rem; font-weight: 600;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .quiz-table-row:hover .quiz-score-badge {
      transform: scale(1.05);
    }
    /* Staggered fade-in for quiz rows when tab is shown */
    @keyframes quiz-row-fade-in {
      from { opacity: 0; transform: translateY(4px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .quiz-table-row.quiz-row-visible {
      animation: quiz-row-fade-in 0.4s ease backwards;
    }

    /* Quizzers / Test Bank: dashboard-style card (same theme as dashboard) */
    .quizzers-section { margin-top: 1rem; }
    .quizzers-header { padding: 1rem 1.5rem; border-bottom: 1px solid rgba(22, 101, 160, 0.15); background: rgba(232, 242, 250, 0.5); display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
    .quizzers-header .quizzers-title { font-size: 1.25rem; font-weight: 700; color: #143D59; margin: 0 0 0.25rem 0; display: flex; align-items: center; gap: 0.5rem; }
    .quizzers-header .quizzers-subtitle { font-size: 0.875rem; color: rgba(20, 61, 89, 0.7); margin: 0; width: 100%; }
    .quiz-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.75rem; padding: 1rem 1.5rem; margin-bottom: 0; }
    .quiz-summary-card {
      /* Match student dashboard quick cards */
      position: relative;
      overflow: hidden;
      border-radius: 1rem; /* ~rounded-2xl */
      padding: 1rem 1.25rem;
      text-align: left;
      background-image: linear-gradient(to bottom right, #d4e8f7, #e8f2fa);
      border: 1px solid rgba(22, 101, 160, 0.22);
      box-shadow:
        0 2px 8px rgba(20, 61, 89, 0.12),
        0 4px 16px rgba(20, 61, 89, 0.08);
      transition: box-shadow 0.25s ease, transform 0.18s ease, border-color 0.18s ease;
      color: #143D59;
    }
    .quiz-summary-card:hover {
      transform: translateY(-2px);
      box-shadow:
        0 8px 24px rgba(20, 61, 89, 0.18),
        0 10px 30px rgba(20, 61, 89, 0.24);
    }
    .quiz-summary-card .summary-value {
      font-size: 1.4rem;
      font-weight: 800;
      color: #143D59;
    }
    .quiz-summary-card .summary-label {
      font-size: 0.7rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(20, 61, 89, 0.75);
    }
    .quiz-summary-card:hover {
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.45),
        0 10px 24px rgba(51, 147, 255, 0.4);
      transform: translateY(-2px);
      border-color: rgba(51, 147, 255, 0.6);
    }
    .quiz-summary-card .summary-value { font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2; display: block; }
    .quiz-summary-card .summary-label { font-size: 0.75rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 0.25rem; }
    .quiz-summary-card.summary-passed .summary-value { color: #059669; }
    .quiz-summary-card.summary-failed .summary-value { color: #dc2626; }
    .quiz-summary-card.summary-avg .summary-value { color: #4154f1; }
    .quiz-table-wrap { overflow-x: auto; border-radius: 0 0 12px 12px; }
    .quiz-filter-pills { overflow-wrap: anywhere; }
    .quiz-table { width: 100%; min-width: 860px; border-collapse: separate; border-spacing: 0; text-sm; }
    .quiz-table thead th { padding: 0.75rem 1rem; font-weight: 600; color: #143D59; background: rgba(232, 242, 250, 0.6); border-bottom: 2px solid rgba(22, 101, 160, 0.2); white-space: nowrap; }
    .quiz-table thead th:first-child { text-align: left; padding-left: 1.5rem; }
    .quiz-table thead th:not(:first-child) { text-align: center; }
    .quiz-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background-color 0.18s ease, box-shadow 0.22s ease, transform 0.18s ease; }
    .quiz-table tbody tr:hover {
      background-color: rgba(51, 147, 255, 0.04);
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.6),
        0 10px 24px rgba(51, 147, 255, 0.5);
      transform: translateY(-1px);
    }
    .quiz-table tbody td { padding: 0.75rem 1rem; vertical-align: middle; }
    .quiz-table tbody td:first-child { text-align: left; padding-left: 1.5rem; font-weight: 500; color: #1e293b; }
    .quiz-table tbody td:not(:first-child) { text-align: center; color: #475569; }
    .quiz-last-score-cell { display: flex; flex-direction: column; align-items: center; gap: 0.25rem; }
    .quiz-last-score-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
    .quiz-score-badge-lg { display: inline-flex; align-items: center; justify-content: center; min-width: 3rem; padding: 0.35rem 0.65rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 700; transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .quiz-score-badge-lg.pass {
      background: #dcfce7;
      color: #15803d;
      box-shadow:
        0 0 0 1px rgba(34, 197, 94, 0.35),
        0 0 16px rgba(51, 147, 255, 0.45);
    }
    .quiz-score-badge-lg.fail {
      background: #fee2e2;
      color: #b91c1c;
      box-shadow:
        0 0 0 1px rgba(239, 68, 68, 0.4),
        0 0 16px rgba(51, 147, 255, 0.35);
    }
    .quiz-status-badge { display: inline-flex; align-items: center; padding: 0.4rem 0.75rem; border-radius: 9999px; font-size: 0.8125rem; font-weight: 700; }
    .quiz-status-badge.passed { background: #dcfce7; color: #15803d; }
    .quiz-status-badge.failed { background: #fee2e2; color: #b91c1c; }
    .quiz-status-badge.in-progress { background: #fef9c3; color: #a16207; }
    .quiz-status-badge.not-started { background: #f1f5f9; color: #64748b; }
    .quiz-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: 10px;
      font-size: 0.8125rem;
      font-weight: 600;
      text-decoration: none;
      transition: transform 0.18s ease, box-shadow 0.22s ease;
    }
    .quiz-btn:hover {
      transform: translateY(-2px);
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.8),
        0 10px 24px rgba(51, 147, 255, 0.6);
    }
    .quiz-btn-primary { background: #1665A0; color: #fff; border: none; }
    .quiz-btn-primary:hover { background: #143D59; color: #fff; }
    .quiz-take-again-btn.quiz-btn { background-color: #1665A0 !important; color: #fff !important; }
    .quiz-take-again-btn.quiz-btn:hover { background-color: #143D59 !important; color: #fff !important; }
    .quiz-btn-outline { background: #fff; color: #475569; border: 2px solid #e2e8f0; }
    .quiz-btn-outline:hover { background: #f8fafc; border-color: #94a3b8; color: #1e293b; }
    .quiz-btn-amber { background: #f59e0b; color: #fff; border: none; }
    .quiz-btn-amber:hover { background: #d97706; color: #fff; }
    .quiz-empty-state { padding: 3rem 1.5rem; text-align: center; background: linear-gradient(to bottom, rgba(240, 247, 252, 0.5), transparent); }
    .quiz-empty-state .quiz-empty-icon { font-size: 3.5rem; color: rgba(22, 101, 160, 0.4); margin-bottom: 1rem; display: block; }
    .quiz-empty-state .quiz-empty-msg { font-size: 1rem; font-weight: 600; color: #143D59; margin: 0; }
    @media (max-width: 768px) {
      .quiz-summary-grid { grid-template-columns: repeat(2, 1fr); }
      .quiz-table thead th, .quiz-table tbody td { padding: 0.75rem 0.5rem; font-size: 0.8125rem; }
      .quiz-table thead th:first-child, .quiz-table tbody td:first-child { padding-left: 1rem; }
      .quizzers-section .quizzers-header { padding: 1rem 1rem; }
      .quizzers-section .quizzers-title { font-size: 1.125rem; }
      .quizzers-section .quizzers-subtitle { font-size: 0.8125rem; }
    }
    @media (max-width: 480px) {
      .quiz-table thead th, .quiz-table tbody td { padding: 0.5rem 0.375rem; font-size: 0.75rem; }
      .quiz-table thead th:first-child, .quiz-table tbody td:first-child { padding-left: 0.75rem; }
      .quiz-btn { padding: 0.375rem 0.75rem; font-size: 0.75rem; }
    }
    .quiz-table-wrap { -webkit-overflow-scrolling: touch; }

    /* Row enter/leave when filtering (smooth expand/collapse) */
    .quiz-row-enter { opacity: 0; transform: translateX(-8px); }
    .quiz-row-enter-active { transition: opacity 0.3s ease, transform 0.3s ease; }
    .quiz-row-enter-to { opacity: 1; transform: translateX(0); }
    .quiz-row-leave { opacity: 1; transform: translateX(0); }
    .quiz-row-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
    .quiz-row-leave-to { opacity: 0; transform: translateX(8px); }

    /* Subject page: back link (aligned with subject card) */
    .subject-back-row {
      margin-bottom: 0.75rem;
    }
    .btn-back-subjects {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.625rem 1.125rem;
      border-radius: 9999px;
      font-weight: 600;
      font-size: 0.9375rem;
      letter-spacing: 0.01em;
      color: #143D59;
      background: linear-gradient(180deg, #ffffff 0%, #f3f9fd 100%);
      border: 1px solid rgba(22, 101, 160, 0.28);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.9) inset,
        0 4px 14px rgba(20, 61, 89, 0.08),
        0 1px 2px rgba(20, 61, 89, 0.06);
      text-decoration: none;
      transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, color 0.2s ease;
    }
    .btn-back-subjects:hover {
      color: #1665A0;
      border-color: rgba(22, 101, 160, 0.45);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.95) inset,
        0 8px 22px rgba(22, 101, 160, 0.14),
        0 2px 4px rgba(20, 61, 89, 0.08);
      transform: translateY(-1px);
    }
    .btn-back-subjects:active {
      transform: translateY(0);
      box-shadow: 0 2px 8px rgba(20, 61, 89, 0.1);
    }
    .btn-back-subjects i {
      font-size: 1.05rem;
      opacity: 0.9;
    }

    /* Materials: view toggle (cards / list) */
    .materials-view-toggle {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      flex-shrink: 0;
    }
    .materials-view-toggle__label {
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(20, 61, 89, 0.45);
      display: none;
    }
    @media (min-width: 640px) {
      .materials-view-toggle__label {
        display: block;
      }
    }
    .materials-view-seg {
      display: inline-flex;
      padding: 0.2rem;
      border-radius: 0.875rem;
      background: rgba(255, 255, 255, 0.85);
      border: 1px solid rgba(22, 101, 160, 0.18);
      box-shadow: 0 1px 3px rgba(20, 61, 89, 0.06);
    }
    .materials-view-seg button {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.45rem 0.75rem;
      border: none;
      border-radius: 0.65rem;
      font-size: 0.8125rem;
      font-weight: 700;
      color: rgba(20, 61, 89, 0.55);
      background: transparent;
      cursor: pointer;
      transition: color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
    }
    .materials-view-seg button:hover {
      color: #1665A0;
      background: rgba(232, 242, 250, 0.9);
    }
    .materials-view-seg button.is-active {
      color: #fff;
      background: linear-gradient(145deg, #1e7cc8 0%, #1665A0 55%, #124a73 100%);
      box-shadow: 0 2px 10px rgba(22, 101, 160, 0.35);
    }
    .materials-view-seg button i {
      font-size: 1rem;
      opacity: 0.95;
    }
    .materials-view-seg button:hover:not(.is-active) i {
      opacity: 1;
    }

    /* Materials: lesson cards */
    .lesson-cards-wrap {
      padding: 1rem 1rem 1.25rem;
    }
    @media (min-width: 640px) {
      .lesson-cards-wrap {
        padding: 1.25rem 1.5rem 1.5rem;
      }
    }
    .lesson-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
      gap: 1rem 1.125rem;
    }
    .lesson-card {
      position: relative;
      display: flex;
      flex-direction: column;
      min-height: 100%;
      padding: 1rem 1rem 1.125rem;
      border-radius: 1rem;
      text-decoration: none;
      color: inherit;
      background: linear-gradient(165deg, #ffffff 0%, #f5f9fd 55%, #eef5fb 100%);
      border: 1px solid rgba(22, 101, 160, 0.14);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.95) inset,
        0 4px 16px rgba(20, 61, 89, 0.07),
        0 1px 3px rgba(20, 61, 89, 0.05);
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
      overflow: hidden;
    }
    .lesson-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, #1665A0, #3393ff, #1665A0);
      opacity: 0.85;
      transform: scaleX(0.35);
      transform-origin: left;
      transition: transform 0.28s ease;
    }
    .lesson-card:hover {
      transform: translateY(-4px);
      border-color: rgba(22, 101, 160, 0.32);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 1) inset,
        0 12px 32px rgba(22, 101, 160, 0.14),
        0 4px 12px rgba(20, 61, 89, 0.08);
    }
    .lesson-card:hover::before {
      transform: scaleX(1);
    }
    .lesson-card:focus-visible {
      outline: 2px solid #1665A0;
      outline-offset: 3px;
    }
    .lesson-card__top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.75rem;
      margin-bottom: 0.625rem;
    }
    .lesson-card__badge {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 2.25rem;
      height: 2.25rem;
      padding: 0 0.5rem;
      border-radius: 0.75rem;
      font-size: 0.8125rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      color: #1665A0;
      background: linear-gradient(180deg, #e8f2fa 0%, #dceaf5 100%);
      border: 1px solid rgba(22, 101, 160, 0.2);
      box-shadow: 0 1px 2px rgba(20, 61, 89, 0.06);
    }
    .lesson-card__icon {
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 0.875rem;
      color: #fff;
      background: linear-gradient(145deg, #1e7cc8 0%, #1665A0 50%, #124a73 100%);
      box-shadow: 0 4px 12px rgba(22, 101, 160, 0.35);
      font-size: 1.125rem;
    }
    .lesson-card__title {
      margin: 0;
      font-size: 0.98rem;
      font-weight: 700;
      line-height: 1.4;
      color: #143D59;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .lesson-card__meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.5rem;
      margin-top: auto;
      padding-top: 0.875rem;
      border-top: 1px solid rgba(22, 101, 160, 0.1);
    }
    .lesson-card__hint {
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: rgba(20, 61, 89, 0.45);
    }
    .lesson-card__cta {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.8125rem;
      font-weight: 700;
      color: #1665A0;
    }
    .lesson-card:hover .lesson-card__cta {
      color: #0f4d7a;
    }
    .lesson-card:hover .lesson-card__cta i {
      transform: translateX(3px);
    }
    .lesson-card__cta i {
      font-size: 0.95rem;
      transition: transform 0.2s ease;
    }
    .lesson-empty-state {
      grid-column: 1 / -1;
      text-align: center;
      padding: 2.5rem 1.5rem;
      border-radius: 1rem;
      border: 1px dashed rgba(22, 101, 160, 0.25);
      background: rgba(232, 242, 250, 0.45);
      color: rgba(20, 61, 89, 0.65);
      font-size: 0.9375rem;
      font-weight: 500;
      line-height: 1.5;
    }
    .lesson-empty-state i {
      display: block;
      font-size: 2.25rem;
      color: rgba(22, 101, 160, 0.35);
      margin-bottom: 0.75rem;
    }

    /* Materials: modern list view */
    .lesson-list-wrap {
      padding: 0.75rem 1rem 1.25rem;
    }
    @media (min-width: 640px) {
      .lesson-list-wrap {
        padding: 1rem 1.5rem 1.5rem;
      }
    }
    .lesson-list {
      list-style: none;
      margin: 0;
      padding: 0;
      border-radius: 1rem;
      overflow: hidden;
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.95) 0%, rgba(245, 249, 253, 0.98) 100%);
      border: 1px solid rgba(22, 101, 160, 0.12);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 1) inset,
        0 4px 20px rgba(20, 61, 89, 0.06);
    }
    .lesson-list__item {
      border-bottom: 1px solid rgba(22, 101, 160, 0.08);
    }
    .lesson-list__item:last-child {
      border-bottom: none;
    }
    .lesson-list__link {
      display: flex;
      align-items: stretch;
      gap: 0.75rem 1rem;
      padding: 0.875rem 1rem;
      text-decoration: none;
      color: inherit;
      transition: background 0.2s ease, box-shadow 0.2s ease;
    }
    @media (min-width: 640px) {
      .lesson-list__link {
        padding: 1rem 1.125rem;
        gap: 1rem 1.25rem;
      }
    }
    .lesson-list__link:hover {
      background: linear-gradient(90deg, rgba(232, 242, 250, 0.65) 0%, rgba(255, 255, 255, 0.4) 100%);
      box-shadow: inset 3px 0 0 #1665A0;
    }
    .lesson-list__link:focus-visible {
      outline: 2px solid #1665A0;
      outline-offset: -2px;
    }
    .lesson-list__idx {
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 0.875rem;
      font-size: 0.8125rem;
      font-weight: 800;
      color: #1665A0;
      background: linear-gradient(180deg, #e8f2fa 0%, #dceaf5 100%);
      border: 1px solid rgba(22, 101, 160, 0.2);
      box-shadow: 0 1px 3px rgba(20, 61, 89, 0.06);
    }
    .lesson-list__body {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 0.2rem;
    }
    .lesson-list__title {
      margin: 0;
      font-size: 0.9375rem;
      font-weight: 700;
      line-height: 1.45;
      color: #143D59;
    }
    @media (min-width: 640px) {
      .lesson-list__title {
        font-size: 1rem;
      }
    }
    .lesson-list__sub {
      font-size: 0.75rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: rgba(20, 61, 89, 0.42);
    }
    .lesson-list__action {
      flex-shrink: 0;
      display: flex;
      align-items: center;
      align-self: center;
    }
    .lesson-list__pill {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.4rem 0.75rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 700;
      color: #1665A0;
      background: rgba(232, 242, 250, 0.95);
      border: 1px solid rgba(22, 101, 160, 0.2);
      transition: transform 0.2s ease, background 0.2s ease, color 0.2s ease;
    }
    .lesson-list__link:hover .lesson-list__pill {
      background: #1665A0;
      color: #fff;
      border-color: transparent;
      transform: translateX(2px);
    }
    .lesson-list__pill i {
      font-size: 1.1rem;
      transition: transform 0.2s ease;
    }
    .lesson-list__link:hover .lesson-list__pill i {
      transform: translateX(3px);
    }

    /* Test Bank: card/list views (mirrors materials UI) */
    .testbank-wrap {
      padding: 1rem 1rem 1.25rem;
    }
    @media (min-width: 640px) {
      .testbank-wrap {
        padding: 1.25rem 1.5rem 1.5rem;
      }
    }
    .testbank-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
      gap: 1rem 1.125rem;
    }
    .testbank-card {
      position: relative;
      display: flex;
      flex-direction: column;
      min-height: 100%;
      padding: 1rem 1rem 1.125rem;
      border-radius: 1rem;
      text-decoration: none;
      color: inherit;
      background: linear-gradient(165deg, #ffffff 0%, #f5f9fd 55%, #eef5fb 100%);
      border: 1px solid rgba(22, 101, 160, 0.14);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.95) inset,
        0 4px 16px rgba(20, 61, 89, 0.07),
        0 1px 3px rgba(20, 61, 89, 0.05);
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
      overflow: hidden;
    }
    .testbank-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, #1665A0, #3393ff, #1665A0);
      opacity: 0.85;
      transform: scaleX(0.35);
      transform-origin: left;
      transition: transform 0.28s ease;
    }
    .testbank-card:hover {
      transform: translateY(-4px);
      border-color: rgba(22, 101, 160, 0.32);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 1) inset,
        0 12px 32px rgba(22, 101, 160, 0.14),
        0 4px 12px rgba(20, 61, 89, 0.08);
    }
    .testbank-card:hover::before { transform: scaleX(1); }
    .testbank-card:focus-visible { outline: 2px solid #1665A0; outline-offset: 3px; }
    .testbank-card__top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.75rem;
      margin-bottom: 0.625rem;
    }
    .testbank-card__badge {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 2.25rem;
      height: 2.25rem;
      padding: 0 0.5rem;
      border-radius: 0.75rem;
      font-size: 0.8125rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      color: #1665A0;
      background: linear-gradient(180deg, #e8f2fa 0%, #dceaf5 100%);
      border: 1px solid rgba(22, 101, 160, 0.2);
      box-shadow: 0 1px 2px rgba(20, 61, 89, 0.06);
    }
    .testbank-card__icon {
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 0.875rem;
      color: #fff;
      background: linear-gradient(145deg, #1e7cc8 0%, #1665A0 50%, #124a73 100%);
      box-shadow: 0 4px 12px rgba(22, 101, 160, 0.35);
      font-size: 1.125rem;
    }
    .testbank-card__title {
      margin: 0;
      font-size: 0.98rem;
      font-weight: 700;
      line-height: 1.4;
      color: #143D59;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .testbank-card__meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.5rem;
      margin-top: auto;
      padding-top: 0.875rem;
      border-top: 1px solid rgba(22, 101, 160, 0.1);
    }
    .testbank-card__hint {
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: rgba(20, 61, 89, 0.45);
    }
    .testbank-card__cta {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.8125rem;
      font-weight: 700;
      color: #1665A0;
    }
    .testbank-card:hover .testbank-card__cta { color: #0f4d7a; }
    .testbank-card:hover .testbank-card__cta i { transform: translateX(3px); }
    .testbank-card__cta i { font-size: 0.95rem; transition: transform 0.2s ease; }
    .testbank-list-wrap { padding: 0.75rem 1rem 1.25rem; }
    @media (min-width: 640px) {
      .testbank-list-wrap { padding: 1rem 1.5rem 1.5rem; }
    }
    .testbank-empty-state {
      text-align: center;
      padding: 2.5rem 1.5rem;
      border-radius: 1rem;
      border: 1px dashed rgba(22, 101, 160, 0.25);
      background: rgba(232, 242, 250, 0.45);
      color: rgba(20, 61, 89, 0.65);
      font-size: 0.9375rem;
      font-weight: 500;
      line-height: 1.5;
    }
    .testbank-empty-state i {
      display: block;
      font-size: 2.25rem;
      color: rgba(22, 101, 160, 0.35);
      margin-bottom: 0.75rem;
    }

    /* Quizzers cards/list switch (uniform with materials) */
    .quiz-cards-wrap { padding: 1rem 1rem 1.25rem; }
    @media (min-width: 640px) {
      .quiz-cards-wrap { padding: 1.25rem 1.5rem 1.5rem; }
    }
    .quiz-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr));
      gap: 1rem 1.125rem;
    }
    .quiz-card {
      position: relative;
      display: flex;
      flex-direction: column;
      min-height: 100%;
      padding: 1rem 1rem 1.1rem;
      border-radius: 1rem;
      text-decoration: none;
      color: inherit;
      background: linear-gradient(165deg, #ffffff 0%, #f5f9fd 55%, #eef5fb 100%);
      border: 1px solid rgba(22, 101, 160, 0.14);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.95) inset,
        0 4px 16px rgba(20, 61, 89, 0.07),
        0 1px 3px rgba(20, 61, 89, 0.05);
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
      overflow: hidden;
    }
    .quiz-card::before {
      content: "";
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      background: linear-gradient(90deg, #1665A0, #3393ff, #1665A0);
      opacity: 0.85;
      transform: scaleX(0.35);
      transform-origin: left;
      transition: transform 0.28s ease;
    }
    .quiz-card:hover {
      transform: translateY(-3px);
      border-color: rgba(22, 101, 160, 0.32);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 1) inset,
        0 12px 28px rgba(22, 101, 160, 0.14),
        0 4px 12px rgba(20, 61, 89, 0.08);
    }
    .quiz-card:hover::before { transform: scaleX(1); }
    .quiz-card__head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.75rem;
      margin-bottom: 0.55rem;
    }
    .quiz-card__title {
      margin: 0;
      font-size: 0.98rem;
      font-weight: 700;
      line-height: 1.4;
      color: #143D59;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .quiz-card__meta {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.5rem;
      margin-top: 0.65rem;
      margin-bottom: 0.8rem;
    }
    .quiz-card__chip {
      border-radius: 0.625rem;
      padding: 0.4rem 0.45rem;
      border: 1px solid rgba(22, 101, 160, 0.14);
      background: rgba(255, 255, 255, 0.75);
      text-align: center;
    }
    .quiz-card__k {
      display: block;
      font-size: 0.62rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: rgba(20, 61, 89, 0.45);
      margin-bottom: 0.15rem;
    }
    .quiz-card__v {
      display: block;
      font-size: 0.82rem;
      font-weight: 700;
      color: #143D59;
      line-height: 1.2;
    }
    .quiz-card__actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: flex-end;
      gap: 0.45rem;
      margin-top: auto;
      padding-top: 0.8rem;
      border-top: 1px solid rgba(22, 101, 160, 0.1);
    }
    .quiz-card-empty {
      grid-column: 1 / -1;
      text-align: center;
      padding: 2.5rem 1.5rem;
      border-radius: 1rem;
      border: 1px dashed rgba(22, 101, 160, 0.25);
      background: rgba(232, 242, 250, 0.45);
      color: rgba(20, 61, 89, 0.65);
      font-size: 0.9375rem;
      font-weight: 500;
      line-height: 1.5;
    }
    .quiz-card-empty i {
      display: block;
      font-size: 2.25rem;
      color: rgba(22, 101, 160, 0.35);
      margin-bottom: 0.75rem;
    }
</style>
<style>
  .student-shell-page { background: linear-gradient(180deg, #eef5fc 0%, #e4f0fa 45%, #ebf4fc 100%); min-height: 100%; }
  .student-hero {
    border-radius: 0.75rem;
    border: 1px solid rgba(255,255,255,0.28);
    background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
    box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22);
  }
  .hero-strip {
    background: rgba(255,255,255,0.14);
    border: 1px solid rgba(255,255,255,0.24);
    border-radius: .62rem;
  }
  .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
  .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; }
  @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
  .student-subject-page .rounded-2xl { border-radius: 0.75rem !important; }
  .student-subject-page .rounded-xl { border-radius: 0.625rem !important; }
  .student-subject-page .rounded-lg { border-radius: 0.5rem !important; }
  /* Light content protection – non-intrusive */
  .student-protected {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
  }
  .student-protected ::selection {
    background: transparent;
  }

    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

    /* Materials: search + sort toolbar */
    .materials-toolbar {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      padding: 0.75rem 1rem 1rem;
      border-top: 1px solid rgba(22, 101, 160, 0.08);
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.65) 0%, rgba(240, 247, 252, 0.5) 100%);
    }
    @media (min-width: 640px) {
      .materials-toolbar {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.875rem 1.5rem 1.125rem;
      }
    }
    .materials-search-wrap {
      position: relative;
      flex: 1;
      min-width: 0;
      max-width: 100%;
    }
    @media (min-width: 640px) {
      .materials-search-wrap {
        max-width: 22rem;
      }
    }
    .materials-search-wrap .materials-search-icon {
      position: absolute;
      left: 0.85rem;
      top: 50%;
      transform: translateY(-50%);
      color: rgba(20, 61, 89, 0.38);
      font-size: 1rem;
      pointer-events: none;
    }
    .materials-search-input {
      width: 100%;
      padding: 0.55rem 2.5rem 0.55rem 2.45rem;
      border-radius: 0.75rem;
      border: 1px solid rgba(22, 101, 160, 0.2);
      background: #fff;
      font-size: 0.9375rem;
      color: #143D59;
      box-shadow: 0 1px 2px rgba(20, 61, 89, 0.04);
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }
    .materials-search-input::placeholder {
      color: rgba(20, 61, 89, 0.42);
    }
    .materials-search-input:hover {
      border-color: rgba(22, 101, 160, 0.32);
    }
    .materials-search-input:focus {
      outline: none;
      border-color: rgba(22, 101, 160, 0.55);
      box-shadow: 0 0 0 3px rgba(22, 101, 160, 0.14);
    }
    .materials-search-clear {
      position: absolute;
      right: 0.4rem;
      top: 50%;
      transform: translateY(-50%);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 1.85rem;
      height: 1.85rem;
      border: none;
      border-radius: 0.5rem;
      background: rgba(20, 61, 89, 0.06);
      color: rgba(20, 61, 89, 0.55);
      cursor: pointer;
      transition: background 0.15s ease, color 0.15s ease;
    }
    .materials-search-clear:hover {
      background: rgba(22, 101, 160, 0.12);
      color: #1665A0;
    }
    .materials-sort-group {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-shrink: 0;
    }
    .materials-sort-group__label {
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: rgba(20, 61, 89, 0.45);
      white-space: nowrap;
    }
    .materials-sort-select {
      min-width: 10.5rem;
      padding: 0.5rem 2rem 0.5rem 0.65rem;
      border-radius: 0.75rem;
      border: 1px solid rgba(22, 101, 160, 0.2);
      background-color: #fff;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23143D59' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 0.6rem center;
      background-size: 0.7rem;
      font-size: 0.875rem;
      font-weight: 600;
      color: #143D59;
      cursor: pointer;
      appearance: none;
      -webkit-appearance: none;
      box-shadow: 0 1px 2px rgba(20, 61, 89, 0.05);
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .materials-sort-select:hover {
      border-color: rgba(22, 101, 160, 0.35);
    }
    .materials-sort-select:focus {
      outline: none;
      border-color: rgba(22, 101, 160, 0.55);
      box-shadow: 0 0 0 3px rgba(22, 101, 160, 0.14);
    }
    .materials-results-hint {
      font-size: 0.75rem;
      color: rgba(20, 61, 89, 0.5);
      padding: 0 1rem 0.5rem;
      margin: 0;
    }
    @media (min-width: 640px) {
      .materials-results-hint {
        padding-left: 1.5rem;
        padding-right: 1.5rem;
      }
    }
    .lesson-no-match {
      margin: 0 1rem 1.25rem;
      padding: 1.75rem 1.25rem;
      text-align: center;
      border-radius: 0.875rem;
      border: 1px dashed rgba(22, 101, 160, 0.25);
      background: rgba(232, 242, 250, 0.45);
      color: rgba(20, 61, 89, 0.75);
      font-size: 0.9375rem;
      font-weight: 600;
    }
    .lesson-no-match i {
      display: block;
      font-size: 1.75rem;
      margin-bottom: 0.5rem;
      opacity: 0.55;
      color: #1665A0;
    }
    @media (min-width: 640px) {
      .lesson-no-match {
        margin-left: 1.5rem;
        margin-right: 1.5rem;
      }
    }
</style>
<script>
window.__EREVIEW_SUBJECT_PAGE__ = <?php echo json_encode(['lessons' => $lessonsForAlpine], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script>
document.addEventListener('alpine:init', function() {
  Alpine.data('subjectPage', function() {
    return {
      activeTab: 'materials',
      materialsView: 'cards',
      materialsLessons: [],
      materialsSearch: '',
      materialsSort: 'lesson_asc',
      quizzersView: 'list',
      testBankView: 'cards',
      get materialsFiltered() {
        var list = this.materialsLessons.slice();
        var q = (this.materialsSearch || '').trim().toLowerCase();
        if (q) {
          list = list.filter(function(l) {
            var t = (l.title || '').toLowerCase();
            var d = (l.desc || '').toLowerCase();
            return t.indexOf(q) !== -1 || d.indexOf(q) !== -1;
          });
        }
        var sort = this.materialsSort;
        if (sort === 'title_asc') {
          list.sort(function(a, b) {
            return (a.title || '').localeCompare(b.title || '', undefined, { sensitivity: 'base' });
          });
        } else if (sort === 'title_desc') {
          list.sort(function(a, b) {
            return (b.title || '').localeCompare(a.title || '', undefined, { sensitivity: 'base' });
          });
        } else if (sort === 'lesson_desc') {
          list.sort(function(a, b) { return b.id - a.id; });
        } else {
          list.sort(function(a, b) { return a.id - b.id; });
        }
        return list;
      },
      init: function() {
        try {
          if (window.__EREVIEW_SUBJECT_PAGE__ && Array.isArray(window.__EREVIEW_SUBJECT_PAGE__.lessons)) {
            this.materialsLessons = window.__EREVIEW_SUBJECT_PAGE__.lessons.slice();
          }
        } catch (e) {}
        var h = window.location.hash;
        if (h === '#quizzers') this.activeTab = 'quizzers';
        else if (h === '#materials') this.activeTab = 'materials';
        else if (h === '#testbank') this.activeTab = 'testbank';
        try {
          var v = localStorage.getItem('ereview_materials_view');
          if (v === 'list' || v === 'cards') this.materialsView = v;
        } catch (e) {}
        try {
          var qv = localStorage.getItem('ereview_quizzers_view');
          if (qv === 'list' || qv === 'cards') this.quizzersView = qv;
        } catch (e) {}
        try {
          var tv = localStorage.getItem('ereview_testbank_view');
          if (tv === 'list' || tv === 'cards') this.testBankView = tv;
        } catch (e) {}
        try {
          var ms = localStorage.getItem('ereview_materials_sort');
          if (ms === 'lesson_asc' || ms === 'lesson_desc' || ms === 'title_asc' || ms === 'title_desc') {
            this.materialsSort = ms;
          }
        } catch (e) {}
      }
    };
  });
});
</script>
</head>
<body class="font-sans antialiased student-protected student-subject-page student-shell-page" x-data="subjectPage">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="subject-back-row dash-anim delay-1">
    <a href="student_subjects.php" class="btn-back-subjects">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Subjects
    </a>
  </div>

  <section class="mb-4 sm:mb-5 dash-anim delay-1">
    <div class="student-hero px-4 sm:px-6 py-4 sm:py-5 text-white flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0 flex-1">
        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md">
          <i class="bi bi-book text-xl" aria-hidden="true"></i>
        </span>
        <div class="min-w-0">
          <h1 class="text-xl sm:text-2xl font-bold m-0 tracking-tight truncate"><?php echo h($subject['subject_name']); ?></h1>
          <p class="text-sm sm:text-base text-white/90 mt-1 mb-0 break-words"><?php echo h($subject['description'] ?? ''); ?></p>
        </div>
      </div>
      <div class="text-xs sm:text-sm text-white/80 flex flex-col items-start sm:items-end gap-1 shrink-0 hero-strip px-3 py-2">
        <span class="uppercase tracking-[0.16em] text-white/60 font-semibold">Subject</span>
        <span class="text-white/90"><?php echo h($subject['subject_code'] ?? 'Subject details'); ?></span>
      </div>
    </div>
  </section>

  <!-- Tabs (active tab persisted via URL hash so refresh keeps same tab) -->
  <nav class="flex flex-wrap gap-2 mb-4 dash-anim delay-2" role="tablist">
    <button type="button" @click="activeTab = 'materials'; window.location.hash = '#materials'" :class="activeTab === 'materials' ? 'bg-[#1665A0] text-white border-[#1665A0]' : 'bg-[#e8f2fa]/80 text-[#143D59] border-[#1665A0]/20 hover:bg-[#e8f2fa]'" class="subject-tab-btn px-3 py-2 sm:px-4 rounded-lg text-sm sm:text-base font-medium border-2 inline-flex items-center gap-1.5 sm:gap-2 min-h-[44px]"><i class="bi bi-collection-play shrink-0"></i> <span class="sm:hidden">Materials</span><span class="hidden sm:inline">Materials (Videos + Handouts)</span></button>
    <button type="button" @click="activeTab = 'quizzers'; window.location.hash = '#quizzers'" :class="activeTab === 'quizzers' ? 'bg-[#1665A0] text-white border-[#1665A0]' : 'bg-[#e8f2fa]/80 text-[#143D59] border-[#1665A0]/20 hover:bg-[#e8f2fa]'" class="subject-tab-btn px-3 py-2 sm:px-4 rounded-lg text-sm sm:text-base font-medium border-2 inline-flex items-center gap-1.5 sm:gap-2 min-h-[44px]"><i class="bi bi-question-circle shrink-0"></i> Quizzers</button>
    <button type="button" @click="activeTab = 'testbank'; window.location.hash = '#testbank'" :class="activeTab === 'testbank' ? 'bg-[#1665A0] text-white border-[#1665A0]' : 'bg-[#e8f2fa]/80 text-[#143D59] border-[#1665A0]/20 hover:bg-[#e8f2fa]'" class="subject-tab-btn px-3 py-2 sm:px-4 rounded-lg text-sm sm:text-base font-medium border-2 inline-flex items-center gap-1.5 sm:gap-2 min-h-[44px]"><i class="bi bi-folder2-open shrink-0"></i> Test Bank</button>
  </nav>

  <!-- Tab: Materials (lessons — card or list view; click to open viewer) -->
  <div x-show="activeTab === 'materials'" x-cloak class="materials-section dash-anim delay-2 rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1),0_4px_16px_rgba(20,61,89,0.06)] overflow-hidden mb-5 bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0]">
    <div class="px-4 sm:px-6 py-4 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex items-center gap-3 min-w-0">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#1665A0] text-white shadow-lg shadow-[#1665A0]/25">
          <i class="bi bi-play-circle-fill text-lg" aria-hidden="true"></i>
        </span>
        <div class="min-w-0">
          <h2 class="text-lg font-bold text-[#143D59] m-0">Materials</h2>
          <p class="text-sm text-[#143D59]/70 mt-0.5 mb-0">Lessons, videos, and handouts. Click a lesson to view.</p>
        </div>
      </div>
      <?php if (!empty($lessonsRows)): ?>
      <div class="materials-view-toggle" role="group" aria-label="Lesson layout">
        <span class="materials-view-toggle__label">View</span>
        <div class="materials-view-seg">
          <button type="button"
            @click="materialsView = 'cards'; try { localStorage.setItem('ereview_materials_view', 'cards'); } catch (e) {}"
            :class="materialsView === 'cards' ? 'is-active' : ''"
            :aria-pressed="materialsView === 'cards'"
            aria-label="Card view">
            <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span class="hidden sm:inline">Cards</span>
          </button>
          <button type="button"
            @click="materialsView = 'list'; try { localStorage.setItem('ereview_materials_view', 'list'); } catch (e) {}"
            :class="materialsView === 'list' ? 'is-active' : ''"
            :aria-pressed="materialsView === 'list'"
            aria-label="List view">
            <i class="bi bi-list-ul" aria-hidden="true"></i><span class="hidden sm:inline">List</span>
          </button>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php if (empty($lessonsRows)): ?>
      <div class="lesson-cards-wrap">
        <div class="lesson-empty-state" role="status">
          <i class="bi bi-inbox" aria-hidden="true"></i>
          No lessons yet. Your instructor may add lessons and materials later.
        </div>
      </div>
    <?php else: ?>
      <div class="materials-toolbar" role="search">
        <div class="materials-search-wrap">
          <i class="bi bi-search materials-search-icon" aria-hidden="true"></i>
          <label for="materials-search-input" class="sr-only">Search lessons</label>
          <input
            id="materials-search-input"
            type="search"
            class="materials-search-input"
            placeholder="Search by lesson title…"
            autocomplete="off"
            x-model.debounce.300ms="materialsSearch"
          >
          <button
            type="button"
            class="materials-search-clear"
            x-show="(materialsSearch || '').trim().length > 0"
            x-cloak
            @click="materialsSearch = ''"
            aria-label="Clear search"
          >
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </div>
        <div class="materials-sort-group">
          <span class="materials-sort-group__label" id="materials-sort-lbl">Sort</span>
          <select
            id="materials-sort-select"
            class="materials-sort-select"
            aria-labelledby="materials-sort-lbl"
            x-model="materialsSort"
            @change="try { localStorage.setItem('ereview_materials_sort', materialsSort); } catch (e) {}"
          >
            <option value="lesson_asc">Oldest first</option>
            <option value="lesson_desc">Newest first</option>
            <option value="title_asc">Title A–Z</option>
            <option value="title_desc">Title Z–A</option>
          </select>
        </div>
      </div>
      <p class="materials-results-hint" x-show="materialsLessons.length > 0" x-cloak>
        <span x-show="(materialsSearch || '').trim().length > 0">
          Showing <strong x-text="materialsFiltered.length"></strong> of <strong x-text="materialsLessons.length"></strong> lesson<span x-show="materialsLessons.length !== 1">s</span>
        </span>
        <span x-show="(materialsSearch || '').trim().length === 0" class="text-[#143D59]/60">
          <strong x-text="materialsLessons.length"></strong> lesson<span x-show="materialsLessons.length !== 1">s</span>
          <span class="text-[#143D59]/45"> · Search or sort to find what you need.</span>
        </span>
      </p>
      <div x-show="materialsView === 'cards'" x-cloak class="lesson-cards-wrap">
        <div class="lesson-cards-grid" role="list">
          <template x-for="(lesson, idx) in materialsFiltered" :key="lesson.id">
            <a :href="lesson.href" class="lesson-card" role="listitem">
              <div class="lesson-card__top">
                <span class="lesson-card__badge" aria-hidden="true" x-text="idx + 1"></span>
                <span class="lesson-card__icon" aria-hidden="true"><i class="bi bi-play-fill"></i></span>
              </div>
              <h3 class="lesson-card__title" x-text="lesson.title"></h3>
              <div class="lesson-card__meta">
                <span class="lesson-card__hint">Lesson</span>
                <span class="lesson-card__cta">Open <i class="bi bi-arrow-right-short" aria-hidden="true"></i></span>
              </div>
            </a>
          </template>
        </div>
      </div>
      <div x-show="materialsView === 'list'" x-cloak class="lesson-list-wrap">
        <ul class="lesson-list" role="list">
          <template x-for="(lesson, idx) in materialsFiltered" :key="lesson.id">
            <li class="lesson-list__item" role="listitem">
              <a :href="lesson.href" class="lesson-list__link">
                <span class="lesson-list__idx" aria-hidden="true" x-text="idx + 1"></span>
                <div class="lesson-list__body">
                  <h3 class="lesson-list__title" x-text="lesson.title"></h3>
                  <span class="lesson-list__sub">Lesson · Videos &amp; handouts</span>
                </div>
                <span class="lesson-list__action">
                  <span class="lesson-list__pill">Open <i class="bi bi-arrow-right-short" aria-hidden="true"></i></span>
                </span>
              </a>
            </li>
          </template>
        </ul>
      </div>
      <div class="lesson-no-match" role="status" x-show="materialsFiltered.length === 0 && materialsLessons.length > 0" x-cloak>
        <i class="bi bi-search" aria-hidden="true"></i>
        No lessons match your search. Try different keywords or clear the search box.
      </div>
    <?php endif; ?>
  </div>

  <!-- Tab: Quizzers (dashboard style with summary + table) -->
  <div x-show="activeTab === 'quizzers'" x-cloak
       x-transition:enter="tab-panel-enter-active"
       x-transition:enter-start="tab-panel-enter"
       x-transition:enter-end="tab-panel-enter-to"
       x-transition:leave="tab-panel-leave-active"
       x-transition:leave-start="tab-panel-leave"
       x-transition:leave-end="tab-panel-leave-to"
       class="quizzers-section dash-anim delay-2 rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1),0_4px_16px_rgba(20,61,89,0.06)] overflow-hidden mb-5 bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0]">
    <div class="quizzers-header flex-col sm:flex-row sm:items-center sm:justify-between">
      <div class="flex items-center gap-3 min-w-0 flex-1">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#1665A0] text-white shadow-lg shadow-[#1665A0]/25">
          <i class="bi bi-question-circle-fill text-lg" aria-hidden="true"></i>
        </span>
        <div class="min-w-0 flex-1">
          <h2 class="quizzers-title">Quizzers</h2>
          <p class="quizzers-subtitle">Take or review your quizzes. Passing score is 50%.</p>
        </div>
      </div>
      <?php if (!empty($quizRows)): ?>
      <div class="materials-view-toggle" role="group" aria-label="Quizzers layout">
        <span class="materials-view-toggle__label">View</span>
        <div class="materials-view-seg">
          <button type="button"
            @click="quizzersView = 'cards'; try { localStorage.setItem('ereview_quizzers_view', 'cards'); } catch (e) {}"
            :class="quizzersView === 'cards' ? 'is-active' : ''"
            :aria-pressed="quizzersView === 'cards'"
            aria-label="Card view">
            <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span class="hidden sm:inline">Cards</span>
          </button>
          <button type="button"
            @click="quizzersView = 'list'; try { localStorage.setItem('ereview_quizzers_view', 'list'); } catch (e) {}"
            :class="quizzersView === 'list' ? 'is-active' : ''"
            :aria-pressed="quizzersView === 'list'"
            aria-label="List view">
            <i class="bi bi-list-ul" aria-hidden="true"></i><span class="hidden sm:inline">List</span>
          </button>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($totalQuizzes > 0): ?>
    <div class="quiz-summary-grid px-4 sm:px-6 pt-4 pb-2">
      <div class="quiz-summary-card">
        <span class="summary-value"><?php echo $totalQuizzes; ?></span>
        <span class="summary-label">Total Quizzes</span>
      </div>
      <div class="quiz-summary-card summary-passed">
        <span class="summary-value"><?php echo $quizzesPassed; ?></span>
        <span class="summary-label">Passed</span>
      </div>
      <div class="quiz-summary-card summary-failed">
        <span class="summary-value"><?php echo $quizzesFailed; ?></span>
        <span class="summary-label">Failed</span>
      </div>
      <div class="quiz-summary-card summary-avg">
        <span class="summary-value"><?php echo $averageScore !== null ? $averageScore . '%' : '—'; ?></span>
        <span class="summary-label">Average Score</span>
      </div>
    </div>
    <?php endif; ?>
    <!-- Filters: status pills + search by title -->
    <div class="px-4 pb-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 sm:justify-between">
      <div class="quiz-filter-pills w-full min-w-0 flex flex-wrap gap-1.5 text-xs sm:text-sm">
        <button type="button" class="px-2.5 sm:px-3 py-1.5 rounded-full border text-gray-700 bg-white shadow-sm text-[0.78rem] sm:text-xs quiz-status-filter-pill is-active shrink-0" data-status="">
          All
        </button>
        <button type="button" class="px-2.5 sm:px-3 py-1.5 rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 shadow-sm text-[0.78rem] sm:text-xs quiz-status-filter-pill shrink-0" data-status="passed">
          Passed
        </button>
        <button type="button" class="px-2.5 sm:px-3 py-1.5 rounded-full border border-rose-200 bg-rose-50 text-rose-700 shadow-sm text-[0.78rem] sm:text-xs quiz-status-filter-pill shrink-0" data-status="need_retake">
          Failed
        </button>
        <button type="button" class="px-2.5 sm:px-3 py-1.5 rounded-full border border-amber-200 bg-amber-50 text-amber-700 shadow-sm text-[0.78rem] sm:text-xs quiz-status-filter-pill shrink-0" data-status="in_progress">
          In Progress
        </button>
        <button type="button" class="px-2.5 sm:px-3 py-1.5 rounded-full border border-slate-200 bg-slate-50 text-slate-600 shadow-sm text-[0.78rem] sm:text-xs quiz-status-filter-pill shrink-0" data-status="not_taken">
          Not Started
        </button>
      </div>
      <div class="relative w-full sm:max-w-xs">
        <input
          id="quizTitleFilter"
          type="text"
          class="w-full rounded-full border border-gray-300 bg-white/80 px-8 py-1.5 text-sm text-gray-700 placeholder-gray-400 shadow-sm focus:outline-none focus:ring-2 focus:ring-[#3393FF]/60 focus:border-[#3393FF]/70"
          placeholder="Filter quizzes by title..."
        >
        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
      </div>
    </div>
    <div x-show="quizzersView === 'list'" x-cloak class="quiz-table-wrap overflow-x-auto -mx-2 sm:mx-0 px-2 sm:px-0">
      <table class="quiz-table" style="min-width: 860px;">
        <thead>
          <tr>
            <th style="min-width: 180px;">Quiz Title</th>
            <th class="w-24" style="min-width: 5rem;">Duration</th>
            <th style="min-width: 110px;">Last Attempt</th>
            <th class="w-28" style="min-width: 5.5rem;">Status</th>
            <th class="w-24" style="min-width: 4rem;">Attempts</th>
            <th style="min-width: 180px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($quizRows as $qr): ?>
            <tr class="quiz-table-row" data-status="<?php echo h($qr['row_status']); ?>" data-title="<?php echo h(strtolower($qr['title'])); ?>">
              <td class="break-words" style="min-width: 220px;"><?php echo h($qr['title']); ?></td>
              <td><?php echo h($qr['duration']); ?></td>
              <td>
                <?php if (!empty($qr['last_attempt_date'])): ?>
                  <div class="quiz-last-score-cell">
                    <?php if ($qr['score_pct'] !== null): ?>
                      <span class="quiz-score-badge-lg <?php echo !empty($qr['passed']) ? 'pass' : 'fail'; ?>"><?php echo (int)$qr['score_pct']; ?>%</span>
                    <?php endif; ?>
                    <span class="text-xs text-gray-500 whitespace-nowrap"><?php echo date('M j, Y', strtotime((string)$qr['last_attempt_date'])); ?></span>
                  </div>
                <?php else: ?>
                  <span class="text-gray-400">—</span>
                <?php endif; ?>
              </td>
              <td><span class="quiz-status-badge <?php echo h($qr['status_badge_class']); ?>"><?php echo h($qr['status_label']); ?></span></td>
              <td><?php echo (int)$qr['attempt_count']; ?></td>
              <td>
                <div class="flex flex-nowrap justify-center items-center gap-2">
                <?php if ((int)$qr['count'] <= 0): ?>
                  <span class="text-gray-400 text-xs">No questions yet</span>
                <?php elseif (!empty($qr['in_progress_attempt_id'])): ?>
                  <a href="<?php echo h($qr['take_url']); ?>&attempt_id=<?php echo (int)$qr['in_progress_attempt_id']; ?>" class="quiz-btn quiz-btn-amber quiz-resume-link shrink-0"><i class="bi bi-play-fill"></i> Resume</a>
                <?php else: ?>
                  <?php if ((int)$qr['attempt_count'] > 0): ?>
                    <a href="<?php echo h($qr['view_result_url']); ?>" class="quiz-btn quiz-btn-outline shrink-0"><i class="bi bi-search"></i> View Result</a>
                  <?php endif; ?>
                  <?php if (!empty($qr['passed'])): ?>
                    <a href="<?php echo h($qr['take_url']); ?>&retake=1" class="quiz-btn quiz-btn-primary quiz-take-again-btn shrink-0"><i class="bi bi-arrow-repeat"></i> Take Again</a>
                  <?php else: ?>
                    <a href="<?php echo h($qr['take_url']); ?>" class="quiz-btn quiz-btn-primary quiz-take-again-btn shrink-0"><i class="bi bi-play-fill"></i> <?php echo (int)$qr['attempt_count'] > 0 ? 'Take Again' : 'Take Quiz'; ?></a>
                  <?php endif; ?>
                <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($quizRows)): ?>
            <tr>
              <td colspan="6" class="quiz-empty-state">
                <i class="bi bi-inbox quiz-empty-icon"></i>
                <p class="quiz-empty-msg">No quizzes available yet.</p>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div x-show="quizzersView === 'cards'" x-cloak class="quiz-cards-wrap">
      <div class="quiz-cards-grid" role="list">
        <?php if (!empty($quizRows)): ?>
          <?php foreach ($quizRows as $qr): ?>
            <article class="quiz-card quiz-card-item" role="listitem" data-status="<?php echo h($qr['row_status']); ?>" data-title="<?php echo h(strtolower($qr['title'])); ?>">
              <div class="quiz-card__head">
                <h3 class="quiz-card__title"><?php echo h($qr['title']); ?></h3>
                <span class="quiz-status-badge <?php echo h($qr['status_badge_class']); ?>"><?php echo h($qr['status_label']); ?></span>
              </div>
              <div class="quiz-card__meta">
                <div class="quiz-card__chip">
                  <span class="quiz-card__k">Duration</span>
                  <span class="quiz-card__v"><?php echo h($qr['duration']); ?></span>
                </div>
                <div class="quiz-card__chip">
                  <span class="quiz-card__k">Attempts</span>
                  <span class="quiz-card__v"><?php echo (int)$qr['attempt_count']; ?></span>
                </div>
                <div class="quiz-card__chip">
                  <span class="quiz-card__k">Last Score</span>
                  <span class="quiz-card__v"><?php echo $qr['score_pct'] !== null ? ((int)$qr['score_pct'] . '%') : '—'; ?></span>
                </div>
              </div>
              <div class="quiz-card__actions">
                <?php if ((int)$qr['count'] <= 0): ?>
                  <span class="text-gray-400 text-xs">No questions yet</span>
                <?php elseif (!empty($qr['in_progress_attempt_id'])): ?>
                  <a href="<?php echo h($qr['take_url']); ?>&attempt_id=<?php echo (int)$qr['in_progress_attempt_id']; ?>" class="quiz-btn quiz-btn-amber quiz-resume-link"><i class="bi bi-play-fill"></i> Resume</a>
                <?php else: ?>
                  <?php if ((int)$qr['attempt_count'] > 0): ?>
                    <a href="<?php echo h($qr['view_result_url']); ?>" class="quiz-btn quiz-btn-outline"><i class="bi bi-search"></i> View Result</a>
                  <?php endif; ?>
                  <?php if (!empty($qr['passed'])): ?>
                    <a href="<?php echo h($qr['take_url']); ?>&retake=1" class="quiz-btn quiz-btn-primary quiz-take-again-btn"><i class="bi bi-arrow-repeat"></i> Take Again</a>
                  <?php else: ?>
                    <a href="<?php echo h($qr['take_url']); ?>" class="quiz-btn quiz-btn-primary quiz-take-again-btn"><i class="bi bi-play-fill"></i> <?php echo (int)$qr['attempt_count'] > 0 ? 'Take Again' : 'Take Quiz'; ?></a>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="quiz-card-empty">
            <i class="bi bi-inbox" aria-hidden="true"></i>
            No quizzes available yet.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Tab: Test Bank (card/list view; files shown only when entry is opened) -->
  <div x-show="activeTab === 'testbank'" x-cloak
       x-transition:enter="tab-panel-enter-active"
       x-transition:enter-start="tab-panel-enter"
       x-transition:enter-end="tab-panel-enter-to"
       x-transition:leave="tab-panel-leave-active"
       x-transition:leave-start="tab-panel-leave"
       x-transition:leave-end="tab-panel-leave-to"
       class="quizzers-section dash-anim delay-3 rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1),0_4px_16px_rgba(20,61,89,0.06)] overflow-hidden mb-5 bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0]">
    <div class="quizzers-header flex-col sm:flex-row sm:items-center sm:justify-between">
      <div class="flex items-center gap-3 min-w-0 flex-1">
      <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#1665A0] text-white shadow-lg shadow-[#1665A0]/25">
        <i class="bi bi-folder2-open text-lg" aria-hidden="true"></i>
      </span>
      <div class="min-w-0 flex-1">
        <h2 class="quizzers-title">Test Bank</h2>
        <p class="quizzers-subtitle">Practice questions and answer sets. Click an entry to view (full-page, view only).</p>
      </div>
      </div>
      <?php
        $testBankRows = [];
        $testBankList = @mysqli_query(
          $conn,
          "SELECT id, title FROM test_bank WHERE subject_id=" . (int)$subjectId . " ORDER BY id DESC"
        );
        if ($testBankList) {
          while ($row = mysqli_fetch_assoc($testBankList)) {
            $testBankRows[] = $row;
          }
        }
      ?>
      <?php if (!empty($testBankRows)): ?>
      <div class="materials-view-toggle" role="group" aria-label="Test bank layout">
        <span class="materials-view-toggle__label">View</span>
        <div class="materials-view-seg">
          <button type="button"
            @click="testBankView = 'cards'; try { localStorage.setItem('ereview_testbank_view', 'cards'); } catch (e) {}"
            :class="testBankView === 'cards' ? 'is-active' : ''"
            :aria-pressed="testBankView === 'cards'"
            aria-label="Card view">
            <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span class="hidden sm:inline">Cards</span>
          </button>
          <button type="button"
            @click="testBankView = 'list'; try { localStorage.setItem('ereview_testbank_view', 'list'); } catch (e) {}"
            :class="testBankView === 'list' ? 'is-active' : ''"
            :aria-pressed="testBankView === 'list'"
            aria-label="List view">
            <i class="bi bi-list-ul" aria-hidden="true"></i><span class="hidden sm:inline">List</span>
          </button>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php if (!empty($testBankRows)): ?>
    <div x-show="testBankView === 'cards'" x-cloak class="testbank-wrap">
      <div class="testbank-grid" role="list">
        <?php
          $tbOrdinal = 0;
          foreach ($testBankRows as $tb):
            $tbOrdinal++;
            $tbId = (int)$tb['id'];
            $tbTitle = h($tb['title']);
        ?>
          <a href="student_test_bank_viewer.php?id=<?php echo $tbId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="testbank-card" role="listitem">
            <div class="testbank-card__top">
              <span class="testbank-card__badge" aria-hidden="true"><?php echo (int)$tbOrdinal; ?></span>
              <span class="testbank-card__icon" aria-hidden="true"><i class="bi bi-folder2-open"></i></span>
            </div>
            <h3 class="testbank-card__title"><?php echo $tbTitle; ?></h3>
            <div class="testbank-card__meta">
              <span class="testbank-card__hint">Test Bank</span>
              <span class="testbank-card__cta">Open <i class="bi bi-arrow-right-short" aria-hidden="true"></i></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div x-show="testBankView === 'list'" x-cloak class="testbank-list-wrap">
      <ul class="lesson-list" role="list">
        <?php
          $tbOrdinal = 0;
          foreach ($testBankRows as $tb):
            $tbOrdinal++;
            $tbId = (int)$tb['id'];
            $tbTitle = h($tb['title']);
        ?>
        <li class="lesson-list__item" role="listitem">
          <a href="student_test_bank_viewer.php?id=<?php echo $tbId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="lesson-list__link">
            <span class="lesson-list__idx" aria-hidden="true"><?php echo (int)$tbOrdinal; ?></span>
            <div class="lesson-list__body">
              <h3 class="lesson-list__title"><?php echo $tbTitle; ?></h3>
              <span class="lesson-list__sub">Test bank · practice set</span>
            </div>
            <span class="lesson-list__action">
              <span class="lesson-list__pill">Open <i class="bi bi-arrow-right-short" aria-hidden="true"></i></span>
            </span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php else: ?>
      <div class="testbank-wrap">
        <div class="testbank-empty-state">
          <i class="bi bi-folder2-open" aria-hidden="true"></i>
          No test bank materials yet. Your instructor may add practice questions and answers later.
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- App modal: Resume quiz (dark gradient format) -->
  <div class="quiz-confirm-overlay" id="quizResumeConfirmOverlay" role="dialog" aria-modal="true" aria-labelledby="quizResumeConfirmTitle" aria-describedby="quizResumeConfirmMessage">
    <div class="quiz-confirm-card">
      <div class="quiz-confirm-icon-wrap info">
        <i class="bi bi-play-circle-fill"></i>
      </div>
      <div class="quiz-confirm-header" id="quizResumeConfirmTitle">Resume quiz</div>
      <div class="quiz-confirm-body" id="quizResumeConfirmMessage">
        You're resuming your quiz. Your progress is saved.<br><br>Continue to quiz?
      </div>
      <div class="quiz-confirm-actions">
        <button type="button" class="quiz-confirm-btn quiz-confirm-btn-cancel" id="quizResumeConfirmCancel">Cancel</button>
        <button type="button" class="quiz-confirm-btn quiz-confirm-btn-primary" id="quizResumeConfirmContinue">Continue</button>
      </div>
    </div>
  </div>

  <!-- Handout Viewer Modal (Alpine store driven from openHandoutViewer()) -->
  <div x-show="$store.handoutViewer && $store.handoutViewer.open" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="$store.handoutViewer && ($store.handoutViewer.open = false)">
    <div class="absolute inset-0 bg-black/50" @click="$store.handoutViewer && ($store.handoutViewer.open = false)"></div>
    <div class="relative bg-white rounded-xl shadow-modal w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col" @click.stop>
      <div class="p-4 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-xl font-bold text-gray-800 m-0" x-text="($store.handoutViewer && $store.handoutViewer.title) || 'Handout'"></h2>
        <button type="button" @click="$store.handoutViewer && ($store.handoutViewer.open = false, $store.handoutViewer.id = '')" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="flex-1 min-h-0 relative" style="aspect-ratio: 4/3;">
        <iframe x-show="$store.handoutViewer && $store.handoutViewer.id" :src="($store.handoutViewer && $store.handoutViewer.id) ? ('handout_viewer.php?handout_id=' + $store.handoutViewer.id) : ''" class="absolute inset-0 w-full h-full border-0 rounded-b-xl" allowfullscreen></iframe>
      </div>
    </div>
  </div>

  <script>
  // Global student-side protection: disable right-click and common shortcuts (except in inputs)
  (function() {
    function isInputLike(el) {
      if (!el) return false;
      var tag = (el.tagName || '').toLowerCase();
      var type = (el.type || '').toLowerCase();
      return tag === 'input' || tag === 'textarea' || el.isContentEditable || type === 'text' || type === 'password';
    }
    document.addEventListener('contextmenu', function(e) {
      if (!isInputLike(e.target)) e.preventDefault();
    });
    document.addEventListener('selectstart', function(e) {
      if (!isInputLike(e.target)) e.preventDefault();
    });
    window.addEventListener('keydown', function(e) {
      var ctrlLike = e.ctrlKey || e.metaKey;
      var key = (e.key || '').toLowerCase();
      if (ctrlLike && ['c','x','s','p','u','a'].indexOf(key) !== -1 && !isInputLike(e.target)) {
        e.preventDefault();
      }
    }, true);
  })();

    document.addEventListener('alpine:init', function() {
      Alpine.store('handoutViewer', { open: false, id: '', title: '' });
    });
    window.openHandoutViewer = function(handoutId, title) {
      if (!handoutId) return;
      if (typeof Alpine !== 'undefined' && Alpine.store && Alpine.store('handoutViewer')) {
        Alpine.store('handoutViewer').open = true;
        Alpine.store('handoutViewer').id = String(handoutId);
        Alpine.store('handoutViewer').title = title || 'Handout Preview';
      }
    };

  </script>
  <script>
  // Client-side filtering for Quizzers table by quiz title + status
  (function() {
    var input = document.getElementById('quizTitleFilter');
    var pills = document.querySelectorAll('.quiz-status-filter-pill');
    var activeStatus = '';
    if (!input && !pills.length) return;

    function applyFilters() {
      var term = input ? (input.value || '').toLowerCase().trim() : '';
      var rows = document.querySelectorAll('.quiz-table tbody tr.quiz-table-row');
      rows.forEach(function(row) {
        var text = (row.getAttribute('data-title') || '').toLowerCase();
        var rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
        var matchesTitle = !term || text.indexOf(term) !== -1;
        var matchesStatus = !activeStatus || rowStatus === activeStatus;
        row.style.display = (matchesTitle && matchesStatus) ? '' : 'none';
      });
      var cards = document.querySelectorAll('.quiz-card-item');
      cards.forEach(function(card) {
        var text = (card.getAttribute('data-title') || '').toLowerCase();
        var rowStatus = (card.getAttribute('data-status') || '').toLowerCase();
        var matchesTitle = !term || text.indexOf(term) !== -1;
        var matchesStatus = !activeStatus || rowStatus === activeStatus;
        card.style.display = (matchesTitle && matchesStatus) ? '' : 'none';
      });
    }

    if (input) {
      input.addEventListener('input', applyFilters);
    }
    pills.forEach(function(btn) {
      btn.addEventListener('click', function() {
        pills.forEach(function(p) { p.classList.remove('is-active'); });
        btn.classList.add('is-active');
        activeStatus = (btn.getAttribute('data-status') || '').toLowerCase();
        applyFilters();
      });
    });
  })();
  </script>
  <script>
  (function() {
    var overlay = document.getElementById('quizResumeConfirmOverlay');
    var cancelBtn = document.getElementById('quizResumeConfirmCancel');
    var continueBtn = document.getElementById('quizResumeConfirmContinue');
    var resumeUrl = '';
    function hide() {
      if (overlay) overlay.classList.remove('show');
    }
    function show(url) {
      resumeUrl = url || '';
      if (overlay) overlay.classList.add('show');
    }
    document.addEventListener('click', function(e) {
      var link = e.target.closest('a.quiz-resume-link');
      if (!link || !link.href) return;
      e.preventDefault();
      show(link.href);
    });
    if (cancelBtn) cancelBtn.addEventListener('click', hide);
    if (continueBtn) continueBtn.addEventListener('click', function() {
      hide();
      if (resumeUrl) window.location.href = resumeUrl;
    });
    if (overlay) overlay.addEventListener('click', function(e) {
      if (e.target === overlay) hide();
    });
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && overlay && overlay.classList.contains('show')) hide();
    });
  })();
  </script>
</main>
</div>
</body>
</html>
