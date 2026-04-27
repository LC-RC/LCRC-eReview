<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';

if (!isLoggedIn() || !verifySession() || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (strlen($q) < 1) {
    echo json_encode(['ok' => true, 'items' => []]);
    exit;
}

$like = '%' . mysqli_real_escape_string($conn, $q) . '%';
$stmt = mysqli_prepare($conn, 'SELECT subject_id, subject_name FROM subjects WHERE status = ? AND subject_name LIKE ? ORDER BY subject_name ASC LIMIT 12');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
$active = 'active';
mysqli_stmt_bind_param($stmt, 'ss', $active, $like);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$items = [];
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $items[] = [
            'id' => (int)($row['subject_id'] ?? 0),
            'name' => (string)($row['subject_name'] ?? ''),
        ];
    }
}
mysqli_stmt_close($stmt);

echo json_encode(['ok' => true, 'items' => $items]);
