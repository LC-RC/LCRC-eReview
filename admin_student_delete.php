<?php
/**
 * Archive / restore student accounts (soft lifecycle).
 * Never hard-deletes users or cascades exam/LMS history.
 *
 * POST actions:
 *   (default) archive  — status → archived (snapshot prior status + exam access)
 *   restore            — status → snapshot prior_status (never infers grants)
 */
require_once __DIR__ . '/auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/admin_account_window.php';
require_once __DIR__ . '/includes/commerce_access_gate.php';
require_once __DIR__ . '/includes/user_archive_lifecycle.php';

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

require_once __DIR__ . '/includes/admin_acl.php';

if ($action === 'restore') {
    mysqli_begin_transaction($conn);
    try {
        $target = user_archive_lock_user($conn, $targetUserId);
        if (!$target) {
            throw new UserArchiveException('User not found', 'not_found');
        }
        $result = user_archive_restore_locked($conn, $target, $adminId);
        if (empty($result['ok'])) {
            throw user_archive_exception_from_result($result, 'Restore failed.');
        }
        mysqli_commit($conn);
        $newStatus = (string) ($result['status'] ?? '');
        echo json_encode([
            'ok' => true,
            'action' => 'restore',
            'message' => 'Student restored to status "' . $newStatus . '". Grants and College Examination access were left unchanged. Existing records were preserved.',
            'user_id' => $targetUserId,
            'status' => $newStatus,
            'legacy_snapshot' => !empty($result['legacy']),
        ]);
        exit;
    } catch (UserArchiveException $e) {
        mysqli_rollback($conn);
        http_response_code(user_archive_http_status_for_error($e->errorType, $e->getMessage()));
        echo json_encode(user_archive_json_error_payload($e));
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ---- ARCHIVE ----
$reasonOtherShort = function_exists('mb_substr') ? mb_substr($deleteReasonOther, 0, 220) : substr($deleteReasonOther, 0, 220);
$finalReason = $deleteReason === 'other'
    ? ('Other: ' . $reasonOtherShort)
    : ($allowedReasons[$deleteReason] ?? $deleteReason);

mysqli_begin_transaction($conn);
try {
    $target = user_archive_lock_user($conn, $targetUserId);
    if (!$target) {
        throw new UserArchiveException('User not found', 'not_found');
    }
    $result = user_archive_apply_locked($conn, $target, $adminId, $finalReason);
    if (empty($result['ok'])) {
        throw user_archive_exception_from_result($result, 'Archive failed.');
    }
    if (!empty($result['already'])) {
        mysqli_commit($conn);
        echo json_encode(['ok' => true, 'message' => 'Student is already archived.', 'user_id' => $targetUserId]);
        exit;
    }

    $adminName = (string)($_SESSION['full_name'] ?? 'Administrator');
    $archivedName = (string)($target['full_name'] ?? '');
    $archivedEmail = (string)($target['email'] ?? '');
    $archivedSchool = ((string)($target['school'] ?? '') === 'Other' && !empty($target['school_other']))
        ? (string)$target['school_other']
        : (string)($target['school'] ?? '');
    $archivedReviewType = strtolower((string)($target['review_type'] ?? '')) === 'undergrad' ? 'Undergrad' : 'Reviewee';
    $logReason = 'ARCHIVED: ' . $finalReason;

    $insertLog = user_archive_deleted_users_log_ready($conn)
        ? mysqli_prepare(
            $conn,
            "INSERT INTO deleted_users_log
                (deleted_user_id, deleted_name, deleted_email, deleted_school, deleted_review_type, deleted_access_range, deleted_by_admin_id, deleted_by_admin_name, deletion_reason, deleted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )
        : false;
    if ($insertLog) {
        $accessRange = 'preserved';
        mysqli_stmt_bind_param(
            $insertLog,
            'isssssiss',
            $targetUserId,
            $archivedName,
            $archivedEmail,
            $archivedSchool,
            $archivedReviewType,
            $accessRange,
            $adminId,
            $adminName,
            $logReason
        );
        mysqli_stmt_execute($insertLog);
        mysqli_stmt_close($insertLog);
    }

    mysqli_commit($conn);
    echo json_encode([
        'ok' => true,
        'action' => 'archive',
        'message' => 'Student archived. Login to eReview and College Examination is blocked. The account row and historical records were preserved.',
        'user_id' => $targetUserId,
        'status' => 'archived',
        'snapshot_id' => (int) ($result['snapshot_id'] ?? 0),
    ]);
} catch (UserArchiveException $e) {
    mysqli_rollback($conn);
    http_response_code(user_archive_http_status_for_error($e->errorType, $e->getMessage()));
    echo json_encode(user_archive_json_error_payload($e));
} catch (Throwable $e) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to archive student',
        'detail' => $e->getMessage(),
    ]);
}
