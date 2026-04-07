<?php
/**
 * Student shell entry — unified sidebar + topbar + main open (see includes/components/app_shell_sidebar.php).
 */
$currentPage = basename($_SERVER['PHP_SELF']);
require_once __DIR__ . '/includes/format_display_name.php';
require_once __DIR__ . '/includes/profile_avatar.php';
$fullName = trim($_SESSION['full_name'] ?? 'User');
$studentShortName = ereview_format_topbar_display_name($fullName);
$profilePicture = '';
$useDefaultAvatar = 1;
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($uid > 0) {
    $hasProfilePicture = false;
    $hasDefaultAvatar = false;
    $cp1 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if ($cp1 && mysqli_fetch_assoc($cp1)) $hasProfilePicture = true;
    $cp2 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'use_default_avatar'");
    if ($cp2 && mysqli_fetch_assoc($cp2)) $hasDefaultAvatar = true;
    if ($hasProfilePicture || $hasDefaultAvatar) {
        $fields = [];
        if ($hasProfilePicture) $fields[] = 'profile_picture';
        if ($hasDefaultAvatar) $fields[] = 'use_default_avatar';
        $sql = 'SELECT ' . implode(', ', $fields) . ' FROM users WHERE user_id = ? LIMIT 1';
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $uid);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
            if ($row) {
                $profilePicture = trim((string)($row['profile_picture'] ?? ''));
                if ($hasDefaultAvatar) {
                    $useDefaultAvatar = !empty($row['use_default_avatar']) ? 1 : 0;
                }
            }
        }
    }
}
$avatarPath = ereview_avatar_public_path($profilePicture);
$avatarInitial = ereview_avatar_initial($fullName);

$appShellTheme = 'student';
$appShellCurrentScript = $currentPage;
$appShellSidebarHeader = 'brand';
$appShellProfileInitial = $avatarInitial;
$appShellProfileName = $studentShortName;
$appShellProfileHref = 'student_dashboard.php';
$appShellProfileImage = ($avatarPath !== '' && !$useDefaultAvatar) ? $avatarPath : '';
$appShellTopbarAvatarImage = $appShellProfileImage;
$appShellTopbarAvatarInitial = $avatarInitial;

$appShellNavConfig = [
    [
        'label' => 'My learning',
        'items' => [
            ['label' => 'Dashboard', 'href' => 'student_dashboard.php', 'icon' => 'bi-speedometer2', 'active' => ['student_dashboard.php']],
            ['label' => 'Subjects', 'href' => 'student_subjects.php', 'icon' => 'bi-journal-bookmark', 'active' => ['student_subjects.php']],
        ],
    ],
    [
        'label' => 'Modules',
        'items' => [
            ['label' => 'Preboards', 'href' => 'student_preboards.php', 'icon' => 'bi-clipboard-check', 'active' => ['student_preboards.php', 'student_preboards_view.php']],
            ['label' => 'Preweek', 'href' => 'student_preweek.php', 'icon' => 'bi-lightning-charge', 'active' => ['student_preweek.php', 'student_preweek_topics.php', 'student_preweek_viewer.php']],
        ],
    ],
];

require __DIR__ . '/includes/components/app_shell_sidebar.php';
