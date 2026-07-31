<?php
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/smtp_sender.php';
require_once __DIR__ . '/includes/student_content_access.php';
require_once __DIR__ . '/includes/commerce_student_admin.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || !empty($_POST['ajax']);

function activate_user_respond(bool $isAjax, bool $ok, string $message, string $redirect, int $http = 200, array $extra = []): void
{
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($ok ? $http : ($http >= 400 ? $http : 400));
        echo json_encode(array_merge(['ok' => $ok, ($ok ? 'message' : 'error') => $message, 'redirect_url' => $redirect], $extra));
        exit;
    }
    if ($ok) {
        $_SESSION['message'] = $message;
    } else {
        $_SESSION['error'] = $message;
    }
    header('Location: ' . $redirect);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    activate_user_respond($isAjax, false, 'Invalid request. Please try again.', 'admin_students?tab=pending&q=&page=1', 403);
}

$months = (int)($_POST['months'] ?? 0);
$returnTo = trim((string)($_POST['return_to'] ?? 'admin_students?tab=enrolled&q=&page=1'));
if ($returnTo === '' || strpos($returnTo, '://') !== false || strpos($returnTo, '//') === 0 || strpos($returnTo, '/') === 0) {
    $returnTo = 'admin_students?tab=enrolled&q=&page=1';
}
$enrolledRedirect = 'admin_students?tab=enrolled&q=&page=1';

$userIds = [];
$rawIds = $_POST['user_ids'] ?? null;
if ($rawIds !== null && $rawIds !== '') {
    if (is_string($rawIds)) {
        $decoded = json_decode($rawIds, true);
        if (is_array($decoded)) {
            $rawIds = $decoded;
        } else {
            $rawIds = preg_split('/\s*,\s*/', $rawIds) ?: [];
        }
    }
    if (is_array($rawIds)) {
        foreach ($rawIds as $uid) {
            $id = (int)$uid;
            if ($id > 0) {
                $userIds[$id] = $id;
            }
        }
    }
}
$singleId = (int)($_POST['user_id'] ?? 0);
if ($singleId > 0) {
    $userIds[$singleId] = $singleId;
}
$userIds = array_values($userIds);

if ($userIds === [] || $months <= 0) {
    activate_user_respond($isAjax, false, 'Invalid user or months value.', 'admin_students?tab=pending&q=&page=1', 400);
}

$grantFullRaw = $_POST['grant_full_lms'] ?? '0';
$grantFull = in_array((string)$grantFullRaw, ['1', 'true', 'on', 'yes'], true);

$rawPerms = $_POST['permissions'] ?? '[]';
if (is_string($rawPerms)) {
    $decodedPerms = json_decode($rawPerms, true);
    $permissions = is_array($decodedPerms) ? $decodedPerms : [];
} else {
    $permissions = is_array($rawPerms) ? $rawPerms : [];
}
$normalizedPerms = sca_normalize_permission_payload($permissions);

// SCA picker is only required for legacy (non-commerce) enrollments.
// Commerce paid/FAR content access must come from fulfillment / FAR — never from this action.
$needsLegacySca = false;
$pathCheck = mysqli_prepare($conn, 'SELECT enrollment_path FROM users WHERE user_id = ? AND role = \'student\' LIMIT 1');
if ($pathCheck) {
    foreach ($userIds as $checkId) {
        mysqli_stmt_bind_param($pathCheck, 'i', $checkId);
        mysqli_stmt_execute($pathCheck);
        $pathRes = mysqli_stmt_get_result($pathCheck);
        $pathRow = $pathRes ? mysqli_fetch_assoc($pathRes) : null;
        $ep = (string) ($pathRow['enrollment_path'] ?? '');
        if (!commerce_admin_is_commerce_enrollment_path($ep)) {
            $needsLegacySca = true;
            break;
        }
    }
    mysqli_stmt_close($pathCheck);
}

