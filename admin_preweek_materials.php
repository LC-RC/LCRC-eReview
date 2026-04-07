<?php
/**
 * Preweek materials admin — same UX and upload rules as admin_materials.php
 * (video URL or file upload, handout file, allow download toggle, search/filter).
 * Data: preweek_topics → preweek_videos / preweek_handouts (under preweek_units).
 */
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/preweek_migrate.php';

$topicId = (int)($_GET['preweek_topic_id'] ?? 0);
$subjectIdLegacy = (int)($_GET['subject_id'] ?? 0);
$unitId = 0;
$unitTitle = 'Preweek';
$topicTitle = 'Lecture';

if ($topicId <= 0) {
    header('Location: admin_preweek.php');
    exit;
}

$topicRes = mysqli_query(
    $conn,
    'SELECT t.preweek_topic_id, t.title AS topic_title, t.preweek_unit_id, u.title AS unit_title
     FROM preweek_topics t
     INNER JOIN preweek_units u ON u.preweek_unit_id = t.preweek_unit_id
     WHERE t.preweek_topic_id=' . (int)$topicId . ' AND u.subject_id=0
     LIMIT 1'
);
$topicRow = $topicRes ? mysqli_fetch_assoc($topicRes) : null;
if (!$topicRow) {
    header('Location: admin_preweek.php');
    exit;
}
$unitId = (int)$topicRow['preweek_unit_id'];
$unitTitle = trim((string)($topicRow['unit_title'] ?? 'Preweek')) ?: 'Preweek';
$topicTitle = trim((string)($topicRow['topic_title'] ?? '')) ?: 'Untitled';

if ($subjectIdLegacy > 0) {
    header('Location: admin_preweek_materials.php?' . http_build_query(['preweek_topic_id' => $topicId]));
    exit;
}

if (!function_exists('admin_preweek_materials_list_url')) {
    function admin_preweek_materials_list_url(int $topicId): string {
        $qs = ['preweek_topic_id' => $topicId];
        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $qs['q'] = $q;
        }
        if (array_key_exists('type', $_GET)) {
            $t = (string)$_GET['type'];
            if ($t === '' || in_array($t, ['videos', 'handouts'], true)) {
                $qs['type'] = $t;
            }
        }
        return 'admin_preweek_materials.php?' . http_build_query($qs);
    }
}

$preweekZeroSubject = 0;

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

if (!function_exists('ereview_format_bytes')) {
    function ereview_format_bytes($n): string
    {
        $n = (int)$n;
        if ($n <= 0) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $x = (float)$n;
        $i = 0;
        while ($x >= 1024 && $i < count($units) - 1) {
            $x /= 1024;
            $i++;
        }
        if ($i === 0) {
            return (string)(int)$x . ' ' . $units[$i];
        }
        return number_format($x, $i >= 2 ? 2 : 1) . ' ' . $units[$i];
    }
}

if (!function_exists('ereview_normalize_uploaded_files')) {
    /**
     * Normalize $_FILES[field] to a list of file dicts (single or multiple upload).
     */
    function ereview_normalize_uploaded_files(string $field): array
    {
        $out = [];
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            return $out;
        }
        $f = $_FILES[$field];
        if (!isset($f['name'])) {
            return $out;
        }
        if (!is_array($f['name'])) {
            if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $out[] = [
                    'name' => (string)$f['name'],
                    'tmp_name' => (string)$f['tmp_name'],
                    'error' => (int)$f['error'],
                    'size' => (int)($f['size'] ?? 0),
                ];
            }
            return $out;
        }
        $n = count($f['name']);
        for ($i = 0; $i < $n; $i++) {
            $err = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_OK) {
                $out[] = [
                    'name' => (string)$f['name'][$i],
                    'tmp_name' => (string)$f['tmp_name'][$i],
                    'error' => $err,
                    'size' => (int)($f['size'][$i] ?? 0),
                ];
            }
        }
        return $out;
    }
}

