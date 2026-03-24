<?php
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/smtp_sender.php';

$token = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($token)) {
    $_SESSION['error'] = 'Invalid request. Please try again.';
    header('Location: admin_dashboard.php');
    exit;
}

$userId = (int)($_POST['user_id'] ?? 0);
$months = (int)($_POST['months'] ?? 0);
$returnTo = trim((string)($_POST['return_to'] ?? 'admin_dashboard.php'));
if ($returnTo === '' || strpos($returnTo, '://') !== false || strpos($returnTo, '//') === 0 || strpos($returnTo, '/') === 0) {
    $returnTo = 'admin_dashboard.php';
}
if ($userId <= 0 || $months <= 0) {
    header('Location: ' . $returnTo);
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
    $_SESSION['error'] = 'Unable to approve student: account/email not found.';
    header('Location: ' . $returnTo);
    exit;
}

// Set access window
$sql = "UPDATE users SET status='approved', access_start=NOW(), access_end=DATE_ADD(NOW(), INTERVAL ? MONTH), access_months=? WHERE user_id=?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'iii', $months, $months, $userId);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

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

        $subject = 'Congratulations! Your LCRC eReview account is now approved';
        $body = "Hi " . ($studentName !== '' ? $studentName : 'Student') . ",\r\n\r\n";
        $body .= "Great news — your account has been approved by the LCRC eReview admin team.\r\n";
        $body .= "Your access has been activated for {$months} month(s).\r\n\r\n";
        $body .= "You can now sign in and start learning:\r\n";
        $body .= $loginUrl . "\r\n\r\n";
        $body .= "After login, proceed to your dashboard:\r\n";
        $body .= $dashboardUrl . "\r\n\r\n";
        $body .= "Congratulations and welcome to LCRC eReview!\r\n\r\n";
        $body .= "— LCRC eReview Admin Team\r\n";

        $fromEmail = $mailConfig['from_email'] ?? ($mailConfig['smtp_username'] ?? '');
        $fromName = $mailConfig['from_name'] ?? 'LCRC eReview';
        if ($fromEmail !== '') {
            $emailSent = sendMailSmtp($studentEmail, $subject, $body, $fromEmail, $fromName, $mailConfig);
        }
    }
}

if ($emailSent) {
    $_SESSION['message'] = 'Student approved and congratulatory email sent.';
} else {
    $_SESSION['message'] = 'Student approved. Email notification was not sent (check mail config).';
}

header('Location: ' . $returnTo);
exit;
?>



