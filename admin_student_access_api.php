<?php
declare(strict_types=1);

require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/commerce_access_gate.php';
require_once __DIR__ . '/includes/commerce_admin_manual_grant.php';
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/platform_access.php';
require_once __DIR__ . '/includes/url_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$mutating = in_array($action, [
    'save_permissions',
    'save_bulk_permissions',
    'create_student',
    'update_student',
    'enable_college_examination',
    'bulk_enable_college_examination',
    'bulk_assign_section',
    'bulk_suspend_college_examination',
], true);
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
    $chk = mysqli_prepare($conn, "SELECT user_id, status FROM users WHERE user_id = ? AND role = 'student' LIMIT 1");
    mysqli_stmt_bind_param($chk, 'i', $userId);
    mysqli_stmt_execute($chk);
    $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);
    if (!$exists) {
        sca_api_json(['ok' => false, 'error' => 'Student not found.'], 404);
    }
    if (strtolower((string) ($exists['status'] ?? '')) === 'archived') {
        sca_api_json(['ok' => false, 'error' => 'This student is archived. Restore before changing content permissions.'], 422);
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
        $chk = mysqli_prepare($conn, "SELECT user_id, status FROM users WHERE user_id = ? AND role = 'student' LIMIT 1");
        mysqli_stmt_bind_param($chk, 'i', $userId);
        mysqli_stmt_execute($chk);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        mysqli_stmt_close($chk);
        if (!$exists) {
            $failed[] = $userId;
            continue;
        }
        if (strtolower((string) ($exists['status'] ?? '')) === 'archived') {
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
    $roleChk = mysqli_prepare($conn, "SELECT user_id, role, status FROM users WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($roleChk, 'i', $userId);
    mysqli_stmt_execute($roleChk);
    $roleRow = mysqli_fetch_assoc(mysqli_stmt_get_result($roleChk));
    mysqli_stmt_close($roleChk);
    if (!$roleRow || ($roleRow['role'] ?? '') !== 'student') {
        sca_api_json(['ok' => false, 'error' => 'Student not found.'], 404);
    }
    if (strtolower((string) ($roleRow['status'] ?? '')) === 'archived') {
        sca_api_json(['ok' => false, 'error' => 'This student is archived. Use Restore Student before editing access.'], 422);
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
        require_once __DIR__ . '/includes/admin_account_window.php';
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
        $endQ = mysqli_prepare($conn, 'SELECT access_end FROM users WHERE user_id = ? LIMIT 1');
        $endSql = '';
        if ($endQ) {
            mysqli_stmt_bind_param($endQ, 'i', $userId);
            mysqli_stmt_execute($endQ);
            $endRow = mysqli_fetch_assoc(mysqli_stmt_get_result($endQ));
            mysqli_stmt_close($endQ);
            $endSql = trim((string) ($endRow['access_end'] ?? ''));
        }
        if ($endSql !== '') {
            admin_sync_access_grants_with_window($conn, $userId, 'extend', [
                'duration_value' => $months,
                'interval_unit' => 'MONTH',
                'absolute_end' => $endSql,
            ]);
        }
    }

    if ($status !== 'approved' && $status !== 'rejected') {
        // Keep pending consistent if admin clears approval without grants.
        commerce_student_demote_if_no_active_grant($conn, $userId);
    }

    sca_api_json(['ok' => true]);
}

if ($action === 'enable_college_examination' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        sca_api_json(['ok' => false, 'error' => 'Invalid user.'], 400);
    }
    $adminId = (int) (getCurrentUserId() ?? 0);
    $chk = mysqli_prepare($conn, "SELECT user_id, role, status, college_examination_access FROM users WHERE user_id=? AND role='student' LIMIT 1");
    if (!$chk) {
        sca_api_json(['ok' => false, 'error' => 'Could not load student.'], 500);
    }
    mysqli_stmt_bind_param($chk, 'i', $userId);
    mysqli_stmt_execute($chk);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);
    if (!$row) {
        sca_api_json(['ok' => false, 'error' => 'Student not found or not an eReview student account.'], 404);
    }
    if (strtolower((string) ($row['status'] ?? '')) === 'archived') {
        sca_api_json(['ok' => false, 'error' => 'This student is archived. Restore before enabling College Examination.'], 422);
    }
    $currentAccess = ereview_user_college_examination_access_value($row);
    if ($currentAccess === 'active') {
        if (!empty($_POST['redirect'])) {
            $_SESSION['message'] = 'College Examination is already enabled for this student.';
            $returnTo = trim((string) ($_POST['return_to'] ?? ''));
            if ($returnTo !== '' && str_starts_with($returnTo, 'admin_students') && !preg_match('#https?:|//|\.\.#i', $returnTo)) {
                header('Location: ' . ereview_url($returnTo));
                exit;
            }
            header('Location: ' . ereview_url('admin_student_view') . '?id=' . $userId . '#college-examination');
            exit;
        }
        sca_api_json(['ok' => true, 'already_active' => true, 'user_id' => $userId]);
    }
    $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
    require_once __DIR__ . '/examination/includes/college_sections.php';
    $parsedSection = college_sections_parse_optional_post($conn, (string) ($_POST['section'] ?? '__none__'));
    if (empty($parsedSection['ok'])) {
        sca_api_json(['ok' => false, 'error' => (string) ($parsedSection['error'] ?? 'Invalid section.')], 422);
    }
    $reviewType = strtolower(trim((string) ($_POST['review_type'] ?? 'undergrad')));
    if (!in_array($reviewType, ['undergrad', 'reviewee'], true)) {
        $reviewType = 'undergrad';
    }
    if ($studentNumber !== '' && (strlen($studentNumber) > 32 || !preg_match('/^[A-Za-z0-9_-]+$/', $studentNumber))) {
        sca_api_json(['ok' => false, 'error' => 'Student number must be at most 32 characters and use only letters, digits, hyphen, or underscore.'], 422);
    }
    if ($studentNumber !== '') {
        $dupSn = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE student_number=? AND user_id<>? LIMIT 1');
        mysqli_stmt_bind_param($dupSn, 'si', $studentNumber, $userId);
        mysqli_stmt_execute($dupSn);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($dupSn))) {
            mysqli_stmt_close($dupSn);
            sca_api_json(['ok' => false, 'error' => 'That student number is already assigned.'], 409);
        }
        mysqli_stmt_close($dupSn);
    }
    $sectionVal = $parsedSection['section'] ?? null;
    $snVal = $studentNumber !== '' ? $studentNumber : null;
    $upd = mysqli_prepare(
        $conn,
        "UPDATE users SET college_examination_access='active', college_examination_enabled_at=NOW(), college_examination_enabled_by=?,
         review_type=?, section=?, student_number=COALESCE(?, student_number)
         WHERE user_id=? AND role='student' LIMIT 1"
    );
    if (!$upd) {
        sca_api_json(['ok' => false, 'error' => 'Could not enable College Examination.'], 500);
    }
    mysqli_stmt_bind_param($upd, 'isssi', $adminId, $reviewType, $sectionVal, $snVal, $userId);
    if (!mysqli_stmt_execute($upd)) {
        mysqli_stmt_close($upd);
        sca_api_json(['ok' => false, 'error' => 'Update failed.'], 500);
    }
    mysqli_stmt_close($upd);
    if (!empty($_POST['redirect'])) {
        $_SESSION['message'] = 'College Examination access enabled for this student.';
        $returnTo = trim((string) ($_POST['return_to'] ?? ''));
        if ($returnTo !== '' && str_starts_with($returnTo, 'admin_students') && !preg_match('#https?:|//|\.\.#i', $returnTo)) {
            header('Location: ' . ereview_url($returnTo));
            exit;
        }
        header('Location: ' . ereview_url('admin_student_view') . '?id=' . $userId . '#college-examination');
        exit;
    }
    sca_api_json(['ok' => true, 'user_id' => $userId]);
}

