<?php
/**
 * My CPA Review - student personal study workspace (ownership-scoped).
 */
declare(strict_types=1);

require_once __DIR__ . '/schema_introspection.php';

function student_cpa_review_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $tables = [
        'student_notes' => "CREATE TABLE IF NOT EXISTS `student_notes` (
          `note_id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `subject_id` INT(11) DEFAULT NULL,
          `lesson_id` INT(11) DEFAULT NULL,
          `question_id` INT(11) DEFAULT NULL,
          `title` VARCHAR(255) NOT NULL DEFAULT '',
          `content` MEDIUMTEXT NOT NULL,
          `tags` VARCHAR(500) DEFAULT NULL,
          `is_starred` TINYINT(1) NOT NULL DEFAULT 0,
          `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`note_id`),
          KEY `idx_sn_user_updated` (`user_id`, `updated_at`),
          KEY `idx_sn_user_subject` (`user_id`, `subject_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        'student_bookmarks' => "CREATE TABLE IF NOT EXISTS `student_bookmarks` (
          `bookmark_id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `item_type` ENUM('lesson','handout','quiz','question','page') NOT NULL,
          `item_id` INT(11) NOT NULL DEFAULT 0,
          `title` VARCHAR(255) NOT NULL DEFAULT '',
          `url` VARCHAR(500) DEFAULT NULL,
          `subject_id` INT(11) DEFAULT NULL,
          `lesson_id` INT(11) DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`bookmark_id`),
          UNIQUE KEY `uq_sb_user_item` (`user_id`, `item_type`, `item_id`),
          KEY `idx_sb_user_created` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        'student_favorites' => "CREATE TABLE IF NOT EXISTS `student_favorites` (
          `favorite_id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `item_type` ENUM('lesson','handout','subject') NOT NULL,
          `item_id` INT(11) NOT NULL DEFAULT 0,
          `title` VARCHAR(255) NOT NULL DEFAULT '',
          `url` VARCHAR(500) DEFAULT NULL,
          `subject_id` INT(11) DEFAULT NULL,
          `lesson_id` INT(11) DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`favorite_id`),
          UNIQUE KEY `uq_sf_user_item` (`user_id`, `item_type`, `item_id`),
          KEY `idx_sf_user_created` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        'student_important_items' => "CREATE TABLE IF NOT EXISTS `student_important_items` (
          `important_id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `item_type` ENUM('lesson','note','quick_review','concept') NOT NULL,
          `item_id` INT(11) NOT NULL DEFAULT 0,
          `title` VARCHAR(255) NOT NULL DEFAULT '',
          `body` TEXT DEFAULT NULL,
          `topic` VARCHAR(255) DEFAULT NULL,
          `subject_id` INT(11) DEFAULT NULL,
          `lesson_id` INT(11) DEFAULT NULL,
          `is_last_minute` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`important_id`),
          UNIQUE KEY `uq_si_user_item` (`user_id`, `item_type`, `item_id`),
          KEY `idx_si_user_created` (`user_id`, `created_at`),
          KEY `idx_si_user_last_minute` (`user_id`, `is_last_minute`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        'student_mistake_notebook' => "CREATE TABLE IF NOT EXISTS `student_mistake_notebook` (
          `mistake_id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `question_id` INT(11) NOT NULL,
          `quiz_id` INT(11) DEFAULT NULL,
          `attempt_id` INT(11) DEFAULT NULL,
          `subject_id` INT(11) DEFAULT NULL,
          `lesson_id` INT(11) DEFAULT NULL,
          `selected_answer` VARCHAR(5) DEFAULT NULL,
          `correct_answer` VARCHAR(5) DEFAULT NULL,
          `explanation_snapshot` TEXT DEFAULT NULL,
          `personal_note` TEXT DEFAULT NULL,
          `is_reviewed` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`mistake_id`),
          UNIQUE KEY `uq_smn_user_q_attempt` (`user_id`, `question_id`, `attempt_id`),
          KEY `idx_smn_user_reviewed` (`user_id`, `is_reviewed`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        'student_quick_review' => "CREATE TABLE IF NOT EXISTS `student_quick_review` (
          `quick_id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `subject_id` INT(11) DEFAULT NULL,
          `lesson_id` INT(11) DEFAULT NULL,
          `title` VARCHAR(255) NOT NULL DEFAULT '',
          `content` TEXT NOT NULL,
          `tags` VARCHAR(500) DEFAULT NULL,
          `is_important` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`quick_id`),
          KEY `idx_sqr_user_updated` (`user_id`, `updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        'student_cpa_activity_log' => "CREATE TABLE IF NOT EXISTS `student_cpa_activity_log` (
          `log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `action` VARCHAR(64) NOT NULL,
          `entity_type` VARCHAR(64) DEFAULT NULL,
          `entity_id` INT(11) DEFAULT NULL,
          `summary` VARCHAR(500) NOT NULL DEFAULT '',
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`log_id`),
          KEY `idx_scal_user_created` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];

    foreach ($tables as $sql) {
        @mysqli_query($conn, $sql);
    }

    // Additive column guards for Important Concepts (idempotent).
    if (ereview_schema_table_exists($conn, 'student_important_items')) {
        $alters = [
            'topic' => "ALTER TABLE `student_important_items` ADD COLUMN `topic` VARCHAR(255) DEFAULT NULL AFTER `body`",
            'is_last_minute' => "ALTER TABLE `student_important_items` ADD COLUMN `is_last_minute` TINYINT(1) NOT NULL DEFAULT 0 AFTER `lesson_id`",
            'updated_at' => "ALTER TABLE `student_important_items` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
        ];
        foreach ($alters as $col => $alterSql) {
            if (!ereview_schema_column_exists_fresh($conn, 'student_important_items', $col)) {
                @mysqli_query($conn, $alterSql);
                ereview_schema_session_forget('c:student_important_items.' . $col);
            }
        }
    }
}

function student_cpa_sanitize_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><h2><h3><a>';
    $clean = strip_tags($html, $allowed);
    // Strip event handlers / javascript: from remaining tags
    $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? $clean;
    $clean = preg_replace('/href\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', 'href="#"', $clean) ?? $clean;
    return $clean;
}

function student_cpa_log_activity(
    mysqli $conn,
    int $userId,
    string $action,
    string $entityType,
    int $entityId,
    string $summary
): void {
    if ($userId <= 0 || $action === '') {
        return;
    }
    student_cpa_review_ensure_schema($conn);
    $summary = mb_substr(trim($summary), 0, 500);
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO student_cpa_activity_log (user_id, action, entity_type, entity_id, summary) VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'issis', $userId, $action, $entityType, $entityId, $summary);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** @return list<array{subject_id:int,subject_name:string}> */
function student_cpa_list_subjects(mysqli $conn): array
{
    $rows = [];
    $res = @mysqli_query($conn, 'SELECT subject_id, subject_name FROM subjects ORDER BY subject_name ASC');
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = ['subject_id' => (int) $r['subject_id'], 'subject_name' => (string) $r['subject_name']];
        }
    }
    return $rows;
}

