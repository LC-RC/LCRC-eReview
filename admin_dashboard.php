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
    <style>
      .admin-dashboard-page .page-hero {
        border: 1px solid #dbeafe;
        background: linear-gradient(135deg, #eff6ff 0%, #ffffff 72%);
        box-shadow: 0 12px 30px -22px rgba(37, 99, 235, 0.35);
      }
      .admin-dashboard-page .page-section-title {
        display: flex;
        align-items: center;
        gap: .5rem;
        margin: 0 0 .85rem;
        padding: .45rem .65rem;
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: .62rem;
        background: #141414 !important;
        color: #e5e7eb !important;
      }
      .admin-dashboard-page .page-section-title i {
        width: 1.55rem;
        height: 1.55rem;
        border-radius: .45rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(255, 255, 255, 0.12);
        background: #1f1f1f;
        color: #d1d5db !important;
        font-size: .83rem;
      }
      .admin-dashboard-page .page-section-title .text-gray-500 {
        color: #9ca3af !important;
      }
      .admin-dashboard-page .page-card {
        border: 1px solid #dbeafe !important;
        background: linear-gradient(180deg, #f8fbff 0%, #ffffff 62%) !important;
        box-shadow: 0 12px 28px -24px rgba(30, 64, 175, 0.3) !important;
        transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
      }
      .admin-dashboard-page .page-card:hover {
        transform: translateY(-2px);
        border-color: #bfdbfe !important;
        box-shadow: 0 20px 34px -24px rgba(30, 64, 175, 0.35) !important;
      }
      .admin-dashboard-page .quick-action-btn {
        border-radius: .6rem;
        font-weight: 700;
      }
    </style>
</head>
<body class="font-sans antialiased admin-app admin-dashboard-page">
  <?php include 'admin_sidebar.php'; ?>

  <div class="admin-dashboard-hero page-hero bg-white rounded-xl shadow-card px-5 py-5 mb-3">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-speedometer2"></i> Admin Dashboard
    </h1>
    <p class="text-gray-500 mt-1">Overview and key numbers at a glance.</p>
    <?php if ($lastLoginAt): ?>
    <p class="text-gray-400 text-sm mt-1"><i class="bi bi-clock-history mr-1"></i>Last login: <?php echo date('M j, Y \a\t g:i A', strtotime($lastLoginAt)); ?></p>
    <?php endif; ?>
    <div class="admin-dashboard-kpi-strip mt-4 mb-0 px-0 py-3 rounded-none flex flex-wrap items-center gap-x-4 gap-y-1 text-sm border-0 border-b-0">
      <span class="font-semibold text-gray-800">Enrolled: <strong class="admin-kpi-number"><?php echo (int)$enrolledCount; ?></strong></span>
      <span class="text-gray-400" aria-hidden="true">·</span>
      <span class="font-semibold text-gray-800">Pending: <strong class="admin-kpi-number"><?php echo (int)$pendingCount; ?></strong></span>
      <span class="text-gray-400" aria-hidden="true">·</span>
      <span class="font-semibold text-gray-800">Expired: <strong class="admin-kpi-number"><?php echo (int)$expiredCount; ?></strong></span>
      <span class="text-gray-400" aria-hidden="true">·</span>
      <span class="text-gray-500">New this week: <strong class="text-gray-400"><?php echo (int)$newThisWeek; ?></strong></span>
    </div>
  </div>

  <div class="admin-dashboard-quick-actions mb-5 flex flex-wrap items-center gap-2 text-sm">
    <span class="text-gray-500 mr-1">Quick actions:</span>
    <a href="admin_students.php" class="quick-action-btn quick-action-btn--students inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg font-medium border-2 transition">Students</a>
    <a href="admin_subjects.php" class="quick-action-btn quick-action-btn--subject inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg font-medium border-2 transition">Content Hub</a>
    <a href="admin_students.php?tab=pending" class="quick-action-btn quick-action-btn--pending inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg font-medium border-2 transition">Pending approvals</a>
  </div>

  <?php if ($pendingCount > 0): ?>
    <div class="admin-dashboard-alert mb-5 p-4 rounded-xl flex items-center gap-4 flex-wrap">
      <div class="flex items-center gap-2 shrink-0">
        <span class="admin-alert-icon w-10 h-10 rounded-full flex items-center justify-center"><i class="bi bi-exclamation-circle text-xl"></i></span>
        <div>
          <div class="font-semibold text-gray-800">Needs attention</div>
          <div class="text-gray-500 text-sm"><?php echo (int)$pendingCount; ?> registration<?php echo $pendingCount === 1 ? '' : 's'; ?> pending approval</div>
        </div>
      </div>
      <a href="admin_students.php?tab=pending" class="admin-alert-btn ml-auto px-4 py-2.5 rounded-lg font-semibold border-2 transition inline-flex items-center gap-2">
        <i class="bi bi-hourglass-split"></i> Review pending
      </a>
    </div>
  <?php endif; ?>

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

  <section class="admin-dashboard-section mt-6 first:mt-0" aria-label="Students overview">
    <h2 class="page-section-title text-lg font-semibold text-gray-700 mb-3 mt-0">
      <i class="bi bi-people-fill admin-section-icon"></i> Students <span class="text-gray-500 font-normal text-sm">(key metrics)</span>
    </h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-2 admin-dashboard-primary-kpis">
    <div class="dashboard-card dashboard-card--enrolled page-card bg-white rounded-xl shadow-card border p-5 h-full flex flex-col">
      <div class="dashboard-card__title text-sm flex items-center gap-1"><i class="bi bi-check2-circle"></i> Enrolled</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3" <?php echo $enrolledCount === 0 ? ' data-zero="true"' : ''; ?>><?php echo (int)$enrolledCount; ?></div>
      <a href="admin_students.php?tab=enrolled" class="dashboard-card__btn mt-auto w-full py-2.5 rounded-lg font-semibold border-2 transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> View enrolled
      </a>
    </div>
    <div class="dashboard-card dashboard-card--pending page-card bg-white rounded-xl shadow-card border p-5 h-full flex flex-col">
      <div class="dashboard-card__title text-sm flex items-center gap-1"><i class="bi bi-hourglass-split"></i> Pending approvals</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3" <?php echo $pendingCount === 0 ? ' data-zero="true"' : ''; ?>><?php echo (int)$pendingCount; ?></div>
      <a href="admin_students.php?tab=pending" class="dashboard-card__btn mt-auto w-full py-2.5 rounded-lg font-semibold border-2 transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> Review pending
      </a>
    </div>
    <div class="dashboard-card dashboard-card--expired page-card bg-white rounded-xl shadow-card border p-5 h-full flex flex-col">
      <div class="dashboard-card__title text-sm flex items-center gap-1"><i class="bi bi-calendar-x"></i> Expired access</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3" <?php echo $expiredCount === 0 ? ' data-zero="true"' : ''; ?>><?php echo (int)$expiredCount; ?></div>
      <a href="admin_students.php?tab=expired" class="dashboard-card__btn mt-auto w-full py-2.5 rounded-lg font-semibold border-2 transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> View expired
      </a>
    </div>
  </div>
  </section>

  <section class="admin-dashboard-section mt-8" aria-label="Content overview">
    <h2 class="page-section-title text-lg font-semibold text-gray-700 mb-3 mt-0">
      <i class="bi bi-grid-3x3-gap admin-section-icon"></i> Content
    </h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-2">
    <div class="dashboard-card dashboard-card--subjects page-card bg-white rounded-xl shadow-card border p-5 h-full flex flex-col">
      <div class="dashboard-card__title text-sm flex items-center gap-1"><i class="bi bi-book"></i> Subjects</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3" <?php echo (int)$subjectsRow['cnt'] === 0 ? ' data-zero="true"' : ''; ?>><?php echo (int)$subjectsRow['cnt']; ?></div>
      <a href="admin_subjects.php" class="dashboard-card__btn mt-auto w-full py-2.5 rounded-lg font-semibold border-2 transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> Manage content
      </a>
    </div>
    <div class="dashboard-card dashboard-card--lessons page-card bg-white rounded-xl shadow-card border p-5 h-full flex flex-col">
      <div class="dashboard-card__title text-sm flex items-center gap-1"><i class="bi bi-file-text"></i> Lessons</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3" <?php echo (int)$lessonsRow['cnt'] === 0 ? ' data-zero="true"' : ''; ?>><?php echo (int)$lessonsRow['cnt']; ?></div>
      <a href="admin_subjects.php" class="dashboard-card__btn mt-auto w-full py-2.5 rounded-lg font-semibold border-2 transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> Open Content Hub
      </a>
    </div>
    <div class="dashboard-card dashboard-card--quizzes page-card bg-white rounded-xl shadow-card border p-5 h-full flex flex-col">
      <div class="dashboard-card__title text-sm flex items-center gap-1"><i class="bi bi-question-circle"></i> Quizzes</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3" <?php echo (int)$quizzesRow['cnt'] === 0 ? ' data-zero="true"' : ''; ?>><?php echo (int)$quizzesRow['cnt']; ?></div>
      <a href="admin_subjects.php" class="dashboard-card__btn mt-auto w-full py-2.5 rounded-lg font-semibold border-2 transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> Open Content Hub
      </a>
    </div>
  </div>
  </section>

  <div class="admin-dashboard-bottom-block mt-10 grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 page-card bg-white rounded-xl shadow-card border border-gray-100 p-5">
      <h2 class="page-section-title text-lg font-semibold text-gray-800 mb-4">
        <i class="bi bi-graph-up-arrow admin-section-icon"></i> Enrollment trend
      </h2>
      <p class="text-gray-500 text-sm mb-2">New student registrations in the last 6 months.</p>
      <p class="admin-dashboard-chart-summary text-sm font-semibold text-gray-800 mb-4">Total: <strong><?php echo (int)array_sum($enrollmentByMonth); ?></strong> registrations</p>
      <div class="h-64">
        <canvas id="enrollmentChart" aria-label="Enrollment by month"></canvas>
      </div>
    </div>
    <div class="page-card bg-white rounded-xl shadow-card border border-gray-100 p-5">
      <h2 class="page-section-title text-lg font-semibold text-gray-800 mb-4">
        <i class="bi bi-people admin-section-icon"></i> Recent registrations
      </h2>
      <p class="text-gray-500 text-sm mb-4">Latest students who signed up.</p>
      <?php if (empty($recentStudents)): ?>
        <div class="text-gray-500 text-sm py-4 flex items-center gap-2"><i class="bi bi-inbox"></i> No registrations yet.</div>
      <?php else: ?>
        <ul class="space-y-3">
          <?php foreach ($recentStudents as $rs): ?>
            <li class="flex items-center justify-between gap-2 py-2 border-b border-gray-100 last:border-0">
              <div class="min-w-0">
                <a href="admin_student_view.php?id=<?php echo (int)$rs['user_id']; ?>" class="admin-link font-medium text-gray-800 truncate block"><?php echo h($rs['full_name']); ?></a>
                <span class="text-gray-500 text-xs"><?php echo date('M j, Y', strtotime($rs['created_at'])); ?></span>
              </div>
              <span class="admin-badge px-2 py-0.5 rounded-full text-xs font-medium"><?php echo h($rs['status']); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <a href="admin_students.php" class="mt-4 block text-center text-sm font-medium admin-link hover:underline">View all students →</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="page-card bg-white rounded-xl shadow-card border border-gray-100 p-5">
      <h2 class="page-section-title text-lg font-semibold text-gray-800 mb-4">
        <i class="bi bi-calendar-event admin-section-icon"></i> Expiring soon
      </h2>
      <p class="text-gray-500 text-sm mb-4">Access ends in the next 30 days. Extend from the student profile.</p>
      <?php if (empty($expiringSoon)): ?>
        <div class="text-gray-500 text-sm py-2 flex items-center gap-2"><i class="bi bi-check2-circle admin-section-icon"></i> No enrollments expiring in the next 30 days.</div>
      <?php else: ?>
        <ul class="space-y-3">
          <?php foreach ($expiringSoon as $es): ?>
            <li class="flex items-center justify-between gap-2 py-2 border-b border-gray-100 last:border-0">
              <div class="min-w-0">
                <a href="admin_student_view.php?id=<?php echo (int)$es['user_id']; ?>" class="admin-link font-medium text-gray-800 truncate block"><?php echo h($es['full_name']); ?></a>
                <span class="text-gray-500 text-xs">Ends <?php echo date('M j, Y', strtotime($es['access_end'])); ?></span>
              </div>
              <a href="admin_student_view.php?id=<?php echo (int)$es['user_id']; ?>" class="admin-outline-btn shrink-0 px-3 py-1.5 rounded-lg text-sm font-medium border-2 transition">View</a>
            </li>
          <?php endforeach; ?>
        </ul>
        <a href="admin_students.php?tab=enrolled" class="mt-4 block text-center text-sm font-medium admin-link hover:underline">View enrolled →</a>
      <?php endif; ?>
    </div>
    <div class="page-card bg-white rounded-xl shadow-card border border-gray-100 p-5">
      <h2 class="page-section-title text-lg font-semibold text-gray-800 mb-4">
        <i class="bi bi-pencil-square admin-section-icon"></i> Quiz activity
      </h2>
      <p class="text-gray-500 text-sm mb-4">Student quiz answers in the last 30 days.</p>
      <div class="flex items-baseline gap-2">
        <span class="text-3xl font-bold text-gray-800"><?php echo (int)$quizAttemptsLast30; ?></span>
        <span class="text-gray-500 text-sm">answers</span>
      </div>
      <a href="admin_subjects.php" class="mt-4 inline-flex items-center gap-2 text-sm font-medium admin-link hover:underline"><i class="bi bi-book"></i> Manage quizzes in Content Hub</a>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
  (function() {
    var ctx = document.getElementById('enrollmentChart');
    if (!ctx) return;
    var data = <?php echo json_encode(array_values($enrollmentByMonth)); ?>;
    var labels = <?php echo json_encode(array_map(function($ym) { return date('M Y', strtotime($ym . '-01')); }, array_keys($enrollmentByMonth))); ?>;
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Registrations',
          data: data,
          backgroundColor: 'rgba(76, 78, 81, 0.7)',
          borderColor: 'rgb(76, 78, 81)',
          borderWidth: 1
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
            grid: { color: 'rgba(255,255,255,0.08)' },
            ticks: { color: '#9ca3af', stepSize: 1 }
          },
          x: {
            grid: { display: false },
            ticks: { color: '#9ca3af', maxRotation: 45 }
          }
        }
      }
    });
  })();
  </script>
</div>
</main>
</body>
</html>
