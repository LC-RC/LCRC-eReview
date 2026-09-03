<?php
/**
 * Edit / extend login account window (users.access_start / access_end)
 * and keep access_grants.ends_at in sync (required for commerce login gate).
 *
 * Set vs Extend semantics (authoritative):
 * - SET: access_start = NOW(); access_end = NOW() + duration. Replaces the window.
 *   Active grants are aligned to the new access_end.
 * - EXTEND (future access_end): access_end = existing access_end + duration
 *   (NOT "now + duration"). Active grants are pushed by the same INTERVAL.
 * - EXTEND (missing/expired access_end): behaves like start-from-now:
 *   access_end = NOW() + duration (existing business rule for expired windows).
 * - CUSTOM: absolute start/end as posted.
 */
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/admin_account_window.php';
require_once __DIR__ . '/includes/commerce_access_gate.php';

$token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    $_SESSION['error'] = 'Invalid request. Please try again.';
    header('Location: admin_students');
    exit;
}

$userId = sanitizeInt($_POST['user_id'] ?? 0);
$mode = strtolower(trim((string) ($_POST['mode'] ?? 'extend')));
if (!in_array($mode, ['extend', 'set', 'custom'], true)) {
    $mode = 'extend';
}
$returnTo = admin_safe_return_to(
    (string) ($_POST['return_to'] ?? ''),
    'admin_student_view?id=' . max(0, $userId)
);

if ($userId <= 0) {
    $_SESSION['error'] = 'Invalid student.';
    header('Location: admin_students');
    exit;
}

$check = mysqli_prepare($conn, "SELECT user_id, role, status, access_start, access_end, access_months FROM users WHERE user_id=? AND role='student' LIMIT 1");
if (!$check) {
    $_SESSION['error'] = 'Could not load student.';
    header('Location: ' . $returnTo);
    exit;
}
mysqli_stmt_bind_param($check, 'i', $userId);
mysqli_stmt_execute($check);
$res = mysqli_stmt_get_result($check);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($check);
if (!$row) {
    $_SESSION['error'] = 'Student not found.';
    header('Location: admin_students');
    exit;
}

$status = strtolower((string) ($row['status'] ?? ''));
if ($status === 'archived') {
    $_SESSION['error'] = 'This student is archived. Restore the account before changing the access window.';
    header('Location: ' . $returnTo);
    exit;
}

/**
 * Reload access_end after update for flash message.
 */
$fetchEnd = static function (mysqli $conn, int $userId): string {
    $q = mysqli_prepare($conn, "SELECT access_end FROM users WHERE user_id = ? LIMIT 1");
    if (!$q) {
        return '';
    }
    mysqli_stmt_bind_param($q, 'i', $userId);
    mysqli_stmt_execute($q);
    $r = mysqli_stmt_get_result($q);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    mysqli_stmt_close($q);
    return trim((string) ($row['access_end'] ?? ''));
};

if ($mode === 'custom') {
    $startRaw = trim((string) ($_POST['access_start'] ?? ''));
    $endRaw = trim((string) ($_POST['access_end'] ?? ''));
    $startTs = $startRaw !== '' ? strtotime($startRaw) : false;
    $endTs = $endRaw !== '' ? strtotime($endRaw) : false;
    if ($startTs === false || $endTs === false) {
        $_SESSION['error'] = 'Enter valid start and end dates.';
        header('Location: ' . $returnTo);
        exit;
    }
    if ($endTs <= $startTs) {
        $_SESSION['error'] = 'Access end must be after access start.';
        header('Location: ' . $returnTo);
        exit;
    }
    // Do not allow empty overwrite of a previously valid window with nonsense — already validated.
    $startSql = date('Y-m-d H:i:s', $startTs);
    $endSql = date('Y-m-d H:i:s', $endTs);
    $monthsEquiv = max(1, (int) ceil(($endTs - $startTs) / (30 * 86400)));

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE users SET access_start=?, access_end=?, access_months=? WHERE user_id=? AND role='student' LIMIT 1"
    );
    if (!$stmt) {
        $_SESSION['error'] = 'Could not update account window.';
        header('Location: ' . $returnTo);
        exit;
    }
    mysqli_stmt_bind_param($stmt, 'ssii', $startSql, $endSql, $monthsEquiv, $userId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if ($ok) {
        admin_sync_access_grants_with_window($conn, $userId, 'custom', [
            'absolute_end' => $endSql,
        ]);
        $_SESSION['message'] = 'Account window updated. Access ends ' . $endSql . '.';
    } else {
        $_SESSION['error'] = 'Could not update account window.';
    }
    header('Location: ' . $returnTo);
    exit;
}

