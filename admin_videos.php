<?php
require_once 'auth.php';
requireAdminPage();

$subjectId = (int)($_GET['subject_id'] ?? 0);
$lessonId = (int)($_GET['lesson_id'] ?? 0);
if ($lessonId <= 0) { header('Location: admin_subjects'); exit; }

$lessonRes = mysqli_query($conn, "SELECT l.*, s.subject_name FROM lessons l JOIN subjects s ON s.subject_id=l.subject_id WHERE l.lesson_id=".$lessonId." LIMIT 1");
$lesson = $lessonRes ? mysqli_fetch_assoc($lessonRes) : null;
if (!$lesson) { header('Location: admin_subjects'); exit; }
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
            users_activity_log($conn, 'material_video_updated', [
                'lesson_id' => $lessonId,
                'subject_id' => $subjectId,
                'video_id' => $videoId,
                'video_title' => $title,
                'lesson_title' => (string) ($lesson['title'] ?? ''),
                'subject_name' => (string) ($lesson['subject_name'] ?? ''),
            ]);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO lesson_videos (lesson_id, video_title, video_url) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iss', $lessonId, $title, $finalUrl);
            mysqli_stmt_execute($stmt);
            users_activity_log($conn, 'video_added', [
                'lesson_id' => $lessonId,
                'subject_id' => $subjectId,
                'video_title' => $title,
                'lesson_title' => (string) ($lesson['title'] ?? ''),
                'subject_name' => (string) ($lesson['subject_name'] ?? ''),
                'upload_type' => $uploadType,
            ]);
        }
    }
    header('Location: admin_videos?lesson_id='.$lessonId.'&subject_id='.$subjectId);
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
    header('Location: admin_videos?lesson_id='.$lessonId.'&subject_id='.$subjectId);
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
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard'], ['Content Hub', 'admin_subjects'], [ h($lesson['subject_name']), 'admin_lessons?subject_id=' . $subjectId ], [ h($lesson['title']), 'admin_lessons?subject_id=' . $subjectId ], ['Videos'] ];
$adminHeroIcon = 'play-circle';
$adminHeroTitle = 'Videos';
$adminHeroSubtitle = $lesson['title'] . ' · ' . $lesson['subject_name'];
$adminHeroEyebrow = 'Content Hub / ' . (string) $lesson['subject_name'];
$adminHeroActions = '<a href="admin_videos?lesson_id=' . (int)$lessonId . '&subject_id=' . (int)$subjectId . '" class="admin-btn admin-btn--primary">New Video</a>';
$adminBackHref = 'admin_lessons?subject_id=' . (int)$subjectId;
$adminBackLabel = 'Back to Lessons';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-videos-page" x-data="{ uploadType: '<?php echo (isset($edit) && strpos($edit['video_url'] ?? '', 'uploads/videos/') === 0) ? 'file' : 'url'; ?>' }">
  <?php include 'admin_sidebar.php'; ?>

  <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
    <div class="lg:col-span-5">
      <div class="rounded-2xl border border-sky-100/80 bg-white/85 p-5 shadow-[0_12px_32px_rgba(15,23,42,0.07),0_3px_14px_rgba(14,165,233,0.08)] backdrop-blur-xl ring-1 ring-white/70">
        <h2 class="text-lg font-bold text-slate-900 mb-1 flex items-center gap-2"><span class="lms-icon-tile bg-sky-50 text-sky-600"><i class="bi bi-play-circle"></i></span> <?php echo $edit ? 'Edit Video' : 'Add Video'; ?></h2>
        <p class="mb-4 text-sm text-slate-500"><?php echo $edit ? 'Update this lesson video, then check the preview on the right.' : 'Attach a URL or upload a file for this lesson.'; ?></p>
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
          <?php if ($edit): ?><input type="hidden" name="video_id" value="<?php echo (int)$edit['video_id']; ?>"><?php endif; ?>
          <div class="rounded-xl border border-slate-100 bg-slate-50/80 p-3.5 space-y-3">
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
          </div>
          <div class="flex gap-2">
            <button type="submit" class="admin-btn admin-btn--primary"><?php echo $edit ? 'Update' : 'Add'; ?></button>
            <?php if ($edit): ?><a href="admin_videos?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="admin-btn admin-btn--secondary">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <div class="lg:col-span-7">
      <?php if ($edit): ?>
      <div class="mb-4 rounded-2xl border border-sky-100/80 bg-gradient-to-br from-sky-50/70 via-white to-white p-4 shadow-sm ring-1 ring-white/70">
        <p class="m-0 text-[10px] font-bold uppercase tracking-[0.14em] text-sky-600/80">Preview / status</p>
        <p class="m-0 mt-1 text-sm font-semibold text-slate-900"><?php echo h($edit['video_title'] ?? 'Untitled video'); ?></p>
        <?php if (!empty($edit['video_url'])): ?>
          <a href="<?php echo h($edit['video_url']); ?>" target="_blank" class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:underline"><i class="bi bi-box-arrow-up-right"></i> Open current source</a>
        <?php else: ?>
          <p class="m-0 mt-1 text-xs text-slate-500">No source URL on file.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="rounded-2xl border border-white/80 bg-white/80 shadow-[0_8px_28px_rgba(15,23,42,0.05)] backdrop-blur-xl overflow-hidden page-table">
        <div class="px-5 py-3 border-b border-slate-100 font-semibold text-slate-900">All Videos</div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="bg-slate-50 border-b border-slate-100">
              <tr>
                <th class="px-5 py-3 font-semibold text-slate-700">Title</th>
                <th class="px-5 py-3 font-semibold text-slate-700">URL</th>
                <th class="px-5 py-3 font-semibold text-slate-700 w-[220px]">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php mysqli_data_seek($videos, 0); while ($v = mysqli_fetch_assoc($videos)): ?>
                <tr class="border-b border-slate-100 hover:bg-slate-50/50">
                  <td class="px-5 py-3">
                    <div class="inline-flex items-center gap-2 font-medium text-slate-900">
                      <span class="lms-icon-tile bg-sky-50 text-sky-600" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-play-btn"></i></span>
                      <?php echo h($v['video_title']); ?>
                    </div>
                  </td>
                  <td class="px-5 py-3 max-w-[260px] truncate"><a href="<?php echo h($v['video_url']); ?>" target="_blank" class="text-primary hover:underline">Open</a></td>
                  <td class="px-5 py-3">
                    <a href="admin_videos?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&edit=<?php echo (int)$v['video_id']; ?>" class="admin-btn admin-btn--secondary text-sm px-3 py-1.5">Edit</a>
                    <a href="admin_videos?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&delete=<?php echo (int)$v['video_id']; ?>" onclick="return confirm('Delete this video?');" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-semibold border border-rose-200 text-rose-700 hover:bg-rose-50 transition">Delete</a>
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
