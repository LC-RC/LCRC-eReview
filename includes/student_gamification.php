<?php
/**
 * Phase 1 Career Gamification — config + server-side grant engine.
 * Assumes migration 039 tables exist; never creates tables from PHP.
 */
declare(strict_types=1);

require_once __DIR__ . '/schema_introspection.php';

/** @var array<string,int> */
const STUDENT_GAMIFICATION_XP_CAPS = [
    'formal_quiz' => 250,
    'playground' => 200,
    'battle' => 150,
];

/** @var array<string,string> event_type => cap bucket */
const STUDENT_GAMIFICATION_EVENT_BUCKETS = [
    'formal_quiz_completed' => 'formal_quiz',
    'formal_quiz_perfect' => 'formal_quiz',
    'formal_quiz_high_score' => 'formal_quiz',
    'playground_session_completed' => 'playground',
    'playground_daily_completed' => 'playground',
    'battle_completed' => 'battle',
    'battle_victory' => 'battle',
];

/**
 * Level thresholds: first matching max_xp >= total (or last tier).
 * Easy to adjust later; not stored in DB.
 *
 * @var list<array{level:int,min_xp:int,rank:string}>
 */
const STUDENT_GAMIFICATION_LEVELS = [
    ['level' => 1, 'min_xp' => 0, 'rank' => 'Trainee'],
    ['level' => 2, 'min_xp' => 100, 'rank' => 'Junior Examiner'],
    ['level' => 3, 'min_xp' => 250, 'rank' => 'Associate'],
    ['level' => 4, 'min_xp' => 500, 'rank' => 'Senior Associate'],
    ['level' => 5, 'min_xp' => 900, 'rank' => 'CPA Contender'],
    ['level' => 6, 'min_xp' => 1500, 'rank' => 'Board Ready'],
    ['level' => 7, 'min_xp' => 2500, 'rank' => 'Top Reviewer'],
    ['level' => 8, 'min_xp' => 4000, 'rank' => 'CPA Elite'],
    ['level' => 9, 'min_xp' => 6500, 'rank' => 'Master Reviewer'],
    ['level' => 10, 'min_xp' => 10000, 'rank' => 'Legend'],
];

/** @var array<string,string> */
const STUDENT_GAMIFICATION_ACHIEVEMENT_LABELS = [
    'first_quiz' => 'First Quiz',
    'first_playground' => 'First Playground',
    'first_daily' => 'First Daily Challenge',
    'first_battle' => 'First Battle',
    'streak_3' => '3-Day Streak',
    'streak_7' => '7-Day Streak',
    'streak_14' => '14-Day Streak',
    'streak_30' => '30-Day Streak',
    'perfect_quiz' => 'Perfect Quiz',
    'perfect_quiz_5' => '5 Perfect Quizzes',
    'high_score_10' => '10 Quizzes ≥90%',
    'questions_100' => '100 Questions Answered',
    'questions_500' => '500 Questions Answered',
    'battle_champion' => 'Battle Champion',
];

function student_gamification_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz instanceof DateTimeZone) {
        return $tz;
    }
    $tz = @timezone_open('Asia/Manila') ?: new DateTimeZone('UTC');
    return $tz;
}

function student_gamification_manila_today(?DateTimeInterface $when = null): string
{
    if ($when instanceof DateTimeInterface) {
        $dt = DateTimeImmutable::createFromInterface($when)->setTimezone(student_gamification_tz());
    } else {
        $dt = new DateTimeImmutable('now', student_gamification_tz());
    }
    return $dt->format('Y-m-d');
}

function student_gamification_daily_key_source_id(?DateTimeInterface $when = null): int
{
    return (int) str_replace('-', '', student_gamification_manila_today($when));
}

function student_gamification_tables_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = ereview_schema_table_exists($conn, 'student_gamification_profile')
        && ereview_schema_table_exists($conn, 'student_gamification_events')
        && ereview_schema_table_exists($conn, 'student_achievements');
    return $ready;
}

/**
 * @return array{level:int,rank:string,min_xp:int,next_min_xp:?int}
 */
function student_gamification_level_for_xp(int $totalXp): array
{
    $tiers = STUDENT_GAMIFICATION_LEVELS;
    $current = $tiers[0];
    foreach ($tiers as $tier) {
        if ($totalXp >= (int) $tier['min_xp']) {
            $current = $tier;
        }
    }
    $next = null;
    foreach ($tiers as $tier) {
        if ((int) $tier['min_xp'] > (int) $current['min_xp']) {
            $next = (int) $tier['min_xp'];
            break;
        }
    }
    return [
        'level' => (int) $current['level'],
        'rank' => (string) $current['rank'],
        'min_xp' => (int) $current['min_xp'],
        'next_min_xp' => $next,
    ];
}

/**
 * @return array{
 *   ready:bool,
 *   total_xp:int,
 *   level:int,
 *   rank:string,
 *   current_streak_days:int,
 *   longest_streak_days:int,
 *   last_qualifying_activity_date:?string,
 *   achievements:list<array{key:string,label:string,unlocked_at:string}>
 * }
 */
