<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/quiz_helpers.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';

$pageTitle = 'Edit exam';
$uid = getCurrentUserId();
$examId = sanitizeInt($_GET['id'] ?? 0);
$csrf = generateCSRFToken();
$error = null;
$draftSaved = !empty($_SESSION['draft_saved_message']);
if ($draftSaved) {
    unset($_SESSION['draft_saved_message']);
}

$dupFrom = sanitizeInt($_GET['duplicate_from'] ?? 0);
$dupSourceExam = null;
if ($dupFrom > 0 && $examId <= 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM college_exams WHERE exam_id=? AND created_by=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $dupFrom, $uid);
    mysqli_stmt_execute($stmt);
    $srcDup = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($srcDup) {
        $dupSourceExam = $srcDup;
        $exam = null;
        $examId = 0;
    }
}

$exam = null;
if ($examId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM college_exams WHERE exam_id=? AND created_by=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $examId, $uid);
    mysqli_stmt_execute($stmt);
    $exam = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$exam) {
        $_SESSION['error'] = 'Exam not found.';
        header('Location: professor_exams.php');
        exit;
    }
}

$examIsRunning = false;
if ($exam) {
    $nowTs = time();
    $openTs = !empty($exam['available_from']) ? strtotime((string)$exam['available_from']) : null;
    $deadlineTs = !empty($exam['deadline']) ? strtotime((string)$exam['deadline']) : null;
    $isPublishedExam = !empty($exam['is_published']);
    $withinOpen = ($openTs === false || $openTs === null || $openTs <= $nowTs);
    $withinDeadline = ($deadlineTs === false || $deadlineTs === null || $deadlineTs >= $nowTs);
    $examIsRunning = $isPublishedExam && $withinOpen && $withinDeadline;
}

$questions = [];
if ($examId > 0) {
    $qr = mysqli_query($conn, "SELECT * FROM college_exam_questions WHERE exam_id=" . (int)$examId . " ORDER BY sort_order ASC, question_id ASC");
    if ($qr) {
        while ($q = mysqli_fetch_assoc($qr)) {
            $questions[] = $q;
        }
        mysqli_free_result($qr);
    }
} elseif ($dupSourceExam) {
    $qr = mysqli_query($conn, "SELECT * FROM college_exam_questions WHERE exam_id=" . (int)$dupFrom . " ORDER BY sort_order ASC, question_id ASC");
    if ($qr) {
        while ($q = mysqli_fetch_assoc($qr)) {
            $questions[] = $q;
        }
        mysqli_free_result($qr);
    }
}

if (empty($questions)) {
    $questions[] = [
        'question_type' => 'mcq',
        'question_text' => '',
        'choice_a' => '',
        'choice_b' => '',
        'choice_c' => '',
        'choice_d' => '',
        'correct_answer' => '',
        'sort_order' => 0,
    ];
}

/**
 * @return int Total seconds (non-negative)
 */
