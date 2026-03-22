<?php
/**
 * Admin shell entry — sidebar, topbar, and main wrapper are rendered by the unified component.
 */
$adminPendingCount = 0;
if (!empty($conn)) {
    $pr = @mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM users WHERE role='student' AND status='pending'");
    if ($pr && $prRow = mysqli_fetch_assoc($pr)) {
        $adminPendingCount = (int)($prRow['cnt'] ?? 0);
        mysqli_free_result($pr);
    }
}

$appShellCurrentScript = basename($_SERVER['PHP_SELF']);
$appShellTheme = 'admin';
$appShellSidebarHeader = 'brand';
$appShellNavConfig = [
    [
        'label' => 'Manage',
        'items' => [
            ['label' => 'Dashboard', 'href' => 'admin_dashboard.php', 'icon' => 'bi-speedometer2', 'title' => 'Overview and key numbers', 'active' => ['admin_dashboard.php']],
            ['label' => 'Students', 'href' => 'admin_students.php', 'icon' => 'bi-people', 'title' => 'Enrollments, approvals, and access', 'active' => ['admin_students.php', 'admin_student_view.php'], 'badge' => $adminPendingCount],
        ],
    ],
    [
        'label' => 'Content',
        'items' => [
            ['label' => 'Content', 'href' => 'admin_subjects.php', 'icon' => 'bi-book', 'title' => 'Subjects, lessons, materials, quizzes, test bank', 'active' => ['admin_subjects.php', 'admin_lessons.php', 'admin_videos.php', 'admin_handouts.php', 'admin_materials.php', 'admin_quizzes.php', 'admin_quiz_questions.php', 'admin_test_bank.php']],
            ['label' => 'Preboards', 'href' => 'admin_preboards_subjects.php', 'icon' => 'bi-clipboard-check', 'title' => 'Preboards: subjects, sets, questions', 'active' => ['admin_preboards_subjects.php', 'admin_preboards_sets.php', 'admin_preboards_questions.php']],
            ['label' => 'Preweek', 'href' => 'admin_preweek.php', 'icon' => 'bi-lightning-charge', 'title' => 'Preweek: videos and handouts', 'active' => ['admin_preweek.php']],
        ],
    ],
];

require __DIR__ . '/includes/components/app_shell_sidebar.php';