$materialsFlash = $_SESSION['admin_preweek_materials_flash'] ?? null;
unset($_SESSION['admin_preweek_materials_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
        $postMax = ini_get('post_max_size') ?: 'unknown';
        $uploadMax = ini_get('upload_max_filesize') ?: 'unknown';
        $postMaxBytes = adminMaterialsParseSizeToBytes((string)$postMax);
        $uploadMaxBytes = adminMaterialsParseSizeToBytes((string)$uploadMax);
        $_SESSION['admin_preweek_materials_flash'] = [
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
        header('Location: ' . admin_preweek_materials_list_url($topicId));
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
        $uploadTypeStr = ($uploadType === 'file') ? 'file' : 'url';
        $finalUrl = $url;
        $existing = null;

        if ($videoId > 0) {
            $er = mysqli_query($conn, 'SELECT video_url, upload_type FROM preweek_videos WHERE preweek_video_id=' . (int)$videoId . ' AND preweek_topic_id=' . (int)$topicId . ' LIMIT 1');
            $existing = $er ? mysqli_fetch_assoc($er) : null;
            if (!$existing) {
                $errors[] = 'That video could not be found.';
            }
        }

        if (!$errors) {
            if ($uploadType === 'file') {
                $hasFile = isset($_FILES['video_file']) && (int)$_FILES['video_file']['error'] === UPLOAD_ERR_OK;
                if ($hasFile) {
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
                            if ($videoId > 0 && $existing && strpos((string)$existing['video_url'], 'uploads/videos/') === 0) {
                                $oldAbs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$existing['video_url']);
                                if (is_file($oldAbs) && $oldAbs !== $target) {
                                    @unlink($oldAbs);
                                }
                            }
                        } else {
                            $errors[] = 'Video upload failed while moving file to uploads/videos.';
                        }
                    }
                } elseif ($videoId > 0 && $existing) {
                    $finalUrl = (string)$existing['video_url'];
                    $uploadTypeStr = (($existing['upload_type'] ?? '') === 'file') ? 'file' : 'url';
                } elseif (isset($_FILES['video_file']) && (int)$_FILES['video_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = adminMaterialsUploadErrorMessage((int)$_FILES['video_file']['error']);
                } else {
                    $errors[] = $videoId > 0
                        ? 'Choose a new video file to replace the current one, or switch to “Link” and paste a URL.'
                        : 'Please choose a video file.';
                }
            } elseif ($finalUrl === '') {
                $errors[] = 'Video URL is required when using a link.';
            }
        }

        if (!$errors && $uploadType === 'url' && $finalUrl !== '' && !filter_var($finalUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Please enter a valid URL starting with http:// or https://.';
        }

        if (!$errors && $finalUrl !== '' && $title === '') {
            $errors[] = 'Video title is required so students can recognize this item in the list.';
        }

        if (!$errors && $finalUrl !== '') {
            if ($videoId > 0) {
                $stmt = mysqli_prepare($conn, 'UPDATE preweek_videos SET video_title=?, video_url=?, upload_type=? WHERE preweek_video_id=? AND preweek_topic_id=?');
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'sssii', $title, $finalUrl, $uploadTypeStr, $videoId, $topicId);
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
                $stmt = mysqli_prepare($conn, 'INSERT INTO preweek_videos (subject_id, preweek_unit_id, preweek_topic_id, video_title, video_url, upload_type) VALUES (?, ?, ?, ?, ?, ?)');
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'iiisss', $preweekZeroSubject, $unitId, $topicId, $title, $finalUrl, $uploadTypeStr);
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
        $allowedHandoutExt = ['pdf', 'doc', 'docx', 'txt', 'ppt', 'pptx', 'xls', 'xlsx'];

        $batchFiles = ereview_normalize_uploaded_files('handout_file');

        if ($handoutId > 0) {
            // Edit existing: at most one replacement file
            $uploadedPath = null;
            $originalName = null;
            $fileSize = null;
            $fileErr = UPLOAD_ERR_NO_FILE;
            if (isset($_FILES['handout_file'])) {
                $fn = $_FILES['handout_file']['name'] ?? null;
                if (!is_array($fn)) {
                    $fileErr = (int)($_FILES['handout_file']['error'] ?? UPLOAD_ERR_NO_FILE);
                } else {
                    $fileErr = isset($_FILES['handout_file']['error'][0]) ? (int)$_FILES['handout_file']['error'][0] : UPLOAD_ERR_NO_FILE;
                }
            }
            $hasNewFile = count($batchFiles) === 1 && $batchFiles[0]['error'] === UPLOAD_ERR_OK;

            if ($hasNewFile) {
                $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'handouts';
                if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
                    $errors[] = 'Handout upload failed: unable to create uploads/handouts directory.';
                } elseif (!is_writable($uploadsDir)) {
                    $errors[] = 'Handout upload failed: uploads/handouts directory is not writable.';
                } else {
                    $originalName = basename(str_replace('\\', '/', $batchFiles[0]['name']));
                    $extLower = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    if ($extLower === '' || !in_array($extLower, $allowedHandoutExt, true)) {
                        $errors[] = 'Handout must be PDF, Word, PowerPoint, Excel, or plain text (.pdf, .doc/.docx, .ppt/.pptx, .xls/.xlsx, .txt).';
                    }
                    if (!$errors) {
                        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
                        $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
                        $fileName = 'handout_' . uniqid('', true) . ($safeExt ? ('.' . $safeExt) : '');
                        $target = $uploadsDir . DIRECTORY_SEPARATOR . $fileName;
                        if (move_uploaded_file($batchFiles[0]['tmp_name'], $target)) {
                            $uploadedPath = 'uploads/handouts/' . $fileName;
                            $fileSize = (int)$batchFiles[0]['size'];
                            if ($title === '') {
                                $title = pathinfo($originalName, PATHINFO_FILENAME);
                            }
                        } else {
                            $errors[] = 'Handout upload failed while moving file to uploads/handouts.';
                        }
                    }
                }
            } elseif (count($batchFiles) > 1) {
                $errors[] = 'When editing, upload only one replacement file.';
            } elseif (isset($_FILES['handout_file']) && $fileErr !== UPLOAD_ERR_NO_FILE && $fileErr !== UPLOAD_ERR_OK) {
                $errors[] = adminMaterialsUploadErrorMessage($fileErr);
            }

            if (!$errors && $handoutId > 0) {
                if ($uploadedPath) {
                    $oldRes = mysqli_query($conn, 'SELECT file_path FROM preweek_handouts WHERE preweek_handout_id=' . (int)$handoutId . ' AND preweek_topic_id=' . (int)$topicId . ' LIMIT 1');
                    $oldRow = $oldRes ? mysqli_fetch_assoc($oldRes) : null;
                    if ($oldRow && !empty($oldRow['file_path']) && (string)$oldRow['file_path'] !== $uploadedPath) {
                        $oldAbs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$oldRow['file_path']);
                        if (is_file($oldAbs)) {
                            @unlink($oldAbs);
                        }
                    }
                    $stmt = mysqli_prepare($conn, 'UPDATE preweek_handouts SET handout_title=?, file_path=?, file_name=?, file_size=?, allow_download=? WHERE preweek_handout_id=? AND preweek_topic_id=?');
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, 'sssiiii', $title, $uploadedPath, $originalName, $fileSize, $allowDownload, $handoutId, $topicId);
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
                    $stmt = mysqli_prepare($conn, 'UPDATE preweek_handouts SET handout_title=?, allow_download=? WHERE preweek_handout_id=? AND preweek_topic_id=?');
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, 'siii', $title, $allowDownload, $handoutId, $topicId);
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
            }
        } else {
            // Add: multiple files or whole folder (handout_file[])
            if (count($batchFiles) === 0) {
                $errors[] = 'Please choose at least one file to upload.';
            } else {
                $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'handouts';
                if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
                    $errors[] = 'Handout upload failed: unable to create uploads/handouts directory.';
                } elseif (!is_writable($uploadsDir)) {
                    $errors[] = 'Handout upload failed: uploads/handouts directory is not writable.';
                } else {
                    $okCount = 0;
                    $singleCustomTitle = (count($batchFiles) === 1 && $title !== '');
                    foreach ($batchFiles as $fi => $bf) {
                        $rawName = (string)$bf['name'];
                        $baseName = basename(str_replace('\\', '/', $rawName));
                        if ($baseName === '.DS_Store' || $baseName === 'Thumbs.db' || strpos($baseName, '._') === 0) {
                            continue;
                        }
                        $extLower = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));
                        if ($extLower === '' || !in_array($extLower, $allowedHandoutExt, true)) {
                            $errors[] = 'Skipped (unsupported type): ' . $baseName;
                            continue;
                        }
                        $ext = pathinfo($baseName, PATHINFO_EXTENSION);
                        $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
                        $fileName = 'handout_' . uniqid('', true) . ($safeExt ? ('.' . $safeExt) : '');
                        $target = $uploadsDir . DIRECTORY_SEPARATOR . $fileName;
                        if (!move_uploaded_file($bf['tmp_name'], $target)) {
                            $errors[] = 'Failed to save: ' . $baseName;
                            continue;
                        }
                        $uploadedPath = 'uploads/handouts/' . $fileName;
                        $fileSize = (int)$bf['size'];
                        $rowTitle = $singleCustomTitle ? $title : pathinfo($baseName, PATHINFO_FILENAME);
                        $stmt = mysqli_prepare($conn, 'INSERT INTO preweek_handouts (subject_id, preweek_unit_id, preweek_topic_id, handout_title, file_path, file_name, file_size, allow_download) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        if ($stmt) {
                            mysqli_stmt_bind_param($stmt, 'iiisssii', $preweekZeroSubject, $unitId, $topicId, $rowTitle, $uploadedPath, $baseName, $fileSize, $allowDownload);
                            if (!mysqli_stmt_execute($stmt)) {
                                $errors[] = 'Database error for ' . $baseName . ': ' . mysqli_stmt_error($stmt);
                                @unlink($target);
                            } else {
                                $okCount++;
                            }
                            mysqli_stmt_close($stmt);
                        } else {
                            $errors[] = 'Could not save ' . $baseName . '.';
                            @unlink($target);
                        }
                    }
                    if ($okCount > 0) {
                        $successes[] = 'Uploaded ' . $okCount . ' handout' . ($okCount === 1 ? '' : 's') . ' successfully.';
                    } elseif (empty($errors)) {
                        $errors[] = 'No valid handout files were uploaded.';
                    }
                }
            }
        }
    }

    $_SESSION['admin_preweek_materials_flash'] = [
        'errors' => $errors,
        'successes' => $successes
    ];
    header('Location: ' . admin_preweek_materials_list_url($topicId));
    exit;
}

if (isset($_GET['delete_video'])) {
    $delId = (int)$_GET['delete_video'];
    $delRes = mysqli_query($conn, "SELECT video_url FROM preweek_videos WHERE preweek_video_id=" . $delId . " AND preweek_topic_id=" . $topicId . " LIMIT 1");
    $delVideo = $delRes ? mysqli_fetch_assoc($delRes) : null;
    if ($delVideo && strpos((string)($delVideo['video_url'] ?? ''), 'uploads/videos/') === 0) {
        $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$delVideo['video_url']);
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
    mysqli_query($conn, "DELETE FROM preweek_videos WHERE preweek_video_id=" . $delId . " AND preweek_topic_id=" . $topicId);
    header('Location: ' . admin_preweek_materials_list_url($topicId));
    exit;
}
if (isset($_GET['delete_handout'])) {
    $delId = (int)$_GET['delete_handout'];
    $delRes = mysqli_query($conn, "SELECT file_path FROM preweek_handouts WHERE preweek_handout_id=" . $delId . " AND preweek_topic_id=" . $topicId . " LIMIT 1");
    $del = $delRes ? mysqli_fetch_assoc($delRes) : null;
    if ($del && !empty($del['file_path'])) {
        $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$del['file_path']);
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
    mysqli_query($conn, "DELETE FROM preweek_handouts WHERE preweek_handout_id=" . $delId . " AND preweek_topic_id=" . $topicId);
    header('Location: ' . admin_preweek_materials_list_url($topicId));
    exit;
}
if (isset($_GET['toggle_handout'])) {
    $toggleId = (int)$_GET['toggle_handout'];
    $toggleRes = mysqli_query($conn, "SELECT allow_download FROM preweek_handouts WHERE preweek_handout_id=" . $toggleId . " AND preweek_topic_id=" . $topicId . " LIMIT 1");
    $toggleRow = $toggleRes ? mysqli_fetch_assoc($toggleRes) : null;
    if ($toggleRow) {
        $newValue = $toggleRow['allow_download'] ? 0 : 1;
        mysqli_query($conn, "UPDATE preweek_handouts SET allow_download=" . (int)$newValue . " WHERE preweek_handout_id=" . $toggleId . " AND preweek_topic_id=" . $topicId);
    }
    header('Location: ' . admin_preweek_materials_list_url($topicId));
    exit;
}

