<?php
require_once 'auth.php';
requireRole('student');
$pageTitle = 'Student Dashboard';
$csrf = generateCSRFToken();

$lastLoginAt = null;
$uid = getCurrentUserId();
try {
    $stmt = mysqli_prepare($conn, 'SELECT last_login_at FROM users WHERE user_id = ? LIMIT 1');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $uid);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            if ($row && !empty($row['last_login_at'])) {
                $lastLoginAt = $row['last_login_at'];
            }
        }
        mysqli_stmt_close($stmt);
    }
} catch (mysqli_sql_exception $e) {
    // last_login_at column may not exist yet; run add_last_login.sql to add it
}

$info = null;
$stmt = mysqli_prepare($conn, "SELECT access_start, access_end FROM users WHERE user_id=? LIMIT 1");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $info = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

// Time-based greeting for a more personal welcome
$hour = (int)date('G');
if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>
  <?php include 'student_topbar.php'; ?>

  <?php
    $subjectsCount = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM subjects WHERE status='active'");
    $subjectsRow = $subjectsCount ? mysqli_fetch_assoc($subjectsCount) : ['cnt' => 0];
    $lessonsCount = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM lessons l JOIN subjects s ON s.subject_id=l.subject_id WHERE s.status='active'");
    $lessonsRow = $lessonsCount ? mysqli_fetch_assoc($lessonsCount) : ['cnt' => 0];
    // Preboards subjects (separate module)
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preboards_subjects (
      preboards_subject_id INT AUTO_INCREMENT PRIMARY KEY,
      subject_name VARCHAR(150) NOT NULL,
      description TEXT NULL,
      status ENUM('active','inactive') NOT NULL DEFAULT 'active',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_preboards_subject_name (subject_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $preboardsCount = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM preboards_subjects WHERE status='active'");
    $preboardsRow = $preboardsCount ? mysqli_fetch_assoc($preboardsCount) : ['cnt' => 0];
  ?>
  <div class="student-dashboard-page min-h-full pb-8">
    <!-- Welcome hero: blue theme, gradient, elevation -->
    <section class="dashboard-welcome-hero relative overflow-hidden rounded-2xl mb-6 px-6 py-6 sm:py-8 border-0 shadow-[0_8px_30px_rgba(20,61,89,0.25),0_0_0_1px_rgba(255,255,255,0.08)_inset]" aria-label="Welcome">
      <div class="absolute inset-0 bg-gradient-to-br from-[#1665A0] via-[#145a8f] to-[#143D59] opacity-100"></div>
      <div class="absolute top-0 right-0 w-72 h-72 sm:w-96 sm:h-96 rounded-full bg-white/10 -translate-y-1/2 translate-x-1/2"></div>
      <div class="absolute bottom-0 left-0 w-56 h-56 rounded-full bg-white/5 -translate-x-1/2 translate-y-1/2"></div>
      <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">
        <div>
          <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-4 text-white drop-shadow-sm">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white/20 backdrop-blur-sm border border-white/30 text-white shadow-lg">
              <i class="bi bi-person-badge text-2xl" aria-hidden="true"></i>
            </span>
            <span><?php echo h($greeting); ?>, <?php echo h($_SESSION['full_name']); ?></span>
          </h1>
          <p class="text-white/95 text-base sm:text-lg mt-3 mb-0 font-medium max-w-xl">Your learning hub — continue where you left off.</p>
        </div>
        <?php if ($lastLoginAt): ?>
        <div class="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white/15 backdrop-blur-sm border border-white/20 text-white/95 text-sm font-medium shrink-0">
          <i class="bi bi-clock-history text-lg opacity-90" aria-hidden="true"></i>
          <span>Last login: <?php echo date('M j, Y \a\t g:i A', strtotime($lastLoginAt)); ?></span>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- 1. Quick Access Cards (Top Section) -->
    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6" aria-label="Quick Access">
      <a href="student_subjects.php" class="dashboard-quick-card group block rounded-2xl p-5 shadow-[0_2px_8px_rgba(20,61,89,0.12),0_4px_16px_rgba(20,61,89,0.08)] hover:shadow-[0_8px_24px_rgba(20,61,89,0.18)] hover:-translate-y-0.5 transition-all duration-300 bg-gradient-to-br from-[#d4e8f7] to-[#e8f2fa] border border-[#1665A0]/20">
        <div class="flex items-start justify-between">
          <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-[#1665A0] text-white shadow-lg shadow-[#1665A0]/30 group-hover:scale-105 transition-transform">
            <i class="bi bi-graph-up-arrow text-xl" aria-hidden="true"></i>
          </span>
          <i class="bi bi-three-dots-vertical text-[#143D59]/50 text-lg" aria-hidden="true"></i>
        </div>
        <h2 class="text-base font-bold text-[#143D59] mt-3 mb-1">My Review Progress</h2>
        <p class="text-sm text-[#143D59]/80 m-0">Track lessons & subjects completed</p>
        <p class="text-xs text-[#1665A0] font-medium mt-2"><?php echo (int)$lessonsRow['cnt']; ?> lessons available</p>
      </a>
      <a href="student_subjects.php" class="dashboard-quick-card group block rounded-2xl p-5 shadow-[0_2px_8px_rgba(20,61,89,0.2)] hover:shadow-[0_8px_28px_rgba(20,61,89,0.35)] hover:-translate-y-0.5 transition-all duration-300 bg-[#1665A0] text-white border border-[#145a8f]">
        <div class="flex items-start justify-between">
          <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/25 text-white shadow-lg group-hover:scale-105 transition-transform">
            <i class="bi bi-journal-bookmark text-xl" aria-hidden="true"></i>
          </span>
          <i class="bi bi-three-dots-vertical text-white/70 text-lg" aria-hidden="true"></i>
        </div>
        <h2 class="text-base font-bold mt-3 mb-1">My Enrolled Review</h2>
        <p class="text-sm text-white/90 m-0">Subjects you're enrolled in</p>
        <p class="text-xs text-white/80 mt-2"><?php echo (int)$subjectsRow['cnt']; ?> subjects</p>
      </a>
      <a href="student_preboards.php" class="dashboard-quick-card group block rounded-2xl p-5 shadow-[0_2px_8px_rgba(20,61,89,0.2)] hover:shadow-[0_8px_28px_rgba(20,61,89,0.35)] hover:-translate-y-0.5 transition-all duration-300 bg-[#143D59] text-white border border-[#143D59]">
        <div class="flex items-start justify-between">
          <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-[#1665A0] text-white shadow-lg group-hover:scale-105 transition-transform">
            <i class="bi bi-clipboard-check text-xl" aria-hidden="true"></i>
          </span>
          <i class="bi bi-three-dots-vertical text-white/70 text-lg" aria-hidden="true"></i>
        </div>
        <h2 class="text-base font-bold mt-3 mb-1">My Preboards</h2>
        <p class="text-sm text-white/90 m-0">Preboard subjects & preparation</p>
        <p class="text-xs text-white/80 mt-2"><?php echo (int)$preboardsRow['cnt']; ?> preboard subject<?php echo ((int)$preboardsRow['cnt'] === 1 ? '' : 's'); ?></p>
      </a>
    </section>

    <!-- Main content grid: left column (chart + overview cards) + right panel -->
    <div class="grid grid-cols-1 xl:grid-cols-[1fr_320px] gap-6">
      <div class="space-y-6 min-w-0">
        <!-- 2. Progress Analytics Section -->
        <article class="rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1),0_4px_16px_rgba(20,61,89,0.06)] overflow-hidden transition-shadow duration-300 hover:shadow-[0_6px_20px_rgba(20,61,89,0.14)] bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0]">
          <div class="px-6 py-4 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50">
            <h2 class="text-lg font-bold text-[#143D59] m-0">Student Study Progress</h2>
            <p class="text-sm text-[#143D59]/70 mt-0.5 mb-0">Weekly activity overview</p>
          </div>
          <div class="p-6 bg-white/60">
            <div class="h-[260px] w-full" aria-hidden="true">
              <canvas id="dashboardStudyChart" width="400" height="260"></canvas>
            </div>
            <div class="flex flex-wrap gap-4 mt-4 pt-4 border-t border-[#1665A0]/10 text-sm">
              <span class="flex items-center gap-2 text-[#143D59]/80"><span class="w-3 h-3 rounded-full bg-[#1665A0] shadow-sm"></span> Study activity</span>
              <span class="flex items-center gap-2 text-[#143D59]/80"><span class="w-3 h-3 rounded-full bg-[#89CFF0] shadow-sm"></span> Quizzes completed</span>
            </div>
          </div>
        </article>

        <!-- 3. Overview Cards Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Study Statistics -->
          <article class="rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1)] overflow-hidden transition-shadow duration-300 hover:shadow-[0_6px_20px_rgba(20,61,89,0.14)] bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0]">
            <div class="px-6 py-4 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50 flex items-center gap-3">
              <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#1665A0] text-white shadow-lg shadow-[#1665A0]/25">
                <i class="bi bi-bar-chart-steps text-lg" aria-hidden="true"></i>
              </span>
              <h2 class="text-base font-bold text-[#143D59] m-0">Study Statistics</h2>
            </div>
            <div class="p-6 space-y-4 bg-white/50">
              <?php
                $totalLessons = (int)$lessonsRow['cnt'];
                $lessonsPct = $totalLessons > 0 ? min(100, (int)round(($totalLessons > 20 ? 12 : $totalLessons * 0.6) / $totalLessons * 100)) : 0;
                $totalPreboards = (int)($preboardsRow['cnt'] ?? 0);
                $preboardsPct = $totalPreboards > 0 ? min(100, (int)round(min(2, $totalPreboards) / max(1, $totalPreboards) * 100)) : 0;
              ?>
              <div>
                <div class="flex justify-between text-sm mb-1"><span class="text-[#143D59] font-medium">Lessons completed</span><span class="text-[#1665A0] font-semibold"><?php echo $lessonsPct; ?>%</span></div>
                <div class="h-2.5 rounded-full bg-[#e8f2fa] overflow-hidden"><div class="h-full rounded-full bg-[#1665A0] transition-all duration-500" style="width:<?php echo $lessonsPct; ?>%"></div></div>
              </div>
              <div>
                <div class="flex justify-between text-sm mb-1"><span class="text-[#143D59] font-medium">Preboards progress</span><span class="text-[#1665A0] font-semibold"><?php echo $preboardsPct; ?>%</span></div>
                <div class="h-2.5 rounded-full bg-[#e8f2fa] overflow-hidden"><div class="h-full rounded-full bg-[#1665A0] transition-all duration-500" style="width:<?php echo $preboardsPct; ?>%"></div></div>
              </div>
              <div>
                <div class="flex justify-between text-sm mb-1"><span class="text-[#143D59] font-medium">Subjects enrolled</span><span class="text-[#1665A0] font-semibold"><?php echo min(100, (int)$subjectsRow['cnt'] * 10); ?>%</span></div>
                <div class="h-2.5 rounded-full bg-[#e8f2fa] overflow-hidden"><div class="h-full rounded-full bg-[#1665A0] transition-all duration-500" style="width:<?php echo min(100, (int)$subjectsRow['cnt'] * 10); ?>%"></div></div>
              </div>
            </div>
          </article>

          <!-- Exam Readiness -->
          <article class="rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1)] overflow-hidden transition-shadow duration-300 hover:shadow-[0_6px_20px_rgba(20,61,89,0.14)] bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#143D59]">
            <div class="px-6 py-4 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50 flex items-center gap-3">
              <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#143D59] text-white shadow-lg shadow-[#143D59]/25">
                <i class="bi bi-award text-lg" aria-hidden="true"></i>
              </span>
              <h2 class="text-base font-bold text-[#143D59] m-0">Exam Readiness</h2>
            </div>
            <div class="p-6 flex flex-col items-center gap-6 bg-white/50">
              <?php
                $readinessPct = min(100, $lessonsPct + $preboardsPct > 0 ? (int)round(($lessonsPct + $preboardsPct) / 2) : 0);
                $subjectProgress = $totalLessons > 0 ? min(100, (int)round(($subjectsRow['cnt'] ?? 0) * 25)) : 0;
              ?>
              <div class="flex flex-wrap justify-center gap-8">
                <div class="text-center">
                  <div class="relative w-24 h-24 rounded-full flex items-center justify-center shadow-inner" style="background: conic-gradient(#1665A0 <?php echo $readinessPct * 3.6; ?>deg, #e8f2fa 0deg);">
                    <div class="absolute inset-2 rounded-full bg-[#f0f7fc] flex items-center justify-center border-2 border-[#1665A0]/20"><span class="text-xl font-bold text-[#143D59]"><?php echo $readinessPct; ?>%</span></div>
                  </div>
                  <p class="text-sm font-medium text-[#143D59]/80 mt-2 mb-0">CPA Readiness</p>
                </div>
                <div class="text-center">
                  <div class="relative w-24 h-24 rounded-full flex items-center justify-center shadow-inner" style="background: conic-gradient(#143D59 <?php echo $subjectProgress * 3.6; ?>deg, #e8f2fa 0deg);">
                    <div class="absolute inset-2 rounded-full bg-[#f0f7fc] flex items-center justify-center border-2 border-[#1665A0]/20"><span class="text-xl font-bold text-[#143D59]"><?php echo $subjectProgress; ?>%</span></div>
                  </div>
                  <p class="text-sm font-medium text-[#143D59]/80 mt-2 mb-0">Subject progress</p>
                </div>
              </div>
            </div>
          </article>
        </div>
      </div>

      <!-- 4. Right-Side Insight Panel -->
      <aside class="rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1)] overflow-hidden transition-shadow duration-300 hover:shadow-[0_6px_20px_rgba(20,61,89,0.14)] bg-gradient-to-b from-[#f0f7fc] to-white xl:max-w-[320px] order-first xl:order-last border-l-4 border-l-[#1665A0]">
        <div class="px-5 py-4 border-b border-[#1665A0]/10 bg-[#e8f2fa]/60">
          <h2 class="text-base font-bold text-[#143D59] m-0">Preview & insights</h2>
          <p class="text-sm text-[#143D59]/70 mt-0.5 mb-0">Schedules, tips & reminders</p>
        </div>
        <div class="p-4 space-y-3 bg-white/40">
          <div class="rounded-xl p-4 bg-gradient-to-br from-[#1665A0] to-[#143D59] text-white shadow-lg shadow-[#1665A0]/25">
            <h3 class="font-bold text-sm m-0 mb-1">Upcoming review</h3>
            <p class="text-xs text-white/90 m-0">Stick to your weekly study plan. Next: <?php echo (int)$subjectsRow['cnt']; ?> subjects to cover.</p>
          </div>
          <div class="rounded-xl p-4 bg-[#d4e8f7] border border-[#1665A0]/25 text-[#143D59]">
            <h3 class="font-bold text-sm m-0 mb-1">Reminder</h3>
            <p class="text-xs text-[#143D59]/90 m-0">Complete at least one quiz this week to keep your streak.</p>
          </div>
          <div class="rounded-xl p-4 bg-[#d4e8f7] border border-[#1665A0]/25 text-[#143D59]">
            <h3 class="font-bold text-sm m-0 mb-1">Announcement</h3>
            <p class="text-xs text-[#143D59]/90 m-0">New handouts and videos have been added. Check your subjects.</p>
          </div>
          <div class="rounded-xl p-4 bg-gradient-to-br from-[#145a8f] to-[#143D59] text-white shadow-lg shadow-[#143D59]/25">
            <h3 class="font-bold text-sm m-0 mb-1">CPA tip</h3>
            <p class="text-xs text-white/90 m-0">Review time management strategies for exam day. Practice under timed conditions.</p>
          </div>
        </div>
      </aside>
    </div>

    <!-- Access status (compact, below main grid) -->
    <?php if ($info && !empty($info['access_end'])): ?>
      <?php $daysLeft = floor((strtotime($info['access_end']) - time()) / 86400); ?>
      <?php if ($daysLeft > 0):
        $startTs = $info['access_start'] ? strtotime($info['access_start']) : null;
        $endTs = strtotime($info['access_end']);
        $pct = 0;
        if ($startTs && $endTs && $endTs > $startTs) {
          $pct = (int)round(((time() - $startTs) / ($endTs - $startTs)) * 100);
          $pct = max(0, min(100, $pct));
        }
      ?>
    <div class="mt-6 p-5 rounded-2xl border border-[#1665A0]/25 bg-gradient-to-r from-[#e8f2fa] to-[#d4e8f7] text-[#143D59] flex flex-wrap items-center justify-between gap-3 shadow-[0_2px_8px_rgba(20,61,89,0.08)]">
      <p class="m-0 flex items-center gap-2 font-semibold"><i class="bi bi-calendar-check text-[#1665A0] text-lg"></i> Access: <?php echo date('Y-m-d', strtotime($info['access_start'])); ?> – <?php echo date('Y-m-d', $endTs); ?> · <?php echo $daysLeft; ?> days remaining</p>
      <div class="flex items-center gap-3">
        <div class="w-36 h-2.5 rounded-full bg-white/80 overflow-hidden border border-[#1665A0]/20"><div class="h-full rounded-full bg-[#1665A0] transition-all duration-500" style="width:<?php echo $pct; ?>%"></div></div>
        <span class="text-sm font-bold text-[#1665A0]"><?php echo $pct; ?>% used</span>
      </div>
    </div>
      <?php elseif ($daysLeft <= 0): ?>
    <div class="mt-6 p-5 rounded-2xl bg-amber-50 border border-amber-300 text-amber-800 flex items-center gap-2 shadow-[0_2px_8px_rgba(20,61,89,0.06)]">
      <i class="bi bi-exclamation-triangle text-xl"></i> <span class="font-medium">Access expired. Contact admin to renew.</span>
    </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  </div>
</main>
</div>
<style>
.student-dashboard-page {
  background: linear-gradient(180deg, #eef5fc 0%, #e3eef8 35%, #dceaf6 60%, #e8f2fa 100%);
}
.dashboard-welcome-hero {
  min-height: 120px;
}
.dashboard-quick-card { transition: box-shadow 0.3s ease, transform 0.3s ease; }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function() {
  var canvas = document.getElementById('dashboardStudyChart');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var primary = '#1665A0';
  var light = '#89CFF0';
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6', 'Week 7', 'Week 8'],
      datasets: [
        {
          label: 'Study activity',
          data: [12, 19, 14, 22, 18, 24, 20, 26],
          borderColor: primary,
          backgroundColor: 'rgba(22, 101, 160, 0.08)',
          fill: true,
          tension: 0.35,
          pointBackgroundColor: primary,
          pointRadius: 4,
          pointHoverRadius: 6
        },
        {
          label: 'Quizzes completed',
          data: [3, 5, 4, 7, 6, 8, 5, 9],
          borderColor: light,
          backgroundColor: 'rgba(137, 207, 240, 0.12)',
          fill: true,
          tension: 0.35,
          pointBackgroundColor: light,
          pointRadius: 4,
          pointHoverRadius: 6
        }
      ]
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
          grid: { color: 'rgba(0,0,0,0.06)' },
          ticks: { color: '#64748b', font: { size: 11 } }
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
