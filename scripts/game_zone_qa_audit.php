<?php
/**
 * Read-only Game Zone QA audit (CLI). Does not grant XP or modify data.
 * Usage: c:\xampp\php\php.exe scripts/game_zone_qa_audit.php [user_id]
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_gamification.php';
require_once __DIR__ . '/../includes/student_gamification_leaderboard.php';
require_once __DIR__ . '/../includes/format_display_name.php';

$userId = isset($argv[1]) ? max(1, (int) $argv[1]) : 2;

function qa_row(string $section, string $check, string $result, string $notes = ''): void
{
    echo sprintf("%-12s | %-42s | %-4s | %s\n", $section, $check, $result, $notes);
}

function qa_snap(mysqli $conn, int $userId): array
{
    $snap = ['total_xp' => 0, 'event_count' => 0, 'xp_sum' => 0, 'ach_count' => 0];
    $stmt = mysqli_prepare($conn, 'SELECT total_xp FROM student_gamification_profile WHERE user_id = ? LIMIT 1');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        $snap['total_xp'] = (int) ($row['total_xp'] ?? 0);
    }
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c, COALESCE(SUM(xp_delta),0) AS s FROM student_gamification_events WHERE user_id = ?');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        $snap['event_count'] = (int) ($row['c'] ?? 0);
        $snap['xp_sum'] = (int) ($row['s'] ?? 0);
    }
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM student_achievements WHERE user_id = ?');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        $snap['ach_count'] = (int) ($row['c'] ?? 0);
    }
    return $snap;
}

function qa_render_page(string $script, int $userId): string
{
    global $conn;
    $root = dirname($script);
    $prevCwd = getcwd();
    chdir($root);

    $_GET = $_GET ?? [];
    $_SERVER['QUERY_STRING'] = $_SERVER['QUERY_STRING'] ?? '';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/' . basename($script);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = 'student';
    $_SESSION['full_name'] = $_SESSION['full_name'] ?? 'Test Student QA';

    ob_start();
    try {
        include $script;
    } catch (Throwable $e) {
        ob_end_clean();
        if ($prevCwd) {
            chdir($prevCwd);
        }
        return '<!-- RENDER_ERROR: ' . $e->getMessage() . ' -->';
    }
    $html = (string) ob_get_clean();
    if ($prevCwd) {
        chdir($prevCwd);
    }
    return $html;
}

echo "=== Game Zone QA Audit (user {$userId}) ===\n\n";

$before = qa_snap($conn, $userId);
echo "DATA BASELINE: total_xp={$before['total_xp']} events={$before['event_count']} sum_xp={$before['xp_sum']} achievements={$before['ach_count']}\n\n";

$root = dirname(__DIR__);
$pages = [
    'play' => $root . '/student_playground.php',
    'career' => $root . '/student_playground_career.php',
    'compete' => $root . '/student_playground_leaderboard.php',
];

$html = [];
foreach ($pages as $key => $path) {
    $html[$key] = qa_render_page($path, $userId);
}

$after = qa_snap($conn, $userId);
$dataOk = $before === $after;

// 1. Play hub
qa_row('Play', 'Game Zone kicker present', strpos($html['play'], 'LCRC eReview · Game Zone') !== false ? 'PASS' : 'FAIL');
qa_row('Play', 'Zone nav PLAY/CAREER/COMPETE', (strpos($html['play'], 'pg-zone-nav') !== false && strpos($html['play'], 'Career') !== false && strpos($html['play'], 'Compete') !== false) ? 'PASS' : 'FAIL');
qa_row('Play', 'Active tab = Play', strpos($html['play'], 'pg-zone-nav__link is-active') !== false && preg_match('/pg-zone-nav__link is-active[^>]*>.*?Play/s', $html['play']) ? 'PASS' : 'FAIL');
qa_row('Play', 'Career preview card', strpos($html['play'], 'aria-label="Career progress"') !== false ? 'PASS' : 'FAIL');
qa_row('Play', 'Compete preview card', strpos($html['play'], 'aria-label="Compete preview"') !== false ? 'PASS' : 'FAIL');
qa_row('Play', 'Links to internal career route', strpos($html['play'], 'student_playground_career') !== false ? 'PASS' : 'FAIL');
qa_row('Play', 'No legacy career links', strpos($html['play'], 'student_career"') === false && strpos($html['play'], "student_career'") === false ? 'PASS' : 'FAIL');
qa_row('Play', 'Game modes present', strpos($html['play'], 'Quick Play') !== false && strpos($html['play'], 'CPA Battle') !== false ? 'PASS' : 'FAIL');
qa_row('Play', 'Uses pg-theme dark shell', strpos($html['play'], 'pg-theme') !== false && strpos($html['play'], 'student_career_styles') === false ? 'PASS' : 'FAIL');
qa_row('Play', 'No light career-page class', strpos($html['play'], 'career-page') === false ? 'PASS' : 'FAIL');

// 2. Career
qa_row('Career', 'Zone header present', strpos($html['career'], 'pg-zone-header') !== false ? 'PASS' : 'FAIL');
qa_row('Career', 'Active tab = Career', preg_match('/pg-zone-nav__link is-active[^>]*>.*?Career/s', $html['career']) ? 'PASS' : 'FAIL');
qa_row('Career', 'Dark pg-theme only', strpos($html['career'], 'pg-theme') !== false && strpos($html['career'], 'student_career_styles') === false ? 'PASS' : 'FAIL');
qa_row('Career', 'Career section renders', strpos($html['career'], 'pg-career-hub') !== false ? 'PASS' : 'FAIL');
qa_row('Career', 'XP progress bar', strpos($html['career'], 'pg-career-progress-bar') !== false ? 'PASS' : 'FAIL');
qa_row('Career', 'XP history list', strpos($html['career'], 'pg-career-history') !== false ? 'PASS' : 'FAIL');
qa_row('Career', 'Achievements grid', strpos($html['career'], 'pg-career-ach-grid') !== false ? 'PASS' : 'FAIL');
qa_row('Career', 'Single zone nav (no duplicate nav)', substr_count($html['career'], 'pg-zone-nav') === 1 ? 'PASS' : 'FAIL', 'count=' . substr_count($html['career'], 'pg-zone-nav'));
qa_row('Career', 'No legacy student_career_nav', strpos($html['career'], 'career-subnav') === false ? 'PASS' : 'FAIL');

$career = student_gamification_career_summary($conn, $userId);
qa_row('Career', 'XP matches DB profile', strpos($html['career'], number_format((int) $career['total_xp'])) !== false ? 'PASS' : 'FAIL', 'xp=' . $career['total_xp']);

// 3. Compete
qa_row('Compete', 'Zone header present', strpos($html['compete'], 'pg-zone-header') !== false ? 'PASS' : 'FAIL');
qa_row('Compete', 'Active tab = Compete', preg_match('/pg-zone-nav__link is-active[^>]*>.*?Compete/s', $html['compete']) ? 'PASS' : 'FAIL');
qa_row('Compete', 'Standing card', strpos($html['compete'], 'pg-zone-standing-card') !== false ? 'PASS' : 'FAIL');
qa_row('Compete', 'Leaderboard table', strpos($html['compete'], 'pg-zone-leaderboard-table') !== false ? 'PASS' : 'FAIL');
qa_row('Compete', 'Season tab disabled placeholder', strpos($html['compete'], 'is-disabled') !== false ? 'PASS' : 'FAIL');
qa_row('Compete', 'Dark theme only', strpos($html['compete'], 'student_career_styles') === false ? 'PASS' : 'FAIL');

$standing = student_gamification_leaderboard_user_standing($conn, $userId, 'lifetime');
if (!empty($standing['joined'])) {
    qa_row('Compete', 'User rank in standing', strpos($html['compete'], '#' . (int) $standing['rank']) !== false ? 'PASS' : 'FAIL');
} else {
    qa_row('Compete', 'Unranked empty state', strpos($html['compete'], "haven't joined") !== false ? 'PASS' : 'FAIL');
}

// PII check on leaderboard rows
$board = student_gamification_leaderboard_lifetime($conn, 1, 25, $userId);
$piiFail = false;
$piiNotes = [];
foreach ($board['rows'] as $row) {
    $dn = (string) ($row['display_name'] ?? '');
    if (strpos($dn, '@') !== false) {
        $piiFail = true;
        $piiNotes[] = 'email-like';
    }
    if (preg_match('/\b(user_id|school)\b/i', $dn)) {
        $piiFail = true;
    }
}
qa_row('Compete', 'No email in display names', !$piiFail ? 'PASS' : 'FAIL', implode(',', $piiNotes));

// 4. Sidebar in rendered pages
foreach (['play', 'career', 'compete'] as $p) {
    qa_row('Nav', "Sidebar: no Career Progress ({$p})", strpos($html[$p], 'Career Progress</') === false ? 'PASS' : 'FAIL');
    qa_row('Nav', "Sidebar: CPA Playground present ({$p})", strpos($html[$p], 'CPA Playground') !== false ? 'PASS' : 'FAIL');
}

// 5. Legacy redirect logic (file-level)
$careerLegacy = file_get_contents($root . '/student_career.php') ?: '';
$lbLegacy = file_get_contents($root . '/student_career_leaderboard.php') ?: '';
qa_row('Legacy', 'student_career → playground_career', strpos($careerLegacy, 'student_playground_career') !== false ? 'PASS' : 'FAIL');
qa_row('Legacy', 'leaderboard redirect preserves QS', strpos($lbLegacy, 'QUERY_STRING') !== false ? 'PASS' : 'FAIL');

// 6. Reward banners on result pages (structure only)
foreach ([
    'playground_result' => $root . '/student_playground_result.php',
    'battle_result' => $root . '/student_playground_battle_result.php',
] as $label => $file) {
    $src = file_get_contents($file) ?: '';
    qa_row('Reward', "{$label} includes career reward", strpos($src, 'student_career_reward.php') !== false ? 'PASS' : 'FAIL');
}
$quizSrc = file_get_contents($root . '/student_take_quiz.php') ?: '';
qa_row('Reward', 'quiz includes career reward', strpos($quizSrc, 'student_career_reward.php') !== false ? 'PASS' : 'FAIL');

// 7. Data safety
qa_row('Data', 'total_xp unchanged', $before['total_xp'] === $after['total_xp'] ? 'PASS' : 'FAIL', "before={$before['total_xp']} after={$after['total_xp']}");
qa_row('Data', 'event count unchanged', $before['event_count'] === $after['event_count'] ? 'PASS' : 'FAIL');
qa_row('Data', 'SUM(xp_delta) unchanged', $before['xp_sum'] === $after['xp_sum'] ? 'PASS' : 'FAIL');
qa_row('Data', 'achievements unchanged', $before['ach_count'] === $after['ach_count'] ? 'PASS' : 'FAIL');
qa_row('Data', 'No grants during render', $dataOk ? 'PASS' : 'FAIL');

echo "\n=== Visual notes (code/CSS review) ===\n";
echo "- Career subpage has Game Zone header PLUS inner 'Career Progress' section header (layered, not duplicated nav).\n";
echo "- Play hub music controls sit in pg-zone-header__meta (right side); verify alignment on narrow screens manually.\n";
echo "- Orphan files student_career_nav.php / student_leaderboard_preview.php still reference legacy URLs but are NOT included in Game Zone pages.\n";
echo "- Pagination: only visible when total_pages > 1 (may be N/A for small user base).\n";
echo "- Table overflow: pg-zone-table-wrap provides horizontal scroll on small screens.\n";

echo "\nDone.\n";
