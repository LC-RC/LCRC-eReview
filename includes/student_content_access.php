<?php
declare(strict_types=1);

/**
 * Granular LMS content access for CPA review students (role=student).
 * Account expiration / approval checks remain on users table (unchanged).
 */

const SCA_DENIED_MESSAGE = 'You do not have permission to access this content.';

/** @var array<int, array<string, mixed>> */
$GLOBALS['_sca_perm_cache'] = [];

function sca_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $sql = "CREATE TABLE IF NOT EXISTS `student_content_permissions` (
      `permission_id` bigint(20) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `content_type` enum(
        'full_lms','subject','lesson','quiz','video','handout',
        'preboard_subject','preboard_set','preweek_unit','preweek_topic','test_bank'
      ) NOT NULL,
      `content_id` int(11) NOT NULL DEFAULT 0,
      `access_level` varchar(32) NOT NULL DEFAULT 'view',
      `granted_by` int(11) DEFAULT NULL,
      `granted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`permission_id`),
      UNIQUE KEY `uq_student_content_perm` (`user_id`, `content_type`, `content_id`),
      KEY `idx_scp_user` (`user_id`),
      KEY `idx_scp_type_id` (`content_type`, `content_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    @mysqli_query($conn, $sql);
    $done = true;
    sca_maybe_backfill_legacy_full_access($conn);
}

/**
 * One-time backfill: approved students with no permissions row get full LMS (pre-granular behavior).
 */
