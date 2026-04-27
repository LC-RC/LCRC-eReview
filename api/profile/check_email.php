<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/profile_rate_limit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn() || !verifySession()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$uid = getCurrentUserId();
if (!$uid) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (ereview_profile_rate_limit_exceeded('check_email', 40, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many requests. Please wait a moment.']);
    exit;
}

$stmtRole = mysqli_prepare($conn, 'SELECT role FROM users WHERE user_id = ? LIMIT 1');
if ($stmtRole) {
    mysqli_stmt_bind_param($stmtRole, 'i', $uid);
    mysqli_stmt_execute($stmtRole);
    $rr = mysqli_stmt_get_result($stmtRole);
    $roleRow = $rr ? mysqli_fetch_assoc($rr) : null;
    mysqli_stmt_close($stmtRole);
    $r = (string)($roleRow['role'] ?? '');
    if ($r === 'student' || $r === 'college_student') {
        echo json_encode([
            'ok' => false,
            'locked' => true,
            'message' => 'Your sign-in email cannot be changed here.',
        ]);
        exit;
    }
}

$email = trim((string)($_GET['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => true, 'available' => false, 'invalid' => true, 'message' => 'Invalid email format.']);
    exit;
}

$stmtSelf = mysqli_prepare($conn, 'SELECT email FROM users WHERE user_id = ? LIMIT 1');
if (!$stmtSelf) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
mysqli_stmt_bind_param($stmtSelf, 'i', $uid);
mysqli_stmt_execute($stmtSelf);
$resSelf = mysqli_stmt_get_result($stmtSelf);
$selfRow = $resSelf ? mysqli_fetch_assoc($resSelf) : null;
mysqli_stmt_close($stmtSelf);

$currentEmail = strtolower(trim((string)($selfRow['email'] ?? '')));
$normalizedInput = strtolower(trim($email));

if ($normalizedInput === $currentEmail) {
    echo json_encode([
        'ok' => true,
        'available' => true,
        'yours' => true,
        'message' => 'This is your current email.',
    ]);
    exit;
}

$stmtDup = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND user_id <> ? LIMIT 1');
if (!$stmtDup) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
mysqli_stmt_bind_param($stmtDup, 'si', $email, $uid);
mysqli_stmt_execute($stmtDup);
$dupRes = mysqli_stmt_get_result($stmtDup);
$taken = $dupRes && mysqli_fetch_assoc($dupRes);
mysqli_stmt_close($stmtDup);

echo json_encode([
    'ok' => true,
    'available' => !$taken,
    'yours' => false,
    'message' => $taken ? 'That email is already used by another account.' : 'Email is available.',
]);
