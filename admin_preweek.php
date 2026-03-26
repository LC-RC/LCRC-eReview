<?php
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/preweek_migrate.php';

$csrf = generateCSRFToken();

$subjectId = sanitizeInt($_GET['subject_id'] ?? ($_POST['subject_id'] ?? 0));

// If no subject selected, show subject picker (per-subject Preweek)
if ($subjectId <= 0) {
    $subjectsResult = mysqli_query($conn, "SELECT subject_id, subject_name FROM subjects ORDER BY subject_name ASC");
    $pageTitle = 'Preweek';
    $adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Preweek'] ];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
      <style>
        .admin-preweek-page .page-hero {
          border: 1px solid #dbeafe;
          background: linear-gradient(135deg, #eff6ff 0%, #ffffff 70%);
          box-shadow: 0 12px 30px -22px rgba(37, 99, 235, 0.35);
        }
        .admin-preweek-page .page-card {
          border: 1px solid #dbeafe;
          box-shadow: 0 12px 28px -24px rgba(30, 64, 175, 0.3);
        }
        .admin-preweek-page .page-card thead th {
          text-transform: uppercase;
          letter-spacing: .02em;
          font-size: .78rem;
        }
      </style>
    </head>
    <body class="font-sans antialiased admin-app admin-preweek-page">
      <?php include 'admin_sidebar.php'; ?>

      <div class="bg-white rounded-xl shadow-card px-5 py-5 mb-5 page-hero">
        <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
        <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
          <i class="bi bi-lightning-charge"></i> Preweek
        </h1>
        <p class="text-gray-500 mt-1">Select a subject to manage its Preweek videos and handouts.</p>
      </div>

      <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden page-card">
        <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-2">
          <div class="flex items-center gap-2">
            <span class="font-semibold text-gray-800">Subjects</span>
            <span class="px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-200 text-gray-700"><?php echo (int)($subjectsResult ? mysqli_num_rows($subjectsResult) : 0); ?></span>
          </div>
        </div>
        <div class="overflow-x-auto pl-3 pr-8">
          <table class="w-full text-left">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="px-5 py-3 font-semibold text-gray-700 text-center">Subject</th>
                <th class="px-5 py-3 font-semibold text-gray-700 text-center w-[260px]">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$subjectsResult || mysqli_num_rows($subjectsResult) === 0): ?>
                <tr><td colspan="2" class="px-5 py-14 text-center text-gray-500">No subjects found.</td></tr>
              <?php else: ?>
                <?php mysqli_data_seek($subjectsResult, 0); while ($s = mysqli_fetch_assoc($subjectsResult)): ?>
                  <tr class="border-b border-gray-100 hover:bg-gray-50/50">
                    <td class="px-5 py-3 text-center"><div class="font-semibold text-gray-800"><?php echo h($s['subject_name']); ?></div></td>
                    <td class="px-5 py-3 text-center">
                      <div class="flex flex-wrap gap-2 items-center justify-center">
                        <a href="admin_preweek.php?subject_id=<?php echo (int)$s['subject_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-lightning-charge"></i> Manage Preweek</a>
                      </div>
                    </td>
                  </tr>
                <?php endwhile; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
    </main>
    </body>
    </html>
    <?php
    exit;
}

