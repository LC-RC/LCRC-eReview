<?php
declare(strict_types=1);

function ereview_msg_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ereview_msg_tables_ready(mysqli $conn): bool
{
    $res = @mysqli_query($conn, "SHOW TABLES LIKE 'admin_reviewee_messages'");
    return (bool)($res && mysqli_fetch_row($res));
}

function ereview_msg_is_admin_role(string $role): bool
{
    return $role === 'admin' || $role === 'professor_admin';
}

function ereview_msg_is_reviewee_role(string $role): bool
{
    return $role === 'student' || $role === 'college_student';
}

/**
 * Optional users columns for avatars + presence (same detection pattern as admin_students.php).
 *
 * @return array<string,bool>
 */
function ereview_msg_user_profile_column_flags(mysqli $conn): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $keys = ['profile_picture', 'use_default_avatar', 'is_online', 'last_seen_at', 'last_logout_at', 'last_login_at'];
    $cached = array_fill_keys($keys, false);
    foreach ($keys as $k) {
        $res = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '" . mysqli_real_escape_string($conn, $k) . "'");
        if ($res && mysqli_fetch_assoc($res)) {
            $cached[$k] = true;
        }
    }
    return $cached;
}

/**
 * Restrict presence query IDs to peers the caller may message (staff → reviewees; reviewee → staff).
 *
 * @param list<int> $ids
 * @return list<int>
 */
function ereview_msg_filter_ids_for_presence_query(mysqli $conn, string $role, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map(static function ($n) {
        return (int)$n;
    }, $ids), static function ($n) {
        return $n > 0;
    })));
    if ($ids === []) {
        return [];
    }
    if (count($ids) > 400) {
        $ids = array_slice($ids, 0, 400);
    }
    $in = implode(',', $ids);
    if (ereview_msg_is_admin_role($role)) {
        $sql = "SELECT user_id FROM users WHERE user_id IN ({$in}) AND role IN ('student','college_student')";
    } elseif (ereview_msg_is_reviewee_role($role)) {
        $sql = "SELECT user_id FROM users WHERE user_id IN ({$in}) AND role IN ('admin','professor_admin')";
    } else {
        return [];
    }
    $res = @mysqli_query($conn, $sql);
    $out = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $out[] = (int)($row['user_id'] ?? 0);
    }
    return array_values(array_unique(array_filter($out, static function ($n) {
        return $n > 0;
    })));
}

/**
 * Session-active map (same rules as admin_students_presence.php / ereview_msg_compute_session_active).
 *
 * @param list<int> $ids
 * @return array<string,bool> user_id string => active
 */
function ereview_msg_presence_map_for_ids(mysqli $conn, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map(static function ($n) {
        return (int)$n;
    }, $ids), static function ($n) {
        return $n > 0;
    })));
    if ($ids === []) {
        return [];
    }
    if (count($ids) > 400) {
        $ids = array_slice($ids, 0, 400);
    }
    $flags = ereview_msg_user_profile_column_flags($conn);
    $cols = ['user_id'];
    foreach (['is_online', 'last_seen_at', 'last_logout_at', 'last_login_at'] as $k) {
        if (!empty($flags[$k])) {
            $cols[] = $k;
        }
    }
    $in = implode(',', $ids);
    $sql = 'SELECT ' . implode(',', $cols) . ' FROM users WHERE user_id IN (' . $in . ')';
    $res = @mysqli_query($conn, $sql);
    $presence = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $uid = (int)($row['user_id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        $presence[(string)$uid] = ereview_msg_compute_session_active($row, $flags);
    }
    return $presence;
}

