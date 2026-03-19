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
    $handoutId = (int)($_POST['handout_id'] ?? 0);
    $title = trim($_POST['handout_title'] ?? '');
    $allowDownload = isset($_POST['allow_download']) ? 1 : 0;
    $uploadedPath = null;
    $fileName = null;
    $fileSize = null;
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
    if ($handoutId > 0 && $uploadedPath) {
        $stmt = mysqli_prepare($conn, "UPDATE lesson_handouts SET handout_title=?, file_path=?, file_name=?, file_size=?, allow_download=? WHERE handout_id=? AND lesson_id=?");
        mysqli_stmt_bind_param($stmt, 'sssiiii', $title, $uploadedPath, $originalName, $fileSize, $allowDownload, $handoutId, $lessonId);
        mysqli_stmt_execute($stmt);
    } elseif ($handoutId > 0 && !$uploadedPath) {
        $stmt = mysqli_prepare($conn, "UPDATE lesson_handouts SET handout_title=?, allow_download=? WHERE handout_id=? AND lesson_id=?");
        mysqli_stmt_bind_param($stmt, 'siii', $title, $allowDownload, $handoutId, $lessonId);
        mysqli_stmt_execute($stmt);
    } elseif ($uploadedPath) {
        $stmt = mysqli_prepare($conn, "INSERT INTO lesson_handouts (lesson_id, handout_title, file_path, file_name, file_size, allow_download) VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'isssii', $lessonId, $title, $uploadedPath, $originalName, $fileSize, $allowDownload);
        mysqli_stmt_execute($stmt);
    }
    header('Location: admin_handouts.php?lesson_id='.$lessonId.'&subject_id='.$subjectId);
    exit;
}

if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    $delRes = mysqli_query($conn, "SELECT file_path FROM lesson_handouts WHERE handout_id=".$delId." AND lesson_id=".$lessonId." LIMIT 1");
    $delFile = $delRes ? mysqli_fetch_assoc($delRes) : null;
    if ($delFile && file_exists($delFile['file_path'])) unlink($delFile['file_path']);
    mysqli_query($conn, "DELETE FROM lesson_handouts WHERE handout_id=".$delId." AND lesson_id=".$lessonId);
    header('Location: admin_handouts.php?lesson_id='.$lessonId.'&subject_id='.$subjectId);
    exit;
}

if (isset($_GET['toggle_download'])) {
    $toggleId = (int)$_GET['toggle_download'];
    $toggleRes = mysqli_query($conn, "SELECT allow_download FROM lesson_handouts WHERE handout_id=".$toggleId." AND lesson_id=".$lessonId." LIMIT 1");
    $toggleRow = $toggleRes ? mysqli_fetch_assoc($toggleRes) : null;
    if ($toggleRow) {
        $newValue = $toggleRow['allow_download'] ? 0 : 1;
        mysqli_query($conn, "UPDATE lesson_handouts SET allow_download=".$newValue." WHERE handout_id=".$toggleId." AND lesson_id=".$lessonId);
    }
    header('Location: admin_handouts.php?lesson_id='.$lessonId.'&subject_id='.$subjectId);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $r = mysqli_query($conn, "SELECT * FROM lesson_handouts WHERE handout_id=".$eid." AND lesson_id=".$lessonId." LIMIT 1");
    $edit = $r ? mysqli_fetch_assoc($r) : null;
}

