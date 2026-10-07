<?php
require_once 'auth.php';
requireAdminPage();
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

$dashCacheTtl = 60;
$dashCacheAt = (int) ($_SESSION['admin_dash_counts_at'] ?? 0);
$dashCache = $_SESSION['admin_dash_counts'] ?? null;
$useDashCache = is_array($dashCache) && $dashCacheAt > 0 && (time() - $dashCacheAt) < $dashCacheTtl;

if ($useDashCache) {
    $enrolledCount = (int) ($dashCache['enrolled'] ?? 0);
    $pendingCount = (int) ($dashCache['pending'] ?? 0);
    $expiredCount = (int) ($dashCache['expired'] ?? 0);
    $subjectsRow = ['cnt' => (int) ($dashCache['subjects'] ?? 0)];
    $lessonsRow = ['cnt' => (int) ($dashCache['lessons'] ?? 0)];
    $quizzesRow = ['cnt' => (int) ($dashCache['quizzes'] ?? 0)];
    $quizAttemptsLast30 = (int) ($dashCache['quiz30'] ?? 0);
    $newThisWeek = (int) ($dashCache['new_week'] ?? 0);
    $expiringIn7 = (int) ($dashCache['expiring7'] ?? 0);
} else {
    require_once __DIR__ . '/includes/commerce_access_gate.php';
    $hasActiveGrantSql = commerce_sql_user_has_active_grant('users.user_id');
    $enrolledWhere = "role='student' AND ({$hasActiveGrantSql})";
    // True registration queue only - not "missing grant" (legacy enrolled are restored separately).
    $pendingWhere = "role='student' AND status='pending' AND NOT ({$hasActiveGrantSql})";
    $expiredWhere = "role='student' AND status='approved' AND access_end IS NOT NULL AND access_end < ? AND NOT ({$hasActiveGrantSql})";

    // Counts (used in hero, needs-attention, and stat cards)
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM users WHERE $enrolledWhere");
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
}

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

