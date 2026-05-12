<?php
declare(strict_types=1);

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/messaging_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ereview_msg_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}
if (!isLoggedIn() || !verifySession()) {
    ereview_msg_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}
if (!ereview_msg_tables_ready($conn)) {
    ereview_msg_json(['ok' => false, 'error' => 'Messaging migration is not installed. Run migration 021.'], 503);
}

$userId = (int)getCurrentUserId();
$role = (string)getCurrentUserRole();
if (!ereview_msg_is_admin_role($role) && !ereview_msg_is_reviewee_role($role)) {
    ereview_msg_json(['ok' => false, 'error' => 'Forbidden'], 403);
}

$markRead = isset($_GET['mark_read']) && (int)$_GET['mark_read'] === 1;
$activeStudentId = 0;
$activeContactId = 0;
$threads = [];

try {

if (ereview_msg_is_admin_role($role)) {
    $threads = ereview_msg_fetch_admin_threads($conn);
    $contactRows = ereview_msg_fetch_reviewee_contacts($conn, 400);
    $byStudent = [];
    foreach ($threads as $t) {
        $sid = (int)($t['student_id'] ?? 0);
        if ($sid > 0) $byStudent[$sid] = $t;
    }
    foreach ($contactRows as $c) {
        $sid = (int)($c['student_id'] ?? 0);
        if ($sid <= 0 || isset($byStudent[$sid])) continue;
        $threads[] = [
            'student_id' => $sid,
            'student_name' => (string)$c['student_name'],
            'last_preview' => 'Start a conversation',
            'last_at' => '',
            'last_at_human' => '',
            'unread_count' => 0,
        ];
    }
    $threads = ereview_msg_enrich_threads_for_ui($conn, $threads);
    ereview_msg_apply_admin_pins($threads, ereview_msg_admin_pinned_map($conn, $userId));
    ereview_msg_sort_threads_for_sidebar($threads);

    $requested = (int)($_GET['student_id'] ?? 0);
    if ($requested > 0) {
        $activeStudentId = $requested;
    } elseif (!empty($threads)) {
        $activeStudentId = (int)$threads[0]['student_id'];
    }
    $activeContactId = $activeStudentId;
} else {
    $activeStudentId = $userId;
    $admins = ereview_msg_fetch_admin_contacts($conn, 50);
    foreach ($admins as $a) {
        $threads[] = [
            'student_id' => (int)$a['admin_id'],
            'student_name' => (string)$a['admin_name'],
            'last_preview' => 'Admin contact',
            'last_at' => '',
            'last_at_human' => '',
            'unread_count' => 0,
        ];
    }
    $threads = ereview_msg_enrich_threads_for_ui($conn, $threads);
    ereview_msg_sort_threads_for_sidebar($threads);
    $reqAdmin = (int)($_GET['admin_id'] ?? 0);
    if ($reqAdmin > 0) $activeContactId = $reqAdmin;
    elseif (!empty($threads)) $activeContactId = (int)$threads[0]['student_id'];
}

if ($markRead && $activeStudentId > 0) {
    ereview_msg_mark_read($conn, $role, $userId, $activeStudentId);
}

$messages = $activeStudentId > 0 ? ereview_msg_fetch_messages($conn, $activeStudentId, 150) : [];
$unreadTotal = ereview_msg_unread_total($conn, $role, $userId);
if (ereview_msg_is_admin_role($role)) {
    $chatTitle = $activeStudentId > 0
        ? ('Conversation with ' . ereview_msg_get_user_display_name($conn, $activeStudentId))
        : 'Select a conversation';
} else {
    $targetName = 'Admin support';
    foreach ($threads as $t) {
        if ((int)$t['student_id'] === $activeContactId) {
            $targetName = (string)$t['student_name'];
            break;
        }
    }
    $chatTitle = 'Message ' . $targetName;
}

$normalizedThreads = [];
foreach ($threads as $t) {
    $id = (int)($t['student_id'] ?? 0);
    if ($id <= 0) continue;
    $normalizedThreads[] = [
        'contact_id' => $id,
        'contact_name' => (string)($t['student_name'] ?? ('Contact #' . $id)),
        'last_preview' => (string)($t['last_preview'] ?? ''),
        'last_at' => (string)($t['last_at'] ?? ''),
        'last_at_human' => (string)($t['last_at_human'] ?? ''),
        'unread_count' => (int)($t['unread_count'] ?? 0),
        'avatar_src' => (string)($t['avatar_img_src'] ?? ''),
        'avatar_initial' => (string)($t['avatar_initial'] ?? ''),
        'session_active' => !empty($t['is_session_active']),
        'is_pinned' => !empty($t['is_pinned']),
    ];
}

ereview_msg_json([
    'ok' => true,
    'role' => $role,
    'user_id' => $userId,
    'threads' => $normalizedThreads,
    'active_student_id' => $activeStudentId,
    'active_contact_id' => $activeContactId,
    'thread_student_id' => $activeStudentId,
    'messages' => $messages,
    'chat_title' => $chatTitle,
    'unread_total' => $unreadTotal,
]);

} catch (Throwable $e) {
    ereview_msg_json([
        'ok' => false,
        'error' => 'Messaging temporarily unavailable. Please try again.',
    ], 500);
}

