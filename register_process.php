<?php
require_once 'db.php';
require_once 'email_verification.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (!empty($_GET['ajax']) || (!empty($_POST['ajax'])));

function sendJson($data) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Invalid request.']);
    header('Location: registration.php');
    exit;
}

$full_name_raw = $_POST['full_name'] ?? '';
$full_name = trim(preg_replace('/\s+/', ' ', $full_name_raw));
$review_type = $_POST['review_type'] ?? 'reviewee';
$school = $_POST['school'] ?? '';
$school_other = isset($_POST['school_other']) ? trim($_POST['school_other']) : null;
$email_raw = $_POST['email'] ?? '';
$email = trim($email_raw);
$password_raw = $_POST['password'] ?? '';
$password = trim($password_raw);
$password_confirm_raw = $_POST['password_confirm'] ?? '';
$password_confirm = trim($password_confirm_raw);

if (!in_array($review_type, ['reviewee', 'undergrad'])) $review_type = 'reviewee';
if ($school !== 'Other') $school_other = null;

if ($full_name === '' || $email === '' || $password === '' || $password_confirm === '' || $school === '') {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Please complete all required fields.']);
    $_SESSION['error'] = 'Please complete all required fields.';
    header('Location: registration.php');
    exit;
}

// Full name: letters, single spaces, single dots only; no leading space, no consecutive dots
if (!preg_match('/^[A-Za-z\.\s]+$/', $full_name) || preg_match('/\.\./', $full_name) || $full_name !== trim($full_name)) {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Full name can only contain letters, single spaces, and single dots. No leading or double spaces.']);
    $_SESSION['error'] = 'Full name can only contain letters, single spaces, and single dots. No leading or double spaces.';
    header('Location: registration.php');
    exit;
}

// Email: reject space-only local part
$at_pos = strpos($email, '@');
$local_part = $at_pos !== false ? substr($email, 0, $at_pos) : '';
if (trim($local_part) === '' || preg_match('/^\s+$/', $local_part)) {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Please enter a valid email address.']);
    $_SESSION['error'] = 'Please enter a valid email address.';
    header('Location: registration.php');
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Please enter a valid email address.']);
    $_SESSION['error'] = 'Please enter a valid email address.';
    header('Location: registration.php');
    exit;
}

// Password: no all-space, trim applied above
if (preg_match('/^\s+$/', $password_raw) || $password === '') {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Password cannot be empty or only spaces.']);
    $_SESSION['error'] = 'Password cannot be empty or only spaces.';
    header('Location: registration.php');
    exit;
}
if ($password !== $password_confirm) {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Passwords do not match.']);
    $_SESSION['error'] = 'Passwords do not match.';
    header('Location: registration.php');
    exit;
}

$pwLen = strlen($password);
$hasNumber = preg_match('/\d/', $password);
$hasUpper = preg_match('/[A-Z]/', $password);
$hasLower = preg_match('/[a-z]/', $password);
$hasSymbol = preg_match('/[^A-Za-z0-9]/', $password);
if ($pwLen < 8 || !$hasNumber || !$hasUpper || !$hasLower || !$hasSymbol) {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Password must be at least 8 characters and include a number, uppercase, lowercase, and symbol.']);
    $_SESSION['error'] = 'Password must be at least 8 characters and include a number, uppercase letter, lowercase letter, and symbol.';
    header('Location: registration.php');
    exit;
}

$checkStmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($checkStmt, 's', $email);
mysqli_stmt_execute($checkStmt);
$result = mysqli_stmt_get_result($checkStmt);
if (mysqli_num_rows($result) > 0) {
    mysqli_stmt_close($checkStmt);
    if ($isAjax) sendJson(['success' => false, 'error' => 'This email is already registered. Please use another email or sign in instead.']);
    $_SESSION['error'] = 'This email is already registered. Please use another email or sign in instead.';
    header('Location: registration.php');
    exit;
}
mysqli_stmt_close($checkStmt);

$uploadedPath = null;
$allowed_mimes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
$allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['payment_proof']['tmp_name']);
    finfo_close($finfo);
    $ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
    if (!in_array($mime, $allowed_mimes) || !in_array($ext, $allowed_ext)) {
        if ($isAjax) sendJson(['success' => false, 'error' => 'Invalid file type. Please upload an image (JPG, PNG) or PDF for payment verification.']);
        $_SESSION['error'] = 'Invalid file type. Please upload an image (JPG, PNG) or PDF for payment verification.';
        header('Location: registration.php');
        exit;
    }
    $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
    }
    $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $filename = 'proof_' . uniqid('', true) . ($safeExt ? ('.' . $safeExt) : '');
    $target = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($_FILES['payment_proof']['tmp_name'], $target)) {
        if ($isAjax) sendJson(['success' => false, 'error' => 'Failed to upload payment proof.']);
        $_SESSION['error'] = 'Failed to upload payment proof.';
        header('Location: registration.php');
        exit;
    }
    $uploadedPath = 'uploads/' . $filename;
} else {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Payment proof is required.']);
    $_SESSION['error'] = 'Payment proof is required.';
    header('Location: registration.php');
    exit;
}

$hashed = password_hash($password, PASSWORD_DEFAULT);

$delPending = mysqli_prepare($conn, "DELETE FROM pending_registrations WHERE email = ?");
if ($delPending) {
    mysqli_stmt_bind_param($delPending, 's', $email);
    mysqli_stmt_execute($delPending);
    mysqli_stmt_close($delPending);
}

$verificationUrl = createPendingRegistration([
    'email' => $email,
    'full_name' => $full_name,
    'review_type' => $review_type,
    'school' => $school,
    'school_other' => $school_other,
    'payment_proof' => $uploadedPath,
    'password_hash' => $hashed,
]);

if ($verificationUrl === null) {
    if ($isAjax) sendJson(['success' => false, 'error' => 'This email is already registered or a verification was already sent. Please check your email or use a different address.']);
    $_SESSION['error'] = 'Registration could not be created. Please try again.';
    header('Location: registration.php');
    exit;
}

$emailSent = sendVerificationEmail($email, $verificationUrl);

if ($isAjax) {
    sendJson([
        'success' => true,
        'message' => 'Verification email sent.',
        'email' => $email,
        'email_sent' => $emailSent,
    ]);
}

$_SESSION['success'] = 'Verification email sent. Please check your email.';
$_SESSION['pending_email'] = $email;
header('Location: registration.php');
exit;
