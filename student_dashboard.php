<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/profile_avatar.php';
requireRole('student');
$pageTitle = 'Student Dashboard';
$csrf = generateCSRFToken();

$lastLoginAt = null;
$uid = getCurrentUserId();
$dashboardAvatarPath = '';
$dashboardUseDefaultAvatar = 1;
$dashboardAvatarInitial = ereview_avatar_initial($_SESSION['full_name'] ?? 'U');

$tableExists = static function (mysqli $conn, string $table): bool {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($safe === '') return false;
    $res = @mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $res && mysqli_num_rows($res) > 0;
};

$scalar = static function (mysqli $conn, string $sql, int $default = 0): int {
    $res = @mysqli_query($conn, $sql);
    if (!$res) return $default;
    $row = mysqli_fetch_assoc($res);
    return (int)($row['c'] ?? $default);
};

try {
    $hasProfilePicture = false;
    $hasDefaultAvatar = false;
    $cp1 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if ($cp1 && mysqli_fetch_assoc($cp1)) $hasProfilePicture = true;
    $cp2 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'use_default_avatar'");
    if ($cp2 && mysqli_fetch_assoc($cp2)) $hasDefaultAvatar = true;

    $select = 'SELECT last_login_at, access_start, access_end';
    if ($hasProfilePicture) $select .= ', profile_picture';
    if ($hasDefaultAvatar) $select .= ', use_default_avatar';
    $select .= ' FROM users WHERE user_id = ? LIMIT 1';

    $stmt = mysqli_prepare($conn, $select);
    $info = null;
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $uid);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            if ($row) {
                $info = $row;
                $lastLoginAt = !empty($row['last_login_at']) ? (string)$row['last_login_at'] : null;
                $dashboardAvatarPath = ereview_avatar_public_path($row['profile_picture'] ?? '');
                if ($hasDefaultAvatar) {
                    $dashboardUseDefaultAvatar = !empty($row['use_default_avatar']) ? 1 : 0;
                }
            }
        }
        mysqli_stmt_close($stmt);
    }
} catch (mysqli_sql_exception $e) {
    $info = null;
}

$subjectsCount = $scalar($conn, "SELECT COUNT(*) AS c FROM subjects WHERE status='active'");
$lessonsCount = $scalar($conn, "SELECT COUNT(*) AS c FROM lessons l JOIN subjects s ON s.subject_id=l.subject_id WHERE s.status='active'");
$quizzesCount = $scalar($conn, "SELECT COUNT(*) AS c FROM quizzes q JOIN subjects s ON s.subject_id=q.subject_id WHERE s.status='active'");
$quizSubmittedCount = $tableExists($conn, 'quiz_attempts')
    ? $scalar($conn, "SELECT COUNT(*) AS c FROM quiz_attempts WHERE user_id=" . (int)$uid . " AND status='submitted'")
    : 0;
$quizInProgressCount = $tableExists($conn, 'quiz_attempts')
    ? $scalar($conn, "SELECT COUNT(*) AS c FROM quiz_attempts WHERE user_id=" . (int)$uid . " AND status='in_progress'")
    : 0;
$preboardsSubjectsCount = $tableExists($conn, 'preboards_subjects')
    ? $scalar($conn, "SELECT COUNT(*) AS c FROM preboards_subjects WHERE status='active'")
    : 0;
$preboardsSubmittedCount = $tableExists($conn, 'preboards_attempts')
    ? $scalar($conn, "SELECT COUNT(*) AS c FROM preboards_attempts WHERE user_id=" . (int)$uid . " AND status='submitted'")
    : 0;

$avgQuizScore = 0;
if ($tableExists($conn, 'quiz_attempts')) {
    $avgRes = @mysqli_query($conn, "SELECT AVG(score) AS avg_score FROM quiz_attempts WHERE user_id=" . (int)$uid . " AND status='submitted' AND score IS NOT NULL");
    if ($avgRes && ($avgRow = mysqli_fetch_assoc($avgRes)) && $avgRow['avg_score'] !== null) {
        $avgQuizScore = (int)round((float)$avgRow['avg_score']);
    }
}

