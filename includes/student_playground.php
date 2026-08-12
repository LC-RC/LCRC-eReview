<?php
/**
 * CPA Playground — solo practice from existing quiz_questions (no duplicate bank).
 */
declare(strict_types=1);

require_once __DIR__ . '/schema_introspection.php';
require_once __DIR__ . '/student_content_access.php';

/** Configurable scoring (correctness first; speed is a small bonus). */
const STUDENT_PLAYGROUND_BASE_POINTS = 100;
const STUDENT_PLAYGROUND_SPEED_BONUS_MAX = 50;

function student_playground_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_playground_sessions` (
      `session_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` INT(11) NOT NULL,
      `mode` ENUM('quick_play','subject_challenge','mixed_challenge','daily_challenge') NOT NULL,
      `subject_id` INT(11) DEFAULT NULL,
      `status` ENUM('in_progress','completed','abandoned') NOT NULL DEFAULT 'in_progress',
      `question_count` INT(11) NOT NULL DEFAULT 10,
      `seconds_per_question` INT(11) NOT NULL DEFAULT 20,
      `difficulty` ENUM('easy','mixed','hard') NOT NULL DEFAULT 'mixed',
      `seed` VARCHAR(64) NOT NULL DEFAULT '',
      `score` INT(11) NOT NULL DEFAULT 0,
      `correct_count` INT(11) NOT NULL DEFAULT 0,
      `wrong_count` INT(11) NOT NULL DEFAULT 0,
      `best_streak` INT(11) NOT NULL DEFAULT 0,
      `current_streak` INT(11) NOT NULL DEFAULT 0,
      `total_response_ms` BIGINT NOT NULL DEFAULT 0,
      `answered_count` INT(11) NOT NULL DEFAULT 0,
      `daily_key` CHAR(10) DEFAULT NULL,
      `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `finished_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`session_id`),
      KEY `idx_spg_user_started` (`user_id`, `started_at`),
      KEY `idx_spg_user_daily` (`user_id`, `daily_key`),
      KEY `idx_spg_user_status` (`user_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_playground_items` (
      `item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `session_id` BIGINT UNSIGNED NOT NULL,
      `ordinal` INT(11) NOT NULL,
      `question_id` INT(11) NOT NULL,
      `quiz_id` INT(11) DEFAULT NULL,
      `subject_id` INT(11) DEFAULT NULL,
      `choice_order` VARCHAR(32) NOT NULL DEFAULT 'ABCD',
      `selected_answer` VARCHAR(5) DEFAULT NULL,
      `is_correct` TINYINT(1) DEFAULT NULL,
      `points` INT(11) NOT NULL DEFAULT 0,
      `response_ms` INT(11) DEFAULT NULL,
      `served_at` DATETIME DEFAULT NULL,
      `answered_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`item_id`),
      UNIQUE KEY `uq_spgi_session_ordinal` (`session_id`, `ordinal`),
      UNIQUE KEY `uq_spgi_session_question` (`session_id`, `question_id`),
      KEY `idx_spgi_question` (`question_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_playground_rooms` (
      `room_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `host_user_id` INT(11) NOT NULL,
      `room_code` CHAR(6) NOT NULL,
      `title` VARCHAR(255) NOT NULL DEFAULT '',
      `mode` ENUM('subject_challenge','mixed_challenge') NOT NULL DEFAULT 'mixed_challenge',
      `subject_id` INT(11) DEFAULT NULL,
      `question_count` INT(11) NOT NULL DEFAULT 20,
      `status` ENUM('lobby','live','finished') NOT NULL DEFAULT 'lobby',
      `seed` VARCHAR(64) NOT NULL DEFAULT '',
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `started_at` DATETIME DEFAULT NULL,
      `finished_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`room_id`),
      UNIQUE KEY `uq_spgr_code` (`room_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Total-exam timer + play style (playground vs future practice_exam).
    // Use fresh checks (session cache can stale-negative after a prior migrate)
    // and catch mysqli exceptions — @ does not suppress MYSQLI_REPORT_STRICT.
    student_playground_ensure_column(
        $conn,
        'student_playground_sessions',
        'play_style',
        "ALTER TABLE `student_playground_sessions`
         ADD COLUMN `play_style` ENUM('playground','practice_exam') NOT NULL DEFAULT 'playground' AFTER `mode`"
    );
    student_playground_ensure_column(
        $conn,
        'student_playground_sessions',
        'total_time_seconds',
        "ALTER TABLE `student_playground_sessions`
         ADD COLUMN `total_time_seconds` INT(11) NOT NULL DEFAULT 600 AFTER `seconds_per_question`"
    );
    student_playground_ensure_column(
        $conn,
        'student_playground_sessions',
        'ends_at',
        "ALTER TABLE `student_playground_sessions`
         ADD COLUMN `ends_at` DATETIME DEFAULT NULL AFTER `started_at`"
    );
    student_playground_ensure_column(
        $conn,
        'student_playground_sessions',
        'current_ordinal',
        "ALTER TABLE `student_playground_sessions`
         ADD COLUMN `current_ordinal` INT(11) NOT NULL DEFAULT 1 AFTER `answered_count`"
    );
}

/** Safe ADD COLUMN: bypasses stale false cache; ignores duplicate-column errors. */
function student_playground_ensure_column(mysqli $conn, string $table, string $column, string $alterSql): void
{
    if (ereview_schema_column_exists_fresh($conn, $table, $column)) {
        return;
    }
    try {
        mysqli_query($conn, $alterSql);
    } catch (Throwable $e) {
        $msg = strtolower($e->getMessage());
        if (strpos($msg, 'duplicate column') === false && strpos($msg, 'already exists') === false) {
            // Unexpected schema error — ignore to keep LMS pages up; next migrate can fix.
        }
    }
    // Refresh cache so later checks in this request/session see the column.
    ereview_schema_column_exists_fresh($conn, $table, $column);
}

/** Absolute bounds for TOTAL exam time (not per-question). */
const STUDENT_PLAYGROUND_MIN_TOTAL_SECONDS = 30;
const STUDENT_PLAYGROUND_MAX_TOTAL_SECONDS = 10800; // 3 hours

/**
 * Recommended total seconds for a question count (~45–60s/Q for CPA items).
 */
function student_playground_recommended_total_seconds(int $questionCount): int
{
    return match (true) {
        $questionCount <= 5 => 5 * 60,
        $questionCount <= 10 => 10 * 60,
        $questionCount <= 20 => 15 * 60,
        $questionCount <= 30 => 30 * 60,
        default => 45 * 60,
    };
}

/** @return list<int> Convenience presets in minutes (legacy / API). */
function student_playground_allowed_time_minutes(): array
{
    return [5, 10, 15, 30, 45, 60];
}

/** @return array{seconds:list<int>,minutes:list<int>,hours:list<int>} */
function student_playground_time_presets_by_unit(): array
{
    return [
        'seconds' => [30, 60, 90, 120, 180],
        'minutes' => [5, 10, 15, 30, 60],
        'hours' => [1, 2],
    ];
}

