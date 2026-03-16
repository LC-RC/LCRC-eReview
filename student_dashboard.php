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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-speedometer2"></i> Welcome, <?php echo h($_SESSION['full_name']); ?>
    </h1>
    <p class="text-gray-500 mt-1">Your learning hub — continue where you left off.</p>
    <?php if ($lastLoginAt): ?>
    <p class="text-gray-400 text-sm mt-1"><i class="bi bi-clock-history mr-1"></i>Last login: <?php echo date('M j, Y \a\t g:i A', strtotime($lastLoginAt)); ?></p>
    <?php endif; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
    <div class="lg:col-span-2 bg-white rounded-xl shadow-card border border-gray-100 p-5 flex flex-wrap justify-between items-center gap-4">
      <div>
        <div class="text-gray-500 text-sm">Quick Actions</div>
        <div class="font-semibold text-gray-800">Jump straight into learning</div>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="student_subjects.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition">
          <i class="bi bi-book"></i> Subjects
        </a>
        <a href="student_quizzes.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg font-semibold border-2 border-primary text-primary hover:bg-primary hover:text-white transition">
          <i class="bi bi-question-circle"></i> Quizzes
        </a>
        <a href="student_handouts.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">
          <i class="bi bi-file-earmark-pdf"></i> Handouts
        </a>
        <a href="student_videos.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">
          <i class="bi bi-play-circle"></i> Videos
        </a>
      </div>
    </div>
    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5">
      <div class="text-gray-500 text-sm">Account</div>
      <div class="font-semibold text-gray-800 mb-3"><?php echo h($_SESSION['full_name']); ?></div>
      <a href="logout.php" class="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg font-semibold border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition">
        <i class="bi bi-box-arrow-right"></i> Logout
      </a>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
      <div class="px-5 py-4 border-b border-gray-100 font-semibold text-[#012970] flex items-center gap-2">
        <i class="bi bi-calendar-check"></i> Access Status
      </div>
      <div class="p-5">
        <?php
          $uid = getCurrentUserId();
          $stmt = mysqli_prepare($conn, "SELECT access_start, access_end FROM users WHERE user_id=? LIMIT 1");
          mysqli_stmt_bind_param($stmt, 'i', $uid);
          mysqli_stmt_execute($stmt);
          $result = mysqli_stmt_get_result($stmt);
          $info = mysqli_fetch_assoc($result);
          mysqli_stmt_close($stmt);
        ?>
        <div class="mb-2"><strong>Start:</strong> <?php echo $info && $info['access_start'] ? date('Y-m-d', strtotime($info['access_start'])) : '-'; ?></div>
        <div class="mb-2"><strong>End:</strong> <?php echo $info && $info['access_end'] ? date('Y-m-d', strtotime($info['access_end'])) : '-'; ?></div>
        <?php if ($info && $info['access_end']):
          $daysLeft = floor((strtotime($info['access_end']) - time()) / 86400);
          if ($daysLeft > 0):
            $startTs = $info['access_start'] ? strtotime($info['access_start']) : null;
            $endTs = strtotime($info['access_end']);
            $pct = 0;
            if ($startTs && $endTs && $endTs > $startTs) {
              $pct = (int)round(((time() - $startTs) / ($endTs - $startTs)) * 100);
              $pct = max(0, min(100, $pct));
            }
        ?>
          <div class="mt-3 p-4 rounded-xl bg-sky-50 border border-sky-200 text-sky-800">
            <i class="bi bi-info-circle"></i> <?php echo $daysLeft; ?> days remaining
            <?php if ($startTs): ?>
              <div class="mt-2 h-2 rounded-full bg-sky-200 overflow-hidden">
                <div class="h-full rounded-full bg-sky-500 transition-all" style="width: <?php echo $pct; ?>%;"></div>
              </div>
              <div class="text-sm text-sky-600 mt-1">Access usage: <?php echo $pct; ?>%</div>
            <?php endif; ?>
          </div>
        <?php elseif ($daysLeft <= 0): ?>
          <div class="mt-3 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800">
            <i class="bi bi-exclamation-triangle"></i> Access expired
          </div>
        <?php endif; endif; ?>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
      <div class="px-5 py-4 border-b border-gray-100 font-semibold text-[#012970] flex items-center gap-2">
        <i class="bi bi-graph-up"></i> Overview
      </div>
      <div class="p-5">
        <?php
          $subjectsCount = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM subjects WHERE status='active'");
          $subjectsRow = $subjectsCount ? mysqli_fetch_assoc($subjectsCount) : ['cnt' => 0];
          $quizzesCount = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM quizzes q JOIN subjects s ON s.subject_id=q.subject_id WHERE s.status='active'");
          $quizzesRow = $quizzesCount ? mysqli_fetch_assoc($quizzesCount) : ['cnt' => 0];
        ?>
        <div class="grid grid-cols-2 gap-3">
          <div class="p-4 rounded-xl border border-gray-200 bg-gray-50">
            <div class="text-gray-500 text-sm flex items-center gap-1"><i class="bi bi-book text-primary"></i> Subjects</div>
            <div class="text-2xl font-bold text-gray-800 mt-1"><?php echo (int)$subjectsRow['cnt']; ?></div>
            <div class="text-sm text-gray-500">Available</div>
          </div>
          <div class="p-4 rounded-xl border border-gray-200 bg-gray-50">
            <div class="text-gray-500 text-sm flex items-center gap-1"><i class="bi bi-question-circle text-sky-500"></i> Quizzes</div>
            <div class="text-2xl font-bold text-gray-800 mt-1"><?php echo (int)$quizzesRow['cnt']; ?></div>
            <div class="text-sm text-gray-500">Available</div>
          </div>
        </div>
        <a href="student_subjects.php" class="mt-4 w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition">
          <i class="bi bi-play-circle"></i> Continue Learning
        </a>
      </div>
    </div>
  </div>
</main>
</body>
</html>
