<?php
require_once 'auth.php';
requireRole('admin');

$lessonId = (int)($_GET['lesson_id'] ?? 0);
if ($lessonId <= 0) { header('Location: admin_subjects.php'); exit; }

$lessonRes = mysqli_query($conn, "SELECT l.*, s.subject_name FROM lessons l JOIN subjects s ON s.subject_id=l.subject_id WHERE l.lesson_id=".$lessonId." LIMIT 1");
$lesson = $lessonRes ? mysqli_fetch_assoc($lessonRes) : null;
if (!$lesson) { header('Location: admin_subjects.php'); exit; }
$subjectId = (int)$lesson['subject_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    if ($type === 'video') {
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
            if (move_uploaded_file($_FILES['video_file']['tmp_name'], $target)) $finalUrl = 'uploads/videos/' . $fileName;
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
    }
    if ($type === 'handout') {
        $handoutId = (int)($_POST['handout_id'] ?? 0);
        $title = trim($_POST['handout_title'] ?? '');
        $allowDownload = isset($_POST['allow_download']) ? 1 : 0;
        $uploadedPath = null; $originalName = null; $fileSize = null;
        if (isset($_FILES['handout_file']) && $_FILES['handout_file']['error'] === UPLOAD_ERR_OK) {
            $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'handouts';
            if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0777, true);
            $originalName = $_FILES['handout_file']['name'];
            $ext = pathinfo($originalName, PATHINFO_EXTENSION);
            $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
            $fileName = 'handout_' . uniqid('', true) . ($safeExt ? ('.' . $safeExt) : '');
            $target = $uploadsDir . DIRECTORY_SEPARATOR . $fileName;
            if (move_uploaded_file($_FILES['handout_file']['tmp_name'], $target)) {
                $uploadedPath = 'uploads/handouts/' . $fileName;
                $fileSize = $_FILES['handout_file']['size'];
            }
        }
        if ($handoutId > 0) {
            if ($uploadedPath) {
                $stmt = mysqli_prepare($conn, "UPDATE lesson_handouts SET handout_title=?, file_path=?, file_name=?, file_size=?, allow_download=? WHERE handout_id=? AND lesson_id=?");
                mysqli_stmt_bind_param($stmt, 'sssiiii', $title, $uploadedPath, $originalName, $fileSize, $allowDownload, $handoutId, $lessonId);
                mysqli_stmt_execute($stmt);
            } else {
                $stmt = mysqli_prepare($conn, "UPDATE lesson_handouts SET handout_title=?, allow_download=? WHERE handout_id=? AND lesson_id=?");
                mysqli_stmt_bind_param($stmt, 'siii', $title, $allowDownload, $handoutId, $lessonId);
                mysqli_stmt_execute($stmt);
            }
        } elseif ($uploadedPath) {
            $stmt = mysqli_prepare($conn, "INSERT INTO lesson_handouts (lesson_id, handout_title, file_path, file_name, file_size, allow_download) VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'isssii', $lessonId, $title, $uploadedPath, $originalName, $fileSize, $allowDownload);
            mysqli_stmt_execute($stmt);
        }
    }
    header('Location: admin_materials.php?lesson_id='.$lessonId.'&subject_id='.$subjectId);
    exit;
}