/** Clamp total exam seconds to safe bounds. */
function student_playground_clamp_total_seconds(int $seconds): int
{
    return max(
        STUDENT_PLAYGROUND_MIN_TOTAL_SECONDS,
        min(STUDENT_PLAYGROUND_MAX_TOTAL_SECONDS, $seconds)
    );
}

/**
 * Convert a UI duration (value + unit) into TOTAL exam seconds.
 * Server-side only — never trust a client-supplied ends_at.
 */
function student_playground_duration_to_seconds(int $value, string $unit): int
{
    $unit = strtolower(trim($unit));
    if (!in_array($unit, ['seconds', 'minutes', 'hours'], true)) {
        $unit = 'minutes';
    }
    $value = max(1, $value);
    $seconds = match ($unit) {
        'seconds' => $value,
        'hours' => $value * 3600,
        default => $value * 60,
    };
    return student_playground_clamp_total_seconds($seconds);
}

/**
 * Legacy helper: minutes (+ optional custom) → total seconds.
 * Prefer student_playground_duration_to_seconds() for new clients.
 */
function student_playground_normalize_total_seconds(int $questionCount, int $minutes, bool $custom): int
{
    if ($minutes <= 0) {
        return student_playground_recommended_total_seconds($questionCount);
    }
    if ($custom) {
        return student_playground_duration_to_seconds($minutes, 'minutes');
    }
    if (!in_array($minutes, student_playground_allowed_time_minutes(), true)) {
        return student_playground_recommended_total_seconds($questionCount);
    }
    return student_playground_clamp_total_seconds($minutes * 60);
}

/**
 * Resolve total exam seconds from start payload (value/unit preferred).
 *
 * @param array<string,mixed> $opts
 */
function student_playground_resolve_total_seconds(int $questionCount, array $opts): int
{
    $unit = strtolower(trim((string) ($opts['time_unit'] ?? '')));
    if ($unit !== '' || isset($opts['time_value'])) {
        $value = (int) ($opts['time_value'] ?? 0);
        if ($value <= 0 && isset($opts['time_minutes'])) {
            $value = (int) $opts['time_minutes'];
            $unit = $unit !== '' ? $unit : 'minutes';
        }
        if ($value <= 0) {
            return student_playground_recommended_total_seconds($questionCount);
        }
        if ($unit === '') {
            $unit = 'minutes';
        }
        return student_playground_duration_to_seconds($value, $unit);
    }

    // Optional direct seconds (still clamped — never trust ends_at from client).
    if (isset($opts['total_seconds']) && (int) $opts['total_seconds'] > 0) {
        return student_playground_clamp_total_seconds((int) $opts['total_seconds']);
    }

    $timeMinutes = (int) ($opts['time_minutes'] ?? 0);
    $customTime = !empty($opts['custom_time']);
    if ($timeMinutes <= 0) {
        return student_playground_recommended_total_seconds($questionCount);
    }
    return student_playground_normalize_total_seconds($questionCount, $timeMinutes, $customTime);
}

/** Remaining seconds until ends_at (0 if expired / missing). */
function student_playground_remaining_total_seconds(array $session): int
{
    if (empty($session['ends_at'])) {
        // Legacy sessions: fall back to started_at + total_time_seconds or recommended.
        $started = !empty($session['started_at']) ? strtotime((string) $session['started_at']) : time();
        $total = (int) ($session['total_time_seconds'] ?? 0);
        if ($total <= 0) {
            $total = student_playground_recommended_total_seconds((int) ($session['question_count'] ?? 10));
        }
        return max(0, $total - (time() - $started));
    }
    $ends = strtotime((string) $session['ends_at']);
    if ($ends === false) {
        return 0;
    }
    return max(0, $ends - time());
}

function student_playground_session_expired(array $session): bool
{
    if (($session['status'] ?? '') !== 'in_progress') {
        return false;
    }
    return student_playground_remaining_total_seconds($session) <= 0;
}

/**
 * @param list<array> $items ordered by ordinal
 * @return array{current_streak:int,best_streak:int}
 */
function student_playground_streaks_from_items(array $items): array
{
    $best = 0;
    $run = 0;
    foreach ($items as $it) {
        $sel = (string) ($it['selected_answer'] ?? '');
        if ($sel === '' || $sel === null) {
            continue;
        }
        if (!empty($it['is_correct'])) {
            $run++;
            $best = max($best, $run);
        } else {
            $run = 0;
        }
    }
    return ['current_streak' => $run, 'best_streak' => $best];
}

function student_playground_seconds_for_difficulty(string $difficulty): int
{
    // Kept for legacy compatibility / pacing hint only (not used as hard per-Q cutoff).
    return match ($difficulty) {
        'easy' => 45,
        'hard' => 30,
        default => 40,
    };
}

/** @return list<int> */
function student_playground_accessible_quiz_ids(mysqli $conn, int $userId, int $subjectId = 0): array
{
    sca_ensure_schema($conn);
    if ($userId <= 0 || !sca_account_access_active($conn, $userId)) {
        return [];
    }

    $sql = 'SELECT quiz_id, subject_id FROM quizzes';
    $params = [];
    $types = '';
    if ($subjectId > 0) {
        $sql .= ' WHERE subject_id = ?';
        $types = 'i';
        $params[] = $subjectId;
    }
    $sql .= ' ORDER BY quiz_id ASC';

    $ids = [];
    if ($types !== '') {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $qid = (int) $row['quiz_id'];
            if ($qid > 0 && sca_has_access($conn, $userId, 'quiz', $qid)) {
                $ids[] = $qid;
            }
        }
        mysqli_stmt_close($stmt);
        return $ids;
    }

    $res = @mysqli_query($conn, $sql);
    if (!$res) {
        return [];
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $qid = (int) $row['quiz_id'];
        if ($qid > 0 && sca_has_access($conn, $userId, 'quiz', $qid)) {
            $ids[] = $qid;
        }
    }
    return $ids;
}

/**
 * Candidate questions from accessible quizzes (IDs + meta only).
 *
 * @return list<array{question_id:int,quiz_id:int,subject_id:int}>
 */
function student_playground_question_pool(mysqli $conn, int $userId, int $subjectId = 0): array
{
    $quizIds = student_playground_accessible_quiz_ids($conn, $userId, $subjectId);
    if ($quizIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($quizIds), '?'));
    $types = str_repeat('i', count($quizIds));
    $sql = "SELECT qq.question_id, qq.quiz_id, q.subject_id
            FROM quiz_questions qq
            INNER JOIN quizzes q ON q.quiz_id = qq.quiz_id
            WHERE qq.quiz_id IN ({$placeholders})
              AND qq.correct_answer IS NOT NULL
              AND TRIM(qq.correct_answer) <> ''";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$quizIds);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $pool = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $pool[] = [
            'question_id' => (int) $row['question_id'],
            'quiz_id' => (int) $row['quiz_id'],
            'subject_id' => (int) $row['subject_id'],
        ];
    }
    mysqli_stmt_close($stmt);
    return $pool;
}

