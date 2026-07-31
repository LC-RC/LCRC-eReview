<?php
/**
 * Edit / extend login account window (users.access_start / access_end).
 * Does not create commerce grants or change SCA.
 */
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/admin_account_window.php';

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
    $_SESSION[$ok ? 'message' : 'error'] = $ok
        ? 'Account window updated.'
        : 'Could not update account window.';
    header('Location: ' . $returnTo);
    exit;
}

// Duration-based extend / set
$durationValue = sanitizeInt($_POST['duration_value'] ?? ($_POST['months'] ?? 0));
$durationUnit = admin_normalize_duration_unit((string) ($_POST['duration_unit'] ?? 'month'));
if ($durationValue <= 0) {
    $_SESSION['error'] = 'Enter a valid duration (1 or more).';
    header('Location: ' . $returnTo);
    exit;
}
if ($durationUnit === 'day' && $durationValue > 3660) {
    $_SESSION['error'] = 'Day duration is too large (max 3660).';
    header('Location: ' . $returnTo);
    exit;
}
if ($durationUnit === 'month' && $durationValue > 120) {
    $_SESSION['error'] = 'Month duration is too large (max 120).';
    header('Location: ' . $returnTo);
    exit;
}
if ($durationUnit === 'year' && $durationValue > 10) {
    $_SESSION['error'] = 'Year duration is too large (max 10).';
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

$unitLabel = $durationUnit === 'day' ? 'day(s)' : ($durationUnit === 'year' ? 'year(s)' : 'month(s)');
$_SESSION[$ok ? 'message' : 'error'] = $ok
    ? ($mode === 'set'
        ? ("Account window set to {$durationValue} {$unitLabel} from now.")
        : ("Account window extended by {$durationValue} {$unitLabel}."))
    : 'Could not update account window.';

header('Location: ' . $returnTo);
exit;
