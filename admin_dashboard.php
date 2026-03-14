<?php
require_once 'auth.php';
requireRole('admin');
$pageTitle = 'Admin Dashboard';

$csrf = generateCSRFToken();

$lastLoginAt = null;
$uid = getCurrentUserId();
$stmt = @mysqli_prepare($conn, 'SELECT last_login_at FROM users WHERE user_id = ? LIMIT 1');
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    if (@mysqli_stmt_execute($stmt)) {
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($row && !empty($row['last_login_at'])) {
            $lastLoginAt = $row['last_login_at'];
        }
    }
    mysqli_stmt_close($stmt);
}

$nowSql = date('Y-m-d H:i:s');

$enrolledWhere = "role='student' AND status='approved' AND access_end IS NOT NULL AND access_end >= ?";
$pendingWhere = "role='student' AND status='pending'";
$expiredWhere = "role='student' AND status='approved' AND access_end IS NOT NULL AND access_end < ?";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-speedometer2"></i> Admin Dashboard
    </h1>
    <p class="text-gray-500 mt-1">Overview and quick actions.</p>
    <?php if ($lastLoginAt): ?>
    <p class="text-gray-400 text-sm mt-1"><i class="bi bi-clock-history mr-1"></i>Last login: <?php echo date('M j, Y \a\t g:i A', strtotime($lastLoginAt)); ?></p>
    <?php endif; ?>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-green-50 border border-green-200 flex items-center gap-2 text-green-800">
      <i class="bi bi-check-circle-fill"></i>
      <span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <?php
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
  ?>

  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 h-full flex flex-col">
      <div class="text-gray-500 text-sm flex items-center gap-1"><i class="bi bi-check2-circle text-green-500"></i> Enrolled</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3"><?php echo (int)$enrolledCount; ?></div>
      <a href="admin_students.php?tab=enrolled" class="mt-auto w-full py-2.5 rounded-lg font-semibold border-2 border-green-500 text-green-600 hover:bg-green-500 hover:text-white transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> View enrolled
      </a>
    </div>
    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 h-full flex flex-col">
      <div class="text-gray-500 text-sm flex items-center gap-1"><i class="bi bi-hourglass-split text-amber-500"></i> Pending approvals</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3"><?php echo (int)$pendingCount; ?></div>
      <a href="admin_students.php?tab=pending" class="mt-auto w-full py-2.5 rounded-lg font-semibold border-2 border-amber-500 text-amber-600 hover:bg-amber-500 hover:text-white transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> Review pending
      </a>
    </div>
    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 h-full flex flex-col">
      <div class="text-gray-500 text-sm flex items-center gap-1"><i class="bi bi-calendar-x text-gray-500"></i> Expired access</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3"><?php echo (int)$expiredCount; ?></div>
      <a href="admin_students.php?tab=expired" class="mt-auto w-full py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> View expired
      </a>
    </div>
    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 h-full flex flex-col">
      <div class="text-gray-500 text-sm flex items-center gap-1"><i class="bi bi-book text-primary"></i> Subjects</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3"><?php echo (int)$subjectsRow['cnt']; ?></div>
      <a href="admin_subjects.php" class="mt-auto w-full py-2.5 rounded-lg font-semibold border-2 border-primary text-primary hover:bg-primary hover:text-white transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> Manage content
      </a>
    </div>
    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 h-full flex flex-col">
      <div class="text-gray-500 text-sm flex items-center gap-1"><i class="bi bi-file-text text-sky-500"></i> Lessons</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3"><?php echo (int)$lessonsRow['cnt']; ?></div>
      <a href="admin_subjects.php" class="mt-auto w-full py-2.5 rounded-lg font-semibold border-2 border-sky-500 text-sky-600 hover:bg-sky-500 hover:text-white transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> Open Content Hub
      </a>
    </div>
    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 h-full flex flex-col">
      <div class="text-gray-500 text-sm flex items-center gap-1"><i class="bi bi-question-circle text-violet-500"></i> Quizzes</div>
      <div class="text-3xl font-bold text-gray-800 mt-1 mb-3"><?php echo (int)$quizzesRow['cnt']; ?></div>
      <a href="admin_subjects.php" class="mt-auto w-full py-2.5 rounded-lg font-semibold border-2 border-violet-500 text-violet-600 hover:bg-violet-500 hover:text-white transition flex items-center justify-center gap-2">
        <i class="bi bi-arrow-right"></i> Open Content Hub
      </a>
    </div>
  </div>
</main>
</body>
</html>
