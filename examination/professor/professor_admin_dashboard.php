<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/examination_domain.php';

$pageTitle = 'Professor dashboard';
$csrf = generateCSRFToken();

$uid = getCurrentUserId();
$nowTs = time();

// --- Students (from professor_college_students) ---
$collegeStudents = 0;
$studentStatus = [
  'pending' => 0,
  'approved' => 0,
  'rejected' => 0,
];

$qStudents = @mysqli_query($conn, "
  SELECT status, COUNT(*) AS c
  FROM users
  WHERE role='college_student'
  GROUP BY status
");
if ($qStudents) {
  while ($r = mysqli_fetch_assoc($qStudents)) {
    $st = strtolower((string)($r['status'] ?? ''));
    $cnt = (int)($r['c'] ?? 0);
    if (array_key_exists($st, $studentStatus)) {
      $studentStatus[$st] = $cnt;
    }
    $collegeStudents += $cnt;
  }
  mysqli_free_result($qStudents);
}

// --- Examinations (unified domain) ---
$allExaminations = examination_domain_list($conn, (int)$uid, []);
$examCount = count($allExaminations);
$examPublishedCount = 0;
$examOpenCount = 0;
foreach ($allExaminations as $examRow) {
    if (!empty($examRow['is_published']) && empty($examRow['is_finished'])) {
        $examPublishedCount++;
    }
    if (!empty($examRow['is_running']) || (($examRow['window_state'] ?? '') === 'open')) {
        $examOpenCount++;
    }
}

$nextExams = [];
foreach ($allExaminations as $examRow) {
    if (!empty($examRow['is_finished'])) {
        continue;
    }
    $nextExams[] = $examRow;
}
usort($nextExams, static function (array $a, array $b): int {
    $da = $a['deadline'] ?? null;
    $db = $b['deadline'] ?? null;
    if ($da === null && $db === null) {
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    }
    if ($da === null) {
        return 1;
    }
    if ($db === null) {
        return -1;
    }

    return strcmp((string)$da, (string)$db);
});
$nextExams = array_slice($nextExams, 0, 4);

$nowSql = date('Y-m-d H:i:s');
$nowEsc = mysqli_real_escape_string($conn, $nowSql);

// --- Upload tasks (from professor_upload_tasks) ---
$taskCount = 0;
$taskOpenCount = 0;
$taskDueSoonCount = 0;

$in7Sql = date('Y-m-d H:i:s', strtotime('+7 days'));
$in7Esc = mysqli_real_escape_string($conn, $in7Sql);

$qTasks = @mysqli_query($conn, "
  SELECT
    COUNT(*) AS total_count,
    SUM(CASE WHEN is_open=1 THEN 1 ELSE 0 END) AS open_count,
    SUM(CASE
      WHEN is_open=1
       AND deadline IS NOT NULL
       AND deadline >= '{$nowEsc}'
       AND deadline <= '{$in7Esc}'
      THEN 1 ELSE 0
    END) AS due_soon_count
  FROM college_upload_tasks
  WHERE created_by=" . (int)$uid . "
");
if ($qTasks) {
  $tr = mysqli_fetch_assoc($qTasks);
  $taskCount = (int)($tr['total_count'] ?? 0);
  $taskOpenCount = (int)($tr['open_count'] ?? 0);
  $taskDueSoonCount = (int)($tr['due_soon_count'] ?? 0);
  mysqli_free_result($qTasks);
}

$nextTasks = [];
$qNextTasks = @mysqli_query($conn, "
  SELECT task_id, title, deadline, is_open
  FROM college_upload_tasks
  WHERE created_by=" . (int)$uid . "
    AND (deadline IS NULL OR deadline >= '{$nowEsc}')
  ORDER BY deadline ASC
  LIMIT 4
");
if ($qNextTasks) {
  while ($r = mysqli_fetch_assoc($qNextTasks)) {
    $nextTasks[] = $r;
  }
  mysqli_free_result($qNextTasks);
}

// --- Recent activity (from professor_monitor) ---
$attemptRows = [];
$recentAttempts = @mysqli_query($conn, "
  SELECT a.attempt_id, a.score, a.submitted_at, a.status,
         u.full_name, u.email,
         e.title AS exam_title
  FROM college_exam_attempts a
  INNER JOIN users u ON u.user_id=a.user_id AND u.role='college_student'
  INNER JOIN college_exams e ON e.exam_id=a.exam_id
  WHERE a.status='submitted'
    AND e.created_by=" . (int)$uid . "
  ORDER BY a.submitted_at DESC
  LIMIT 6
");
if ($recentAttempts) {
  while ($r = mysqli_fetch_assoc($recentAttempts)) {
    $attemptRows[] = $r;
  }
  mysqli_free_result($recentAttempts);
}

$subRows = [];
$recentSubs = @mysqli_query($conn, "
  SELECT s.submission_id, s.file_path, s.file_name, s.submitted_at, s.status,
         u.full_name, u.email,
         t.title AS task_title
  FROM college_submissions s
  INNER JOIN users u ON u.user_id=s.user_id
  INNER JOIN college_upload_tasks t ON t.task_id=s.task_id
  WHERE t.created_by=" . (int)$uid . "
  ORDER BY s.submitted_at DESC
  LIMIT 6
");
if ($recentSubs) {
  while ($r = mysqli_fetch_assoc($recentSubs)) {
    $subRows[] = $r;
  }
  mysqli_free_result($recentSubs);
}

$totalActivity = count($attemptRows) + count($subRows);
$needsAttention = ((int)$studentStatus['pending'] > 0) || ($taskDueSoonCount > 0);

// Overview charts: last 6 months activity trend + current distribution
$activityByMonth = [];
for ($i = 5; $i >= 0; $i--) {
  $ym = date('Y-m', strtotime("-{$i} months"));
  $activityByMonth[$ym] = 0;
}

$qAttemptTrend = @mysqli_query($conn, "
  SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS ym, COUNT(*) AS c
  FROM college_exam_attempts a
  INNER JOIN college_exams e ON e.exam_id=a.exam_id
  WHERE a.status='submitted'
    AND e.created_by=" . (int)$uid . "
    AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
  GROUP BY ym
");
if ($qAttemptTrend) {
  while ($r = mysqli_fetch_assoc($qAttemptTrend)) {
    $ym = (string)($r['ym'] ?? '');
    if (isset($activityByMonth[$ym])) {
      $activityByMonth[$ym] += (int)($r['c'] ?? 0);
    }
  }
  mysqli_free_result($qAttemptTrend);
}

$qSubTrend = @mysqli_query($conn, "
  SELECT DATE_FORMAT(s.submitted_at, '%Y-%m') AS ym, COUNT(*) AS c
  FROM college_submissions s
  INNER JOIN college_upload_tasks t ON t.task_id=s.task_id
  WHERE t.created_by=" . (int)$uid . "
    AND s.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
  GROUP BY ym
");
if ($qSubTrend) {
  while ($r = mysqli_fetch_assoc($qSubTrend)) {
    $ym = (string)($r['ym'] ?? '');
    if (isset($activityByMonth[$ym])) {
      $activityByMonth[$ym] += (int)($r['c'] ?? 0);
    }
  }
  mysqli_free_result($qSubTrend);
}

// Activity dataset for interactive 7/30/90 range filter
$activityDaily = [];
for ($i = 89; $i >= 0; $i--) {
  $d = date('Y-m-d', strtotime("-{$i} days"));
  $activityDaily[$d] = 0;
}
$qAttemptDaily = @mysqli_query($conn, "
  SELECT DATE(a.submitted_at) AS d, COUNT(*) AS c
  FROM college_exam_attempts a
  INNER JOIN college_exams e ON e.exam_id=a.exam_id
  WHERE a.status='submitted'
    AND e.created_by=" . (int)$uid . "
    AND a.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
  GROUP BY d
");
if ($qAttemptDaily) {
  while ($r = mysqli_fetch_assoc($qAttemptDaily)) {
    $d = (string)($r['d'] ?? '');
    if (isset($activityDaily[$d])) $activityDaily[$d] += (int)($r['c'] ?? 0);
  }
  mysqli_free_result($qAttemptDaily);
}
$qSubDaily = @mysqli_query($conn, "
  SELECT DATE(s.submitted_at) AS d, COUNT(*) AS c
  FROM college_submissions s
  INNER JOIN college_upload_tasks t ON t.task_id=s.task_id
  WHERE t.created_by=" . (int)$uid . "
    AND s.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
  GROUP BY d
");
if ($qSubDaily) {
  while ($r = mysqli_fetch_assoc($qSubDaily)) {
    $d = (string)($r['d'] ?? '');
    if (isset($activityDaily[$d])) $activityDaily[$d] += (int)($r['c'] ?? 0);
  }
  mysqli_free_result($qSubDaily);
}

$activityWindow7 = array_sum(array_slice(array_values($activityDaily), -7));
$activityWindow30 = array_sum(array_slice(array_values($activityDaily), -30));
$activityWindow90 = array_sum(array_values($activityDaily));

// KPI micro trends (this week vs previous week)
$studentsNew7 = 0; $studentsPrev7 = 0;
$qStudentTrend = @mysqli_query($conn, "
  SELECT
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS this_week,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS prev_week
  FROM users
  WHERE role='college_student'
");
if ($qStudentTrend && $r = mysqli_fetch_assoc($qStudentTrend)) {
  $studentsNew7 = (int)($r['this_week'] ?? 0);
  $studentsPrev7 = (int)($r['prev_week'] ?? 0);
  mysqli_free_result($qStudentTrend);
}

$examsNew7 = 0;
$examsPrev7 = 0;
$weekAgoTs = strtotime('-7 days');
$twoWeeksAgoTs = strtotime('-14 days');
foreach ($allExaminations as $examRow) {
    $createdTs = strtotime((string)($examRow['created_at'] ?? ''));
    if ($createdTs === false) {
        continue;
    }
    if ($createdTs >= $weekAgoTs) {
        $examsNew7++;
    } elseif ($createdTs >= $twoWeeksAgoTs && $createdTs < $weekAgoTs) {
        $examsPrev7++;
    }
}

$tasksNew7 = 0; $tasksPrev7 = 0;
$qTaskTrend = @mysqli_query($conn, "
  SELECT
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS this_week,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS prev_week
  FROM college_upload_tasks
  WHERE created_by=" . (int)$uid . "
");
if ($qTaskTrend && $r = mysqli_fetch_assoc($qTaskTrend)) {
  $tasksNew7 = (int)($r['this_week'] ?? 0);
  $tasksPrev7 = (int)($r['prev_week'] ?? 0);
  mysqli_free_result($qTaskTrend);
}

$activityPrev7 = array_sum(array_slice(array_values($activityDaily), -14, 7));
$trendText = function (int $current, int $previous): string {
  if ($current === $previous) return 'No change vs last week';
  if ($current > $previous) return '+' . ($current - $previous) . ' vs last week';
  return '-' . ($previous - $current) . ' vs last week';
};

require_once dirname(__DIR__, 2) . '/includes/format_display_name.php';

$hour = (int) date('G');
$dashGreeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$dashFirst = ereview_greeting_display_name($_SESSION['full_name'] ?? '');
$approvedSharePct = $collegeStudents > 0 ? round(($studentStatus['approved'] / $collegeStudents) * 100, 1) : 0;
$actionPendingN = (int)$studentStatus['pending'] + (int)$examOpenCount + (int)$taskDueSoonCount;
$calMonth = new DateTime('first day of this month');
$calDaysInMonth = (int) $calMonth->format('t');
$calStartPad = (int) $calMonth->format('w');
$calTitle = $calMonth->format('F Y');
$calToday = (int) date('j');
$calMarkDays = [];
foreach (array_merge($nextExams, $nextTasks) as $calItem) {
  $tsCal = strtotime((string)($calItem['deadline'] ?? ''));
  if ($tsCal && date('Y-m', $tsCal) === $calMonth->format('Y-m')) {
    $calMarkDays[(int) date('j', $tsCal)] = true;
  }
}

$pageTitle = 'Professor Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page professor-admin professor-dashboard-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>
  <?php
    $adminBreadcrumbs = [['Dashboard']];
    include dirname(__DIR__, 2) . '/includes/admin_breadcrumb.php';
  ?>

  <section class="edupro-dash" aria-label="Dashboard">
    <div class="prof-dash-hero-wrap">
    <article class="edupro-hero">
      <svg class="edupro-hero__ribbon" viewBox="0 0 720 160" preserveAspectRatio="xMaxYMid slice" aria-hidden="true" focusable="false">
        <defs>
          <linearGradient id="eduproProfHeroRibbonA" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="#38bdf8" stop-opacity="0.55"/>
            <stop offset="40%" stop-color="#60a5fa" stop-opacity="0.5"/>
            <stop offset="70%" stop-color="#818cf8" stop-opacity="0.55"/>
            <stop offset="100%" stop-color="#7c3aed" stop-opacity="0.42"/>
          </linearGradient>
          <linearGradient id="eduproProfHeroRibbonB" x1="0" y1="0" x2="1" y2="0.8">
            <stop offset="0%" stop-color="#67e8f9" stop-opacity="0.4"/>
            <stop offset="55%" stop-color="#a5b4fc" stop-opacity="0.45"/>
            <stop offset="100%" stop-color="#8b5cf6" stop-opacity="0.35"/>
          </linearGradient>
          <filter id="eduproProfHeroBlur" x="-20%" y="-40%" width="140%" height="180%">
            <feGaussianBlur stdDeviation="12"/>
          </filter>
        </defs>
        <g filter="url(#eduproProfHeroBlur)" opacity="0.95">
          <ellipse cx="560" cy="40" rx="220" ry="90" fill="url(#eduproProfHeroRibbonA)"/>
          <ellipse cx="640" cy="110" rx="180" ry="70" fill="url(#eduproProfHeroRibbonB)"/>
          <ellipse cx="480" cy="120" rx="140" ry="50" fill="#38bdf8" fill-opacity="0.28"/>
        </g>
        <path fill="url(#eduproProfHeroRibbonA)" fill-opacity="0.55" d="M250,96 C360,18 470,22 720,38 L720,160 C560,118 430,128 250,96Z"/>
        <path fill="url(#eduproProfHeroRibbonB)" fill-opacity="0.5" d="M290,112 C410,42 530,50 720,62 L720,160 C580,126 440,134 290,112Z"/>
      </svg>
      <div class="edupro-hero__left">
        <p class="edupro-greeting__date"><span class="prof-hero-dot" aria-hidden="true"></span><?php echo h(date('l, M j, Y')); ?></p>
        <h1 id="prof-dash-greeting" class="edupro-greeting__title"><span class="prof-greet-line"><?php echo h($dashGreeting); ?>,</span><span class="prof-greet-name"><?php echo h($dashFirst); ?></span></h1>
        <p class="edupro-greeting__sub">Here's what's happening across your examinations today.</p>
      </div>
      <div class="edupro-hero__right">
        <span class="edupro-quote__icon"><i class="bi bi-mortarboard"></i></span>
        <div class="edupro-quote__copy">
          <p class="edupro-quote__text">&ldquo;Education today.<br>Greater tomorrows.&rdquo;</p>
          <p class="edupro-quote__cite">Learn. Practice. Achieve.</p>
        </div>
      </div>
    </article>
    </div>
    <a href="professor_college_students" class="edupro-kpi edupro-kpi--blue no-underline" style="color:inherit" id="overview">
      <span class="edupro-icon-tile edupro-icon-tile--blue"><i class="bi bi-people-fill"></i></span>
      <div class="edupro-kpi__body">
        <div class="edupro-kpi__label">College Students</div>
        <div class="edupro-kpi__value"><?php echo (int)$collegeStudents; ?></div>
        <div class="edupro-kpi__hint"><?php echo h((string)$approvedSharePct); ?>% approved</div>
        <?php if ($collegeStudents > 0): ?>
          <span class="edupro-kpi__bar" style="--pct: <?php echo (int) $approvedSharePct; ?>" aria-hidden="true">
            <i></i>
            <span class="edupro-kpi__frac"><?php echo (int) $studentStatus['approved']; ?> / <?php echo (int) $collegeStudents; ?></span>
          </span>
        <?php endif; ?>
      </div>
    </a>
    <a href="professor_college_students?status=pending" class="edupro-kpi edupro-kpi--coral no-underline" style="color:inherit">
      <span class="edupro-icon-tile edupro-icon-tile--coral"><i class="bi bi-exclamation-triangle-fill"></i></span>
      <div class="edupro-kpi__body">
        <div class="edupro-kpi__label">Needs Review</div>
        <div class="edupro-kpi__value"><?php echo (int)$studentStatus['pending']; ?></div>
        <div class="edupro-kpi__hint"><?php echo ((int)$studentStatus['pending'] > 0) ? 'Review now' : 'No pending reviews'; ?></div>
      </div>
    </a>
    <a href="professor_examinations" class="edupro-kpi edupro-kpi--green no-underline" style="color:inherit">
      <span class="edupro-icon-tile edupro-icon-tile--green"><i class="bi bi-journal-check"></i></span>
      <div class="edupro-kpi__body">
        <div class="edupro-kpi__label">Open Examinations</div>
        <div class="edupro-kpi__value"><?php echo (int)$examOpenCount; ?></div>
        <div class="edupro-kpi__hint"><?php echo (int)$examCount; ?> total &middot; <?php echo (int)$examPublishedCount; ?> published</div>
      </div>
    </a>
    <a href="professor_examination_monitor" class="edupro-kpi edupro-kpi--violet no-underline" style="color:inherit">
      <span class="edupro-icon-tile edupro-icon-tile--violet"><i class="bi bi-activity"></i></span>
      <div class="edupro-kpi__body">
        <div class="edupro-kpi__label">Recent Activity</div>
        <div id="activityWindowCard" class="edupro-kpi__value"><?php echo (int)$activityWindow30; ?></div>
        <div class="edupro-kpi__hint">Submissions &middot; <span id="activityWindowHero"><?php echo (int)$activityWindow30; ?></span> in <span data-activity-window-label>30d</span></div>
      </div>
    </a>
    <div class="edupro-chart-main page-card prof-dash-panel prof-dash-panel--trend p-4 sm:p-5" id="insights">
      <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
          <span class="edupro-icon-tile edupro-icon-tile--blue"><i class="bi bi-bar-chart-fill"></i></span>
          <div>
            <h2 id="profDashInsightsTitle" class="page-section-title m-0">Submissions Trend</h2>
            <p class="edupro-section-sub">Exam attempts + file uploads</p>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <div class="examination-chart-toolbar prof-dash-chart-toolbar inline-flex rounded-xl bg-slate-100/70 p-1" role="group" aria-label="Activity range">
            <button class="examination-chart-range-btn rounded-lg px-2.5 py-1 text-xs font-semibold text-slate-600" type="button" data-range="7">7d</button>
            <button class="examination-chart-range-btn is-active rounded-lg bg-gradient-to-r from-blue-50 to-indigo-50 px-2.5 py-1 text-xs font-bold text-blue-700" type="button" data-range="30">30d</button>
            <button class="examination-chart-range-btn rounded-lg px-2.5 py-1 text-xs font-semibold text-slate-600" type="button" data-range="90">90d</button>
          </div>
          <span id="chartTotalBadge" class="text-xs font-semibold text-slate-500">Total: <?php echo (int)$activityWindow30; ?></span>
        </div>
      </div>
      <div class="examination-chart-wrap prof-dash-chart-wrap relative h-64">
        <canvas id="profActivityChart" aria-label="Professor activity trend"></canvas>
        <div id="profChartEmpty" class="examination-chart-empty prof-dash-chart-empty absolute inset-0 flex-col items-center justify-center text-center text-slate-500">
          <span class="prof-dash-chart-empty__icon" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></span>
          <strong>No activity yet</strong>
          <p>There is no submission activity in this time range.<br>Activity will appear once students submit exams or files.</p>
        </div>
      </div>
    </div>

    <div class="edupro-activity page-card prof-dash-panel prof-dash-panel--activity p-4" id="activity">
      <div class="mb-3 flex items-center justify-between gap-2">
        <h2 class="page-section-title m-0 text-sm font-semibold text-slate-900">Recent Activity</h2>
        <a href="professor_monitor" class="text-xs font-semibold text-blue-600 no-underline">View all</a>
      </div>
      <?php if (empty($attemptRows)): ?>
        <p class="m-0 text-xs text-slate-500">No exam submissions available yet.</p>
      <?php else: ?>
        <div class="prof-timeline">
        <?php foreach (array_slice($attemptRows, 0, 5) as $r): ?>
          <div class="prof-timeline__item">
            <span class="prof-activity-avatar" aria-hidden="true"><?php echo h(strtoupper(substr(trim((string) $r['full_name']), 0, 1))); ?></span>
            <div class="min-w-0">
              <p class="m-0 truncate text-sm font-semibold text-slate-900"><?php echo h($r['full_name']); ?></p>
              <p class="m-0 truncate text-[11px] text-slate-500"><?php echo h($r['exam_title'] ?? ''); ?></p>
            </div>
            <span class="prof-timeline__score"><?php echo ($r['score'] !== null && $r['score'] !== '') ? h((string)$r['score']) . '%' : '-'; ?></span>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="edupro-action page-card prof-dash-panel prof-dash-panel--action p-4">
      <div class="mb-3 flex items-center justify-between gap-2">
        <h2 class="page-section-title m-0 text-sm font-semibold text-slate-900">Action Center</h2>
        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700"><?php echo (int)$actionPendingN; ?> pending</span>
      </div>
      <ul class="m-0 list-none space-y-2 p-0">
        <li>
          <a class="prof-action-row" href="professor_college_students?status=pending">
            <span class="prof-action-ico prof-action-ico--amber"><i class="bi bi-hourglass-split"></i></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-slate-900">Student approvals</span>
              <span class="text-[11px] text-slate-500">Pending college students</span>
            </span>
            <span class="text-xs font-bold text-amber-700"><?php echo (int)$studentStatus['pending']; ?></span>
            <i class="bi bi-chevron-right prof-action-row__go" aria-hidden="true"></i>
          </a>
        </li>
        <li>
          <a class="prof-action-row" href="professor_examinations">
            <span class="prof-action-ico prof-action-ico--emerald"><i class="bi bi-journal-check"></i></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-slate-900">Open examinations</span>
              <span class="text-[11px] text-slate-500">Currently running or open</span>
            </span>
            <span class="text-xs font-bold text-emerald-700"><?php echo (int)$examOpenCount; ?></span>
            <i class="bi bi-chevron-right prof-action-row__go" aria-hidden="true"></i>
          </a>
        </li>
        <li>
          <a class="prof-action-row" href="professor_upload_tasks">
            <span class="prof-action-ico prof-action-ico--violet"><i class="bi bi-alarm"></i></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-slate-900">Tasks due soon</span>
              <span class="text-[11px] text-slate-500">Next 7 days</span>
            </span>
            <span class="text-xs font-bold text-violet-700"><?php echo (int)$taskDueSoonCount; ?></span>
            <i class="bi bi-chevron-right prof-action-row__go" aria-hidden="true"></i>
          </a>
        </li>
        <li>
          <a class="prof-action-row" href="professor_examination_monitor">
            <span class="prof-action-ico prof-action-ico--cyan"><i class="bi bi-broadcast"></i></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-slate-900">Open monitor</span>
              <span class="text-[11px] text-slate-500">Live exam progress</span>
            </span>
            <i class="bi bi-chevron-right prof-action-row__go" aria-hidden="true"></i>
          </a>
        </li>
      </ul>
    </div>
    <div class="edupro-bottom-card edupro-bottom-card--snapshot page-card prof-dash-panel p-4" id="upcoming">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="page-section-title m-0 text-sm font-semibold text-slate-900">Content snapshot</h2>
        <a href="professor_examinations" class="text-xs font-semibold text-blue-600 no-underline">View all</a>
      </div>
      <?php
        $snapBarMax = max(1, (int)$examCount, (int)$taskCount, (int)$collegeStudents, (int)count($subRows));
        $snapRows = [
          ['label' => 'Examinations', 'meta' => (int)$examPublishedCount . ' published', 'n' => (int)$examCount, 'tone' => 'bg-blue-500'],
          ['label' => 'Upload tasks', 'meta' => (int)$taskOpenCount . ' open', 'n' => (int)$taskCount, 'tone' => 'bg-indigo-500'],
          ['label' => 'Approved students', 'meta' => (int)$studentStatus['approved'] . ' active', 'n' => (int)$studentStatus['approved'], 'tone' => 'bg-emerald-500'],
          ['label' => 'Recent uploads', 'meta' => (int)count($subRows) . ' files', 'n' => (int)count($subRows), 'tone' => 'bg-violet-500'],
        ];
      ?>
      <div class="space-y-3">
        <?php foreach ($snapRows as $sr): ?>
          <div>
            <div class="mb-1 flex items-center justify-between gap-2">
              <span class="text-sm font-semibold text-slate-800"><?php echo h($sr['label']); ?></span>
              <span class="text-xs font-semibold text-slate-500"><?php echo h($sr['meta']); ?></span>
            </div>
            <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
              <div class="h-full rounded-full <?php echo h($sr['tone']); ?>" style="width: <?php echo (int) round(($sr['n'] / $snapBarMax) * 100); ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($subRows)): ?>
        <div class="mt-3 border-t border-slate-100 pt-3">
          <?php $uploadShown = 0; foreach ($subRows as $s): if ($uploadShown >= 2) break; ?>
            <div class="flex items-center justify-between gap-2 py-1.5">
              <div class="min-w-0">
                <p class="m-0 truncate text-sm font-semibold"><?php echo h($s['full_name']); ?></p>
                <p class="m-0 truncate text-[11px] text-slate-500"><?php echo h($s['task_title'] ?? ''); ?></p>
                <?php if (!empty($s['file_name']) && !empty($s['file_path'])): ?>
                  <a href="<?php echo h($s['file_path']); ?>" class="file-chip w-fit" target="_blank" rel="noopener"><i class="bi bi-paperclip" aria-hidden="true"></i><?php echo h($s['file_name']); ?></a>
                <?php endif; ?>
              </div>
              <span class="shrink-0 text-[11px] text-slate-400"><?php echo !empty($s['submitted_at']) ? h(date('M j', strtotime($s['submitted_at']))) : '-'; ?></span>
            </div>
          <?php $uploadShown++; endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="edupro-bottom-card edupro-bottom-card--deadlines page-card prof-dash-panel p-4">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="page-section-title m-0 text-sm font-semibold text-slate-900">Upcoming Deadlines</h2>
        <a href="professor_examinations" class="text-xs font-semibold text-blue-600 no-underline">View all</a>
      </div>
      <?php
        $deadlineItems = [];
        foreach ($nextExams as $e) {
          $deadlineItems[] = [
            'title' => (string)($e['title'] ?? ''),
            'meta' => (string)($e['exam_type_label'] ?? 'Examination'),
            'deadline' => $e['deadline'] ?? null,
            'href' => (($e['exam_type'] ?? '') === 'diagnostic')
              ? 'professor_examination_edit?exam_type=diagnostic&batch_id=' . (int)($e['source_id'] ?? 0)
              : 'professor_examination_edit?exam_type=regular&exam_id=' . (int)($e['source_id'] ?? 0),
          ];
        }
        foreach ($nextTasks as $t) {
          $deadlineItems[] = [
            'title' => (string)($t['title'] ?? ''),
            'meta' => 'Upload task',
            'deadline' => $t['deadline'] ?? null,
            'href' => 'professor_upload_tasks?edit=' . (int)($t['task_id'] ?? 0),
          ];
        }
        usort($deadlineItems, static function ($a, $b) {
          $da = $a['deadline'] ?? null;
          $db = $b['deadline'] ?? null;
          if ($da === null && $db === null) return 0;
          if ($da === null) return 1;
          if ($db === null) return -1;
          return strcmp((string)$da, (string)$db);
        });
        $deadlineItems = array_slice($deadlineItems, 0, 5);
      ?>
      <?php if (empty($deadlineItems)): ?>
        <p class="m-0 text-xs text-slate-500">No upcoming examinations or upload tasks.</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($deadlineItems as $di):
            $endTs = !empty($di['deadline']) ? strtotime((string)$di['deadline']) : null;
            $daysLeft = ($endTs && $endTs > time()) ? (int) ceil(($endTs - time()) / 86400) : 0;
          ?>
            <a href="<?php echo h($di['href']); ?>" class="flex items-center gap-3 rounded-xl bg-white/50 px-2 py-2 no-underline">
              <span class="inline-flex w-10 shrink-0 flex-col items-center rounded-lg bg-blue-50 py-1 text-blue-700">
                <span class="text-[9px] font-bold uppercase"><?php echo $endTs ? date('M', $endTs) : '--'; ?></span>
                <span class="text-sm font-bold leading-none"><?php echo $endTs ? date('j', $endTs) : ''; ?></span>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-slate-900"><?php echo h($di['title']); ?></span>
                <span class="block text-[11px] text-slate-500"><?php echo h($di['meta']); ?></span>
              </span>
              <?php if ($endTs): ?><span class="shrink-0 text-[11px] font-semibold text-blue-600"><?php echo $daysLeft; ?> days</span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="edupro-bottom-card edupro-bottom-card--calendar page-card p-4">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="page-section-title m-0 text-sm font-semibold text-slate-900"><?php echo h($calTitle); ?></h2>
        <span class="text-[11px] font-semibold text-blue-600">Today</span>
      </div>
      <div class="edupro-cal" aria-label="Calendar">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
          <div class="edupro-cal__dow"><?php echo h($dow); ?></div>
        <?php endforeach; ?>
        <?php for ($p = 0; $p < $calStartPad; $p++): ?>
          <div class="edupro-cal__day"></div>
        <?php endfor; ?>
        <?php for ($d = 1; $d <= $calDaysInMonth; $d++):
          $cls = 'edupro-cal__day';
          if ($d === $calToday) $cls .= ' is-today';
          elseif (!empty($calMarkDays[$d])) $cls .= ' is-mark';
        ?>
          <div class="<?php echo h($cls); ?>"><?php echo (int)$d; ?></div>
        <?php endfor; ?>
      </div>
    </div>
  </section>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
  (function () {
    var tabs = document.querySelectorAll('.prof-dash-tabs a[href^="#"]');
    function setActiveTab(hash) {
      tabs.forEach(function (a) {
        var on = a.getAttribute('href') === hash;
        a.classList.toggle('is-active', on);
        if (on) a.setAttribute('aria-current', 'true');
        else a.removeAttribute('aria-current');
      });
    }
    tabs.forEach(function (a) {
      a.addEventListener('click', function () {
        setActiveTab(a.getAttribute('href') || '#overview');
      });
    });
    if (location.hash) setActiveTab(location.hash);
    else setActiveTab('#overview');

    if (typeof Chart === 'undefined') return;

    var trendCanvas = document.getElementById('profActivityChart');
    if (!trendCanvas) return;

    var daily = <?php echo json_encode(array_map(function ($date, $count) { return ['date' => $date, 'count' => $count]; }, array_keys($activityDaily), array_values($activityDaily))); ?>;
    var activityWindowHero = document.getElementById('activityWindowHero');
    var activityWindowCard = document.getElementById('activityWindowCard');
    var chartTotalBadge = document.getElementById('chartTotalBadge');
    var chartEmpty = document.getElementById('profChartEmpty');
    var windowLabels = document.querySelectorAll('[data-activity-window-label]');
    var rangeButtons = document.querySelectorAll('.examination-chart-range-btn');
    var isLight = document.documentElement.getAttribute('data-admin-theme') === 'light';
    var tickColor = isLight ? '#64748b' : '#94a3b8';
    var gridColor = isLight ? 'rgba(148, 163, 184, 0.22)' : 'rgba(148, 163, 184, 0.14)';
    var lineColor = isLight ? '#246bfd' : '#60a5fa';
    var fillColor = isLight ? 'rgba(37, 99, 235, 0.10)' : 'rgba(96, 165, 250, 0.14)';

    function formatLabel(dateStr) {
      var d = new Date(dateStr + 'T00:00:00');
      return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    function getGrouped(rangeDays) {
      var windowData = daily.slice(-rangeDays);
      if (rangeDays <= 14) {
        return {
          labels: windowData.map(function (r) { return formatLabel(r.date); }),
          values: windowData.map(function (r) { return Number(r.count || 0); })
        };
      }
      var grouped = [];
      for (var i = 0; i < windowData.length; i += 7) {
        var chunk = windowData.slice(i, i + 7);
        if (!chunk.length) continue;
        var total = chunk.reduce(function (sum, item) { return sum + Number(item.count || 0); }, 0);
        grouped.push({
          label: formatLabel(chunk[0].date) + ' - ' + formatLabel(chunk[chunk.length - 1].date),
          value: total
        });
      }
      return {
        labels: grouped.map(function (g) { return g.label; }),
        values: grouped.map(function (g) { return g.value; })
      };
    }

    var initial = getGrouped(30);
    var chart = new Chart(trendCanvas, {
      type: 'line',
      data: {
        labels: initial.labels,
        datasets: [{
          label: 'Total submissions',
          data: initial.values,
          borderColor: lineColor,
          backgroundColor: fillColor,
          fill: true,
          tension: 0.35,
          borderWidth: 2.25,
          pointRadius: 2.5,
          pointHoverRadius: 5,
          pointBackgroundColor: lineColor,
          pointBorderColor: '#fff',
          pointBorderWidth: 1.5
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: isLight ? '#0f172a' : '#1e293b',
            titleColor: '#f8fafc',
            bodyColor: '#e2e8f0',
            padding: 10,
            cornerRadius: 8,
            displayColors: false
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { color: tickColor, precision: 0, font: { size: 11 } },
            grid: { color: gridColor, drawBorder: false },
            border: { display: false }
          },
          x: {
            ticks: { color: tickColor, maxRotation: 0, autoSkip: true, maxTicksLimit: 8, font: { size: 11 } },
            grid: { display: false },
            border: { display: false }
          }
        }
      }
    });

    function updateRange(rangeDays) {
      var grouped = getGrouped(rangeDays);
      var total = grouped.values.reduce(function (sum, n) { return sum + Number(n || 0); }, 0);
      chart.data.labels = grouped.labels;
      chart.data.datasets[0].data = grouped.values;
      chart.update();

      if (activityWindowHero) activityWindowHero.textContent = total;
      if (activityWindowCard) activityWindowCard.textContent = total;
      if (chartTotalBadge) chartTotalBadge.textContent = 'Total: ' + total;
      windowLabels.forEach(function (el) { el.textContent = rangeDays + 'd'; });
      if (chartEmpty) chartEmpty.classList.toggle('is-visible', total === 0);
    }

    rangeButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var rangeDays = Number(btn.getAttribute('data-range') || 30);
        rangeButtons.forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        updateRange(rangeDays);
      });
    });

    updateRange(30);

  })();
  </script>
</body>
</html>