if ($needsLegacySca && !$grantFull && $normalizedPerms === []) {
    activate_user_respond(
        $isAjax,
        false,
        'Select Full LMS access or at least one content item before approving a legacy student.',
        'admin_students?tab=pending&q=&page=1',
        422
    );
}

sca_ensure_schema($conn);
$adminId = (int)getCurrentUserId();

$mailConfig = null;
$mailConfigFile = __DIR__ . '/config/mail_config.php';
if (file_exists($mailConfigFile)) {
    $loaded = require $mailConfigFile;
    if (is_array($loaded) && function_exists('isMailConfigValid') && isMailConfigValid($loaded)) {
        $mailConfig = $loaded;
    }
}
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = rtrim($scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$dashboardUrl = $base . '/student_dashboard';
$loginUrl = $base . '/login';

$approved = 0;
$failed = [];
$emailSentCount = 0;
$lastStudentName = '';
$commerceActivated = 0;
$legacyActivated = 0;

foreach ($userIds as $userId) {
    $studentEmail = '';
    $studentName = '';
    $studentRole = '';
    $enrollmentPath = '';
    $studentStmt = mysqli_prepare(
        $conn,
        "SELECT email, full_name, role, enrollment_path FROM users WHERE user_id=? LIMIT 1"
    );
    if (!$studentStmt) {
        $failed[] = $userId;
        continue;
    }
    mysqli_stmt_bind_param($studentStmt, 'i', $userId);
    mysqli_stmt_execute($studentStmt);
    $studentRes = mysqli_stmt_get_result($studentStmt);
    $studentRow = $studentRes ? mysqli_fetch_assoc($studentRes) : null;
    mysqli_stmt_close($studentStmt);
    if ($studentRow) {
        $studentEmail = trim((string)($studentRow['email'] ?? ''));
        $studentName = trim((string)($studentRow['full_name'] ?? 'Student'));
        $studentRole = trim((string)($studentRow['role'] ?? ''));
        $enrollmentPath = (string)($studentRow['enrollment_path'] ?? '');
    }
    if ($studentRole !== 'student' || $studentEmail === '') {
        $failed[] = $userId;
        continue;
    }

    $isCommerce = commerce_admin_is_commerce_enrollment_path($enrollmentPath);

    $sql = "UPDATE users SET status='approved', access_start=NOW(), access_end=DATE_ADD(NOW(), INTERVAL ? MONTH), access_months=? WHERE user_id=? AND role='student'";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        $failed[] = $userId;
        continue;
    }
    mysqli_stmt_bind_param($stmt, 'iii', $months, $months, $userId);
    $okUpdate = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    if (!$okUpdate || $affected < 1) {
        $failed[] = $userId;
        continue;
    }

    if ($isCommerce) {
        // Activate login only. Preserve any SCA already granted by commerce fulfillment / FAR.
        // Do NOT apply the admin SCA picker — that would simulate paid content access.
        $permOk = sca_save_user_permissions_preserving_commerce($conn, $userId, [], $adminId);
        $commerceActivated++;
    } else {
        if (!$grantFull && $normalizedPerms === []) {
            $failed[] = $userId;
            continue;
        }
        $permOk = $grantFull
            ? sca_save_user_permissions_preserving_commerce(
                $conn,
                $userId,
                [['content_type' => 'full_lms', 'content_id' => 0]],
                $adminId
            )
            : sca_save_user_permissions_preserving_commerce($conn, $userId, $normalizedPerms, $adminId);
        $legacyActivated++;
    }
    if (!$permOk) {
        $failed[] = $userId;
        continue;
    }

    $approved++;
    $lastStudentName = $studentName;

    $accessStartRaw = '';
    $accessEndRaw = '';
    $accessInfoStmt = mysqli_prepare($conn, "SELECT access_start, access_end FROM users WHERE user_id=? LIMIT 1");
    if ($accessInfoStmt) {
        mysqli_stmt_bind_param($accessInfoStmt, 'i', $userId);
        mysqli_stmt_execute($accessInfoStmt);
        $accessInfoRes = mysqli_stmt_get_result($accessInfoStmt);
        $accessInfoRow = $accessInfoRes ? mysqli_fetch_assoc($accessInfoRes) : null;
        mysqli_stmt_close($accessInfoStmt);
        if ($accessInfoRow) {
            $accessStartRaw = (string)($accessInfoRow['access_start'] ?? '');
            $accessEndRaw = (string)($accessInfoRow['access_end'] ?? '');
        }
    }
    $accessStartLabel = $accessStartRaw !== '' ? date('F j, Y', strtotime($accessStartRaw)) : 'N/A';
    $accessEndLabel = $accessEndRaw !== '' ? date('F j, Y', strtotime($accessEndRaw)) : 'N/A';

    if ($mailConfig) {
        $subject = 'Your LCRC eReview account has been approved';
        $body = "Dear " . ($studentName !== '' ? $studentName : 'Student') . ",\r\n\r\n";
        $body .= "Congratulations and welcome to LCRC eReview.\r\n";
        $body .= "We are pleased to inform you that your registration has been approved.\r\n\r\n";
        $body .= "Your account availability details are:\r\n";
        $body .= "- Access starts: {$accessStartLabel}\r\n";
        $body .= "- Access valid until: {$accessEndLabel}\r\n\r\n";
        $body .= "You may now sign in using your registered account:\r\n";
        $body .= $loginUrl . "\r\n\r\n";
        $body .= "After signing in, you can go directly to your dashboard:\r\n";
        $body .= $dashboardUrl . "\r\n\r\n";
        $body .= "For inquiries or assistance, please email us at lcrc.mmco.elearning@gmail.com\r\n";
        $body .= "or message the admin through your account in the system.\r\n\r\n";
        $body .= "We look forward to supporting your review journey.\r\n\r\n";
        $body .= "Sincerely,\r\n";
        $body .= "LCRC eReview Admin Team\r\n";

        $fromEmail = $mailConfig['from_email'] ?? ($mailConfig['smtp_username'] ?? '');
        $fromName = $mailConfig['from_name'] ?? 'LCRC eReview';
        if ($fromEmail !== '' && function_exists('sendMailSmtp')) {
            if (sendMailSmtp($studentEmail, $subject, $body, $fromEmail, $fromName, $mailConfig)) {
                $emailSentCount++;
            }
        }
    }
}

