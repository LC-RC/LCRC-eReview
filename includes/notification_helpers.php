<?php
/**
 * Notification helper functions for all roles.
 */

if (!function_exists('notifications_column_exists')) {
    function notifications_column_exists(mysqli $conn, string $table, string $column): bool {
        require_once __DIR__ . '/schema_introspection.php';
        return ereview_schema_column_exists($conn, $table, $column);
    }
}

if (!function_exists('notifications_ensure_table')) {
    function notifications_ensure_table(mysqli $conn) {
        static $ensured = false;
        if ($ensured) return true;
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['notifications_table_ready'])) {
            $ensured = true;
            return true;
        }
        require_once __DIR__ . '/schema_introspection.php';
        if (ereview_schema_table_exists($conn, 'notifications')
            && notifications_column_exists($conn, 'notifications', 'category')
            && notifications_column_exists($conn, 'notifications', 'actor_user_id')
            && notifications_column_exists($conn, 'notifications', 'toast_shown')
        ) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['notifications_table_ready'] = 1;
            }
            $ensured = true;
            return true;
        }
        $sql = "CREATE TABLE IF NOT EXISTS notifications (
            notification_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role VARCHAR(50) DEFAULT NULL,
            title VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            link_url VARCHAR(255) DEFAULT NULL,
            category VARCHAR(50) DEFAULT NULL,
            actor_user_id INT DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            toast_shown TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME DEFAULT NULL,
            INDEX idx_notifications_user_created (user_id, created_at),
            INDEX idx_notifications_user_unread (user_id, is_read),
            INDEX idx_notifications_user_toast (user_id, toast_shown)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        $ok = @mysqli_query($conn, $sql);
        if ($ok) {
            if (!notifications_column_exists($conn, 'notifications', 'category')) {
                @mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN category VARCHAR(50) DEFAULT NULL AFTER link_url");
            }
            if (!notifications_column_exists($conn, 'notifications', 'actor_user_id')) {
                @mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN actor_user_id INT DEFAULT NULL AFTER category");
            }
            if (!notifications_column_exists($conn, 'notifications', 'toast_shown')) {
                @mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN toast_shown TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read");
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['notifications_table_ready'] = 1;
            }
        }
        $ensured = $ok ? true : false;
        return $ok ? true : false;
    }
}

if (!function_exists('notifications_seed_defaults')) {
    function notifications_seed_defaults(mysqli $conn, int $userId, string $role) {
        if ($userId <= 0) return;
        $seedKey = 'notifications_seeded_' . $userId;
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[$seedKey])) {
            return;
        }
        if (!notifications_ensure_table($conn)) return;

        $count = 0;
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            $count = (int)($row['c'] ?? 0);
            mysqli_stmt_close($stmt);
        }
        if ($count > 0) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[$seedKey] = 1;
            }
            return;
        }

        $title1 = 'Welcome to notifications';
        $msg1 = 'Your notification center is now active. Updates will appear here.';
        $title2 = 'Review reminder';
        $msg2 = 'Continue your latest tasks to keep your progress on track.';
        $title3 = 'Tip';
        $msg3 = 'Use search, filters, and quick actions for a faster workflow.';
        $link = dashboardUrlForRole($role);
        $isRead = 0;

        $hasCategory = notifications_column_exists($conn, 'notifications', 'category');
        $hasActor = notifications_column_exists($conn, 'notifications', 'actor_user_id');
        $hasToast = notifications_column_exists($conn, 'notifications', 'toast_shown');
        if ($hasCategory && $hasActor && $hasToast) {
            $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, role, title, message, link_url, category, actor_user_id, is_read, toast_shown) VALUES (?, ?, ?, ?, ?, 'general', NULL, ?, 1)");
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, role, title, message, link_url, is_read) VALUES (?, ?, ?, ?, ?, ?)");
        }
        if (!$stmt) return;

        mysqli_stmt_bind_param($stmt, 'issssi', $userId, $role, $title1, $msg1, $link, $isRead);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_param($stmt, 'issssi', $userId, $role, $title2, $msg2, $link, $isRead);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_param($stmt, 'issssi', $userId, $role, $title3, $msg3, $link, $isRead);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$seedKey] = 1;
        }
    }
}

