<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';

/**
 * Safe display datetime for monitor table. PHP 8+ throws if date() gets strtotime()=false.
 *
 * @param mixed $raw
 */
function professor_exam_monitor_format_dt($raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    $s = trim((string) $raw);
    if ($s === '' || preg_match('/^0000-00-00(\s00:00:00)?$/', $s)) {
        return '';
    }
    $ts = strtotime($s);
    if ($ts === false) {
        return '';
    }

    return date('M j, Y g:i A', $ts);
}

$pageTitle = 'Exam Monitor';
$uid = (int)getCurrentUserId();
$examId = (int)($_GET['exam_id'] ?? 0);
$now = date('Y-m-d H:i:s');

if ($examId <= 0) {
    $_SESSION['message'] = 'Invalid exam selected.';
    header('Location: professor_exams.php');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM college_exams WHERE exam_id=? AND created_by=? LIMIT 1");
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_review_access') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['message'] = 'Invalid security token.';
        header('Location: professor_exam_monitor.php?exam_id=' . (int)$exam['exam_id']);
        exit;
    }
    $eidPost = (int)($_POST['exam_id'] ?? 0);
    if ($eidPost !== (int)$exam['exam_id']) {
        header('Location: professor_exams.php');
        exit;
    }
    if (!empty($_POST['clear_review_schedule'])) {
        mysqli_query(
            $conn,
            'UPDATE college_exams SET review_sheet_available_from=NULL, review_sheet_available_until=NULL WHERE exam_id=' . $eidPost . ' AND created_by=' . (int)$uid
        );
        $_SESSION['message'] = 'Review sheet access cleared. Students only see their results summary until you schedule again.';
    } else {
        $fromSql = college_exam_parse_datetime_local($_POST['review_sheet_from'] ?? '');
        $untilSql = college_exam_parse_datetime_local($_POST['review_sheet_until'] ?? '');
        if ($fromSql === null) {
            $_SESSION['message'] = 'Choose a start date and time for review access, or use Clear schedule.';
        } elseif ($untilSql !== null && $untilSql < $fromSql) {
            $_SESSION['message'] = 'End time must be on or after the start time.';
        } else {
            if ($untilSql === null) {
                $ust = mysqli_prepare(
                    $conn,
                    'UPDATE college_exams SET review_sheet_available_from=?, review_sheet_available_until=NULL WHERE exam_id=? AND created_by=?'
                );
                mysqli_stmt_bind_param($ust, 'sii', $fromSql, $eidPost, $uid);
                mysqli_stmt_execute($ust);
                mysqli_stmt_close($ust);
            } else {
                $ust = mysqli_prepare(
                    $conn,
                    'UPDATE college_exams SET review_sheet_available_from=?, review_sheet_available_until=? WHERE exam_id=? AND created_by=?'
                );
                mysqli_stmt_bind_param($ust, 'ssii', $fromSql, $untilSql, $eidPost, $uid);
                mysqli_stmt_execute($ust);
                mysqli_stmt_close($ust);
            }
            $_SESSION['message'] = 'Review sheet schedule saved.';
        }
    }
    header('Location: professor_exam_monitor.php?exam_id=' . $eidPost);
    exit;
}

$examIdSafe = (int)$examId;

college_exam_finalize_expired_in_progress($conn, $examIdSafe, 0, 0);

$examQuestionCount = 0;
$qc = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id={$examIdSafe}");
if ($qc) {
    $qrow = mysqli_fetch_assoc($qc);
    $examQuestionCount = (int)($qrow['c'] ?? 0);
    mysqli_free_result($qc);
}

// Same cohort as Exam Library / helpers: attempts on this exam ∪ approved (or unset-status) college students
$totalStudents = college_exam_professor_roster_count($conn, $examIdSafe);

$metrics = [
    'taking_count' => 0,
    'submitted_count' => 0,
    'avg_score' => null,
    'pass_count' => 0,
    'fail_count' => 0,
];
$mq = @mysqli_query($conn, "
  SELECT
    SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS taking_count,
    SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) AS submitted_count,
    AVG(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
      THEN (50 + 0.5 * (100.0 * COALESCE(correct_count,0) / total_count))
      WHEN status='submitted' THEN score END) AS avg_score,
    SUM(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
      AND COALESCE(correct_count,0) >= CEILING(total_count / 2) THEN 1
      ELSE 0 END) AS pass_count,
    SUM(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
      AND COALESCE(correct_count,0) < CEILING(total_count / 2) THEN 1
      WHEN status='submitted' AND IFNULL(total_count,0) <= 0 THEN 1
      ELSE 0 END) AS fail_count
  FROM college_exam_attempts
  WHERE exam_id=" . (int)$examId
);
if ($mq) {
    $m = mysqli_fetch_assoc($mq);
    if ($m) {
        $metrics['taking_count'] = (int)($m['taking_count'] ?? 0);
        $metrics['submitted_count'] = (int)($m['submitted_count'] ?? 0);
        $metrics['avg_score'] = $m['avg_score'] !== null ? (float)$m['avg_score'] : null;
        $metrics['pass_count'] = (int)($m['pass_count'] ?? 0);
        $metrics['fail_count'] = (int)($m['fail_count'] ?? 0);
    }
    mysqli_free_result($mq);
}

