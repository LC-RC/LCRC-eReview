<?php
declare(strict_types=1);

require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/commerce_access_gate.php';
require_once __DIR__ . '/includes/commerce_admin_manual_grant.php';

header('Content-Type: application/json; charset=utf-8');

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$mutating = in_array($action, ['save_permissions', 'save_bulk_permissions', 'create_student', 'update_student'], true);
if ($mutating) {
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
}

function sca_api_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

sca_ensure_schema($conn);

if ($action === 'catalog') {
    sca_api_json(['ok' => true, 'catalog' => sca_admin_content_catalog($conn)]);
}

if ($action === 'student') {
    $userId = (int) ($_GET['user_id'] ?? 0);
    if ($userId <= 0) {
        sca_api_json(['ok' => false, 'error' => 'Invalid user.'], 400);
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT user_id, full_name, email, school, status, access_start, access_end, access_months, created_at
         FROM users WHERE user_id = ? AND role = 'student' LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $user = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$user) {
        sca_api_json(['ok' => false, 'error' => 'Student not found.'], 404);
    }
    sca_api_json([
        'ok' => true,
        'user' => $user,
        'permissions' => sca_permissions_for_api($conn, $userId),
    ]);
}

if ($action === 'search') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $like = '%' . $q . '%';
    $stmt = mysqli_prepare(
        $conn,
        "SELECT user_id, full_name, email, status, access_end
         FROM users WHERE role = 'student' AND (full_name LIKE ? OR email LIKE ?)
         ORDER BY full_name ASC"
    );
    mysqli_stmt_bind_param($stmt, 'ss', $like, $like);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);

    sca_api_json(['ok' => true, 'students' => $rows, 'total' => count($rows)]);
}

if ($action === 'save_permissions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $raw = $_POST['permissions'] ?? '[]';
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $permissions = is_array($decoded) ? $decoded : [];
    } else {
        $permissions = is_array($raw) ? $raw : [];
    }
    if ($userId <= 0) {
        sca_api_json(['ok' => false, 'error' => 'Invalid user.'], 400);
    }
    $chk = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id = ? AND role = 'student' LIMIT 1");
    mysqli_stmt_bind_param($chk, 'i', $userId);
    mysqli_stmt_execute($chk);
    $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);
    if (!$exists) {
        sca_api_json(['ok' => false, 'error' => 'Student not found.'], 404);
    }
    $adminId = getCurrentUserId();
    if (!sca_save_user_permissions_preserving_commerce($conn, $userId, $permissions, $adminId)) {
        sca_api_json(['ok' => false, 'error' => 'Failed to save permissions.'], 500);
    }
    sca_api_json(['ok' => true, 'permissions' => sca_permissions_for_api($conn, $userId)]);
}

if ($action === 'save_bulk_permissions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawIds = $_POST['user_ids'] ?? '[]';
    if (is_string($rawIds)) {
        $decodedIds = json_decode($rawIds, true);
        $userIds = is_array($decodedIds) ? $decodedIds : [];
    } else {
        $userIds = is_array($rawIds) ? $rawIds : [];
    }
    $raw = $_POST['permissions'] ?? '[]';
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $permissions = is_array($decoded) ? $decoded : [];
    } else {
        $permissions = is_array($raw) ? $raw : [];
    }

    $normalizedIds = [];
    foreach ($userIds as $uid) {
        $id = (int) $uid;
        if ($id > 0) {
            $normalizedIds[$id] = $id;
        }
    }
    $normalizedIds = array_values($normalizedIds);

    if ($normalizedIds === []) {
        sca_api_json(['ok' => false, 'error' => 'Select at least one student.'], 400);
    }

    $adminId = getCurrentUserId();
    $updated = 0;
    $failed = [];
    foreach ($normalizedIds as $userId) {
        $chk = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id = ? AND role = 'student' LIMIT 1");
        mysqli_stmt_bind_param($chk, 'i', $userId);
        mysqli_stmt_execute($chk);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        mysqli_stmt_close($chk);
        if (!$exists) {
            $failed[] = $userId;
            continue;
        }
        if (sca_save_user_permissions_preserving_commerce($conn, $userId, $permissions, $adminId)) {
            $updated++;
        } else {
            $failed[] = $userId;
        }
    }

    if ($updated === 0) {
        sca_api_json(['ok' => false, 'error' => 'Failed to update any student.'], 500);
    }

    sca_api_json([
        'ok' => true,
        'updated' => $updated,
        'failed' => $failed,
        'total' => count($normalizedIds),
    ]);
}

