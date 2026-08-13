<?php
/**
 * Student shell entry - unified sidebar + topbar + main open (see includes/components/app_shell_sidebar).
 */
require_once __DIR__ . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/ereview_app_settings.php';
require_once __DIR__ . '/includes/student_playground.php';
$currentPage = ereview_page_basename();
require_once __DIR__ . '/includes/format_display_name.php';
require_once __DIR__ . '/includes/profile_avatar.php';

$playgroundEnabled = true;
if (!empty($conn) && $conn instanceof mysqli) {
    $playgroundEnabled = student_playground_is_enabled($conn);
}
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
$appShellProfileHref = 'student_dashboard';
$appShellProfileImage = ($avatarPath !== '' && !$useDefaultAvatar) ? $avatarPath : '';
$appShellTopbarAvatarImage = $appShellProfileImage;
$appShellTopbarAvatarInitial = $avatarInitial;

$modulesItems = [
    ['label' => 'Preboards', 'href' => 'student_preboards', 'icon' => 'bi-clipboard-check', 'active' => ['student_preboards', 'student_preboards_view']],
    ['label' => 'Preweek', 'href' => 'student_preweek', 'icon' => 'bi-lightning-charge', 'active' => ['student_preweek', 'student_preweek_topics', 'student_preweek_viewer']],
];
if ($playgroundEnabled) {
    $modulesItems[] = [
        'label' => 'CPA Playground',
        'href' => 'student_playground',
        'icon' => 'bi-controller',
        'active' => [
            'student_playground',
            'student_playground_play',
            'student_playground_result',
            'student_playground_battle',
            'student_playground_battle_lobby',
            'student_playground_battle_play',
            'student_playground_battle_result',
        ],
    ];
}

$appShellNavConfig = [
    [
        'label' => 'My learning',
        'items' => [
            ['label' => 'Dashboard', 'href' => 'student_dashboard', 'icon' => 'bi-speedometer2', 'active' => ['student_dashboard']],
            ['label' => 'Subjects', 'href' => 'student_subjects', 'icon' => 'bi-journal-bookmark', 'active' => ['student_subjects']],
        ],
    ],
    [
        'label' => 'Modules',
        'items' => $modulesItems,
    ],
    [
        'label' => 'My CPA Review',
        'items' => [
            [
                'label' => 'My CPA Review',
                'href' => 'student_cpa_review',
                'icon' => 'bi-book',
                'active' => [
                    'student_cpa_review',
                    'student_cpa_notes',
                    'student_cpa_bookmarks',
                    'student_cpa_important',
                    'student_cpa_mistakes',
                    'student_cpa_quick_review',
                    'student_cpa_progress',
                    'student_cpa_last_minute',
                ],
            ],
        ],
    ],
];

require __DIR__ . '/includes/components/app_shell_sidebar.php';
