<?php
/**
 * Take Quiz - Exam-style: one question per page, server-side timer, resume, retake.
 * Security: One in-progress attempt per user per quiz; timer and answers stored server-side.
 */
require_once 'auth.php';
require_once __DIR__ . '/includes/quiz_helpers.php';
requireRole('student');

$quizId = sanitizeInt($_GET['quiz_id'] ?? 0);
$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($quizId <= 0) { header('Location: student_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT q.*, s.subject_name FROM quizzes q JOIN subjects s ON s.subject_id=q.subject_id WHERE q.quiz_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $quizId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$quiz = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$quiz) { header('Location: student_subjects.php'); exit; }

if (!$subjectId && !empty($quiz['subject_id'])) $subjectId = (int)$quiz['subject_id'];

$userId = getCurrentUserId();
$timeLimitSeconds = getQuizTimeLimitSeconds($quiz);
if ($timeLimitSeconds < 1) $timeLimitSeconds = 1;
if ($timeLimitSeconds > 86400) $timeLimitSeconds = 86400; // max 24 hours

function get_question_choices($q) {
  $letters = ['A','B','C','D','E','F','G','H','I','J'];
  $out = [];
  foreach ($letters as $letter) {
    $col = 'choice_' . strtolower($letter);
    if (isset($q[$col]) && trim((string)$q[$col]) !== '') {
      $out[$letter] = trim($q[$col]);
    }
  }
  return $out;
}
$timeLimitLabel = formatTimeLimitSeconds($timeLimitSeconds);

// ----- Load question IDs for this quiz -----
$stmt = mysqli_prepare($conn, "SELECT question_id FROM quiz_questions WHERE quiz_id=? ORDER BY question_id ASC");
mysqli_stmt_bind_param($stmt, 'i', $quizId);
mysqli_stmt_execute($stmt);
$qIdsRes = mysqli_stmt_get_result($stmt);
$questionIds = [];
while ($r = mysqli_fetch_assoc($qIdsRes)) $questionIds[] = (int)$r['question_id'];
mysqli_stmt_close($stmt);
$totalQuestions = count($questionIds);

// ----- Retake: create new attempt and redirect -----
if (isset($_GET['retake']) && (int)$_GET['retake'] === 1 && $totalQuestions > 0) {
    $now = time();
    $startedAt = date('Y-m-d H:i:s', $now);
    $expiresAt = date('Y-m-d H:i:s', $now + $timeLimitSeconds);
    $stmt = mysqli_prepare($conn, "INSERT INTO quiz_attempts (user_id, quiz_id, started_at, expires_at, status) VALUES (?, ?, ?, ?, 'in_progress')");
    mysqli_stmt_bind_param($stmt, 'iiss', $userId, $quizId, $startedAt, $expiresAt);
    mysqli_stmt_execute($stmt);
    $attemptId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    if ($attemptId > 0) {
        header('Location: student_take_quiz.php?quiz_id='.$quizId.'&attempt_id='.$attemptId.'&subject_id='.$subjectId);
        exit;
    }
}

// ----- POST: Start attempt -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_attempt']) && $totalQuestions > 0) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: student_take_quiz.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
        exit;
    }
    $stmt = mysqli_prepare($conn, "SELECT attempt_id FROM quiz_attempts WHERE user_id=? AND quiz_id=? AND status='in_progress' ORDER BY attempt_id DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $quizId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($existing) {
        header('Location: student_take_quiz.php?quiz_id='.$quizId.'&attempt_id='.(int)$existing['attempt_id'].'&subject_id='.$subjectId);
        exit;
    }
    $now = time();
    $startedAt = date('Y-m-d H:i:s', $now);
    $expiresAt = date('Y-m-d H:i:s', $now + $timeLimitSeconds);
    $stmt = mysqli_prepare($conn, "INSERT INTO quiz_attempts (user_id, quiz_id, started_at, expires_at, status) VALUES (?, ?, ?, ?, 'in_progress')");
    mysqli_stmt_bind_param($stmt, 'iiss', $userId, $quizId, $startedAt, $expiresAt);
    mysqli_stmt_execute($stmt);
    $attemptId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    if ($attemptId > 0) {
        header('Location: student_take_quiz.php?quiz_id='.$quizId.'&attempt_id='.$attemptId.'&subject_id='.$subjectId);
        exit;
    }
}

