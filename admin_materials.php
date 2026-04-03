<?php
require_once 'auth.php';
requireRole('admin');

$lessonId = (int)($_GET['lesson_id'] ?? 0);
if ($lessonId <= 0) { header('Location: admin_subjects.php'); exit; }

$lessonRes = mysqli_query($conn, "SELECT l.*, s.subject_name FROM lessons l JOIN subjects s ON s.subject_id=l.subject_id WHERE l.lesson_id=".$lessonId." LIMIT 1");
$lesson = $lessonRes ? mysqli_fetch_assoc($lessonRes) : null;
if (!$lesson) { header('Location: admin_subjects.php'); exit; }
$subjectId = (int)$lesson['subject_id'];

if (!function_exists('admin_materials_list_url')) {
    function admin_materials_list_url(int $lessonId, int $subjectId): string {
        $qs = ['lesson_id' => $lessonId, 'subject_id' => $subjectId];
        $q = trim($_GET['q'] ?? '');
        $t = $_GET['type'] ?? '';
        if ($q !== '') {
            $qs['q'] = $q;
        }
        if ($t !== '' && in_array($t, ['videos', 'handouts'], true)) {
            $qs['type'] = $t;
        }
        return 'admin_materials.php?' . http_build_query($qs);
    }
}

if (!function_exists('adminMaterialsUploadErrorMessage')) {
    function adminMaterialsUploadErrorMessage(int $code): string {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'Upload failed: file exceeds server upload_max_filesize limit.';
            case UPLOAD_ERR_FORM_SIZE:
                return 'Upload failed: file exceeds form MAX_FILE_SIZE limit.';
            case UPLOAD_ERR_PARTIAL:
                return 'Upload failed: file was only partially uploaded.';
            case UPLOAD_ERR_NO_FILE:
                return 'Upload failed: no file was selected.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Upload failed: missing temporary upload directory on server.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Upload failed: server could not write the uploaded file.';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload failed: a PHP extension blocked the upload.';
            default:
                return 'Upload failed due to an unexpected server upload error.';
        }
    }
}

if (!function_exists('adminMaterialsParseSizeToBytes')) {
    function adminMaterialsParseSizeToBytes(string $value): int {
        $value = trim($value);
        if ($value === '') return 0;
        $unit = strtolower(substr($value, -1));
        $num = (float)$value;
        switch ($unit) {
            case 'g':
                $num *= 1024;
                // no break
            case 'm':
                $num *= 1024;
                // no break
            case 'k':
                $num *= 1024;
        }
        return (int)$num;
    }
}

