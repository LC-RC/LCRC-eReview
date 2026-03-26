<?php
require_once __DIR__ . '/auth.php';
requireRole('admin');

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
$deleteReason = trim((string)($_POST['delete_reason'] ?? ''));
$deleteReasonOther = trim((string)($_POST['delete_reason_other'] ?? ''));

if ($adminId <= 0 || $targetUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}
if ($adminId === $targetUserId) {
    echo json_encode(['ok' => false, 'error' => 'You cannot delete your own account.']);
    exit;
}
if (trim($adminPassword) === '') {
    echo json_encode(['ok' => false, 'error' => 'Admin password is required.']);
    exit;
}
if ($deleteReason === '') {
    echo json_encode(['ok' => false, 'error' => 'Please select a deletion reason.']);
    exit;
}
if ($deleteReason === 'other' && $deleteReasonOther === '') {
    echo json_encode(['ok' => false, 'error' => 'Please provide a specific reason.']);
    exit;
}
$allowedReasons = [
    'duplicate' => 'Duplicate account',
    'fraud' => 'Fraud or invalid registration',
    'request' => 'Requested by user',
    'inactive' => 'Inactive or abandoned account',
    'other' => 'Other',
];
if (!isset($allowedReasons[$deleteReason])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid deletion reason.']);
    exit;
}
$reasonOtherShort = function_exists('mb_substr') ? mb_substr($deleteReasonOther, 0, 220) : substr($deleteReasonOther, 0, 220);
$finalReason = $deleteReason === 'other'
    ? ('Other: ' . $reasonOtherShort)
    : $allowedReasons[$deleteReason];

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

// Target user must be a student account.
$targetStmt = mysqli_prepare($conn, "SELECT user_id, full_name, email, role, review_type, school, school_other, access_start, access_end FROM users WHERE user_id = ? LIMIT 1");
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
    echo json_encode(['ok' => false, 'error' => 'Only student accounts can be deleted here.']);
    exit;
}

// Ensure audit table exists.
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

// Add optional audit columns only when missing (prevents duplicate-column failures).
$columnExists = function(mysqli $conn, string $table, string $column): bool {
    $safeTable = mysqli_real_escape_string($conn, $table);
    $safeColumn = mysqli_real_escape_string($conn, $column);
    $res = @mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $res && mysqli_fetch_assoc($res) ? true : false;
};
if (!$columnExists($conn, 'deleted_users_log', 'deleted_school')) {
    @mysqli_query($conn, "ALTER TABLE deleted_users_log ADD COLUMN deleted_school VARCHAR(180) NULL AFTER deleted_email");
}
if (!$columnExists($conn, 'deleted_users_log', 'deleted_review_type')) {
    @mysqli_query($conn, "ALTER TABLE deleted_users_log ADD COLUMN deleted_review_type VARCHAR(80) NULL AFTER deleted_school");
}
if (!$columnExists($conn, 'deleted_users_log', 'deleted_access_range')) {
    @mysqli_query($conn, "ALTER TABLE deleted_users_log ADD COLUMN deleted_access_range VARCHAR(80) NULL AFTER deleted_review_type");
}
if (!$columnExists($conn, 'deleted_users_log', 'deletion_reason')) {
    @mysqli_query($conn, "ALTER TABLE deleted_users_log ADD COLUMN deletion_reason VARCHAR(255) NULL AFTER deleted_by_admin_name");
}

mysqli_begin_transaction($conn);
try {
    $adminName = (string)($_SESSION['full_name'] ?? 'Administrator');
    $insertLog = mysqli_prepare(
        $conn,
        "INSERT INTO deleted_users_log
            (deleted_user_id, deleted_name, deleted_email, deleted_school, deleted_review_type, deleted_access_range, deleted_by_admin_id, deleted_by_admin_name, deletion_reason, deleted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    if (!$insertLog) {
        throw new Exception('Failed to prepare audit log insert.');
    }
    $deletedName = (string)($target['full_name'] ?? '');
    $deletedEmail = (string)($target['email'] ?? '');
    $deletedSchool = ((string)($target['school'] ?? '') === 'Other' && !empty($target['school_other']))
        ? (string)$target['school_other']
        : (string)($target['school'] ?? '');
    $deletedReviewType = strtolower((string)($target['review_type'] ?? '')) === 'undergrad' ? 'Undergrad' : 'Reviewee';
    $start = !empty($target['access_start']) ? date('F j, Y', strtotime((string)$target['access_start'])) : '?';
    $end = !empty($target['access_end']) ? date('F j, Y', strtotime((string)$target['access_end'])) : '?';
    $deletedAccessRange = (!empty($target['access_start']) || !empty($target['access_end'])) ? ($start . ' - ' . $end) : 'No access set';
    mysqli_stmt_bind_param(
        $insertLog,
        'isssssiss',
        $targetUserId,
        $deletedName,
        $deletedEmail,
        $deletedSchool,
        $deletedReviewType,
        $deletedAccessRange,
        $adminId,
        $adminName,
        $finalReason
    );
    if (!mysqli_stmt_execute($insertLog)) {
        mysqli_stmt_close($insertLog);
        throw new Exception('Failed to write audit log.');
    }
    mysqli_stmt_close($insertLog);

    $deleteStmt = mysqli_prepare($conn, "DELETE FROM users WHERE user_id = ? AND role = 'student' LIMIT 1");
    if (!$deleteStmt) {
        throw new Exception('Failed to prepare delete.');
    }
    mysqli_stmt_bind_param($deleteStmt, 'i', $targetUserId);
    if (!mysqli_stmt_execute($deleteStmt)) {
        $err = mysqli_stmt_error($deleteStmt);
        mysqli_stmt_close($deleteStmt);
        throw new Exception($err ?: 'Delete failed.');
    }
    $affected = mysqli_stmt_affected_rows($deleteStmt);
    mysqli_stmt_close($deleteStmt);
    if ($affected < 1) {
        throw new Exception('User delete failed.');
    }

    mysqli_commit($conn);
    echo json_encode([
        'ok' => true,
        'message' => 'User successfully deleted',
        'deleted_user_id' => $targetUserId,
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to delete user',
        'detail' => $e->getMessage(),
    ]);
}