// ----- POST: Submit quiz -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: ' . ($subjectId > 0 ? 'student_subject.php?subject_id='.(int)$subjectId : 'student_subjects.php'));
        exit;
    }
    $attemptId = sanitizeInt($_POST['attempt_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "SELECT attempt_id, quiz_id, status, expires_at, started_at FROM quiz_attempts WHERE attempt_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attemptRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$attemptRow || (int)$attemptRow['quiz_id'] !== $quizId) {
        $_SESSION['error'] = 'Invalid attempt.';
        header('Location: ' . ($subjectId > 0 ? 'student_subject.php?subject_id='.(int)$subjectId : 'student_subjects.php'));
        exit;
    }
    if ($attemptRow['status'] === 'submitted') {
        $_SESSION['quiz_result'] = ['quiz_id' => $quizId, 'score' => (float)$attemptRow['score'] ?? 0, 'correct' => (int)($attemptRow['correct_count'] ?? 0), 'total' => (int)($attemptRow['total_count'] ?? 0), 'attempt_id' => $attemptId];
        header('Location: student_take_quiz.php?quiz_id='.$quizId.'&result=1&subject_id='.$subjectId);
        exit;
    }
    $total = 0;
    $correct = 0;
    $countRes = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM quiz_answers WHERE attempt_id=".(int)$attemptId);
    if ($countRes) $total = (int)mysqli_fetch_assoc($countRes)['cnt'];
    $correctRes = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM quiz_answers WHERE attempt_id=".(int)$attemptId." AND is_correct=1");
    if ($correctRes) $correct = (int)mysqli_fetch_assoc($correctRes)['cnt'];
    $score = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
    $nowTs = time();
    $submittedAt = date('Y-m-d H:i:s', $nowTs);
    $startedTs = strtotime($attemptRow['started_at'] ?? $submittedAt);
    $timeSpentSeconds = $nowTs > $startedTs ? ($nowTs - $startedTs) : 0;
    $stmt = mysqli_prepare($conn, "UPDATE quiz_attempts SET status='submitted', score=?, correct_count=?, total_count=?, submitted_at=? WHERE attempt_id=? AND user_id=?");
    mysqli_stmt_bind_param($stmt, 'diisii', $score, $correct, $total, $submittedAt, $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $_SESSION['quiz_result'] = ['quiz_id' => $quizId, 'score' => $score, 'correct' => $correct, 'total' => $total, 'attempt_id' => $attemptId];
    header('Location: student_take_quiz.php?quiz_id='.$quizId.'&result=1&subject_id='.$subjectId);
    exit;
}

// ----- Result view -----
$showResult = isset($_GET['result']) && isset($_SESSION['quiz_result']) && (int)$_SESSION['quiz_result']['quiz_id'] === $quizId;
$result = $showResult ? $_SESSION['quiz_result'] : null;
if ($showResult) unset($_SESSION['quiz_result']);

// ----- View last result (from subject quiz dashboard) -----
if (isset($_GET['view_result']) && (int)$_GET['view_result'] === 1 && !$showResult && $userId) {
    $stmt = mysqli_prepare($conn, "SELECT attempt_id, score, correct_count, total_count FROM quiz_attempts WHERE user_id=? AND quiz_id=? AND status='submitted' ORDER BY attempt_id DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $quizId);
    mysqli_stmt_execute($stmt);
    $lastAttempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($lastAttempt) {
        $_SESSION['quiz_result'] = [
            'quiz_id' => $quizId,
            'score' => (float)$lastAttempt['score'],
            'correct' => (int)$lastAttempt['correct_count'],
            'total' => (int)$lastAttempt['total_count'],
            'attempt_id' => (int)$lastAttempt['attempt_id']
        ];
        header('Location: student_take_quiz.php?quiz_id='.$quizId.'&result=1&subject_id='.$subjectId);
        exit;
    }
}

// ----- Attempt in progress: redirect to resume if no attempt_id -----
$attemptId = sanitizeInt($_GET['attempt_id'] ?? 0);
if ($attemptId <= 0 && !$showResult && $totalQuestions > 0) {
    $stmt = mysqli_prepare($conn, "SELECT attempt_id FROM quiz_attempts WHERE user_id=? AND quiz_id=? AND status='in_progress' ORDER BY attempt_id DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $quizId);
    mysqli_stmt_execute($stmt);
    $resume = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($resume) {
        header('Location: student_take_quiz.php?quiz_id='.$quizId.'&attempt_id='.(int)$resume['attempt_id'].'&subject_id='.$subjectId);
        exit;
    }
}

$attempt = null;
$savedAnswers = [];
$expiresAt = null;
$remainingSeconds = 0;
$answeredCount = 0;

if ($attemptId > 0 && $totalQuestions > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM quiz_attempts WHERE attempt_id=? AND user_id=? AND quiz_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'iii', $attemptId, $userId, $quizId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$attempt || $attempt['status'] !== 'in_progress') {
        if ($attempt && $attempt['status'] === 'submitted') {
            $_SESSION['quiz_result'] = ['quiz_id' => $quizId, 'score' => (float)$attempt['score'], 'correct' => (int)$attempt['correct_count'], 'total' => (int)$attempt['total_count'], 'attempt_id' => $attemptId];
            header('Location: student_take_quiz.php?quiz_id='.$quizId.'&result=1&subject_id='.$subjectId);
            exit;
        }
        header('Location: student_take_quiz.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
        exit;
    }

    $expiresAt = $attempt['expires_at'];
    $remainingSeconds = max(0, strtotime($expiresAt) - time());
    $maxAllowedSeconds = $timeLimitSeconds;
    if ($remainingSeconds > $maxAllowedSeconds) {
        $remainingSeconds = $maxAllowedSeconds;
        $newExpires = date('Y-m-d H:i:s', time() + $remainingSeconds);
        $upd = mysqli_prepare($conn, "UPDATE quiz_attempts SET expires_at=? WHERE attempt_id=? AND user_id=?");
        if ($upd) {
            mysqli_stmt_bind_param($upd, 'sii', $newExpires, $attemptId, $userId);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
    }

    if ($remainingSeconds <= 0) {
        $total = 0; $correct = 0;
        $cr = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM quiz_answers WHERE attempt_id=".(int)$attemptId);
        if ($cr) $total = (int)mysqli_fetch_assoc($cr)['cnt'];
        $cr2 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM quiz_answers WHERE attempt_id=".(int)$attemptId." AND is_correct=1");
        if ($cr2) $correct = (int)mysqli_fetch_assoc($cr2)['cnt'];
        $score = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
        $nowTs = time();
        $submittedAt = date('Y-m-d H:i:s', $nowTs);
        $stmt = mysqli_prepare($conn, "UPDATE quiz_attempts SET status='submitted', score=?, correct_count=?, total_count=?, submitted_at=? WHERE attempt_id=? AND user_id=?");
        mysqli_stmt_bind_param($stmt, 'diisii', $score, $correct, $total, $submittedAt, $attemptId, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['quiz_result'] = ['quiz_id' => $quizId, 'score' => $score, 'correct' => $correct, 'total' => $total, 'attempt_id' => $attemptId];
        header('Location: student_take_quiz.php?quiz_id='.$quizId.'&result=1&subject_id='.$subjectId);
        exit;
    }

    $ansRes = mysqli_query($conn, "SELECT question_id, selected_answer FROM quiz_answers WHERE attempt_id=".(int)$attemptId);
    if ($ansRes) {
        while ($a = mysqli_fetch_assoc($ansRes)) {
            $savedAnswers[(int)$a['question_id']] = $a['selected_answer'];
        }
    }
    $answeredCount = count($savedAnswers);
}

// Load all questions for single-page scroll view
$allQuestions = [];
if ($totalQuestions > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY question_id ASC");
    mysqli_stmt_bind_param($stmt, 'i', $quizId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $allQuestions[] = $row;
    mysqli_stmt_close($stmt);
}
$allAnswered = ($totalQuestions > 0 && $answeredCount >= $totalQuestions);

$pageTitle = 'Take Quiz - ' . ($quiz['title'] ?? '');
$headerBackUrl = $subjectId > 0 ? 'student_subject.php?subject_id='.(int)$subjectId.'#quizzers' : 'student_subjects.php';
$csrf = generateCSRFToken();

// For result view: load review questions (with answers from submitted attempt)
$reviewQuestions = [];
if ($showResult && $result && getCurrentUserId()) {
    $aid = (int)($result['attempt_id'] ?? 0);
    if ($aid > 0) {
        $qRes = mysqli_query($conn, "SELECT qq.*, qa.selected_answer, qa.is_correct FROM quiz_questions qq LEFT JOIN quiz_answers qa ON qa.question_id=qq.question_id AND qa.attempt_id=".$aid." WHERE qq.quiz_id=".(int)$quizId." ORDER BY qq.question_id ASC");
        if ($qRes) { while ($r = mysqli_fetch_assoc($qRes)) $reviewQuestions[] = $r; }
    }
}

$attemptCount = 0;
$lastAttempt = null;
$quizHistoryAttempts = [];
if ($userId) {
    $res = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM quiz_attempts WHERE user_id=".(int)$userId." AND quiz_id=".(int)$quizId);
    if ($res) $attemptCount = (int)mysqli_fetch_assoc($res)['cnt'];
    $res2 = mysqli_query($conn, "SELECT score, correct_count, total_count, submitted_at FROM quiz_attempts WHERE user_id=".(int)$userId." AND quiz_id=".(int)$quizId." AND status='submitted' ORDER BY submitted_at DESC LIMIT 1");
    if ($res2) $lastAttempt = mysqli_fetch_assoc($res2);
    // Load quiz history (submitted attempts) – order by attempt_id DESC for consistent, accurate attempt order
    $histRes = mysqli_query($conn, "SELECT attempt_id, started_at, submitted_at, score, correct_count, total_count FROM quiz_attempts WHERE user_id=".(int)$userId." AND quiz_id=".(int)$quizId." AND status='submitted' ORDER BY attempt_id DESC LIMIT 10");
    if ($histRes) {
        $attemptNum = 0;
        while ($row = mysqli_fetch_assoc($histRes)) {
            $attemptNum++;
            $started = !empty($row['started_at']) ? strtotime($row['started_at']) : 0;
            $submitted = !empty($row['submitted_at']) ? strtotime($row['submitted_at']) : 0;
            $row['time_spent_seconds'] = ($submitted > 0 && $started > 0 && $submitted >= $started) ? ($submitted - $started) : 0;
            $row['attempt_number'] = $attemptNum;
            $row['questions'] = [];
            $qRes = mysqli_query($conn, "SELECT qq.*, qa.selected_answer, qa.is_correct FROM quiz_questions qq LEFT JOIN quiz_answers qa ON qa.question_id=qq.question_id AND qa.attempt_id=".(int)$row['attempt_id']." WHERE qq.quiz_id=".(int)$quizId." ORDER BY qq.question_id ASC");
            if ($qRes) { while ($r = mysqli_fetch_assoc($qRes)) $row['questions'][] = $r; }
            $quizHistoryAttempts[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    /* Exam UI – professional exam-style layout */
    :root {
      /* Aligned with site theme blues (#1665A0 / #143D59) */
      --exam-primary: #1665A0;
      --exam-primary-light: #e8f2fa;
      --exam-success: #059669;
      --exam-warning: #d97706;
      --exam-danger: #dc2626;
      --exam-surface: #ffffff;
      --exam-bg: #f6f9ff;
      --exam-text: #143D59;
      --exam-muted: #64748b;
      --exam-border: rgba(22, 101, 160, 0.16);
      --exam-radius: 14px;
      --exam-radius-sm: 10px;
      --exam-shadow: 0 1px 3px rgba(0,0,0,0.06);
      --exam-shadow-lg: 0 10px 25px -5px rgba(0,0,0,0.08);
      --exam-space: 1rem;
      --exam-space-lg: 1.5rem;
      --exam-space-xl: 2rem;
    }
    /* Sticky exam header – clear hierarchy, balanced spacing */
    .exam-bar {
      position: sticky;
      top: 0;
      z-index: 100;
      background: var(--exam-surface);
      box-shadow: 0 2px 12px rgba(0,0,0,0.06);
      border-bottom: 1px solid var(--exam-border);
    }
    .exam-header {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: var(--exam-space-lg);
      padding: 1rem 1.75rem;
    }
    .exam-header-left {
      display: flex;
      flex-direction: column;
      gap: 0.25rem;
    }
    .exam-title {
      font-size: 1.125rem;
      font-weight: 700;
      color: var(--exam-text);
      letter-spacing: -0.02em;
      line-height: 1.3;
    }
    .exam-subject {
      font-size: 0.8125rem;
      color: var(--exam-muted);
      font-weight: 500;
    }
    .exam-q-badge {
      font-size: 0.8125rem;
      font-weight: 600;
      color: var(--exam-muted);
      padding: 0.4rem 0.75rem;
      background: var(--exam-bg);
      border-radius: var(--exam-radius-sm);
    }
    .exam-q-badge strong { color: var(--exam-text); }
    .exam-progress-wrap {
      padding: 0 1.75rem 1rem;
    }
    .exam-progress-bar {
      height: 8px;
      background: var(--exam-border);
      border-radius: 9999px;
      overflow: hidden;
      transition: width 0.4s ease;
    }
    .exam-progress-fill {
      transition: width 0.4s ease, box-shadow 0.25s ease;
      height: 100%;
      background: linear-gradient(90deg, var(--exam-primary), #3393FF);
      border-radius: 9999px;
    }
    .exam-progress-wrap:hover .exam-progress-fill {
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.55),
        0 0 20px rgba(51, 147, 255, 0.6);
    }
    .exam-progress-label {
      font-size: 0.8125rem;
      font-weight: 600;
      color: var(--exam-muted);
      margin-top: 0.5rem;
    }
    /* Instructions (start screen only) */
    .exam-instructions-wrap {
      background: linear-gradient(135deg, #f0f7fc 0%, #e8f2fa 100%);
      border: 1px solid rgba(22, 101, 160, 0.16);
      border-radius: var(--exam-radius);
      margin-bottom: 1.5rem;
      overflow: hidden;
      box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .exam-instructions-head {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.8125rem;
      font-weight: 700;
      color: #1665A0;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 0.75rem;
    }
    .exam-instructions-list {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 0.4rem 1.25rem;
      list-style: none;
      margin: 0;
      padding: 0;
    }
    .exam-instructions-list li {
      display: flex;
      align-items: flex-start;
      gap: 0.5rem;
      font-size: 0.8125rem;
      color: #475569;
      line-height: 1.5;
    }
    .exam-instructions-list li i {
      color: #22c55e;
      margin-top: 0.2rem;
      flex-shrink: 0;
      font-size: 0.85rem;
    }
    .exam-instructions-list li strong { color: #1e293b; }
    @media (max-width: 640px) {
      .exam-instructions-list { grid-template-columns: 1fr; }
    }
    .exam-question-card {
      background: var(--exam-surface);
      border-radius: var(--exam-radius);
      border: 1px solid var(--exam-border);
      box-shadow: 0 2px 12px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);
      padding: var(--exam-space-xl) 2.25rem;
      margin-bottom: 1.5rem;
      transition: box-shadow 0.25s ease, border-color 0.2s ease, transform 0.18s ease;
      scroll-margin-top: 1.5rem;
    }
    html { scroll-behavior: smooth; }
    .exam-question-card:focus-within {
      box-shadow: 0 8px 24px rgba(65, 84, 241, 0.12), 0 2px 8px rgba(0,0,0,0.06);
      border-color: rgba(65, 84, 241, 0.25);
    }
    .exam-question-label {
      font-size: 0.6875rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--exam-muted);
      margin-bottom: 0.75rem;
    }
    .exam-question-text {
      font-size: 1.1875rem;
      line-height: 1.65;
      color: var(--exam-text);
      font-weight: 500;
      margin-bottom: 1.5rem;
    }
    .exam-question-text table,
    .exam-choice-text table,
    .quiz-rich-text table {
      width: 100%;
      border-collapse: collapse;
      margin: 0.75rem 0;
    }
    .exam-question-text th,
    .exam-question-text td,
    .exam-choice-text th,
    .exam-choice-text td,
    .quiz-rich-text th,
    .quiz-rich-text td {
      border: 1px solid #cbd5e1;
      padding: 0.4rem 0.55rem;
      vertical-align: top;
    }
    .exam-question-text thead th,
    .exam-choice-text thead th,
    .quiz-rich-text thead th {
      background: #f8fafc;
      font-weight: 700;
    }
    .exam-choices {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }
    .exam-choice {
      display: flex;
      align-items: center;
      gap: 1.125rem;
      min-height: 3.5rem;
      padding: 1.125rem 1.5rem;
      border-radius: var(--exam-radius);
      border: 2px solid var(--exam-border);
      background: var(--exam-surface);
      cursor: pointer;
      transition: border-color 0.2s, background-color 0.2s, box-shadow 0.2s;
    }
    .exam-choice:hover {
      border-color: rgba(51, 147, 255, 0.55);
      background: #e8f2fa;
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.55),
        0 8px 20px rgba(51, 147, 255, 0.45);
    }
    .exam-choice.selected {
      border-color: var(--exam-success);
      background: #f0fdf4;
      box-shadow:
        0 0 0 1px rgba(34, 197, 94, 0.45),
        0 6px 18px rgba(22, 163, 74, 0.35);
    }
    .exam-choice-letter {
      flex-shrink: 0;
      width: 2.25rem;
      height: 2.25rem;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      font-weight: 700;
      font-size: 0.9375rem;
      background: #d4e8f7;
      color: var(--exam-text);
      transition: background 0.2s, color 0.2s;
    }
    .exam-choice.selected .exam-choice-letter {
      background: var(--exam-primary);
      color: white;
    }
    .exam-choice-text { font-size: 1rem; color: var(--exam-text); line-height: 1.55; flex: 1; }
    .exam-choice input { position: absolute; opacity: 0; pointer-events: none; }
    .exam-nav-card {
      background: var(--exam-surface);
      border-radius: var(--exam-radius);
      border: 1px solid var(--exam-border);
      padding: 1.5rem 1.75rem;
      margin-top: 1.5rem;
      margin-bottom: 1rem;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: center;
      gap: var(--exam-space-lg);
      box-shadow: 0 2px 12px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);
    }
    .exam-nav-card .exam-nav-group {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
    }
    .exam-btn-prev, .exam-btn-next {
      padding: 0.75rem 1.5rem;
      border-radius: var(--exam-radius-sm);
      font-weight: 600;
      font-size: 0.9375rem;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      transition: all 0.2s ease;
    }
    .exam-btn-prev {
      border: 2px solid var(--exam-border);
      color: var(--exam-muted);
      background: var(--exam-surface);
    }
    .exam-btn-prev:hover { border-color: #94a3b8; color: var(--exam-text); background: #f8fafc; }
    .exam-btn-next {
      background: var(--exam-primary);
      color: white;
      border: 2px solid var(--exam-primary);
    }
    .exam-btn-next:hover {
      background: #2563eb;
      border-color: #2563eb;
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.7),
        0 10px 26px rgba(51, 147, 255, 0.6);
      transform: translateY(-1px);
    }
    .exam-btn-submit {
      min-height: 3rem;
      padding: 0.75rem 1.75rem;
      border-radius: var(--exam-radius-sm);
      font-weight: 700;
      font-size: 1rem;
      background: var(--exam-primary);
      color: white;
      border: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      transition: background 0.2s ease, transform 0.2s ease, opacity 0.2s ease, box-shadow 0.2s ease;
    }
    .exam-btn-submit:hover:not(:disabled) {
      background: #2563eb;
      transform: translateY(-2px);
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.7),
        0 12px 30px rgba(51, 147, 255, 0.65);
    }
    .exam-btn-submit:active:not(:disabled) { transform: scale(0.98); }
    .exam-btn-submit:disabled {
      background: #cbd5e1;
      color: #94a3b8;
      cursor: not-allowed;
      opacity: 0.9;
    }
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
    /* Page container: consistent max-width and padding */
    .exam-page-container {
      /* Full-width inside main content (no extra side gaps; main already has padding) */
      width: 100%;
      max-width: 100%;
      margin-left: auto;
      margin-right: auto;
      padding-left: 0;
      padding-right: 0;
    }
    .exam-page-container-result {
      /* Full-width page container; inner content handles max-width */
      width: 100%;
      padding-top: 1.75rem;
      padding-bottom: 2.5rem;
      margin-left: auto;
      margin-right: auto;
    }
    .exam-result-inner {
      /* Let the result card span the full content width (no large side gaps) */
      max-width: 100%;
      margin-left: auto;
      margin-right: auto;
    }
    .exam-page-header {
      display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem;
      padding: 0.5rem 0 1rem; margin-bottom: 0.5rem;
    }
    .exam-page-header h1 {
      font-size: 1.125rem;
      font-weight: 700;
      color: #143D59;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .exam-page-header .exam-back-link {
      display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: var(--exam-radius-sm);
      font-size: 0.875rem; font-weight: 600; color: var(--exam-primary); text-decoration: none;
      border: 2px solid var(--exam-primary); background: transparent; transition: all 0.2s;
    }
    .exam-page-header .exam-back-link:hover { background: var(--exam-primary); color: white; }
    /* Result screen: action buttons row – all same visual weight */
    .result-card-actions { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 0.75rem; margin-top: 1.5rem; }
    .result-card-actions .exam-btn-prev, .result-card-actions .exam-btn-next,
    .result-card-actions .exam-btn-view-history { padding: 0.75rem 1.5rem; border-radius: var(--exam-radius-sm); font-weight: 600; font-size: 0.9375rem; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; transition: all 0.2s; }
    .result-card-actions .exam-btn-view-history { border: 2px solid var(--exam-primary); color: var(--exam-primary); background: var(--exam-surface); }
    .result-card-actions .exam-btn-view-history:hover { background: var(--exam-primary); color: white; }
    /* Layout: full-width main + right sidebar (Quiz Questions List) */
    .exam-layout { display: flex; flex-wrap: wrap; gap: 2rem; align-items: flex-start; width: 100%; }
    .exam-main { flex: 1; min-width: 0; }
    .exam-sidebar { flex: 0 0 280px; position: sticky; top: 1.25rem; }
    .exam-sidebar-card {
      background: var(--exam-surface);
      border-radius: var(--exam-radius);
      border: 1px solid var(--exam-border);
      box-shadow: 0 2px 12px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);
      overflow: hidden;
    }
    .exam-sidebar-title { font-size: 0.875rem; font-weight: 700; color: var(--exam-text); padding: 1.125rem 1.25rem; border-bottom: 1px solid var(--exam-border); }
    /* Circular timer in sidebar: SVG progress ring */
    .exam-timer-circle-wrap {
      width: 140px; height: 140px;
      margin: 1.25rem auto;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.35),
        0 14px 32px rgba(51, 147, 255, 0.5);
      border-radius: 9999px;
      transition: box-shadow 0.25s ease, transform 0.18s ease;
    }
    .exam-timer-circle-wrap svg {
      position: absolute;
      inset: 0;
      width: 100%; height: 100%;
      transform: rotate(-90deg);
    }
    .exam-timer-circle-track {
      fill: none;
      stroke: var(--exam-border);
      stroke-width: 10;
    }
    .exam-timer-circle-progress {
      fill: none;
      stroke-width: 10;
      stroke-linecap: round;
      stroke: #059669;
      transition: stroke-dashoffset 0.8s ease-out, stroke 0.3s ease;
    }
    .exam-timer-circle-wrap.warning .exam-timer-circle-progress { stroke: #d97706; }
    .exam-timer-circle-wrap.danger .exam-timer-circle-progress { stroke: #dc2626; }
    .exam-timer-circle-inner {
      position: relative;
      z-index: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      font-variant-numeric: tabular-nums;
    }
    .exam-timer-circle-value {
      font-size: 1.5rem; font-weight: 800; color: #047857;
      line-height: 1.2;
      transition: color 0.3s ease;
    }
    .exam-timer-circle-wrap.warning .exam-timer-circle-value { color: #b45309; }
    .exam-timer-circle-wrap.danger .exam-timer-circle-value { color: #b91c1c; }
    .exam-timer-circle-wrap.danger .exam-timer-circle-inner { animation: exam-pulse 1s ease-in-out infinite; }
    @keyframes exam-pulse { 50% { opacity: 0.92; } }
    .exam-timer-circle-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--exam-muted); margin-top: 0.25rem; }
    /* Collapsible Quiz Questions List */
    .exam-sidebar-section { border-bottom: 1px solid var(--exam-border); }
    .exam-sidebar-section:last-child { border-bottom: none; }
    .exam-sidebar-section-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      padding: 1rem 1.25rem;
      background: transparent;
      border: none;
      font-size: 0.875rem;
      font-weight: 700;
      color: var(--exam-text);
      cursor: pointer;
      transition: background 0.15s;
      text-align: left;
    }
    .exam-sidebar-section-head:hover {
      background: #f1f5ff;
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.55),
        0 8px 22px rgba(51, 147, 255, 0.5);
    }
    .exam-sidebar-section-head i.bi-chevron-up { transition: transform 0.2s ease; }
    .exam-sidebar-section.collapsed .exam-sidebar-section-head i.bi-chevron-up { transform: rotate(180deg); }
    .exam-sidebar-section.collapsed .exam-q-list { display: none; }
    /* Breadcrumb */
    .exam-breadcrumb {
      font-size: 0.8125rem;
      color: var(--exam-muted);
      margin-bottom: 1rem;
      padding: 0 0.25rem;
    }
    .exam-breadcrumb a { color: var(--exam-primary); text-decoration: none; font-weight: 500; transition: color 0.15s; }
    .exam-breadcrumb a:hover { color: #2d3fc7; }
    .exam-breadcrumb span { color: var(--exam-muted); }
    /* Quiz Questions List – interactive */
    .exam-q-list { padding: 0.5rem 0.75rem 1.25rem; max-height: 280px; overflow-y: auto; }
    .exam-q-list a {
      display: flex;
      align-items: center;
      gap: 0.625rem;
      padding: 0.65rem 0.875rem;
      border-radius: var(--exam-radius-sm);
      text-decoration: none;
      font-size: 0.875rem;
      color: var(--exam-text);
      transition: background 0.2s, transform 0.15s ease, box-shadow 0.2s;
    }
    .exam-q-list a:hover {
      background: #eff6ff;
      transform: translateX(2px);
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.6),
        0 6px 18px rgba(51, 147, 255, 0.55);
    }
    .exam-q-list a.current { background: var(--exam-primary-light); color: var(--exam-primary); font-weight: 600; box-shadow: 0 1px 3px rgba(65, 84, 241, 0.12); }
    .exam-q-list a .q-num { flex-shrink: 0; width: 1.5rem; height: 1.5rem; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: var(--exam-border); color: var(--exam-muted); font-size: 0.75rem; font-weight: 700; transition: background 0.2s, color 0.2s; }
    .exam-q-list a.answered .q-num { background: #059669; color: white; }
    .exam-q-list a.current .q-num { background: var(--exam-primary); color: white; }
    .exam-q-list a .q-check { margin-left: auto; color: #059669; font-size: 0.875rem; transition: transform 0.2s; }
    .exam-q-list a.answered:hover .q-check { transform: scale(1.1); }
    /* Answer style – green when selected (saved), checkmark on right, interactive */
    .exam-choice.selected {
      border-color: var(--exam-success);
      background: #f0fdf4;
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.5),
        0 0 0 3px rgba(5, 150, 105, 0.28);
    }
    .exam-choice.selected .exam-choice-letter { background: var(--exam-success); color: white; }
    .exam-choice .exam-choice-check { margin-left: auto; flex-shrink: 0; width: 1.5rem; height: 1.5rem; border-radius: 50%; background: var(--exam-success); color: white; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; opacity: 0; transition: opacity 0.2s; }
    .exam-choice.selected .exam-choice-check { opacity: 1; }
    .exam-choice { transition: transform 0.15s ease, box-shadow 0.2s ease; }
    .exam-choice:hover {
      transform: translateY(-2px);
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.6),
        0 10px 22px rgba(51, 147, 255, 0.5);
    }
    .exam-choice.selected:hover {
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.55),
        0 0 0 3px rgba(5, 150, 105, 0.3),
        0 10px 22px rgba(51, 147, 255, 0.5);
    }
    .exam-choice:active { transform: translateY(0); }
    .exam-choice:focus-visible {
      outline: none;
      box-shadow:
        0 0 0 2px rgba(51, 147, 255, 0.7),
        0 0 0 4px rgba(148, 163, 184, 0.6);
    }
    .exam-q-list a:focus-visible {
      outline: none;
      box-shadow:
        0 0 0 2px rgba(51, 147, 255, 0.85);
    }
    .exam-btn-submit:focus-visible {
      outline: none;
      box-shadow:
        0 0 0 2px rgba(51, 147, 255, 0.8),
        0 0 0 4px rgba(5, 150, 105, 0.45);
    }
    .exam-question-card:target { border-color: rgba(65, 84, 241, 0.35); box-shadow: 0 6px 20px rgba(65, 84, 241, 0.1); }
    .exam-nav-card { transition: box-shadow 0.2s ease, border-color 0.2s ease; }
    .exam-nav-card:focus-within { box-shadow: 0 4px 16px rgba(0,0,0,0.08); border-color: rgba(5, 150, 105, 0.25); }
    /* Saved feedback toast */
    .exam-saved-toast {
      position: fixed; bottom: 1.75rem; left: 50%; transform: translateX(-50%) translateY(100px);
      padding: 0.625rem 1.25rem; background: #059669; color: white; border-radius: 9999px;
      font-size: 0.875rem; font-weight: 600; box-shadow: 0 4px 20px rgba(5, 150, 105, 0.4);
      opacity: 0; transition: transform 0.3s ease, opacity 0.3s ease; z-index: 999;
    }
    .exam-saved-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
    /* Resume banner + time warning toast */
    .exam-resume-banner {
      display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
      padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: var(--exam-radius);
      background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1px solid #93c5fd;
      font-size: 0.875rem; color: #1e40af; transition: opacity 0.3s ease, transform 0.3s ease, max-height 0.3s ease;
    }
    .exam-resume-banner.dismissed { opacity: 0; max-height: 0; margin: 0; padding: 0; overflow: hidden; pointer-events: none; }
    .exam-resume-banner-inner { display: flex; align-items: center; gap: 0.5rem; flex: 1; min-width: 0; }
    .exam-resume-banner-inner i { flex-shrink: 0; font-size: 1.125rem; }
    .exam-resume-banner-dismiss { background: none; border: none; font-size: 1.25rem; line-height: 1; color: #64748b; cursor: pointer; padding: 0.25rem; border-radius: 4px; transition: color 0.2s, background 0.2s; }
    .exam-resume-banner-dismiss:hover { color: #1e293b; background: rgba(0,0,0,0.06); }
    .exam-time-warning-toast {
      position: fixed; bottom: 1.75rem; left: 50%; transform: translateX(-50%) translateY(100px);
      padding: 0.75rem 1.5rem; border-radius: var(--exam-radius); font-size: 0.9375rem; font-weight: 600;
      box-shadow: 0 8px 24px rgba(0,0,0,0.15); opacity: 0; transition: transform 0.3s ease, opacity 0.3s ease; z-index: 1000;
    }
    .exam-time-warning-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
    .exam-time-warning-toast.warning { background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; }
    .exam-time-warning-toast.danger { background: #fef2f2; color: #b91c1c; border: 1px solid #dc2626; }
    /* Global quiz submit loading overlay – similar to welcome modal feel */
    .quiz-submit-overlay {
      position: fixed;
      inset: 0;
      z-index: 1400;
      display: flex;
      align-items: center;
      justify-content: center;
      background: radial-gradient(circle at top, rgba(51,147,255,0.18), transparent 55%),
                  rgba(15,23,42,0.78);
      backdrop-filter: blur(6px);
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.25s ease;
    }
    .quiz-submit-overlay.show {
      opacity: 1;
      pointer-events: auto;
    }
    .quiz-submit-card {
      background: linear-gradient(135deg, #0f172a 0%, #020617 55%, #020617 100%);
      border-radius: 1rem;
      padding: 1.75rem 2rem;
      max-width: 380px;
      width: 100%;
      text-align: center;
      box-shadow: 0 25px 60px rgba(0,0,0,0.65);
      border: 1px solid rgba(148,163,184,0.35);
      color: #e5e7eb;
    }
    .quiz-submit-spinner {
      width: 3rem;
      height: 3rem;
      border-radius: 9999px;
      border-width: 3px;
      border-style: solid;
      border-color: #3393ff transparent #60a5fa transparent;
      margin: 0 auto 1.25rem;
      animation: quiz-spin 0.9s linear infinite;
      box-shadow:
        0 0 0 1px rgba(51,147,255,0.55),
        0 0 30px rgba(51,147,255,0.85);
    }
    @keyframes quiz-spin {
      to { transform: rotate(360deg); }
    }
    .quiz-submit-title {
      font-size: 1.1rem;
      font-weight: 700;
      margin-bottom: 0.25rem;
      color: #f9fafb;
    }
    .quiz-submit-text {
      font-size: 0.9rem;
      color: #cbd5f5;
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
    /* Result card: dashboard-style theme (blue card, subtle status colors) */
    .result-card {
      border-width: 1px;
      border-radius: 18px;
      padding: 1.25rem 1.75rem 1.35rem;
      width: 100%;
      position: relative;
      overflow: hidden;
      background: linear-gradient(to bottom right, #d4e8f7, #e8f2fa);
      border-color: rgba(22, 101, 160, 0.18);
      box-shadow:
        0 2px 8px rgba(20, 61, 89, 0.12),
        0 6px 18px rgba(20, 61, 89, 0.08);
      border-left: 4px solid #1665A0;
    }
    .result-card::before {
      content: "";
      position: absolute;
      inset: -30%;
      background:
        radial-gradient(circle at 0 0, rgba(255,255,255,0.55), transparent 55%),
        radial-gradient(circle at 100% 0, rgba(148, 197, 255,0.3), transparent 60%);
      opacity: 0.5;
      z-index: -1;
    }
    .result-card-inner {
      position: relative;
      z-index: 1;
      border-radius: inherit;
      padding: 0.25rem 0 0;
    }
    .result-card.result-pass { border-left-color: #059669; }
    .result-card.result-pass .result-score { color: #047857; }
    .result-card.result-pass .result-badge { background: #059669; color: white; }
    .result-card.result-pass h2 i { color: #059669; }
    .result-card.result-fair { border-left-color: #d97706; }
    .result-card.result-fair .result-score { color: #b45309; }
    .result-card.result-fair .result-badge { background: #d97706; color: white; }
    .result-card.result-fair h2 i { color: #d97706; }
    .result-card.result-fail { border-left-color: #dc2626; }
    .result-card.result-fail .result-score { color: #b91c1c; }
    .result-card.result-fail .result-badge { background: #dc2626; color: white; }
    .result-card.result-fail h2 i { color: #dc2626; }
    .result-badge {
      display: inline-block;
      padding: 0.25rem 0.8rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 0.5rem;
      background: rgba(255,255,255,0.9);
    }
    .result-card h2 { margin-bottom: 0.35rem; }
    .result-card .result-score { margin-bottom: 0.35rem; }
    /* Result actions: vertically stacked on mobile, evenly spaced on desktop */
    .result-card-actions {
      display: flex;
      flex-direction: column;
      align-items: stretch;
      gap: 0.75rem;
      margin-top: 1.75rem;
      max-width: 380px;
      margin-left: auto;
      margin-right: auto;
    }
    .result-card-actions .exam-btn-prev,
    .result-card-actions .exam-btn-next,
    .result-card-actions .exam-btn-view-history {
      padding: 0.7rem 1.5rem;
      border-radius: var(--exam-radius-sm);
      font-weight: 600;
      font-size: 0.9375rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      text-decoration: none;
      transition: all 0.2s ease;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    }
    .result-card-actions .exam-btn-prev:hover,
    .result-card-actions .exam-btn-next:hover,
    .result-card-actions .exam-btn-view-history:hover {
      transform: translateY(-1px);
      box-shadow:
        0 0 0 1px rgba(51, 147, 255, 0.75),
        0 10px 26px rgba(51, 147, 255, 0.65);
    }
    .result-card-actions .exam-btn-view-history {
      border: 2px solid var(--exam-primary);
      color: var(--exam-primary);
      background: var(--exam-surface);
    }
    .result-card-actions .exam-btn-view-history:hover {
      background: var(--exam-primary);
      color: #ffffff;
    }
    @media (min-width: 640px) {
      .result-card-actions {
        flex-direction: row;
        align-items: center;
        justify-content: center;
      }
    }
    /* Result stats row under main score */
    .result-stats-row {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.75rem;
      margin-top: 0.35rem;
      margin-bottom: 0.35rem;
    }
    .result-stat-card {
      border-radius: 9999px;
      padding: 0.5rem 0.75rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      font-size: 0.8rem;
      font-weight: 600;
      background: rgba(255,255,255,0.78);
      color: #0f172a;
      box-shadow: 0 1px 4px rgba(15,23,42,0.08);
    }
    .result-stat-card i {
      font-size: 0.9rem;
    }
    .result-stat-correct i { color: #16a34a; }
    .result-stat-wrong i { color: #dc2626; }
    .result-stat-total i { color: #0ea5e9; }
    @media (max-width: 640px) {
      .result-stats-row {
        grid-template-columns: 1fr;
      }
    }
    /* Section 2: compact actions under result card */
    .result-actions-bar {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 0.5rem;
      margin-bottom: 1.5rem;
    }
    .result-actions-bar a,
    .result-actions-bar button {
      font-size: 0.875rem;
      padding: 0.55rem 1.1rem;
      border-radius: 9999px;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      border-width: 1px;
      border-style: solid;
      transition: background-color 0.18s ease, color 0.18s ease, box-shadow 0.22s ease, transform 0.16s ease;
    }
    .result-actions-primary {
      background: #4154f1;
      border-color: #4154f1;
      color: #ffffff;
    }
    .result-actions-primary:hover {
      background: #2563eb;
      box-shadow:
        0 0 0 1px rgba(51,147,255,0.75),
        0 10px 26px rgba(51,147,255,0.65);
      transform: translateY(-1px);
    }
    .result-actions-ghost {
      background: #ffffff;
      border-color: #d1d5db;
      color: #4b5563;
    }
    .result-actions-ghost:hover {
      background: #f3f4f6;
      box-shadow:
        0 0 0 1px rgba(51,147,255,0.45),
        0 8px 20px rgba(51,147,255,0.4);
      transform: translateY(-1px);
    }
    /* Review: correct = green, wrong = red – consistent padding */
    .review-item-correct { background: #f0fdf4 !important; border-color: #059669 !important; padding: 1.25rem !important; }
    .review-item-wrong { background: #fef2f2 !important; border-color: #dc2626 !important; padding: 1.25rem !important; }
    /* Correct answer choice = highlighted (main focus in review) */
    .review-correct-choice { background: #ecfdf5 !important; border: 2px solid #059669 !important; box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.25); }
    .review-correct-choice .review-choice-letter { background: #059669 !important; color: white !important; }
    .review-correct-choice .review-correct-label { color: #047857; font-weight: 700; }
    /* Embedded Quiz History – collapsible + accordion, detailed layout */
    .quiz-history-wrap { border: 1px solid var(--exam-border); border-radius: var(--exam-radius); background: var(--exam-surface); box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-top: 1.5rem; overflow: hidden; }
    .quiz-history-toggle { width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 1.125rem 1.5rem; background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%); border: none; font-size: 0.9375rem; font-weight: 700; color: var(--exam-text); cursor: pointer; transition: background 0.2s; text-align: left; }
    .quiz-history-toggle:hover { background: #eef2ff; color: var(--exam-primary); }
    .quiz-history-toggle i.bi-chevron-down { transition: transform 0.25s ease; flex-shrink: 0; }
    .quiz-history-wrap.open .quiz-history-toggle i.bi-chevron-down { transform: rotate(180deg); }
    .quiz-history-body { display: none; padding: 1.25rem 1.5rem; }
    .quiz-history-wrap.open .quiz-history-body { display: block; }
    .quiz-history-accordion { border: 1px solid var(--exam-border); border-radius: var(--exam-radius-sm); overflow: hidden; background: #fff; }
    .quiz-history-attempt { border-bottom: 1px solid var(--exam-border); }
    .quiz-history-attempt:last-child { border-bottom: none; }
    .quiz-history-attempt-head { width: 100%; display: flex; flex-wrap: wrap; align-items: center; gap: 0; padding: 1.125rem 1.5rem; background: #fff; border: none; font-size: 0.875rem; text-align: left; cursor: pointer; transition: background 0.15s; }
    .quiz-history-attempt-head:hover { background: #f8fafc; }
    .quiz-history-attempt-head .attempt-head-left { flex: 1; min-width: 0; }
    .quiz-history-attempt-head .attempt-head-title { font-weight: 700; color: var(--exam-text); margin-bottom: 0.35rem; }
    .quiz-history-attempt-head .attempt-meta-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem 1rem; font-size: 0.8125rem; }
    @media (max-width: 640px) {
      .quiz-history-attempt-head .attempt-meta-grid { grid-template-columns: repeat(2, 1fr); }
    }
    .quiz-history-attempt-head .attempt-meta-item { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
    .quiz-history-attempt-head .attempt-meta-label { color: var(--exam-muted); font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
    .quiz-history-attempt-head .attempt-meta-value { color: var(--exam-text); font-weight: 600; word-break: break-word; }
    .quiz-history-attempt-head i.bi-chevron-down { flex-shrink: 0; margin-left: 0.75rem; font-size: 0.875rem; transition: transform 0.2s ease; color: var(--exam-muted); }
    .quiz-history-attempt.open .quiz-history-attempt-head i.bi-chevron-down { transform: rotate(180deg); }
    .quiz-history-attempt-body { display: none; padding: 1.5rem; background: #fafbfc; border-top: 1px solid var(--exam-border); }
    .quiz-history-attempt.open .quiz-history-attempt-body { display: block; }
    .quiz-history-score-pass { color: #059669 !important; }
    .quiz-history-score-fair { color: #d97706 !important; }
    .quiz-history-score-fail { color: #dc2626 !important; }
    /* Start screen – full-width card with history inside */
    .quiz-history-wrap.quiz-history-inside { margin-top: 0; margin-left: 0; margin-right: 0; border: none; border-radius: 0; box-shadow: none; border-top: 1px solid var(--exam-border); }
    .quiz-start-card {
      background: linear-gradient(to bottom, #f0f7fc, #ffffff);
      border-radius: var(--exam-radius);
      border: 1px solid rgba(22, 101, 160, 0.16);
      box-shadow:
        0 2px 8px rgba(20, 61, 89, 0.1),
        0 6px 18px rgba(20, 61, 89, 0.08);
      border-left: 4px solid #1665A0;
    }
    .quiz-start-hero { padding-bottom: 1.75rem; border-bottom: 1px solid rgba(22, 101, 160, 0.16); margin-bottom: 1.75rem; }
    .quiz-start-hero .exam-question-label { margin-bottom: 0.5rem; }
    .quiz-start-hero h1 { font-size: 1.75rem; line-height: 1.3; }
    .quiz-start-meta { display: flex; flex-wrap: wrap; gap: 1.25rem 2rem; margin-top: 1rem; }
    .quiz-start-meta-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.9375rem; color: var(--exam-muted); }
    .quiz-start-meta-item i { color: var(--exam-primary); font-size: 1.1rem; }
    .quiz-start-last { padding: 1rem 1.25rem; background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--exam-radius-sm); margin-bottom: 1.5rem; font-size: 0.875rem; color: #92400e; }
    .quiz-start-last strong { color: #1e293b; }
    .quiz-start-card .exam-instructions-wrap { margin-bottom: 1.5rem; }
    .quiz-start-card .exam-btn-submit { width: 100%; min-height: 3.25rem; font-size: 1.0625rem; }
    @media (max-width: 900px) {
      .exam-sidebar { flex: 0 0 100%; position: static; order: -1; }
      .exam-layout { flex-direction: column; }
    }
    @media (max-width: 640px) {
      .exam-header { flex-wrap: wrap; justify-content: center; text-align: center; }
      .exam-header-left { align-items: center; }
      .exam-question-card { padding: 1.25rem 1.25rem; margin-bottom: 1.25rem; }
      .exam-choices { gap: 0.75rem; }
      .exam-choice { padding: 1rem 1.25rem; min-height: 3.25rem; }
      .exam-nav-card { flex-direction: column; align-items: stretch; padding: 1.25rem 1.25rem; }
      .exam-nav-card .exam-nav-group { justify-content: center; }
      .exam-nav-card form { display: flex; justify-content: center; width: 100%; }
    }
  </style>
  <style>
    /* Exam content protection: discourage copying (non-visual) */
    .exam-protected {
      -webkit-user-select: none;
      -moz-user-select: none;
      -ms-user-select: none;
      user-select: none;
    }
    .exam-protected ::selection {
      background: transparent;
    }
    .exam-protected * {
      -webkit-user-drag: none;
    }
  </style>
</head>
<body class="font-sans antialiased exam-protected">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="w-full">
    <?php if (isset($_SESSION['error'])): ?>
      <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
        <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?></span>
      </div>
    <?php endif; ?>

    <!-- Quiz header card (same width as other pages) -->
    <section class="mb-4 sm:mb-5">
      <div class="rounded-2xl px-4 sm:px-6 py-4 sm:py-5 bg-gradient-to-r from-[#1665A0] to-[#143D59] text-white shadow-[0_10px_30px_rgba(20,61,89,0.35)] flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0 flex-1">
          <a href="<?php echo htmlspecialchars($headerBackUrl); ?>" class="exam-leave-link flex h-10 w-10 sm:h-11 sm:w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md hover:bg-white/25 transition" aria-label="Leave quiz">
            <i class="bi bi-arrow-left text-lg sm:text-xl" aria-hidden="true"></i>
          </a>
          <span class="flex h-10 w-10 sm:h-11 sm:w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md">
            <i class="bi bi-question-circle text-lg sm:text-xl" aria-hidden="true"></i>
          </span>
          <div class="min-w-0">
            <h1 class="text-lg sm:text-xl md:text-2xl font-bold m-0 tracking-tight truncate"><?php echo h($quiz['title'] ?? 'Quiz'); ?></h1>
            <p class="text-xs sm:text-sm text-white/90 mt-1 mb-0 flex items-center gap-3">
              <?php if (!empty($quiz['subject_name'])): ?>
                <span class="inline-flex items-center gap-1"><i class="bi bi-book" aria-hidden="true"></i><?php echo h($quiz['subject_name']); ?></span>
              <?php endif; ?>
              <span class="inline-flex items-center gap-2 text-white/80 text-[0.75rem] sm:text-xs">
                <span class="inline-flex items-center gap-1"><i class="bi bi-list-ol"></i> <?php echo $totalQuestions; ?> questions</span>
                <span class="inline-flex items-center gap-1"><i class="bi bi-clock"></i> <?php echo h($timeLimitLabel); ?></span>
              </span>
            </p>
          </div>
        </div>
      </div>
    </section>

    <?php if ($showResult && $result):
      $score = (float)$result['score'];
      $resultClass = $score >= 50 ? 'result-pass' : 'result-fail';
      $resultLabel = $score >= 50 ? 'Passed' : 'Failed';
      $backUrl = $subjectId > 0 ? 'student_subject.php?subject_id='.(int)$subjectId.'#quizzers' : 'student_subjects.php';
      // Personal best + time insight (compare with previous attempts)
      $currentAttemptId = (int)($result['attempt_id'] ?? 0);
      $bestBefore = null;
      $currentTimeSpent = null;
      $prevTimeSum = 0;
      $prevTimeCount = 0;
      foreach ($quizHistoryAttempts as $h) {
        $s = isset($h['score']) ? (float)$h['score'] : null;
        $t = isset($h['time_spent_seconds']) ? (int)$h['time_spent_seconds'] : 0;
        if ($currentAttemptId && (int)$h['attempt_id'] === $currentAttemptId) {
          $currentTimeSpent = $t;
          continue;
        }
        if ($s !== null) {
          if ($bestBefore === null || $s > $bestBefore) $bestBefore = $s;
        }
        if ($t > 0) {
          $prevTimeSum += $t;
          $prevTimeCount++;
        }
      }
      $isPersonalBest = ($bestBefore !== null && $score > $bestBefore);
      $prevAvgTime = $prevTimeCount > 0 ? (int)round($prevTimeSum / $prevTimeCount) : null;
      function formatSecondsShort(int $sec): string {
        if ($sec <= 0) return '0s';
        $m = intdiv($sec, 60);
        $s = $sec % 60;
        if ($m && $s) return $m . 'm ' . $s . 's';
        if ($m) return $m . 'm';
        return $s . 's';
      }
    ?>
      <div class="exam-page-container exam-page-container-result">
        <div class="exam-result-inner">
        <header class="exam-page-header">
          <h1><i class="bi bi-trophy-fill text-[#4154f1]"></i> <?php echo h($quiz['title']); ?></h1>
        </header>
        <div class="exam-question-card result-card w-full text-center mb-4 <?php echo $resultClass; ?>">
          <div class="result-card-inner">
            <div class="flex flex-col items-center gap-2 mb-4">
              <div class="w-12 h-12 rounded-full flex items-center justify-center bg-white/80 shadow-sm">
                <i class="bi bi-trophy-fill text-[#f97373] text-xl"></i>
              </div>
              <div class="exam-question-label"><?php echo h($quiz['subject_name']); ?> — Quiz Complete</div>
            </div>
            <span class="result-badge"><?php echo h($resultLabel); ?></span>
            <h2 class="text-2xl font-bold text-[#1e293b] mb-2"><i class="bi bi-bar-chart-fill mr-2"></i>Results</h2>
            <div class="text-5xl font-extrabold mb-3 result-score tracking-tight"><?php echo number_format($score, 0); ?>%</div>
            <?php $scoreWidth = max(4, min(100, (int)round($score))); ?>
            <div class="w-full max-w-xs mx-auto h-2 rounded-full bg-white/70 overflow-hidden mb-4">
              <div class="h-full rounded-full" style="width: <?php echo $scoreWidth; ?>%; background: linear-gradient(90deg, #3393ff, #60a5fa);"></div>
            </div>
            <p class="text-[#64748b] mb-3 text-sm sm:text-base">
              You got
              <strong class="text-[#1e293b]"><?php echo (int)$result['correct']; ?></strong>
              out of
              <strong class="text-[#1e293b]"><?php echo (int)$result['total']; ?></strong>
              questions correct.
            </p>
            <?php if ($isPersonalBest): ?>
              <p class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 text-xs font-semibold mb-3 shadow-sm">
                <i class="bi bi-stars"></i>
                New personal best score!
              </p>
            <?php endif; ?>
            <?php if ($currentTimeSpent !== null && $currentTimeSpent > 0 && $prevAvgTime !== null && $prevAvgTime > 0): ?>
              <?php
                $faster = $currentTimeSpent < $prevAvgTime;
                $diffSec = abs($currentTimeSpent - $prevAvgTime);
              ?>
              <p class="text-[0.7rem] sm:text-xs text-[#64748b] mb-3">
                You finished in <strong><?php echo formatSecondsShort($currentTimeSpent); ?></strong>.
                Your previous average was <strong><?php echo formatSecondsShort($prevAvgTime); ?></strong>,
                so this attempt was <strong><?php echo formatSecondsShort($diffSec); ?></strong>
                <?php echo $faster ? 'faster' : 'slower'; ?> than before.
              </p>
            <?php endif; ?>
            <div class="result-stats-row text-xs sm:text-sm text-[#64748b]">
              <?php
                $correctCount = (int)$result['correct'];
                $totalCount = (int)$result['total'];  
                $incorrectCount = max(0, $totalCount - $correctCount);
              ?>
              <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/70 text-[#0f172a] font-semibold">
                <i class="bi bi-check-circle-fill result-stat-correct"></i>
                <?php echo $correctCount; ?> correct
              </span>
              <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/60">
                <i class="bi bi-x-circle-fill result-stat-wrong"></i>
                <?php echo $incorrectCount; ?> incorrect
              </span>
              <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/60">
                <i class="bi bi-question-circle result-stat-total"></i>
                <?php echo $totalCount; ?> questions
              </span>
            </div>
            <!-- Section 2: primary actions neatly attached under summary -->
            <div class="result-actions-bar mt-4 pt-3 border-t border-[#dbeafe]">
              <a href="student_take_quiz.php?quiz_id=<?php echo $quizId; ?>&subject_id=<?php echo $subjectId; ?>&retake=1" class="result-actions-primary">
                <i class="bi bi-arrow-repeat"></i>
                Take Again
              </a>
              <a href="#reviewAnswers" class="result-actions-ghost">
                <i class="bi bi-journal-text"></i>
                Review Answers
              </a>
              <button type="button"
                      class="result-actions-ghost js-open-quiz-history"
                      data-target="quizHistoryWrap">
                <i class="bi bi-clock-history"></i>
                View History
              </button>
            </div>
          </div>
        </div>
      <?php if (!empty($reviewQuestions)): ?>
      <div class="exam-question-card w-full mb-5" id="reviewAnswers">
        <h3 class="text-lg font-bold text-[#1e293b] mb-4"><i class="bi bi-journal-text text-[#4154f1] mr-2"></i>Review answers</h3>
        <p class="text-[#64748b] text-sm mb-4">Review each question, your answer, and the correct answer below.</p>
        <div class="space-y-5">
          <?php foreach ($reviewQuestions as $i => $q):
            $choices = get_question_choices($q);
            $sel = $q['selected_answer'] ?? '';
            $correctAns = $q['correct_answer'] ?? '';
            $isCorrect = !empty($q['is_correct']);
          ?>
            <div class="border rounded-xl p-5 <?php echo $isCorrect ? 'review-item-correct' : 'review-item-wrong'; ?>">
              <div class="text-xs font-bold uppercase tracking-wide text-[#64748b] mb-1">Question <?php echo $i + 1; ?> of <?php echo count($reviewQuestions); ?></div>
              <div class="text-base font-semibold text-[#1e293b] mb-4 leading-relaxed"><?php echo renderQuizRichText($q['question_text']); ?></div>
              <div class="space-y-2 mb-4">
                <?php foreach ($choices as $letter => $choiceText): ?>
                  <?php
                    $isYourAnswer = ($sel === $letter);
                    $isCorrectChoice = ($correctAns === $letter);
                  ?>
                  <div class="flex items-start gap-2 p-3 rounded-lg border <?php
                    if ($isCorrectChoice) echo 'review-correct-choice';
                    elseif ($isYourAnswer && !$isCorrect) echo 'bg-[#fef2f2] border-[#dc2626]'; 
                    else echo 'bg-white border-[#e2e8f0]';
                  ?>">
                    <span class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-sm font-bold review-choice-letter <?php echo $isCorrectChoice ? 'bg-[#059669] text-white' : ($isYourAnswer && !$isCorrect ? 'bg-[#dc2626] text-white' : 'bg-[#e2e8f0] text-[#64748b]'); ?>"><?php echo $letter; ?></span>
                    <div class="text-[#1e293b] flex-1 quiz-rich-text"><?php echo renderQuizRichText($choiceText); ?></div>
                    <?php if ($isYourAnswer && $isCorrectChoice): ?>
                      <span class="ml-auto review-correct-label text-sm"><i class="bi bi-check-circle-fill"></i> Your answer · Correct</span>
                    <?php elseif ($isCorrectChoice): ?>
                      <span class="ml-auto review-correct-label text-sm"><i class="bi bi-check-circle-fill"></i> Correct answer</span>
                    <?php elseif ($isYourAnswer): ?>
                      <span class="ml-auto text-[#dc2626] font-semibold text-sm"><i class="bi bi-x-circle-fill"></i> Your answer · Wrong</span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if (!empty(trim((string)($q['explanation'] ?? '')))): ?>
                <div class="pt-3 mt-3 border-t border-[#e2e8f0]">
                  <p class="text-xs font-bold uppercase tracking-wide text-[#64748b] mb-1">Explanation</p>
                  <div class="text-sm text-[#475569] leading-relaxed quiz-rich-text"><?php echo renderQuizRichText($q['explanation']); ?></div>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($quizHistoryAttempts)): ?>
      <div class="quiz-history-wrap w-full mt-6" id="quizHistoryWrap">
        <button type="button" class="quiz-history-toggle" id="quizHistoryToggle" aria-expanded="false">
          <span><i class="bi bi-clock-history mr-2"></i>Quiz History — Past attempts for this quiz</span>
          <i class="bi bi-chevron-down"></i>
        </button>
        <div class="quiz-history-body">
          <div class="quiz-history-accordion">
            <?php foreach ($quizHistoryAttempts as $h): 
              $hScore = (float)$h['score'];
              $hScoreClass = $hScore >= 50 ? 'quiz-history-score-pass' : 'quiz-history-score-fail';
              $hSpent = (int)($h['time_spent_seconds'] ?? 0);
              $hMins = floor($hSpent / 60);
              $hSecs = $hSpent % 60;
              $hTimeStr = $hMins > 0 ? $hMins . 'm ' . $hSecs . 's' : ($hSecs . 's');
              $hDateStr = !empty($h['submitted_at']) ? date('M j, Y g:i A', strtotime($h['submitted_at'])) : '—';
            ?>
            <div class="quiz-history-attempt" id="history-attempt-<?php echo (int)$h['attempt_id']; ?>">
              <button type="button" class="quiz-history-attempt-head" data-attempt-id="<?php echo (int)$h['attempt_id']; ?>">
                <div class="attempt-head-left">
                  <div class="attempt-head-title">Attempt <?php echo (int)$h['attempt_number']; ?></div>
                  <div class="attempt-meta-grid">
                    <div class="attempt-meta-item">
                      <span class="attempt-meta-label">Date &amp; time</span>
                      <span class="attempt-meta-value"><?php echo h($hDateStr); ?></span>
                    </div>
                    <div class="attempt-meta-item">
                      <span class="attempt-meta-label">Score</span>
                      <span class="attempt-meta-value <?php echo $hScoreClass; ?>"><?php echo number_format($hScore, 0); ?>%</span>
                    </div>
                    <div class="attempt-meta-item">
                      <span class="attempt-meta-label">Correct answers</span>
                      <span class="attempt-meta-value"><?php echo (int)$h['correct_count']; ?> / <?php echo (int)$h['total_count']; ?></span>
                    </div>
                    <div class="attempt-meta-item">
                      <span class="attempt-meta-label">Time spent</span>
                      <span class="attempt-meta-value"><?php echo h($hTimeStr); ?></span>
                    </div>
                  </div>
                </div>
                <i class="bi bi-chevron-down"></i>
              </button>
              <div class="quiz-history-attempt-body">
                <p class="text-[#64748b] text-sm mb-3">Review each question and your answers below.</p>
                <div class="space-y-4">
                  <?php foreach ($h['questions'] as $qi => $hq):
                    $hChoices = get_question_choices($hq);
                    $hSel = $hq['selected_answer'] ?? '';
                    $hCorrectAns = $hq['correct_answer'] ?? '';
                    $hIsCorrect = !empty($hq['is_correct']);
                  ?>
                  <div class="border rounded-xl p-4 <?php echo $hIsCorrect ? 'review-item-correct' : 'review-item-wrong'; ?>">
                    <div class="text-xs font-bold uppercase tracking-wide text-[#64748b] mb-1">Question <?php echo $qi + 1; ?> of <?php echo count($h['questions']); ?></div>
                    <div class="text-base font-semibold text-[#1e293b] mb-3 leading-relaxed"><?php echo renderQuizRichText($hq['question_text']); ?></div>
                    <div class="space-y-2 mb-3">
                      <?php foreach ($hChoices as $letter => $hChoiceText): ?>
                        <?php $isYour = ($hSel === $letter); $isCorrectChoice = ($hCorrectAns === $letter); ?>
                        <div class="flex items-start gap-2 p-2 rounded-lg border <?php echo $isCorrectChoice ? 'review-correct-choice' : ($isYour && !$hIsCorrect ? 'bg-[#fef2f2] border-[#dc2626]' : 'bg-white border-[#e2e8f0]'); ?>">
                          <span class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold review-choice-letter <?php echo $isCorrectChoice ? 'bg-[#059669] text-white' : ($isYour && !$hIsCorrect ? 'bg-[#dc2626] text-white' : 'bg-[#e2e8f0] text-[#64748b]'); ?>"><?php echo $letter; ?></span>
                          <div class="text-sm text-[#1e293b] flex-1 quiz-rich-text"><?php echo renderQuizRichText($hChoiceText); ?></div>
                          <?php if ($isYour && $isCorrectChoice): ?><span class="ml-auto review-correct-label text-xs"><i class="bi bi-check-circle-fill"></i> Your answer · Correct</span>
                          <?php elseif ($isCorrectChoice): ?><span class="ml-auto review-correct-label text-xs"><i class="bi bi-check-circle-fill"></i> Correct answer</span>
                          <?php elseif ($isYour): ?><span class="ml-auto text-[#dc2626] font-semibold text-xs"><i class="bi bi-x-circle-fill"></i> Your answer · Wrong</span><?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <?php if (!empty(trim((string)($hq['explanation'] ?? '')))): ?>
                      <div class="text-xs text-[#64748b] mt-2 pt-2 border-t border-[#e2e8f0] quiz-rich-text"><strong>Explanation:</strong> <?php echo renderQuizRichText($hq['explanation']); ?></div>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <p class="text-[#64748b] text-xs mt-3 text-center"><a href="student_quiz_history.php" class="text-[#4154f1] font-medium hover:underline">View full quiz history</a></p>
        </div>
      </div>
      </div><!-- end exam-result-inner -->
      <?php endif; ?>
      </div><!-- end exam-page-container (result) -->
    <?php elseif ($totalQuestions === 0): ?>
      <div class="exam-page-container">
        <header class="exam-page-header">
          <h1><i class="bi bi-question-circle text-[#4154f1]"></i> <?php echo h($quiz['title']); ?></h1>
          <a href="<?php echo htmlspecialchars($headerBackUrl); ?>" class="exam-back-link"><i class="bi bi-arrow-left"></i> Back</a>
        </header>
        <div class="exam-question-card w-full text-center py-12">
          <i class="bi bi-inbox text-5xl text-[#cbd5e1] block mb-4"></i>
          <p class="text-lg font-semibold text-[#64748b]">No questions available for this quiz.</p>
          <a href="<?php echo $subjectId > 0 ? 'student_subject.php?subject_id='.(int)$subjectId.'#quizzers' : 'student_subjects.php'; ?>" class="mt-4 inline-flex items-center gap-2 exam-btn-prev">Back to Subject</a>
        </div>
      </div>

    <?php elseif ($attemptId <= 0): ?>
      <!-- Start Exam screen -->
      <?php $backUrlStart = $headerBackUrl; ?>
      <div class="exam-page-container exam-page-container--wide">
        <div class="exam-question-card quiz-start-card w-full">
          <div class="quiz-start-hero">
            <div class="exam-question-label"><?php echo h($quiz['subject_name']); ?></div>
            <h2 class="text-2xl font-bold text-[#012970] mb-0"><?php echo h($quiz['title']); ?></h2>
            <div class="quiz-start-meta">
              <span class="quiz-start-meta-item"><i class="bi bi-list-ol"></i> <?php echo $totalQuestions; ?> questions</span>
              <span class="quiz-start-meta-item"><i class="bi bi-clock"></i> <?php echo h($timeLimitLabel); ?></span>
            </div>
          </div>
          <div class="p-5 rounded-xl mb-5 exam-instructions-wrap">
            <div class="exam-instructions-head mb-3">
              <i class="bi bi-lightbulb-fill"></i>
              <span>Instructions</span>
            </div>
            <ul class="exam-instructions-list" style="grid-template-columns: 1fr;">
              <li><i class="bi bi-check-circle-fill"></i> All questions are shown on one page. <strong>Scroll down</strong> to answer each one. You must answer all questions before you can submit.</li>
              <li><i class="bi bi-check-circle-fill"></i> Answers are saved automatically. You can leave and resume later.</li>
              <li><i class="bi bi-check-circle-fill"></i> When time runs out, the quiz will be submitted automatically.</li>
            </ul>
          </div>
          <?php if ($attemptCount > 0 && $lastAttempt): ?>
            <div class="quiz-start-last">
              <strong>Last attempt:</strong> <?php echo number_format((float)$lastAttempt['score'], 0); ?>% — <?php echo (int)$lastAttempt['correct_count']; ?> of <?php echo (int)$lastAttempt['total_count']; ?> correct
            </div>
          <?php endif; ?>
          <div class="flex flex-col sm:flex-row gap-3 justify-center items-stretch sm:items-center">
            <form method="POST" action="student_take_quiz.php?quiz_id=<?php echo $quizId; ?>&subject_id=<?php echo $subjectId; ?>" class="w-full sm:max-w-xs">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="start_attempt" value="1">
              <button type="submit" class="exam-btn-submit bg-[#4154f1] hover:bg-[#2d3fc7] w-full justify-center py-3 text-lg"><i class="bi bi-play-circle-fill"></i> Start Exam</button>
            </form>
            <?php if (!empty($quizHistoryAttempts)): ?>
              <button type="button" class="exam-btn-prev w-full sm:max-w-xs justify-center py-3 text-lg no-underline js-open-quiz-history" data-target="quizHistoryWrapStart">
                <i class="bi bi-clock-history"></i> View History
              </button>
            <?php endif; ?>
          </div>

      <?php if (!empty($quizHistoryAttempts)): ?>
      <div class="quiz-history-wrap quiz-history-inside mt-5" id="quizHistoryWrapStart">
        <button type="button" class="quiz-history-toggle" id="quizHistoryToggleStart" aria-expanded="false">
          <span><i class="bi bi-clock-history mr-2"></i>Quiz History — Past attempts for this quiz</span>
          <i class="bi bi-chevron-down"></i>
        </button>
        <div class="quiz-history-body">
          <div class="quiz-history-accordion">
            <?php foreach ($quizHistoryAttempts as $h): 
              $hScore = (float)$h['score'];
              $hScoreClass = $hScore >= 50 ? 'quiz-history-score-pass' : 'quiz-history-score-fail';
              $hSpent = (int)($h['time_spent_seconds'] ?? 0);
              $hMins = floor($hSpent / 60);
              $hSecs = $hSpent % 60;
              $hTimeStr = $hMins > 0 ? $hMins . 'm ' . $hSecs . 's' : ($hSecs . 's');
              $hDateStr = !empty($h['submitted_at']) ? date('M j, Y g:i A', strtotime($h['submitted_at'])) : '—';
            ?>
            <div class="quiz-history-attempt">
              <button type="button" class="quiz-history-attempt-head">
                <div class="attempt-head-left">
                  <div class="attempt-head-title">Attempt <?php echo (int)$h['attempt_number']; ?></div>
                  <div class="attempt-meta-grid">
                    <div class="attempt-meta-item">
                      <span class="attempt-meta-label">Date &amp; time</span>
                      <span class="attempt-meta-value"><?php echo h($hDateStr); ?></span>
                    </div>
                    <div class="attempt-meta-item">
                      <span class="attempt-meta-label">Score</span>
                      <span class="attempt-meta-value <?php echo $hScoreClass; ?>"><?php echo number_format($hScore, 0); ?>%</span>
                    </div>
                    <div class="attempt-meta-item">
                      <span class="attempt-meta-label">Correct answers</span>
                      <span class="attempt-meta-value"><?php echo (int)$h['correct_count']; ?> / <?php echo (int)$h['total_count']; ?></span>
                    </div>
                    <div class="attempt-meta-item">
                      <span class="attempt-meta-label">Time spent</span>
                      <span class="attempt-meta-value"><?php echo h($hTimeStr); ?></span>
                    </div>
                  </div>
                </div>
                <i class="bi bi-chevron-down"></i>
              </button>
              <div class="quiz-history-attempt-body">
                <p class="text-[#64748b] text-sm mb-3">Review each question and your answers below.</p>
                <div class="space-y-4">
                  <?php foreach ($h['questions'] as $qi => $hq):
                    $hChoices = get_question_choices($hq);
                    $hSel = $hq['selected_answer'] ?? '';
                    $hCorrectAns = $hq['correct_answer'] ?? '';
                    $hIsCorrect = !empty($hq['is_correct']);
                  ?>
                  <div class="border rounded-xl p-4 <?php echo $hIsCorrect ? 'review-item-correct' : 'review-item-wrong'; ?>">
                    <div class="text-xs font-bold uppercase tracking-wide text-[#64748b] mb-1">Question <?php echo $qi + 1; ?> of <?php echo count($h['questions']); ?></div>
                    <div class="text-base font-semibold text-[#1e293b] mb-3 leading-relaxed"><?php echo renderQuizRichText($hq['question_text']); ?></div>
                    <div class="space-y-2 mb-3">
                      <?php foreach ($hChoices as $letter => $hChoiceText): ?>
                        <?php $isYour = ($hSel === $letter); $isCorrectChoice = ($hCorrectAns === $letter); ?>
                        <div class="flex items-start gap-2 p-2 rounded-lg border <?php echo $isCorrectChoice ? 'review-correct-choice' : ($isYour && !$hIsCorrect ? 'bg-[#fef2f2] border-[#dc2626]' : 'bg-white border-[#e2e8f0]'); ?>">
                          <span class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold review-choice-letter <?php echo $isCorrectChoice ? 'bg-[#059669] text-white' : ($isYour && !$hIsCorrect ? 'bg-[#dc2626] text-white' : 'bg-[#e2e8f0] text-[#64748b]'); ?>"><?php echo $letter; ?></span>
                          <div class="text-sm text-[#1e293b] flex-1 quiz-rich-text"><?php echo renderQuizRichText($hChoiceText); ?></div>
                          <?php if ($isYour && $isCorrectChoice): ?><span class="ml-auto review-correct-label text-xs"><i class="bi bi-check-circle-fill"></i> Your answer · Correct</span>
                          <?php elseif ($isCorrectChoice): ?><span class="ml-auto review-correct-label text-xs"><i class="bi bi-check-circle-fill"></i> Correct answer</span>
                          <?php elseif ($isYour): ?><span class="ml-auto text-[#dc2626] font-semibold text-xs"><i class="bi bi-x-circle-fill"></i> Your answer · Wrong</span><?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <?php if (!empty(trim((string)($hq['explanation'] ?? '')))): ?>
                      <div class="text-xs text-[#64748b] mt-2 pt-2 border-t border-[#e2e8f0] quiz-rich-text"><strong>Explanation:</strong> <?php echo renderQuizRichText($hq['explanation']); ?></div>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <p class="text-[#64748b] text-xs mt-4 text-center"><a href="student_quiz_history.php" class="text-[#4154f1] font-medium hover:underline">View full quiz history</a></p>
        </div>
      </div>
      <?php endif; ?>
        </div><!-- end quiz-start-card -->
      </div><!-- end exam-page-container (start) -->

    <?php else: ?>
      <?php
        $min = floor($remainingSeconds / 60);
        $sec = $remainingSeconds % 60;
        $timerDisplay = $remainingSeconds >= 3600
          ? sprintf('%d:%02d:%02d', floor($remainingSeconds / 3600), (int)($remainingSeconds / 60) % 60, $sec)
          : sprintf('%02d:%02d', $min, $sec);
      ?>
      <?php if ($answeredCount > 0): ?>
      <div class="exam-resume-banner" id="examResumeBanner" role="status">
        <div class="exam-resume-banner-inner">
          <i class="bi bi-info-circle-fill"></i>
          <span>You're resuming this quiz. Your progress is saved. You can leave anytime and come back later.</span>
        </div>
        <button type="button" class="exam-resume-banner-dismiss" onclick="this.closest('.exam-resume-banner').classList.add('dismissed')" aria-label="Dismiss">×</button>
      </div>
      <?php endif; ?>
      <div class="exam-page-container">
      <!-- Sticky header: quiz title, subject, progress, leave link -->
      <div class="exam-bar mb-4">
        <div class="exam-header">
          <div class="exam-header-left">
            <span class="exam-title"><?php echo h($quiz['title']); ?></span>
            <span class="exam-subject"><i class="bi bi-book mr-1"></i><?php echo h($quiz['subject_name']); ?></span>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <div class="exam-q-badge"><strong id="answeredCountNum"><?php echo $answeredCount; ?></strong> of <strong><?php echo $totalQuestions; ?></strong> answered</div>
          </div>
        </div>
        <div class="exam-progress-wrap">
          <div class="exam-progress-bar">
            <div class="exam-progress-fill" id="progressBar" style="width: <?php echo $totalQuestions > 0 ? round(($answeredCount / $totalQuestions) * 100) : 0; ?>%"></div>
          </div>
          <div class="exam-progress-label" id="progressText"><?php echo $answeredCount; ?> of <?php echo $totalQuestions; ?> answered</div>
        </div>
      </div>

      <div class="exam-layout exam-protected">
        <div class="exam-main">
      <?php foreach ($allQuestions as $idx => $q): $num = $idx + 1; ?>
      <div class="exam-question-card" id="q<?php echo $num; ?>">
        <div class="exam-question-label">Question <?php echo $num; ?> of <?php echo $totalQuestions; ?></div>
        <div class="exam-question-text mb-6"><?php echo renderQuizRichText($q['question_text']); ?></div>
        <div class="exam-choices">
          <?php $choices = get_question_choices($q); foreach ($choices as $letter => $choiceText): ?>
            <?php $isSelected = (isset($savedAnswers[$q['question_id']]) && $savedAnswers[$q['question_id']] === $letter); ?>
            <label class="exam-choice <?php echo $isSelected ? 'selected' : ''; ?>">
              <input type="radio" name="answer_<?php echo (int)$q['question_id']; ?>" value="<?php echo $letter; ?>" class="sr-only" data-question-id="<?php echo (int)$q['question_id']; ?>"
                <?php echo $isSelected ? ' checked' : ''; ?>>
              <span class="exam-choice-letter"><?php echo $letter; ?></span>
              <div class="exam-choice-text"><?php echo renderQuizRichText($choiceText); ?></div>
              <span class="exam-choice-check"><i class="bi bi-check"></i></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <div class="exam-nav-card">
        <form method="POST" id="submitForm" action="student_take_quiz.php?quiz_id=<?php echo $quizId; ?>&subject_id=<?php echo $subjectId; ?>" class="w-full">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="submit_quiz" value="1">
          <input type="hidden" name="attempt_id" value="<?php echo $attemptId; ?>">
          <button type="submit" id="submitQuizBtn" class="exam-btn-submit w-full justify-center" <?php echo $allAnswered ? '' : 'disabled'; ?>>
            <i class="bi bi-check-circle-fill"></i>
            <?php echo $allAnswered ? 'Submit quiz' : 'Answer all questions to submit'; ?>
          </button>
        </form>
      </div>
        </div>

        <!-- Right sidebar: circular timer + Questions list -->
        <aside class="exam-sidebar">
          <div class="exam-sidebar-card mb-4">
            <div class="exam-sidebar-title text-center">Timer remaining</div>
            <div class="exam-timer-circle-wrap" id="examTimerCircle" data-remaining="<?php echo $remainingSeconds; ?>" data-total="<?php echo (int)$timeLimitSeconds; ?>">
              <svg viewBox="0 0 120 120" aria-hidden="true">
                <circle class="exam-timer-circle-track" cx="60" cy="60" r="52"/>
                <circle class="exam-timer-circle-progress" id="examTimerCircleProgress" cx="60" cy="60" r="52" stroke-dasharray="327" stroke-dashoffset="0"/>
              </svg>
              <div class="exam-timer-circle-inner">
                <span class="exam-timer-circle-value" id="examTimerCircleValue"><?php echo $timerDisplay; ?></span>
                <span class="exam-timer-circle-label">MM : SS</span>
              </div>
            </div>
            <p class="text-center text-xs text-[#64748b] px-3 pb-3">Limit: <?php echo h($timeLimitLabel); ?></p>
          </div>
          <div class="exam-sidebar-card">
            <div class="exam-sidebar-title flex items-center justify-between flex-wrap gap-1">
              <span>Questions</span>
              <span class="text-xs font-normal text-[#64748b]"><?php echo $totalQuestions; ?> total</span>
            </div>
            <div class="exam-sidebar-section" id="examQListSection">
              <button type="button" class="exam-sidebar-section-head" id="examQListTrigger" aria-expanded="true">
                <span>Jump to question</span>
                <i class="bi bi-chevron-up"></i>
              </button>
              <div class="exam-q-list">
                <?php for ($n = 1; $n <= $totalQuestions; $n++): ?>
                  <?php $qIdForN = $questionIds[$n - 1] ?? 0; $answered = isset($savedAnswers[$qIdForN]); ?>
                  <a href="#q<?php echo $n; ?>" class="<?php echo $answered ? 'answered' : ''; ?>" data-question-id="<?php echo $qIdForN; ?>">
                    <span class="q-num"><?php echo $n; ?></span>
                    <span>Question <?php echo $n; ?></span>
                    <?php if ($answered): ?><i class="bi bi-check-circle-fill q-check"></i><?php endif; ?>
                  </a>
                <?php endfor; ?>
              </div>
            </div>
          </div>
        </aside>
      </div>
      </div><!-- end exam-page-container (in-progress) -->

      <div class="exam-saved-toast" id="examSavedToast" aria-live="polite"><i class="bi bi-check-circle-fill mr-1"></i> Answer saved</div>
      <div class="exam-time-warning-toast" id="examTimeWarningToast" aria-live="assertive" role="alert"></div>

      <!-- Security modal: shown on tab switch / window blur -->
      <div class="quiz-submit-overlay" id="examSecurityOverlay" aria-live="assertive" aria-modal="true" role="dialog">
        <div class="quiz-submit-card">
          <div class="quiz-submit-spinner"></div>
          <div class="quiz-submit-title">Screen activity detected</div>
          <p class="quiz-submit-text">Screen activity was detected (such as switching tabs or windows). This action is not allowed during the quiz.</p>
          <button type="button" id="examSecurityContinue" class="exam-btn-submit w-full justify-center mt-4">
            <i class="bi bi-shield-check"></i> Continue quiz
          </button>
        </div>
      </div>

      <!-- Global quiz submit loader -->
      <div class="quiz-submit-overlay" id="quizSubmitOverlay" aria-live="assertive" aria-busy="true">
        <div class="quiz-submit-card">
          <div class="quiz-submit-spinner"></div>
          <div class="quiz-submit-title">Submitting your quiz…</div>
          <p class="quiz-submit-text">Please wait a moment while we save your answers and calculate your score.</p>
        </div>
      </div>

      <!-- App modal: Leave quiz (dark gradient format) -->
      <div class="quiz-confirm-overlay" id="examLeaveConfirmOverlay" role="dialog" aria-modal="true" aria-labelledby="examLeaveConfirmTitle" aria-describedby="examLeaveConfirmMessage">
        <div class="quiz-confirm-card">
          <div class="quiz-confirm-icon-wrap warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
          </div>
          <div class="quiz-confirm-header" id="examLeaveConfirmTitle">Leave quiz?</div>
          <div class="quiz-confirm-body" id="examLeaveConfirmMessage">
            Are you sure you want to leave the quiz? Your progress will be saved and you can resume later.
          </div>
          <div class="quiz-confirm-actions">
            <button type="button" class="quiz-confirm-btn quiz-confirm-btn-cancel" id="examLeaveConfirmCancel">Stay</button>
            <button type="button" class="quiz-confirm-btn quiz-confirm-btn-primary" id="examLeaveConfirmLeave">Leave</button>
          </div>
        </div>
      </div>

      <script>
      (function() {
        // Client-side only: custom modal for leave confirmation (no browser "localhost says" dialog).
        var LEAVE_MSG = 'Are you sure you want to leave the quiz? Your progress will be saved.';
        function setLeavingAllowed(allow) {
          window.__examLeavingAllowed = !!allow;
        }
        setLeavingAllowed(false);
        window.addEventListener('beforeunload', function(e) {
          if (window.__examLeavingAllowed) return;
          e.preventDefault();
          e.returnValue = LEAVE_MSG;
          return LEAVE_MSG;
        });
        var leaveOverlay = document.getElementById('examLeaveConfirmOverlay');
        var leaveCancelBtn = document.getElementById('examLeaveConfirmCancel');
        var leaveLeaveBtn = document.getElementById('examLeaveConfirmLeave');
        var leaveUrl = '';
        function hideLeaveModal() {
          if (leaveOverlay) leaveOverlay.classList.remove('show');
        }
        function showLeaveModal(url) {
          leaveUrl = url || '';
          if (leaveOverlay) leaveOverlay.classList.add('show');
        }
        document.addEventListener('click', function(e) {
          var link = e.target.closest('a.exam-leave-link');
          if (!link || !link.href) return;
          e.preventDefault();
          showLeaveModal(link.getAttribute('href'));
        });
        if (leaveCancelBtn) leaveCancelBtn.addEventListener('click', hideLeaveModal);
        if (leaveLeaveBtn) leaveLeaveBtn.addEventListener('click', function() {
          setLeavingAllowed(true);
          hideLeaveModal();
          if (leaveUrl) window.location.href = leaveUrl;
        });
        if (leaveOverlay) leaveOverlay.addEventListener('click', function(e) {
          if (e.target === leaveOverlay) hideLeaveModal();
        });
        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape' && leaveOverlay && leaveOverlay.classList.contains('show')) hideLeaveModal();
        });

        // Suspicious activity protection: tab switch / window blur / devtools
        var SECURITY_MODE = 'pause'; // 'pause' or 'autosubmit'
        var securityOverlay = document.getElementById('examSecurityOverlay');
        var securityContinue = document.getElementById('examSecurityContinue');
        var securityReason = '';
        function showSecurityOverlay(reason) {
          if (!securityOverlay) return;
          securityOverlay.classList.add('show');
          window.__examTimerPaused = true;
          securityReason = reason || '';
        }
        function hideSecurityOverlay() {
          if (!securityOverlay) return;
          securityOverlay.classList.remove('show');
          window.__examTimerPaused = false;
        }
        if (securityContinue) {
          securityContinue.addEventListener('click', hideSecurityOverlay);
        }
        document.addEventListener('visibilitychange', function() {
          if (document.hidden) {
            if (SECURITY_MODE === 'autosubmit') {
              // Auto-submit best-effort when student leaves the tab
              setLeavingAllowed(true);
              form && form.submit();
            } else {
              showSecurityOverlay('visibility');
            }
          }
        });
        window.addEventListener('blur', function() {
          if (SECURITY_MODE === 'autosubmit') {
            setLeavingAllowed(true);
            form && form.submit();
          } else {
            showSecurityOverlay('blur');
          }
        });
        window.addEventListener('focus', function() {
          // don't auto-hide; user must click Continue to acknowledge
        });

        // Basic DevTools detection (best-effort)
        function detectDevToolsOnce() {
          var threshold = 160;
          var widthDiff = Math.abs((window.outerWidth || 0) - (window.innerWidth || 0));
          var heightDiff = Math.abs((window.outerHeight || 0) - (window.innerHeight || 0));
          if (widthDiff > threshold || heightDiff > threshold) {
            if (SECURITY_MODE === 'autosubmit') {
              setLeavingAllowed(true);
              form && form.submit();
            } else {
              showSecurityOverlay('devtools');
            }
          }
        }
        window.addEventListener('resize', detectDevToolsOnce);
        window.addEventListener('keydown', function(e) {
          var key = (e.key || '').toLowerCase();
          var ctrlLike = e.ctrlKey || e.metaKey;
          var devToolsCombo =
            key === 'f12' ||
            (ctrlLike && e.shiftKey && (key === 'i' || key === 'j' || key === 'c'));
          if (devToolsCombo) {
            e.preventDefault();
            if (SECURITY_MODE === 'autosubmit') {
              setLeavingAllowed(true);
              form && form.submit();
            } else {
              showSecurityOverlay('devtools');
            }
          }
        }, true);

        var circleTimer = document.getElementById('examTimerCircle');
        var circleTimerValue = document.getElementById('examTimerCircleValue');
        var circleProgress = document.getElementById('examTimerCircleProgress');
        var form = document.getElementById('submitForm');
        var submitOverlay = document.getElementById('quizSubmitOverlay');
        var submitBtn = document.getElementById('submitQuizBtn');
        var submitIntercepted = false;
        if (!circleTimer || !form) return;
        form.addEventListener('submit', function(e) {
          // First time: show loading modal for a short moment before real submit
          if (!submitIntercepted) {
            e.preventDefault();
            submitIntercepted = true;
            setLeavingAllowed(true);
            if (submitBtn) {
              submitBtn.disabled = true;
              submitBtn.classList.add('opacity-90', 'cursor-not-allowed');
              submitBtn.innerHTML = '<span class="inline-flex items-center gap-2"><span class="inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin-fast"></span>Submitting…</span>';
            }
            if (submitOverlay) {
              submitOverlay.classList.add('show');
            }
            // Give the user time to see the modal, then perform the real submit
            setTimeout(function() {
              form.submit();
            }, 900);
            return;
          }
          // Second time (programmatic submit) – let it pass through immediately
          setLeavingAllowed(true);
        });
        var serverRemaining = parseInt(circleTimer.getAttribute('data-remaining'), 10);
        var totalSec = parseInt(circleTimer.getAttribute('data-total'), 10) || Math.max(1, serverRemaining);
        var circumference = 327;
        var expires = new Date(Date.now() + (isNaN(serverRemaining) ? 0 : serverRemaining) * 1000);
        var lastSyncAt = Date.now();
        var SYNC_INTERVAL_MS = 30000;
        var timeWarned = { 300: false, 60: false, 30: false };
        var timeWarningToast = document.getElementById('examTimeWarningToast');
        function showTimeWarning(text, isDanger) {
          if (!timeWarningToast) return;
          timeWarningToast.textContent = text;
          timeWarningToast.className = 'exam-time-warning-toast show ' + (isDanger ? 'danger' : 'warning');
          setTimeout(function() {
            timeWarningToast.classList.remove('show');
          }, 5000);
        }
        function formatTime(sec) {
          var h = Math.floor(sec / 3600);
          var m = Math.floor((sec % 3600) / 60);
          var s = sec % 60;
          if (h > 0) return h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
          return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }
        function syncTimerFromServer() {
          var fd = new FormData();
          fd.append('action', 'get_time');
          fd.append('attempt_id', attemptId);
          fetch('quiz_ajax.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
              if (data.ok && typeof data.remaining_seconds === 'number' && data.remaining_seconds >= 0) {
                expires = new Date(Date.now() + data.remaining_seconds * 1000);
                lastSyncAt = Date.now();
              }
            });
        }
        window.__examTimerPaused = false;

        function updateTimer() {
          if (window.__examTimerPaused) {
            setTimeout(updateTimer, 500);
            return;
          }
          if (Date.now() - lastSyncAt >= SYNC_INTERVAL_MS) syncTimerFromServer();
          var now = new Date();
          var rem = Math.max(0, Math.floor((expires - now) / 1000));
          if (circleTimerValue) circleTimerValue.textContent = formatTime(rem);
          var pct = totalSec > 0 ? rem / totalSec : 0;
          if (circleProgress) circleProgress.setAttribute('stroke-dashoffset', circumference * (1 - pct));
          circleTimer.classList.remove('warning', 'danger');
          if (rem <= 60) circleTimer.classList.add('danger');
          else if (rem <= 300) circleTimer.classList.add('warning');
          if (rem === 300 && !timeWarned[300]) { timeWarned[300] = true; showTimeWarning('5 minutes remaining.', false); }
          if (rem === 60 && !timeWarned[60]) { timeWarned[60] = true; showTimeWarning('1 minute remaining!', true); }
          if (rem === 30 && !timeWarned[30]) { timeWarned[30] = true; showTimeWarning('30 seconds left! Submit soon.', true); }
          if (rem <= 0) { setLeavingAllowed(true); form.submit(); return; }
          setTimeout(updateTimer, 1000);
        }
        updateTimer();

        var qListSection = document.getElementById('examQListSection');
        var qListTrigger = document.getElementById('examQListTrigger');
        if (qListSection && qListTrigger) {
          qListTrigger.addEventListener('click', function() {
            qListSection.classList.toggle('collapsed');
            qListTrigger.setAttribute('aria-expanded', !qListSection.classList.contains('collapsed'));
          });
        }
        var toast = document.getElementById('examSavedToast');
        function showSavedToast() {
          if (!toast) return;
          toast.classList.add('show');
          setTimeout(function() { toast.classList.remove('show'); }, 2200);
        }

        var csrf = '<?php echo addslashes($csrf); ?>';
        var attemptId = <?php echo $attemptId; ?>;
        var progressEl = document.getElementById('progressText');
        var progressBar = document.getElementById('progressBar');
        var total = <?php echo $totalQuestions; ?>;

        var answeredCountEl = document.getElementById('answeredCountNum');
        function updateProgressUi(answered) {
          if (progressBar) progressBar.style.width = Math.round((answered / total) * 100) + '%';
          if (progressEl) progressEl.textContent = answered + ' of ' + total + ' answered';
          if (answeredCountEl) answeredCountEl.textContent = answered;
          if (submitBtn) {
            if (answered >= total) {
              submitBtn.disabled = false;
              submitBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Submit quiz';
            }
          }
        }

        document.querySelectorAll('input[name^="answer_"]').forEach(function(radio) {
          radio.addEventListener('change', function() {
            var qId = this.getAttribute('data-question-id');
            var val = this.value;
            var card = radio.closest('.exam-question-card');
            if (card) card.querySelectorAll('.exam-choice').forEach(function(c) { c.classList.remove('selected'); });
            radio.closest('.exam-choice').classList.add('selected');
            var fd = new FormData();
            fd.append('action', 'save_answer');
            fd.append('csrf_token', csrf);
            fd.append('attempt_id', attemptId);
            fd.append('question_id', qId);
            fd.append('selected_answer', val);
            fetch('quiz_ajax.php', { method: 'POST', body: fd })
              .then(function(r) { return r.json(); })
              .then(function(data) {
                if (data.ok && typeof data.answered_count !== 'undefined') {
                  updateProgressUi(data.answered_count);
                  var link = document.querySelector('.exam-q-list a[data-question-id="' + qId + '"]');
                  if (link && !link.classList.contains('answered')) {
                    link.classList.add('answered');
                    if (!link.querySelector('.q-check')) {
                      var ic = document.createElement('i');
                      ic.className = 'bi bi-check-circle-fill q-check';
                      link.appendChild(ic);
                    }
                  }
                  showSavedToast();
                }
              });
          });
        });

        document.addEventListener('keydown', function(e) {
          if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
          var sb = document.getElementById('submitQuizBtn');
          if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey && sb && !sb.disabled) {
            e.preventDefault();
            sb.click();
          }
        });
      })();

      // Basic client-side protections: disable right-click, selection, and common shortcuts inside exam
      (function() {
        var examRoot = document.querySelector('.exam-layout') || document.body;
        if (!examRoot) return;
        function isInputLike(el) {
          if (!el) return false;
          var tag = el.tagName ? el.tagName.toLowerCase() : '';
          var type = (el.type || '').toLowerCase();
          return tag === 'input' || tag === 'textarea' || el.isContentEditable || type === 'text' || type === 'password';
        }
        examRoot.addEventListener('contextmenu', function(e) {
          if (!isInputLike(e.target)) {
            e.preventDefault();
          }
        });
        examRoot.addEventListener('selectstart', function(e) {
          if (!isInputLike(e.target)) {
            e.preventDefault();
          }
        });
        window.addEventListener('keydown', function(e) {
          var ctrlLike = e.ctrlKey || e.metaKey;
          var key = (e.key || '').toLowerCase();
          if (ctrlLike && ['c', 'x', 's', 'p', 'u', 'a'].indexOf(key) !== -1 && !isInputLike(e.target)) {
            e.preventDefault();
          }
        }, true);
      })();
      </script>
    <?php endif; ?>
  </div>
<script>
(function() {
  document.querySelectorAll('.quiz-history-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var wrap = btn.closest('.quiz-history-wrap');
      if (wrap) wrap.classList.toggle('open');
      btn.setAttribute('aria-expanded', wrap && wrap.classList.contains('open'));
    });
  });
  document.querySelectorAll('.quiz-history-attempt-head').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var attempt = btn.closest('.quiz-history-attempt');
      if (attempt) attempt.classList.toggle('open');
    });
  });
  // Buttons like "View History" should open the embedded history section and scroll to it
  document.querySelectorAll('.js-open-quiz-history').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var targetId = btn.getAttribute('data-target');
      if (!targetId) return;
      var wrap = document.getElementById(targetId);
      if (!wrap) return;
      wrap.classList.add('open');
      var toggle = wrap.querySelector('.quiz-history-toggle');
      if (toggle) toggle.setAttribute('aria-expanded', 'true');
      wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
})();
</script>
</main>
</div>
</body>
</html>