function ereview_msg_compute_session_active(array $row, array $flags): bool
{
    if ($row === []) {
        return false;
    }
    $hasLastSeenAt = !empty($flags['last_seen_at']);
    $hasLastLoginAt = !empty($flags['last_login_at']);
    $hasIsOnline = !empty($flags['is_online']);
    $hasLastLogoutAt = !empty($flags['last_logout_at']);

    $isSessionActive = false;
    $recentThresholdTs = time() - (2 * 60);
    if ($hasLastSeenAt && !empty($row['last_seen_at'])) {
        $lastSeenTs = strtotime((string)$row['last_seen_at']);
        if ($lastSeenTs !== false && $lastSeenTs >= $recentThresholdTs) {
            $isSessionActive = true;
        }
    } elseif ($hasLastLoginAt && !empty($row['last_login_at'])) {
        $lastLoginTs = strtotime((string)$row['last_login_at']);
        if ($lastLoginTs !== false && $lastLoginTs >= $recentThresholdTs) {
            $isSessionActive = true;
        }
    } elseif (!$hasLastSeenAt && !$hasLastLoginAt && $hasIsOnline && !empty($row['is_online'])) {
        $isSessionActive = true;
    }
    if ($hasLastLogoutAt && !empty($row['last_logout_at'])) {
        $lastLogoutTs = strtotime((string)$row['last_logout_at']);
        $lastSeenTs2 = (!empty($row['last_seen_at']) ? strtotime((string)$row['last_seen_at']) : false);
        if ($lastLogoutTs !== false && ($lastSeenTs2 === false || $lastSeenTs2 <= $lastLogoutTs)) {
            $isSessionActive = false;
        }
    }
    return $isSessionActive;
}

/**
 * @param list<int> $userIds
 * @return array<int,array<string,mixed>>
 */
function ereview_msg_batch_users_by_id(mysqli $conn, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map(static function ($n) {
        return (int)$n;
    }, $userIds), static function ($n) {
        return $n > 0;
    })));
    if ($userIds === []) {
        return [];
    }
    $flags = ereview_msg_user_profile_column_flags($conn);
    $cols = ['user_id', 'full_name'];
    foreach (['profile_picture', 'use_default_avatar', 'is_online', 'last_seen_at', 'last_logout_at', 'last_login_at'] as $k) {
        if (!empty($flags[$k])) {
            $cols[] = $k;
        }
    }
    $in = implode(',', $userIds);
    $sql = 'SELECT ' . implode(',', $cols) . ' FROM users WHERE user_id IN (' . $in . ')';
    $res = @mysqli_query($conn, $sql);
    $map = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $map[(int)$row['user_id']] = $row;
    }
    return $map;
}

/**
 * Add avatar + presence fields to thread rows (student_id = contact user id).
 *
 * @param list<array<string,mixed>> $threads
 * @return list<array<string,mixed>>
 */
function ereview_msg_enrich_threads_for_ui(mysqli $conn, array $threads): array
{
    require_once __DIR__ . '/profile_avatar.php';
    $flags = ereview_msg_user_profile_column_flags($conn);
    $ids = [];
    foreach ($threads as $t) {
        $ids[] = (int)($t['student_id'] ?? 0);
    }
    $byId = ereview_msg_batch_users_by_id($conn, $ids);
    $out = [];
    foreach ($threads as $t) {
        $id = (int)($t['student_id'] ?? 0);
        $u = $byId[$id] ?? [];
        $name = (string)($t['student_name'] ?? $u['full_name'] ?? '');
        $avatarPath = ereview_avatar_public_path($u['profile_picture'] ?? '');
        $useDefaultAvatar = !$flags['use_default_avatar'] ? true : !empty($u['use_default_avatar']);
        $showImg = ($avatarPath !== '' && !$useDefaultAvatar);
        $t['avatar_img_src'] = $showImg ? ereview_avatar_img_src($avatarPath) : '';
        $t['avatar_initial'] = ereview_avatar_initial($name !== '' ? $name : 'U');
        $t['is_session_active'] = ereview_msg_compute_session_active($u, $flags);
        $out[] = $t;
    }
    return $out;
}

/**
 * Online first, then most recent message, then name (A–Z).
 *
 * @param list<array<string,mixed>> $threads
 */
