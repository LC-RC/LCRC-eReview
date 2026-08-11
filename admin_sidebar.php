<?php
/**
 * Admin shell entry — sidebar, topbar, and main wrapper are rendered by the unified component.
 */
require_once __DIR__ . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/admin_acl.php';

if (!empty($conn) && $conn instanceof mysqli) {
    admin_acl_ensure_schema($conn);
    if (session_status() === PHP_SESSION_ACTIVE && ($_SESSION['role'] ?? '') === 'admin') {
        admin_acl_refresh_session($conn);
    }
}

$adminPendingCount = 0;
$adminPreboardsPendingCount = 0;
if (!empty($conn)) {
    // Cache badge COUNTs so every admin page stays cheap after the first hit.
    $badgeTtl = 120;
    $badgeNow = time();
    $cachedPending = $_SESSION['admin_badge_pending_students'] ?? null;
    $cachedPendingAt = (int) ($_SESSION['admin_badge_pending_students_at'] ?? 0);
    $cachedPreboards = $_SESSION['admin_badge_preboards_pending'] ?? null;
    $cachedPreboardsAt = (int) ($_SESSION['admin_badge_preboards_pending_at'] ?? 0);

    if ($cachedPending !== null && ($badgeNow - $cachedPendingAt) < $badgeTtl) {
        $adminPendingCount = (int) $cachedPending;
    } else {
        // Sidebar badge: pending registrations only (no correlated access_grants EXISTS).
        $pr = @mysqli_query(
            $conn,
            "SELECT COUNT(*) AS cnt FROM users WHERE role='student' AND status='pending'"
        );
        if ($pr && $prRow = mysqli_fetch_assoc($pr)) {
            $adminPendingCount = (int) ($prRow['cnt'] ?? 0);
            mysqli_free_result($pr);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['admin_badge_pending_students'] = $adminPendingCount;
            $_SESSION['admin_badge_pending_students_at'] = $badgeNow;
        }
    }

    if ($cachedPreboards !== null && ($badgeNow - $cachedPreboardsAt) < $badgeTtl) {
        $adminPreboardsPendingCount = (int) $cachedPreboards;
    } else {
        require_once __DIR__ . '/includes/preboards_helpers.php';
        $adminPreboardsPendingCount = preboards_count_pending_requests($conn);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['admin_badge_preboards_pending'] = $adminPreboardsPendingCount;
            $_SESSION['admin_badge_preboards_pending_at'] = $badgeNow;
        }
    }
    // Preboards digest sync lives in notifications_api (not every HTML page).
}

// Free the session file lock on normal admin GET pages so badge/API polls are not serialized.
if (
    empty($adminKeepSessionOpen)
    && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
    && function_exists('ereview_release_session_lock')
) {
    ereview_release_session_lock();
}

