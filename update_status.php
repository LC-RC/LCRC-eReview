<?php
/**
 * Legacy status flip - hardened: cannot approve a student without an active access grant.
 */
require_once __DIR__ . '/auth.php';
requireAdminPage('students');
require_once __DIR__ . '/includes/commerce_access_gate.php';

$id = (int) ($_GET['id'] ?? 0);
$new_status = strtolower((string) ($_GET['status'] ?? ''));
if ($id <= 0 || !in_array($new_status, ['pending', 'approved', 'rejected'], true)) {
    header('Location: admin_dashboard');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT user_id, role, status FROM users WHERE user_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$user = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);
if (!$user) {
    header('Location: admin_dashboard');
    exit;
}

if ((string) ($user['role'] ?? '') === 'student' && $new_status === 'approved') {
    if (!commerce_student_has_active_access($conn, $id)) {
        $_SESSION['error'] = 'Cannot set Active without an active access grant. Use Grant Access or approve payment first.';
        header('Location: admin_dashboard');
        exit;
    }
}

$upd = mysqli_prepare($conn, 'UPDATE users SET status = ? WHERE user_id = ? LIMIT 1');
mysqli_stmt_bind_param($upd, 'si', $new_status, $id);
mysqli_stmt_execute($upd);
mysqli_stmt_close($upd);

header('Location: admin_dashboard');
exit;
