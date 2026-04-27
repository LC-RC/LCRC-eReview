<?php
require_once __DIR__ . '/auth.php';
requireLogin();
$staffRole = getCurrentUserRole();
if (!isStaffRole($staffRole)) {
    header('Location: student_dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/profile_avatar.php';
require_once __DIR__ . '/includes/profile_account_helpers.php';

$pageTitle = 'My profile';
$csrf = generateCSRFToken();
$uid = (int)getCurrentUserId();

$row = ereview_profile_fetch_row($conn, $uid);
if (!$row) {
    $_SESSION['error'] = 'Your profile could not be loaded.';
    header('Location: ' . dashboardUrlForRole($staffRole));
    exit;
}
$cols = ereview_profile_users_columns($conn);
$roleLabel = ereview_profile_role_display($row['role'] ?? $staffRole);
$activity = ereview_profile_activity_rows($conn, $uid, 8);

$picRaw = trim((string)($row['profile_picture'] ?? ''));
$useDef = $cols['use_default_avatar'] ? !empty($row['use_default_avatar']) : true;
$avatarSrc = ($picRaw !== '' && !$useDef && function_exists('ereview_avatar_img_src')) ? ereview_avatar_img_src($picRaw) : '';
$avatarInitial = ereview_avatar_initial((string)($row['full_name'] ?? 'U'));

$lastLogin = ($cols['last_login_at'] && !empty($row['last_login_at']))
    ? date('M j, Y g:i A', strtotime((string)$row['last_login_at']))
    : null;
$lastIp = ($cols['last_login_ip'] && !empty($row['last_login_ip'])) ? (string)$row['last_login_ip'] : null;

$createdAt = !empty($row['created_at']) ? date('M j, Y', strtotime((string)$row['created_at'])) : '—';
$updatedAt = !empty($row['updated_at']) ? date('M j, Y g:i A', strtotime((string)$row['updated_at'])) : '—';

$signInEmail = trim((string)($row['email'] ?? ''));
$phoneDisp = !empty($cols['phone']) ? trim((string)($row['phone'] ?? '')) : '';
$bioRaw = !empty($cols['profile_bio']) ? trim((string)($row['profile_bio'] ?? '')) : '';

$enrollDaysLeft = null;
$ereviewProfileShowEnrollment = false;
$ereviewProfileTheme = 'staff';
$ereviewProfileVariant = ($staffRole === 'professor_admin') ? 'professor' : '';
$ereviewProfileEditBtnId = 'staffProfileEditBtn';
$ereviewProfilePwBtnId = 'staffProfilePwBtn';

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$profileCss = __DIR__ . '/assets/css/profile-page.css';
$profileJs = __DIR__ . '/assets/js/profile-page.js';
$profileCssV = file_exists($profileCss) ? filemtime($profileCss) : 0;
$profileJsV = file_exists($profileJs) ? filemtime($profileJs) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/profile-page.css?v=<?php echo (int)$profileCssV; ?>">
</head>
<body class="font-sans antialiased admin-app staff-profile-page">
  <?php
  if ($staffRole === 'professor_admin') {
      include __DIR__ . '/professor_admin_sidebar.php';
  } else {
      include __DIR__ . '/admin_sidebar.php';
  }
  ?>

  <?php require __DIR__ . '/includes/components/profile_page_content.php'; ?>

  <script defer src="<?php echo h($base); ?>/assets/js/profile-page.js?v=<?php echo (int)$profileJsV; ?>"></script>
</div>
</main>
</body>
</html>
