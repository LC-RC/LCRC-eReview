<?php
require_once 'auth.php';
requireRole('admin');

$token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    $_SESSION['error'] = 'Invalid request. Please try again.';
    header('Location: admin_dashboard.php');
    exit;
}

$userId = (int)($_POST['user_id'] ?? 0);
$months = (int)($_POST['months'] ?? 0);
if ($userId <= 0 || $months <= 0) {
    header('Location: admin_dashboard.php');
    exit;
}

// Set access window
$sql = "UPDATE users SET status='approved', access_start=NOW(), access_end=DATE_ADD(NOW(), INTERVAL ? MONTH), access_months=? WHERE user_id=?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'iii', $months, $months, $userId);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

header('Location: admin_dashboard.php');
exit;
?>