if (!function_exists('notifications_create_admin_pending_registration_notifications')) {
    function notifications_create_admin_pending_registration_notifications(mysqli $conn, int $newStudentUserId): void {
        if ($newStudentUserId <= 0) return;
        if (!notifications_ensure_table($conn)) return;

        $student = null;
        $stmt = mysqli_prepare($conn, "SELECT user_id, full_name, email, school, school_other, review_type FROM users WHERE user_id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $newStudentUserId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $student = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
        }
        if (!$student || (string)($student['email'] ?? '') === '') return;

        $admins = [];
        $ar = @mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' AND status='approved'");
        while ($ar && ($row = mysqli_fetch_assoc($ar))) {
            $admins[] = (int)$row['user_id'];
        }
        if (empty($admins)) return;

        $title = 'New registration pending approval';
        $msg = 'A newly verified student registration is now waiting in the Pending tab for your review.';
        $link = 'admin_students?tab=pending&q=&page=1';
        $role = 'admin';
        $category = 'pending_registration';
        $isRead = 0;
        $toastShown = 0;

        $hasCategory = notifications_column_exists($conn, 'notifications', 'category');
        $hasActor = notifications_column_exists($conn, 'notifications', 'actor_user_id');
        $hasToast = notifications_column_exists($conn, 'notifications', 'toast_shown');
        if ($hasCategory && $hasActor && $hasToast) {
            $ins = mysqli_prepare($conn, "INSERT INTO notifications (user_id, role, title, message, link_url, category, actor_user_id, is_read, toast_shown) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        } else {
            $ins = mysqli_prepare($conn, "INSERT INTO notifications (user_id, role, title, message, link_url, is_read) VALUES (?, ?, ?, ?, ?, ?)");
        }
        if (!$ins) return;

        foreach ($admins as $adminId) {
            if ($hasCategory && $hasActor && $hasToast) {
                mysqli_stmt_bind_param($ins, 'isssssiii', $adminId, $role, $title, $msg, $link, $category, $newStudentUserId, $isRead, $toastShown);
            } else {
                mysqli_stmt_bind_param($ins, 'issssi', $adminId, $role, $title, $msg, $link, $isRead);
            }
            mysqli_stmt_execute($ins);
        }
        mysqli_stmt_close($ins);
    }
}

if (!function_exists('notifications_get_admin_user_ids')) {
    /** @return list<int> */
    function notifications_get_admin_user_ids(mysqli $conn): array
    {
        $admins = [];
        $ar = @mysqli_query($conn, "SELECT user_id FROM users WHERE role='admin' AND status='approved'");
        while ($ar && ($row = mysqli_fetch_assoc($ar))) {
            $admins[] = (int) $row['user_id'];
        }
        if ($ar) {
            mysqli_free_result($ar);
        }
        return $admins;
    }
}

if (!function_exists('notifications_create_for_user')) {
    /**
     * Create one in-app notification for a user (student/admin/etc.).
     *
     * @return bool true when a row was inserted
     */
    function notifications_create_for_user(
        mysqli $conn,
        int $userId,
        string $role,
        string $title,
        string $message,
        string $linkUrl = '',
        string $category = 'general',
        ?int $actorUserId = null
    ): bool {
        if ($userId <= 0 || trim($title) === '' || trim($message) === '') {
            return false;
        }
        if (!notifications_ensure_table($conn)) {
            return false;
        }
        $role = trim($role);
        if ($role === '') {
            $role = 'student';
        }
        $linkUrl = trim($linkUrl);
        $category = trim($category) !== '' ? trim($category) : 'general';
        $isRead = 0;
        $toastShown = 0;
        $actor = ($actorUserId !== null && $actorUserId > 0) ? $actorUserId : 0;

        $hasCategory = notifications_column_exists($conn, 'notifications', 'category');
        $hasActor = notifications_column_exists($conn, 'notifications', 'actor_user_id');
        $hasToast = notifications_column_exists($conn, 'notifications', 'toast_shown');
        if ($hasCategory && $hasActor && $hasToast) {
            $ins = mysqli_prepare(
                $conn,
                'INSERT INTO notifications (user_id, role, title, message, link_url, category, actor_user_id, is_read, toast_shown)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$ins) {
                return false;
            }
            // actor_user_id 0 → store NULL
            if ($actor <= 0) {
                $insNull = mysqli_prepare(
                    $conn,
                    'INSERT INTO notifications (user_id, role, title, message, link_url, category, actor_user_id, is_read, toast_shown)
                     VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)'
                );
                if ($insNull) {
                    mysqli_stmt_close($ins);
                    $ins = $insNull;
                    mysqli_stmt_bind_param(
                        $ins,
                        'isssssii',
                        $userId,
                        $role,
                        $title,
                        $message,
                        $linkUrl,
                        $category,
                        $isRead,
                        $toastShown
                    );
                    $ok = mysqli_stmt_execute($ins);
                    mysqli_stmt_close($ins);
                    return (bool) $ok;
                }
            }
            mysqli_stmt_bind_param(
                $ins,
                'isssssiii',
                $userId,
                $role,
                $title,
                $message,
                $linkUrl,
                $category,
                $actor,
                $isRead,
                $toastShown
            );
            $ok = mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
            return (bool) $ok;
        }

        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO notifications (user_id, role, title, message, link_url, is_read)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$ins) {
            return false;
        }
        mysqli_stmt_bind_param($ins, 'issssi', $userId, $role, $title, $message, $linkUrl, $isRead);
        $ok = mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
        return (bool) $ok;
    }
}

