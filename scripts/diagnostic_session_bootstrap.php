<?php
declare(strict_types=1);
/** Bootstrap one role session and print PHPSESSID for HTTP probes. Usage: php scripts/diagnostic_session_bootstrap.php professor_admin */
require dirname(__DIR__) . '/db.php';
$role = $argv[1] ?? '';
if ($role === '') {
    fwrite(STDERR, "Usage: php diagnostic_session_bootstrap.php <role>\n");
    exit(1);
}
$stmt = mysqli_prepare($conn, "SELECT user_id, full_name, email, role, status FROM users WHERE role=? AND status='approved' ORDER BY user_id ASC LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $role);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$user) {
    fwrite(STDERR, "No approved user for role: $role\n");
    exit(1);
}
require dirname(__DIR__) . '/session_config.php';
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['user_id'];
$_SESSION['full_name'] = (string)$user['full_name'];
$_SESSION['email'] = (string)($user['email'] ?? '');
$_SESSION['role'] = (string)$user['role'];
$_SESSION['created'] = time();
$_SESSION['last_activity'] = time();
echo session_id();
session_write_close();
