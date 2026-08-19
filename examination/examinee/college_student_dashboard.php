<?php
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
ereview_require_college_examination_portal();
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/examination_eligibility.php';

$pageTitle = 'College Portal';
$uid = getCurrentUserId();
$csrf = generateCSRFToken();

$now = date('Y-m-d H:i:s');
$uidDash = (int)$uid;
if ($uidDash > 0) {
    college_exam_finalize_expired_in_progress($conn, 0, $uidDash, 0);
    diagnostic_exam_finalize_expired_in_progress($conn, 0, $uidDash);
}

$diagnosticCards = [];
$assignedExams = examination_student_load_assigned_exams($conn, $uidDash, $now);
$activeExams = 0;
$completedExams = 0;
$dueSoonExams = 0;
$upcoming = [];
$soonTs = strtotime('+3 days', strtotime($now));

foreach ($assignedExams as $examItem) {
    $bucket = (string)($examItem['_bucket'] ?? '');
    $st = (string)($examItem['attempt_status'] ?? '');
    if ($bucket === 'open' || ($st === 'in_progress')) {
        $activeExams++;
    }
    if ($bucket === 'finished' || $st === 'submitted' || ($st === 'expired' && !empty($examItem['submitted_at']))) {
        $completedExams++;
    }
    $deadline = trim((string)($examItem['deadline'] ?? ''));
    if ($deadline !== '' && $deadline > $now) {
        $upcoming[] = [
            'title' => (string)($examItem['title'] ?? ''),
            'deadline' => $deadline,
            'exam_type' => (string)($examItem['exam_type'] ?? 'regular'),
        ];
        $dTs = strtotime($deadline);
        if ($dTs !== false && $dTs <= $soonTs) {
            $dueSoonExams++;
        }
    }
}

usort($upcoming, static function ($a, $b) {
    return strcmp((string)($a['deadline'] ?? ''), (string)($b['deadline'] ?? ''));
});
$upcoming = array_slice($upcoming, 0, 5);

$pendingUploads = 0;
$uploadDue = [];
$dueSoonUploads = 0;
$openUploadTasksTotal = 0;
require_once dirname(__DIR__) . '/includes/college_upload_helpers.php';
$eligibleUploadTasks = college_upload_list_for_student($conn, $uidDash);
foreach ($eligibleUploadTasks as $taskRow) {
    $deadline = (string)($taskRow['deadline'] ?? '');
    if ($deadline === '' || $deadline < $now) {
        continue;
    }
    $openUploadTasksTotal++;
    $taskId = (int)($taskRow['task_id'] ?? 0);
    $hasSubmission = false;
    if ($taskId > 0) {
        $sq = @mysqli_query(
            $conn,
            'SELECT submission_id FROM college_submissions WHERE task_id=' . $taskId . ' AND user_id=' . $uidDash . ' LIMIT 1'
        );
        if ($sq && mysqli_fetch_assoc($sq)) {
            $hasSubmission = true;
        }
        if ($sq) {
            mysqli_free_result($sq);
        }
    }
    if ($hasSubmission) {
        continue;
    }
    $pendingUploads++;
    $dTs = strtotime($deadline);
    if ($dTs !== false && $dTs <= $soonTs) {
        $dueSoonUploads++;
    }
    if (count($uploadDue) < 5) {
        $uploadDue[] = [
            'task_id' => $taskId,
            'title' => (string)($taskRow['title'] ?? ''),
            'deadline' => $deadline,
        ];
    }
}

$examEngagementPct = ($completedExams + $activeExams) > 0 ? (int)round(($completedExams / ($completedExams + $activeExams)) * 100) : 0;
$uploadCompletionPct = $openUploadTasksTotal > 0 ? (int)round((($openUploadTasksTotal - $pendingUploads) / $openUploadTasksTotal) * 100) : 0;

$weeklyActivity = [];
$weeklyLabels = [];
for ($i = 7; $i >= 0; $i--) {
    $weekTs = strtotime("monday this week -{$i} week");
    $weekKey = date('o-W', $weekTs);
    $weeklyActivity[$weekKey] = 0;
    $weeklyLabels[$weekKey] = 'Wk ' . date('M j', $weekTs);
}

