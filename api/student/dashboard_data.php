<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';

if (!isLoggedIn() || !verifySession() || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = getCurrentUserId();
if ($uid === null || $uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hasWeeklyGoalCol = false;
$wgCol = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'weekly_activity_goal'");
if ($wgCol && mysqli_fetch_assoc($wgCol)) {
    $hasWeeklyGoalCol = true;
}
$weeklyActivityGoal = 0;
if ($hasWeeklyGoalCol) {
    $wgRes = @mysqli_query($conn, 'SELECT weekly_activity_goal AS g FROM users WHERE user_id = ' . (int)$uid . ' LIMIT 1');
    if ($wgRes && ($wgr = mysqli_fetch_assoc($wgRes))) {
        $weeklyActivityGoal = max(0, min(50, (int)($wgr['g'] ?? 0)));
    }
}

$tableExists = static function (mysqli $conn, string $table): bool {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($safe === '') {
        return false;
    }
    $res = @mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");

    return $res && mysqli_num_rows($res) > 0;
};

$info = null;
$stmt = mysqli_prepare($conn, 'SELECT access_start, access_end FROM users WHERE user_id = ? LIMIT 1');
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    if (mysqli_stmt_execute($stmt)) {
        $res = mysqli_stmt_get_result($stmt);
        $info = $res ? mysqli_fetch_assoc($res) : null;
    }
    mysqli_stmt_close($stmt);
}

require_once __DIR__ . '/../../includes/student_dashboard_aggregate.php';
$data = ereview_student_dashboard_aggregate($conn, (int)$uid, $tableExists, $info, $weeklyActivityGoal);

echo json_encode([
    'ok' => true,
    'generated_at' => gmdate('c'),
    'data' => $data,
], JSON_UNESCAPED_UNICODE);