/**
 * Bulk enable College Examination on existing eReview students (UPDATE only, one transaction).
 * Does not INSERT users, touch access_grants, or modify student_content_permissions.
 */
if ($action === 'bulk_enable_college_examination' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ereview_platform_access_columns_ready($conn)) {
        sca_api_json(['ok' => false, 'error' => 'College Examination access columns are not available.'], 500);
    }

    $rawIds = $_POST['user_ids'] ?? [];
    if (is_string($rawIds)) {
        $decoded = json_decode($rawIds, true);
        if (is_array($decoded)) {
            $rawIds = $decoded;
        } else {
            $parts = preg_split('/\s*,\s*/', $rawIds);
            $rawIds = is_array($parts) ? $parts : [];
        }
    }
    if (!is_array($rawIds)) {
        sca_api_json(['ok' => false, 'error' => 'Select at least one student.'], 400);
    }

    $userIds = [];
    foreach ($rawIds as $id) {
        $uid = (int) $id;
        if ($uid > 0) {
            $userIds[$uid] = $uid;
        }
    }
    $userIds = array_values($userIds);
    if ($userIds === []) {
        sca_api_json(['ok' => false, 'error' => 'Select at least one student.'], 400);
    }
    if (count($userIds) > 500) {
        sca_api_json(['ok' => false, 'error' => 'You can enable at most 500 students at once.'], 422);
    }

    $sectionMode = strtolower(trim((string) ($_POST['section_mode'] ?? 'same')));
    if ($sectionMode !== 'same') {
        // Individual assignment reserved for a later iteration.
        sca_api_json(['ok' => false, 'error' => 'Only "same section for all selected students" is supported right now.'], 422);
    }

    $section = trim((string) ($_POST['section'] ?? ''));
    require_once __DIR__ . '/examination/includes/college_sections.php';
    $parsedSection = college_sections_parse_optional_post($conn, $section);
    if (empty($parsedSection['ok'])) {
        sca_api_json(['ok' => false, 'error' => (string) ($parsedSection['error'] ?? 'Invalid section.')], 422);
    }
    $sectionVal = $parsedSection['section'] ?? null;

    $reviewType = strtolower(trim((string) ($_POST['review_type'] ?? 'undergrad')));
    if (!in_array($reviewType, ['undergrad', 'reviewee'], true)) {
        sca_api_json(['ok' => false, 'error' => 'Review type must be undergrad or reviewee.'], 422);
    }

    $adminId = (int) (getCurrentUserId() ?? 0);
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));

    mysqli_begin_transaction($conn);
    try {
        $chkSql = "SELECT user_id, role, status, college_examination_access
                   FROM users
                   WHERE user_id IN ({$placeholders})
                   FOR UPDATE";
        $chk = mysqli_prepare($conn, $chkSql);
        if (!$chk) {
            throw new RuntimeException('Could not validate selected students.');
        }
        mysqli_stmt_bind_param($chk, $types, ...$userIds);
        mysqli_stmt_execute($chk);
        $res = mysqli_stmt_get_result($chk);
        $found = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $found[(int) $row['user_id']] = $row;
        }
        mysqli_stmt_close($chk);

        if (count($found) !== count($userIds)) {
            throw new InvalidArgumentException('One or more selected students were not found.');
        }

        foreach ($found as $uid => $row) {
            $role = (string) ($row['role'] ?? '');
            if ($role !== 'student') {
                throw new InvalidArgumentException('User #' . $uid . ' is not an eReview student account.');
            }
            if (function_exists('isStaffRole') && isStaffRole($role)) {
                throw new InvalidArgumentException('Staff accounts cannot be enabled for College Examination via this action.');
            }
            $status = strtolower((string) ($row['status'] ?? ''));
            if ($status === 'rejected' || $status === 'archived') {
                throw new InvalidArgumentException(ucfirst($status) . ' student #' . $uid . ' cannot be enabled.');
            }
        }

        if ($sectionVal === null) {
            $upd = mysqli_prepare(
                $conn,
                "UPDATE users
                 SET college_examination_access='active',
                     college_examination_enabled_at=COALESCE(college_examination_enabled_at, NOW()),
                     college_examination_enabled_by=COALESCE(college_examination_enabled_by, ?),
                     review_type=?
                 WHERE user_id IN ({$placeholders})
                   AND role='student'
                   AND status NOT IN ('rejected','archived')"
            );
            if (!$upd) {
                throw new RuntimeException('Could not prepare College Examination bulk update.');
            }
            $bindTypes = 'is' . $types;
            $bindValues = array_merge([$adminId, $reviewType], $userIds);
        } else {
            $upd = mysqli_prepare(
                $conn,
                "UPDATE users
                 SET college_examination_access='active',
                     college_examination_enabled_at=COALESCE(college_examination_enabled_at, NOW()),
                     college_examination_enabled_by=COALESCE(college_examination_enabled_by, ?),
                     review_type=?,
                     section=?
                 WHERE user_id IN ({$placeholders})
                   AND role='student'
                   AND status NOT IN ('rejected','archived')"
            );
            if (!$upd) {
                throw new RuntimeException('Could not prepare College Examination bulk update.');
            }
            $bindTypes = 'iss' . $types;
            $bindValues = array_merge([$adminId, $reviewType, $sectionVal], $userIds);
        }
        mysqli_stmt_bind_param($upd, $bindTypes, ...$bindValues);
        if (!mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            throw new RuntimeException('Bulk update failed.');
        }
        $affected = mysqli_stmt_affected_rows($upd);
        mysqli_stmt_close($upd);

        // Confirm every selected row is now active with the chosen section/review_type.
        $verify = mysqli_prepare(
            $conn,
            "SELECT user_id, college_examination_access, section, review_type, role
             FROM users WHERE user_id IN ({$placeholders})"
        );
        if (!$verify) {
            throw new RuntimeException('Could not verify bulk enable.');
        }
        mysqli_stmt_bind_param($verify, $types, ...$userIds);
        mysqli_stmt_execute($verify);
        $vres = mysqli_stmt_get_result($verify);
        $verified = 0;
        while ($vrow = mysqli_fetch_assoc($vres)) {
            if ((string) ($vrow['role'] ?? '') !== 'student') {
                throw new RuntimeException('Unexpected role change detected; rolling back.');
            }
            if (ereview_user_college_examination_access_value($vrow) !== 'active') {
                throw new RuntimeException('Not all selected students were enabled; rolling back.');
            }
            $actualSection = trim((string) ($vrow['section'] ?? ''));
            $expectedSection = $sectionVal === null ? '' : $sectionVal;
            if ($actualSection !== $expectedSection) {
                throw new RuntimeException('Section was not applied to all selected students; rolling back.');
            }
            if (strtolower(trim((string) ($vrow['review_type'] ?? ''))) !== $reviewType) {
                throw new RuntimeException('Review type was not applied to all selected students; rolling back.');
            }
            $verified++;
        }
        mysqli_stmt_close($verify);
        if ($verified !== count($userIds)) {
            throw new RuntimeException('Verification count mismatch; rolling back.');
        }

        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        sca_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        sca_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $count = count($userIds);
    $reviewLabel = $reviewType === 'reviewee' ? 'Reviewee' : 'Undergrad';
    $sectionDetail = $sectionVal === null ? 'No section assigned' : ('Section: ' . $sectionVal);
    sca_api_json([
        'ok' => true,
        'enabled_count' => $count,
        'affected_rows' => $affected ?? $count,
        'user_ids' => $userIds,
        'section' => $sectionVal,
        'review_type' => $reviewType,
        'message' => 'College Examination enabled for ' . $count . ' student' . ($count === 1 ? '' : 's') . '.',
        'detail' => $sectionDetail . ' · Review Type: ' . $reviewLabel,
    ]);
}

