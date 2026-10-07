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
        users_activity_log($conn, 'material_handout_updated', [
            'lesson_id' => $lessonId,
            'subject_id' => $subjectId,
            'handout_id' => $handoutId,
            'handout_title' => $title,
            'file_name' => (string) ($originalName ?? ''),
            'lesson_title' => (string) ($lesson['title'] ?? ''),
            'subject_name' => (string) ($lesson['subject_name'] ?? ''),
        ]);
    } elseif ($handoutId > 0 && !$uploadedPath) {
        $stmt = mysqli_prepare($conn, "UPDATE lesson_handouts SET handout_title=?, allow_download=? WHERE handout_id=? AND lesson_id=?");
        mysqli_stmt_bind_param($stmt, 'siii', $title, $allowDownload, $handoutId, $lessonId);
        mysqli_stmt_execute($stmt);
        users_activity_log($conn, 'material_handout_updated', [
            'lesson_id' => $lessonId,
            'subject_id' => $subjectId,
            'handout_id' => $handoutId,
            'handout_title' => $title,
            'lesson_title' => (string) ($lesson['title'] ?? ''),
            'subject_name' => (string) ($lesson['subject_name'] ?? ''),
        ]);
    } elseif ($uploadedPath) {
        $stmt = mysqli_prepare($conn, "INSERT INTO lesson_handouts (lesson_id, handout_title, file_path, file_name, file_size, allow_download) VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'isssii', $lessonId, $title, $uploadedPath, $originalName, $fileSize, $allowDownload);
        mysqli_stmt_execute($stmt);
        users_activity_log($conn, 'handout_uploaded', [
            'lesson_id' => $lessonId,
            'subject_id' => $subjectId,
            'handout_title' => $title,
            'file_name' => (string) ($originalName ?? ''),
            'lesson_title' => (string) ($lesson['title'] ?? ''),
            'subject_name' => (string) ($lesson['subject_name'] ?? ''),
        ]);
    }
    header('Location: admin_handouts?lesson_id='.$lessonId.'&subject_id='.$subjectId);
    exit;
}