function ereview_msg_sort_threads_for_sidebar(array &$threads): void
{
    usort($threads, static function (array $a, array $b): int {
        $pa = !empty($a['is_pinned']) ? 1 : 0;
        $pb = !empty($b['is_pinned']) ? 1 : 0;
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }
        $oa = !empty($a['is_session_active']) ? 1 : 0;
        $ob = !empty($b['is_session_active']) ? 1 : 0;
        if ($oa !== $ob) {
            return $ob <=> $oa;
        }
        $ta = strtotime((string)($a['last_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['last_at'] ?? '')) ?: 0;
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }
        return strcasecmp((string)($a['student_name'] ?? ''), (string)($b['student_name'] ?? ''));
    });
}

function ereview_msg_get_user_display_name(mysqli $conn, int $userId): string
{
    if ($userId <= 0) return 'User';
    // Use only columns present on all installs (schema has full_name, email; "username" may be absent).
    $stmt = mysqli_prepare($conn, "SELECT COALESCE(NULLIF(full_name,''), NULLIF(email,''), CONCAT('User #', user_id)) AS n FROM users WHERE user_id=? LIMIT 1");
    if (!$stmt) {
        return 'User #' . $userId;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return trim((string)($row['n'] ?? ('User #' . $userId)));
}

function ereview_msg_fetch_admin_threads(mysqli $conn): array
{
    $sql = "SELECT
              m.thread_student_id AS student_id,
              MAX(m.message_id) AS last_message_id,
              MAX(m.created_at) AS last_created_at,
              SUM(CASE WHEN m.recipient_role='admin' AND m.read_at IS NULL THEN 1 ELSE 0 END) AS unread_count
            FROM admin_reviewee_messages m
            GROUP BY m.thread_student_id
            ORDER BY MAX(m.message_id) DESC";
    $res = @mysqli_query($conn, $sql);
    $threads = [];
    if (!$res) return $threads;
    while ($row = mysqli_fetch_assoc($res)) {
        $studentId = (int)($row['student_id'] ?? 0);
        if ($studentId <= 0) continue;
        $lastStmt = mysqli_prepare($conn, "SELECT body, created_at FROM admin_reviewee_messages WHERE message_id=? LIMIT 1");
        $preview = '';
        $lastAt = '';
        if ($lastStmt) {
            $mid = (int)($row['last_message_id'] ?? 0);
            mysqli_stmt_bind_param($lastStmt, 'i', $mid);
            mysqli_stmt_execute($lastStmt);
            $lr = mysqli_stmt_get_result($lastStmt);
            $lrow = $lr ? mysqli_fetch_assoc($lr) : null;
            mysqli_stmt_close($lastStmt);
            if ($lrow) {
                $preview = trim((string)($lrow['body'] ?? ''));
                $lastAt = (string)($lrow['created_at'] ?? '');
            }
        }
        $previewShort = function_exists('mb_substr')
            ? (mb_substr($preview, 0, 90) . (mb_strlen($preview) > 90 ? '…' : ''))
            : (substr($preview, 0, 90) . (strlen($preview) > 90 ? '…' : ''));
        $threads[] = [
            'student_id' => $studentId,
            'student_name' => ereview_msg_get_user_display_name($conn, $studentId),
            'last_preview' => $previewShort,
            'last_at' => $lastAt,
            'last_at_human' => $lastAt !== '' ? date('M j, g:i A', strtotime($lastAt)) : '',
            'unread_count' => (int)($row['unread_count'] ?? 0),
        ];
    }
    return $threads;
}

function ereview_msg_fetch_reviewee_contacts(mysqli $conn, int $limit = 300): array
{
    $limit = max(1, min(1000, $limit));
    // Include approved reviewees; pending/rejected can still appear if column differs (legacy DB).
    $sql = "SELECT user_id, role, COALESCE(NULLIF(full_name,''), CONCAT('User #', user_id)) AS n
            FROM users
            WHERE role IN ('student','college_student')
            ORDER BY n ASC
            LIMIT {$limit}";
    $res = @mysqli_query($conn, $sql);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = [
            'student_id' => (int)($row['user_id'] ?? 0),
            'student_name' => trim((string)($row['n'] ?? 'Student')),
            'student_role' => (string)($row['role'] ?? 'student'),
        ];
    }
    return $rows;
}

function ereview_msg_fetch_admin_contacts(mysqli $conn, int $limit = 50): array
{
    $limit = max(1, min(300, $limit));
    $sql = "SELECT user_id, role, COALESCE(NULLIF(full_name,''), CONCAT('Admin #', user_id)) AS n
            FROM users
            WHERE role IN ('admin','professor_admin')
            ORDER BY role='admin' DESC, n ASC
            LIMIT {$limit}";
    $res = @mysqli_query($conn, $sql);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $uid = (int)($row['user_id'] ?? 0);
        if ($uid <= 0) continue;
        $rows[] = [
            'admin_id' => $uid,
            'admin_name' => trim((string)($row['n'] ?? 'Admin')),
            'admin_role' => (string)($row['role'] ?? 'admin'),
        ];
    }
    if (!empty($rows)) {
        return $rows;
    }
    // Fallback: some databases use legacy role storage — pick any account flagged as admin.
    $fb = @mysqli_query($conn, "SELECT user_id, role, COALESCE(NULLIF(full_name,''), CONCAT('Admin #', user_id)) AS n
        FROM users WHERE role='admin' ORDER BY user_id ASC LIMIT 1");
    if ($fb && ($row = mysqli_fetch_assoc($fb))) {
        $uid = (int)($row['user_id'] ?? 0);
        if ($uid > 0) {
            $rows[] = [
                'admin_id' => $uid,
                'admin_name' => trim((string)($row['n'] ?? 'Administrator')),
                'admin_role' => 'admin',
            ];
        }
    }
    return $rows;
}

function ereview_msg_fetch_messages(mysqli $conn, int $studentId, int $limit = 120): array
{
    $limit = max(1, min(300, $limit));
    $stmt = mysqli_prepare($conn, "SELECT message_id, sender_id, sender_role, recipient_id, recipient_role, body, created_at, read_at
                                   FROM admin_reviewee_messages
                                   WHERE thread_student_id=?
                                   ORDER BY message_id DESC
                                   LIMIT {$limit}");
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, 'i', $studentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $row['message_id'] = (int)$row['message_id'];
        $row['sender_id'] = (int)$row['sender_id'];
        $row['recipient_id'] = (int)$row['recipient_id'];
        $row['body'] = (string)$row['body'];
        $row['created_at_human'] = date('M j, g:i A', strtotime((string)$row['created_at']));
        $row['sender_name'] = ereview_msg_get_user_display_name($conn, (int)$row['sender_id']);
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    $rows = array_reverse($rows);
    $map = ereview_msg_attachment_map_for_messages($conn, array_map(static function ($r) { return (int)($r['message_id'] ?? 0); }, $rows));
    foreach ($rows as &$r) {
        $mid = (int)($r['message_id'] ?? 0);
        $r['attachment'] = $map[$mid] ?? null;
    }
    unset($r);
    return $rows;
}

function ereview_msg_mark_read(mysqli $conn, string $role, int $userId, int $studentId): void
{
    if ($studentId <= 0 || $userId <= 0) return;
    if (ereview_msg_is_admin_role($role)) {
        $stmt = mysqli_prepare($conn, "UPDATE admin_reviewee_messages
                                       SET read_at = NOW()
                                       WHERE thread_student_id=? AND recipient_role='admin' AND read_at IS NULL");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $studentId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        return;
    }
    $stmt = mysqli_prepare($conn, "UPDATE admin_reviewee_messages
                                   SET read_at = NOW()
                                   WHERE thread_student_id=? AND recipient_id=? AND recipient_role IN ('student','college_student') AND read_at IS NULL");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $studentId, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function ereview_msg_unread_total(mysqli $conn, string $role, int $userId): int
{
    if ($userId <= 0) return 0;
    if (ereview_msg_is_admin_role($role)) {
        $res = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM admin_reviewee_messages WHERE recipient_role='admin' AND read_at IS NULL");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        return (int)($row['c'] ?? 0);
    }
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM admin_reviewee_messages WHERE recipient_id=? AND recipient_role IN ('student','college_student') AND read_at IS NULL");
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (int)($row['c'] ?? 0);
}

function ereview_msg_typing_table_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $res = @mysqli_query($conn, "SHOW TABLES LIKE 'admin_reviewee_typing'");
    $ready = (bool)($res && mysqli_fetch_row($res));
    return $ready;
}

function ereview_msg_can_access_thread(mysqli $conn, string $role, int $userId, int $threadStudentId): bool
{
    if ($threadStudentId <= 0 || $userId <= 0) {
        return false;
    }
    if (ereview_msg_is_admin_role($role)) {
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id=? AND role IN ('student','college_student') LIMIT 1");
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'i', $threadStudentId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $ok = (bool)($res && mysqli_fetch_assoc($res));
        mysqli_stmt_close($stmt);
        return $ok;
    }
    return $threadStudentId === $userId;
}

/**
 * Newest-first history uses fetch_messages; this returns only rows with message_id > $afterMessageId (ASC for append).
 *
 * @return list<array<string,mixed>>
 */
function ereview_msg_fetch_new_messages_since(mysqli $conn, int $threadStudentId, int $afterMessageId, int $limit = 100): array
{
    $limit = max(1, min(200, $limit));
    $stmt = mysqli_prepare($conn, "SELECT message_id, sender_id, sender_role, recipient_id, recipient_role, body, created_at, read_at
        FROM admin_reviewee_messages
        WHERE thread_student_id=? AND message_id>?
        ORDER BY message_id ASC
        LIMIT {$limit}");
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $threadStudentId, $afterMessageId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $row['message_id'] = (int)$row['message_id'];
        $row['sender_id'] = (int)$row['sender_id'];
        $row['recipient_id'] = (int)$row['recipient_id'];
        $row['body'] = (string)$row['body'];
        $row['created_at_human'] = date('M j, g:i A', strtotime((string)$row['created_at']));
        $row['sender_name'] = ereview_msg_get_user_display_name($conn, (int)$row['sender_id']);
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    $map = ereview_msg_attachment_map_for_messages($conn, array_map(static function ($r) { return (int)($r['message_id'] ?? 0); }, $rows));
    foreach ($rows as &$r) {
        $mid = (int)($r['message_id'] ?? 0);
        $r['attachment'] = $map[$mid] ?? null;
    }
    unset($r);
    return $rows;
}

function ereview_msg_typing_touch(mysqli $conn, int $threadStudentId, int $userId): void
{
    if ($threadStudentId <= 0 || $userId <= 0 || !ereview_msg_typing_table_ready($conn)) {
        return;
    }
    $stmt = mysqli_prepare($conn, "INSERT INTO admin_reviewee_typing (thread_student_id, user_id, touched_at) VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE touched_at = CURRENT_TIMESTAMP");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $threadStudentId, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/**
 * @return array{active:bool,user_id:int,user_name:string}
 */
function ereview_msg_typing_peer(mysqli $conn, int $threadStudentId, int $viewerUserId): array
{
    if ($threadStudentId <= 0 || !ereview_msg_typing_table_ready($conn)) {
        return ['active' => false, 'user_id' => 0, 'user_name' => ''];
    }
    $stmt = mysqli_prepare($conn, "SELECT user_id FROM admin_reviewee_typing
        WHERE thread_student_id=? AND user_id<>? AND touched_at>=DATE_SUB(NOW(), INTERVAL 6 SECOND)
        ORDER BY touched_at DESC LIMIT 1");
    if (!$stmt) {
        return ['active' => false, 'user_id' => 0, 'user_name' => ''];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $threadStudentId, $viewerUserId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return ['active' => false, 'user_id' => 0, 'user_name' => ''];
    }
    $peerId = (int)($row['user_id'] ?? 0);
    if ($peerId <= 0) {
        return ['active' => false, 'user_id' => 0, 'user_name' => ''];
    }
    return [
        'active' => true,
        'user_id' => $peerId,
        'user_name' => ereview_msg_get_user_display_name($conn, $peerId),
    ];
}

function ereview_msg_attachments_table_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $res = @mysqli_query($conn, "SHOW TABLES LIKE 'admin_reviewee_message_attachments'");
    $ready = (bool)($res && mysqli_fetch_row($res));
    return $ready;
}

function ereview_msg_pins_table_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $res = @mysqli_query($conn, "SHOW TABLES LIKE 'admin_reviewee_pinned_threads'");
    $ready = (bool)($res && mysqli_fetch_row($res));
    return $ready;
}

/**
 * @param list<int> $messageIds
 * @return array<int,array<string,mixed>>
 */
function ereview_msg_attachment_map_for_messages(mysqli $conn, array $messageIds): array
{
    if (!ereview_msg_attachments_table_ready($conn)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds), static function ($n) { return $n > 0; })));
    if ($ids === []) return [];
    $in = implode(',', $ids);
    $sql = "SELECT message_id, orig_name, mime_type, size_bytes, storage_token
            FROM admin_reviewee_message_attachments
            WHERE message_id IN ({$in})";
    $res = @mysqli_query($conn, $sql);
    $map = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $mid = (int)($row['message_id'] ?? 0);
        if ($mid <= 0) continue;
        $map[$mid] = [
            'orig_name' => (string)($row['orig_name'] ?? ''),
            'mime_type' => (string)($row['mime_type'] ?? ''),
            'size_bytes' => (int)($row['size_bytes'] ?? 0),
            'download_url' => 'api/messages/attachment_download.php?token=' . urlencode((string)($row['storage_token'] ?? '')),
        ];
    }
    return $map;
}

function ereview_msg_admin_pinned_map(mysqli $conn, int $adminUserId): array
{
    if (!ereview_msg_pins_table_ready($conn) || $adminUserId <= 0) return [];
    $stmt = mysqli_prepare($conn, "SELECT thread_student_id FROM admin_reviewee_pinned_threads WHERE admin_user_id=?");
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, 'i', $adminUserId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $map = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $sid = (int)($row['thread_student_id'] ?? 0);
        if ($sid > 0) $map[$sid] = true;
    }
    mysqli_stmt_close($stmt);
    return $map;
}