/** @return list<array{lesson_id:int,title:string,subject_id:int}> */
function student_cpa_list_lessons(mysqli $conn, int $subjectId = 0): array
{
    $rows = [];
    if ($subjectId > 0) {
        $stmt = mysqli_prepare($conn, 'SELECT lesson_id, title, subject_id FROM lessons WHERE subject_id = ? ORDER BY title ASC');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $subjectId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $rows[] = ['lesson_id' => (int) $r['lesson_id'], 'title' => (string) $r['title'], 'subject_id' => (int) $r['subject_id']];
            }
            mysqli_stmt_close($stmt);
        }
        return $rows;
    }
    $res = @mysqli_query($conn, 'SELECT lesson_id, title, subject_id FROM lessons ORDER BY title ASC LIMIT 500');
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = ['lesson_id' => (int) $r['lesson_id'], 'title' => (string) $r['title'], 'subject_id' => (int) $r['subject_id']];
        }
    }
    return $rows;
}

/** @return array<string,int> */
function student_cpa_dashboard_counts(mysqli $conn, int $userId): array
{
    student_cpa_review_ensure_schema($conn);
    $out = [
        'notes' => 0,
        'bookmarks' => 0,
        'important' => 0,
        'mistakes' => 0,
        'mistakes_unreviewed' => 0,
        'favorites' => 0,
        'quick_review' => 0,
        'last_minute' => 0,
    ];
    if ($userId <= 0) {
        return $out;
    }
    $map = [
        'notes' => 'SELECT COUNT(*) AS c FROM student_notes WHERE user_id = ?',
        'bookmarks' => 'SELECT COUNT(*) AS c FROM student_bookmarks WHERE user_id = ?',
        'important' => "SELECT COUNT(*) AS c FROM student_important_items WHERE user_id = ? AND item_type = 'concept'",
        'mistakes' => 'SELECT COUNT(*) AS c FROM student_mistake_notebook WHERE user_id = ?',
        'mistakes_unreviewed' => 'SELECT COUNT(*) AS c FROM student_mistake_notebook WHERE user_id = ? AND is_reviewed = 0',
        'favorites' => 'SELECT COUNT(*) AS c FROM student_favorites WHERE user_id = ?',
        'quick_review' => 'SELECT COUNT(*) AS c FROM student_quick_review WHERE user_id = ?',
        'last_minute' => "SELECT COUNT(*) AS c FROM student_important_items WHERE user_id = ? AND item_type = 'concept' AND is_last_minute = 1",
    ];
    foreach ($map as $key => $sql) {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            continue;
        }
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $out[$key] = (int) ($row['c'] ?? 0);
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function student_cpa_recent_activity(mysqli $conn, int $userId, int $limit = 15): array
{
    student_cpa_review_ensure_schema($conn);
    $limit = max(1, min(50, $limit));
    $stmt = mysqli_prepare(
        $conn,
        'SELECT log_id, action, entity_type, entity_id, summary, created_at
         FROM student_cpa_activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT ?'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $limit);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Weak areas from real quiz answers (subject-level). Empty if insufficient data.
 *
 * @return list<array{subject_id:int,subject_name:string,total:int,correct:int,accuracy:float,label:string}>
 */
function student_cpa_weak_areas(mysqli $conn, int $userId, int $minAnswers = 5): array
{
    if ($userId <= 0 || !ereview_schema_table_exists($conn, 'quiz_answers')) {
        return [];
    }
    $hasAttempt = ereview_schema_column_exists($conn, 'quiz_answers', 'attempt_id');
    if ($hasAttempt && ereview_schema_table_exists($conn, 'quiz_attempts')) {
        $sql = "SELECT s.subject_id, s.subject_name,
                       COUNT(*) AS total,
                       SUM(CASE WHEN qa.is_correct = 1 THEN 1 ELSE 0 END) AS correct
                FROM quiz_answers qa
                INNER JOIN quiz_attempts a ON a.attempt_id = qa.attempt_id AND a.user_id = ?
                INNER JOIN quiz_questions qq ON qq.question_id = qa.question_id
                INNER JOIN quizzes q ON q.quiz_id = qq.quiz_id
                INNER JOIN subjects s ON s.subject_id = q.subject_id
                WHERE a.status = 'submitted'
                GROUP BY s.subject_id, s.subject_name
                HAVING total >= ?
                ORDER BY (correct / total) ASC, total DESC";
    } else {
        $sql = "SELECT s.subject_id, s.subject_name,
                       COUNT(*) AS total,
                       SUM(CASE WHEN qa.is_correct = 1 THEN 1 ELSE 0 END) AS correct
                FROM quiz_answers qa
                INNER JOIN quiz_questions qq ON qq.question_id = qa.question_id
                INNER JOIN quizzes q ON q.quiz_id = qq.quiz_id
                INNER JOIN subjects s ON s.subject_id = q.subject_id
                WHERE qa.user_id = ?
                GROUP BY s.subject_id, s.subject_name
                HAVING total >= ?
                ORDER BY (correct / total) ASC, total DESC";
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $minAnswers);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $total = (int) $r['total'];
        $correct = (int) $r['correct'];
        $acc = $total > 0 ? round(($correct / $total) * 100, 1) : 0.0;
        $rows[] = [
            'subject_id' => (int) $r['subject_id'],
            'subject_name' => (string) $r['subject_name'],
            'total' => $total,
            'correct' => $correct,
            'accuracy' => $acc,
            'label' => $acc < 75 ? 'Needs Review' : 'Good',
        ];
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

// ---------- Notes ----------

/** @return array{rows:list<array<string,mixed>>,total:int} */
function student_cpa_notes_list(mysqli $conn, int $userId, array $filters): array
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0) {
        return ['rows' => [], 'total' => 0];
    }
    $q = trim((string) ($filters['q'] ?? ''));
    $subjectId = (int) ($filters['subject_id'] ?? 0);
    $lessonId = (int) ($filters['lesson_id'] ?? 0);
    $sort = (string) ($filters['sort'] ?? 'updated_desc');
    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
    $where = ['n.user_id = ?'];
    $types = 'i';
    $params = [$userId];
    if ($q !== '') {
        $where[] = '(n.title LIKE ? OR n.content LIKE ? OR n.tags LIKE ?)';
        $types .= 'sss';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($subjectId > 0) {
        $where[] = 'n.subject_id = ?';
        $types .= 'i';
        $params[] = $subjectId;
    }
    if ($lessonId > 0) {
        $where[] = 'n.lesson_id = ?';
        $types .= 'i';
        $params[] = $lessonId;
    }
    $order = match ($sort) {
        'oldest' => 'n.created_at ASC',
        'newest' => 'n.created_at DESC',
        'title' => 'n.title ASC',
        default => 'n.is_pinned DESC, n.is_starred DESC, n.updated_at DESC',
    };
    $whereSql = implode(' AND ', $where);
    $countStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM student_notes n WHERE {$whereSql}");
    if (!$countStmt) {
        return ['rows' => [], 'total' => 0];
    }
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
    mysqli_stmt_execute($countStmt);
    $total = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['c'] ?? 0);
    mysqli_stmt_close($countStmt);

    $offset = ($page - 1) * $perPage;
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $stmt = mysqli_prepare(
        $conn,
        "SELECT n.*, s.subject_name, l.title AS lesson_title
         FROM student_notes n
         LEFT JOIN subjects s ON s.subject_id = n.subject_id
         LEFT JOIN lessons l ON l.lesson_id = n.lesson_id
         WHERE {$whereSql}
         ORDER BY {$order}
         LIMIT ? OFFSET ?"
    );
    if (!$stmt) {
        return ['rows' => [], 'total' => $total];
    }
    mysqli_stmt_bind_param($stmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return ['rows' => $rows, 'total' => $total];
}

function student_cpa_note_get(mysqli $conn, int $userId, int $noteId): ?array
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0 || $noteId <= 0) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT n.*, s.subject_name, l.title AS lesson_title
         FROM student_notes n
         LEFT JOIN subjects s ON s.subject_id = n.subject_id
         LEFT JOIN lessons l ON l.lesson_id = n.lesson_id
         WHERE n.note_id = ? AND n.user_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $noteId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}

function student_cpa_note_save(mysqli $conn, int $userId, array $data): array
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Unauthorized'];
    }
    $noteId = (int) ($data['note_id'] ?? 0);
    $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 255);
    $content = student_cpa_sanitize_html((string) ($data['content'] ?? ''));
    $tags = mb_substr(trim((string) ($data['tags'] ?? '')), 0, 500);
    $subjectId = (int) ($data['subject_id'] ?? 0);
    $lessonId = (int) ($data['lesson_id'] ?? 0);
    $questionId = (int) ($data['question_id'] ?? 0);
    $subjectIdSql = $subjectId > 0 ? $subjectId : null;
    $lessonIdSql = $lessonId > 0 ? $lessonId : null;
    $questionIdSql = $questionId > 0 ? $questionId : null;
    $isStarred = !empty($data['is_starred']) ? 1 : 0;
    $isPinned = !empty($data['is_pinned']) ? 1 : 0;
    if ($title === '' && trim(strip_tags($content)) === '') {
        return ['ok' => false, 'error' => 'Title or content is required.'];
    }
    if ($title === '') {
        $title = 'Untitled note';
    }

    if ($noteId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE student_notes SET title=?, content=?, tags=?,
             subject_id=NULLIF(?,0), lesson_id=NULLIF(?,0), question_id=NULLIF(?,0),
             is_starred=?, is_pinned=? WHERE note_id=? AND user_id=?'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Could not save note.'];
        }
        mysqli_stmt_bind_param(
            $stmt,
            'sssiiiiiii',
            $title,
            $content,
            $tags,
            $subjectId,
            $lessonId,
            $questionId,
            $isStarred,
            $isPinned,
            $noteId,
            $userId
        );
        $ok = mysqli_stmt_execute($stmt);
        $aff = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok || $aff < 0) {
            return ['ok' => false, 'error' => 'Note not found or not saved.'];
        }
        // Notes stay in Notes; Important Concepts are separate concept records.
        student_cpa_log_activity($conn, $userId, 'note_updated', 'note', $noteId, 'Updated note: ' . $title);
        return ['ok' => true, 'note_id' => $noteId];
    }

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO student_notes (user_id, subject_id, lesson_id, question_id, title, content, tags, is_starred, is_pinned)
         VALUES (?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not create note.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'iiiisssii',
        $userId,
        $subjectId,
        $lessonId,
        $questionId,
        $title,
        $content,
        $tags,
        $isStarred,
        $isPinned
    );
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Could not create note.'];
    }
    $newId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    student_cpa_log_activity($conn, $userId, 'note_added', 'note', $newId, 'Added note: ' . $title);
    return ['ok' => true, 'note_id' => $newId];
}

