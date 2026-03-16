<?php
require_once 'auth.php';
requireRole('student');

$lessonId = sanitizeInt($_GET['lesson_id'] ?? 0);
$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($lessonId <= 0) { header('Location: student_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT l.*, s.subject_name FROM lessons l JOIN subjects s ON s.subject_id=l.subject_id WHERE l.lesson_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $lessonId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$lesson = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
if (!$lesson) { header('Location: student_subjects.php'); exit; }
$subjectId = (int)$lesson['subject_id'];

$selectedVideoId = sanitizeInt($_GET['video'] ?? 0);
$stmt = mysqli_prepare($conn, "SELECT * FROM lesson_videos WHERE lesson_id=? ORDER BY video_id ASC");
mysqli_stmt_bind_param($stmt, 'i', $lessonId);
mysqli_stmt_execute($stmt);
$videosResult = mysqli_stmt_get_result($stmt);

$selectedVideo = null;
if ($selectedVideoId > 0) {
    $stmt2 = mysqli_prepare($conn, "SELECT * FROM lesson_videos WHERE video_id=? AND lesson_id=? LIMIT 1");
    mysqli_stmt_bind_param($stmt2, 'ii', $selectedVideoId, $lessonId);
    mysqli_stmt_execute($stmt2);
    $svRes = mysqli_stmt_get_result($stmt2);
    $selectedVideo = $svRes ? mysqli_fetch_assoc($svRes) : null;
    mysqli_stmt_close($stmt2);
}
if (!$selectedVideo) {
    mysqli_data_seek($videosResult, 0);
    $selectedVideo = mysqli_fetch_assoc($videosResult);
}

$pageTitle = $lesson['subject_name'] . ' - ' . $lesson['title'] . ' - Videos';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>.video-embed { aspect-ratio: 16/9; width: 100%; border: 0; border-radius: 8px; background: #000; }</style>
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-play-circle"></i> <?php echo h($lesson['subject_name']); ?> · <?php echo h($lesson['title']); ?>
    </h1>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
    <div class="lg:col-span-8">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5">
        <?php if ($selectedVideo): ?>
          <?php
            $url = $selectedVideo['video_url'];
            $embed = $url;
            $isLocal = strpos($url, 'uploads/videos/') === 0;
            if (!$isLocal && (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false)) {
              if (preg_match('/(?:v=|\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) $embed = 'https://www.youtube.com/embed/'.$m[1].'?rel=0';
            } elseif (!$isLocal && strpos($url, 'vimeo.com') !== false) {
              if (preg_match('/vimeo.com\/(\d+)/', $url, $m)) $embed = 'https://player.vimeo.com/video/'.$m[1];
            }
          ?>
          <?php if ($isLocal): ?>
            <video class="video-embed" controls><source src="<?php echo h($url); ?>" type="video/mp4">Your browser does not support the video tag.</video>
          <?php else: ?>
            <iframe class="video-embed" src="<?php echo h($embed); ?>" allowfullscreen></iframe>
          <?php endif; ?>
          <h2 class="mt-3 font-semibold text-gray-800"><?php echo h($selectedVideo['video_title'] ?: 'Untitled Video'); ?></h2>
        <?php else: ?>
          <div class="text-center text-gray-500 py-12">
            <i class="bi bi-play-circle text-5xl block mb-3"></i>
            <p>No videos available yet.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="lg:col-span-4">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden max-h-[70vh] overflow-y-auto">
        <div class="px-5 py-4 border-b border-gray-100 font-semibold text-gray-800 flex items-center gap-2"><i class="bi bi-list"></i> Playlist</div>
        <div class="divide-y divide-gray-100">
          <?php mysqli_data_seek($videosResult, 0); while ($v = mysqli_fetch_assoc($videosResult)): $isActive = ($selectedVideo && $v['video_id'] == $selectedVideo['video_id']); ?>
            <a href="student_videos.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&video=<?php echo (int)$v['video_id']; ?>" class="flex items-center gap-2 px-5 py-3 text-left transition <?php echo $isActive ? 'bg-primary/10 text-primary font-semibold border-l-4 border-primary' : 'hover:bg-gray-50 text-gray-700'; ?>">
              <i class="bi bi-play-circle"></i> <?php echo h($v['video_title'] ?: 'Untitled Video'); ?>
            </a>
          <?php endwhile; ?>
          <?php if (mysqli_num_rows($videosResult) == 0): ?>
            <div class="px-5 py-6 text-center text-gray-500">No videos</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>
</div>
</body>
</html>