if (!$useDashCache) {
  // Quiz activity: total quiz answers in last 30 days (read-only metric)
  $quizAttemptsLast30 = 0;
  $quizRes = @mysqli_query($conn, "
    SELECT COUNT(*) AS cnt FROM quiz_answers WHERE answered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  ");
  if ($quizRes && $qr = mysqli_fetch_assoc($quizRes)) {
    $quizAttemptsLast30 = (int)($qr['cnt'] ?? 0);
    mysqli_free_result($quizRes);
  }

  // New this week (registrations in last 7 days) - for "at a glance" recency
  $newThisWeek = 0;
  $weekRes = @mysqli_query($conn, "
    SELECT COUNT(*) AS cnt FROM users WHERE role='student' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
  ");
  if ($weekRes && $wr = mysqli_fetch_assoc($weekRes)) {
    $newThisWeek = (int)($wr['cnt'] ?? 0);
    mysqli_free_result($weekRes);
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

  if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['admin_dash_counts'] = [
      'enrolled' => $enrolledCount,
      'pending' => $pendingCount,
      'expired' => $expiredCount,
      'subjects' => (int) ($subjectsRow['cnt'] ?? 0),
      'lessons' => (int) ($lessonsRow['cnt'] ?? 0),
      'quizzes' => (int) ($quizzesRow['cnt'] ?? 0),
      'quiz30' => $quizAttemptsLast30,
      'new_week' => $newThisWeek,
      'expiring7' => $expiringIn7,
    ];
    $_SESSION['admin_dash_counts_at'] = time();
  }
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
    $adminBreadcrumbs = [['Dashboard']];
    include __DIR__ . '/includes/admin_breadcrumb.php';
  ?>

  <?php
    require_once __DIR__ . '/includes/format_display_name.php';
    $hour = (int) date('G');
    $dashGreeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $dashFirst = ereview_greeting_display_name($_SESSION['full_name'] ?? '');
    $canStudents = admin_can('students');
    $canSubjects = admin_can('subjects');
    $canQuizzes = admin_can('quizzes');
    $subjectsHref = $canSubjects ? 'admin_subjects' : ($canQuizzes ? 'admin_quizzes' : '#');
    $subjectsLocked = !$canSubjects && !$canQuizzes;
    // Sparkline from real last-6-month enrollment counts only (decorative, no invented stats).
    $sparkVals = array_values(array_map('intval', $enrollmentByMonth));
    $sparkMax = max(1, ...($sparkVals ?: [1]));
    $sparkPts = [];
    $sparkN = count($sparkVals);
    for ($si = 0; $si < $sparkN; $si++) {
      $sx = $sparkN <= 1 ? 0 : ($si / ($sparkN - 1)) * 64;
      $sy = 22 - (($sparkVals[$si] / $sparkMax) * 18);
      $sparkPts[] = round($sx, 1) . ',' . round($sy, 1);
    }
    $sparkPoly = $sparkPts ? implode(' ', $sparkPts) : '0,22 64,22';
    $enrollPrev = $sparkN >= 2 ? (int) $sparkVals[$sparkN - 2] : 0;
    $enrollLast = $sparkN >= 1 ? (int) $sparkVals[$sparkN - 1] : 0;
    $enrollTrendPct = null;
    if ($enrollPrev > 0) {
      $enrollTrendPct = (int) round((($enrollLast - $enrollPrev) / $enrollPrev) * 100);
    }
    $totalStudents = (int) $enrolledCount + (int) $pendingCount + (int) $expiredCount;
    $activeSharePct = $totalStudents > 0 ? round(((int) $enrolledCount / $totalStudents) * 100, 1) : 0;
    $calMonth = new DateTime('first day of this month');
    $calDaysInMonth = (int) $calMonth->format('t');
    $calStartPad = (int) $calMonth->format('w');
    $calTitle = $calMonth->format('F Y');
    $calToday = (int) date('j');
    $calMarkDays = [];
    foreach ($expiringSoon as $esCal) {
      $tsCal = strtotime((string) ($esCal['access_end'] ?? ''));
      if ($tsCal && date('Y-m', $tsCal) === $calMonth->format('Y-m')) {
        $calMarkDays[(int) date('j', $tsCal)] = true;
      }
    }
    $actionPendingN = (int) $pendingCount + (int) $expiringIn7;
  ?>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="admin-flash admin-flash--success mb-4 flex items-center gap-2 rounded-2xl border border-emerald-100 bg-emerald-50 p-3 text-emerald-700">
      <i class="bi bi-check-circle-fill"></i>
      <span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="admin-flash admin-flash--error mb-4 flex items-center gap-2 rounded-2xl border border-rose-100 bg-rose-50 p-3 text-rose-700">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <section class="edupro-dash" aria-label="Dashboard">
    <article class="edupro-hero">
      <svg class="edupro-hero__ribbon" viewBox="0 0 720 132" preserveAspectRatio="xMaxYMid slice" aria-hidden="true" focusable="false">
        <defs>
          <linearGradient id="eduproHeroRibbonA" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="#60a5fa" stop-opacity="0.08"/>
            <stop offset="35%" stop-color="#67e8f9" stop-opacity="0.22"/>
            <stop offset="68%" stop-color="#c4b5fd" stop-opacity="0.28"/>
            <stop offset="100%" stop-color="#7c3aed" stop-opacity="0.16"/>
          </linearGradient>
          <linearGradient id="eduproHeroRibbonB" x1="0.1" y1="0" x2="1" y2="0.8">
            <stop offset="0%" stop-color="#93c5fd" stop-opacity="0.06"/>
            <stop offset="50%" stop-color="#a5b4fc" stop-opacity="0.2"/>
            <stop offset="100%" stop-color="#8b5cf6" stop-opacity="0.14"/>
          </linearGradient>
        </defs>
        <path fill="url(#eduproHeroRibbonA)" d="M250,86 C360,18 470,22 720,38 L720,132 C560,108 430,118 250,86Z"/>
        <path fill="url(#eduproHeroRibbonB)" d="M290,102 C410,42 530,50 720,62 L720,132 C580,116 440,124 290,102Z"/>
      </svg>
      <div class="edupro-hero__left">
        <p class="edupro-greeting__date"><?php echo h(date('l, M j, Y')); ?></p>
        <h1 id="admin-dash-greeting" class="admin-dash-greeting edupro-greeting__title">
          <?php echo h($dashGreeting); ?>, <?php echo h($dashFirst); ?> <span class="edupro-hero__wave" aria-hidden="true">👋</span>
        </h1>
        <p class="edupro-greeting__sub">Here's what's happening across your LMS today.</p>
        <?php if ($lastLoginAt): ?>
          <p class="edupro-greeting__meta"><i class="bi bi-clock-history mr-1"></i>Last login <?php echo date('M j, Y', strtotime($lastLoginAt)); ?> · <?php echo date('g:i A', strtotime($lastLoginAt)); ?></p>
        <?php endif; ?>
      </div>
      <div class="edupro-hero__right">
        <span class="edupro-quote__icon"><i class="bi bi-book"></i></span>
        <div class="edupro-quote__copy">
          <p class="edupro-quote__text">“Education today.<br>Greater tomorrows.”</p>
          <p class="edupro-quote__cite">— Learn. Practice. Achieve.</p>
        </div>
        <span class="edupro-quote__rule" aria-hidden="true"></span>
        <p class="edupro-quote__aside-text">A better version of you is in progress.</p>
        <span class="edupro-quote__go" aria-hidden="true">→</span>
      </div>
    </article>

    <a href="<?php echo $canStudents ? 'admin_students?tab=all' : '#'; ?>" class="edupro-kpi edupro-kpi--blue group relative flex min-h-0 overflow-hidden no-underline<?php echo $canStudents ? '' : ' opacity-50 cursor-not-allowed'; ?>" style="color:inherit"<?php echo $canStudents ? '' : ' aria-disabled="true" onclick="return false;" title="Locked - no access to Students"'; ?>>
      <span class="edupro-icon-tile edupro-icon-tile--blue"><i class="bi bi-people-fill"></i></span>
      <div class="edupro-kpi__body">
        <div class="edupro-kpi__label">Total Students</div>
        <div class="edupro-kpi__value"><?php echo (int)$totalStudents; ?></div>
        <div class="edupro-kpi__hint <?php echo ($enrollTrendPct !== null && $enrollTrendPct >= 0) ? 'is-up' : ''; ?>">
          <?php if ($enrollTrendPct !== null): ?><?php echo $enrollTrendPct >= 0 ? '+' : ''; ?><?php echo (int) $enrollTrendPct; ?>% this month<?php else: ?><?php echo (int)$newThisWeek; ?> new this week<?php endif; ?>
        </div>
      </div>
      <svg class="edupro-kpi__spark" viewBox="0 0 64 28" fill="none" aria-hidden="true">
        <polyline class="edupro-kpi__spark-fill" points="<?php echo h($sparkPoly); ?> 64,28 0,28" />
        <polyline class="edupro-kpi__spark-line" points="<?php echo h($sparkPoly); ?>" />
      </svg>
    </a>
    <a href="<?php echo $canStudents ? 'admin_students?tab=enrolled' : '#'; ?>" class="edupro-kpi edupro-kpi--green group relative flex min-h-0 overflow-hidden no-underline<?php echo $canStudents ? '' : ' opacity-50 cursor-not-allowed'; ?>" style="color:inherit"<?php echo $canStudents ? '' : ' aria-disabled="true" onclick="return false;" title="Locked - no access to Students"'; ?>>
      <span class="edupro-icon-tile edupro-icon-tile--green"><i class="bi bi-lock-fill"></i></span>
      <div class="edupro-kpi__body">
        <div class="edupro-kpi__label">Active Access</div>
        <div class="edupro-kpi__value"><?php echo (int)$enrolledCount; ?></div>
        <div class="edupro-kpi__hint"><?php echo h((string)$activeSharePct); ?>% of total</div>
      </div>
      <svg class="edupro-kpi__spark" viewBox="0 0 64 28" fill="none" aria-hidden="true">
        <polyline class="edupro-kpi__spark-fill" points="<?php echo h($sparkPoly); ?> 64,28 0,28" />
        <polyline class="edupro-kpi__spark-line" points="<?php echo h($sparkPoly); ?>" />
      </svg>
    </a>
    <a href="<?php echo $canStudents ? 'admin_students?tab=pending' : '#'; ?>" class="edupro-kpi edupro-kpi--coral group relative flex min-h-0 overflow-hidden no-underline<?php echo $canStudents ? '' : ' opacity-50 cursor-not-allowed'; ?>" style="color:inherit"<?php echo $canStudents ? '' : ' aria-disabled="true" onclick="return false;" title="Locked - no access to Students"'; ?>>
      <span class="edupro-icon-tile edupro-icon-tile--coral"><i class="bi bi-exclamation-triangle-fill"></i></span>
      <div class="edupro-kpi__body">
        <div class="edupro-kpi__label">Needs Review</div>
        <div class="edupro-kpi__value"><?php echo (int)$pendingCount; ?></div>
        <div class="edupro-kpi__hint">Review now →</div>
      </div>
      <svg class="edupro-kpi__spark" viewBox="0 0 64 28" fill="none" aria-hidden="true">
        <polyline class="edupro-kpi__spark-fill" points="<?php echo h($sparkPoly); ?> 64,28 0,28" />
        <polyline class="edupro-kpi__spark-line" points="<?php echo h($sparkPoly); ?>" />
      </svg>
    </a>
    <a href="<?php echo h($subjectsHref); ?>" class="edupro-kpi edupro-kpi--violet group relative flex min-h-0 overflow-hidden no-underline<?php echo $subjectsLocked ? ' opacity-50 cursor-not-allowed' : ''; ?>" style="color:inherit"<?php echo $subjectsLocked ? ' aria-disabled="true" onclick="return false;" title="Locked - no access to content"' : ''; ?>>
      <span class="edupro-icon-tile edupro-icon-tile--violet"><i class="bi bi-book"></i></span>
      <div class="edupro-kpi__body">
        <div class="edupro-kpi__label">Course Subjects</div>
        <div class="edupro-kpi__value"><?php echo (int)$subjectsRow['cnt']; ?></div>
        <div class="edupro-kpi__hint"><?php echo (int)$lessonsRow['cnt']; ?> lessons · <?php echo (int)$quizzesRow['cnt']; ?> quizzes</div>
      </div>
      <svg class="edupro-kpi__spark" viewBox="0 0 64 28" fill="none" aria-hidden="true">
        <polyline class="edupro-kpi__spark-fill" points="<?php echo h($sparkPoly); ?> 64,28 0,28" />
        <polyline class="edupro-kpi__spark-line" points="<?php echo h($sparkPoly); ?>" />
      </svg>
    </a>
    <div class="edupro-chart-main page-card admin-dash-chart-card relative flex h-full min-h-0 flex-col overflow-hidden p-4 sm:p-5">
      <div class="admin-dash-chart-card__head relative z-[1] mb-3 flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
          <span class="edupro-icon-tile edupro-icon-tile--blue"><i class="bi bi-bar-chart-fill"></i></span>
          <div>
            <h2 class="page-section-title m-0">Enrollment Overview</h2>
            <p class="edupro-section-sub">New students over the last 6 months</p>
          </div>
        </div>
        <span class="edupro-range-pill">Last 6 months</span>
      </div>
      <div class="admin-dash-chart-wrap relative z-[1] h-52">
        <canvas id="enrollmentChart" aria-label="Enrollment by month"></canvas>
      </div>
    </div>

    <div class="edupro-activity page-card admin-dash-panel admin-dash-panel--recent relative flex h-full min-h-0 flex-col overflow-hidden p-4">
      <div class="edupro-panel-head">
        <span class="edupro-icon-tile edupro-icon-tile--blue"><i class="bi bi-activity"></i></span>
        <h2 class="page-section-title m-0">Recent Activity</h2>
        <a href="admin_students" class="edupro-text-action">View all</a>
      </div>
      <?php if (empty($recentStudents)): ?>
        <div class="rounded-xl border border-dashed border-slate-200 py-8 text-center text-xs text-slate-500">No registrations yet.</div>
      <?php else: ?>
        <?php foreach ($recentStudents as $rs):
          $st = strtolower((string)$rs['status']);
          $tileTone = $st === 'approved' ? 'green' : ($st === 'rejected' ? 'coral' : 'orange');
          $initial = function_exists('mb_substr') ? strtoupper(mb_substr(trim((string)$rs['full_name']), 0, 1)) : strtoupper(substr(trim((string)$rs['full_name']), 0, 1));
        ?>
          <div class="admin-dash-recent-item">
            <span class="edupro-icon-tile edupro-icon-tile--<?php echo h($tileTone); ?>"><?php echo h($initial); ?></span>
            <div class="min-w-0 flex-1">
              <a href="admin_student_view?id=<?php echo (int)$rs['user_id']; ?>" class="admin-link edupro-activity-title"><?php echo h($rs['full_name']); ?></a>
              <span class="edupro-activity-meta">New student registered</span>
            </div>
            <time class="admin-dash-recent-time"><?php echo date('g:i A', strtotime($rs['created_at'])); ?></time>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="edupro-action page-card admin-dash-panel admin-dash-panel--actions relative flex h-full min-h-0 flex-col overflow-hidden p-4">
      <div class="edupro-panel-head">
        <span class="edupro-icon-tile edupro-icon-tile--coral"><i class="bi bi-bell"></i></span>
        <h2 class="page-section-title m-0">Action Center</h2>
        <span class="edupro-pending-badge"><?php echo (int)$actionPendingN; ?> pending</span>
      </div>
      <ul class="admin-dash-actions-list m-0 list-none space-y-2 p-0">
        <?php if ($canStudents): ?>
        <li>
          <a class="admin-dash-action admin-dash-action--amber" href="admin_students?tab=pending">
            <span class="edupro-icon-tile edupro-icon-tile--orange"><i class="bi bi-hourglass-split"></i></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-slate-900">Access requires review</span>
              <span class="text-[11px] text-slate-500">Registrations awaiting approval</span>
            </span>
            <span class="admin-dash-action__count"><?php echo (int)$pendingCount; ?></span>
          </a>
        </li>
        <li>
          <a class="admin-dash-action admin-dash-action--violet" href="admin_students?tab=enrolled">
            <span class="edupro-icon-tile edupro-icon-tile--violet"><i class="bi bi-calendar-event"></i></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-slate-900">Review expiring access</span>
              <span class="text-[11px] text-slate-500">Next 30 days</span>
            </span>
            <span class="admin-dash-action__count"><?php echo count($expiringSoon); ?></span>
          </a>
        </li>
        <?php endif; ?>
        <?php if ($canSubjects || $canQuizzes): ?>
        <li>
          <a class="admin-dash-action admin-dash-action--blue" href="<?php echo h($subjectsHref); ?>">
            <span class="edupro-icon-tile edupro-icon-tile--blue"><i class="bi bi-journal-richtext"></i></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-slate-900"><?php echo $canSubjects ? 'Update course content' : 'Manage quizzes'; ?></span>
              <span class="text-[11px] text-slate-500"><?php echo (int)$subjectsRow['cnt']; ?> subjects</span>
            </span>
            <i class="bi bi-chevron-right text-slate-400 text-xs"></i>
          </a>
        </li>
        <?php endif; ?>
        <?php if ($canQuizzes): ?>
        <li>
          <a class="admin-dash-action admin-dash-action--coral" href="admin_quiz_monitor">
            <span class="edupro-icon-tile edupro-icon-tile--coral"><i class="bi bi-exclamation-circle"></i></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-slate-900">Quiz activity</span>
              <span class="text-[11px] text-slate-500"><?php echo (int)$quizAttemptsLast30; ?> answers in 30d</span>
            </span>
            <span class="admin-dash-action__count"><?php echo (int)$quizAttemptsLast30; ?></span>
          </a>
        </li>
        <?php endif; ?>
      </ul>
    </div>
    <div class="edupro-bottom-card page-card p-4">
      <div class="edupro-panel-head">
        <span class="edupro-icon-tile edupro-icon-tile--blue"><i class="bi bi-journal-bookmark"></i></span>
        <h2 class="page-section-title m-0">Content snapshot</h2>
        <a href="<?php echo h($subjectsHref); ?>" class="edupro-text-action">View all</a>
      </div>
      <?php
        $snapRows = [
          ['label' => 'Subjects', 'meta' => (int)$subjectsRow['cnt'] . ' courses', 'n' => (int)$subjectsRow['cnt'], 'tone' => 'blue', 'icon' => 'bi-book'],
          ['label' => 'Lessons', 'meta' => (int)$lessonsRow['cnt'] . ' lessons', 'n' => (int)$lessonsRow['cnt'], 'tone' => 'violet', 'icon' => 'bi-journal-text'],
          ['label' => 'Quizzes', 'meta' => (int)$quizzesRow['cnt'] . ' quizzes', 'n' => (int)$quizzesRow['cnt'], 'tone' => 'green', 'icon' => 'bi-ui-checks-grid'],
          ['label' => 'Quiz answers (30d)', 'meta' => (int)$quizAttemptsLast30 . ' attempts', 'n' => (int)$quizAttemptsLast30, 'tone' => 'orange', 'icon' => 'bi-graph-up'],
        ];
        $snapBarMax = max(1, (int)$subjectsRow['cnt'], (int)$lessonsRow['cnt'], (int)$quizzesRow['cnt'], (int)$quizAttemptsLast30);
      ?>
      <div class="space-y-3">
        <?php foreach ($snapRows as $sr): ?>
          <div class="edupro-subject-row">
            <span class="edupro-icon-tile edupro-icon-tile--<?php echo h($sr['tone']); ?>"><i class="bi <?php echo h($sr['icon']); ?>"></i></span>
            <div class="edupro-subject-row__body">
              <div class="edupro-subject-row__meta">
                <span class="edupro-subject-row__name"><?php echo h($sr['label']); ?></span>
                <span class="edupro-subject-row__pct"><?php echo (int) round(($sr['n'] / $snapBarMax) * 100); ?>%</span>
              </div>
              <div class="edupro-progress">
                <div class="edupro-progress__bar edupro-progress__bar--<?php echo h($sr['tone']); ?>" style="width: <?php echo (int) round(($sr['n'] / $snapBarMax) * 100); ?>%"></div>
              </div>
              <span class="edupro-subject-row__hint"><?php echo h($sr['meta']); ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="edupro-bottom-card page-card p-4">
      <div class="edupro-panel-head">
        <span class="edupro-icon-tile edupro-icon-tile--coral"><i class="bi bi-calendar2-week"></i></span>
        <h2 class="page-section-title m-0">Upcoming Deadlines</h2>
        <?php if ($canStudents): ?><a href="admin_students?tab=enrolled" class="edupro-text-action">View all</a><?php endif; ?>
      </div>
      <?php if (empty($expiringSoon)): ?>
        <p class="m-0 text-xs text-slate-500">No access windows ending in the next 30 days.</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($expiringSoon as $es):
            $endTs = strtotime((string)$es['access_end']);
            $daysLeft = $endTs ? (int) ceil(($endTs - time()) / 86400) : 0;
          ?>
            <div class="edupro-deadline-row">
              <span class="edupro-date-tile">
                <span class="edupro-date-tile__mo"><?php echo $endTs ? date('M', $endTs) : ''; ?></span>
                <span class="edupro-date-tile__day"><?php echo $endTs ? date('j', $endTs) : ''; ?></span>
              </span>
              <div class="min-w-0 flex-1">
                <a href="admin_student_view?id=<?php echo (int)$es['user_id']; ?>" class="admin-link edupro-activity-title"><?php echo h($es['full_name']); ?></a>
                <span class="edupro-activity-meta">Access request deadline</span>
              </div>
              <span class="edupro-days-badge"><?php echo $daysLeft; ?> days</span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="edupro-bottom-card page-card p-4">
      <div class="edupro-panel-head">
        <span class="edupro-icon-tile edupro-icon-tile--violet"><i class="bi bi-calendar3"></i></span>
        <h2 class="page-section-title m-0"><?php echo h($calTitle); ?></h2>
        <span class="edupro-text-action">Today</span>
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
  (function() {
    var chartInstance = null;
    var canvas = document.getElementById('enrollmentChart');
    if (!canvas) return;
    var data = <?php echo json_encode(array_values($enrollmentByMonth)); ?>;
    var labels = <?php echo json_encode(array_map(function($ym) { return date('M Y', strtotime($ym . '-01')); }, array_keys($enrollmentByMonth))); ?>;

    function themeColors() {
      var styles = getComputedStyle(document.documentElement);
      return {
        primary: (styles.getPropertyValue('--admin-chart-bar') || styles.getPropertyValue('--accent-blue') || '#2563eb').trim(),
        primary2: (styles.getPropertyValue('--admin-chart-bar-2') || styles.getPropertyValue('--accent-violet') || '#7657f6').trim(),
        muted: (styles.getPropertyValue('--admin-chart-tick') || styles.getPropertyValue('--text-muted') || '#64748b').trim(),
        border: (styles.getPropertyValue('--admin-chart-grid') || styles.getPropertyValue('--glass-border') || 'rgba(30,58,110,0.1)').trim()
      };
    }

    function renderChart() {
      var c = themeColors();
      if (chartInstance) chartInstance.destroy();
      var ctx = canvas.getContext('2d');
      var grad = ctx.createLinearGradient(0, 0, 0, canvas.clientHeight || 208);
      grad.addColorStop(0, c.primary2);
      grad.addColorStop(1, c.primary);
      chartInstance = new Chart(canvas, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            label: 'Registrations',
            data: data,
            backgroundColor: grad,
            borderColor: 'transparent',
            borderWidth: 0,
            borderRadius: 10,
            borderSkipped: false,
            maxBarThickness: 36
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          layout: { padding: { top: 8, right: 4, left: 0, bottom: 2 } },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: c.primary,
              titleColor: '#f4f7fb',
              bodyColor: '#e8eef4',
              padding: 10,
              cornerRadius: 10,
              displayColors: false
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              grid: { color: c.border, drawBorder: false },
              ticks: { color: c.muted, stepSize: 1, font: { size: 11, weight: '500' } },
              border: { display: false }
            },
            x: {
              grid: { display: false },
              ticks: { color: c.muted, maxRotation: 0, font: { size: 11, weight: '500' } },
              border: { display: false }
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