function ereview_msg_apply_admin_pins(array &$threads, array $pinnedMap): void
{
    foreach ($threads as &$t) {
        $sid = (int)($t['student_id'] ?? 0);
        $t['is_pinned'] = !empty($pinnedMap[$sid]);
    }
    unset($t);
}

/**
 * Simple anti-spam throttle for send endpoint.
 */
function ereview_msg_rate_limit_check(mysqli $conn, int $threadStudentId, int $senderId, int $windowSeconds = 10, int $maxMessages = 6): array
{
    $threadStudentId = (int)$threadStudentId;
    $senderId = (int)$senderId;
    $windowSeconds = max(3, min(60, (int)$windowSeconds));
    $maxMessages = max(1, min(30, (int)$maxMessages));
    if ($threadStudentId <= 0 || $senderId <= 0) {
        return ['allowed' => true, 'retry_after' => 0];
    }
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c, UNIX_TIMESTAMP(MIN(created_at)) AS first_ts
        FROM admin_reviewee_messages
        WHERE thread_student_id=? AND sender_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL {$windowSeconds} SECOND)");
    if (!$stmt) {
        return ['allowed' => true, 'retry_after' => 0];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $threadStudentId, $senderId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $count = (int)($row['c'] ?? 0);
    if ($count < $maxMessages) {
        return ['allowed' => true, 'retry_after' => 0];
    }
    $firstTs = (int)($row['first_ts'] ?? 0);
    $retryAfter = 2;
    if ($firstTs > 0) {
        $retryAfter = max(1, $windowSeconds - (time() - $firstTs));
    }
    return ['allowed' => false, 'retry_after' => $retryAfter];
}

