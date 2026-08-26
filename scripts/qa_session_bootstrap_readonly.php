<?php
require dirname(__DIR__) . '/session_config.php';
require dirname(__DIR__) . '/db.php';
$role = $argv[1] ?? 'professor_admin';
$stmt = mysqli_prepare($conn, "SELECT user_id, full_name, email, role FROM users WHERE role=? AND status='approved' LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $role);
mysqli_stmt_execute($stmt);
$u = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$u['user_id'];
$_SESSION['full_name'] = (string)$u['full_name'];
$_SESSION['email'] = (string)($u['email'] ?? '');
$_SESSION['role'] = (string)$u['role'];
$_SESSION['created'] = time();
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
echo session_id() . "\t" . $_SESSION['csrf_token'] . "\t" . $u['email'] . "\n";
session_write_close();