/** Deterministic shuffle of array using seed string. */
function student_playground_seeded_shuffle(array $items, string $seed): array
{
    $items = array_values($items);
    $n = count($items);
    if ($n < 2) {
        return $items;
    }
    for ($i = $n - 1; $i > 0; $i--) {
        $h = hexdec(substr(hash('sha256', $seed . ':' . $i), 0, 8));
        $j = $h % ($i + 1);
        $tmp = $items[$i];
        $items[$i] = $items[$j];
        $items[$j] = $tmp;
    }
    return $items;
}

function student_playground_choice_letters_from_row(array $q): array
{
    $letters = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $L) {
        $key = 'choice_' . strtolower($L);
        if (!array_key_exists($key, $q)) {
            if ($L === 'E') {
                break;
            }
            continue;
        }
        $val = trim((string) ($q[$key] ?? ''));
        if ($val !== '') {
            $letters[] = $L;
        }
    }
    if ($letters === []) {
        $letters = ['A', 'B', 'C', 'D'];
    }
    return $letters;
}

function student_playground_shuffle_choice_order(array $letters, string $seed, int $questionId): string
{
    $shuffled = student_playground_seeded_shuffle($letters, $seed . ':choices:' . $questionId);
    return implode('', $shuffled);
}

function student_playground_compute_points(bool $correct, int $responseMs, int $secondsPerQuestion): int
{
    if (!$correct) {
        return 0;
    }
    $limitMs = max(1, $secondsPerQuestion) * 1000;
    $responseMs = max(0, min($responseMs, $limitMs));
    // Faster answers earn more of the bonus; correctness always awards base.
    $ratio = 1.0 - ($responseMs / $limitMs);
    $bonus = (int) round(STUDENT_PLAYGROUND_SPEED_BONUS_MAX * max(0.0, min(1.0, $ratio)));
    return STUDENT_PLAYGROUND_BASE_POINTS + $bonus;
}

function student_playground_daily_key(?DateTimeInterface $when = null): string
{
    $dt = $when ?? new DateTimeImmutable('now');
    return $dt->format('Y-m-d');
}

/**
 * @return array{ok:bool,error?:string,session_id?:int}
 */
function student_playground_start(mysqli $conn, int $userId, array $opts): array
{
    student_playground_ensure_schema($conn);
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Unauthorized'];
    }

    $mode = (string) ($opts['mode'] ?? 'quick_play');
    $allowedModes = ['quick_play', 'subject_challenge', 'mixed_challenge', 'daily_challenge'];
    if (!in_array($mode, $allowedModes, true)) {
        return ['ok' => false, 'error' => 'Invalid mode.'];
    }

    $playStyle = (string) ($opts['play_style'] ?? 'playground');
    if (!in_array($playStyle, ['playground', 'practice_exam'], true)) {
        $playStyle = 'playground';
    }

    $difficulty = (string) ($opts['difficulty'] ?? 'mixed');
    if (!in_array($difficulty, ['easy', 'mixed', 'hard'], true)) {
        $difficulty = 'mixed';
    }
    // Pacing hint only (recommended seconds/question); not a hard per-question cutoff.
    $secondsHint = student_playground_seconds_for_difficulty($difficulty);

    $subjectId = (int) ($opts['subject_id'] ?? 0);
    if ($mode === 'subject_challenge') {
        if ($subjectId <= 0) {
            return ['ok' => false, 'error' => 'Select a subject for Subject Challenge.'];
        }
        if (!sca_subject_has_any_access($conn, $userId, $subjectId) && !sca_has_access($conn, $userId, 'subject', $subjectId)) {
            // Still allow if they have any quiz under that subject via accessible pool check below.
        }
    } else {
        $subjectId = ($mode === 'mixed_challenge' || $mode === 'quick_play' || $mode === 'daily_challenge') ? 0 : $subjectId;
    }

    $count = (int) ($opts['question_count'] ?? 10);
    if ($mode === 'quick_play') {
        $count = 10;
    } elseif ($mode === 'daily_challenge') {
        $count = 5;
    } else {
        $count = in_array($count, [10, 20, 30, 50], true) ? $count : 20;
    }

    // TOTAL exam duration (server-validated). Client cannot set ends_at.
    $totalSeconds = student_playground_resolve_total_seconds($count, $opts);

    $dailyKey = null;
    if ($mode === 'daily_challenge') {
        $dailyKey = student_playground_daily_key();
        $chk = mysqli_prepare(
            $conn,
            "SELECT session_id FROM student_playground_sessions
             WHERE user_id = ? AND mode = 'daily_challenge' AND daily_key = ? AND status = 'completed'
             LIMIT 1"
        );
        if ($chk) {
            mysqli_stmt_bind_param($chk, 'is', $userId, $dailyKey);
            mysqli_stmt_execute($chk);
            $done = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
            mysqli_stmt_close($chk);
            if ($done) {
                return [
                    'ok' => false,
                    'error' => 'You already completed today\'s CPA Challenge.',
                    'session_id' => (int) $done['session_id'],
                    'already_done' => true,
                ];
            }
        }
        // Deterministic seed per calendar day (shared question set identity).
        $seed = hash('sha256', 'daily:' . $dailyKey);
    } else {
        $seed = bin2hex(random_bytes(16));
    }

    $poolSubject = ($mode === 'subject_challenge') ? $subjectId : 0;
    $pool = student_playground_question_pool($conn, $userId, $poolSubject);
    if (count($pool) < 1) {
        return ['ok' => false, 'error' => 'No accessible quiz questions found for this challenge.'];
    }

    $shuffled = student_playground_seeded_shuffle($pool, $seed . ':pick');
    $selected = array_slice($shuffled, 0, min($count, count($shuffled)));
    if (count($selected) < 1) {
        return ['ok' => false, 'error' => 'Not enough questions available.'];
    }
    $actualCount = count($selected);

    // Abandon prior in-progress solo sessions for this user (keep history clean).
    @mysqli_query(
        $conn,
        "UPDATE student_playground_sessions SET status = 'abandoned'
         WHERE user_id = " . (int) $userId . " AND status = 'in_progress'"
    );

    $dailyKeyBind = $dailyKey ?? '';
    $hasPlayStyle = ereview_schema_column_exists($conn, 'student_playground_sessions', 'play_style');
    $hasTotal = ereview_schema_column_exists($conn, 'student_playground_sessions', 'total_time_seconds');
    $hasEnds = ereview_schema_column_exists($conn, 'student_playground_sessions', 'ends_at');

    if ($hasPlayStyle && $hasTotal && $hasEnds) {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO student_playground_sessions
              (user_id, mode, play_style, subject_id, status, question_count, seconds_per_question,
               total_time_seconds, difficulty, seed, daily_key, ends_at, current_ordinal)
             VALUES (?, ?, ?, NULLIF(?,0), \'in_progress\', ?, ?, ?, ?, ?, NULLIF(?, \'\'),
                     DATE_ADD(NOW(), INTERVAL ? SECOND), 1)'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Could not start session.'];
        }
        mysqli_stmt_bind_param(
            $stmt,
            'issiiiisssi',
            $userId,
            $mode,
            $playStyle,
            $subjectId,
            $actualCount,
            $secondsHint,
            $totalSeconds,
            $difficulty,
            $seed,
            $dailyKeyBind,
            $totalSeconds
        );
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO student_playground_sessions
              (user_id, mode, subject_id, status, question_count, seconds_per_question, difficulty, seed,
               daily_key)
             VALUES (?, ?, NULLIF(?,0), \'in_progress\', ?, ?, ?, ?, NULLIF(?, \'\'))'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Could not start session.'];
        }
        mysqli_stmt_bind_param(
            $stmt,
            'isiiisss',
            $userId,
            $mode,
            $subjectId,
            $actualCount,
            $secondsHint,
            $difficulty,
            $seed,
            $dailyKeyBind
        );
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Could not start session.'];
    }
    $sessionId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // Ensure ends_at for legacy insert path.
    if ($hasEnds && $hasTotal) {
        $u = mysqli_prepare(
            $conn,
            'UPDATE student_playground_sessions
             SET total_time_seconds = ?, ends_at = DATE_ADD(started_at, INTERVAL ? SECOND)
             WHERE session_id = ? AND user_id = ? AND ends_at IS NULL'
        );
        if ($u) {
            mysqli_stmt_bind_param($u, 'iiii', $totalSeconds, $totalSeconds, $sessionId, $userId);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
        }
    }

    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO student_playground_items
          (session_id, ordinal, question_id, quiz_id, subject_id, choice_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$ins) {
        return ['ok' => false, 'error' => 'Could not prepare questions.'];
    }

    $ordinal = 0;
    foreach ($selected as $row) {
        $ordinal++;
        $qid = (int) $row['question_id'];
        // Load letters for choice order (no correct answer exposed later via this string alone).
        $qstmt = mysqli_prepare($conn, 'SELECT * FROM quiz_questions WHERE question_id = ? LIMIT 1');
        $letters = ['A', 'B', 'C', 'D'];
        if ($qstmt) {
            mysqli_stmt_bind_param($qstmt, 'i', $qid);
            mysqli_stmt_execute($qstmt);
            $qrow = mysqli_fetch_assoc(mysqli_stmt_get_result($qstmt)) ?: [];
            mysqli_stmt_close($qstmt);
            if ($qrow) {
                $letters = student_playground_choice_letters_from_row($qrow);
            }
        }
        $choiceOrder = student_playground_shuffle_choice_order($letters, $seed, $qid);
        $quizId = (int) $row['quiz_id'];
        $sid = (int) $row['subject_id'];
        mysqli_stmt_bind_param($ins, 'iiiiis', $sessionId, $ordinal, $qid, $quizId, $sid, $choiceOrder);
        if (!mysqli_stmt_execute($ins)) {
            mysqli_stmt_close($ins);
            return ['ok' => false, 'error' => 'Could not save question set.'];
        }
    }
    mysqli_stmt_close($ins);

    return [
        'ok' => true,
        'session_id' => $sessionId,
        'question_count' => $actualCount,
        'total_time_seconds' => $totalSeconds,
        'play_style' => $playStyle,
    ];
}

