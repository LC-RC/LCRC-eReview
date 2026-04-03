<?php
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';

$pageTitle = 'College Portal';
$uid = getCurrentUserId();
$csrf = generateCSRFToken();

$now = date('Y-m-d H:i:s');
$uidDash = (int)$uid;
if ($uidDash > 0) {
    college_exam_finalize_expired_in_progress($conn, 0, $uidDash, 0);
}

$activeExams = 0;
$pubE = college_exam_where_published_sql('e');
$dashExamRows = college_exams_load_published_exams($conn);
$dashExamIds = array_values(array_unique(array_filter(array_map(static function ($row) {
    return (int)($row['exam_id'] ?? 0);
}, $dashExamRows), static function ($id) { return $id > 0; })));
$dashSubmittedByExam = [];
if ($dashExamIds !== []) {
    $inDash = implode(',', $dashExamIds);
    $dqs = @mysqli_query($conn, "
      SELECT exam_id,
        SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) AS submitted_count
      FROM college_exam_attempts
      WHERE exam_id IN ({$inDash})
      GROUP BY exam_id
    ");
    if ($dqs) {
        while ($dr = mysqli_fetch_assoc($dqs)) {
            $dashSubmittedByExam[(int)$dr['exam_id']] = (int)($dr['submitted_count'] ?? 0);
        }
        mysqli_free_result($dqs);
    }
}
foreach ($dashExamRows as $de) {
    if (!college_exam_row_is_published($de)) {
        continue;
    }
    if (!empty($de['available_from']) && (string)$de['available_from'] > $now) {
        continue;
    }
    if (!empty($de['deadline']) && (string)$de['deadline'] < $now) {
        continue;
    }
    $deid = (int)($de['exam_id'] ?? 0);
    $dSub = (int)($dashSubmittedByExam[$deid] ?? 0);
    if (college_exam_finished_all_submitted_no_deadline($conn, $de, $dSub)) {
        continue;
    }
    $activeExams++;
}

