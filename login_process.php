<?php
require_once 'db.php';
require_once 'auth.php';
require_once 'login_rate_limit.php';
require_once 'remember_me.php';

// Regenerate session ID on login to prevent session fixation
if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Rate limit check first (before any other processing)
    list($rateLimited, $lockedUntilTs) = isLoginRateLimited();
    if ($rateLimited && $lockedUntilTs !== null) {
        $_SESSION['rate_limit_until'] = $lockedUntilTs;
        $_SESSION['error'] = 'Too many login attempts. Try again in ' . max(1, (int) ceil(($lockedUntilTs - time()) / 60)) . ' minutes.';
        $_SESSION['error_type'] = 'rate_limit';
        header('Location: login.php');
        exit;
    }

    // CSRF protection
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        $_SESSION['error_type'] = 'csrf';
        header('Location: login.php');
        exit;
    }

    // CAPTCHA after 2+ failed attempts (when reCAPTCHA is configured)
    $attemptCount = getLoginAttemptCount();
    $recaptchaFile = __DIR__ . '/config/recaptcha_config.php';
    if ($attemptCount >= LOGIN_CAPTCHA_AFTER_ATTEMPTS && file_exists($recaptchaFile)) {
        $recaptchaConfig = require $recaptchaFile;
        $secretKey = is_array($recaptchaConfig) ? trim($recaptchaConfig['secret_key'] ?? '') : '';
        if ($secretKey !== '') {
            $recaptchaToken = trim($_POST['g-recaptcha-response'] ?? '');
            if ($recaptchaToken === '') {
                $_SESSION['error'] = 'Please complete the security check and try again.';
                $_SESSION['error_type'] = 'invalid_credentials';
                header('Location: login.php');
                exit;
            }
            $verify = @file_get_contents('https://www.google.com/recaptcha/api/siteverify?' . http_build_query([
                'secret'   => $secretKey,
                'response' => $recaptchaToken,
                'remoteip' => getLoginClientIp(),
            ]));
            $verify = $verify ? json_decode($verify, true) : null;
            if (!is_array($verify) || empty($verify['success']) || (isset($verify['score']) && $verify['score'] < 0.3)) {
                $_SESSION['error'] = 'Security check failed. Please try again.';
                $_SESSION['error_type'] = 'invalid_credentials';
                header('Location: login.php');
                exit;
            }
        }
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Server-side validation: email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email or password.';
        $_SESSION['error_type'] = 'invalid_credentials';
        header('Location: login.php');
        exit;
    }

    if ($password === '') {
        $_SESSION['error'] = 'Please enter your password.';
        $_SESSION['error_type'] = 'password_required';
        header('Location: login.php');
        exit;
    }

    // Use prepared statement to prevent SQL injection
    $stmt = mysqli_prepare($conn, "SELECT user_id, full_name, email, password, role, status, access_end FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    $isValid = false;
    if ($user) {
        // Accept hashed passwords; fallback to plain match for existing seed data
        if (password_verify($password, $user['password'])) {
            $isValid = true;
        } elseif ($password === $user['password']) {
            $isValid = true;
        }
    }

    if ($isValid) {
        // Enforce email verification when column exists
        $cols = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_verified'");
        if ($cols && mysqli_fetch_assoc($cols)) {
            $evStmt = mysqli_prepare($conn, "SELECT email_verified FROM users WHERE user_id = ? LIMIT 1");
            mysqli_stmt_bind_param($evStmt, 'i', $user['user_id']);
            mysqli_stmt_execute($evStmt);
            $evRes = mysqli_stmt_get_result($evStmt);
            $evRow = $evRes ? mysqli_fetch_assoc($evRes) : null;
            mysqli_stmt_close($evStmt);
            if ($evRow && (int)($evRow['email_verified'] ?? 1) === 0) {
                $_SESSION['error'] = 'Your account has not been verified yet. Please confirm your email before signing in.';
                $_SESSION['error_type'] = 'google_not_verified';
                header('Location: login.php');
                exit;
            }
        }
        // Enforce approval and active access window for non-admins
        if ($user['role'] !== 'admin' && strtolower($user['status']) !== 'approved') {
            $_SESSION['error'] = 'Your account is not approved yet.';
            $_SESSION['error_type'] = 'not_approved';
            header('Location: login.php');
            exit;
        }
        if ($user['role'] !== 'admin') {
            $now = new DateTime('now');
            if (!empty($user['access_end'])) {
                $end = new DateTime($user['access_end']);
                if ($now > $end) {
                    $_SESSION['error'] = 'Your access has expired.';
                    $_SESSION['error_type'] = 'access_expired';
                    header('Location: login.php');
                    exit;
                }
            }
        }

        // Set session variables
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['created'] = time();
        $_SESSION['last_activity'] = time();

        // Update last login (columns added by add_last_login.sql)
        $uid = (int) $user['user_id'];
        $now = date('Y-m-d H:i:s');
        $ip = getLoginClientIp();
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $upd = @mysqli_prepare($conn, 'UPDATE users SET last_login_at = ?, last_login_ip = ?, last_login_user_agent = ? WHERE user_id = ?');
        if ($upd) {
            mysqli_stmt_bind_param($upd, 'sssi', $now, $ip, $ua, $uid);
            @mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
        
        // Verify session is valid
        if (!verifySession()) {
            session_destroy();
            $_SESSION['error'] = 'Session verification failed. Please try again.';
            $_SESSION['error_type'] = 'session_failed';
            header('Location: login.php');
            exit;
        }

        clearLoginAttempts();
        if (!empty($_POST['remember_me'])) {
            setRememberMeCookie($user['user_id']);
        }
        $target = ($user['role'] === 'admin') ? 'admin_dashboard.php' : 'student_dashboard.php';
        $fullName = trim($user['full_name'] ?? '');
        $firstName = $fullName !== '' ? explode(' ', $fullName)[0] : 'User';
        header('Location: auth_success.php?target=' . rawurlencode($target) . '&name=' . rawurlencode($firstName));
        exit;
    } else {
        $lockTs = recordFailedLoginAttempt();
        if ($lockTs !== null) {
            $_SESSION['rate_limit_until'] = $lockTs;
            $_SESSION['error'] = 'Too many login attempts. Try again in ' . max(1, (int) ceil(($lockTs - time()) / 60)) . ' minutes.';
            $_SESSION['error_type'] = 'rate_limit';
        } else {
            $_SESSION['error'] = 'Invalid email or password.';
            $_SESSION['error_type'] = 'invalid_credentials';
        }
        header('Location: login.php');
        exit;
    }
}
?>
