<?php
require_once __DIR__ . '/auth.php';
requireRole('student');

$uid = (int)$_SESSION['user_id'];
$ur = mysqli_query($conn, 'SELECT access_end FROM users WHERE user_id=' . $uid . ' LIMIT 1');
$u = $ur ? mysqli_fetch_assoc($ur) : null;
if ($u && !empty($u['access_end']) && strtotime($u['access_end']) < time()) {
    $_SESSION['error'] = 'Your access has expired.';
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/profile_avatar.php';
require_once __DIR__ . '/includes/profile_account_helpers.php';

$pageTitle = 'My profile';
$csrf = generateCSRFToken();
$coverUploadCols = ereview_profile_users_columns($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_cover_upload'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request token for cover upload.';
    } elseif (!isset($_FILES['profile_cover']) || (int)($_FILES['profile_cover']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = 'Please choose a cover image.';
    } else {
        $file = $_FILES['profile_cover'];
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            $_SESSION['error'] = 'Cover image must be 5 MB or smaller.';
        } else {
            $tmp = (string)($file['tmp_name'] ?? '');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $tmp !== '' ? (string)$finfo->file($tmp) : '';
            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            if (!isset($extMap[$mime])) {
                $_SESSION['error'] = 'Use JPG, PNG, WebP, or GIF for cover photo.';
            } else {
                $coverDir = __DIR__ . '/uploads/profile/covers';
                if (!is_dir($coverDir) && !@mkdir($coverDir, 0755, true)) {
                    $_SESSION['error'] = 'Could not create cover upload directory.';
                } else {
                    foreach (glob($coverDir . '/cover_' . $uid . '.*') ?: [] as $oldCover) {
                        if (is_file($oldCover)) {
                            @unlink($oldCover);
                        }
                    }
                    $ext = $extMap[$mime];
                    $destFile = $coverDir . '/cover_' . $uid . '.' . $ext;
                    if (!@move_uploaded_file($tmp, $destFile)) {
                        $_SESSION['error'] = 'Could not upload cover image.';
                    } else {
                        $coverRel = 'uploads/profile/covers/cover_' . $uid . '.' . $ext;
                        if (!empty($coverUploadCols['profile_cover'])) {
                            $up = mysqli_prepare($conn, 'UPDATE users SET profile_cover = ? WHERE user_id = ? LIMIT 1');
                            if ($up) {
                                mysqli_stmt_bind_param($up, 'si', $coverRel, $uid);
                                mysqli_stmt_execute($up);
                                mysqli_stmt_close($up);
                            }
                        }
                        $_SESSION['success'] = 'Cover photo updated.';
                    }
                }
            }
        }
    }
    header('Location: student_profile.php');
    exit;
}

$row = ereview_profile_fetch_row($conn, $uid);
if (!$row) {
    $_SESSION['error'] = 'Your profile could not be loaded.';
    header('Location: student_dashboard.php');
    exit;
}
$cols = ereview_profile_users_columns($conn);

// Defensive fallback: keep key student identity fields populated even if the shared
// profile payload is partial or stale.
// Pull these fields directly from users to avoid "Not set" on profile when optional
// column detection becomes stale/misreported in shared payloads.
$identitySelect = ['full_name', 'review_type', 'school', 'school_other', 'payment_proof'];
$idSql = 'SELECT ' . implode(', ', $identitySelect) . ' FROM users WHERE user_id = ? LIMIT 1';
$idStmt = mysqli_prepare($conn, $idSql);
if ($idStmt) {
    mysqli_stmt_bind_param($idStmt, 'i', $uid);
    if (mysqli_stmt_execute($idStmt)) {
        $idRes = mysqli_stmt_get_result($idStmt);
        $idRow = $idRes ? mysqli_fetch_assoc($idRes) : null;
        if (is_array($idRow)) {
            foreach ($idRow as $idKey => $idVal) {
                if (!array_key_exists($idKey, $row) || trim((string)($row[$idKey] ?? '')) === '') {
                    $row[$idKey] = $idVal;
                }
            }
        }
    }
    mysqli_stmt_close($idStmt);
}
$roleLabel = ereview_profile_role_display($row['role'] ?? 'student');
$activity = ereview_profile_activity_rows($conn, $uid, 8);