$materialsFlash = $_SESSION['admin_materials_flash'] ?? null;
unset($_SESSION['admin_materials_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
        $postMax = ini_get('post_max_size') ?: 'unknown';
        $uploadMax = ini_get('upload_max_filesize') ?: 'unknown';
        $postMaxBytes = adminMaterialsParseSizeToBytes((string)$postMax);
        $uploadMaxBytes = adminMaterialsParseSizeToBytes((string)$uploadMax);
        $_SESSION['admin_materials_flash'] = [
            'errors' => [
                'Upload failed: request payload exceeded server limits before PHP could read form data.',
                'Uploaded payload size: ' . number_format($contentLength) . ' bytes.',
                'Server limits — post_max_size: ' . $postMax . ', upload_max_filesize: ' . $uploadMax . '.',
                ($postMaxBytes > 0 && $contentLength > $postMaxBytes)
                    ? 'Root cause: file is larger than post_max_size.'
                    : (($uploadMaxBytes > 0 && $contentLength > $uploadMaxBytes)
                        ? 'Root cause: file is larger than upload_max_filesize.'
                        : 'Root cause: server rejected request body due to upload size policy.')
            ],
            'successes' => []
        ];
        header('Location: ' . admin_materials_list_url($lessonId, $subjectId));
        exit;
    }

    $type = $_POST['type'] ?? '';
    $errors = [];
    $successes = [];

    if ($type === 'video') {
        $videoId = (int)($_POST['video_id'] ?? 0);
        $title = trim($_POST['video_title'] ?? '');
        $url = trim($_POST['video_url'] ?? '');
        $uploadType = $_POST['upload_type'] ?? 'url';
        $finalUrl = $url;

        if ($uploadType === 'file') {
            if (!isset($_FILES['video_file'])) {
                $errors[] = 'Video upload failed: request does not include the video file payload.';
            } elseif ((int)$_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = adminMaterialsUploadErrorMessage((int)$_FILES['video_file']['error']);
            } else {
                $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'videos';
                if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
                    $errors[] = 'Video upload failed: unable to create uploads/videos directory.';
                } elseif (!is_writable($uploadsDir)) {
                    $errors[] = 'Video upload failed: uploads/videos directory is not writable.';
                } else {
                    $originalName = $_FILES['video_file']['name'];
                    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
                    $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
                    $fileName = 'video_' . uniqid('', true) . ($safeExt ? ('.' . $safeExt) : '');
                    $target = $uploadsDir . DIRECTORY_SEPARATOR . $fileName;
                    if (move_uploaded_file($_FILES['video_file']['tmp_name'], $target)) {
                        $finalUrl = 'uploads/videos/' . $fileName;
                    } else {
                        $errors[] = 'Video upload failed while moving file to uploads/videos.';
                    }
                }
            }
        } elseif ($finalUrl === '') {
            $errors[] = 'Video URL is required when upload type is URL.';
        }

        if (!$errors && $finalUrl !== '') {
            if ($videoId > 0) {
                $stmt = mysqli_prepare($conn, "UPDATE lesson_videos SET video_title=?, video_url=? WHERE video_id=? AND lesson_id=?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'ssii', $title, $finalUrl, $videoId, $lessonId);
                    if (!mysqli_stmt_execute($stmt)) {
                        $errors[] = 'Video update failed: ' . mysqli_stmt_error($stmt);
                    } else {
                        $successes[] = 'Video updated successfully.';
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $errors[] = 'Video update failed while preparing SQL statement.';
                }
            } else {
                $stmt = mysqli_prepare($conn, "INSERT INTO lesson_videos (lesson_id, video_title, video_url) VALUES (?, ?, ?)");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'iss', $lessonId, $title, $finalUrl);
                    if (!mysqli_stmt_execute($stmt)) {
                        $errors[] = 'Video insert failed: ' . mysqli_stmt_error($stmt);
                    } else {
                        $successes[] = 'Video added successfully.';
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $errors[] = 'Video insert failed while preparing SQL statement.';
                }
            }
        }
    }

    if ($type === 'handout') {
        $handoutId = (int)($_POST['handout_id'] ?? 0);
        $title = trim($_POST['handout_title'] ?? '');
        $allowDownload = isset($_POST['allow_download']) ? 1 : 0;
        $uploadedPath = null; $originalName = null; $fileSize = null;

        if (!isset($_FILES['handout_file'])) {
            $errors[] = 'Handout upload failed: request does not include the handout file payload.';
        } elseif ((int)$_FILES['handout_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = adminMaterialsUploadErrorMessage((int)$_FILES['handout_file']['error']);
        } else {
            $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'handouts';
            if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
                $errors[] = 'Handout upload failed: unable to create uploads/handouts directory.';
            } elseif (!is_writable($uploadsDir)) {
                $errors[] = 'Handout upload failed: uploads/handouts directory is not writable.';
            } else {
                $originalName = $_FILES['handout_file']['name'];
                $ext = pathinfo($originalName, PATHINFO_EXTENSION);
                $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
                $fileName = 'handout_' . uniqid('', true) . ($safeExt ? ('.' . $safeExt) : '');
                $target = $uploadsDir . DIRECTORY_SEPARATOR . $fileName;
                if (move_uploaded_file($_FILES['handout_file']['tmp_name'], $target)) {
                    $uploadedPath = 'uploads/handouts/' . $fileName;
                    $fileSize = (int)($_FILES['handout_file']['size'] ?? 0);
                    if ($title === '') {
                        $title = pathinfo($originalName, PATHINFO_FILENAME);
                    }
                } else {
                    $errors[] = 'Handout upload failed while moving file to uploads/handouts.';
                }
            }
        }

        if (!$errors && $handoutId > 0) {
            if ($uploadedPath) {
                $stmt = mysqli_prepare($conn, "UPDATE lesson_handouts SET handout_title=?, file_path=?, file_name=?, file_size=?, allow_download=? WHERE handout_id=? AND lesson_id=?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'sssiiii', $title, $uploadedPath, $originalName, $fileSize, $allowDownload, $handoutId, $lessonId);
                    if (!mysqli_stmt_execute($stmt)) {
                        $errors[] = 'Handout update failed: ' . mysqli_stmt_error($stmt);
                    } else {
                        $successes[] = 'Handout updated successfully.';
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $errors[] = 'Handout update failed while preparing SQL statement.';
                }
            } else {
                $stmt = mysqli_prepare($conn, "UPDATE lesson_handouts SET handout_title=?, allow_download=? WHERE handout_id=? AND lesson_id=?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'siii', $title, $allowDownload, $handoutId, $lessonId);
                    if (!mysqli_stmt_execute($stmt)) {
                        $errors[] = 'Handout update failed: ' . mysqli_stmt_error($stmt);
                    } else {
                        $successes[] = 'Handout updated successfully.';
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $errors[] = 'Handout update failed while preparing SQL statement.';
                }
            }
        } elseif (!$errors && $uploadedPath) {
            $stmt = mysqli_prepare($conn, "INSERT INTO lesson_handouts (lesson_id, handout_title, file_path, file_name, file_size, allow_download) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'isssii', $lessonId, $title, $uploadedPath, $originalName, $fileSize, $allowDownload);
                if (!mysqli_stmt_execute($stmt)) {
                    $errors[] = 'Handout insert failed: ' . mysqli_stmt_error($stmt);
                } else {
                    $successes[] = 'Handout uploaded successfully.';
                }
                mysqli_stmt_close($stmt);
            } else {
                $errors[] = 'Handout insert failed while preparing SQL statement.';
            }
        }
    }

    $_SESSION['admin_materials_flash'] = [
        'errors' => $errors,
        'successes' => $successes
    ];
    header('Location: ' . admin_materials_list_url($lessonId, $subjectId));
    exit;
}

if (isset($_GET['delete_video'])) {
    $delId = (int)$_GET['delete_video'];
    $delRes = mysqli_query($conn, "SELECT video_url FROM lesson_videos WHERE video_id=".$delId." AND lesson_id=".$lessonId." LIMIT 1");
    $delVideo = $delRes ? mysqli_fetch_assoc($delRes) : null;
    if ($delVideo && strpos($delVideo['video_url'], 'uploads/videos/') === 0 && file_exists($delVideo['video_url'])) @unlink($delVideo['video_url']);
    mysqli_query($conn, "DELETE FROM lesson_videos WHERE video_id=".$delId." AND lesson_id=".$lessonId);
    header('Location: ' . admin_materials_list_url($lessonId, $subjectId));
    exit;
}
if (isset($_GET['delete_handout'])) {
    $delId = (int)$_GET['delete_handout'];
    $delRes = mysqli_query($conn, "SELECT file_path FROM lesson_handouts WHERE handout_id=".$delId." AND lesson_id=".$lessonId." LIMIT 1");
    $del = $delRes ? mysqli_fetch_assoc($delRes) : null;
    if ($del && !empty($del['file_path']) && file_exists($del['file_path'])) @unlink($del['file_path']);
    mysqli_query($conn, "DELETE FROM lesson_handouts WHERE handout_id=".$delId." AND lesson_id=".$lessonId);
    header('Location: ' . admin_materials_list_url($lessonId, $subjectId));
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
    header('Location: ' . admin_materials_list_url($lessonId, $subjectId));
    exit;
}

$searchQ = trim($_GET['q'] ?? '');
$matType = $_GET['type'] ?? '';
if ($matType !== '' && !in_array($matType, ['videos', 'handouts'], true)) {
    $matType = '';
}
$showVideos = ($matType === '' || $matType === 'videos');
$showHandouts = ($matType === '' || $matType === 'handouts');

if ($searchQ === '') {
    $videos = mysqli_query($conn, 'SELECT * FROM lesson_videos WHERE lesson_id=' . (int)$lessonId . ' ORDER BY video_id DESC');
} else {
    $like = '%' . $searchQ . '%';
    $stmtV = mysqli_prepare($conn, 'SELECT * FROM lesson_videos WHERE lesson_id=? AND (video_title LIKE ? OR video_url LIKE ?) ORDER BY video_id DESC');
    mysqli_stmt_bind_param($stmtV, 'iss', $lessonId, $like, $like);
    mysqli_stmt_execute($stmtV);
    $videos = mysqli_stmt_get_result($stmtV);
}
if ($searchQ === '') {
    $handouts = mysqli_query($conn, 'SELECT * FROM lesson_handouts WHERE lesson_id=' . (int)$lessonId . ' ORDER BY handout_id DESC');
} else {
    $likeH = '%' . $searchQ . '%';
    $stmtH = mysqli_prepare($conn, 'SELECT * FROM lesson_handouts WHERE lesson_id=? AND (handout_title LIKE ? OR IFNULL(file_name, \'\') LIKE ?) ORDER BY handout_id DESC');
    mysqli_stmt_bind_param($stmtH, 'iss', $lessonId, $likeH, $likeH);
    mysqli_stmt_execute($stmtH);
    $handouts = mysqli_stmt_get_result($stmtH);
}
$pageTitle = 'Materials - ' . $lesson['title'];
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Content Hub', 'admin_subjects.php'], [ h($lesson['subject_name']), 'admin_lessons.php?subject_id=' . $subjectId ], [ h($lesson['title']), 'admin_lessons.php?subject_id=' . $subjectId ], ['Materials'] ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    .admin-materials-submit-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      padding: 0.7rem 1.1rem;
      border-radius: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.01em;
      border: 1px solid rgba(16, 185, 129, 0.45);
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: #ffffff;
      box-shadow: 0 6px 18px rgba(16, 185, 129, 0.26), inset 0 1px 0 rgba(255,255,255,0.2);
      transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
    }
    .admin-materials-submit-btn:hover {
      transform: translateY(-1px);
      filter: brightness(1.04);
      box-shadow: 0 10px 24px rgba(16, 185, 129, 0.32), inset 0 1px 0 rgba(255,255,255,0.25);
    }
    .admin-materials-submit-btn:active {
      transform: translateY(0);
    }
    .admin-materials-submit-btn:focus-visible {
      outline: none;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25), 0 8px 20px rgba(16, 185, 129, 0.28);
    }

    .admin-upload-input {
      border-radius: 0.8rem !important;
      border: 1px solid rgba(148, 163, 184, 0.35) !important;
      background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(248,250,252,0.95)) !important;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }
    .admin-upload-input:hover {
      border-color: rgba(14, 165, 233, 0.55) !important;
      box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
    }
    .admin-upload-input:focus-within {
      border-color: rgba(14, 165, 233, 0.7) !important;
      box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.18);
    }
    .admin-upload-input::file-selector-button {
      border: 1px solid rgba(71, 85, 105, 0.35);
      border-radius: 0.65rem;
      background: linear-gradient(135deg, #ffffff 0%, #f1f5f9 100%);
      color: #0f172a;
      font-weight: 700;
      padding: 0.48rem 0.9rem;
      margin-right: 0.65rem;
      cursor: pointer;
      transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }
    .admin-upload-input::file-selector-button:hover {
      transform: translateY(-1px);
      background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
      box-shadow: 0 4px 10px rgba(15, 23, 42, 0.12);
    }
  </style>
  <link rel="stylesheet" href="assets/css/admin-quiz-ui.css?v=3">
</head>
<body class="font-sans antialiased admin-app admin-materials-page" x-data="{ uploadType: 'url' }">
  <?php include 'admin_sidebar.php'; ?>

  <div class="quiz-admin-hero rounded-xl px-6 py-5 mb-5">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <?php if (!empty($materialsFlash['errors'])): ?>
      <div class="mb-4 rounded-xl border border-red-500/35 bg-red-500/10 px-4 py-3 text-red-100">
        <p class="font-semibold mb-1"><i class="bi bi-exclamation-triangle mr-1"></i>Upload failed</p>
        <ul class="list-disc pl-5 space-y-1 text-sm">
          <?php foreach ($materialsFlash['errors'] as $err): ?>
            <li><?php echo h((string)$err); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if (!empty($materialsFlash['successes'])): ?>
      <div class="mb-4 rounded-xl border border-emerald-500/35 bg-emerald-500/10 px-4 py-3 text-emerald-100">
        <p class="font-semibold mb-1"><i class="bi bi-check-circle mr-1"></i>Success</p>
        <ul class="list-disc pl-5 space-y-1 text-sm">
          <?php foreach ($materialsFlash['successes'] as $ok): ?>
            <li><?php echo h((string)$ok); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <h1 class="text-2xl font-bold text-gray-100 m-0 flex flex-wrap items-center gap-2">
      <span class="quiz-admin-hero-icon" aria-hidden="true"><i class="bi bi-folder-plus"></i></span>
      Materials — <?php echo h($lesson['title']); ?> <span class="text-gray-500 font-medium text-lg">(<?php echo h($lesson['subject_name']); ?>)</span>
    </h1>
    <p class="text-gray-400 mt-2 mb-0 max-w-3xl text-sm sm:text-base">Videos and handouts for this lesson — add, edit, or manage download access.</p>
  </div>

  <form method="get" action="admin_materials.php" class="quiz-admin-filter quiz-admin-table-shell rounded-xl px-4 py-3 mb-4 flex flex-wrap items-end gap-3">
    <input type="hidden" name="lesson_id" value="<?php echo (int)$lessonId; ?>">
    <input type="hidden" name="subject_id" value="<?php echo (int)$subjectId; ?>">
    <div class="flex-1 min-w-[200px]">
      <label for="mat-search-q" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Search</label>
      <input type="search" id="mat-search-q" name="q" value="<?php echo h($searchQ); ?>" placeholder="Search video or handout titles…" class="input-custom w-full" autocomplete="off">
    </div>
    <div class="w-full sm:w-44">
      <label for="mat-search-type" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Show</label>
      <select id="mat-search-type" name="type" class="input-custom w-full">
        <option value=""<?php echo $matType === '' ? ' selected' : ''; ?>>Videos &amp; handouts</option>
        <option value="videos"<?php echo $matType === 'videos' ? ' selected' : ''; ?>>Videos only</option>
        <option value="handouts"<?php echo $matType === 'handouts' ? ' selected' : ''; ?>>Handouts only</option>
      </select>
    </div>
    <div class="flex flex-wrap gap-2">
      <button type="submit" class="quiz-admin-filter-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-funnel"></i> Apply</button>
      <?php if ($searchQ !== '' || $matType !== ''): ?>
        <a href="admin_materials.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="quiz-admin-filter-clear px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2">Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="<?php echo ($showVideos && $showHandouts) ? 'grid grid-cols-1 lg:grid-cols-2 gap-5' : 'grid grid-cols-1 gap-5'; ?>">
    <?php if ($showVideos): ?>
    <div class="quiz-admin-table-shell rounded-xl overflow-hidden">
      <div class="quiz-admin-table-head px-5 py-4 flex flex-wrap justify-between items-center gap-2">
        <span class="font-semibold text-gray-100 flex items-center gap-2"><i class="bi bi-play-circle text-sky-400"></i> Videos</span>
        <a href="admin_lessons.php?subject_id=<?php echo (int)$subjectId; ?>" class="text-sm px-3 py-1.5 rounded-lg font-medium border border-white/18 text-gray-300 hover:bg-white/10 hover:text-white transition">Back to Lessons</a>
      </div>
      <div class="p-5 materials-panel-inner">
        <form method="POST" action="<?php echo h(admin_materials_list_url($lessonId, $subjectId)); ?>" enctype="multipart/form-data" class="space-y-3 mb-4">
          <input type="hidden" name="type" value="video">
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Title</label>
            <input type="text" name="video_title" id="videoTitleInput" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Upload Type</label>
            <select name="upload_type" x-model="uploadType" class="input-custom">
              <option value="url">URL (YouTube/Vimeo/Link)</option>
              <option value="file">Upload Video File</option>
            </select>
          </div>
          <div x-show="uploadType === 'url'" x-cloak>
            <label class="block text-sm font-medium text-gray-300 mb-1">Video URL</label>
            <input type="url" name="video_url" class="input-custom" placeholder="https://...">
          </div>
          <div x-show="uploadType === 'file'" x-cloak>
            <label class="block text-sm font-medium text-gray-300 mb-1">Upload Video File</label>
            <input type="file" name="video_file" id="videoFileInput" class="input-custom admin-upload-input" accept="video/*">
          </div>
          <button type="submit" class="admin-materials-submit-btn"><i class="bi bi-plus-circle"></i><span>Add Video</span></button>
        </form>
        <div class="overflow-x-auto pl-3 pr-8">
          <table class="materials-data-table w-full text-left">
            <thead>
              <tr>
                <th class="px-5 py-3 font-semibold text-center">Title</th>
                <th class="px-5 py-3 font-semibold text-center">URL</th>
                <th class="px-5 py-3 font-semibold text-center w-[220px]">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php mysqli_data_seek($videos, 0); while ($v = mysqli_fetch_assoc($videos)): ?>
                <tr class="materials-data-row">
                  <td class="px-5 py-3 text-center">
                    <div class="font-semibold text-gray-100"><?php echo h($v['video_title']); ?></div>
                  </td>
                  <td class="px-5 py-3 text-center max-w-[260px] truncate">
                    <a href="<?php echo h($v['video_url']); ?>" target="_blank" class="mat-link-open inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-semibold border transition"><i class="bi bi-box-arrow-up-right"></i> Open</a>
                  </td>
                  <td class="px-5 py-3 text-center">
                    <div class="flex flex-wrap gap-2 items-center justify-center">
                      <a href="<?php echo h(admin_materials_list_url($lessonId, $subjectId)); ?>&delete_video=<?php echo (int)$v['video_id']; ?>" onclick="return confirm('Delete this video?');" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500/55 text-red-300 hover:bg-red-600 hover:text-white transition"><i class="bi bi-trash"></i> Delete</a>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
              <?php if (mysqli_num_rows($videos) == 0): ?>
                <tr><td colspan="3" class="px-5 py-14 text-center text-gray-500"><?php echo $searchQ !== '' ? 'No videos match your search.' : 'No videos yet.'; ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($showHandouts): ?>
    <div class="quiz-admin-table-shell rounded-xl overflow-hidden">
      <div class="quiz-admin-table-head px-5 py-4 font-semibold text-gray-100 flex items-center gap-2"><i class="bi bi-file-earmark-pdf text-amber-300"></i> Handouts</div>
      <div class="p-5 materials-panel-inner">
        <form method="POST" action="<?php echo h(admin_materials_list_url($lessonId, $subjectId)); ?>" enctype="multipart/form-data" class="space-y-3 mb-4">
          <input type="hidden" name="type" value="handout">
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Title</label>
            <input type="text" name="handout_title" id="handoutTitleInput" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Upload File (PDF, DOC, PPT, etc.)</label>
            <input type="file" name="handout_file" id="handoutFileInput" class="input-custom admin-upload-input" accept=".pdf,.doc,.docx,.txt,.ppt,.pptx,.xls,.xlsx" required>
          </div>
          <div class="flex items-center gap-2">
            <input type="checkbox" id="allowDownloadHandout" name="allow_download" value="1" checked class="rounded border-white/20 text-emerald-500 focus:ring-emerald-500 bg-[#1a1a1a]">
            <label for="allowDownloadHandout" class="text-sm font-medium text-gray-300">Allow students to download</label>
          </div>
          <button type="submit" class="admin-materials-submit-btn"><i class="bi bi-cloud-upload"></i><span>Upload Handout</span></button>
        </form>
        <div class="overflow-x-auto pl-3 pr-8">
          <table class="materials-data-table w-full text-left">
            <thead>
              <tr>
                <th class="px-5 py-3 font-semibold text-center">Title</th>
                <th class="px-5 py-3 font-semibold text-center">File</th>
                <th class="px-5 py-3 font-semibold text-center">Downloads</th>
                <th class="px-5 py-3 font-semibold text-center w-[220px]">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php mysqli_data_seek($handouts, 0); while ($h = mysqli_fetch_assoc($handouts)): ?>
                <tr class="materials-data-row">
                  <td class="px-5 py-3 text-center">
                    <div class="font-semibold text-gray-100"><?php echo h($h['handout_title'] ?: 'Untitled'); ?></div>
                  </td>
                  <td class="px-5 py-3 text-center">
                    <?php if (!empty($h['file_path'])): ?>
                      <a href="<?php echo h($h['file_path']); ?>" target="_blank" class="mat-link-open inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-semibold border transition"><i class="bi bi-download"></i> Download</a>
                    <?php else: ?>
                      <span class="text-gray-500">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-5 py-3 text-center">
                    <?php if (!empty($h['allow_download'])): ?><span class="px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/15 text-emerald-300 border border-emerald-500/35">Allowed</span>
                    <?php else: ?><span class="px-2 py-0.5 rounded-full text-xs font-medium bg-white/10 text-gray-400 border border-white/15">Locked</span><?php endif; ?>
                  </td>
                  <td class="px-5 py-3 text-center">
                    <div class="flex flex-wrap gap-2 items-center justify-center">
                      <a href="<?php echo h(admin_materials_list_url($lessonId, $subjectId)); ?>&toggle_handout=<?php echo (int)$h['handout_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-amber-500/55 text-amber-200 hover:bg-amber-500 hover:text-white transition"><i class="bi bi-lock"></i> <?php echo !empty($h['allow_download']) ? 'Lock' : 'Unlock'; ?></a>
                      <a href="<?php echo h(admin_materials_list_url($lessonId, $subjectId)); ?>&delete_handout=<?php echo (int)$h['handout_id']; ?>" onclick="return confirm('Delete this handout?');" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500/55 text-red-300 hover:bg-red-600 hover:text-white transition"><i class="bi bi-trash"></i> Delete</a>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
              <?php if (mysqli_num_rows($handouts) == 0): ?>
                <tr><td colspan="4" class="px-5 py-14 text-center text-gray-500"><?php echo $searchQ !== '' ? 'No handouts match your search.' : 'No handouts yet.'; ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
</main>
<script>
  (function () {
    function toTitleFromFilename(fileName) {
      if (!fileName) return '';
      var base = fileName.replace(/\.[^/.]+$/, '');
      base = base.replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
      return base;
    }

    function bindAutoTitle(fileInputId, titleInputId) {
      var fileInput = document.getElementById(fileInputId);
      var titleInput = document.getElementById(titleInputId);
      if (!fileInput || !titleInput) return;
      fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        if (!file) return;
        if (titleInput.value.trim() !== '') return;
        titleInput.value = toTitleFromFilename(file.name);
      });
    }

    bindAutoTitle('handoutFileInput', 'handoutTitleInput');
    bindAutoTitle('videoFileInput', 'videoTitleInput');
  })();
