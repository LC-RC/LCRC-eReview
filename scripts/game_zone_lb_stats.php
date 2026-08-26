<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_gamification_leaderboard.php';
$b = student_gamification_leaderboard_lifetime($conn, 1, 25, 2);
echo 'ranked_total=' . ($b['pagination']['total'] ?? 0) . ' pages=' . ($b['pagination']['total_pages'] ?? 1) . PHP_EOL;
$s = student_gamification_leaderboard_user_standing($conn, 2, 'lifetime');
echo 'user2 rank=' . ($s['rank'] ?? 'n/a') . ' xp=' . ($s['score_xp'] ?? 0) . ' joined=' . (!empty($s['joined']) ? 'yes' : 'no') . PHP_EOL;
