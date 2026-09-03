<?php
/**
 * Archive / restore student accounts (soft lifecycle).
 * Never hard-deletes users or cascades exam/LMS history.
 *
 * POST actions:
 *   (default) archive  — status → archived
 *   restore            — status → approved (or pending if no active grant)
 */
require_once __DIR__ . '/auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/admin_account_window.php';
require_once __DIR__ . '/includes/commerce_access_gate.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!verifyCSRFToken($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$adminId = getCurrentUserId() ?? 0;
$targetUserId = sanitizeInt($_POST['user_id'] ?? 0);
$adminPassword = (string)($_POST['admin_password'] ?? '');
$action = strtolower(trim((string) ($_POST['action'] ?? 'archive')));
if (!in_array($action, ['archive', 'restore'], true)) {
    // Back-compat: older UI posted without action (was hard delete).
    $action = 'archive';
}
$deleteReason = trim((string)($_POST['delete_reason'] ?? ($_POST['archive_reason'] ?? '')));
$deleteReasonOther = trim((string)($_POST['delete_reason_other'] ?? ($_POST['archive_reason_other'] ?? '')));

if ($adminId <= 0 || $targetUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}
if ($adminId === $targetUserId) {
    echo json_encode(['ok' => false, 'error' => 'You cannot archive your own account.']);
    exit;
}
if (trim($adminPassword) === '') {
    echo json_encode(['ok' => false, 'error' => 'Admin password is required.']);
    exit;
}

$allowedReasons = [
    'duplicate' => 'Duplicate account',
    'fraud' => 'Fraud or invalid registration',
    'request' => 'Requested by user',
    'inactive' => 'Inactive or abandoned account',
    'graduated' => 'Completed / graduated',
    'other' => 'Other',
];

if ($action === 'archive') {
    if ($deleteReason === '') {
        echo json_encode(['ok' => false, 'error' => 'Please select a reason.']);
        exit;
    }
    if ($deleteReason === 'other' && $deleteReasonOther === '') {
        echo json_encode(['ok' => false, 'error' => 'Please provide a specific reason.']);
        exit;
    }
    if (!isset($allowedReasons[$deleteReason])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid reason.']);
        exit;
    }
}

// Verify current admin password
$adminStmt = mysqli_prepare($conn, "SELECT user_id, password, role FROM users WHERE user_id = ? LIMIT 1");
if (!$adminStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to verify admin credentials.']);
    exit;
}
mysqli_stmt_bind_param($adminStmt, 'i', $adminId);
mysqli_stmt_execute($adminStmt);
$adminRes = mysqli_stmt_get_result($adminStmt);
$adminRow = $adminRes ? mysqli_fetch_assoc($adminRes) : null;
mysqli_stmt_close($adminStmt);

