<?php
require_once 'auth.php';
requireRole('student');

// Optional: enforce access_end check on every page load
$uid = (int)$_SESSION['user_id'];
$ur = mysqli_query($conn, "SELECT access_end FROM users WHERE user_id=" . $uid . " LIMIT 1");
$u = $ur ? mysqli_fetch_assoc($ur) : null;
if ($u && !empty($u['access_end']) && strtotime($u['access_end']) < time()) {
    $_SESSION['error'] = 'Your access has expired.';
    header('Location: index.php');
    exit;
}

$subjectsResult = mysqli_query($conn, "SELECT * FROM subjects WHERE status='active' ORDER BY subject_name ASC");
$pageTitle = 'Subjects';
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
      <i class="bi bi-book"></i> Subjects
    </h1>
    <p class="text-gray-500 mt-1">Choose a subject to view lessons and materials.</p>
  </div>

  <?php if (isset($_SESSION['error'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php if ($subjectsResult && mysqli_num_rows($subjectsResult) > 0): ?>
      <?php while ($s = mysqli_fetch_assoc($subjectsResult)): ?>
        <?php
          $lessonsCount = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM lessons WHERE subject_id=" . (int)$s['subject_id']);
          $lessonsRow = $lessonsCount ? mysqli_fetch_assoc($lessonsCount) : ['cnt' => 0];
        ?>
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 h-full flex flex-col">
          <h2 class="text-lg font-bold text-gray-800 mb-2 flex items-center gap-2">
            <i class="bi bi-book text-primary"></i> <?php echo h($s['subject_name']); ?>
          </h2>
          <p class="text-gray-500 text-sm mb-4 flex-1"><?php echo h($s['description'] ?: 'No description'); ?></p>
          <div class="flex justify-between items-center mb-4">
            <span class="text-gray-500 text-sm"><i class="bi bi-file-text"></i> <?php echo (int)$lessonsRow['cnt']; ?> Lessons</span>
          </div>
          <a href="student_subject.php?subject_id=<?php echo (int)$s['subject_id']; ?>" class="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition">
            <i class="bi bi-arrow-right-circle"></i> Open Subject
          </a>
        </div>
      <?php endwhile; ?>
    <?php else: ?>
      <div class="col-span-full">
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-12 text-center text-gray-500">
          <i class="bi bi-inbox text-5xl block mb-3"></i>
          <p class="text-lg font-semibold">No subjects available yet.</p>
          <p class="text-sm mt-1">Check back later or contact your administrator.</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