function student_cpa_note_delete(mysqli $conn, int $userId, int $noteId): bool
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0 || $noteId <= 0) {
        return false;
    }
    $stmt = mysqli_prepare($conn, 'DELETE FROM student_notes WHERE note_id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $noteId, $userId);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_stmt_affected_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    if ($ok) {
        student_cpa_log_activity($conn, $userId, 'note_deleted', 'note', $noteId, 'Deleted a note');
    }
    return $ok;
}

// ---------- Bookmarks / Favorites toggles ----------

function student_cpa_bookmark_has(mysqli $conn, int $userId, string $itemType, int $itemId): bool
{
    student_cpa_review_ensure_schema($conn);
    $stmt = mysqli_prepare($conn, 'SELECT bookmark_id FROM student_bookmarks WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'isi', $userId, $itemType, $itemId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (bool) $row;
}

function student_cpa_bookmark_toggle(mysqli $conn, int $userId, array $data): array
{
    student_cpa_review_ensure_schema($conn);
    $itemType = (string) ($data['item_type'] ?? '');
    $itemId = (int) ($data['item_id'] ?? 0);
    $allowed = ['lesson', 'handout', 'quiz', 'question', 'page'];
    if ($userId <= 0 || !in_array($itemType, $allowed, true) || $itemId < 0) {
        return ['ok' => false, 'error' => 'Invalid bookmark.'];
    }
    if (student_cpa_bookmark_has($conn, $userId, $itemType, $itemId)) {
        $stmt = mysqli_prepare($conn, 'DELETE FROM student_bookmarks WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'isi', $userId, $itemType, $itemId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        student_cpa_log_activity($conn, $userId, 'bookmark_removed', $itemType, $itemId, 'Removed bookmark');
        return ['ok' => true, 'bookmarked' => false];
    }
    $title = mb_substr(trim((string) ($data['title'] ?? 'Bookmark')), 0, 255);
    $url = mb_substr(trim((string) ($data['url'] ?? '')), 0, 500);
    $subjectId = (int) ($data['subject_id'] ?? 0);
    $lessonId = (int) ($data['lesson_id'] ?? 0);
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO student_bookmarks (user_id, item_type, item_id, title, url, subject_id, lesson_id)
         VALUES (?, ?, ?, ?, ?, NULLIF(?,0), NULLIF(?,0))'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not bookmark.'];
    }
    mysqli_stmt_bind_param($stmt, 'isissii', $userId, $itemType, $itemId, $title, $url, $subjectId, $lessonId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return ['ok' => false, 'error' => 'Already bookmarked or save failed.'];
    }
    student_cpa_log_activity($conn, $userId, 'bookmark_added', $itemType, $itemId, 'Bookmarked: ' . $title);
    return ['ok' => true, 'bookmarked' => true];
}

function student_cpa_favorite_has(mysqli $conn, int $userId, string $itemType, int $itemId): bool
{
    student_cpa_review_ensure_schema($conn);
    $stmt = mysqli_prepare($conn, 'SELECT favorite_id FROM student_favorites WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'isi', $userId, $itemType, $itemId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (bool) $row;
}

function student_cpa_favorite_toggle(mysqli $conn, int $userId, array $data): array
{
    student_cpa_review_ensure_schema($conn);
    $itemType = (string) ($data['item_type'] ?? '');
    $itemId = (int) ($data['item_id'] ?? 0);
    $allowed = ['lesson', 'handout', 'subject'];
    if ($userId <= 0 || !in_array($itemType, $allowed, true) || $itemId <= 0) {
        return ['ok' => false, 'error' => 'Invalid favorite.'];
    }
    if (student_cpa_favorite_has($conn, $userId, $itemType, $itemId)) {
        $stmt = mysqli_prepare($conn, 'DELETE FROM student_favorites WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'isi', $userId, $itemType, $itemId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        student_cpa_log_activity($conn, $userId, 'favorite_removed', $itemType, $itemId, 'Removed favorite');
        return ['ok' => true, 'favorited' => false];
    }
    $title = mb_substr(trim((string) ($data['title'] ?? 'Favorite')), 0, 255);
    $url = mb_substr(trim((string) ($data['url'] ?? '')), 0, 500);
    $subjectId = (int) ($data['subject_id'] ?? 0);
    $lessonId = (int) ($data['lesson_id'] ?? 0);
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO student_favorites (user_id, item_type, item_id, title, url, subject_id, lesson_id)
         VALUES (?, ?, ?, ?, ?, NULLIF(?,0), NULLIF(?,0))'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not favorite.'];
    }
    mysqli_stmt_bind_param($stmt, 'isissii', $userId, $itemType, $itemId, $title, $url, $subjectId, $lessonId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return ['ok' => false, 'error' => 'Already favorited or save failed.'];
    }
    student_cpa_log_activity($conn, $userId, 'favorite_added', $itemType, $itemId, 'Favorited: ' . $title);
    return ['ok' => true, 'favorited' => true];
}