// ----- Videos: add/edit/delete -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_preweek.php');
        exit;
    }

    $type = $_POST['type'] ?? '';

    if ($type === 'video') {
        $videoId = sanitizeInt($_POST['preweek_video_id'] ?? 0);
        $title = trim($_POST['video_title'] ?? '');
        $uploadType = ($_POST['upload_type'] ?? 'url') === 'file' ? 'file' : 'url';
        $url = '';

        if ($uploadType === 'url') {
            $url = trim($_POST['video_url'] ?? '');
            if ($title === '' || $url === '') {
                $_SESSION['error'] = 'Video title and URL are required.';
                header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
                exit;
            }
        } else {
            if ($title === '') {
                $_SESSION['error'] = 'Video title is required.';
                header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
                exit;
            }
            if (empty($_FILES['video_file']) || ($_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $_SESSION['error'] = 'Please upload a video file.';
                header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
                exit;
            }
            $file = $_FILES['video_file'];
            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            $allowed = ['mp4','webm','mov','m4v','avi'];
            if ($ext === '' || !in_array($ext, $allowed, true)) {
                $_SESSION['error'] = 'Invalid video file type.';
                header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
                exit;
            }
            $dir = __DIR__ . '/uploads/videos';
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            $name = 'preweek_' . uniqid('', true) . '.' . $ext;
            $dest = $dir . '/' . $name;
            if (!@move_uploaded_file($file['tmp_name'], $dest)) {
                $_SESSION['error'] = 'Upload failed. Check folder permissions.';
                header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
                exit;
            }
            $url = 'uploads/videos/' . $name;
        }

        if ($videoId > 0) {
            // If replacing a file, remove old file
            $old = null;
            $res = mysqli_query($conn, "SELECT video_url, upload_type FROM preweek_videos WHERE preweek_video_id=" . (int)$videoId . " AND subject_id=" . (int)$subjectId . " LIMIT 1");
            $old = $res ? mysqli_fetch_assoc($res) : null;
            if ($old && ($old['upload_type'] ?? '') === 'file' && $uploadType === 'file') {
                $oldPath = (string)($old['video_url'] ?? '');
                if (strpos($oldPath, 'uploads/videos/') === 0) {
                    $abs = __DIR__ . '/' . $oldPath;
                    if (is_file($abs)) @unlink($abs);
                }
            }
            $stmt = mysqli_prepare($conn, "UPDATE preweek_videos SET video_title=?, video_url=?, upload_type=? WHERE preweek_video_id=? AND subject_id=?");
            mysqli_stmt_bind_param($stmt, 'sssii', $title, $url, $uploadType, $videoId, $subjectId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Preweek video updated.';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO preweek_videos (subject_id, video_title, video_url, upload_type) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'isss', $subjectId, $title, $url, $uploadType);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Preweek video added.';
        }

        header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
        exit;
    }

    if ($type === 'handout') {
        $handoutId = sanitizeInt($_POST['preweek_handout_id'] ?? 0);
        $title = trim($_POST['handout_title'] ?? '');
        $allow = !empty($_POST['allow_download']) ? 1 : 0;

        if ($title === '') {
            $_SESSION['error'] = 'Handout title is required.';
            header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
            exit;
        }

        $filePath = null;
        $fileName = null;
        $fileSize = null;
        $hasFile = !empty($_FILES['handout_file']) && ($_FILES['handout_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

        if ($handoutId <= 0 && !$hasFile) {
            $_SESSION['error'] = 'Please upload a handout file.';
            header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
            exit;
        }

        if ($hasFile) {
            $file = $_FILES['handout_file'];
            $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt'];
            if ($ext === '' || !in_array($ext, $allowed, true)) {
                $_SESSION['error'] = 'Invalid handout file type.';
                header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
                exit;
            }
            $dir = __DIR__ . '/uploads/handouts';
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            $name = 'preweek_' . uniqid('', true) . '.' . $ext;
            $dest = $dir . '/' . $name;
            if (!@move_uploaded_file($file['tmp_name'], $dest)) {
                $_SESSION['error'] = 'Upload failed. Check folder permissions.';
                header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
                exit;
            }
            $filePath = 'uploads/handouts/' . $name;
            $fileName = basename((string)($file['name'] ?? $name));
            $fileSize = (int)($file['size'] ?? 0);
        }

        if ($handoutId > 0) {
            if ($filePath !== null) {
                $res = mysqli_query($conn, "SELECT file_path FROM preweek_handouts WHERE preweek_handout_id=" . (int)$handoutId . " AND subject_id=" . (int)$subjectId . " LIMIT 1");
                $old = $res ? mysqli_fetch_assoc($res) : null;
                $oldPath = (string)($old['file_path'] ?? '');
                if (strpos($oldPath, 'uploads/handouts/') === 0) {
                    $abs = __DIR__ . '/' . $oldPath;
                    if (is_file($abs)) @unlink($abs);
                }
                $stmt = mysqli_prepare($conn, "UPDATE preweek_handouts SET handout_title=?, file_path=?, file_name=?, file_size=?, allow_download=? WHERE preweek_handout_id=? AND subject_id=?");
                mysqli_stmt_bind_param($stmt, 'sssiiii', $title, $filePath, $fileName, $fileSize, $allow, $handoutId, $subjectId);
            } else {
                $stmt = mysqli_prepare($conn, "UPDATE preweek_handouts SET handout_title=?, allow_download=? WHERE preweek_handout_id=? AND subject_id=?");
                mysqli_stmt_bind_param($stmt, 'siii', $title, $allow, $handoutId, $subjectId);
            }
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Preweek handout updated.';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO preweek_handouts (subject_id, handout_title, file_path, file_name, file_size, allow_download) VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'isssii', $subjectId, $title, $filePath, $fileName, $fileSize, $allow);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Preweek handout uploaded.';
        }

        header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
        exit;
    }
}

// GET actions
if (isset($_GET['delete_video'])) {
    $id = sanitizeInt($_GET['delete_video'] ?? 0);
    if ($id > 0) {
        $res = mysqli_query($conn, "SELECT video_url, upload_type FROM preweek_videos WHERE preweek_video_id=" . (int)$id . " AND subject_id=" . (int)$subjectId . " LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($row && ($row['upload_type'] ?? '') === 'file') {
            $p = (string)($row['video_url'] ?? '');
            if (strpos($p, 'uploads/videos/') === 0) {
                $abs = __DIR__ . '/' . $p;
                if (is_file($abs)) @unlink($abs);
            }
        }
        mysqli_query($conn, "DELETE FROM preweek_videos WHERE preweek_video_id=" . (int)$id . " AND subject_id=" . (int)$subjectId);
        $_SESSION['message'] = 'Preweek video deleted.';
    }
    header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
    exit;
}

if (isset($_GET['delete_handout'])) {
    $id = sanitizeInt($_GET['delete_handout'] ?? 0);
    if ($id > 0) {
        $res = mysqli_query($conn, "SELECT file_path FROM preweek_handouts WHERE preweek_handout_id=" . (int)$id . " AND subject_id=" . (int)$subjectId . " LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        $p = (string)($row['file_path'] ?? '');
        if (strpos($p, 'uploads/handouts/') === 0) {
            $abs = __DIR__ . '/' . $p;
            if (is_file($abs)) @unlink($abs);
        }
        mysqli_query($conn, "DELETE FROM preweek_handouts WHERE preweek_handout_id=" . (int)$id . " AND subject_id=" . (int)$subjectId);
        $_SESSION['message'] = 'Preweek handout deleted.';
    }
    header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
    exit;
}

if (isset($_GET['toggle_handout'])) {
    $id = sanitizeInt($_GET['toggle_handout'] ?? 0);
    if ($id > 0) {
        $res = mysqli_query($conn, "SELECT allow_download FROM preweek_handouts WHERE preweek_handout_id=" . (int)$id . " AND subject_id=" . (int)$subjectId . " LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        $cur = (int)($row['allow_download'] ?? 1);
        $newVal = $cur ? 0 : 1;
        mysqli_query($conn, "UPDATE preweek_handouts SET allow_download=" . $newVal . " WHERE preweek_handout_id=" . (int)$id . " AND subject_id=" . (int)$subjectId);
        $_SESSION['message'] = $newVal ? 'Downloads enabled.' : 'Downloads locked.';
    }
    header('Location: admin_preweek.php?subject_id=' . (int)$subjectId);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT subject_name FROM subjects WHERE subject_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$subRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$subjectName = $subRow['subject_name'] ?? 'Subject';

$videosStmt = mysqli_prepare($conn, "SELECT * FROM preweek_videos WHERE subject_id=? ORDER BY preweek_video_id DESC");
mysqli_stmt_bind_param($videosStmt, 'i', $subjectId);
mysqli_stmt_execute($videosStmt);
$videos = mysqli_stmt_get_result($videosStmt);

$handoutsStmt = mysqli_prepare($conn, "SELECT * FROM preweek_handouts WHERE subject_id=? ORDER BY preweek_handout_id DESC");
mysqli_stmt_bind_param($handoutsStmt, 'i', $subjectId);
mysqli_stmt_execute($handoutsStmt);
$handouts = mysqli_stmt_get_result($handoutsStmt);

$editVideo = null;
if (isset($_GET['edit_video'])) {
    $id = sanitizeInt($_GET['edit_video'] ?? 0);
    if ($id > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM preweek_videos WHERE preweek_video_id=? AND subject_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $id, $subjectId);
        mysqli_stmt_execute($stmt);
        $editVideo = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
}
$editHandout = null;
if (isset($_GET['edit_handout'])) {
    $id = sanitizeInt($_GET['edit_handout'] ?? 0);
    if ($id > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM preweek_handouts WHERE preweek_handout_id=? AND subject_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $id, $subjectId);
        mysqli_stmt_execute($stmt);
        $editHandout = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
}

$pageTitle = 'Preweek - ' . $subjectName;
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Content Hub', 'admin_subjects.php'], [$subjectName], ['Preweek'] ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    .admin-preweek-page .page-hero {
      border: 1px solid #dbeafe;
      background: linear-gradient(135deg, #eff6ff 0%, #ffffff 70%);
      box-shadow: 0 12px 30px -22px rgba(37, 99, 235, 0.35);
    }
    .admin-preweek-page .panel-card {
      border: 1px solid #dbeafe;
      box-shadow: 0 12px 28px -24px rgba(30, 64, 175, 0.3);
    }
    .admin-preweek-page .panel-card thead th {
      text-transform: uppercase;
      letter-spacing: .02em;
      font-size: .78rem;
    }
    .admin-preweek-page .panel-card tbody tr { transition: background-color .2s ease, transform .2s ease; }
    .admin-preweek-page .panel-card tbody tr:hover { background: #f8fbff; transform: translateY(-1px); }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-preweek-page">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-5 py-5 mb-5 page-hero">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-lightning-charge"></i> Preweek
    </h1>
    <p class="text-gray-500 mt-1">Subject: <strong><?php echo h($subjectName); ?></strong> · Upload and manage Preweek videos and handouts (students see the same list).</p>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="admin-flash admin-flash--success mb-5 p-4 rounded-xl flex items-center gap-2">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="admin-flash admin-flash--error mb-5 p-4 rounded-xl flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <!-- Videos -->
    <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden panel-card">
      <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
        <span class="font-semibold text-gray-800 flex items-center gap-2"><i class="bi bi-play-circle"></i> Videos</span>
        <?php if ($editVideo): ?>
          <a href="admin_preweek.php?subject_id=<?php echo (int)$subjectId; ?>" class="admin-outline-btn px-3 py-1.5 rounded-lg font-semibold border-2 transition text-sm">Cancel edit</a>
        <?php endif; ?>
      </div>
      <div class="p-5">
        <form method="POST" enctype="multipart/form-data" class="space-y-3 mb-4">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="type" value="video">
          <input type="hidden" name="subject_id" value="<?php echo (int)$subjectId; ?>">
          <?php if ($editVideo): ?><input type="hidden" name="preweek_video_id" value="<?php echo (int)$editVideo['preweek_video_id']; ?>"><?php endif; ?>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
            <input type="text" name="video_title" value="<?php echo h($editVideo['video_title'] ?? ''); ?>" class="input-custom" required>
          </div>
          <div x-data="{ uploadType: '<?php echo h(($editVideo['upload_type'] ?? 'url') === 'file' ? 'file' : 'url'); ?>' }">
            <label class="block text-sm font-medium text-gray-700 mb-1">Upload Type</label>
            <select name="upload_type" x-model="uploadType" class="input-custom">
              <option value="url">URL (YouTube/Vimeo/Link)</option>
              <option value="file">Upload Video File</option>
            </select>
            <div class="mt-3" x-show="uploadType === 'url'">
              <label class="block text-sm font-medium text-gray-700 mb-1">Video URL</label>
              <input type="url" name="video_url" value="<?php echo (($editVideo['upload_type'] ?? '') === 'url') ? h($editVideo['video_url'] ?? '') : ''; ?>" class="input-custom" placeholder="https://...">
            </div>
            <div class="mt-3" x-show="uploadType === 'file'" x-cloak>
              <label class="block text-sm font-medium text-gray-700 mb-1">Video file</label>
              <input type="file" name="video_file" class="input-custom" accept="video/*">
              <?php if ($editVideo && ($editVideo['upload_type'] ?? '') === 'file'): ?>
                <p class="text-sm text-gray-500 mt-1">Current file: <a href="<?php echo h($editVideo['video_url']); ?>" target="_blank" class="text-primary hover:underline">Open</a></p>
              <?php endif; ?>
              <p class="text-xs text-gray-500 mt-1">Allowed: mp4, webm, mov, m4v, avi</p>
            </div>
          </div>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition"><?php echo $editVideo ? 'Update video' : 'Add video'; ?></button>
        </form>

        <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden panel-card">
          <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-2">
            <div class="flex items-center gap-2">
              <span class="font-semibold text-gray-800">All videos</span>
              <span class="px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-200 text-gray-700"><?php echo (int)($videos ? mysqli_num_rows($videos) : 0); ?></span>
            </div>
          </div>
          <div class="overflow-x-auto pl-3 pr-8">
            <table class="w-full text-left">
              <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="px-5 py-3 font-semibold text-gray-700 text-center">Title</th>
                  <th class="px-5 py-3 font-semibold text-gray-700 text-center">Type</th>
                  <th class="px-5 py-3 font-semibold text-gray-700 text-center w-[260px]">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$videos || mysqli_num_rows($videos) === 0): ?>
                  <tr><td colspan="3" class="px-5 py-14 text-center text-gray-500">No videos yet.</td></tr>
                <?php else: ?>
                  <?php mysqli_data_seek($videos, 0); while ($v = mysqli_fetch_assoc($videos)): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50/50">
                      <td class="px-5 py-3 text-center">
                        <div class="font-semibold text-gray-800"><?php echo h($v['video_title']); ?></div>
                      </td>
                      <td class="px-5 py-3 text-center">
                        <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium <?php echo (($v['upload_type'] ?? '') === 'file') ? 'bg-gray-200 text-gray-700' : 'bg-sky-100 text-sky-800'; ?>">
                          <?php echo (($v['upload_type'] ?? '') === 'file') ? 'file' : 'url'; ?>
                        </span>
                      </td>
                      <td class="px-5 py-3 text-center">
                        <div class="flex flex-wrap gap-2 items-center justify-center">
                          <a href="<?php echo h($v['video_url']); ?>" target="_blank" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-box-arrow-up-right"></i> Open</a>
                          <a href="admin_preweek.php?subject_id=<?php echo (int)$subjectId; ?>&edit_video=<?php echo (int)$v['preweek_video_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition"><i class="bi bi-pencil"></i> Edit</a>
                          <a href="admin_preweek.php?subject_id=<?php echo (int)$subjectId; ?>&delete_video=<?php echo (int)$v['preweek_video_id']; ?>" onclick="return confirm('Delete this video?');" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition"><i class="bi bi-trash"></i> Delete</a>
                        </div>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Handouts -->
    <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden panel-card">
      <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
        <span class="font-semibold text-gray-800 flex items-center gap-2"><i class="bi bi-file-earmark-pdf"></i> Handouts</span>
        <?php if ($editHandout): ?>
          <a href="admin_preweek.php?subject_id=<?php echo (int)$subjectId; ?>" class="admin-outline-btn px-3 py-1.5 rounded-lg font-semibold border-2 transition text-sm">Cancel edit</a>
        <?php endif; ?>
      </div>
      <div class="p-5">
        <form method="POST" enctype="multipart/form-data" class="space-y-3 mb-4">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="type" value="handout">
          <input type="hidden" name="subject_id" value="<?php echo (int)$subjectId; ?>">
          <?php if ($editHandout): ?><input type="hidden" name="preweek_handout_id" value="<?php echo (int)$editHandout['preweek_handout_id']; ?>"><?php endif; ?>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
            <input type="text" name="handout_title" value="<?php echo h($editHandout['handout_title'] ?? ''); ?>" class="input-custom" required>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Upload file (PDF, DOC, PPT, etc.)</label>
            <input type="file" name="handout_file" class="input-custom" <?php echo $editHandout ? '' : 'required'; ?> accept=".pdf,.doc,.docx,.txt,.ppt,.pptx,.xls,.xlsx">
            <?php if ($editHandout && !empty($editHandout['file_path'])): ?>
              <p class="text-sm text-gray-500 mt-1">Current: <a href="<?php echo h($editHandout['file_path']); ?>" target="_blank" class="text-primary hover:underline">Download</a></p>
            <?php endif; ?>
          </div>
          <div class="flex items-center gap-2">
            <input type="checkbox" id="allowDownloadPreweek" name="allow_download" value="1" <?php echo (!$editHandout || (int)($editHandout['allow_download'] ?? 1) === 1) ? 'checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
            <label for="allowDownloadPreweek" class="text-sm font-medium text-gray-700">Allow students to download</label>
          </div>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition"><?php echo $editHandout ? 'Update handout' : 'Upload handout'; ?></button>
        </form>

        <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden panel-card">
          <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-2">
            <div class="flex items-center gap-2">
              <span class="font-semibold text-gray-800">All handouts</span>
              <span class="px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-200 text-gray-700"><?php echo (int)($handouts ? mysqli_num_rows($handouts) : 0); ?></span>
            </div>
          </div>
          <div class="overflow-x-auto pl-3 pr-8">
            <table class="w-full text-left">
              <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="px-5 py-3 font-semibold text-gray-700 text-center">Title</th>
                  <th class="px-5 py-3 font-semibold text-gray-700 text-center">File</th>
                  <th class="px-5 py-3 font-semibold text-gray-700 text-center">Downloads</th>
                  <th class="px-5 py-3 font-semibold text-gray-700 text-center w-[260px]">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$handouts || mysqli_num_rows($handouts) === 0): ?>
                  <tr><td colspan="4" class="px-5 py-14 text-center text-gray-500">No handouts yet.</td></tr>
                <?php else: ?>
                  <?php mysqli_data_seek($handouts, 0); while ($h = mysqli_fetch_assoc($handouts)): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50/50">
                      <td class="px-5 py-3 text-center"><div class="font-semibold text-gray-800"><?php echo h($h['handout_title']); ?></div></td>
                      <td class="px-5 py-3 text-center">
                        <a href="<?php echo h($h['file_path']); ?>" target="_blank" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-download"></i> Download</a>
                      </td>
                      <td class="px-5 py-3 text-center">
                        <?php if (!empty($h['allow_download'])): ?>
                          <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">Allowed</span>
                        <?php else: ?>
                          <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-gray-200 text-gray-700">Locked</span>
                        <?php endif; ?>
                      </td>
                      <td class="px-5 py-3 text-center">
                        <div class="flex flex-wrap gap-2 items-center justify-center">
                          <a href="admin_preweek.php?subject_id=<?php echo (int)$subjectId; ?>&edit_handout=<?php echo (int)$h['preweek_handout_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition"><i class="bi bi-pencil"></i> Edit</a>
                          <a href="admin_preweek.php?subject_id=<?php echo (int)$subjectId; ?>&toggle_handout=<?php echo (int)$h['preweek_handout_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-amber-500 text-amber-600 hover:bg-amber-500 hover:text-white transition"><i class="bi bi-lock"></i> <?php echo !empty($h['allow_download']) ? 'Lock' : 'Unlock'; ?></a>
                          <a href="admin_preweek.php?subject_id=<?php echo (int)$subjectId; ?>&delete_handout=<?php echo (int)$h['preweek_handout_id']; ?>" onclick="return confirm('Delete this handout?');" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition"><i class="bi bi-trash"></i> Delete</a>
                        </div>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>
</main>
</body>
</html>

