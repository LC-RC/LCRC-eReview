<?php
/**
 * Admin shell entry — sidebar, topbar, and main wrapper are rendered by the unified component.
 */
require_once __DIR__ . '/includes/url_helpers.php';
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
            ['label' => 'Dashboard', 'href' => 'admin_dashboard', 'icon' => 'bi-speedometer2', 'title' => 'Overview and key numbers', 'active' => ['admin_dashboard']],
        ],
    ],
    [
        'label' => 'Students',
        'items' => [
            ['label' => 'Students', 'href' => 'admin_students', 'icon' => 'bi-people', 'title' => 'Enrollments, approvals, and access', 'active' => ['admin_students', 'admin_student_view'], 'badge' => $adminPendingCount],
            ['label' => 'Student Access', 'href' => 'admin_student_access', 'icon' => 'bi-shield-lock', 'title' => 'Manage per-student LMS content permissions', 'active' => ['admin_student_access']],
            ['label' => 'Support Analytics', 'href' => 'admin_support_analytics', 'icon' => 'bi-headset', 'title' => 'Analytics, KB backlog, knowledge base, and enrollment lookup', 'active' => ['admin_support_analytics', 'admin_support_backlog', 'admin_support_kb', 'admin_support_lookup']],
        ],
    ],
    [
        'label' => 'Content',
        'items' => [
            ['label' => 'Content', 'href' => 'admin_subjects', 'icon' => 'bi-book', 'title' => 'Subjects, lessons, materials, quizzes, test bank', 'active' => ['admin_subjects', 'admin_lessons', 'admin_videos', 'admin_handouts', 'admin_materials', 'admin_quizzes', 'admin_quiz_questions', 'admin_test_bank']],
            ['label' => 'Preboards', 'href' => $adminPreboardsPendingCount > 0 ? 'admin_preboards_subjects#preboards-requests' : 'admin_preboards_subjects', 'icon' => 'bi-clipboard-check', 'title' => $adminPreboardsPendingCount > 0 ? ('Preboards: ' . $adminPreboardsPendingCount . ' pending request(s) — open inbox') : 'Preboards: subjects, sets, questions, monitoring', 'active' => ['admin_preboards_subjects', 'admin_preboards_sets', 'admin_preboards_questions', 'admin_preboards_monitor', 'admin_preboards_attempt_review'], 'badge' => $adminPreboardsPendingCount],
            ['label' => 'Pre-week', 'href' => 'admin_preweek', 'icon' => 'bi-lightning-charge', 'title' => 'Pre-week: list entries → lectures → materials (per lecture)', 'active' => ['admin_preweek', 'admin_preweek_topics', 'admin_preweek_materials']],
            ['label' => 'Question Bank', 'href' => 'admin_question_sort', 'icon' => 'bi-diagram-3', 'title' => 'Upload .docx MCQs; group by topic in parentheses; export JSON / HTML / Word', 'active' => ['admin_question_sort']],
        ],
    ],
    [
        'label' => 'Commerce',
        'items' => [
            ['label' => 'Packages', 'href' => 'admin_commerce_packages', 'icon' => 'bi-box-seam', 'title' => 'Sellable packages, content maps, and features', 'active' => ['admin_commerce_packages']],
            ['label' => 'By Topic Pricing', 'href' => 'admin_commerce_topics', 'icon' => 'bi-tags', 'title' => 'Lesson/topic prices and durations', 'active' => ['admin_commerce_topics']],
            ['label' => 'GCash Settings', 'href' => 'admin_commerce_gcash', 'icon' => 'bi-qr-code', 'title' => 'GCash QR, account, and payment instructions', 'active' => ['admin_commerce_gcash']],
            ['label' => 'Payment Verification', 'href' => 'admin_commerce_payments', 'icon' => 'bi-receipt', 'title' => 'Payment verification and manual review queue', 'active' => ['admin_commerce_payments']],
            ['label' => 'Free Access', 'href' => 'admin_commerce_free_access', 'icon' => 'bi-gift', 'title' => 'Approve or reject Free Access requests', 'active' => ['admin_commerce_free_access']],
            ['label' => 'Grant Ledger', 'href' => 'admin_commerce_grants', 'icon' => 'bi-journal-text', 'title' => 'Read-only access_grants ledger', 'active' => ['admin_commerce_grants']],
            ['label' => 'Reports', 'href' => 'admin_commerce_reports', 'icon' => 'bi-bar-chart-line', 'title' => 'Read-only commerce payment, grant, and Free Access summaries', 'active' => ['admin_commerce_reports']],
        ],
    ],
];

require __DIR__ . '/includes/components/app_shell_sidebar.php';