/**
 * @return list<array<string,mixed>>
 */
function ereview_msg_search_messages(mysqli $conn, int $threadStudentId, string $q, int $limit = 80): array
{
    $threadStudentId = (int)$threadStudentId;
    $q = trim($q);
    $limit = max(1, min(150, (int)$limit));
    if ($threadStudentId <= 0 || $q === '') {
        return [];
    }
    $like = '%' . $q . '%';
    $stmt = mysqli_prepare($conn, "SELECT message_id, sender_id, sender_role, recipient_id, recipient_role, body, created_at, read_at
        FROM admin_reviewee_messages
        WHERE thread_student_id=? AND body LIKE ?
        ORDER BY message_id DESC
        LIMIT {$limit}");
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'is', $threadStudentId, $like);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $row['message_id'] = (int)$row['message_id'];
        $row['sender_id'] = (int)$row['sender_id'];
        $row['recipient_id'] = (int)$row['recipient_id'];
        $row['body'] = (string)$row['body'];
        $row['created_at_human'] = date('M j, g:i A', strtotime((string)$row['created_at']));
        $row['sender_name'] = ereview_msg_get_user_display_name($conn, (int)$row['sender_id']);
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    $rows = array_reverse($rows);
    $map = ereview_msg_attachment_map_for_messages($conn, array_map(static function ($r) { return (int)($r['message_id'] ?? 0); }, $rows));
    foreach ($rows as &$r) {
        $mid = (int)($r['message_id'] ?? 0);
        $r['attachment'] = $map[$mid] ?? null;
    }
    unset($r);
    return $rows;
}

