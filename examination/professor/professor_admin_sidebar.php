<?php
/**
 * Professor admin shell - uses admin styling; brand links to professor dashboard.
 */
require_once dirname(__DIR__, 2) . '/includes/url_helpers.php';
$appShellCurrentScript = ereview_page_basename();
$appShellTheme = 'admin';
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
        'label' => 'Examination',
        'items' => [
            ['label' => 'Students', 'href' => 'professor_college_students', 'icon' => 'bi-people', 'title' => 'College students and reviewees for examinations', 'active' => ['professor_college_students', 'student_registration', 'professor_create_college_student', 'professor_create_reviewee', 'professor_college_student_view']],
            ['label' => 'Sections', 'href' => 'professor_college_sections', 'icon' => 'bi-collection', 'title' => 'Centralized College Examination sections', 'active' => ['professor_college_sections']],
            ['label' => 'Examinations', 'href' => 'professor_examinations', 'icon' => 'bi-journal-text', 'title' => 'All examinations', 'active' => ['professor_examinations', 'professor_examination_edit', 'professor_exams', 'professor_exam_edit', 'professor_diagnostic_batches', 'professor_diagnostic_batch_edit']],
            ['label' => 'Monitoring', 'href' => 'professor_examination_monitor', 'icon' => 'bi-graph-up', 'title' => 'Examination monitoring', 'active' => ['professor_examination_monitor', 'professor_exam_monitor', 'professor_diagnostic_monitor']],
            ['label' => 'Upload tasks', 'href' => 'professor_upload_tasks', 'icon' => 'bi-folder-plus', 'title' => 'Assignment uploads', 'active' => ['professor_upload_tasks', 'professor_upload_task_monitor']],
        ],
    ],
];

require dirname(__DIR__, 2) . '/includes/components/app_shell_sidebar.php';