if ($action === 'create_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    // Use dedicated field name so browser/profile handlers never confuse this with admin login password.
    $password = (string) ($_POST['student_password'] ?? $_POST['password'] ?? '');
    $school = trim((string) ($_POST['school'] ?? 'Manual enrollment'));
    $months = (int) ($_POST['months'] ?? 0);
    $status = (string) ($_POST['status'] ?? 'approved');
    if ($fullName === '' || $email === '' || $password === '') {
        sca_api_json(['ok' => false, 'error' => 'Name, email, and password are required.'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sca_api_json(['ok' => false, 'error' => 'Invalid email address.'], 422);
    }
    $adminId = getCurrentUserId();
    $sessionEmail = trim((string) ($_SESSION['email'] ?? ''));
    if ($sessionEmail !== '' && strcasecmp($email, $sessionEmail) === 0) {
        sca_api_json(['ok' => false, 'error' => 'Use a student email - not your admin login email.'], 422);
    }
    if (!in_array($status, ['approved', 'pending'], true)) {
        $status = 'approved';
    }
    $dup = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE email = ? LIMIT 1');
    mysqli_stmt_bind_param($dup, 's', $email);
    mysqli_stmt_execute($dup);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($dup))) {
        mysqli_stmt_close($dup);
        sca_api_json(['ok' => false, 'error' => 'Email already registered.'], 409);
    }
    mysqli_stmt_close($dup);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $reviewType = 'reviewee';
    $section = '';
    $accessMonths = $months > 0 ? $months : null;
    if ($status === 'approved' && $months > 0) {
        $ins = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, review_type, school, section, email, password, role, status, access_start, access_end, access_months)
             VALUES (?, ?, ?, ?, ?, ?, 'student', 'approved', NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH), ?)"
        );
        mysqli_stmt_bind_param($ins, 'ssssssii', $fullName, $reviewType, $school, $section, $email, $hash, $months, $months);
    } else {
        $ins = mysqli_prepare(
            $conn,
            "INSERT INTO users (full_name, review_type, school, section, email, password, role, status)
             VALUES (?, ?, ?, ?, ?, ?, 'student', ?)"
        );
        mysqli_stmt_bind_param($ins, 'sssssss', $fullName, $reviewType, $school, $section, $email, $hash, $status);
    }
    if (!$ins || !mysqli_stmt_execute($ins)) {
        sca_api_json(['ok' => false, 'error' => 'Could not create student: ' . mysqli_error($conn)], 500);
    }
    $newId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    $grantFull = isset($_POST['grant_full_lms']) && in_array((string) $_POST['grant_full_lms'], ['1', 'true', 'on', 'yes'], true);
    $rawPerms = $_POST['permissions'] ?? '[]';
    if (is_string($rawPerms)) {
        $decoded = json_decode($rawPerms, true);
        $permissions = is_array($decoded) ? $decoded : [];
    } else {
        $permissions = is_array($rawPerms) ? $rawPerms : [];
    }
    if ($grantFull) {
        sca_grant_full_lms($conn, $newId, $adminId);
    } elseif ($permissions !== []) {
        sca_save_user_permissions($conn, $newId, $permissions, $adminId);
    }

    // Active account requires an access_grants row (SOT).
    if ($status === 'approved') {
        $grantMonths = $months > 0 ? $months : 6;
        $g = commerce_admin_grant_manual_access($conn, $newId, (int) $adminId, [
            'months' => $grantMonths,
            'activate_login' => true,
            'close_open_payment' => false,
            'label' => 'Admin-created student access',
        ]);
        if (empty($g['ok'])) {
            mysqli_query($conn, "UPDATE users SET status='pending', access_start=NULL, access_end=NULL, access_months=NULL WHERE user_id=" . (int) $newId . " LIMIT 1");
            sca_api_json([
                'ok' => false,
                'error' => 'Student created as pending - could not create access grant (' . (string) ($g['error'] ?? 'grant_failed') . ').',
                'user_id' => $newId,
            ], 500);
        }
    }

    sca_api_json(['ok' => true, 'user_id' => $newId]);
}

