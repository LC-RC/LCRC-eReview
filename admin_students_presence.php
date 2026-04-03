<?php
require_once 'auth.php';
requireRole('admin');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$idsRaw = trim($_GET['ids'] ?? '');
if ($idsRaw === '') {
    echo json_encode(['ok' => true, 'presence' => []]);
    exit;
}

$idParts = array_filter(array_map('trim', explode(',', $idsRaw)), static function ($v) {
    return $v !== '' && ctype_digit($v);
});
$ids = array_values(array_unique(array_map('intval', $idParts)));
if (!$ids) {
    echo json_encode(['ok' => true, 'presence' => []]);
    exit;
}

$hasIsOnline = false;
$hasLastSeenAt = false;
$hasLastLogoutAt = false;
$hasLastLoginAt = false;

$cp1 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'is_online'");
if ($cp1 && mysqli_fetch_assoc($cp1)) $hasIsOnline = true;
$cp2 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'last_seen_at'");
if ($cp2 && mysqli_fetch_assoc($cp2)) $hasLastSeenAt = true;
$cp3 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'last_logout_at'");
if ($cp3 && mysqli_fetch_assoc($cp3)) $hasLastLogoutAt = true;
$cp4 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'last_login_at'");
if ($cp4 && mysqli_fetch_assoc($cp4)) $hasLastLoginAt = true;

$cols = ['user_id'];
if ($hasIsOnline) $cols[] = 'is_online';
if ($hasLastSeenAt) $cols[] = 'last_seen_at';
if ($hasLastLogoutAt) $cols[] = 'last_logout_at';
if ($hasLastLoginAt) $cols[] = 'last_login_at';

$inList = implode(',', array_map('intval', $ids));
$sql = "SELECT " . implode(', ', $cols) . " FROM users WHERE user_id IN ($inList)";
$res = @mysqli_query($conn, $sql);

$presence = [];
$recentThresholdTs = time() - (2 * 60); // 2 minutes idle window
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $uid = (int) ($row['user_id'] ?? 0);
    if ($uid <= 0) {
        continue;
    }
    $active = false;
    if ($hasLastSeenAt && !empty($row['last_seen_at'])) {
        $ts = strtotime((string)$row['last_seen_at']);
        if ($ts !== false && $ts >= $recentThresholdTs) {
            $active = true;
        }
    } elseif ($hasLastLoginAt && !empty($row['last_login_at'])) {
        $ts = strtotime((string)$row['last_login_at']);
        if ($ts !== false && $ts >= $recentThresholdTs) {
            $active = true;
        }
    } elseif (!$hasLastSeenAt && !$hasLastLoginAt && $hasIsOnline && !empty($row['is_online'])) {
        // Legacy schema without timestamps: fall back to is_online flag.
        $active = true;
    }
    if ($hasLastLogoutAt && !empty($row['last_logout_at'])) {
        $logoutTs = strtotime((string)$row['last_logout_at']);
        $seenTs = ($hasLastSeenAt && !empty($row['last_seen_at'])) ? strtotime((string)$row['last_seen_at']) : false;
        if ($logoutTs !== false && ($seenTs === false || $seenTs <= $logoutTs)) {
            $active = false;
        }
    }
    $presence[(string) $uid] = $active;
}

echo json_encode(['ok' => true, 'presence' => $presence]);
exit;