</script>
<script>
  // Console-upload debugger hook: run `window.__adminMaterialsDebugAttach()` in DevTools.
  window.__adminMaterialsDebugAttach = function () {
    var form = document.querySelector('form input[name="type"][value="handout"]')?.closest('form');
    var fileInput = document.getElementById('handoutFileInput');
    var titleInput = document.getElementById('handoutTitleInput');
    if (!form || !fileInput) {
      console.error('[materials-debug] Handout form/input not found.');
      return;
    }
    if (form.dataset.debugAttached === '1') {
      console.warn('[materials-debug] Already attached.');
      return;
    }
    form.dataset.debugAttached = '1';

    form.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      var file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
      console.group('[materials-debug] Handout upload submit');
      console.log('action:', form.action || location.href);
      console.log('method:', (form.method || 'POST').toUpperCase());
      console.log('title:', titleInput ? titleInput.value : '');
      if (file) {
        console.log('file.name:', file.name);
        console.log('file.size(bytes):', file.size);
        console.log('file.type:', file.type || '(empty)');
        var ext = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '(none)';
        console.log('file.ext:', ext);
      } else {
        console.warn('No file selected.');
      }

      try {
        var fd = new FormData(form);
        var res = await fetch(form.action || location.href, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          redirect: 'manual'
        });
        console.log('response.status:', res.status);
        console.log('response.type:', res.type);
        console.log('response.redirected:', res.redirected);
        console.log('response.url:', res.url);
        var loc = res.headers.get('location');
        if (loc) console.log('response.location header:', loc);
        var text = await res.text();
        console.log('response snippet:', text.slice(0, 600));
      } catch (e) {
        console.error('fetch error:', e);
      }
      console.groupEnd();
      console.info('[materials-debug] Upload request sent via fetch for inspection (page not auto-reloaded).');
    });
    console.info('[materials-debug] Attached. Submit handout form now.');
  };
</script>
</body>
</html>
