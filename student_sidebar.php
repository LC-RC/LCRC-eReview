<?php
/**
 * Student shell entry — unified sidebar + topbar + main open (see includes/components/app_shell_sidebar.php).
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$fullName = trim($_SESSION['full_name'] ?? 'User');
$nameWords = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY);
if (count($nameWords) <= 1) {
    $studentShortName = $fullName ? mb_strtoupper(mb_substr($fullName, 0, 1)) . '.' : 'U.';
} elseif (count($nameWords) === 2) {
    $studentShortName = mb_strtoupper(mb_substr($nameWords[0], 0, 1)) . '. ' . $nameWords[1];
} else {
    $lastWord = array_pop($nameWords);
    $initials = implode('.', array_map(function ($w) {
        return mb_strtoupper(mb_substr($w, 0, 1));
    }, $nameWords));
    $studentShortName = $initials . '.' . $lastWord;
}

$appShellTheme = 'student';
$appShellCurrentScript = $currentPage;
$appShellSidebarHeader = 'profile';
$appShellProfileInitial = strtoupper(mb_substr(trim($_SESSION['full_name'] ?? 'U'), 0, 1));
$appShellProfileName = $studentShortName;
$appShellProfileHref = 'student_dashboard.php';

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
            ['label' => 'Preweek', 'href' => 'student_preweek.php', 'icon' => 'bi-lightning-charge', 'active' => ['student_preweek.php']],
        ],
    ],
];

require __DIR__ . '/includes/components/app_shell_sidebar.php';