$allFinishedOpenExam = college_exam_finished_all_submitted_no_deadline($conn, $exam, (int)$metrics['submitted_count']);
$isRunning = !empty($exam['is_published'])
    && (empty($exam['available_from']) || (string)$exam['available_from'] <= $now)
    && (empty($exam['deadline']) || (string)$exam['deadline'] >= $now)
    && !$allFinishedOpenExam;
$isFinished = (!empty($exam['deadline']) && (string)$exam['deadline'] < $now) || $allFinishedOpenExam;

$students = [];
$sq = mysqli_prepare($conn, "
  SELECT
    u.user_id, u.full_name, u.email, u.student_number, u.status AS user_status,
    a.attempt_id, a.status AS attempt_status, a.score, a.correct_count, a.total_count, a.started_at, a.submitted_at, a.last_seen_at,
    a.tab_switch_count, a.last_tab_switch_at
  FROM users u
  INNER JOIN (
    SELECT user_id FROM college_exam_attempts WHERE exam_id=?
    UNION
    SELECT u2.user_id FROM users u2
    WHERE u2.role='college_student'
      AND (
        u2.status='approved' OR u2.status IS NULL OR TRIM(COALESCE(u2.status,''))=''
      )
  ) exam_roster ON exam_roster.user_id = u.user_id
  LEFT JOIN college_exam_attempts a ON a.user_id=u.user_id AND a.exam_id=?
  ORDER BY u.full_name ASC
");
mysqli_stmt_bind_param($sq, 'ii', $examId, $examId);
mysqli_stmt_execute($sq);
$sres = mysqli_stmt_get_result($sq);
if ($sres) {
    while ($row = mysqli_fetch_assoc($sres)) {
        $students[] = $row;
    }
}
mysqli_stmt_close($sq);

$totalTabLeaves = 0;
foreach ($students as $sx) {
    $totalTabLeaves += (int)($sx['tab_switch_count'] ?? 0);
}

$absentCount = 0;
if ($isFinished && $totalStudents > 0) {
    foreach ($students as $stRow) {
        if ((string)($stRow['attempt_status'] ?? '') !== 'submitted') {
            $absentCount++;
        }
    }
}

$reviewScheduleEligible = $totalStudents > 0
    && (
        $isFinished
        || ($metrics['taking_count'] === 0 && $metrics['submitted_count'] > 0 && $metrics['submitted_count'] >= $totalStudents)
    );

$reviewAccessStatus = college_exam_review_access_status($exam, $now);
$reviewFromLocal = college_exam_format_datetime_local($exam['review_sheet_available_from'] ?? null);
$reviewUntilLocal = college_exam_format_datetime_local($exam['review_sheet_available_until'] ?? null);

$monitorFlash = null;
if (!empty($_SESSION['message'])) {
    $monitorFlash = (string)$_SESSION['message'];
    unset($_SESSION['message']);
}
$monitorCsrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .prof-page { background: linear-gradient(180deg, #eefaf3 0%, #e6f6ee 45%, #edf9f2 100%); min-height: 100%; }
    .dashboard-shell { padding-bottom: 1.5rem; color: #0f172a; }
    .prof-hero { border-radius: .75rem; border: 1px solid rgba(255,255,255,.28); background: linear-gradient(130deg, #0f766e 0%, #0e9f6e 35%, #16a34a 75%, #15803d 100%); box-shadow: 0 14px 34px -20px rgba(5,46,22,.75), inset 0 1px 0 rgba(255,255,255,.22); }
    .section-title { display:flex; align-items:center; gap:.5rem; margin:0 0 .85rem; padding:.45rem .65rem; border:1px solid #d1fae5; border-radius:.62rem; background:linear-gradient(180deg,#f5fff9 0%,#fff 100%); color:#14532d; font-size:1.03rem; font-weight:800; }
    .section-title i { width:1.55rem; height:1.55rem; border-radius:.45rem; display:inline-flex; align-items:center; justify-content:center; border:1px solid #bbf7d0; background:#ecfdf3; color:#15803d; font-size:.83rem; }
    .card { border-radius:.75rem; border:1px solid rgba(22,163,74,.22); background:linear-gradient(180deg,#f4fff8 0%,#fff 50%); box-shadow:0 10px 28px -22px rgba(21,128,61,.58), 0 1px 0 rgba(255,255,255,.8) inset; }
    .status-pill { display:inline-flex; align-items:center; gap:.32rem; padding:.2rem .55rem; border-radius:999px; font-size:.72rem; font-weight:800; border:1px solid transparent; }
    .status-running { color:#065f46; background:#ecfdf5; border-color:#a7f3d0; }
    .status-finished { color:#6b21a8; background:#f5f3ff; border-color:#ddd6fe; }
    .status-waiting { color:#6b7280; background:#f8fafc; border-color:#e2e8f0; }
    .kpi-grid { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:.75rem; }
    .kpi { border:1px solid #d1fae5; border-radius:.65rem; background:#fff; padding:.8rem; }
    .kpi-k { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#64748b; font-weight:800; }
    .kpi-v { margin-top:.2rem; color:#166534; font-weight:900; font-size:1.25rem; }
    .table-head { background: linear-gradient(180deg,#edfff4 0%,#f6fff9 100%); }
    .table-head th { font-size:.74rem; text-transform:uppercase; letter-spacing:.02em; font-weight:800; color:#166534; }
    .table-row { transition: background-color .2s ease; }
    .table-row:hover { background:#f4fff8; }
    .student-status { font-size:.74rem; font-weight:800; border-radius:999px; padding:.16rem .5rem; border:1px solid transparent; display:inline-flex; align-items:center; gap:.3rem; }
    .st-taking { color:#1d4ed8; background:#eff6ff; border-color:#bfdbfe; }
    .st-done { color:#047857; background:#ecfdf5; border-color:#a7f3d0; }
    .st-none { color:#64748b; background:#f8fafc; border-color:#e2e8f0; }
    .mark-pass { color:#047857; font-weight:800; }
    .mark-fail { color:#b91c1c; font-weight:800; }
    .mark-absent { color:#92400e; font-weight:800; }
    .student-cell { display:flex; align-items:center; gap:.55rem; min-width: 220px; }
    .student-avatar { width:1.9rem; height:1.9rem; border-radius:999px; border:1px solid #bbf7d0; background:#ecfdf3; color:#166534; font-weight:900; font-size:.75rem; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
    .student-meta-name { font-weight:800; color:#14532d; line-height:1.2; }
    .student-meta-sub { font-size:.72rem; color:#64748b; line-height:1.2; margin-top:.08rem; }
    .date-chip { display:inline-flex; align-items:center; gap:.34rem; border:1px solid #d1fae5; background:#f7fff9; color:#166534; border-radius:.5rem; padding:.18rem .48rem; font-size:.73rem; font-weight:700; white-space:nowrap; }
    .date-chip.muted { border-color:#e2e8f0; background:#f8fafc; color:#64748b; }
    .score-chip { display:inline-flex; align-items:center; gap:.36rem; border:1px solid #d1fae5; border-radius:.52rem; background:#fff; padding:.18rem .48rem; font-size:.75rem; font-weight:800; color:#14532d; }
    .sec-alert-card { border:1px solid #fed7aa; border-radius:.65rem; background:linear-gradient(180deg,#fffbeb 0%,#fff 100%); padding:.85rem 1rem; }
    .sec-alert-row { display:flex; align-items:flex-start; gap:.75rem; border-bottom:1px solid #fde68a; padding:.45rem 0; font-size:.82rem; }
    .sec-alert-row:last-child { border-bottom:0; }
    .sec-alert-dot { width:.5rem; height:.5rem; border-radius:999px; background:#f59e0b; margin-top:.35rem; flex-shrink:0; }
    .tab-flash { animation: tabFlash 1.1s ease-out 1; }
    @keyframes tabFlash { 0%{ background:#fff7ed;} 100%{ background:transparent;} }
    @media (max-width: 1280px) { .kpi-grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
    @media (max-width: 1120px) { .kpi-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .review-sched-card { border-radius:.75rem; border:1px solid rgba(109,40,217,.22); background:linear-gradient(135deg,#faf5ff 0%,#fff 55%); box-shadow:0 10px 28px -22px rgba(91,33,182,.35); }
    .review-sched-title { font-size:1.02rem; font-weight:900; color:#5b21b6; margin:0 0 .35rem; display:flex; align-items:center; gap:.5rem; }
    .review-sched-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.75rem 1rem; }
    @media (max-width:720px){ .review-sched-grid { grid-template-columns:1fr; } }
    .review-sched-label { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#7c3aed; margin-bottom:.2rem; }
    .review-sched-input { width:100%; border:1px solid #ddd6fe; border-radius:.55rem; padding:.5rem .6rem; font-size:.86rem; font-weight:600; color:#1e1b4b; background:#fff; }
    .review-sched-actions { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:1rem; align-items:center; }
    .review-sched-btn { border-radius:.6rem; padding:.55rem 1rem; font-size:.82rem; font-weight:800; border:1px solid transparent; cursor:pointer; transition:transform .15s ease, box-shadow .15s ease; }
    .review-sched-btn-primary { background:linear-gradient(135deg,#7c3aed 0%,#5b21b6 100%); color:#fff; border-color:#6d28d9; box-shadow:0 8px 20px -12px rgba(91,33,182,.9); }
    .review-sched-btn-primary:hover { transform:translateY(-1px); }
    .review-sched-btn-muted { background:#fff; color:#64748b; border-color:#e2e8f0; font-weight:700; }
    .review-sched-pill { display:inline-flex; align-items:center; gap:.3rem; font-size:.72rem; font-weight:800; padding:.2rem .55rem; border-radius:999px; border:1px solid #e9d5ff; background:#f5f3ff; color:#6b21a8; }
    .pem-progress-head { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:.75rem; margin-bottom:.85rem; }
    .pem-progress-head .section-title { margin-bottom:0; flex:1 1 auto; min-width:0; }
    .pem-export-btns { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; }
    .pem-pdf-btn {
      display:inline-flex; align-items:center; gap:.4rem;
      padding:.52rem 1rem; border-radius:.6rem;
      border:1px solid #059669;
      background:linear-gradient(135deg,#10b981 0%,#059669 100%);
      color:#fff; font-size:.82rem; font-weight:800;
      text-decoration:none;
      box-shadow:0 8px 18px -12px rgba(5,150,105,.85);
      transition:transform .15s ease, filter .15s ease, box-shadow .15s ease;
      white-space:nowrap;
    }
    .pem-pdf-btn:hover { transform:translateY(-1px); filter:brightness(1.05); color:#fff; box-shadow:0 12px 22px -14px rgba(5,150,105,.75); }
    .pem-pdf-btn i { font-size:1rem; }
    .pem-xlsx-btn {
      display:inline-flex; align-items:center; gap:.4rem;
      padding:.52rem 1rem; border-radius:.6rem;
      border:1px solid #fbbf24;
      background:linear-gradient(135deg,#1e40af 0%,#2563eb 48%,#1d4ed8 100%);
      color:#fff; font-size:.82rem; font-weight:800;
      text-decoration:none;
      box-shadow:0 8px 20px -12px rgba(30,64,175,.55), inset 0 1px 0 rgba(255,255,255,.12);
      transition:transform .15s ease, filter .15s ease, box-shadow .15s ease;
      white-space:nowrap;
    }
    .pem-xlsx-btn:hover { transform:translateY(-1px); filter:brightness(1.06); color:#fff; box-shadow:0 12px 24px -14px rgba(30,64,175,.65); }
    .pem-xlsx-btn i { font-size:1rem; }
    .pem-review-btn {
      display:inline-flex; align-items:center; gap:.38rem;
      padding:.4rem .75rem; border-radius:.55rem;
      border:1px solid #059669;
      background:linear-gradient(135deg,#ecfdf5 0%,#d1fae5 100%);
      color:#065f46; font-size:.76rem; font-weight:900;
      text-decoration:none;
      box-shadow:0 4px 14px -8px rgba(5,150,105,.55);
      transition:transform .15s ease, box-shadow .15s ease, filter .15s ease;
      white-space:nowrap;
    }
    .pem-review-btn:hover { transform:translateY(-1px); filter:brightness(1.03); color:#064e3b; box-shadow:0 8px 18px -10px rgba(5,150,105,.65); }
    .pem-review-btn i { font-size:.95rem; }
    .pem-review-muted { font-size:.74rem; font-weight:700; color:#94a3b8; display:inline-flex; align-items:center; gap:.3rem; }
    .pem-review-muted.pending { color:#b45309; }
  </style>
</head>
<body class="font-sans antialiased prof-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>
  <main class="dashboard-shell w-full max-w-none">
    <?php if ($monitorFlash !== null && $monitorFlash !== ''): ?>
      <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm font-semibold"><?php echo h($monitorFlash); ?></div>
    <?php endif; ?>
    <div class="mb-6">
      <div class="prof-hero overflow-hidden">
        <div class="p-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <a href="professor_exams.php" class="inline-flex items-center gap-2 text-sm text-white/90 hover:text-white font-semibold"><i class="bi bi-arrow-left"></i> Back to Exam Library</a>
            <h1 class="text-2xl font-bold text-white m-0 mt-2"><?php echo h((string)$exam['title']); ?></h1>
            <p class="text-white/90 mt-1 mb-0">Live monitoring dashboard for this exam.</p>
          </div>
          <div>
            <?php if ($isFinished): ?>
              <span class="status-pill status-finished"><i class="bi bi-flag"></i> Finished</span>
            <?php elseif ($isRunning): ?>
              <span class="status-pill status-running"><i class="bi bi-broadcast"></i> Running</span>
            <?php else: ?>
              <span class="status-pill status-waiting"><i class="bi bi-clock"></i> Waiting</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <h2 class="section-title"><i class="bi bi-speedometer2"></i> Monitoring KPIs</h2>
    <div class="card p-4 mb-4">
      <div class="kpi-grid">
        <div class="kpi"><div class="kpi-k">Still taking</div><div class="kpi-v" id="kpiTakingCount"><?php echo (int)$metrics['taking_count']; ?></div></div>
        <div class="kpi"><div class="kpi-k">Average score</div><div class="kpi-v" id="kpiAvgScore"><?php echo $metrics['avg_score'] !== null ? h(number_format((float)$metrics['avg_score'], 2)) . '%' : '—'; ?></div></div>
        <div class="kpi"><div class="kpi-k">Passed</div><div class="kpi-v" id="kpiPassCount"><?php echo (int)$metrics['pass_count']; ?></div></div>
        <div class="kpi"><div class="kpi-k">Failed (submitted)</div><div class="kpi-v" id="kpiFailSubmitted"><?php echo (int)$metrics['fail_count']; ?></div></div>
        <div class="kpi"><div class="kpi-k">Failed (absent)</div><div class="kpi-v" id="kpiFailAbsent"><?php echo $isFinished ? (int)$absentCount : '—'; ?></div></div>
        <div class="kpi"><div class="kpi-k">Finished coverage</div><div class="kpi-v"><span id="kpiSubmittedCount"><?php echo (int)$metrics['submitted_count']; ?></span>/<span id="kpiRosterTotal"><?php echo (int)$totalStudents; ?></span></div></div>
        <div class="kpi"><div class="kpi-k">Tab leaves (total)</div><div class="kpi-v" id="kpiTabLeavesTotal"><?php echo (int)$totalTabLeaves; ?></div></div>
      </div>
      <?php if ($allFinishedOpenExam): ?>
        <div class="mt-3 text-sm font-semibold text-purple-700 bg-purple-50 border border-purple-200 rounded-lg px-3 py-2">
          There is no deadline and everyone on the roster has submitted, so this exam is treated as finished (even if it had a scheduled opening time).
        </div>
      <?php endif; ?>
    </div>

    <?php if ($reviewScheduleEligible): ?>
    <h2 class="section-title"><i class="bi bi-calendar2-check"></i> Review sheet access (students)</h2>
    <div class="review-sched-card p-4 mb-4">
      <p class="review-sched-title"><i class="bi bi-lock-fill text-violet-600"></i> Set schedule for review access</p>
      <p class="text-sm text-slate-600 m-0 mb-3">After the exam, students always see a <strong>results summary</strong>. The <strong>full question-by-question review</strong> stays locked until you choose when it opens (and optionally when it closes).</p>
      <p class="m-0 mb-3">
        <?php if ($reviewAccessStatus === 'no_schedule'): ?>
          <span class="review-sched-pill"><i class="bi bi-dash-circle"></i> No schedule set — review sheet is locked</span>
        <?php elseif ($reviewAccessStatus === 'pending'): ?>
          <span class="review-sched-pill"><i class="bi bi-hourglass-split"></i> Scheduled — opens <?php echo h(professor_exam_monitor_format_dt($exam['review_sheet_available_from'] ?? null)); ?></span>
        <?php elseif ($reviewAccessStatus === 'open'): ?>
          <span class="review-sched-pill" style="border-color:#86efac;background:#ecfdf5;color:#047857;"><i class="bi bi-unlock-fill"></i> Open now for students</span>
        <?php else: ?>
          <span class="review-sched-pill" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;"><i class="bi bi-lock-fill"></i> Window ended</span>
        <?php endif; ?>
      </p>
      <form method="post" action="professor_exam_monitor.php?exam_id=<?php echo (int)$examIdSafe; ?>" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?php echo h($monitorCsrf); ?>">
        <input type="hidden" name="action" value="save_review_access">
        <input type="hidden" name="exam_id" value="<?php echo (int)$examIdSafe; ?>">
        <div class="review-sched-grid">
          <div>
            <div class="review-sched-label">Review opens</div>
            <input class="review-sched-input" type="datetime-local" name="review_sheet_from" value="<?php echo h($reviewFromLocal); ?>" required>
          </div>
          <div>
            <div class="review-sched-label">Review closes (optional)</div>
            <input class="review-sched-input" type="datetime-local" name="review_sheet_until" value="<?php echo h($reviewUntilLocal); ?>">
            <p class="text-xs text-slate-500 m-0 mt-1">Leave empty for no end date.</p>
          </div>
        </div>
        <div class="review-sched-actions">
          <button type="submit" class="review-sched-btn review-sched-btn-primary"><i class="bi bi-check2-circle"></i> Save schedule</button>
          <button type="submit" name="clear_review_schedule" value="1" class="review-sched-btn review-sched-btn-muted" formnovalidate onclick="return confirm('Clear review schedule? Students will only see the summary until you set a new window.');"><i class="bi bi-x-lg"></i> Clear schedule</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <h2 class="section-title"><i class="bi bi-shield-exclamation"></i> Security — tab visibility</h2>
    <div class="card p-4 mb-4 sec-alert-card" id="examSecurityAlerts">
      <div class="flex items-center justify-between gap-3 mb-2">
        <span class="text-sm font-extrabold text-amber-900">Live alerts when students leave the exam tab</span>
        <span class="text-xs text-amber-800/80" id="examSecurityPollStatus">Updating…</span>
      </div>
      <div id="examSecurityFeed" class="text-amber-950">
        <?php
        $hasTabAlerts = false;
        foreach ($students as $sx) {
            if ((int)($sx['tab_switch_count'] ?? 0) > 0) {
                $hasTabAlerts = true;
                break;
            }
        }
        ?>
        <?php if (!$hasTabAlerts): ?>
          <p class="mt-1 mb-0 text-sm text-amber-900/80">No tab leaves recorded yet. Counts update every 12 seconds while this page is open.</p>
        <?php else: ?>
          <?php foreach ($students as $sx): ?>
            <?php if ((int)($sx['tab_switch_count'] ?? 0) <= 0) { continue; } ?>
            <div class="sec-alert-row" data-feed-user="<?php echo (int)$sx['user_id']; ?>">
              <span class="sec-alert-dot" aria-hidden="true"></span>
              <div>
                <strong><?php echo h((string)$sx['full_name']); ?></strong>
                left the exam tab <strong><?php echo (int)$sx['tab_switch_count']; ?></strong> time(s).
                <?php $lt = professor_exam_monitor_format_dt($sx['last_tab_switch_at'] ?? null); ?>
                Last: <?php echo $lt !== '' ? h($lt) : '—'; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="pem-progress-head">
      <h2 class="section-title"><i class="bi bi-people"></i> Student Progress</h2>
      <?php if ($isFinished): ?>
        <div class="pem-export-btns">
          <a href="professor_exam_monitor_pdf.php?exam_id=<?php echo (int)$examIdSafe; ?>" class="pem-pdf-btn" title="Download PDF (finished exams only)">
            <i class="bi bi-file-earmark-pdf"></i> Download PDF report
          </a>
          <a href="professor_exam_monitor_xlsx.php?exam_id=<?php echo (int)$examIdSafe; ?>" class="pem-xlsx-btn" title="Download Excel (finished exams only)">
            <i class="bi bi-file-earmark-spreadsheet"></i> Download Excel report
          </a>
        </div>
      <?php endif; ?>
    </div>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm text-left min-w-[1400px]">
        <thead class="table-head border-b border-green-100">
          <tr>
            <th class="px-4 py-3">Student</th>
            <th class="px-4 py-3">Student number</th>
            <th class="px-4 py-3">Email</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3">Score</th>
            <th class="px-4 py-3">Mark</th>
            <th class="px-4 py-3">Started</th>
            <th class="px-4 py-3">Submitted</th>
            <th class="px-4 py-3">Last Seen</th>
            <th class="px-4 py-3">Tab leaves</th>
            <th class="px-4 py-3">Last tab leave</th>
            <th class="px-4 py-3 whitespace-nowrap">Review sheet</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-green-100">
          <?php if ($students === []): ?>
            <tr><td colspan="12" class="px-4 py-10 text-center text-gray-500">No college students found.</td></tr>
          <?php else: ?>
            <?php foreach ($students as $st): ?>
              <?php
                $attemptStatus = (string)($st['attempt_status'] ?? '');
                $markLabel = '—';
                $markClass = '';
                $scoreColHtml = '<span class="date-chip muted">—</span>';
                if ($isFinished && $attemptStatus !== 'submitted') {
                    $markLabel = 'Failed (Absent)';
                    $markClass = 'mark-absent';
                } elseif ($attemptStatus === 'submitted') {
                    $scoreLine = college_exam_format_score_total_line(
                        isset($st['correct_count']) ? (int)$st['correct_count'] : null,
                        isset($st['total_count']) ? (int)$st['total_count'] : null,
                        $st['score'] ?? null,
                        $examQuestionCount
                    );
                    $scoreColHtml = '<span class="score-chip">' . h($scoreLine) . '</span>';
                    $isPass = college_exam_is_pass_half_correct(
                        isset($st['correct_count']) ? (int)$st['correct_count'] : null,
                        isset($st['total_count']) ? (int)$st['total_count'] : null,
                        $examQuestionCount
                    );
                    $markLabel = ($isPass ? 'Pass' : 'Fail');
                    $markClass = $isPass ? 'mark-pass' : 'mark-fail';
                }
                $tabLeaveN = (int)($st['tab_switch_count'] ?? 0);
                $tabLeaveLast = professor_exam_monitor_format_dt($st['last_tab_switch_at'] ?? null);
                $canReviewSheet = false;
                if (!empty($st['attempt_id'])) {
                    $canReviewSheet = college_exam_attempt_is_effectively_submitted([
                        'status' => $st['attempt_status'] ?? null,
                        'submitted_at' => $st['submitted_at'] ?? null,
                    ]);
                }
              ?>
              <tr class="table-row js-monitor-row" data-user-id="<?php echo (int)$st['user_id']; ?>">
                <td class="px-4 py-3">
                  <div class="student-cell">
                    <span class="student-avatar"><?php echo h(strtoupper(substr(trim((string)$st['full_name']), 0, 1) ?: 'S')); ?></span>
                    <div>
                      <div class="student-meta-name"><?php echo h((string)$st['full_name']); ?></div>
                      <div class="student-meta-sub">College student</div>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3 text-gray-600">
                  <?php $sn = trim((string)($st['student_number'] ?? '')); ?>
                  <?php if ($sn !== ''): ?>
                    <span class="date-chip"><i class="bi bi-hash"></i> <?php echo h($sn); ?></span>
                  <?php else: ?>
                    <span class="date-chip muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600">
                  <span class="date-chip muted"><i class="bi bi-envelope"></i> <?php echo h((string)$st['email']); ?></span>
                </td>
                <td class="px-4 py-3">
                  <?php if ($isFinished && $attemptStatus !== 'submitted'): ?>
                    <span class="student-status st-none" style="color:#92400e;background:#fffbeb;border-color:#fde68a"><i class="bi bi-person-x"></i> Failed (Absent)</span>
                  <?php elseif ($attemptStatus === 'in_progress'): ?>
                    <span class="student-status st-taking"><i class="bi bi-broadcast"></i> Taking</span>
                  <?php elseif ($attemptStatus === 'submitted'): ?>
                    <span class="student-status st-done"><i class="bi bi-check-circle"></i> Submitted</span>
                  <?php else: ?>
                    <span class="student-status st-none"><i class="bi bi-dash-circle"></i> Not started</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3"><?php echo $scoreColHtml; ?></td>
                <td class="px-4 py-3">
                  <?php if ($markLabel !== '—'): ?>
                    <span class="score-chip"><span class="<?php echo h($markClass); ?>"><?php echo h($markLabel); ?></span></span>
                  <?php else: ?>
                    <span class="date-chip muted">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600">
                  <?php
                    $fmtStarted = professor_exam_monitor_format_dt($st['started_at'] ?? null);
                  ?>
                  <?php if ($fmtStarted !== ''): ?>
                    <span class="date-chip"><i class="bi bi-play-circle"></i> <?php echo h($fmtStarted); ?></span>
                  <?php else: ?><span class="date-chip muted">—</span><?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600">
                  <?php
                    $fmtSubmitted = professor_exam_monitor_format_dt($st['submitted_at'] ?? null);
                  ?>
                  <?php if ($fmtSubmitted !== ''): ?>
                    <span class="date-chip"><i class="bi bi-check2-circle"></i> <?php echo h($fmtSubmitted); ?></span>
                  <?php else: ?><span class="date-chip muted">—</span><?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600">
                  <?php
                    $fmtSeen = professor_exam_monitor_format_dt($st['last_seen_at'] ?? null);
                  ?>
                  <?php if ($fmtSeen !== ''): ?>
                    <span class="date-chip"><i class="bi bi-clock-history"></i> <?php echo h($fmtSeen); ?></span>
                  <?php else: ?><span class="date-chip muted">—</span><?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600">
                  <span class="date-chip js-tab-count"><?php echo $tabLeaveN; ?></span>
                </td>
                <td class="px-4 py-3 text-gray-600">
                  <?php if ($tabLeaveLast !== ''): ?>
                    <span class="date-chip js-tab-last"><i class="bi bi-window"></i> <?php echo h($tabLeaveLast); ?></span>
                  <?php else: ?>
                    <span class="date-chip muted js-tab-last">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                  <?php if ($canReviewSheet): ?>
                    <a href="professor_exam_review_sheet.php?exam_id=<?php echo (int)$examIdSafe; ?>&amp;user_id=<?php echo (int)$st['user_id']; ?>" class="pem-review-btn" title="Open this student’s full examination sheet (questions, choices, explanations)">
                      <i class="bi bi-layout-text-window-reverse"></i> Review
                    </a>
                  <?php elseif ($attemptStatus === 'in_progress'): ?>
                    <span class="pem-review-muted pending"><i class="bi bi-hourglass-split"></i> After submit</span>
                  <?php else: ?>
                    <span class="pem-review-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
  <script>
  (function () {
    var examId = <?php echo (int)$examId; ?>;
    var pollEl = document.getElementById('examSecurityPollStatus');
    var feedEl = document.getElementById('examSecurityFeed');
    var kpiTab = document.getElementById('kpiTabLeavesTotal');
    var prevByUser = {};
    document.querySelectorAll('.js-monitor-row').forEach(function (tr) {
      var uid = tr.getAttribute('data-user-id');
      var cell = tr.querySelector('.js-tab-count');
      if (uid && cell) prevByUser[uid] = parseInt(cell.textContent, 10) || 0;
    });
    function esc(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
    }
    function tick() {
      fetch('professor_exam_monitor_live.php?exam_id=' + examId, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) throw new Error('poll');
          var elT = document.getElementById('kpiTakingCount');
          var elS = document.getElementById('kpiSubmittedCount');
          if (elT && data.taking_count !== undefined && parseInt(elT.textContent, 10) !== data.taking_count) {
            location.reload();
            return;
          }
          if (elS && data.submitted_count !== undefined && parseInt(elS.textContent, 10) !== data.submitted_count) {
            location.reload();
            return;
          }
          if ((data.auto_finalized || 0) > 0) {
            location.reload();
            return;
          }
          if (pollEl) pollEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
          if (kpiTab) kpiTab.textContent = String(data.total_tab_leaves || 0);
          var list = data.students || [];
          list.forEach(function (s) {
            var uid = String(s.user_id);
            var tr = document.querySelector('.js-monitor-row[data-user-id="' + uid + '"]');
            if (!tr) return;
            var n = parseInt(s.tab_switch_count, 10) || 0;
            var prev = prevByUser[uid] || 0;
            var countCell = tr.querySelector('.js-tab-count');
            if (countCell) countCell.textContent = String(n);
            var lastCells = tr.querySelectorAll('.js-tab-last');
            var lastTxt = s.last_tab_switch_fmt || '—';
            lastCells.forEach(function (lc) {
              if (lastTxt && lastTxt !== '—') {
                lc.className = 'date-chip js-tab-last';
                lc.innerHTML = '<i class="bi bi-window"></i> ' + esc(lastTxt);
              } else {
                lc.className = 'date-chip muted js-tab-last';
                lc.textContent = '—';
              }
            });
            if (n > prev) {
              tr.classList.remove('tab-flash');
              void tr.offsetWidth;
              tr.classList.add('tab-flash');
            }
            prevByUser[uid] = n;
          });
          var alerts = list.filter(function (x) { return (parseInt(x.tab_switch_count, 10) || 0) > 0; });
          if (feedEl) {
            if (alerts.length === 0) {
              feedEl.innerHTML = '<p class="mt-1 mb-0 text-sm text-amber-900/80">No tab leaves recorded yet. Counts update every 12 seconds while this page is open.</p>';
            } else {
              feedEl.innerHTML = alerts.map(function (a) {
                var n = parseInt(a.tab_switch_count, 10) || 0;
                var lt = a.last_tab_switch_fmt || '—';
                return '<div class="sec-alert-row" data-feed-user="' + esc(String(a.user_id)) + '"><span class="sec-alert-dot" aria-hidden="true"></span><div><strong>' +
                  esc(a.full_name || '') + '</strong> left the exam tab <strong>' + n + '</strong> time(s). Last: ' + esc(lt) + '</div></div>';
              }).join('');
            }
          }
        })
        .catch(function () {
          if (pollEl) pollEl.textContent = 'Update failed — retrying…';
        });
    }
    tick();
    setInterval(tick, 12000);
  })();
  </script>
</body>
</html>