if ($action === 'update_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        sca_api_json(['ok' => false, 'error' => 'Invalid user.'], 400);
    }
    $adminId = getCurrentUserId();
    if ($adminId !== null && $userId === $adminId) {
        sca_api_json(['ok' => false, 'error' => 'You cannot change your own admin password here. Use My Profile instead.'], 403);
    }
    $roleChk = mysqli_prepare($conn, "SELECT user_id, role FROM users WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($roleChk, 'i', $userId);
    mysqli_stmt_execute($roleChk);
    $roleRow = mysqli_fetch_assoc(mysqli_stmt_get_result($roleChk));
    mysqli_stmt_close($roleChk);
    if (!$roleRow || ($roleRow['role'] ?? '') !== 'student') {
        sca_api_json(['ok' => false, 'error' => 'Student not found.'], 404);
    }
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $school = trim((string) ($_POST['school'] ?? ''));
    $status = (string) ($_POST['status'] ?? 'approved');
    if ($fullName === '' || $email === '') {
        sca_api_json(['ok' => false, 'error' => 'Name and email are required.'], 422);
    }
    if (!in_array($status, ['approved', 'pending', 'rejected'], true)) {
        sca_api_json(['ok' => false, 'error' => 'Invalid status.'], 422);
    }
    $sessionEmail = trim((string) ($_SESSION['email'] ?? ''));
    if ($sessionEmail !== '' && strcasecmp($email, $sessionEmail) === 0) {
        sca_api_json(['ok' => false, 'error' => 'Student email cannot be the same as your admin login email.'], 422);
    }
    // Dedicated field - never reuse generic "password" for optional student reset.
    $password = (string) ($_POST['new_password'] ?? $_POST['password'] ?? '');
    $months = (int) ($_POST['extend_months'] ?? $_POST['months'] ?? 0);

    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE users SET full_name = ?, email = ?, school = ?, status = ?, password = ? WHERE user_id = ? AND role = ?'
        );
        $role = 'student';
        mysqli_stmt_bind_param($stmt, 'sssssis', $fullName, $email, $school, $status, $hash, $userId, $role);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE users SET full_name = ?, email = ?, school = ?, status = ? WHERE user_id = ? AND role = ?'
        );
        $role = 'student';
        mysqli_stmt_bind_param($stmt, 'ssssis', $fullName, $email, $school, $status, $userId, $role);
    }
    if ($status === 'approved' && !commerce_student_has_active_access($conn, $userId)) {
        $grantMonths = $months > 0 ? $months : 6;
        $g = commerce_admin_grant_manual_access($conn, $userId, (int) $adminId, [
            'months' => $grantMonths,
            'activate_login' => true,
            'close_open_payment' => false,
            'label' => 'Admin update - access grant',
        ]);
        if (empty($g['ok'])) {
            if ($stmt) {
                mysqli_stmt_close($stmt);
            }
            sca_api_json([
                'ok' => false,
                'error' => 'Cannot set Active without access grant. Grant Access failed: ' . (string) ($g['error'] ?? 'unknown'),
            ], 422);
        }
    }

    if (!$stmt || !mysqli_stmt_execute($stmt)) {
        if ($stmt) {
            mysqli_stmt_close($stmt);
        }
        sca_api_json(['ok' => false, 'error' => 'Update failed.'], 500);
    }
    mysqli_stmt_close($stmt);

    if ($months > 0 && $status === 'approved' && commerce_student_has_active_access($conn, $userId)) {
        $ext = mysqli_prepare(
            $conn,
            "UPDATE users SET access_start = IFNULL(access_start, NOW()),
             access_end = DATE_ADD(IF(access_end IS NOT NULL AND access_end > NOW(), access_end, NOW()), INTERVAL ? MONTH),
             access_months = IFNULL(access_months, 0) + ?
             WHERE user_id = ? AND role = 'student'"
        );
        mysqli_stmt_bind_param($ext, 'iii', $months, $months, $userId);
        mysqli_stmt_execute($ext);
        mysqli_stmt_close($ext);
    }

    if ($status !== 'approved' && $status !== 'rejected') {
        // Keep pending consistent if admin clears approval without grants.
        commerce_student_demote_if_no_active_grant($conn, $userId);
    }

    sca_api_json(['ok' => true]);
}

sca_api_json(['ok' => false, 'error' => 'Unknown action.'], 400);