function student_gamification_get_progress(mysqli $conn, int $userId): array
{
    $empty = [
        'ready' => false,
        'total_xp' => 0,
        'level' => 1,
        'rank' => STUDENT_GAMIFICATION_LEVELS[0]['rank'],
        'current_streak_days' => 0,
        'longest_streak_days' => 0,
        'last_qualifying_activity_date' => null,
        'achievements' => [],
    ];
    if ($userId <= 0 || !student_gamification_tables_ready($conn)) {
        return $empty;
    }

    $profile = student_gamification_profile_fetch($conn, $userId);
    $xp = (int) ($profile['total_xp'] ?? 0);
    $tier = student_gamification_level_for_xp($xp);

    $achievements = [];
    $stmt = mysqli_prepare(
        $conn,
        'SELECT achievement_key, unlocked_at FROM student_achievements
         WHERE user_id = ? ORDER BY unlocked_at ASC, achievement_key ASC'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $key = (string) ($row['achievement_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $achievements[] = [
                'key' => $key,
                'label' => STUDENT_GAMIFICATION_ACHIEVEMENT_LABELS[$key] ?? $key,
                'unlocked_at' => (string) ($row['unlocked_at'] ?? ''),
            ];
        }
        mysqli_stmt_close($stmt);
    }

    return [
        'ready' => true,
        'total_xp' => $xp,
        'level' => $tier['level'],
        'rank' => $tier['rank'],
        'current_streak_days' => (int) ($profile['current_streak_days'] ?? 0),
        'longest_streak_days' => (int) ($profile['longest_streak_days'] ?? 0),
        'last_qualifying_activity_date' => $profile['last_qualifying_activity_date'] ?? null,
        'achievements' => $achievements,
    ];
}

/**
 * Hook: formal quiz attempt finalized to submitted.
 */
function student_gamification_on_formal_quiz_submitted(mysqli $conn, int $userId, int $attemptId): void
{
    if ($userId <= 0 || $attemptId <= 0 || !student_gamification_tables_ready($conn)) {
        return;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT attempt_id, user_id, quiz_id, status FROM quiz_attempts
         WHERE attempt_id = ? AND user_id = ? LIMIT 1"
    );
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $userId);
    mysqli_stmt_execute($stmt);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    if (!$attempt || ($attempt['status'] ?? '') !== 'submitted') {
        return;
    }

    $quizId = (int) ($attempt['quiz_id'] ?? 0);
    $strict = student_gamification_formal_strict_stats($conn, $attemptId, $quizId);
    $correctFull = (int) $strict['correct'];
    $xpRaw = 25 + min(40, 2 * $correctFull);

    student_gamification_grant_event($conn, $userId, 'formal_quiz_completed', 'quiz_attempts', $attemptId, $xpRaw, [
        'quiz_id' => $quizId,
        'correct_full' => $correctFull,
        'question_count' => (int) $strict['question_count'],
        'answered_full' => (int) $strict['answered'],
    ]);

    if ($strict['is_perfect']) {
        student_gamification_grant_event($conn, $userId, 'formal_quiz_perfect', 'quiz_attempts', $attemptId, 30, [
            'quiz_id' => $quizId,
            'question_count' => (int) $strict['question_count'],
        ]);
    } elseif ($strict['is_high_score']) {
        student_gamification_grant_event($conn, $userId, 'formal_quiz_high_score', 'quiz_attempts', $attemptId, 15, [
            'quiz_id' => $quizId,
            'ratio' => $strict['ratio'],
            'question_count' => (int) $strict['question_count'],
        ]);
    }
}

/**
 * Hook: solo Playground session finished (or already completed — idempotent).
 */
function student_gamification_on_playground_finished(mysqli $conn, int $userId, int $sessionId): void
{
    if ($userId <= 0 || $sessionId <= 0 || !student_gamification_tables_ready($conn)) {
        return;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT session_id, user_id, mode, status, correct_count, question_count, daily_key
         FROM student_playground_sessions
         WHERE session_id = ? AND user_id = ? LIMIT 1"
    );
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $sessionId, $userId);
    mysqli_stmt_execute($stmt);
    $session = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    if (!$session || ($session['status'] ?? '') !== 'completed') {
        return;
    }

    $mode = (string) ($session['mode'] ?? '');
    $perfect = student_gamification_playground_session_is_perfect($conn, $sessionId);
    $correct = (int) ($session['correct_count'] ?? 0);

    if ($mode === 'daily_challenge') {
        $dailyKey = trim((string) ($session['daily_key'] ?? ''));
        if ($dailyKey === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dailyKey)) {
            $dailyKey = student_gamification_manila_today();
        }
        $sourceId = (int) str_replace('-', '', $dailyKey);
        $xpRaw = 40 + min(30, $correct) + ($perfect ? 20 : 0);
        student_gamification_grant_event($conn, $userId, 'playground_daily_completed', 'playground_daily_key', $sourceId, $xpRaw, [
            'session_id' => $sessionId,
            'daily_key' => $dailyKey,
            'correct' => $correct,
            'perfect' => $perfect,
        ]);
        return;
    }

    $xpRaw = 15 + min(30, $correct) + ($perfect ? 20 : 0);
    student_gamification_grant_event($conn, $userId, 'playground_session_completed', 'student_playground_sessions', $sessionId, $xpRaw, [
        'mode' => $mode,
        'correct' => $correct,
        'perfect' => $perfect,
        'question_count' => (int) ($session['question_count'] ?? 0),
    ]);
}

/**
 * Hook: battle game finished (ranks already written).
 */
