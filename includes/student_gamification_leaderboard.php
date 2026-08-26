<?php
/**
 * Phase 3 Competitive Gamification — read-only leaderboard helpers.
 * SELECT-only. Never grants XP or modifies gamification state.
 */
declare(strict_types=1);

require_once __DIR__ . '/student_gamification.php';
require_once __DIR__ . '/commerce_access_gate.php';
require_once __DIR__ . '/format_display_name.php';
require_once __DIR__ . '/schema_introspection.php';

function student_gamification_seasons_tables_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = ereview_schema_table_exists($conn, 'student_gamification_seasons');
    return $ready;
}

function student_gamification_leaderboard_ready(mysqli $conn): bool
{
    return student_gamification_tables_ready($conn);
}

function student_gamification_leaderboard_display_name(?string $fullName): string
{
    return ereview_format_topbar_display_name($fullName);
}

/**
 * SQL AND-clause fragment for eligible LMS students (qualified users alias).
 */
function student_gamification_leaderboard_eligibility_sql(string $userAlias = 'u', ?mysqli $conn = null): string
{
    $userAlias = trim($userAlias);
    if ($userAlias === '') {
        $userAlias = 'u';
    }
    $parts = [
        "{$userAlias}.role = 'student'",
        "{$userAlias}.status = 'approved'",
    ];
    if ($conn instanceof mysqli && function_exists('commerce_schema_ready') && commerce_schema_ready($conn)) {
        $parts[] = '(' . commerce_sql_user_has_active_grant("{$userAlias}.user_id")
            . " OR ({$userAlias}.access_end IS NOT NULL AND {$userAlias}.access_end > NOW()))";
    } else {
        $parts[] = "({$userAlias}.access_end IS NULL OR {$userAlias}.access_end > NOW())";
    }
    return implode(' AND ', $parts);
}

function student_gamification_leaderboard_user_is_eligible(mysqli $conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT user_id, role, status, access_end FROM users WHERE user_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    if (!$row || ($row['role'] ?? '') !== 'student' || ($row['status'] ?? '') !== 'approved') {
        return false;
    }
    if (!function_exists('commerce_student_can_login')) {
        return true;
    }
    $gate = commerce_student_can_login($conn, $row);
    return !empty($gate['ok']);
}

function student_gamification_leaderboard_last_xp_subquery(string $profileAlias = 'p'): string
{
    $profileAlias = trim($profileAlias) !== '' ? trim($profileAlias) : 'p';
    return "(SELECT MAX(e.created_at) FROM student_gamification_events e
             WHERE e.user_id = {$profileAlias}.user_id AND e.xp_delta > 0)";
}

/**
 * @return list<array<string,mixed>>
 */
