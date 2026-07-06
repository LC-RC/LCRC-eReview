<?php
/**
 * Admin shell entry — sidebar, topbar, and main wrapper are rendered by the unified component.
 */
require_once __DIR__ . '/includes/url_helpers.php';
$adminPendingCount = 0;
$adminPreboardsPendingCount = 0;
if (!empty($conn)) {
    $pr = @mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM users WHERE role='student' AND status='pending'");
    if ($pr && $prRow = mysqli_fetch_assoc($pr)) {
        $adminPendingCount = (int)($prRow['cnt'] ?? 0);
        mysqli_free_result($pr);
    }
    require_once __DIR__ . '/includes/preboards_helpers.php';
    $adminPreboardsPendingCount = preboards_count_pending_requests($conn);
    if ($adminPreboardsPendingCount > 0 && !empty($_SESSION['user_id'])) {
        require_once __DIR__ . '/includes/notification_helpers.php';
        notifications_sync_admin_preboards_pending_reminder($conn, (int) $_SESSION['user_id']);
    }
}

$appShellCurrentScript = ereview_page_basename();
$appShellTheme = 'admin';
$appShellSidebarHeader = 'brand';
$appShellNavConfig = [
    [
        'label' => 'Manage',
        'items' => [
            ['label' => 'Dashboard', 'href' => 'admin_dashboard', 'icon' => 'bi-speedometer2', 'title' => 'Overview and key numbers', 'active' => ['admin_dashboard']],
            ['label' => 'Students', 'href' => 'admin_students', 'icon' => 'bi-people', 'title' => 'Enrollments, approvals, and access', 'active' => ['admin_students', 'admin_student_view'], 'badge' => $adminPendingCount],
            ['label' => 'Student Access', 'href' => 'admin_student_access', 'icon' => 'bi-shield-lock', 'title' => 'Manage per-student LMS content permissions', 'active' => ['admin_student_access']],
            ['label' => 'Support Analytics', 'href' => 'admin_support_analytics', 'icon' => 'bi-headset', 'title' => 'Analytics, KB backlog, knowledge base, and enrollment lookup', 'active' => ['admin_support_analytics', 'admin_support_backlog', 'admin_support_kb', 'admin_support_lookup']],
        ],
    ],
    [
        'label' => 'Content',
        'items' => [
            ['label' => 'Content', 'href' => 'admin_subjects', 'icon' => 'bi-book', 'title' => 'Subjects, lessons, materials, quizzes, test bank', 'active' => ['admin_subjects', 'admin_lessons', 'admin_videos', 'admin_handouts', 'admin_materials', 'admin_quizzes', 'admin_quiz_questions', 'admin_test_bank']],
            ['label' => 'Preboards', 'href' => 'admin_preboards_subjects', 'icon' => 'bi-clipboard-check', 'title' => 'Preboards: subjects, sets, questions, monitoring', 'active' => ['admin_preboards_subjects', 'admin_preboards_sets', 'admin_preboards_questions', 'admin_preboards_monitor', 'admin_preboards_attempt_review'], 'badge' => $adminPreboardsPendingCount],
            ['label' => 'Pre-week', 'href' => 'admin_preweek', 'icon' => 'bi-lightning-charge', 'title' => 'Pre-week: list entries → lectures → materials (per lecture)', 'active' => ['admin_preweek', 'admin_preweek_topics', 'admin_preweek_materials']],
            ['label' => 'Question sorting', 'href' => 'admin_question_sort', 'icon' => 'bi-diagram-3', 'title' => 'Upload .docx MCQs; group by topic in parentheses; export JSON / HTML / Word', 'active' => ['admin_question_sort']],
        ],
    ],
];

require __DIR__ . '/includes/components/app_shell_sidebar.php';