$appShellCurrentScript = ereview_page_basename();
$appShellTheme = 'admin';
$appShellSidebarHeader = 'brand';
$appShellNavConfig = [
    [
        'label' => 'Main',
        'items' => [
            ['label' => 'Dashboard', 'href' => 'admin_dashboard', 'icon' => 'bi-speedometer2', 'title' => 'Overview and key numbers', 'active' => ['admin_dashboard'], 'acl_key' => 'dashboard'],
            ['label' => 'Admins', 'href' => 'admin_admins', 'icon' => 'bi-shield-lock', 'title' => 'Manage admin accounts, page access, and users log', 'active' => ['admin_admins'], 'acl_key' => 'manage_admins'],
        ],
    ],
    [
        'label' => 'Students',
        'items' => [
            ['label' => 'Students', 'href' => 'admin_students', 'icon' => 'bi-people', 'title' => 'Enrollments, approvals, and access', 'active' => ['admin_students', 'admin_student_view'], 'badge' => $adminPendingCount, 'acl_key' => 'students'],
            ['label' => 'Student Access', 'href' => 'admin_student_access', 'icon' => 'bi-shield-lock', 'title' => 'Manage per-student LMS content permissions', 'active' => ['admin_student_access'], 'acl_key' => 'student_access'],
            ['label' => 'Support Analytics', 'href' => 'admin_support_analytics', 'icon' => 'bi-headset', 'title' => 'Analytics, KB backlog, knowledge base, and enrollment lookup', 'active' => ['admin_support_analytics', 'admin_support_backlog', 'admin_support_kb', 'admin_support_lookup'], 'acl_key' => 'support'],
        ],
    ],
    [
        'label' => 'Content',
        'items' => [
            ['label' => 'Subjects', 'href' => 'admin_subjects', 'icon' => 'bi-book', 'title' => 'Subjects', 'active' => ['admin_subjects'], 'acl_key' => 'subjects'],
            ['label' => 'Lessons', 'href' => 'admin_lessons', 'icon' => 'bi-journal-text', 'title' => 'Lessons / topics', 'active' => ['admin_lessons'], 'acl_key' => 'lessons'],
            ['label' => 'Videos', 'href' => 'admin_videos', 'icon' => 'bi-camera-video', 'title' => 'Lesson videos', 'active' => ['admin_videos'], 'acl_key' => 'videos'],
            ['label' => 'Handouts', 'href' => 'admin_handouts', 'icon' => 'bi-file-earmark-text', 'title' => 'Lesson handouts', 'active' => ['admin_handouts'], 'acl_key' => 'handouts'],
            ['label' => 'Materials', 'href' => 'admin_materials', 'icon' => 'bi-folder2-open', 'title' => 'Course materials uploads', 'active' => ['admin_materials'], 'acl_key' => 'materials'],
            ['label' => 'Quizzes', 'href' => 'admin_quizzes', 'icon' => 'bi-ui-checks-grid', 'title' => 'Quizzes and questions', 'active' => ['admin_quizzes', 'admin_quiz_questions'], 'acl_key' => 'quizzes'],
            ['label' => 'Test Bank', 'href' => 'admin_test_bank', 'icon' => 'bi-collection', 'title' => 'Test bank', 'active' => ['admin_test_bank'], 'acl_key' => 'test_bank'],
        ],
    ],
    [
        'label' => 'Preboards & Pre-week',
        'items' => [
            ['label' => 'Preboards', 'href' => $adminPreboardsPendingCount > 0 ? 'admin_preboards_subjects#preboards-requests' : 'admin_preboards_subjects', 'icon' => 'bi-clipboard-check', 'title' => $adminPreboardsPendingCount > 0 ? ('Preboards: ' . $adminPreboardsPendingCount . ' pending request(s) — open inbox') : 'Preboards: subjects, sets, questions, monitoring', 'active' => ['admin_preboards_subjects', 'admin_preboards_sets', 'admin_preboards_questions', 'admin_preboards_monitor', 'admin_preboards_attempt_review'], 'badge' => $adminPreboardsPendingCount, 'acl_key' => 'preboards'],
            ['label' => 'Pre-week', 'href' => 'admin_preweek', 'icon' => 'bi-lightning-charge', 'title' => 'Pre-week: list entries → lectures → materials (per lecture)', 'active' => ['admin_preweek', 'admin_preweek_topics', 'admin_preweek_materials'], 'acl_key' => 'preweek'],
            ['label' => 'Question Bank', 'href' => 'admin_question_sort', 'icon' => 'bi-diagram-3', 'title' => 'Upload .docx MCQs; group by topic in parentheses; export JSON / HTML / Word', 'active' => ['admin_question_sort'], 'acl_key' => 'question_bank'],
        ],
    ],
    [
        'label' => 'Commerce',
        'items' => [
            ['label' => 'Packages', 'href' => 'admin_commerce_packages', 'icon' => 'bi-box-seam', 'title' => 'Sellable packages, content maps, and features', 'active' => ['admin_commerce_packages'], 'acl_key' => 'commerce_packages'],
            ['label' => 'By Topic Pricing', 'href' => 'admin_commerce_topics', 'icon' => 'bi-tags', 'title' => 'Lesson/topic prices and durations', 'active' => ['admin_commerce_topics'], 'acl_key' => 'commerce_topics'],
            ['label' => 'GCash Settings', 'href' => 'admin_commerce_gcash', 'icon' => 'bi-qr-code', 'title' => 'GCash QR, account, and payment instructions', 'active' => ['admin_commerce_gcash'], 'acl_key' => 'commerce_gcash'],
            ['label' => 'Payment Verification', 'href' => 'admin_commerce_payments', 'icon' => 'bi-receipt', 'title' => 'Payment verification and manual review queue', 'active' => ['admin_commerce_payments'], 'acl_key' => 'commerce_payments'],
            ['label' => 'Free Access', 'href' => 'admin_commerce_free_access', 'icon' => 'bi-gift', 'title' => 'Approve or reject Free Access requests', 'active' => ['admin_commerce_free_access'], 'acl_key' => 'commerce_free_access'],
            ['label' => 'Grant Ledger', 'href' => 'admin_commerce_grants', 'icon' => 'bi-journal-text', 'title' => 'Read-only access_grants ledger', 'active' => ['admin_commerce_grants'], 'acl_key' => 'commerce_grants'],
            ['label' => 'Reports', 'href' => 'admin_commerce_reports', 'icon' => 'bi-bar-chart-line', 'title' => 'Read-only commerce payment, grant, and Free Access summaries', 'active' => ['admin_commerce_reports'], 'acl_key' => 'commerce_reports'],
        ],
    ],
];

$appShellNavConfig = admin_acl_filter_nav($appShellNavConfig);

require __DIR__ . '/includes/components/app_shell_sidebar.php';