if (isset($_GET['delete_video'])) {
    $delId = (int)$_GET['delete_video'];
    $delRes = mysqli_query($conn, "SELECT video_url FROM lesson_videos WHERE video_id=".$delId." AND lesson_id=".$lessonId." LIMIT 1");
    $delVideo = $delRes ? mysqli_fetch_assoc($delRes) : null;
    if ($delVideo && strpos($delVideo['video_url'], 'uploads/videos/') === 0 && file_exists($delVideo['video_url'])) @unlink($delVideo['video_url']);
    mysqli_query($conn, "DELETE FROM lesson_videos WHERE video_id=".$delId." AND lesson_id=".$lessonId);
    header('Location: admin_materials.php?lesson_id='.$lessonId.'&subject_id='.$subjectId);
    exit;
}
if (isset($_GET['delete_handout'])) {
    $delId = (int)$_GET['delete_handout'];
    $delRes = mysqli_query($conn, "SELECT file_path FROM lesson_handouts WHERE handout_id=".$delId." AND lesson_id=".$lessonId." LIMIT 1");
    $del = $delRes ? mysqli_fetch_assoc($delRes) : null;
    if ($del && !empty($del['file_path']) && file_exists($del['file_path'])) @unlink($del['file_path']);
    mysqli_query($conn, "DELETE FROM lesson_handouts WHERE handout_id=".$delId." AND lesson_id=".$lessonId);
    header('Location: admin_materials.php?lesson_id='.$lessonId.'&subject_id='.$subjectId);
    exit;
}
if (isset($_GET['toggle_handout'])) {
    $toggleId = (int)$_GET['toggle_handout'];
    $toggleRes = mysqli_query($conn, "SELECT allow_download FROM lesson_handouts WHERE handout_id=".$toggleId." AND lesson_id=".$lessonId." LIMIT 1");
    $toggleRow = $toggleRes ? mysqli_fetch_assoc($toggleRes) : null;
    if ($toggleRow) {
        $newValue = $toggleRow['allow_download'] ? 0 : 1;
        mysqli_query($conn, "UPDATE lesson_handouts SET allow_download=".$newValue." WHERE handout_id=".$toggleId." AND lesson_id=".$lessonId);
    }
    header('Location: admin_materials.php?lesson_id='.$lessonId.'&subject_id='.$subjectId);
    exit;
}

