<?php
require_once 'session_config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'login_rate_limit.php';

if (isLoggedIn() && verifySession()) {
    header('Location: ' . dashboardUrlForRole(getCurrentUserRole()));
    exit;
}

// Magic link sign-in: validate token and log user in
if (!empty($_GET['magic'])) {
    require_once __DIR__ . '/magic_link.php';
    $magicRaw = $_GET['magic'];
    $magicResult = validateMagicLinkToken($magicRaw);
    if ($magicResult !== null) {
        $stmt = mysqli_prepare($conn, "SELECT user_id, full_name, role, status, access_end FROM users WHERE user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $magicResult['user_id']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        if ($user) {
            require_once __DIR__ . '/includes/college_schema.php';
            require_once __DIR__ . '/includes/college_exam_helpers.php';
            $examBlock = college_exam_login_blocked_by_active_exam_session($conn, (int)$user['user_id'], (string)$user['role']);
            if ($examBlock !== null) {
                $_SESSION['error'] = $examBlock;
                $_SESSION['error_type'] = 'exam_session_active';
                header('Location: login');
                exit;
            }
            if (!isStaffRole($user['role']) && strtolower($user['status']) !== 'approved') {
                $_SESSION['error'] = 'Your account is not approved yet.';
                $_SESSION['error_type'] = 'not_approved';
                header('Location: login');
                exit;
            }
            if (!isStaffRole($user['role'])) {
                $now = new DateTime('now');
                if (!empty($user['access_end'])) {
                    $end = new DateTime($user['access_end']);
                    if ($now > $end) {
                        $_SESSION['error'] = 'Your access has expired.';
                        $_SESSION['error_type'] = 'access_expired';
                        header('Location: login');
                        exit;
                    }
                }
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['created'] = time();
            $_SESSION['last_activity'] = time();
            $uid = (int) $user['user_id'];
            $now = date('Y-m-d H:i:s');
            $ip = function_exists('getLoginClientIp') ? getLoginClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            $upd = @mysqli_prepare($conn, 'UPDATE users SET last_login_at = ?, last_login_ip = ?, last_login_user_agent = ? WHERE user_id = ?');
            if ($upd) {
                mysqli_stmt_bind_param($upd, 'sssi', $now, $ip, $ua, $uid);
                @mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);
            }
            setUserPresenceStatus($uid, true);
            deleteMagicLinkToken($magicResult['token_id']);
            if (verifySession()) {
                header('Location: ' . dashboardUrlForRole($user['role']));
                exit;
            }
        }
    }
    $_SESSION['error'] = 'This sign-in link is invalid or has expired.';
    $_SESSION['error_type'] = 'invalid_credentials';
    header('Location: login');
    exit;
}

$pageTitle = 'Login';
$error = $_SESSION['error'] ?? null;
$errorType = $_SESSION['error_type'] ?? 'invalid_credentials';
$rateLimitUntil = isset($_SESSION['rate_limit_until']) ? (int) $_SESSION['rate_limit_until'] : null;
unset($_SESSION['error'], $_SESSION['error_type']);

// DB is source of truth; clamp any legacy 15-minute lockouts down to 2 minutes.
list($dbRateLimited, $dbLockedUntil) = isLoginRateLimited();
if ($dbRateLimited && $dbLockedUntil !== null) {
    $rateLimitUntil = $dbLockedUntil;
    $_SESSION['rate_limit_until'] = $dbLockedUntil;
} elseif ($rateLimitUntil !== null && $rateLimitUntil > time()) {
    $rateLimitUntil = login_rate_limit_normalize_locked_until($rateLimitUntil, false);
    $_SESSION['rate_limit_until'] = $rateLimitUntil;
}

// Clear expired rate limit so we don't show the block on next load
if ($rateLimitUntil !== null && time() >= $rateLimitUntil) {
    unset($_SESSION['rate_limit_until']);
    $rateLimitUntil = null;
}

