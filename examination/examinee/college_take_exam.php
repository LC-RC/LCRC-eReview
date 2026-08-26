<?php
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
ereview_require_college_examination_portal();
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_exam_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/quiz_helpers.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

$pageTitle = 'Take exam';
$uid = getCurrentUserId();
$examId = sanitizeInt($_GET['exam_id'] ?? 0);
$reviewParam = $_GET['review'] ?? null;
$reviewMode = $reviewParam !== null
    && $reviewParam !== ''
    && !in_array(strtolower((string)$reviewParam), ['0', 'false', 'no'], true);
$csrf = generateCSRFToken();

if ($examId <= 0) {
    header('Location: college_exams');
    exit;
}

$pubWhere = college_exam_where_published_sql();
$stmt = mysqli_prepare($conn, "SELECT * FROM college_exams WHERE exam_id=? AND {$pubWhere} LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $examId);
mysqli_stmt_execute($stmt);
$exam = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$exam) {
    $_SESSION['error'] = 'Exam not found.';
    header('Location: college_exams');
    exit;
}

$now = date('Y-m-d H:i:s');
college_exam_finalize_expired_in_progress($conn, (int)$examId, (int)$uid, 0);
$stmt = mysqli_prepare($conn, "SELECT * FROM college_exam_attempts WHERE user_id=? AND exam_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ii', $uid, $examId);
mysqli_stmt_execute($stmt);
$attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!college_exam_user_can_view($conn, (int)$uid, $exam, $attempt ?: null)) {
    $_SESSION['error'] = 'You do not have access to this examination.';
    header('Location: college_exams');
    exit;
}

$profName = 'Professor';
$creatorId = (int)($exam['created_by'] ?? 0);
if ($creatorId > 0) {
    $pst = mysqli_prepare($conn, "SELECT full_name FROM users WHERE user_id=? LIMIT 1");
    if ($pst) {
        mysqli_stmt_bind_param($pst, 'i', $creatorId);
        mysqli_stmt_execute($pst);
        $pres = mysqli_stmt_get_result($pst);
        $prow = $pres ? mysqli_fetch_assoc($pres) : null;
        if ($prow && !empty($prow['full_name'])) {
            $profName = (string)$prow['full_name'];
        }
        mysqli_stmt_close($pst);
    }
}

$attemptStatusNorm = college_exam_attempt_status_normalized($attempt);
$attemptSubmitted = college_exam_attempt_is_effectively_submitted($attempt);

$subCntR = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exam_attempts WHERE exam_id=" . (int)$examId . " AND status='submitted'");
$classSubmittedCount = 0;
if ($subCntR) {
    $subCntRrow = mysqli_fetch_assoc($subCntR);
    $classSubmittedCount = (int)($subCntRrow['c'] ?? 0);
    mysqli_free_result($subCntR);
}
$examClosedAllSubmitted = college_exam_finished_all_submitted_no_deadline($conn, $exam, $classSubmittedCount);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_exam'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: college_take_exam?exam_id=' . $examId);
        exit;
    }
    if (!college_exam_user_can_start($conn, (int)$uid, $exam, $now)) {
        $_SESSION['error'] = 'This examination is not available to you at this time.';
        header('Location: college_exams');
        exit;
    }
    if ($attempt && $attemptSubmitted) {
        header('Location: college_take_exam?exam_id=' . $examId . '&review=1');
        exit;
    }
    if ($examClosedAllSubmitted && (!$attempt || $attemptStatusNorm !== 'in_progress')) {
        $_SESSION['error'] = 'This exam has closed because everyone on the roster has submitted.';
        header('Location: college_exams');
        exit;
    }
    if (!empty($exam['available_from']) && $exam['available_from'] > $now) {
        $_SESSION['error'] = 'This exam is not available yet.';
        header('Location: college_exams');
        exit;
    }
    if (!empty($exam['deadline']) && $exam['deadline'] < $now) {
        $_SESSION['error'] = 'The deadline has passed.';
        header('Location: college_exams');
        exit;
    }

    $started = date('Y-m-d H:i:s');
    $expiresAt = college_exam_compute_expires_at((int)$exam['time_limit_seconds'], $exam['deadline'] ?? null);
    $startedAttemptId = 0;
    if (!$attempt) {
        $ins = mysqli_prepare($conn, "INSERT INTO college_exam_attempts (exam_id, user_id, status, started_at, expires_at, last_seen_at) VALUES (?, ?, 'in_progress', ?, ?, ?)");
        mysqli_stmt_bind_param($ins, 'iisss', $examId, $uid, $started, $expiresAt, $started);
        mysqli_stmt_execute($ins);
        $startedAttemptId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
    } elseif ($attemptStatusNorm === 'in_progress') {
        header('Location: college_take_exam?exam_id=' . $examId);
        exit;
    } elseif ($attemptStatusNorm === 'expired') {
        $aid = (int)$attempt['attempt_id'];
        $startedAttemptId = $aid;
        mysqli_query($conn, "DELETE FROM college_exam_answers WHERE attempt_id={$aid}");
        $emptyState = '{"current_index":0,"flags":[],"updated_at":0}';
        $upd = mysqli_prepare($conn, "UPDATE college_exam_attempts SET status='in_progress', started_at=?, expires_at=?, submitted_at=NULL, score=NULL, correct_count=NULL, total_count=NULL, ui_state_json=?, last_seen_at=?, exam_session_lock=NULL, tab_switch_count=0, last_tab_switch_at=NULL WHERE attempt_id=? AND user_id=?");
        mysqli_stmt_bind_param($upd, 'ssssii', $started, $expiresAt, $emptyState, $started, $aid, $uid);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }
    if ($startedAttemptId > 0) {
        require_once dirname(__DIR__) . '/includes/college_exam_attempt_events.php';
        college_exam_attempt_event_record($conn, $startedAttemptId, (int)$uid, (int)$examId, 'exam_started', null);
    }
    header('Location: college_take_exam?exam_id=' . $examId);
    exit;
}

if ($examClosedAllSubmitted) {
    if (!$attempt || ($attemptStatusNorm !== 'in_progress' && !$attemptSubmitted)) {
        $_SESSION['error'] = 'This exam has closed because everyone on the roster has submitted.';
        header('Location: college_exams');
        exit;
    }
}

$questions = [];
$qr = mysqli_query($conn, "SELECT * FROM college_exam_questions WHERE exam_id=" . (int)$examId . " ORDER BY sort_order ASC, question_id ASC");
if ($qr) {
    while ($q = mysqli_fetch_assoc($qr)) {
        $questions[] = $q;
    }
    mysqli_free_result($qr);
}
if ($attempt && ($attemptStatusNorm === 'in_progress' || $attemptSubmitted)) {
    $questions = college_exam_prepare_questions_for_attempt($questions, $exam, (int)$attempt['attempt_id']);
}

$answersMap = [];
if ($attempt) {
    $ar = mysqli_query($conn, 'SELECT question_id, selected_answer FROM college_exam_answers WHERE attempt_id=' . (int)$attempt['attempt_id']);
    if ($ar) {
        while ($r = mysqli_fetch_assoc($ar)) {
            $answersMap[(int)$r['question_id']] = $r;
        }
        mysqli_free_result($ar);
    }
}

if ($attempt && $attemptSubmitted && !$reviewMode) {
    header('Location: college_take_exam?exam_id=' . $examId . '&review=1');
    exit;
}

$examSessionBlock = false;
if ($attempt && $attemptStatusNorm === 'in_progress') {
    $lockResult = college_exam_ensure_attempt_session_lock($conn, (int)$attempt['attempt_id'], (int)$uid);
    $examSessionBlock = empty($lockResult['ok']) || !empty($lockResult['blocked']);
}

$attemptStatusNorm = college_exam_attempt_status_normalized($attempt);
$attemptSubmitted = college_exam_attempt_is_effectively_submitted($attempt);

