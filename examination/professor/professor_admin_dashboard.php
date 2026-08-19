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
$adminHeroActions = '<a class="admin-btn admin-btn--primary admin-btn--sm" href="professor_college_students"><i class="bi bi-person-plus"></i> Add student</a>'
    . '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_examinations"><i class="bi bi-journal-text"></i> Examinations</a>'
    . '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_upload_tasks"><i class="bi bi-cloud-arrow-up"></i> Upload tasks</a>';
$adminHeroMeta = '<span class="text-sm opacity-80">Students: <strong>' . (int)$collegeStudents . '</strong> Â· Pending: <strong>' . (int)$studentStatus['pending'] . '</strong> Â· Open exams: <strong>' . (int)$examOpenCount . '</strong> Â· Tasks due soon: <strong>' . (int)$taskDueSoonCount . '</strong> Â· Activity (<span data-activity-window-label>30d</span>): <strong id="activityWindowHero">' . (int)$activityWindow30 . '</strong></span>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

    <nav class="examination-section-jump" aria-label="Dashboard sections">
      <a href="#overview">Overview</a>
      <a href="#performance">Performance</a>
      <a href="#insights">Insights</a>
      <a href="#activity">Activity</a>
      <a href="#upcoming">Upcoming</a>
    </nav>
    <section class="examination-kpi-grid mb-4" id="performance">
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">College students</div><div class="examination-kpi-card__value"><?php echo (int)$collegeStudents; ?></div><div class="examination-kpi-card__meta">Approved: <?php echo (int)$studentStatus['approved']; ?> · Pending: <?php echo (int)$studentStatus['pending']; ?></div><a href="professor_college_students" class="admin-btn admin-btn--ghost admin-btn--sm mt-3"><i class="bi bi-arrow-right"></i> View students</a></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Examinations</div><div class="examination-kpi-card__value"><?php echo (int)$examCount; ?></div><div class="examination-kpi-card__meta">Published: <?php echo (int)$examPublishedCount; ?> · Open: <?php echo (int)$examOpenCount; ?></div><a href="professor_examinations" class="admin-btn admin-btn--ghost admin-btn--sm mt-3"><i class="bi bi-arrow-right"></i> Manage examinations</a></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Upload tasks</div><div class="examination-kpi-card__value"><?php echo (int)$taskCount; ?></div><div class="examination-kpi-card__meta">Open: <?php echo (int)$taskOpenCount; ?> · Due soon: <?php echo (int)$taskDueSoonCount; ?></div><a href="professor_upload_tasks" class="admin-btn admin-btn--ghost admin-btn--sm mt-3"><i class="bi bi-arrow-right"></i> View tasks</a></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Recent activity</div><div class="examination-kpi-card__value" id="activityWindowCard"><?php echo (int)$activityWindow30; ?></div><div class="examination-kpi-card__meta">Submissions in selected window</div><a href="professor_examination_monitor" class="admin-btn admin-btn--ghost admin-btn--sm mt-3"><i class="bi bi-arrow-right"></i> Open monitor</a></div>
    </section>

    <h2 id="insights" class="examination-section-title "><i class="bi bi-graph-up-arrow"></i> Insights</h2>
    <section class="insights-grid mb-6">
      <div class="rounded-xl overflow-hidden page-table chart-card dash-anim delay-3 bg-white p-5">
        <div class="flex items-center justify-between gap-3 mb-3">
          <div>
            <h3 class="text-lg font-bold  m-0">Submissions trend</h3>
            <p class="text-sm text-gray-500 m-0 mt-1">Exam attempts + file uploads over the last 6 months.</p>
          </div>
          <div class="flex items-center gap-2">
            <div class="examination-chart-toolbar" role="group" aria-label="Activity range">
              <button class="examination-chart-range-btn" type="button" data-range="7">7d</button>
              <button class="examination-chart-range-btn is-active" type="button" data-range="30">30d</button>
              <button class="examination-chart-range-btn" type="button" data-range="90">90d</button>
            </div>
            <span id="chartTotalBadge" class="text-xs font-semibold px-2.5 py-1 rounded-full  border border-green-200 ">
              Total: <?php echo (int)$activityWindow30; ?>
            </span>
          </div>
        </div>
        <div class="examination-chart-wrap">
          <canvas id="profActivityChart" aria-label="Professor activity trend"></canvas>
          <div id="profChartEmpty" class="examination-chart-empty">No activity yet in this time range.<br>Activity will appear once students submit exams or files.</div>
        </div>
      </div>
    </section>

    <h2 id="activity" class="examination-section-title "><i class="bi bi-clipboard-data"></i> Activity And Submissions</h2>
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
      <!-- Recent exam attempts -->
      <div class="lg:col-span-2 rounded-xl overflow-hidden page-table dash-anim delay-3 bg-white overflow-hidden">
        <div class="table-card-head px-6 py-5 border-b border-[var(--admin-border)] flex items-center justify-between gap-3">
          <div>
            <h2 class="text-lg font-bold  m-0">Recent exam results</h2>
            <p class="text-sm text-gray-500 m-0 mt-1">Latest scores from your exams</p>
          </div>
          <a href="professor_monitor" class=" font-semibold hover:underline inline-flex items-center gap-1">
            View monitor <i class="bi bi-arrow-right"></i>
          </a>
        </div>

        <div class="table-shell overflow-x-auto">
          <table class="w-full text-sm text-left">
            <thead class="  font-semibold">
              <tr>
                <th class="px-6 py-3.5">Student</th>
                <th class="px-6 py-3.5">Exam</th>
                <th class="px-6 py-3.5">Score</th>
                <th class="px-6 py-3.5">Submitted</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--admin-border)]">
              <?php if (empty($attemptRows)): ?>
                <tr>
                  <td colspan="4" class="px-6 py-12 text-center empty-state">
                    <i class="bi bi-file-earmark-text"></i>
                    <div class="font-medium">No exam submissions available yet.</div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($attemptRows as $r): ?>
                  <tr class="result-row hover:/80 transition-colors">
                    <td class="px-6 py-3.5 font-semibold text-gray-800"><?php echo h($r['full_name']); ?></td>
                    <td class="px-6 py-3.5 text-gray-700"><?php echo h($r['exam_title'] ?? ''); ?></td>
                    <td class="px-6 py-3.5 font-bold "><?php echo ($r['score'] !== null && $r['score'] !== '') ? h((string)$r['score']) . '%' : '-'; ?></td>
                    <td class="px-6 py-3.5 text-gray-600"><?php echo !empty($r['submitted_at']) ? h(date('M j, g:i A', strtotime($r['submitted_at']))) : '-'; ?><i class="bi bi-arrow-right table-row-caret"></i></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Recent file submissions -->
      <div class="rounded-xl overflow-hidden page-table dash-anim delay-4 bg-white overflow-hidden">
        <div class="table-card-head px-6 py-5 border-b border-[var(--admin-border)] flex items-center justify-between gap-3">
          <div>
            <h2 class="text-lg font-bold  m-0">Latest file uploads</h2>
            <p class="text-sm text-gray-500 m-0 mt-1">Quick upload overview and latest submission activity.</p>
          </div>
          <a href="professor_upload_tasks" class=" font-semibold hover:underline inline-flex items-center gap-1">
            Manage uploads <i class="bi bi-arrow-right"></i>
          </a>
        </div>

        <div class="examination-upload-overview">
          <div class="rounded-xl border p-3">
            <p class="text-xs text-gray-500 m-0">Total recent uploads</p>
            <p class="text-xl font-extrabold  m-0 mt-1"><?php echo (int)count($subRows); ?></p>
          </div>
          <div class="rounded-xl border p-3">
            <p class="text-xs text-gray-500 m-0">Upload tasks</p>
            <p class="text-xl font-extrabold  m-0 mt-1"><?php echo (int)$taskCount; ?></p>
          </div>
          <div class="rounded-xl border p-3">
            <p class="text-xs text-gray-500 m-0">Open tasks</p>
            <p class="text-xl font-extrabold  m-0 mt-1"><?php echo (int)$taskOpenCount; ?></p>
          </div>
          <div class="rounded-xl border p-3">
            <p class="text-xs text-gray-500 m-0">Due soon</p>
            <p class="text-xl font-extrabold <?php echo $taskDueSoonCount > 0 ? 'text-amber-700' : ''; ?> m-0 mt-1"><?php echo (int)$taskDueSoonCount; ?></p>
          </div>
        </div>

        <div class="examination-upload-feed">
          <?php if (empty($subRows)): ?>
            <div class="py-8 text-center empty-state">
              <i class="bi bi-folder2-open"></i>
              <div class="font-medium">No file submissions available yet.</div>
            </div>
          <?php else: ?>
            <?php $uploadShown = 0; ?>
            <?php foreach ($subRows as $s): ?>
              <?php if ($uploadShown >= 3) break; ?>
              <div class="examination-examination-upload-feed-item">
                <div class="flex items-start justify-between gap-2">
                  <div class="min-w-0">
                    <p class="font-semibold text-gray-800 m-0 truncate"><?php echo h($s['full_name']); ?></p>
                    <p class="text-xs text-gray-500 m-0 mt-0.5 truncate"><?php echo h($s['task_title'] ?? ''); ?></p>
                    <?php if (!empty($s['file_name']) && !empty($s['file_path'])): ?>
                      <a href="<?php echo h($s['file_path']); ?>" class="file-chip w-fit" target="_blank" rel="noopener">
                        <i class="bi bi-paperclip"></i><?php echo h($s['file_name']); ?>
                      </a>
                    <?php endif; ?>
                  </div>
                  <span class="text-[11px] text-gray-500 shrink-0">
                    <?php echo !empty($s['submitted_at']) ? h(date('M j, g:i A', strtotime($s['submitted_at']))) : '-'; ?>
                  </span>
                </div>
              </div>
              <?php $uploadShown++; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="table-footer-action">
          <a href="professor_monitor"><i class="bi bi-eye"></i> View all file activity</a>
        </div>
      </div>
    </section>

    <h2 id="upcoming" class="examination-section-title "><i class="bi bi-calendar-event"></i> Upcoming Work</h2>
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-5">
      <!-- Upcoming exams -->
      <div class="rounded-xl overflow-hidden page-table dash-anim delay-4 bg-white overflow-hidden">
        <div class="table-card-head px-6 py-5 border-b border-[var(--admin-border)]">
          <h2 class="text-lg font-bold  m-0">Upcoming examinations</h2>
          <p class="text-sm text-gray-500 m-0 mt-1">Deadlines for regular and diagnostic examinations</p>
        </div>
        <div class="p-5">
          <?php if (empty($nextExams)): ?>
            <div class="text-center py-12 empty-state">
              <i class="bi bi-calendar-week"></i>
              <div class="font-medium">No upcoming examinations scheduled.</div>
              <a href="professor_examination_edit" class="admin-btn admin-btn--primary admin-btn--sm mt-3"><i class="bi bi-plus-circle"></i> Create an examination</a>
            </div>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($nextExams as $e): ?>
                <?php
                  $examDeadlineTs = !empty($e['deadline']) ? strtotime((string)$e['deadline']) : null;
                  $isExamDueSoon = $examDeadlineTs !== false && $examDeadlineTs !== null && $examDeadlineTs >= $nowTs && $examDeadlineTs <= ($nowTs + (2 * 86400));
                  $upcomingHref = ($e['exam_type'] ?? '') === 'diagnostic'
                    ? 'professor_examination_edit?exam_type=diagnostic&batch_id=' . (int)($e['source_id'] ?? 0)
                    : 'professor_examination_edit?exam_type=regular&exam_id=' . (int)($e['source_id'] ?? 0);
                ?>
                <a href="<?php echo h($upcomingHref); ?>" class="examination-dash-list-tile group flex items-center justify-between gap-3 p-4">
                  <div class="min-w-0 flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-green-100 border border-green-200  flex items-center justify-center shrink-0 mt-0.5">
                      <i class="bi bi-journal-richtext"></i>
                    </div>
                    <div class="min-w-0">
                      <p class="font-semibold  truncate"><?php echo h($e['title'] ?? ''); ?></p>
                      <p class="text-xs text-gray-500 mt-1 mb-0">
                      <?php echo h($e['exam_type_label'] ?? 'Examination'); ?> Â· <?php echo !empty($e['deadline']) ? h(date('M j, Y g:i A', strtotime((string)$e['deadline']))) : 'No deadline'; ?>
                      </p>
                    </div>
                  </div>
                  <div class="shrink-0 inline-flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold border <?php echo $isExamDueSoon ? 'bg-amber-50 text-amber-700 border-amber-200' : '  border-green-200'; ?>">
                      <?php echo $isExamDueSoon ? 'Due Soon' : 'Upcoming'; ?>
                    </span>
                    <i class="bi bi-arrow-right  opacity-0 group-hover:opacity-100 transition-opacity"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Upcoming tasks -->
      <div class="rounded-xl overflow-hidden page-table dash-anim delay-5 bg-white overflow-hidden">
        <div class="table-card-head px-6 py-5 border-b border-[var(--admin-border)]">
          <h2 class="text-lg font-bold  m-0">Upcoming upload tasks</h2>
          <p class="text-sm text-gray-500 m-0 mt-1">Deadlines for student submissions</p>
        </div>
        <div class="p-5">
          <?php if (empty($nextTasks)): ?>
            <div class="text-center py-12 empty-state">
              <i class="bi bi-calendar2-check"></i>
              <div class="font-medium">No upcoming upload tasks.</div>
              <a href="professor_upload_tasks" class="admin-btn admin-btn--primary admin-btn--sm mt-3"><i class="bi bi-plus-circle"></i> Create an upload task</a>
            </div>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($nextTasks as $t): ?>
                <?php
                  $taskDeadlineTs = !empty($t['deadline']) ? strtotime((string)$t['deadline']) : null;
                  $isTaskDueSoon = $taskDeadlineTs !== false && $taskDeadlineTs !== null && $taskDeadlineTs >= $nowTs && $taskDeadlineTs <= ($nowTs + (2 * 86400));
                ?>
                <a href="professor_upload_tasks?edit=<?php echo (int)($t['task_id'] ?? 0); ?>" class="examination-dash-list-tile group flex items-center justify-between gap-3 p-4">
                  <div class="min-w-0 flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-green-100 border border-green-200  flex items-center justify-center shrink-0 mt-0.5">
                      <i class="bi bi-folder2"></i>
                    </div>
                    <div class="min-w-0">
                      <p class="font-semibold  truncate"><?php echo h($t['title'] ?? ''); ?></p>
                      <p class="text-xs text-gray-500 mt-1 mb-0">
                      <?php echo !empty($t['deadline']) ? h(date('M j, Y g:i A', strtotime($t['deadline']))) : 'No deadline'; ?>
                      </p>
                    </div>
                  </div>
                  <div class="shrink-0 inline-flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold border <?php echo $isTaskDueSoon ? 'bg-amber-50 text-amber-700 border-amber-200' : '  border-green-200'; ?>">
                      <?php echo $isTaskDueSoon ? 'Due Soon' : 'Upcoming'; ?>
                    </span>
                    <i class="bi bi-arrow-right  opacity-0 group-hover:opacity-100 transition-opacity"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
  (function () {
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
          borderColor: '#15803d',
          backgroundColor: 'rgba(21, 128, 61, 0.12)',
          fill: true,
          tension: 0.34,
          pointRadius: 3.2,
          pointHoverRadius: 5.2,
          pointBackgroundColor: '#15803d'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { color: '#6b7280', precision: 0 },
            grid: { color: 'rgba(21, 128, 61, 0.12)' }
          },
          x: {
            ticks: { color: '#6b7280', maxRotation: 0 },
            grid: { display: false }
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