function student_cpa_important_upsert(
    mysqli $conn,
    int $userId,
    string $itemType,
    int $itemId,
    string $title,
    ?string $body,
    ?int $subjectId,
    ?int $lessonId
): bool {
    student_cpa_review_ensure_schema($conn);
    $allowed = ['lesson', 'note', 'quick_review', 'concept'];
    if ($userId <= 0 || !in_array($itemType, $allowed, true)) {
        return false;
    }
    $title = mb_substr(trim($title), 0, 255);
    $body = $body !== null ? mb_substr(trim($body), 0, 5000) : null;
    $sid = (int) ($subjectId ?? 0);
    $lid = (int) ($lessonId ?? 0);
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO student_important_items (user_id, item_type, item_id, title, body, subject_id, lesson_id)
         VALUES (?, ?, ?, ?, ?, NULLIF(?,0), NULLIF(?,0))
         ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body),
           subject_id = VALUES(subject_id), lesson_id = VALUES(lesson_id)'
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'isissii', $userId, $itemType, $itemId, $title, $body, $sid, $lid);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function student_cpa_important_remove(mysqli $conn, int $userId, string $itemType, int $itemId): bool
{
    student_cpa_review_ensure_schema($conn);
    $stmt = mysqli_prepare($conn, 'DELETE FROM student_important_items WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'isi', $userId, $itemType, $itemId);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_stmt_affected_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $ok;
}

function student_cpa_important_toggle(mysqli $conn, int $userId, array $data): array
{
    // Legacy polymorphic toggle kept for API compat (lesson/quick_review only).
    $itemType = (string) ($data['item_type'] ?? 'concept');
    if ($itemType === 'concept') {
        return student_cpa_concept_save($conn, $userId, $data);
    }
    $itemId = (int) ($data['item_id'] ?? 0);
    $title = trim((string) ($data['title'] ?? ''));
    $body = trim((string) ($data['body'] ?? ''));
    $subjectId = (int) ($data['subject_id'] ?? 0) ?: null;
    $lessonId = (int) ($data['lesson_id'] ?? 0) ?: null;
    $forceAdd = !empty($data['force_add']);

    $stmt = mysqli_prepare($conn, 'SELECT important_id FROM student_important_items WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'isi', $userId, $itemType, $itemId);
        mysqli_stmt_execute($stmt);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($exists && !$forceAdd) {
            student_cpa_important_remove($conn, $userId, $itemType, $itemId);
            return ['ok' => true, 'important' => false];
        }
    }
    if ($title === '') {
        $title = 'Important item';
    }
    $ok = student_cpa_important_upsert($conn, $userId, $itemType, $itemId, $title, $body !== '' ? $body : null, $subjectId, $lessonId);
    return ['ok' => $ok, 'important' => $ok, 'item_id' => $itemId];
}

/**
 * Save Important Concept (own records only — not notes).
 *
 * @return array{ok:bool,error?:string,important_id?:int}
 */
function student_cpa_concept_save(mysqli $conn, int $userId, array $data): array
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Unauthorized'];
    }
    $importantId = (int) ($data['important_id'] ?? 0);
    $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 255);
    $body = mb_substr(trim((string) ($data['body'] ?? '')), 0, 5000);
    $topic = mb_substr(trim((string) ($data['topic'] ?? '')), 0, 255);
    $subjectId = (int) ($data['subject_id'] ?? 0);
    $lessonId = (int) ($data['lesson_id'] ?? 0);
    $isLastMinute = !empty($data['is_last_minute']) ? 1 : 0;
    if ($title === '') {
        return ['ok' => false, 'error' => 'Concept title is required.'];
    }

    if ($importantId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE student_important_items
             SET title=?, body=?, topic=?, subject_id=NULLIF(?,0), lesson_id=NULLIF(?,0), is_last_minute=?
             WHERE important_id=? AND user_id=? AND item_type='concept'"
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Could not update concept.'];
        }
        mysqli_stmt_bind_param(
            $stmt,
            'sssiiiii',
            $title,
            $body,
            $topic,
            $subjectId,
            $lessonId,
            $isLastMinute,
            $importantId,
            $userId
        );
        $ok = mysqli_stmt_execute($stmt);
        $aff = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok || $aff < 0) {
            return ['ok' => false, 'error' => 'Concept not found.'];
        }
        // If only same values, affected may be 0 — verify ownership.
        $check = student_cpa_concept_get($conn, $userId, $importantId);
        if (!$check) {
            return ['ok' => false, 'error' => 'Concept not found.'];
        }
        student_cpa_log_activity($conn, $userId, 'concept_updated', 'concept', $importantId, 'Updated concept: ' . $title);
        return ['ok' => true, 'important_id' => $importantId];
    }

    // Unique (user_id, item_type, item_id): use temporary unique item_id, then sync to important_id.
    $tempItemId = (int) (microtime(true) * 1000) % 2000000000;
    if ($tempItemId <= 0) {
        $tempItemId = random_int(1, 1999999999);
    }
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO student_important_items
          (user_id, item_type, item_id, title, body, topic, subject_id, lesson_id, is_last_minute)
         VALUES (?, 'concept', ?, ?, ?, ?, NULLIF(?,0), NULLIF(?,0), ?)"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not create concept.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'iisssiii',
        $userId,
        $tempItemId,
        $title,
        $body,
        $topic,
        $subjectId,
        $lessonId,
        $isLastMinute
    );
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Could not create concept.'];
    }
    $newId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    $sync = mysqli_prepare(
        $conn,
        "UPDATE student_important_items SET item_id = important_id WHERE important_id = ? AND user_id = ?"
    );
    if ($sync) {
        mysqli_stmt_bind_param($sync, 'ii', $newId, $userId);
        mysqli_stmt_execute($sync);
        mysqli_stmt_close($sync);
    }
    student_cpa_log_activity($conn, $userId, 'concept_added', 'concept', $newId, 'Added concept: ' . $title);
    return ['ok' => true, 'important_id' => $newId];
}

function student_cpa_concept_get(mysqli $conn, int $userId, int $importantId): ?array
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0 || $importantId <= 0) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT i.*, s.subject_name, l.title AS lesson_title
         FROM student_important_items i
         LEFT JOIN subjects s ON s.subject_id = i.subject_id
         LEFT JOIN lessons l ON l.lesson_id = i.lesson_id
         WHERE i.important_id = ? AND i.user_id = ? AND i.item_type = 'concept' LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $importantId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}

function student_cpa_concept_delete(mysqli $conn, int $userId, int $importantId): bool
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0 || $importantId <= 0) {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM student_important_items WHERE important_id = ? AND user_id = ? AND item_type = 'concept' LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $importantId, $userId);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_stmt_affected_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    if ($ok) {
        student_cpa_log_activity($conn, $userId, 'concept_deleted', 'concept', $importantId, 'Removed an important concept');
    }
    return $ok;
}

function student_cpa_concept_set_last_minute(mysqli $conn, int $userId, int $importantId, bool $on): array
{
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE student_important_items SET is_last_minute = ?
         WHERE important_id = ? AND user_id = ? AND item_type = 'concept'"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Update failed.'];
    }
    $flag = $on ? 1 : 0;
    mysqli_stmt_bind_param($stmt, 'iii', $flag, $importantId, $userId);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_stmt_affected_rows($stmt) >= 0 && student_cpa_concept_get($conn, $userId, $importantId) !== null;
    mysqli_stmt_close($stmt);
    return ['ok' => $ok, 'is_last_minute' => $ok ? $flag : null];
}