if (!function_exists('notifications_create_admin_preboards_request_notifications')) {
    function notifications_create_admin_preboards_request_notifications(
        mysqli $conn,
        int $studentUserId,
        int $preboardsSubjectId,
        string $subjectName,
        string $setLabel,
        string $requestType
    ): void {
        if ($studentUserId <= 0 || $preboardsSubjectId <= 0) {
            return;
        }
        if (!notifications_ensure_table($conn)) {
            return;
        }

        $studentName = 'A student';
        $stmt = mysqli_prepare($conn, 'SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $studentUserId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $student = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
            if ($student) {
                $studentName = trim((string) ($student['full_name'] ?? '')) ?: trim((string) ($student['email'] ?? '')) ?: $studentName;
            }
        }

        $setLabel = trim($setLabel) !== '' ? trim($setLabel) : 'Set';
        $subjectName = trim($subjectName) !== '' ? trim($subjectName) : 'Preboards';
        $isRetake = $requestType === 'retake';
        $title = $isRetake ? 'Preboards retake request' : 'Preboards access request';
        $msg = $studentName . ' requested ' . ($isRetake ? 'a retake' : 'access') . ' for Set ' . $setLabel . ' (' . $subjectName . ').';
        $link = 'admin_preboards_sets?preboards_subject_id=' . (int) $preboardsSubjectId . '#preboards-requests';
        $role = 'admin';
        $category = $isRetake ? 'preboards_request_retake' : 'preboards_request_access';
        $isRead = 0;
        $toastShown = 0;

        $admins = notifications_get_admin_user_ids($conn);
        if (empty($admins)) {
            return;
        }

        $hasCategory = notifications_column_exists($conn, 'notifications', 'category');
        $hasActor = notifications_column_exists($conn, 'notifications', 'actor_user_id');
        $hasToast = notifications_column_exists($conn, 'notifications', 'toast_shown');
        if ($hasCategory && $hasActor && $hasToast) {
            $ins = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, role, title, message, link_url, category, actor_user_id, is_read, toast_shown) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        } else {
            $ins = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, role, title, message, link_url, is_read) VALUES (?, ?, ?, ?, ?, ?)');
        }
        if (!$ins) {
            return;
        }

        foreach ($admins as $adminId) {
            if ($hasCategory && $hasActor && $hasToast) {
                mysqli_stmt_bind_param($ins, 'isssssiii', $adminId, $role, $title, $msg, $link, $category, $studentUserId, $isRead, $toastShown);
            } else {
                mysqli_stmt_bind_param($ins, 'issssi', $adminId, $role, $title, $msg, $link, $isRead);
            }
            mysqli_stmt_execute($ins);
        }
        mysqli_stmt_close($ins);
    }
}

