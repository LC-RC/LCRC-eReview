<?php
/**
 * Professor admin shell - uses admin styling; brand links to professor dashboard.
 */
require_once __DIR__ . '/includes/url_helpers.php';
$appShellCurrentScript = ereview_page_basename();
$appShellTheme = 'professor';
$appShellSidebarHeader = 'brand';
$appShellBrandHref = 'professor_admin_dashboard';

$appShellNavConfig = [
    [
        'label' => 'Overview',
        'items' => [
            ['label' => 'Dashboard', 'href' => 'professor_admin_dashboard', 'icon' => 'bi-speedometer2', 'title' => 'Overview', 'active' => ['professor_admin_dashboard']],
        ],
    ],
    [
        'label' => 'College',
        'items' => [
            ['label' => 'Students', 'href' => 'professor_college_students', 'icon' => 'bi-people', 'title' => 'College student accounts', 'active' => ['professor_college_students', 'professor_create_college_student']],
            ['label' => 'Exams', 'href' => 'professor_exams', 'icon' => 'bi-journal-text', 'title' => 'Quizzes and exams', 'active' => ['professor_exams', 'professor_exam_edit', 'professor_exam_monitor', 'professor_exam_review_sheet']],
            ['label' => 'Upload tasks', 'href' => 'professor_upload_tasks', 'icon' => 'bi-folder-plus', 'title' => 'Assignment uploads', 'active' => ['professor_upload_tasks', 'professor_upload_task_monitor']],
            ['label' => 'Monitor', 'href' => 'professor_monitor', 'icon' => 'bi-graph-up', 'title' => 'Scores and files', 'active' => ['professor_monitor']],
        ],
    ],
];

require __DIR__ . '/includes/components/app_shell_sidebar.php';