/** @return array{rows:list<array<string,mixed>>,total:int} */
function student_cpa_concepts_list(mysqli $conn, int $userId, array $filters): array
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0) {
        return ['rows' => [], 'total' => 0];
    }
    $q = trim((string) ($filters['q'] ?? ''));
    $subjectId = (int) ($filters['subject_id'] ?? 0);
    $lastMinuteOnly = !empty($filters['last_minute_only']);
    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
    $where = ["i.user_id = ?", "i.item_type = 'concept'"];
    $types = 'i';
    $params = [$userId];
    if ($subjectId > 0) {
        $where[] = 'i.subject_id = ?';
        $types .= 'i';
        $params[] = $subjectId;
    }
    if ($lastMinuteOnly) {
        $where[] = 'i.is_last_minute = 1';
    }
    if ($q !== '') {
        $where[] = '(i.title LIKE ? OR i.body LIKE ? OR i.topic LIKE ?)';
        $types .= 'sss';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $whereSql = implode(' AND ', $where);
    $cstmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM student_important_items i WHERE {$whereSql}");
    if (!$cstmt) {
        return ['rows' => [], 'total' => 0];
    }
    mysqli_stmt_bind_param($cstmt, $types, ...$params);
    mysqli_stmt_execute($cstmt);
    $total = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($cstmt))['c'] ?? 0);
    mysqli_stmt_close($cstmt);

    $offset = ($page - 1) * $perPage;
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $orderCol = ereview_schema_column_exists($conn, 'student_important_items', 'updated_at')
        ? 'i.is_last_minute DESC, i.updated_at DESC'
        : 'i.is_last_minute DESC, i.created_at DESC';
    $stmt = mysqli_prepare(
        $conn,
        "SELECT i.*, s.subject_name, l.title AS lesson_title
         FROM student_important_items i
         LEFT JOIN subjects s ON s.subject_id = i.subject_id
         LEFT JOIN lessons l ON l.lesson_id = i.lesson_id
         WHERE {$whereSql}
         ORDER BY {$orderCol}
         LIMIT ? OFFSET ?"
    );
    if (!$stmt) {
        return ['rows' => [], 'total' => $total];
    }
    mysqli_stmt_bind_param($stmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return ['rows' => $rows, 'total' => $total];
}

/** @return list<array{subject_id:int,subject_name:string,concepts:list<array<string,mixed>>}> */
function student_cpa_last_minute_grouped(mysqli $conn, int $userId): array
{
    $list = student_cpa_concepts_list($conn, $userId, ['last_minute_only' => 1, 'per_page' => 100, 'page' => 1]);
    $groups = [];
    foreach ($list['rows'] as $row) {
        $sid = (int) ($row['subject_id'] ?? 0);
        $name = (string) ($row['subject_name'] ?? 'General');
        if ($sid <= 0) {
            $name = 'General';
        }
        if (!isset($groups[$sid])) {
            $groups[$sid] = ['subject_id' => $sid, 'subject_name' => $name, 'concepts' => []];
        }
        $groups[$sid]['concepts'][] = $row;
    }
    return array_values($groups);
}

/**
 * Latest real study activity for "Continue Your Review".
 * Prefers activity log; falls back to newest note. Skips empty/placeholder titles.
 *
 * @return array{
 *   subject_id:?int,lesson_id:?int,note_id:?int,subject_name:string,topic:string,
 *   activity_label:string,detail:string,created_at:?string
 * }|null
 */
function student_cpa_continue_review(mysqli $conn, int $userId): ?array
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0) {
        return null;
    }

    $isPlaceholder = static function (string $title): bool {
        $t = strtolower(trim($title));
        if ($t === '' || $t === 'untitled note' || $t === 'untitled') {
            return true;
        }
        return (bool) preg_match('/\b(sample|smoke|demo|test data)\b/i', $t);
    };

    $buildFromNote = static function (array $n) use ($isPlaceholder): ?array {
        $title = trim((string) ($n['title'] ?? ''));
        if ($isPlaceholder($title) && trim(strip_tags((string) ($n['content'] ?? ''))) === '') {
            return null;
        }
        $subject = trim((string) ($n['subject_name'] ?? ''));
        $lesson = trim((string) ($n['lesson_title'] ?? ''));
        return [
            'subject_id' => !empty($n['subject_id']) ? (int) $n['subject_id'] : null,
            'lesson_id' => !empty($n['lesson_id']) ? (int) $n['lesson_id'] : null,
            'note_id' => (int) ($n['note_id'] ?? 0) ?: null,
            'subject_name' => $subject !== '' ? $subject : 'Your notes',
            'topic' => $lesson,
            'activity_label' => 'Added a note',
            'detail' => $title !== '' && !$isPlaceholder($title) ? $title : 'Open your latest note to continue.',
            'created_at' => $n['updated_at'] ?? $n['created_at'] ?? null,
        ];
    };

    // 1) Newest meaningful activity log row
    $act = student_cpa_recent_activity($conn, $userId, 8);
    foreach ($act as $a) {
        $action = (string) ($a['action'] ?? '');
        $entityType = (string) ($a['entity_type'] ?? '');
        $entityId = (int) ($a['entity_id'] ?? 0);
        $summary = trim((string) ($a['summary'] ?? ''));
        if ($isPlaceholder($summary)) {
            continue;
        }

        if (($entityType === 'note' || str_contains($action, 'note')) && $entityId > 0) {
            $note = student_cpa_note_get($conn, $userId, $entityId);
            if ($note) {
                $built = $buildFromNote($note);
                if ($built) {
                    if (str_contains($action, 'updated')) {
                        $built['activity_label'] = 'Updated a note';
                    }
                    return $built;
                }
            }
        }

        if ($entityType === 'concept' || str_contains($action, 'concept')) {
            $concept = $entityId > 0 ? student_cpa_concept_get($conn, $userId, $entityId) : null;
            if ($concept) {
                return [
                    'subject_id' => !empty($concept['subject_id']) ? (int) $concept['subject_id'] : null,
                    'lesson_id' => !empty($concept['lesson_id']) ? (int) $concept['lesson_id'] : null,
                    'note_id' => null,
                    'important_id' => (int) $concept['important_id'],
                    'subject_name' => trim((string) ($concept['subject_name'] ?? '')) ?: 'Important concepts',
                    'topic' => trim((string) ($concept['topic'] ?? '')),
                    'activity_label' => str_contains($action, 'updated') ? 'Updated an important concept' : 'Saved an important concept',
                    'detail' => (string) ($concept['title'] ?? $summary),
                    'created_at' => $a['created_at'] ?? null,
                ];
            }
        }

        if (str_contains($action, 'bookmark')) {
            return [
                'subject_id' => null,
                'lesson_id' => null,
                'note_id' => null,
                'subject_name' => 'Bookmarks',
                'topic' => '',
                'activity_label' => str_contains($action, 'removed') ? 'Updated bookmarks' : 'Bookmarked a resource',
                'detail' => $summary !== '' ? $summary : 'Continue from your saved bookmarks.',
                'created_at' => $a['created_at'] ?? null,
                'bookmarks' => true,
            ];
        }

        if (str_contains($action, 'mistake')) {
            return [
                'subject_id' => null,
                'lesson_id' => null,
                'note_id' => null,
                'subject_name' => 'Mistake Notebook',
                'topic' => '',
                'activity_label' => 'Added a mistake to review',
                'detail' => $summary !== '' ? $summary : 'Review your unreviewed mistakes.',
                'created_at' => $a['created_at'] ?? null,
                'mistakes' => true,
            ];
        }
    }

    // 2) Fallback: newest non-placeholder note
    $stmt = mysqli_prepare(
        $conn,
        'SELECT n.note_id, n.title, n.content, n.subject_id, n.lesson_id, n.updated_at, n.created_at,
                s.subject_name, l.title AS lesson_title
         FROM student_notes n
         LEFT JOIN subjects s ON s.subject_id = n.subject_id
         LEFT JOIN lessons l ON l.lesson_id = n.lesson_id
         WHERE n.user_id = ?
         ORDER BY n.updated_at DESC
         LIMIT 12'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($n = mysqli_fetch_assoc($res))) {
        $built = $buildFromNote($n);
        if ($built) {
            mysqli_stmt_close($stmt);
            return $built;
        }
    }
    mysqli_stmt_close($stmt);
    return null;
}