function student_gamification_on_battle_finished(mysqli $conn, int $gameId): void
{
    if ($gameId <= 0 || !student_gamification_tables_ready($conn)) {
        return;
    }

    $gstmt = mysqli_prepare(
        $conn,
        "SELECT game_id, status FROM student_playground_games WHERE game_id = ? LIMIT 1"
    );
    if (!$gstmt) {
        return;
    }
    mysqli_stmt_bind_param($gstmt, 'i', $gameId);
    mysqli_stmt_execute($gstmt);
    $game = mysqli_fetch_assoc(mysqli_stmt_get_result($gstmt)) ?: null;
    mysqli_stmt_close($gstmt);
    if (!$game || ($game['status'] ?? '') !== 'finished') {
        return;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT player_id, user_id, final_rank, status
         FROM student_playground_game_players
         WHERE game_id = ? AND status <> 'left'"
    );
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'i', $gameId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($p = mysqli_fetch_assoc($res))) {
        $userId = (int) ($p['user_id'] ?? 0);
        $playerId = (int) ($p['player_id'] ?? 0);
        if ($userId <= 0 || $playerId <= 0) {
            continue;
        }
        student_gamification_grant_event($conn, $userId, 'battle_completed', 'student_playground_game_players', $playerId, 25, [
            'game_id' => $gameId,
            'final_rank' => $p['final_rank'] !== null ? (int) $p['final_rank'] : null,
        ]);
        if ((int) ($p['final_rank'] ?? 0) === 1) {
            student_gamification_grant_event($conn, $userId, 'battle_victory', 'student_playground_game_players', $playerId, 35, [
                'game_id' => $gameId,
            ]);
        }
    }
    mysqli_stmt_close($stmt);
}

/**
 * Core grant: insert event first, then profile/streak/achievements.
 *
 * @param array<string,mixed> $meta
 * @return array{ok:bool,inserted:bool,xp_delta:int,error?:string}
 */
function student_gamification_grant_event(
    mysqli $conn,
    int $userId,
    string $eventType,
    string $sourceTable,
    int $sourceId,
    int $xpRaw,
    array $meta = []
): array {
    if ($userId <= 0 || $sourceId <= 0 || $eventType === '' || $sourceTable === '') {
        return ['ok' => false, 'inserted' => false, 'xp_delta' => 0, 'error' => 'invalid_args'];
    }
    if (!student_gamification_tables_ready($conn)) {
        return ['ok' => false, 'inserted' => false, 'xp_delta' => 0, 'error' => 'tables_missing'];
    }
    if (!isset(STUDENT_GAMIFICATION_EVENT_BUCKETS[$eventType])) {
        return ['ok' => false, 'inserted' => false, 'xp_delta' => 0, 'error' => 'unknown_event'];
    }

    $bucket = STUDENT_GAMIFICATION_EVENT_BUCKETS[$eventType];
    $cap = (int) (STUDENT_GAMIFICATION_XP_CAPS[$bucket] ?? 0);
    $manilaDate = student_gamification_manila_today();
    $xpRaw = max(0, $xpRaw);

    $startedTx = false;
    try {
        if (!mysqli_begin_transaction($conn)) {
            return ['ok' => false, 'inserted' => false, 'xp_delta' => 0, 'error' => 'begin_failed'];
        }
        $startedTx = true;

        student_gamification_ensure_profile($conn, $userId);

        // Serialize per-user grants so concurrent different events cannot over-read daily room.
        $lockStmt = mysqli_prepare(
            $conn,
            'SELECT user_id FROM student_gamification_profile WHERE user_id = ? FOR UPDATE'
        );
        if (!$lockStmt) {
            throw new RuntimeException('prepare_profile_lock_failed');
        }
        mysqli_stmt_bind_param($lockStmt, 'i', $userId);
        if (!mysqli_stmt_execute($lockStmt)) {
            mysqli_stmt_close($lockStmt);
            throw new RuntimeException('profile_lock_failed');
        }
        $lockRow = mysqli_fetch_assoc(mysqli_stmt_get_result($lockStmt));
        mysqli_stmt_close($lockStmt);
        if (!$lockRow) {
            throw new RuntimeException('profile_lock_missing');
        }

        $sumToday = student_gamification_sum_bucket_xp_for_date($conn, $userId, $bucket, $manilaDate);
        $room = max(0, $cap - $sumToday);
        $xpGrant = min($xpRaw, $room);
        $capped = $xpGrant < $xpRaw;

        $metaOut = array_merge($meta, [
            'manila_date' => $manilaDate,
            'xp_raw' => $xpRaw,
            'room' => $room,
            'capped' => $capped,
            'bucket' => $bucket,
        ]);
        $metaJson = json_encode($metaOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metaJson === false) {
            $metaJson = '{"manila_date":"' . $manilaDate . '","capped":' . ($capped ? 'true' : 'false') . '}';
        }

        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO student_gamification_events
                (user_id, event_type, source_table, source_id, xp_delta, meta_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$ins) {
            throw new RuntimeException('prepare_insert_failed');
        }
        mysqli_stmt_bind_param(
            $ins,
            'issiis',
            $userId,
            $eventType,
            $sourceTable,
            $sourceId,
            $xpGrant,
            $metaJson
        );
        $executed = mysqli_stmt_execute($ins);
        $errno = mysqli_stmt_errno($ins);
        mysqli_stmt_close($ins);

        if (!$executed) {
            if ($errno === 1062) {
                mysqli_rollback($conn);
                return ['ok' => true, 'inserted' => false, 'xp_delta' => 0];
            }
            throw new RuntimeException('insert_failed:' . $errno);
        }

        if ($xpGrant > 0) {
            $upd = mysqli_prepare(
                $conn,
                'UPDATE student_gamification_profile
                 SET total_xp = total_xp + ?
                 WHERE user_id = ?'
            );
            if (!$upd) {
                throw new RuntimeException('prepare_xp_failed');
            }
            mysqli_stmt_bind_param($upd, 'ii', $xpGrant, $userId);
            if (!mysqli_stmt_execute($upd)) {
                mysqli_stmt_close($upd);
                throw new RuntimeException('xp_update_failed');
            }
            mysqli_stmt_close($upd);
        }

        student_gamification_apply_streak($conn, $userId, $manilaDate);
        student_gamification_evaluate_achievements($conn, $userId);

        mysqli_commit($conn);
        return ['ok' => true, 'inserted' => true, 'xp_delta' => $xpGrant];
    } catch (Throwable $e) {
        if ($startedTx) {
            @mysqli_rollback($conn);
        }
        return ['ok' => false, 'inserted' => false, 'xp_delta' => 0, 'error' => $e->getMessage()];
    }
}

