<?php
require_once 'db.php';
require_once 'email_verification.php';

function ensureRegistrationProfileColumns($conn) {
    $hasUserProfilePicture = false;
    $hasUserDefaultAvatar = false;
    $hasPendingProfilePicture = false;
    $hasPendingDefaultAvatar = false;

    $c1 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if ($c1 && mysqli_fetch_assoc($c1)) $hasUserProfilePicture = true;
    $c2 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'use_default_avatar'");
    if ($c2 && mysqli_fetch_assoc($c2)) $hasUserDefaultAvatar = true;
    $c3 = @mysqli_query($conn, "SHOW COLUMNS FROM pending_registrations LIKE 'profile_picture'");
    if ($c3 && mysqli_fetch_assoc($c3)) $hasPendingProfilePicture = true;
    $c4 = @mysqli_query($conn, "SHOW COLUMNS FROM pending_registrations LIKE 'use_default_avatar'");
    if ($c4 && mysqli_fetch_assoc($c4)) $hasPendingDefaultAvatar = true;

    if (!$hasUserProfilePicture) {
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL AFTER payment_proof");
    }
    if (!$hasUserDefaultAvatar) {
        @mysqli_query($conn, "ALTER TABLE users ADD COLUMN use_default_avatar TINYINT(1) NOT NULL DEFAULT 1 AFTER profile_picture");
    }
    if (!$hasPendingProfilePicture) {
        @mysqli_query($conn, "ALTER TABLE pending_registrations ADD COLUMN profile_picture VARCHAR(255) NULL AFTER payment_proof");
    }
    if (!$hasPendingDefaultAvatar) {
        @mysqli_query($conn, "ALTER TABLE pending_registrations ADD COLUMN use_default_avatar TINYINT(1) NOT NULL DEFAULT 1 AFTER profile_picture");
    }
}

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

ensureRegistrationProfileColumns($conn);
require_once __DIR__ . '/includes/registration_school_options.php';

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
$useDefaultAvatar = 0;

if (!in_array($review_type, ['reviewee', 'undergrad'])) $review_type = 'reviewee';
if ($school !== 'Other') {
    $school_other = null;
} elseif ($school_other !== null && (function_exists('mb_strlen') ? mb_strlen($school_other, 'UTF-8') : strlen($school_other)) > 220) {
    if ($isAjax) {
        sendJson(['success' => false, 'error' => 'School name is too long. Please shorten it.']);
    }
    $_SESSION['error'] = 'School name is too long. Please shorten it.';
    header('Location: registration.php');
    exit;
}

if (!ereview_registration_school_is_submitted_value_allowed($conn, $school, $school_other)) {
    if ($isAjax) {
        sendJson(['success' => false, 'error' => 'Please choose a valid school from the list. If you pick “Other”, enter your school name.']);
    }
    $_SESSION['error'] = 'Please choose a valid school from the list. If you pick “Other”, enter your school name.';
    header('Location: registration.php');
    exit;
}

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

$hasEmailVerifiedCol = false;
$cols = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_verified'");
if ($cols && mysqli_fetch_assoc($cols)) $hasEmailVerifiedCol = true;

// If an account exists but is NOT email-verified yet, allow re-registration.
// This fixes cases where a stale/unverified row blocks registration.
if ($hasEmailVerifiedCol) {
    $checkStmt = mysqli_prepare($conn, "SELECT user_id, email_verified FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($checkStmt, 's', $email);
    mysqli_stmt_execute($checkStmt);
    $result = mysqli_stmt_get_result($checkStmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($checkStmt);

    if ($row) {
        $ev = (int)($row['email_verified'] ?? 1);
        if ($ev === 0) {
            // Remove unverified user so pending registration can be created cleanly.
            $delStmt = mysqli_prepare($conn, "DELETE FROM users WHERE user_id = ? LIMIT 1");
            if ($delStmt) {
                $uid = (int)($row['user_id'] ?? 0);
                mysqli_stmt_bind_param($delStmt, 'i', $uid);
                mysqli_stmt_execute($delStmt);
                mysqli_stmt_close($delStmt);
            }
        } else {
            if ($isAjax) sendJson(['success' => false, 'error' => 'This email is already registered. Please use another email or sign in instead.']);
            $_SESSION['error'] = 'This email is already registered. Please use another email or sign in instead.';
            header('Location: registration.php');
            exit;
        }
    }
} else {
    // Backward compatibility: if the column doesn't exist, any existing row blocks registration.
    $checkStmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($checkStmt, 's', $email);
    mysqli_stmt_execute($checkStmt);
    $result = mysqli_stmt_get_result($checkStmt);
    if ($result && mysqli_num_rows($result) > 0) {
        mysqli_stmt_close($checkStmt);
        if ($isAjax) sendJson(['success' => false, 'error' => 'This email is already registered. Please use another email or sign in instead.']);
        $_SESSION['error'] = 'This email is already registered. Please use another email or sign in instead.';
        header('Location: registration.php');
        exit;
    }
    mysqli_stmt_close($checkStmt);
}

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

$profilePicturePath = null;
$allowed_avatar_mimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
$allowed_avatar_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] === UPLOAD_ERR_NO_FILE) {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Profile picture is required. Please upload JPG, PNG, WEBP, or GIF.']);
    $_SESSION['error'] = 'Profile picture is required. Please upload JPG, PNG, WEBP, or GIF.';
    header('Location: registration.php');
    exit;
}
if ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Failed to upload profile picture.']);
    $_SESSION['error'] = 'Failed to upload profile picture.';
    header('Location: registration.php');
    exit;
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['profile_picture']['tmp_name']);
finfo_close($finfo);
$ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
if (!in_array($mime, $allowed_avatar_mimes, true) || !in_array($ext, $allowed_avatar_ext, true)) {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Invalid profile picture type. Upload JPG, PNG, WEBP, or GIF only.']);
    $_SESSION['error'] = 'Invalid profile picture type. Upload JPG, PNG, WEBP, or GIF only.';
    header('Location: registration.php');
    exit;
}
$uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0777, true);
}
$safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
$filename = 'avatar_' . uniqid('', true) . ($safeExt ? ('.' . $safeExt) : '');
$target = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
if (!move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target)) {
    if ($isAjax) sendJson(['success' => false, 'error' => 'Failed to upload profile picture.']);
    $_SESSION['error'] = 'Failed to upload profile picture.';
    header('Location: registration.php');
    exit;
}
$profilePicturePath = 'uploads/avatars/' . $filename;

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
    'profile_picture' => $profilePicturePath,
    'use_default_avatar' => $useDefaultAvatar,
    'password_hash' => $hashed,
]);

if ($verificationUrl === null) {
    $detail = function_exists('getLastPendingRegistrationError') ? trim(getLastPendingRegistrationError()) : '';
    $msg = 'This email is already registered or a verification was already sent. Please check your email or use a different address.';
    if ($detail !== '') {
        $msg .= ' Details: ' . $detail;
    }
    if ($isAjax) sendJson(['success' => false, 'error' => $msg]);
    $_SESSION['error'] = 'Registration could not be created. Please try again.';
    header('Location: registration.php');
    exit;
}

ereview_registration_school_catalog_save($conn, $school, $school_other);

$emailSent = sendVerificationEmail($email, $verificationUrl);

if (!$emailSent) {
    if ($isAjax) {
        sendJson([
            'success' => false,
            'error' => 'Registration was saved, but we could not send the verification email right now. Please try again in a moment.'
        ]);
    }
    $_SESSION['error'] = 'Registration was saved, but we could not send the verification email right now. Please try again in a moment.';
    header('Location: registration.php');
    exit;
}

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
