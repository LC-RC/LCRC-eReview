<?php
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';
require_once __DIR__ . '/includes/quiz_helpers.php';

$pageTitle = 'Take exam';
$uid = getCurrentUserId();
$examId = sanitizeInt($_GET['exam_id'] ?? 0);
$reviewParam = $_GET['review'] ?? null;
$reviewMode = $reviewParam !== null
    && $reviewParam !== ''
    && !in_array(strtolower((string)$reviewParam), ['0', 'false', 'no'], true);
$csrf = generateCSRFToken();

if ($examId <= 0) {
    header('Location: college_exams.php');
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
    header('Location: college_exams.php');
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

$now = date('Y-m-d H:i:s');
college_exam_finalize_expired_in_progress($conn, (int)$examId, (int)$uid, 0);
$stmt = mysqli_prepare($conn, "SELECT * FROM college_exam_attempts WHERE user_id=? AND exam_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ii', $uid, $examId);
mysqli_stmt_execute($stmt);
$attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

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
        header('Location: college_take_exam.php?exam_id=' . $examId);
        exit;
    }
    if ($attempt && $attemptSubmitted) {
        header('Location: college_take_exam.php?exam_id=' . $examId . '&review=1');
        exit;
    }
    if ($examClosedAllSubmitted && (!$attempt || $attemptStatusNorm !== 'in_progress')) {
        $_SESSION['error'] = 'This exam has closed because everyone on the roster has submitted.';
        header('Location: college_exams.php');
        exit;
    }
    if (!empty($exam['available_from']) && $exam['available_from'] > $now) {
        $_SESSION['error'] = 'This exam is not available yet.';
        header('Location: college_exams.php');
        exit;
    }
    if (!empty($exam['deadline']) && $exam['deadline'] < $now) {
        $_SESSION['error'] = 'The deadline has passed.';
        header('Location: college_exams.php');
        exit;
    }

    $started = date('Y-m-d H:i:s');
    $expiresAt = college_exam_compute_expires_at((int)$exam['time_limit_seconds'], $exam['deadline'] ?? null);
    if (!$attempt) {
        $ins = mysqli_prepare($conn, "INSERT INTO college_exam_attempts (exam_id, user_id, status, started_at, expires_at, last_seen_at) VALUES (?, ?, 'in_progress', ?, ?, ?)");
        mysqli_stmt_bind_param($ins, 'iisss', $examId, $uid, $started, $expiresAt, $started);
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
    } elseif ($attemptStatusNorm === 'expired') {
        $aid = (int)$attempt['attempt_id'];
        mysqli_query($conn, "DELETE FROM college_exam_answers WHERE attempt_id={$aid}");
        $emptyState = '{"current_index":0,"flags":[],"updated_at":0}';
        $upd = mysqli_prepare($conn, "UPDATE college_exam_attempts SET status='in_progress', started_at=?, expires_at=?, submitted_at=NULL, score=NULL, correct_count=NULL, total_count=NULL, ui_state_json=?, last_seen_at=?, exam_session_lock=NULL, tab_switch_count=0, last_tab_switch_at=NULL WHERE attempt_id=? AND user_id=?");
        mysqli_stmt_bind_param($upd, 'ssssii', $started, $expiresAt, $emptyState, $started, $aid, $uid);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }
    header('Location: college_take_exam.php?exam_id=' . $examId);
    exit;
}

