<?php
require_once 'auth.php';
requireRole('admin');

$subjectId = (int)($_GET['subject_id'] ?? 0);
if ($subjectId <= 0) {
    header('Location: admin_subjects.php');
    exit;
}

// Load subject for context
$stmt = mysqli_prepare($conn, "SELECT subject_name FROM subjects WHERE subject_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$subjectRes = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($subjectRes);
mysqli_stmt_close($stmt);
if (!$subject) {
    header('Location: admin_subjects.php');
    exit;
}

$pageTitle = 'Test Bank - ' . $subject['subject_name'];
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard.php'],
    ['Content Hub', 'admin_subjects.php'],
    [h($subject['subject_name']), 'admin_test_bank.php?subject_id=' . $subjectId],
    ['Test Bank'],
];
$uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'test_bank';
$allowedExtensions = ['pdf', 'doc', 'docx', 'txt', 'ppt', 'pptx', 'xls', 'xlsx'];
$maxSize = 20 * 1024 * 1024; // 20 MB per file

// Ensure table exists (create if missing for convenience) with subject-specific linkage.
$conn->query("CREATE TABLE IF NOT EXISTS `test_bank` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL DEFAULT '',
  `description` text,
  `question_file_path` varchar(512) NOT NULL DEFAULT '',
  `question_file_name` varchar(255) NOT NULL DEFAULT '',
  `solution_file_path` varchar(512) NOT NULL DEFAULT '',
  `solution_file_name` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Backwards compatibility: if the table existed without subject_id, add it once.
$colRes = $conn->query("SHOW COLUMNS FROM `test_bank` LIKE 'subject_id'");
if ($colRes && $colRes->num_rows === 0) {
    $conn->query("ALTER TABLE `test_bank` ADD COLUMN `subject_id` int(11) NOT NULL DEFAULT 0");
    $conn->query("CREATE INDEX `idx_subject_id` ON `test_bank` (`subject_id`)");
}

function saveUpload($fileKey, $prefix, $uploadsDir, $allowedExt, $maxSize) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return [null, null];
    $f = $_FILES[$fileKey];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    if (!in_array($ext, $allowedExt, true)) return [null, 'Invalid file type.'];
    if ($f['size'] > $maxSize) return [null, 'File too large.'];
    if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0777, true);
    $fileName = $prefix . '_' . uniqid('', true) . ($ext ? '.' . $ext : '');
    $target = $uploadsDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($f['tmp_name'], $target)) return [null, 'Upload failed.'];
    return ['uploads/test_bank/' . $fileName, $f['name']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['test_bank_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $title = $title !== '' ? $title : 'Untitled Test Bank';

    list($qPath, $qName) = saveUpload('question_file', 'question', $uploadsDir, $allowedExtensions, $maxSize);
    list($sPath, $sName) = saveUpload('solution_file', 'solution', $uploadsDir, $allowedExtensions, $maxSize);

    if ($id > 0) {
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT question_file_path, question_file_name, solution_file_path, solution_file_name FROM test_bank WHERE id=" . $id . " AND subject_id=" . $subjectId . " LIMIT 1"));
        if (!$row) { header('Location: admin_test_bank.php?subject_id=' . $subjectId); exit; }
        $oldQPath = $row['question_file_path'] ?? '';
        $oldSPath = $row['solution_file_path'] ?? '';
        $qPath = $qPath ?: $oldQPath;
        $qName = $qName ?: ($row['question_file_name'] ?? '');
        $sPath = $sPath ?: $oldSPath;
        $sName = $sName ?: ($row['solution_file_name'] ?? '');
        if ($qPath && $oldQPath && $qPath !== $oldQPath && file_exists(__DIR__ . '/' . $oldQPath)) unlink(__DIR__ . '/' . $oldQPath);
        if ($sPath && $oldSPath && $sPath !== $oldSPath && file_exists(__DIR__ . '/' . $oldSPath)) unlink(__DIR__ . '/' . $oldSPath);
        $stmt = mysqli_prepare($conn, "UPDATE test_bank SET title=?, description=?, question_file_path=?, question_file_name=?, solution_file_path=?, solution_file_name=? WHERE id=? AND subject_id=?");
        mysqli_stmt_bind_param($stmt, 'ssssssii', $title, $description, $qPath, $qName, $sPath, $sName, $id, $subjectId);
        mysqli_stmt_execute($stmt);
        $_SESSION['message'] = 'Test bank entry updated.';
    } else {
        if (!$qPath || !$sPath) {
            $_SESSION['error'] = 'Both question file and answer file are required for new entries.';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO test_bank (subject_id, title, description, question_file_path, question_file_name, solution_file_path, solution_file_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'issssss', $subjectId, $title, $description, $qPath, $qName, $sPath, $sName);
            mysqli_stmt_execute($stmt);
            $_SESSION['message'] = 'Test bank entry added.';
        }
    }
    header('Location: admin_test_bank.php?subject_id=' . $subjectId);
    exit;
}

if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT question_file_path, solution_file_path FROM test_bank WHERE id=" . $delId . " AND subject_id=" . $subjectId . " LIMIT 1"));
    if ($row) {
        if (!empty($row['question_file_path']) && file_exists(__DIR__ . '/' . $row['question_file_path'])) unlink(__DIR__ . '/' . $row['question_file_path']);
        if (!empty($row['solution_file_path']) && file_exists(__DIR__ . '/' . $row['solution_file_path'])) unlink(__DIR__ . '/' . $row['solution_file_path']);
    }
    mysqli_query($conn, "DELETE FROM test_bank WHERE id=" . $delId . " AND subject_id=" . $subjectId);
    $_SESSION['message'] = 'Test bank entry deleted.';
    header('Location: admin_test_bank.php?subject_id=' . $subjectId);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $r = mysqli_query($conn, "SELECT * FROM test_bank WHERE id=" . $eid . " AND subject_id=" . $subjectId . " LIMIT 1");
    $edit = $r ? mysqli_fetch_assoc($r) : null;
}