$pendingUploads = 0;
$r2 = @mysqli_query($conn, "
  SELECT COUNT(*) AS c FROM college_upload_tasks t
  LEFT JOIN college_submissions s ON s.task_id=t.task_id AND s.user_id=" . (int)$uid . "
  WHERE t.is_open=1 AND t.deadline >= '$now' AND s.submission_id IS NULL
");
if ($r2) {
    $pendingUploads = (int)(mysqli_fetch_assoc($r2)['c'] ?? 0);
    mysqli_free_result($r2);
}

$completedExams = 0;
$r3 = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exam_attempts WHERE user_id=" . (int)$uid . " AND status='submitted'");
if ($r3) {
    $completedExams = (int)(mysqli_fetch_assoc($r3)['c'] ?? 0);
    mysqli_free_result($r3);
}

$upcoming = [];
$uq = @mysqli_query($conn, "
  SELECT e.exam_id, e.title, e.deadline FROM college_exams e
  WHERE {$pubE} AND e.deadline IS NOT NULL AND e.deadline > '$now'
  ORDER BY e.deadline ASC LIMIT 5
");
if ($uq) {
    while ($row = mysqli_fetch_assoc($uq)) {
        $upcoming[] = $row;
    }
    mysqli_free_result($uq);
}

$uploadDue = [];
$tq = @mysqli_query($conn, "
  SELECT t.task_id, t.title, t.deadline FROM college_upload_tasks t
  LEFT JOIN college_submissions s ON s.task_id=t.task_id AND s.user_id=" . (int)$uid . "
  WHERE t.is_open=1 AND t.deadline >= '$now' AND s.submission_id IS NULL
  ORDER BY t.deadline ASC LIMIT 5
");
if ($tq) {
    while ($row = mysqli_fetch_assoc($tq)) {
        $uploadDue[] = $row;
    }
    mysqli_free_result($tq);
}

$dueSoonExams = 0;
$r4 = @mysqli_query($conn, "
  SELECT COUNT(*) AS c
  FROM college_exams e
  WHERE {$pubE}
    AND e.deadline IS NOT NULL
    AND e.deadline >= '{$now}'
    AND e.deadline <= DATE_ADD('{$now}', INTERVAL 3 DAY)
");
if ($r4) {
    $dueSoonExams = (int)(mysqli_fetch_assoc($r4)['c'] ?? 0);
    mysqli_free_result($r4);
}

$dueSoonUploads = 0;
$r5 = @mysqli_query($conn, "
  SELECT COUNT(*) AS c
  FROM college_upload_tasks t
  LEFT JOIN college_submissions s ON s.task_id=t.task_id AND s.user_id=" . (int)$uid . "
  WHERE t.is_open=1
    AND t.deadline IS NOT NULL
    AND t.deadline >= '{$now}'
    AND t.deadline <= DATE_ADD('{$now}', INTERVAL 3 DAY)
    AND s.submission_id IS NULL
");
if ($r5) {
    $dueSoonUploads = (int)(mysqli_fetch_assoc($r5)['c'] ?? 0);
    mysqli_free_result($r5);
}

$openUploadTasksTotal = 0;
$r6 = @mysqli_query($conn, "
  SELECT COUNT(*) AS c
  FROM college_upload_tasks t
  WHERE t.is_open=1 AND t.deadline >= '{$now}'
");
if ($r6) {
    $openUploadTasksTotal = (int)(mysqli_fetch_assoc($r6)['c'] ?? 0);
    mysqli_free_result($r6);
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
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .college-dashboard-page {
      width: 100%;
      margin-left: 0;
      margin-right: 0;
      max-width: none;
      padding: 0 0 2rem;
    }
    @media (min-width: 1024px) { .college-dashboard-page { padding: 0 0 2rem; } }
    @media (min-width: 1280px) { .college-dashboard-page { padding: 0 0 2rem; } }
    @media (min-width: 1536px) { .college-dashboard-page { padding: 0 0 2rem; } }

    .cstu-hero {
      border-radius: 0.75rem;
      border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
      box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22);
    }
    .cstu-kpi-strip {
      background: rgba(255,255,255,0.14);
      border: 1px solid rgba(255,255,255,0.24);
      border-radius: 0.62rem;
    }
    .cstu-hero-btn {
      border-radius: 9999px;
      transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
    }
    .cstu-hero-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 22px -20px rgba(14, 64, 105, .95); }

    .section-title {
      display: flex; align-items: center; gap: .5rem;
      margin: 0 0 .85rem; padding: .45rem .65rem;
      border: 1px solid #d8e8f6; border-radius: .62rem;
      background: linear-gradient(180deg,#f4f9fe 0%,#fff 100%);
      color: #143D59; font-size: 1.03rem; font-weight: 800;
    }
    .section-title i {
      width: 1.55rem; height: 1.55rem; border-radius: .45rem;
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #b9daf2; background: #e8f2fa; color: #1665A0; font-size: .83rem;
    }

    .kpi-card, .overview-card {
      border-radius: .75rem;
      border: 1px solid rgba(22,101,160,.18);
      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 60%);
      box-shadow: 0 10px 28px -22px rgba(20,61,89,.55), 0 1px 0 rgba(255,255,255,.85) inset;
      transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease, background-color .22s ease;
    }
    .kpi-card:hover, .overview-card:hover {
      transform: translateY(-2px);
      border-color: rgba(22,101,160,.32);
      background-color: #fdfeff;
      box-shadow: 0 20px 34px -24px rgba(20,61,89,.35);
    }
    .kpi-action {
      width: 100%; border: 1px solid #cde2f4; border-radius: .55rem; background: #fff;
      display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
      font-size: .82rem; font-weight: 700; color: #1665A0; padding: .55rem .7rem;
      transition: all .2s ease;
    }
    .kpi-action:hover { border-color: #8fc0e8; background: #f4f9fe; transform: translateY(-1px); }
    .list-tile {
      border: 1px solid #d6e8f7; border-radius: .65rem; padding: .65rem .72rem;
      transition: all .2s ease;
    }
    .list-tile:hover { border-color: #9bc8ea; background: #f8fbff; transform: translateY(-1px); }

    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; } .delay-4 { animation-delay: .24s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) { .dash-anim { opacity: 1; transform: none; animation: none; } }
  </style>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="college-dashboard-page pt-2">
    <section class="cstu-hero dash-anim delay-1 relative overflow-hidden mb-6 px-6 py-7">
      <div class="relative z-10 text-white">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3">
              <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 border border-white/30"><i class="bi bi-mortarboard"></i></span>
              College portal
            </h1>
            <p class="text-white/90 mt-2 mb-0 max-w-2xl">Quizzes, exams, and assignment uploads for your courses.</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <a href="college_exams.php" class="cstu-hero-btn inline-flex items-center gap-2 px-4 py-2.5 bg-white text-[#145a8f] font-semibold">Open exams</a>
            <a href="college_uploads.php" class="cstu-hero-btn inline-flex items-center gap-2 px-4 py-2.5 border border-white/35 bg-white/10 text-white font-semibold">Upload tasks</a>
          </div>
        </div>
        <div class="cstu-kpi-strip mt-4 px-4 py-2.5 text-sm flex flex-wrap gap-x-3 gap-y-1">
          <span class="font-semibold">Open exams: <?php echo (int)$activeExams; ?></span>
          <span class="text-white/50">·</span>
          <span class="font-semibold">Pending uploads: <?php echo (int)$pendingUploads; ?></span>
          <span class="text-white/50">·</span>
          <span class="font-semibold">Completed exams: <?php echo (int)$completedExams; ?></span>
          <span class="text-white/50">·</span>
          <span class="font-semibold">Due soon: <?php echo (int)($dueSoonExams + $dueSoonUploads); ?></span>
        </div>
      </div>
    </section>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-speedometer2"></i> Learning Overview</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-7">
      <div class="kpi-card dash-anim delay-2 p-5 flex flex-col justify-between">
        <div class="flex items-center gap-4">
        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#e8f2fa] text-[#1665A0] text-2xl"><i class="bi bi-journal-text"></i></span>
        <div>
          <p class="text-sm text-gray-500 m-0">Open exams</p>
          <p class="text-2xl font-bold text-[#143D59] m-0"><?php echo (int)$activeExams; ?></p>
        </div>
        </div>
        <a href="college_exams.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> Go to exams</a>
      </div>
      <div class="kpi-card dash-anim delay-2 p-5 flex flex-col justify-between">
        <div class="flex items-center gap-4">
        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-600 text-2xl"><i class="bi bi-cloud-upload"></i></span>
        <div>
          <p class="text-sm text-gray-500 m-0">Pending uploads</p>
          <p class="text-2xl font-bold text-[#143D59] m-0"><?php echo (int)$pendingUploads; ?></p>
        </div>
        </div>
        <a href="college_uploads.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> Go to uploads</a>
      </div>
      <div class="kpi-card dash-anim delay-3 p-5 flex flex-col justify-between">
        <div class="flex items-center gap-4">
        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 text-2xl"><i class="bi bi-check2-circle"></i></span>
        <div>
          <p class="text-sm text-gray-500 m-0">Exams completed</p>
          <p class="text-2xl font-bold text-[#143D59] m-0"><?php echo (int)$completedExams; ?></p>
        </div>
        </div>
        <a href="college_exams.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> View history</a>
      </div>
      <div class="kpi-card dash-anim delay-3 p-5 flex flex-col justify-between">
        <div class="flex items-center gap-4">
          <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-rose-50 text-rose-600 text-2xl"><i class="bi bi-alarm"></i></span>
          <div>
            <p class="text-sm text-gray-500 m-0">Due soon (3d)</p>
            <p class="text-2xl font-bold text-[#143D59] m-0"><?php echo (int)($dueSoonExams + $dueSoonUploads); ?></p>
          </div>
        </div>
        <a href="college_exams.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> Review deadlines</a>
      </div>
    </div>

    <h2 class="section-title dash-anim delay-3"><i class="bi bi-graph-up-arrow"></i> Insights</h2>
    <div class="overview-card dash-anim delay-3 p-5 mb-7">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h3 class="text-lg font-bold text-[#143D59] m-0">Your activity trend</h3>
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
      <div class="mt-4 h-[220px]">
        <canvas id="collegeActivityChart" aria-label="Weekly activity trend"></canvas>
      </div>
    </div>

    <h2 class="section-title dash-anim delay-4"><i class="bi bi-clipboard-data"></i> Deadlines and Uploads</h2>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <article class="overview-card dash-anim delay-4 overflow-hidden">
        <div class="px-5 py-4 border-b border-[#d6e8f7] bg-gradient-to-r from-[#f0f7fc] to-white flex items-center justify-between">
          <h2 class="text-lg font-bold text-[#143D59] m-0 flex items-center gap-2"><i class="bi bi-alarm"></i> Exam deadlines</h2>
          <a href="college_exams.php" class="text-sm font-semibold text-[#1665A0] hover:underline">View all</a>
        </div>
        <div class="p-5">
          <?php if (empty($upcoming)): ?>
            <p class="text-gray-500 m-0">No upcoming deadlines.</p>
            <a href="college_exams.php" class="inline-flex items-center gap-1 text-sm font-semibold text-[#1665A0] mt-2 hover:underline">Browse exams <i class="bi bi-arrow-right"></i></a>
          <?php else: ?>
            <ul class="m-0 p-0 list-none space-y-2">
              <?php foreach ($upcoming as $u): ?>
              <li class="list-tile flex justify-between gap-3">
                <span class="font-medium text-gray-800 truncate"><?php echo h($u['title']); ?></span>
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
          <a href="college_uploads.php" class="text-sm font-semibold text-[#1665A0] hover:underline">View all</a>
        </div>
        <div class="p-5">
          <?php if (empty($uploadDue)): ?>
            <p class="text-gray-500 m-0">No pending uploads.</p>
            <a href="college_uploads.php" class="inline-flex items-center gap-1 text-sm font-semibold text-[#1665A0] mt-2 hover:underline">Open upload center <i class="bi bi-arrow-right"></i></a>
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