$weeklyActivity = [];
$weeklyLabels = [];
for ($i = 7; $i >= 0; $i--) {
    $weekTs = strtotime("monday this week -{$i} week");
    $key = date('o-W', $weekTs);
    $weeklyActivity[$key] = 0;
    $weeklyLabels[$key] = 'Wk ' . date('M j', $weekTs);
}
if ($tableExists($conn, 'quiz_attempts')) {
    $act1 = @mysqli_query($conn, "SELECT DATE_FORMAT(submitted_at, '%x-%v') AS yw, COUNT(*) AS c FROM quiz_attempts WHERE user_id=" . (int)$uid . " AND status='submitted' AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK) GROUP BY yw");
    if ($act1) {
        while ($row = mysqli_fetch_assoc($act1)) {
            $k = (string)($row['yw'] ?? '');
            if (isset($weeklyActivity[$k])) $weeklyActivity[$k] += (int)($row['c'] ?? 0);
        }
    }
}
if ($tableExists($conn, 'preboards_attempts')) {
    $act2 = @mysqli_query($conn, "SELECT DATE_FORMAT(submitted_at, '%x-%v') AS yw, COUNT(*) AS c FROM preboards_attempts WHERE user_id=" . (int)$uid . " AND status='submitted' AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK) GROUP BY yw");
    if ($act2) {
        while ($row = mysqli_fetch_assoc($act2)) {
            $k = (string)($row['yw'] ?? '');
            if (isset($weeklyActivity[$k])) $weeklyActivity[$k] += (int)($row['c'] ?? 0);
        }
    }
}