if (!function_exists('notifications_sync_admin_preboards_pending_reminder')) {
    function notifications_sync_admin_preboards_pending_reminder(mysqli $conn, int $adminUserId): void
    {
        if ($adminUserId <= 0) {
            return;
        }
        if (!notifications_ensure_table($conn)) {
            return;
        }

        require_once __DIR__ . '/preboards_helpers.php';
        $counts = preboards_pending_request_counts($conn);
        if ($counts['total'] <= 0) {
            return;
        }

        $stmt = mysqli_prepare(
            $conn,
            "SELECT notification_id FROM notifications
             WHERE user_id = ? AND category = 'preboards_pending_digest' AND is_read = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL 12 HOUR)
             LIMIT 1"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $adminUserId);
            mysqli_stmt_execute($stmt);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($existing) {
                return;
            }
        }

        $parts = [];
        if ($counts['access'] > 0) {
            $parts[] = $counts['access'] . ' access';
        }
        if ($counts['retake'] > 0) {
            $parts[] = $counts['retake'] . ' retake';
        }
        $detail = implode(' and ', $parts);
        $title = 'Pending preboards requests';
        $msg = 'You have ' . $counts['total'] . ' pending student request(s)' . ($detail !== '' ? ' (' . $detail . ')' : '') . '. Review them under Preboards sets.';
        $link = 'admin_preboards_subjects#preboards-requests';
        $role = 'admin';
        $category = 'preboards_pending_digest';
        $isRead = 0;
        $toastShown = 0;

        $hasCategory = notifications_column_exists($conn, 'notifications', 'category');
        $hasActor = notifications_column_exists($conn, 'notifications', 'actor_user_id');
        $hasToast = notifications_column_exists($conn, 'notifications', 'toast_shown');
        if ($hasCategory && $hasActor && $hasToast) {
            $ins = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, role, title, message, link_url, category, actor_user_id, is_read, toast_shown) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)');
            if ($ins) {
                mysqli_stmt_bind_param($ins, 'isssssii', $adminUserId, $role, $title, $msg, $link, $category, $isRead, $toastShown);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        } else {
            $ins = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, role, title, message, link_url, is_read) VALUES (?, ?, ?, ?, ?, ?)');
            if ($ins) {
                mysqli_stmt_bind_param($ins, 'issssi', $adminUserId, $role, $title, $msg, $link, $isRead);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }
    }
}

if (!function_exists('notifications_time_label')) {
    function notifications_time_label(?string $dt): string {
        if (!$dt) return 'Just now';
        $ts = strtotime($dt);
        if (!$ts) return 'Just now';
        $diff = time() - $ts;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
        if ($diff < 172800) return 'Yesterday';
        if ($diff < 604800) return floor($diff / 86400) . ' days ago';
        return date('M j', $ts);
    }
}

