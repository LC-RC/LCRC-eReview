<?php
/**
 * CPA Battle — multiplayer extension of CPA Playground (AJAX polling, server-authoritative).
 * Questions come from existing quiz_questions via student_playground_question_pool().
 */
declare(strict_types=1);

require_once __DIR__ . '/schema_introspection.php';
require_once __DIR__ . '/student_playground.php';
require_once __DIR__ . '/student_content_access.php';

const STUDENT_PLAYGROUND_BATTLE_MIN_PLAYERS = 2;
const STUDENT_PLAYGROUND_BATTLE_MAX_PLAYERS = 10;
const STUDENT_PLAYGROUND_BATTLE_LOBBY_TTL_SEC = 2700; // 45 min
const STUDENT_PLAYGROUND_BATTLE_BASE_POINTS = 500;
const STUDENT_PLAYGROUND_BATTLE_SPEED_MAX = 500;
const STUDENT_PLAYGROUND_BATTLE_STREAK_MAX = 300;
const STUDENT_PLAYGROUND_BATTLE_COUNTDOWN_SEC = 3;
const STUDENT_PLAYGROUND_BATTLE_REVEAL_SEC = 2;

function student_playground_battle_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_playground_games` (
      `game_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `room_code` CHAR(5) NOT NULL,
      `host_user_id` INT(11) NOT NULL,
      `title` VARCHAR(120) NOT NULL DEFAULT '',
      `status` ENUM('lobby','countdown','question','reveal','finished','cancelled') NOT NULL DEFAULT 'lobby',
      `question_count` INT(11) NOT NULL DEFAULT 10,
      `total_time_seconds` INT(11) NOT NULL DEFAULT 600,
      `seconds_per_question` INT(11) NOT NULL DEFAULT 30,
      `selection_mode` ENUM('mixed','subjects') NOT NULL DEFAULT 'mixed',
      `subject_ids_json` TEXT DEFAULT NULL,
      `balanced` TINYINT(1) NOT NULL DEFAULT 0,
      `speed_bonus` TINYINT(1) NOT NULL DEFAULT 1,
      `streak_bonus` TINYINT(1) NOT NULL DEFAULT 1,
      `seed` VARCHAR(64) NOT NULL DEFAULT '',
      `settings_json` TEXT DEFAULT NULL,
      `current_ordinal` INT(11) NOT NULL DEFAULT 0,
      `question_started_at` DATETIME DEFAULT NULL,
      `question_ends_at` DATETIME DEFAULT NULL,
      `started_at` DATETIME DEFAULT NULL,
      `ends_at` DATETIME DEFAULT NULL,
      `completed_at` DATETIME DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`game_id`),
      UNIQUE KEY `uq_spg_battle_room_code` (`room_code`),
      KEY `idx_spg_battle_host` (`host_user_id`),
      KEY `idx_spg_battle_status` (`status`, `last_activity_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_playground_game_players` (
      `player_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `game_id` BIGINT UNSIGNED NOT NULL,
      `user_id` INT(11) NOT NULL,
      `nickname` VARCHAR(16) NOT NULL,
      `avatar_key` VARCHAR(32) NOT NULL DEFAULT 'a1',
      `status` ENUM('joined','ready','playing','disconnected','left','finished') NOT NULL DEFAULT 'joined',
      `ready_at` DATETIME DEFAULT NULL,
      `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `left_at` DATETIME DEFAULT NULL,
      `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `score` INT(11) NOT NULL DEFAULT 0,
      `correct_count` INT(11) NOT NULL DEFAULT 0,
      `wrong_count` INT(11) NOT NULL DEFAULT 0,
      `best_streak` INT(11) NOT NULL DEFAULT 0,
      `current_streak` INT(11) NOT NULL DEFAULT 0,
      `final_rank` INT(11) DEFAULT NULL,
      PRIMARY KEY (`player_id`),
      UNIQUE KEY `uq_spg_battle_game_user` (`game_id`, `user_id`),
      KEY `idx_spg_battle_player_user` (`user_id`),
      KEY `idx_spg_battle_player_game` (`game_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_playground_game_questions` (
      `game_question_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `game_id` BIGINT UNSIGNED NOT NULL,
      `question_id` INT(11) NOT NULL,
      `ordinal` INT(11) NOT NULL,
      `started_at` DATETIME DEFAULT NULL,
      `ended_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`game_question_id`),
      UNIQUE KEY `uq_spg_battle_gq_ord` (`game_id`, `ordinal`),
      UNIQUE KEY `uq_spg_battle_gq_qid` (`game_id`, `question_id`),
      KEY `idx_spg_battle_gq_question` (`question_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `student_playground_game_answers` (
      `answer_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `game_id` BIGINT UNSIGNED NOT NULL,
      `player_id` BIGINT UNSIGNED NOT NULL,
      `game_question_id` BIGINT UNSIGNED NOT NULL,
      `selected_answer` VARCHAR(5) NOT NULL DEFAULT '',
      `is_correct` TINYINT(1) NOT NULL DEFAULT 0,
      `response_ms` INT(11) NOT NULL DEFAULT 0,
      `points` INT(11) NOT NULL DEFAULT 0,
      `answered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`answer_id`),
      UNIQUE KEY `uq_spg_battle_ans_once` (`player_id`, `game_question_id`),
      KEY `idx_spg_battle_ans_game` (`game_id`),
      KEY `idx_spg_battle_ans_gq` (`game_question_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function student_playground_battle_nick_get(): string
{
    return trim((string) ($_SESSION['pg_battle_nick'] ?? ''));
}

function student_playground_battle_nick_set(string $nick): array
{
    $v = student_playground_battle_validate_nickname($nick);
    if (!$v['ok']) {
        return $v;
    }
    $_SESSION['pg_battle_nick'] = $v['nickname'];
    return ['ok' => true, 'nickname' => $v['nickname']];
}

function student_playground_battle_validate_nickname(string $nick): array
{
    $nick = trim(preg_replace('/\s+/', ' ', $nick) ?? '');
    if (strlen($nick) < 3 || strlen($nick) > 16) {
        return ['ok' => false, 'error' => 'Game name must be 3–16 characters.'];
    }
    if (!preg_match('/^[A-Za-z0-9 _]+$/', $nick)) {
        return ['ok' => false, 'error' => 'Use letters, numbers, spaces, or underscore only.'];
    }
    $blocked = ['admin', 'fuck', 'shit', 'bitch', 'asshole', 'nigger', 'faggot', 'puta', 'gago', 'tangina'];
    $lower = strtolower(str_replace([' ', '_'], '', $nick));
    foreach ($blocked as $b) {
        if ($lower !== '' && str_contains($lower, $b)) {
            return ['ok' => false, 'error' => 'Please choose a different game name.'];
        }
    }
    return ['ok' => true, 'nickname' => $nick];
}

function student_playground_battle_avatar_key(string $nickname): string
{
    $palette = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8'];
    $h = hexdec(substr(hash('sha256', strtolower($nickname)), 0, 8));
    return $palette[$h % count($palette)];
}

function student_playground_battle_normalize_code(string $code): string
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    return substr($code, 0, 5);
}

function student_playground_battle_generate_code(mysqli $conn): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = mysqli_prepare(
            $conn,
            "SELECT game_id FROM student_playground_games
             WHERE room_code = ? AND status NOT IN ('finished','cancelled') LIMIT 1"
        );
        if (!$stmt) {
            return $code;
        }
        mysqli_stmt_bind_param($stmt, 's', $code);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) {
            return $code;
        }
    }
    return substr(strtoupper(bin2hex(random_bytes(3))), 0, 5);
}

function student_playground_battle_touch(mysqli $conn, int $gameId): void
{
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE student_playground_games SET last_activity_at = NOW() WHERE game_id = ?'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $gameId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function student_playground_battle_game_by_code(mysqli $conn, string $code): ?array
{
    $code = student_playground_battle_normalize_code($code);
    if (strlen($code) < 4) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM student_playground_games WHERE room_code = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $code);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}

function student_playground_battle_game_by_id(mysqli $conn, int $gameId): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT * FROM student_playground_games WHERE game_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $gameId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}

function student_playground_battle_player_get(mysqli $conn, int $gameId, int $userId): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM student_playground_game_players WHERE game_id = ? AND user_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $gameId, $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}

/** @return list<array> */
function student_playground_battle_players(mysqli $conn, int $gameId, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM student_playground_game_players WHERE game_id = ?';
    if ($activeOnly) {
        $sql .= " AND status NOT IN ('left')";
    }
    $sql .= ' ORDER BY joined_at ASC, player_id ASC';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $gameId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $out;
}

function student_playground_battle_public_player(array $p, bool $includeScore = true): array
{
    $row = [
        'nickname' => (string) $p['nickname'],
        'avatar_key' => (string) ($p['avatar_key'] ?? 'a1'),
        'status' => (string) $p['status'],
    ];
    if ($includeScore) {
        $row['score'] = (int) $p['score'];
        $row['correct_count'] = (int) $p['correct_count'];
        $row['current_streak'] = (int) $p['current_streak'];
        $row['final_rank'] = $p['final_rank'] !== null ? (int) $p['final_rank'] : null;
    }
    return $row;
}

function student_playground_battle_iso(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '';
    }
    $ts = strtotime($dt);
    return $ts === false ? '' : date('c', $ts);
}

function student_playground_battle_create(mysqli $conn, int $userId, array $opts): array
{
    student_playground_battle_ensure_schema($conn);
    $nick = trim((string) ($opts['nickname'] ?? student_playground_battle_nick_get()));
    $nv = student_playground_battle_validate_nickname($nick);
    if (!$nv['ok']) {
        return $nv;
    }
    $_SESSION['pg_battle_nick'] = $nv['nickname'];

    $count = (int) ($opts['question_count'] ?? 10);
    $count = in_array($count, [10, 20, 30, 50], true) ? $count : 10;
    $totalSeconds = student_playground_resolve_total_seconds($count, $opts);
    $perQ = max(15, (int) floor($totalSeconds / max(1, $count)));

    $selection = (string) ($opts['selection_mode'] ?? 'mixed');
    if (!in_array($selection, ['mixed', 'subjects'], true)) {
        $selection = 'mixed';
    }
    $subjectIds = [];
    if ($selection === 'subjects') {
        $raw = $opts['subject_ids'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (is_array($raw)) {
            foreach ($raw as $sid) {
                $sid = (int) $sid;
                if ($sid > 0) {
                    $subjectIds[] = $sid;
                }
            }
        }
        $subjectIds = array_values(array_unique($subjectIds));
        if ($subjectIds === []) {
            return ['ok' => false, 'error' => 'Select at least one subject.'];
        }
    }

    $title = trim((string) ($opts['title'] ?? ''));
    if ($title === '') {
        $title = 'CPA Battle';
    }
    $title = mb_substr($title, 0, 120);
    $balanced = !empty($opts['balanced']) ? 1 : 0;
    $speedBonus = array_key_exists('speed_bonus', $opts) ? (!empty($opts['speed_bonus']) ? 1 : 0) : 1;
    $streakBonus = array_key_exists('streak_bonus', $opts) ? (!empty($opts['streak_bonus']) ? 1 : 0) : 1;
    $seed = bin2hex(random_bytes(16));
    $code = student_playground_battle_generate_code($conn);
    $subjectsJson = $subjectIds === [] ? '' : (string) json_encode($subjectIds);

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO student_playground_games
          (room_code, host_user_id, title, status, question_count, total_time_seconds, seconds_per_question,
           selection_mode, subject_ids_json, balanced, speed_bonus, streak_bonus, seed, last_activity_at)
         VALUES (?, ?, ?, \'lobby\', ?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?, NOW())'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not create game.'];
    }
    // Types: code(s) user(i) title(s) count(i) total(i) perQ(i) selection(s) subjects(s) balanced(i) speed(i) streak(i) seed(s)
    mysqli_stmt_bind_param(
        $stmt,
        'sisiiissiiis',
        $code,
        $userId,
        $title,
        $count,
        $totalSeconds,
        $perQ,
        $selection,
        $subjectsJson,
        $balanced,
        $speedBonus,
        $streakBonus,
        $seed
    );
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['ok' => false, 'error' => 'Could not create game.'];
    }
    $gameId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $avatar = student_playground_battle_avatar_key($nv['nickname']);
    $nickStr = $nv['nickname'];
    $pstmt = mysqli_prepare(
        $conn,
        'INSERT INTO student_playground_game_players
          (game_id, user_id, nickname, avatar_key, status, last_seen_at)
         VALUES (?, ?, ?, ?, \'joined\', NOW())'
    );
    if (!$pstmt) {
        return ['ok' => false, 'error' => 'Could not join as host.'];
    }
    mysqli_stmt_bind_param($pstmt, 'iiss', $gameId, $userId, $nickStr, $avatar);
    mysqli_stmt_execute($pstmt);
    mysqli_stmt_close($pstmt);

    return ['ok' => true, 'game_id' => $gameId, 'room_code' => $code];
}

function student_playground_battle_join(mysqli $conn, int $userId, string $code, string $nickname = ''): array
{
    student_playground_battle_ensure_schema($conn);
    $game = student_playground_battle_game_by_code($conn, $code);
    if (!$game) {
        return ['ok' => false, 'error' => 'Invalid room code.'];
    }
    if (($game['status'] ?? '') === 'cancelled') {
        return ['ok' => false, 'error' => 'This game was cancelled.'];
    }
    if (($game['status'] ?? '') === 'finished') {
        return ['ok' => false, 'error' => 'This game has already finished.', 'finished' => true, 'room_code' => $game['room_code']];
    }

    $nick = trim($nickname !== '' ? $nickname : student_playground_battle_nick_get());
    $nv = student_playground_battle_validate_nickname($nick);
    if (!$nv['ok']) {
        return $nv;
    }
    $_SESSION['pg_battle_nick'] = $nv['nickname'];

    $existing = student_playground_battle_player_get($conn, (int) $game['game_id'], $userId);
    if ($existing) {
        if (($existing['status'] ?? '') === 'left' && !in_array($game['status'], ['lobby'], true)) {
            return ['ok' => false, 'error' => 'You left this game and cannot rejoin mid-match.'];
        }
        $avatar = student_playground_battle_avatar_key($nv['nickname']);
        $st = in_array($game['status'], ['lobby'], true) ? 'joined' : 'playing';
        if (($existing['status'] ?? '') === 'ready' && $game['status'] === 'lobby') {
            $st = 'ready';
        }
        $nickStr = $nv['nickname'];
        $pid = (int) $existing['player_id'];
        $u = mysqli_prepare(
            $conn,
            'UPDATE student_playground_game_players
             SET nickname = ?, avatar_key = ?, status = ?, left_at = NULL, last_seen_at = NOW()
             WHERE player_id = ? AND game_id = ?'
        );
        if ($u) {
            mysqli_stmt_bind_param($u, 'sssii', $nickStr, $avatar, $st, $pid, $game['game_id']);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
        }
        student_playground_battle_touch($conn, (int) $game['game_id']);
        return ['ok' => true, 'game_id' => (int) $game['game_id'], 'room_code' => (string) $game['room_code'], 'rejoined' => true];
    }

    if (($game['status'] ?? '') !== 'lobby') {
        return ['ok' => false, 'error' => 'Game already started. Ask the host for a new room.'];
    }

    $active = student_playground_battle_players($conn, (int) $game['game_id'], true);
    $active = array_filter($active, static fn ($p) => ($p['status'] ?? '') !== 'left');
    if (count($active) >= STUDENT_PLAYGROUND_BATTLE_MAX_PLAYERS) {
        return ['ok' => false, 'error' => 'Room is full (max ' . STUDENT_PLAYGROUND_BATTLE_MAX_PLAYERS . ' players).'];
    }

    // Unique nickname within room (case-insensitive).
    foreach ($active as $p) {
        if (strcasecmp((string) $p['nickname'], $nv['nickname']) === 0 && (int) $p['user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'That game name is already taken in this room.'];
        }
    }

    $avatar = student_playground_battle_avatar_key($nv['nickname']);
    $nickStr = $nv['nickname'];
    $gid = (int) $game['game_id'];
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO student_playground_game_players
          (game_id, user_id, nickname, avatar_key, status, last_seen_at)
         VALUES (?, ?, ?, ?, \'joined\', NOW())'
    );
    if (!$ins) {
        return ['ok' => false, 'error' => 'Could not join.'];
    }
    mysqli_stmt_bind_param($ins, 'iiss', $gid, $userId, $nickStr, $avatar);
    if (!mysqli_stmt_execute($ins)) {
        mysqli_stmt_close($ins);
        return ['ok' => false, 'error' => 'Could not join.'];
    }
    mysqli_stmt_close($ins);
    student_playground_battle_touch($conn, $gid);
    return ['ok' => true, 'game_id' => $gid, 'room_code' => (string) $game['room_code']];
}

function student_playground_battle_set_ready(mysqli $conn, int $userId, string $code, bool $ready): array
{
    $game = student_playground_battle_game_by_code($conn, $code);
    if (!$game || ($game['status'] ?? '') !== 'lobby') {
        return ['ok' => false, 'error' => 'Lobby not available.'];
    }
    $player = student_playground_battle_player_get($conn, (int) $game['game_id'], $userId);
    if (!$player || ($player['status'] ?? '') === 'left') {
        return ['ok' => false, 'error' => 'You are not in this room.'];
    }
    $status = $ready ? 'ready' : 'joined';
    $pid = (int) $player['player_id'];
    $gid = (int) $game['game_id'];
    if ($ready) {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE student_playground_game_players SET status = ?, ready_at = NOW(), last_seen_at = NOW()
             WHERE player_id = ? AND game_id = ?'
        );
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE student_playground_game_players SET status = ?, ready_at = NULL, last_seen_at = NOW()
             WHERE player_id = ? AND game_id = ?'
        );
    }
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sii', $status, $pid, $gid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    student_playground_battle_touch($conn, $gid);
    return ['ok' => true];
}

function student_playground_battle_leave(mysqli $conn, int $userId, string $code): array
{
    $game = student_playground_battle_game_by_code($conn, $code);
    if (!$game) {
        return ['ok' => false, 'error' => 'Game not found.'];
    }
    $player = student_playground_battle_player_get($conn, (int) $game['game_id'], $userId);
    if (!$player) {
        return ['ok' => true];
    }
    $pid = (int) $player['player_id'];
    $gid = (int) $game['game_id'];
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE student_playground_game_players
         SET status = 'left', left_at = NOW(), last_seen_at = NOW()
         WHERE player_id = ? AND game_id = ?"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $pid, $gid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    // Host leaving lobby cancels the game.
    if ((int) $game['host_user_id'] === $userId && ($game['status'] ?? '') === 'lobby') {
        student_playground_battle_cancel($conn, $userId, $code);
    }
    return ['ok' => true];
}

function student_playground_battle_kick(mysqli $conn, int $hostUserId, string $code, string $targetNickname): array
{
    $game = student_playground_battle_game_by_code($conn, $code);
    if (!$game || (int) $game['host_user_id'] !== $hostUserId) {
        return ['ok' => false, 'error' => 'Only the host can remove players.'];
    }
    if (($game['status'] ?? '') !== 'lobby') {
        return ['ok' => false, 'error' => 'Can only remove players in the lobby.'];
    }
    $targetNickname = trim($targetNickname);
    $players = student_playground_battle_players($conn, (int) $game['game_id'], true);
    foreach ($players as $p) {
        if (strcasecmp((string) $p['nickname'], $targetNickname) === 0) {
            if ((int) $p['user_id'] === $hostUserId) {
                return ['ok' => false, 'error' => 'Host cannot remove themselves.'];
            }
            $pid = (int) $p['player_id'];
            $gid = (int) $game['game_id'];
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE student_playground_game_players SET status = 'left', left_at = NOW() WHERE player_id = ? AND game_id = ?"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $pid, $gid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            return ['ok' => true];
        }
    }
    return ['ok' => false, 'error' => 'Player not found.'];
}

function student_playground_battle_cancel(mysqli $conn, int $hostUserId, string $code): array
{
    $game = student_playground_battle_game_by_code($conn, $code);
    if (!$game || (int) $game['host_user_id'] !== $hostUserId) {
        return ['ok' => false, 'error' => 'Only the host can cancel.'];
    }
    if (in_array($game['status'], ['finished', 'cancelled'], true)) {
        return ['ok' => true];
    }
    $gid = (int) $game['game_id'];
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE student_playground_games SET status = 'cancelled', completed_at = NOW(), last_activity_at = NOW() WHERE game_id = ?"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $gid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    return ['ok' => true];
}

/**
 * Build shared question set from existing LMS bank (host-accessible pool).
 * @return list<array{question_id:int,quiz_id:int,subject_id:int}>
 */
function student_playground_battle_build_question_set(mysqli $conn, array $game): array
{
    $hostId = (int) $game['host_user_id'];
    $count = (int) $game['question_count'];
    $selection = (string) ($game['selection_mode'] ?? 'mixed');
    $balanced = !empty($game['balanced']);
    $seed = (string) $game['seed'];
    $subjectIds = [];
    if (!empty($game['subject_ids_json'])) {
        $decoded = json_decode((string) $game['subject_ids_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $sid) {
                $sid = (int) $sid;
                if ($sid > 0) {
                    $subjectIds[] = $sid;
                }
            }
        }
    }

    $pool = [];
    if ($selection === 'subjects' && $subjectIds !== []) {
        foreach ($subjectIds as $sid) {
            foreach (student_playground_question_pool($conn, $hostId, $sid) as $q) {
                $pool[$q['question_id']] = $q;
            }
        }
        $pool = array_values($pool);
    } else {
        $pool = student_playground_question_pool($conn, $hostId, 0);
    }

    if ($pool === []) {
        return [];
    }

    if ($balanced && $subjectIds !== []) {
        $bySub = [];
        foreach ($pool as $q) {
            $bySub[(int) $q['subject_id']][] = $q;
        }
        foreach ($bySub as $sid => $list) {
            $bySub[$sid] = student_playground_seeded_shuffle($list, $seed . ':sub:' . $sid);
        }
        $picked = [];
        $idx = 0;
        while (count($picked) < $count) {
            $added = false;
            foreach ($subjectIds as $sid) {
                if (count($picked) >= $count) {
                    break;
                }
                if (!empty($bySub[$sid][$idx])) {
                    $picked[] = $bySub[$sid][$idx];
                    $added = true;
                }
            }
            if (!$added) {
                break;
            }
            $idx++;
        }
        if (count($picked) < $count) {
            $have = array_column($picked, 'question_id');
            $rest = array_values(array_filter($pool, static fn ($q) => !in_array($q['question_id'], $have, true)));
            $rest = student_playground_seeded_shuffle($rest, $seed . ':fill');
            foreach ($rest as $q) {
                if (count($picked) >= $count) {
                    break;
                }
                $picked[] = $q;
            }
        }
        return array_slice($picked, 0, $count);
    }

    $shuffled = student_playground_seeded_shuffle($pool, $seed . ':pick');
    return array_slice($shuffled, 0, min($count, count($shuffled)));
}

function student_playground_battle_start(mysqli $conn, int $hostUserId, string $code): array
{
    $game = student_playground_battle_game_by_code($conn, $code);
    if (!$game || (int) $game['host_user_id'] !== $hostUserId) {
        return ['ok' => false, 'error' => 'Only the host can start.'];
    }
    if (($game['status'] ?? '') !== 'lobby') {
        return ['ok' => false, 'error' => 'Game already started.'];
    }

    $players = array_values(array_filter(
        student_playground_battle_players($conn, (int) $game['game_id'], true),
        static fn ($p) => ($p['status'] ?? '') !== 'left'
    ));
    if (count($players) < STUDENT_PLAYGROUND_BATTLE_MIN_PLAYERS) {
        return ['ok' => false, 'error' => 'Need at least ' . STUDENT_PLAYGROUND_BATTLE_MIN_PLAYERS . ' players.'];
    }
    foreach ($players as $p) {
        if (($p['status'] ?? '') !== 'ready' && (int) $p['user_id'] !== $hostUserId) {
            // Host may start if they are ready OR we require all including host.
        }
        if (($p['status'] ?? '') !== 'ready') {
            return ['ok' => false, 'error' => 'All players must be READY before starting.'];
        }
    }

    $set = student_playground_battle_build_question_set($conn, $game);
    if (count($set) < 1) {
        return ['ok' => false, 'error' => 'No accessible quiz questions for this battle.'];
    }
    $actualCount = count($set);
    $gid = (int) $game['game_id'];
    $totalSeconds = (int) $game['total_time_seconds'];
    $perQ = max(15, (int) floor($totalSeconds / max(1, $actualCount)));

    foreach ($set as $i => $q) {
        $ord = $i + 1;
        $qid = (int) $q['question_id'];
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO student_playground_game_questions (game_id, question_id, ordinal) VALUES (?, ?, ?)'
        );
        if ($ins) {
            mysqli_stmt_bind_param($ins, 'iii', $gid, $qid, $ord);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
        }
    }

    $countdown = STUDENT_PLAYGROUND_BATTLE_COUNTDOWN_SEC;
    $ustmt = mysqli_prepare(
        $conn,
        "UPDATE student_playground_games
         SET status = 'countdown',
             question_count = ?,
             seconds_per_question = ?,
             current_ordinal = 0,
             started_at = NOW(),
             ends_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
             question_started_at = NOW(),
             question_ends_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
             last_activity_at = NOW()
         WHERE game_id = ?"
    );
    if ($ustmt) {
        mysqli_stmt_bind_param($ustmt, 'iiiii', $actualCount, $perQ, $totalSeconds, $countdown, $gid);
        mysqli_stmt_execute($ustmt);
        mysqli_stmt_close($ustmt);
    }

    @mysqli_query(
        $conn,
        "UPDATE student_playground_game_players SET status = 'playing', last_seen_at = NOW()
         WHERE game_id = {$gid} AND status IN ('ready','joined')"
    );

    return ['ok' => true, 'room_code' => (string) $game['room_code'], 'question_count' => $actualCount];
}

function student_playground_battle_compute_points(
    bool $correct,
    int $responseMs,
    int $secondsPerQuestion,
    int $streakAfter,
    bool $speedOn,
    bool $streakOn
): int {
    if (!$correct) {
        return 0;
    }
    $pts = STUDENT_PLAYGROUND_BATTLE_BASE_POINTS;
    if ($speedOn) {
        $limitMs = max(1, $secondsPerQuestion) * 1000;
        $responseMs = max(0, min($responseMs, $limitMs));
        $ratio = 1.0 - ($responseMs / $limitMs);
        $pts += (int) round(STUDENT_PLAYGROUND_BATTLE_SPEED_MAX * max(0.0, min(1.0, $ratio)));
    }
    if ($streakOn && $streakAfter > 1) {
        $pts += min(STUDENT_PLAYGROUND_BATTLE_STREAK_MAX, ($streakAfter - 1) * 75);
    }
    return $pts;
}

function student_playground_battle_active_player_ids(mysqli $conn, int $gameId): array
{
    $ids = [];
    foreach (student_playground_battle_players($conn, $gameId, true) as $p) {
        if (in_array($p['status'] ?? '', ['playing', 'disconnected'], true)) {
            $ids[] = (int) $p['player_id'];
        }
    }
    return $ids;
}

function student_playground_battle_answers_for_question(mysqli $conn, int $gameQuestionId): array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM student_playground_game_answers WHERE game_question_id = ?'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $gameQuestionId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $out;
}

function student_playground_battle_gq_by_ordinal(mysqli $conn, int $gameId, int $ordinal): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM student_playground_game_questions WHERE game_id = ? AND ordinal = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $gameId, $ordinal);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $row;
}

function student_playground_battle_finish(mysqli $conn, int $gameId): void
{
    $game = student_playground_battle_game_by_id($conn, $gameId);
    if (!$game || in_array($game['status'], ['finished', 'cancelled'], true)) {
        return;
    }
    $players = array_values(array_filter(
        student_playground_battle_players($conn, $gameId, true),
        static fn ($p) => ($p['status'] ?? '') !== 'left'
    ));
    usort($players, static function ($a, $b) {
        $sc = ((int) $b['score']) <=> ((int) $a['score']);
        if ($sc !== 0) {
            return $sc;
        }
        return ((int) $b['correct_count']) <=> ((int) $a['correct_count']);
    });
    $rank = 1;
    foreach ($players as $p) {
        $pid = (int) $p['player_id'];
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE student_playground_game_players
             SET final_rank = ?, status = 'finished', last_seen_at = NOW()
             WHERE player_id = ?"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $rank, $pid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $rank++;
    }
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE student_playground_games
         SET status = 'finished', completed_at = NOW(), last_activity_at = NOW()
         WHERE game_id = ?"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $gameId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function student_playground_battle_open_question(mysqli $conn, int $gameId, int $ordinal): void
{
    $gq = student_playground_battle_gq_by_ordinal($conn, $gameId, $ordinal);
    if (!$gq) {
        student_playground_battle_finish($conn, $gameId);
        return;
    }
    $game = student_playground_battle_game_by_id($conn, $gameId);
    $perQ = max(15, (int) ($game['seconds_per_question'] ?? 30));
    $gqId = (int) $gq['game_question_id'];
    @mysqli_query(
        $conn,
        "UPDATE student_playground_game_questions SET started_at = NOW(), ended_at = NULL WHERE game_question_id = {$gqId}"
    );
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE student_playground_games
         SET status = 'question', current_ordinal = ?,
             question_started_at = NOW(),
             question_ends_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
             last_activity_at = NOW()
         WHERE game_id = ?"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'iii', $ordinal, $perQ, $gameId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function student_playground_battle_enter_reveal(mysqli $conn, int $gameId): void
{
    $game = student_playground_battle_game_by_id($conn, $gameId);
    if (!$game) {
        return;
    }
    $ord = (int) $game['current_ordinal'];
    $gq = student_playground_battle_gq_by_ordinal($conn, $gameId, $ord);
    if ($gq) {
        $gqId = (int) $gq['game_question_id'];
        @mysqli_query(
            $conn,
            "UPDATE student_playground_game_questions SET ended_at = NOW() WHERE game_question_id = {$gqId}"
        );
    }
    $reveal = STUDENT_PLAYGROUND_BATTLE_REVEAL_SEC;
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE student_playground_games
         SET status = 'reveal',
             question_ends_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
             last_activity_at = NOW()
         WHERE game_id = ?"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $reveal, $gameId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/** Advance server game clock / phases. Call on every poll. */
function student_playground_battle_tick(mysqli $conn, array $game): array
{
    $gid = (int) $game['game_id'];
    $status = (string) ($game['status'] ?? '');
    if (in_array($status, ['finished', 'cancelled', 'lobby'], true)) {
        return student_playground_battle_game_by_id($conn, $gid) ?: $game;
    }

    // Global total timer.
    if (!empty($game['ends_at'])) {
        $ends = strtotime((string) $game['ends_at']);
        if ($ends !== false && time() >= $ends && $status !== 'finished') {
            // If mid-question, reveal then finish quickly; else finish.
            if ($status === 'question') {
                student_playground_battle_enter_reveal($conn, $gid);
                $game = student_playground_battle_game_by_id($conn, $gid) ?: $game;
                $status = (string) $game['status'];
            } else {
                student_playground_battle_finish($conn, $gid);
                return student_playground_battle_game_by_id($conn, $gid) ?: $game;
            }
        }
    }

    $phaseEnd = !empty($game['question_ends_at']) ? strtotime((string) $game['question_ends_at']) : false;

    if ($status === 'countdown') {
        if ($phaseEnd !== false && time() >= $phaseEnd) {
            student_playground_battle_open_question($conn, $gid, 1);
        }
        return student_playground_battle_game_by_id($conn, $gid) ?: $game;
    }

    if ($status === 'question') {
        $ord = (int) $game['current_ordinal'];
        $gq = student_playground_battle_gq_by_ordinal($conn, $gid, $ord);
        $allAnswered = false;
        if ($gq) {
            $active = student_playground_battle_active_player_ids($conn, $gid);
            $answers = student_playground_battle_answers_for_question($conn, (int) $gq['game_question_id']);
            $answeredIds = array_map(static fn ($a) => (int) $a['player_id'], $answers);
            if ($active !== []) {
                $allAnswered = count(array_diff($active, $answeredIds)) === 0;
            }
        }
        $timedOut = $phaseEnd !== false && time() >= $phaseEnd;
        if ($allAnswered || $timedOut) {
            student_playground_battle_enter_reveal($conn, $gid);
        }
        return student_playground_battle_game_by_id($conn, $gid) ?: $game;
    }

    if ($status === 'reveal') {
        if ($phaseEnd !== false && time() >= $phaseEnd) {
            $next = (int) $game['current_ordinal'] + 1;
            $qCount = (int) $game['question_count'];
            // Also check global timer
            $globalDone = !empty($game['ends_at']) && strtotime((string) $game['ends_at']) !== false
                && time() >= strtotime((string) $game['ends_at']);
            if ($next > $qCount || $globalDone) {
                student_playground_battle_finish($conn, $gid);
            } else {
                student_playground_battle_open_question($conn, $gid, $next);
            }
        }
        return student_playground_battle_game_by_id($conn, $gid) ?: $game;
    }

    return student_playground_battle_game_by_id($conn, $gid) ?: $game;
}

function student_playground_battle_heartbeat(mysqli $conn, int $playerId): void
{
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE student_playground_game_players SET last_seen_at = NOW() WHERE player_id = ?'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $playerId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function student_playground_battle_build_question_payload(
    mysqli $conn,
    array $game,
    array $gq,
    array $player
): ?array {
    $qid = (int) $gq['question_id'];
    $stmt = mysqli_prepare($conn, 'SELECT * FROM quiz_questions WHERE question_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $qid);
    mysqli_stmt_execute($stmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    if (!$qRow) {
        return null;
    }

    $letters = student_playground_choice_letters_from_row($qRow);
    $choiceOrder = student_playground_shuffle_choice_order(
        $letters,
        (string) $game['seed'] . ':p' . (int) $player['player_id'],
        $qid
    );

    // Subject from quiz
    $subjectId = 0;
    $quizId = (int) ($qRow['quiz_id'] ?? 0);
    if ($quizId > 0) {
        $qs = mysqli_prepare($conn, 'SELECT subject_id FROM quizzes WHERE quiz_id = ? LIMIT 1');
        if ($qs) {
            mysqli_stmt_bind_param($qs, 'i', $quizId);
            mysqli_stmt_execute($qs);
            $qr = mysqli_fetch_assoc(mysqli_stmt_get_result($qs));
            mysqli_stmt_close($qs);
            $subjectId = (int) ($qr['subject_id'] ?? 0);
        }
    }

    $item = [
        'ordinal' => (int) $gq['ordinal'],
        'question_id' => $qid,
        'choice_order' => $choiceOrder,
        'subject_id' => $subjectId,
        'selected_answer' => null,
    ];
    $pub = student_playground_public_question($conn, $item, $qRow);
    $pub['game_question_id'] = (int) $gq['game_question_id'];
    $pub['quiz_id'] = $quizId;
    return $pub;
}

function student_playground_battle_answer(
    mysqli $conn,
    int $userId,
    string $code,
    int $gameQuestionId,
    string $selected
): array {
    $game = student_playground_battle_game_by_code($conn, $code);
    if (!$game) {
        return ['ok' => false, 'error' => 'Game not found.'];
    }
    $game = student_playground_battle_tick($conn, $game);
    if (($game['status'] ?? '') !== 'question') {
        return ['ok' => false, 'error' => 'Not accepting answers right now.'];
    }
    $player = student_playground_battle_player_get($conn, (int) $game['game_id'], $userId);
    if (!$player || ($player['status'] ?? '') === 'left') {
        return ['ok' => false, 'error' => 'Not in this game.'];
    }

    $gq = null;
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM student_playground_game_questions WHERE game_question_id = ? AND game_id = ? LIMIT 1'
    );
    if ($stmt) {
        $gid = (int) $game['game_id'];
        mysqli_stmt_bind_param($stmt, 'ii', $gameQuestionId, $gid);
        mysqli_stmt_execute($stmt);
        $gq = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);
    }
    if (!$gq || (int) $gq['ordinal'] !== (int) $game['current_ordinal']) {
        return ['ok' => false, 'error' => 'Invalid question.'];
    }

    $pid = (int) $player['player_id'];
    $dup = mysqli_prepare(
        $conn,
        'SELECT answer_id FROM student_playground_game_answers WHERE player_id = ? AND game_question_id = ? LIMIT 1'
    );
    if ($dup) {
        mysqli_stmt_bind_param($dup, 'ii', $pid, $gameQuestionId);
        mysqli_stmt_execute($dup);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($dup));
        mysqli_stmt_close($dup);
        if ($exists) {
            return ['ok' => false, 'error' => 'Already answered.', 'duplicate' => true];
        }
    }

    $selected = strtoupper(trim($selected));
    if (!preg_match('/^[A-J]$/', $selected)) {
        return ['ok' => false, 'error' => 'Invalid answer.'];
    }

    $qid = (int) $gq['question_id'];
    $qstmt = mysqli_prepare($conn, 'SELECT correct_answer FROM quiz_questions WHERE question_id = ? LIMIT 1');
    if (!$qstmt) {
        return ['ok' => false, 'error' => 'Could not grade.'];
    }
    mysqli_stmt_bind_param($qstmt, 'i', $qid);
    mysqli_stmt_execute($qstmt);
    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($qstmt)) ?: null;
    mysqli_stmt_close($qstmt);
    $correct = strtoupper(trim((string) ($qRow['correct_answer'] ?? '')));
    $isCorrect = ($selected === $correct) ? 1 : 0;

    $started = !empty($game['question_started_at']) ? strtotime((string) $game['question_started_at']) : time();
    $responseMs = (int) max(0, (time() - $started) * 1000);
    $perQ = max(15, (int) $game['seconds_per_question']);
    $responseMs = min($responseMs, $perQ * 1000 + 2000);

    $streak = (int) $player['current_streak'];
    $best = (int) $player['best_streak'];
    if ($isCorrect) {
        $streak++;
        $best = max($best, $streak);
    } else {
        $streak = 0;
    }
    $points = student_playground_battle_compute_points(
        $isCorrect === 1,
        $responseMs,
        $perQ,
        $streak,
        !empty($game['speed_bonus']),
        !empty($game['streak_bonus'])
    );

    $gid = (int) $game['game_id'];
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO student_playground_game_answers
          (game_id, player_id, game_question_id, selected_answer, is_correct, response_ms, points)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$ins) {
        return ['ok' => false, 'error' => 'Could not save answer.'];
    }
    mysqli_stmt_bind_param($ins, 'iiisiii', $gid, $pid, $gameQuestionId, $selected, $isCorrect, $responseMs, $points);
    if (!mysqli_stmt_execute($ins)) {
        mysqli_stmt_close($ins);
        return ['ok' => false, 'error' => 'Could not save answer.', 'duplicate' => true];
    }
    mysqli_stmt_close($ins);

    $score = (int) $player['score'] + $points;
    $correctCount = (int) $player['correct_count'] + ($isCorrect ? 1 : 0);
    $wrongCount = (int) $player['wrong_count'] + ($isCorrect ? 0 : 1);
    $up = mysqli_prepare(
        $conn,
        'UPDATE student_playground_game_players
         SET score = ?, correct_count = ?, wrong_count = ?, current_streak = ?, best_streak = ?,
             status = \'playing\', last_seen_at = NOW()
         WHERE player_id = ?'
    );
    if ($up) {
        mysqli_stmt_bind_param($up, 'iiiiii', $score, $correctCount, $wrongCount, $streak, $best, $pid);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
    }

    student_playground_battle_touch($conn, $gid);
    student_playground_battle_tick($conn, student_playground_battle_game_by_id($conn, $gid) ?: $game);

    return [
        'ok' => true,
        'locked' => true,
        'points' => $points,
        // Do not reveal correctness until reveal phase — omit is_correct here.
    ];
}

function student_playground_battle_state(mysqli $conn, int $userId, string $code): array
{
    student_playground_battle_ensure_schema($conn);
    $game = student_playground_battle_game_by_code($conn, $code);
    if (!$game) {
        return ['ok' => false, 'error' => 'Game not found.'];
    }

    // Expire stale lobbies.
    if (($game['status'] ?? '') === 'lobby' && !empty($game['last_activity_at'])) {
        $la = strtotime((string) $game['last_activity_at']);
        if ($la !== false && (time() - $la) > STUDENT_PLAYGROUND_BATTLE_LOBBY_TTL_SEC) {
            $gid = (int) $game['game_id'];
            @mysqli_query(
                $conn,
                "UPDATE student_playground_games SET status = 'cancelled', completed_at = NOW() WHERE game_id = {$gid}"
            );
            $game = student_playground_battle_game_by_id($conn, $gid) ?: $game;
        }
    }

    $game = student_playground_battle_tick($conn, $game);
    $player = student_playground_battle_player_get($conn, (int) $game['game_id'], $userId);
    if (!$player) {
        return ['ok' => false, 'error' => 'You are not in this room.', 'room_code' => $game['room_code']];
    }
    student_playground_battle_heartbeat($conn, (int) $player['player_id']);

    $players = student_playground_battle_players($conn, (int) $game['game_id'], true);
    $publicPlayers = [];
    foreach ($players as $p) {
        if (($p['status'] ?? '') === 'left') {
            continue;
        }
        $publicPlayers[] = student_playground_battle_public_player($p, true);
    }
    // Live ranking sort
    usort($publicPlayers, static function ($a, $b) {
        return ((int) $b['score']) <=> ((int) $a['score']);
    });
    $rank = 1;
    foreach ($publicPlayers as &$pp) {
        $pp['rank'] = $rank++;
    }
    unset($pp);

    $status = (string) $game['status'];
    $payload = [
        'ok' => true,
        'server_now' => date('c'),
        'room_code' => (string) $game['room_code'],
        'title' => (string) $game['title'],
        'status' => $status,
        'is_host' => (int) $game['host_user_id'] === $userId,
        'question_count' => (int) $game['question_count'],
        'current_ordinal' => (int) $game['current_ordinal'],
        'seconds_per_question' => (int) $game['seconds_per_question'],
        'total_time_seconds' => (int) $game['total_time_seconds'],
        'ends_at' => student_playground_battle_iso($game['ends_at'] ?? null),
        'question_ends_at' => student_playground_battle_iso($game['question_ends_at'] ?? null),
        'question_started_at' => student_playground_battle_iso($game['question_started_at'] ?? null),
        'players' => $publicPlayers,
        'player_count' => count($publicPlayers),
        'max_players' => STUDENT_PLAYGROUND_BATTLE_MAX_PLAYERS,
        'min_players' => STUDENT_PLAYGROUND_BATTLE_MIN_PLAYERS,
        'me' => [
            'nickname' => (string) $player['nickname'],
            'avatar_key' => (string) $player['avatar_key'],
            'status' => (string) $player['status'],
            'score' => (int) $player['score'],
            'current_streak' => (int) $player['current_streak'],
            'correct_count' => (int) $player['correct_count'],
            'answered_current' => false,
        ],
        'settings' => [
            'selection_mode' => (string) $game['selection_mode'],
            'balanced' => (bool) $game['balanced'],
            'speed_bonus' => (bool) $game['speed_bonus'],
            'streak_bonus' => (bool) $game['streak_bonus'],
        ],
    ];

    if (in_array($status, ['question', 'reveal'], true) && (int) $game['current_ordinal'] > 0) {
        $gq = student_playground_battle_gq_by_ordinal($conn, (int) $game['game_id'], (int) $game['current_ordinal']);
        if ($gq) {
            $ansCheck = mysqli_prepare(
                $conn,
                'SELECT answer_id FROM student_playground_game_answers WHERE player_id = ? AND game_question_id = ? LIMIT 1'
            );
            $answered = false;
            if ($ansCheck) {
                $pid = (int) $player['player_id'];
                $gqId = (int) $gq['game_question_id'];
                mysqli_stmt_bind_param($ansCheck, 'ii', $pid, $gqId);
                mysqli_stmt_execute($ansCheck);
                $answered = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($ansCheck));
                mysqli_stmt_close($ansCheck);
            }
            $payload['me']['answered_current'] = $answered;

            if ($status === 'question') {
                $qPayload = student_playground_battle_build_question_payload($conn, $game, $gq, $player);
                if ($qPayload) {
                    // Never include correct_answer
                    unset($qPayload['correct_answer']);
                    $payload['question'] = $qPayload;
                }
            }

            if ($status === 'reveal') {
                $qid = (int) $gq['question_id'];
                $qstmt = mysqli_prepare($conn, 'SELECT correct_answer FROM quiz_questions WHERE question_id = ? LIMIT 1');
                $correct = '';
                if ($qstmt) {
                    mysqli_stmt_bind_param($qstmt, 'i', $qid);
                    mysqli_stmt_execute($qstmt);
                    $qr = mysqli_fetch_assoc(mysqli_stmt_get_result($qstmt));
                    mysqli_stmt_close($qstmt);
                    $correct = strtoupper(trim((string) ($qr['correct_answer'] ?? '')));
                }
                $myAns = null;
                $astmt = mysqli_prepare(
                    $conn,
                    'SELECT selected_answer, is_correct, points FROM student_playground_game_answers
                     WHERE player_id = ? AND game_question_id = ? LIMIT 1'
                );
                if ($astmt) {
                    $pid = (int) $player['player_id'];
                    $gqId = (int) $gq['game_question_id'];
                    mysqli_stmt_bind_param($astmt, 'ii', $pid, $gqId);
                    mysqli_stmt_execute($astmt);
                    $myAns = mysqli_fetch_assoc(mysqli_stmt_get_result($astmt)) ?: null;
                    mysqli_stmt_close($astmt);
                }
                $letters = [];
                $fullQ = mysqli_prepare($conn, 'SELECT * FROM quiz_questions WHERE question_id = ? LIMIT 1');
                $qRow = null;
                if ($fullQ) {
                    mysqli_stmt_bind_param($fullQ, 'i', $qid);
                    mysqli_stmt_execute($fullQ);
                    $qRow = mysqli_fetch_assoc(mysqli_stmt_get_result($fullQ));
                    mysqli_stmt_close($fullQ);
                }
                $displayCorrect = $correct;
                if ($qRow) {
                    $order = student_playground_shuffle_choice_order(
                        student_playground_choice_letters_from_row($qRow),
                        (string) $game['seed'] . ':p' . (int) $player['player_id'],
                        $qid
                    );
                    $idx = 0;
                    foreach (str_split($order) as $orig) {
                        if ($orig === $correct) {
                            $displayCorrect = chr(65 + $idx);
                            break;
                        }
                        $idx++;
                    }
                }
                $payload['reveal'] = [
                    'correct_answer' => $correct,
                    'correct_display' => $displayCorrect,
                    'is_correct' => $myAns ? (bool) $myAns['is_correct'] : false,
                    'points' => $myAns ? (int) $myAns['points'] : 0,
                    'selected_answer' => $myAns ? (string) $myAns['selected_answer'] : '',
                    'ordinal' => (int) $gq['ordinal'],
                ];
            }
        }
    }

    return $payload;
}

function student_playground_battle_results(mysqli $conn, int $userId, string $code): array
{
    $game = student_playground_battle_game_by_code($conn, $code);
    if (!$game) {
        return ['ok' => false, 'error' => 'Game not found.'];
    }
    $player = student_playground_battle_player_get($conn, (int) $game['game_id'], $userId);
    if (!$player) {
        return ['ok' => false, 'error' => 'Not in this game.'];
    }
    if (($game['status'] ?? '') !== 'finished') {
        $game = student_playground_battle_tick($conn, $game);
    }
    if (($game['status'] ?? '') !== 'finished') {
        return ['ok' => false, 'error' => 'Game not finished yet.', 'status' => $game['status']];
    }

    $players = array_values(array_filter(
        student_playground_battle_players($conn, (int) $game['game_id'], true),
        static fn ($p) => ($p['status'] ?? '') !== 'left'
    ));
    usort($players, static function ($a, $b) {
        $ra = $a['final_rank'] !== null ? (int) $a['final_rank'] : 999;
        $rb = $b['final_rank'] !== null ? (int) $b['final_rank'] : 999;
        return $ra <=> $rb;
    });
    $board = [];
    foreach ($players as $p) {
        $board[] = [
            'nickname' => (string) $p['nickname'],
            'avatar_key' => (string) $p['avatar_key'],
            'score' => (int) $p['score'],
            'correct_count' => (int) $p['correct_count'],
            'wrong_count' => (int) $p['wrong_count'],
            'best_streak' => (int) $p['best_streak'],
            'final_rank' => (int) ($p['final_rank'] ?? 0),
        ];
    }

    // Wrong answers for this player
    $pid = (int) $player['player_id'];
    $gid = (int) $game['game_id'];
    $hasExpl = ereview_schema_column_exists($conn, 'quiz_questions', 'explanation');
    $explSel = $hasExpl ? ', qq.explanation' : ', \'\' AS explanation';
    $sql = "SELECT a.selected_answer, a.is_correct, gq.ordinal, gq.question_id, qq.quiz_id, qq.correct_answer,
                   qq.question_text{$explSel}, q.subject_id, s.subject_name
            FROM student_playground_game_answers a
            INNER JOIN student_playground_game_questions gq ON gq.game_question_id = a.game_question_id
            INNER JOIN quiz_questions qq ON qq.question_id = gq.question_id
            LEFT JOIN quizzes q ON q.quiz_id = qq.quiz_id
            LEFT JOIN subjects s ON s.subject_id = q.subject_id
            WHERE a.game_id = ? AND a.player_id = ?
            ORDER BY gq.ordinal ASC";
    $stmt = mysqli_prepare($conn, $sql);
    $wrong = [];
    $bySubject = [];
    $totalMs = 0;
    $ansCount = 0;
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $gid, $pid);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $ansCount++;
            $sid = (int) ($r['subject_id'] ?? 0);
            $name = (string) ($r['subject_name'] ?? 'General');
            if (!isset($bySubject[$sid])) {
                $bySubject[$sid] = ['subject_id' => $sid, 'subject_name' => $name, 'correct' => 0, 'total' => 0];
            }
            $bySubject[$sid]['total']++;
            if (!empty($r['is_correct'])) {
                $bySubject[$sid]['correct']++;
            } else {
                $wrong[] = [
                    'question_id' => (int) $r['question_id'],
                    'quiz_id' => (int) ($r['quiz_id'] ?? 0),
                    'subject_id' => $sid,
                    'subject_name' => $name,
                    'ordinal' => (int) $r['ordinal'],
                    'selected_answer' => (string) $r['selected_answer'],
                    'correct_answer' => (string) $r['correct_answer'],
                    'explanation' => (string) ($r['explanation'] ?? ''),
                    'question_preview' => mb_substr(strip_tags((string) ($r['question_text'] ?? '')), 0, 180),
                ];
            }
        }
        mysqli_stmt_close($stmt);
    }

    $by = array_values($bySubject);
    foreach ($by as &$b) {
        $b['accuracy'] = $b['total'] > 0 ? round(100 * $b['correct'] / $b['total']) : 0;
    }
    unset($b);
    usort($by, static fn ($a, $b) => ($a['accuracy'] <=> $b['accuracy']));
    $weakest = $by[0] ?? null;

    $total = (int) $game['question_count'];
    $correct = (int) $player['correct_count'];

    return [
        'ok' => true,
        'room_code' => (string) $game['room_code'],
        'title' => (string) $game['title'],
        'me' => [
            'nickname' => (string) $player['nickname'],
            'avatar_key' => (string) $player['avatar_key'],
            'score' => (int) $player['score'],
            'correct_count' => $correct,
            'wrong_count' => (int) $player['wrong_count'],
            'best_streak' => (int) $player['best_streak'],
            'final_rank' => (int) ($player['final_rank'] ?? 0),
            'accuracy' => $total > 0 ? round(100 * $correct / $total) : 0,
            'total' => $total,
        ],
        'leaderboard' => $board,
        'by_subject' => $by,
        'weakest' => $weakest,
        'wrong' => $wrong,
    ];
}
