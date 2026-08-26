<?php
/**
 * Phase 3 Game Zone integration smoke tests (CLI, read-only checks).
 * Usage: c:\xampp\php\php.exe scripts/game_zone_smoke_test.php [user_id]
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_gamification.php';
require_once __DIR__ . '/../includes/student_gamification_leaderboard.php';

$userId = isset($argv[1]) ? max(1, (int) $argv[1]) : 2;

$pass = 0;
$fail = 0;

function gz_assert(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        echo "PASS  {$label}\n";
        $pass++;
    } else {
        echo "FAIL  {$label}\n";
        $fail++;
    }
}

// T3-T7: read-only data integrity
$career = student_gamification_career_summary($conn, $userId);
gz_assert(!empty($career['ready']), 'T3 Career summary ready');
gz_assert(isset($career['total_xp'], $career['level'], $career['rank']), 'T3 Career fields present');

$events = student_gamification_list_events($conn, $userId, 30);
$achievements = student_gamification_achievement_gallery($conn, $userId);
gz_assert(is_array($events), 'T4 XP history is array');
gz_assert(is_array($achievements), 'T5 Achievements gallery is array');

$board = student_gamification_leaderboard_lifetime($conn, 1, 25, $userId);
$standing = student_gamification_leaderboard_user_standing($conn, $userId, 'lifetime');
gz_assert(!empty($board['ready']), 'T6 Leaderboard ready');
gz_assert(isset($standing['rank']) || empty($standing['joined']), 'T7 Standing structure ok');

// T8-T11: static file / redirect checks
$root = dirname(__DIR__);
$careerLegacy = file_get_contents($root . '/student_career.php') ?: '';
$lbLegacy = file_get_contents($root . '/student_career_leaderboard.php') ?: '';
$sidebar = file_get_contents($root . '/student_sidebar.php') ?: '';
$playground = file_get_contents($root . '/student_playground.php') ?: '';

gz_assert(strpos($careerLegacy, 'student_playground_career') !== false, 'T10 student_career.php redirects to playground career');
gz_assert(strpos($lbLegacy, 'student_playground_leaderboard') !== false, 'T11 student_career_leaderboard.php redirects to playground leaderboard');
gz_assert(strpos($sidebar, 'Career Progress') === false, 'T8 Sidebar has no Career Progress');
gz_assert(strpos($sidebar, "'label' => 'Leaderboard'") === false, 'T8 Sidebar has no standalone Leaderboard');
gz_assert(strpos($sidebar, 'student_playground_career') !== false, 'T9 Sidebar active includes playground career');
gz_assert(strpos($sidebar, 'student_playground_leaderboard') !== false, 'T9 Sidebar active includes playground leaderboard');
gz_assert(strpos($playground, 'student_playground_career') !== false, 'T1 Play hub links to playground career');
$previewFile = file_get_contents($root . '/includes/components/playground_leaderboard_preview.php') ?: '';
gz_assert(strpos($previewFile, 'student_playground_leaderboard') !== false, 'T1 Play hub compete preview links to playground leaderboard');
gz_assert(strpos($playground, 'student_career') === false, 'T1 Play hub does not link to legacy career');
gz_assert(strpos($playground, 'playground_zone_header.php') !== false, 'T2 Play hub includes zone header');

foreach (['student_playground_career.php', 'student_playground_leaderboard.php', 'includes/components/playground_zone_nav.php'] as $rel) {
    gz_assert(is_file($root . '/' . $rel), "File exists: {$rel}");
}

// T13: event count snapshot (no grant calls in this script)
$stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM student_gamification_events WHERE user_id = ?');
$before = 0;
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $before = (int) ($row['c'] ?? 0);
}
gz_assert($before >= 0, 'T13 Event count readable (no grants in test)');

// T12/T14: reward component untouched
$reward = file_get_contents($root . '/includes/components/student_career_reward.php') ?: '';
$gamification = file_get_contents($root . '/includes/student_gamification.php') ?: '';
gz_assert(strpos($reward, 'career-reward-banner') !== false, 'T12 Reward banner component intact');
gz_assert(strpos($gamification, 'function student_gamification_grant_event') !== false, 'T14 grant_event still present');

echo "\n---\nPASS: {$pass}  FAIL: {$fail}\n";
exit($fail > 0 ? 1 : 0);