$r7 = @mysqli_query($conn, "
  SELECT DATE_FORMAT(submitted_at, '%x-%v') AS yw, COUNT(*) AS c
  FROM college_exam_attempts
  WHERE user_id=" . (int)$uid . "
    AND status='submitted'
    AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
  GROUP BY yw
");
if ($r7) {
    while ($row = mysqli_fetch_assoc($r7)) {
        $key = (string)($row['yw'] ?? '');
        if (isset($weeklyActivity[$key])) $weeklyActivity[$key] += (int)($row['c'] ?? 0);
    }
    mysqli_free_result($r7);
}

$r8 = @mysqli_query($conn, "
  SELECT DATE_FORMAT(submitted_at, '%x-%v') AS yw, COUNT(*) AS c
  FROM college_submissions
  WHERE user_id=" . (int)$uid . "
    AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
  GROUP BY yw
");
if ($r8) {
    while ($row = mysqli_fetch_assoc($r8)) {
        $key = (string)($row['yw'] ?? '');
        if (isset($weeklyActivity[$key])) $weeklyActivity[$key] += (int)($row['c'] ?? 0);
    }
    mysqli_free_result($r8);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="cp-page-shell ereview-shell-no-fade pt-2">
    <?php
      $hour = (int)date('G');
      $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
      $firstName = trim(explode(' ', trim((string)($_SESSION['full_name'] ?? 'Student')))[0] ?? 'Student');
    ?>
    <div class="cp-welcome cp-anim delay-1">
      <div>
        <h1 class="cp-welcome__title"><?php echo h($greeting); ?>, <?php echo h($firstName); ?></h1>
        <p class="cp-welcome__sub">Here’s your learning progress today — exams, uploads, and upcoming deadlines.</p>
      </div>
      <div class="cp-welcome__actions">
        <a href="college_exams" class="cp-btn cp-btn--primary"><i class="bi bi-journal-text"></i> View exams</a>
        <a href="college_uploads" class="cp-btn"><i class="bi bi-cloud-upload"></i> Uploads</a>
      </div>
    </div>
    <?php
      $cpPageEyebrow = 'College portal';
      $cpPageTitle = 'Dashboard';
      $cpPageSubtitle = 'Overview of examinations, uploads, and activity.';
      $cpPageIcon = 'bi-speedometer2';
      require dirname(__DIR__, 2) . '/includes/components/college_portal_page_header.php';
      $cpSectionIcon = 'bi-speedometer2';
      $cpSectionTitle = 'Quick stats';
      require dirname(__DIR__, 2) . '/includes/components/college_portal_section.php';
    ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-6">
      <div class="kpi-card dash-anim delay-2 p-4 flex flex-col justify-between">
        <div class="flex items-center gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#e8f2fa] text-[#1665A0] text-xl"><i class="bi bi-journal-text"></i></span>
        <div>
          <p class="text-xs text-gray-500 m-0">Open exams</p>
          <p class="text-xl font-bold text-[#143D59] m-0"><?php echo (int)$activeExams; ?></p>
          <?php if ((int)$activeExams === 0): ?><p class="text-xs text-gray-500 m-0 mt-0.5">No exams currently available.</p><?php endif; ?>
        </div>
        </div>
        <a href="college_exams" class="kpi-action mt-3"><i class="bi bi-arrow-right"></i> View exams</a>
      </div>
      <div class="kpi-card dash-anim delay-2 p-4 flex flex-col justify-between">
        <div class="flex items-center gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600 text-xl"><i class="bi bi-cloud-upload"></i></span>
        <div>
          <p class="text-xs text-gray-500 m-0">Pending uploads</p>
          <p class="text-xl font-bold text-[#143D59] m-0"><?php echo (int)$pendingUploads; ?></p>
          <?php if ((int)$pendingUploads === 0): ?><p class="text-xs text-gray-500 m-0 mt-0.5">No pending uploads.</p><?php endif; ?>
        </div>
        </div>
        <a href="college_uploads" class="kpi-action mt-3"><i class="bi bi-arrow-right"></i> View uploads</a>
      </div>
      <div class="kpi-card dash-anim delay-3 p-4 flex flex-col justify-between">
        <div class="flex items-center gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 text-xl"><i class="bi bi-check2-circle"></i></span>
        <div>
          <p class="text-xs text-gray-500 m-0">Completed exams</p>
          <p class="text-xl font-bold text-[#143D59] m-0"><?php echo (int)$completedExams; ?></p>
        </div>
        </div>
        <a href="college_exams" class="kpi-action mt-3"><i class="bi bi-arrow-right"></i> View history</a>
      </div>
      <div class="kpi-card dash-anim delay-3 p-4 flex flex-col justify-between">
        <div class="flex items-center gap-3">
          <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50 text-rose-600 text-xl"><i class="bi bi-alarm"></i></span>
          <div>
            <p class="text-xs text-gray-500 m-0">Due soon (3d)</p>
            <p class="text-xl font-bold text-[#143D59] m-0"><?php echo (int)($dueSoonExams + $dueSoonUploads); ?></p>
          </div>
        </div>
        <a href="college_exams" class="kpi-action mt-3"><i class="bi bi-arrow-right"></i> Review deadlines</a>
      </div>
    </div>

    <?php $hasWeeklyActivity = array_sum($weeklyActivity) > 0; ?>
    <?php
      $cpSectionIcon = 'bi-graph-up-arrow';
      $cpSectionTitle = 'Insights';
      $cpSectionClass = 'cp-anim delay-3';
      require dirname(__DIR__, 2) . '/includes/components/college_portal_section.php';
    ?>
    <div class="overview-card dash-anim delay-3 p-4 mb-6">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
          <h3 class="text-base font-bold text-[#143D59] m-0">Your activity trend</h3>
          <p class="text-sm text-gray-500 m-0 mt-1">Exam submissions and file uploads over the last 8 weeks.</p>
        </div>
        <div class="grid grid-cols-2 gap-2 text-sm">
          <div class="px-3 py-2 rounded-lg border border-[#d6e8f7] bg-[#f8fbff]">
            <p class="text-xs text-gray-500 m-0">Exam engagement</p>
            <p class="font-extrabold text-[#1665A0] m-0 mt-1"><?php echo (int)$examEngagementPct; ?>%</p>
          </div>
          <div class="px-3 py-2 rounded-lg border border-[#d6e8f7] bg-[#f8fbff]">
            <p class="text-xs text-gray-500 m-0">Upload completion</p>
            <p class="font-extrabold text-[#1665A0] m-0 mt-1"><?php echo (int)$uploadCompletionPct; ?>%</p>
          </div>
        </div>
      </div>
      <?php if ($hasWeeklyActivity): ?>
      <div class="mt-3 h-[160px]">
        <canvas id="collegeActivityChart" aria-label="Weekly activity trend"></canvas>
      </div>
      <?php else: ?>
      <p class="text-sm text-gray-500 m-0 mt-3">No recent exam or upload activity yet.</p>
      <?php endif; ?>
    </div>

    <?php
      $cpSectionIcon = 'bi-clipboard-data';
      $cpSectionTitle = 'Deadlines and uploads';
      $cpSectionClass = 'cp-anim delay-4';
      require dirname(__DIR__, 2) . '/includes/components/college_portal_section.php';
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <article class="overview-card dash-anim delay-4 overflow-hidden">
        <div class="px-5 py-4 border-b border-[#d6e8f7] bg-gradient-to-r from-[#f0f7fc] to-white flex items-center justify-between">
          <h2 class="text-lg font-bold text-[#143D59] m-0 flex items-center gap-2"><i class="bi bi-alarm"></i> Exam deadlines</h2>
          <a href="college_exams" class="text-sm font-semibold text-[#1665A0] hover:underline">View all</a>
        </div>
        <div class="p-5">
          <?php if (empty($upcoming)): ?>
            <p class="text-gray-500 m-0">No upcoming deadlines.</p>
            <a href="college_exams" class="inline-flex items-center gap-1 text-sm font-semibold text-[#1665A0] mt-2 hover:underline">Browse exams <i class="bi bi-arrow-right"></i></a>
          <?php else: ?>
            <ul class="m-0 p-0 list-none space-y-2">
              <?php foreach ($upcoming as $u):
                $typeLabel = examination_exam_type_label((string)($u['exam_type'] ?? 'regular'));
              ?>
              <li class="list-tile flex justify-between gap-3 items-center">
                <span class="font-medium text-gray-800 truncate"><?php echo h($u['title']); ?></span>
                <span class="type-pill <?php echo ($u['exam_type'] ?? '') === 'diagnostic' ? 'type-diagnostic' : 'type-regular'; ?> shrink-0"><?php echo h($typeLabel); ?></span>
                <span class="text-sm text-amber-700 whitespace-nowrap"><?php echo h(date('M j, g:i A', strtotime($u['deadline']))); ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </article>

      <article class="overview-card dash-anim delay-4 overflow-hidden">
        <div class="px-5 py-4 border-b border-[#d6e8f7] bg-gradient-to-r from-[#f0f7fc] to-white flex items-center justify-between">
          <h2 class="text-lg font-bold text-[#143D59] m-0 flex items-center gap-2"><i class="bi bi-upload"></i> Upload due</h2>
          <a href="college_uploads" class="text-sm font-semibold text-[#1665A0] hover:underline">View all</a>
        </div>
        <div class="p-5">
          <?php if (empty($uploadDue)): ?>
            <p class="text-gray-500 m-0">No pending uploads.</p>
            <a href="college_uploads" class="inline-flex items-center gap-1 text-sm font-semibold text-[#1665A0] mt-2 hover:underline">Open upload center <i class="bi bi-arrow-right"></i></a>
          <?php else: ?>
            <ul class="m-0 p-0 list-none space-y-2">
              <?php foreach ($uploadDue as $u): ?>
              <li class="list-tile flex justify-between gap-3">
                <span class="font-medium text-gray-800 truncate"><?php echo h($u['title']); ?></span>
                <span class="text-sm text-amber-700 whitespace-nowrap"><?php echo h(date('M j, g:i A', strtotime($u['deadline']))); ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </article>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
  (function () {
    if (typeof Chart === 'undefined') return;
    var canvas = document.getElementById('collegeActivityChart');
    if (!canvas) return;
    new Chart(canvas, {
      type: 'line',
      data: {
        labels: <?php echo json_encode(array_values($weeklyLabels)); ?>,
        datasets: [{
          label: 'Activity',
          data: <?php echo json_encode(array_values($weeklyActivity)); ?>,
          borderColor: '#1665A0',
          backgroundColor: 'rgba(22, 101, 160, 0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: 3.6,
          pointHoverRadius: 5.6,
          pointBackgroundColor: '#1665A0'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0, color: '#64748b' },
            grid: { color: 'rgba(22, 101, 160, 0.12)' }
          },
          x: {
            ticks: { color: '#64748b' },
            grid: { display: false }
          }
        }
      }
    });
  })();
  </script>
</main>
</body>
</html>
