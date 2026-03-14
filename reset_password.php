<?php
require_once 'session_config.php';
require_once 'db.php';
require_once 'password_reset.php';
require_once 'auth.php';

if (isLoggedIn() && verifySession()) {
    header('Location: ' . (getCurrentUserRole() === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php'));
    exit;
}

$pageTitle = 'Reset Password';
$error = $_SESSION['error'] ?? null;
unset($_SESSION['error']);

// Get token: on GET use raw query string so token is never lost (XAMPP/some configs leave $_GET empty)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenRaw = trim($_POST['token'] ?? '');
} else {
    $tokenRaw = '';
    if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
        parse_str($_SERVER['QUERY_STRING'], $qs);
        $tokenRaw = isset($qs['token']) ? trim((string) $qs['token']) : '';
    }
    if ($tokenRaw === '') {
        $tokenRaw = trim($_GET['token'] ?? '');
    }
    if ($tokenRaw === '') {
        $tokenRaw = trim($_REQUEST['token'] ?? '');
    }
    // Fallback: parse token from REQUEST_URI when QUERY_STRING/$_GET are empty (e.g. some XAMPP setups)
    if ($tokenRaw === '' && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'token=') !== false) {
        $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        if ($query !== null && $query !== false) {
            parse_str($query, $params);
            $tokenRaw = trim((string) ($params['token'] ?? ''));
        }
    }
}
$pasteLink = trim($_GET['paste_link'] ?? $_POST['paste_link'] ?? '');
if ($pasteLink !== '' && strpos($pasteLink, 'token=') !== false) {
    $query = parse_url($pasteLink, PHP_URL_QUERY);
    if ($query !== null && $query !== false) {
        parse_str($query, $q);
        $extracted = trim($q['token'] ?? '');
        if ($extracted !== '') {
            header('Location: reset_password.php?token=' . rawurlencode($extracted));
            exit;
        }
    }
}
$tokenValid = null;
if ($tokenRaw !== '') {
    $tokenValid = validatePasswordResetToken($tokenRaw);
}
if ($tokenValid !== null) {
    $error = null; // clear stale "invalid" from session when token is valid
}

// POST: set new password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenRaw = trim($_POST['token'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    $tokenValid = $tokenRaw !== '' ? validatePasswordResetToken($tokenRaw) : null;

    if (!$tokenValid) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $password_confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $hashed, $tokenValid['user_id']);
        if (mysqli_stmt_execute($stmt)) {
            deletePasswordResetToken($tokenValid['token_id']);
            mysqli_stmt_close($stmt);
            header('Location: login.php');
            exit;
        }
        mysqli_stmt_close($stmt);
        $error = 'Unable to update password. Please try again or request a new reset link.';
    }
}

$showForm = $tokenValid !== null;
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <?php require_once __DIR__ . '/includes/head_public.php'; ?>
  <?php require_once __DIR__ . '/includes/auth_theme_login_prototype.php'; ?>
  <style>
    body.login-prototype .login-card .auth-password-wrap .auth-input { padding-right: 2.75rem !important; }
    body.login-prototype .login-card #reset-toggle-password {
      position: absolute !important;
      right: 0.5rem !important;
      top: 50% !important;
      transform: translateY(-50%) !important;
      width: 2rem;
      height: 2rem;
      padding: 0 !important;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #9ca3af !important;
      background: transparent !important;
      border: none !important;
      cursor: pointer;
    }
    body.login-prototype .login-card #reset-toggle-password:hover { color: #F59E0B !important; }
  </style>