$searchQ = trim($_GET['q'] ?? '');
// No `type` in URL → default to videos-only. Explicit `type=` means "Videos & handouts".
$matTypeExplicit = array_key_exists('type', $_GET);
if (!$matTypeExplicit) {
    $matType = 'videos';
} else {
    $t = (string)$_GET['type'];
    if ($t === '') {
        $matType = '';
    } elseif (in_array($t, ['videos', 'handouts'], true)) {
        $matType = $t;
    } else {
        $matType = 'videos';
    }
}
$showVideos = ($matType === '' || $matType === 'videos');
$showHandouts = ($matType === '' || $matType === 'handouts');

if ($searchQ === '') {
    $videos = mysqli_query($conn, 'SELECT * FROM preweek_videos WHERE preweek_topic_id=' . (int)$topicId . ' ORDER BY preweek_video_id DESC');
} else {
    $like = '%' . $searchQ . '%';
    $stmtV = mysqli_prepare($conn, 'SELECT * FROM preweek_videos WHERE preweek_topic_id=? AND (video_title LIKE ? OR video_url LIKE ?) ORDER BY preweek_video_id DESC');
    mysqli_stmt_bind_param($stmtV, 'iss', $topicId, $like, $like);
    mysqli_stmt_execute($stmtV);
    $videos = mysqli_stmt_get_result($stmtV);
}
if ($searchQ === '') {
    $handouts = mysqli_query($conn, 'SELECT * FROM preweek_handouts WHERE preweek_topic_id=' . (int)$topicId . ' ORDER BY preweek_handout_id DESC');
} else {
    $likeH = '%' . $searchQ . '%';
    $stmtH = mysqli_prepare($conn, 'SELECT * FROM preweek_handouts WHERE preweek_topic_id=? AND (handout_title LIKE ? OR IFNULL(file_name, \'\') LIKE ?) ORDER BY preweek_handout_id DESC');
    mysqli_stmt_bind_param($stmtH, 'iss', $topicId, $likeH, $likeH);
    mysqli_stmt_execute($stmtH);
    $handouts = mysqli_stmt_get_result($stmtH);
}
$pageTitle = 'Preweek materials — ' . h($topicTitle);
$topicsListUrl = 'admin_preweek_topics.php?' . http_build_query(['preweek_unit_id' => $unitId]);
$preweekNavStep = 'materials';
$preweekNavUnitId = $unitId;
$preweekNavUnitTitle = $unitTitle;
$preweekNavTopicId = $topicId;
$preweekNavTopicTitle = $topicTitle;
$preweekVideoCount = ($videos && is_object($videos)) ? mysqli_num_rows($videos) : 0;
$preweekHandoutCount = ($handouts && is_object($handouts)) ? mysqli_num_rows($handouts) : 0;
$preweekUploadMax = ini_get('upload_max_filesize') ?: '—';
$preweekPostMax = ini_get('post_max_size') ?: '—';
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

    .preweek-materials-hero-bar {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem 1.25rem;
    }
    .preweek-stat-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.35rem 0.75rem;
      border-radius: 9999px;
      font-size: 0.8125rem;
      font-weight: 600;
      border: 1px solid rgba(255, 255, 255, 0.12);
      background: rgba(255, 255, 255, 0.06);
      color: #e2e8f0;
    }
    .preweek-guidelines {
      border: 1px solid rgba(59, 130, 246, 0.28);
      background: linear-gradient(135deg, rgba(30, 58, 138, 0.22) 0%, rgba(15, 23, 42, 0.5) 100%);
      border-radius: 0.75rem;
      padding: 0.875rem 1.125rem;
      font-size: 0.875rem;
      color: #cbd5e1;
      line-height: 1.5;
    }
    .preweek-guidelines strong { color: #f1f5f9; font-weight: 600; }
    .preweek-add-panel {
      border: 1px solid rgba(148, 163, 184, 0.2);
      border-radius: 0.75rem;
      background: rgba(15, 23, 42, 0.35);
      padding: 1rem 1.125rem 1.125rem;
      margin-bottom: 1.25rem;
    }
    .preweek-add-panel h3 {
      margin: 0 0 0.75rem 0;
      font-size: 0.9375rem;
      font-weight: 700;
      color: #f8fafc;
      letter-spacing: 0.02em;
    }
    .preweek-field-hint {
      font-size: 0.75rem;
      color: #94a3b8;
      margin-top: 0.25rem;
      line-height: 1.4;
    }
    .preweek-empty-state {
      text-align: center;
      padding: 2.5rem 1rem;
      color: #94a3b8;
    }
    .preweek-empty-state .preweek-empty-icon {
      font-size: 2.25rem;
      opacity: 0.45;
      margin-bottom: 0.5rem;
    }
    .admin-materials-submit-btn:disabled {
      opacity: 0.65;
      cursor: not-allowed;
      transform: none;
      filter: none;
    }
    [x-cloak] { display: none !important; }

    .preweek-cell-mono {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 0.75rem;
      line-height: 1.45;
      color: #cbd5e1;
      word-break: break-all;
      max-width: 28rem;
      text-align: left;
    }
    .preweek-badge-type {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.2rem 0.55rem;
      border-radius: 9999px;
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .preweek-badge-type--link { background: rgba(56, 189, 248, 0.15); color: #7dd3fc; border: 1px solid rgba(56, 189, 248, 0.35); }
    .preweek-badge-type--file { background: rgba(167, 139, 250, 0.15); color: #c4b5fd; border: 1px solid rgba(167, 139, 250, 0.35); }
    .preweek-modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 2000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      background: rgba(15, 23, 42, 0.75);
      backdrop-filter: blur(4px);
    }
    .preweek-modal-overlay[hidden] { display: none !important; }
    .preweek-modal-panel--add {
      max-width: 32rem;
    }
    .preweek-modal-panel {
      width: 100%;
      max-width: 28rem;
      max-height: 90vh;
      overflow-y: auto;
      border-radius: 0.875rem;
      border: 1px solid rgba(148, 163, 184, 0.25);
      background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
    }
    .preweek-modal-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.75rem;
      padding: 1rem 1.125rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }
    .preweek-modal-body { padding: 1rem 1.125rem 1.125rem; }
    .preweek-btn-edit {
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      padding: 0.4rem 0.75rem;
      border-radius: 0.5rem;
      font-size: 0.8125rem;
      font-weight: 600;
      border: 1px solid rgba(96, 165, 250, 0.45);
      color: #93c5fd;
      background: rgba(59, 130, 246, 0.12);
      cursor: pointer;
      transition: background 0.15s ease, color 0.15s ease;
    }
    .preweek-btn-edit:hover { background: rgba(59, 130, 246, 0.25); color: #fff; }
    .preweek-section-toolbar {
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(15, 23, 42, 0.45);
    }
    .preweek-table-full-wrap {
      overflow-x: auto;
    }
    .preweek-table-full-wrap .preweek-cell-mono {
      max-width: none;
    }
    .preweek-materials-stack {
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
    }
  </style>
  <link rel="stylesheet" href="assets/css/admin-quiz-ui.css?v=10">
</head>
<body class="font-sans antialiased admin-app admin-materials-page admin-preweek-materials-page">
  <?php include 'admin_sidebar.php'; ?>

  <?php require __DIR__ . '/includes/admin_preweek_context_nav.php'; ?>

  <div class="quiz-admin-hero rounded-xl px-5 py-5 mb-4">
    <h1 class="text-2xl font-bold text-gray-100 m-0 flex flex-wrap items-center gap-2">
      <span class="quiz-admin-hero-icon quiz-admin-hero-icon--preweek" aria-hidden="true"><i class="bi bi-collection-play"></i></span>
      Materials <span class="text-gray-500 font-semibold">—</span> <span class="text-amber-200 font-semibold"><?php echo h($topicTitle); ?></span>
    </h1>
    <p class="text-gray-400 mt-3 mb-0 text-sm sm:text-base max-w-3xl">
      <span class="text-gray-500">Pre-week:</span> <?php echo h($unitTitle); ?>
      <span class="text-gray-600 mx-1">·</span>
      <span class="text-gray-400">Videos &amp; handouts for this lecture only.</span>
    </p>
    <div class="flex flex-wrap items-center gap-2 mt-4">
      <span class="preweek-stat-pill" title="Videos"><i class="bi bi-play-btn text-sky-400"></i> <?php echo (int)$preweekVideoCount; ?> video<?php echo $preweekVideoCount === 1 ? '' : 's'; ?></span>
      <span class="preweek-stat-pill" title="Handouts"><i class="bi bi-file-earmark-text text-amber-300"></i> <?php echo (int)$preweekHandoutCount; ?> handout<?php echo $preweekHandoutCount === 1 ? '' : 's'; ?></span>
    </div>
  </div>

  <?php if (!empty($materialsFlash['errors'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--error mb-4 flex flex-col gap-1" role="alert">
      <span class="font-semibold inline-flex items-center gap-2"><i class="bi bi-exclamation-triangle-fill shrink-0"></i> Could not save</span>
      <ul class="list-disc pl-5 text-sm m-0 space-y-0.5">
        <?php foreach ($materialsFlash['errors'] as $err): ?>
          <li><?php echo h((string)$err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <?php if (!empty($materialsFlash['successes'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--success mb-4 flex flex-col gap-1">
      <span class="font-semibold inline-flex items-center gap-2"><i class="bi bi-check-circle-fill shrink-0"></i> Saved</span>
      <ul class="list-disc pl-5 text-sm m-0 space-y-0.5">
        <?php foreach ($materialsFlash['successes'] as $ok): ?>
          <li><?php echo h((string)$ok); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php
    $matFiltersActive = ($searchQ !== '' || $matTypeExplicit);
  ?>
  <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
    <a href="<?php echo h($topicsListUrl); ?>" class="text-sm font-medium text-gray-400 hover:text-amber-200/90 inline-flex items-center gap-2 no-underline transition-colors">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to lectures
    </a>
    <?php if ($matFiltersActive): ?>
      <span class="text-xs font-medium uppercase tracking-wide text-amber-200/70 bg-amber-500/10 border border-amber-500/25 rounded-full px-2.5 py-1">Search active</span>
    <?php endif; ?>
  </div>

  <form method="get" action="admin_preweek_materials.php" class="quiz-admin-filter quiz-admin-table-shell rounded-xl px-4 py-3 mb-3 flex flex-wrap items-end gap-3">
    <input type="hidden" name="preweek_topic_id" value="<?php echo (int)$topicId; ?>">
    <div class="w-full sm:flex-1 sm:min-w-[200px] sm:max-w-lg">
      <label for="mat-search-q" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Search</label>
      <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 pointer-events-none"><i class="bi bi-search" aria-hidden="true"></i></span>
        <input type="search" id="mat-search-q" name="q" value="<?php echo h($searchQ); ?>" placeholder="Title or filename…" class="input-custom w-full pl-10" autocomplete="off">
      </div>
    </div>
    <div class="w-full sm:w-48">
      <label for="mat-search-type" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Show</label>
      <select id="mat-search-type" name="type" class="input-custom w-full">
        <option value=""<?php echo $matTypeExplicit && $matType === '' ? ' selected' : ''; ?>>Videos &amp; handouts</option>
        <option value="videos"<?php echo $matType === 'videos' ? ' selected' : ''; ?>>Videos only</option>
        <option value="handouts"<?php echo $matType === 'handouts' ? ' selected' : ''; ?>>Handouts only</option>
      </select>
    </div>
    <div class="flex flex-wrap gap-2 shrink-0">
      <button type="submit" class="quiz-admin-filter-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-search" aria-hidden="true"></i> Apply</button>
      <?php if ($matFiltersActive): ?>
        <a href="<?php echo h(admin_preweek_materials_list_url($topicId)); ?>" class="quiz-admin-filter-clear px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2">Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <p class="text-xs text-gray-500 mb-4 leading-relaxed" role="note">
    <strong class="text-gray-400 font-semibold">Limits:</strong> max <?php echo h($preweekUploadMax); ?> per file, <?php echo h($preweekPostMax); ?> per request.
    Handouts: PDF, Office, TXT. Videos: link or upload (MP4, etc.).
  </p>

  <div class="preweek-materials-stack">
    <?php if ($showVideos): ?>
    <div class="quiz-admin-table-shell rounded-xl overflow-hidden">
      <div class="preweek-section-toolbar px-5 py-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-lg font-bold text-gray-100 m-0 flex items-center gap-2"><i class="bi bi-play-circle text-sky-400"></i> Videos</h2>
          <p class="text-xs text-gray-500 m-0 mt-1 max-w-2xl">Videos for this lecture. Use <strong class="text-gray-400">Add video</strong> to paste a link or upload a file.</p>
        </div>
        <button type="button" id="preweekOpenVideoAdd" class="admin-materials-submit-btn shrink-0"><i class="bi bi-plus-lg"></i><span>Add video</span></button>
      </div>
      <div class="preweek-table-full-wrap px-2 sm:px-4 pb-4">
          <table class="materials-data-table w-full text-left preweek-table-full">
            <thead>
              <tr>
                <th class="px-4 py-3 font-semibold text-left">Title</th>
                <th class="px-4 py-3 font-semibold text-center w-[100px]">Type</th>
                <th class="px-4 py-3 font-semibold text-left min-w-[200px]">Link / file on server</th>
                <th class="px-4 py-3 font-semibold text-center w-[220px] min-w-[11rem]">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php mysqli_data_seek($videos, 0); while ($v = mysqli_fetch_assoc($videos)):
                $vidType = (($v['upload_type'] ?? '') === 'file') ? 'file' : 'url';
                $vurl = (string)($v['video_url'] ?? '');
                $videoEditPayload = [
                    'id' => (int)$v['preweek_video_id'],
                    'title' => (string)($v['video_title'] ?? ''),
                    'url' => $vurl,
                    'uploadType' => $vidType,
                ];
                ?>
                <tr class="materials-data-row">
                  <td class="px-4 py-3 align-top">
                    <div class="font-semibold text-gray-100"><?php echo h($v['video_title']); ?></div>
                  </td>
                  <td class="px-4 py-3 align-top text-center">
                    <?php if ($vidType === 'file'): ?>
                      <span class="preweek-badge-type preweek-badge-type--file"><i class="bi bi-hdd"></i> File</span>
                    <?php else: ?>
                      <span class="preweek-badge-type preweek-badge-type--link"><i class="bi bi-link-45deg"></i> Link</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3 align-top">
                    <div class="preweek-cell-mono" title="<?php echo h($vurl); ?>"><?php echo h($vurl); ?></div>
                    <?php if ($vidType === 'file' && $vurl !== ''): ?>
                      <div class="text-xs text-slate-500 mt-1">Stored as: <?php echo h(basename($vurl)); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3 align-middle text-center">
                    <div class="inline-block w-full max-w-[240px] mx-auto text-left" x-data="{ expanded: false }">
                      <div class="flex flex-nowrap items-stretch justify-center gap-2">
                        <a href="<?php echo h($vurl); ?>" target="_blank" rel="noopener" class="preweek-materials-link flex items-center justify-center gap-1.5 min-w-0 flex-1 px-2.5 py-2 rounded-lg text-sm font-semibold transition no-underline">
                          <i class="bi bi-box-arrow-up-right shrink-0" aria-hidden="true"></i><span class="truncate">Open</span>
                        </a>
                        <button type="button" @click="expanded = !expanded" class="quiz-admin-more-btn flex items-center justify-center gap-1 shrink-0 px-2.5 py-2 rounded-md text-xs border transition" :aria-expanded="expanded" title="More actions">
                          <i class="bi text-sm" :class="expanded ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                          <span class="opacity-80 hidden sm:inline">More</span>
                        </button>
                      </div>
                      <div x-show="expanded" x-cloak class="flex flex-col gap-2 mt-2 w-full">
                        <button type="button" class="preweek-btn-edit flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg" data-video="<?php echo h(json_encode($videoEditPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)); ?>" @click="expanded = false"><i class="bi bi-pencil"></i> Edit</button>
                        <a href="<?php echo h(admin_preweek_materials_list_url($topicId)); ?>&delete_video=<?php echo (int)$v['preweek_video_id']; ?>" onclick="return confirm('Remove this video from preweek? This cannot be undone.');" class="flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg text-sm font-medium border-2 border-red-500/50 text-red-400 hover:bg-red-950/30 transition no-underline" @click="expanded = false"><i class="bi bi-trash"></i> Delete</a>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
              <?php if (mysqli_num_rows($videos) == 0): ?>
                <tr><td colspan="4" class="px-3 py-2">
                  <div class="preweek-empty-state rounded-lg border border-white/10 bg-white/[0.02]">
                    <div class="preweek-empty-icon" aria-hidden="true"><i class="bi bi-film"></i></div>
                    <p class="font-medium text-gray-300 m-0"><?php echo $searchQ !== '' ? 'No videos match this search.' : 'No videos yet.'; ?></p>
                    <p class="text-sm mt-2 m-0"><?php echo $searchQ !== '' ? 'Try clearing filters or use different keywords.' : 'Click <strong class="text-gray-400">Add video</strong> to add a link or upload a file.'; ?></p>
                  </div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($showHandouts): ?>
    <div class="quiz-admin-table-shell rounded-xl overflow-hidden">
      <div class="preweek-section-toolbar px-5 py-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-lg font-bold text-gray-100 m-0 flex items-center gap-2"><i class="bi bi-file-earmark-pdf text-amber-300"></i> Handouts</h2>
          <p class="text-xs text-gray-500 m-0 mt-1 max-w-2xl">PDF / Office files for this lecture. Students use the secure viewer. Use <strong class="text-gray-400">Upload handout</strong> to add files.</p>
        </div>
        <button type="button" id="preweekOpenHandoutAdd" class="admin-materials-submit-btn shrink-0"><i class="bi bi-cloud-upload"></i><span>Upload handout</span></button>
      </div>
      <div class="preweek-table-full-wrap px-2 sm:px-4 pb-4">
          <table class="materials-data-table w-full text-left preweek-table-full">
            <thead>
              <tr>
                <th class="px-4 py-3 font-semibold text-left">Title</th>
                <th class="px-4 py-3 font-semibold text-left">Original file name</th>
                <th class="px-4 py-3 font-semibold text-left min-w-[140px]">Stored path</th>
                <th class="px-4 py-3 font-semibold text-center w-[90px]">Size</th>
                <th class="px-4 py-3 font-semibold text-center w-[100px]">Access</th>
                <th class="px-4 py-3 font-semibold text-center w-[240px] min-w-[11rem]">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php mysqli_data_seek($handouts, 0); while ($h = mysqli_fetch_assoc($handouts)):
                $fp = (string)($h['file_path'] ?? '');
                $handoutEditPayload = [
                    'id' => (int)$h['preweek_handout_id'],
                    'title' => (string)($h['handout_title'] ?? ''),
                    'allowDownload' => !empty($h['allow_download']),
                    'fileName' => (string)($h['file_name'] ?? ''),
                    'stored' => $fp !== '' ? basename($fp) : '',
                ];
                ?>
                <tr class="materials-data-row">
                  <td class="px-4 py-3 align-top">
                    <div class="font-semibold text-gray-100"><?php echo h($h['handout_title'] ?: 'Untitled'); ?></div>
                  </td>
                  <td class="px-4 py-3 align-top">
                    <div class="text-gray-200 text-sm"><?php echo !empty($h['file_name']) ? h($h['file_name']) : '—'; ?></div>
                  </td>
                  <td class="px-4 py-3 align-top">
                    <?php if ($fp !== ''): ?>
                      <div class="preweek-cell-mono" title="<?php echo h($fp); ?>"><?php echo h($fp); ?></div>
                    <?php else: ?>
                      <span class="text-gray-500">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3 align-top text-center text-sm text-gray-300"><?php echo h(ereview_format_bytes($h['file_size'] ?? 0)); ?></td>
                  <td class="px-4 py-3 align-top text-center">
                    <?php if (!empty($h['allow_download'])): ?><span class="px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/15 text-emerald-300 border border-emerald-500/35">Allowed</span>
                    <?php else: ?><span class="px-2 py-0.5 rounded-full text-xs font-medium bg-white/10 text-gray-400 border border-white/15">Locked</span><?php endif; ?>
                  </td>
                  <td class="px-4 py-3 align-middle text-center">
                    <div class="inline-block w-full max-w-[260px] mx-auto text-left" x-data="{ expanded: false }">
                      <div class="flex flex-nowrap items-stretch <?php echo $fp !== '' ? 'justify-center gap-2' : 'justify-center'; ?>">
                        <?php if ($fp !== ''): ?>
                        <a href="<?php echo h($fp); ?>" target="_blank" rel="noopener" class="preweek-materials-link flex items-center justify-center gap-1.5 min-w-0 flex-1 px-2.5 py-2 rounded-lg text-sm font-semibold transition no-underline">
                          <i class="bi bi-download shrink-0" aria-hidden="true"></i><span class="truncate">Download</span>
                        </a>
                        <?php endif; ?>
                        <button type="button" @click="expanded = !expanded" class="quiz-admin-more-btn flex items-center justify-center gap-1 shrink-0 px-2.5 py-2 rounded-md text-xs border transition" :aria-expanded="expanded" title="More actions">
                          <i class="bi text-sm" :class="expanded ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                          <span class="opacity-80 hidden sm:inline">More</span>
                        </button>
                      </div>
                      <div x-show="expanded" x-cloak class="flex flex-col gap-2 mt-2 w-full">
                        <button type="button" class="preweek-btn-edit flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg" data-handout="<?php echo h(json_encode($handoutEditPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)); ?>" @click="expanded = false"><i class="bi bi-pencil"></i> Edit</button>
                        <a href="<?php echo h(admin_preweek_materials_list_url($topicId)); ?>&toggle_handout=<?php echo (int)$h['preweek_handout_id']; ?>" class="quiz-admin-btn-secondary flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg text-sm font-semibold transition no-underline" @click="expanded = false"><i class="bi bi-<?php echo !empty($h['allow_download']) ? 'lock' : 'unlock'; ?>"></i> <?php echo !empty($h['allow_download']) ? 'Lock download' : 'Allow download'; ?></a>
                        <a href="<?php echo h(admin_preweek_materials_list_url($topicId)); ?>&delete_handout=<?php echo (int)$h['preweek_handout_id']; ?>" onclick="return confirm('Remove this handout? Files cannot be recovered after deletion.');" class="flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg text-sm font-medium border-2 border-red-500/50 text-red-400 hover:bg-red-950/30 transition no-underline" @click="expanded = false"><i class="bi bi-trash"></i> Delete</a>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endwhile; ?>
              <?php if (mysqli_num_rows($handouts) == 0): ?>
                <tr><td colspan="6" class="px-3 py-2">
                  <div class="preweek-empty-state rounded-lg border border-white/10 bg-white/[0.02]">
                    <div class="preweek-empty-icon" aria-hidden="true"><i class="bi bi-file-earmark-plus"></i></div>
                    <p class="font-medium text-gray-300 m-0"><?php echo $searchQ !== '' ? 'No handouts match this search.' : 'No handouts yet.'; ?></p>
                    <p class="text-sm mt-2 m-0"><?php echo $searchQ !== '' ? 'Try different keywords or clear the search.' : 'Click <strong class="text-gray-400">Upload handout</strong> to add a PDF or Office document.'; ?></p>
                  </div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php $preweekMatAction = h(admin_preweek_materials_list_url($topicId)); ?>

  <div id="preweekVideoAddModal" class="preweek-modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="preweekVideoAddTitle">
    <div class="preweek-modal-panel preweek-modal-panel--add">
      <div class="preweek-modal-head">
        <h2 id="preweekVideoAddTitle" class="text-lg font-bold text-white m-0">Add video</h2>
        <button type="button" class="text-gray-400 hover:text-white p-1 rounded-lg preweek-modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="preweek-modal-body">
        <form id="preweekVideoForm" method="post" enctype="multipart/form-data" action="<?php echo $preweekMatAction; ?>" novalidate>
          <input type="hidden" name="type" value="video">
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-200 mb-1" for="videoTitleInput">Display title <span class="text-red-400">*</span></label>
              <input type="text" name="video_title" id="videoTitleInput" class="input-custom w-full" maxlength="255" placeholder="e.g. Preweek intro — overview" autocomplete="off">
              <p class="preweek-field-hint">Shown to students in the preweek list.</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-200 mb-1" for="preweekUploadType">Source</label>
              <select name="upload_type" id="preweekUploadType" class="input-custom w-full">
                <option value="url">Link (YouTube, Vimeo, or direct URL)</option>
                <option value="file">Upload file from computer</option>
              </select>
            </div>
            <div id="preweekAddUrlWrap">
              <label class="block text-sm font-medium text-gray-200 mb-1" for="preweekVideoUrl">Video URL <span class="text-red-400">*</span></label>
              <input type="url" name="video_url" id="preweekVideoUrl" class="input-custom w-full" placeholder="https://www.youtube.com/watch?v=…" inputmode="url" autocomplete="url">
            </div>
            <div id="preweekAddFileWrap" class="hidden">
              <label class="block text-sm font-medium text-gray-200 mb-1" for="videoFileInput">Video file <span class="text-red-400">*</span></label>
              <input type="file" name="video_file" id="videoFileInput" class="input-custom admin-upload-input w-full" accept="video/*">
              <p class="preweek-field-hint">MP4, WebM, MOV, and other common formats.</p>
            </div>
            <div class="flex flex-wrap gap-2 pt-2">
              <button type="submit" class="admin-materials-submit-btn preweek-submit-btn" data-default-label="Add video"><i class="bi bi-plus-circle"></i><span class="preweek-btn-label">Add video</span></button>
              <button type="button" class="px-4 py-2.5 rounded-lg font-semibold border border-white/20 text-gray-300 hover:bg-white/10 preweek-modal-close">Cancel</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="preweekHandoutAddModal" class="preweek-modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="preweekHandoutAddTitle">
    <div class="preweek-modal-panel preweek-modal-panel--add">
      <div class="preweek-modal-head">
        <h2 id="preweekHandoutAddTitle" class="text-lg font-bold text-white m-0">Upload handouts</h2>
        <button type="button" class="text-gray-400 hover:text-white p-1 rounded-lg preweek-modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="preweek-modal-body">
        <form id="preweekHandoutForm" method="post" enctype="multipart/form-data" action="<?php echo $preweekMatAction; ?>" novalidate>
          <input type="hidden" name="type" value="handout">
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-200 mb-1" for="handoutTitleInput">Title</label>
              <input type="text" name="handout_title" id="handoutTitleInput" class="input-custom w-full" maxlength="255" placeholder="Optional — for a single file, defaults to file name" autocomplete="off">
            </div>
            <div class="flex items-start gap-3 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2.5">
              <input type="checkbox" id="handoutFolderMode" class="mt-1 rounded border-white/20 text-amber-500 focus:ring-amber-500 bg-[#1a1a1a]">
              <div>
                <label for="handoutFolderMode" class="text-sm font-medium text-gray-200 cursor-pointer">Upload from folder</label>
                <p class="preweek-field-hint m-0">Select a folder to upload all supported documents inside it (Chrome / Edge / Safari). You can also pick multiple files without this.</p>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-200 mb-1" for="handoutFileInput">Files <span class="text-red-400">*</span></label>
              <input type="file" name="handout_file[]" id="handoutFileInput" class="input-custom admin-upload-input w-full" accept=".pdf,.doc,.docx,.txt,.ppt,.pptx,.xls,.xlsx" multiple>
              <p class="preweek-field-hint">PDF, Word, PowerPoint, Excel, TXT — multiple files or one folder. Each file becomes one handout. Max files per request is limited by your PHP <code class="text-gray-400">max_file_uploads</code> setting.</p>
            </div>
            <div class="flex items-start gap-3 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2.5">
              <input type="checkbox" id="allowDownloadHandout" name="allow_download" value="1" checked class="mt-0.5 rounded border-white/20 text-emerald-500 focus:ring-emerald-500 bg-[#1a1a1a]">
              <div>
                <label for="allowDownloadHandout" class="text-sm font-medium text-gray-200 cursor-pointer">Allow students to download</label>
                <p class="preweek-field-hint m-0">If off, view-only in the browser.</p>
              </div>
            </div>
            <div class="flex flex-wrap gap-2 pt-2">
              <button type="submit" class="admin-materials-submit-btn preweek-submit-btn" data-default-label="Upload handouts"><i class="bi bi-cloud-upload"></i><span class="preweek-btn-label">Upload handouts</span></button>
              <button type="button" class="px-4 py-2.5 rounded-lg font-semibold border border-white/20 text-gray-300 hover:bg-white/10 preweek-modal-close">Cancel</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="preweekVideoEditModal" class="preweek-modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="preweekVideoEditTitle">
    <div class="preweek-modal-panel">
      <div class="preweek-modal-head">
        <h2 id="preweekVideoEditTitle" class="text-lg font-bold text-white m-0">Edit video</h2>
        <button type="button" class="text-gray-400 hover:text-white p-1 rounded-lg preweek-modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="preweek-modal-body">
        <form id="preweekVideoEditForm" method="post" enctype="multipart/form-data" action="<?php echo $preweekMatAction; ?>">
          <input type="hidden" name="type" value="video">
          <input type="hidden" name="video_id" id="preweekVideoEditId" value="">
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-200 mb-1" for="preweekVideoEditTitleInput">Display title <span class="text-red-400">*</span></label>
              <input type="text" name="video_title" id="preweekVideoEditTitleInput" class="input-custom w-full" maxlength="255" required>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-200 mb-1" for="preweekVideoEditUploadType">Source</label>
              <select name="upload_type" id="preweekVideoEditUploadType" class="input-custom w-full">
                <option value="url">Link (YouTube, Vimeo, or URL)</option>
                <option value="file">Upload file from computer</option>
              </select>
              <p class="preweek-field-hint">To replace an uploaded file, choose “Upload file” and pick a new video. To change only the title or link, adjust fields and save.</p>
            </div>
            <div id="preweekVideoEditUrlBlock">
              <label class="block text-sm font-medium text-gray-200 mb-1" for="preweekVideoEditUrl">Video URL <span class="text-red-400">*</span></label>
              <input type="url" name="video_url" id="preweekVideoEditUrl" class="input-custom w-full" placeholder="https://...">
            </div>
            <div id="preweekVideoEditFileBlock" class="hidden">
              <label class="block text-sm font-medium text-gray-200 mb-1" for="preweekVideoEditFile">New video file</label>
              <input type="file" name="video_file" id="preweekVideoEditFile" class="input-custom admin-upload-input w-full" accept="video/*">
              <p class="preweek-field-hint">Leave empty to keep the current file. Selecting a file replaces it.</p>
            </div>
            <div class="flex flex-wrap gap-2 pt-2">
              <button type="submit" class="admin-materials-submit-btn"><i class="bi bi-check-lg"></i><span>Save changes</span></button>
              <button type="button" class="px-4 py-2.5 rounded-lg font-semibold border border-white/20 text-gray-300 hover:bg-white/10 preweek-modal-close">Cancel</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="preweekHandoutEditModal" class="preweek-modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="preweekHandoutEditTitle">
    <div class="preweek-modal-panel">
      <div class="preweek-modal-head">
        <h2 id="preweekHandoutEditTitle" class="text-lg font-bold text-white m-0">Edit handout</h2>
        <button type="button" class="text-gray-400 hover:text-white p-1 rounded-lg preweek-modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="preweek-modal-body">
        <form id="preweekHandoutEditForm" method="post" enctype="multipart/form-data" action="<?php echo $preweekMatAction; ?>">
          <input type="hidden" name="type" value="handout">
          <input type="hidden" name="handout_id" id="preweekHandoutEditId" value="">
          <p id="preweekHandoutEditCurrent" class="text-sm text-gray-400 mb-3 p-3 rounded-lg bg-white/5 border border-white/10"></p>
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-gray-200 mb-1" for="preweekHandoutEditTitleInput">Title</label>
              <input type="text" name="handout_title" id="preweekHandoutEditTitleInput" class="input-custom w-full" maxlength="255">
            </div>
            <div class="flex items-start gap-3 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2.5">
              <input type="checkbox" id="preweekHandoutEditAllow" name="allow_download" value="1" class="mt-0.5 rounded border-white/20 text-emerald-500 focus:ring-emerald-500 bg-[#1a1a1a]">
              <label for="preweekHandoutEditAllow" class="text-sm font-medium text-gray-200 cursor-pointer">Allow students to download</label>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-200 mb-1" for="preweekHandoutEditFile">Replace file (optional)</label>
              <input type="file" name="handout_file" id="preweekHandoutEditFile" class="input-custom admin-upload-input w-full" accept=".pdf,.doc,.docx,.txt,.ppt,.pptx,.xls,.xlsx">
              <p class="preweek-field-hint">Leave empty to keep the current document. New file replaces the old one.</p>
            </div>
            <div class="flex flex-wrap gap-2 pt-2">
              <button type="submit" class="admin-materials-submit-btn"><i class="bi bi-check-lg"></i><span>Save changes</span></button>
              <button type="button" class="px-4 py-2.5 rounded-lg font-semibold border border-white/20 text-gray-300 hover:bg-white/10 preweek-modal-close">Cancel</button>
            </div>
          </div>
        </form>
      </div>
    </div>
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

    bindAutoTitle('videoFileInput', 'videoTitleInput');

    var handoutFile = document.getElementById('handoutFileInput');
    var handoutFolderMode = document.getElementById('handoutFolderMode');
    if (handoutFolderMode && handoutFile) {
      handoutFolderMode.addEventListener('change', function () {
        handoutFile.webkitdirectory = !!this.checked;
        handoutFile.multiple = true;
        handoutFile.value = '';
        handoutFile.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
    if (handoutFile) {
      handoutFile.addEventListener('change', function () {
        var list = handoutFile.files;
        var hint = document.getElementById('preweekHandoutFileHint');
        if (!hint) {
          hint = document.createElement('p');
          hint.id = 'preweekHandoutFileHint';
          hint.className = 'preweek-field-hint text-emerald-300/90';
          handoutFile.parentNode.appendChild(hint);
        }
        if (!list || !list.length) {
          hint.textContent = '';
          return;
        }
        var total = 0;
        for (var i = 0; i < list.length; i++) total += list[i].size || 0;
        var sz = total > 1024 * 1024 ? (total / (1024 * 1024)).toFixed(1) + ' MB' : Math.ceil(total / 1024) + ' KB';
        hint.textContent = list.length === 1
          ? ('Selected: ' + list[0].name + ' (' + sz + ')')
          : ('Selected: ' + list.length + ' files (' + sz + ' total)');
        var titleIn = document.getElementById('handoutTitleInput');
        if (titleIn && list.length === 1 && titleIn.value.trim() === '') {
          titleIn.value = toTitleFromFilename(list[0].name.replace(/^.*[\\/]/, ''));
        }
      });
    }

    function setLoading(btn, loading) {
      if (!btn) return;
      var label = btn.querySelector('.preweek-btn-label');
      var def = btn.getAttribute('data-default-label') || (label ? label.textContent : '');
      btn.disabled = !!loading;
      if (label) {
        label.textContent = loading ? 'Please wait…' : def;
      }
      var icon = btn.querySelector('i');
      if (icon && loading) {
        btn.dataset.prevIconClass = icon.className;
        icon.className = 'bi bi-hourglass-split';
      } else if (icon && btn.dataset.prevIconClass) {
        icon.className = btn.dataset.prevIconClass;
        delete btn.dataset.prevIconClass;
      }
    }

    var videoForm = document.getElementById('preweekVideoForm');
    if (videoForm) {
      videoForm.addEventListener('submit', function (ev) {
        var title = (document.getElementById('videoTitleInput') || {}).value || '';
        if (!title.trim()) {
          ev.preventDefault();
          alert('Please enter a display title for this video.');
          return;
        }
        var typeSel = document.getElementById('preweekUploadType');
        var mode = typeSel ? typeSel.value : 'url';
        if (mode === 'url') {
          var u = ((document.getElementById('preweekVideoUrl') || {}).value || '').trim();
          if (!u) {
            ev.preventDefault();
            alert('Please paste the video URL.');
            return;
          }
          try {
            new URL(u);
          } catch (e) {
            ev.preventDefault();
            alert('Please enter a valid URL starting with http:// or https://');
            return;
          }
        } else {
          var vf = document.getElementById('videoFileInput');
          if (!vf || !vf.files || !vf.files.length) {
            ev.preventDefault();
            alert('Please choose a video file to upload.');
            return;
          }
        }
        setLoading(videoForm.querySelector('.preweek-submit-btn'), true);
      });
    }

    var handoutForm = document.getElementById('preweekHandoutForm');
    if (handoutForm) {
      handoutForm.addEventListener('submit', function (ev) {
        var hf = document.getElementById('handoutFileInput');
        if (!hf || !hf.files || !hf.files.length) {
          ev.preventDefault();
          alert('Please choose one or more files (or a folder) to upload.');
          return;
        }
        var allowed = ['pdf', 'doc', 'docx', 'txt', 'ppt', 'pptx', 'xls', 'xlsx'];
        for (var i = 0; i < hf.files.length; i++) {
          var raw = hf.files[i].name || '';
          var base = raw.replace(/^.*[\\/]/, '');
          if (base === '.DS_Store' || base === 'Thumbs.db' || base.indexOf('._') === 0) continue;
          var ext = base.indexOf('.') >= 0 ? base.split('.').pop().toLowerCase() : '';
          if (allowed.indexOf(ext) < 0) {
            ev.preventDefault();
            alert('Unsupported type: ' + base + '\n\nAllowed: PDF, Word, PowerPoint, Excel, TXT.');
            return;
          }
        }
        setLoading(handoutForm.querySelector('.preweek-submit-btn'), true);
      });
    }

    function syncVideoEditMode() {
      var modeEl = document.getElementById('preweekVideoEditUploadType');
      var mode = modeEl ? modeEl.value : 'url';
      var urlBlock = document.getElementById('preweekVideoEditUrlBlock');
      var fileBlock = document.getElementById('preweekVideoEditFileBlock');
      var urlIn = document.getElementById('preweekVideoEditUrl');
      var fileIn = document.getElementById('preweekVideoEditFile');
      if (!urlIn || !fileIn || !urlBlock || !fileBlock) return;
      if (mode === 'url') {
        urlBlock.classList.remove('hidden');
        fileBlock.classList.add('hidden');
        urlIn.disabled = false;
        urlIn.setAttribute('name', 'video_url');
        fileIn.removeAttribute('name');
        fileIn.disabled = true;
        fileIn.value = '';
      } else {
        urlBlock.classList.add('hidden');
        fileBlock.classList.remove('hidden');
        urlIn.disabled = true;
        urlIn.removeAttribute('name');
        fileIn.disabled = false;
        fileIn.setAttribute('name', 'video_file');
      }
    }

    var vEditType = document.getElementById('preweekVideoEditUploadType');
    if (vEditType) {
      vEditType.addEventListener('change', syncVideoEditMode);
    }

    var vm = document.getElementById('preweekVideoEditModal');
    var hm = document.getElementById('preweekHandoutEditModal');
    var vam = document.getElementById('preweekVideoAddModal');
    var ham = document.getElementById('preweekHandoutAddModal');
    function closePreweekModals() {
      if (vm) vm.hidden = true;
      if (hm) hm.hidden = true;
      if (vam) vam.hidden = true;
      if (ham) ham.hidden = true;
    }

    function syncVideoAddMode() {
      var sel = document.getElementById('preweekUploadType');
      var mode = sel ? sel.value : 'url';
      var urlW = document.getElementById('preweekAddUrlWrap');
      var fileW = document.getElementById('preweekAddFileWrap');
      var urlIn = document.getElementById('preweekVideoUrl');
      var fileIn = document.getElementById('videoFileInput');
      if (!urlW || !fileW || !urlIn || !fileIn) return;
      if (mode === 'url') {
        urlW.classList.remove('hidden');
        fileW.classList.add('hidden');
        urlIn.disabled = false;
        urlIn.setAttribute('name', 'video_url');
        fileIn.removeAttribute('name');
        fileIn.disabled = true;
        fileIn.value = '';
      } else {
        urlW.classList.add('hidden');
        fileW.classList.remove('hidden');
        urlIn.disabled = true;
        urlIn.removeAttribute('name');
        fileIn.disabled = false;
        fileIn.setAttribute('name', 'video_file');
      }
    }

    var addTypeSel = document.getElementById('preweekUploadType');
    if (addTypeSel) {
      addTypeSel.addEventListener('change', syncVideoAddMode);
    }

    if (document.getElementById('preweekOpenVideoAdd') && vam) {
      document.getElementById('preweekOpenVideoAdd').addEventListener('click', function () {
        var f = document.getElementById('preweekVideoForm');
        if (f) f.reset();
        syncVideoAddMode();
        vam.hidden = false;
      });
    }
    if (document.getElementById('preweekOpenHandoutAdd') && ham) {
      document.getElementById('preweekOpenHandoutAdd').addEventListener('click', function () {
        var f = document.getElementById('preweekHandoutForm');
        if (f) f.reset();
        var ad = document.getElementById('allowDownloadHandout');
        if (ad) ad.checked = true;
        ham.hidden = false;
      });
    }

    document.addEventListener('click', function (e) {
      var vidBtn = e.target.closest('[data-video]');
      if (vidBtn) {
        try {
          var d = JSON.parse(vidBtn.getAttribute('data-video'));
          document.getElementById('preweekVideoEditId').value = d.id;
          document.getElementById('preweekVideoEditTitleInput').value = d.title || '';
          document.getElementById('preweekVideoEditUploadType').value = d.uploadType === 'file' ? 'file' : 'url';
          document.getElementById('preweekVideoEditUrl').value = (d.uploadType === 'url') ? (d.url || '') : '';
          document.getElementById('preweekVideoEditFile').value = '';
          syncVideoEditMode();
          vm.hidden = false;
        } catch (err) {
          console.error(err);
        }
        return;
      }
      var hoBtn = e.target.closest('[data-handout]');
      if (hoBtn) {
        try {
          var dh = JSON.parse(hoBtn.getAttribute('data-handout'));
          document.getElementById('preweekHandoutEditId').value = dh.id;
          document.getElementById('preweekHandoutEditTitleInput').value = dh.title || '';
          document.getElementById('preweekHandoutEditAllow').checked = !!dh.allowDownload;
          document.getElementById('preweekHandoutEditFile').value = '';
          var cur = document.getElementById('preweekHandoutEditCurrent');
          var parts = [];
          if (dh.fileName) parts.push('<strong class="text-gray-300">Original name:</strong> ' + escapeHtml(dh.fileName));
          if (dh.stored) parts.push('<strong class="text-gray-300">Stored as:</strong> <code class="text-xs text-slate-400">' + escapeHtml(dh.stored) + '</code>');
          cur.innerHTML = parts.length ? parts.join('<br>') : 'No file on record.';
          hm.hidden = false;
        } catch (err2) {
          console.error(err2);
        }
        return;
      }
      if (e.target.classList.contains('preweek-modal-overlay')) {
        e.target.hidden = true;
      }
      if (e.target.closest('.preweek-modal-close')) {
        closePreweekModals();
      }
    });

    function escapeHtml(s) {
      var d = document.createElement('div');
      d.textContent = s;
      return d.innerHTML;
    }

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      closePreweekModals();
    });

    if (vm) {
      vm.addEventListener('click', function (e) {
        if (e.target === vm) vm.hidden = true;
      });
    }
    if (hm) {
      hm.addEventListener('click', function (e) {
        if (e.target === hm) hm.hidden = true;
      });
    }
    if (vam) {
      vam.addEventListener('click', function (e) {
        if (e.target === vam) vam.hidden = true;
      });
    }
    if (ham) {
      ham.addEventListener('click', function (e) {
        if (e.target === ham) ham.hidden = true;
      });
    }

    var vef = document.getElementById('preweekVideoEditForm');
    if (vef) {
      vef.addEventListener('submit', function (ev) {
        var title = (document.getElementById('preweekVideoEditTitleInput') || {}).value || '';
        if (!title.trim()) {
          ev.preventDefault();
          alert('Please enter a display title.');
          return;
        }
        var mode = (document.getElementById('preweekVideoEditUploadType') || {}).value;
        if (mode === 'url') {
          var u = ((document.getElementById('preweekVideoEditUrl') || {}).value || '').trim();
          if (!u) {
            ev.preventDefault();
            alert('Please paste the video URL.');
            return;
          }
          try {
            new URL(u);
          } catch (e1) {
            ev.preventDefault();
            alert('Please enter a valid URL starting with http:// or https://');
            return;
          }
        }
      });
    }

    var hef = document.getElementById('preweekHandoutEditForm');
    if (hef) {
      hef.addEventListener('submit', function (ev) {
        var f = document.getElementById('preweekHandoutEditFile');
        if (f && f.files && f.files.length) {
          var allowed = ['pdf', 'doc', 'docx', 'txt', 'ppt', 'pptx', 'xls', 'xlsx'];
          var name = f.files[0].name;
          var ext = name.indexOf('.') >= 0 ? name.split('.').pop().toLowerCase() : '';
          if (allowed.indexOf(ext) < 0) {
            ev.preventDefault();
            alert('This file type is not allowed. Use PDF, Word, PowerPoint, Excel, or TXT.');
          }
        }
      });
    }
  })();
</script>
</body>
</html>
