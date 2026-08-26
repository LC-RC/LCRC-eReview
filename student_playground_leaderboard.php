<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/student_playground.php';
require_once __DIR__ . '/includes/student_gamification.php';
require_once __DIR__ . '/includes/student_gamification_leaderboard.php';
requireRole('student');

sca_ensure_schema($conn);
sca_enforce_student_session($conn);
student_playground_enforce_enabled($conn);

$userId = (int) getCurrentUserId();
$careerReady = student_gamification_leaderboard_ready($conn);
$pgZoneNavActive = 'compete';
$pageTitle = 'CPA Playground · Compete';

$boardType = (string) ($_GET['board'] ?? 'lifetime');
if ($boardType !== 'season') {
    $boardType = 'lifetime';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(50, (int) ($_GET['per_page'] ?? 25)));

$activeSeason = student_gamification_season_active($conn);
$seasonId = (int) ($_GET['season_id'] ?? ($activeSeason['season_id'] ?? 0));
if ($boardType === 'season' && $seasonId <= 0 && $activeSeason) {
    $seasonId = (int) $activeSeason['season_id'];
}

$board = ['rows' => [], 'pagination' => student_gamification_leaderboard_pagination(1, $perPage, 0), 'ready' => false];
$standing = student_gamification_leaderboard_user_standing($conn, $userId, 'lifetime');
$scoreLabel = 'XP';

if ($careerReady) {
    if ($boardType === 'season' && $seasonId > 0 && student_gamification_seasons_tables_ready($conn)) {
        $board = student_gamification_leaderboard_season($conn, $seasonId, $page, $perPage, $userId);
        $standing = student_gamification_leaderboard_user_standing($conn, $userId, 'season', $seasonId);
        $scoreLabel = 'Season XP';
    } else {
        $boardType = 'lifetime';
        $board = student_gamification_leaderboard_lifetime($conn, $page, $perPage, $userId);
        $standing = student_gamification_leaderboard_user_standing($conn, $userId, 'lifetime');
    }
}

$pagination = $board['pagination'] ?? student_gamification_leaderboard_pagination(1, $perPage, 0);
$seasonTitle = $board['season']['title'] ?? ($activeSeason['title'] ?? 'Current Season');

$pageUrlBuilder = static function (string $board, int $pageNum, int $per, int $season = 0): string {
    $q = ['board' => $board, 'page' => $pageNum, 'per_page' => $per];
    if ($board === 'season' && $season > 0) {
        $q['season_id'] = $season;
    }
    return 'student_playground_leaderboard?' . http_build_query($q);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <?php require __DIR__ . '/includes/components/playground_styles.php'; ?>
</head>
<body class="font-sans antialiased pg-theme pg-lobby-mode">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page pg-page pg-zone-page min-h-full">
    <?php require __DIR__ . '/includes/components/playground_zone_header.php'; ?>
    <?php require __DIR__ . '/includes/components/playground_leaderboard_panel.php'; ?>
  </div>
</body>
</html>