function student_gamification_ensure_profile(mysqli $conn, int $userId): void
{
    $stmt = mysqli_prepare(
        $conn,
        'INSERT IGNORE INTO student_gamification_profile
            (user_id, total_xp, current_streak_days, longest_streak_days, last_qualifying_activity_date)
         VALUES (?, 0, 0, 0, NULL)'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/**
 * @return array<string,mixed>
 */
function student_gamification_profile_fetch(mysqli $conn, int $userId): array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT user_id, total_xp, current_streak_days, longest_streak_days,
                last_qualifying_activity_date, updated_at
         FROM student_gamification_profile WHERE user_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
    mysqli_stmt_close($stmt);
    return $row;
}

function student_gamification_sum_bucket_xp_for_date(mysqli $conn, int $userId, string $bucket, string $manilaDate): int
{
    $types = [];
    foreach (STUDENT_GAMIFICATION_EVENT_BUCKETS as $eventType => $b) {
        if ($b === $bucket) {
            $types[] = $eventType;
        }
    }
    if ($types === []) {
        return 0;
    }

    $sum = 0;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COALESCE(SUM(xp_delta), 0) AS s
         FROM student_gamification_events
         WHERE user_id = ?
           AND event_type = ?
           AND JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.manila_date')) = ?"
    );
    if (!$stmt) {
        return 0;
    }
    foreach ($types as $eventType) {
        mysqli_stmt_bind_param($stmt, 'iss', $userId, $eventType, $manilaDate);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $sum += (int) ($row['s'] ?? 0);
    }
    mysqli_stmt_close($stmt);
    return $sum;
}

function student_gamification_apply_streak(mysqli $conn, int $userId, string $today): void
{
    $profile = student_gamification_profile_fetch($conn, $userId);
    $last = $profile['last_qualifying_activity_date'] ?? null;
    $last = $last !== null && $last !== '' ? (string) $last : null;
    $current = (int) ($profile['current_streak_days'] ?? 0);
    $longest = (int) ($profile['longest_streak_days'] ?? 0);

    if ($last === $today) {
        return;
    }

    $yesterday = (new DateTimeImmutable($today, student_gamification_tz()))
        ->modify('-1 day')
        ->format('Y-m-d');

    if ($last === $yesterday) {
        $current += 1;
    } else {
        $current = 1;
    }
    if ($current > $longest) {
        $longest = $current;
    }

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE student_gamification_profile
         SET current_streak_days = ?, longest_streak_days = ?, last_qualifying_activity_date = ?
         WHERE user_id = ?'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'iisi', $current, $longest, $today, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/**
 * @return array{
 *   question_count:int,
 *   answered:int,
 *   correct:int,
 *   ratio:float,
 *   is_perfect:bool,
 *   is_high_score:bool
 * }
 */
function student_gamification_formal_strict_stats(mysqli $conn, int $attemptId, int $quizId): array
{
    $empty = [
        'question_count' => 0,
        'answered' => 0,
        'correct' => 0,
        'ratio' => 0.0,
        'is_perfect' => false,
        'is_high_score' => false,
    ];
    if ($attemptId <= 0 || $quizId <= 0) {
        return $empty;
    }

    $qRes = mysqli_query(
        $conn,
        'SELECT COUNT(*) AS c FROM quiz_questions WHERE quiz_id = ' . (int) $quizId
    );
    $Q = $qRes ? (int) (mysqli_fetch_assoc($qRes)['c'] ?? 0) : 0;
    if ($Q < 1) {
        return $empty;
    }

    $aRes = mysqli_query(
        $conn,
        'SELECT COUNT(DISTINCT qa.question_id) AS answered,
                COALESCE(SUM(CASE WHEN qa.is_correct = 1 THEN 1 ELSE 0 END), 0) AS correct
         FROM quiz_answers qa
         INNER JOIN quiz_questions qq ON qq.question_id = qa.question_id AND qq.quiz_id = ' . (int) $quizId . '
         WHERE qa.attempt_id = ' . (int) $attemptId
    );
    $answered = 0;
    $correct = 0;
    if ($aRes && ($ar = mysqli_fetch_assoc($aRes))) {
        $answered = (int) ($ar['answered'] ?? 0);
        $correct = (int) ($ar['correct'] ?? 0);
    }

    $fullyAnswered = ($answered >= $Q);
    $ratio = $Q > 0 ? ($correct / $Q) : 0.0;
    $isPerfect = ($Q >= 5 && $fullyAnswered && $correct === $Q);
    $isHigh = ($Q >= 5 && $fullyAnswered && $ratio >= 0.90 && $ratio < 1.0);

    return [
        'question_count' => $Q,
        'answered' => $answered,
        'correct' => $correct,
        'ratio' => $ratio,
        'is_perfect' => $isPerfect,
        'is_high_score' => $isHigh,
    ];
}

function student_gamification_playground_session_is_perfect(mysqli $conn, int $sessionId): bool
{
    $res = mysqli_query(
        $conn,
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END), 0) AS correct,
                COALESCE(SUM(CASE
                    WHEN selected_answer IS NULL OR selected_answer = \'\' OR selected_answer = \'-\' THEN 1
                    ELSE 0 END), 0) AS skipped
         FROM student_playground_items
         WHERE session_id = ' . (int) $sessionId
    );
    if (!$res || !($row = mysqli_fetch_assoc($res))) {
        return false;
    }
    $total = (int) ($row['total'] ?? 0);
    $correct = (int) ($row['correct'] ?? 0);
    $skipped = (int) ($row['skipped'] ?? 0);
    return $total > 0 && $skipped === 0 && $correct === $total;
}

