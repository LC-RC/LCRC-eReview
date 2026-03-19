<?php
require_once 'auth.php';
requireRole('admin');

$subjectId = (int)($_GET['subject_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);
if ($lessonId <= 0) { header('Location: admin_subjects.php'); exit; }

$lessonRes = mysqli_query($conn, "SELECT l.*, s.subject_name FROM lessons l JOIN subjects s ON s.subject_id=l.subject_id WHERE l.lesson_id=".$lessonId." LIMIT 1");
$lesson = $lessonRes ? mysqli_fetch_assoc($lessonRes) : null;
if (!$lesson) { header('Location: admin_subjects.php'); exit; }
$subjectId = (int)$lesson['subject_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $videoId = (int)($_POST['video_id'] ?? 0);
    $title = trim($_POST['video_title'] ?? '');
    $url = trim($_POST['video_url'] ?? '');
    $uploadType = $_POST['upload_type'] ?? 'url';
    $finalUrl = $url;
    if ($uploadType === 'file' && isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
        $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'videos';
        if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0777, true);
        $originalName = $_FILES['video_file']['name'];
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        $fileName = 'video_' . uniqid('', true) . ($safeExt ? ('.' . $safeExt) : '');
        $target = $uploadsDir . DIRECTORY_SEPARATOR . $fileName;
        if (move_uploaded_file($_FILES['video_file']['tmp_name'], $target)) {
            $finalUrl = 'uploads/videos/' . $fileName;
        }
    }
    if ($finalUrl !== '') {
        if ($videoId > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE lesson_videos SET video_title=?, video_url=? WHERE video_id=? AND lesson_id=?");
            mysqli_stmt_bind_param($stmt, 'ssii', $title, $finalUrl, $videoId, $lessonId);
            mysqli_stmt_execute($stmt);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO lesson_videos (lesson_id, video_title, video_url) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iss', $lessonId, $title, $finalUrl);
            mysqli_stmt_execute($stmt);
        }
    }
    header('Location: admin_videos.php?lesson_id='.$lessonId.'&subject_id='.$subjectId);
    exit;
}

if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    $delRes = mysqli_query($conn, "SELECT video_url FROM lesson_videos WHERE video_id=".$delId." AND lesson_id=".$lessonId." LIMIT 1");
    $delVideo = $delRes ? mysqli_fetch_assoc($delRes) : null;
    if ($delVideo && strpos($delVideo['video_url'], 'uploads/videos/') === 0 && file_exists($delVideo['video_url'])) {
        unlink($delVideo['video_url']);
    }
    mysqli_query($conn, "DELETE FROM lesson_videos WHERE video_id=".$delId." AND lesson_id=".$lessonId);
    header('Location: admin_videos.php?lesson_id='.$lessonId.'&subject_id='.$subjectId);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $r = mysqli_query($conn, "SELECT * FROM lesson_videos WHERE video_id=".$eid." AND lesson_id=".$lessonId." LIMIT 1");
    $edit = $r ? mysqli_fetch_assoc($r) : null;
}

$videos = mysqli_query($conn, "SELECT * FROM lesson_videos WHERE lesson_id=".$lessonId." ORDER BY video_id DESC");
$pageTitle = 'Videos - ' . $lesson['title'];
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Content Hub', 'admin_subjects.php'], [ h($lesson['subject_name']), 'admin_lessons.php?subject_id=' . $subjectId ], [ h($lesson['title']), 'admin_lessons.php?subject_id=' . $subjectId ], ['Videos'] ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app" x-data="{ uploadType: '<?php echo (isset($edit) && strpos($edit['video_url'] ?? '', 'uploads/videos/') === 0) ? 'file' : 'url'; ?>' }">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-play-circle"></i> Videos - <?php echo h($lesson['title']); ?> (<span class="admin-subject-text"><?php echo h($lesson['subject_name']); ?></span>)
    </h1>
    <p class="text-gray-500 mt-1">Add or edit video links or uploads for this lesson.</p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-4 mb-5">
    <div></div>
    <div class="flex gap-2">
      <a href="admin_lessons.php?subject_id=<?php echo (int)$subjectId; ?>" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Back to Lessons</a>
      <a href="admin_videos.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition">New Video</a>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
    <div class="lg:col-span-5">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5">
        <h2 class="text-lg font-bold text-gray-800 mb-4"><?php echo $edit ? 'Edit Video' : 'Add Video'; ?></h2>
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
          <?php if ($edit): ?><input type="hidden" name="video_id" value="<?php echo (int)$edit['video_id']; ?>"><?php endif; ?>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
            <input type="text" name="video_title" value="<?php echo h($edit['video_title'] ?? ''); ?>" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Upload Type</label>
            <select name="upload_type" x-model="uploadType" class="input-custom">
              <option value="url" <?php echo (!isset($edit) || strpos($edit['video_url'] ?? '', 'http') === 0) ? 'selected' : ''; ?>>URL (YouTube/Vimeo/Link)</option>
              <option value="file" <?php echo (isset($edit) && strpos($edit['video_url'] ?? '', 'uploads/videos/') === 0) ? 'selected' : ''; ?>>Upload Video File</option>
            </select>
          </div>
          <div x-show="uploadType === 'url'" x-cloak>
            <label class="block text-sm font-medium text-gray-700 mb-1">Video URL</label>
            <input type="url" name="video_url" placeholder="https://..." value="<?php echo (isset($edit) && strpos($edit['video_url'] ?? '', 'http') === 0) ? h($edit['video_url']) : ''; ?>" class="input-custom">
          </div>
          <div x-show="uploadType === 'file'" x-cloak>
            <label class="block text-sm font-medium text-gray-700 mb-1">Upload Video File</label>
            <input type="file" name="video_file" class="input-custom" accept="video/*">
            <?php if (isset($edit) && strpos($edit['video_url'] ?? '', 'uploads/videos/') === 0): ?>
              <p class="text-sm text-gray-500 mt-1">Current: <a href="<?php echo h($edit['video_url']); ?>" target="_blank" class="text-primary hover:underline">View Video</a></p>
            <?php endif; ?>
          </div>
          <div class="flex gap-2">
            <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition"><?php echo $edit ? 'Update' : 'Add'; ?></button>
            <?php if ($edit): ?><a href="admin_videos.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <div class="lg:col-span-7">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 font-semibold text-gray-800">All Videos</div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="px-5 py-3 font-semibold text-gray-700">Title</th>
                <th class="px-5 py-3 font-semibold text-gray-700">URL</th>
                <th class="px-5 py-3 font-semibold text-gray-700 w-[220px]">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php mysqli_data_seek($videos, 0); while ($v = mysqli_fetch_assoc($videos)): ?>
                <tr class="border-b border-gray-100 hover:bg-gray-50/50">
                  <td class="px-5 py-3"><?php echo h($v['video_title']); ?></td>
                  <td class="px-5 py-3 max-w-[260px] truncate"><a href="<?php echo h($v['video_url']); ?>" target="_blank" class="text-primary hover:underline">Open</a></td>
                  <td class="px-5 py-3">
                    <a href="admin_videos.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&edit=<?php echo (int)$v['video_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Edit</a>
                    <a href="admin_videos.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&delete=<?php echo (int)$v['video_id']; ?>" onclick="return confirm('Delete this video?');" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition">Delete</a>
                  </td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
</main>
</body>
</html>
