<?php
require_once 'auth.php';
requireRole('admin');

$token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    $_SESSION['error'] = 'Invalid request. Please try again.';
    header('Location: admin_dashboard.php');
    exit;
}

$userId = sanitizeInt($_POST['user_id'] ?? 0);
$months = sanitizeInt($_POST['months'] ?? 0);
if ($userId <= 0 || $months <= 0) {
    header('Location: admin_dashboard.php');
    exit;
}

// Extend from current access_end if exists and in future, otherwise from NOW()
$stmt = mysqli_prepare($conn, "SELECT access_end, access_months FROM users WHERE user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if ($row && !empty($row['access_end'])) {
    $sql = "UPDATE users SET access_end=DATE_ADD(access_end, INTERVAL ? MONTH), access_months=IFNULL(access_months,0)+? WHERE user_id=?";
} else {
    $sql = "UPDATE users SET access_start=NOW(), access_end=DATE_ADD(NOW(), INTERVAL ? MONTH), access_months=IFNULL(access_months,0)+? WHERE user_id=?";
}
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'iii', $months, $months, $userId);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

header('Location: admin_dashboard.php');
exit;
?>