if (!empty($examSessionBlock)) {
    $pageTitle = 'Exam session active';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_app.php'; ?>
  <style>
    body { background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%); min-height: 100vh; }
    .exam-lock-card { max-width: 28rem; margin: 4rem auto; padding: 2rem; border-radius: 1rem; border: 1px solid #cbd5e1; background: #fff; box-shadow: 0 20px 40px -24px rgba(15,23,42,.35); }
    .exam-lock-icon { width: 3.5rem; height: 3.5rem; border-radius: 999px; margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #1e3a5f 0%, #1665a0 100%); color: #fff; font-size: 1.5rem; }
  </style>
</head>
<body class="font-sans antialiased text-slate-800<?php echo !empty($examinationStudentBodyClass) ? ' ' . h($examinationStudentBodyClass) : ''; ?>">
  <div class="exam-lock-card text-center">
    <div class="exam-lock-icon"><i class="bi bi-shield-lock"></i></div>
    <h1 class="text-xl font-bold text-slate-900 m-0">This exam is open elsewhere</h1>
    <p class="mt-3 mb-0 text-sm text-slate-600 leading-relaxed">This attempt is tied to the browser where you started. You cannot continue in this window. Close other tabs or use the same device and browser, or log out and sign in again only after finishing the exam.</p>
    <p class="mt-4 mb-0"><a href="college_exams" class="inline-flex items-center gap-2 text-sm font-semibold text-[#1665A0] hover:underline">Back to exams</a></p>
  </div>
</body>
</html>
    <?php
    exit;
}

$showIntro = !$attempt || ($attemptStatusNorm === 'expired');
$remainingSeconds = null;
if ($attempt && $attemptStatusNorm === 'in_progress' && !empty($attempt['expires_at'])) {
    $remainingSeconds = max(0, strtotime($attempt['expires_at']) - time());
}

$initialAnsweredIds = [];
foreach ($questions as $q) {
    $qid = (int)$q['question_id'];
    if (!empty($answersMap[$qid]['selected_answer'])) {
        $initialAnsweredIds[] = $qid;
    }
}
$savedUiState = null;
if ($attempt && !empty($attempt['ui_state_json'])) {
    $tmp = json_decode((string)$attempt['ui_state_json'], true);
    if (is_array($tmp)) {
        $savedUiState = $tmp;
    }
}

$timeUsedSec = null;
if ($attempt && $attemptSubmitted && !empty($attempt['started_at']) && !empty($attempt['submitted_at'])) {
    $timeUsedSec = max(0, strtotime($attempt['submitted_at']) - strtotime($attempt['started_at']));
}

$examTimeLimitSec = max(0, (int)($exam['time_limit_seconds'] ?? 0));
$introWindowRemainSec = college_exam_seconds_exam_window_remaining(
    !empty($exam['available_from']) ? (string)$exam['available_from'] : null,
    !empty($exam['deadline']) ? (string)$exam['deadline'] : null,
    time()
);
$introEffectiveTimerSec = ($examTimeLimitSec > 0 && $introWindowRemainSec !== null)
    ? min($examTimeLimitSec, $introWindowRemainSec)
    : null;

$ereviewTakeUiBranch = 'unknown';
if ($reviewMode) {
    if ($attemptSubmitted) {
        $ereviewTakeUiBranch = 'review_results';
    } elseif ($attempt && !$attemptSubmitted) {
        $ereviewTakeUiBranch = 'review_not_ready';
    } elseif (!$attempt) {
        $ereviewTakeUiBranch = 'review_no_attempt';
    } else {
        $ereviewTakeUiBranch = 'review_fallback';
    }
} elseif ($showIntro) {
    $ereviewTakeUiBranch = 'intro';
} elseif ($attempt && $attemptStatusNorm === 'in_progress') {
    $ereviewTakeUiBranch = 'in_progress';
} else {
    $ereviewTakeUiBranch = 'other';
}

$ereviewCollegeTakeDiag = [
    'uiBranch' => $ereviewTakeUiBranch,
    'reviewMode' => $reviewMode,
    'reviewParamRaw' => $reviewParam,
    'attemptSubmitted' => $attemptSubmitted,
    'attemptStatusNorm' => $attemptStatusNorm,
    'hasAttempt' => $attempt !== null,
    'attemptId' => $attempt ? (int)($attempt['attempt_id'] ?? 0) : null,
    'showIntro' => $showIntro,
    'examId' => $examId,
];
if ($reviewMode && $attemptSubmitted) {
    $ereviewCollegeTakeDiag['reviewSheetOpen'] = college_exam_review_sheet_is_open($exam, $now);
    $ereviewCollegeTakeDiag['reviewAccessStatus'] = college_exam_review_access_status($exam, $now);
}

$reviewSubmittedSectionHtml = '';
if ($reviewMode && $attemptSubmitted) {
    $studentDisplayName = '';
    $nst = mysqli_prepare($conn, 'SELECT full_name FROM users WHERE user_id=? LIMIT 1');
    if ($nst) {
        mysqli_stmt_bind_param($nst, 'i', $uid);
        mysqli_stmt_execute($nst);
        $nrow = mysqli_fetch_assoc(mysqli_stmt_get_result($nst));
        mysqli_stmt_close($nst);
        if ($nrow && !empty($nrow['full_name'])) {
            $studentDisplayName = (string)$nrow['full_name'];
        }
    }
    ob_start();
    require dirname(__DIR__) . '/includes/college_take_exam_review_submitted_section.php';
    $reviewSubmittedSectionHtml = (string)ob_get_clean();
    $ereviewCollegeTakeDiag['reviewSectionBytes'] = strlen($reviewSubmittedSectionHtml);
}

$ereviewJsonDiagFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_app.php'; ?>
  <?php
    $__examTakeCss = dirname(__DIR__, 2) . '/assets/css/exam-take-shared.css';
    $__examTakeBase = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $__examTakeHrefBase = preg_replace('#/examination/examinee$#', '', $__examTakeBase);
    if (!is_string($__examTakeHrefBase) || $__examTakeHrefBase === '') {
        $__examTakeHrefBase = $__examTakeBase;
    }
    if (is_file($__examTakeCss)) {
        echo '<link rel="stylesheet" href="' . h($__examTakeHrefBase) . '/assets/css/exam-take-shared.css?v=' . filemtime($__examTakeCss) . '">' . "\n";
    }
  ?>
  <?php if ($reviewMode): ?>
  <script>
  window.__EREVIEW_COLLEGE_TAKE_DIAG = <?php echo json_encode($ereviewCollegeTakeDiag, $ereviewJsonDiagFlags); ?>;
  </script>
  <?php endif; ?>
  <style>
    .exam-shell { width: 100%; max-width: none; margin: 0; padding: 0 0 5rem; }
    .dash-anim { animation: dashFadeUp .55s ease-out both; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .exam-hero {
      border-radius: .78rem;
      border: 1px solid rgba(255,255,255,.28);
      background: linear-gradient(130deg,#1665A0 0%,#145a8f 38%,#143D59 100%);
      box-shadow: 0 14px 34px -20px rgba(20,61,89,.85), inset 0 1px 0 rgba(255,255,255,.22);
    }
    .exam-hero .back-link { color: rgba(255,255,255,.92); }
    .exam-hero .back-link:hover { color: #fff; text-decoration: underline; }
    .exam-hero .exam-title { color: #fff; letter-spacing: .01em; }
    .exam-subtitle { color: rgba(255,255,255,.9); font-size: .9rem; line-height: 1.45; }
    .intro-card {
      border: 1px solid rgba(22,101,160,.2);
      border-radius: .86rem;
      background: linear-gradient(180deg,#f8fbff 0%,#fff 55%);
      box-shadow: 0 12px 26px -22px rgba(20,61,89,.5), 0 1px 0 rgba(255,255,255,.85) inset;
    }
    .intro-meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem .8rem;margin-bottom:.9rem}
    .intro-meta{border:1px solid #dbe7f5;border-radius:.65rem;background:#fff;padding:.52rem .62rem}
    .intro-meta-k{font-size:.68rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#64748b}
    .intro-meta-v{font-size:.84rem;color:#143D59;font-weight:800;margin-top:.1rem}
    .intro-timer-cap-note{margin-top:.45rem;font-size:.72rem;font-weight:700;color:#b45309;line-height:1.35}
    .time-up-modal-panel{border-radius:1rem;border:1px solid #fecaca;background:linear-gradient(180deg,#fffbeb 0%,#fff 55%);box-shadow:0 24px 48px -24px rgba(127,29,29,.45)}
    .time-up-pulse{width:3rem;height:3rem;border-radius:999px;background:#fef2f2;border:2px solid #fecaca;display:flex;align-items:center;justify-content:center;animation:timeUpPulse 1.4s ease-in-out infinite}
    @keyframes timeUpPulse{0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(220,38,38,.25)}50%{transform:scale(1.04);box-shadow:0 0 0 12px rgba(220,38,38,0)}}
    .start-btn {
      display: inline-flex; align-items: center; gap: .5rem;
      padding: .62rem 1rem; border-radius: .68rem;
      border: 1px solid #1665A0; color: #fff; font-weight: 800;
      background: linear-gradient(135deg,#1665A0 0%,#0d4f80 100%);
      box-shadow: 0 12px 20px -18px rgba(13,79,128,.9);
    }
    .start-btn:hover { transform: translateY(-1px); background: linear-gradient(135deg,#145a8f 0%,#0b436c 100%); }
    .focus-ring:focus-visible { outline: 3px solid #1d4ed8; outline-offset: 2px; }
    .exam-take-wrap { max-width: 1200px; margin: 0 auto; padding: 0 1rem 2rem; }
    body.exam-taking-mode .exam-hero { display: none; }
    body.exam-review-mode .exam-hero { display: none; }
    .submit-confirm-overlay { background: rgba(15,23,42,.55); backdrop-filter: blur(4px); }
    .submit-confirm-shell { width: 100%; max-width: 32rem; border-radius: 1rem; background: #fff; border: 1px solid #dbe7f5; box-shadow: 0 24px 48px -24px rgba(15,23,42,.45); overflow: hidden; }
    .submit-confirm-head { padding: 1.25rem 1.35rem .85rem; background: linear-gradient(180deg,#f8fbff,#fff); border-bottom: 1px solid #e8eef6; text-align: center; }
    .submit-confirm-icon { width: 3rem; height: 3rem; margin: 0 auto .75rem; border-radius: 999px; display: flex; align-items: center; justify-content: center; background: #e8f2fa; color: #1665A0; font-size: 1.35rem; }
    .submit-confirm-title { margin: 0; font-size: 1.15rem; font-weight: 800; color: #143D59; }
    .submit-confirm-sub { margin: .45rem 0 0; font-size: .82rem; color: #64748b; line-height: 1.45; }
    .submit-confirm-body { padding: 1rem 1.25rem; }
    .submit-stat-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: .55rem; }
    .submit-stat-cell { border: 1px solid #e2e8f0; border-radius: .65rem; padding: .55rem .65rem; background: #f8fafc; }
    .submit-stat-k { font-size: .68rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
    .submit-stat-v { font-size: 1rem; font-weight: 800; color: #143D59; margin-top: .15rem; }
    .submit-unanswered-banner { margin-top: .85rem; padding: .65rem .75rem; border-radius: .65rem; font-size: .82rem; }
    .submit-unanswered-ok { background: #ecfdf5; border: 1px solid #86efac; color: #047857; }
    .submit-unanswered-warn { background: #fffbeb; border: 1px solid #fcd34d; color: #b45309; }
    .submit-unanswered-nums { display: block; margin-top: .35rem; font-size: .75rem; }
    .submit-double-hint { margin: 0 1.25rem .75rem; font-size: .78rem; color: #b45309; font-weight: 700; text-align: center; }
    .submit-confirm-foot { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: flex-end; padding: .85rem 1.15rem 1.15rem; border-top: 1px solid #e8eef6; }
    .submit-btn-review, .submit-btn-cancel, .submit-btn-go { padding: .55rem .9rem; border-radius: .55rem; font-size: .82rem; font-weight: 700; }
    .submit-btn-review { border: 1px solid #cbd5e1; background: #fff; color: #334155; }
    .submit-btn-cancel { border: 1px solid #cbd5e1; background: #f8fafc; color: #475569; }
    .submit-btn-go { border: 1px solid #1665A0; background: #1665A0; color: #fff; }
    .help-kbd { display: inline-block; min-width: 1.5rem; padding: .1rem .35rem; border-radius: .3rem; border: 1px solid #cbd5e1; background: #f8fafc; font-family: ui-monospace, monospace; font-size: .75rem; text-align: center; }
    .review-locked-gate { max-width: 28rem; margin: 2rem auto; text-align: center; padding: 1.5rem; border: 1px solid #dbe7f5; border-radius: 1rem; background: #fff; }
    .review-locked-icon { font-size: 2rem; color: #1665A0; margin-bottom: .75rem; }
    .review-locked-title { font-size: 1.15rem; font-weight: 800; color: #143D59; margin: 0; }
    .review-locked-text { font-size: .9rem; color: #64748b; margin-top: .5rem; }
  </style>
</head>
<body class="font-sans antialiased<?php echo !empty($examinationStudentBodyClass) ? ' ' . h($examinationStudentBodyClass) : ''; ?>">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="exam-shell<?php echo $reviewMode ? ' cer-page-shell' : ' cp-content'; ?> ereview-shell-no-fade<?php echo $reviewMode ? '' : ' pt-2'; ?>">
    <?php if (!$showIntro && !$reviewMode && !($attempt && $attemptStatusNorm === 'in_progress')): ?>
    <section class="exam-hero dash-anim delay-1 px-5 py-5 mb-5">
      <a href="college_exams" class="focus-ring back-link inline-flex items-center gap-1 text-sm font-semibold mb-3"><i class="bi bi-arrow-left"></i> Back to exams</a>
      <h1 class="exam-title text-[1.9rem] font-extrabold m-0"><?php echo h($exam['title']); ?></h1>
      <?php if (!empty($exam['description'])): ?>
        <div class="exam-subtitle mt-2 prose prose-sm max-w-none"><?php echo ereview_render_exam_description($exam['description'], !empty($exam['description_markdown'])); ?></div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php
    if ($reviewMode && isset($_GET['debug_review'])) {
        $dbg = [
            'reviewMode' => $reviewMode,
            'exam_id' => $examId,
            'attempt_id' => $attempt['attempt_id'] ?? null,
            'status_raw' => $attempt['status'] ?? null,
            'status_normalized' => $attemptStatusNorm,
            'attemptSubmitted' => $attemptSubmitted,
            'submitted_at' => $attempt['submitted_at'] ?? null,
            'helpers' => [
                'college_exam_attempt_is_effectively_submitted' => function_exists('college_exam_attempt_is_effectively_submitted'),
                'college_exam_review_sheet_is_open' => function_exists('college_exam_review_sheet_is_open'),
                'college_exam_format_student_result_datetime' => function_exists('college_exam_format_student_result_datetime'),
            ],
        ];
        $jf = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        echo '<script>console.log("[ereview review debug]", ' . json_encode($dbg, $jf) . ');</script>';
    }
    ?>

    <?php if ($reviewMode && $attemptSubmitted): ?>
      <script>document.body.classList.add('exam-review-mode');</script>
      <!-- ereview-review-section -->
      <?php echo $reviewSubmittedSectionHtml; ?>
      <!-- /ereview-review-section -->
    <?php elseif ($reviewMode && $attempt && !$attemptSubmitted): ?>
      <div class="review-locked-gate">
        <div class="review-locked-icon"><i class="bi bi-hourglass-split"></i></div>
        <h2 class="review-locked-title">Results not ready to view</h2>
        <p class="review-locked-text">Your attempt is not marked as submitted yet. Return to your <a class="text-[#1665A0] font-bold underline" href="college_exams">exam list</a> and open the exam from there, or finish submitting if you still have time.</p>
      </div>
    <?php elseif ($reviewMode && !$attempt): ?>
      <div class="review-locked-gate">
        <div class="review-locked-icon"><i class="bi bi-journal-x"></i></div>
        <h2 class="review-locked-title">No attempt on file</h2>
        <p class="review-locked-text">We could not find an exam attempt for your account. Open this exam from your <a class="text-[#1665A0] font-bold underline" href="college_exams">exam list</a> to start or continue.</p>
      </div>
    <?php elseif ($showIntro): ?>
      <?php
        $introOpensTs = !empty($exam['available_from']) ? strtotime((string)$exam['available_from']) : false;
        $introOpens = $introOpensTs !== false
            ? date('M j, Y', $introOpensTs) . ' · ' . date('g:i A', $introOpensTs)
            : 'Immediate';
        $introClosesTs = !empty($exam['deadline']) ? strtotime((string)$exam['deadline']) : false;
        $introCloses = $introClosesTs !== false
            ? date('M j, Y', $introClosesTs) . ' · ' . date('g:i A', $introClosesTs)
            : 'No closing time';
        $introDuration = $examTimeLimitSec <= 0
            ? 'No fixed timer'
            : college_exam_human_duration($introEffectiveTimerSec !== null ? $introEffectiveTimerSec : $examTimeLimitSec);
      ?>
      <div class="cp-dash-panel cp-anim delay-2">
        <div class="cp-dash-panel__body">
      <section class="cp-exam-prep" aria-label="Exam instructions">
        <a href="college_exams" class="cp-exam-prep__back focus-ring"><i class="bi bi-arrow-left"></i> Back to examinations</a>
        <header class="cp-exam-prep__head">
          <span class="type-pill type-regular">Regular examination</span>
          <h1 class="cp-exam-prep__title"><?php echo h($exam['title']); ?></h1>
          <?php if (empty($exam['description'])): ?>
            <p class="cp-exam-prep__lead">Review the schedule and details below before you begin.</p>
          <?php endif; ?>
        </header>
        <div class="cp-exam-prep__meta">
          <div class="cp-exam-prep__meta-item">
            <span class="cp-meta-k">Opens</span>
            <span class="cp-meta-v"><?php echo h($introOpens); ?></span>
          </div>
          <div class="cp-exam-prep__meta-item">
            <span class="cp-meta-k">Closes</span>
            <span class="cp-meta-v"><?php echo h($introCloses); ?></span>
          </div>
          <div class="cp-exam-prep__meta-item">
            <span class="cp-meta-k">Duration</span>
            <span class="cp-meta-v">
              <?php echo h($introDuration); ?>
              <?php if ($examTimeLimitSec > 0 && $introEffectiveTimerSec !== null && $introEffectiveTimerSec < $examTimeLimitSec): ?>
                <span class="cp-exam-prep__note">Working time may be capped by the closing time when you start.</span>
              <?php endif; ?>
            </span>
          </div>
          <div class="cp-exam-prep__meta-item">
            <span class="cp-meta-k">Questions</span>
            <span class="cp-meta-v"><?php echo (int)count($questions); ?></span>
          </div>
          <div class="cp-exam-prep__meta-item">
            <span class="cp-meta-k">Professor</span>
            <span class="cp-meta-v"><?php echo h($profName); ?></span>
          </div>
          <div class="cp-exam-prep__meta-item">
            <span class="cp-meta-k">Type</span>
            <span class="cp-meta-v">Regular exam</span>
          </div>
        </div>
        <?php if (!empty($exam['description'])): ?>
        <div class="cp-exam-prep__instructions">
          <h2 class="cp-exam-prep__instructions-title"><i class="bi bi-info-circle"></i> Instructions</h2>
          <div class="cp-exam-prep__instructions-body prose prose-sm max-w-none"><?php echo ereview_render_exam_description($exam['description'], !empty($exam['description_markdown'])); ?></div>
        </div>
        <?php endif; ?>
        <div class="cp-exam-prep__start">
          <div class="cp-exam-prep__start-copy">
            <h2 class="cp-exam-prep__start-title">Ready to begin?</h2>
            <p class="cp-exam-prep__start-text">Once you start, the timer and examination rules apply. Make sure you have a stable connection.</p>
          </div>
          <form method="post" action="" class="cp-exam-prep__start-form">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <button type="submit" name="start_exam" value="1" class="focus-ring start-btn cp-btn cp-btn--primary cp-btn--lg">
              <i class="bi bi-play-fill"></i> Start examination
            </button>
          </form>
        </div>
      </section>
        </div>
      </div>
    <?php elseif ($attempt && $attemptStatusNorm === 'in_progress'): ?>
      <?php
        $qTotal = (int)count($questions);
        $timerInitial = $remainingSeconds !== null ? (int)$remainingSeconds : null;
        $timerCircumference = 2 * M_PI * 54;
        $wmStudentName = 'Student';
        $wmSt = mysqli_prepare($conn, 'SELECT full_name FROM users WHERE user_id=? LIMIT 1');
        if ($wmSt) {
            mysqli_stmt_bind_param($wmSt, 'i', $uid);
            mysqli_stmt_execute($wmSt);
            $wmRow = mysqli_fetch_assoc(mysqli_stmt_get_result($wmSt));
            mysqli_stmt_close($wmSt);
            if ($wmRow && trim((string)($wmRow['full_name'] ?? '')) !== '') {
                $wmStudentName = trim((string)$wmRow['full_name']);
            }
        }
        $wmLine = $wmStudentName . ' · ' . (string)($exam['title'] ?? 'Exam') . ' · Attempt #' . (int)$attempt['attempt_id'];
      ?>
      <script>document.body.classList.add('exam-taking-mode','exam-protected');</script>
      <div class="exam-watermark" aria-hidden="true"><div class="exam-watermark__inner"><?php
        for ($wi = 0; $wi < 18; $wi++) {
            echo '<div class="exam-watermark__cell">' . h($wmLine) . '</div>';
        }
      ?></div></div>
      <div class="exam-page-container exam-take-wrap dash-anim delay-2">
        <div id="examConnBanner" class="exam-conn-banner" role="status"><i class="bi bi-wifi-off mr-1"></i> <span id="examConnBannerText">Connection interrupted. Your answers are being preserved. Reconnecting...</span></div>

        <?php
          $examChromeTitle = (string)$exam['title'];
          $examChromeSubtitle = 'Regular exam · ' . $profName;
          $examChromeSubmitLabel = 'Submit exam';
          require dirname(__DIR__) . '/includes/college_exam_take_workspace_header.php';
        ?>

        <div class="exam-layout exam-workspace-layout">
          <div class="exam-main exam-workspace-main">
            <form id="examForm" data-attempt-id="<?php echo (int)$attempt['attempt_id']; ?>" data-csrf="<?php echo h($csrf); ?>" data-exam-id="<?php echo (int)$examId; ?>" data-total="<?php echo $qTotal; ?>" data-remaining="<?php echo $timerInitial !== null ? (int)$timerInitial : ''; ?>">
              <?php foreach ($questions as $index => $q): ?>
                <?php
                  $qid = (int)$q['question_id'];
                  $displayChoices = college_exam_question_display_choices($q);
                  $isTfQ = college_exam_question_type_is_tf((string)($q['question_type'] ?? ''));
                  $prev = strtoupper((string)($answersMap[$qid]['selected_answer'] ?? ''));
                ?>
                <section class="exam-question-card exam-question-panel<?php echo $prev !== '' ? ' is-answered' : ''; ?>" data-question-panel data-index="<?php echo $index; ?>" data-question-id="<?php echo $qid; ?>" data-question-type="<?php echo $isTfQ ? 'tf' : 'mcq'; ?>" id="q<?php echo ($index + 1); ?>">
                  <div class="exam-question-head">
                    <div class="exam-question-label">Question <?php echo ($index + 1); ?> <span class="exam-answered-pill" aria-hidden="true">✓</span></div>
                    <button type="button" class="exam-flag-btn flagBtn focus-ring" data-question-id="<?php echo $qid; ?>" aria-label="Mark question <?php echo ($index + 1); ?> for review"><i class="bi bi-flag"></i> Mark for review</button>
                  </div>
                  <div class="exam-question-text exam-no-copy quiz-rich-text"><span class="exam-question-num"><?php echo ($index + 1); ?>.</span> <?php echo renderQuizRichText($q['question_text']); ?></div>
                  <div class="exam-choices">
                    <?php foreach ($displayChoices as $choice): ?>
                      <?php
                        $L = $choice['letter'];
                        $label = $choice['label'];
                        $showLetter = !empty($choice['show_letter']);
                        $aria = $showLetter ? ('Choice ' . $L) : $label;
                      ?>
                      <label class="exam-choice focus-ring exam-no-copy <?php echo $prev === $L ? 'selected' : ''; ?>" data-choice-row tabindex="0">
                        <span class="exam-choice-radio" aria-hidden="true"></span>
                        <input type="radio" class="sr-only" name="q_<?php echo $qid; ?>" value="<?php echo h($L); ?>" data-question-id="<?php echo $qid; ?>" <?php echo $prev === $L ? 'checked' : ''; ?> aria-label="<?php echo h($aria); ?>">
                        <?php if ($showLetter): ?>
                          <span class="exam-choice-letter"><?php echo h($L); ?></span>
                        <?php endif; ?>
                        <div class="exam-choice-text"><?php echo $showLetter ? nl2br(h($label)) : h($label); ?></div>
                        <span class="exam-choice-check" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </section>
              <?php endforeach; ?>
            </form>

            <?php require dirname(__DIR__) . '/includes/college_exam_take_workspace_footer.php'; ?>
          </div>

          <aside class="exam-sidebar exam-workspace-sidebar">
            <div class="exam-sidebar-card exam-navigator-card">
              <div class="exam-sidebar-title">Question navigator</div>
              <div class="exam-filter-row">
                <button type="button" class="exam-filter-btn focus-ring is-active" data-filter="all">All</button>
                <button type="button" class="exam-filter-btn focus-ring" data-filter="unanswered">Unanswered</button>
                <button type="button" class="exam-filter-btn focus-ring" data-filter="answered">Answered</button>
                <button type="button" class="exam-filter-btn focus-ring" data-filter="flagged">Flagged</button>
              </div>
              <div class="exam-sidebar-section" id="examQListSection">
                <div id="questionNavigator" class="exam-q-list exam-q-grid" aria-label="Question navigator"></div>
                <div class="cp-qnav-legend" aria-hidden="true">
                  <span class="cp-qnav-legend__item"><span class="cp-qnav-legend__dot cp-qnav-legend__dot--answered"></span> Answered</span>
                  <span class="cp-qnav-legend__item"><span class="cp-qnav-legend__dot cp-qnav-legend__dot--current"></span> Current</span>
                  <span class="cp-qnav-legend__item"><span class="cp-qnav-legend__dot cp-qnav-legend__dot--unanswered"></span> Unanswered</span>
                  <span class="cp-qnav-legend__item"><span class="cp-qnav-legend__dot cp-qnav-legend__dot--flagged"></span> Flagged</span>
                </div>
              </div>
            </div>
          </aside>
        </div>

        <div id="examQnavDrawer" class="exam-qnav-drawer" hidden aria-hidden="true">
          <button type="button" class="exam-qnav-drawer__backdrop" id="examQnavDrawerBackdrop" aria-label="Close question navigator"></button>
          <div class="exam-qnav-drawer__sheet" role="dialog" aria-modal="true" aria-labelledby="examQnavDrawerTitle">
            <header class="exam-qnav-drawer__head">
              <h2 id="examQnavDrawerTitle" class="exam-qnav-drawer__title">Question navigator</h2>
              <button type="button" id="closeMobileDrawerBtn" class="exam-qnav-drawer__close focus-ring" aria-label="Close question navigator"><i class="bi bi-x-lg"></i></button>
            </header>
            <div class="exam-filter-row exam-filter-row--drawer">
              <button type="button" class="exam-filter-btn focus-ring is-active" data-filter="all">All</button>
              <button type="button" class="exam-filter-btn focus-ring" data-filter="unanswered">Unanswered</button>
              <button type="button" class="exam-filter-btn focus-ring" data-filter="answered">Answered</button>
              <button type="button" class="exam-filter-btn focus-ring" data-filter="flagged">Flagged</button>
            </div>
            <div id="mobileQuestionNavigator" class="exam-q-list exam-q-grid exam-q-grid--drawer" aria-label="Question navigator"></div>
            <div class="cp-qnav-legend cp-qnav-legend--drawer" aria-hidden="true">
              <span class="cp-qnav-legend__item"><span class="cp-qnav-legend__dot cp-qnav-legend__dot--answered"></span> Answered</span>
              <span class="cp-qnav-legend__item"><span class="cp-qnav-legend__dot cp-qnav-legend__dot--current"></span> Current</span>
              <span class="cp-qnav-legend__item"><span class="cp-qnav-legend__dot cp-qnav-legend__dot--unanswered"></span> Unanswered</span>
              <span class="cp-qnav-legend__item"><span class="cp-qnav-legend__dot cp-qnav-legend__dot--flagged"></span> Flagged</span>
            </div>
          </div>
        </div>
      </div>

      <div id="examSavedToast" class="exam-saved-toast" role="status">Answer saved</div>
      <div id="examTimeWarningToast" class="exam-time-warning-toast" role="status"></div>

      <div class="exam-privacy-shield" id="examPrivacyShield" aria-hidden="true">
        <div class="exam-privacy-shield__card">
          <i class="bi bi-eye-slash" aria-hidden="true"></i>
          <h2>Exam content hidden</h2>
          <p>Content is blocked while this window is inactive or a screen-capture shortcut is detected. Return here to continue. Screenshots are not allowed during the exam.</p>
        </div>
      </div>

      <div id="examSecurityOverlay" class="exam-security-overlay" role="alertdialog" aria-modal="true" aria-labelledby="examSecurityTitle">
        <div class="exam-security-card">
          <h3 id="examSecurityTitle">Security notice</h3>
          <p id="examSecurityMessage">Screenshots and leaving the exam window are not allowed.</p>
          <button type="button" id="examSecurityContinueBtn" class="exam-btn-submit focus-ring">
            <i class="bi bi-shield-check"></i> Return to exam
          </button>
        </div>
      </div>

      <div id="examReturnOverlay" class="exam-return-overlay" role="alertdialog" aria-modal="true" aria-labelledby="examReturnTitle">
        <div class="exam-return-card">
          <h3 id="examReturnTitle">Exam tab was inactive</h3>
          <p>This activity has been recorded for your professor. Continue only when you are ready to resume the exam.</p>
          <button type="button" id="examReturnContinueBtn" class="exam-btn-submit focus-ring">I understand — continue</button>
        </div>
      </div>
      <div id="quizSubmitOverlay" class="quiz-submit-overlay" aria-hidden="true">
        <div class="quiz-submit-card">
          <div class="quiz-submit-spinner"></div>
          <div class="quiz-submit-title">Submitting exam</div>
          <div class="quiz-submit-text">Please wait while we finalize your answers...</div>
        </div>
      </div>

      <div id="timeUpModal" class="fixed inset-0 z-[1350] hidden items-center justify-center bg-slate-900/55 p-4 backdrop-blur-[2px]" aria-live="assertive" role="alertdialog" aria-modal="true" aria-labelledby="timeUpModalTitle">
        <div class="time-up-modal-panel w-full max-w-md p-6 text-center">
          <div class="time-up-pulse mx-auto mb-4"><i class="bi bi-hourglass-bottom text-2xl text-red-600"></i></div>
          <h3 id="timeUpModalTitle" class="m-0 text-xl font-extrabold text-red-900">Time is up</h3>
          <p class="mt-2 mb-0 text-sm text-slate-700 font-semibold">Saving your answers and submitting automatically. Please wait…</p>
          <p class="mt-3 mb-0 text-xs text-slate-500">Do not close this page until you are redirected to the results.</p>
        </div>
      </div>

      <div id="submitConfirmModal" class="submit-confirm-overlay fixed inset-0 z-[1300] hidden items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="submitConfirmTitle">
        <div class="submit-confirm-shell">
          <div class="submit-confirm-head">
            <div class="submit-confirm-icon" aria-hidden="true"><i class="bi bi-clipboard2-check"></i></div>
            <h3 id="submitConfirmTitle" class="submit-confirm-title">Submit examination?</h3>
            <p class="submit-confirm-sub">Are you sure you want to submit your examination? Unanswered items are highlighted below.</p>
          </div>
          <div class="submit-confirm-body">
            <div class="submit-stat-grid">
              <div class="submit-stat-cell">
                <div class="submit-stat-k">Total questions</div>
                <div class="submit-stat-v"><?php echo $qTotal; ?></div>
              </div>
              <div class="submit-stat-cell">
                <div class="submit-stat-k">Answered</div>
                <div class="submit-stat-v" id="sumAnswered">0</div>
              </div>
              <div class="submit-stat-cell">
                <div class="submit-stat-k">Flagged</div>
                <div class="submit-stat-v" id="sumFlagged">0</div>
              </div>
              <div class="submit-stat-cell">
                <div class="submit-stat-k">Time left</div>
                <div class="submit-stat-v"><span id="sumTimeRemaining">--:--</span></div>
              </div>
            </div>
            <div id="submitUnansweredBanner" class="submit-unanswered-banner submit-unanswered-ok" role="status">
              <span id="submitUnansweredLabel"><strong>Unanswered: <span id="sumUnanswered">0</span></strong></span>
              <span id="submitUnansweredNums" class="submit-unanswered-nums hidden"></span>
            </div>
          </div>
          <p id="doubleConfirmHint" class="submit-double-hint hidden">Tap "Submit exam" again to confirm — this cannot be undone.</p>
          <div class="submit-confirm-foot">
            <button type="button" id="reviewUnansweredBtn" class="focus-ring submit-btn-review"><i class="bi bi-search"></i> Review unanswered</button>
            <button type="button" id="closeSubmitModalBtn" class="focus-ring submit-btn-cancel">Cancel</button>
            <button type="button" id="confirmSubmitBtn" class="focus-ring submit-btn-go"><i class="bi bi-send-fill"></i> Submit exam</button>
          </div>
        </div>
      </div>

      <div id="shortcutsModal" class="fixed inset-0 z-[1300] hidden items-center justify-center bg-slate-900/45 p-4">
        <div class="w-full max-w-md rounded-xl bg-white border border-slate-200 shadow-xl p-5">
          <h3 class="m-0 text-lg font-bold text-[#143D59]">Keyboard shortcuts</h3>
          <ul class="mt-3 text-sm text-slate-700 space-y-2">
            <li><span class="help-kbd">N</span> Jump to next question</li>
            <li><span class="help-kbd">P</span> Jump to previous question</li>
            <li><span class="help-kbd">F</span> Flag/unflag current</li>
            <li><span class="help-kbd">1-4</span> Select choice A-D</li>
            <li><span class="help-kbd">?</span> Open this help</li>
          </ul>
          <div class="mt-4 text-right"><button type="button" id="closeShortcutsBtn" class="focus-ring px-3 py-2 rounded-lg border border-slate-200 text-slate-700 text-sm font-semibold">Close</button></div>
        </div>
      </div>

      <div id="leaveConfirmModal" class="fixed inset-0 z-[1300] hidden items-center justify-center bg-slate-900/45 p-4">
        <div class="w-full max-w-md rounded-xl bg-white border border-slate-200 shadow-xl p-5">
          <h3 class="m-0 text-lg font-bold text-[#143D59]">Leave exam?</h3>
          <p class="mt-2 text-sm text-slate-600">Your attempt is in progress. You can stay here, or leave and continue later from your exam list.</p>
          <div class="mt-4 flex flex-wrap gap-2 justify-end">
            <button type="button" id="stayOnExamBtn" class="focus-ring px-3 py-2 rounded-lg border border-slate-200 text-slate-700 text-sm font-semibold">Stay here</button>
            <button type="button" id="leaveExamBtn" class="focus-ring px-4 py-2 rounded-lg bg-rose-600 text-white text-sm font-semibold">Leave page</button>
          </div>
        </div>
      </div>

      <script>
      (function () {
        var form = document.getElementById('examForm');
        if (!form) return;
        var ajaxUrl = 'college_exam_ajax';
        var attemptId = parseInt(form.getAttribute('data-attempt-id'), 10);
        var csrf = form.getAttribute('data-csrf');
        var examId = parseInt(form.getAttribute('data-exam-id'), 10);
        var totalQuestions = parseInt(form.getAttribute('data-total'), 10) || 0;
        var remAttr = form.getAttribute('data-remaining');
        var countdown = remAttr === '' || remAttr === null ? null : Math.max(0, parseInt(remAttr, 10) || 0);
        var initialRemaining = countdown;
        var circumference = 2 * Math.PI * 54;
        var panels = Array.prototype.slice.call(document.querySelectorAll('[data-question-panel]'));
        var state = {
          currentIndex: 0,
          flags: new Set(<?php echo json_encode(array_values(array_filter(array_map('intval', (array)($savedUiState['flags'] ?? []))))); ?>),
          answered: new Set(<?php echo json_encode(array_values(array_unique(array_map('intval', $initialAnsweredIds)))); ?>),
          filter: 'all',
          submitting: false,
          online: navigator.onLine,
          submitConfirmStep: 0
        };
        var timerWrap = document.getElementById('examTimerCircle');
        var timerValue = document.getElementById('examTimerCircleValue');
        var timerProgress = document.getElementById('examTimerCircleProgress');
        var progressBar = document.getElementById('progressBar');
        var answeredCountEl = document.getElementById('answeredCountNum');
        var flaggedCountEl = document.getElementById('flaggedCount');
        var currentLabel = document.getElementById('examCurrentLabel');
        var connBanner = document.getElementById('examConnBanner');
        var connBannerText = document.getElementById('examConnBannerText');
        var savedToast = document.getElementById('examSavedToast');
        var warnToast = document.getElementById('examTimeWarningToast');
        var navigatorEl = document.getElementById('questionNavigator');
        var mobileNavEl = document.getElementById('mobileQuestionNavigator');
        var submitExamBtn = document.getElementById('submitExamBtn');
        var submitExamBtnText = document.getElementById('submitExamBtnText');
        var submitAnsweredNum = document.getElementById('submitAnsweredNum');
        var submitIncompleteHint = document.getElementById('submitIncompleteHint');
        var examQuestionsBtn = document.getElementById('examQuestionsBtn');
        var examQnavDrawer = document.getElementById('examQnavDrawer');
        var examNavCurrentLabel = document.getElementById('examNavCurrentLabel');
        var examTimerCompact = document.getElementById('examTimerCompact');
        var examTimerCompactWrap = document.getElementById('examTimerCompactWrap');
        var tabBlurLastSent = 0;
        var examPageReadyAt = Date.now();
        var warned5 = false, warned1 = false, warned30 = false;
        var saveRetryTimers = {};
        var inflightSaves = {};
        var answersLocked = false;
        var scrollSyncFromClick = false;
        var scrollSyncTimer = null;

        function showSavedToast(msg) {
          if (!savedToast) return;
          savedToast.textContent = msg || 'Answer saved';
          savedToast.classList.add('show');
          clearTimeout(showSavedToast._t);
          showSavedToast._t = setTimeout(function () { savedToast.classList.remove('show'); }, 1800);
        }
        function showWarnToast(msg, kind) {
          if (!warnToast) return;
          warnToast.textContent = msg;
          warnToast.className = 'exam-time-warning-toast show ' + (kind || 'warning');
          clearTimeout(showWarnToast._t);
          showWarnToast._t = setTimeout(function () { warnToast.classList.remove('show'); }, 3500);
        }
        function setConn(online, recovering) {
          state.online = online;
          if (!connBanner) return;
          if (!online) {
            connBanner.classList.add('is-on');
            connBanner.classList.remove('is-ok');
            if (connBannerText) connBannerText.textContent = 'Connection interrupted. Your answers are being preserved. Reconnecting...';
          } else if (recovering) {
            connBanner.classList.add('is-on', 'is-ok');
            if (connBannerText) connBannerText.textContent = 'Connected — answers saved';
            clearTimeout(setConn._t);
            setConn._t = setTimeout(function () { connBanner.classList.remove('is-on', 'is-ok'); }, 2200);
          } else {
            connBanner.classList.remove('is-on', 'is-ok');
          }
        }
        window.addEventListener('online', function () { setConn(true, true); });
        window.addEventListener('offline', function () { setConn(false); });
        setConn(navigator.onLine);

        function request(action, payload) {
          var body = new URLSearchParams();
          body.set('action', action);
          Object.keys(payload || {}).forEach(function (k) { body.set(k, String(payload[k])); });
          return fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
            .then(function (r) {
              return r.text().then(function (text) {
                var data = null;
                try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
                if (!r.ok) {
                  throw new Error((data && data.error) || ('Request failed (' + r.status + ')'));
                }
                if (data === null) {
                  throw new Error('Invalid server response');
                }
                return data;
              });
            });
        }

        function getLocalAnswersMap() {
          var map = {};
          // Merge durable local backup first, then overlay current radio selections.
          try {
            var raw = localStorage.getItem(answerBackupKey());
            if (raw) {
              var parsed = JSON.parse(raw);
              if (parsed && typeof parsed === 'object') {
                Object.keys(parsed).forEach(function (qid) {
                  var v = String(parsed[qid] || '').toUpperCase();
                  if (/^[A-D]$/.test(v)) map[parseInt(qid, 10)] = v;
                });
              }
            }
          } catch (e) {}
          panels.forEach(function (panel) {
            var qid = parseInt(panel.getAttribute('data-question-id'), 10);
            if (!qid) return;
            var checked = panel.querySelector('input[type=radio]:checked');
            if (checked && /^[A-D]$/i.test(String(checked.value || ''))) {
              map[qid] = String(checked.value).toUpperCase();
            }
          });
          return map;
        }
        function answerBackupKey() {
          return 'ereview_exam_answers_' + attemptId;
        }
        function persistAnswerBackup(qid, value) {
          try {
            var map = {};
            var raw = localStorage.getItem(answerBackupKey());
            if (raw) {
              var parsed = JSON.parse(raw);
              if (parsed && typeof parsed === 'object') map = parsed;
            }
            map[String(qid)] = String(value).toUpperCase();
            localStorage.setItem(answerBackupKey(), JSON.stringify(map));
          } catch (e) {}
        }
        function clearAnswerBackup() {
          try { localStorage.removeItem(answerBackupKey()); } catch (e) {}
        }
        function localAnswersPayload() {
          var map = getLocalAnswersMap();
          return Object.keys(map).map(function (qid) {
            return { question_id: parseInt(qid, 10), selected_answer: map[qid] };
          });
        }
        function isQuestionAnsweredLocal(qid) {
          if (state.answered.has(qid)) return true;
          var map = getLocalAnswersMap();
          return !!map[qid];
        }

        function sendVisibility(visibility) {
          var now = Date.now();
          if (visibility === 'hidden') {
            if (now - examPageReadyAt < 1500) return;
            if (now - tabBlurLastSent < 2200) return;
            tabBlurLastSent = now;
          }
          request('tab_visibility', {
            csrf_token: csrf,
            attempt_id: attemptId,
            visibility: visibility,
            client_ts: now
          }).catch(function () {});
        }
        var wasHidden = false;
        var privacyShield = document.getElementById('examPrivacyShield');
        var securityOverlay = document.getElementById('examSecurityOverlay');
        var securityTitle = document.getElementById('examSecurityTitle');
        var securityMessage = document.getElementById('examSecurityMessage');
        var securityContinue = document.getElementById('examSecurityContinueBtn');
        var returnOverlay = document.getElementById('examReturnOverlay');
        var returnBtn = document.getElementById('examReturnContinueBtn');
        var securityMessages = {
          screenshot: {
            title: 'Screenshots are not allowed',
            message: 'Screen capture tools (including Snipping Tool / Win + Shift + S) are not allowed. Exam content has been hidden. Return to the exam window to continue.'
          },
          visibility: {
            title: 'Stay on the exam page',
            message: 'You left the exam window. Switching tabs or apps is recorded. Exam content stays hidden until you return.'
          },
          blur: {
            title: 'Stay focused on your exam',
            message: 'The exam window lost focus. Content is hidden while another window is active. Click Return to exam to continue.'
          },
          win_key: {
            title: 'Windows key / app switch blocked',
            message: 'Using the Windows key or leaving the exam can expose screen-capture tools. Exam content is hidden. Return here to continue.'
          }
        };
        function setPrivacyShield(on) {
          if (!privacyShield) return;
          privacyShield.classList.toggle('is-active', !!on);
          privacyShield.setAttribute('aria-hidden', on ? 'false' : 'true');
          document.body.classList.toggle('exam-content-obscured', !!on);
        }
        function showSecurityOverlay(reason) {
          var meta = securityMessages[reason] || securityMessages.visibility;
          if (securityTitle) securityTitle.textContent = meta.title;
          if (securityMessage) securityMessage.textContent = meta.message;
          if (securityOverlay) securityOverlay.classList.add('is-on');
          setPrivacyShield(true);
        }
        function hideSecurityOverlay() {
          if (securityOverlay) securityOverlay.classList.remove('is-on');
          if (returnOverlay) returnOverlay.classList.remove('is-on');
          if (document.hasFocus() && !document.hidden) {
            setPrivacyShield(false);
          }
        }
        function showReturnWarning() {
          showSecurityOverlay('visibility');
        }
        function hideReturnWarning() {
          hideSecurityOverlay();
        }
        if (securityContinue) securityContinue.addEventListener('click', hideSecurityOverlay);
        if (returnBtn) returnBtn.addEventListener('click', hideReturnWarning);

        document.addEventListener('visibilitychange', function () {
          if (document.visibilityState === 'hidden') {
            wasHidden = true;
            setPrivacyShield(true);
            sendVisibility('hidden');
          } else {
            sendVisibility('visible');
            if (wasHidden) {
              wasHidden = false;
              showSecurityOverlay('visibility');
            } else if (document.hasFocus() && (!securityOverlay || !securityOverlay.classList.contains('is-on'))) {
              setPrivacyShield(false);
            }
          }
        });
        // Blur/focus: hide content immediately (helps against Snipping Tool) — do NOT double-count tab switches.
        window.addEventListener('blur', function () {
          if (state.submitting) return;
          setPrivacyShield(true);
          showSecurityOverlay('blur');
        });
        window.addEventListener('focus', function () {
          if (securityOverlay && securityOverlay.classList.contains('is-on')) return;
          if (!document.hidden) setPrivacyShield(false);
        });
        setInterval(function () {
          if (state.submitting) return;
          if (!document.hasFocus() || document.hidden) setPrivacyShield(true);
        }, 350);

        function isWinKey(e) {
          var code = e.code || '';
          var key = e.key || '';
          var isMetaCode = code === 'MetaLeft' || code === 'MetaRight' || code === 'OSLeft' || code === 'OSRight'
            || key === 'Meta' || key === 'OS';
          if (!isMetaCode) return false;
          // Only treat as Windows key on Windows — Meta is Command on macOS.
          var ua = navigator.userAgent || '';
          return /Windows/i.test(ua) || /Win/i.test(navigator.platform || '');
        }
        function triggerScreenshotBlock(reason) {
          setPrivacyShield(true);
          showSecurityOverlay(reason || 'screenshot');
        }
        window.addEventListener('keydown', function (e) {
          if (state.submitting) return;
          var key = (e.key || '').toLowerCase();
          var ctrlLike = e.ctrlKey || e.metaKey;
          if (e.key === 'PrintScreen' || e.code === 'PrintScreen') {
            triggerScreenshotBlock('screenshot');
            return;
          }
          // Win key alone / as modifier — hide content before Snipping Tool can capture questions.
          if (isWinKey(e)) {
            triggerScreenshotBlock('win_key');
            return;
          }
          // Snipping Tool / macOS capture style shortcuts
          if (ctrlLike && e.shiftKey && (key === 's' || key === '3' || key === '4' || key === '5')) {
            e.preventDefault();
            triggerScreenshotBlock('screenshot');
            return;
          }
          if (e.shiftKey && e.metaKey && key === 's') {
            e.preventDefault();
            triggerScreenshotBlock('screenshot');
          }
        }, true);
        window.addEventListener('keyup', function (e) {
          if (state.submitting) return;
          if (e.key === 'PrintScreen' || e.code === 'PrintScreen' || isWinKey(e)) {
            triggerScreenshotBlock(isWinKey(e) ? 'win_key' : 'screenshot');
          }
        }, true);

        document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        document.addEventListener('copy', function (e) { e.preventDefault(); });
        document.addEventListener('cut', function (e) { e.preventDefault(); });
        document.addEventListener('paste', function (e) { e.preventDefault(); });
        window.addEventListener('beforeprint', function () {
          triggerScreenshotBlock('screenshot');
          showWarnToast('Printing is disabled during an active exam.', 'danger');
        });

        document.querySelectorAll('.exam-no-copy').forEach(function (el) {
          ['copy', 'cut', 'contextmenu'].forEach(function (ev) {
            el.addEventListener(ev, function (e) { e.preventDefault(); });
          });
        });

        function fmtTime(sec) {
          if (sec === null || sec === undefined) return '--:--';
          sec = Math.max(0, sec | 0);
          var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
          if (h > 0) return h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
          return m + ':' + (s < 10 ? '0' : '') + s;
        }
        function updateTimerVisual() {
          if (countdown === null || !timerWrap || !timerValue) return;
          timerValue.textContent = fmtTime(countdown);
          timerWrap.classList.remove('warning', 'danger');
          if (countdown <= 60) timerWrap.classList.add('danger');
          else if (countdown <= 300) timerWrap.classList.add('warning');
          if (timerProgress && initialRemaining && initialRemaining > 0) {
            var pct = Math.max(0, Math.min(1, countdown / initialRemaining));
            timerProgress.style.strokeDashoffset = String(circumference * (1 - pct));
          }
          if (countdown <= 300 && countdown > 60 && !warned5) { warned5 = true; showWarnToast('5 minutes remaining', 'warning'); }
          if (countdown <= 60 && countdown > 30 && !warned1) { warned1 = true; showWarnToast('1 minute remaining', 'danger'); }
          if (countdown <= 30 && countdown > 0 && !warned30) { warned30 = true; showWarnToast('30 seconds remaining', 'danger'); }
          if (examTimerCompact && timerValue) examTimerCompact.textContent = timerValue.textContent;
          if (examTimerCompactWrap && timerWrap) {
            examTimerCompactWrap.classList.toggle('warning', timerWrap.classList.contains('warning'));
            examTimerCompactWrap.classList.toggle('danger', timerWrap.classList.contains('danger'));
          }
        }
        function updateActionBarUI() {
          var prevBtn = document.getElementById('examPrevBtn');
          var nextBtn = document.getElementById('examNextBtn');
          var onLast = state.currentIndex >= totalQuestions - 1;
          var complete = unansweredCount() === 0 && totalQuestions > 0;
          if (prevBtn) prevBtn.disabled = state.currentIndex <= 0;
          if (nextBtn) {
            nextBtn.hidden = onLast && complete;
            if (onLast && !complete) {
              nextBtn.innerHTML = '<i class="bi bi-list-check" aria-hidden="true"></i><span>Review</span>';
            } else if (!onLast) {
              nextBtn.innerHTML = '<span>Next</span><i class="bi bi-arrow-right" aria-hidden="true"></i>';
            }
          }
        }
        function updateCounts() {
          var uniq = {};
          var map = getLocalAnswersMap();
          Object.keys(map).forEach(function (qid) { uniq[qid] = true; });
          state.answered.forEach(function (qid) { uniq[qid] = true; });
          var n = Object.keys(uniq).length;
          if (answeredCountEl) answeredCountEl.textContent = String(n);
          if (flaggedCountEl) flaggedCountEl.textContent = String(state.flags.size);
          if (progressBar && totalQuestions > 0) progressBar.style.width = Math.round((n / totalQuestions) * 100) + '%';
          if (currentLabel) currentLabel.textContent = String(state.currentIndex + 1);
          if (examNavCurrentLabel) examNavCurrentLabel.textContent = String(state.currentIndex + 1);
          if (submitAnsweredNum) submitAnsweredNum.textContent = String(n);
          updateSubmitButton();
          updateActionBarUI();
        }
        function unansweredList() {
          var list = [];
          panels.forEach(function (p, idx) {
            var qid = parseInt(p.getAttribute('data-question-id'), 10);
            if (!isQuestionAnsweredLocal(qid)) list.push(idx + 1);
          });
          return list;
        }
        function updateSubmitButton() {
          var answeredN = totalQuestions - unansweredCount();
          var complete = unansweredCount() === 0 && totalQuestions > 0;
          var examSubmitStatus = document.getElementById('examSubmitStatus');
          if (examSubmitStatus) {
            var statusNum = document.getElementById('submitAnsweredNum');
            if (statusNum) {
              statusNum.textContent = String(answeredN);
            } else {
              examSubmitStatus.textContent = answeredN + ' of ' + totalQuestions + ' answered';
            }
            examSubmitStatus.classList.toggle('is-complete', complete);
          }
          if (submitExamBtn) {
            submitExamBtn.disabled = false;
            submitExamBtn.hidden = !complete;
            submitExamBtn.classList.toggle('is-locked', !complete);
            submitExamBtn.setAttribute('aria-disabled', complete ? 'false' : 'true');
            if (submitExamBtnText) {
              submitExamBtnText.textContent = 'Submit exam';
            }
          }
          if (submitIncompleteHint && complete) {
            submitIncompleteHint.classList.add('hidden');
            submitIncompleteHint.textContent = '';
          }
          document.querySelectorAll('.flagBtn').forEach(function (btn) {
            var qid = parseInt(btn.getAttribute('data-question-id'), 10);
            btn.classList.toggle('is-on', state.flags.has(qid));
          });
        }
        function updatePrimaryActionUI() {
          updateSubmitButton();
        }
        function unansweredCount() { return unansweredList().length; }
        function syncChoiceStyles() {
          panels.forEach(function (panel) {
            panel.querySelectorAll('[data-choice-row]').forEach(function (row) {
              var input = row.querySelector('input[type=radio]');
              row.classList.toggle('selected', !!(input && input.checked));
            });
            var qid = parseInt(panel.getAttribute('data-question-id'), 10);
            panel.classList.toggle('is-answered', isQuestionAnsweredLocal(qid));
          });
        }
        function renderNav(target) {
          if (!target) return;
          target.innerHTML = '';
          panels.forEach(function (panel, idx) {
            var qid = parseInt(panel.getAttribute('data-question-id'), 10);
            var answered = isQuestionAnsweredLocal(qid), flagged = state.flags.has(qid);
            if (state.filter === 'flagged' && !flagged) return;
            if (state.filter === 'unanswered' && answered) return;
            if (state.filter === 'answered' && !answered) return;
            var a = document.createElement('a');
            a.href = '#q' + (idx + 1);
            a.className = '';
            if (idx === state.currentIndex) a.classList.add('current');
            if (answered) a.classList.add('answered');
            if (flagged) a.classList.add('flagged');
            a.setAttribute('data-question-id', String(qid));
            a.setAttribute('title', 'Question ' + (idx + 1));
            a.innerHTML = '<span class="q-num">' + (idx + 1) + '</span>';
            a.addEventListener('click', function (e) {
              e.preventDefault();
              scrollToQuestion(idx, true);
              closeMobileDrawer();
            });
            target.appendChild(a);
          });
        }
        function renderNavigator() {
          renderNav(navigatorEl);
          renderNav(mobileNavEl);
        }
        function scrollToQuestion(i, intentional) {
          i = Math.max(0, Math.min(totalQuestions - 1, i));
          state.currentIndex = i;
          scrollSyncFromClick = true;
          clearTimeout(scrollSyncTimer);
          scrollSyncTimer = setTimeout(function () { scrollSyncFromClick = false; }, 700);
          var panel = panels[i];
          if (panel) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            try { panel.focus({ preventScroll: true }); } catch (e) {}
          }
          updateCounts();
          renderNavigator();
          queueStateSync();
        }
        function setCurrentIndex(i) {
          scrollToQuestion(i, true);
        }
        function setCurrentIndexFromScroll(i) {
          i = Math.max(0, Math.min(totalQuestions - 1, i));
          if (i === state.currentIndex) return;
          state.currentIndex = i;
          updateCounts();
          renderNavigator();
          queueStateSync();
        }
        function findNextUnansweredAfter(fromIndex) {
          for (var i = fromIndex + 1; i < panels.length; i++) {
            var qid = parseInt(panels[i].getAttribute('data-question-id'), 10);
            if (!isQuestionAnsweredLocal(qid)) return i;
          }
          for (var j = 0; j <= fromIndex; j++) {
            var qid2 = parseInt(panels[j].getAttribute('data-question-id'), 10);
            if (!isQuestionAnsweredLocal(qid2)) return j;
          }
          return -1;
        }

        var stateTimer = null;
        function queueStateSync() {
          if (stateTimer) clearTimeout(stateTimer);
          stateTimer = setTimeout(function () {
            request('sync_state', {
              csrf_token: csrf,
              attempt_id: attemptId,
              current_index: state.currentIndex,
              flags: JSON.stringify(Array.from(state.flags.values()))
            }).catch(function () {});
          }, 350);
        }

        function lockAnswerInputs() {
          answersLocked = true;
          form.querySelectorAll('input[type=radio]').forEach(function (inp) {
            inp.disabled = true;
          });
        }

        function awaitInflightSaves(maxWaitMs) {
          maxWaitMs = typeof maxWaitMs === 'number' ? maxWaitMs : 2500;
          var pending = Object.keys(inflightSaves).map(function (k) { return inflightSaves[k]; }).filter(Boolean);
          if (!pending.length) return Promise.resolve();
          return Promise.race([
            Promise.allSettled(pending),
            new Promise(function (resolve) { setTimeout(resolve, maxWaitMs); })
          ]);
        }

        function saveAnswer(qid, value, fromIndex, attempt) {
          attempt = attempt || 1;
          if (answersLocked || state.submitting) {
            return Promise.resolve(null);
          }
          if (attempt > 1) showWarnToast('Unable to save — retrying...', 'warning');
          else if (warnToast) warnToast.classList.remove('show');
          var p = request('save_answer', {
            csrf_token: csrf,
            attempt_id: attemptId,
            question_id: qid,
            selected_answer: value
          }).then(function (data) {
            if (!data || !data.ok) throw new Error((data && data.error) || 'Save failed');
            setConn(true, attempt > 1);
            showSavedToast('Answer saved');
            var wasAnswered = state.answered.has(qid);
            state.answered.add(qid);
            syncChoiceStyles();
            updateCounts();
            renderNavigator();
            // Auto-advance only after first successful answer (changing an answer does not yank the page).
            if (!wasAnswered && typeof fromIndex === 'number' && fromIndex >= 0) {
              var nextIdx = findNextUnansweredAfter(fromIndex);
              if (nextIdx >= 0 && nextIdx !== fromIndex) {
                setTimeout(function () { scrollToQuestion(nextIdx, false); }, 180);
              } else if (unansweredCount() === 0) {
                var submitCard = document.querySelector('.exam-submit-card');
                if (submitCard) submitCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
              }
            }
            return data;
          }).catch(function () {
            setConn(false);
            if (answersLocked || state.submitting) return null;
            if (attempt < 4) {
              clearTimeout(saveRetryTimers[qid]);
              saveRetryTimers[qid] = setTimeout(function () { saveAnswer(qid, value, fromIndex, attempt + 1); }, 450 * attempt);
              showWarnToast('Unable to save your answer. Please check your connection.', 'danger');
            } else {
              showWarnToast('Unable to save your answer. Please check your connection.', 'danger');
            }
            return null;
          }).finally(function () {
            if (inflightSaves[qid] === p) delete inflightSaves[qid];
          });
          inflightSaves[qid] = p;
          return p;
        }

        function flushAllLocalAnswers() {
          // Prefer one submit payload over N round-trips (faster + survives flaky saves).
          return Promise.resolve({ ok: true, answers: localAnswersPayload() });
        }

        function openTimeUpModal() {
          var sm = document.getElementById('submitConfirmModal');
          if (sm) { sm.classList.add('hidden'); sm.classList.remove('flex'); }
          var m = document.getElementById('timeUpModal');
          if (!m) return;
          m.classList.remove('hidden'); m.classList.add('flex');
        }
        function closeTimeUpModal() {
          var m = document.getElementById('timeUpModal');
          if (!m) return;
          m.classList.add('hidden'); m.classList.remove('flex');
        }

        function postSubmitPayload(reason, answersJson, useKeepalive) {
          var body = new URLSearchParams();
          body.set('action', 'submit');
          body.set('csrf_token', csrf);
          body.set('attempt_id', String(attemptId));
          body.set('reason', reason || 'manual');
          body.set('answers', answersJson || '[]');
          var opts = {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
            credentials: 'same-origin'
          };
          if (useKeepalive) opts.keepalive = true;
          return fetch(ajaxUrl, opts).then(function (r) {
            return r.text().then(function (text) {
              var data = null;
              try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
              if (!r.ok) throw new Error((data && data.error) || ('Request failed (' + r.status + ')'));
              if (data === null) throw new Error('Invalid server response');
              return data;
            });
          });
        }

        /** Time expired: lock inputs, await in-flight autosaves briefly, then one atomic timeout submit. */
        function autoSubmitOnTimeUp(reason, attempt) {
          reason = reason || 'timeout';
          attempt = attempt || 1;
          if (state.submitting && attempt === 1) return;
          state.submitting = true;
          countdown = 0;
          lockAnswerInputs();
          Object.keys(saveRetryTimers).forEach(function (k) {
            clearTimeout(saveRetryTimers[k]);
            delete saveRetryTimers[k];
          });
          openTimeUpModal();
          window.onbeforeunload = null;

          awaitInflightSaves(2500).then(function () {
            var answersJson = JSON.stringify(localAnswersPayload());
            // Prefer waiting for server confirmation over keepalive fire-and-forget.
            return postSubmitPayload(reason, answersJson, false);
          }).then(function (data) {
            if (!data || !data.ok) throw new Error((data && data.error) || 'Submit failed');
            clearAnswerBackup();
            window.location.href = 'college_take_exam?exam_id=' + examId + '&review=1&reason=' + encodeURIComponent(reason);
          }).catch(function () {
            if (attempt < 8) {
              setTimeout(function () {
                state.submitting = false;
                autoSubmitOnTimeUp(reason, attempt + 1);
              }, Math.min(8000, 900 * attempt));
            } else {
              state.submitting = false;
              showWarnToast('Time is up but submit failed. Retrying… check your connection.', 'danger');
              setTimeout(function () {
                autoSubmitOnTimeUp(reason, 1);
              }, 5000);
            }
          });
        }

        function submitNow(reason) {
          if (state.submitting) return;
          var isTimeout = (reason === 'timeout' || reason === 'timeout-sync');
          if (isTimeout) {
            autoSubmitOnTimeUp(reason);
            return;
          }
          if (unansweredCount() > 0) {
            var miss = unansweredList();
            var msg = 'Please answer all questions before submitting the exam.';
            if (miss.length) {
              var qLabel;
              if (miss.length === 1) qLabel = 'Question ' + miss[0];
              else if (miss.length === 2) qLabel = 'Questions ' + miss[0] + ' and ' + miss[1];
              else qLabel = 'Questions ' + miss.slice(0, -1).join(', ') + ', and ' + miss[miss.length - 1];
              msg += ' ' + miss.length + ' question' + (miss.length === 1 ? '' : 's') + ' unanswered: ' + qLabel + '.';
            }
            if (submitIncompleteHint) {
              submitIncompleteHint.textContent = msg;
              submitIncompleteHint.classList.remove('hidden');
            }
            showWarnToast(msg, 'danger');
            var firstMiss = miss.length ? miss[0] - 1 : 0;
            scrollToQuestion(firstMiss, true);
            return;
          }
          state.submitting = true;
          var overlay = document.getElementById('quizSubmitOverlay');
          if (overlay) overlay.classList.add('show');
          flushAllLocalAnswers().then(function () {
            var answersJson = JSON.stringify(localAnswersPayload());
            return postSubmitPayload(reason || 'manual', answersJson, false);
          }).then(function (data) {
            if (!data || !data.ok) throw new Error((data && data.error) || 'Submit failed');
            clearAnswerBackup();
            window.onbeforeunload = null;
            window.location.href = 'college_take_exam?exam_id=' + examId + '&review=1&reason=' + encodeURIComponent(reason || 'submit');
          }).catch(function (err) {
            state.submitting = false;
            if (overlay) overlay.classList.remove('show');
            var msg = (err && err.message) ? err.message : 'Submit failed';
            if (submitIncompleteHint) {
              submitIncompleteHint.textContent = msg;
              submitIncompleteHint.classList.remove('hidden');
            }
            showWarnToast(msg, 'danger');
            alert('Could not submit exam. ' + msg);
          });
        }

        form.querySelectorAll('input[type=radio]').forEach(function (inp) {
          inp.addEventListener('change', function () {
            if (answersLocked || state.submitting) return;
            var qid = parseInt(inp.getAttribute('data-question-id'), 10);
            if (!qid) return;
            var panel = inp.closest('[data-question-panel]');
            var fromIndex = panel ? parseInt(panel.getAttribute('data-index'), 10) : -1;
            // Reflect selection immediately (even if autosave is slow/offline).
            persistAnswerBackup(qid, inp.value);
            syncChoiceStyles();
            updateCounts();
            renderNavigator();
            saveAnswer(qid, inp.value, fromIndex);
          });
        });

        // Restore selections from local backup when autosave previously failed.
        (function restoreAnswerBackup() {
          var map = {};
          try {
            var raw = localStorage.getItem(answerBackupKey());
            if (raw) {
              var parsed = JSON.parse(raw);
              if (parsed && typeof parsed === 'object') map = parsed;
            }
          } catch (e) { return; }
          Object.keys(map).forEach(function (qidStr) {
            var qid = parseInt(qidStr, 10);
            var val = String(map[qidStr] || '').toUpperCase();
            if (!qid || !/^[A-D]$/.test(val)) return;
            var inp = form.querySelector('input[type=radio][data-question-id="' + qid + '"][value="' + val + '"]');
            if (inp && !inp.checked) {
              inp.checked = true;
            }
          });
          syncChoiceStyles();
          updateCounts();
          renderNavigator();
        })();
        document.querySelectorAll('.flagBtn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var qid = parseInt(btn.getAttribute('data-question-id'), 10);
            if (!qid) return;
            if (state.flags.has(qid)) state.flags.delete(qid); else state.flags.add(qid);
            updateCounts(); renderNavigator(); updatePrimaryActionUI(); queueStateSync();
          });
        });
        document.querySelectorAll('[data-choice-row]').forEach(function (row) {
          row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              var input = row.querySelector('input[type=radio]');
              if (input) { input.checked = true; input.dispatchEvent(new Event('change', { bubbles: true })); }
            }
          });
        });

        function tryOpenSubmit() {
          if (unansweredCount() > 0) {
            submitNow('manual');
            return;
          }
          openSubmitModal();
        }
        if (submitExamBtn) submitExamBtn.addEventListener('click', tryOpenSubmit);
        document.querySelectorAll('.exam-filter-btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            state.filter = btn.getAttribute('data-filter') || 'all';
            document.querySelectorAll('.exam-filter-btn').forEach(function (b) {
              b.classList.toggle('is-active', (b.getAttribute('data-filter') || 'all') === state.filter);
            });
            renderNavigator();
          });
        });
        var qListSection = document.getElementById('examQListSection');
        var qListTrigger = document.getElementById('examQListTrigger');
        if (qListTrigger && qListSection) {
          qListTrigger.addEventListener('click', function () {
            qListSection.classList.toggle('collapsed');
            qListTrigger.setAttribute('aria-expanded', qListSection.classList.contains('collapsed') ? 'false' : 'true');
          });
        }

        var submitModal = document.getElementById('submitConfirmModal');
        function openSubmitModal() {
          if (unansweredCount() > 0) {
            submitNow('manual');
            return;
          }
          state.submitConfirmStep = 0;
          document.getElementById('doubleConfirmHint').classList.add('hidden');
          var answeredN = totalQuestions - unansweredCount();
          document.getElementById('sumAnswered').textContent = String(answeredN);
          var u = unansweredCount();
          document.getElementById('sumUnanswered').textContent = String(u);
          document.getElementById('sumFlagged').textContent = String(state.flags.size);
          document.getElementById('sumTimeRemaining').textContent = fmtTime(countdown);
          var ban = document.getElementById('submitUnansweredBanner');
          var nums = document.getElementById('submitUnansweredNums');
          if (ban && nums) {
            ban.classList.remove('submit-unanswered-ok', 'submit-unanswered-warn');
            if (u === 0) {
              ban.classList.add('submit-unanswered-ok');
              nums.classList.add('hidden');
              nums.textContent = '';
            } else {
              ban.classList.add('submit-unanswered-warn');
              nums.textContent = 'Unanswered question #' + unansweredList().join(', #');
              nums.classList.remove('hidden');
            }
          }
          submitModal.classList.remove('hidden'); submitModal.classList.add('flex');
        }
        function closeSubmitModal() { submitModal.classList.add('hidden'); submitModal.classList.remove('flex'); }
        document.getElementById('closeSubmitModalBtn').addEventListener('click', closeSubmitModal);
        document.getElementById('reviewUnansweredBtn').addEventListener('click', function () {
          closeSubmitModal();
          state.filter = 'unanswered';
          document.querySelectorAll('.exam-filter-btn').forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-filter') === 'unanswered');
          });
          renderNavigator();
          var idx = panels.findIndex(function (p) {
            return !isQuestionAnsweredLocal(parseInt(p.getAttribute('data-question-id'), 10));
          });
          if (idx >= 0) setCurrentIndex(idx);
        });
        document.getElementById('confirmSubmitBtn').addEventListener('click', function () {
          if (state.submitConfirmStep === 0) {
            state.submitConfirmStep = 1;
            document.getElementById('doubleConfirmHint').classList.remove('hidden');
            return;
          }
          closeSubmitModal();
          submitNow('manual');
        });

        var shortcutsModal = document.getElementById('shortcutsModal');
        function openShortcuts() { shortcutsModal.classList.remove('hidden'); shortcutsModal.classList.add('flex'); }
        function closeShortcuts() { shortcutsModal.classList.add('hidden'); shortcutsModal.classList.remove('flex'); }
        var closeShortcutsBtn = document.getElementById('closeShortcutsBtn');
        if (closeShortcutsBtn) closeShortcutsBtn.addEventListener('click', closeShortcuts);

        function openMobileDrawer() {
          if (!examQnavDrawer) return;
          examQnavDrawer.hidden = false;
          examQnavDrawer.setAttribute('aria-hidden', 'false');
          if (examQuestionsBtn) examQuestionsBtn.setAttribute('aria-expanded', 'true');
          document.body.classList.add('exam-qnav-drawer-open');
        }
        function closeMobileDrawer() {
          if (!examQnavDrawer) return;
          examQnavDrawer.hidden = true;
          examQnavDrawer.setAttribute('aria-hidden', 'true');
          if (examQuestionsBtn) examQuestionsBtn.setAttribute('aria-expanded', 'false');
          document.body.classList.remove('exam-qnav-drawer-open');
        }
        if (examQuestionsBtn) examQuestionsBtn.addEventListener('click', openMobileDrawer);
        var closeMobileDrawerBtn = document.getElementById('closeMobileDrawerBtn');
        if (closeMobileDrawerBtn) closeMobileDrawerBtn.addEventListener('click', closeMobileDrawer);
        var examQnavDrawerBackdrop = document.getElementById('examQnavDrawerBackdrop');
        if (examQnavDrawerBackdrop) examQnavDrawerBackdrop.addEventListener('click', closeMobileDrawer);
        if (examQnavDrawer) {
          examQnavDrawer.addEventListener('click', function (e) {
            if (e.target === examQnavDrawer) closeMobileDrawer();
          });
        }
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && examQnavDrawer && !examQnavDrawer.hidden) {
            closeMobileDrawer();
          }
        });

        var examPrevBtn = document.getElementById('examPrevBtn');
        var examNextBtn = document.getElementById('examNextBtn');
        if (examPrevBtn) {
          examPrevBtn.addEventListener('click', function () {
            if (state.currentIndex > 0) setCurrentIndex(state.currentIndex - 1);
          });
        }
        if (examNextBtn) {
          examNextBtn.addEventListener('click', function () {
            var onLast = state.currentIndex >= totalQuestions - 1;
            if (onLast) {
              var idx = panels.findIndex(function (p) {
                return !isQuestionAnsweredLocal(parseInt(p.getAttribute('data-question-id'), 10));
              });
              if (idx >= 0) setCurrentIndex(idx);
              else tryOpenSubmit();
              return;
            }
            if (state.currentIndex < totalQuestions - 1) setCurrentIndex(state.currentIndex + 1);
          });
        }
        document.addEventListener('keydown', function (e) {
          if (e.target && /input|textarea|select/i.test(e.target.tagName)) return;
          if (e.key === '?' || (e.shiftKey && e.key === '/')) { e.preventDefault(); openShortcuts(); return; }
          if (e.key === 'n' || e.key === 'N') {
            e.preventDefault();
            if (state.currentIndex < totalQuestions - 1) setCurrentIndex(state.currentIndex + 1);
            else {
              var actionBar = document.getElementById('examActionBar');
              if (actionBar) actionBar.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
          }
          if (e.key === 'p' || e.key === 'P') {
            e.preventDefault();
            setCurrentIndex(state.currentIndex - 1);
          }
          if (e.key === 'f' || e.key === 'F') {
            var panel = panels[state.currentIndex]; if (!panel) return;
            var qid = parseInt(panel.getAttribute('data-question-id'), 10); if (!qid) return;
            if (state.flags.has(qid)) state.flags.delete(qid); else state.flags.add(qid);
            updateCounts(); renderNavigator(); updatePrimaryActionUI(); queueStateSync();
          }
          if (/^[1-4]$/.test(e.key)) {
            var panel2 = panels[state.currentIndex]; if (!panel2) return;
            var radios = panel2.querySelectorAll('input[type=radio]');
            var idx = parseInt(e.key, 10) - 1;
            if (radios[idx]) { radios[idx].checked = true; radios[idx].dispatchEvent(new Event('change', { bubbles: true })); }
          }
        });

        if ('IntersectionObserver' in window) {
          var io = new IntersectionObserver(function (entries) {
            if (scrollSyncFromClick) return;
            var best = null;
            entries.forEach(function (entry) {
              if (!entry.isIntersecting) return;
              if (!best || entry.intersectionRatio > best.intersectionRatio) best = entry;
            });
            if (!best || !best.target) return;
            var idx = parseInt(best.target.getAttribute('data-index'), 10);
            if (isFinite(idx)) setCurrentIndexFromScroll(idx);
          }, { root: null, rootMargin: '-20% 0px -55% 0px', threshold: [0.15, 0.35, 0.55] });
          panels.forEach(function (p) {
            p.setAttribute('tabindex', '-1');
            io.observe(p);
          });
        }

        var leaveModal = document.getElementById('leaveConfirmModal');
        var leaveTargetUrl = '';
        function openLeaveModal(url) {
          leaveTargetUrl = url || '';
          leaveModal.classList.remove('hidden'); leaveModal.classList.add('flex');
        }
        function closeLeaveModal() {
          leaveTargetUrl = '';
          leaveModal.classList.add('hidden'); leaveModal.classList.remove('flex');
        }
        document.getElementById('stayOnExamBtn').addEventListener('click', closeLeaveModal);
        var examExitBtn = document.getElementById('examExitBtn');
        if (examExitBtn) {
          examExitBtn.addEventListener('click', function () {
            var href = examExitBtn.getAttribute('data-exit-href') || 'college_exams';
            var exitLink = document.createElement('a');
            exitLink.href = href;
            openLeaveModal(exitLink.href);
          });
        }
        document.getElementById('leaveExamBtn').addEventListener('click', function () {
          state.submitting = true;
          if (leaveTargetUrl) window.location.href = leaveTargetUrl;
        });
        leaveModal.addEventListener('click', function (e) { if (e.target === e.currentTarget) closeLeaveModal(); });
        document.querySelectorAll('a[href]').forEach(function (link) {
          link.addEventListener('click', function (e) {
            var href = link.getAttribute('href') || '';
            if (!href || href[0] === '#' || /^javascript:/i.test(href)) return;
            if (state.submitting) return;
            if (link.hasAttribute('data-allow-leave')) return;
            e.preventDefault();
            openLeaveModal(link.href);
          });
        });
        window.onbeforeunload = function () {
          if (state.submitting) return;
          return 'Your exam is still in progress.';
        };

        function timerTick() {
          if (countdown === null) {
            if (timerValue) timerValue.textContent = '--:--';
            return;
          }
          if (countdown <= 0) {
            if (timerValue) timerValue.textContent = '0:00';
            updateTimerVisual();
            autoSubmitOnTimeUp('timeout');
            return;
          }
          updateTimerVisual();
          countdown--;
          setTimeout(timerTick, 1000);
        }

        var timeSyncTimer = null;
        var timeSyncMs = 15000;
        function runTimeSyncTick() {
          if (state.submitting) return;
          request('get_time', { attempt_id: attemptId }).then(function (data) {
            if (data && data.ok && data.remaining_seconds !== null && data.remaining_seconds !== undefined) {
              countdown = Math.max(0, parseInt(data.remaining_seconds, 10) || 0);
              if (countdown <= 0) {
                autoSubmitOnTimeUp('timeout-sync');
                return;
              }
              updateTimerVisual();
              // Poll faster in the last minute so server clock wins over drift.
              if (countdown <= 60 && timeSyncMs > 5000) {
                scheduleTimeSync(5000);
              }
            }
          }).catch(function () {});
        }
        function scheduleTimeSync(ms) {
          if (typeof ms === 'number' && ms > 0) timeSyncMs = ms;
          if (timeSyncTimer) clearInterval(timeSyncTimer);
          timeSyncTimer = setInterval(runTimeSyncTick, timeSyncMs);
        }

        request('load_state', { csrf_token: csrf, attempt_id: attemptId }).then(function (data) {
          if (data && data.ok && data.state) {
            var s = data.state;
            if (Array.isArray(s.flags)) state.flags = new Set(s.flags.map(function (v) { return parseInt(v, 10); }).filter(function (v) { return v > 0; }));
            if (typeof s.current_index === 'number' && isFinite(s.current_index)) {
              state.currentIndex = Math.max(0, Math.min(totalQuestions - 1, s.current_index));
            }
          }
        }).catch(function () {})
          .finally(function () {
            updateCounts();
            syncChoiceStyles();
            updatePrimaryActionUI();
            renderNavigator();
            // Resume focus without forcing a jarring scroll to Q1 when already mid-page.
            if (state.currentIndex > 0 && panels[state.currentIndex]) {
              scrollToQuestion(state.currentIndex, true);
            }
            if (countdown !== null && countdown <= 0) {
              autoSubmitOnTimeUp('timeout');
            } else {
              timerTick();
              scheduleTimeSync((countdown !== null && countdown <= 60) ? 5000 : 15000);
            }
            setInterval(function () { if (!state.submitting) queueStateSync(); }, 15000);
          });
      })();
      </script>
    <?php else: ?>
      <p class="text-gray-600 mt-6">Unable to load this exam.</p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