/**
 * Bulk assign or change section on eReview students (does not change college_examination_access).
 */
if ($action === 'bulk_assign_section' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/examination/includes/college_sections.php';

    $rawIds = $_POST['user_ids'] ?? [];
    if (is_string($rawIds)) {
        $decoded = json_decode($rawIds, true);
        $rawIds = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $rawIds);
    }
    if (!is_array($rawIds)) {
        sca_api_json(['ok' => false, 'error' => 'Select at least one student.'], 400);
    }
    $userIds = [];
    foreach ($rawIds as $id) {
        $uid = (int) $id;
        if ($uid > 0) {
            $userIds[$uid] = $uid;
        }
    }
    $userIds = array_values($userIds);
    if ($userIds === []) {
        sca_api_json(['ok' => false, 'error' => 'Select at least one student.'], 400);
    }
    if (count($userIds) > 500) {
        sca_api_json(['ok' => false, 'error' => 'You can update at most 500 students at once.'], 422);
    }

    $parsed = college_sections_parse_optional_post($conn, (string) ($_POST['section'] ?? ''));
    if (empty($parsed['ok'])) {
        sca_api_json(['ok' => false, 'error' => (string) ($parsed['error'] ?? 'Invalid section.')], 422);
    }
    $sectionVal = $parsed['section'] ?? null;

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));
    $hasCollegeAccessCol = ereview_platform_access_columns_ready($conn);

    mysqli_begin_transaction($conn);
    try {
        $chkSql = $hasCollegeAccessCol
            ? "SELECT user_id, role, college_examination_access FROM users WHERE user_id IN ({$placeholders}) FOR UPDATE"
            : "SELECT user_id, role FROM users WHERE user_id IN ({$placeholders}) FOR UPDATE";
        $chk = mysqli_prepare($conn, $chkSql);
        if (!$chk) {
            throw new RuntimeException('Could not validate selected students.');
        }
        mysqli_stmt_bind_param($chk, $types, ...$userIds);
        mysqli_stmt_execute($chk);
        $found = [];
        $res = mysqli_stmt_get_result($chk);
        while ($row = mysqli_fetch_assoc($res)) {
            $found[(int) $row['user_id']] = $row;
        }
        mysqli_stmt_close($chk);
        if (count($found) !== count($userIds)) {
            throw new InvalidArgumentException('One or more selected students were not found.');
        }
        foreach ($found as $uid => $row) {
            if ((string) ($row['role'] ?? '') !== 'student') {
                throw new InvalidArgumentException('User #' . $uid . ' is not an eReview student account.');
            }
            if ($hasCollegeAccessCol) {
                $access = strtolower(trim((string) ($row['college_examination_access'] ?? 'none')));
                if ($access !== 'active' && $access !== 'suspended') {
                    throw new InvalidArgumentException(
                        'Section can only be assigned to students with College Examination access (active or suspended). User #' . $uid . ' is not enabled.'
                    );
                }
            }
        }

        if ($sectionVal === null) {
            $upd = mysqli_prepare($conn, "UPDATE users SET section=NULL WHERE user_id IN ({$placeholders}) AND role='student'");
            if (!$upd) {
                throw new RuntimeException('Could not prepare section clear.');
            }
            mysqli_stmt_bind_param($upd, $types, ...$userIds);
        } else {
            $upd = mysqli_prepare($conn, "UPDATE users SET section=? WHERE user_id IN ({$placeholders}) AND role='student'");
            if (!$upd) {
                throw new RuntimeException('Could not prepare section assignment.');
            }
            $bindTypes = 's' . $types;
            mysqli_stmt_bind_param($upd, $bindTypes, $sectionVal, ...$userIds);
        }
        if (!mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            throw new RuntimeException('Section assignment failed.');
        }
        mysqli_stmt_close($upd);
        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        sca_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        sca_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $count = count($userIds);
    sca_api_json([
        'ok' => true,
        'updated_count' => $count,
        'section' => $sectionVal,
        'message' => $sectionVal === null
            ? ('Section cleared for ' . $count . ' student' . ($count === 1 ? '' : 's') . '.')
            : ('Section "' . $sectionVal . '" assigned to ' . $count . ' student' . ($count === 1 ? '' : 's') . '.'),
    ]);
}

