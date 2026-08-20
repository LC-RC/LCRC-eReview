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
$pageTitle = 'Professor Dashboard';
$adminHeroIcon = 'speedometer2';
$adminHeroTitle = 'Professor Dashboard';
$adminHeroSubtitle = 'Overview of students, examinations, submissions, and what needs attention next.';
$adminHeroActions = '<a class="admin-btn admin-btn--primary admin-btn--sm prof-dash-cta-primary" href="professor_college_students"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add student</a>'
    . '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_examinations"><i class="bi bi-journal-text" aria-hidden="true"></i> Examinations</a>'
    . '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_upload_tasks"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Upload tasks</a>';
$adminHeroMeta = ''
    . '<div class="prof-dash-hero-stats" role="list" aria-label="Dashboard snapshot">'
    .   '<div class="prof-dash-hero-stat" role="listitem">'
    .     '<span class="prof-dash-hero-stat__icon" aria-hidden="true"><i class="bi bi-people"></i></span>'
    .     '<div class="prof-dash-hero-stat__body"><span class="prof-dash-hero-stat__label">Students</span><span class="prof-dash-hero-stat__value">' . (int)$collegeStudents . '</span></div>'
    .   '</div>'
    .   '<div class="prof-dash-hero-stat" role="listitem">'
    .     '<span class="prof-dash-hero-stat__icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>'
    .     '<div class="prof-dash-hero-stat__body"><span class="prof-dash-hero-stat__label">Pending</span><span class="prof-dash-hero-stat__value">' . (int)$studentStatus['pending'] . '</span></div>'
    .   '</div>'
    .   '<div class="prof-dash-hero-stat" role="listitem">'
    .     '<span class="prof-dash-hero-stat__icon" aria-hidden="true"><i class="bi bi-journal-check"></i></span>'
    .     '<div class="prof-dash-hero-stat__body"><span class="prof-dash-hero-stat__label">Open Exams</span><span class="prof-dash-hero-stat__value">' . (int)$examOpenCount . '</span></div>'
    .   '</div>'
    .   '<div class="prof-dash-hero-stat" role="listitem">'
    .     '<span class="prof-dash-hero-stat__icon" aria-hidden="true"><i class="bi bi-alarm"></i></span>'
    .     '<div class="prof-dash-hero-stat__body"><span class="prof-dash-hero-stat__label">Tasks Due Soon</span><span class="prof-dash-hero-stat__value">' . (int)$taskDueSoonCount . '</span></div>'
    .   '</div>'
    .   '<div class="prof-dash-hero-stat" role="listitem">'
    .     '<span class="prof-dash-hero-stat__icon" aria-hidden="true"><i class="bi bi-activity"></i></span>'
    .     '<div class="prof-dash-hero-stat__body"><span class="prof-dash-hero-stat__label">Activity (<span data-activity-window-label>30d</span>)</span><span class="prof-dash-hero-stat__value" id="activityWindowHero">' . (int)$activityWindow30 . '</span></div>'
    .   '</div>'
    . '</div>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page professor-dashboard-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <div class="prof-dash-shell">
    <nav class="prof-dash-tabs examination-section-jump" aria-label="Dashboard sections">
      <a href="#overview" class="is-active">Overview</a>
      <a href="#performance">Performance</a>
      <a href="#insights">Insights</a>
      <a href="#activity">Activity</a>
      <a href="#upcoming">Upcoming</a>
    </nav>

    <span id="performance" class="prof-dash-anchor" tabindex="-1"></span>
    <section class="prof-dash-kpi-grid" id="overview" aria-label="Key metrics">
      <article class="prof-dash-kpi prof-dash-kpi--students">
        <div class="prof-dash-kpi__icon" aria-hidden="true"><i class="bi bi-people"></i></div>
        <p class="prof-dash-kpi__label">College Students</p>
        <p class="prof-dash-kpi__value"><?php echo (int)$collegeStudents; ?></p>
        <dl class="prof-dash-kpi__meta">
          <div><dt>Approved</dt><dd><?php echo (int)$studentStatus['approved']; ?></dd></div>
          <div><dt>Pending</dt><dd><?php echo (int)$studentStatus['pending']; ?></dd></div>
        </dl>
        <a href="professor_college_students" class="prof-dash-kpi__link">View students <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </article>
      <article class="prof-dash-kpi prof-dash-kpi--exams">
        <div class="prof-dash-kpi__icon" aria-hidden="true"><i class="bi bi-journal-text"></i></div>
        <p class="prof-dash-kpi__label">Examinations</p>
        <p class="prof-dash-kpi__value"><?php echo (int)$examCount; ?></p>
        <dl class="prof-dash-kpi__meta">
          <div><dt>Published</dt><dd><?php echo (int)$examPublishedCount; ?></dd></div>
          <div><dt>Open</dt><dd><?php echo (int)$examOpenCount; ?></dd></div>
        </dl>
        <a href="professor_examinations" class="prof-dash-kpi__link">Manage examinations <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </article>
      <article class="prof-dash-kpi prof-dash-kpi--tasks">
        <div class="prof-dash-kpi__icon" aria-hidden="true"><i class="bi bi-cloud-arrow-up"></i></div>
        <p class="prof-dash-kpi__label">Upload Tasks</p>
        <p class="prof-dash-kpi__value"><?php echo (int)$taskCount; ?></p>
        <dl class="prof-dash-kpi__meta">
          <div><dt>Open</dt><dd><?php echo (int)$taskOpenCount; ?></dd></div>
          <div><dt>Due soon</dt><dd><?php echo (int)$taskDueSoonCount; ?></dd></div>
        </dl>
        <a href="professor_upload_tasks" class="prof-dash-kpi__link">View tasks <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </article>
      <article class="prof-dash-kpi prof-dash-kpi--activity">
        <div class="prof-dash-kpi__icon" aria-hidden="true"><i class="bi bi-graph-up"></i></div>
        <p class="prof-dash-kpi__label">Recent Activity</p>
        <p class="prof-dash-kpi__value" id="activityWindowCard"><?php echo (int)$activityWindow30; ?></p>
        <p class="prof-dash-kpi__hint">Submissions in selected window</p>
        <a href="professor_examination_monitor" class="prof-dash-kpi__link">Open monitor <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </article>
    </section>

    <section class="prof-dash-insights" id="insights" aria-labelledby="profDashInsightsTitle">
      <div class="prof-dash-insights__head">
        <div>
          <p class="prof-dash-eyebrow">Insights</p>
          <h2 id="profDashInsightsTitle" class="prof-dash-insights__title">Submissions Trend</h2>
          <p class="prof-dash-insights__sub">Exam attempts + file uploads over the last 6 months.</p>
        </div>
        <div class="prof-dash-insights__controls">
          <div class="examination-chart-toolbar prof-dash-chart-toolbar" role="group" aria-label="Activity range">
            <button class="examination-chart-range-btn" type="button" data-range="7">7d</button>
            <button class="examination-chart-range-btn is-active" type="button" data-range="30">30d</button>
            <button class="examination-chart-range-btn" type="button" data-range="90">90d</button>
          </div>
          <span id="chartTotalBadge" class="prof-dash-chart-total">Total: <?php echo (int)$activityWindow30; ?></span>
        </div>
      </div>
      <div class="examination-chart-wrap prof-dash-chart-wrap">
        <canvas id="profActivityChart" aria-label="Professor activity trend"></canvas>
        <div id="profChartEmpty" class="examination-chart-empty prof-dash-chart-empty">
          <span class="prof-dash-chart-empty__icon" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></span>
          <strong>No activity yet</strong>
          <p>There is no submission activity in this time range.<br>Activity will appear once students submit exams or files.</p>
        </div>
      </div>
    </section>

    <h2 id="activity" class="prof-dash-section-title"><i class="bi bi-clipboard-data" aria-hidden="true"></i> Activity And Submissions</h2>
    <section class="prof-dash-activity-grid">
      <!-- Recent exam attempts -->
      <div class="prof-dash-panel lg:col-span-2">
        <div class="prof-dash-panel__head">
          <div>
            <h3 class="prof-dash-panel__title">Recent exam results</h3>
            <p class="prof-dash-panel__sub">Latest scores from your exams</p>
          </div>
          <a href="professor_monitor" class="prof-dash-panel__action">
            View monitor <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </a>
        </div>

        <div class="table-shell overflow-x-auto">
          <table class="prof-dash-table w-full text-sm text-left">
            <thead>
              <tr>
                <th scope="col">Student</th>
                <th scope="col">Exam</th>
                <th scope="col">Score</th>
                <th scope="col">Submitted</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($attemptRows)): ?>
                <tr>
                  <td colspan="4" class="prof-dash-empty-cell">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    <div class="font-medium">No exam submissions available yet.</div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($attemptRows as $r): ?>
                  <tr>
                    <td class="prof-dash-table__strong"><?php echo h($r['full_name']); ?></td>
                    <td><?php echo h($r['exam_title'] ?? ''); ?></td>
                    <td class="prof-dash-table__score"><?php echo ($r['score'] !== null && $r['score'] !== '') ? h((string)$r['score']) . '%' : '-'; ?></td>
                    <td class="prof-dash-table__muted"><?php echo !empty($r['submitted_at']) ? h(date('M j, g:i A', strtotime($r['submitted_at']))) : '-'; ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Recent file submissions -->
      <div class="prof-dash-panel">
        <div class="prof-dash-panel__head">
          <div>
            <h3 class="prof-dash-panel__title">Latest file uploads</h3>
            <p class="prof-dash-panel__sub">Quick upload overview and latest submission activity.</p>
          </div>
          <a href="professor_upload_tasks" class="prof-dash-panel__action">
            Manage uploads <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </a>
        </div>

        <div class="prof-dash-upload-overview">
          <div class="prof-dash-mini-stat">
            <p class="prof-dash-mini-stat__label">Total recent uploads</p>
            <p class="prof-dash-mini-stat__value"><?php echo (int)count($subRows); ?></p>
          </div>
          <div class="prof-dash-mini-stat">
            <p class="prof-dash-mini-stat__label">Upload tasks</p>
            <p class="prof-dash-mini-stat__value"><?php echo (int)$taskCount; ?></p>
          </div>
          <div class="prof-dash-mini-stat">
            <p class="prof-dash-mini-stat__label">Open tasks</p>
            <p class="prof-dash-mini-stat__value"><?php echo (int)$taskOpenCount; ?></p>
          </div>
          <div class="prof-dash-mini-stat">
            <p class="prof-dash-mini-stat__label">Due soon</p>
            <p class="prof-dash-mini-stat__value<?php echo $taskDueSoonCount > 0 ? ' is-warn' : ''; ?>"><?php echo (int)$taskDueSoonCount; ?></p>
          </div>
        </div>

        <div class="examination-upload-feed">
          <?php if (empty($subRows)): ?>
            <div class="prof-dash-empty-inline">
              <i class="bi bi-folder2-open" aria-hidden="true"></i>
              <div class="font-medium">No file submissions available yet.</div>
            </div>
          <?php else: ?>
            <?php $uploadShown = 0; ?>
            <?php foreach ($subRows as $s): ?>
              <?php if ($uploadShown >= 3) break; ?>
              <div class="examination-upload-feed-item">
                <div class="flex items-start justify-between gap-2">
                  <div class="min-w-0">
                    <p class="font-semibold m-0 truncate"><?php echo h($s['full_name']); ?></p>
                    <p class="text-xs opacity-70 m-0 mt-0.5 truncate"><?php echo h($s['task_title'] ?? ''); ?></p>
                    <?php if (!empty($s['file_name']) && !empty($s['file_path'])): ?>
                      <a href="<?php echo h($s['file_path']); ?>" class="file-chip w-fit" target="_blank" rel="noopener">
                        <i class="bi bi-paperclip" aria-hidden="true"></i><?php echo h($s['file_name']); ?>
                      </a>
                    <?php endif; ?>
                  </div>
                  <span class="text-[11px] opacity-60 shrink-0">
                    <?php echo !empty($s['submitted_at']) ? h(date('M j, g:i A', strtotime($s['submitted_at']))) : '-'; ?>
                  </span>
                </div>
              </div>
              <?php $uploadShown++; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="prof-dash-panel__footer">
          <a href="professor_monitor"><i class="bi bi-eye" aria-hidden="true"></i> View all file activity</a>
        </div>
      </div>
    </section>

    <h2 id="upcoming" class="prof-dash-section-title"><i class="bi bi-calendar-event" aria-hidden="true"></i> Upcoming Work</h2>
    <section class="prof-dash-upcoming-grid">
      <!-- Upcoming exams -->
      <div class="prof-dash-panel">
        <div class="prof-dash-panel__head">
          <div>
            <h3 class="prof-dash-panel__title">Upcoming examinations</h3>
            <p class="prof-dash-panel__sub">Deadlines for regular and diagnostic examinations</p>
          </div>
        </div>
        <div class="prof-dash-panel__body">
          <?php if (empty($nextExams)): ?>
            <div class="prof-dash-empty-inline">
              <i class="bi bi-calendar-week" aria-hidden="true"></i>
              <div class="font-medium">No upcoming examinations scheduled.</div>
              <a href="professor_examination_edit" class="admin-btn admin-btn--primary admin-btn--sm mt-3"><i class="bi bi-plus-circle" aria-hidden="true"></i> Create an examination</a>
            </div>
          <?php else: ?>
            <div class="prof-dash-tile-list">
              <?php foreach ($nextExams as $e): ?>
                <?php
                  $examDeadlineTs = !empty($e['deadline']) ? strtotime((string)$e['deadline']) : null;
                  $isExamDueSoon = $examDeadlineTs !== false && $examDeadlineTs !== null && $examDeadlineTs >= $nowTs && $examDeadlineTs <= ($nowTs + (2 * 86400));
                  $upcomingHref = ($e['exam_type'] ?? '') === 'diagnostic'
                    ? 'professor_examination_edit?exam_type=diagnostic&batch_id=' . (int)($e['source_id'] ?? 0)
                    : 'professor_examination_edit?exam_type=regular&exam_id=' . (int)($e['source_id'] ?? 0);
                  $examMetaParts = [];
                  $examMetaParts[] = (string)($e['exam_type_label'] ?? 'Examination');
                  $examMetaParts[] = !empty($e['deadline']) ? date('M j, Y g:i A', strtotime((string)$e['deadline'])) : 'No deadline';
                ?>
                <a href="<?php echo h($upcomingHref); ?>" class="prof-dash-tile group">
                  <div class="prof-dash-tile__main">
                    <div class="prof-dash-tile__icon prof-dash-tile__icon--exam" aria-hidden="true"><i class="bi bi-journal-richtext"></i></div>
                    <div class="min-w-0">
                      <p class="prof-dash-tile__title"><?php echo h($e['title'] ?? ''); ?></p>
                      <p class="prof-dash-tile__meta"><?php echo h(implode(' | ', $examMetaParts)); ?></p>
                    </div>
                  </div>
                  <div class="prof-dash-tile__side">
                    <span class="prof-dash-badge<?php echo $isExamDueSoon ? ' is-warn' : ''; ?>">
                      <?php echo $isExamDueSoon ? 'Due Soon' : 'Upcoming'; ?>
                    </span>
                    <i class="bi bi-arrow-right prof-dash-tile__chev" aria-hidden="true"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Upcoming tasks -->
      <div class="prof-dash-panel">
        <div class="prof-dash-panel__head">
          <div>
            <h3 class="prof-dash-panel__title">Upcoming upload tasks</h3>
            <p class="prof-dash-panel__sub">Deadlines for student submissions</p>
          </div>
        </div>
        <div class="prof-dash-panel__body">
          <?php if (empty($nextTasks)): ?>
            <div class="prof-dash-empty-inline">
              <i class="bi bi-calendar2-check" aria-hidden="true"></i>
              <div class="font-medium">No upcoming upload tasks.</div>
              <a href="professor_upload_tasks" class="admin-btn admin-btn--primary admin-btn--sm mt-3"><i class="bi bi-plus-circle" aria-hidden="true"></i> Create an upload task</a>
            </div>
          <?php else: ?>
            <div class="prof-dash-tile-list">
              <?php foreach ($nextTasks as $t): ?>
                <?php
                  $taskDeadlineTs = !empty($t['deadline']) ? strtotime((string)$t['deadline']) : null;
                  $isTaskDueSoon = $taskDeadlineTs !== false && $taskDeadlineTs !== null && $taskDeadlineTs >= $nowTs && $taskDeadlineTs <= ($nowTs + (2 * 86400));
                ?>
                <a href="professor_upload_tasks?edit=<?php echo (int)($t['task_id'] ?? 0); ?>" class="prof-dash-tile group">
                  <div class="prof-dash-tile__main">
                    <div class="prof-dash-tile__icon prof-dash-tile__icon--task" aria-hidden="true"><i class="bi bi-folder2"></i></div>
                    <div class="min-w-0">
                      <p class="prof-dash-tile__title"><?php echo h($t['title'] ?? ''); ?></p>
                      <p class="prof-dash-tile__meta"><?php echo !empty($t['deadline']) ? h(date('M j, Y g:i A', strtotime($t['deadline']))) : 'No deadline'; ?></p>
                    </div>
                  </div>
                  <div class="prof-dash-tile__side">
                    <span class="prof-dash-badge<?php echo $isTaskDueSoon ? ' is-warn' : ''; ?>">
                      <?php echo $isTaskDueSoon ? 'Due Soon' : 'Upcoming'; ?>
                    </span>
                    <i class="bi bi-arrow-right prof-dash-tile__chev" aria-hidden="true"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>

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
    var lineColor = isLight ? '#2563eb' : '#60a5fa';
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