if (!function_exists('notifications_list_for_user')) {
    function notifications_list_for_user(mysqli $conn, int $userId, int $limit = 30): array {
        if ($userId <= 0) return [];
        if (!notifications_ensure_table($conn)) return [];
        $limit = max(1, min(100, $limit));
        $rows = [];
        $hasCategory = notifications_column_exists($conn, 'notifications', 'category');
        $hasActor = notifications_column_exists($conn, 'notifications', 'actor_user_id');
        $hasToast = notifications_column_exists($conn, 'notifications', 'toast_shown');
        $hasProfilePic = notifications_column_exists($conn, 'users', 'profile_picture');
        $hasDefaultAvatar = notifications_column_exists($conn, 'users', 'use_default_avatar');

        $actorProfileExpr = $hasProfilePic ? "u.profile_picture AS actor_profile_picture" : "'' AS actor_profile_picture";
        $actorDefaultExpr = $hasDefaultAvatar ? "u.use_default_avatar AS actor_use_default_avatar" : "1 AS actor_use_default_avatar";
        $sql = "SELECT n.notification_id, n.title, n.message, n.link_url, " .
                       ($hasCategory ? "n.category" : "'' AS category") . ", " .
                       ($hasActor ? "n.actor_user_id" : "NULL AS actor_user_id") . ", " .
                       "n.is_read, " .
                       ($hasToast ? "n.toast_shown" : "1 AS toast_shown") . ", " .
                       "n.created_at, u.full_name AS actor_name, u.email AS actor_email, u.review_type AS actor_review_type, u.school AS actor_school, u.school_other AS actor_school_other, " .
                       $actorProfileExpr . ", " . $actorDefaultExpr . "
                FROM notifications n
                LEFT JOIN users u ON u.user_id = n.actor_user_id
                WHERE n.user_id = ?
                ORDER BY n.is_read ASC, n.created_at DESC
                LIMIT " . $limit;
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return [];
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $rows[] = [
                'id' => (int)$r['notification_id'],
                'title' => (string)$r['title'],
                'message' => (string)$r['message'],
                'link_url' => (string)($r['link_url'] ?? ''),
                'category' => (string)($r['category'] ?? ''),
                'is_read' => (int)($r['is_read'] ?? 0) === 1,
                'toast_shown' => (int)($r['toast_shown'] ?? 0) === 1,
                'actor' => [
                    'user_id' => (int)($r['actor_user_id'] ?? 0),
                    'name' => (string)($r['actor_name'] ?? ''),
                    'email' => (string)($r['actor_email'] ?? ''),
                    'review_type' => (string)($r['actor_review_type'] ?? ''),
                    'school' => ((string)($r['actor_school'] ?? '') === 'Other' && !empty($r['actor_school_other'])) ? (string)$r['actor_school_other'] : (string)($r['actor_school'] ?? ''),
                    'profile_picture' => (string)($r['actor_profile_picture'] ?? ''),
                    'use_default_avatar' => (int)($r['actor_use_default_avatar'] ?? 1) === 1,
                ],
                'time_label' => notifications_time_label((string)($r['created_at'] ?? '')),
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
        mysqli_stmt_close($stmt);
        return $rows;
    }
}

if (!function_exists('notifications_unread_count')) {
    function notifications_unread_count(mysqli $conn, int $userId): int {
        if ($userId <= 0) return 0;
        if (!notifications_ensure_table($conn)) return 0;
        $count = 0;
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            $count = (int)($row['c'] ?? 0);
            mysqli_stmt_close($stmt);
        }
        return $count;
    }
}

if (!function_exists('notifications_mark_read')) {
    function notifications_mark_read(mysqli $conn, int $userId, int $notificationId): bool {
        if ($userId <= 0 || $notificationId <= 0) return false;
        if (!notifications_ensure_table($conn)) return false;
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ? LIMIT 1");
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 'ii', $notificationId, $userId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok ? true : false;
    }
}

if (!function_exists('notifications_mark_all_read')) {
    function notifications_mark_all_read(mysqli $conn, int $userId): bool {
        if ($userId <= 0) return false;
        if (!notifications_ensure_table($conn)) return false;
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok ? true : false;
    }
}

if (!function_exists('notifications_mark_toast_shown')) {
    function notifications_mark_toast_shown(mysqli $conn, int $userId, int $notificationId): bool {
        if ($userId <= 0 || $notificationId <= 0) return false;
        if (!notifications_ensure_table($conn)) return false;
        if (!notifications_column_exists($conn, 'notifications', 'toast_shown')) return true;
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET toast_shown = 1 WHERE notification_id = ? AND user_id = ? LIMIT 1");
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 'ii', $notificationId, $userId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok ? true : false;
    }
}

if (!function_exists('notifications_delete')) {
    function notifications_delete(mysqli $conn, int $userId, int $notificationId): bool {
        if ($userId <= 0 || $notificationId <= 0) return false;
        if (!notifications_ensure_table($conn)) return false;
        $stmt = mysqli_prepare($conn, "DELETE FROM notifications WHERE notification_id = ? AND user_id = ? LIMIT 1");
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 'ii', $notificationId, $userId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok ? true : false;
    }
}