function student_gamification_count_events(mysqli $conn, int $userId, string $eventType): int
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS c FROM student_gamification_events
         WHERE user_id = ? AND event_type = ?'
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'is', $userId, $eventType);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (int) ($row['c'] ?? 0);
}

function student_gamification_count_high_or_perfect_attempts(mysqli $conn, int $userId): int
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(DISTINCT source_id) AS c
         FROM student_gamification_events
         WHERE user_id = ?
           AND event_type IN ('formal_quiz_perfect', 'formal_quiz_high_score')"
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (int) ($row['c'] ?? 0);
}

function student_gamification_count_questions_answered(mysqli $conn, int $userId): int
{
    $formal = 0;
    $sqlFormal = "SELECT COUNT(*) AS c
                  FROM quiz_answers qa
                  INNER JOIN quiz_attempts a ON a.attempt_id = qa.attempt_id
                  WHERE a.user_id = ? AND a.status = 'submitted'";
    $stmt = mysqli_prepare($conn, $sqlFormal);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $formal = (int) ($row['c'] ?? 0);
    }

    $pg = 0;
    if (ereview_schema_table_exists($conn, 'student_playground_items')
        && ereview_schema_table_exists($conn, 'student_playground_sessions')) {
        $sqlPg = "SELECT COUNT(*) AS c
                  FROM student_playground_items i
                  INNER JOIN student_playground_sessions s ON s.session_id = i.session_id
                  WHERE s.user_id = ?
                    AND s.status = 'completed'
                    AND i.selected_answer IS NOT NULL
                    AND i.selected_answer <> ''
                    AND i.selected_answer <> '-'";
        $stmt2 = mysqli_prepare($conn, $sqlPg);
        if ($stmt2) {
            mysqli_stmt_bind_param($stmt2, 'i', $userId);
            mysqli_stmt_execute($stmt2);
            $row2 = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));
            mysqli_stmt_close($stmt2);
            $pg = (int) ($row2['c'] ?? 0);
        }
    }

    return $formal + $pg;
}

