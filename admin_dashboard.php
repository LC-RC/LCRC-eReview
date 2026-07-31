<?php
require_once 'auth.php';
requireRole('admin');
$pageTitle = 'Admin Dashboard';

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

$nowSql = date('Y-m-d H:i:s');

$enrolledWhere = "role='student' AND status='approved' AND access_end IS NOT NULL AND access_end >= ?";
$pendingWhere = "role='student' AND status='pending'";
$expiredWhere = "role='student' AND status='approved' AND access_end IS NOT NULL AND access_end < ?";

// Counts (used in hero, needs-attention, and stat cards)
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM users WHERE $enrolledWhere");
mysqli_stmt_bind_param($stmt, 's', $nowSql);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
$enrolledCount = (int)($row['total'] ?? 0);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM users WHERE $pendingWhere");
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
$pendingCount = (int)($row['total'] ?? 0);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM users WHERE $expiredWhere");
mysqli_stmt_bind_param($stmt, 's', $nowSql);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
$expiredCount = (int)($row['total'] ?? 0);
mysqli_stmt_close($stmt);

$subjectsCount = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM subjects");
$subjectsRow = $subjectsCount ? mysqli_fetch_assoc($subjectsCount) : ['cnt' => 0];
$lessonsCount = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM lessons");
$lessonsRow = $lessonsCount ? mysqli_fetch_assoc($lessonsCount) : ['cnt' => 0];
$quizzesCount = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM quizzes");
$quizzesRow = $quizzesCount ? mysqli_fetch_assoc($quizzesCount) : ['cnt' => 0];

