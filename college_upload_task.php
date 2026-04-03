<?php
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_upload_helpers.php';

$pageTitle = 'Upload task';
$uid = getCurrentUserId();
$csrf = generateCSRFToken();
$allowedTypesLabel = college_upload_allowed_types_label();
$fileAccept = '.pdf,.jpg,.jpeg,.png,application/pdf';
$taskId = sanitizeInt($_GET['id'] ?? 0);

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'college';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_task_id'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: college_upload_task.php?id=' . (int)$taskId);
        exit;
    }
    $postTid = sanitizeInt($_POST['upload_task_id'] ?? 0);
    if ($postTid !== $taskId || $taskId <= 0) {
        $_SESSION['error'] = 'Invalid task.';
        header('Location: college_uploads.php');
        exit;
    }

    $stmt = mysqli_prepare($conn, 'SELECT * FROM college_upload_tasks WHERE task_id=? AND is_open=1 LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $taskId);
    mysqli_stmt_execute($stmt);
    $taskPost = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$taskPost || college_upload_deadline_has_passed($taskPost['deadline'] ?? null)) {
        $_SESSION['error'] = 'This task is closed or past deadline.';
        header('Location: college_upload_task.php?id=' . (int)$taskId);
        exit;
    }

    if (empty($_FILES['file'])) {
        $_SESSION['error'] = 'Please choose a valid file.';
        header('Location: college_upload_task.php?id=' . (int)$taskId);
        exit;
    }

    $f = $_FILES['file'];
    $maxBytes = (int)$taskPost['max_file_size'];
    $valid = college_upload_validate_file($f, $maxBytes);
    if (!$valid['ok']) {
        $_SESSION['error'] = $valid['error'] ?? 'Upload rejected.';
        header('Location: college_upload_task.php?id=' . (int)$taskId);
        exit;
    }

    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $safeName = 'u' . $uid . '_t' . $taskId . '_' . bin2hex(random_bytes(6)) . ($ext !== '' ? '.' . $ext : '');
    $subDir = $uploadDir . DIRECTORY_SEPARATOR . $taskId;
    if (!is_dir($subDir)) {
        @mkdir($subDir, 0775, true);
    }
    $dest = $subDir . DIRECTORY_SEPARATOR . $safeName;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        $_SESSION['error'] = 'Upload failed.';
        header('Location: college_upload_task.php?id=' . (int)$taskId);
        exit;
    }

    $relPath = 'uploads/college/' . $taskId . '/' . $safeName;
    $chk = mysqli_prepare($conn, 'SELECT submission_id FROM college_submissions WHERE task_id=? AND user_id=? LIMIT 1');
    mysqli_stmt_bind_param($chk, 'ii', $taskId, $uid);
    mysqli_stmt_execute($chk);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);

    if ($existing) {
        $old = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT file_path FROM college_submissions WHERE submission_id=' . (int)$existing['submission_id']));
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
        $ins = mysqli_prepare($conn, 'INSERT INTO college_submissions (task_id, user_id, file_path, file_name, file_size) VALUES (?, ?, ?, ?, ?)');
        $fn = $f['name'];
        $sz = (int)$f['size'];
        mysqli_stmt_bind_param($ins, 'iissi', $taskId, $uid, $relPath, $fn, $sz);
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
    }

    $_SESSION['message'] = 'File submitted successfully.';
    header('Location: college_upload_task.php?id=' . (int)$taskId);
    exit;
}

if ($taskId <= 0) {
    $_SESSION['error'] = 'Task not found.';
    header('Location: college_uploads.php');
    exit;
}

$stmt = mysqli_prepare($conn, '
  SELECT
    t.task_id, t.title, t.instructions, t.deadline, t.max_file_size, t.allowed_extensions, t.is_open, t.created_by, t.created_at,
    s.submission_id, s.file_name AS submitted_file, s.submitted_at AS submitted_at
  FROM college_upload_tasks t
  LEFT JOIN college_submissions s ON s.task_id = t.task_id AND s.user_id = ?
  WHERE t.task_id = ? AND t.is_open = 1
  LIMIT 1
');
mysqli_stmt_bind_param($stmt, 'ii', $uid, $taskId);
mysqli_stmt_execute($stmt);
$task = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$task) {
    $_SESSION['error'] = 'This task is not available.';
    header('Location: college_uploads.php');
    exit;
}