$activityLast8Weeks = array_sum($weeklyActivity);
$currentWeekActivity = (int)end($weeklyActivity);
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$progressBase = max(1, $subjectsCount + $quizzesCount + $preboardsSubjectsCount);
$progressDone = min($progressBase, $quizSubmittedCount + $preboardsSubmittedCount + (int)round($lessonsCount / 6));
$learningProgressPct = (int)round(($progressDone / $progressBase) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>
  <div class="student-dashboard-page min-h-full pb-8">
    <section class="student-hero dash-anim delay-1 relative overflow-hidden mb-6 px-6 py-7">
      <div class="relative z-10 text-white">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3">
              <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/20 border border-white/30 overflow-hidden">
                <?php if ($dashboardAvatarPath !== '' && !$dashboardUseDefaultAvatar): ?>
                  <img src="<?php echo h($dashboardAvatarPath); ?>" alt="" class="w-full h-full object-cover">
                <?php else: ?>
                  <span class="text-lg font-semibold"><?php echo h($dashboardAvatarInitial); ?></span>
                <?php endif; ?>
              </span>
              <?php echo h($greeting); ?>, <?php echo h($_SESSION['full_name']); ?>
            </h1>
            <p class="text-white/90 mt-2 mb-0 max-w-2xl">A smarter learning overview with your real-time review activity and progress.</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <a href="student_subjects.php" class="hero-btn inline-flex items-center gap-2 px-4 py-2.5 bg-white text-[#145a8f] font-semibold">
              <i class="bi bi-journal-bookmark"></i> Open subjects
            </a>
            <a href="student_preboards.php" class="hero-btn inline-flex items-center gap-2 px-4 py-2.5 border border-white/35 bg-white/10 text-white font-semibold">
              <i class="bi bi-clipboard-check"></i> Open preboards
            </a>
          </div>
        </div>
        <div class="hero-strip mt-4 px-4 py-2.5 text-sm flex flex-wrap gap-x-3 gap-y-1">
          <span class="font-semibold">Active subjects: <?php echo (int)$subjectsCount; ?></span>
          <span class="text-white/50">·</span>
          <span class="font-semibold">Lessons: <?php echo (int)$lessonsCount; ?></span>
          <span class="text-white/50">·</span>
          <span class="font-semibold">Quiz submissions: <?php echo (int)$quizSubmittedCount; ?></span>
          <span class="text-white/50">·</span>
          <span class="font-semibold">Preboards done: <?php echo (int)$preboardsSubmittedCount; ?></span>
          <?php if ($lastLoginAt): ?>
            <span class="text-white/50">·</span>
            <span class="font-semibold">Last login: <?php echo h(date('M j, g:i A', strtotime($lastLoginAt))); ?></span>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-speedometer2"></i> Learning Overview</h2>
    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-7">
      <article class="dash-card kpi-card dash-anim delay-2 p-5">
        <div class="flex items-center gap-4">
          <span class="kpi-icon bg-[#e8f2fa] text-[#1665A0]"><i class="bi bi-journal-bookmark"></i></span>
          <div>
            <p class="text-sm text-slate-500 m-0">Subjects</p>
            <p class="kpi-number text-[#143D59] m-0"><?php echo (int)$subjectsCount; ?></p>
          </div>
        </div>
        <a href="student_subjects.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> Manage subjects</a>
      </article>
      <article class="dash-card kpi-card dash-anim delay-2 p-5">
        <div class="flex items-center gap-4">
          <span class="kpi-icon bg-cyan-50 text-cyan-700"><i class="bi bi-journal-text"></i></span>
          <div>
            <p class="text-sm text-slate-500 m-0">Lessons</p>
            <p class="kpi-number text-[#143D59] m-0"><?php echo (int)$lessonsCount; ?></p>
          </div>
        </div>
        <a href="student_subjects.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> Continue learning</a>
      </article>
      <article class="dash-card kpi-card dash-anim delay-3 p-5">
        <div class="flex items-center gap-4">
          <span class="kpi-icon bg-emerald-50 text-emerald-700"><i class="bi bi-check2-circle"></i></span>
          <div>
            <p class="text-sm text-slate-500 m-0">Quiz completed</p>
            <p class="kpi-number text-[#143D59] m-0"><?php echo (int)$quizSubmittedCount; ?></p>
          </div>
        </div>
        <a href="student_subjects.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> View quizzes</a>
      </article>
      <article class="dash-card kpi-card dash-anim delay-3 p-5">
        <div class="flex items-center gap-4">
          <span class="kpi-icon bg-indigo-50 text-indigo-700"><i class="bi bi-clipboard-check"></i></span>
          <div>
            <p class="text-sm text-slate-500 m-0">Preboards done</p>
            <p class="kpi-number text-[#143D59] m-0"><?php echo (int)$preboardsSubmittedCount; ?></p>
          </div>
        </div>
        <a href="student_preboards.php" class="kpi-action mt-4"><i class="bi bi-arrow-right"></i> Open preboards</a>
      </article>
    </section>

    <div class="grid grid-cols-1 xl:grid-cols-[1fr_320px] gap-6">
      <div class="space-y-6 min-w-0">
        <h2 class="section-title dash-anim delay-3"><i class="bi bi-graph-up-arrow"></i> Insights</h2>
        <article class="dash-card dash-anim delay-3 p-5">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
              <h3 class="text-lg font-bold text-[#143D59] m-0">Study activity trend</h3>
              <p class="text-sm text-slate-500 m-0 mt-1">Quiz and preboard submissions over the last 8 weeks.</p>
            </div>
            <div class="grid grid-cols-3 gap-2 text-sm">
              <div class="metric-box">
                <p class="metric-label">8-week activity</p>
                <p class="metric-value"><?php echo (int)$activityLast8Weeks; ?></p>
              </div>
              <div class="metric-box">
                <p class="metric-label">This week</p>
                <p class="metric-value"><?php echo (int)$currentWeekActivity; ?></p>
              </div>
              <div class="metric-box">
                <p class="metric-label">Avg score</p>
                <p class="metric-value"><?php echo (int)$avgQuizScore; ?>%</p>
              </div>
            </div>
          </div>
          <div class="mt-4 h-[240px]">
            <canvas id="dashboardStudyChart" aria-label="Student activity trend"></canvas>
          </div>
        </article>

        <article class="dash-card dash-anim delay-4 p-5">
          <h3 class="text-base font-bold text-[#143D59] m-0 mb-4 flex items-center gap-2"><i class="bi bi-bar-chart-line"></i> Progress indicators</h3>
          <div class="space-y-4">
            <div>
              <div class="flex justify-between text-sm mb-1"><span class="text-slate-600 font-medium">Overall learning progress</span><span class="text-[#1665A0] font-semibold"><?php echo (int)$learningProgressPct; ?>%</span></div>
              <div class="progress-track"><div class="progress-fill" style="width:<?php echo (int)$learningProgressPct; ?>%"></div></div>
            </div>
            <div>
              <?php $quizCoverage = $quizzesCount > 0 ? min(100, (int)round(($quizSubmittedCount / $quizzesCount) * 100)) : 0; ?>
              <div class="flex justify-between text-sm mb-1"><span class="text-slate-600 font-medium">Quiz coverage</span><span class="text-[#1665A0] font-semibold"><?php echo (int)$quizCoverage; ?>%</span></div>
              <div class="progress-track"><div class="progress-fill" style="width:<?php echo (int)$quizCoverage; ?>%"></div></div>
            </div>
            <div>
              <?php $preboardCoverage = $preboardsSubjectsCount > 0 ? min(100, (int)round(($preboardsSubmittedCount / $preboardsSubjectsCount) * 100)) : 0; ?>
              <div class="flex justify-between text-sm mb-1"><span class="text-slate-600 font-medium">Preboards coverage</span><span class="text-[#1665A0] font-semibold"><?php echo (int)$preboardCoverage; ?>%</span></div>
              <div class="progress-track"><div class="progress-fill" style="width:<?php echo (int)$preboardCoverage; ?>%"></div></div>
            </div>
          </div>
        </article>
      </div>

      <aside class="dash-card dash-anim delay-4 overflow-hidden h-fit">
        <div class="px-5 py-4 border-b border-[#d6e8f7] bg-gradient-to-r from-[#f0f7fc] to-white">
          <h3 class="text-base font-bold text-[#143D59] m-0">Focus panel</h3>
          <p class="text-sm text-slate-500 m-0 mt-1">Data-backed reminders for your account.</p>
        </div>
        <div class="p-4 space-y-3">
          <div class="focus-card is-primary">
            <p class="focus-title">In-progress quizzes</p>
            <p class="focus-copy"><?php echo (int)$quizInProgressCount; ?> active quiz attempt<?php echo $quizInProgressCount === 1 ? '' : 's'; ?> ready to continue.</p>
          </div>
          <div class="focus-card">
            <p class="focus-title">Preboards library</p>
            <p class="focus-copy"><?php echo (int)$preboardsSubjectsCount; ?> available preboard subject<?php echo $preboardsSubjectsCount === 1 ? '' : 's'; ?> in your module.</p>
          </div>
          <div class="focus-card">
            <p class="focus-title">Completed assessments</p>
            <p class="focus-copy"><?php echo (int)($quizSubmittedCount + $preboardsSubmittedCount); ?> total submitted attempts in your record.</p>
          </div>
        </div>
      </aside>
    </div>

    <?php if ($info && !empty($info['access_end'])): ?>
      <?php
      $daysLeft = (int)floor((strtotime((string)$info['access_end']) - time()) / 86400);
      $startTs = !empty($info['access_start']) ? strtotime((string)$info['access_start']) : null;
      $endTs = strtotime((string)$info['access_end']);
      $pct = 0;
      if ($startTs && $endTs && $endTs > $startTs) {
          $pct = (int)round(((time() - $startTs) / ($endTs - $startTs)) * 100);
          $pct = max(0, min(100, $pct));
      }
      ?>
      <?php if ($daysLeft > 0): ?>
      <div class="dash-card dash-anim delay-5 mt-6 p-5 flex flex-wrap items-center justify-between gap-3">
        <p class="m-0 flex items-center gap-2 font-semibold text-[#143D59]"><i class="bi bi-calendar-check text-[#1665A0] text-lg"></i> Access active until <?php echo h(date('M j, Y', (int)$endTs)); ?> · <?php echo (int)$daysLeft; ?> day<?php echo $daysLeft === 1 ? '' : 's'; ?> left</p>
        <div class="flex items-center gap-3">
          <div class="w-40 h-2.5 rounded-full bg-[#e8f2fa] overflow-hidden border border-[#cde2f4]"><div class="h-full rounded-full bg-[#1665A0]" style="width:<?php echo (int)$pct; ?>%"></div></div>
          <span class="text-sm font-bold text-[#1665A0]"><?php echo (int)$pct; ?>% used</span>
        </div>
      </div>
      <?php else: ?>
      <div class="dash-card dash-anim delay-5 mt-6 p-5 border-amber-300 bg-amber-50 text-amber-800 flex items-center gap-2">
        <i class="bi bi-exclamation-triangle text-xl"></i> <span class="font-medium">Access expired. Contact admin to renew your access.</span>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
<style>
.student-dashboard-page {
  background: linear-gradient(180deg, #eef5fc 0%, #e4f0fa 45%, #ebf4fc 100%);
}
.student-hero {
  border-radius: 0.75rem;
  border: 1px solid rgba(255,255,255,0.28);
  background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
  box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22);
}
.hero-btn {
  border-radius: 9999px;
  transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
}
.hero-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 22px -20px rgba(14, 64, 105, .95);
}
.hero-strip {
  background: rgba(255,255,255,0.14);
  border: 1px solid rgba(255,255,255,0.24);
  border-radius: 0.62rem;
}
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
.dash-card {
  border-radius: .75rem;
  border: 1px solid rgba(22,101,160,.18);
  background: linear-gradient(180deg, #f8fbff 0%, #ffffff 60%);
  box-shadow: 0 10px 28px -22px rgba(20,61,89,.55), 0 1px 0 rgba(255,255,255,.85) inset;
  transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease, background-color .22s ease;
}
.dash-card:hover {
  transform: translateY(-2px);
  border-color: rgba(22,101,160,.32);
  background-color: #fdfeff;
  box-shadow: 0 20px 34px -24px rgba(20,61,89,.35);
}
.kpi-card { display: flex; flex-direction: column; justify-content: space-between; min-height: 188px; }
.kpi-icon {
  width: 3rem; height: 3rem; border-radius: .75rem;
  display: inline-flex; align-items: center; justify-content: center; font-size: 1.3rem;
}
.kpi-number { font-size: 2rem; font-weight: 800; line-height: 1; letter-spacing: -0.02em; }
.kpi-action {
  width: 100%; border: 1px solid #cde2f4; border-radius: .55rem; background: #fff;
  display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
  font-size: .82rem; font-weight: 700; color: #1665A0; padding: .55rem .7rem;
  transition: all .2s ease;
}
.kpi-action:hover { border-color: #8fc0e8; background: #f4f9fe; transform: translateY(-1px); }
.metric-box {
  padding: .5rem .65rem;
  border-radius: .55rem;
  border: 1px solid #d6e8f7;
  background: #f8fbff;
}
.metric-label { margin: 0; font-size: .66rem; color: #64748b; text-transform: uppercase; font-weight: 700; }
.metric-value { margin: .15rem 0 0; font-size: .95rem; color: #1665A0; font-weight: 800; }
.progress-track {
  height: .65rem; border-radius: 9999px; background: #e8f2fa; overflow: hidden; border: 1px solid #d4e6f6;
}
.progress-fill {
  height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #1665A0 0%, #3b82c4 100%);
}
.focus-card {
  border: 1px solid #d6e8f7;
  border-radius: .62rem;
  background: #f8fbff;
  padding: .75rem .8rem;
}
.focus-card.is-primary {
  border-color: #9ec8e9;
  background: linear-gradient(145deg, #1665A0 0%, #145a8f 85%);
}
.focus-title { margin: 0 0 .2rem; font-size: .75rem; font-weight: 800; color: #143D59; text-transform: uppercase; letter-spacing: .02em; }
.focus-copy { margin: 0; font-size: .77rem; color: #475569; line-height: 1.45; }
.focus-card.is-primary .focus-title, .focus-card.is-primary .focus-copy { color: #fff; }
.dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
.delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; } .delay-4 { animation-delay: .24s; } .delay-5 { animation-delay: .3s; }
@keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) {
  .dash-anim { opacity: 1; transform: none; animation: none; }
  .dash-card, .kpi-action, .hero-btn { transition: none !important; }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function() {
  var canvas = document.getElementById('dashboardStudyChart');
  if (!canvas || typeof Chart === 'undefined') return;
  var ctx = canvas.getContext('2d');
  new Chart(ctx, {
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
        pointBackgroundColor: '#1665A0',
        pointRadius: 3.6,
        pointHoverRadius: 5.6
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { precision: 0, color: '#64748b', font: { size: 11 } },
          grid: { color: 'rgba(22, 101, 160, 0.12)' }
        },
        x: {
          grid: { display: false },
          ticks: { color: '#64748b', font: { size: 11 } }
        }
      }
    }
  });
})();
</script>
</body>
</html>