if (!$adminRow || (string)($adminRow['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$hashed = (string)($adminRow['password'] ?? '');
$passOk = false;
if ($hashed !== '') {
    $passOk = password_verify($adminPassword, $hashed) || hash_equals($hashed, $adminPassword);
}
if (!$passOk) {
    echo json_encode(['ok' => false, 'error' => 'Incorrect password', 'code' => 'INVALID_PASSWORD']);
    exit;
}

admin_ensure_user_status_archived($conn);

$targetStmt = mysqli_prepare(
    $conn,
    "SELECT user_id, full_name, email, role, status, review_type, school, school_other, access_start, access_end, college_examination_access
     FROM users WHERE user_id = ? LIMIT 1"
);
if (!$targetStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to fetch user']);
    exit;
}
mysqli_stmt_bind_param($targetStmt, 'i', $targetUserId);
mysqli_stmt_execute($targetStmt);
$targetRes = mysqli_stmt_get_result($targetStmt);
$target = $targetRes ? mysqli_fetch_assoc($targetRes) : null;
mysqli_stmt_close($targetStmt);

if (!$target) {
    echo json_encode(['ok' => false, 'error' => 'User not found']);
    exit;
}
if ((string)($target['role'] ?? '') !== 'student') {
    echo json_encode(['ok' => false, 'error' => 'Only student accounts can be archived here.']);
    exit;
}

$currentStatus = strtolower((string) ($target['status'] ?? ''));
require_once __DIR__ . '/includes/admin_acl.php';

if ($action === 'restore') {
    if ($currentStatus !== 'archived') {
        echo json_encode(['ok' => false, 'error' => 'Only archived students can be restored.']);
        exit;
    }
    $hasGrant = function_exists('commerce_student_has_active_access')
        && commerce_student_has_active_access($conn, $targetUserId);
    $newStatus = $hasGrant ? 'approved' : 'pending';
    $upd = mysqli_prepare(
        $conn,
        "UPDATE users SET status = ? WHERE user_id = ? AND role = 'student' AND status = 'archived' LIMIT 1"
    );
    if (!$upd) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not restore student.']);
        exit;
    }
    mysqli_stmt_bind_param($upd, 'si', $newStatus, $targetUserId);
    $ok = mysqli_stmt_execute($upd);
    $affected = (int) mysqli_stmt_affected_rows($upd);
    mysqli_stmt_close($upd);
    if (!$ok || $affected < 1) {
        echo json_encode(['ok' => false, 'error' => 'Restore failed.']);
        exit;
    }
    users_activity_log($conn, 'student_restored', [
        'email' => (string) ($target['email'] ?? ''),
        'name' => (string) ($target['full_name'] ?? ''),
        'new_status' => $newStatus,
    ], $adminId, null, 'admin', $targetUserId);

    echo json_encode([
        'ok' => true,
        'action' => 'restore',
        'message' => $hasGrant
            ? 'Student restored to Active. Existing records were preserved.'
            : 'Student restored as Pending (no active access grant). Existing records were preserved.',
        'user_id' => $targetUserId,
        'status' => $newStatus,
    ]);
    exit;
}

// ---- ARCHIVE ----
if ($currentStatus === 'archived') {
    echo json_encode(['ok' => true, 'message' => 'Student is already archived.', 'user_id' => $targetUserId]);
    exit;
}

$reasonOtherShort = function_exists('mb_substr') ? mb_substr($deleteReasonOther, 0, 220) : substr($deleteReasonOther, 0, 220);
$finalReason = $deleteReason === 'other'
    ? ('Other: ' . $reasonOtherShort)
    : ($allowedReasons[$deleteReason] ?? $deleteReason);

mysqli_begin_transaction($conn);
try {
    // Soft-archive: keep the user row and all related history.
    $hasCollegeAccessCol = false;
    if (function_exists('ereview_schema_column_exists')) {
        require_once __DIR__ . '/includes/schema_introspection.php';
        $hasCollegeAccessCol = ereview_schema_column_exists($conn, 'users', 'college_examination_access');
    } else {
        $colRes = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'college_examination_access'");
        $hasCollegeAccessCol = $colRes && mysqli_fetch_assoc($colRes);
    }
    if ($hasCollegeAccessCol) {
        $upd = mysqli_prepare(
            $conn,
            "UPDATE users
             SET status = 'archived',
                 college_examination_access = IF(college_examination_access = 'active', 'suspended', college_examination_access)
             WHERE user_id = ? AND role = 'student'
             LIMIT 1"
        );
    } else {
        $upd = mysqli_prepare(
            $conn,
            "UPDATE users
             SET status = 'archived'
             WHERE user_id = ? AND role = 'student'
             LIMIT 1"
        );
    }
    if (!$upd) {
        throw new Exception('Failed to prepare archive update.');
    }
    mysqli_stmt_bind_param($upd, 'i', $targetUserId);
    if (!mysqli_stmt_execute($upd)) {
        $err = mysqli_stmt_error($upd);
        mysqli_stmt_close($upd);
        throw new Exception($err ?: 'Archive failed.');
    }
    $affected = (int) mysqli_stmt_affected_rows($upd);
    mysqli_stmt_close($upd);
    if ($affected < 1) {
        throw new Exception('Archive failed (no rows updated).');
    }

    // Optional audit snapshot (not a hard-delete log). Keep table for historical hard deletes.
    $createSql = "CREATE TABLE IF NOT EXISTS deleted_users_log (
        log_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        deleted_user_id INT NOT NULL,
        deleted_name VARCHAR(180) NOT NULL,
        deleted_email VARCHAR(180) NOT NULL,
        deleted_school VARCHAR(180) NULL,
        deleted_review_type VARCHAR(80) NULL,
        deleted_access_range VARCHAR(80) NULL,
        deleted_by_admin_id INT NOT NULL,
        deleted_by_admin_name VARCHAR(180) NOT NULL,
        deletion_reason VARCHAR(255) NULL,
        deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_deleted_users_deleted_at (deleted_at),
        INDEX idx_deleted_users_admin (deleted_by_admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    @mysqli_query($conn, $createSql);

    $adminName = (string)($_SESSION['full_name'] ?? 'Administrator');
    $archivedName = (string)($target['full_name'] ?? '');
    $archivedEmail = (string)($target['email'] ?? '');
    $archivedSchool = ((string)($target['school'] ?? '') === 'Other' && !empty($target['school_other']))
        ? (string)$target['school_other']
        : (string)($target['school'] ?? '');
    $archivedReviewType = strtolower((string)($target['review_type'] ?? '')) === 'undergrad' ? 'Undergrad' : 'Reviewee';
    $start = !empty($target['access_start']) ? date('F j, Y', strtotime((string)$target['access_start'])) : '?';
    $end = !empty($target['access_end']) ? date('F j, Y', strtotime((string)$target['access_end'])) : '?';
    $archivedAccessRange = (!empty($target['access_start']) || !empty($target['access_end'])) ? ($start . ' - ' . $end) : 'No access set';
    $logReason = 'ARCHIVED: ' . $finalReason;

    $insertLog = mysqli_prepare(
        $conn,
        "INSERT INTO deleted_users_log
            (deleted_user_id, deleted_name, deleted_email, deleted_school, deleted_review_type, deleted_access_range, deleted_by_admin_id, deleted_by_admin_name, deletion_reason, deleted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    if ($insertLog) {
        mysqli_stmt_bind_param(
            $insertLog,
            'isssssiss',
            $targetUserId,
            $archivedName,
            $archivedEmail,
            $archivedSchool,
            $archivedReviewType,
            $archivedAccessRange,
            $adminId,
            $adminName,
            $logReason
        );
        mysqli_stmt_execute($insertLog);
        mysqli_stmt_close($insertLog);
    }

    mysqli_commit($conn);
    users_activity_log($conn, 'student_archived', [
        'reason' => $finalReason,
        'email' => (string) ($target['email'] ?? ''),
        'name' => (string) ($target['full_name'] ?? ''),
    ], $adminId, null, 'admin', $targetUserId);

    echo json_encode([
        'ok' => true,
        'action' => 'archive',
        'message' => 'Student archived. The account was removed from Active Students; all records were preserved.',
        'user_id' => $targetUserId,
        'status' => 'archived',
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to archive student',
        'detail' => $e->getMessage(),
    ]);
}
