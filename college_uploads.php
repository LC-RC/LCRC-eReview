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
  <style>
    .college-page-shell {
      width: 100%;
      margin-left: 0;
      margin-right: 0;
      max-width: none;
      padding: 0 0 2rem;
    }
    @media (min-width: 1024px) { .college-page-shell { padding: 0 0 2rem; } }
    @media (min-width: 1280px) { .college-page-shell { padding: 0 0 2rem; } }
    @media (min-width: 1536px) { .college-page-shell { padding: 0 0 2rem; } }

    .cstu-hero {
      border-radius: 0.75rem;
      border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
      box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22);
    }
    .cstu-hero-btn {
      border-radius: 9999px;
      transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
    }
    .cstu-hero-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 22px -20px rgba(14, 64, 105, .95);
    }
    .section-title {
      display: flex; align-items: center; gap: .5rem;
      margin: 0 0 .85rem; padding: .45rem .65rem;
      border: 1px solid #d8e8f6; border-radius: .62rem;
      background: linear-gradient(180deg,#f4f9fe 0%,#fff 100%);
      color: #143D59; font-size: 1.03rem; font-weight: 800;
      position: relative; overflow: hidden;
    }
    .section-title::after {
      content: "";
      position: absolute;
      left: 0.62rem;
      bottom: 0.36rem;
      width: 38px;
      height: 2px;
      border-radius: 9999px;
      background: linear-gradient(90deg, #1665A0 0%, rgba(22,101,160,0.12) 100%);
    }
    .section-title i {
      width: 1.55rem; height: 1.55rem; border-radius: .45rem;
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #b9daf2; background: #e8f2fa; color: #1665A0; font-size: .83rem;
    }
    .dash-card {
      border-radius: .75rem;
      border: 1px solid rgba(22,101,160,.18);
      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 60%);
      box-shadow: 0 10px 28px -22px rgba(20,61,89,.55), 0 1px 0 rgba(255,255,255,.85) inset;
      transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease, background-color .22s ease;
    }
    .dash-card:hover {
      transform: translateY(-2px);
      border-color: rgba(22,101,160,.32);
      background-color: #fdfeff;
      box-shadow: 0 20px 34px -24px rgba(20,61,89,.35);
    }
    .upload-meta {
      border: 1px solid #d6e8f7;
      background: #f8fbff;
      border-radius: .55rem;
      padding: .6rem .72rem;
      font-size: .75rem;
      color: #475569;
      margin-bottom: .85rem;
    }
    .upload-status {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      border-radius: 9999px;
      padding: .2rem .55rem;
      font-size: .72rem;
      font-weight: 700;
      border: 1px solid transparent;
    }
    .status-open { color: #b45309; background: #fffbeb; border-color: #fde68a; }
    .status-closed { color: #64748b; background: #f8fafc; border-color: #e2e8f0; }
    .status-submitted { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
    .field {
      border: 1px solid #cde2f4;
      border-radius: .55rem;
      background: #fff;
      color: #1f2937;
      padding: .5rem .65rem;
      font-size: .86rem;
    }
    .field:focus {
      outline: none;
      border-color: #7ab5e3;
      box-shadow: 0 0 0 3px rgba(22,101,160,.12);
    }
    .save-btn {
      display: inline-flex; align-items: center; gap: .45rem;
      border: 1px solid #1665A0; border-radius: .55rem;
      background: #1665A0; color: #fff; font-weight: 700;
      padding: .55rem .9rem; font-size: .83rem; transition: all .2s ease;
    }
    .save-btn:hover { transform: translateY(-1px); background: #145a8f; border-color: #145a8f; }
    .hint-btn {
      display: inline-flex; align-items: center; gap: .35rem;
      border: 1px solid #cde2f4; border-radius: .55rem;
      background: #fff; color: #1665A0; font-weight: 700;
      padding: .5rem .85rem; font-size: .8rem; transition: all .2s ease;
    }
    .hint-btn:hover { transform: translateY(-1px); background: #f4f9fe; border-color: #8fc0e8; }
    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
      .dash-card, .save-btn, .hint-btn, .cstu-hero-btn { transition: none !important; }
    }
  </style>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="college-page-shell pt-2">
    <section class="cstu-hero dash-anim delay-1 relative overflow-hidden mb-6 px-6 py-7">
      <div class="relative z-10 text-white">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3">
              <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 border border-white/30"><i class="bi bi-cloud-upload"></i></span>
              Assignment uploads
            </h1>
            <p class="text-white/90 mt-2 mb-0 max-w-2xl">Submit, replace, and track upload tasks before each deadline.</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <a href="college_student_dashboard.php" class="cstu-hero-btn inline-flex items-center gap-2 px-4 py-2.5 bg-white text-[#145a8f] font-semibold">
              <i class="bi bi-house-door"></i> Dashboard
            </a>
            <a href="college_exams.php" class="cstu-hero-btn inline-flex items-center gap-2 px-4 py-2.5 border border-white/35 bg-white/10 text-white font-semibold">
              <i class="bi bi-journal-text"></i> Exams
            </a>
          </div>
        </div>
      </div>
    </section>

    <?php if ($msg): ?><div class="dash-anim delay-1 mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900"><?php echo h($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="dash-anim delay-1 mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-900"><?php echo h($err); ?></div><?php endif; ?>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-folder2-open"></i> Upload Tasks</h2>
    <div class="space-y-4">
      <?php foreach ($tasks as $t):
        $open = $t['deadline'] >= $now;
      ?>
      <article class="dash-card dash-anim delay-2 p-5">
        <div class="flex flex-wrap justify-between gap-2 mb-2">
          <h2 class="text-lg font-bold text-[#143D59] m-0 flex items-center gap-2">
            <i class="bi bi-paperclip text-[#1665A0]"></i> <?php echo h($t['title']); ?>
          </h2>
          <span class="upload-status <?php echo $open ? 'status-open' : 'status-closed'; ?>">
            <i class="bi <?php echo $open ? 'bi-alarm' : 'bi-lock'; ?>"></i>
            Due <?php echo h(date('M j, Y g:i A', strtotime($t['deadline']))); ?>
          </span>
        </div>
        <?php if (!empty($t['instructions'])): ?>
          <p class="text-gray-700 text-sm m-0 mb-4"><?php echo nl2br(h($t['instructions'])); ?></p>
        <?php endif; ?>
        <div class="upload-meta">
          Allowed: <strong><?php echo h($t['allowed_extensions']); ?></strong> · Max <strong><?php echo (int)floor($t['max_file_size'] / 1048576); ?> MB</strong>
        </div>

        <?php if (!empty($t['submission_id'])): ?>
          <p class="text-sm m-0 mb-3">
            <span class="upload-status status-submitted"><i class="bi bi-check-circle"></i> Submitted</span>
            <span class="text-emerald-800"> <strong><?php echo h($t['submitted_file']); ?></strong> · <?php echo h(date('M j, g:i A', strtotime($t['submitted_at']))); ?></span>
          </p>
        <?php endif; ?>

        <?php if ($open): ?>
        <form method="post" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="upload_task_id" value="<?php echo (int)$t['task_id']; ?>">
          <div class="min-w-[260px]">
            <label class="block text-xs font-semibold text-gray-600 mb-1">File</label>
            <input type="file" name="file" required class="field w-full">
          </div>
          <button type="submit" class="save-btn">
            <?php echo !empty($t['submission_id']) ? 'Replace file' : 'Upload'; ?>
          </button>
        </form>
        <?php else: ?>
          <p class="text-sm text-gray-500 m-0">Deadline passed.</p>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>

      <?php if (empty($tasks)): ?>
        <div class="dash-card dash-anim delay-3 p-8 text-center">
          <div class="inline-flex items-center justify-center h-12 w-12 rounded-full border border-[#cde2f4] bg-[#eef6ff] text-[#1665A0] text-xl mb-3">
            <i class="bi bi-inbox"></i>
          </div>
          <p class="text-slate-600 font-medium m-0">No upload tasks yet.</p>
          <p class="text-sm text-slate-500 mt-1 mb-4">Once your instructor publishes a task, it will appear here.</p>
          <a href="college_student_dashboard.php" class="hint-btn"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
