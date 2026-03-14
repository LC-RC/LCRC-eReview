<?php
/**
 * API: check if an email has been verified (user exists with email_verified=1).
 * GET or POST: email (required). Returns JSON { "verified": true|false }.
 */
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['verified' => false, 'error' => 'invalid_email']);
    exit;
}

$verified = false;
$cols = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_verified'");
$hasCol = ($cols && mysqli_fetch_assoc($cols));
$sql = $hasCol
    ? "SELECT 1 FROM users WHERE email = ? AND (email_verified = 1 OR email_verified IS NULL) LIMIT 1"
    : "SELECT 1 FROM users WHERE email = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_fetch_assoc($result)) {
        $verified = true;
    }
    mysqli_stmt_close($stmt);
}

echo json_encode(['verified' => $verified]);
