<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
require_once dirname(__DIR__, 2) . '/includes/user_archive_lifecycle.php';
require_once dirname(__DIR__) . '/includes/academic_record_protection.php';

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
$postedMode = strtolower(trim((string) ($_POST['remove_mode'] ?? '')));

if ($targetId <= 0 || $targetId === $profId) {
    $_SESSION['message'] = 'Invalid student selected.';
    header('Location: professor_college_students');
    exit;
}

mysqli_begin_transaction($conn);
try {
    $row = user_archive_lock_user($conn, $targetId);
    if (!$row) {
        throw new RuntimeException('That student could not be found.');
    }

    $name = (string) ($row['full_name'] ?? '');
    $role = (string) ($row['role'] ?? '');
    $removeMode = academic_record_resolve_student_remove_mode($postedMode, $role);

    if ($role === 'student') {
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
        if ($removeMode === 'delete') {
            $block = academic_record_user_hard_delete_block_reason($conn, $targetId);
            if ($block !== null) {
                throw new RuntimeException($block);
            }
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
            $result = user_archive_apply_locked($conn, $row, $profId, 'Archived from College Students');
            if (empty($result['ok'])) {
                throw new RuntimeException((string) ($result['error'] ?? 'Could not archive student.'));
            }
            mysqli_commit($conn);
            $_SESSION['message'] = !empty($result['already'])
                ? ('Student already archived: ' . $name . '.')
                : ('Archived college student: ' . $name . '. Student ID and academic records were kept.');
        }
    } else {
        throw new RuntimeException('unsupported role');
    }
} catch (Throwable $e) {
    mysqli_rollback($conn);
    $_SESSION['message'] = $e->getMessage() !== '' && !in_array($e->getMessage(), ['prepare', 'no rows', 'missing columns', 'not on roster', 'unsupported role'], true)
        ? $e->getMessage()
        : 'Could not remove student from Examination.';
}

header('Location: professor_college_students');
exit;