$videos = mysqli_query($conn, "SELECT * FROM lesson_videos WHERE lesson_id=".$lessonId." ORDER BY video_id DESC");
$handouts = mysqli_query($conn, "SELECT * FROM lesson_handouts WHERE lesson_id=".$lessonId." ORDER BY handout_id DESC");
$pageTitle = 'Materials - ' . $lesson['title'];
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Content Hub', 'admin_subjects.php'], [ h($lesson['subject_name']), 'admin_lessons.php?subject_id=' . $subjectId ], [ h($lesson['title']), 'admin_lessons.php?subject_id=' . $subjectId ], ['Materials'] ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app" x-data="{ uploadType: 'url' }">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-folder-plus"></i> Materials - <?php echo h($lesson['title']); ?> (<span class="admin-subject-text"><?php echo h($lesson['subject_name']); ?></span>)
    </h1>
    <p class="text-gray-500 mt-1">Videos and handouts for this lesson — add, edit, or manage download access.</p>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
      <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
        <span class="font-semibold text-gray-800 flex items-center gap-2"><i class="bi bi-play-circle"></i> Videos</span>
        <a href="admin_lessons.php?subject_id=<?php echo (int)$subjectId; ?>" class="text-sm px-3 py-1.5 rounded-lg font-medium border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Back to Lessons</a>
      </div>
      <div class="p-5">
        <form method="POST" enctype="multipart/form-data" class="space-y-3 mb-4">
          <input type="hidden" name="type" value="video">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
            <input type="text" name="video_title" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Upload Type</label>
            <select name="upload_type" x-model="uploadType" class="input-custom">
              <option value="url">URL (YouTube/Vimeo/Link)</option>
              <option value="file">Upload Video File</option>
            </select>
          </div>
          <div x-show="uploadType === 'url'" x-cloak>
            <label class="block text-sm font-medium text-gray-700 mb-1">Video URL</label>
            <input type="url" name="video_url" class="input-custom" placeholder="https://...">
          </div>
          <div x-show="uploadType === 'file'" x-cloak>
            <label class="block text-sm font-medium text-gray-700 mb-1">Upload Video File</label>
            <input type="file" name="video_file" class="input-custom" accept="video/*">
          </div>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition">Add Video</button>
        </form>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr><th class="px-3 py-2 font-semibold text-gray-700">Title</th><th class="px-3 py-2 font-semibold text-gray-700">URL</th><th class="px-3 py-2 font-semibold text-gray-700 w-[160px]">Actions</th></tr>
            </thead>
            <tbody>
              <?php mysqli_data_seek($videos, 0); while ($v = mysqli_fetch_assoc($videos)): ?>
                <tr class="border-b border-gray-100">
                  <td class="px-3 py-2"><?php echo h($v['video_title']); ?></td>
                  <td class="px-3 py-2 max-w-[200px] truncate"><a href="<?php echo h($v['video_url']); ?>" target="_blank" class="text-primary hover:underline">Open</a></td>
                  <td class="px-3 py-2">
                    <a href="admin_materials.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&delete_video=<?php echo (int)$v['video_id']; ?>" onclick="return confirm('Delete this video?');" class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition">Delete</a>
                  </td>
                </tr>
              <?php endwhile; ?>
              <?php if (mysqli_num_rows($videos) == 0): ?>
                <tr><td colspan="3" class="px-3 py-6 text-center text-gray-500">No videos yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
      <div class="px-5 py-4 border-b border-gray-100 font-semibold text-gray-800 flex items-center gap-2"><i class="bi bi-file-earmark-pdf"></i> Handouts</div>
      <div class="p-5">
        <form method="POST" enctype="multipart/form-data" class="space-y-3 mb-4">
          <input type="hidden" name="type" value="handout">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
            <input type="text" name="handout_title" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Upload File (PDF, DOC, PPT, etc.)</label>
            <input type="file" name="handout_file" class="input-custom" accept=".pdf,.doc,.docx,.txt,.ppt,.pptx,.xls,.xlsx" required>
          </div>
          <div class="flex items-center gap-2">
            <input type="checkbox" id="allowDownloadHandout" name="allow_download" value="1" checked class="rounded border-gray-300 text-primary focus:ring-primary">
            <label for="allowDownloadHandout" class="text-sm font-medium text-gray-700">Allow students to download</label>
          </div>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition">Upload Handout</button>
        </form>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr><th class="px-3 py-2 font-semibold text-gray-700">Title</th><th class="px-3 py-2 font-semibold text-gray-700">File</th><th class="px-3 py-2 font-semibold text-gray-700">Downloads</th><th class="px-3 py-2 font-semibold text-gray-700 w-[220px]">Actions</th></tr>
            </thead>
            <tbody>
              <?php mysqli_data_seek($handouts, 0); while ($h = mysqli_fetch_assoc($handouts)): ?>
                <tr class="border-b border-gray-100">
                  <td class="px-3 py-2"><?php echo h($h['handout_title'] ?: 'Untitled'); ?></td>
                  <td class="px-3 py-2"><?php if (!empty($h['file_path'])): ?><a href="<?php echo h($h['file_path']); ?>" target="_blank" class="text-primary hover:underline">Download</a><?php endif; ?></td>
                  <td class="px-3 py-2">
                    <?php if (!empty($h['allow_download'])): ?><span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Allowed</span>
                    <?php else: ?><span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-200 text-gray-700">Locked</span><?php endif; ?>
                  </td>
                  <td class="px-3 py-2">
                    <a href="admin_materials.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&toggle_handout=<?php echo (int)$h['handout_id']; ?>" class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium border-2 border-amber-500 text-amber-600 hover:bg-amber-500 hover:text-white transition"><?php echo !empty($h['allow_download']) ? 'Lock' : 'Unlock'; ?></a>
                    <a href="admin_materials.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&delete_handout=<?php echo (int)$h['handout_id']; ?>" onclick="return confirm('Delete this handout?');" class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition">Delete</a>
                  </td>
                </tr>
              <?php endwhile; ?>
              <?php if (mysqli_num_rows($handouts) == 0): ?>
                <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">No handouts yet.</td></tr>
              <?php endif; ?>
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
