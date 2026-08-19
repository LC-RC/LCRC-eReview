<?php
require_once 'db.php';
require_once 'auth.php';
require_once 'login_rate_limit.php';
require_once 'remember_me.php';
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';

// Regenerate session ID on login to prevent session fixation
if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Rate limit check first (before any other processing)
    list($rateLimited, $lockedUntilTs) = isLoginRateLimited();
    if ($rateLimited && $lockedUntilTs !== null) {
        $_SESSION['rate_limit_until'] = $lockedUntilTs;
        $_SESSION['error'] = 'Too many login attempts. Try again in ' . formatLoginLockoutRemaining($lockedUntilTs) . '.';
        $_SESSION['error_type'] = 'rate_limit';
        header('Location: login');
        exit;
    }

    // CSRF protection
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        $_SESSION['error_type'] = 'csrf';
        header('Location: login');
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
                header('Location: login');
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
                header('Location: login');
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
        header('Location: login');
        exit;
    }

    if ($password === '') {
        $_SESSION['error'] = 'Please enter your password.';
        $_SESSION['error_type'] = 'password_required';
        header('Location: login');
        exit;
    }

    // Use prepared statement to prevent SQL injection
    require_once __DIR__ . '/includes/platform_access.php';
    $loginCols = 'user_id, full_name, email, password, role, status, access_end';
    if (ereview_platform_access_columns_ready($conn)) {
        $loginCols .= ', college_examination_access, review_type, section, student_number';
    }
    $stmt = mysqli_prepare($conn, "SELECT {$loginCols} FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    $isValid = false;
    if ($user) {
        $stored = (string)($user['password'] ?? '');
        if ($stored !== '' && password_verify($password, $stored)) {
            $isValid = true;
        } elseif ($stored !== '' && !preg_match('/^\$2[ayb]\$/', $stored) && hash_equals($stored, $password)) {
            // Legacy plaintext seed passwords: accept once, then upgrade to bcrypt.
            $isValid = true;
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upPw = @mysqli_prepare($conn, 'UPDATE users SET password = ? WHERE user_id = ? LIMIT 1');
            if ($upPw) {
                $uidUp = (int)$user['user_id'];
                mysqli_stmt_bind_param($upPw, 'si', $newHash, $uidUp);
                @mysqli_stmt_execute($upPw);
                mysqli_stmt_close($upPw);
            }
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
                header('Location: login');
                exit;
            }
        }
        // Platform access: eReview and/or College Examination (staff bypass).
        if (!isStaffRole($user['role'])) {
            require_once __DIR__ . '/includes/platform_access.php';
            $gate = ereview_user_can_authenticate($conn, $user);
            if (empty($gate['ok'])) {
                $_SESSION['error'] = (string) ($gate['error'] ?? 'Your account is not approved yet.');
                $_SESSION['error_type'] = (string) ($gate['error_type'] ?? 'not_approved');
                header('Location: login');
                exit;
            }
        }

        $examBlock = college_exam_login_blocked_by_active_exam_session($conn, (int)$user['user_id']);
        if ($examBlock !== null) {
            $_SESSION['error'] = $examBlock;
            $_SESSION['error_type'] = 'exam_session_active';
            header('Location: login');
            exit;
        }

        // Set session variables
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'] ?? '';
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
        setUserPresenceStatus($uid, true);
        
        // Verify session is valid
        if (!verifySession()) {
            session_destroy();
            $_SESSION['error'] = 'Session verification failed. Please try again.';
            $_SESSION['error_type'] = 'session_failed';
            header('Location: login');
            exit;
        }

        clearLoginAttempts();
        if (!empty($_POST['remember_me'])) {
            setRememberMeCookie($user['user_id']);
        }
        require_once __DIR__ . '/includes/admin_acl.php';
        admin_acl_ensure_schema($conn);
        if (($user['role'] ?? '') === 'admin') {
            admin_acl_refresh_session($conn, (int) $user['user_id']);
        }
        users_activity_log(
            $conn,
            'login_success',
            ['via' => 'password'],
            (int) $user['user_id'],
            (string) ($user['email'] ?? $email),
            (string) ($user['role'] ?? ''),
            null
        );
        $target = ereview_resolve_post_login_url($conn, $user);
        if (($user['role'] ?? '') === 'admin' && !admin_can('dashboard')) {
            $firstKey = null;
            $keys = admin_acl_session_keys();
            if (is_array($keys) && $keys !== []) {
                $firstKey = $keys[0];
            }
            $scriptMap = [
                'manage_admins' => 'admin_admins',
                'students' => 'admin_students',
                'student_access' => 'admin_student_access',
                'support' => 'admin_support_analytics',
                'subjects' => 'admin_subjects',
                'lessons' => 'admin_lessons',
                'videos' => 'admin_videos',
                'handouts' => 'admin_handouts',
                'materials' => 'admin_materials',
                'quizzes' => 'admin_quizzes',
                'test_bank' => 'admin_test_bank',
                'preboards' => 'admin_preboards_subjects',
                'preweek' => 'admin_preweek',
                'question_bank' => 'admin_question_sort',
                'commerce_packages' => 'admin_commerce_packages',
                'commerce_topics' => 'admin_commerce_topics',
                'commerce_gcash' => 'admin_commerce_gcash',
                'commerce_payments' => 'admin_commerce_payments',
                'commerce_free_access' => 'admin_commerce_free_access',
                'commerce_grants' => 'admin_commerce_grants',
                'commerce_reports' => 'admin_commerce_reports',
            ];
            if ($firstKey && isset($scriptMap[$firstKey])) {
                $target = ereview_url($scriptMap[$firstKey]);
            }
        }
        $fullName = trim($user['full_name'] ?? '');
        $firstName = $fullName !== '' ? explode(' ', $fullName)[0] : 'User';
        header('Location: auth_success?target=' . rawurlencode($target) . '&name=' . rawurlencode($firstName));
        exit;
    } else {
        require_once __DIR__ . '/includes/admin_acl.php';
        admin_acl_ensure_schema($conn);
        users_activity_log(
            $conn,
            'login_fail',
            ['reason' => 'invalid_credentials'],
            $user ? (int) $user['user_id'] : null,
            $email,
            $user ? (string) ($user['role'] ?? '') : null,
            null
        );
        $lockTs = recordFailedLoginAttempt();
        if ($lockTs !== null) {
            $_SESSION['rate_limit_until'] = $lockTs;
            $_SESSION['error'] = 'Too many login attempts. Try again in ' . formatLoginLockoutRemaining($lockTs) . '.';
            $_SESSION['error_type'] = 'rate_limit';
        } else {
            $_SESSION['error'] = 'Invalid email or password.';
            $_SESSION['error_type'] = 'invalid_credentials';
        }
        header('Location: login');
        exit;
    }
}
?>
