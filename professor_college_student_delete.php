<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: professor_college_students.php');
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($token)) {
    $_SESSION['message'] = 'Invalid request. Student was not removed.';
    header('Location: professor_college_students.php');
    exit;
}

$targetId = sanitizeInt($_POST['user_id'] ?? 0);
$profId = (int)getCurrentUserId();
if ($targetId <= 0 || $targetId === $profId) {
    $_SESSION['message'] = 'Invalid student selected.';
    header('Location: professor_college_students.php');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT user_id, full_name, role FROM users WHERE user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $targetId);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$row || (string)($row['role'] ?? '') !== 'college_student') {
    $_SESSION['message'] = 'That college student could not be found.';
    header('Location: professor_college_students.php');
    exit;
}

$name = (string)($row['full_name'] ?? '');

mysqli_begin_transaction($conn);
try {
    $del = mysqli_prepare($conn, "DELETE FROM users WHERE user_id=? AND role='college_student' LIMIT 1");
    if (!$del) {
        throw new RuntimeException('prepare');
    }
    mysqli_stmt_bind_param($del, 'i', $targetId);
    mysqli_stmt_execute($del);
    $aff = mysqli_stmt_affected_rows($del);
    mysqli_stmt_close($del);
    if ($aff < 1) {
        throw new RuntimeException('no rows');
    }
    mysqli_commit($conn);
    $_SESSION['message'] = 'Removed college student: ' . $name . '.';
} catch (Throwable $e) {
    mysqli_rollback($conn);
    $_SESSION['message'] = 'Could not remove student (they may still have linked records).';
}

header('Location: professor_college_students.php');
exit;