if ($examClosedAllSubmitted) {
    if (!$attempt || ($attemptStatusNorm !== 'in_progress' && !$attemptSubmitted)) {
        $_SESSION['error'] = 'This exam has closed because everyone on the roster has submitted.';
        header('Location: college_exams.php');
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
    header('Location: college_take_exam.php?exam_id=' . $examId . '&review=1');
    exit;
}

$examSessionBlock = false;
if ($attempt && $attemptStatusNorm === 'in_progress') {
    $aid = (int)$attempt['attempt_id'];
    $cookieName = 'ereview_exam_lock_' . $aid;
    $lock = isset($attempt['exam_session_lock']) ? trim((string)$attempt['exam_session_lock']) : '';
    if ($lock === '') {
        $newLock = bin2hex(random_bytes(32));
        $upd = mysqli_prepare(
            $conn,
            "UPDATE college_exam_attempts SET exam_session_lock=? WHERE attempt_id=? AND user_id=? AND (exam_session_lock IS NULL OR exam_session_lock='')"
        );
        if ($upd) {
            mysqli_stmt_bind_param($upd, 'sii', $newLock, $aid, $uid);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
        $stmt = mysqli_prepare($conn, 'SELECT * FROM college_exam_attempts WHERE user_id=? AND exam_id=? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'ii', $uid, $examId);
        mysqli_stmt_execute($stmt);
        $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $lock = isset($attempt['exam_session_lock']) ? trim((string)$attempt['exam_session_lock']) : '';
        if ($lock !== '') {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            if (PHP_VERSION_ID >= 70300) {
                setcookie($cookieName, $lock, [
                    'expires' => time() + 86400 * 30,
                    'path' => '/',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            } else {
                setcookie($cookieName, $lock, time() + 86400 * 30, '/; samesite=Lax', '', $secure, true);
            }
        }
    }
    $lock = isset($attempt['exam_session_lock']) ? trim((string)$attempt['exam_session_lock']) : '';
    if ($lock !== '') {
        $cookieVal = $_COOKIE[$cookieName] ?? '';
        if (!hash_equals($lock, $cookieVal)) {
            $examSessionBlock = true;
        }
    }
}

$attemptStatusNorm = college_exam_attempt_status_normalized($attempt);
$attemptSubmitted = college_exam_attempt_is_effectively_submitted($attempt);

if (!empty($examSessionBlock)) {
    $pageTitle = 'Exam session active';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    body { background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%); min-height: 100vh; }
    .exam-lock-card { max-width: 28rem; margin: 4rem auto; padding: 2rem; border-radius: 1rem; border: 1px solid #cbd5e1; background: #fff; box-shadow: 0 20px 40px -24px rgba(15,23,42,.35); }
    .exam-lock-icon { width: 3.5rem; height: 3.5rem; border-radius: 999px; margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #1e3a5f 0%, #1665a0 100%); color: #fff; font-size: 1.5rem; }
  </style>
</head>
<body class="font-sans antialiased text-slate-800">
  <div class="exam-lock-card text-center">
    <div class="exam-lock-icon"><i class="bi bi-shield-lock"></i></div>
    <h1 class="text-xl font-bold text-slate-900 m-0">This exam is open elsewhere</h1>
    <p class="mt-3 mb-0 text-sm text-slate-600 leading-relaxed">This attempt is tied to the browser where you started. You cannot continue in this window. Close other tabs or use the same device and browser, or log out and sign in again only after finishing the exam.</p>
    <p class="mt-4 mb-0"><a href="college_exams.php" class="inline-flex items-center gap-2 text-sm font-semibold text-[#1665A0] hover:underline">Back to exams</a></p>
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
    ob_start();
    require __DIR__ . '/includes/college_take_exam_review_submitted_section.php';
    $reviewSubmittedSectionHtml = (string)ob_get_clean();
    $ereviewCollegeTakeDiag['reviewSectionBytes'] = strlen($reviewSubmittedSectionHtml);
}

$ereviewJsonDiagFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
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
    .exam-title { color: #fff; letter-spacing: .01em; }
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
      transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
    }
    .start-btn:hover { transform: translateY(-1px); background: linear-gradient(135deg,#145a8f 0%,#0b436c 100%); }
    .focus-ring:focus-visible { outline: 3px solid #1d4ed8; outline-offset: 2px; }
    .exam-toolbar { position: sticky; top: .5rem; z-index: 40; border: 1px solid #d5e7f7; border-radius: .9rem; background: rgba(255,255,255,.96); backdrop-filter: blur(8px); box-shadow: 0 8px 28px -20px rgba(20,61,89,.45); transition: border-color .25s ease, box-shadow .25s ease, background-color .25s ease; }
    .exam-badge { border-radius: 999px; padding: .34rem .65rem; font-size: .73rem; font-weight: 800; border: 1px solid #dbe7f5; background: #f8fbff; color: #1e3a5f; }
    .chip-dot { width: .5rem; height: .5rem; border-radius: 999px; display: inline-block; margin-right: .38rem; vertical-align: middle; }
    .time-normal { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
    .time-warning { background: #fffbeb; border-color: #fde68a; color: #b45309; }
    .time-critical { background: #fef2f2; border-color: #fecaca; color: #b91c1c; animation: pulseWarn 1.2s infinite; }
    @keyframes pulseWarn { 0%,100%{ box-shadow: 0 0 0 0 rgba(220,38,38,.28);} 50%{ box-shadow: 0 0 0 8px rgba(220,38,38,0);} }
    .exam-grid { display: grid; grid-template-columns: minmax(0,1fr) 320px; gap: 1rem; align-items: start; }
    .exam-card { border: 1px solid #dbe7f5; border-radius: .9rem; background: linear-gradient(180deg,#ffffff 0%,#fbfdff 100%); box-shadow: 0 8px 22px -18px rgba(20,61,89,.4); transition: border-color .25s ease, box-shadow .25s ease, background .25s ease; }
    .question-text { font-size: 1.03rem; line-height: 1.66; color: #0f172a; }
    .question-text table { border-collapse: collapse; width: 100%; max-width: 100%; margin: 0.65rem 0; font-size: 0.96em; }
    .question-text th, .question-text td { border: 1px solid #cbd5e1; padding: 0.45rem 0.65rem; vertical-align: top; }
    .question-text th { background: #f1f5f9; font-weight: 700; }
    .question-text p { margin: 0 0 0.5em 0; }
    .question-text p:last-child { margin-bottom: 0; }
    .question-text code, .choice-text code { font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size: .92em; padding: .08rem .26rem; border-radius: .35rem; background: #f1f5f9; }
    .question-text pre, .choice-text pre { font-family: ui-monospace,SFMono-Regular,Menlo,monospace; font-size: .84rem; padding: .72rem; border-radius: .55rem; background: #0f172a; color: #e2e8f0; overflow:auto; }
    .choice-row { border: 1px solid #dbe7f5; border-radius: .8rem; padding: .82rem .9rem; margin-bottom: .62rem; cursor: pointer; transition: all .2s ease; display: flex; gap: .65rem; align-items: flex-start; }
    .choice-row:hover { background: #f8fbff; border-color: #8ec5eb; transform: translateY(-1px); }
    .choice-row.is-selected { border-color: #1665A0; background: #eef6ff; box-shadow: inset 0 0 0 1px rgba(22,101,160,.18); }
    .choice-radio { margin-top: .22rem; width: 1.1rem; height: 1.1rem; }
    .choice-text { line-height: 1.55; color: #0f172a; word-break: break-word; }
    .question-nav-grid { display: grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap: .4rem; }
    .question-stage-title { font-size: .95rem; font-weight: 800; color: #143D59; letter-spacing: .01em; }
    .question-stage-sub { font-size: .78rem; color: #64748b; margin-top: .12rem; }
    .qchip { border: 1px solid #dbe7f5; border-radius: .6rem; min-height: 2.1rem; font-size: .78rem; font-weight: 800; background: #fff; color: #1f3658; }
    .qchip-current { border-color: #1665A0; background: #e8f2fa; color: #0d4f80; }
    .qchip-answered { border-color: #86efac; background: #ecfdf5; color: #047857; }
    .qchip-flagged { border-color: #f59e0b; background: #fffbeb; color: #b45309; }
    .offline-banner { display: none; border: 1px solid #fca5a5; background: #fef2f2; color: #991b1b; border-radius: .75rem; padding: .55rem .75rem; font-size: .82rem; font-weight: 700; }
    .offline-banner.is-on { display: block; }
    .session-active { color:#047857; } .session-syncing { color:#1d4ed8; } .session-reconnect { color:#b45309; }
    .help-kbd { border:1px solid #cbd5e1; background:#f8fafc; border-radius:.34rem; padding:.05rem .35rem; font-size:.72rem; font-family: ui-monospace,SFMono-Regular,Menlo,monospace; }
    .focus-mode #app-sidebar, .focus-mode .student-topbar { display: none !important; }
    .focus-mode #main.app-shell-main--student { margin-left: 0 !important; padding-top: .5rem !important; }
    .contrast-high .exam-card, .contrast-high .exam-toolbar { border-color:#334155 !important; box-shadow:none !important; }
    .contrast-high .choice-row { border-color:#334155 !important; background:#fff !important; }
    .contrast-high .choice-row.is-selected { border-color:#0f172a !important; background:#e2e8f0 !important; }
    .review-pill-correct { color:#047857; background:#ecfdf5; border-color:#86efac; }
    .review-pill-picked { color:#b91c1c; background:#fef2f2; border-color:#fecaca; }
    .review-pill-skip { color:#92400e; background:#fffbeb; border-color:#fde68a; }
    /* Final submission modal — premium */
    .submit-confirm-overlay { background: rgba(15, 23, 42, 0.62); backdrop-filter: blur(8px); }
    .submit-confirm-shell {
      width: 100%; max-width: 28rem;
      border-radius: 1.15rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      background: linear-gradient(165deg, #ffffff 0%, #f8fafc 42%, #f1f5f9 100%);
      box-shadow: 0 28px 64px -28px rgba(15, 23, 42, 0.55), 0 0 0 1px rgba(255,255,255,0.8) inset;
      overflow: hidden;
    }
    .submit-confirm-head { padding: 1.25rem 1.35rem 1rem; border-bottom: 1px solid rgba(226, 232, 240, 0.9); background: linear-gradient(180deg, rgba(248,250,252,0.9) 0%, transparent 100%); }
    .submit-confirm-icon { width: 2.75rem; height: 2.75rem; border-radius: 0.9rem; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%); color: #fff; font-size: 1.35rem; margin-bottom: 0.65rem; box-shadow: 0 10px 24px -12px rgba(37, 99, 235, 0.65); }
    .submit-confirm-title { margin: 0; font-size: 1.15rem; font-weight: 900; letter-spacing: -0.02em; color: #0f172a; }
    .submit-confirm-sub { margin: 0.35rem 0 0; font-size: 0.84rem; color: #64748b; line-height: 1.45; font-weight: 600; }
    .submit-confirm-body { padding: 1.1rem 1.35rem 1.25rem; }
    .submit-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.55rem; }
    .submit-stat-cell { border-radius: 0.65rem; border: 1px solid #e2e8f0; background: #fff; padding: 0.55rem 0.65rem; }
    .submit-stat-k { font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; }
    .submit-stat-v { font-size: 0.95rem; font-weight: 800; color: #0f172a; margin-top: 0.12rem; }
    .submit-unanswered-banner { margin-top: 0.75rem; border-radius: 0.75rem; padding: 0.65rem 0.8rem; font-size: 0.82rem; font-weight: 700; line-height: 1.4; display: flex; flex-direction: column; gap: 0.25rem; transition: background .2s, border-color .2s, color .2s; }
    .submit-unanswered-ok { border: 1px solid #86efac; background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); color: #065f46; }
    .submit-unanswered-warn { border: 1px solid #fca5a5; background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); color: #991b1b; }
    .submit-unanswered-warn strong { font-size: 0.88rem; }
    .submit-unanswered-nums { font-size: 0.78rem; font-weight: 800; opacity: 0.95; }
    .submit-confirm-foot { padding: 0 1.35rem 1.25rem; display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: flex-end; align-items: center; }
    .submit-btn-review { border-radius: 0.65rem; padding: 0.55rem 0.9rem; font-size: 0.8rem; font-weight: 800; border: 1px solid #2563eb; color: #1d4ed8; background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%); }
    .submit-btn-review:hover { filter: brightness(0.97); }
    .submit-btn-cancel { border-radius: 0.65rem; padding: 0.55rem 0.85rem; font-size: 0.8rem; font-weight: 700; border: 1px solid #e2e8f0; color: #475569; background: #fff; }
    .submit-btn-go { border-radius: 0.65rem; padding: 0.58rem 1.05rem; font-size: 0.82rem; font-weight: 800; border: 1px solid #059669; color: #fff; background: linear-gradient(135deg, #10b981 0%, #059669 100%); box-shadow: 0 10px 22px -14px rgba(5, 150, 105, 0.9); }
    .submit-btn-go:hover { filter: brightness(1.05); }
    .submit-double-hint { margin: 0.5rem 1.35rem 0; font-size: 0.72rem; font-weight: 700; color: #b45309; }
    /* Results / review page */
    .review-result-hero {
      border-radius: 1rem;
      border: 1px solid rgba(22, 101, 160, 0.2);
      background: linear-gradient(135deg, #f0f9ff 0%, #ffffff 45%, #f8fafc 100%);
      box-shadow: 0 20px 50px -28px rgba(20, 61, 89, 0.45);
      padding: 1.35rem 1.5rem;
      margin-bottom: 1.25rem;
    }
    .review-hero-title { margin: 0; font-size: 1.35rem; font-weight: 900; color: #0c4a6e; letter-spacing: -0.02em; }
    .review-hero-sub { margin: 0.35rem 0 0; font-size: 0.88rem; color: #0369a1; font-weight: 600; }
    .review-summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.65rem; margin-top: 1.1rem; }
    @media (max-width: 1024px) { .review-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 520px) { .review-summary-grid { grid-template-columns: 1fr; } }
    .review-sum-card { border-radius: 0.75rem; border: 1px solid #e0f2fe; background: #fff; padding: 0.75rem 0.85rem; box-shadow: 0 4px 14px -8px rgba(14, 116, 144, 0.35); }
    .review-sum-k { font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.07em; color: #64748b; }
    .review-sum-v { font-size: 0.95rem; font-weight: 800; color: #0f172a; margin-top: 0.2rem; line-height: 1.3; }
    .review-mark-pass { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.55rem; border-radius: 999px; font-size: 0.75rem; font-weight: 800; background: #ecfdf5; color: #047857; border: 1px solid #86efac; }
    .review-mark-fail { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.2rem 0.55rem; border-radius: 999px; font-size: 0.75rem; font-weight: 800; background: #fff7ed; color: #c2410c; border: 1px solid #fdba74; }
    .review-locked-gate {
      margin-top: 1rem;
      border-radius: 0.9rem;
      border: 1px dashed #cbd5e1;
      background: linear-gradient(180deg, #f8fafc 0%, #fff 100%);
      padding: 1.25rem 1.35rem;
      text-align: center;
    }
    .review-locked-icon { width: 3rem; height: 3rem; margin: 0 auto 0.75rem; border-radius: 999px; background: #f1f5f9; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 1.25rem; }
    .review-locked-title { margin: 0; font-size: 1rem; font-weight: 900; color: #334155; }
    .review-locked-text { margin: 0.5rem 0 0; font-size: 0.86rem; color: #64748b; line-height: 1.55; font-weight: 600; max-width: 32rem; margin-left: auto; margin-right: auto; }
    .review-q-card { border-radius: 0.9rem; border: 1px solid #dbe7f5; background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%); box-shadow: 0 10px 28px -20px rgba(20, 61, 89, 0.4); padding: 1.15rem 1.25rem; margin-bottom: 1rem; }
    /* Always show review UI (avoid opacity:0 from dash-anim / shell motion conflicts). */
    .review-result-hero,
    .review-locked-gate,
    .review-q-card {
      opacity: 1 !important;
      transform: none !important;
      animation: none !important;
    }
    .review-no-answer-strip {
      display: flex; align-items: center; gap: 0.5rem;
      border-radius: 0.65rem; border: 1px solid #fecaca; background: linear-gradient(90deg, #fef2f2 0%, #fff 100%);
      color: #991b1b; font-size: 0.82rem; font-weight: 800; padding: 0.5rem 0.75rem; margin-bottom: 0.75rem;
    }
    .mobile-nav { display:none; }
    .exam-actions { margin-top: 1rem; display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
    .action-btn {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      min-height: 2.65rem;
      border-radius: .72rem;
      font-weight: 800;
      font-size: .86rem;
      padding: .62rem 1rem;
      transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease, background-color .2s ease;
    }
    .action-btn:disabled { opacity: .45; cursor: not-allowed; transform: none !important; box-shadow: none !important; }
    .action-btn-prev {
      border: 1px solid #cbd5e1;
      background: linear-gradient(180deg,#fff 0%,#f8fafc 100%);
      color: #334155;
      box-shadow: 0 8px 18px -20px rgba(15,23,42,.5);
    }
    .action-btn-prev:hover:not(:disabled) { transform: translateY(-1px); border-color: #94a3b8; box-shadow: 0 12px 24px -20px rgba(15,23,42,.55); }
    .action-btn-next {
      border: 1px solid #1665A0;
      background: linear-gradient(135deg,#1665A0 0%,#0d4f80 100%);
      color: #fff;
      box-shadow: 0 14px 24px -20px rgba(13,79,128,.9);
    }
    .action-btn-next:hover:not(:disabled) { transform: translateY(-1px); background: linear-gradient(135deg,#145a8f 0%,#0b436c 100%); }
    .action-btn-submit {
      border: 1px solid #059669;
      background: linear-gradient(135deg,#10b981 0%,#059669 100%);
      color: #fff;
      box-shadow: 0 14px 24px -20px rgba(5,150,105,.9);
    }
    .action-btn-submit:hover:not(:disabled) { transform: translateY(-1px); background: linear-gradient(135deg,#0ea774 0%,#047857 100%); }
    /* Subtle workspace urgency tints by timer state */
    .exam-workspace-theme { transition: background-color .25s ease, border-radius .25s ease, padding .25s ease; border-radius: .85rem; }
    .exam-workspace-theme.state-normal { background: linear-gradient(180deg, rgba(22,101,160,.03) 0%, rgba(22,101,160,0) 45%); padding: .15rem; }
    .exam-workspace-theme.state-warning { background: linear-gradient(180deg, rgba(245,158,11,.08) 0%, rgba(245,158,11,0) 46%); padding: .15rem; }
    .exam-workspace-theme.state-critical { background: linear-gradient(180deg, rgba(220,38,38,.09) 0%, rgba(220,38,38,0) 46%); padding: .15rem; }
    .exam-workspace-theme.state-warning .exam-toolbar { border-color: #fcd34d; box-shadow: 0 10px 28px -20px rgba(180,83,9,.35); }
    .exam-workspace-theme.state-warning .exam-card { border-color: #f6d58c; }
    .exam-workspace-theme.state-critical .exam-toolbar { border-color: #fca5a5; box-shadow: 0 12px 30px -20px rgba(185,28,28,.35); }
    .exam-workspace-theme.state-critical .exam-card { border-color: #fecaca; }
    .exam-no-copy { user-select: none; -webkit-user-select: none; -moz-user-select: none; }
    @media (max-width: 1023px) {
      .exam-grid { grid-template-columns: 1fr; }
      .exam-nav-panel { display:none; }
      .mobile-nav { display:flex; position: fixed; bottom: .75rem; left: .75rem; right: .75rem; z-index: 60; border:1px solid #dbe7f5; border-radius:.9rem; background:#fff; box-shadow:0 12px 25px -18px rgba(15,23,42,.6); padding:.5rem; gap:.4rem; }
      .mobile-nav button { flex:1; min-height: 2.8rem; border-radius:.7rem; font-size:.8rem; font-weight:800; }
      .question-nav-grid { grid-template-columns: repeat(8, minmax(0,1fr)); }
      .exam-workspace-theme { padding: 0; background: transparent !important; }
      .intro-meta-grid{grid-template-columns:1fr}
    }
    @media (prefers-reduced-motion: reduce) { .dash-anim { animation: none !important; opacity: 1 !important; transform: none !important; } }
  </style>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="exam-shell ereview-shell-no-fade pt-2">
    <section class="exam-hero dash-anim delay-1 px-5 py-5 mb-5">
      <a href="college_exams.php" class="focus-ring back-link inline-flex items-center gap-1 text-sm font-semibold mb-3"><i class="bi bi-arrow-left"></i> Back to exams</a>
      <h1 class="exam-title text-[1.9rem] font-extrabold m-0"><?php echo h($exam['title']); ?></h1>
      <?php if (!empty($exam['description'])): ?>
        <div class="exam-subtitle mt-2 prose prose-sm max-w-none"><?php echo ereview_render_exam_description($exam['description'], !empty($exam['description_markdown'])); ?></div>
      <?php endif; ?>
    </section>

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
      <!-- ereview-review-section -->
      <?php echo $reviewSubmittedSectionHtml; ?>
      <!-- /ereview-review-section -->
    <?php elseif ($reviewMode && $attempt && !$attemptSubmitted): ?>
      <div class="review-locked-gate">
        <div class="review-locked-icon"><i class="bi bi-hourglass-split"></i></div>
        <h2 class="review-locked-title">Results not ready to view</h2>
        <p class="review-locked-text">Your attempt is not marked as submitted yet. Return to your <a class="text-[#1665A0] font-bold underline" href="college_exams.php">exam list</a> and open the exam from there, or finish submitting if you still have time.</p>
      </div>
    <?php elseif ($reviewMode && !$attempt): ?>
      <div class="review-locked-gate">
        <div class="review-locked-icon"><i class="bi bi-journal-x"></i></div>
        <h2 class="review-locked-title">No attempt on file</h2>
        <p class="review-locked-text">We could not find an exam attempt for your account. Open this exam from your <a class="text-[#1665A0] font-bold underline" href="college_exams.php">exam list</a> to start or continue.</p>
      </div>
    <?php elseif ($showIntro): ?>
      <div class="mt-3 intro-card dash-anim delay-2 p-6">
        <div class="intro-meta-grid">
          <div class="intro-meta">
            <div class="intro-meta-k">Time limit</div>
            <div class="intro-meta-v">
              <?php if ($examTimeLimitSec <= 0): ?>
                No fixed timer
              <?php else: ?>
                <?php echo h(college_exam_human_duration($introEffectiveTimerSec !== null ? $introEffectiveTimerSec : $examTimeLimitSec)); ?>
                <?php if ($introEffectiveTimerSec !== null && $introEffectiveTimerSec < $examTimeLimitSec): ?>
                  <div class="intro-timer-cap-note">Your working time is capped by the exam deadline when you start (may be less than the full timer above).</div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="intro-meta">
            <div class="intro-meta-k">Questions</div>
            <div class="intro-meta-v"><?php echo (int)count($questions); ?> total</div>
          </div>
          <div class="intro-meta">
            <div class="intro-meta-k">Professor</div>
            <div class="intro-meta-v"><?php echo h($profName); ?></div>
          </div>
          <div class="intro-meta">
            <div class="intro-meta-k">Published on</div>
            <div class="intro-meta-v"><?php echo !empty($exam['created_at']) ? h(date('M j, Y g:i A', strtotime((string)$exam['created_at']))) : '—'; ?></div>
          </div>
          <?php if (!empty($exam['deadline'])): ?>
          <div class="intro-meta">
            <div class="intro-meta-k">Deadline</div>
            <div class="intro-meta-v" title="<?php echo h((string)$exam['deadline']); ?>"><?php echo h(date('M j, Y g:i A', strtotime((string)$exam['deadline']))); ?></div>
          </div>
          <?php endif; ?>
        </div>
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <button type="submit" name="start_exam" value="1" class="focus-ring start-btn">
            <i class="bi bi-play-fill"></i> Start exam
          </button>
        </form>
      </div>
    <?php elseif ($attempt && $attemptStatusNorm === 'in_progress'): ?>
      <div id="examWorkspaceTheme" class="exam-workspace-theme state-normal">
      <div id="offlineBanner" class="offline-banner mt-4"><i class="bi bi-wifi-off mr-1"></i> Connection lost. Retrying autosave...</div>
      <div class="exam-toolbar dash-anim delay-2 mt-1 mb-4 px-4 py-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex flex-wrap items-center gap-2">
            <span id="timeBadge" class="exam-badge time-normal"><i class="bi bi-hourglass-split mr-1"></i> Time left: <span id="timerDisplay">--:--</span></span>
            <span class="exam-badge"><span class="chip-dot bg-emerald-500"></span>Answered <span id="answeredCount">0</span>/<?php echo count($questions); ?></span>
            <span class="exam-badge"><span class="chip-dot bg-amber-500"></span>Flagged <span id="flaggedCount">0</span></span>
            <span class="exam-badge"><span class="chip-dot bg-sky-500"></span>Autosave <span id="autosaveStatus">Ready</span></span>
            <span class="exam-badge">Session <span id="sessionStatus" class="session-active">Active</span></span>
          </div>
          <div class="flex items-center gap-2">
            <button type="button" id="toggleContrastBtn" class="focus-ring px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 text-sm font-semibold hover:bg-slate-50"><i class="bi bi-circle-half"></i> Contrast</button>
            <button type="button" id="helpShortcutBtn" class="focus-ring px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 text-sm font-semibold hover:bg-slate-50"><i class="bi bi-keyboard"></i> Shortcuts</button>
            <button type="button" id="focusToggleBtn" class="focus-ring px-3 py-1.5 rounded-lg border border-slate-200 text-slate-700 text-sm font-semibold hover:bg-slate-50"><i class="bi bi-fullscreen"></i> Focus mode</button>
          </div>
        </div>
      </div>

      <div class="exam-grid dash-anim delay-3">
        <div class="exam-card p-5">
          <form id="examForm" data-attempt-id="<?php echo (int)$attempt['attempt_id']; ?>" data-csrf="<?php echo h($csrf); ?>" data-exam-id="<?php echo (int)$examId; ?>">
            <?php foreach ($questions as $index => $q): ?>
              <?php $qid = (int)$q['question_id']; $letters = ['A'=>$q['choice_a'],'B'=>$q['choice_b'],'C'=>$q['choice_c'],'D'=>$q['choice_d']]; $prev = strtoupper((string)($answersMap[$qid]['selected_answer'] ?? '')); ?>
              <section class="exam-question-panel <?php echo $index === 0 ? '' : 'hidden'; ?>" data-question-panel data-index="<?php echo $index; ?>" data-question-id="<?php echo $qid; ?>">
                <div class="flex items-start justify-between gap-3 mb-3">
                  <div>
                    <p class="question-stage-title m-0">Question <?php echo ($index + 1); ?> of <?php echo count($questions); ?></p>
                    <p class="question-stage-sub m-0">Answer carefully and use the navigator for quick jumps.</p>
                  </div>
                  <button type="button" class="focus-ring flagBtn inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-amber-300 text-amber-800 bg-amber-50 text-sm font-semibold" data-question-id="<?php echo $qid; ?>" aria-label="Flag question <?php echo ($index+1); ?>"><i class="bi bi-flag"></i> Flag</button>
                </div>
                <div class="question-text mb-4 exam-no-copy"><?php echo renderQuizRichText($q['question_text']); ?></div>
                <?php foreach ($letters as $L => $txt): if ($txt === null || $txt === '') { continue; } ?>
                  <label class="choice-row focus-ring exam-no-copy <?php echo $prev === $L ? 'is-selected' : ''; ?>" data-choice-row tabindex="0">
                    <input type="radio" name="q_<?php echo $qid; ?>" value="<?php echo h($L); ?>" class="choice-radio focus-ring" data-question-id="<?php echo $qid; ?>" aria-label="Choice <?php echo h($L); ?> for question <?php echo ($index+1); ?>" <?php echo $prev === $L ? 'checked' : ''; ?>>
                    <span class="choice-text"><span class="font-mono text-[#1665A0]"><?php echo h($L); ?>.</span> <?php echo nl2br(h($txt)); ?></span>
                  </label>
                <?php endforeach; ?>
              </section>
            <?php endforeach; ?>
          </form>
          <div class="exam-actions">
            <button type="button" id="prevBtn" class="focus-ring action-btn action-btn-prev"><i class="bi bi-arrow-left"></i> Previous</button>
            <button type="button" id="nextBtn" class="focus-ring action-btn action-btn-next"><span id="nextBtnText">Next question</span> <i id="nextBtnIcon" class="bi bi-arrow-right"></i></button>
          </div>
        </div>
        <aside class="exam-nav-panel exam-card p-4 lg:sticky lg:top-24">
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-extrabold text-[#143D59] m-0">Question navigator</h2>
            <span class="text-xs text-slate-500" id="navigatorHint">All</span>
          </div>
          <div class="flex flex-wrap gap-2 mb-3">
            <button type="button" class="focus-ring nav-filter-btn px-2.5 py-1 rounded-md border border-slate-200 text-xs font-semibold" data-filter="all">All</button>
            <button type="button" class="focus-ring nav-filter-btn px-2.5 py-1 rounded-md border border-slate-200 text-xs font-semibold" data-filter="unanswered">Unanswered</button>
            <button type="button" class="focus-ring nav-filter-btn px-2.5 py-1 rounded-md border border-slate-200 text-xs font-semibold" data-filter="flagged">Flagged</button>
          </div>
          <div id="questionNavigator" class="question-nav-grid" aria-label="Question index"></div>
        </aside>
      </div>

      <div class="mobile-nav" id="mobileNav">
        <button type="button" id="mPrevBtn" class="focus-ring border border-slate-200 bg-white text-slate-700">Prev</button>
        <button type="button" id="mIndexBtn" class="focus-ring border border-slate-200 bg-white text-slate-700">Index</button>
        <button type="button" id="mFlagBtn" class="focus-ring border border-amber-300 bg-amber-50 text-amber-800">Flag</button>
        <button type="button" id="mNextBtn" class="focus-ring bg-[#1665A0] text-white">Next</button>
      </div>

      <div id="mobileIndexDrawer" class="fixed inset-0 z-[1200] hidden bg-slate-900/45 p-4">
        <div class="w-full max-w-lg mx-auto mt-10 rounded-xl bg-white border border-slate-200 shadow-xl p-4">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-[#143D59] m-0">Question index</h3>
            <button type="button" id="closeMobileDrawerBtn" class="focus-ring px-2 py-1 rounded border border-slate-200 text-slate-700"><i class="bi bi-x-lg"></i></button>
          </div>
          <div id="mobileQuestionNavigator" class="question-nav-grid"></div>
        </div>
      </div>
      </div>

      <div id="timeUpModal" class="fixed inset-0 z-[1350] hidden items-center justify-center bg-slate-900/55 p-4 backdrop-blur-[2px]" aria-live="assertive" role="alertdialog" aria-modal="true" aria-labelledby="timeUpModalTitle">
        <div class="time-up-modal-panel w-full max-w-md p-6 text-center">
          <div class="time-up-pulse mx-auto mb-4"><i class="bi bi-hourglass-bottom text-2xl text-red-600"></i></div>
          <h3 id="timeUpModalTitle" class="m-0 text-xl font-extrabold text-red-900">Time is up</h3>
          <p class="mt-2 mb-0 text-sm text-slate-700 font-semibold">Your attempt is being submitted automatically. Please wait…</p>
          <p class="mt-3 mb-0 text-xs text-slate-500">Do not close this page until you are redirected to the results.</p>
        </div>
      </div>

      <div id="submitConfirmModal" class="submit-confirm-overlay fixed inset-0 z-[1300] hidden items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="submitConfirmTitle">
        <div class="submit-confirm-shell">
          <div class="submit-confirm-head">
            <div class="submit-confirm-icon" aria-hidden="true"><i class="bi bi-clipboard2-check"></i></div>
            <h3 id="submitConfirmTitle" class="submit-confirm-title">Final submission check</h3>
            <p class="submit-confirm-sub">Confirm your progress before you send your answers. Unanswered items are highlighted so nothing slips through.</p>
          </div>
          <div class="submit-confirm-body">
            <div class="submit-stat-grid">
              <div class="submit-stat-cell">
                <div class="submit-stat-k">Total questions</div>
                <div class="submit-stat-v"><?php echo (int)count($questions); ?></div>
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
          <p id="doubleConfirmHint" class="submit-double-hint hidden">Tap “Submit exam” again to confirm — this cannot be undone.</p>
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
            <li><span class="help-kbd">N</span> Next question</li>
            <li><span class="help-kbd">P</span> Previous question</li>
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
        var ajaxUrl = 'college_exam_ajax.php';
        var attemptId = parseInt(form.getAttribute('data-attempt-id'), 10);
        var csrf = form.getAttribute('data-csrf');
        var examId = parseInt(form.getAttribute('data-exam-id'), 10);
        var totalQuestions = <?php echo (int)count($questions); ?>;
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
        var countdown = <?php echo $remainingSeconds !== null ? (int)$remainingSeconds : 'null'; ?>;
        var timerEl = document.getElementById('timerDisplay');
        var timeBadge = document.getElementById('timeBadge');
        var autosaveStatus = document.getElementById('autosaveStatus');
        var sessionStatus = document.getElementById('sessionStatus');
        var answeredCountEl = document.getElementById('answeredCount');
        var flaggedCountEl = document.getElementById('flaggedCount');
        var offlineBanner = document.getElementById('offlineBanner');
        var navigatorEl = document.getElementById('questionNavigator');
        var mobileNavEl = document.getElementById('mobileQuestionNavigator');
        var navigatorHint = document.getElementById('navigatorHint');
        var workspaceThemeEl = document.getElementById('examWorkspaceTheme');
        var tabBlurLastSent = 0;
        var examPageReadyAt = Date.now();
        document.addEventListener('visibilitychange', function () {
          if (document.visibilityState !== 'hidden') return;
          if (Date.now() - examPageReadyAt < 1500) return;
          var now = Date.now();
          if (now - tabBlurLastSent < 2200) return;
          tabBlurLastSent = now;
          request('tab_blur', { csrf_token: csrf, attempt_id: attemptId }).catch(function () {});
        });
        document.querySelectorAll('.exam-no-copy').forEach(function (el) {
          ['copy', 'cut', 'contextmenu'].forEach(function (ev) {
            el.addEventListener(ev, function (e) { e.preventDefault(); });
          });
        });
        var nextBtn = document.getElementById('nextBtn');
        var nextBtnText = document.getElementById('nextBtnText');
        var nextBtnIcon = document.getElementById('nextBtnIcon');
        var prevBtn = document.getElementById('prevBtn');
        var mNextBtn = document.getElementById('mNextBtn');

        function setSession(kind) {
          sessionStatus.classList.remove('session-active','session-syncing','session-reconnect');
          if (kind === 'syncing') { sessionStatus.textContent = 'Syncing'; sessionStatus.classList.add('session-syncing'); }
          else if (kind === 'reconnect') { sessionStatus.textContent = 'Reconnecting'; sessionStatus.classList.add('session-reconnect'); }
          else { sessionStatus.textContent = 'Active'; sessionStatus.classList.add('session-active'); }
        }
        function setAutosave(text, error) { autosaveStatus.textContent = text; autosaveStatus.className = error ? 'text-red-700 font-semibold' : ''; }
        function markOnline(online) {
          state.online = online;
          offlineBanner.classList.toggle('is-on', !online);
          setSession(online ? 'active' : 'reconnect');
        }
        window.addEventListener('online', function(){ markOnline(true); });
        window.addEventListener('offline', function(){ markOnline(false); });
        markOnline(navigator.onLine);

        function fmtTime(sec) { if (sec === null) return '--:--'; var m = Math.floor(sec/60), s = sec%60; return m + ':' + (s<10?'0':'') + s; }
        function updateTimerVisual() {
          if (countdown === null) return;
          timeBadge.classList.remove('time-normal','time-warning','time-critical');
          if (workspaceThemeEl) workspaceThemeEl.classList.remove('state-normal','state-warning','state-critical');
          if (countdown <= 300) {
            timeBadge.classList.add('time-critical');
            if (workspaceThemeEl) workspaceThemeEl.classList.add('state-critical');
          } else if (countdown <= 900) {
            timeBadge.classList.add('time-warning');
            if (workspaceThemeEl) workspaceThemeEl.classList.add('state-warning');
          } else {
            timeBadge.classList.add('time-normal');
            if (workspaceThemeEl) workspaceThemeEl.classList.add('state-normal');
          }
        }
        function updateCounts() { answeredCountEl.textContent = String(state.answered.size); flaggedCountEl.textContent = String(state.flags.size); }
        function updatePrimaryActionUI() {
          var isLast = state.currentIndex >= (totalQuestions - 1);
          if (prevBtn) prevBtn.disabled = state.currentIndex <= 0;
          if (nextBtn && nextBtnText && nextBtnIcon) {
            nextBtn.classList.toggle('action-btn-next', !isLast);
            nextBtn.classList.toggle('action-btn-submit', isLast);
            nextBtnText.textContent = isLast ? 'Submit exam' : 'Next question';
            nextBtnIcon.className = isLast ? 'bi bi-check2-circle' : 'bi bi-arrow-right';
          }
          if (mNextBtn) {
            mNextBtn.textContent = isLast ? 'Submit' : 'Next';
            mNextBtn.classList.toggle('bg-emerald-600', isLast);
            mNextBtn.classList.toggle('bg-[#1665A0]', !isLast);
          }
        }
        function questionAt(i) { return panels[i] || null; }
        function unansweredCount() { return Math.max(0, totalQuestions - state.answered.size); }
        function syncChoiceStyles() {
          panels.forEach(function(panel){
            panel.querySelectorAll('[data-choice-row]').forEach(function(row){
              var input = row.querySelector('input[type=radio]');
              row.classList.toggle('is-selected', !!(input && input.checked));
            });
          });
        }
        function renderNav(target, forMobile) {
          target.innerHTML = '';
          panels.forEach(function(panel, idx){
            var qid = parseInt(panel.getAttribute('data-question-id'), 10);
            var answered = state.answered.has(qid), flagged = state.flags.has(qid);
            if (state.filter === 'flagged' && !flagged) return;
            if (state.filter === 'unanswered' && answered) return;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'qchip focus-ring';
            if (idx === state.currentIndex) btn.classList.add('qchip-current');
            if (answered) btn.classList.add('qchip-answered');
            if (flagged) btn.classList.add('qchip-flagged');
            btn.textContent = String(idx + 1);
            btn.addEventListener('click', function(){ setCurrentIndex(idx); if (forMobile) closeMobileDrawer(); });
            target.appendChild(btn);
          });
        }
        function renderNavigator() {
          navigatorHint.textContent = state.filter === 'all' ? 'All' : (state.filter === 'flagged' ? 'Flagged only' : 'Unanswered only');
          renderNav(navigatorEl, false);
          renderNav(mobileNavEl, true);
        }
        function setCurrentIndex(i) {
          state.currentIndex = Math.max(0, Math.min(totalQuestions - 1, i));
          panels.forEach(function(p, idx){ p.classList.toggle('hidden', idx !== state.currentIndex); });
          var cur = panels[state.currentIndex];
          if (cur) cur.scrollIntoView({ behavior: 'smooth', block: 'start' });
          renderNavigator();
          updatePrimaryActionUI();
          queueStateSync();
        }

        function request(action, payload) {
          var body = new URLSearchParams();
          body.set('action', action);
          Object.keys(payload || {}).forEach(function(k){ body.set(k, String(payload[k])); });
          return fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body }).then(function(r){ return r.json(); });
        }
        var stateTimer = null;
        function queueStateSync() {
          if (stateTimer) clearTimeout(stateTimer);
          stateTimer = setTimeout(function(){
            setSession('syncing');
            request('sync_state', { csrf_token: csrf, attempt_id: attemptId, current_index: state.currentIndex, flags: JSON.stringify(Array.from(state.flags.values())) })
              .then(function(data){
                if (!data || !data.ok) throw new Error((data && data.error) || 'Sync failed');
                setAutosave(data.saved_at ? ('Saved ' + data.saved_at) : 'Saved', false);
                setSession('active');
              })
              .catch(function(){ setAutosave('Retrying...', true); setSession('reconnect'); });
          }, 350);
        }
        function saveAnswer(qid, value) {
          setSession('syncing');
          setAutosave('Saving...', false);
          return request('save_answer', { csrf_token: csrf, attempt_id: attemptId, question_id: qid, selected_answer: value })
            .then(function(data){
              if (!data || !data.ok) throw new Error((data && data.error) || 'Save failed');
              setAutosave(data.saved_at ? ('Saved ' + data.saved_at) : 'Saved', false);
              setSession('active');
            })
            .catch(function(){ setAutosave('Retrying...', true); setSession('reconnect'); });
        }
        function openTimeUpModal() {
          var sm = document.getElementById('submitConfirmModal');
          if (sm) { sm.classList.add('hidden'); sm.classList.remove('flex'); }
          var m = document.getElementById('timeUpModal');
          if (!m) return;
          m.classList.remove('hidden');
          m.classList.add('flex');
        }
        function closeTimeUpModal() {
          var m = document.getElementById('timeUpModal');
          if (!m) return;
          m.classList.add('hidden');
          m.classList.remove('flex');
        }
        function submitNow(reason) {
          if (state.submitting) return;
          state.submitting = true;
          var isTimeout = (reason === 'timeout' || reason === 'timeout-sync');
          if (isTimeout) openTimeUpModal();
          setAutosave('Submitting...', false);
          request('submit', { csrf_token: csrf, attempt_id: attemptId }).then(function(data){
            if (!data || !data.ok) throw new Error((data && data.error) || 'Submit failed');
            window.onbeforeunload = null;
            window.location.href = 'college_take_exam.php?exam_id=' + examId + '&review=1&reason=' + encodeURIComponent(reason || 'submit');
          }).catch(function(err){
            state.submitting = false;
            closeTimeUpModal();
            alert('Could not submit exam. ' + err.message);
          });
        }

        form.querySelectorAll('input[type=radio]').forEach(function(inp){
          inp.addEventListener('change', function(){
            var qid = parseInt(inp.getAttribute('data-question-id'), 10);
            if (!qid) return;
            state.answered.add(qid);
            syncChoiceStyles(); updateCounts(); renderNavigator();
            saveAnswer(qid, inp.value);
          });
        });
        document.querySelectorAll('.flagBtn').forEach(function(btn){
          btn.addEventListener('click', function(){
            var qid = parseInt(btn.getAttribute('data-question-id'), 10);
            if (!qid) return;
            if (state.flags.has(qid)) state.flags.delete(qid); else state.flags.add(qid);
            updateCounts(); renderNavigator(); queueStateSync();
          });
        });

        document.getElementById('prevBtn').addEventListener('click', function(){ setCurrentIndex(state.currentIndex - 1); });
        document.getElementById('nextBtn').addEventListener('click', function(){
          if (state.currentIndex >= (totalQuestions - 1)) { openSubmitModal(); return; }
          setCurrentIndex(state.currentIndex + 1);
        });
        document.getElementById('mPrevBtn').addEventListener('click', function(){ setCurrentIndex(state.currentIndex - 1); });
        document.getElementById('mNextBtn').addEventListener('click', function(){
          if (state.currentIndex >= (totalQuestions - 1)) { openSubmitModal(); return; }
          setCurrentIndex(state.currentIndex + 1);
        });
        document.getElementById('mFlagBtn').addEventListener('click', function(){
          var p = questionAt(state.currentIndex); if (!p) return;
          var qid = parseInt(p.getAttribute('data-question-id'), 10); if (!qid) return;
          if (state.flags.has(qid)) state.flags.delete(qid); else state.flags.add(qid);
          updateCounts(); renderNavigator(); queueStateSync();
        });
        document.querySelectorAll('.nav-filter-btn').forEach(function(btn){
          btn.addEventListener('click', function(){
            state.filter = btn.getAttribute('data-filter') || 'all';
            document.querySelectorAll('.nav-filter-btn').forEach(function(b){ b.classList.remove('bg-slate-900','text-white'); });
            btn.classList.add('bg-slate-900','text-white');
            renderNavigator();
          });
        });

        var submitModal = document.getElementById('submitConfirmModal');
        function openSubmitModal() {
          state.submitConfirmStep = 0;
          document.getElementById('doubleConfirmHint').classList.add('hidden');
          document.getElementById('sumAnswered').textContent = String(state.answered.size);
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
              var list = [];
              panels.forEach(function (p, idx) {
                var qid = parseInt(p.getAttribute('data-question-id'), 10);
                if (!state.answered.has(qid)) list.push(idx + 1);
              });
              nums.textContent = 'Unanswered question #' + list.join(', #');
              nums.classList.remove('hidden');
            }
          }
          submitModal.classList.remove('hidden'); submitModal.classList.add('flex');
        }
        function closeSubmitModal() { submitModal.classList.add('hidden'); submitModal.classList.remove('flex'); }
        document.getElementById('closeSubmitModalBtn').addEventListener('click', closeSubmitModal);
        document.getElementById('reviewUnansweredBtn').addEventListener('click', function(){
          closeSubmitModal();
          state.filter = 'unanswered';
          document.querySelectorAll('.nav-filter-btn').forEach(function(b){ b.classList.remove('bg-slate-900','text-white'); if (b.getAttribute('data-filter') === 'unanswered') b.classList.add('bg-slate-900','text-white'); });
          renderNavigator();
          var idx = panels.findIndex(function(p){ return !state.answered.has(parseInt(p.getAttribute('data-question-id'), 10)); });
          if (idx >= 0) setCurrentIndex(idx);
        });
        document.getElementById('confirmSubmitBtn').addEventListener('click', function(){
          if (state.submitConfirmStep === 0) {
            state.submitConfirmStep = 1;
            document.getElementById('doubleConfirmHint').classList.remove('hidden');
            return;
          }
          closeSubmitModal();
          submitNow('manual');
        });

        var shortcutsModal = document.getElementById('shortcutsModal');
        function openShortcuts(){ shortcutsModal.classList.remove('hidden'); shortcutsModal.classList.add('flex'); }
        function closeShortcuts(){ shortcutsModal.classList.add('hidden'); shortcutsModal.classList.remove('flex'); }
        document.getElementById('helpShortcutBtn').addEventListener('click', openShortcuts);
        document.getElementById('closeShortcutsBtn').addEventListener('click', closeShortcuts);

        function openMobileDrawer(){ document.getElementById('mobileIndexDrawer').classList.remove('hidden'); }
        function closeMobileDrawer(){ document.getElementById('mobileIndexDrawer').classList.add('hidden'); }
        document.getElementById('mIndexBtn').addEventListener('click', openMobileDrawer);
        document.getElementById('closeMobileDrawerBtn').addEventListener('click', closeMobileDrawer);
        document.getElementById('mobileIndexDrawer').addEventListener('click', function(e){ if (e.target === e.currentTarget) closeMobileDrawer(); });

        document.getElementById('toggleContrastBtn').addEventListener('click', function(){ document.body.classList.toggle('contrast-high'); });
        document.getElementById('focusToggleBtn').addEventListener('click', function(){
          document.body.classList.toggle('focus-mode');
          if (document.fullscreenElement) document.exitFullscreen().catch(function(){});
          else document.documentElement.requestFullscreen().catch(function(){});
        });

        document.addEventListener('keydown', function(e){
          if (e.target && /input|textarea|select/i.test(e.target.tagName)) return;
          if (e.key === '?' || (e.shiftKey && e.key === '/')) { e.preventDefault(); openShortcuts(); return; }
          if (e.key === 'n' || e.key === 'N') setCurrentIndex(state.currentIndex + 1);
          if (e.key === 'p' || e.key === 'P') setCurrentIndex(state.currentIndex - 1);
          if (e.key === 'f' || e.key === 'F') {
            var panel = questionAt(state.currentIndex); if (!panel) return;
            var qid = parseInt(panel.getAttribute('data-question-id'), 10); if (!qid) return;
            if (state.flags.has(qid)) state.flags.delete(qid); else state.flags.add(qid);
            updateCounts(); renderNavigator(); queueStateSync();
          }
          if (/^[1-4]$/.test(e.key)) {
            var panel2 = questionAt(state.currentIndex); if (!panel2) return;
            var radios = panel2.querySelectorAll('input[type=radio]');
            var idx = parseInt(e.key, 10) - 1;
            if (radios[idx]) { radios[idx].checked = true; radios[idx].dispatchEvent(new Event('change', { bubbles: true })); }
          }
        });

        var leaveModal = document.getElementById('leaveConfirmModal');
        var leaveTargetUrl = '';
        function openLeaveModal(url) {
          leaveTargetUrl = url || '';
          leaveModal.classList.remove('hidden');
          leaveModal.classList.add('flex');
        }
        function closeLeaveModal() {
          leaveTargetUrl = '';
          leaveModal.classList.add('hidden');
          leaveModal.classList.remove('flex');
        }
        document.getElementById('stayOnExamBtn').addEventListener('click', closeLeaveModal);
        document.getElementById('leaveExamBtn').addEventListener('click', function(){
          state.submitting = true;
          if (leaveTargetUrl) window.location.href = leaveTargetUrl;
        });
        leaveModal.addEventListener('click', function(e){ if (e.target === e.currentTarget) closeLeaveModal(); });
        document.querySelectorAll('a[href]').forEach(function(link){
          link.addEventListener('click', function(e){
            var href = link.getAttribute('href') || '';
            if (!href || href[0] === '#' || /^javascript:/i.test(href)) return;
            if (state.submitting) return;
            if (link.hasAttribute('data-allow-leave')) return;
            e.preventDefault();
            openLeaveModal(link.href);
          });
        });

        function timerTick() {
          if (countdown === null) { timerEl.textContent = '--:--'; return; }
          if (countdown <= 0) {
            timerEl.textContent = '0:00';
            updateTimerVisual();
            submitNow('timeout');
            return;
          }
          timerEl.textContent = fmtTime(countdown);
          updateTimerVisual();
          countdown--;
          setTimeout(timerTick, 1000);
        }

        request('load_state', { csrf_token: csrf, attempt_id: attemptId }).then(function(data){
          if (data && data.ok && data.state) {
            var s = data.state;
            if (Array.isArray(s.flags)) state.flags = new Set(s.flags.map(function(v){ return parseInt(v,10); }).filter(function(v){ return v > 0; }));
            if (typeof s.current_index === 'number' && isFinite(s.current_index)) state.currentIndex = Math.max(0, Math.min(totalQuestions - 1, s.current_index));
          }
        }).catch(function(){})
          .finally(function(){
            updateCounts();
            setCurrentIndex(state.currentIndex || 0);
            syncChoiceStyles();
            updatePrimaryActionUI();
            document.querySelectorAll('.nav-filter-btn')[0].classList.add('bg-slate-900','text-white');
            timerTick();
            setInterval(function(){
              request('get_time', { attempt_id: attemptId }).then(function(data){
                if (data && data.ok && data.remaining_seconds !== null && data.remaining_seconds !== undefined) {
                  countdown = Math.max(0, parseInt(data.remaining_seconds, 10) || 0);
                  if (countdown <= 0) submitNow('timeout-sync');
                }
              }).catch(function(){});
            }, 30000);
            setInterval(function(){ queueStateSync(); }, 15000);
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