// Duration-based extend / set
$durationValue = sanitizeInt($_POST['duration_value'] ?? ($_POST['months'] ?? 0));
$durationUnit = admin_normalize_duration_unit((string) ($_POST['duration_unit'] ?? 'month'));
$durationError = admin_validate_duration($durationValue, $durationUnit);
if ($durationError !== null) {
    // Never overwrite an existing window with an invalid duration.
    $_SESSION['error'] = $durationError;
    header('Location: ' . $returnTo);
    exit;
}

$intervalUnit = admin_sql_interval_unit($durationUnit);
$monthsEquiv = admin_duration_to_months_equiv($durationValue, $durationUnit);

if ($mode === 'set') {
    $sql = "UPDATE users
            SET access_start = NOW(),
                access_end = DATE_ADD(NOW(), INTERVAL ? {$intervalUnit}),
                access_months = ?
            WHERE user_id = ? AND role = 'student'
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        $_SESSION['error'] = 'Could not set account window.';
        header('Location: ' . $returnTo);
        exit;
    }
    mysqli_stmt_bind_param($stmt, 'iii', $durationValue, $monthsEquiv, $userId);
} else {
    // extend
    $hasFutureEnd = false;
    if (!empty($row['access_end'])) {
        $endTs = strtotime((string) $row['access_end']);
        $hasFutureEnd = ($endTs !== false && $endTs > time());
    }
    if ($hasFutureEnd) {
        $sql = "UPDATE users
                SET access_end = DATE_ADD(access_end, INTERVAL ? {$intervalUnit}),
                    access_months = IFNULL(access_months, 0) + ?
                WHERE user_id = ? AND role = 'student'
                LIMIT 1";
    } else {
        $sql = "UPDATE users
                SET access_start = IFNULL(access_start, NOW()),
                    access_end = DATE_ADD(NOW(), INTERVAL ? {$intervalUnit}),
                    access_months = IFNULL(access_months, 0) + ?
                WHERE user_id = ? AND role = 'student'
                LIMIT 1";
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        $_SESSION['error'] = 'Could not extend account window.';
        header('Location: ' . $returnTo);
        exit;
    }
    mysqli_stmt_bind_param($stmt, 'iii', $durationValue, $monthsEquiv, $userId);
}

$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if ($ok) {
    $newEnd = $fetchEnd($conn, $userId);
    admin_sync_access_grants_with_window($conn, $userId, $mode, [
        'duration_value' => $durationValue,
        'interval_unit' => $intervalUnit,
        'absolute_end' => $newEnd,
    ]);
    $unitLabel = admin_duration_unit_label($durationUnit, $durationValue);
    $endNote = $newEnd !== '' ? (' New access end: ' . $newEnd . '.') : '';
    $_SESSION['message'] = $mode === 'set'
        ? ("Account window set to {$durationValue} {$unitLabel} from now." . $endNote)
        : ("Account window extended by {$durationValue} {$unitLabel}." . $endNote);
} else {
    $_SESSION['error'] = 'Could not update account window. Existing expiration was left unchanged.';
}

header('Location: ' . $returnTo);
exit;