$handouts = mysqli_query($conn, "SELECT * FROM lesson_handouts WHERE lesson_id=".$lessonId." ORDER BY handout_id DESC");
$pageTitle = 'Handouts - ' . $lesson['title'];
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Content Hub', 'admin_subjects.php'], [ h($lesson['subject_name']), 'admin_lessons.php?subject_id=' . $subjectId ], [ h($lesson['title']), 'admin_lessons.php?subject_id=' . $subjectId ], ['Handouts'] ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-file-earmark-pdf"></i> Handouts - <?php echo h($lesson['title']); ?> (<span class="admin-subject-text"><?php echo h($lesson['subject_name']); ?></span>)
    </h1>
    <p class="text-gray-500 mt-1">Upload PDFs or documents and control download access per handout.</p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-4 mb-5">
    <div></div>
    <div class="flex gap-2">
      <a href="admin_lessons.php?subject_id=<?php echo (int)$subjectId; ?>" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Back to Lessons</a>
      <a href="admin_handouts.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition">New Handout</a>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
    <div class="lg:col-span-5">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5">
        <h2 class="text-lg font-bold text-gray-800 mb-4"><?php echo $edit ? 'Edit Handout' : 'Add Handout'; ?></h2>
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
          <?php if ($edit): ?><input type="hidden" name="handout_id" value="<?php echo (int)$edit['handout_id']; ?>"><?php endif; ?>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
            <input type="text" name="handout_title" value="<?php echo h($edit['handout_title'] ?? ''); ?>" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Upload File (PDF, DOC, DOCX, etc.)</label>
            <input type="file" name="handout_file" class="input-custom" <?php echo $edit ? '' : 'required'; ?> accept=".pdf,.doc,.docx,.txt,.ppt,.pptx">
            <?php if ($edit && !empty($edit['file_path'])): ?>
              <p class="text-sm text-gray-500 mt-1">Current: <a href="<?php echo h($edit['file_path']); ?>" target="_blank" class="text-primary hover:underline"><?php echo h($edit['file_name'] ?? 'Download'); ?></a></p>
            <?php endif; ?>
          </div>
          <div class="flex items-center gap-2">
            <input type="checkbox" id="allowDownload" name="allow_download" value="1" <?php echo (!$edit || (int)($edit['allow_download'] ?? 1) === 1) ? 'checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
            <label for="allowDownload" class="text-sm font-medium text-gray-700">Allow students to download</label>
          </div>
          <div class="flex gap-2">
            <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition"><?php echo $edit ? 'Update' : 'Upload'; ?></button>
            <?php if ($edit): ?><a href="admin_handouts.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <div class="lg:col-span-7">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 font-semibold text-gray-800">All Handouts</div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="px-5 py-3 font-semibold text-gray-700">Title</th>
                <th class="px-5 py-3 font-semibold text-gray-700">File</th>
                <th class="px-5 py-3 font-semibold text-gray-700">Size</th>
                <th class="px-5 py-3 font-semibold text-gray-700">Downloads</th>
                <th class="px-5 py-3 font-semibold text-gray-700 w-[220px]">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php mysqli_data_seek($handouts, 0); while ($h = mysqli_fetch_assoc($handouts)): ?>
                <tr class="border-b border-gray-100 hover:bg-gray-50/50">
                  <td class="px-5 py-3"><?php echo h($h['handout_title'] ?: 'Untitled'); ?></td>
                  <td class="px-5 py-3">
                    <?php if (!empty($h['file_path'])): ?>
                      <a href="<?php echo h($h['file_path']); ?>" target="_blank" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-download"></i> Download</a>
                    <?php else: ?>
                      <span class="text-gray-500">No file</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-5 py-3"><?php echo $h['file_size'] ? number_format($h['file_size'] / 1024, 2) . ' KB' : '-'; ?></td>
                  <td class="px-5 py-3">
                    <?php if (!empty($h['allow_download'])): ?>
                      <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">Allowed</span>
                    <?php else: ?>
                      <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-gray-200 text-gray-700">Locked</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-5 py-3">
                    <a href="admin_handouts.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&edit=<?php echo (int)$h['handout_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Edit</a>
                    <a href="admin_handouts.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&toggle_download=<?php echo (int)$h['handout_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-amber-500 text-amber-600 hover:bg-amber-500 hover:text-white transition"><?php echo !empty($h['allow_download']) ? 'Lock' : 'Unlock'; ?></a>
                    <a href="admin_handouts.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&delete=<?php echo (int)$h['handout_id']; ?>" onclick="return confirm('Delete this handout?');" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition">Delete</a>
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
