<?php
declare(strict_types=1);

/**
 * POST handlers for Support hub (admin_support_analytics.php tabs).
 * Expects: $conn (mysqli), verifyCSRFToken, ereview_chat_* helpers, getCurrentUserId.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($token)) {
    header('Location: admin_support_analytics.php?tab=' . urlencode($_POST['hub_tab'] ?? 'overview') . '&err=csrf');
    exit;
}

$hub = (string)($_POST['hub_tab'] ?? '');

if ($hub === 'backlog') {
    $r = @mysqli_query(
        $conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_kb_backlog' LIMIT 1"
    );
    $backlogReady = $r && mysqli_fetch_row($r);
    if ($r) {
        mysqli_free_result($r);
    }
    if ($backlogReady) {
        $bid = (int)($_POST['backlog_id'] ?? 0);
        $status = ereview_chat_safe_trim((string)($_POST['status'] ?? ''), 32);
        $notes = ereview_chat_safe_trim((string)($_POST['notes'] ?? ''), 500);
        $allowed = ['pending', 'reviewed', 'added_to_kb', 'dismissed'];
        if ($bid > 0 && in_array($status, $allowed, true)) {
            $st = mysqli_prepare(
                $conn,
                'UPDATE support_kb_backlog SET status = ?, notes = ?, updated_at = NOW() WHERE backlog_id = ? LIMIT 1'
            );
            mysqli_stmt_bind_param($st, 'ssi', $status, $notes, $bid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
    }
    header('Location: admin_support_analytics.php?tab=backlog&saved=1');
    exit;
}

if ($hub === 'lookup') {
    $email = ereview_chat_safe_trim((string)($_POST['email'] ?? ''), 120);
    $adminId = (int) getCurrentUserId();
    $error = '';
    $result = null;

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $st = mysqli_prepare(
            $conn,
            'SELECT user_id, full_name, email, role, status, access_start, access_end, access_months, created_at FROM users WHERE email = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($st, 's', $email);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $result = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($st);

        @mysqli_query(
            $conn,
            "INSERT IGNORE INTO support_chat_sessions (session_id, source_page, status) VALUES ('system-staff-audit', 'system', 'closed')"
        );
        ereview_chat_log_event($conn, 'system-staff-audit', 'staff_enrollment_lookup', [
            'admin_user_id' => $adminId,
            'email_hash' => hash('sha256', strtolower($email)),
            'found' => $result ? 1 : 0,
        ]);
    }

    $_SESSION['support_lookup_state'] = [
        'result' => $result,
        'error' => $error,
        'email' => $email,
    ];
    header('Location: admin_support_analytics.php?tab=lookup');
    exit;
}

if ($hub === 'kb') {
    $v2 = ereview_chat_v2_ready($conn);
    if (!$v2) {
        header('Location: admin_support_analytics.php?tab=kb');
        exit;
    }

    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save_settings') {
        $banned = ereview_chat_safe_trim((string)($_POST['global_banned_topics'] ?? ''), 20000);
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO support_chat_settings (setting_key, setting_value) VALUES (\'global_banned_topics\', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        mysqli_stmt_bind_param($stmt, 's', $banned);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['support_hub_kb_flash_ok'] = 'Global settings saved.';
        header('Location: admin_support_analytics.php?tab=kb');
        exit;
    }

    if ($act === 'save_article') {
        $aid = (int)($_POST['article_id'] ?? 0);
        $title = ereview_chat_safe_trim((string)($_POST['title'] ?? ''), 200);
        $content = ereview_chat_safe_trim((string)($_POST['content'] ?? ''), 65000);
        $short = ereview_chat_safe_trim((string)($_POST['short_answer'] ?? ''), 10000);
        $keywords = ereview_chat_safe_trim((string)($_POST['keywords'] ?? ''), 500);
        $approved = ereview_chat_safe_trim((string)($_POST['approved_phrases'] ?? ''), 20000);
        $artBan = ereview_chat_safe_trim((string)($_POST['article_banned_topics'] ?? ''), 500);
        $status = in_array(($_POST['status'] ?? 'active'), ['active', 'inactive'], true) ? $_POST['status'] : 'active';
        $markReviewed = !empty($_POST['mark_reviewed']);
        $uid = (int) getCurrentUserId();

        if ($title === '' || $content === '') {
            $_SESSION['support_hub_kb_flash_err'] = 'Title and main content are required.';
            header('Location: admin_support_analytics.php?tab=kb&edit=' . $aid);
            exit;
        }

        if ($aid > 0) {
            $snap = mysqli_prepare(
                $conn,
                'INSERT INTO support_kb_article_versions (article_id, title, content, short_answer, keywords, approved_phrases, article_banned_topics, edited_by_user_id, created_at)
                 SELECT article_id, title, content, short_answer, keywords, approved_phrases, article_banned_topics, ?, NOW() FROM support_kb_articles WHERE article_id = ? LIMIT 1'
            );
            mysqli_stmt_bind_param($snap, 'ii', $uid, $aid);
            mysqli_stmt_execute($snap);
            mysqli_stmt_close($snap);

            if ($markReviewed) {
                $up = mysqli_prepare(
                    $conn,
                    'UPDATE support_kb_articles SET title=?, content=?, short_answer=?, keywords=?, approved_phrases=?, article_banned_topics=?, status=?, last_reviewed_at=NOW(), reviewed_by_user_id=? WHERE article_id=? LIMIT 1'
                );
                mysqli_stmt_bind_param($up, 'sssssssii', $title, $content, $short, $keywords, $approved, $artBan, $status, $uid, $aid);
            } else {
                $up = mysqli_prepare(
                    $conn,
                    'UPDATE support_kb_articles SET title=?, content=?, short_answer=?, keywords=?, approved_phrases=?, article_banned_topics=?, status=? WHERE article_id=? LIMIT 1'
                );
                mysqli_stmt_bind_param($up, 'sssssssi', $title, $content, $short, $keywords, $approved, $artBan, $status, $aid);
            }
            mysqli_stmt_execute($up);
            mysqli_stmt_close($up);
            $_SESSION['support_hub_kb_flash_ok'] = 'Article updated.';
            header('Location: admin_support_analytics.php?tab=kb&edit=' . $aid);
            exit;
        }

        if ($markReviewed) {
            $ins = mysqli_prepare(
                $conn,
                'INSERT INTO support_kb_articles (title, content, short_answer, keywords, approved_phrases, article_banned_topics, status, last_reviewed_at, reviewed_by_user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())'
            );
            mysqli_stmt_bind_param($ins, 'sssssssi', $title, $content, $short, $keywords, $approved, $artBan, $status, $uid);
        } else {
            $ins = mysqli_prepare(
                $conn,
                'INSERT INTO support_kb_articles (title, content, short_answer, keywords, approved_phrases, article_banned_topics, status, last_reviewed_at, reviewed_by_user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, NOW(), NOW())'
            );
            mysqli_stmt_bind_param($ins, 'sssssss', $title, $content, $short, $keywords, $approved, $artBan, $status);
        }
        mysqli_stmt_execute($ins);
        $newId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        if ($newId > 0) {
            $vins = mysqli_prepare(
                $conn,
                'INSERT INTO support_kb_article_versions (article_id, title, content, short_answer, keywords, approved_phrases, article_banned_topics, edited_by_user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            mysqli_stmt_bind_param($vins, 'issssssi', $newId, $title, $content, $short, $keywords, $approved, $artBan, $uid);
            mysqli_stmt_execute($vins);
            mysqli_stmt_close($vins);
        }
        $_SESSION['support_hub_kb_flash_ok'] = 'Article created.';
        header('Location: admin_support_analytics.php?tab=kb&edit=' . $newId);
        exit;
    }

    header('Location: admin_support_analytics.php?tab=kb');
    exit;
}

return;