// Enrollment trend: last 6 months (student registrations per month)
$enrollmentByMonth = [];
for ($i = 5; $i >= 0; $i--) {
  $ym = date('Y-m', strtotime("-$i months"));
  $enrollmentByMonth[$ym] = 0;
}
$trendRes = @mysqli_query($conn, "
  SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
  FROM users
  WHERE role='student' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
  GROUP BY ym ORDER BY ym
");
if ($trendRes) {
  while ($tr = mysqli_fetch_assoc($trendRes)) {
    $enrollmentByMonth[$tr['ym']] = (int)$tr['cnt'];
  }
  mysqli_free_result($trendRes);
}

// Recent registrations (last 5 students, any status)
$recentStudents = [];
$recentRes = @mysqli_query($conn, "
  SELECT user_id, full_name, email, status, created_at
  FROM users
  WHERE role='student'
  ORDER BY created_at DESC
  LIMIT 5
");
if ($recentRes) {
  while ($r = mysqli_fetch_assoc($recentRes)) {
    $recentStudents[] = $r;
  }
  mysqli_free_result($recentRes);
}

// Expiring soon: enrolled students whose access_end is within the next 30 days (read-only list for UI)
$expiringSoon = [];
$expireRes = @mysqli_query($conn, "
  SELECT user_id, full_name, access_end
  FROM users
  WHERE role='student' AND status='approved' AND access_end IS NOT NULL
    AND access_end >= NOW() AND access_end <= DATE_ADD(NOW(), INTERVAL 30 DAY)
  ORDER BY access_end ASC
  LIMIT 5
");
if ($expireRes) {
  while ($e = mysqli_fetch_assoc($expireRes)) {
    $expiringSoon[] = $e;
  }
  mysqli_free_result($expireRes);
}

// Quiz activity: total quiz answers in last 30 days (read-only metric)
$quizAttemptsLast30 = 0;
$quizRes = @mysqli_query($conn, "
  SELECT COUNT(*) AS cnt FROM quiz_answers WHERE answered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
if ($quizRes && $qr = mysqli_fetch_assoc($quizRes)) {
  $quizAttemptsLast30 = (int)($qr['cnt'] ?? 0);
  mysqli_free_result($quizRes);
}

// New this week (registrations in last 7 days) — for "at a glance" recency
$newThisWeek = 0;
$weekRes = @mysqli_query($conn, "
  SELECT COUNT(*) AS cnt FROM users WHERE role='student' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
if ($weekRes && $wr = mysqli_fetch_assoc($weekRes)) {
  $newThisWeek = (int)($wr['cnt'] ?? 0);
  if ($weekRes) mysqli_free_result($weekRes);
}

// Expiring in next 7 days (for "what to do next")
$expiringIn7 = 0;
$e7Res = @mysqli_query($conn, "
  SELECT COUNT(*) AS cnt FROM users
  WHERE role='student' AND status='approved' AND access_end IS NOT NULL
    AND access_end >= NOW() AND access_end <= DATE_ADD(NOW(), INTERVAL 7 DAY)
");
if ($e7Res && $e7 = mysqli_fetch_assoc($e7Res)) {
  $expiringIn7 = (int)($e7['cnt'] ?? 0);
  mysqli_free_result($e7Res);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-dashboard-page">
  <?php include 'admin_sidebar.php'; ?>

  <?php
    $hour = (int) date('G');
    $dashGreeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $dashFirst = trim(explode(' ', (string)($_SESSION['full_name'] ?? 'Admin'))[0]);
    if ($dashFirst === '') $dashFirst = 'Admin';
  ?>
  <section class="quiz-admin-hero page-hero admin-glass-hero admin-dashboard-hero" aria-labelledby="admin-dash-greeting">
    <div class="admin-page-header">
      <div class="min-w-0">
        <p class="admin-breadcrumb mb-2" style="margin:0 0 0.45rem;color:var(--admin-text-muted);font-size:0.78rem;">LCRC eReview · Admin</p>
        <h1 id="admin-dash-greeting" class="admin-dash-greeting"><span><?php echo h($dashGreeting); ?>, <?php echo h($dashFirst); ?></span></h1>
        <p class="admin-page-header__subtitle">Here’s what’s happening across enrollments, access, and content.</p>
        <?php if ($lastLoginAt): ?>
          <p class="text-sm mt-2 mb-0" style="color:var(--admin-text-muted)"><i class="bi bi-clock-history mr-1"></i>Last login <?php echo date('M j, Y · g:i A', strtotime($lastLoginAt)); ?></p>
        <?php endif; ?>
      </div>
      <div class="admin-page-header__actions">
        <a href="admin_students?tab=pending" class="admin-btn admin-btn--primary"><i class="bi bi-hourglass-split"></i> Review pending<?php echo $pendingCount > 0 ? ' (' . (int)$pendingCount . ')' : ''; ?></a>
        <a href="admin_subjects" class="admin-btn admin-btn--secondary"><i class="bi bi-book"></i> Content Hub</a>
      </div>
    </div>
  </section>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="admin-flash admin-flash--success mb-5 p-4 rounded-xl flex items-center gap-2">
      <i class="bi bi-check-circle-fill"></i>
      <span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="admin-flash admin-flash--error mb-5 p-4 rounded-xl flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <?php if ($pendingCount > 0): ?>
    <div class="admin-dashboard-alert mb-5 p-4 rounded-xl flex items-center gap-4 flex-wrap">
      <div class="flex items-center gap-2 shrink-0">
        <span class="admin-alert-icon w-10 h-10 rounded-full flex items-center justify-center"><i class="bi bi-exclamation-circle text-xl"></i></span>
        <div>
          <div class="font-semibold text-gray-800">Needs attention</div>
          <div class="text-gray-500 text-sm"><?php echo (int)$pendingCount; ?> registration<?php echo $pendingCount === 1 ? '' : 's'; ?> awaiting approval</div>
        </div>
      </div>
      <a href="admin_students?tab=pending" class="admin-btn admin-btn--primary ml-auto"><i class="bi bi-hourglass-split"></i> Review now</a>
    </div>
  <?php endif; ?>

  <section class="admin-dash-kpis" aria-label="Key metrics">
    <a href="admin_students?tab=enrolled" class="dashboard-card dashboard-card--featured dashboard-card--enrolled page-card p-5 flex flex-col no-underline" style="color:inherit">
      <div class="dashboard-card__title"><i class="bi bi-people-fill"></i> Active Students</div>
      <div class="admin-kpi-value"><?php echo (int)$enrolledCount; ?></div>
      <div class="text-sm" style="color:var(--admin-text-secondary)"><?php echo (int)$newThisWeek; ?> new this week · <?php echo (int)$quizAttemptsLast30; ?> quiz answers (30d)</div>
      <span class="dashboard-card__btn mt-auto mt-4 w-full py-2.5 rounded-lg font-semibold border-2 transition flex items-center justify-center gap-2"><i class="bi bi-arrow-right"></i> View enrolled</span>
    </a>
    <a href="admin_students?tab=pending" class="dashboard-card dashboard-card--pending page-card p-5 flex flex-col no-underline" style="color:inherit">
      <div class="dashboard-card__title"><i class="bi bi-hourglass-split"></i> Pending</div>
      <div class="admin-kpi-value"><?php echo (int)$pendingCount; ?></div>
      <span class="dashboard-card__btn mt-auto w-full py-2.5 rounded-lg font-semibold border-2 flex items-center justify-center gap-2">Review</span>
    </a>
    <a href="admin_students?tab=expired" class="dashboard-card dashboard-card--expired page-card p-5 flex flex-col no-underline" style="color:inherit">
      <div class="dashboard-card__title"><i class="bi bi-calendar-x"></i> Expiring / Expired</div>
      <div class="admin-kpi-value"><?php echo (int)$expiredCount; ?></div>
      <div class="text-xs" style="color:var(--admin-text-muted)"><?php echo (int)$expiringIn7; ?> end within 7 days</div>
      <span class="dashboard-card__btn mt-auto w-full py-2.5 rounded-lg font-semibold border-2 flex items-center justify-center gap-2">Open</span>
    </a>
    <a href="admin_subjects" class="dashboard-card dashboard-card--subjects page-card p-5 flex flex-col no-underline" style="color:inherit">
      <div class="dashboard-card__title"><i class="bi bi-book"></i> Courses / Subjects</div>
      <div class="admin-kpi-value"><?php echo (int)$subjectsRow['cnt']; ?></div>
      <div class="text-xs" style="color:var(--admin-text-muted)"><?php echo (int)$lessonsRow['cnt']; ?> lessons · <?php echo (int)$quizzesRow['cnt']; ?> quizzes</div>
      <span class="dashboard-card__btn mt-auto w-full py-2.5 rounded-lg font-semibold border-2 flex items-center justify-center gap-2">Manage</span>
    </a>
  </section>

  <div class="admin-dash-main">
    <div class="page-card p-5">
      <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
        <div>
          <h2 class="page-section-title text-lg m-0">Enrollment / Registration Overview</h2>
          <p class="text-sm mt-1 mb-0" style="color:var(--admin-text-muted)">New student registrations · last 6 months</p>
        </div>
        <p class="text-sm font-semibold m-0" style="color:var(--admin-text)">Total <span class="admin-kpi-number"><?php echo (int)array_sum($enrollmentByMonth); ?></span></p>
      </div>
      <div class="h-72">
        <canvas id="enrollmentChart" aria-label="Enrollment by month"></canvas>
      </div>
    </div>

    <div class="admin-dash-side">
      <div class="page-card p-5">
        <h2 class="page-section-title text-lg m-0 mb-1">Recent Registrations</h2>
        <p class="text-sm mb-3" style="color:var(--admin-text-muted)">Latest sign-ups</p>
        <?php if (empty($recentStudents)): ?>
          <div class="text-sm py-4" style="color:var(--admin-text-muted)"><i class="bi bi-inbox"></i> No registrations yet.</div>
        <?php else: ?>
          <?php foreach ($recentStudents as $rs):
            $st = strtolower((string)$rs['status']);
            $pill = $st === 'approved' ? 'approved' : ($st === 'rejected' ? 'rejected' : 'pending');
          ?>
            <div class="admin-dash-recent-item">
              <div class="min-w-0">
                <a href="admin_student_view?id=<?php echo (int)$rs['user_id']; ?>" class="admin-link font-medium truncate block"><?php echo h($rs['full_name']); ?></a>
                <span class="text-xs" style="color:var(--admin-text-muted)"><?php echo date('M j, Y', strtotime($rs['created_at'])); ?></span>
              </div>
              <span class="admin-status-pill admin-status-pill--<?php echo h($pill); ?>"><?php echo h($rs['status']); ?></span>
            </div>
          <?php endforeach; ?>
          <a href="admin_students" class="mt-3 inline-flex text-sm font-medium admin-link">View all students →</a>
        <?php endif; ?>
      </div>

      <div class="page-card p-5">
        <h2 class="page-section-title text-lg m-0 mb-1">Pending Actions</h2>
        <p class="text-sm mb-3" style="color:var(--admin-text-muted)">What to do next</p>
        <ul class="admin-dash-actions-list">
          <li>
            <a class="admin-dash-action" href="admin_students?tab=pending">
              <span class="admin-dash-action__icon"><i class="bi bi-hourglass-split"></i></span>
              <span class="min-w-0">
                <span class="font-semibold block">Approve registrations</span>
                <span class="admin-dash-action__meta"><?php echo (int)$pendingCount; ?> pending</span>
              </span>
            </a>
          </li>
          <li>
            <a class="admin-dash-action" href="admin_students?tab=enrolled">
              <span class="admin-dash-action__icon"><i class="bi bi-calendar-event"></i></span>
              <span class="min-w-0">
                <span class="font-semibold block">Review expiring access</span>
                <span class="admin-dash-action__meta"><?php echo count($expiringSoon); ?> in next 30 days</span>
              </span>
            </a>
          </li>
          <li>
            <a class="admin-dash-action" href="admin_subjects">
              <span class="admin-dash-action__icon"><i class="bi bi-journal-richtext"></i></span>
              <span class="min-w-0">
                <span class="font-semibold block">Update course content</span>
                <span class="admin-dash-action__meta"><?php echo (int)$subjectsRow['cnt']; ?> subjects</span>
              </span>
            </a>
          </li>
        </ul>
        <?php if (!empty($expiringSoon)): ?>
          <div class="mt-4 pt-3" style="border-top:1px solid var(--admin-border)">
            <p class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--admin-text-muted)">Ending soon</p>
            <?php foreach (array_slice($expiringSoon, 0, 3) as $es): ?>
              <div class="admin-dash-recent-item py-2">
                <a href="admin_student_view?id=<?php echo (int)$es['user_id']; ?>" class="admin-link text-sm truncate"><?php echo h($es['full_name']); ?></a>
                <span class="text-xs" style="color:var(--admin-text-muted)"><?php echo date('M j', strtotime($es['access_end'])); ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
  (function() {
    var chartInstance = null;
    var canvas = document.getElementById('enrollmentChart');
    if (!canvas) return;
    var data = <?php echo json_encode(array_values($enrollmentByMonth)); ?>;
    var labels = <?php echo json_encode(array_map(function($ym) { return date('M Y', strtotime($ym . '-01')); }, array_keys($enrollmentByMonth))); ?>;

    function themeColors() {
      var styles = getComputedStyle(document.documentElement);
      return {
        primary: (styles.getPropertyValue('--admin-chart-bar') || styles.getPropertyValue('--admin-primary') || '#2563eb').trim(),
        muted: (styles.getPropertyValue('--admin-chart-tick') || styles.getPropertyValue('--admin-text-muted') || '#64748b').trim(),
        border: (styles.getPropertyValue('--admin-chart-grid') || styles.getPropertyValue('--admin-border') || 'rgba(30,58,110,0.1)').trim()
      };
    }

    function renderChart() {
      var c = themeColors();
      if (chartInstance) chartInstance.destroy();
      chartInstance = new Chart(canvas, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            label: 'Registrations',
            data: data,
            backgroundColor: c.primary,
            borderColor: c.primary,
            borderWidth: 0,
            borderRadius: 6,
            maxBarThickness: 36
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: {
              beginAtZero: true,
              grid: { color: c.border },
              ticks: { color: c.muted, stepSize: 1 }
            },
            x: {
              grid: { display: false },
              ticks: { color: c.muted, maxRotation: 45 }
            }
          }
        }
      });
    }

    renderChart();
    document.addEventListener('ereview:admin-theme-change', renderChart);
  })();
  </script>
</div>
</main>
</body>
</html>
