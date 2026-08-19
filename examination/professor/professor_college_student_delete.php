<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: professor_college_students');
    exit;
}

$token = (string) ($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($token)) {
    $_SESSION['message'] = 'Invalid request. Student was not removed.';
    header('Location: professor_college_students');
    exit;
}

$targetId = sanitizeInt($_POST['user_id'] ?? 0);
$profId = (int) getCurrentUserId();
$removeMode = strtolower(trim((string) ($_POST['remove_mode'] ?? 'unlink')));
if (!in_array($removeMode, ['unlink', 'delete'], true)) {
    $removeMode = 'unlink';
}

if ($targetId <= 0 || $targetId === $profId) {
    $_SESSION['message'] = 'Invalid student selected.';
    header('Location: professor_college_students');
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    'SELECT user_id, full_name, role, college_examination_access FROM users WHERE user_id=? LIMIT 1'
);
mysqli_stmt_bind_param($stmt, 'i', $targetId);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$row) {
    $_SESSION['message'] = 'That student could not be found.';
    header('Location: professor_college_students');
    exit;
}

$name = (string) ($row['full_name'] ?? '');
$role = (string) ($row['role'] ?? '');

mysqli_begin_transaction($conn);
try {
    if ($role === 'student') {
        // LMS-linked account: clear examination access only. Never delete the user row.
        if (!ereview_platform_access_columns_ready($conn)) {
            throw new RuntimeException('missing columns');
        }
        $access = ereview_user_college_examination_access_value($row);
        if (!in_array($access, ['active', 'suspended'], true)) {
            throw new RuntimeException('not on roster');
        }
        $upd = mysqli_prepare(
            $conn,
            "UPDATE users
             SET college_examination_access='none'
             WHERE user_id=? AND role='student' LIMIT 1"
        );
        if (!$upd) {
            throw new RuntimeException('prepare');
        }
        mysqli_stmt_bind_param($upd, 'i', $targetId);
        mysqli_stmt_execute($upd);
        $aff = mysqli_stmt_affected_rows($upd);
        mysqli_stmt_close($upd);
        if ($aff < 1) {
            throw new RuntimeException('no rows');
        }
        mysqli_commit($conn);
        $_SESSION['message'] = 'Removed College Examination access for ' . $name . '. eReview LMS account was kept.';
    } elseif ($role === 'college_student') {
        // Native examination-only account: hard delete.
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
        $_SESSION['message'] = 'Deleted college student: ' . $name . '.';
    } else {
        throw new RuntimeException('unsupported role');
    }
} catch (Throwable $e) {
    mysqli_rollback($conn);
    $_SESSION['message'] = 'Could not remove student from Examination.';
}

header('Location: professor_college_students');
exit;
