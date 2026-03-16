<?php
/**
 * Check if email is available (not already registered).
 * GET/POST: email=... -> JSON { available: true|false, error?: string }
 */
require_once 'db.php';

header('Content-Type: application/json; charset=UTF-8');

$email = isset($_GET['email']) ? trim($_GET['email']) : (isset($_POST['email']) ? trim($_POST['email']) : '');
if ($email === '') {
    echo json_encode(['available' => false, 'error' => 'Email is required.']);
    exit;
}
// Basic format check
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['available' => false, 'error' => 'Invalid email format.']);
    exit;
}
// Reject space-only local part (e.g. " @gmail.com")
$at = strpos($email, '@');
$local = $at !== false ? substr($email, 0, $at) : '';
if (trim($local) === '') {
    echo json_encode(['available' => false, 'error' => 'Invalid email.']);
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$exists = mysqli_num_rows($result) > 0;
mysqli_stmt_close($stmt);

// Also check pending_registrations if table exists
$pendingExists = false;
$pendingStmt = @mysqli_prepare($conn, "SELECT 1 FROM pending_registrations WHERE email = ? LIMIT 1");
if ($pendingStmt) {
    mysqli_stmt_bind_param($pendingStmt, 's', $email);
    mysqli_stmt_execute($pendingStmt);
    $pr = mysqli_stmt_get_result($pendingStmt);
    if ($pr && mysqli_num_rows($pr) > 0) $pendingExists = true;
    if ($pr) mysqli_free_result($pr);
    mysqli_stmt_close($pendingStmt);
}

echo json_encode([
    'available' => !$exists && !$pendingExists,
    'message'   => ($exists || $pendingExists) ? 'This email is already registered. Please use another email or sign in instead.' : null,
]);