</head>
<body class="auth-page login-prototype min-h-screen font-sans antialiased">
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
      <span class="login-bg-node login-bg-node--gold" style="--x:50%;--y:12%;--delay:5s;--dur:27s"></span>
      <span class="login-bg-node login-bg-node--white" style="--x:35%;--y:35%;--delay:2s;--dur:24s"></span>
    </div>
    <div class="login-bg-lines">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
        <path class="line line--blue" stroke-dasharray="120 80" d="M0 200 Q300 150 600 200 T1200 200" />
        <path class="line line--gold" stroke-dasharray="80 100" d="M100 0 V800 M500 0 V800 M900 0 V800" />
      </svg>
    </div>
  </div>
  <div class="auth-corner-decor" aria-hidden="true">
    <span class="auth-corner-dot tl"></span><span class="auth-corner-dot tr blue"></span>
    <span class="auth-corner-dot bl blue"></span><span class="auth-corner-dot br"></span>
  </div>
  <div class="login-page-layout min-h-screen flex flex-col relative z-10">
    <div class="flex-1 flex items-center justify-center p-4">
      <div class="login-card-wrap w-full max-w-[400px] mx-auto">
        <div class="login-card">
          <div class="flex flex-col items-center login-header">
            <div class="login-logo-wrap flex items-center justify-center login-logo-hover">
              <img src="image%20assets/lms-logo.png" alt="LCRC eReview" class="login-logo-img" width="120" height="48" loading="eager" decoding="async">
            </div>
            <span class="brand-text"><span class="blue">LCRC</span> <span class="amber">eReview</span></span>
          </div>

          <div class="text-center login-welcome">
            <h1 class="text-xl font-bold tracking-tight">Set new password</h1>
            <p class="login-value-statement">Choose a strong password for your account.</p>
          </div>

          <?php if ($error && !$showForm): ?>
            <div class="auth-alert auth-alert-error">
              <i class="bi bi-exclamation-circle-fill auth-alert-icon" aria-hidden="true"></i>
              <span class="auth-alert-text"><?php echo h($error); ?></span>
            </div>
            <p class="auth-success-msg text-sm mb-3">If you clicked the link from your email, security software may have changed it. Try pasting the <strong>full link</strong> from the email here:</p>
            <form method="post" action="reset_password.php" class="mb-4 space-y-2">
              <input type="text" name="paste_link" placeholder="Paste the full reset link from your email" class="auth-input w-full rounded-xl px-4 py-3 text-sm" value="">
              <button type="submit" class="auth-secondary-btn w-full">Use this link</button>
            </form>
            <div class="flex flex-col gap-3">
              <a href="forgot_password.php" class="auth-submit-btn btn-shine w-full text-center">Request new reset link</a>
              <p class="text-center"><a href="login.php" class="auth-back-link font-semibold">Back to login</a></p>
            </div>
          <?php endif; ?>

          <?php if ($showForm): ?>
            <?php if ($error): ?>
              <div class="auth-alert auth-alert-error">
                <i class="bi bi-exclamation-circle-fill auth-alert-icon" aria-hidden="true"></i>
                <span class="auth-alert-text"><?php echo h($error); ?></span>
              </div>
            <?php endif; ?>
            <form method="POST" action="reset_password.php" class="space-y-4" id="reset-form">
              <input type="hidden" name="token" value="<?php echo h($tokenRaw); ?>">
              <div class="space-y-2">
                <label for="reset-password" class="block text-sm font-medium">New password</label>
                <div class="relative auth-password-wrap">
                  <span class="auth-input-icon-wrap absolute left-0 top-0 bottom-0 flex items-center justify-center input-icon">
                    <i class="bi bi-lock-fill text-lg" aria-hidden="true"></i>
                  </span>
                  <input
                    id="reset-password"
                    name="password"
                    type="password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                    placeholder="At least 8 characters"
                    class="auth-input w-full rounded-xl border pl-11 pr-12 py-3 text-sm"
                  >
                  <button type="button" id="reset-toggle-password" class="absolute" aria-label="Show password" title="Show password">
                    <i class="bi bi-eye-fill text-lg" aria-hidden="true"></i>
                  </button>
                </div>
                <p id="reset-password-error" class="text-red-400 text-sm mt-1 min-h-[1.25rem]" role="alert"></p>
              </div>
              <div class="space-y-2">
                <label for="reset-password-confirm" class="block text-sm font-medium">Confirm password</label>
                <div class="relative">
                  <span class="auth-input-icon-wrap absolute left-0 top-0 bottom-0 flex items-center justify-center input-icon">
                    <i class="bi bi-lock-fill text-lg" aria-hidden="true"></i>
                  </span>
                  <input
                    id="reset-password-confirm"
                    name="password_confirm"
                    type="password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                    placeholder="Repeat your password"
                    class="auth-input w-full rounded-xl border pl-11 pr-4 py-3 text-sm"
                  >
                </div>
                <p id="reset-password-confirm-error" class="text-red-400 text-sm mt-1 min-h-[1.25rem]" role="alert"></p>
              </div>
              <button type="submit" class="auth-submit-btn btn-shine w-full">Reset password</button>
            </form>
          <?php elseif (!$tokenValid && $tokenRaw !== ''): ?>
            <div class="auth-alert auth-alert-error">
              <i class="bi bi-exclamation-circle-fill auth-alert-icon" aria-hidden="true"></i>
              <span class="auth-alert-text">This link is invalid or has expired.</span>
            </div>
            <p class="auth-success-msg text-sm mb-3">If you clicked the link from your email, try pasting the <strong>full link</strong> here:</p>
            <form method="post" action="reset_password.php" class="mb-4 space-y-2">
              <input type="text" name="paste_link" placeholder="Paste the full reset link from your email" class="auth-input w-full rounded-xl px-4 py-3 text-sm" value="">
              <button type="submit" class="auth-secondary-btn w-full">Use this link</button>
            </form>
            <a href="forgot_password.php" class="auth-submit-btn btn-shine w-full text-center block">Request new reset link</a>
          <?php else: ?>
            <a href="forgot_password.php" class="auth-submit-btn btn-shine w-full text-center block">Get reset link</a>
          <?php endif; ?>

          <p class="mt-8 text-center subtext">
            <a href="login.php" class="auth-back-link font-semibold">Back to login</a>
          </p>
        </div>
      </div>
    </div>
    <footer class="login-footer-copy text-center shrink-0">
      © Copyright 2026 LCRC eReview. All rights reserved. · Built for aspiring CPAs
    </footer>
  </div>
  <?php if ($showForm): ?>
  <script>
    document.getElementById('reset-form').addEventListener('submit', function(e) {
      var p = document.getElementById('reset-password').value;
      var c = document.getElementById('reset-password-confirm').value;
      var pe = document.getElementById('reset-password-error');
      var ce = document.getElementById('reset-password-confirm-error');
      pe.textContent = '';
      ce.textContent = '';
      if (p.length < 8) {
        e.preventDefault();
        pe.textContent = 'Password must be at least 8 characters.';
        return;
      }
      if (p !== c) {
        e.preventDefault();
        ce.textContent = 'Passwords do not match.';
        return;
      }
    });
    (function() {
      var toggle = document.getElementById('reset-toggle-password');
      var pw = document.getElementById('reset-password');
      var icon = toggle ? toggle.querySelector('i') : null;
      if (toggle && pw && icon) {
        toggle.addEventListener('click', function() {
          var isPassword = pw.type === 'password';
          pw.type = isPassword ? 'text' : 'password';
          icon.className = isPassword ? 'bi bi-eye-slash-fill text-lg' : 'bi bi-eye-fill text-lg';
          toggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        });
      }
    })();
  </script>
  <?php endif; ?>
  <script>
  (function() {
    function getTokenFromUrl() {
      var params = new URLSearchParams(window.location.search);
      return params.get('token') || '';
    }
    window.runResetTokenDebug = function() {
      var token = getTokenFromUrl();
      if (!token) {
        console.warn('[Reset token debug] No token in URL. Add ?token=... to the reset page URL.');
        return;
      }
      var base = window.location.origin + window.location.pathname.replace(/[^/]+$/, '');
      var url = base + 'debug_reset_token.php?token=' + encodeURIComponent(token);
      console.log('[Reset token debug] Fetching: ' + url);
      fetch(url).then(function(r) { return r.json(); }).then(function(data) {
        console.log('[Reset token debug] Result:', data.result, data.result_reason);
        console.log('[Reset token debug] Steps:', data.step);
        console.log('[Reset token debug] Full response:', data);
      }).catch(function(e) {
        console.error('[Reset token debug] Error:', e);
      });
    };
    if (/[?&]debug=1/.test(window.location.search)) {
      console.log('[Reset token debug] Auto-running (debug=1 in URL)...');
      window.runResetTokenDebug();
    }
  })();
  </script>
</body>
</html>