if ($approved === 0) {
    activate_user_respond(
        $isAjax,
        false,
        'Unable to activate student account(s): account/email not found or permission save failed.',
        'admin_students?tab=pending&q=&page=1',
        400,
        ['approved' => 0, 'failed' => $failed, 'total' => count($userIds)]
    );
}

if ($commerceActivated > 0 && $legacyActivated === 0) {
    $message = count($userIds) === 1
        ? 'Account activated. Content access comes from commerce fulfillment or Free Access — not from this action.'
        : ($approved . ' account(s) activated. Content access comes from commerce fulfillment or Free Access.');
} elseif ($legacyActivated > 0 && $commerceActivated === 0) {
    $accessLabel = $grantFull ? 'Full LMS' : (count($normalizedPerms) . ' content item(s)');
    $message = count($userIds) === 1
        ? 'Student approved with ' . $accessLabel . ' (manual/administrative access).'
        : ($approved . ' student(s) approved with ' . $accessLabel . '.');
} else {
    $message = $approved . ' account(s) activated (' . $commerceActivated . ' commerce login-only, ' . $legacyActivated . ' legacy with manual access).';
}
if ($emailSentCount > 0) {
    $message .= ' Emails sent: ' . $emailSentCount . '.';
}
if ($failed !== []) {
    $message .= ' Failed: ' . count($failed) . '.';
}

activate_user_respond($isAjax, true, $message, $enrolledRedirect, 200, [
    'approved' => $approved,
    'failed' => $failed,
    'total' => count($userIds),
    'email_sent' => $emailSentCount,
    'student_name' => $lastStudentName,
    'commerce_activated' => $commerceActivated,
    'legacy_activated' => $legacyActivated,
]);