function student_gamification_has_first_playground(mysqli $conn, int $userId): bool
{
    if (student_gamification_count_events($conn, $userId, 'playground_session_completed') > 0) {
        return true;
    }
    if (student_gamification_count_events($conn, $userId, 'playground_daily_completed') > 0) {
        return true;
    }
    if (!ereview_schema_table_exists($conn, 'student_playground_sessions')) {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1 FROM student_playground_sessions
         WHERE user_id = ? AND status = 'completed' LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (bool) $row;
}

function student_gamification_unlock(mysqli $conn, int $userId, string $key): void
{
    if ($key === '' || !isset(STUDENT_GAMIFICATION_ACHIEVEMENT_LABELS[$key])) {
        return;
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT IGNORE INTO student_achievements (user_id, achievement_key) VALUES (?, ?)'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $userId, $key);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function student_gamification_evaluate_achievements(mysqli $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    if (student_gamification_count_events($conn, $userId, 'formal_quiz_completed') >= 1) {
        student_gamification_unlock($conn, $userId, 'first_quiz');
    }
    if (student_gamification_has_first_playground($conn, $userId)) {
        student_gamification_unlock($conn, $userId, 'first_playground');
    }
    if (student_gamification_count_events($conn, $userId, 'playground_daily_completed') >= 1) {
        student_gamification_unlock($conn, $userId, 'first_daily');
    }
    if (student_gamification_count_events($conn, $userId, 'battle_completed') >= 1) {
        student_gamification_unlock($conn, $userId, 'first_battle');
    }
    if (student_gamification_count_events($conn, $userId, 'battle_victory') >= 1) {
        student_gamification_unlock($conn, $userId, 'battle_champion');
    }
    if (student_gamification_count_events($conn, $userId, 'formal_quiz_perfect') >= 1) {
        student_gamification_unlock($conn, $userId, 'perfect_quiz');
    }
    if (student_gamification_count_events($conn, $userId, 'formal_quiz_perfect') >= 5) {
        student_gamification_unlock($conn, $userId, 'perfect_quiz_5');
    }
    if (student_gamification_count_high_or_perfect_attempts($conn, $userId) >= 10) {
        student_gamification_unlock($conn, $userId, 'high_score_10');
    }

    $profile = student_gamification_profile_fetch($conn, $userId);
    $streak = max(
        (int) ($profile['current_streak_days'] ?? 0),
        (int) ($profile['longest_streak_days'] ?? 0)
    );
    foreach ([3 => 'streak_3', 7 => 'streak_7', 14 => 'streak_14', 30 => 'streak_30'] as $n => $key) {
        if ($streak >= $n) {
            student_gamification_unlock($conn, $userId, $key);
        }
    }

    $qCount = student_gamification_count_questions_answered($conn, $userId);
    if ($qCount >= 100) {
        student_gamification_unlock($conn, $userId, 'questions_100');
    }
    if ($qCount >= 500) {
        student_gamification_unlock($conn, $userId, 'questions_500');
    }
}

// ---------------------------------------------------------------------------
// Phase 2 — read-only presentation helpers (no grants / unlocks / writes)
// ---------------------------------------------------------------------------

/** @var array<string,string> */
const STUDENT_GAMIFICATION_ACHIEVEMENT_DESCRIPTIONS = [
    'first_quiz' => 'Complete your first formal quiz.',
    'first_playground' => 'Finish a CPA Playground session or Daily Challenge.',
    'first_daily' => 'Complete your first Daily Challenge.',
    'first_battle' => 'Finish a CPA Battle game.',
    'streak_3' => 'Maintain a 3-day qualifying activity streak.',
    'streak_7' => 'Maintain a 7-day qualifying activity streak.',
    'streak_14' => 'Maintain a 14-day qualifying activity streak.',
    'streak_30' => 'Maintain a 30-day qualifying activity streak.',
    'perfect_quiz' => 'Earn a perfect score on a full formal quiz.',
    'perfect_quiz_5' => 'Earn five perfect formal quiz scores.',
    'high_score_10' => 'Reach 90% or higher on ten formal quizzes.',
    'questions_100' => 'Answer 100 questions across quizzes and Playground.',
    'questions_500' => 'Answer 500 questions across quizzes and Playground.',
    'battle_champion' => 'Win your first CPA Battle.',
];

/** @var array<string,string> */
const STUDENT_GAMIFICATION_EVENT_DISPLAY_LABELS = [
    'formal_quiz_completed' => 'Formal Quiz Completed',
    'formal_quiz_perfect' => 'Perfect Quiz Bonus',
    'formal_quiz_high_score' => 'High Score Bonus',
    'playground_session_completed' => 'Playground Session',
    'playground_daily_completed' => 'Daily Challenge',
    'battle_completed' => 'Battle Completed',
    'battle_victory' => 'Battle Victory',
];

/** @var array<string,string> */
const STUDENT_GAMIFICATION_BUCKET_DISPLAY_LABELS = [
    'formal_quiz' => 'Formal Quiz',
    'playground' => 'Playground',
    'battle' => 'Battle',
];

function student_gamification_event_display_label(string $eventType): string
{
    return STUDENT_GAMIFICATION_EVENT_DISPLAY_LABELS[$eventType]
        ?? ucwords(str_replace('_', ' ', $eventType));
}

function student_gamification_format_event_datetime(string $createdAt): string
{
    $raw = trim($createdAt);
    if ($raw === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($raw, student_gamification_tz());
        return $dt->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return $raw;
    }
}

/**
 * @return array{
 *   ready:bool,
 *   total_xp:int,
 *   level:int,
 *   rank:string,
 *   current_streak_days:int,
 *   longest_streak_days:int,
 *   last_qualifying_activity_date:?string,
 *   achievements:list<array{key:string,label:string,unlocked_at:string}>,
 *   min_xp:int,
 *   next_min_xp:?int,
 *   xp_in_level:int,
 *   xp_span:int,
 *   xp_to_next:?int,
 *   progress_pct:float,
 *   is_max_level:bool
 * }
 */
function student_gamification_career_summary(mysqli $conn, int $userId): array
{
    $base = student_gamification_get_progress($conn, $userId);
    $tier = student_gamification_level_for_xp((int) ($base['total_xp'] ?? 0));
    $totalXp = (int) ($base['total_xp'] ?? 0);
    $minXp = (int) ($tier['min_xp'] ?? 0);
    $nextMin = $tier['next_min_xp'] ?? null;
    $isMax = $nextMin === null;
    $xpInLevel = max(0, $totalXp - $minXp);
    $xpSpan = $isMax ? max(1, $xpInLevel) : max(1, (int) $nextMin - $minXp);
    $xpToNext = $isMax ? null : max(0, (int) $nextMin - $totalXp);
    $progressPct = $isMax ? 100.0 : min(100.0, max(0.0, ($xpInLevel / $xpSpan) * 100.0));

    return array_merge($base, [
        'min_xp' => $minXp,
        'next_min_xp' => $nextMin,
        'xp_in_level' => $xpInLevel,
        'xp_span' => $xpSpan,
        'xp_to_next' => $xpToNext,
        'progress_pct' => round($progressPct, 1),
        'is_max_level' => $isMax,
    ]);
}

/**
 * @return list<array<string,mixed>>
 */
function student_gamification_list_events(mysqli $conn, int $userId, int $limit = 30, int $offset = 0): array
{
    if ($userId <= 0 || !student_gamification_tables_ready($conn)) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $stmt = mysqli_prepare(
        $conn,
        'SELECT event_id, event_type, source_table, source_id, xp_delta, meta_json, created_at
         FROM student_gamification_events
         WHERE user_id = ?
         ORDER BY created_at DESC, event_id DESC
         LIMIT ? OFFSET ?'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'iii', $userId, $limit, $offset);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $meta = [];
        if (!empty($row['meta_json'])) {
            $decoded = json_decode((string) $row['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $eventType = (string) ($row['event_type'] ?? '');
        $bucket = (string) ($meta['bucket'] ?? (STUDENT_GAMIFICATION_EVENT_BUCKETS[$eventType] ?? ''));
        $rows[] = [
            'event_id' => (int) ($row['event_id'] ?? 0),
            'event_type' => $eventType,
            'label' => student_gamification_event_display_label($eventType),
            'source_table' => (string) ($row['source_table'] ?? ''),
            'source_id' => (int) ($row['source_id'] ?? 0),
            'xp_delta' => (int) ($row['xp_delta'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_at_display' => student_gamification_format_event_datetime((string) ($row['created_at'] ?? '')),
            'bucket' => $bucket,
            'bucket_label' => STUDENT_GAMIFICATION_BUCKET_DISPLAY_LABELS[$bucket] ?? $bucket,
            'capped' => !empty($meta['capped']),
            'meta' => $meta,
        ];
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function student_gamification_events_for_source(mysqli $conn, int $userId, string $sourceTable, int $sourceId): array
{
    if ($userId <= 0 || $sourceId <= 0 || $sourceTable === '' || !student_gamification_tables_ready($conn)) {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT event_id, event_type, source_table, source_id, xp_delta, meta_json, created_at
         FROM student_gamification_events
         WHERE user_id = ? AND source_table = ? AND source_id = ?
         ORDER BY created_at ASC, event_id ASC'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'isi', $userId, $sourceTable, $sourceId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $meta = [];
        if (!empty($row['meta_json'])) {
            $decoded = json_decode((string) $row['meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $eventType = (string) ($row['event_type'] ?? '');
        $bucket = (string) ($meta['bucket'] ?? (STUDENT_GAMIFICATION_EVENT_BUCKETS[$eventType] ?? ''));
        $rows[] = [
            'event_id' => (int) ($row['event_id'] ?? 0),
            'event_type' => $eventType,
            'label' => student_gamification_event_display_label($eventType),
            'source_table' => (string) ($row['source_table'] ?? ''),
            'source_id' => (int) ($row['source_id'] ?? 0),
            'xp_delta' => (int) ($row['xp_delta'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_at_display' => student_gamification_format_event_datetime((string) ($row['created_at'] ?? '')),
            'bucket' => $bucket,
            'bucket_label' => STUDENT_GAMIFICATION_BUCKET_DISPLAY_LABELS[$bucket] ?? $bucket,
            'capped' => !empty($meta['capped']),
            'meta' => $meta,
        ];
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * @param list<array{source_table:string,source_id:int}> $sources
 * @return array{
 *   ready:bool,
 *   events:list<array<string,mixed>>,
 *   total_xp_delta:int,
 *   level_up:bool,
 *   level_before:int,
 *   level_after:int,
 *   rank_after:string,
 *   new_achievements:list<array{key:string,label:string,unlocked_at:string}>
 * }
 */
function student_gamification_reward_context_for_sources(mysqli $conn, int $userId, array $sources): array
{
    $empty = [
        'ready' => false,
        'events' => [],
        'total_xp_delta' => 0,
        'level_up' => false,
        'level_before' => 1,
        'level_after' => 1,
        'rank_after' => STUDENT_GAMIFICATION_LEVELS[0]['rank'],
        'new_achievements' => [],
    ];
    if ($userId <= 0 || !student_gamification_tables_ready($conn) || $sources === []) {
        return $empty;
    }

    $events = [];
    foreach ($sources as $src) {
        $table = (string) ($src['source_table'] ?? '');
        $sid = (int) ($src['source_id'] ?? 0);
        if ($table === '' || $sid <= 0) {
            continue;
        }
        foreach (student_gamification_events_for_source($conn, $userId, $table, $sid) as $ev) {
            $events[] = $ev;
        }
    }
    if ($events === []) {
        return array_merge($empty, ['ready' => true]);
    }

    usort($events, static function ($a, $b) {
        return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
    });

    $totalDelta = 0;
    foreach ($events as $ev) {
        $totalDelta += (int) ($ev['xp_delta'] ?? 0);
    }

    $profile = student_gamification_profile_fetch($conn, $userId);
    $totalXp = (int) ($profile['total_xp'] ?? 0);
    $after = student_gamification_level_for_xp($totalXp);
    $before = student_gamification_level_for_xp(max(0, $totalXp - $totalDelta));

    $latestAt = (string) ($events[count($events) - 1]['created_at'] ?? '');
    $newAchievements = [];
    if ($latestAt !== '') {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT achievement_key, unlocked_at FROM student_achievements
             WHERE user_id = ? AND unlocked_at >= DATE_SUB(?, INTERVAL 90 SECOND)
             ORDER BY unlocked_at ASC'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'is', $userId, $latestAt);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($res && ($row = mysqli_fetch_assoc($res))) {
                $key = (string) ($row['achievement_key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $newAchievements[] = [
                    'key' => $key,
                    'label' => STUDENT_GAMIFICATION_ACHIEVEMENT_LABELS[$key] ?? $key,
                    'unlocked_at' => (string) ($row['unlocked_at'] ?? ''),
                ];
            }
            mysqli_stmt_close($stmt);
        }
    }

    return [
        'ready' => true,
        'events' => $events,
        'total_xp_delta' => $totalDelta,
        'level_up' => (int) ($after['level'] ?? 1) > (int) ($before['level'] ?? 1),
        'level_before' => (int) ($before['level'] ?? 1),
        'level_after' => (int) ($after['level'] ?? 1),
        'rank_after' => (string) ($after['rank'] ?? ''),
        'new_achievements' => $newAchievements,
    ];
}

/**
 * @return list<array{key:string,label:string,description:string}>
 */
function student_gamification_achievement_catalog(): array
{
    $out = [];
    foreach (STUDENT_GAMIFICATION_ACHIEVEMENT_LABELS as $key => $label) {
        $out[] = [
            'key' => $key,
            'label' => $label,
            'description' => STUDENT_GAMIFICATION_ACHIEVEMENT_DESCRIPTIONS[$key] ?? '',
        ];
    }
    return $out;
}

/**
 * @return array{current:int,target:int,label:string}|null
 */
function student_gamification_achievement_progress_hint(mysqli $conn, int $userId, string $key): ?array
{
    if ($userId <= 0) {
        return null;
    }
    switch ($key) {
        case 'first_quiz':
            $c = student_gamification_count_events($conn, $userId, 'formal_quiz_completed');
            return ['current' => min(1, $c), 'target' => 1, 'label' => 'Quizzes completed'];
        case 'first_playground':
            $c = student_gamification_has_first_playground($conn, $userId) ? 1 : 0;
            return ['current' => $c, 'target' => 1, 'label' => 'Playground runs'];
        case 'first_daily':
            $c = student_gamification_count_events($conn, $userId, 'playground_daily_completed');
            return ['current' => min(1, $c), 'target' => 1, 'label' => 'Daily challenges'];
        case 'first_battle':
            $c = student_gamification_count_events($conn, $userId, 'battle_completed');
            return ['current' => min(1, $c), 'target' => 1, 'label' => 'Battles finished'];
        case 'battle_champion':
            $c = student_gamification_count_events($conn, $userId, 'battle_victory');
            return ['current' => min(1, $c), 'target' => 1, 'label' => 'Battle wins'];
        case 'perfect_quiz':
            $c = student_gamification_count_events($conn, $userId, 'formal_quiz_perfect');
            return ['current' => min(1, $c), 'target' => 1, 'label' => 'Perfect quizzes'];
        case 'perfect_quiz_5':
            $c = student_gamification_count_events($conn, $userId, 'formal_quiz_perfect');
            return ['current' => min(5, $c), 'target' => 5, 'label' => 'Perfect quizzes'];
        case 'high_score_10':
            $c = student_gamification_count_high_or_perfect_attempts($conn, $userId);
            return ['current' => min(10, $c), 'target' => 10, 'label' => 'High-score quizzes'];
        case 'streak_3':
        case 'streak_7':
        case 'streak_14':
        case 'streak_30':
            $target = (int) str_replace('streak_', '', $key);
            $profile = student_gamification_profile_fetch($conn, $userId);
            $streak = max((int) ($profile['current_streak_days'] ?? 0), (int) ($profile['longest_streak_days'] ?? 0));
            return ['current' => min($target, $streak), 'target' => $target, 'label' => 'Day streak'];
        case 'questions_100':
            $c = student_gamification_count_questions_answered($conn, $userId);
            return ['current' => min(100, $c), 'target' => 100, 'label' => 'Questions answered'];
        case 'questions_500':
            $c = student_gamification_count_questions_answered($conn, $userId);
            return ['current' => min(500, $c), 'target' => 500, 'label' => 'Questions answered'];
        default:
            return null;
    }
}

/**
 * @return list<array{
 *   key:string,
 *   label:string,
 *   description:string,
 *   unlocked:bool,
 *   unlocked_at:?string,
 *   progress:?array{current:int,target:int,label:string}
 * }>
 */
function student_gamification_achievement_gallery(mysqli $conn, int $userId): array
{
    if ($userId <= 0 || !student_gamification_tables_ready($conn)) {
        return [];
    }

    $unlockedMap = [];
    $stmt = mysqli_prepare(
        $conn,
        'SELECT achievement_key, unlocked_at FROM student_achievements WHERE user_id = ?'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $unlockedMap[(string) ($row['achievement_key'] ?? '')] = (string) ($row['unlocked_at'] ?? '');
        }
        mysqli_stmt_close($stmt);
    }

    $gallery = [];
    foreach (student_gamification_achievement_catalog() as $item) {
        $key = (string) $item['key'];
        $isUnlocked = array_key_exists($key, $unlockedMap);
        $gallery[] = [
            'key' => $key,
            'label' => (string) $item['label'],
            'description' => (string) $item['description'],
            'unlocked' => $isUnlocked,
            'unlocked_at' => $isUnlocked ? $unlockedMap[$key] : null,
            'progress' => $isUnlocked ? null : student_gamification_achievement_progress_hint($conn, $userId, $key),
        ];
    }

    usort($gallery, static function ($a, $b) {
        if ($a['unlocked'] !== $b['unlocked']) {
            return $a['unlocked'] ? -1 : 1;
        }
        if ($a['unlocked'] && $b['unlocked']) {
            return strcmp((string) ($a['unlocked_at'] ?? ''), (string) ($b['unlocked_at'] ?? ''));
        }
        return strcmp((string) $a['label'], (string) $b['label']);
    });

    return $gallery;
}

function student_gamification_battle_player_id_for_room(mysqli $conn, int $userId, string $roomCode): int
{
    if ($userId <= 0 || $roomCode === '') {
        return 0;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT p.player_id
         FROM student_playground_game_players p
         INNER JOIN student_playground_games g ON g.game_id = p.game_id
         WHERE p.user_id = ? AND g.room_code = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'is', $userId, $roomCode);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $row ? (int) ($row['player_id'] ?? 0) : 0;
}