/**
 * Overall progress % for dashboard card from real quiz/video subject stats.
 * Null when the student has no measurable progress yet.
 */
function student_cpa_overall_progress_pct(mysqli $conn, int $userId): ?float
{
    $rows = student_cpa_progress_by_subject($conn, $userId);
    if ($rows === []) {
        return null;
    }
    $scores = [];
    foreach ($rows as $r) {
        if ($r['quiz_accuracy'] !== null) {
            $scores[] = (float) $r['quiz_accuracy'];
        } elseif ($r['video_pct'] !== null) {
            $scores[] = (float) $r['video_pct'];
        }
    }
    if ($scores === []) {
        return null;
    }
    return round(array_sum($scores) / count($scores), 0);
}

/**
 * Focus list: subjects with lower quiz accuracy and/or unreviewed mistakes.
 *
 * @return list<array{subject_id:int,subject_name:string,accuracy:?float,unreviewed:int,total_mistakes:int,label:string}>
 */
function student_cpa_your_focus(mysqli $conn, int $userId, int $limit = 5): array
{
    $weak = student_cpa_weak_areas($conn, $userId, 3);
    $mistakes = student_cpa_mistakes_by_subject($conn, $userId);
    $mistakeMap = [];
    foreach ($mistakes as $m) {
        $mistakeMap[(int) $m['subject_id']] = $m;
    }

    $focus = [];
    $seen = [];
    foreach ($weak as $w) {
        $sid = (int) $w['subject_id'];
        if (($w['accuracy'] ?? 100) >= 75 && empty($mistakeMap[$sid]['unreviewed'])) {
            continue;
        }
        $seen[$sid] = true;
        $unrev = (int) ($mistakeMap[$sid]['unreviewed'] ?? 0);
        $focus[] = [
            'subject_id' => $sid,
            'subject_name' => (string) $w['subject_name'],
            'accuracy' => (float) $w['accuracy'],
            'unreviewed' => $unrev,
            'total_mistakes' => (int) ($mistakeMap[$sid]['total'] ?? 0),
            'label' => (string) $w['label'],
        ];
    }
    foreach ($mistakes as $m) {
        $sid = (int) $m['subject_id'];
        if ($sid <= 0 || isset($seen[$sid]) || (int) $m['unreviewed'] <= 0) {
            continue;
        }
        $focus[] = [
            'subject_id' => $sid,
            'subject_name' => (string) $m['subject_name'],
            'accuracy' => null,
            'unreviewed' => (int) $m['unreviewed'],
            'total_mistakes' => (int) $m['total'],
            'label' => 'Needs Review',
        ];
        $seen[$sid] = true;
    }

    usort($focus, static function (array $a, array $b): int {
        $au = (int) $a['unreviewed'];
        $bu = (int) $b['unreviewed'];
        if ($au !== $bu) {
            return $bu <=> $au;
        }
        $aa = $a['accuracy'] ?? 100.0;
        $ba = $b['accuracy'] ?? 100.0;
        return $aa <=> $ba;
    });

    return array_slice($focus, 0, max(1, $limit));
}

/**
 * Pre-exam pack: pinned concepts + starred notes + unreviewed mistakes + important quick cards.
 *
 * @return array{concepts:list,notes:list,mistakes:list,quick:list,total:int}
 */
function student_cpa_last_minute_pack(mysqli $conn, int $userId): array
{
    student_cpa_review_ensure_schema($conn);
    $concepts = student_cpa_concepts_list($conn, $userId, ['last_minute_only' => 1, 'per_page' => 50, 'page' => 1])['rows'];

    $notes = [];
    $nstmt = mysqli_prepare(
        $conn,
        'SELECT n.note_id, n.title, n.subject_id, n.lesson_id, n.updated_at, s.subject_name, l.title AS lesson_title
         FROM student_notes n
         LEFT JOIN subjects s ON s.subject_id = n.subject_id
         LEFT JOIN lessons l ON l.lesson_id = n.lesson_id
         WHERE n.user_id = ? AND n.is_starred = 1
         ORDER BY n.updated_at DESC LIMIT 30'
    );
    if ($nstmt) {
        mysqli_stmt_bind_param($nstmt, 'i', $userId);
        mysqli_stmt_execute($nstmt);
        $res = mysqli_stmt_get_result($nstmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $notes[] = $r;
        }
        mysqli_stmt_close($nstmt);
    }

    $mistakes = student_cpa_mistakes_list($conn, $userId, ['reviewed' => 'no', 'per_page' => 20, 'page' => 1])['rows'];

    $quick = [];
    $qstmt = mysqli_prepare(
        $conn,
        'SELECT q.quick_id, q.title, q.content, q.subject_id, q.updated_at, s.subject_name
         FROM student_quick_review q
         LEFT JOIN subjects s ON s.subject_id = q.subject_id
         WHERE q.user_id = ? AND q.is_important = 1
         ORDER BY q.updated_at DESC LIMIT 30'
    );
    if ($qstmt) {
        mysqli_stmt_bind_param($qstmt, 'i', $userId);
        mysqli_stmt_execute($qstmt);
        $res = mysqli_stmt_get_result($qstmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $quick[] = $r;
        }
        mysqli_stmt_close($qstmt);
    }

    return [
        'concepts' => $concepts,
        'notes' => $notes,
        'mistakes' => $mistakes,
        'quick' => $quick,
        'total' => count($concepts) + count($notes) + count($mistakes) + count($quick),
    ];
}

