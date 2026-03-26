<?php
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/smtp_sender.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || !empty($_POST['ajax']);

$token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid request. Please try again.']);
        exit;
    }
    $_SESSION['error'] = 'Invalid request. Please try again.';
    header('Location: admin_students.php?tab=pending&q=&page=1');
    exit;
}

$userId = (int)($_POST['user_id'] ?? 0);
$months = (int)($_POST['months'] ?? 0);
$returnTo = trim((string)($_POST['return_to'] ?? 'admin_dashboard.php'));
if ($returnTo === '' || strpos($returnTo, '://') !== false || strpos($returnTo, '//') === 0 || strpos($returnTo, '/') === 0) {
    $returnTo = 'admin_students.php?tab=enrolled&q=&page=1';
}
if ($userId <= 0 || $months <= 0) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid user or months value.']);
        exit;
    }
    header('Location: admin_students.php?tab=pending&q=&page=1');
    exit;
}

// Get student details before status update.
$studentEmail = '';
$studentName = '';
$studentRole = '';
$studentStmt = mysqli_prepare($conn, "SELECT email, full_name, role FROM users WHERE user_id=? LIMIT 1");
if ($studentStmt) {
    mysqli_stmt_bind_param($studentStmt, 'i', $userId);
    mysqli_stmt_execute($studentStmt);
    $studentRes = mysqli_stmt_get_result($studentStmt);
    $studentRow = $studentRes ? mysqli_fetch_assoc($studentRes) : null;
    mysqli_stmt_close($studentStmt);
    if ($studentRow) {
        $studentEmail = trim((string)($studentRow['email'] ?? ''));
        $studentName = trim((string)($studentRow['full_name'] ?? 'Student'));
        $studentRole = trim((string)($studentRow['role'] ?? ''));
    }
}
if ($studentRole !== 'student' || $studentEmail === '') {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Unable to approve student: account/email not found.']);
        exit;
    }
    $_SESSION['error'] = 'Unable to approve student: account/email not found.';
    header('Location: admin_students.php?tab=pending&q=&page=1');
    exit;
}

// Set access window
$sql = "UPDATE users SET status='approved', access_start=NOW(), access_end=DATE_ADD(NOW(), INTERVAL ? MONTH), access_months=? WHERE user_id=?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'iii', $months, $months, $userId);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

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

// Send congratulatory approval email (non-blocking: approval stays successful even if email fails).
$emailSent = false;
$mailConfigFile = __DIR__ . '/config/mail_config.php';
if (file_exists($mailConfigFile)) {
    $mailConfig = require $mailConfigFile;
    if (is_array($mailConfig) && function_exists('isMailConfigValid') && isMailConfigValid($mailConfig)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim($scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        $dashboardUrl = $base . '/student_dashboard.php';
        $loginUrl = $base . '/login.php';

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
        if ($fromEmail !== '') {
            $emailSent = sendMailSmtp($studentEmail, $subject, $body, $fromEmail, $fromName, $mailConfig);
        }
    }
}

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'message' => $emailSent
            ? 'Student approved and notification email sent.'
            : 'Student approved successfully. Email was not sent.',
        'redirect_url' => 'admin_students.php?tab=enrolled&q=&page=1',
    ]);
    exit;
}

if ($emailSent) {
    $_SESSION['message'] = 'Student approved and notification email sent.';
} else {
    $_SESSION['message'] = 'Student approved successfully.';
}
header('Location: admin_students.php?tab=enrolled&q=&page=1');
exit;
?>