function student_playground_session_get(mysqli $conn, int $userId, int $sessionId): ?array
{
    student_playground_ensure_schema($conn);
    if ($userId <= 0 || $sessionId <= 0) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM student_playground_sessions WHERE session_id = ? AND user_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $sessionId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}

/** Public question payload — never includes correct_answer. */
function student_playground_public_question(mysqli $conn, array $item, array $qRow): array
{
    $order = str_split(strtoupper((string) ($item['choice_order'] ?? 'ABCD')));
    $choices = [];
    $displayLetter = 'A';
    $idx = 0;
    foreach ($order as $origLetter) {
        if ($origLetter === '') {
            continue;
        }
        $key = 'choice_' . strtolower($origLetter);
        $text = trim((string) ($qRow[$key] ?? ''));
        if ($text === '') {
            continue;
        }
        // Display letters A,B,C… in shuffled content order; value stays ORIGINAL letter for server grading.
        $choices[] = [
            'display' => chr(65 + $idx),
            'letter' => $origLetter,
            'text' => $text,
        ];
        $idx++;
    }

    $subjectName = '';
    $sid = (int) ($item['subject_id'] ?? 0);
    if ($sid > 0) {
        $s = mysqli_prepare($conn, 'SELECT subject_name FROM subjects WHERE subject_id = ? LIMIT 1');
        if ($s) {
            mysqli_stmt_bind_param($s, 'i', $sid);
            mysqli_stmt_execute($s);
            $sr = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
            mysqli_stmt_close($s);
            $subjectName = (string) ($sr['subject_name'] ?? '');
        }
    }

    return [
        'ordinal' => (int) $item['ordinal'],
        'question_id' => (int) $item['question_id'],
        'question_text' => (string) ($qRow['question_text'] ?? ''),
        'choices' => $choices,
        'subject_id' => $sid,
        'subject_name' => $subjectName,
        'already_answered' => $item['selected_answer'] !== null && $item['selected_answer'] !== '',
    ];
}