function sca_maybe_backfill_legacy_full_access(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $table = @mysqli_query($conn, "SHOW TABLES LIKE 'student_content_permissions'");
    if (!$table || !mysqli_fetch_row($table)) {
        return;
    }

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `ereview_app_meta` (
      `meta_key` varchar(64) NOT NULL,
      `meta_value` varchar(255) NOT NULL DEFAULT '',
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`meta_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $flag = @mysqli_query($conn, "SELECT meta_value FROM ereview_app_meta WHERE meta_key = 'sca_legacy_full_backfill' LIMIT 1");
    $flagRow = $flag ? mysqli_fetch_assoc($flag) : null;
    if ($flagRow && (string) ($flagRow['meta_value'] ?? '') === '1') {
        return;
    }

    $sql = "INSERT INTO `student_content_permissions` (`user_id`, `content_type`, `content_id`, `access_level`, `granted_by`)
        SELECT u.user_id, 'full_lms', 0, 'view', NULL
        FROM `users` u
        WHERE u.role = 'student'
          AND u.status = 'approved'
          AND NOT EXISTS (
            SELECT 1 FROM `student_content_permissions` p
            WHERE p.user_id = u.user_id
          )";
    @mysqli_query($conn, $sql);

    @mysqli_query(
        $conn,
        "INSERT INTO `ereview_app_meta` (`meta_key`, `meta_value`) VALUES ('sca_legacy_full_backfill', '1')
         ON DUPLICATE KEY UPDATE `meta_value` = '1'"
    );
}

function sca_user_permission_row_count(mysqli $conn, int $userId): int
{
    if ($userId <= 0 || !sca_tables_ready($conn)) {
        return 0;
    }
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS cnt FROM student_content_permissions WHERE user_id = ?');
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (int) ($row['cnt'] ?? 0);
}

function sca_tables_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    sca_ensure_schema($conn);
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'student_content_permissions'");
    $ready = (bool) ($r && mysqli_fetch_row($r));
    return $ready;
}

/**
 * Approved + not past access_end (if set).
 * Cached per user for the remainder of the request (same answer for every sca_has_access call).
 */
function sca_account_access_active(mysqli $conn, int $userId): bool
{
    static $cache = [];
    if ($userId <= 0) {
        return false;
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $stmt = mysqli_prepare($conn, "SELECT user_id, role, status, access_end FROM users WHERE user_id = ? AND role = 'student' LIMIT 1");
    if (!$stmt) {
        $cache[$userId] = false;
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        $cache[$userId] = false;
        return false;
    }
    require_once __DIR__ . '/commerce_access_gate.php';
    $gate = commerce_student_can_login($conn, $row);
    $cache[$userId] = !empty($gate['ok']);
    return $cache[$userId];
}

/**
 * @return array{full_lms:bool, map:array<string, array<int, true>>}
 */
function sca_load_permissions(mysqli $conn, int $userId): array
{
    if (isset($GLOBALS['_sca_perm_cache'][$userId])) {
        return $GLOBALS['_sca_perm_cache'][$userId];
    }
    $out = ['full_lms' => false, 'map' => []];
    if (!sca_tables_ready($conn)) {
        $GLOBALS['_sca_perm_cache'][$userId] = $out;
        return $out;
    }
    $stmt = mysqli_prepare($conn, 'SELECT content_type, content_id FROM student_content_permissions WHERE user_id = ?');
    if (!$stmt) {
        $GLOBALS['_sca_perm_cache'][$userId] = $out;
        return $out;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $type = (string) ($row['content_type'] ?? '');
        $cid = (int) ($row['content_id'] ?? 0);
        if ($type === 'full_lms' && $cid === 0) {
            $out['full_lms'] = true;
            continue;
        }
        if ($type === '') {
            continue;
        }
        if (!isset($out['map'][$type])) {
            $out['map'][$type] = [];
        }
        $out['map'][$type][$cid] = true;
    }
    mysqli_stmt_close($stmt);
    $GLOBALS['_sca_perm_cache'][$userId] = $out;
    return $out;
}

function sca_clear_permission_cache(?int $userId = null): void
{
    if ($userId === null) {
        $GLOBALS['_sca_perm_cache'] = [];
        return;
    }
    unset($GLOBALS['_sca_perm_cache'][$userId]);
}

function sca_perm_has(array $perms, string $type, int $contentId): bool
{
    return !empty($perms['map'][$type][$contentId]);
}

function sca_lookup_lesson_subject(mysqli $conn, int $lessonId): int
{
    static $cache = [];
    if ($lessonId <= 0) {
        return 0;
    }
    if (isset($cache[$lessonId])) {
        return $cache[$lessonId];
    }
    $stmt = mysqli_prepare($conn, 'SELECT subject_id FROM lessons WHERE lesson_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $lessonId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $cache[$lessonId] = (int) ($row['subject_id'] ?? 0);
    return $cache[$lessonId];
}

function sca_lookup_quiz_subject(mysqli $conn, int $quizId): int
{
    static $cache = [];
    if ($quizId <= 0) {
        return 0;
    }
    if (isset($cache[$quizId])) {
        return $cache[$quizId];
    }
    $stmt = mysqli_prepare($conn, 'SELECT subject_id FROM quizzes WHERE quiz_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $quizId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $cache[$quizId] = (int) ($row['subject_id'] ?? 0);
    return $cache[$quizId];
}

function sca_lookup_video_lesson(mysqli $conn, int $videoId): int
{
    static $cache = [];
    if ($videoId <= 0) {
        return 0;
    }
    if (isset($cache[$videoId])) {
        return $cache[$videoId];
    }
    $stmt = mysqli_prepare($conn, 'SELECT lesson_id FROM lesson_videos WHERE video_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $videoId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $cache[$videoId] = (int) ($row['lesson_id'] ?? 0);
    return $cache[$videoId];
}

function sca_lookup_handout_lesson(mysqli $conn, int $handoutId): int
{
    static $cache = [];
    if ($handoutId <= 0) {
        return 0;
    }
    if (isset($cache[$handoutId])) {
        return $cache[$handoutId];
    }
    $stmt = mysqli_prepare($conn, 'SELECT lesson_id FROM lesson_handouts WHERE handout_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $handoutId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $cache[$handoutId] = (int) ($row['lesson_id'] ?? 0);
    return $cache[$handoutId];
}

function sca_lookup_preboard_set_subject(mysqli $conn, int $setId): int
{
    static $cache = [];
    if ($setId <= 0) {
        return 0;
    }
    if (isset($cache[$setId])) {
        return $cache[$setId];
    }
    $sid = 0;
    $col = @mysqli_query($conn, "SHOW COLUMNS FROM preboards_sets LIKE 'preboards_subject_id'");
    if ($col && mysqli_num_rows($col) > 0) {
        $stmt = mysqli_prepare($conn, 'SELECT preboards_subject_id FROM preboards_sets WHERE preboards_set_id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $setId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        $sid = (int) ($row['preboards_subject_id'] ?? 0);
    }
    $cache[$setId] = $sid;
    return $sid;
}

function sca_lookup_preweek_topic_unit(mysqli $conn, int $topicId): int
{
    static $cache = [];
    if ($topicId <= 0) {
        return 0;
    }
    if (isset($cache[$topicId])) {
        return $cache[$topicId];
    }
    $stmt = mysqli_prepare($conn, 'SELECT preweek_unit_id FROM preweek_topics WHERE preweek_topic_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $topicId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $cache[$topicId] = (int) ($row['preweek_unit_id'] ?? 0);
    return $cache[$topicId];
}

function sca_has_access(mysqli $conn, int $userId, string $type, int $contentId): bool
{
    if ($userId <= 0 || !sca_account_access_active($conn, $userId)) {
        return false;
    }
    $perms = sca_load_permissions($conn, $userId);
    if ($perms['full_lms']) {
        return true;
    }
    $map = $perms['map'];
    if ($type === 'full_lms') {
        return false;
    }
    if (sca_perm_has($perms, $type, $contentId)) {
        return true;
    }

    switch ($type) {
        case 'subject':
            return false;
        case 'lesson':
            $sid = sca_lookup_lesson_subject($conn, $contentId);
            return $sid > 0 && sca_perm_has($perms, 'subject', $sid);
        case 'quiz':
            $sid = sca_lookup_quiz_subject($conn, $contentId);
            return $sid > 0 && sca_perm_has($perms, 'subject', $sid);
        case 'video':
            $lid = sca_lookup_video_lesson($conn, $contentId);
            if ($lid > 0 && (sca_perm_has($perms, 'lesson', $lid) || sca_has_access($conn, $userId, 'lesson', $lid))) {
                return true;
            }
            return false;
        case 'handout':
            $lid = sca_lookup_handout_lesson($conn, $contentId);
            if ($lid > 0 && (sca_perm_has($perms, 'lesson', $lid) || sca_has_access($conn, $userId, 'lesson', $lid))) {
                return true;
            }
            return false;
        case 'preboard_set':
            if (sca_perm_has($perms, 'preboard_set', $contentId)) {
                return true;
            }
            $pbsid = sca_lookup_preboard_set_subject($conn, $contentId);
            return $pbsid > 0 && sca_perm_has($perms, 'preboard_subject', $pbsid);
        case 'preboard_subject':
            return false;
        case 'preweek_topic':
            if (sca_perm_has($perms, 'preweek_topic', $contentId)) {
                return true;
            }
            $uid = sca_lookup_preweek_topic_unit($conn, $contentId);
            return $uid > 0 && sca_perm_has($perms, 'preweek_unit', $uid);
        case 'preweek_unit':
            return false;
        case 'test_bank':
            return sca_perm_has($perms, 'test_bank', $contentId);
        default:
            return false;
    }
}

function sca_subject_has_any_access(mysqli $conn, int $userId, int $subjectId): bool
{
    if ($userId <= 0 || $subjectId <= 0) {
        return false;
    }
    if (!sca_account_access_active($conn, $userId)) {
        return false;
    }
    // Entire subject OR full LMS (via sca_has_access).
    if (sca_has_access($conn, $userId, 'subject', $subjectId)) {
        return true;
    }

    $perms = sca_load_permissions($conn, $userId);
    if ($perms['full_lms']) {
        return true;
    }

    // Same rules as before; load lesson/video/handout/quiz ids in a few queries (not per-lesson).
    $lessonIds = [];
    $stmt = mysqli_prepare($conn, 'SELECT lesson_id FROM lessons WHERE subject_id = ?');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $subjectId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $lid = (int) ($row['lesson_id'] ?? 0);
            if ($lid > 0) {
                $lessonIds[] = $lid;
            }
        }
        mysqli_stmt_close($stmt);
    }
    foreach ($lessonIds as $lid) {
        if (sca_perm_has($perms, 'lesson', $lid)) {
            return true;
        }
    }
    if ($lessonIds !== []) {
        $in = implode(',', array_map('intval', $lessonIds));
        $vq = mysqli_query($conn, "SELECT video_id FROM lesson_videos WHERE lesson_id IN ($in)");
        while ($vq && ($v = mysqli_fetch_assoc($vq))) {
            if (sca_perm_has($perms, 'video', (int) ($v['video_id'] ?? 0))) {
                return true;
            }
        }
        $hq = mysqli_query($conn, "SELECT handout_id FROM lesson_handouts WHERE lesson_id IN ($in)");
        while ($hq && ($h = mysqli_fetch_assoc($hq))) {
            if (sca_perm_has($perms, 'handout', (int) ($h['handout_id'] ?? 0))) {
                return true;
            }
        }
    }

    $stmt = mysqli_prepare($conn, 'SELECT quiz_id FROM quizzes WHERE subject_id = ?');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $subjectId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $qid = (int) ($row['quiz_id'] ?? 0);
            if ($qid > 0 && sca_perm_has($perms, 'quiz', $qid)) {
                mysqli_stmt_close($stmt);
                return true;
            }
        }
        mysqli_stmt_close($stmt);
    }

    return false;
}

function sca_preboard_subject_has_any_access(mysqli $conn, int $userId, int $preboardsSubjectId): bool
{
    if ($userId <= 0 || $preboardsSubjectId <= 0 || !sca_account_access_active($conn, $userId)) {
        return false;
    }
    if (sca_has_access($conn, $userId, 'preboard_subject', $preboardsSubjectId)) {
        return true;
    }
    $stmt = mysqli_prepare($conn, 'SELECT preboards_set_id FROM preboards_sets WHERE preboards_subject_id = ?');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $preboardsSubjectId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $setId = (int) ($row['preboards_set_id'] ?? 0);
        if ($setId > 0 && sca_has_access($conn, $userId, 'preboard_set', $setId)) {
            mysqli_stmt_close($stmt);
            return true;
        }
    }
    mysqli_stmt_close($stmt);
    return false;
}

/**
 * Student may view/use this preboard set under granular permissions (or full LMS).
 */
function sca_preboard_set_granted(mysqli $conn, int $userId, int $setId, int $preboardsSubjectId): bool
{
    if (!sca_has_access($conn, $userId, 'preboard_set', $setId)) {
        return false;
    }
    $perms = sca_load_permissions($conn, $userId);
    if ($perms['full_lms']) {
        return true;
    }
    return sca_perm_has($perms, 'preboard_set', $setId)
        || sca_perm_has($perms, 'preboard_subject', $preboardsSubjectId);
}

/**
 * Whether the student can start/continue a set.
 * SCA grants visibility; set must be open (manual/schedule) or have an approved one-time access grant.
 */
function sca_preboard_set_can_enter(mysqli $conn, int $userId, int $setId, int $preboardsSubjectId, array $setRow, bool $hasAccessGrant): bool
{
    if (!sca_preboard_set_granted($conn, $userId, $setId, $preboardsSubjectId)) {
        return false;
    }
    require_once __DIR__ . '/preboards_helpers.php';
    $effectiveOpen = preboards_set_is_open_for_students($setRow);
    return $effectiveOpen || $hasAccessGrant;
}

/**
 * Explicit per-set or per-subject grant (not full LMS) - bypasses admin is_open lock.
 */
function sca_preboard_has_granular_grant(mysqli $conn, int $userId, int $setId, int $preboardsSubjectId): bool
{
    if (!sca_preboard_set_granted($conn, $userId, $setId, $preboardsSubjectId)) {
        return false;
    }
    $perms = sca_load_permissions($conn, $userId);
    return !$perms['full_lms'];
}

function sca_enforce_student_session(mysqli $conn, string $redirect = 'index'): void
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return;
    }
    $stmt = mysqli_prepare($conn, "SELECT user_id, role, status, access_end FROM users WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return;
    }
    require_once __DIR__ . '/commerce_access_gate.php';
    $gate = commerce_student_can_login($conn, $row);
    if (empty($gate['ok'])) {
        $_SESSION['error'] = (string) ($gate['error'] ?? 'Your account is not approved yet.');
        header('Location: ' . $redirect);
        exit;
    }
}

function sca_require_access(mysqli $conn, int $userId, string $type, int $contentId, string $fallback = 'student_dashboard'): void
{
    sca_ensure_schema($conn);
    if (!sca_has_access($conn, $userId, $type, $contentId)) {
        $_SESSION['error'] = SCA_DENIED_MESSAGE;
        header('Location: ' . $fallback);
        exit;
    }
}

/**
 * @return list<array{content_type:string, content_id:int}>
 */
function sca_normalize_permission_payload(array $raw): array
{
    $allowed = [
        'full_lms', 'subject', 'lesson', 'quiz', 'video', 'handout',
        'preboard_subject', 'preboard_set', 'preweek_unit', 'preweek_topic', 'test_bank',
    ];
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = (string) ($item['content_type'] ?? $item['type'] ?? '');
        if (!in_array($type, $allowed, true)) {
            continue;
        }
        $cid = (int) ($item['content_id'] ?? $item['id'] ?? 0);
        if ($type !== 'full_lms' && $cid <= 0) {
            continue;
        }
        $out[] = ['content_type' => $type, 'content_id' => $cid];
    }
    return $out;
}

function sca_save_user_permissions(mysqli $conn, int $userId, array $permissions, ?int $grantedBy): bool
{
    sca_ensure_schema($conn);
    if ($userId <= 0) {
        return false;
    }
    $normalized = sca_normalize_permission_payload($permissions);
    mysqli_begin_transaction($conn);
    $ok = true;
    $del = mysqli_prepare($conn, 'DELETE FROM student_content_permissions WHERE user_id = ?');
    if (!$del) {
        mysqli_rollback($conn);
        return false;
    }
    mysqli_stmt_bind_param($del, 'i', $userId);
    $ok = $ok && mysqli_stmt_execute($del);
    mysqli_stmt_close($del);

    if ($ok && $normalized !== []) {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO student_content_permissions (user_id, content_type, content_id, access_level, granted_by) VALUES (?, ?, ?, ?, ?)'
        );
        if (!$ins) {
            mysqli_rollback($conn);
            return false;
        }
        $level = 'view';
        foreach ($normalized as $p) {
            $type = $p['content_type'];
            $cid = (int) $p['content_id'];
            mysqli_stmt_bind_param($ins, 'isisi', $userId, $type, $cid, $level, $grantedBy);
            if (!mysqli_stmt_execute($ins)) {
                $ok = false;
                break;
            }
        }
        mysqli_stmt_close($ins);
    }

    if ($ok) {
        mysqli_commit($conn);
        sca_clear_permission_cache($userId);
        return true;
    }
    mysqli_rollback($conn);
    return false;
}

/**
 * @return array<string, mixed>
 */
function sca_admin_content_catalog(mysqli $conn): array
{
    $catalog = [
        'subjects' => [],
        'preboard_subjects' => [],
        'preweek_units' => [],
        'test_bank' => [],
    ];

    $sq = mysqli_query($conn, "SELECT subject_id, subject_name FROM subjects WHERE status='active' ORDER BY subject_name");
    while ($sq && ($s = mysqli_fetch_assoc($sq))) {
        $sid = (int) $s['subject_id'];
        $lessons = [];
        $lq = mysqli_prepare($conn, 'SELECT lesson_id, title FROM lessons WHERE subject_id = ? ORDER BY title');
        mysqli_stmt_bind_param($lq, 'i', $sid);
        mysqli_stmt_execute($lq);
        $lr = mysqli_stmt_get_result($lq);
        while ($lr && ($l = mysqli_fetch_assoc($lr))) {
            $lid = (int) $l['lesson_id'];
            $videos = [];
            $handouts = [];
            $vq = mysqli_query($conn, 'SELECT video_id, video_title FROM lesson_videos WHERE lesson_id = ' . $lid . ' ORDER BY video_id');
            while ($vq && ($v = mysqli_fetch_assoc($vq))) {
                $videos[] = ['id' => (int) $v['video_id'], 'label' => (string) ($v['video_title'] ?: 'Video #' . $v['video_id'])];
            }
            $hq = mysqli_query($conn, 'SELECT handout_id, handout_title FROM lesson_handouts WHERE lesson_id = ' . $lid . ' ORDER BY handout_id');
            while ($hq && ($h = mysqli_fetch_assoc($hq))) {
                $handouts[] = ['id' => (int) $h['handout_id'], 'label' => (string) ($h['handout_title'] ?: 'Handout #' . $h['handout_id'])];
            }
            $lessons[] = [
                'id' => $lid,
                'label' => (string) ($l['title'] ?: 'Lesson #' . $lid),
                'videos' => $videos,
                'handouts' => $handouts,
            ];
        }
        mysqli_stmt_close($lq);

        $quizzes = [];
        $qq = mysqli_prepare($conn, 'SELECT quiz_id, title FROM quizzes WHERE subject_id = ? ORDER BY title');
        mysqli_stmt_bind_param($qq, 'i', $sid);
        mysqli_stmt_execute($qq);
        $qr = mysqli_stmt_get_result($qq);
        while ($qr && ($q = mysqli_fetch_assoc($qr))) {
            $quizzes[] = ['id' => (int) $q['quiz_id'], 'label' => (string) ($q['title'] ?: 'Quiz #' . $q['quiz_id'])];
        }
        mysqli_stmt_close($qq);

        $catalog['subjects'][] = [
            'id' => $sid,
            'label' => (string) $s['subject_name'],
            'lessons' => $lessons,
            'quizzes' => $quizzes,
        ];
    }

    $tb = @mysqli_query($conn, "SHOW TABLES LIKE 'preboards_subjects'");
    if ($tb && mysqli_num_rows($tb) > 0) {
        require_once __DIR__ . '/preboards_helpers.php';
        $scheduleCols = false;
        $scheduleCol = @mysqli_query($conn, "SHOW COLUMNS FROM preboards_sets LIKE 'use_schedule'");
        if ($scheduleCol && mysqli_num_rows($scheduleCol) > 0) {
            $scheduleCols = true;
        }
        $setSelect = $scheduleCols
            ? 'preboards_set_id, set_label, is_open, use_schedule, opens_at, closes_at'
            : 'preboards_set_id, set_label, is_open';
        $psq = mysqli_query($conn, "SELECT preboards_subject_id, subject_name FROM preboards_subjects WHERE status='active' ORDER BY subject_name");
        while ($psq && ($ps = mysqli_fetch_assoc($psq))) {
            $pbsid = (int) $ps['preboards_subject_id'];
            $sets = [];
            $setQ = mysqli_query(
                $conn,
                'SELECT ' . $setSelect . ' FROM preboards_sets WHERE preboards_subject_id = ' . $pbsid . ' ORDER BY sort_order ASC, set_label ASC'
            );
            while ($setQ && ($st = mysqli_fetch_assoc($setQ))) {
                if (!$scheduleCols) {
                    $st['use_schedule'] = 0;
                    $st['opens_at'] = null;
                    $st['closes_at'] = null;
                }
                $accessMeta = preboards_set_access_meta($st);
                $sets[] = [
                    'id' => (int) $st['preboards_set_id'],
                    'label' => (string) ($st['set_label'] ?: 'Set #' . $st['preboards_set_id']),
                    'access_key' => (string) ($accessMeta['key'] ?? 'locked'),
                    'access_label' => (string) ($accessMeta['label'] ?? 'Locked'),
                ];
            }
            $catalog['preboard_subjects'][] = [
                'id' => $pbsid,
                'label' => (string) $ps['subject_name'],
                'sets' => $sets,
            ];
        }
    }

    $pu = mysqli_query($conn, 'SELECT preweek_unit_id, title FROM preweek_units WHERE subject_id = 0 ORDER BY created_at DESC');
    while ($pu && ($u = mysqli_fetch_assoc($pu))) {
        $uid = (int) $u['preweek_unit_id'];
        $topics = [];
        $tq = mysqli_prepare($conn, 'SELECT preweek_topic_id, title FROM preweek_topics WHERE preweek_unit_id = ? ORDER BY sort_order, title');
        mysqli_stmt_bind_param($tq, 'i', $uid);
        mysqli_stmt_execute($tq);
        $tr = mysqli_stmt_get_result($tq);
        while ($tr && ($t = mysqli_fetch_assoc($tr))) {
            $topics[] = ['id' => (int) $t['preweek_topic_id'], 'label' => (string) ($t['title'] ?: 'Topic #' . $t['preweek_topic_id'])];
        }
        mysqli_stmt_close($tq);
        $catalog['preweek_units'][] = [
            'id' => $uid,
            'label' => (string) ($u['title'] ?: 'Pre-week #' . $uid),
            'topics' => $topics,
        ];
    }

    $tbk = @mysqli_query($conn, "SHOW TABLES LIKE 'test_bank'");
    if ($tbk && mysqli_num_rows($tbk) > 0) {
        $bk = mysqli_query($conn, 'SELECT id, title FROM test_bank ORDER BY id DESC');
        while ($bk && ($b = mysqli_fetch_assoc($bk))) {
            $catalog['test_bank'][] = ['id' => (int) $b['id'], 'label' => (string) ($b['title'] ?: 'Item #' . $b['id'])];
        }
    }

    return $catalog;
}

/**
 * @return list<array{content_type:string, content_id:int}>
 */
function sca_permissions_for_api(mysqli $conn, int $userId): array
{
    sca_maybe_backfill_legacy_full_access($conn);
    $perms = sca_load_permissions($conn, $userId);
    $list = [];
    if ($perms['full_lms']) {
        $list[] = ['content_type' => 'full_lms', 'content_id' => 0];
    }
    foreach ($perms['map'] as $type => $ids) {
        foreach (array_keys($ids) as $cid) {
            $list[] = ['content_type' => $type, 'content_id' => (int) $cid];
        }
    }
    return $list;
}

function sca_grant_full_lms(mysqli $conn, int $userId, ?int $grantedBy): bool
{
    return sca_save_user_permissions($conn, $userId, [['content_type' => 'full_lms', 'content_id' => 0]], $grantedBy);
}

/**
 * Active grant-backed content keys from access_grants that admin replace-all must preserve.
 * Includes source IN ('purchase','free_access','admin_manual'), status=active, ends_at > NOW().
 * Does not include Free Access requests that were never granted. Empty if access_grants missing.
 *
 * @return list<array{content_type:string,content_id:int}>
 */
function sca_commerce_active_permission_keys(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $tq = @mysqli_query($conn, "SHOW TABLES LIKE 'access_grants'");
    if (!$tq || mysqli_num_rows($tq) === 0) {
        if ($tq) {
            mysqli_free_result($tq);
        }
        return [];
    }
    if ($tq) {
        mysqli_free_result($tq);
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT content_type, content_id
         FROM access_grants
         WHERE user_id = ?
           AND source IN ('purchase', 'free_access', 'admin_manual')
           AND status = 'active'
           AND ends_at > NOW()"
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $raw = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $raw[] = [
            'content_type' => (string) ($row['content_type'] ?? ''),
            'content_id' => (int) ($row['content_id'] ?? 0),
        ];
    }
    mysqli_stmt_close($stmt);
    return sca_normalize_permission_payload($raw);
}

/**
 * Union of permission lists by (content_type, content_id). No duplicates.
 *
 * @param list<array{content_type?:string,type?:string,content_id?:int|string,id?:int|string}> $a
 * @param list<array{content_type?:string,type?:string,content_id?:int|string,id?:int|string}> $b
 * @return list<array{content_type:string,content_id:int}>
 */
function sca_merge_permission_lists(array $a, array $b): array
{
    $seen = [];
    $out = [];
    foreach (array_merge(sca_normalize_permission_payload($a), sca_normalize_permission_payload($b)) as $p) {
        $type = $p['content_type'];
        $cid = (int) $p['content_id'];
        $key = $type . ':' . $cid;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = ['content_type' => $type, 'content_id' => $cid];
    }
    return $out;
}

/**
 * Replace-all SCA save that merges in active purchase + free_access grant permissions first.
 * Used by activate_user and Student Access so admin saves cannot wipe paid/FAR access rows.
 * Does not modify access_grants, login, or payment fulfillment.
 *
 * @param list<array{content_type?:string,type?:string,content_id?:int|string,id?:int|string}> $permissions
 */
function sca_save_user_permissions_preserving_commerce(
    mysqli $conn,
    int $userId,
    array $permissions,
    ?int $grantedBy
): bool {
    $commerce = sca_commerce_active_permission_keys($conn, $userId);
    $merged = sca_merge_permission_lists($permissions, $commerce);
    if ($merged === []) {
        // Preserve prior contract: empty payload is invalid for a full replace unless
        // commerce had keys (already handled). Mirror sca_save empty → clear non-commerce only
        // by saving commerce-only when admin sent empty but commerce exists.
        return sca_save_user_permissions($conn, $userId, $commerce, $grantedBy);
    }
    return sca_save_user_permissions($conn, $userId, $merged, $grantedBy);
}

/**
 * Merge/upsert SCA permissions without deleting existing rows.
 * Used by commerce fulfillment - NEVER replace-all.
 *
 * @param list<array{content_type?:string,type?:string,content_id?:int|string,id?:int|string}> $permissions
 */
function sca_upsert_permissions(mysqli $conn, int $userId, array $permissions, ?int $grantedBy): bool
{
    sca_ensure_schema($conn);
    if ($userId <= 0) {
        return false;
    }
    $normalized = sca_normalize_permission_payload($permissions);
    if ($normalized === []) {
        return true;
    }

    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO student_content_permissions (user_id, content_type, content_id, access_level, granted_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           access_level = VALUES(access_level),
           updated_at = CURRENT_TIMESTAMP'
    );
    if (!$ins) {
        return false;
    }
    $level = 'view';
    $ok = true;
    foreach ($normalized as $p) {
        $type = $p['content_type'];
        $cid = (int) $p['content_id'];
        mysqli_stmt_bind_param($ins, 'isisi', $userId, $type, $cid, $level, $grantedBy);
        if (!mysqli_stmt_execute($ins)) {
            $ok = false;
            break;
        }
    }
    mysqli_stmt_close($ins);
    if ($ok) {
        sca_clear_permission_cache($userId);
    }
    return $ok;
}

/**
 * Delete exactly one SCA permission row for (user_id, content_type, content_id).
 * Does not replace-all or touch other keys. Used by commerce expiry reconcile.
 */
function sca_delete_permission_key(mysqli $conn, int $userId, string $contentType, int $contentId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $normalized = sca_normalize_permission_payload([
        ['content_type' => $contentType, 'content_id' => $contentId],
    ]);
    if ($normalized === []) {
        return false;
    }
    $type = $normalized[0]['content_type'];
    $cid = (int) $normalized[0]['content_id'];
    $del = mysqli_prepare(
        $conn,
        'DELETE FROM student_content_permissions
         WHERE user_id = ? AND content_type = ? AND content_id = ?
         LIMIT 1'
    );
    if (!$del) {
        return false;
    }
    mysqli_stmt_bind_param($del, 'isi', $userId, $type, $cid);
    $ok = mysqli_stmt_execute($del);
    mysqli_stmt_close($del);
    if ($ok) {
        sca_clear_permission_cache($userId);
    }
    return $ok;
}