$list = mysqli_query($conn, "SELECT * FROM test_bank WHERE subject_id=" . $subjectId . " ORDER BY id DESC");
$tbCount = $list ? (int)mysqli_num_rows($list) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    @media (max-width: 768px) {
      .admin-tb-table th, .admin-tb-table td { padding: 0.5rem 0.5rem !important; font-size: 0.8125rem; }
      .admin-tb-table th:first-child, .admin-tb-table td:first-child { padding-left: 1rem !important; }
    }
    @media (max-width: 480px) {
      .admin-tb-table th, .admin-tb-table td { padding: 0.375rem 0.375rem !important; font-size: 0.75rem; }
    }
    .admin-tb-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  </style>
</head>
<body class="font-sans antialiased admin-app">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-5 py-5 mb-5">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2 flex-wrap">
      <i class="bi bi-folder2-open"></i> Test Bank - <span class="admin-subject-text admin-subject-text--testbank"><?php echo h($subject['subject_name']); ?></span>
    </h1>
    <p class="text-gray-500 mt-1">Upload and manage review materials (question + answer files) for this subject only.</p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-4 mb-5">
    <div></div>
    <div class="flex flex-wrap gap-2">
      <a href="admin_subjects.php" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-arrow-left"></i> Back to Content Hub</a>
      <a href="admin_lessons.php?subject_id=<?php echo (int)$subjectId; ?>" class="admin-outline-btn admin-outline-btn--lessons px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-file-text"></i> Lessons for <?php echo h($subject['subject_name']); ?></a>
      <a href="admin_quizzes.php?subject_id=<?php echo (int)$subjectId; ?>" class="admin-outline-btn admin-outline-btn--quiz px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-question-circle"></i> Quizzes for <?php echo h($subject['subject_name']); ?></a>
      <a href="admin_preweek.php?subject_id=<?php echo (int)$subjectId; ?>" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-lightning-charge"></i> Preweek for <?php echo h($subject['subject_name']); ?></a>
    </div>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-green-50 border border-green-200 flex items-center gap-2 text-green-800">
      <i class="bi bi-check-circle-fill"></i>
      <span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="mb-4 sm:mb-5 p-3 sm:p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800 text-sm sm:text-base">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5">
    <div class="lg:col-span-5 order-2 lg:order-1">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-4 sm:p-5">
        <h2 class="text-base sm:text-lg font-bold text-gray-800 mb-3 sm:mb-4"><?php echo $edit ? 'Edit entry' : 'Add Test Bank entry'; ?></h2>
        <form method="POST" enctype="multipart/form-data" class="space-y-3 sm:space-y-4">
          <?php if ($edit): ?><input type="hidden" name="test_bank_id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>
          <input type="hidden" name="subject_id" value="<?php echo (int)$subjectId; ?>">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
            <input type="text" name="title" value="<?php echo h($edit['title'] ?? ''); ?>" class="input-custom" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea name="description" rows="2" class="input-custom"><?php echo h($edit['description'] ?? ''); ?></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Question file (PDF, DOC, DOCX, etc.)</label>
            <input type="file" name="question_file" class="input-custom" <?php echo $edit ? '' : 'required'; ?> accept=".pdf,.doc,.docx,.txt,.ppt,.pptx,.xls,.xlsx">
            <?php if ($edit && !empty($edit['question_file_path'])): ?>
              <p class="text-sm text-gray-500 mt-1">Current: <a href="<?php echo h($edit['question_file_path']); ?>" target="_blank" class="text-primary hover:underline"><?php echo h($edit['question_file_name'] ?: 'Download'); ?></a></p>
            <?php endif; ?>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Answer file</label>
            <input type="file" name="solution_file" class="input-custom" <?php echo $edit ? '' : 'required'; ?> accept=".pdf,.doc,.docx,.txt,.ppt,.pptx,.xls,.xlsx">
            <?php if ($edit && !empty($edit['solution_file_path'])): ?>
              <p class="text-sm text-gray-500 mt-1">Current: <a href="<?php echo h($edit['solution_file_path']); ?>" target="_blank" class="text-primary hover:underline"><?php echo h($edit['solution_file_name'] ?: 'Download'); ?></a></p>
            <?php endif; ?>
          </div>
          <p class="text-xs text-gray-500">Max 20 MB per file. Allowed: <?php echo implode(', ', $allowedExtensions); ?></p>
          <div class="flex flex-wrap gap-2">
            <button type="submit" class="admin-content-btn admin-content-btn--testbank px-4 py-2.5 rounded-lg font-semibold border-2 transition inline-flex items-center gap-2"><i class="bi bi-<?php echo $edit ? 'check-lg' : 'plus-circle'; ?>"></i> <?php echo $edit ? 'Update entry' : 'Add entry'; ?></button>
            <?php if ($edit): ?><a href="admin_test_bank.php?subject_id=<?php echo (int)$subjectId; ?>" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <div class="lg:col-span-7 order-1 lg:order-2">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
        <div class="px-4 sm:px-5 py-3 sm:py-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-2">
          <div class="flex items-center gap-2">
            <span class="font-semibold text-gray-800">Test Bank entries</span>
            <span class="px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-200 text-gray-700"><?php echo (int)$tbCount; ?></span>
          </div>
          <p class="text-gray-500 text-sm hidden md:block m-0">Question file + answer file per entry.</p>
        </div>
        <div class="admin-tb-table-wrap">
          <table class="admin-tb-table w-full text-left" style="min-width: 680px;">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="px-4 sm:px-5 py-2.5 sm:py-3 font-semibold text-gray-700" style="min-width: 140px;">Title</th>
                <th class="px-4 sm:px-5 py-2.5 sm:py-3 font-semibold text-gray-700">Question file</th>
                <th class="px-4 sm:px-5 py-2.5 sm:py-3 font-semibold text-gray-700">Answer file</th>
                <th class="px-4 sm:px-5 py-2.5 sm:py-3 font-semibold text-gray-700 w-24 sm:w-28">Updated</th>
                <th class="px-4 sm:px-5 py-2.5 sm:py-3 font-semibold text-gray-700 w-[120px] sm:w-[140px]">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($list && mysqli_num_rows($list)): ?>
                <?php while ($row = mysqli_fetch_assoc($list)): ?>
                  <tr class="border-b border-gray-100 hover:bg-gray-50/50">
                    <td class="px-4 sm:px-5 py-2.5 sm:py-3">
                      <span class="font-medium text-gray-800 break-words"><?php echo h($row['title']); ?></span>
                      <?php if (!empty($row['description'])): ?>
                        <p class="text-xs sm:text-sm text-gray-500 mt-0.5 line-clamp-2"><?php echo h(mb_substr($row['description'], 0, 60)); ?><?php echo mb_strlen($row['description']) > 60 ? '…' : ''; ?></p>
                      <?php endif; ?>
                    </td>
                    <td class="px-4 sm:px-5 py-2.5 sm:py-3 whitespace-nowrap">
                      <?php if (!empty($row['question_file_path'])): ?>
                        <a href="<?php echo h($row['question_file_path']); ?>" target="_blank" class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs sm:text-sm border border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-download"></i> Download</a>
                      <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="px-4 sm:px-5 py-2.5 sm:py-3 whitespace-nowrap">
                      <?php if (!empty($row['solution_file_path'])): ?>
                        <a href="<?php echo h($row['solution_file_path']); ?>" target="_blank" class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs sm:text-sm border border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-download"></i> Download</a>
                      <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="px-4 sm:px-5 py-2.5 sm:py-3 text-xs sm:text-sm text-gray-600 whitespace-nowrap"><?php echo $row['updated_at'] ? date('M j, Y', strtotime($row['updated_at'])) : '—'; ?></td>
                    <td class="px-4 sm:px-5 py-2.5 sm:py-3">
                      <a href="admin_test_bank.php?subject_id=<?php echo (int)$subjectId; ?>&edit=<?php echo (int)$row['id']; ?>" class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs sm:text-sm border border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Edit</a>
                      <a href="admin_test_bank.php?subject_id=<?php echo (int)$subjectId; ?>&delete=<?php echo (int)$row['id']; ?>" onclick="return confirm('Delete this test bank entry?');" class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs sm:text-sm border border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition">Delete</a>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="5" class="px-4 sm:px-5 py-12 text-center text-gray-500 text-sm sm:text-base">
                  <i class="bi bi-folder2-open text-4xl block mb-2 opacity-60"></i>
                  <div class="font-semibold">No test bank entries yet</div>
                  <p class="text-sm mt-1">Add question and answer files using the form on the left.</p>
                </td></tr>
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
