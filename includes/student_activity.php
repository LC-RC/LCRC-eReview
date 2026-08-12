<?php
/**
 * LMS reviewee activity monitoring: content events, video progress, live sessions.
 *
 * Scope: role=student (eReview LMS) only.
 * Out of scope: college_student / professor college exams (separate product surface;
 * shared login page only — do not mix college_exam_* telemetry here).
 */
declare(strict_types=1);

require_once __DIR__ . '/schema_introspection.php';

/** True only for LMS reviewee students — never college_student / staff. */
function student_activity_is_lms_student(?int $userId = null): bool
{
    if (!function_exists('getCurrentUserRole') || getCurrentUserRole() !== 'student') {
        return false;
    }
    if ($userId !== null && $userId > 0 && function_exists('getCurrentUserId')) {
        $current = (int) getCurrentUserId();
        if ($current > 0 && $current !== $userId) {
            return false;
        }
    }
    return true;
}

function student_activity_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_content_events` (
      `event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` INT(11) NOT NULL,
      `event_type` VARCHAR(64) NOT NULL,
      `subject_id` INT(11) DEFAULT NULL,
      `lesson_id` INT(11) DEFAULT NULL,
      `quiz_id` INT(11) DEFAULT NULL,
      `video_id` INT(11) DEFAULT NULL,
      `handout_id` INT(11) DEFAULT NULL,
      `attempt_id` INT(11) DEFAULT NULL,
      `page_key` VARCHAR(120) DEFAULT NULL,
      `page_title` VARCHAR(255) DEFAULT NULL,
      `page_url` VARCHAR(500) DEFAULT NULL,
      `meta_json` JSON DEFAULT NULL,
      `session_token` VARCHAR(64) DEFAULT NULL,
      `ip_address` VARCHAR(45) DEFAULT NULL,
      `user_agent` VARCHAR(255) DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`event_id`),
      KEY `idx_sce_user_created` (`user_id`, `created_at`),
      KEY `idx_sce_type_created` (`event_type`, `created_at`),
      KEY `idx_sce_session` (`session_token`),
      KEY `idx_sce_page_created` (`page_key`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_video_progress` (
      `progress_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` INT(11) NOT NULL,
      `video_id` INT(11) NOT NULL,
      `lesson_id` INT(11) DEFAULT NULL,
      `subject_id` INT(11) DEFAULT NULL,
      `position_sec` DECIMAL(10,2) NOT NULL DEFAULT 0,
      `max_position_sec` DECIMAL(10,2) NOT NULL DEFAULT 0,
      `duration_sec` DECIMAL(10,2) DEFAULT NULL,
      `percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
      `watch_seconds` DECIMAL(12,2) NOT NULL DEFAULT 0,
      `is_playing` TINYINT(1) NOT NULL DEFAULT 0,
      `completed` TINYINT(1) NOT NULL DEFAULT 0,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`progress_id`),
      UNIQUE KEY `uq_svp_user_video` (`user_id`, `video_id`),
      KEY `idx_svp_updated` (`updated_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    if (ereview_schema_table_exists($conn, 'student_video_progress')) {
        $videoProgressCols = [
            'max_position_sec' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
            'watch_seconds' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'is_playing' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ];
        foreach ($videoProgressCols as $col => $def) {
            if (ereview_schema_column_exists_fresh($conn, 'student_video_progress', $col)) {
                continue;
            }
            try {
                mysqli_query($conn, "ALTER TABLE student_video_progress ADD COLUMN `{$col}` {$def}");
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'Duplicate column') === false) {
                    throw $e;
                }
            }
            $cacheKey = 'c:student_video_progress.' . $col;
            $req = &ereview_schema_req_cache();
            $req[$cacheKey] = true;
            ereview_schema_session_set($cacheKey, true);
        }
    }

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_sessions` (
      `session_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` INT(11) NOT NULL,
      `session_token` VARCHAR(64) NOT NULL,
      `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `ended_at` DATETIME DEFAULT NULL,
      `current_page_key` VARCHAR(120) DEFAULT NULL,
      `current_page_title` VARCHAR(255) DEFAULT NULL,
      `current_page_url` VARCHAR(500) DEFAULT NULL,
      `subject_id` INT(11) DEFAULT NULL,
      `lesson_id` INT(11) DEFAULT NULL,
      `quiz_id` INT(11) DEFAULT NULL,
      `video_id` INT(11) DEFAULT NULL,
      `ip_address` VARCHAR(45) DEFAULT NULL,
      `user_agent` VARCHAR(255) DEFAULT NULL,
      `is_active` TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (`session_id`),
      UNIQUE KEY `uq_ss_token` (`session_token`),
      KEY `idx_ss_user_active` (`user_id`, `is_active`, `last_seen_at`),
      KEY `idx_ss_live` (`is_active`, `last_seen_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    if (ereview_schema_table_exists($conn, 'quiz_attempts')) {
        // Add proctoring columns one-by-one. Fresh checks avoid stale session
        // cache (false cached before a prior ALTER) which caused Duplicate column fatals.
        $quizAttemptCols = [
            'last_seen_at' => 'DATETIME DEFAULT NULL',
            'tab_switch_count' => 'INT NOT NULL DEFAULT 0',
            'last_tab_switch_at' => 'DATETIME DEFAULT NULL',
        ];
        foreach ($quizAttemptCols as $col => $def) {
            if (ereview_schema_column_exists_fresh($conn, 'quiz_attempts', $col)) {
                continue;
            }
            try {
                mysqli_query($conn, "ALTER TABLE quiz_attempts ADD COLUMN `{$col}` {$def}");
            } catch (Throwable $e) {
                // Race / already exists: ignore duplicate-column errors only.
                if (stripos($e->getMessage(), 'Duplicate column') === false) {
                    throw $e;
                }
            }
            $cacheKey = 'c:quiz_attempts.' . $col;
            $req = &ereview_schema_req_cache();
            $req[$cacheKey] = true;
            ereview_schema_session_set($cacheKey, true);
        }
    }
}

function student_activity_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return mb_substr($ip, 0, 45);
}

function student_activity_user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function student_activity_session_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['student_activity_token']) || !is_string($_SESSION['student_activity_token'])) {
        $_SESSION['student_activity_token'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['student_activity_token'];
}

/**
 * @param array<string,mixed> $ctx
 */
function student_activity_log_event(mysqli $conn, int $userId, string $eventType, array $ctx = []): bool
{
    if ($userId <= 0 || $eventType === '' || !student_activity_is_lms_student($userId)) {
        return false;
    }
    student_activity_ensure_schema($conn);
    if (!ereview_schema_table_exists($conn, 'student_content_events')) {
        return false;
    }

    $subjectId = !empty($ctx['subject_id']) ? (int) $ctx['subject_id'] : 0;
    $lessonId = !empty($ctx['lesson_id']) ? (int) $ctx['lesson_id'] : 0;
    $quizId = !empty($ctx['quiz_id']) ? (int) $ctx['quiz_id'] : 0;
    $videoId = !empty($ctx['video_id']) ? (int) $ctx['video_id'] : 0;
    $handoutId = !empty($ctx['handout_id']) ? (int) $ctx['handout_id'] : 0;
    $attemptId = !empty($ctx['attempt_id']) ? (int) $ctx['attempt_id'] : 0;
    $pageKey = isset($ctx['page_key']) ? mb_substr((string) $ctx['page_key'], 0, 120) : '';
    $pageTitle = isset($ctx['page_title']) ? mb_substr((string) $ctx['page_title'], 0, 255) : '';
    $pageUrl = isset($ctx['page_url']) ? mb_substr((string) $ctx['page_url'], 0, 500) : '';
    $meta = isset($ctx['meta']) && is_array($ctx['meta']) ? json_encode($ctx['meta'], JSON_UNESCAPED_UNICODE) : null;
    $token = student_activity_session_token();
    $ip = student_activity_client_ip();
    $ua = student_activity_user_agent();

    // Store NULLs for empty optional IDs via SQL COALESCE-friendly insert.
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO student_content_events
          (user_id, event_type, subject_id, lesson_id, quiz_id, video_id, handout_id, attempt_id,
           page_key, page_title, page_url, meta_json, session_token, ip_address, user_agent)
         VALUES (
           ?, ?,
           NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0),
           NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?, ?, ?
         )'
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'isiiiiissssssss',
        $userId,
        $eventType,
        $subjectId,
        $lessonId,
        $quizId,
        $videoId,
        $handoutId,
        $attemptId,
        $pageKey,
        $pageTitle,
        $pageUrl,
        $meta,
        $token,
        $ip,
        $ua
    );
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return (bool) $ok;
}

/**
 * @param array<string,mixed> $location
 */
function student_activity_touch_session(mysqli $conn, int $userId, array $location = []): void
{
    if ($userId <= 0 || !student_activity_is_lms_student($userId)) {
        return;
    }
    student_activity_ensure_schema($conn);
    if (!ereview_schema_table_exists($conn, 'student_sessions')) {
        return;
    }

    $token = student_activity_session_token();
    if ($token === '') {
        return;
    }

    $pageKey = isset($location['page_key']) ? mb_substr((string) $location['page_key'], 0, 120) : '';
    $pageTitle = isset($location['page_title']) ? mb_substr((string) $location['page_title'], 0, 255) : '';
    $pageUrl = isset($location['page_url']) ? mb_substr((string) $location['page_url'], 0, 500) : '';
    $subjectId = !empty($location['subject_id']) ? (int) $location['subject_id'] : 0;
    $lessonId = !empty($location['lesson_id']) ? (int) $location['lesson_id'] : 0;
    $quizId = !empty($location['quiz_id']) ? (int) $location['quiz_id'] : 0;
    $videoId = !empty($location['video_id']) ? (int) $location['video_id'] : 0;
    $ip = student_activity_client_ip();
    $ua = student_activity_user_agent();

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO student_sessions
          (user_id, session_token, started_at, last_seen_at, current_page_key, current_page_title, current_page_url,
           subject_id, lesson_id, quiz_id, video_id, ip_address, user_agent, is_active)
         VALUES (
           ?, ?, NOW(), NOW(),
           NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'),
           NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0),
           ?, ?, 1
         )
         ON DUPLICATE KEY UPDATE
           last_seen_at = NOW(),
           current_page_key = COALESCE(VALUES(current_page_key), current_page_key),
           current_page_title = COALESCE(VALUES(current_page_title), current_page_title),
           current_page_url = COALESCE(VALUES(current_page_url), current_page_url),
           subject_id = COALESCE(VALUES(subject_id), subject_id),
           lesson_id = COALESCE(VALUES(lesson_id), lesson_id),
           quiz_id = COALESCE(VALUES(quiz_id), quiz_id),
           video_id = COALESCE(VALUES(video_id), video_id),
           ip_address = VALUES(ip_address),
           user_agent = VALUES(user_agent),
           is_active = 1,
           ended_at = NULL'
    );
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param(
        $stmt,
        'issssiiiiss',
        $userId,
        $token,
        $pageKey,
        $pageTitle,
        $pageUrl,
        $subjectId,
        $lessonId,
        $quizId,
        $videoId,
        $ip,
        $ua
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function student_activity_end_session(mysqli $conn, int $userId): void
{
    if ($userId <= 0 || !student_activity_is_lms_student($userId)) {
        return;
    }
    student_activity_ensure_schema($conn);
    $token = student_activity_session_token();
    if ($token === '' || !ereview_schema_table_exists($conn, 'student_sessions')) {
        return;
    }
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE student_sessions SET is_active = 0, ended_at = NOW(), last_seen_at = NOW()
         WHERE user_id = ? AND session_token = ? AND is_active = 1'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $userId, $token);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function student_activity_format_duration(float $seconds): string
{
    $seconds = max(0, (int) round($seconds));
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%d:%02d', $m, $s);
}

/** Create a zero progress row if missing (does not reset an existing watch position). */
function student_activity_seed_video_progress(
    mysqli $conn,
    int $userId,
    int $videoId,
    ?int $lessonId = null,
    ?int $subjectId = null
): void {
    if ($userId <= 0 || $videoId <= 0 || !student_activity_is_lms_student($userId)) {
        return;
    }
    student_activity_ensure_schema($conn);
    if (!ereview_schema_table_exists($conn, 'student_video_progress')) {
        return;
    }
    $hasWatch = ereview_schema_column_exists($conn, 'student_video_progress', 'watch_seconds');
    if ($hasWatch) {
        $sql = 'INSERT IGNORE INTO student_video_progress
          (user_id, video_id, lesson_id, subject_id, position_sec, max_position_sec, percent, watch_seconds, is_playing, completed)
          VALUES (?, ?, ?, ?, 0, 0, 0, 0, 0, 0)';
    } else {
        $sql = 'INSERT IGNORE INTO student_video_progress
          (user_id, video_id, lesson_id, subject_id, position_sec, percent, completed)
          VALUES (?, ?, ?, ?, 0, 0, 0)';
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'iiii', $userId, $videoId, $lessonId, $subjectId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * @param array{watch_delta_sec?:float,is_playing?:bool|int} $opts
 */
function student_activity_upsert_video_progress(
    mysqli $conn,
    int $userId,
    int $videoId,
    float $positionSec,
    ?float $durationSec,
    ?int $lessonId = null,
    ?int $subjectId = null,
    array $opts = []
): bool {
    if ($userId <= 0 || $videoId <= 0 || !student_activity_is_lms_student($userId)) {
        return false;
    }
    student_activity_ensure_schema($conn);
    if (!ereview_schema_table_exists($conn, 'student_video_progress')) {
        return false;
    }

    $positionSec = max(0.0, $positionSec);
    $watchDelta = max(0.0, min(45.0, (float) ($opts['watch_delta_sec'] ?? 0)));
    $isPlaying = !empty($opts['is_playing']) ? 1 : 0;

    // Server-side watch estimate: if client delta is 0, credit elapsed while previously playing
    // or small forward position gains (Vimeo/YouTube pings are often sparse).
    if ($watchDelta < 0.25) {
        $prevStmt = mysqli_prepare(
            $conn,
            'SELECT position_sec, is_playing, updated_at, watch_seconds
             FROM student_video_progress WHERE user_id = ? AND video_id = ? LIMIT 1'
        );
        if ($prevStmt) {
            mysqli_stmt_bind_param($prevStmt, 'ii', $userId, $videoId);
            mysqli_stmt_execute($prevStmt);
            $prev = mysqli_fetch_assoc(mysqli_stmt_get_result($prevStmt)) ?: null;
            mysqli_stmt_close($prevStmt);
            if ($prev) {
                $prevPos = (float) ($prev['position_sec'] ?? 0);
                $posGain = max(0.0, $positionSec - $prevPos);
                $elapsed = 0.0;
                if (!empty($prev['updated_at'])) {
                    $elapsed = max(0.0, time() - strtotime((string) $prev['updated_at']));
                }
                if (!empty($prev['is_playing']) && $elapsed > 0 && $elapsed <= 20) {
                    $watchDelta = min(20.0, $elapsed);
                } elseif ($posGain > 0 && $posGain <= 20 && $elapsed <= 20) {
                    $watchDelta = min($posGain, $elapsed > 0 ? $elapsed : $posGain);
                }
            }
        }
    }

    $percent = 0.0;
    if ($durationSec !== null && $durationSec > 0) {
        $percent = min(100.0, max(0.0, ($positionSec / $durationSec) * 100.0));
    }
    $completed = $percent >= 90.0 ? 1 : 0;
    $hasWatch = ereview_schema_column_exists($conn, 'student_video_progress', 'watch_seconds');
    $hasMax = ereview_schema_column_exists($conn, 'student_video_progress', 'max_position_sec');
    $hasPlaying = ereview_schema_column_exists($conn, 'student_video_progress', 'is_playing');

    if ($hasWatch && $hasMax && $hasPlaying) {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO student_video_progress
              (user_id, video_id, lesson_id, subject_id, position_sec, max_position_sec, duration_sec, percent, watch_seconds, is_playing, completed)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               position_sec = VALUES(position_sec),
               max_position_sec = GREATEST(max_position_sec, VALUES(max_position_sec)),
               duration_sec = COALESCE(VALUES(duration_sec), duration_sec),
               percent = VALUES(percent),
               watch_seconds = watch_seconds + VALUES(watch_seconds),
               is_playing = VALUES(is_playing),
               completed = IF(VALUES(completed) = 1 OR completed = 1, 1, 0),
               lesson_id = COALESCE(VALUES(lesson_id), lesson_id),
               subject_id = COALESCE(VALUES(subject_id), subject_id),
               updated_at = NOW()'
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'iiiidddddii',
            $userId,
            $videoId,
            $lessonId,
            $subjectId,
            $positionSec,
            $positionSec,
            $durationSec,
            $percent,
            $watchDelta,
            $isPlaying,
            $completed
        );
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO student_video_progress
              (user_id, video_id, lesson_id, subject_id, position_sec, duration_sec, percent, completed)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               position_sec = VALUES(position_sec),
               duration_sec = COALESCE(VALUES(duration_sec), duration_sec),
               percent = GREATEST(percent, VALUES(percent)),
               completed = IF(VALUES(completed) = 1 OR completed = 1, 1, 0),
               lesson_id = COALESCE(VALUES(lesson_id), lesson_id),
               subject_id = COALESCE(VALUES(subject_id), subject_id),
               updated_at = NOW()'
        );
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'iiiidddi',
            $userId,
            $videoId,
            $lessonId,
            $subjectId,
            $positionSec,
            $durationSec,
            $percent,
            $completed
        );
    }
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return (bool) $ok;
}

/**
 * @return list<array<string,mixed>>
 */
function student_activity_fetch_events(mysqli $conn, int $userId, int $limit = 40): array
{
    student_activity_ensure_schema($conn);
    if ($userId <= 0 || !ereview_schema_table_exists($conn, 'student_content_events')) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $stmt = mysqli_prepare(
        $conn,
        'SELECT event_id, event_type, subject_id, lesson_id, quiz_id, video_id, handout_id, attempt_id,
                page_key, page_title, page_url, meta_json, created_at
         FROM student_content_events
         WHERE user_id = ?
         ORDER BY created_at DESC, event_id DESC
         LIMIT ?'
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
 * Live students seen within $withinSeconds.
 *
 * @return list<array<string,mixed>>
 */
function student_activity_fetch_live(mysqli $conn, int $withinSeconds = 180): array
{
    student_activity_ensure_schema($conn);
    if (!ereview_schema_table_exists($conn, 'student_sessions')) {
        return [];
    }
    $withinSeconds = max(60, min(3600, $withinSeconds));
    $hasVideoProgress = ereview_schema_table_exists($conn, 'student_video_progress');
    $hasLessonVideos = ereview_schema_table_exists($conn, 'lesson_videos');
    $vpCols = '';
    $vpJoin = '';
    if ($hasVideoProgress) {
        $vpCols = ', vp.position_sec AS video_position_sec, vp.duration_sec AS video_duration_sec,
                    vp.percent AS video_percent, vp.completed AS video_completed, vp.updated_at AS video_updated_at';
        if (ereview_schema_column_exists($conn, 'student_video_progress', 'watch_seconds')) {
            $vpCols .= ', vp.watch_seconds AS video_watch_seconds';
        }
        if (ereview_schema_column_exists($conn, 'student_video_progress', 'is_playing')) {
            $vpCols .= ', vp.is_playing AS video_is_playing';
        }
        if (ereview_schema_column_exists($conn, 'student_video_progress', 'max_position_sec')) {
            $vpCols .= ', vp.max_position_sec AS video_max_position_sec';
        }
        $vpJoin = 'LEFT JOIN student_video_progress vp ON vp.user_id = s.user_id AND vp.video_id = s.video_id';
    }
    $vCols = $hasLessonVideos ? ', v.video_title' : '';
    $vJoin = $hasLessonVideos ? 'LEFT JOIN lesson_videos v ON v.video_id = s.video_id' : '';

    $sql = "SELECT s.session_id, s.user_id, s.session_token, s.started_at, s.last_seen_at,
              s.current_page_key, s.current_page_title, s.current_page_url,
              s.subject_id, s.lesson_id, s.quiz_id, s.video_id, s.ip_address,
              TIMESTAMPDIFF(SECOND, s.started_at, s.last_seen_at) AS session_seconds,
              u.full_name, u.email,
              sub.subject_name, l.title AS lesson_title, q.title AS quiz_title
              {$vCols}{$vpCols}
            FROM student_sessions s
            INNER JOIN users u ON u.user_id = s.user_id AND u.role = 'student'
            LEFT JOIN subjects sub ON sub.subject_id = s.subject_id
            LEFT JOIN lessons l ON l.lesson_id = s.lesson_id
            LEFT JOIN quizzes q ON q.quiz_id = s.quiz_id
            {$vJoin}
            {$vpJoin}
            WHERE s.is_active = 1
              AND s.last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            ORDER BY s.last_seen_at DESC
            LIMIT 200";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $withinSeconds);
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
 * Map video_id => progress row for a student (for resume + playlist UI).
 *
 * @param list<int> $videoIds
 * @return array<int, array<string,mixed>>
 */
function student_activity_get_progress_map(mysqli $conn, int $userId, array $videoIds = []): array
{
    student_activity_ensure_schema($conn);
    if ($userId <= 0 || !ereview_schema_table_exists($conn, 'student_video_progress')) {
        return [];
    }
    $ids = [];
    foreach ($videoIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $hasWatch = ereview_schema_column_exists($conn, 'student_video_progress', 'watch_seconds');
    $extra = $hasWatch ? ', watch_seconds' : '';
    if (ereview_schema_column_exists($conn, 'student_video_progress', 'is_playing')) {
        $extra .= ', is_playing';
    }

    if ($ids === []) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT video_id, lesson_id, subject_id, position_sec, duration_sec, percent, completed, updated_at{$extra}
             FROM student_video_progress WHERE user_id = ?"
        );
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 'i', $userId);
    } else {
        $in = implode(',', array_map('intval', array_values($ids)));
        $stmt = mysqli_prepare(
            $conn,
            "SELECT video_id, lesson_id, subject_id, position_sec, duration_sec, percent, completed, updated_at{$extra}
             FROM student_video_progress WHERE user_id = ? AND video_id IN ({$in})"
        );
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 'i', $userId);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $map = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $map[(int) $r['video_id']] = $r;
    }
    mysqli_stmt_close($stmt);
    return $map;
}

/** Resume position in seconds, or 0 if finished / too early to resume. */
function student_activity_resume_seconds(array $progress): float
{
    $pos = (float) ($progress['position_sec'] ?? 0);
    $dur = isset($progress['duration_sec']) ? (float) $progress['duration_sec'] : 0.0;
    $pct = (float) ($progress['percent'] ?? 0);
    if ($pos < 5) {
        return 0.0;
    }
    if ($pct >= 95 || ($dur > 0 && $pos >= max(0.0, $dur - 8))) {
        return 0.0; // finished — start over
    }
    return $pos;
}

/**
 * Recent video watch rows for one student (admin Activity tab).
 *
 * @return list<array<string,mixed>>
 */
function student_activity_fetch_video_progress_for_user(mysqli $conn, int $userId, int $limit = 12): array
{
    student_activity_ensure_schema($conn);
    if ($userId <= 0 || !ereview_schema_table_exists($conn, 'student_video_progress')) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $hasWatch = ereview_schema_column_exists($conn, 'student_video_progress', 'watch_seconds');
    $extra = $hasWatch ? ', vp.watch_seconds' : '';
    $hasPlaying = ereview_schema_column_exists($conn, 'student_video_progress', 'is_playing');
    if ($hasPlaying) {
        $extra .= ', vp.is_playing';
    }
    $sql = "SELECT vp.video_id, vp.lesson_id, vp.subject_id, vp.position_sec, vp.duration_sec,
                   vp.percent, vp.completed, vp.updated_at{$extra},
                   v.video_title, l.title AS lesson_title, s.subject_name
            FROM student_video_progress vp
            LEFT JOIN lesson_videos v ON v.video_id = vp.video_id
            LEFT JOIN lessons l ON l.lesson_id = vp.lesson_id
            LEFT JOIN subjects s ON s.subject_id = vp.subject_id
            WHERE vp.user_id = ?
            ORDER BY vp.updated_at DESC
            LIMIT ?";
    $stmt = mysqli_prepare($conn, $sql);
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
 * Recent video watches across all LMS students (for admin monitor history).
 *
 * @return list<array<string,mixed>>
 */
function student_activity_fetch_recent_video_watches(mysqli $conn, int $withinHours = 48, int $limit = 80): array
{
    student_activity_ensure_schema($conn);
    if (!ereview_schema_table_exists($conn, 'student_video_progress')) {
        return [];
    }
    $withinHours = max(1, min(720, $withinHours));
    $limit = max(1, min(200, $limit));
    $hasWatch = ereview_schema_column_exists($conn, 'student_video_progress', 'watch_seconds');
    $extra = $hasWatch ? ', vp.watch_seconds' : '';
    if (ereview_schema_column_exists($conn, 'student_video_progress', 'is_playing')) {
        $extra .= ', vp.is_playing';
    }
    $sql = "SELECT vp.user_id, vp.video_id, vp.lesson_id, vp.subject_id, vp.position_sec, vp.duration_sec,
                   vp.percent, vp.completed, vp.updated_at{$extra},
                   u.full_name, u.email,
                   v.video_title, l.title AS lesson_title, s.subject_name
            FROM student_video_progress vp
            INNER JOIN users u ON u.user_id = vp.user_id AND u.role = 'student'
            LEFT JOIN lesson_videos v ON v.video_id = vp.video_id
            LEFT JOIN lessons l ON l.lesson_id = vp.lesson_id
            LEFT JOIN subjects s ON s.subject_id = vp.subject_id
            WHERE vp.updated_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
              AND (vp.position_sec > 0 OR vp.percent > 0" . ($hasWatch ? ' OR vp.watch_seconds > 0' : '') . ")
            ORDER BY vp.updated_at DESC
            LIMIT ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $withinHours, $limit);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function student_activity_event_label(string $type): string
{
    static $map = [
        'page_view' => 'Opened page',
        'lesson_open' => 'Opened lesson',
        'video_open' => 'Opened video',
        'video_progress' => 'Watching video',
        'handout_open' => 'Opened handout',
        'handout_download' => 'Downloaded handout',
        'test_bank_open' => 'Opened test bank',
        'quiz_started' => 'Started quiz',
        'quiz_submitted' => 'Submitted quiz',
        'quiz_heartbeat' => 'In quiz',
        'quiz_tab_switch' => 'Quiz tab switch',
        'preboard_started' => 'Started preboard',
        'preboard_submitted' => 'Submitted preboard',
        'session_start' => 'Session started',
        'session_end' => 'Session ended',
    ];
    return $map[$type] ?? $type;
}

/**
 * Recent quiz attempts for one student.
 *
 * @return list<array<string,mixed>>
 */
function student_activity_fetch_quiz_attempts_for_user(mysqli $conn, int $userId, int $limit = 15): array
{
    if ($userId <= 0 || !ereview_schema_table_exists($conn, 'quiz_attempts')) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $stmt = mysqli_prepare(
        $conn,
        "SELECT a.attempt_id, a.quiz_id, a.status, a.score, a.correct_count, a.total_count,
                a.started_at, a.submitted_at, a.expires_at,
                q.title AS quiz_title, s.subject_id, s.subject_name
         FROM quiz_attempts a
         INNER JOIN quizzes q ON q.quiz_id = a.quiz_id
         INNER JOIN subjects s ON s.subject_id = q.subject_id
         WHERE a.user_id = ?
         ORDER BY COALESCE(a.submitted_at, a.started_at) DESC, a.attempt_id DESC
         LIMIT ?"
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
 * @return list<array<string,mixed>>
 */
function student_activity_fetch_preboard_attempts_for_user(mysqli $conn, int $userId, int $limit = 10): array
{
    if ($userId <= 0 || !ereview_schema_table_exists($conn, 'preboards_attempts')) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    $stmt = mysqli_prepare(
        $conn,
        "SELECT a.preboards_attempt_id, a.status, a.score, a.correct_count, a.total_count,
                a.started_at, a.submitted_at, a.attempt_no,
                s.set_label, s.title AS set_title, sub.subject_name
         FROM preboards_attempts a
         INNER JOIN preboards_sets s ON s.preboards_set_id = a.preboards_set_id
         INNER JOIN preboards_subjects sub ON sub.preboards_subject_id = s.preboards_subject_id
         WHERE a.user_id = ?
         ORDER BY COALESCE(a.submitted_at, a.started_at) DESC
         LIMIT ?"
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