function student_gamification_seasons_list(mysqli $conn, bool $includeArchived = false): array
{
    if (!student_gamification_seasons_tables_ready($conn)) {
        return [];
    }
    $sql = 'SELECT season_id, slug, title, starts_at, ends_at, status, created_at
            FROM student_gamification_seasons';
    if (!$includeArchived) {
        $sql .= " WHERE status <> 'archived'";
    }
    $sql .= ' ORDER BY starts_at DESC, season_id DESC';
    $res = mysqli_query($conn, $sql);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = [
            'season_id' => (int) ($row['season_id'] ?? 0),
            'slug' => (string) ($row['slug'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'starts_at' => (string) ($row['starts_at'] ?? ''),
            'ends_at' => (string) ($row['ends_at'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
    return $rows;
}

/**
 * @return array<string,mixed>|null
 */
function student_gamification_season_active(mysqli $conn): ?array
{
    if (!student_gamification_seasons_tables_ready($conn)) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT season_id, slug, title, starts_at, ends_at, status, created_at
         FROM student_gamification_seasons
         WHERE status = 'active'
         ORDER BY starts_at DESC, season_id DESC
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    if (!$row) {
        return null;
    }
    return [
        'season_id' => (int) ($row['season_id'] ?? 0),
        'slug' => (string) ($row['slug'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'starts_at' => (string) ($row['starts_at'] ?? ''),
        'ends_at' => (string) ($row['ends_at'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

/**
 * @return array{current:int,per_page:int,total:int,total_pages:int}
 */
function student_gamification_leaderboard_pagination(int $page, int $perPage, int $total): array
{
    $perPage = max(1, min(50, $perPage));
    $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
    $page = max(1, min($page, max(1, $totalPages)));
    return [
        'current' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
    ];
}

/**
 * @return array{rows:list<array<string,mixed>>,pagination:array<string,int>,ready:bool}
 */
function student_gamification_leaderboard_lifetime(
    mysqli $conn,
    int $page = 1,
    int $perPage = 25,
    int $viewerUserId = 0
): array {
    $empty = [
        'rows' => [],
        'pagination' => student_gamification_leaderboard_pagination(1, $perPage, 0),
        'ready' => false,
    ];
    if (!student_gamification_leaderboard_ready($conn)) {
        return $empty;
    }

    $elig = student_gamification_leaderboard_eligibility_sql('u', $conn);
    $lastXp = student_gamification_leaderboard_last_xp_subquery('p');

    $countSql = "SELECT COUNT(*) AS c
                 FROM student_gamification_profile p
                 INNER JOIN users u ON u.user_id = p.user_id
                 WHERE p.total_xp > 0 AND {$elig}";
    $countRes = mysqli_query($conn, $countSql);
    $total = $countRes ? (int) (mysqli_fetch_assoc($countRes)['c'] ?? 0) : 0;
    $pagination = student_gamification_leaderboard_pagination($page, $perPage, $total);
    $offset = ($pagination['current'] - 1) * $pagination['per_page'];

    $sql = "SELECT p.user_id, p.total_xp, u.full_name, {$lastXp} AS last_xp_at
            FROM student_gamification_profile p
            INNER JOIN users u ON u.user_id = p.user_id
            WHERE p.total_xp > 0 AND {$elig}
            ORDER BY p.total_xp DESC, last_xp_at ASC, p.user_id ASC
            LIMIT ? OFFSET ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return array_merge($empty, ['ready' => true]);
    }
    $limit = $pagination['per_page'];
    mysqli_stmt_bind_param($stmt, 'ii', $limit, $offset);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    $rank = $offset + 1;
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $totalXp = (int) ($row['total_xp'] ?? 0);
        $tier = student_gamification_level_for_xp($totalXp);
        $uid = (int) ($row['user_id'] ?? 0);
        $rows[] = [
            'rank' => $rank,
            'display_name' => student_gamification_leaderboard_display_name((string) ($row['full_name'] ?? '')),
            'score_xp' => $totalXp,
            'level' => (int) ($tier['level'] ?? 1),
            'rank_title' => (string) ($tier['rank'] ?? ''),
            'is_viewer' => ($viewerUserId > 0 && $uid === $viewerUserId),
        ];
        $rank++;
    }
    mysqli_stmt_close($stmt);

    return [
        'rows' => $rows,
        'pagination' => $pagination,
        'ready' => true,
    ];
}

/**
 * @return array{rows:list<array<string,mixed>>,pagination:array<string,int>,ready:bool,season:?array}
 */
function student_gamification_leaderboard_season(
    mysqli $conn,
    int $seasonId,
    int $page = 1,
    int $perPage = 25,
    int $viewerUserId = 0
): array {
    $empty = [
        'rows' => [],
        'pagination' => student_gamification_leaderboard_pagination(1, $perPage, 0),
        'ready' => false,
        'season' => null,
    ];
    if (!student_gamification_leaderboard_ready($conn) || !student_gamification_seasons_tables_ready($conn)) {
        return $empty;
    }

    $stmt = mysqli_prepare(
        $conn,
        'SELECT season_id, slug, title, starts_at, ends_at, status, created_at
         FROM student_gamification_seasons WHERE season_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return $empty;
    }
    mysqli_stmt_bind_param($stmt, 'i', $seasonId);
    mysqli_stmt_execute($stmt);
    $seasonRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    if (!$seasonRow) {
        return array_merge($empty, ['ready' => true]);
    }

    $season = [
        'season_id' => (int) ($seasonRow['season_id'] ?? 0),
        'slug' => (string) ($seasonRow['slug'] ?? ''),
        'title' => (string) ($seasonRow['title'] ?? ''),
        'starts_at' => (string) ($seasonRow['starts_at'] ?? ''),
        'ends_at' => (string) ($seasonRow['ends_at'] ?? ''),
        'status' => (string) ($seasonRow['status'] ?? ''),
    ];

    $elig = student_gamification_leaderboard_eligibility_sql('u', $conn);
    $startsAt = $season['starts_at'];
    $endsAt = $season['ends_at'];

    $countSql = "SELECT COUNT(*) AS c FROM (
            SELECT e.user_id
            FROM student_gamification_events e
            INNER JOIN users u ON u.user_id = e.user_id
            WHERE e.created_at >= ? AND e.created_at < ?
              AND {$elig}
            GROUP BY e.user_id
            HAVING SUM(e.xp_delta) > 0
        ) AS ranked";
    $countStmt = mysqli_prepare($conn, $countSql);
    if (!$countStmt) {
        return array_merge($empty, ['ready' => true, 'season' => $season]);
    }
    mysqli_stmt_bind_param($countStmt, 'ss', $startsAt, $endsAt);
    mysqli_stmt_execute($countStmt);
    $total = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['c'] ?? 0);
    mysqli_stmt_close($countStmt);

    $pagination = student_gamification_leaderboard_pagination($page, $perPage, $total);
    $offset = ($pagination['current'] - 1) * $pagination['per_page'];

    $sql = "SELECT agg.user_id, agg.season_xp, agg.last_season_xp_at, u.full_name, p.total_xp
            FROM (
                SELECT e.user_id,
                       SUM(e.xp_delta) AS season_xp,
                       MAX(CASE WHEN e.xp_delta > 0 THEN e.created_at END) AS last_season_xp_at
                FROM student_gamification_events e
                INNER JOIN users u ON u.user_id = e.user_id
                WHERE e.created_at >= ? AND e.created_at < ?
                  AND {$elig}
                GROUP BY e.user_id
                HAVING season_xp > 0
            ) AS agg
            INNER JOIN users u ON u.user_id = agg.user_id
            INNER JOIN student_gamification_profile p ON p.user_id = agg.user_id
            ORDER BY agg.season_xp DESC, agg.last_season_xp_at ASC, agg.user_id ASC
            LIMIT ? OFFSET ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return array_merge($empty, ['ready' => true, 'season' => $season]);
    }
    mysqli_stmt_bind_param($stmt, 'ssii', $startsAt, $endsAt, $pagination['per_page'], $offset);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    $rank = $offset + 1;
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $lifetimeXp = (int) ($row['total_xp'] ?? 0);
        $tier = student_gamification_level_for_xp($lifetimeXp);
        $uid = (int) ($row['user_id'] ?? 0);
        $rows[] = [
            'rank' => $rank,
            'display_name' => student_gamification_leaderboard_display_name((string) ($row['full_name'] ?? '')),
            'score_xp' => (int) ($row['season_xp'] ?? 0),
            'level' => (int) ($tier['level'] ?? 1),
            'rank_title' => (string) ($tier['rank'] ?? ''),
            'is_viewer' => ($viewerUserId > 0 && $uid === $viewerUserId),
        ];
        $rank++;
    }
    mysqli_stmt_close($stmt);

    return [
        'rows' => $rows,
        'pagination' => $pagination,
        'ready' => true,
        'season' => $season,
    ];
}

/**
 * @return array{
 *   ready:bool,
 *   board_type:string,
 *   rank:?int,
 *   ranked_total:int,
 *   score_xp:int,
 *   level:int,
 *   rank_title:string,
 *   xp_gap_above:int,
 *   above_rank:?int,
 *   joined:bool
 * }
 */
function student_gamification_leaderboard_user_standing(
    mysqli $conn,
    int $userId,
    string $boardType = 'lifetime',
    ?int $seasonId = null
): array {
    $base = [
        'ready' => false,
        'board_type' => $boardType,
        'rank' => null,
        'ranked_total' => 0,
        'score_xp' => 0,
        'level' => 1,
        'rank_title' => STUDENT_GAMIFICATION_LEVELS[0]['rank'],
        'xp_gap_above' => 0,
        'above_rank' => null,
        'joined' => false,
    ];
    if ($userId <= 0 || !student_gamification_leaderboard_ready($conn)) {
        return $base;
    }

    $profile = student_gamification_profile_fetch($conn, $userId);
    $lifetimeXp = (int) ($profile['total_xp'] ?? 0);
    $tier = student_gamification_level_for_xp($lifetimeXp);
    $base['level'] = (int) ($tier['level'] ?? 1);
    $base['rank_title'] = (string) ($tier['rank'] ?? '');
    $base['ready'] = true;

    if (!student_gamification_leaderboard_user_is_eligible($conn, $userId)) {
        return $base;
    }

    if ($boardType === 'season') {
        if ($seasonId === null || $seasonId <= 0 || !student_gamification_seasons_tables_ready($conn)) {
            return $base;
        }
        $stmt = mysqli_prepare(
            $conn,
            'SELECT starts_at, ends_at FROM student_gamification_seasons WHERE season_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return $base;
        }
        mysqli_stmt_bind_param($stmt, 'i', $seasonId);
        mysqli_stmt_execute($stmt);
        $seasonRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);
        if (!$seasonRow) {
            return $base;
        }
        $startsAt = (string) ($seasonRow['starts_at'] ?? '');
        $endsAt = (string) ($seasonRow['ends_at'] ?? '');

        $sumStmt = mysqli_prepare(
            $conn,
            'SELECT COALESCE(SUM(xp_delta), 0) AS s,
                    MAX(CASE WHEN xp_delta > 0 THEN created_at END) AS last_at
             FROM student_gamification_events
             WHERE user_id = ? AND created_at >= ? AND created_at < ?'
        );
        if (!$sumStmt) {
            return $base;
        }
        mysqli_stmt_bind_param($sumStmt, 'iss', $userId, $startsAt, $endsAt);
        mysqli_stmt_execute($sumStmt);
        $sumRow = mysqli_fetch_assoc(mysqli_stmt_get_result($sumStmt)) ?: [];
        mysqli_stmt_close($sumStmt);
        $scoreXp = (int) ($sumRow['s'] ?? 0);
        $lastAt = (string) ($sumRow['last_at'] ?? '');
        $base['score_xp'] = $scoreXp;
        if ($scoreXp <= 0) {
            return $base;
        }
        $base['joined'] = true;

        $elig = student_gamification_leaderboard_eligibility_sql('u', $conn);
        $rankedSql = "SELECT COUNT(*) AS c FROM (
            SELECT e.user_id
            FROM student_gamification_events e
            INNER JOIN users u ON u.user_id = e.user_id
            WHERE e.created_at >= ? AND e.created_at < ? AND {$elig}
            GROUP BY e.user_id
            HAVING SUM(e.xp_delta) > 0
        ) AS t";
        $rankedStmt = mysqli_prepare($conn, $rankedSql);
        if ($rankedStmt) {
            mysqli_stmt_bind_param($rankedStmt, 'ss', $startsAt, $endsAt);
            mysqli_stmt_execute($rankedStmt);
            $base['ranked_total'] = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($rankedStmt))['c'] ?? 0);
            mysqli_stmt_close($rankedStmt);
        }

        $aheadSql = "SELECT COUNT(*) AS c FROM (
            SELECT e.user_id,
                   SUM(e.xp_delta) AS season_xp,
                   MAX(CASE WHEN e.xp_delta > 0 THEN e.created_at END) AS last_season_xp_at
            FROM student_gamification_events e
            INNER JOIN users u ON u.user_id = e.user_id
            WHERE e.created_at >= ? AND e.created_at < ? AND {$elig}
            GROUP BY e.user_id
            HAVING season_xp > 0
        ) AS agg
        WHERE agg.season_xp > ?
           OR (agg.season_xp = ? AND agg.last_season_xp_at < ?)
           OR (agg.season_xp = ? AND agg.last_season_xp_at = ? AND agg.user_id < ?)";
        $aheadStmt = mysqli_prepare($conn, $aheadSql);
        if ($aheadStmt) {
            mysqli_stmt_bind_param(
                $aheadStmt,
                'ssiissii',
                $startsAt,
                $endsAt,
                $scoreXp,
                $scoreXp,
                $lastAt,
                $scoreXp,
                $lastAt,
                $userId
            );
            mysqli_stmt_execute($aheadStmt);
            $ahead = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($aheadStmt))['c'] ?? 0);
            mysqli_stmt_close($aheadStmt);
            $base['rank'] = $ahead + 1;
            $base['above_rank'] = $ahead > 0 ? $ahead : null;
        }

        $aboveSql = "SELECT agg.season_xp FROM (
            SELECT e.user_id,
                   SUM(e.xp_delta) AS season_xp,
                   MAX(CASE WHEN e.xp_delta > 0 THEN e.created_at END) AS last_season_xp_at
            FROM student_gamification_events e
            INNER JOIN users u ON u.user_id = e.user_id
            WHERE e.created_at >= ? AND e.created_at < ? AND {$elig}
            GROUP BY e.user_id
            HAVING season_xp > 0
        ) AS agg
        WHERE agg.season_xp > ?
           OR (agg.season_xp = ? AND agg.last_season_xp_at < ?)
           OR (agg.season_xp = ? AND agg.last_season_xp_at = ? AND agg.user_id < ?)
        ORDER BY agg.season_xp ASC, agg.last_season_xp_at DESC, agg.user_id DESC
        LIMIT 1";
        $aboveStmt = mysqli_prepare($conn, $aboveSql);
        if ($aboveStmt) {
            mysqli_stmt_bind_param(
                $aboveStmt,
                'ssiissii',
                $startsAt,
                $endsAt,
                $scoreXp,
                $scoreXp,
                $lastAt,
                $scoreXp,
                $lastAt,
                $userId
            );
            mysqli_stmt_execute($aboveStmt);
            $aboveRow = mysqli_fetch_assoc(mysqli_stmt_get_result($aboveStmt)) ?: null;
            mysqli_stmt_close($aboveStmt);
            if ($aboveRow) {
                $base['xp_gap_above'] = max(0, (int) ($aboveRow['season_xp'] ?? 0) - $scoreXp);
            }
        }

        return $base;
    }

    // Lifetime
    $base['score_xp'] = $lifetimeXp;
    if ($lifetimeXp <= 0) {
        return $base;
    }
    $base['joined'] = true;

    $elig = student_gamification_leaderboard_eligibility_sql('u', $conn);
    $lastXpSub = student_gamification_leaderboard_last_xp_subquery('p2');

    $rankedSql = "SELECT COUNT(*) AS c
                  FROM student_gamification_profile p2
                  INNER JOIN users u ON u.user_id = p2.user_id
                  WHERE p2.total_xp > 0 AND {$elig}";
    $rankedRes = mysqli_query($conn, $rankedSql);
    $base['ranked_total'] = $rankedRes ? (int) (mysqli_fetch_assoc($rankedRes)['c'] ?? 0) : 0;

    $lastXpStmt = mysqli_prepare(
        $conn,
        'SELECT MAX(created_at) AS last_at FROM student_gamification_events
         WHERE user_id = ? AND xp_delta > 0'
    );
    $lastAt = '';
    if ($lastXpStmt) {
        mysqli_stmt_bind_param($lastXpStmt, 'i', $userId);
        mysqli_stmt_execute($lastXpStmt);
        $lastRow = mysqli_fetch_assoc(mysqli_stmt_get_result($lastXpStmt)) ?: [];
        $lastAt = (string) ($lastRow['last_at'] ?? '');
        mysqli_stmt_close($lastXpStmt);
    }

    $aheadSql = "SELECT COUNT(*) AS c
                 FROM student_gamification_profile p2
                 INNER JOIN users u ON u.user_id = p2.user_id
                 WHERE p2.total_xp > 0 AND {$elig}
                   AND (
                     p2.total_xp > ?
                     OR (p2.total_xp = ? AND {$lastXpSub} < ?)
                     OR (p2.total_xp = ? AND {$lastXpSub} = ? AND p2.user_id < ?)
                   )";
    $aheadStmt = mysqli_prepare($conn, $aheadSql);
    if ($aheadStmt) {
        mysqli_stmt_bind_param(
            $aheadStmt,
            'iissii',
            $lifetimeXp,
            $lifetimeXp,
            $lastAt,
            $lifetimeXp,
            $lastAt,
            $userId
        );
        mysqli_stmt_execute($aheadStmt);
        $ahead = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($aheadStmt))['c'] ?? 0);
        mysqli_stmt_close($aheadStmt);
        $base['rank'] = $ahead + 1;
        $base['above_rank'] = $ahead > 0 ? $ahead : null;
    }

    $aboveSql = "SELECT p2.total_xp AS score_xp
                 FROM student_gamification_profile p2
                 INNER JOIN users u ON u.user_id = p2.user_id
                 WHERE p2.total_xp > 0 AND {$elig}
                   AND (
                     p2.total_xp > ?
                     OR (p2.total_xp = ? AND {$lastXpSub} < ?)
                     OR (p2.total_xp = ? AND {$lastXpSub} = ? AND p2.user_id < ?)
                   )
                 ORDER BY p2.total_xp ASC, {$lastXpSub} DESC, p2.user_id DESC
                 LIMIT 1";
    $aboveStmt = mysqli_prepare($conn, $aboveSql);
    if ($aboveStmt) {
        mysqli_stmt_bind_param(
            $aboveStmt,
            'iissii',
            $lifetimeXp,
            $lifetimeXp,
            $lastAt,
            $lifetimeXp,
            $lastAt,
            $userId
        );
        mysqli_stmt_execute($aboveStmt);
        $aboveRow = mysqli_fetch_assoc(mysqli_stmt_get_result($aboveStmt)) ?: null;
        mysqli_stmt_close($aboveStmt);
        if ($aboveRow) {
            $base['xp_gap_above'] = max(0, (int) ($aboveRow['score_xp'] ?? 0) - $lifetimeXp);
        }
    }

    return $base;
}

/**
 * @return array{ready:bool,top:list<array<string,mixed>>,standing:array<string,mixed>}
 */
function student_gamification_leaderboard_preview(mysqli $conn, int $userId): array
{
    $board = student_gamification_leaderboard_lifetime($conn, 1, 3, $userId);
    $standing = student_gamification_leaderboard_user_standing($conn, $userId, 'lifetime');
    return [
        'ready' => !empty($board['ready']),
        'top' => $board['rows'],
        'standing' => $standing,
    ];
}