$showRateLimitBlock = $rateLimitUntil !== null && $rateLimitUntil > time();
$csrf = generateCSRFToken();

// CAPTCHA after 2+ failed attempts (when configured)
$loginAttemptCount = $showRateLimitBlock ? 0 : getLoginAttemptCount();
$recaptchaConfig = file_exists(__DIR__ . '/config/recaptcha_config.php') ? require __DIR__ . '/config/recaptcha_config.php' : [];
$recaptchaSiteKey = is_array($recaptchaConfig) ? trim($recaptchaConfig['site_key'] ?? '') : '';
$showRecaptcha = ($loginAttemptCount >= LOGIN_CAPTCHA_AFTER_ATTEMPTS && $recaptchaSiteKey !== '');

// Modal title by error type (clearer server-side messages)
$errorTitles = [
    'rate_limit' => 'Too many attempts',
    'not_approved' => 'Account not approved',
    'access_expired' => 'Access expired',
    'invalid_credentials' => 'Incorrect credentials',
    'csrf' => 'Invalid request',
    'password_required' => 'Password required',
    'session_failed' => 'Session error',
    'google_no_account' => 'No account found',
    'google_not_verified' => 'Email not verified',
    'google_not_configured' => 'Google Sign-In not set up',
    'exam_session_active' => 'Exam in progress elsewhere',
];
$errorModalTitle = $errorTitles[$errorType] ?? 'Incorrect credentials';
$googleRedirectUri = '';
if ($errorType === 'google_not_configured' || (strpos($error ?? '', 'Google') !== false && $errorType === 'invalid_credentials')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $base = str_replace('\\', '/', $base);
    $googleRedirectUri = rtrim($base, '/') . '/google_callback';
}
if (isset($_SESSION['google_redirect_uri'])) {
    $googleRedirectUri = $_SESSION['google_redirect_uri'];
    unset($_SESSION['google_redirect_uri']);
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <?php require_once __DIR__ . '/includes/head_public.php'; ?>
  <?php require_once __DIR__ . '/includes/auth_theme_light_glass.php'; ?>
</head>
<body class="auth-page login-prototype min-h-screen font-sans antialiased" data-login-error="<?php echo ($error && !$showRateLimitBlock) ? '1' : '0'; ?>" data-login-error-message="<?php echo ($error && !$showRateLimitBlock) ? h($error) : ''; ?>" data-login-error-title="<?php echo ($error && !$showRateLimitBlock) ? h($errorModalTitle) : ''; ?>" data-login-error-type="<?php echo ($error && !$showRateLimitBlock) ? h($errorType) : ''; ?>" data-rate-limit-until="<?php echo $showRateLimitBlock ? $rateLimitUntil : ''; ?>">
  <div class="animated-bg"></div>
  <div class="circuit-bg" aria-hidden="true"></div>
  <div class="login-bg-blob" aria-hidden="true"></div>
  <div class="login-cpa-visual" aria-hidden="true">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
      <circle class="cpa-ring" cx="200" cy="200" r="80" stroke-dasharray="80 200" />
      <circle class="cpa-ring" cx="1000" cy="600" r="100" stroke-dasharray="100 250" style="animation-delay: -2s" />
      <path class="cpa-line" d="M0 400 Q300 350 600 380 T1200 360" />
      <path class="cpa-line" d="M0 550 L400 500 L800 520 L1200 480" style="animation-delay: -8s" />
    </svg>
  </div>
  <div class="login-cashflow-path" aria-hidden="true">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
      <path class="path" d="M0 750 Q200 600 400 550 T800 350 T1200 100" stroke="rgba(245,158,11,0.35)" />
    </svg>
  </div>
  <div class="login-bg-animation" aria-hidden="true">
    <div class="login-bg-nodes absolute inset-0">
      <span class="login-bg-node login-bg-node--blue" style="--x:12%;--y:18%;--delay:0s;--dur:22s"></span>
      <span class="login-bg-node login-bg-node--blue" style="--x:88%;--y:22%;--delay:3s;--dur:25s"></span>
      <span class="login-bg-node login-bg-node--blue" style="--x:25%;--y:75%;--delay:6s;--dur:24s"></span>
      <span class="login-bg-node login-bg-node--blue" style="--x:75%;--y:80%;--delay:2s;--dur:26s"></span>
      <span class="login-bg-node login-bg-node--gold" style="--x:8%;--y:55%;--delay:1s;--dur:28s"></span>
      <span class="login-bg-node login-bg-node--gold" style="--x:92%;--y:45%;--delay:4s;--dur:23s"></span>
      <span class="login-bg-node login-bg-node--gold" style="--x:50%;--y:12%;--delay:5s;--dur:27s"></span>
      <span class="login-bg-node login-bg-node--white" style="--x:35%;--y:35%;--delay:2s;--dur:24s"></span>
      <span class="login-bg-node login-bg-node--white" style="--x:65%;--y:60%;--delay:7s;--dur:22s"></span>
    </div>
    <div class="login-bg-lines">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
        <path class="line line--blue" stroke-dasharray="120 80" d="M0 200 Q300 150 600 200 T1200 200" />
        <path class="line line--blue" stroke-dasharray="100 60" d="M0 500 Q400 450 800 500 T1200 480" />
        <path class="line line--gold" stroke-dasharray="80 100" d="M100 0 V800 M500 0 V800 M900 0 V800" />
        <path class="line line--white" stroke-dasharray="150 100" d="M0 400 L1200 400 M0 600 L1200 600" />
      </svg>
    </div>
  </div>
  <div class="auth-corner-decor" aria-hidden="true">
    <span class="auth-corner-dot tl"></span><span class="auth-corner-dot tr blue"></span>
    <span class="auth-corner-dot bl blue"></span><span class="auth-corner-dot br"></span>
  </div>
  <div class="login-page-layout min-h-screen flex flex-col relative z-10">
    <div class="flex-1 flex items-center justify-center p-4">
    <div class="login-card-wrap w-full max-w-[520px] mx-auto">
      <div class="login-card">
          <div class="flex flex-col items-center login-piece login-piece-1 login-header">
          <div class="login-logo-wrap flex items-center justify-center login-logo-hover">
            <img src="image%20assets/lms-logo.png" alt="LCRC eReview" class="login-logo-img" width="120" height="48" loading="eager" decoding="async">
          </div>
          <span class="brand-text"><span class="blue">LCRC</span> <span class="amber">eReview</span></span>
        </div>

        <div class="text-center login-piece login-piece-2 login-welcome">
          <p class="login-value-statement">Track your scores, drills, and mock exams in one place.</p>
          <h1 class="text-xl font-bold tracking-tight">Welcome Back</h1>
          <p class="subtext login-signup-line">Don't have an account yet? <a href="registration">Sign up</a></p>
        </div>

        <?php if ($showRateLimitBlock): ?>
        <div class="login-ratelimit-block login-piece login-piece-3" id="login-ratelimit-block" data-until="<?php echo (int) $rateLimitUntil; ?>">
          <div class="login-ratelimit-block-icon-wrap">
            <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>
          </div>
          <h2 class="login-ratelimit-block-title">Too many login attempts</h2>
          <p class="login-ratelimit-block-desc">For your security, we've temporarily limited sign-in from this device. You can try again when the timer below reaches zero.</p>
          <div class="login-ratelimit-countdown" id="login-ratelimit-countdown" role="timer" aria-live="polite">—</div>
          <p class="mt-3 text-xs text-amber-700/80">Attempts are limited to <?php echo LOGIN_RATE_LIMIT_MAX_ATTEMPTS; ?> in <?php echo (int) (LOGIN_RATE_LIMIT_WINDOW_SECONDS / 60); ?> minutes. Lockout lasts <?php echo (int) (LOGIN_RATE_LIMIT_LOCKOUT_SECONDS / 60); ?> minutes.</p>
          <p class="mt-2 text-xs"><a href="forgot_password" class="text-amber-600 hover:underline font-medium">Reset your password</a> to unlock sooner.</p>
        </div>
        <?php endif; ?>

        <div class="login-form-wrap" id="login-form-wrap"<?php if ($showRateLimitBlock): ?> style="display: none;"<?php endif; ?>>
        <form action="login_process" method="POST" class="login-form-fields space-y-4" novalidate id="login-form"<?php if ($showRecaptcha): ?> data-recaptcha="1" data-recaptcha-key="<?php echo h($recaptchaSiteKey); ?>"<?php endif; ?>>
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <?php if ($showRecaptcha): ?>
          <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response" value="">
          <?php endif; ?>
          <div class="space-y-2 login-piece login-piece-4">
            <div class="relative float-label-wrap" id="login-email-wrap">
              <label for="login-email" class="float-label">Email Address</label>
              <span class="auth-input-icon-wrap absolute left-0 top-0 bottom-0 flex items-center justify-center input-icon">
                <i class="bi bi-envelope-fill text-lg" aria-hidden="true"></i>
              </span>
              <input
                id="login-email"
                name="email"
                type="email"
                inputmode="email"
                autocomplete="email"
                required
                placeholder=" "
                class="auth-input w-full rounded-xl border pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-blue-500/30" aria-describedby="email-error"
              >
            </div>
            <p id="email-error" class="text-sm mt-1 min-h-[1.25rem]" role="alert" aria-live="polite"></p>
          </div>

          <div class="space-y-2 login-piece login-piece-5">
            <div class="relative float-label-wrap auth-password-wrap" id="login-password-wrap">
              <label for="login-password" class="float-label">Password</label>
              <span class="auth-input-icon-wrap absolute left-0 top-0 bottom-0 flex items-center justify-center input-icon">
                <i class="bi bi-lock-fill text-lg" aria-hidden="true"></i>
              </span>
              <input
                id="login-password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
                placeholder=" "
                class="auth-input w-full rounded-xl border pl-11 pr-12 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-blue-500/30" aria-describedby="password-error"
              >
              <button
                type="button"
                id="toggle-password"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1F58C3]/40 rounded-lg p-1.5 transition-colors auth-toggle-password"
                aria-label="Show password"
                aria-pressed="false"
                title="Show password"
              >
                <i id="toggle-password-icon" class="bi bi-eye-fill text-lg" aria-hidden="true"></i>
              </button>
            </div>
            <p id="password-error" class="text-red-600 text-sm mt-1 min-h-[1.25rem]" role="alert" aria-live="polite"></p>
            <div class="login-password-actions">
              <p class="login-security-hint text-xs">
                <i class="bi bi-shield-lock text-slate-500" aria-hidden="true"></i>
                <span>Secure sign-in. We never share your data.</span>
              </p>
              <a href="forgot_password" class="login-forgot-link text-xs font-medium">Forgot password?</a>
            </div>
          </div>

          <div class="flex items-start gap-3 login-piece login-piece-5b">
            <input type="checkbox" id="login-remember" name="remember_me" value="1" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#1F58C3] focus:ring-[#1F58C3]/50" aria-describedby="login-remember-hint">
            <label for="login-remember" class="flex flex-col gap-0.5 cursor-pointer">
              <span class="text-sm font-semibold text-gray-700">Remember me</span>
              <span id="login-remember-hint" class="text-xs text-gray-500">Stay signed in for 30 days — next visit, skip the login form (no password needed)</span>
            </label>
          </div>

          <button
            type="submit"
            name="login"
            id="login-submit"
            class="btn-shine w-full inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3.5 text-sm transition-all duration-200 disabled:opacity-70 disabled:cursor-not-allowed login-piece login-piece-6"
          >
            <span id="login-submit-text">Login</span>
            <span id="login-submit-spinner" class="hidden" aria-hidden="true"><i class="bi bi-arrow-repeat animate-spin text-lg"></i></span>
            <i id="login-submit-arrow" class="hidden" aria-hidden="true"></i>
          </button>

          <div class="flex items-center gap-3 or-divider login-piece login-piece-7">
            <span class="h-px flex-1"></span>
            <span>OR</span>
            <span class="h-px flex-1"></span>
          </div>

          <div class="login-piece login-piece-8">
            <div class="login-social-actions">
              <a href="google_auth" class="login-google-btn w-full inline-flex items-center justify-center gap-2 no-underline" aria-label="Continue with Google">
                <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" alt="" class="login-google-icon w-4 h-4" aria-hidden="true">
                <span>Google Sign-In</span>
              </a>
              <a href="request_magic_link" class="login-magic-btn w-full inline-flex items-center justify-center gap-2 no-underline" aria-label="Email me a sign-in link">
                <i class="bi bi-envelope-paper-fill" aria-hidden="true"></i>
                <span>Sign-in Link</span>
              </a>
            </div>
          </div>
        </form>
        </div>
      </div>
    </div>
    </div>
    <footer class="login-footer-copy text-center shrink-0">
      © Copyright 2026 LCRC eReview. All rights reserved. · Built for aspiring CPAs
    </footer>
  </div>
  <div id="login-loading" class="login-loading-backdrop">
    <div class="login-loading-stack">
      <div class="login-loading-orb">
        <div class="login-loading-orb-inner">
          <span></span>
        </div>
      </div>
      <div class="login-loading-label">Signing you in...</div>
    </div>
  </div>
  <div id="login-error-modal" class="login-error-backdrop">
    <div class="login-error-card">
      <div class="login-error-icon">
        <div class="login-error-circle">
          <span class="login-error-line login-error-line-1"></span>
          <span class="login-error-line login-error-line-2"></span>
        </div>
      </div>
      <h2 id="login-error-title" class="text-base font-semibold mb-1.5 text-gray-100 login-error-title">Incorrect credentials</h2>
      <p class="text-xs text-gray-300 mb-2 login-error-text" id="login-error-message" role="alert" aria-live="assertive">
        The email or password you entered is incorrect. Please check your credentials and try again.
      </p>
      <div id="login-error-hint" class="text-xs text-gray-400 mb-4">Check your email and password, or <a href="forgot_password" id="login-error-forgot-link" class="text-amber-400 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 rounded">reset your password</a>.</div>
      <button id="login-error-close" class="btn-shine inline-flex items-center justify-center px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-[#1F58C3] shadow-md shadow-[#1F58C3]/30 transition-all duration-200 hover:bg-[#1E40AF] hover:-translate-y-0.5 active:translate-y-0">
        <span>OK, try again</span>
      </button>
    </div>
  </div>
  <?php if ($showRecaptcha): ?>
  <script src="https://www.google.com/recaptcha/api.js?render=<?php echo h($recaptchaSiteKey); ?>" async defer></script>
  <?php endif; ?>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const form = document.getElementById('login-form');
      const emailInput = document.getElementById('login-email');
      const passwordInput = document.getElementById('login-password');
      const emailError = document.getElementById('email-error');
      const passwordError = document.getElementById('password-error');
      const togglePasswordButton = document.getElementById('toggle-password');
      const togglePasswordIcon = document.getElementById('toggle-password-icon');
      const loadingOverlay = document.getElementById('login-loading');
      const errorModal = document.getElementById('login-error-modal');
      const errorClose = document.getElementById('login-error-close');
      const errorMessage = document.getElementById('login-error-message');
      const loginCard = document.querySelector('.login-card');
      const submitBtn = document.getElementById('login-submit');
      const submitText = document.getElementById('login-submit-text');
      const submitSpinner = document.getElementById('login-submit-spinner');
      const submitArrow = document.getElementById('login-submit-arrow');

      function setInputState(input, isValid, errorEl, message) {
        if (isValid) {
          input.classList.remove('border-red-500', 'focus:ring-red-500/40');
          input.classList.add('border-gray-200', 'focus:border-[#1F58C3]', 'focus:ring-[#1F58C3]/20');
          input.setAttribute('aria-invalid', 'false');
          if (errorEl) errorEl.textContent = '';
        } else {
          input.classList.add('border-red-500', 'focus:ring-red-500/40');
          input.classList.remove('border-gray-200', 'focus:border-[#1F58C3]', 'focus:ring-[#1F58C3]/20');
          input.setAttribute('aria-invalid', 'true');
          if (errorEl) errorEl.textContent = message || '';
        }
      }

      function validateEmail() {
        const email = emailInput.value.trim();
        if (!email) {
          setInputState(emailInput, false, emailError, 'Please enter your email.');
          return false;
        }
        var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!re.test(email)) {
          setInputState(emailInput, false, emailError, 'Please enter a valid email address.');
          return false;
        }
        setInputState(emailInput, true, emailError);
        return true;
      }

      function validatePassword() {
        const password = passwordInput.value;
        if (!password) {
          setInputState(passwordInput, false, passwordError, 'Please enter your password.');
          return false;
        }
        setInputState(passwordInput, true, passwordError);
        return true;
      }

      function updateFloatLabel(wrap, input) {
        if (!wrap || !input) return;
        var hasVal = input.value.trim() !== '';
        var isFocused = document.activeElement === input;
        wrap.classList.toggle('has-value', hasVal);
        wrap.classList.toggle('focused', isFocused);
      }
      var emailWrap = document.getElementById('login-email-wrap');
      var passwordWrap = document.getElementById('login-password-wrap');
      [emailInput, passwordInput].forEach(function (input) {
        var wrap = input.id === 'login-email' ? emailWrap : passwordWrap;
        input.addEventListener('focus', function () { updateFloatLabel(wrap, input); });
        input.addEventListener('blur', function () { updateFloatLabel(wrap, input); });
        input.addEventListener('input', function () { updateFloatLabel(wrap, input); });
        input.addEventListener('change', function () { updateFloatLabel(wrap, input); });
        // Chrome autofill often skips input/change; animationstart fires on -webkit-autofill
        input.addEventListener('animationstart', function (e) {
          if (e.animationName === 'login-autofill-on') updateFloatLabel(wrap, input);
        });
      });
      updateFloatLabel(emailWrap, emailInput);
      updateFloatLabel(passwordWrap, passwordInput);
      // Autofill can land after first paint
      [50, 200, 500, 1000].forEach(function (ms) {
        setTimeout(function () {
          updateFloatLabel(emailWrap, emailInput);
          updateFloatLabel(passwordWrap, passwordInput);
        }, ms);
      });

      try {
        var lastEmail = localStorage.getItem('lcreview_last_email');
        if (lastEmail && emailInput && !emailInput.value.trim()) {
          emailInput.value = lastEmail;
          updateFloatLabel(emailWrap, emailInput);
        }
      } catch (err) {}

      emailInput.addEventListener('input', function () {
        if (emailError && emailError.textContent) validateEmail();
      });
      emailInput.addEventListener('blur', function () {
        if (emailInput.value.trim()) validateEmail();
      });
      passwordInput.addEventListener('input', function () {
        if (passwordError && passwordError.textContent) validatePassword();
      });

      if (togglePasswordButton && togglePasswordIcon && passwordInput) {
        function updateToggleState() {
          const isPassword = passwordInput.type === 'password';
          togglePasswordIcon.classList.toggle('bi-eye-fill', !isPassword);
          togglePasswordIcon.classList.toggle('bi-eye-slash-fill', isPassword);
          togglePasswordButton.setAttribute('aria-label', isPassword ? 'Show password' : 'Hide password');
          togglePasswordButton.setAttribute('aria-pressed', isPassword ? 'false' : 'true');
          togglePasswordButton.setAttribute('title', isPassword ? 'Show password' : 'Hide password');
        }
        togglePasswordButton.addEventListener('click', function () {
          const isPassword = passwordInput.type === 'password';
          passwordInput.type = isPassword ? 'text' : 'password';
          updateToggleState();
        });
        togglePasswordButton.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            togglePasswordButton.click();
          }
        });
      }

      form.addEventListener('submit', function (event) {
        var isEmailValid = validateEmail();
        var isPasswordValid = validatePassword();
        if (!isEmailValid || !isPasswordValid) {
          event.preventDefault();
          (emailInput.value.trim() ? passwordInput : emailInput).focus();
          return;
        }
        var recaptchaRequired = form.dataset.recaptcha === '1';
        var recaptchaInput = document.getElementById('g-recaptcha-response');
        if (recaptchaRequired && recaptchaInput && !recaptchaInput.value) {
          event.preventDefault();
          var siteKey = form.dataset.recaptchaKey || '';
          function doSubmitWithToken() {
            if (typeof grecaptcha === 'undefined' || !grecaptcha.execute) {
              if (submitText) submitText.textContent = 'Login';
              alert('Security check is loading. Please wait a moment and try again.');
              return;
            }
            grecaptcha.execute(siteKey, { action: 'login' }).then(function (token) {
              recaptchaInput.value = token;
              submitBtn.disabled = true;
              submitBtn.setAttribute('aria-busy', 'true');
              if (submitText) submitText.textContent = 'Signing in…';
              if (submitSpinner) submitSpinner.classList.remove('hidden');
              if (submitArrow) submitArrow.classList.add('hidden');
              if (loadingOverlay) loadingOverlay.classList.add('is-active');
              try { var e = emailInput.value.trim(); if (e) localStorage.setItem('lcreview_last_email', e); } catch (err) {}
              form.submit();
            }, function () {
              if (submitText) submitText.textContent = 'Login';
              alert('Security check failed. Please try again.');
            });
          }
          if (typeof grecaptcha !== 'undefined' && grecaptcha.ready) {
            grecaptcha.ready(doSubmitWithToken);
          } else {
            window.addEventListener('load', function () { doSubmitWithToken(); });
          }
          return;
        }
        submitBtn.disabled = true;
        submitBtn.setAttribute('aria-busy', 'true');
        if (submitText) submitText.textContent = 'Signing in…';
        if (submitSpinner) submitSpinner.classList.remove('hidden');
        if (submitArrow) submitArrow.classList.add('hidden');
        if (loadingOverlay) loadingOverlay.classList.add('is-active');
        try {
          var e = emailInput.value.trim();
          if (e) localStorage.setItem('lcreview_last_email', e);
        } catch (err) {}
      });

      var hasServerError = document.body.dataset.loginError === '1';
      var serverErrorMessage = document.body.dataset.loginErrorMessage || '';
      var serverErrorTitle = document.body.dataset.loginErrorTitle || 'Incorrect credentials';
      var serverErrorType = (document.body.dataset.loginErrorType || '').trim();
      var defaultErrorMessage = errorMessage ? errorMessage.textContent.trim() : '';
      var errorHint = document.getElementById('login-error-hint');

      window.loginDebug = function () {
        var m = document.getElementById('login-error-modal');
        var backdrop = document.querySelector('.login-error-backdrop');
        var card = document.querySelector('.login-error-card');
        var info = {
          page: 'login',
          bodyData: {
            loginError: document.body.dataset.loginError,
            loginErrorMessage: (document.body.dataset.loginErrorMessage || '').substring(0, 80),
            loginErrorTitle: document.body.dataset.loginErrorTitle || '',
            loginErrorType: document.body.dataset.loginErrorType || '',
            rateLimitUntil: document.body.dataset.rateLimitUntil || ''
          },
          shouldShowModal: hasServerError,
          modal: {
            found: !!m,
            hasActiveClass: m ? m.classList.contains('is-active') : false,
            display: m ? (window.getComputedStyle(m).display) : 'N/A',
            visibility: m ? (window.getComputedStyle(m).visibility) : 'N/A',
            opacity: backdrop ? (window.getComputedStyle(backdrop).opacity) : 'N/A'
          },
          referrer: document.referrer || '(none)'
        };
        console.group('LCRC Login Debug');
        console.log('Summary:', info);
        console.log('Paste this in console to re-run: loginDebug()');
        console.groupEnd();
        return info;
      };

      if (hasServerError) {
        var titleEl = document.getElementById('login-error-title');
        if (titleEl) titleEl.textContent = serverErrorTitle;
        if (errorMessage) {
          var normalizedMessage = serverErrorMessage.trim();
          errorMessage.textContent = normalizedMessage || defaultErrorMessage || 'The email or password you entered is incorrect. Please check your credentials and try again.';
        }
        if (errorHint) {
          if (serverErrorType === 'google_no_account') {
            errorHint.innerHTML = 'Create an account with your email first, then you can <a href="google_auth" class="text-amber-400 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 rounded">sign in with Google</a>. <a href="registration" class="text-amber-400 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 rounded ml-1">Register here</a>.';
          } else if (serverErrorType === 'not_approved') {
            errorHint.textContent = 'An admin must approve your account before you can sign in. Please try again later or contact support.';
          } else if (serverErrorType === 'exam_session_active') {
            errorHint.textContent = 'Finish or submit the exam in the browser where you started it, or log out there first. Starting the same exam on two devices is not allowed.';
          } else {
            errorHint.innerHTML = 'Check your email and password, or <a href="forgot_password" id="login-error-forgot-link" class="text-amber-400 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 rounded">reset your password</a>.';
          }
        }
        if (errorModal) errorModal.classList.add('is-active');
        if (loginCard) {
          loginCard.classList.remove('login-card--shake');
          void loginCard.offsetWidth;
          loginCard.classList.add('login-card--shake');
          setTimeout(function () { loginCard.classList.remove('login-card--shake'); }, 460);
        }
        setTimeout(function () {
          if (errorClose) errorClose.focus();
        }, 120);
      }

      if (typeof window.loginDebug === 'function') {
        setTimeout(window.loginDebug, 200);
      }

      if (errorClose && errorModal) {
        errorClose.addEventListener('click', function () {
          errorModal.classList.remove('is-active');
          if (loadingOverlay) loadingOverlay.classList.remove('is-active');
          if (emailInput) emailInput.focus();
        });
        var modalFocusables = errorModal ? [].slice.call(errorModal.querySelectorAll('button, [href]')).filter(function (el) { return el.getAttribute('tabindex') !== '-1' && !el.disabled; }) : [];
        errorModal.addEventListener('keydown', function (e) {
          if (e.key !== 'Tab' || !errorModal.classList.contains('is-active')) return;
          var first = modalFocusables[0];
          var last = modalFocusables[modalFocusables.length - 1];
          if (e.shiftKey) {
            if (document.activeElement === first) { e.preventDefault(); last.focus(); }
          } else {
            if (document.activeElement === last) { e.preventDefault(); first.focus(); }
          }
        });
      }

      // Rate limit countdown
      var rateLimitUntil = document.body.getAttribute('data-rate-limit-until');
      if (rateLimitUntil) {
        var untilTs = parseInt(rateLimitUntil, 10) * 1000;
        var countdownEl = document.getElementById('login-ratelimit-countdown');
        function updateCountdown() {
          var now = Date.now();
          var rem = Math.max(0, Math.floor((untilTs - now) / 1000));
          if (rem <= 0) {
            if (window.loginRateLimitTimer) clearInterval(window.loginRateLimitTimer);
            if (countdownEl) countdownEl.textContent = 'You can try again now';
            setTimeout(function () { window.location.reload(); }, 800);
            return;
          }
          var m = Math.floor(rem / 60);
          var s = rem % 60;
          if (countdownEl) countdownEl.textContent = m + ' min ' + s + ' sec';
        }
        updateCountdown();
        window.loginRateLimitTimer = setInterval(updateCountdown, 1000);
      }
    });
  </script>
</body>
</html>
