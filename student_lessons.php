<?php
require_once 'auth.php';
requireRole('student');

$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectId <= 0) { header('Location: student_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE subject_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
if (!$subject) { header('Location: student_subjects.php'); exit; }

$lessonsResult = mysqli_query($conn, "SELECT * FROM lessons WHERE subject_id=".$subjectId." ORDER BY lesson_id DESC");
$pageTitle = $subject['subject_name'] . ' - Lessons';
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
      <i class="bi bi-file-text"></i> <?php echo h($subject['subject_name']); ?> - Lessons
    </h1>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <?php if ($lessonsResult && mysqli_num_rows($lessonsResult) > 0): ?>
      <?php while ($l = mysqli_fetch_assoc($lessonsResult)): ?>
        <?php
          $videosCount = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM lesson_videos WHERE lesson_id=".(int)$l['lesson_id']);
          $videosRow = $videosCount ? mysqli_fetch_assoc($videosCount) : ['cnt' => 0];
          $handoutsCount = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM lesson_handouts WHERE lesson_id=".(int)$l['lesson_id']);
          $handoutsRow = $handoutsCount ? mysqli_fetch_assoc($handoutsCount) : ['cnt' => 0];
        ?>
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 h-full flex flex-col">
          <h2 class="text-lg font-bold text-gray-800 mb-2 flex items-center gap-2">
            <i class="bi bi-file-text text-primary"></i> <?php echo h($l['title']); ?>
          </h2>
          <p class="text-gray-500 text-sm mb-4 flex-1"><?php echo h($l['description'] ?: 'No description'); ?></p>
          <div class="flex gap-4 mb-4 text-gray-500 text-sm">
            <span><i class="bi bi-play-circle"></i> <?php echo (int)$videosRow['cnt']; ?> Videos</span>
            <span><i class="bi bi-file-earmark-pdf"></i> <?php echo (int)$handoutsRow['cnt']; ?> Handouts</span>
          </div>
          <a href="student_lesson.php?lesson_id=<?php echo (int)$l['lesson_id']; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition">
            <i class="bi bi-collection-play"></i> Open Lesson Materials
          </a>
        </div>
      <?php endwhile; ?>
    <?php else: ?>
      <div class="col-span-full">
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-12 text-center text-gray-500">
          <i class="bi bi-inbox text-5xl block mb-3"></i>
          <p class="text-lg font-semibold">No lessons available yet.</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
