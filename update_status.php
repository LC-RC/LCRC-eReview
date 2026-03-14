<?php
session_start();
include "db.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$new_status = strtolower($_GET['status'] ?? '');
if (!in_array($new_status, ['pending','approved','rejected'])) {
    header("Location: admin_dashboard.php");
    exit;
}

mysqli_query($conn, "UPDATE users SET status='$new_status' WHERE user_id=$id");
header("Location: admin_dashboard.php");
exit;
?>