/** @return list<array{ordinal:int,question_id:int,status:string,selected_display?:string}> */
function student_playground_navigator(mysqli $conn, int $sessionId, int $currentOrdinal): array
{
    $out = [];
    $stmt = mysqli_prepare(
        $conn,
        'SELECT ordinal, question_id, selected_answer, choice_order
         FROM student_playground_items WHERE session_id = ? ORDER BY ordinal ASC'
    );
    if (!$stmt) {
        return $out;
    }
    mysqli_stmt_bind_param($stmt, 'i', $sessionId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $sel = (string) ($r['selected_answer'] ?? '');
        // '-' is a finish-time skip marker, not a real answer during play.
        $answered = $sel !== '' && $sel !== '-';
        $ord = (int) $r['ordinal'];
        $status = 'unanswered';
        if ($ord === $currentOrdinal) {
            $status = 'current';
        } elseif ($answered) {
            $status = 'answered';
        }
        $row = [
            'ordinal' => $ord,
            'question_id' => (int) $r['question_id'],
            'status' => $status,
            'answered' => $answered,
        ];
        if ($answered && $sel !== '-') {
            $row['selected_display'] = student_playground_display_letter_for($r, $sel);
        }
        $out[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $out;
}

function student_playground_public_session(array $session): array
{
    $remaining = student_playground_remaining_total_seconds($session);
    $endsRaw = (string) ($session['ends_at'] ?? '');
    $endsIso = '';
    if ($endsRaw !== '') {
        $ts = strtotime($endsRaw);
        if ($ts !== false) {
            $endsIso = date('c', $ts);
        }
    }
    $startedRaw = (string) ($session['started_at'] ?? '');
    $startedIso = '';
    if ($startedRaw !== '') {
        $sts = strtotime($startedRaw);
        if ($sts !== false) {
            $startedIso = date('c', $sts);
        }
    }
    return [
        'session_id' => (int) $session['session_id'],
        'mode' => $session['mode'],
        'play_style' => (string) ($session['play_style'] ?? 'playground'),
        'question_count' => (int) $session['question_count'],
        'seconds_per_question' => (int) ($session['seconds_per_question'] ?? 0),
        'total_time_seconds' => (int) ($session['total_time_seconds'] ?? 0),
        'ends_at' => $endsIso !== '' ? $endsIso : $endsRaw,
        'started_at' => $startedIso !== '' ? $startedIso : $startedRaw,
        'remaining_total_seconds' => $remaining,
        'score' => (int) $session['score'],
        'correct_count' => (int) $session['correct_count'],
        'wrong_count' => (int) $session['wrong_count'],
        'current_streak' => (int) $session['current_streak'],
        'best_streak' => (int) $session['best_streak'],
        'answered_count' => (int) $session['answered_count'],
        'current_ordinal' => (int) ($session['current_ordinal'] ?? 1),
        'unanswered_count' => max(
            0,
            (int) $session['question_count'] - (int) $session['answered_count']
        ),
    ];
}

/**
 * Load a question by ordinal (free navigation). Never exposes correct_answer.
 *
 * @return array{ok:bool,error?:string,finished?:bool,session?:array,question?:array,navigator?:list}
 */
function student_playground_get_question(mysqli $conn, int $userId, int $sessionId, int $ordinal = 0): array
{
    $session = student_playground_session_get($conn, $userId, $sessionId);
    if (!$session) {
        return ['ok' => false, 'error' => 'Session not found.'];
    }
    if ($session['status'] !== 'in_progress') {
        return ['ok' => false, 'error' => 'Session is not in progress.', 'finished' => true, 'session' => $session];
    }

    // Total exam time expired → finish safely.
    if (student_playground_session_expired($session)) {
        $fin = student_playground_finish($conn, $userId, $sessionId);
        return [
            'ok' => true,
            'finished' => true,
            'time_expired' => true,
            'session' => $fin['session'] ?? $session,
        ];
    }

    $qCount = (int) $session['question_count'];
    if ($ordinal <= 0) {
        $ordinal = (int) ($session['current_ordinal'] ?? 1);
    }
    $ordinal = max(1, min($qCount, $ordinal));

    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM student_playground_items WHERE session_id = ? AND ordinal = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not load question.'];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $sessionId, $ordinal);
    mysqli_stmt_execute($stmt);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    if (!$item) {
        return ['ok' => false, 'error' => 'Question missing.'];
    }

    $qid = (int) $item['question_id'];
    $qstmt = mysqli_prepare($conn, 'SELECT * FROM quiz_questions WHERE question_id = ? LIMIT 1');
    if (!$qstmt) {
        return ['ok' => false, 'error' => 'Question missing.'];
    }
    mysqli_stmt_bind_param($qstmt, 'i', $qid);
    mysqli_stmt_execute($qstmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($qstmt)) ?: null;
    mysqli_stmt_close($qstmt);
    if (!$qRow) {
        return ['ok' => false, 'error' => 'Question missing.'];
    }

    if (empty($item['served_at'])) {
        $u = mysqli_prepare(
            $conn,
            'UPDATE student_playground_items SET served_at = NOW() WHERE item_id = ? AND session_id = ?'
        );
        if ($u) {
            $itemId = (int) $item['item_id'];
            mysqli_stmt_bind_param($u, 'ii', $itemId, $sessionId);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
        }
        $item['served_at'] = date('Y-m-d H:i:s');
    }

    if (ereview_schema_column_exists($conn, 'student_playground_sessions', 'current_ordinal')) {
        $cu = mysqli_prepare(
            $conn,
            'UPDATE student_playground_sessions SET current_ordinal = ? WHERE session_id = ? AND user_id = ?'
        );
        if ($cu) {
            mysqli_stmt_bind_param($cu, 'iii', $ordinal, $sessionId, $userId);
            mysqli_stmt_execute($cu);
            mysqli_stmt_close($cu);
            $session['current_ordinal'] = $ordinal;
        }
    }

    $pub = student_playground_public_question($conn, $item, $qRow);
    // For playground re-answer: include prior selection display only (not correctness) if answered.
    $sel = (string) ($item['selected_answer'] ?? '');
    if ($sel !== '' && $sel !== '-') {
        $pub['prior_selected'] = $sel;
        $pub['prior_selected_display'] = student_playground_display_letter_for($item, $sel);
    }

    return [
        'ok' => true,
        'finished' => false,
        'session' => student_playground_public_session($session),
        'question' => $pub,
        'navigator' => student_playground_navigator($conn, $sessionId, $ordinal),
        'remaining_total_seconds' => student_playground_remaining_total_seconds($session),
        // Legacy key kept empty — no per-question hard timer.
        'remaining_seconds' => null,
    ];
}

/** Back-compat wrapper: load current_ordinal (or first unanswered). */
function student_playground_get_current_question(mysqli $conn, int $userId, int $sessionId): array
{
    $session = student_playground_session_get($conn, $userId, $sessionId);
    if (!$session) {
        return ['ok' => false, 'error' => 'Session not found.'];
    }
    $ord = (int) ($session['current_ordinal'] ?? 0);
    if ($ord <= 0) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT ordinal FROM student_playground_items
             WHERE session_id = ? AND (selected_answer IS NULL OR selected_answer = \'\')
             ORDER BY ordinal ASC LIMIT 1'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $sessionId);
            mysqli_stmt_execute($stmt);
            $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $ord = $r ? (int) $r['ordinal'] : 1;
        } else {
            $ord = 1;
        }
    }
    return student_playground_get_question($conn, $userId, $sessionId, $ord);
}

/**
 * Map bank letter (original) → display letter for the shuffled choice_order on an item.
 */
function student_playground_display_letter_for(array $item, string $originalLetter): string
{
    $originalLetter = strtoupper(trim($originalLetter));
    if ($originalLetter === '') {
        return '';
    }
    $order = str_split(strtoupper((string) ($item['choice_order'] ?? 'ABCD')));
    $idx = 0;
    foreach ($order as $orig) {
        if ($orig === '') {
            continue;
        }
        if ($orig === $originalLetter) {
            return chr(65 + $idx);
        }
        $idx++;
    }
    return $originalLetter;
}

/**
 * Submit / change an answer. Playground style allows re-answer with score delta.
 * No per-question hard timeout — only total session ends_at.
 *
 * @return array{ok:bool,error?:string,data?:array,time_expired?:bool}
 */
function student_playground_submit_answer(
    mysqli $conn,
    int $userId,
    int $sessionId,
    int $questionId,
    string $selected,
    int $clientResponseMs = 0,
    bool $timedOut = false
): array {
    $session = student_playground_session_get($conn, $userId, $sessionId);
    if (!$session || $session['status'] !== 'in_progress') {
        return ['ok' => false, 'error' => 'Session not active.'];
    }

    if (student_playground_session_expired($session)) {
        $fin = student_playground_finish($conn, $userId, $sessionId);
        return [
            'ok' => false,
            'error' => 'Time expired.',
            'time_expired' => true,
            'finished' => true,
            'session' => $fin['session'] ?? $session,
        ];
    }

    $playStyle = (string) ($session['play_style'] ?? 'playground');
    $allowReanswer = ($playStyle === 'playground');

    $selected = strtoupper(trim($selected));
    $valid = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
    if (!$timedOut && !in_array($selected, $valid, true)) {
        return ['ok' => false, 'error' => 'Invalid answer.'];
    }
    if ($timedOut) {
        // Legacy path unused for per-Q; treat as skip marker if ever called.
        $selected = '-';
    }

    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM student_playground_items WHERE session_id = ? AND question_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Item not found.'];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $sessionId, $questionId);
    mysqli_stmt_execute($stmt);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    if (!$item) {
        return ['ok' => false, 'error' => 'Question not in this game.'];
    }

    $prevSel = (string) ($item['selected_answer'] ?? '');
    $wasAnswered = $prevSel !== '';
    if ($wasAnswered && !$allowReanswer) {
        return ['ok' => false, 'error' => 'Already answered.', 'duplicate' => true];
    }
    if ($wasAnswered && $prevSel === $selected) {
        // No-op same answer
        return [
            'ok' => true,
            'data' => [
                'is_correct' => (bool) $item['is_correct'],
                'points' => (int) $item['points'],
                'points_delta' => 0,
                'reanswer' => true,
                'unchanged' => true,
                'correct_answer' => '', // don't re-leak if somehow
                'score' => (int) $session['score'],
                'current_streak' => (int) $session['current_streak'],
                'best_streak' => (int) $session['best_streak'],
                'answered_count' => (int) $session['answered_count'],
                'question_count' => (int) $session['question_count'],
                'finished' => false,
                'question_id' => $questionId,
                'ordinal' => (int) $item['ordinal'],
            ],
        ];
    }

    $hasExpl = ereview_schema_column_exists($conn, 'quiz_questions', 'explanation');
    $explSel = $hasExpl ? ', explanation' : '';
    $qstmt = mysqli_prepare(
        $conn,
        "SELECT question_id, quiz_id, correct_answer{$explSel} FROM quiz_questions WHERE question_id = ? LIMIT 1"
    );
    if (!$qstmt) {
        return ['ok' => false, 'error' => 'Could not grade.'];
    }
    mysqli_stmt_bind_param($qstmt, 'i', $questionId);
    mysqli_stmt_execute($qstmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($qstmt)) ?: null;
    mysqli_stmt_close($qstmt);
    if (!$qRow) {
        return ['ok' => false, 'error' => 'Question missing.'];
    }

    $quizId = (int) ($qRow['quiz_id'] ?? $item['quiz_id'] ?? 0);
    if ($quizId > 0 && !sca_has_access($conn, $userId, 'quiz', $quizId)) {
        return ['ok' => false, 'error' => 'Access denied.'];
    }

    $correct = strtoupper(trim((string) ($qRow['correct_answer'] ?? '')));

    if (empty($item['served_at'])) {
        $su = mysqli_prepare(
            $conn,
            'UPDATE student_playground_items SET served_at = NOW() WHERE item_id = ? AND session_id = ?'
        );
        if ($su) {
            $iid = (int) $item['item_id'];
            mysqli_stmt_bind_param($su, 'ii', $iid, $sessionId);
            mysqli_stmt_execute($su);
            mysqli_stmt_close($su);
        }
        $item['served_at'] = date('Y-m-d H:i:s');
    }

    $servedTs = strtotime((string) $item['served_at']) ?: time();
    $serverElapsedMs = (int) max(0, (time() - $servedTs) * 1000);
    $elapsedMs = $serverElapsedMs;
    if ($clientResponseMs > 0) {
        $elapsedMs = min($elapsedMs, $clientResponseMs);
    }
    // Cap for scoring only (does not force wrong).
    $paceHint = max(20, (int) ($session['seconds_per_question'] ?? 40));
    $elapsedMs = min($elapsedMs, ($paceHint * 1000) + 60000);

    $isCorrect = ($selected !== '-' && $selected === $correct) ? 1 : 0;
    // Gentle speed bonus using pace hint — correctness still dominates.
    $points = ($selected === '-')
        ? 0
        : student_playground_compute_points($isCorrect === 1, $elapsedMs, $paceHint);

    $oldPoints = $wasAnswered ? (int) $item['points'] : 0;
    $oldCorrect = $wasAnswered ? (int) $item['is_correct'] : 0;
    $itemId = (int) $item['item_id'];

    $ustmt = mysqli_prepare(
        $conn,
        'UPDATE student_playground_items
         SET selected_answer = ?, is_correct = ?, points = ?, response_ms = ?, answered_at = NOW()
         WHERE item_id = ? AND session_id = ?'
    );
    if (!$ustmt) {
        return ['ok' => false, 'error' => 'Could not save answer.'];
    }
    mysqli_stmt_bind_param($ustmt, 'siiiii', $selected, $isCorrect, $points, $elapsedMs, $itemId, $sessionId);
    mysqli_stmt_execute($ustmt);
    mysqli_stmt_close($ustmt);

    // Recount aggregates from items (safe with re-answers).
    $agg = mysqli_prepare(
        $conn,
        "SELECT
            COALESCE(SUM(points),0) AS score,
            COALESCE(SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END),0) AS correct_count,
            COALESCE(SUM(CASE WHEN selected_answer IS NOT NULL AND selected_answer <> '' AND selected_answer <> '-' AND is_correct = 0 THEN 1 ELSE 0 END),0) AS wrong_count,
            COALESCE(SUM(CASE WHEN selected_answer IS NOT NULL AND selected_answer <> '' AND selected_answer <> '-' THEN 1 ELSE 0 END),0) AS answered_count,
            COALESCE(SUM(COALESCE(response_ms,0)),0) AS total_response_ms
         FROM student_playground_items WHERE session_id = ?"
    );
    $score = 0;
    $correctCount = 0;
    $wrongCount = 0;
    $answered = 0;
    $totalMs = 0;
    if ($agg) {
        mysqli_stmt_bind_param($agg, 'i', $sessionId);
        mysqli_stmt_execute($agg);
        $ar = mysqli_fetch_assoc(mysqli_stmt_get_result($agg)) ?: [];
        mysqli_stmt_close($agg);
        $score = (int) ($ar['score'] ?? 0);
        $correctCount = (int) ($ar['correct_count'] ?? 0);
        $wrongCount = (int) ($ar['wrong_count'] ?? 0);
        $answered = (int) ($ar['answered_count'] ?? 0);
        $totalMs = (int) ($ar['total_response_ms'] ?? 0);
    }

    $items = [];
    $ilist = mysqli_prepare(
        $conn,
        'SELECT selected_answer, is_correct FROM student_playground_items WHERE session_id = ? ORDER BY ordinal ASC'
    );
    if ($ilist) {
        mysqli_stmt_bind_param($ilist, 'i', $sessionId);
        mysqli_stmt_execute($ilist);
        $ires = mysqli_stmt_get_result($ilist);
        while ($ires && ($ir = mysqli_fetch_assoc($ires))) {
            $items[] = $ir;
        }
        mysqli_stmt_close($ilist);
    }
    $streaks = student_playground_streaks_from_items($items);
    $streak = $streaks['current_streak'];
    $best = max((int) $session['best_streak'], $streaks['best_streak']);

    $nextOrd = (int) $item['ordinal'];
    if (!$wasAnswered) {
        // Prefer next unanswered after this ordinal.
        $nq = mysqli_prepare(
            $conn,
            "SELECT ordinal FROM student_playground_items
             WHERE session_id = ? AND ordinal > ? AND (selected_answer IS NULL OR selected_answer = '')
             ORDER BY ordinal ASC LIMIT 1"
        );
        if ($nq) {
            $curOrd = (int) $item['ordinal'];
            mysqli_stmt_bind_param($nq, 'ii', $sessionId, $curOrd);
            mysqli_stmt_execute($nq);
            $nr = mysqli_fetch_assoc(mysqli_stmt_get_result($nq));
            mysqli_stmt_close($nq);
            if ($nr) {
                $nextOrd = (int) $nr['ordinal'];
            }
        }
    }

    $sstmt = mysqli_prepare(
        $conn,
        'UPDATE student_playground_sessions
         SET score=?, correct_count=?, wrong_count=?, answered_count=?, current_streak=?, best_streak=?,
             total_response_ms=?, current_ordinal=?
         WHERE session_id=? AND user_id=?'
    );
    if ($sstmt) {
        mysqli_stmt_bind_param(
            $sstmt,
            'iiiiiiiiii',
            $score,
            $correctCount,
            $wrongCount,
            $answered,
            $streak,
            $best,
            $totalMs,
            $nextOrd,
            $sessionId,
            $userId
        );
        mysqli_stmt_execute($sstmt);
        mysqli_stmt_close($sstmt);
    }

    // Do not auto-finish when all answered — student may review; finish via submit/time.
    $allAnswered = $answered >= (int) $session['question_count'];
    $milestone = !$wasAnswered && in_array($streak, [2, 3, 4, 5, 10], true);
    $correctDisplay = student_playground_display_letter_for($item, $correct);
    $selectedDisplay = ($selected === '-' || $selected === '')
        ? ''
        : student_playground_display_letter_for($item, $selected);

    return [
        'ok' => true,
        'data' => [
            'is_correct' => (bool) $isCorrect,
            'timed_out' => false,
            'reanswer' => $wasAnswered,
            'points' => $points,
            'points_delta' => $points - $oldPoints,
            'correct_answer' => $correct,
            'correct_display' => $correctDisplay,
            'selected_answer' => $selected === '-' ? '' : $selected,
            'selected_display' => $selectedDisplay,
            'score' => $score,
            'current_streak' => $streak,
            'best_streak' => $best,
            'streak_milestone' => $milestone,
            'answered_count' => $answered,
            'question_count' => (int) $session['question_count'],
            'unanswered_count' => max(0, (int) $session['question_count'] - $answered),
            'all_answered' => $allAnswered,
            'finished' => false,
            'next_ordinal' => $nextOrd,
            'ordinal' => (int) $item['ordinal'],
            'question_id' => $questionId,
            'quiz_id' => $quizId,
            'subject_id' => (int) ($item['subject_id'] ?? 0),
            'explanation' => trim((string) ($qRow['explanation'] ?? '')),
            'remaining_total_seconds' => student_playground_remaining_total_seconds(
                array_merge($session, ['ends_at' => $session['ends_at'] ?? null])
            ),
            'navigator' => student_playground_navigator($conn, $sessionId, (int) $item['ordinal']),
        ],
    ];
}