function professor_exam_time_limit_from_post(array $post): int
{
    $h = max(0, sanitizeInt($post['time_limit_hours'] ?? 0));
    $m = max(0, sanitizeInt($post['time_limit_minutes'] ?? 0));
    $s = max(0, sanitizeInt($post['time_limit_secs'] ?? 0));
    $m = min(59, $m);
    $s = min(59, $s);
    $h = min(999, $h);
    return min(999 * 3600 + 59 * 60 + 59, $h * 3600 + $m * 60 + $s);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = 'Invalid request.';
    } else {
        $saveAction = trim((string)($_POST['save_action'] ?? 'final'));
        $isDraft = ($saveAction === 'draft');

        $title = trim($_POST['title'] ?? '');
        if ($title === '' && $isDraft) {
            $title = 'Untitled draft';
        }

        $description = trim($_POST['description'] ?? '');
        $timeLimit = professor_exam_time_limit_from_post($_POST);
        $availableFrom = trim($_POST['available_from'] ?? '');
        $deadline = trim($_POST['deadline'] ?? '');
        $isPublished = !empty($_POST['is_published']) ? 1 : 0;
        $shuffleQuestions = !empty($_POST['shuffle_questions']) ? 1 : 0;
        $shuffleMcqQuestions = !empty($_POST['shuffle_mcq_questions']) ? 1 : $shuffleQuestions;
        $shuffleTfQuestions = !empty($_POST['shuffle_tf_questions']) ? 1 : $shuffleQuestions;
        $shuffleChoices = !empty($_POST['shuffle_choices']) ? 1 : 0;
        $descriptionMarkdown = !empty($_POST['description_markdown']) ? 1 : 0;

        $qTexts = $_POST['question_text'] ?? [];
        $ca = $_POST['choice_a'] ?? [];
        $cb = $_POST['choice_b'] ?? [];
        $cc = $_POST['choice_c'] ?? [];
        $cd = $_POST['choice_d'] ?? [];
        $corr = $_POST['correct_answer'] ?? [];
        $qType = $_POST['question_type'] ?? [];

        if ($title === '') {
            $error = 'Title is required.';
        } else {
            $availSql = ($availableFrom !== '') ? date('Y-m-d H:i:s', strtotime($availableFrom)) : null;
            $deadSql = ($deadline !== '') ? date('Y-m-d H:i:s', strtotime($deadline)) : null;

            $n = max(count($qTexts), count($ca), count($cb), count($qType));
            $missingCorrectNums = [];
            $qNumScan = 0;
            for ($si = 0; $si < $n; $si++) {
                $qtScan = sanitizeQuizRichHtmlForStorage(trim((string)($qTexts[$si] ?? '')));
                if ($qtScan === '') {
                    continue;
                }
                $qNumScan++;
                $typeScan = strtolower(trim((string)($qType[$si] ?? 'mcq')));
                if ($typeScan !== 'tf') {
                    $typeScan = 'mcq';
                }
                $okScan = strtoupper(trim((string)($corr[$si] ?? '')));
                if ($isDraft) {
                    continue;
                }
                if ($typeScan === 'tf') {
                    if ($okScan !== 'A' && $okScan !== 'B') {
                        $missingCorrectNums[] = $qNumScan;
                    }
                } elseif (!preg_match('/^[A-D]$/', $okScan)) {
                    $missingCorrectNums[] = $qNumScan;
                }
            }
            if (!$isDraft && !empty($missingCorrectNums)) {
                $error = 'Select the correct answer for every question. Missing for question number(s): ' . implode(', ', $missingCorrectNums) . '.';
            } elseif (!$isDraft && $qNumScan === 0) {
                $error = 'Add at least one question with a prompt.';
            }

            if (empty($error) && !$isDraft && $isPublished && $deadSql !== null && $deadSql !== '' && $timeLimit > 0) {
                $windowSec = college_exam_seconds_exam_window_remaining($availSql, $deadSql, time());
                if ($windowSec !== null && $timeLimit > $windowSec) {
                    $error = 'Cannot publish: the per-exam timer (' . college_exam_human_duration($timeLimit) . ') is longer than the time available before the deadline (' . college_exam_human_duration($windowSec) . ' from when the exam window is active). Shorten the timer or set a later deadline.';
                }
            }

            if (!empty($error)) {
                // keep user on page
            } else {
            mysqli_begin_transaction($conn);
            try {
                if ($examId <= 0) {
                    $ins = mysqli_prepare($conn, "INSERT INTO college_exams (title, description, time_limit_seconds, available_from, deadline, is_published, created_by, shuffle_questions, shuffle_choices, shuffle_mcq_questions, shuffle_tf_questions, description_markdown) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($ins, 'ssissiiiiiii', $title, $description, $timeLimit, $availSql, $deadSql, $isPublished, $uid, $shuffleQuestions, $shuffleChoices, $shuffleMcqQuestions, $shuffleTfQuestions, $descriptionMarkdown);
                    mysqli_stmt_execute($ins);
                    $examId = (int)mysqli_insert_id($conn);
                    mysqli_stmt_close($ins);
                } else {
                    $upd = mysqli_prepare($conn, "UPDATE college_exams SET title=?, description=?, time_limit_seconds=?, available_from=?, deadline=?, is_published=?, shuffle_questions=?, shuffle_choices=?, shuffle_mcq_questions=?, shuffle_tf_questions=?, description_markdown=? WHERE exam_id=? AND created_by=?");
                    mysqli_stmt_bind_param($upd, 'ssissiiiiiiii', $title, $description, $timeLimit, $availSql, $deadSql, $isPublished, $shuffleQuestions, $shuffleChoices, $shuffleMcqQuestions, $shuffleTfQuestions, $descriptionMarkdown, $examId, $uid);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                    mysqli_query($conn, "DELETE FROM college_exam_questions WHERE exam_id=" . (int)$examId);
                }

                $sort = 0;
                for ($i = 0; $i < $n; $i++) {
                    $qt = sanitizeQuizRichHtmlForStorage(trim((string)($qTexts[$i] ?? '')));
                    if ($qt === '') {
                        continue;
                    }
                    $type = strtolower(trim((string)($qType[$i] ?? 'mcq')));
                    if ($type !== 'tf') { $type = 'mcq'; }
                    $a = trim((string)($ca[$i] ?? ''));
                    $b = trim((string)($cb[$i] ?? ''));
                    $c = trim((string)($cc[$i] ?? ''));
                    $d = trim((string)($cd[$i] ?? ''));
                    $ok = strtoupper(trim((string)($corr[$i] ?? '')));
                    if ($type === 'tf') {
                        $a = 'True';
                        $b = 'False';
                        $c = '';
                        $d = '';
                    }
                    if ($isDraft) {
                        if ($type === 'tf') {
                            if ($ok !== 'A' && $ok !== 'B') {
                                $ok = '';
                            }
                        } elseif (!preg_match('/^[A-D]$/', $ok)) {
                            $ok = '';
                        }
                    }
                    $insq = mysqli_prepare($conn, "INSERT INTO college_exam_questions (exam_id, question_type, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($insq, 'isssssssi', $examId, $type, $qt, $a, $b, $c, $d, $ok, $sort);
                    mysqli_stmt_execute($insq);
                    mysqli_stmt_close($insq);
                    $sort++;
                }

                if ($sort === 0 && !$isDraft) {
                    mysqli_rollback($conn);
                    $error = 'Add at least one question with a prompt.';
                } else {
                    mysqli_commit($conn);
                    if ($isDraft) {
                        $_SESSION['draft_saved_message'] = 1;
                        header('Location: professor_exam_edit.php?id=' . $examId);
                        exit;
                    }
                    $_SESSION['message'] = 'Exam saved.';
                    header('Location: professor_exams.php');
                    exit;
                }
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $error = 'Could not save exam.';
            }
            }
        }
    }
}

if ($exam) {
    $pageTitle = 'Edit exam';
} else {
    $pageTitle = 'New exam';
}

$prefill = $exam ?: [
    'title' => '',
    'description' => '',
    'time_limit_seconds' => 3600,
    'available_from' => null,
    'deadline' => null,
    'is_published' => 0,
    'shuffle_questions' => 0,
    'shuffle_mcq_questions' => 0,
    'shuffle_tf_questions' => 0,
    'shuffle_choices' => 0,
    'description_markdown' => 0,
];
if ($dupSourceExam) {
    $prefill = [
        'title' => 'Copy of ' . $dupSourceExam['title'],
        'description' => $dupSourceExam['description'] ?? '',
        'time_limit_seconds' => (int)($dupSourceExam['time_limit_seconds'] ?? 3600),
        'available_from' => null,
        'deadline' => $dupSourceExam['deadline'] ?? null,
        'is_published' => 0,
        'shuffle_questions' => isset($dupSourceExam['shuffle_questions']) ? (int)$dupSourceExam['shuffle_questions'] : 0,
        'shuffle_mcq_questions' => isset($dupSourceExam['shuffle_mcq_questions']) ? (int)$dupSourceExam['shuffle_mcq_questions'] : (isset($dupSourceExam['shuffle_questions']) ? (int)$dupSourceExam['shuffle_questions'] : 0),
        'shuffle_tf_questions' => isset($dupSourceExam['shuffle_tf_questions']) ? (int)$dupSourceExam['shuffle_tf_questions'] : (isset($dupSourceExam['shuffle_questions']) ? (int)$dupSourceExam['shuffle_questions'] : 0),
        'shuffle_choices' => isset($dupSourceExam['shuffle_choices']) ? (int)$dupSourceExam['shuffle_choices'] : 0,
        'description_markdown' => isset($dupSourceExam['description_markdown']) ? (int)$dupSourceExam['description_markdown'] : 0,
    ];
}
$isEditMode = (int)$examId > 0;
$heroTitle = $isEditMode ? 'Edit exam' : 'New exam';
$heroSubtitle = $isEditMode && !empty($prefill['title'])
    ? h($prefill['title'])
    : ($dupSourceExam
        ? 'Duplicating from “' . h($dupSourceExam['title']) . '” — save as a new exam.'
        : 'Set details, schedule, and build multiple-choice questions for your students.');

$tsec = max(0, (int)($prefill['time_limit_seconds'] ?? 0));
$prefillHours = intdiv($tsec, 3600);
$_rest = $tsec - $prefillHours * 3600;
$prefillMinutes = intdiv($_rest, 60);
$prefillSecs = $_rest % 60;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .prof-page { background: linear-gradient(180deg, #eefaf3 0%, #e6f6ee 45%, #edf9f2 100%); min-height: 100%; }
    /* Match professor_exams.php: full width inside .admin-content (horizontal padding from app-shell) */
    .dashboard-shell { padding-bottom: 1.5rem; color: #0f172a; width: 100%; max-width: none; }
    .prof-hero {
      border-radius: 0.75rem; border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #0f766e 0%, #0e9f6e 35%, #16a34a 75%, #15803d 100%);
      box-shadow: 0 14px 34px -20px rgba(5,46,22,.75), inset 0 1px 0 rgba(255,255,255,.22);
    }
    .prof-icon { background: rgba(255,255,255,.22); border: 1px solid rgba(255,255,255,.34); color: #fff; }
    .prof-btn { border-radius: 9999px; transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease; }
    .prof-btn:hover { transform: translateY(-2px); box-shadow: 0 14px 24px -20px rgba(21,128,61,.85); }
    .prof-btn-outline {
      border-radius: 9999px; border: 1px solid rgba(255,255,255,.55); color: #fff; background: rgba(255,255,255,.12);
      transition: transform .2s ease, background-color .2s ease, border-color .2s ease;
    }
    .prof-btn-outline:hover { background: rgba(255,255,255,.22); border-color: rgba(255,255,255,.85); }
    .section-title {
      display: flex; align-items: center; gap: .5rem; margin: 0 0 .85rem; padding: .45rem .65rem;
      border: 1px solid #d1fae5; border-radius: .62rem; background: linear-gradient(180deg,#f5fff9 0%,#fff 100%);
      color: #14532d; font-size: 1.03rem; font-weight: 800;
    }
    .section-title i {
      width: 1.55rem; height: 1.55rem; border-radius: .45rem; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #bbf7d0; background: #ecfdf3; color: #15803d; font-size: .83rem;
    }
    .table-card {
      border-radius: .75rem; border: 1px solid rgba(22,163,74,.22); overflow: hidden;
      background: linear-gradient(180deg, #f4fff8 0%, #fff 40%);
      box-shadow: 0 10px 28px -22px rgba(21,128,61,.58), 0 1px 0 rgba(255,255,255,.8) inset;
      transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .table-card:hover { border-color: rgba(22,163,74,.32); box-shadow: 0 16px 32px -22px rgba(15,118,110,.35); }
    .exam-field {
      width: 100%; border-radius: 0.62rem; border: 1px solid #bbf7d0; background: #fff;
      padding: 0.625rem 0.875rem; font-size: 0.875rem; color: #14532d;
      transition: border-color .2s ease, box-shadow .2s ease;
    }
    .exam-field:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.15); }
    textarea.exam-field.has-ocr-table {
      font-family: ui-monospace, 'Cascadia Mono', 'Consolas', monospace;
      font-size: 0.8125rem;
      line-height: 1.45;
    }
    .exam-q-richtext-hint {
      font-size: 0.6875rem; font-weight: 600; color: #6b7280; margin: 0 0 0.35rem;
    }
    .prof-page .tox-tinymce {
      border-radius: 0.62rem !important;
      border-color: #bbf7d0 !important;
    }
    .prof-page .tox .tox-toolbar,
    .prof-page .tox .tox-toolbar__primary {
      background: linear-gradient(180deg, #f8fffa 0%, #fff 100%) !important;
    }
    .exam-label { display: block; font-size: 0.8125rem; font-weight: 700; color: #166534; margin-bottom: 0.35rem; }
    .hero-status-bar {
      margin-top: 0.65rem; display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem;
      font-size: 0.72rem; font-weight: 600; letter-spacing: 0.01em;
    }
    .hero-pill {
      display: inline-flex; align-items: center; padding: 0.2rem 0.55rem; border-radius: 9999px;
      background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.35); color: rgba(255,255,255,.95);
      white-space: nowrap; max-width: 100%;
    }
    .hero-pill.is-warn { background: rgba(254,243,199,.25); border-color: rgba(253,230,138,.5); color: #fef9c3; }
    .exam-form-section { margin-bottom: 1.25rem; }
    .exam-form-section:last-child { margin-bottom: 0; }
    .field-hint-icon {
      display: inline-flex; align-items: center; justify-content: center; width: 1rem; height: 1rem; border-radius: 9999px;
      background: #ecfdf3; border: 1px solid #bbf7d0; color: #15803d; font-size: 0.65rem; cursor: help; vertical-align: middle;
    }
    .question-block {
      border-radius: 0.75rem; border: 1px solid #bbf7d0; background: #fff;
      padding: 0.85rem 1rem; margin-bottom: 0.75rem;
      box-shadow: 0 2px 12px -8px rgba(21,128,61,.25);
    }
    .question-block:last-child { margin-bottom: 0; }
    .q-toolbar {
      display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem;
      margin-bottom: 0.65rem; padding-bottom: 0.55rem; border-bottom: 1px solid #ecfdf3;
    }
    .q-card-label { font-size: 0.75rem; font-weight: 800; color: #14532d; text-transform: uppercase; letter-spacing: 0.04em; }
    .q-toolbar-btns { display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem; }
    .q-ai-wrap { display: inline-flex; gap: 0.2rem; padding-right: 0.45rem; margin-right: 0.2rem; border-right: 1px solid #d1fae5; }
    .q-tool-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 0.25rem;
      padding: 0.35rem 0.55rem; border-radius: 0.45rem; font-size: 0.75rem; font-weight: 700;
      border: 1px solid #bbf7d0; background: #fff; color: #15803d; cursor: pointer;
      transition: background-color .15s ease, border-color .15s ease, color .15s ease;
    }
    .q-tool-btn:hover:not(:disabled) { background: #ecfdf3; border-color: #86efac; }
    .q-tool-btn:disabled { opacity: 0.45; cursor: not-allowed; }
    .q-tool-btn.danger { color: #b91c1c; border-color: #fecaca; }
    .q-tool-btn.danger:hover:not(:disabled) { background: #fef2f2; border-color: #f87171; }
    .time-readout { font-size: 0.75rem; font-weight: 600; color: #15803d; margin-top: 0.35rem; }
    .time-inline-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.75rem; }
    .time-inline-row .time-fields { display: flex; flex-wrap: wrap; gap: 0.5rem; flex: 1; min-width: 0; }
    .time-inline-row .time-fields > div { flex: 1; min-width: 4.5rem; max-width: 6rem; }
    .publish-strip {
      display: flex; align-items: flex-start; gap: 0.65rem; padding: 0.65rem 0.85rem;
      border-radius: 0.62rem; border: 1px solid #d1fae5; background: #f8fffa;
    }
    .shuffle-row { display: flex; flex-wrap: wrap; gap: 1rem 1.5rem; align-items: center; padding: 0.35rem 0; }
    .shuffle-row label { display: inline-flex; align-items: center; gap: 0.45rem; font-size: 0.8125rem; font-weight: 600; color: #166534; cursor: pointer; margin: 0; }
    .q-section-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.65rem; }
    .q-section-head .section-title { margin-bottom: 0; flex: 1; min-width: 12rem; }
    .draft-toast {
      border-radius: 0.62rem; border: 1px solid #86efac; background: #ecfdf3; color: #14532d;
      padding: 0.65rem 1rem; font-size: 0.875rem; font-weight: 600; margin-bottom: 1rem;
    }
    .dash-anim { opacity: 0; transform: translateY(12px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; }
    .delay-2 { animation-delay: .12s; }
    .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
    }
    .import-modal-overlay {
      position: fixed; inset: 0; z-index: 100; background: rgba(15, 23, 42, 0.45);
      display: none; align-items: center; justify-content: center; padding: 1rem;
    }
    .import-modal-overlay.is-open { display: flex; }
    .import-modal {
      width: 100%; max-width: 52rem; max-height: 90vh; overflow: hidden;
      border-radius: 0.75rem; background: #fff; border: 1px solid #bbf7d0;
      box-shadow: 0 24px 48px -12px rgba(21,128,61,.35);
      display: flex; flex-direction: column;
    }
    .import-modal-head {
      padding: 1rem 1.25rem; border-bottom: 1px solid #d1fae5;
      display: flex; align-items: center; justify-content: space-between; gap: 1rem;
      background: linear-gradient(180deg,#f0fdf4 0%,#fff 100%);
    }
    .import-tabs { display: flex; gap: 0.35rem; flex-wrap: wrap; }
    .import-tab {
      padding: 0.45rem 0.85rem; border-radius: 9999px; font-size: 0.8125rem; font-weight: 700;
      border: 1px solid #bbf7d0; background: #fff; color: #166534; cursor: pointer;
    }
    .import-tab.is-active { background: #15803d; color: #fff; border-color: #15803d; }
    .import-modal-body { padding: 1rem 1.25rem; overflow-y: auto; flex: 1; }
    .import-preview-wrap { overflow-x: auto; max-height: 12rem; border: 1px solid #d1fae5; border-radius: 0.5rem; }
    .import-preview-wrap table { font-size: 0.75rem; }
    .ocr-modal-overlay {
      position: fixed; inset: 0; z-index: 110; background: rgba(15, 23, 42, 0.5);
      display: none; align-items: center; justify-content: center; padding: 1rem;
    }
    .ocr-modal-overlay.is-open { display: flex; }
    .ocr-modal {
      width: 100%; max-width: 28rem; border-radius: 0.85rem; background: #fff;
      border: 1px solid #bbf7d0; box-shadow: 0 24px 48px -12px rgba(21,128,61,.4);
      overflow: hidden;
    }
    .ocr-modal-head {
      padding: 1rem 1.15rem; border-bottom: 1px solid #d1fae5;
      background: linear-gradient(180deg,#f0fdf4 0%,#fff 100%);
      display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem;
    }
    .ocr-drop {
      margin: 1rem 1.15rem; padding: 1.25rem; border: 2px dashed #86efac; border-radius: 0.65rem;
      text-align: center; background: #f8fffa; cursor: pointer; transition: border-color .15s, background .15s;
    }
    .ocr-drop:hover, .ocr-drop.is-drag { border-color: #22c55e; background: #ecfdf3; }
    .ocr-progress-track {
      height: 6px; border-radius: 9999px; background: #d1fae5; margin: 0 1.15rem 0.75rem; overflow: hidden;
    }
    .ocr-progress-bar { height: 100%; width: 0%; background: linear-gradient(90deg,#15803d,#22c55e); transition: width .2s ease; }
    .fab-add-wrap {
      position: fixed;
      bottom: max(1.35rem, env(safe-area-inset-bottom, 0px));
      right: max(1.15rem, env(safe-area-inset-right, 0px));
      z-index: 9998;
      display: flex; flex-direction: column; align-items: flex-end; gap: 0.45rem;
      pointer-events: none;
    }
    .fab-add-wrap[hidden] { display: none !important; }
    .fab-add-wrap > button { pointer-events: auto; }
    .fab-add-btn {
      display: inline-flex; align-items: center; gap: 0.45rem;
      padding: 0.65rem 1.05rem; border-radius: 9999px; font-size: 0.8125rem; font-weight: 800;
      letter-spacing: 0.02em; border: none; cursor: pointer;
      box-shadow: 0 12px 32px -10px rgba(21,128,61,.55), 0 2px 8px rgba(15,23,42,.08);
      transition: transform .15s ease, box-shadow .15s ease;
    }
    .fab-add-btn:hover { transform: translateY(-2px); box-shadow: 0 16px 36px -10px rgba(21,128,61,.6), 0 4px 12px rgba(15,23,42,.1); }
    .fab-add-btn--mcq {
      background: linear-gradient(135deg, #15803d 0%, #22c55e 100%); color: #fff;
    }
    .fab-add-btn--tf {
      background: #fff; color: #14532d; border: 1px solid #bbf7d0;
    }
    .fab-add-hint {
      font-size: 0.65rem; font-weight: 700; color: #166534; background: rgba(255,255,255,.92);
      padding: 0.2rem 0.55rem; border-radius: 9999px; border: 1px solid #d1fae5;
      pointer-events: none; margin-bottom: 0.15rem;
    }
    .exam-q-editor-wrap { margin-bottom: 1.35rem; }
    .exam-q-choices-wrap { margin-top: 0.75rem; padding-top: 1rem; border-top: 1px solid #ecfdf3; }
    .exam-q-choices-grid { display: grid; grid-template-columns: 1fr; gap: 0.75rem; }
    @media (min-width: 640px) {
      .exam-q-choices-grid { grid-template-columns: 1fr 1fr; }
    }
    .question-block.question-block--error {
      border-color: #f87171 !important;
      box-shadow: 0 0 0 3px rgba(248,113,113,.25), 0 2px 12px -8px rgba(185,28,28,.35);
      animation: examQErrorPulse 1.2s ease-out 2;
    }
    @keyframes examQErrorPulse {
      0%, 100% { box-shadow: 0 0 0 3px rgba(248,113,113,.25), 0 2px 12px -8px rgba(185,28,28,.35); }
      50% { box-shadow: 0 0 0 5px rgba(248,113,113,.35), 0 4px 16px -8px rgba(185,28,28,.45); }
    }
    .validation-modal-overlay {
      position: fixed; inset: 0; z-index: 200; background: rgba(15, 23, 42, 0.5);
      display: none; align-items: center; justify-content: center; padding: 1rem;
    }
    .validation-modal-overlay.is-open { display: flex; }
    .validation-modal {
      width: 100%; max-width: 26rem; border-radius: 0.85rem; background: #fff;
      border: 1px solid #fecaca; box-shadow: 0 24px 48px -12px rgba(127,29,29,.25);
      overflow: hidden;
    }
    .validation-modal-head {
      padding: 1rem 1.15rem; background: linear-gradient(180deg, #fef2f2 0%, #fff 100%);
      border-bottom: 1px solid #fecaca;
      display: flex; align-items: flex-start; gap: 0.75rem;
    }
    .validation-modal-body { padding: 1rem 1.15rem 1.15rem; }
  </style>
</head>
<body class="font-sans antialiased prof-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <main class="dashboard-shell w-full max-w-none flex-1 min-w-0">
    <div class="mb-5 dash-anim delay-1">
      <div class="prof-hero overflow-hidden">
        <div class="p-4 sm:p-5 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div class="flex gap-3 min-w-0 flex-1">
            <div class="prof-icon w-10 h-10 rounded-xl flex items-center justify-center shrink-0">
              <i class="bi bi-<?php echo $isEditMode ? 'pencil-square' : 'journal-text'; ?> text-lg"></i>
            </div>
            <div class="min-w-0 flex-1">
              <h1 class="text-xl sm:text-2xl font-bold text-white m-0 leading-tight"><?php echo h($heroTitle); ?></h1>
              <p class="text-white/85 mt-0.5 mb-0 text-sm break-words"><?php echo $heroSubtitle; ?></p>
              <div class="hero-status-bar" id="hero-status-bar" aria-live="polite"></div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 shrink-0 lg:pt-0.5">
            <a href="professor_exams.php" class="prof-btn-outline inline-flex items-center gap-1.5 px-3 py-2 text-xs sm:text-sm font-semibold">
              <i class="bi bi-arrow-left"></i> Library
            </a>
            <button type="submit" form="exam-edit-form" name="save_action" value="final" class="prof-btn inline-flex items-center gap-1.5 px-3 py-2 text-xs sm:text-sm font-semibold bg-white text-green-800 hover:bg-green-50 shadow-sm">
              <i class="bi bi-check2-circle"></i> Save
            </button>
            <button type="submit" form="exam-edit-form" name="save_action" value="draft" class="prof-btn-outline inline-flex items-center gap-1.5 px-3 py-2 text-xs sm:text-sm font-semibold">
              <i class="bi bi-save"></i> Draft
            </button>
          </div>
        </div>
      </div>
    </div>

    <?php if ($draftSaved): ?>
      <div class="draft-toast dash-anim delay-2"><i class="bi bi-check2-circle me-2"></i>Draft saved. You can leave and return anytime.</div>
    <?php endif; ?>
    <?php if ($dupSourceExam): ?>
      <div class="mb-3 px-3 py-2 rounded-lg bg-blue-50/90 border border-blue-200 text-blue-900 text-xs sm:text-sm dash-anim delay-2">
        <i class="bi bi-files me-1"></i> New exam from copy: <strong><?php echo h($dupSourceExam['title']); ?></strong>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-900 font-medium dash-anim delay-2"><?php echo h($error); ?></div>
    <?php endif; ?>
    <?php if ($examIsRunning): ?>
      <div class="mb-4 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 font-medium dash-anim delay-2">
        <i class="bi bi-exclamation-triangle me-1"></i> This exam is currently running. Deletion is strictly disabled while running.
      </div>
    <?php endif; ?>

    <form id="exam-edit-form" method="post" class="space-y-6 dash-anim delay-2" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

      <h2 class="section-title"><i class="bi bi-sliders"></i> Exam details</h2>
      <div class="table-card p-4 sm:p-5 mb-5">
        <div class="exam-form-section">
          <label class="exam-label" for="exam-title">Title <span class="field-hint-icon" title="Empty title + Draft saves as Untitled draft">?</span></label>
          <input id="exam-title" type="text" name="title" class="exam-field" value="<?php echo h($prefill['title'] ?? ''); ?>" placeholder="e.g. Midterm — Biology 101" data-initial="<?php echo h($prefill['title'] ?? ''); ?>">
        </div>
        <div class="exam-form-section">
          <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
            <label class="exam-label m-0" for="exam-desc">Instructions for students</label>
            <label class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-600 cursor-pointer select-none" title="Bold, italic, code, ## headings">
              <input type="checkbox" name="description_markdown" id="desc-md" value="1" class="rounded border-green-300 text-green-600 focus:ring-green-500" <?php echo !empty($prefill['description_markdown']) ? 'checked' : ''; ?>>
              Markdown
            </label>
          </div>
          <textarea id="exam-desc" name="description" rows="3" class="exam-field text-sm" placeholder="Shown before students start the exam"><?php echo h($prefill['description'] ?? ''); ?></textarea>
        </div>
        <div class="exam-form-section">
          <span class="exam-label">Timer <span class="field-hint-icon" title="All zeros = no countdown (deadline rules still apply)">?</span></span>
          <div class="time-inline-row">
            <div class="time-fields">
              <div>
                <label class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5 block" for="time-hours">H</label>
                <input id="time-hours" type="number" name="time_limit_hours" min="0" max="999" step="1" class="exam-field py-2" value="<?php echo (int)$prefillHours; ?>">
              </div>
              <div>
                <label class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5 block" for="time-minutes">M</label>
                <input id="time-minutes" type="number" name="time_limit_minutes" min="0" max="59" step="1" class="exam-field py-2" value="<?php echo (int)$prefillMinutes; ?>">
              </div>
              <div>
                <label class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5 block" for="time-secs">S</label>
                <input id="time-secs" type="number" name="time_limit_secs" min="0" max="59" step="1" class="exam-field py-2" value="<?php echo (int)$prefillSecs; ?>" title="Sub-minute precision">
              </div>
            </div>
            <p class="time-readout m-0 self-end pb-1 text-xs" id="time-readout"></p>
          </div>
        </div>
        <div class="exam-form-section">
          <label class="publish-strip cursor-pointer" for="pub">
            <input type="checkbox" name="is_published" id="pub" value="1" class="mt-0.5 rounded border-green-300 text-green-600 focus:ring-green-500 shrink-0" <?php echo !empty($prefill['is_published']) ? 'checked' : ''; ?>>
            <span class="text-sm font-semibold text-gray-800">Publish — make visible in the exam list (still respects dates below)</span>
          </label>
        </div>
        <div class="exam-form-section shuffle-row">
          <label for="shuffle-q"><input type="checkbox" name="shuffle_questions" id="shuffle-q" value="1" class="rounded border-green-300 text-green-600 focus:ring-green-500" <?php echo !empty($prefill['shuffle_questions']) ? 'checked' : ''; ?>> Legacy shuffle (all categories)</label>
          <label for="shuffle-mcq"><input type="checkbox" name="shuffle_mcq_questions" id="shuffle-mcq" value="1" class="rounded border-green-300 text-green-600 focus:ring-green-500" <?php echo !empty($prefill['shuffle_mcq_questions']) ? 'checked' : ''; ?>> Shuffle MCQ questions</label>
          <label for="shuffle-tf"><input type="checkbox" name="shuffle_tf_questions" id="shuffle-tf" value="1" class="rounded border-green-300 text-green-600 focus:ring-green-500" <?php echo !empty($prefill['shuffle_tf_questions']) ? 'checked' : ''; ?>> Shuffle True/False questions</label>
          <label for="shuffle-c"><input type="checkbox" name="shuffle_choices" id="shuffle-c" value="1" class="rounded border-green-300 text-green-600 focus:ring-green-500" <?php echo !empty($prefill['shuffle_choices']) ? 'checked' : ''; ?>> Shuffle MCQ choices</label>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="exam-label" for="avail-from">Opens</label>
            <input id="avail-from" type="datetime-local" name="available_from" class="exam-field"
              value="<?php echo !empty($prefill['available_from']) ? h(date('Y-m-d\TH:i', strtotime($prefill['available_from']))) : ''; ?>">
          </div>
          <div>
            <label class="exam-label" for="deadline">Deadline</label>
            <input id="deadline" type="datetime-local" name="deadline" class="exam-field"
              value="<?php echo !empty($prefill['deadline']) ? h(date('Y-m-d\TH:i', strtotime($prefill['deadline']))) : ''; ?>">
          </div>
        </div>
      </div>

      <div class="q-section-head dash-anim delay-3">
        <h2 class="section-title m-0"><i class="bi bi-list-check"></i> Questions (MCQ + True/False)</h2>
        <div class="flex flex-wrap gap-2">
          <button type="button" id="btn-open-import" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs sm:text-sm font-bold border border-green-300 text-green-800 bg-white hover:bg-green-50 transition-colors">
            <i class="bi bi-upload"></i> Import
          </button>
          <button type="button" id="btn-add-mcq" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs sm:text-sm font-bold bg-green-600 text-white hover:bg-green-700 shadow-sm transition-colors">
            <i class="bi bi-plus-lg"></i> Add MCQ
          </button>
          <button type="button" id="btn-add-tf" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs sm:text-sm font-bold border border-green-300 text-green-800 bg-white hover:bg-green-50 transition-colors">
            <i class="bi bi-plus-lg"></i> Add T/F
          </button>
        </div>
      </div>
      <div class="table-card p-4 sm:p-5 dash-anim delay-3">

        <div id="question-blocks"></div>

        <template id="question-block-template">
          <div class="question-block" data-question-row>
            <div class="q-toolbar">
              <span class="q-card-label">Question <span class="js-q-num">1</span></span>
              <div class="q-toolbar-btns">
                <span class="q-ai-wrap">
                  <button type="button" class="q-tool-btn js-ai-gen" title="AI: generate A–D from stem"><i class="bi bi-stars"></i></button>
                  <button type="button" class="q-tool-btn js-ai-dist" title="AI: refresh distractors"><i class="bi bi-lightbulb"></i></button>
                </span>
                <button type="button" class="q-tool-btn js-q-ocr" title="Scan question from image (OCR)" aria-label="Scan question from image"><i class="bi bi-image"></i></button>
                <button type="button" class="q-tool-btn js-q-up" title="Move up" aria-label="Move up"><i class="bi bi-arrow-up"></i></button>
                <button type="button" class="q-tool-btn js-q-down" title="Move down" aria-label="Move down"><i class="bi bi-arrow-down"></i></button>
                <button type="button" class="q-tool-btn danger js-q-remove" title="Remove" aria-label="Remove"><i class="bi bi-trash"></i></button>
              </div>
            </div>
            <label class="exam-label sr-only">Question</label>
            <div class="mb-2.5 flex flex-wrap items-center gap-2">
              <span class="text-xs font-bold text-gray-600">Category</span>
              <select name="question_type[]" class="exam-field w-auto min-w-[9rem] py-1.5 text-sm js-q-type">
                <option value="mcq">Multiple Choice (MCQ)</option>
                <option value="tf">True / False (T&F)</option>
              </select>
              <span class="text-[11px] text-gray-500 js-q-type-hint">Use A-D choices for MCQ.</span>
            </div>
            <div class="exam-q-editor-wrap">
              <p class="exam-q-richtext-hint">Formatting: bold, lists, and tables (same editor as admin quizzes). HTML is sanitized on save.</p>
              <textarea name="question_text[]" rows="4" class="exam-field mb-2.5 js-q-prompt js-exam-q-richtext" placeholder="Question text"></textarea>
            </div>
            <div class="exam-q-choices-wrap">
              <span class="exam-label mb-2 block">Choices</span>
              <div class="exam-q-choices-grid mb-2.5">
                <input type="text" name="choice_a[]" class="exam-field py-2 text-sm js-q-choice" placeholder="A" autocomplete="off" aria-label="Choice A">
                <input type="text" name="choice_c[]" class="exam-field py-2 text-sm js-q-choice" placeholder="C" autocomplete="off" aria-label="Choice C">
                <input type="text" name="choice_b[]" class="exam-field py-2 text-sm js-q-choice" placeholder="B" autocomplete="off" aria-label="Choice B">
                <input type="text" name="choice_d[]" class="exam-field py-2 text-sm js-q-choice" placeholder="D" autocomplete="off" aria-label="Choice D">
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-1">
              <label class="text-xs font-bold text-gray-600 m-0">Correct</label>
              <select name="correct_answer[]" class="exam-field w-auto min-w-[10rem] py-1.5 text-sm js-q-correct">
                <option value="">Select correct answer</option>
                <option value="A">A</option>
                <option value="B">B</option>
                <option value="C">C</option>
                <option value="D">D</option>
              </select>
            </div>
          </div>
        </template>

      </div>

      <div class="flex flex-wrap items-center gap-2 pb-8 pt-2 dash-anim delay-3 border-t border-green-100 mt-2">
        <button type="submit" name="save_action" value="final" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-lg text-sm font-bold bg-green-600 text-white hover:bg-green-700 shadow-sm">
          <i class="bi bi-check2-circle"></i> Save exam
        </button>
        <button type="submit" name="save_action" value="draft" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-lg text-sm font-semibold border border-green-600 text-green-800 bg-white hover:bg-green-50">
          <i class="bi bi-save"></i> Draft
        </button>
        <a href="professor_exams.php" class="inline-flex items-center gap-1.5 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</a>
      </div>
    </form>

    <div id="import-modal" class="import-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="import-modal-title">
      <div class="import-modal">
        <div class="import-modal-head">
          <h3 id="import-modal-title" class="text-lg font-bold text-green-900 m-0">Import questions</h3>
          <button type="button" id="import-modal-close" class="text-gray-500 hover:text-gray-800 p-2" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="px-5 pt-3 border-b border-green-100">
          <div class="import-tabs" role="tablist">
            <button type="button" class="import-tab is-active" data-import-tab="csv">CSV</button>
            <button type="button" class="import-tab" data-import-tab="paste">Structured paste</button>
          </div>
        </div>
        <div class="import-modal-body">
          <div id="import-panel-csv">
            <p class="text-sm text-gray-600 m-0 mb-2">First row optional: <code class="bg-gray-100 px-1 rounded">question,choice_a,choice_b,choice_c,choice_d,correct</code> (comma-separated). Correct is A–D.</p>
            <textarea id="import-csv-text" class="exam-field font-mono text-xs" rows="8" placeholder="question,choice_a,choice_b,choice_c,choice_d,correct&#10;&quot;What is 2+2?&quot;,3,4,5,6,B"></textarea>
          </div>
          <div id="import-panel-paste" class="hidden">
            <p class="text-sm text-gray-600 m-0 mb-2">Blocks separated by a blank line. Each block: question line(s), then lines starting with <code class="bg-gray-100 px-1 rounded">A)</code> … <code class="bg-gray-100 px-1 rounded">D)</code>, then <code class="bg-gray-100 px-1 rounded">Answer: B</code> (or Correct:).</p>
            <textarea id="import-paste-text" class="exam-field font-mono text-xs" rows="10" placeholder="What is the capital of France?&#10;A) Berlin&#10;B) Paris&#10;C) Madrid&#10;D) Rome&#10;Answer: B"></textarea>
          </div>
          <p id="import-parse-error" class="text-sm text-red-700 font-medium mt-2 hidden m-0"></p>
          <div class="mt-3">
            <p class="text-xs font-bold text-green-800 m-0 mb-1">Preview (confirm below)</p>
            <div class="import-preview-wrap">
              <table class="w-full text-left border-collapse">
                <thead class="bg-green-50 text-green-900"><tr>
                  <th class="p-2 border-b border-green-100">#</th>
                  <th class="p-2 border-b border-green-100">Question</th>
                  <th class="p-2 border-b border-green-100">A</th>
                  <th class="p-2 border-b border-green-100">B</th>
                  <th class="p-2 border-b border-green-100">C</th>
                  <th class="p-2 border-b border-green-100">D</th>
                  <th class="p-2 border-b border-green-100">✓</th>
                </tr></thead>
                <tbody id="import-preview-body"></tbody>
              </table>
            </div>
          </div>
          <div class="flex flex-wrap gap-2 mt-4 justify-end">
            <button type="button" id="import-modal-cancel" class="px-4 py-2 rounded-full text-sm font-semibold border border-gray-300 bg-white hover:bg-gray-50">Cancel</button>
            <button type="button" id="import-apply-append" class="px-4 py-2 rounded-full text-sm font-bold bg-green-100 text-green-900 border border-green-300 hover:bg-green-200">Append to exam</button>
            <button type="button" id="import-apply-replace" class="px-4 py-2 rounded-full text-sm font-bold bg-green-600 text-white hover:bg-green-700">Replace all questions</button>
          </div>
        </div>
      </div>
    </div>

    <div id="ocr-modal" class="ocr-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ocr-modal-title">
      <div class="ocr-modal">
        <div class="ocr-modal-head">
          <div>
            <h3 id="ocr-modal-title" class="text-base font-bold text-green-900 m-0">Scan question from image</h3>
            <p class="text-xs text-gray-600 m-0 mt-1">Upload a clear photo or screenshot. Text is read on your device and filled into the question box.</p>
          </div>
          <button type="button" id="ocr-modal-close" class="text-gray-500 hover:text-gray-800 p-2 shrink-0 rounded-lg hover:bg-green-50" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="ocr-drop relative" id="ocr-drop-zone" tabindex="0">
          <input type="file" id="ocr-file-input" accept="image/*" class="absolute opacity-0 w-0 h-0 overflow-hidden" aria-label="Choose image file">
          <i class="bi bi-cloud-arrow-up text-2xl text-green-600"></i>
          <p class="text-sm font-semibold text-green-900 m-1">Drop an image here or click to browse</p>
          <p class="text-[11px] text-gray-500 m-0">PNG, JPG, or screenshots from your textbook work best.</p>
        </div>
        <div class="ocr-progress-track" id="ocr-progress-wrap" hidden>
          <div class="ocr-progress-bar" id="ocr-progress-bar"></div>
        </div>
        <p id="ocr-status" class="text-xs font-medium px-4 pb-3 m-0 min-h-[1.25rem] text-gray-600" role="status"></p>
      </div>
    </div>

    <div id="fab-question-actions" class="fab-add-wrap" hidden aria-hidden="true">
      <span class="fab-add-hint">Quick add</span>
      <button type="button" id="fab-btn-add-mcq" class="fab-add-btn fab-add-btn--mcq">
        <i class="bi bi-plus-lg"></i> Add MCQ
      </button>
      <button type="button" id="fab-btn-add-tf" class="fab-add-btn fab-add-btn--tf">
        <i class="bi bi-plus-lg"></i> Add T/F
      </button>
    </div>

    <div id="exam-validation-modal" class="validation-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="exam-validation-title">
      <div class="validation-modal">
        <div class="validation-modal-head">
          <div class="flex items-start gap-3">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700 text-lg"><i class="bi bi-exclamation-lg"></i></span>
            <div class="min-w-0">
              <h3 id="exam-validation-title" class="text-base font-bold text-red-900 m-0">Cannot save exam</h3>
              <p id="exam-validation-modal-msg" class="text-sm text-gray-700 m-0 mt-1 leading-relaxed">Select the correct answer for every question that has a prompt.</p>
            </div>
          </div>
        </div>
        <div class="validation-modal-body flex justify-end gap-2">
          <button type="button" id="exam-validation-modal-ok" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-bold bg-green-600 text-white hover:bg-green-700 shadow-sm">OK</button>
        </div>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.6/tinymce.min.js" referrerpolicy="origin"></script>
  <script>
  (function () {
    var aiCsrf = <?php echo json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var form = document.getElementById('exam-edit-form');
    var container = document.getElementById('question-blocks');
    var tpl = document.getElementById('question-block-template');
    var addMcqBtn = document.getElementById('btn-add-mcq');
    var addTfBtn = document.getElementById('btn-add-tf');
    var timeReadout = document.getElementById('time-readout');
    var titleInput = document.getElementById('exam-title');
    var descInput = document.getElementById('exam-desc');
    var hoursInput = document.getElementById('time-hours');
    var minutesInput = document.getElementById('time-minutes');
    var pubInput = document.getElementById('pub');
    var availInput = document.getElementById('avail-from');
    var deadlineInput = document.getElementById('deadline');
    var descMdInput = document.getElementById('desc-md');
    var shuffleQInput = document.getElementById('shuffle-q');
    var shuffleMcqInput = document.getElementById('shuffle-mcq');
    var shuffleTfInput = document.getElementById('shuffle-tf');
    var shuffleCInput = document.getElementById('shuffle-c');

    var dirty = false;

    var secsInput = document.getElementById('time-secs');

    function ensureExamTextareaId(el) {
      if (!el) return;
      if (!el.id) el.id = 'exam-q-' + Math.random().toString(36).slice(2, 11);
    }

    function getChoiceInputs(block) {
      return {
        a: block.querySelector('[name="choice_a[]"]'),
        b: block.querySelector('[name="choice_b[]"]'),
        c: block.querySelector('[name="choice_c[]"]'),
        d: block.querySelector('[name="choice_d[]"]')
      };
    }

    function destroyExamRichEditors() {
      if (!window.tinymce) return;
      container.querySelectorAll('textarea.js-exam-q-richtext').forEach(function (el) {
        if (!el.id) return;
        var ed = tinymce.get(el.id);
        if (ed) ed.remove();
      });
    }

    function getPromptHtml(row) {
      var ta = row.querySelector('.js-exam-q-richtext');
      if (!ta) return '';
      ensureExamTextareaId(ta);
      if (window.tinymce && ta.id && tinymce.get(ta.id)) {
        return tinymce.get(ta.id).getContent();
      }
      return ta.value || '';
    }

    function getPromptPlainForAi(row) {
      var html = getPromptHtml(row);
      if (!html || !html.trim()) return '';
      var tmp = document.createElement('div');
      tmp.innerHTML = html;
      return (tmp.textContent || '').trim();
    }

    function promptIsSemanticallyEmpty(row) {
      var html = getPromptHtml(row);
      if (!html || !String(html).trim()) return true;
      var tmp = document.createElement('div');
      tmp.innerHTML = html;
      return (tmp.textContent || '').trim() === '';
    }

    function initExamRichEditors(root) {
      if (!window.tinymce) return;
      var scope = root || container;
      if (!scope || !scope.querySelectorAll) return;
      var nodes = scope.querySelectorAll('textarea.js-exam-q-richtext');
      var examContentCss = 'body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:14px;color:#14532d;line-height:1.5;}table{border-collapse:collapse;margin:0.5em 0;width:100%;max-width:100%;}td,th{border:1px solid #bbf7d0;padding:6px 10px;vertical-align:top;}th{border-color:#86efac;background:linear-gradient(180deg,#f0fdf4,#ecfdf3);}p{margin:0 0 0.5em 0;}ul,ol{margin:0.25em 0 0.5em 1.25em;}';
      nodes.forEach(function (el) {
        ensureExamTextareaId(el);
        if (tinymce.get(el.id)) return;
        tinymce.init({
          selector: '#' + el.id,
          menubar: false,
          height: 220,
          branding: false,
          promotion: false,
          plugins: 'table lists link',
          toolbar: 'undo redo | bold italic underline | bullist numlist | table | removeformat',
          valid_elements: 'p,br,strong/b,em/i,u,sub,sup,ul,ol,li,table,thead,tbody,tfoot,tr,th[colspan|rowspan|scope],td[colspan|rowspan]',
          forced_root_block: 'p',
          skin: 'oxide',
          content_css: false,
          content_style: examContentCss,
          setup: function (editor) {
            editor.on('change input undo redo keyup', function () {
              editor.save();
            });
            editor.on('keyup', function () {
              markDirty();
            });
            editor.on('change', function () {
              markDirty();
              refreshSummary();
            });
          }
        });
      });
    }

    function getTotalSeconds() {
      var h = Math.max(0, parseInt(hoursInput.value, 10) || 0);
      var m = Math.max(0, parseInt(minutesInput.value, 10) || 0);
      var s = Math.max(0, parseInt(secsInput.value, 10) || 0);
      m = Math.min(59, m);
      s = Math.min(59, s);
      h = Math.min(999, h);
      return Math.min(999 * 3600 + 59 * 60 + 59, h * 3600 + m * 60 + s);
    }

    function formatDuration(sec) {
      if (sec <= 0) return 'No per-exam timer';
      var h = Math.floor(sec / 3600);
      var m = Math.floor((sec % 3600) / 60);
      var s = sec % 60;
      var bits = [];
      if (h) bits.push(h + (h === 1 ? ' hr' : ' hr'));
      if (m) bits.push(m + (m === 1 ? ' min' : ' min'));
      if (s) bits.push(s + (s === 1 ? ' sec' : ' sec'));
      if (!bits.length) return 'No per-exam timer';
      return '≈ ' + bits.join(' ');
    }

    function countFilledQuestions() {
      var n = 0;
      container.querySelectorAll('[data-question-row]').forEach(function (row) {
        if (!promptIsSemanticallyEmpty(row)) n++;
      });
      return n;
    }

    function renumber() {
      var blocks = container.querySelectorAll('[data-question-row]');
      blocks.forEach(function (block, i) {
        var num = i + 1;
        var numEl = block.querySelector('.js-q-num');
        if (numEl) numEl.textContent = String(num);
        var up = block.querySelector('.js-q-up');
        var down = block.querySelector('.js-q-down');
        var rem = block.querySelector('.js-q-remove');
        if (up) up.disabled = i === 0;
        if (down) down.disabled = i === blocks.length - 1;
        if (rem) rem.disabled = blocks.length <= 1;
      });
      updateFabVisibility();
    }

    function updateFabVisibility() {
      var fab = document.getElementById('fab-question-actions');
      if (!fab) return;
      var n = container.querySelectorAll('[data-question-row]').length;
      if (n >= 5) {
        fab.removeAttribute('hidden');
        fab.setAttribute('aria-hidden', 'false');
      } else {
        fab.setAttribute('hidden', '');
        fab.setAttribute('aria-hidden', 'true');
      }
    }

    function applyTypeUI(block, type) {
      var t = (type === 'tf') ? 'tf' : 'mcq';
      var hint = block.querySelector('.js-q-type-hint');
      var sel = block.querySelector('.js-q-correct');
      var ch = getChoiceInputs(block);
      if (hint) hint.textContent = (t === 'tf') ? 'True/False uses A=True and B=False.' : 'Use A-D choices for MCQ.';
      if (t === 'tf') {
        if (ch.a) { ch.a.value = 'True'; ch.a.readOnly = true; ch.a.placeholder = 'A (True)'; }
        if (ch.b) { ch.b.value = 'False'; ch.b.readOnly = true; ch.b.placeholder = 'B (False)'; }
        if (ch.c) { ch.c.value = ''; ch.c.readOnly = true; ch.c.placeholder = 'Disabled for T/F'; }
        if (ch.d) { ch.d.value = ''; ch.d.readOnly = true; ch.d.placeholder = 'Disabled for T/F'; }
        if (sel) {
          Array.prototype.forEach.call(sel.options, function (op) {
            op.hidden = !(op.value === '' || op.value === 'A' || op.value === 'B');
          });
          if (sel.value !== 'A' && sel.value !== 'B') sel.value = '';
        }
      } else {
        if (ch.a) { ch.a.readOnly = false; ch.a.placeholder = 'A'; }
        if (ch.b) { ch.b.readOnly = false; ch.b.placeholder = 'B'; }
        if (ch.c) { ch.c.readOnly = false; ch.c.placeholder = 'C'; }
        if (ch.d) { ch.d.readOnly = false; ch.d.placeholder = 'D'; }
        if (sel) {
          Array.prototype.forEach.call(sel.options, function (op) { op.hidden = false; });
        }
      }
      var ocrBtn = block.querySelector('.js-q-ocr');
      if (ocrBtn) {
        ocrBtn.style.display = (t === 'tf') ? 'none' : '';
        ocrBtn.disabled = (t === 'tf');
        ocrBtn.setAttribute('aria-hidden', t === 'tf' ? 'true' : 'false');
      }
    }

    function addBlock(data, forcedType) {
      var node = tpl.content.cloneNode(true).firstElementChild;
      var typeSel = node.querySelector('.js-q-type');
      if (data) {
        if (typeSel) {
          var t0 = String(data.question_type || 'mcq').toLowerCase();
          typeSel.value = (t0 === 'tf') ? 'tf' : 'mcq';
        }
        var ta = node.querySelector('.js-q-prompt');
        if (ta) ta.value = data.question_text || '';
        var ch = getChoiceInputs(node);
        if (ch.a) ch.a.value = data.choice_a != null ? String(data.choice_a) : '';
        if (ch.b) ch.b.value = data.choice_b != null ? String(data.choice_b) : '';
        if (ch.c) ch.c.value = data.choice_c != null ? String(data.choice_c) : '';
        if (ch.d) ch.d.value = data.choice_d != null ? String(data.choice_d) : '';
        var sel = node.querySelector('.js-q-correct');
        if (sel) {
          var t0 = typeSel ? typeSel.value : 'mcq';
          var L = data.correct_answer ? String(data.correct_answer).toUpperCase().charAt(0) : '';
          if (t0 === 'tf') {
            sel.value = (L === 'A' || L === 'B') ? L : '';
          } else {
            sel.value = ('ABCD'.indexOf(L) >= 0) ? L : '';
          }
        }
      } else if (typeSel) {
        typeSel.value = forcedType === 'tf' ? 'tf' : 'mcq';
      }
      container.appendChild(node);
      bindBlock(node);
      applyTypeUI(node, typeSel ? typeSel.value : 'mcq');
      renumber();
      var taNew = node.querySelector('.js-exam-q-richtext');
      if (taNew) ensureExamTextareaId(taNew);
      initExamRichEditors(node);
    }

    function bindBlock(block) {
      block.querySelector('.js-q-up').addEventListener('click', function () {
        var prev = block.previousElementSibling;
        if (prev) {
          destroyExamRichEditors();
          container.insertBefore(block, prev);
          renumber();
          initExamRichEditors(container);
          markDirty();
          refreshSummary();
        }
      });
      block.querySelector('.js-q-down').addEventListener('click', function () {
        var next = block.nextElementSibling;
        if (next) {
          destroyExamRichEditors();
          container.insertBefore(next, block);
          renumber();
          initExamRichEditors(container);
          markDirty();
          refreshSummary();
        }
      });
      block.querySelector('.js-q-remove').addEventListener('click', function () {
        var blocks = container.querySelectorAll('[data-question-row]');
        if (blocks.length <= 1) return;
        destroyExamRichEditors();
        block.remove();
        renumber();
        initExamRichEditors(container);
        markDirty();
        refreshSummary();
      });
      block.querySelector('.js-ai-gen').addEventListener('click', function () {
        var stem = getPromptPlainForAi(block);
        if (!stem) {
          alert('Enter a question stem first.');
          return;
        }
        var btn = block.querySelector('.js-ai-gen');
        btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', aiCsrf);
        fd.append('action', 'generate_options');
        fd.append('stem', stem);
        fetch('professor_exam_ai.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            btn.disabled = false;
            if (!data.ok) {
              alert(data.error || 'Request failed');
              return;
            }
            var ch = getChoiceInputs(block);
            if (ch.a) ch.a.value = data.choice_a || '';
            if (ch.b) ch.b.value = data.choice_b || '';
            if (ch.c) ch.c.value = data.choice_c || '';
            if (ch.d) ch.d.value = data.choice_d || '';
            var sel = block.querySelector('.js-q-correct');
            if (sel && data.correct) sel.value = String(data.correct).toUpperCase().charAt(0);
            if (data.note) {
              /* optional: show once */
            }
            markDirty();
            refreshSummary();
          })
          .catch(function () {
            btn.disabled = false;
            alert('Network error.');
          });
      });
      block.querySelector('.js-q-ocr').addEventListener('click', function () {
        var typeSel = block.querySelector('.js-q-type');
        if (typeSel && typeSel.value === 'tf') return;
        openOcrModal(block);
      });
      block.querySelector('.js-ai-dist').addEventListener('click', function () {
        var stem = getPromptPlainForAi(block);
        if (!stem) {
          alert('Enter a question stem first.');
          return;
        }
        var sel = block.querySelector('.js-q-correct');
        var cor = sel ? sel.value : 'A';
        var ch = getChoiceInputs(block);
        var fd = new FormData();
        fd.append('csrf_token', aiCsrf);
        fd.append('action', 'suggest_distractors');
        fd.append('stem', stem);
        fd.append('correct_letter', cor);
        fd.append('choice_a', ch.a ? ch.a.value : '');
        fd.append('choice_b', ch.b ? ch.b.value : '');
        fd.append('choice_c', ch.c ? ch.c.value : '');
        fd.append('choice_d', ch.d ? ch.d.value : '');
        if (!(ch.a && ch.a.value.trim())) {
          alert('Fill the correct option text (the letter marked correct) before asking for distractors.');
          return;
        }
        var btn = block.querySelector('.js-ai-dist');
        btn.disabled = true;
        fetch('professor_exam_ai.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            btn.disabled = false;
            if (!data.ok) {
              alert(data.error || 'Request failed');
              return;
            }
            if (ch.a) ch.a.value = data.choice_a || '';
            if (ch.b) ch.b.value = data.choice_b || '';
            if (ch.c) ch.c.value = data.choice_c || '';
            if (ch.d) ch.d.value = data.choice_d || '';
            if (sel && data.correct) sel.value = String(data.correct).toUpperCase().charAt(0);
            markDirty();
            refreshSummary();
          })
          .catch(function () {
            btn.disabled = false;
            alert('Network error.');
          });
      });
      block.querySelectorAll('textarea, input, select').forEach(function (el) {
        el.addEventListener('input', function () { markDirty(); refreshSummary(); });
        el.addEventListener('change', function () { markDirty(); refreshSummary(); });
      });
      var typeSel = block.querySelector('.js-q-type');
      if (typeSel) {
        typeSel.addEventListener('change', function () {
          applyTypeUI(block, typeSel.value);
          markDirty();
          refreshSummary();
        });
      }
    }

    function windowSummaryShort() {
      var af = availInput.value.trim();
      var dl = deadlineInput.value.trim();
      if (!af && !dl) return 'No date limits';
      if (af && dl) return 'Window + deadline';
      if (af) return 'Opens later';
      return 'Deadline set';
    }

    function refreshSummary() {
      var sec = getTotalSeconds();
      var n = countFilledQuestions();
      var mcqCount = 0;
      var tfCount = 0;
      container.querySelectorAll('[data-question-row]').forEach(function (row) {
        var t = row.querySelector('.js-q-type');
        if (t && t.value === 'tf') tfCount++; else mcqCount++;
      });
      var published = pubInput.checked;
      var timeStr = formatDuration(sec);

      if (timeReadout) {
        timeReadout.textContent = sec > 0 ? (timeStr.replace(/^≈ /, '') + ' · ' + sec.toLocaleString() + ' s') : 'No countdown';
      }

      var bar = document.getElementById('hero-status-bar');
      if (bar) {
        var qPill = '<span class="hero-pill' + (n === 0 ? ' is-warn' : '') + '">' + (n === 0 ? '0 Q' : n + ' Q') + '</span>';
        var catPill = '<span class="hero-pill">MCQ ' + mcqCount + ' · T/F ' + tfCount + '</span>';
        var tPill = '<span class="hero-pill">' + (sec <= 0 ? 'No timer' : timeStr.replace(/^≈ /, '')) + '</span>';
        var pPill = '<span class="hero-pill">' + (published ? 'Live' : 'Draft') + '</span>';
        var wPill = '<span class="hero-pill">' + windowSummaryShort() + '</span>';
        bar.innerHTML = qPill + catPill + tPill + pPill + wPill;
      }
    }

    function markDirty() {
      dirty = true;
    }

    function snapshotForm() {
      return JSON.stringify({
        title: titleInput.value,
        desc: descInput.value,
        h: hoursInput.value,
        m: minutesInput.value,
        s: secsInput.value,
        pub: pubInput.checked,
        af: availInput.value,
        dl: deadlineInput.value,
        descMd: descMdInput ? descMdInput.checked : false,
        shQ: shuffleQInput ? shuffleQInput.checked : false,
        shMQ: shuffleMcqInput ? shuffleMcqInput.checked : false,
        shTF: shuffleTfInput ? shuffleTfInput.checked : false,
        shC: shuffleCInput ? shuffleCInput.checked : false,
        qs: Array.prototype.map.call(container.querySelectorAll('[data-question-row]'), function (row) {
          var typeSel = row.querySelector('.js-q-type');
          return {
            qt: typeSel ? typeSel.value : 'mcq',
            p: getPromptHtml(row),
            a: row.querySelector('[name="choice_a[]"]').value,
            b: row.querySelector('[name="choice_b[]"]').value,
            c: row.querySelector('[name="choice_c[]"]').value,
            d: row.querySelector('[name="choice_d[]"]').value,
            cor: row.querySelector('.js-q-correct').value
          };
        })
      });
    }

    hoursInput.addEventListener('input', function () {
      var v = parseInt(hoursInput.value, 10);
      if (v > 999) hoursInput.value = '999';
      markDirty();
      refreshSummary();
    });
    minutesInput.addEventListener('input', function () {
      var v = parseInt(minutesInput.value, 10);
      if (v > 59) minutesInput.value = '59';
      if (v < 0) minutesInput.value = '0';
      markDirty();
      refreshSummary();
    });
    secsInput.addEventListener('input', function () {
      var v = parseInt(secsInput.value, 10);
      if (v > 59) secsInput.value = '59';
      if (v < 0) secsInput.value = '0';
      markDirty();
      refreshSummary();
    });
    [titleInput, descInput, pubInput, availInput, deadlineInput, descMdInput, shuffleQInput, shuffleMcqInput, shuffleTfInput, shuffleCInput].forEach(function (el) {
      if (!el) return;
      el.addEventListener('input', function () { markDirty(); refreshSummary(); });
      el.addEventListener('change', function () { markDirty(); refreshSummary(); });
    });

    function addQuestionAndScroll(forcedType) {
      addBlock(null, forcedType);
      markDirty();
      refreshSummary();
      updateFabVisibility();
      var last = container.querySelector('[data-question-row]:last-child');
      if (last) {
        requestAnimationFrame(function () {
          last.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          var ta = last.querySelector('.js-exam-q-richtext');
          ensureExamTextareaId(ta);
          if (ta && ta.id && window.tinymce && tinymce.get(ta.id)) {
            tinymce.get(ta.id).focus();
          } else if (ta) ta.focus();
        });
      }
    }

    addMcqBtn.addEventListener('click', function () { addQuestionAndScroll('mcq'); });
    addTfBtn.addEventListener('click', function () { addQuestionAndScroll('tf'); });

    var fabMcq = document.getElementById('fab-btn-add-mcq');
    var fabTf = document.getElementById('fab-btn-add-tf');
    if (fabMcq) fabMcq.addEventListener('click', function () { addQuestionAndScroll('mcq'); });
    if (fabTf) fabTf.addEventListener('click', function () { addQuestionAndScroll('tf'); });

    var ocrModal = document.getElementById('ocr-modal');
    var ocrFileInput = document.getElementById('ocr-file-input');
    var ocrDropZone = document.getElementById('ocr-drop-zone');
    var ocrProgressWrap = document.getElementById('ocr-progress-wrap');
    var ocrProgressBar = document.getElementById('ocr-progress-bar');
    var ocrStatus = document.getElementById('ocr-status');
    var ocrTargetBlock = null;

    function formatOcrForQuestion(raw) {
      var t = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
      var lines = t.split('\n');
      var out = [];
      for (var i = 0; i < lines.length; i++) {
        var ln = lines[i].replace(/\u00a0/g, ' ').trim();
        if (!ln) {
          out.push('');
          continue;
        }
        if (ln.indexOf('\t') >= 0) {
          out.push(ln.split(/\t+/).map(function (x) { return x.trim(); }).filter(Boolean).join(' | '));
          continue;
        }
        var parts = ln.split(/\s{2,}/).map(function (x) { return x.trim(); }).filter(Boolean);
        if (parts.length >= 3 && parts.length <= 10) {
          var joined = parts.join(' | ');
          if (joined.length >= ln.length * 0.5) out.push(joined);
          else out.push(ln);
        } else {
          out.push(ln);
        }
      }
      return out.join('\n').replace(/\n{3,}/g, '\n\n').trim();
    }

    function loadTesseractScript() {
      return new Promise(function (resolve, reject) {
        if (window.Tesseract && (typeof window.Tesseract.recognize === 'function' || typeof window.Tesseract.createWorker === 'function')) {
          resolve();
          return;
        }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
        s.async = true;
        s.onload = function () { resolve(); };
        s.onerror = function () { reject(new Error('Could not load OCR library.')); };
        document.head.appendChild(s);
      });
    }

    function closeOcrModal() {
      if (ocrModal) ocrModal.classList.remove('is-open');
      ocrTargetBlock = null;
      if (ocrFileInput) ocrFileInput.value = '';
      if (ocrProgressWrap) ocrProgressWrap.setAttribute('hidden', '');
      if (ocrProgressBar) ocrProgressBar.style.width = '0%';
    }

    function openOcrModal(block) {
      ocrTargetBlock = block;
      if (ocrStatus) ocrStatus.textContent = '';
      if (ocrProgressWrap) ocrProgressWrap.setAttribute('hidden', '');
      if (ocrProgressBar) ocrProgressBar.style.width = '0%';
      if (ocrModal) ocrModal.classList.add('is-open');
    }

    function runOcrOnFile(file) {
      if (!file || !ocrTargetBlock) return;
      if (ocrProgressWrap) ocrProgressWrap.removeAttribute('hidden');
      if (ocrProgressBar) ocrProgressBar.style.width = '5%';
      if (ocrStatus) ocrStatus.textContent = 'Loading OCR engine…';
      var logger = function (m) {
        if (ocrProgressBar && (m.status === 'recognizing text' || m.status === 'loading tesseract core')) {
          var prog = typeof m.progress === 'number' ? m.progress : (m.status === 'loading tesseract core' ? 0.1 : 0);
          var p = Math.max(5, Math.min(99, Math.round(prog * 100)));
          ocrProgressBar.style.width = p + '%';
        }
      };
      loadTesseractScript()
        .then(function () {
          if (ocrStatus) ocrStatus.textContent = 'Reading text from image…';
          var T = window.Tesseract;
          if (typeof T.recognize === 'function') {
            return T.recognize(file, 'eng', { logger: logger }).then(function (res) {
              return res && res.data && res.data.text ? res.data.text : '';
            });
          }
          return T.createWorker('eng', 1, { logger: logger }).then(function (worker) {
            return worker.recognize(file).then(function (res) {
              var txt = res && res.data && res.data.text ? res.data.text : '';
              return worker.terminate().then(function () { return txt; });
            });
          });
        })
        .then(function (text) {
          var formatted = formatOcrForQuestion(text);
          var ta = ocrTargetBlock && ocrTargetBlock.querySelector('.js-exam-q-richtext');
          if (ta) {
            ensureExamTextareaId(ta);
            var lines = formatted.split('\n').length;
            ta.rows = Math.min(28, Math.max(4, lines + 2));
            if (/\|/.test(formatted) && formatted.indexOf('\n') >= 0) {
              ta.classList.add('has-ocr-table');
            } else {
              ta.classList.remove('has-ocr-table');
            }
            var escLine = function (s) {
              var d = document.createElement('div');
              d.textContent = s;
              return d.innerHTML;
            };
            var ocrHtml = '<p>' + formatted.split(/\n/).map(escLine).join('<br>') + '</p>';
            ta.value = formatted;
            if (window.tinymce && ta.id && tinymce.get(ta.id)) {
              tinymce.get(ta.id).setContent(ocrHtml);
              tinymce.get(ta.id).save();
            }
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            markDirty();
            refreshSummary();
          }
          closeOcrModal();
        })
        .catch(function (err) {
          if (ocrStatus) ocrStatus.textContent = (err && err.message) ? err.message : 'OCR failed. Try a sharper image.';
          if (ocrProgressBar) ocrProgressBar.style.width = '0%';
        });
    }

    var ocrModalCloseBtn = document.getElementById('ocr-modal-close');
    if (ocrModalCloseBtn) ocrModalCloseBtn.addEventListener('click', closeOcrModal);
    if (ocrModal) {
      ocrModal.addEventListener('click', function (e) {
        if (e.target === ocrModal) closeOcrModal();
      });
    }
    if (ocrDropZone && ocrFileInput) {
      ocrDropZone.addEventListener('click', function () { ocrFileInput.click(); });
      ocrDropZone.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          ocrFileInput.click();
        }
      });
      ocrFileInput.addEventListener('change', function () {
        var f = ocrFileInput.files && ocrFileInput.files[0];
        if (f) runOcrOnFile(f);
      });
      ['dragenter', 'dragover'].forEach(function (ev) {
        ocrDropZone.addEventListener(ev, function (e) {
          e.preventDefault();
          e.stopPropagation();
          ocrDropZone.classList.add('is-drag');
        });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        ocrDropZone.addEventListener(ev, function (e) {
          e.preventDefault();
          e.stopPropagation();
          ocrDropZone.classList.remove('is-drag');
        });
      });
      ocrDropZone.addEventListener('drop', function (e) {
        var files = e.dataTransfer && e.dataTransfer.files;
        if (files && files[0] && /^image\//.test(files[0].type)) runOcrOnFile(files[0]);
        else if (ocrStatus) ocrStatus.textContent = 'Please drop an image file.';
      });
    }

    var boot = <?php echo json_encode(array_map(function ($q) {
        return [
            'question_type' => $q['question_type'] ?? 'mcq',
            'question_text' => $q['question_text'] ?? '',
            'choice_a' => $q['choice_a'] ?? '',
            'choice_b' => $q['choice_b'] ?? '',
            'choice_c' => $q['choice_c'] ?? '',
            'choice_d' => $q['choice_d'] ?? '',
            'correct_answer' => $q['correct_answer'] ?? '',
        ];
    }, $questions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function validateMissingCorrect() {
      var missing = [];
      var blocks = container.querySelectorAll('[data-question-row]');
      blocks.forEach(function (block, idx) {
        if (promptIsSemanticallyEmpty(block)) return;
        var typeSel = block.querySelector('.js-q-type');
        var isTf = typeSel && typeSel.value === 'tf';
        var sel = block.querySelector('.js-q-correct');
        var v = sel ? String(sel.value || '').trim() : '';
        if (isTf) {
          if (v !== 'A' && v !== 'B') missing.push(idx + 1);
        } else if (!/^[A-D]$/.test(v)) {
          missing.push(idx + 1);
        }
      });
      return missing;
    }

    function openMissingCorrectModal(nums) {
      var el = document.getElementById('exam-validation-modal');
      var msg = document.getElementById('exam-validation-modal-msg');
      if (msg) {
        msg.textContent = 'Select the correct answer for question ' + (nums.length === 1 ? 'number ' : 'numbers ') + nums.join(', ') + ' (A–D for MCQ, or A/B for True/False).';
      }
      if (el) el.classList.add('is-open');
    }

    function closeMissingCorrectModal() {
      var el = document.getElementById('exam-validation-modal');
      if (el) el.classList.remove('is-open');
      container.querySelectorAll('.question-block--error').forEach(function (b) {
        b.classList.remove('question-block--error');
      });
    }

    function highlightInvalidCorrectQuestions(nums) {
      container.querySelectorAll('.question-block--error').forEach(function (b) {
        b.classList.remove('question-block--error');
      });
      nums.forEach(function (num) {
        var block = container.querySelectorAll('[data-question-row]')[num - 1];
        if (block) block.classList.add('question-block--error');
      });
      var first = nums[0];
      if (first) {
        var fb = container.querySelectorAll('[data-question-row]')[first - 1];
        if (fb) {
          fb.scrollIntoView({ behavior: 'smooth', block: 'center' });
          setTimeout(function () {
            var sel = fb.querySelector('.js-q-correct');
            if (sel) sel.focus();
          }, 450);
        }
      }
    }

    var examValModal = document.getElementById('exam-validation-modal');
    var examValOk = document.getElementById('exam-validation-modal-ok');
    if (examValOk) examValOk.addEventListener('click', closeMissingCorrectModal);
    if (examValModal) {
      examValModal.addEventListener('click', function (e) {
        if (e.target === examValModal) closeMissingCorrectModal();
      });
    }

    boot.forEach(function (row) { addBlock(row); });
    updateFabVisibility();

    form.addEventListener('submit', function (e) {
      if (window.tinymce) tinymce.triggerSave();
      var action = e.submitter && e.submitter.getAttribute('value');
      var isFinal = action === 'final' || !action;
      var t = titleInput.value.trim();
      if (isFinal) {
        if (t === '') {
          e.preventDefault();
          titleInput.focus();
          alert('Please enter a title before saving the exam.');
          return;
        }
        var miss = validateMissingCorrect();
        if (miss.length) {
          e.preventDefault();
          openMissingCorrectModal(miss);
          highlightInvalidCorrectQuestions(miss);
          return;
        }
        if (countFilledQuestions() < 1) {
          e.preventDefault();
          alert('Add at least one question with a prompt before using Save exam.');
          return;
        }
      }
      dirty = false;
    });

    window.addEventListener('beforeunload', function (e) {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = '';
    });

    function parseCSVLine(str) {
      var row = [];
      var cur = '';
      var i = 0;
      var inQ = false;
      while (i < str.length) {
        var c = str[i];
        if (inQ) {
          if (c === '"') {
            if (str[i + 1] === '"') {
              cur += '"';
              i += 2;
              continue;
            }
            inQ = false;
            i++;
            continue;
          }
          cur += c;
          i++;
        } else {
          if (c === '"') {
            inQ = true;
            i++;
            continue;
          }
          if (c === ',') {
            row.push(cur.trim());
            cur = '';
            i++;
            continue;
          }
          cur += c;
          i++;
        }
      }
      row.push(cur.trim());
      return row;
    }

    function parseImportedCSV(text) {
      var lines = text.trim().split(/\r?\n/).filter(function (l) {
        return l.trim() !== '';
      });
      if (!lines.length) return [];
      var rows = lines.map(parseCSVLine);
      var header = rows[0].map(function (x) {
        return String(x).toLowerCase().replace(/\s+/g, '_');
      });
      var hasHeader = header.some(function (h) {
        return h.indexOf('question') !== -1 || h === 'q' || h.indexOf('choice') !== -1 || h.indexOf('correct') !== -1 || h === 'answer' || h === 'ans';
      });
      var h = hasHeader ? header : ['question', 'choice_a', 'choice_b', 'choice_c', 'choice_d', 'correct'];
      var dataRows = hasHeader ? rows.slice(1) : rows;
      function ix(name) {
        var keys = {
          question: ['question', 'question_text', 'q', 'stem', 'prompt'],
          choice_a: ['choice_a', 'a'],
          choice_b: ['choice_b', 'b'],
          choice_c: ['choice_c', 'c'],
          choice_d: ['choice_d', 'd'],
          correct: ['correct', 'answer', 'ans', 'key']
        };
        var kk = keys[name];
        for (var i = 0; i < kk.length; i++) {
          var j = h.indexOf(kk[i]);
          if (j >= 0) return j;
        }
        return -1;
      }
      function cell(row, name) {
        if (!hasHeader) {
          var fi = { question: 0, choice_a: 1, choice_b: 2, choice_c: 3, choice_d: 4, correct: 5 };
          return row[fi[name]] !== undefined ? String(row[fi[name]]) : '';
        }
        var j = ix(name);
        return j >= 0 && row[j] !== undefined ? String(row[j]) : '';
      }
      var out = [];
      for (var r = 0; r < dataRows.length; r++) {
        var row = dataRows[r];
        var q = cell(row, 'question').trim();
        if (!q) continue;
        var cor = cell(row, 'correct').trim().toUpperCase().charAt(0);
        if (!/^[A-D]$/.test(cor)) cor = '';
        out.push({
          question_text: q,
          choice_a: cell(row, 'choice_a').trim(),
          choice_b: cell(row, 'choice_b').trim(),
          choice_c: cell(row, 'choice_c').trim(),
          choice_d: cell(row, 'choice_d').trim(),
          correct_answer: cor
        });
      }
      return out;
    }

    function parseImportedPaste(text) {
      var blocks = text.split(/\n\s*\n/).map(function (b) {
        return b.trim();
      }).filter(Boolean);
      var out = [];
      for (var b = 0; b < blocks.length; b++) {
        var lines = blocks[b].split(/\r?\n/).map(function (l) {
          return l.trim();
        }).filter(function (l) {
          return l !== '';
        });
        if (!lines.length) continue;
        var choices = { A: '', B: '', C: '', D: '' };
        var ans = '';
        var qLines = [];
        var i = 0;
        for (; i < lines.length; i++) {
          var ln = lines[i];
          var am = /^(?:answer|correct|key)\s*:\s*([A-D])/i.exec(ln);
          if (am) {
            ans = am[1].toUpperCase();
            continue;
          }
          var m = /^([A-D])[\).\:\s]\s*(.*)$/.exec(ln);
          if (m) break;
          qLines.push(ln);
        }
        for (; i < lines.length; i++) {
          ln = lines[i];
          am = /^(?:answer|correct|key)\s*:\s*([A-D])/i.exec(ln);
          if (am) {
            ans = am[1].toUpperCase();
            continue;
          }
          m = /^([A-D])[\).\:\s]\s*(.*)$/.exec(ln);
          if (m) {
            choices[m[1]] = m[2];
          }
        }
        var stem = qLines.join('\n').trim();
        if (!stem) continue;
        out.push({
          question_text: stem,
          choice_a: choices.A,
          choice_b: choices.B,
          choice_c: choices.C,
          choice_d: choices.D,
          correct_answer: ans
        });
      }
      return out;
    }

    var importModal = document.getElementById('import-modal');
    var importTab = 'csv';
    var lastParsed = [];

    function renderImportPreview(rows) {
      var tb = document.getElementById('import-preview-body');
      lastParsed = rows;
      tb.innerHTML = rows.map(function (r, idx) {
        return '<tr class="border-b border-green-50"><td class="p-2">' + (idx + 1) + '</td><td class="p-2 max-w-xs truncate">' +
          (r.question_text || '').replace(/</g, '&lt;') + '</td><td class="p-2 truncate">' + (r.choice_a || '').replace(/</g, '&lt;') +
          '</td><td class="p-2 truncate">' + (r.choice_b || '').replace(/</g, '&lt;') + '</td><td class="p-2 truncate">' + (r.choice_c || '').replace(/</g, '&lt;') +
          '</td><td class="p-2 truncate">' + (r.choice_d || '').replace(/</g, '&lt;') + '</td><td class="p-2 font-mono">' + (r.correct_answer ? r.correct_answer : '—') + '</td></tr>';
      }).join('');
    }

    function runImportParse() {
      var err = document.getElementById('import-parse-error');
      err.classList.add('hidden');
      err.textContent = '';
      try {
        var rows = importTab === 'csv'
          ? parseImportedCSV(document.getElementById('import-csv-text').value)
          : parseImportedPaste(document.getElementById('import-paste-text').value);
        if (!rows.length) {
          err.textContent = 'No questions parsed. Check the format.';
          err.classList.remove('hidden');
          renderImportPreview([]);
          return;
        }
        renderImportPreview(rows);
      } catch (e) {
        err.textContent = 'Could not parse: ' + (e.message || 'error');
        err.classList.remove('hidden');
      }
    }

    document.querySelectorAll('[data-import-tab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        importTab = btn.getAttribute('data-import-tab');
        document.querySelectorAll('[data-import-tab]').forEach(function (b) {
          b.classList.toggle('is-active', b === btn);
        });
        document.getElementById('import-panel-csv').classList.toggle('hidden', importTab !== 'csv');
        document.getElementById('import-panel-paste').classList.toggle('hidden', importTab !== 'paste');
        runImportParse();
      });
    });
    document.getElementById('import-csv-text').addEventListener('input', runImportParse);
    document.getElementById('import-paste-text').addEventListener('input', runImportParse);

    function openImportModal() {
      importModal.classList.add('is-open');
      runImportParse();
    }
    function closeImportModal() {
      importModal.classList.remove('is-open');
    }
    document.getElementById('btn-open-import').addEventListener('click', openImportModal);
    document.getElementById('import-modal-close').addEventListener('click', closeImportModal);
    document.getElementById('import-modal-cancel').addEventListener('click', closeImportModal);
    importModal.addEventListener('click', function (e) {
      if (e.target === importModal) closeImportModal();
    });

    function applyImportedQuestions(replace) {
      if (!lastParsed.length) {
        alert('Nothing to import. Paste or type data and check the preview.');
        return;
      }
      if (replace) {
        destroyExamRichEditors();
        container.innerHTML = '';
      }
      lastParsed.forEach(function (row) {
        addBlock(row);
      });
      renumber();
      markDirty();
      refreshSummary();
      closeImportModal();
    }
    document.getElementById('import-apply-append').addEventListener('click', function () {
      applyImportedQuestions(false);
    });
    document.getElementById('import-apply-replace').addEventListener('click', function () {
      if (!confirm('Replace all questions on this form with the imported set?')) return;
      applyImportedQuestions(true);
    });

    var initialSnapshot = snapshotForm();
    setInterval(function () {
      if (snapshotForm() !== initialSnapshot) markDirty();
    }, 500);

    refreshSummary();
  })();
  </script>
</body>
</html>