/** @return list<array{subject_id:int,subject_name:string,total:int,unreviewed:int}> */
function student_cpa_mistakes_by_subject(mysqli $conn, int $userId): array
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0) {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COALESCE(m.subject_id, 0) AS subject_id,
                COALESCE(s.subject_name, \'Unassigned\') AS subject_name,
                COUNT(*) AS total,
                SUM(CASE WHEN m.is_reviewed = 0 THEN 1 ELSE 0 END) AS unreviewed
         FROM student_mistake_notebook m
         LEFT JOIN subjects s ON s.subject_id = m.subject_id
         WHERE m.user_id = ?
         GROUP BY COALESCE(m.subject_id, 0), COALESCE(s.subject_name, \'Unassigned\')
         ORDER BY total DESC'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = [
            'subject_id' => (int) $r['subject_id'],
            'subject_name' => (string) $r['subject_name'],
            'total' => (int) $r['total'],
            'unreviewed' => (int) $r['unreviewed'],
        ];
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

// ---------- Mistakes ----------

function student_cpa_mistake_add(mysqli $conn, int $userId, array $data): array
{
    student_cpa_review_ensure_schema($conn);
    $questionId = (int) ($data['question_id'] ?? 0);
    $attemptKey = (int) ($data['attempt_id'] ?? 0);
    if ($userId <= 0 || $questionId <= 0) {
        return ['ok' => false, 'error' => 'Invalid question.'];
    }
    $quizId = (int) ($data['quiz_id'] ?? 0);
    $subjectId = (int) ($data['subject_id'] ?? 0);
    $lessonId = (int) ($data['lesson_id'] ?? 0);
    $selected = mb_substr(trim((string) ($data['selected_answer'] ?? '')), 0, 5);
    $correct = mb_substr(trim((string) ($data['correct_answer'] ?? '')), 0, 5);
    $explanation = trim((string) ($data['explanation'] ?? ''));
    $personal = trim((string) ($data['personal_note'] ?? ''));

    // Resolve quiz/subject from question if missing
    if ($quizId <= 0 || $subjectId <= 0 || $correct === '' || $explanation === '') {
        $hasExpl = ereview_schema_column_exists($conn, 'quiz_questions', 'explanation');
        $explSel = $hasExpl ? 'qq.explanation' : "'' AS explanation";
        $stmt = mysqli_prepare(
            $conn,
            "SELECT qq.quiz_id, q.subject_id, qq.correct_answer, {$explSel}
             FROM quiz_questions qq INNER JOIN quizzes q ON q.quiz_id = qq.quiz_id
             WHERE qq.question_id = ? LIMIT 1"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $questionId);
            mysqli_stmt_execute($stmt);
            $qr = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($qr) {
                if ($quizId <= 0) {
                    $quizId = (int) $qr['quiz_id'];
                }
                if ($subjectId <= 0) {
                    $subjectId = (int) $qr['subject_id'];
                }
                if ($correct === '') {
                    $correct = (string) ($qr['correct_answer'] ?? '');
                }
                if ($explanation === '') {
                    $explanation = (string) ($qr['explanation'] ?? '');
                }
            }
        }
    }

    // Store attempt_id=0 when unknown so UNIQUE(user_id, question_id, attempt_id) works (NULL is not unique in MySQL).
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO student_mistake_notebook
          (user_id, question_id, quiz_id, attempt_id, subject_id, lesson_id, selected_answer, correct_answer, explanation_snapshot, personal_note)
         VALUES (?, ?, NULLIF(?,0), ?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE selected_answer = VALUES(selected_answer), correct_answer = VALUES(correct_answer),
           explanation_snapshot = VALUES(explanation_snapshot),
           personal_note = IF(VALUES(personal_note) <> \'\', VALUES(personal_note), personal_note),
           updated_at = NOW()'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not save mistake.'];
    }
    mysqli_stmt_bind_param(
        $stmt,
        'iiiiisssss',
        $userId,
        $questionId,
        $quizId,
        $attemptKey,
        $subjectId,
        $lessonId,
        $selected,
        $correct,
        $explanation,
        $personal
    );
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return ['ok' => false, 'error' => 'Could not save mistake.'];
    }
    student_cpa_log_activity($conn, $userId, 'mistake_added', 'mistake', $questionId, 'Added question to Mistake Notebook');
    return ['ok' => true];
}

function student_cpa_mistake_update(mysqli $conn, int $userId, int $mistakeId, array $data): array
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0 || $mistakeId <= 0) {
        return ['ok' => false, 'error' => 'Not found.'];
    }
    $personal = trim((string) ($data['personal_note'] ?? ''));
    $reviewed = array_key_exists('is_reviewed', $data) ? (!empty($data['is_reviewed']) ? 1 : 0) : null;
    if ($reviewed !== null) {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE student_mistake_notebook SET personal_note = ?, is_reviewed = ? WHERE mistake_id = ? AND user_id = ?'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Update failed.'];
        }
        mysqli_stmt_bind_param($stmt, 'siii', $personal, $reviewed, $mistakeId, $userId);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE student_mistake_notebook SET personal_note = ? WHERE mistake_id = ? AND user_id = ?'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Update failed.'];
        }
        mysqli_stmt_bind_param($stmt, 'sii', $personal, $mistakeId, $userId);
    }
    mysqli_stmt_execute($stmt);
    $ok = mysqli_stmt_affected_rows($stmt) >= 0;
    mysqli_stmt_close($stmt);
    return ['ok' => $ok];
}

function student_cpa_mistake_delete(mysqli $conn, int $userId, int $mistakeId): bool
{
    $stmt = mysqli_prepare($conn, 'DELETE FROM student_mistake_notebook WHERE mistake_id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $mistakeId, $userId);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_stmt_affected_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $ok;
}

/** @return array{rows:list<array<string,mixed>>,total:int} */
function student_cpa_mistakes_list(mysqli $conn, int $userId, array $filters): array
{
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0) {
        return ['rows' => [], 'total' => 0];
    }
    $q = trim((string) ($filters['q'] ?? ''));
    $subjectId = (int) ($filters['subject_id'] ?? 0);
    $reviewed = (string) ($filters['reviewed'] ?? 'all');
    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
    $where = ['m.user_id = ?'];
    $types = 'i';
    $params = [$userId];
    if ($subjectId > 0) {
        $where[] = 'm.subject_id = ?';
        $types .= 'i';
        $params[] = $subjectId;
    }
    if ($reviewed === 'yes') {
        $where[] = 'm.is_reviewed = 1';
    } elseif ($reviewed === 'no') {
        $where[] = 'm.is_reviewed = 0';
    }
    if ($q !== '') {
        $where[] = '(m.personal_note LIKE ? OR m.explanation_snapshot LIKE ? OR qq.question_text LIKE ?)';
        $types .= 'sss';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $whereSql = implode(' AND ', $where);
    $countSql = "SELECT COUNT(*) AS c FROM student_mistake_notebook m
                 LEFT JOIN quiz_questions qq ON qq.question_id = m.question_id
                 WHERE {$whereSql}";
    $cstmt = mysqli_prepare($conn, $countSql);
    if (!$cstmt) {
        return ['rows' => [], 'total' => 0];
    }
    mysqli_stmt_bind_param($cstmt, $types, ...$params);
    mysqli_stmt_execute($cstmt);
    $total = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($cstmt))['c'] ?? 0);
    mysqli_stmt_close($cstmt);

    $offset = ($page - 1) * $perPage;
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $stmt = mysqli_prepare(
        $conn,
        "SELECT m.*, s.subject_name, q.title AS quiz_title,
                LEFT(qq.question_text, 240) AS question_preview
         FROM student_mistake_notebook m
         LEFT JOIN subjects s ON s.subject_id = m.subject_id
         LEFT JOIN quizzes q ON q.quiz_id = m.quiz_id
         LEFT JOIN quiz_questions qq ON qq.question_id = m.question_id
         WHERE {$whereSql}
         ORDER BY m.is_reviewed ASC, m.updated_at DESC
         LIMIT ? OFFSET ?"
    );
    if (!$stmt) {
        return ['rows' => [], 'total' => $total];
    }
    mysqli_stmt_bind_param($stmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return ['rows' => $rows, 'total' => $total];
}