/**
 * @return array{ok:bool,session?:array}
 */
function student_playground_finish(mysqli $conn, int $userId, int $sessionId): array
{
    $session = student_playground_session_get($conn, $userId, $sessionId);
    if (!$session) {
        return ['ok' => false];
    }
    if ($session['status'] === 'completed') {
        return ['ok' => true, 'session' => $session];
    }

    // Mark unanswered as skipped (0 pts) when finishing / time expired.
    @mysqli_query(
        $conn,
        "UPDATE student_playground_items
         SET selected_answer = '-',
             is_correct = 0,
             points = 0,
             answered_at = COALESCE(answered_at, NOW())
         WHERE session_id = " . (int) $sessionId . "
           AND (selected_answer IS NULL OR selected_answer = '')"
    );

    // Recount final aggregates.
    $agg = @mysqli_query(
        $conn,
        "SELECT
            COALESCE(SUM(points),0) AS score,
            COALESCE(SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END),0) AS correct_count,
            COALESCE(SUM(CASE WHEN selected_answer <> '-' AND is_correct = 0 THEN 1 ELSE 0 END),0) AS wrong_count,
            COALESCE(SUM(CASE WHEN selected_answer IS NOT NULL AND selected_answer <> '' AND selected_answer <> '-' THEN 1 ELSE 0 END),0) AS answered_count
         FROM student_playground_items WHERE session_id = " . (int) $sessionId
    );
    $score = 0;
    $correctCount = 0;
    $wrongCount = 0;
    $answered = 0;
    if ($agg && ($ar = mysqli_fetch_assoc($agg))) {
        $score = (int) $ar['score'];
        $correctCount = (int) $ar['correct_count'];
        $wrongCount = (int) $ar['wrong_count'];
        $answered = (int) $ar['answered_count'];
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE student_playground_sessions
         SET status = 'completed', finished_at = NOW(), current_streak = 0,
             score = ?, correct_count = ?, wrong_count = ?, answered_count = ?
         WHERE session_id = ? AND user_id = ?"
    );
    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt,
            'iiiiii',
            $score,
            $correctCount,
            $wrongCount,
            $answered,
            $sessionId,
            $userId
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    $session = student_playground_session_get($conn, $userId, $sessionId);
    return ['ok' => true, 'session' => $session ?: [], 'time_expired' => student_playground_remaining_total_seconds($session ?: []) <= 0];
}

