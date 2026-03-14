<?php
require_once 'auth.php';
requireRole('admin');

$token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    $_SESSION['error'] = 'Invalid request. Please try again.';
    header("Location: admin_dashboard.php");
    exit;
}

$id = sanitizeInt($_POST['user_id'] ?? 0);
if ($id <= 0) {
    header("Location: admin_dashboard.php");
    exit;
}

$stmt = mysqli_prepare($conn, "UPDATE users SET status='rejected' WHERE user_id=?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

header("Location: admin_dashboard.php");
exit;
?>