if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    $delRes = mysqli_query($conn, "SELECT file_path FROM lesson_handouts WHERE handout_id=".$delId." AND lesson_id=".$lessonId." LIMIT 1");
    $delFile = $delRes ? mysqli_fetch_assoc($delRes) : null;
    if ($delFile && file_exists($delFile['file_path'])) unlink($delFile['file_path']);
    mysqli_query($conn, "DELETE FROM lesson_handouts WHERE handout_id=".$delId." AND lesson_id=".$lessonId);
    header('Location: admin_handouts?lesson_id='.$lessonId.'&subject_id='.$subjectId);
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
    header('Location: admin_handouts?lesson_id='.$lessonId.'&subject_id='.$subjectId);
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
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard'], ['Content Hub', 'admin_subjects'], [ h($lesson['subject_name']), 'admin_lessons?subject_id=' . $subjectId ], [ h($lesson['title']), 'admin_lessons?subject_id=' . $subjectId ], ['Handouts'] ];
$adminHeroIcon = 'file-earmark-pdf';
$adminHeroTitle = 'Handouts';
$adminHeroSubtitle = $lesson['title'] . ' · ' . $lesson['subject_name'];
$adminHeroEyebrow = 'Content Hub / ' . (string) $lesson['subject_name'];
$adminHeroActions = '<a href="admin_handouts?lesson_id=' . (int)$lessonId . '&subject_id=' . (int)$subjectId . '" class="admin-btn admin-btn--primary">New Handout</a>';
$adminBackHref = 'admin_lessons?subject_id=' . (int)$subjectId;
$adminBackLabel = 'Back to Lessons';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-handouts-page">
  <?php include 'admin_sidebar.php'; ?>

  <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
    <div class="lg:col-span-5">
      <div class="rounded-2xl border border-violet-100/80 bg-white/85 p-5 shadow-[0_12px_32px_rgba(15,23,42,0.07),0_3px_14px_rgba(139,92,246,0.08)] backdrop-blur-xl ring-1 ring-white/70">
        <h2 class="text-lg font-bold text-slate-900 mb-1 flex items-center gap-2"><span class="lms-icon-tile bg-violet-50 text-violet-600"><i class="bi bi-file-earmark-pdf"></i></span> <?php echo $edit ? 'Edit Handout' : 'Add Handout'; ?></h2>
        <p class="mb-4 text-sm text-slate-500"><?php echo $edit ? 'Replace the file or update download access for this document.' : 'Upload a PDF or document and control student download access.'; ?></p>
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
          <?php if ($edit): ?><input type="hidden" name="handout_id" value="<?php echo (int)$edit['handout_id']; ?>"><?php endif; ?>
          <div class="rounded-xl border border-slate-100 bg-slate-50/80 p-3.5 space-y-3">
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
          </div>
          <div class="flex gap-2">
            <button type="submit" class="admin-btn admin-btn--primary"><?php echo $edit ? 'Update' : 'Upload'; ?></button>
            <?php if ($edit): ?><a href="admin_handouts?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="admin-btn admin-btn--secondary">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <div class="lg:col-span-7">
      <?php if ($edit && !empty($edit['file_path'])): ?>
      <div class="mb-4 rounded-2xl border border-violet-100/80 bg-gradient-to-br from-violet-50/70 via-white to-white p-4 shadow-sm ring-1 ring-white/70">
        <p class="m-0 text-[10px] font-bold uppercase tracking-[0.14em] text-violet-600/80">Preview / status</p>
        <p class="m-0 mt-1 text-sm font-semibold text-slate-900"><?php echo h($edit['handout_title'] ?? 'Untitled handout'); ?></p>
        <a href="<?php echo h($edit['file_path']); ?>" target="_blank" class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:underline"><i class="bi bi-file-earmark-arrow-down"></i> Open current file</a>
      </div>
      <?php endif; ?>
      <div class="rounded-2xl border border-white/80 bg-white/80 shadow-[0_8px_28px_rgba(15,23,42,0.05)] backdrop-blur-xl overflow-hidden page-table">
        <div class="px-5 py-3 border-b border-slate-100 font-semibold text-slate-900">All Handouts</div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="bg-slate-50 border-b border-slate-100">
              <tr>
                <th class="px-5 py-3 font-semibold text-slate-700">Title</th>
                <th class="px-5 py-3 font-semibold text-slate-700">File</th>
                <th class="px-5 py-3 font-semibold text-slate-700">Size</th>
                <th class="px-5 py-3 font-semibold text-slate-700">Downloads</th>
                <th class="px-5 py-3 font-semibold text-slate-700 w-[220px]">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php mysqli_data_seek($handouts, 0); while ($h = mysqli_fetch_assoc($handouts)): ?>
                <tr class="border-b border-slate-100 hover:bg-slate-50/50">
                  <td class="px-5 py-3">
                    <div class="inline-flex items-center gap-2 font-medium text-slate-900">
                      <span class="lms-icon-tile bg-violet-50 text-violet-600" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-file-earmark-text"></i></span>
                      <?php echo h($h['handout_title'] ?: 'Untitled'); ?>
                    </div>
                  </td>
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
                      <span class="admin-status-pill admin-status-pill--approved">Allowed</span>
                    <?php else: ?>
                      <span class="admin-status-pill admin-status-pill--inactive">Locked</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-5 py-3">
                    <a href="admin_handouts?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&edit=<?php echo (int)$h['handout_id']; ?>" class="admin-btn admin-btn--secondary text-sm px-3 py-1.5">Edit</a>
                    <a href="admin_handouts?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&toggle_download=<?php echo (int)$h['handout_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-semibold border border-indigo-200 text-indigo-700 hover:bg-indigo-50 transition"><?php echo !empty($h['allow_download']) ? 'Lock' : 'Unlock'; ?></a>
                    <a href="admin_handouts?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&delete=<?php echo (int)$h['handout_id']; ?>" onclick="return confirm('Delete this handout?');" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-semibold border border-rose-200 text-rose-700 hover:bg-rose-50 transition">Delete</a>
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