/**
 * @return array{by_subject:list<array>,weakest:?array,wrong:list<array>,stats:array}
 */
function student_playground_results(mysqli $conn, int $userId, int $sessionId): array
{
    $session = student_playground_session_get($conn, $userId, $sessionId);
    if (!$session) {
        return ['by_subject' => [], 'weakest' => null, 'wrong' => [], 'stats' => []];
    }

    $hasExpl = ereview_schema_column_exists($conn, 'quiz_questions', 'explanation');
    $explSel = $hasExpl ? ', qq.explanation' : ', \'\' AS explanation';
    $sql = "SELECT i.*, qq.question_text, qq.correct_answer{$explSel},
                   s.subject_name
            FROM student_playground_items i
            LEFT JOIN quiz_questions qq ON qq.question_id = i.question_id
            LEFT JOIN subjects s ON s.subject_id = i.subject_id
            WHERE i.session_id = ?
            ORDER BY i.ordinal ASC";
    $stmt = mysqli_prepare($conn, $sql);
    $items = [];
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $sessionId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $items[] = $r;
        }
        mysqli_stmt_close($stmt);
    }

    $by = [];
    $wrong = [];
    foreach ($items as $it) {
        $sid = (int) ($it['subject_id'] ?? 0);
        $name = (string) ($it['subject_name'] ?? 'General');
        if (!isset($by[$sid])) {
            $by[$sid] = ['subject_id' => $sid, 'subject_name' => $name, 'correct' => 0, 'total' => 0];
        }
        $by[$sid]['total']++;
        if (!empty($it['is_correct'])) {
            $by[$sid]['correct']++;
        } else {
            $wrong[] = [
                'question_id' => (int) $it['question_id'],
                'quiz_id' => (int) ($it['quiz_id'] ?? 0),
                'subject_id' => $sid,
                'subject_name' => $name,
                'ordinal' => (int) $it['ordinal'],
                'question_preview' => mb_substr(strip_tags((string) ($it['question_text'] ?? '')), 0, 180),
                'selected_answer' => (string) ($it['selected_answer'] ?? ''),
                'correct_answer' => (string) ($it['correct_answer'] ?? ''),
            ];
        }
    }

    $byList = array_values($by);
    $weakest = null;
    foreach ($byList as $b) {
        if ($b['total'] <= 0) {
            continue;
        }
        $acc = ($b['correct'] / $b['total']) * 100;
        $b['accuracy'] = round($acc, 1);
        if ($weakest === null || $acc < $weakest['accuracy']) {
            $weakest = $b + ['accuracy' => round($acc, 1)];
        }
    }
    foreach ($byList as &$b) {
        $b['accuracy'] = $b['total'] > 0 ? round(($b['correct'] / $b['total']) * 100, 1) : 0.0;
    }
    unset($b);

    $answered = max(1, (int) $session['answered_count']);
    $avgMs = ((int) $session['total_response_ms']) / $answered;
    $total = max(1, (int) $session['question_count']);
    $accuracy = round(((int) $session['correct_count'] / $total) * 100, 1);

    return [
        'session' => $session,
        'by_subject' => $byList,
        'weakest' => $weakest,
        'wrong' => $wrong,
        'stats' => [
            'score' => (int) $session['score'],
            'correct' => (int) $session['correct_count'],
            'total' => (int) $session['question_count'],
            'accuracy' => $accuracy,
            'best_streak' => (int) $session['best_streak'],
            'avg_response_sec' => round($avgMs / 1000, 1),
        ],
    ];
}