function ereview_msg_can_soft_delete(mysqli $conn, int $messageId, int $viewerId, string $viewerRole, int $windowSeconds = 120): bool
{
    $messageId = (int)$messageId;
    $viewerId = (int)$viewerId;
    if ($messageId <= 0 || $viewerId <= 0 || $windowSeconds <= 0) {
        return false;
    }
    $stmt = mysqli_prepare($conn, "SELECT sender_id, sender_role, read_at, created_at, body
        FROM admin_reviewee_messages WHERE message_id=? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $messageId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return false;
    }
    if ((int)($row['sender_id'] ?? 0) !== $viewerId) {
        return false;
    }
    if ((string)($row['sender_role'] ?? '') !== (string)$viewerRole) {
        return false;
    }
    if ((string)($row['body'] ?? '') === '[message removed]') {
        return false;
    }
    $createdTs = strtotime((string)($row['created_at'] ?? ''));
    if ($createdTs === false || (time() - $createdTs) > $windowSeconds) {
        return false;
    }
    if (!empty($row['read_at'])) {
        return false;
    }
    return true;
}

/**
 * Hook point for AV scanning integration (ClamAV or external scanner).
 */
function ereview_msg_attachment_passes_scan(string $absolutePath): bool
{
    // TODO: integrate real scanner and return false when malicious.
    return is_file($absolutePath);
}

/**
 * Resolve a writable attachment directory with fallbacks.
 */
function ereview_msg_attachment_storage_dir(): string
{
    $root = dirname(__DIR__);
    $candidates = [
        $root . '/ereview_message_storage/message_attachments',
        $root . '/storage/message_attachments',
        sys_get_temp_dir() . '/ereview_message_attachments',
    ];
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
    }
    return '';
}