$picRaw = trim((string)($row['profile_picture'] ?? ''));
$useDef = $cols['use_default_avatar'] ? !empty($row['use_default_avatar']) : true;
$avatarSrc = ($picRaw !== '' && !$useDef && function_exists('ereview_avatar_img_src')) ? ereview_avatar_img_src($picRaw) : '';
$avatarInitial = ereview_avatar_initial((string)($row['full_name'] ?? 'U'));

$coverRaw = '';
if (!empty($coverUploadCols['profile_cover'])) {
    $coverRaw = trim((string)($row['profile_cover'] ?? ''));
}
if ($coverRaw === '') {
    $coverDir = __DIR__ . '/uploads/profile/covers';
    $matches = glob($coverDir . '/cover_' . $uid . '.*') ?: [];
    if (!empty($matches)) {
        usort($matches, static function ($a, $b) {
            return @filemtime($b) <=> @filemtime($a);
        });
        $best = str_replace('\\', '/', (string)$matches[0]);
        $prefix = str_replace('\\', '/', __DIR__) . '/';
        if (strpos($best, $prefix) === 0) {
            $coverRaw = ltrim(substr($best, strlen($prefix)), '/');
        }
    }
}
$coverSrc = $coverRaw !== '' ? ereview_avatar_img_src($coverRaw) : '';

$lastLogin = ($cols['last_login_at'] && !empty($row['last_login_at']))
    ? date('M j, Y g:i A', strtotime((string)$row['last_login_at']))
    : null;
$lastIp = ($cols['last_login_ip'] && !empty($row['last_login_ip'])) ? (string)$row['last_login_ip'] : null;

$createdAt = !empty($row['created_at']) ? date('M j, Y', strtotime((string)$row['created_at'])) : '—';
$updatedAt = !empty($row['updated_at']) ? date('M j, Y g:i A', strtotime((string)$row['updated_at'])) : '—';

$enrollStart = ($cols['access_start'] && !empty($row['access_start']))
    ? date('M j, Y g:i A', strtotime((string)$row['access_start'])) : null;
$enrollEnd = ($cols['access_end'] && !empty($row['access_end']))
    ? date('M j, Y g:i A', strtotime((string)$row['access_end'])) : null;

$signInEmail = trim((string)($row['email'] ?? ''));
$phoneDisp = !empty($cols['phone']) ? trim((string)($row['phone'] ?? '')) : '';
$bioRaw = !empty($cols['profile_bio']) ? trim((string)($row['profile_bio'] ?? '')) : '';

$enrollDaysLeft = null;
if (!empty($cols['access_end']) && !empty($row['access_end'])) {
    $ets = strtotime((string)$row['access_end']);
    if ($ets !== false) {
        $secLeft = $ets - time();
        $enrollDaysLeft = ($secLeft <= 0) ? -1 : (int)ceil($secLeft / 86400);
    }
}
$ereviewProfileShowEnrollment = (bool)($enrollStart || $enrollEnd);
$ereviewProfileTheme = 'student';
$ereviewProfileVariant = 'student-centered';
$ereviewProfileEditBtnId = 'ereviewProfilePageEditBtn';
$ereviewProfilePwBtnId = 'ereviewProfilePageEditPwBtn';

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$ereviewProfileDebugUrl = $base . '/api/profile/debug_student_profile.php';
$profileCss = __DIR__ . '/assets/css/profile-page.css';
$profileJs = __DIR__ . '/assets/js/profile-page.js';
$profileCssV = file_exists($profileCss) ? filemtime($profileCss) : 0;
$profileJsV = file_exists($profileJs) ? filemtime($profileJs) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/profile-page.css?v=<?php echo (int)$profileCssV; ?>">
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/student_sidebar.php'; ?>

  <div class="student-dashboard-page student-dashboard-page--profile min-h-full pb-10">
    <?php require __DIR__ . '/includes/components/profile_page_content.php'; ?>
  </div>
</main>

  <script defer src="<?php echo h($base); ?>/assets/js/profile-page.js?v=<?php echo (int)$profileJsV; ?>"></script>
</body>
</html>