function student_playground_daily_status(mysqli $conn, int $userId): array
{
    student_playground_ensure_schema($conn);
    $key = student_playground_daily_key();
    $stmt = mysqli_prepare(
        $conn,
        "SELECT session_id, score, correct_count, question_count, status
         FROM student_playground_sessions
         WHERE user_id = ? AND mode = 'daily_challenge' AND daily_key = ?
         ORDER BY session_id DESC LIMIT 1"
    );
    $row = null;
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $userId, $key);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);
    }
    $completed = $row && ($row['status'] ?? '') === 'completed';
    $accuracy = null;
    if ($completed && (int) ($row['question_count'] ?? 0) > 0) {
        $accuracy = round(((int) $row['correct_count'] / (int) $row['question_count']) * 100, 0);
    }
    return [
        'daily_key' => $key,
        'completed' => $completed,
        'session_id' => $row ? (int) $row['session_id'] : null,
        'correct' => $row ? (int) $row['correct_count'] : null,
        'total' => $row ? (int) $row['question_count'] : 5,
        'accuracy' => $accuracy,
        'score' => $row ? (int) $row['score'] : null,
    ];
}

/**
 * Aggregate stats for the playground lobby HUD.
 * @return array{best_score:int,games_played:int,avg_accuracy:float,best_streak:int,total_points:int}
 */
function student_playground_user_stats(mysqli $conn, int $userId): array
{
    student_playground_ensure_schema($conn);
    $stats = [
        'best_score' => 0,
        'games_played' => 0,
        'avg_accuracy' => 0.0,
        'best_streak' => 0,
        'total_points' => 0,
    ];
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            COALESCE(MAX(score), 0) AS best_score,
            COUNT(*) AS games_played,
            COALESCE(SUM(score), 0) AS total_points,
            COALESCE(MAX(best_streak), 0) AS best_streak,
            COALESCE(SUM(correct_count), 0) AS sum_correct,
            COALESCE(SUM(question_count), 0) AS sum_total
         FROM student_playground_sessions
         WHERE user_id = ? AND status = 'completed'"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);
        if ($row) {
            $sumTotal = (int) ($row['sum_total'] ?? 0);
            $stats['best_score'] = (int) ($row['best_score'] ?? 0);
            $stats['games_played'] = (int) ($row['games_played'] ?? 0);
            $stats['total_points'] = (int) ($row['total_points'] ?? 0);
            $stats['best_streak'] = (int) ($row['best_streak'] ?? 0);
            $stats['avg_accuracy'] = $sumTotal > 0
                ? round(((int) $row['sum_correct'] / $sumTotal) * 100, 0)
                : 0.0;
        }
    }
    return $stats;
}

/**
 * Recent completed games for lobby.
 * @return list<array>
 */
function student_playground_recent_games(mysqli $conn, int $userId, int $limit = 8): array
{
    student_playground_ensure_schema($conn);
    $limit = max(1, min(20, $limit));
    $sql = "SELECT session_id, mode, score, correct_count, question_count, best_streak, finished_at, started_at
            FROM student_playground_sessions
            WHERE user_id = ? AND status = 'completed'
            ORDER BY COALESCE(finished_at, started_at) DESC
            LIMIT {$limit}";
    $stmt = mysqli_prepare($conn, $sql);
    $out = [];
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $total = max(1, (int) $r['question_count']);
            $out[] = [
                'session_id' => (int) $r['session_id'],
                'mode' => (string) $r['mode'],
                'score' => (int) $r['score'],
                'correct' => (int) $r['correct_count'],
                'total' => (int) $r['question_count'],
                'accuracy' => round(((int) $r['correct_count'] / $total) * 100, 0),
                'best_streak' => (int) $r['best_streak'],
                'finished_at' => (string) ($r['finished_at'] ?? $r['started_at'] ?? ''),
            ];
        }
        mysqli_stmt_close($stmt);
    }
    return $out;
}

/**
 * Personal rank of this session among the user's completed games (1 = best score).
 */
function student_playground_personal_rank(mysqli $conn, int $userId, int $sessionId, int $score): int
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS better
         FROM student_playground_sessions
         WHERE user_id = ? AND status = 'completed'
           AND (score > ? OR (score = ? AND session_id < ?))"
    );
    if (!$stmt) {
        return 1;
    }
    mysqli_stmt_bind_param($stmt, 'iiii', $userId, $score, $score, $sessionId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return ((int) ($row['better'] ?? 0)) + 1;
}

/** @return list<array{subject_id:int,subject_name:string,question_count:int}> */
function student_playground_subjects_with_counts(mysqli $conn, int $userId): array
{
    $pool = student_playground_question_pool($conn, $userId, 0);
    $counts = [];
    foreach ($pool as $p) {
        $sid = (int) $p['subject_id'];
        if ($sid <= 0) {
            continue;
        }
        $counts[$sid] = ($counts[$sid] ?? 0) + 1;
    }
    if ($counts === []) {
        return [];
    }
    $out = [];
    $res = @mysqli_query($conn, 'SELECT subject_id, subject_name FROM subjects WHERE status = \'active\' ORDER BY subject_name ASC');
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $sid = (int) $r['subject_id'];
            if (!isset($counts[$sid])) {
                continue;
            }
            $out[] = [
                'subject_id' => $sid,
                'subject_name' => (string) $r['subject_name'],
                'question_count' => (int) $counts[$sid],
            ];
        }
    }
    return $out;
}
