<?php
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'Uploads';
$uid = getCurrentUserId();
$csrf = generateCSRFToken();
$now = date('Y-m-d H:i:s');

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'college';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_task_id'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: college_uploads.php');
        exit;
    }
    $taskId = sanitizeInt($_POST['upload_task_id'] ?? 0);
    $stmt = mysqli_prepare($conn, "SELECT * FROM college_upload_tasks WHERE task_id=? AND is_open=1 LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $taskId);
    mysqli_stmt_execute($stmt);
    $task = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$task || $task['deadline'] < $now) {
        $_SESSION['error'] = 'This task is closed or past deadline.';
        header('Location: college_uploads.php');
        exit;
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = 'Please choose a valid file.';
        header('Location: college_uploads.php');
        exit;
    }

    $f = $_FILES['file'];
    if ($f['size'] > (int)$task['max_file_size']) {
        $_SESSION['error'] = 'File is too large.';
        header('Location: college_uploads.php');
        exit;
    }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = array_map('trim', explode(',', strtolower((string)$task['allowed_extensions'])));
    if (!in_array($ext, $allowed, true)) {
        $_SESSION['error'] = 'File type not allowed.';
        header('Location: college_uploads.php');
        exit;
    }

    $safeName = 'u' . $uid . '_t' . $taskId . '_' . bin2hex(random_bytes(6)) . ($ext !== '' ? '.' . $ext : '');
    $subDir = $uploadDir . DIRECTORY_SEPARATOR . $taskId;
    if (!is_dir($subDir)) {
        @mkdir($subDir, 0775, true);
    }
    $dest = $subDir . DIRECTORY_SEPARATOR . $safeName;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        $_SESSION['error'] = 'Upload failed.';
        header('Location: college_uploads.php');
        exit;
    }

    $relPath = 'uploads/college/' . $taskId . '/' . $safeName;
    $chk = mysqli_prepare($conn, "SELECT submission_id FROM college_submissions WHERE task_id=? AND user_id=? LIMIT 1");
    mysqli_stmt_bind_param($chk, 'ii', $taskId, $uid);
    mysqli_stmt_execute($chk);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);

    if ($existing) {
        $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT file_path FROM college_submissions WHERE submission_id=" . (int)$existing['submission_id']));
        if (!empty($old['file_path']) && file_exists(__DIR__ . '/' . $old['file_path'])) {
            @unlink(__DIR__ . '/' . $old['file_path']);
        }
        $upd = mysqli_prepare($conn, "UPDATE college_submissions SET file_path=?, file_name=?, file_size=?, submitted_at=NOW(), status='submitted' WHERE submission_id=? AND user_id=?");
        $fn = $f['name'];
        $sz = (int)$f['size'];
        $sid = (int)$existing['submission_id'];
        mysqli_stmt_bind_param($upd, 'ssiii', $relPath, $fn, $sz, $sid, $uid);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    } else {
        $ins = mysqli_prepare($conn, "INSERT INTO college_submissions (task_id, user_id, file_path, file_name, file_size) VALUES (?, ?, ?, ?, ?)");
        $fn = $f['name'];
        $sz = (int)$f['size'];
        mysqli_stmt_bind_param($ins, 'iissi', $taskId, $uid, $relPath, $fn, $sz);
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
    }

    $_SESSION['message'] = 'File submitted successfully.';
    header('Location: college_uploads.php');
    exit;
}

$tasks = [];
$tq = mysqli_query($conn, "
  SELECT t.*, s.submission_id, s.file_name AS submitted_file, s.submitted_at AS submitted_at
  FROM college_upload_tasks t
  LEFT JOIN college_submissions s ON s.task_id=t.task_id AND s.user_id=" . (int)$uid . "
  WHERE t.is_open=1
  ORDER BY t.deadline ASC
");
if ($tq) {
    while ($row = mysqli_fetch_assoc($tq)) {
        $tasks[] = $row;
    }
    mysqli_free_result($tq);
}

$msg = $_SESSION['message'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="max-w-4xl mx-auto w-full px-4 sm:px-5 pt-2 pb-10">
    <h1 class="text-2xl font-bold text-[#143D59] m-0 flex items-center gap-2"><i class="bi bi-cloud-upload text-[#1665A0]"></i> Assignment uploads</h1>
    <p class="text-gray-600 mt-1 mb-6">Submit files before each deadline. You can replace your file until the deadline.</p>

    <?php if ($msg): ?><div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900"><?php echo h($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-900"><?php echo h($err); ?></div><?php endif; ?>

    <div class="space-y-4">
      <?php foreach ($tasks as $t):
        $open = $t['deadline'] >= $now;
      ?>
      <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap justify-between gap-2 mb-2">
          <h2 class="text-lg font-bold text-[#143D59] m-0"><?php echo h($t['title']); ?></h2>
          <span class="text-sm font-semibold <?php echo $open ? 'text-amber-700' : 'text-gray-500'; ?>">
            Due <?php echo h(date('M j, Y g:i A', strtotime($t['deadline']))); ?>
          </span>
        </div>
        <?php if (!empty($t['instructions'])): ?>
          <p class="text-gray-700 text-sm m-0 mb-4"><?php echo nl2br(h($t['instructions'])); ?></p>
        <?php endif; ?>
        <p class="text-xs text-gray-500 m-0 mb-3">Allowed: <?php echo h($t['allowed_extensions']); ?> · Max <?php echo (int)floor($t['max_file_size'] / 1048576); ?> MB</p>

        <?php if (!empty($t['submission_id'])): ?>
          <p class="text-sm text-emerald-800 m-0 mb-3"><i class="bi bi-check-circle"></i> Submitted: <strong><?php echo h($t['submitted_file']); ?></strong> · <?php echo h(date('M j, g:i A', strtotime($t['submitted_at']))); ?></p>
        <?php endif; ?>

        <?php if ($open): ?>
        <form method="post" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="upload_task_id" value="<?php echo (int)$t['task_id']; ?>">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">File</label>
            <input type="file" name="file" required class="text-sm">
          </div>
          <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold bg-[#1665A0] text-white hover:bg-[#145a8f] transition">
            <?php echo !empty($t['submission_id']) ? 'Replace file' : 'Upload'; ?>
          </button>
        </form>
        <?php else: ?>
          <p class="text-sm text-gray-500 m-0">Deadline passed.</p>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>

      <?php if (empty($tasks)): ?>
        <p class="text-gray-500">No upload tasks yet.</p>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