$open = college_upload_deadline_allows_upload($task['deadline'] ?? null);
$fileViewUrl = '';
$fileDownloadUrl = '';
$viewKind = '';
if (!empty($task['submission_id']) && trim((string)($task['file_path'] ?? '')) !== '') {
    $fileViewUrl = 'college_upload_file.php?s=' . (int)$task['submission_id'];
    $fileDownloadUrl = $fileViewUrl . '&download=1';
    $viewKind = college_upload_view_kind_from_filename((string)($task['submitted_file'] ?? ''));
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
    .college-upload-task-page {
      width: 100%;
      margin-left: 0;
      margin-right: 0;
      max-width: none;
      padding: 0 0 2rem;
    }

    .cut-hero-banner {
      border-radius: 0.75rem;
      border: 1px solid rgba(255, 255, 255, 0.28);
      background: linear-gradient(130deg, #1665a0 0%, #145a8f 38%, #143d59 100%);
      box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255, 255, 255, 0.22);
      margin-bottom: 1.25rem;
    }
    .cut-back {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.8rem;
      font-weight: 800;
      color: rgba(255, 255, 255, 0.92);
      text-decoration: none;
      padding: 0.35rem 0.65rem;
      margin: 0 0 0.75rem;
      border-radius: 9999px;
      border: 1px solid rgba(255, 255, 255, 0.28);
      background: rgba(255, 255, 255, 0.1);
      transition: background-color 0.2s ease, border-color 0.2s ease;
    }
    .cut-back:hover {
      background: rgba(255, 255, 255, 0.18);
      border-color: rgba(255, 255, 255, 0.4);
      color: #fff;
    }

    .cut-title {
      font-size: clamp(1.05rem, 2.5vw, 1.5rem);
      font-weight: 900;
      color: #fff;
      margin: 0;
      line-height: 1.3;
      letter-spacing: -0.02em;
    }
    .cut-hero-row {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
    }
    .cut-status {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      border-radius: 9999px;
      padding: 0.35rem 0.75rem;
      font-size: 0.75rem;
      font-weight: 800;
      border: 1px solid transparent;
      flex-shrink: 0;
    }
    .cut-open { color: #78350f; background: #fffbeb; border-color: #fde68a; }
    .cut-closed { color: #475569; background: #f1f5f9; border-color: #e2e8f0; }
    .cut-done { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }

    .cut-section-title {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin: 0 0 0.85rem;
      padding: 0.45rem 0.65rem;
      border: 1px solid #d8e8f6;
      border-radius: 0.62rem;
      background: linear-gradient(180deg, #f4f9fe 0%, #fff 100%);
      color: #143d59;
      font-size: 1.03rem;
      font-weight: 800;
    }
    .cut-section-title i {
      width: 1.55rem;
      height: 1.55rem;
      border-radius: 0.45rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid #b9daf2;
      background: #e8f2fa;
      color: #1665a0;
      font-size: 0.83rem;
    }

    .cut-panel {
      border-radius: 0.75rem;
      border: 1px solid rgba(22, 101, 160, 0.18);
      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 60%);
      box-shadow: 0 10px 28px -22px rgba(20, 61, 89, 0.55), 0 1px 0 rgba(255, 255, 255, 0.85) inset;
      overflow: hidden;
    }
    .cut-panel-head {
      padding: 0.85rem 1.1rem;
      border-bottom: 1px solid #d6e8f7;
      background: linear-gradient(90deg, #f0f7fc 0%, #fff 100%);
      font-size: 0.9rem;
      font-weight: 800;
      color: #143d59;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .cut-panel-body { padding: 1rem 1.15rem 1.15rem; }

    .cut-prose {
      font-size: 0.9rem;
      line-height: 1.65;
      color: #334155;
      max-width: 65ch;
    }
    @media (min-width: 1024px) {
      .cut-prose { max-width: none; }
    }

    .cut-side-meta {
      font-size: 0.8rem;
      color: #475569;
      line-height: 1.5;
      padding: 0.75rem 0.85rem;
      border-radius: 0.55rem;
      border: 1px solid #d6e8f7;
      background: #f8fbff;
      margin-bottom: 1rem;
    }
    .cut-side-meta strong { color: #0f3550; }

    .cut-dropzone {
      position: relative;
      border: 2px dashed #9dc4e8;
      border-radius: 0.65rem;
      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
      padding: 1.35rem 1rem;
      text-align: center;
      cursor: pointer;
      min-height: 9rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
    }
    .cut-dropzone:hover,
    .cut-dropzone.cut-dz-focus {
      border-color: #1665a0;
      background: #f0f7fd;
      box-shadow: 0 0 0 3px rgba(22, 101, 160, 0.1);
    }
    .cut-dropzone.cut-dz-drag {
      border-color: #0d5a94;
      background: #e8f3fb;
    }
    .cut-dropzone input[type="file"] {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      opacity: 0;
      cursor: pointer;
    }
    .cut-submit {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      border: none;
      border-radius: 0.55rem;
      width: 100%;
      background: linear-gradient(180deg, #1a6fb5 0%, #145a8f 100%);
      color: #fff;
      font-weight: 800;
      font-size: 0.88rem;
      padding: 0.75rem 1.2rem;
      box-shadow: 0 8px 20px -10px rgba(20, 90, 143, 0.75);
      transition: filter 0.18s ease, transform 0.18s ease;
    }
    .cut-submit:hover {
      filter: brightness(1.05);
      transform: translateY(-1px);
    }
    .cut-preview-wrap {
      border-radius: 0.85rem;
      border: 1px solid rgba(22, 101, 160, 0.2);
      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 55%);
      overflow: hidden;
      margin-bottom: 1rem;
      box-shadow: 0 14px 36px -28px rgba(20, 61, 89, 0.45);
    }
    .cut-preview-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.5rem;
      flex-wrap: wrap;
      font-size: 0.7rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: #0f3550;
      padding: 0.55rem 0.85rem;
      background: linear-gradient(90deg, #e8f2fa 0%, #f0f9ff 100%);
      border-bottom: 1px solid #b9daf2;
    }
    .cut-preview-head > span:first-child { display: inline-flex; align-items: center; gap: 0.35rem; color: #145a8f; }
    .cut-fullview-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.38rem 0.65rem;
      border-radius: 0.5rem;
      border: 1px solid #93c5fd;
      background: #fff;
      color: #145a8f;
      font-size: 0.68rem;
      font-weight: 800;
      text-transform: none;
      letter-spacing: 0.02em;
      cursor: pointer;
      transition: background 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
    }
    .cut-fullview-btn:hover {
      background: #eff6ff;
      border-color: #3b82f6;
      transform: translateY(-1px);
    }
    .cut-preview-body { padding: 0.85rem; }
    .cut-preview-stage {
      position: relative;
      border-radius: 0.65rem;
      padding: 0.65rem;
      background: linear-gradient(145deg, #f0f9ff 0%, #f8fafc 45%, #e8f4fc 100%);
      border: 1px solid #bfdbfe;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
    }
    .cut-preview-img-wrap {
      display: block;
      margin: 0;
      padding: 0;
      border: none;
      background: transparent;
      width: 100%;
      cursor: zoom-in;
      border-radius: 0.5rem;
      overflow: hidden;
      line-height: 0;
    }
    .cut-preview-img-wrap:focus-visible {
      outline: 2px solid #1665a0;
      outline-offset: 3px;
    }
    .cut-preview-img {
      display: block;
      max-width: 100%;
      max-height: 22rem;
      width: auto;
      height: auto;
      margin: 0 auto;
      border-radius: 0.45rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      box-shadow: 0 12px 32px -20px rgba(15, 53, 80, 0.35);
    }
    .cut-preview-pdf {
      width: 100%;
      min-height: 26rem;
      border: none;
      border-radius: 0.45rem;
      background: #fff;
      box-shadow: 0 8px 24px -16px rgba(15, 23, 42, 0.2);
    }
    .cut-preview-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-top: 0.75rem;
    }
    .cut-preview-link {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.8rem;
      font-weight: 800;
      color: #1665a0;
      text-decoration: none;
      padding: 0.45rem 0.75rem;
      border-radius: 0.5rem;
      border: 1px solid #cde2f4;
      background: #fff;
      transition: background-color 0.15s ease, border-color 0.15s ease;
    }
    .cut-preview-link:hover {
      background: #f4f9fe;
      border-color: #93c5fd;
      color: #0f3550;
    }
    .ufl-overlay {
      position: fixed;
      inset: 0;
      z-index: 2000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 0.65rem;
    }
    .ufl-overlay.is-open { display: flex; }
    .ufl-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.76); backdrop-filter: blur(5px); }
    .ufl-dialog {
      position: relative;
      z-index: 1;
      width: 100%;
      max-width: min(96vw, 1180px);
      max-height: 92vh;
      display: flex;
      flex-direction: column;
      background: #0f172a;
      border-radius: 1rem;
      border: 1px solid rgba(255, 255, 255, 0.1);
      box-shadow: 0 28px 90px -24px rgba(0, 0, 0, 0.58);
      overflow: hidden;
    }
    .ufl-chrome {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.65rem;
      padding: 0.6rem 0.85rem;
      background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      flex-shrink: 0;
    }
    .ufl-title {
      font-size: 0.78rem;
      font-weight: 800;
      color: #e2e8f0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      min-width: 0;
    }
    .ufl-chrome-actions { display: flex; gap: 0.35rem; flex-shrink: 0; }
    .ufl-icon-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 2.2rem;
      height: 2.2rem;
      border-radius: 0.48rem;
      border: 1px solid rgba(255, 255, 255, 0.14);
      background: rgba(255, 255, 255, 0.06);
      color: #e2e8f0;
      cursor: pointer;
      text-decoration: none;
      transition: background 0.15s ease, color 0.15s ease;
    }
    .ufl-icon-btn:hover { background: rgba(255, 255, 255, 0.12); color: #fff; }
    .ufl-stage {
      flex: 1;
      min-height: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #020617;
      padding: 0.45rem;
    }
    .ufl-img {
      max-width: 100%;
      max-height: calc(92vh - 4.5rem);
      width: auto;
      height: auto;
      object-fit: contain;
      border-radius: 0.35rem;
    }
    .ufl-iframe {
      width: 100%;
      height: min(82vh, 880px);
      min-height: 360px;
      border: none;
      border-radius: 0.35rem;
      background: #fff;
    }
    .ufl-fallback { text-align: center; padding: 1.75rem 1.25rem; color: #94a3b8; font-size: 0.88rem; max-width: 28rem; }
    .ufl-fallback a { color: #7dd3fc; font-weight: 800; }
    @media (min-width: 1024px) {
      .cut-sticky-panel {
        position: sticky;
        top: 1rem;
        align-self: flex-start;
      }
    }

    .dash-anim { opacity: 0; transform: translateY(10px); animation: cutFadeUp 0.55s ease-out forwards; }
    .delay-1 { animation-delay: 0.05s; }
    .delay-2 { animation-delay: 0.12s; }
    @keyframes cutFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
      .cut-dropzone, .cut-submit, .cut-back { transition: none !important; }
    }
  </style>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="college-upload-task-page ereview-shell-no-fade pt-2">
    <section class="cut-hero-banner dash-anim delay-1 px-5 py-6 sm:px-6 sm:py-7">
      <div class="relative z-10 text-white w-full">
        <a href="college_uploads.php" class="cut-back"><i class="bi bi-arrow-left"></i> All upload tasks</a>
        <div class="cut-hero-row">
          <div class="min-w-0 flex-1 pr-2">
            <p class="text-[0.65rem] font-extrabold uppercase tracking-wider text-white/60 m-0 mb-1">Assignment task</p>
            <h1 class="cut-title"><?php echo h($task['title']); ?></h1>
          </div>
          <span class="cut-status <?php echo $open ? 'cut-open' : 'cut-closed'; ?>">
            <i class="bi <?php echo $open ? 'bi-clock-history' : 'bi-lock'; ?>"></i>
            <?php echo $open ? 'Due ' : 'Ended '; ?><?php echo h(date('M j, Y g:i A', strtotime($task['deadline']))); ?>
          </span>
        </div>
      </div>
    </section>

    <?php if ($msg): ?><div class="dash-anim delay-1 mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900 font-semibold text-sm"><?php echo h($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="dash-anim delay-1 mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-900 font-semibold text-sm"><?php echo h($err); ?></div><?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8">
      <div class="lg:col-span-7 xl:col-span-8 space-y-4 min-w-0">
        <h2 class="cut-section-title dash-anim delay-2 m-0"><i class="bi bi-card-text"></i> Instructions</h2>
        <article class="cut-panel dash-anim delay-2">
          <div class="cut-panel-head">
            <i class="bi bi-info-circle text-[#1665a0]"></i>
            Read carefully before you upload
          </div>
          <div class="cut-panel-body">
            <?php if (!empty($task['instructions'])): ?>
              <div class="cut-prose"><?php echo nl2br(h($task['instructions'])); ?></div>
            <?php else: ?>
              <p class="text-slate-500 m-0 text-sm">No extra instructions for this task. Upload your file according to the file rules on the right.</p>
            <?php endif; ?>
          </div>
        </article>
      </div>

      <aside class="lg:col-span-5 xl:col-span-4 min-w-0">
        <div class="cut-sticky-panel space-y-4">
          <h2 class="cut-section-title dash-anim delay-2 m-0"><i class="bi bi-cloud-upload"></i> Your submission</h2>

          <div class="cut-panel dash-anim delay-2">
            <div class="cut-panel-head">
              <i class="bi bi-file-earmark-check text-[#1665a0]"></i>
              File rules
            </div>
            <div class="cut-panel-body">
              <div class="cut-side-meta m-0">
                <strong>Allowed types:</strong> <?php echo h($allowedTypesLabel); ?><br>
                <strong>Maximum size:</strong> <?php echo (int)max(1, floor((int)$task['max_file_size'] / 1048576)); ?> MB<br>
                <span class="text-slate-500 text-xs mt-1 inline-block">Other formats are rejected on the server.</span>
              </div>

              <?php if (!empty($task['submission_id'])): ?>
                <div class="rounded-lg border border-emerald-200 bg-emerald-50/80 p-3 mb-4">
                  <p class="text-xs font-extrabold text-emerald-800 uppercase tracking-wide m-0 mb-1">Current file</p>
                  <p class="m-0 flex flex-wrap items-center gap-2">
                    <span class="cut-status cut-done text-xs"><i class="bi bi-check-circle-fill"></i> Submitted</span>
                  </p>
                  <p class="text-sm font-bold text-emerald-900 m-1 mt-2 break-words"><?php echo h($task['submitted_file']); ?></p>
                  <p class="text-xs text-emerald-700 m-0"><?php echo h(date('M j, Y g:i A', strtotime($task['submitted_at']))); ?></p>
                </div>
                <?php if ($fileViewUrl !== ''): ?>
                <div class="cut-preview-wrap">
                  <div class="cut-preview-head">
                    <span><i class="bi bi-eye"></i> Preview</span>
                    <button type="button" class="cut-fullview-btn" data-ufl-open
                      data-ufl-kind="<?php echo h($viewKind); ?>"
                      data-ufl-url="<?php echo h($fileViewUrl); ?>"
                      data-ufl-download="<?php echo h($fileDownloadUrl); ?>"
                      data-ufl-name="<?php echo h((string)($task['submitted_file'] ?? 'Your file')); ?>">
                      <i class="bi bi-arrows-fullscreen"></i> Full screen
                    </button>
                  </div>
                  <div class="cut-preview-body">
                    <?php if ($viewKind === 'image'): ?>
                      <div class="cut-preview-stage">
                        <button type="button" class="cut-preview-img-wrap text-center" data-ufl-open title="Open full-screen preview"
                          data-ufl-kind="image"
                          data-ufl-url="<?php echo h($fileViewUrl); ?>"
                          data-ufl-download="<?php echo h($fileDownloadUrl); ?>"
                          data-ufl-name="<?php echo h((string)($task['submitted_file'] ?? 'Your file')); ?>">
                          <img src="<?php echo h($fileViewUrl); ?>" alt="Your uploaded file" class="cut-preview-img">
                        </button>
                      </div>
                    <?php elseif ($viewKind === 'pdf'): ?>
                      <div class="cut-preview-stage">
                        <iframe class="cut-preview-pdf" title="PDF preview" src="<?php echo h($fileViewUrl); ?>"></iframe>
                      </div>
                    <?php else: ?>
                      <p class="text-sm text-slate-600 m-0">Preview is not available for this file type. Use full screen or open in a new tab.</p>
                    <?php endif; ?>
                    <div class="cut-preview-actions">
                      <a href="<?php echo h($fileViewUrl); ?>" target="_blank" rel="noopener" class="cut-preview-link"><i class="bi bi-box-arrow-up-right"></i> Open in new tab</a>
                      <a href="<?php echo h($fileDownloadUrl); ?>" class="cut-preview-link"><i class="bi bi-download"></i> Download</a>
                    </div>
                  </div>
                </div>
                <?php endif; ?>
              <?php endif; ?>

              <?php if ($open): ?>
              <form method="post" enctype="multipart/form-data" class="space-y-3" id="cut-upload-form">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="upload_task_id" value="<?php echo (int)$task['task_id']; ?>">
                <label class="cut-dropzone block m-0 w-full" id="cut-dz" tabindex="0">
                  <input type="file" name="file" required class="cut-file-input" accept="<?php echo h($fileAccept); ?>" aria-label="Choose file to upload">
                  <div class="text-3xl text-[#1665a0] mb-2"><i class="bi bi-cloud-arrow-up"></i></div>
                  <p class="font-extrabold text-[#0f3550] text-sm m-0 mb-1">Drop a file here or tap to browse</p>
                  <p class="text-xs text-slate-500 m-0 px-2"><?php echo h($allowedTypesLabel); ?> · up to <?php echo (int)max(1, floor((int)$task['max_file_size'] / 1048576)); ?> MB</p>
                  <p class="text-sm font-bold text-[#145a8f] mt-3 cut-filename-out min-h-[1.25em] px-2" aria-live="polite"></p>
                </label>
                <button type="submit" class="cut-submit">
                  <i class="bi <?php echo !empty($task['submission_id']) ? 'bi-arrow-repeat' : 'bi-upload'; ?>"></i>
                  <?php echo !empty($task['submission_id']) ? 'Replace file' : 'Upload file'; ?>
                </button>
              </form>
              <?php else: ?>
              <p class="text-sm text-slate-600 font-medium m-0">The deadline has passed. Contact your instructor if you need an exception.</p>
              <?php endif; ?>
            </div>
          </div>

          <div class="flex flex-wrap gap-2 dash-anim delay-2">
            <a href="college_uploads.php" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg border border-[#cde2f4] bg-white text-[#1665a0] font-bold text-sm hover:bg-[#f4f9fe] transition-colors w-full sm:w-auto">
              <i class="bi bi-grid-3x3-gap"></i> All tasks
            </a>
            <a href="college_student_dashboard.php" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg border border-[#cde2f4] bg-white text-[#1665a0] font-bold text-sm hover:bg-[#f4f9fe] transition-colors w-full sm:w-auto">
              <i class="bi bi-house-door"></i> Dashboard
            </a>
          </div>
        </div>
      </aside>
    </div>
  </div>

  <div id="ufl-overlay" class="ufl-overlay" aria-hidden="true">
    <div class="ufl-backdrop" data-ufl-close tabindex="-1" aria-hidden="true"></div>
    <div class="ufl-dialog" role="dialog" aria-modal="true" aria-labelledby="ufl-title">
      <div class="ufl-chrome">
        <span id="ufl-title" class="ufl-title">File</span>
        <div class="ufl-chrome-actions">
          <a id="ufl-newtab" class="ufl-icon-btn" href="#" target="_blank" rel="noopener" title="Open in new tab"><i class="bi bi-box-arrow-up-right"></i></a>
          <a id="ufl-download" class="ufl-icon-btn" href="#" title="Download"><i class="bi bi-download"></i></a>
          <button type="button" class="ufl-icon-btn" data-ufl-close title="Close (Esc)"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>
      <div class="ufl-stage">
        <img id="ufl-img" class="ufl-img" alt="" style="display:none">
        <iframe id="ufl-iframe" class="ufl-iframe" title="Document" style="display:none"></iframe>
        <div id="ufl-fallback" class="ufl-fallback" style="display:none">
          <p class="m-0">Inline preview is not available for this file type. Use the toolbar to open or download.</p>
          <p class="mt-3 mb-0"><a id="ufl-fallback-link" href="#" target="_blank" rel="noopener">Open in new tab</a></p>
        </div>
      </div>
    </div>
  </div>
  <script>
  (function () {
    var overlay = document.getElementById('ufl-overlay');
    if (overlay) {
      var titleEl = document.getElementById('ufl-title');
      var img = document.getElementById('ufl-img');
      var iframe = document.getElementById('ufl-iframe');
      var fallback = document.getElementById('ufl-fallback');
      var newTab = document.getElementById('ufl-newtab');
      var download = document.getElementById('ufl-download');
      var fallbackLink = document.getElementById('ufl-fallback-link');
      function uflClose() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        img.removeAttribute('src');
        iframe.removeAttribute('src');
      }
      function uflOpen(btn) {
        var kind = (btn.getAttribute('data-ufl-kind') || 'other').toLowerCase();
        var url = btn.getAttribute('data-ufl-url') || '';
        var dl = btn.getAttribute('data-ufl-download') || '';
        var name = btn.getAttribute('data-ufl-name') || 'File';
        if (!url) return;
        titleEl.textContent = name;
        newTab.href = url;
        download.href = dl || url;
        if (fallbackLink) fallbackLink.href = url;
        img.style.display = 'none';
        iframe.style.display = 'none';
        fallback.style.display = 'none';
        if (kind === 'image') {
          img.style.display = 'block';
          img.src = url;
        } else if (kind === 'pdf') {
          iframe.style.display = 'block';
          iframe.src = url;
        } else {
          fallback.style.display = 'block';
        }
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
      }
      document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-ufl-open]');
        if (t) {
          e.preventDefault();
          uflOpen(t);
        }
        if (e.target.closest('[data-ufl-close]')) {
          e.preventDefault();
          uflClose();
        }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) uflClose();
      });
    }
  })();
  </script>
  <script>
  (function () {
    var form = document.getElementById('cut-upload-form');
    if (!form) return;
    var zone = document.getElementById('cut-dz');
    var input = form.querySelector('.cut-file-input');
    var out = form.querySelector('.cut-filename-out');
    if (!zone || !input || !out) return;
    function setName() {
      var f = input.files && input.files[0];
      out.textContent = f ? f.name : '';
    }
    input.addEventListener('change', setName);
    zone.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
    zone.addEventListener('focus', function () { zone.classList.add('cut-dz-focus'); });
    zone.addEventListener('blur', function () { zone.classList.remove('cut-dz-focus'); });
    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        e.preventDefault();
        e.stopPropagation();
        zone.classList.add('cut-dz-drag');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        e.preventDefault();
        e.stopPropagation();
        zone.classList.remove('cut-dz-drag');
      });
    });
    zone.addEventListener('drop', function (e) {
      var dt = e.dataTransfer;
      if (!dt || !dt.files || !dt.files.length) return;
      try { input.files = dt.files; } catch (err) { return; }
      setName();
    });
  })();
  </script>
</main>
</body>
</html>