/**
 * Suspend College Examination login access (eReview grants unchanged).
 */
if ($action === 'bulk_suspend_college_examination' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ereview_platform_access_columns_ready($conn)) {
        sca_api_json(['ok' => false, 'error' => 'College Examination access columns are not available.'], 500);
    }

    $rawIds = $_POST['user_ids'] ?? [];
    if (is_string($rawIds)) {
        $decoded = json_decode($rawIds, true);
        $rawIds = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $rawIds);
    }
    if (!is_array($rawIds)) {
        sca_api_json(['ok' => false, 'error' => 'Select at least one student.'], 400);
    }
    $userIds = [];
    foreach ($rawIds as $id) {
        $uid = (int) $id;
        if ($uid > 0) {
            $userIds[$uid] = $uid;
        }
    }
    $userIds = array_values($userIds);
    if ($userIds === []) {
        sca_api_json(['ok' => false, 'error' => 'Select at least one student.'], 400);
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));

    mysqli_begin_transaction($conn);
    try {
        $chk = mysqli_prepare($conn, "SELECT user_id, role, college_examination_access FROM users WHERE user_id IN ({$placeholders}) FOR UPDATE");
        if (!$chk) {
            throw new RuntimeException('Could not validate selected students.');
        }
        mysqli_stmt_bind_param($chk, $types, ...$userIds);
        mysqli_stmt_execute($chk);
        $found = [];
        $res = mysqli_stmt_get_result($chk);
        while ($row = mysqli_fetch_assoc($res)) {
            $found[(int) $row['user_id']] = $row;
        }
        mysqli_stmt_close($chk);
        if (count($found) !== count($userIds)) {
            throw new InvalidArgumentException('One or more selected students were not found.');
        }
        foreach ($found as $uid => $row) {
            if ((string) ($row['role'] ?? '') !== 'student') {
                throw new InvalidArgumentException('User #' . $uid . ' is not an eReview student account.');
            }
            if (ereview_user_college_examination_access_value($row) !== 'active') {
                throw new InvalidArgumentException('User #' . $uid . ' does not have active College Examination access.');
            }
        }

        $upd = mysqli_prepare(
            $conn,
            "UPDATE users SET college_examination_access='suspended'
             WHERE user_id IN ({$placeholders}) AND role='student'"
        );
        if (!$upd) {
            throw new RuntimeException('Could not prepare suspend update.');
        }
        mysqli_stmt_bind_param($upd, $types, ...$userIds);
        if (!mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            throw new RuntimeException('Suspend update failed.');
        }
        mysqli_stmt_close($upd);
        mysqli_commit($conn);
    } catch (InvalidArgumentException $e) {
        mysqli_rollback($conn);
        sca_api_json(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        sca_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $count = count($userIds);
    sca_api_json([
        'ok' => true,
        'suspended_count' => $count,
        'message' => 'College Examination suspended for ' . $count . ' student' . ($count === 1 ? '' : 's') . '. eReview access is unchanged.',
    ]);
}

sca_api_json(['ok' => false, 'error' => 'Unknown action.'], 400);