function student_cpa_mistake_get(mysqli $conn, int $userId, int $mistakeId): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT m.*, s.subject_name, q.title AS quiz_title, qq.question_text, qq.choice_a, qq.choice_b, qq.choice_c, qq.choice_d
         FROM student_mistake_notebook m
         LEFT JOIN subjects s ON s.subject_id = m.subject_id
         LEFT JOIN quizzes q ON q.quiz_id = m.quiz_id
         LEFT JOIN quiz_questions qq ON qq.question_id = m.question_id
         WHERE m.mistake_id = ? AND m.user_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $mistakeId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}

// ---------- Quick review ----------

function student_cpa_quick_save(mysqli $conn, int $userId, array $data): array
{
    student_cpa_review_ensure_schema($conn);
    $quickId = (int) ($data['quick_id'] ?? 0);
    $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 255);
    $content = trim((string) ($data['content'] ?? ''));
    $tags = mb_substr(trim((string) ($data['tags'] ?? '')), 0, 500);
    $subjectId = (int) ($data['subject_id'] ?? 0);
    $lessonId = (int) ($data['lesson_id'] ?? 0);
    $isImportant = !empty($data['is_important']) ? 1 : 0;
    if ($title === '' || $content === '') {
        return ['ok' => false, 'error' => 'Title and content are required.'];
    }
    $contentPlain = mb_substr(trim((string) ($data['content'] ?? '')), 0, 20000);
    $subjectIdSql = $subjectId > 0 ? $subjectId : null;
    $lessonIdSql = $lessonId > 0 ? $lessonId : null;

    if ($quickId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE student_quick_review SET title=?, content=?, tags=?, subject_id=NULLIF(?,0), lesson_id=NULLIF(?,0), is_important=?
             WHERE quick_id=? AND user_id=?'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Save failed.'];
        }
        mysqli_stmt_bind_param($stmt, 'sssiiiii', $title, $contentPlain, $tags, $subjectId, $lessonId, $isImportant, $quickId, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        student_cpa_log_activity($conn, $userId, 'quick_updated', 'quick_review', $quickId, 'Updated quick review: ' . $title);
        return ['ok' => true, 'quick_id' => $quickId];
    }

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO student_quick_review (user_id, subject_id, lesson_id, title, content, tags, is_important)
         VALUES (?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Create failed.'];
    }
    mysqli_stmt_bind_param($stmt, 'iiisssi', $userId, $subjectId, $lessonId, $title, $contentPlain, $tags, $isImportant);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Create failed.'];
    }
    $newId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    student_cpa_log_activity($conn, $userId, 'quick_added', 'quick_review', $newId, 'Created quick review: ' . $title);
    return ['ok' => true, 'quick_id' => $newId];
}

function student_cpa_quick_delete(mysqli $conn, int $userId, int $quickId): bool
{
    $stmt = mysqli_prepare($conn, 'DELETE FROM student_quick_review WHERE quick_id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $quickId, $userId);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_stmt_affected_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $ok;
}

/** @return array{rows:list<array<string,mixed>>,total:int} */
function student_cpa_generic_list(
    mysqli $conn,
    int $userId,
    string $table,
    string $idCol,
    array $filters,
    string $searchCols
): array {
    student_cpa_review_ensure_schema($conn);
    if ($userId <= 0 || !ereview_schema_table_exists($conn, $table)) {
        return ['rows' => [], 'total' => 0];
    }
    $q = trim((string) ($filters['q'] ?? ''));
    $subjectId = (int) ($filters['subject_id'] ?? 0);
    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
    $where = ['t.user_id = ?'];
    $types = 'i';
    $params = [$userId];
    if ($subjectId > 0) {
        $where[] = 't.subject_id = ?';
        $types .= 'i';
        $params[] = $subjectId;
    }
    if ($q !== '') {
        $parts = [];
        foreach (explode(',', $searchCols) as $col) {
            $col = trim($col);
            if ($col !== '') {
                $parts[] = "t.{$col} LIKE ?";
                $types .= 's';
                $params[] = '%' . $q . '%';
            }
        }
        if ($parts) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }
    $whereSql = implode(' AND ', $where);
    $cstmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM `{$table}` t WHERE {$whereSql}");
    if (!$cstmt) {
        return ['rows' => [], 'total' => 0];
    }
    mysqli_stmt_bind_param($cstmt, $types, ...$params);
    mysqli_stmt_execute($cstmt);
    $total = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($cstmt))['c'] ?? 0);
    mysqli_stmt_close($cstmt);

    $offset = ($page - 1) * $perPage;
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $orderCol = ereview_schema_column_exists($conn, $table, 'updated_at') ? 't.updated_at' : 't.created_at';
    $stmt = mysqli_prepare(
        $conn,
        "SELECT t.*, s.subject_name, l.title AS lesson_title
         FROM `{$table}` t
         LEFT JOIN subjects s ON s.subject_id = t.subject_id
         LEFT JOIN lessons l ON l.lesson_id = t.lesson_id
         WHERE {$whereSql}
         ORDER BY {$orderCol} DESC
         LIMIT ? OFFSET ?"
    );
    if (!$stmt) {
        return ['rows' => [], 'total' => $total];
    }
    mysqli_stmt_bind_param($stmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return ['rows' => $rows, 'total' => $total];
}

/**
 * Progress by subject: video completion + quiz accuracy.
 *
 * @return list<array<string,mixed>>
 */
function student_cpa_progress_by_subject(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    require_once __DIR__ . '/student_activity.php';
    student_activity_ensure_schema($conn);
    $subjects = student_cpa_list_subjects($conn);
    $weak = [];
    foreach (student_cpa_weak_areas($conn, $userId, 1) as $w) {
        $weak[(int) $w['subject_id']] = $w;
    }
    $out = [];
    foreach ($subjects as $s) {
        $sid = (int) $s['subject_id'];
        $videoPct = null;
        $videoDone = 0;
        $videoTotal = 0;
        if (ereview_schema_table_exists($conn, 'lesson_videos') && ereview_schema_table_exists($conn, 'student_video_progress')) {
            $vres = mysqli_query(
                $conn,
                'SELECT COUNT(*) AS c FROM lesson_videos v
                 INNER JOIN lessons l ON l.lesson_id = v.lesson_id WHERE l.subject_id = ' . $sid
            );
            $videoTotal = $vres ? (int) (mysqli_fetch_assoc($vres)['c'] ?? 0) : 0;
            if ($videoTotal > 0) {
                $stmt = mysqli_prepare(
                    $conn,
                    'SELECT COUNT(*) AS c FROM student_video_progress vp
                     INNER JOIN lesson_videos v ON v.video_id = vp.video_id
                     INNER JOIN lessons l ON l.lesson_id = v.lesson_id
                     WHERE vp.user_id = ? AND l.subject_id = ? AND (vp.completed = 1 OR vp.percent >= 95)'
                );
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'ii', $userId, $sid);
                    mysqli_stmt_execute($stmt);
                    $videoDone = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'] ?? 0);
                    mysqli_stmt_close($stmt);
                }
                $videoPct = round(($videoDone / $videoTotal) * 100, 1);
            }
        }
        $quizAcc = $weak[$sid]['accuracy'] ?? null;
        $quizTotal = $weak[$sid]['total'] ?? 0;
        if ($videoPct === null && $quizAcc === null) {
            continue;
        }
        $out[] = [
            'subject_id' => $sid,
            'subject_name' => $s['subject_name'],
            'video_pct' => $videoPct,
            'video_done' => $videoDone,
            'video_total' => $videoTotal,
            'quiz_accuracy' => $quizAcc,
            'quiz_answers' => $quizTotal,
            'quiz_label' => $weak[$sid]['label'] ?? null,
        ];
    }
    return $out;
}
