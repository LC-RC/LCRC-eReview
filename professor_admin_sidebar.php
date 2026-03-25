<?php
/**
 * Professor admin shell — uses admin styling; brand links to professor dashboard.
 */
$appShellCurrentScript = basename($_SERVER['PHP_SELF']);
$appShellTheme = 'professor';
$appShellSidebarHeader = 'brand';
$appShellBrandHref = 'professor_admin_dashboard.php';

$appShellNavConfig = [
    [
        'label' => 'Overview',
        'items' => [
            ['label' => 'Dashboard', 'href' => 'professor_admin_dashboard.php', 'icon' => 'bi-speedometer2', 'title' => 'Overview', 'active' => ['professor_admin_dashboard.php']],
        ],
    ],
    [
        'label' => 'College',
        'items' => [
            ['label' => 'Students', 'href' => 'professor_college_students.php', 'icon' => 'bi-people', 'title' => 'College student accounts', 'active' => ['professor_college_students.php', 'professor_create_college_student.php']],
            ['label' => 'Exams', 'href' => 'professor_exams.php', 'icon' => 'bi-journal-text', 'title' => 'Quizzes and exams', 'active' => ['professor_exams.php', 'professor_exam_edit.php']],
            ['label' => 'Upload tasks', 'href' => 'professor_upload_tasks.php', 'icon' => 'bi-folder-plus', 'title' => 'Assignment uploads', 'active' => ['professor_upload_tasks.php']],
            ['label' => 'Monitor', 'href' => 'professor_monitor.php', 'icon' => 'bi-graph-up', 'title' => 'Scores and files', 'active' => ['professor_monitor.php']],
        ],
    ],
];

require __DIR__ . '/includes/components/app_shell_sidebar.php';
