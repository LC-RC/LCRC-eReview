<?php
require_once dirname(__DIR__) . '/session_config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/college_password_reset.php';

$pageTitle = 'Set College password';
$csrf = generateCSRFToken();
$tokenRaw = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenRaw = trim((string) ($_POST['token'] ?? ''));
} else {
    $tokenRaw = trim((string) ($_GET['token'] ?? ''));
}
$error = null;
$done = false;
$peek = college_public_peek_reset_token($conn, $tokenRaw);
$tokenOk = !empty($peek['ok']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $result = college_public_complete_password_reset(
            $conn,
            $tokenRaw,
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        if (!empty($result['ok'])) {
            $done = true;
            $tokenOk = false;
        } else {
            $error = (string) ($result['error'] ?? COLLEGE_RESET_INVALID);
            $peek = college_public_peek_reset_token($conn, $tokenRaw);
            $tokenOk = !empty($peek['ok']);
        }
    }
} elseif (!$tokenOk) {
    $error = COLLEGE_RESET_INVALID;
}

$regCssFile = dirname(__DIR__) . '/assets/css/college-student-registration.css';
$regJsFile = dirname(__DIR__) . '/assets/js/college-student-registration.js';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/head_public.php'; ?>
  <?php if (is_file($regCssFile)): ?>
  <link rel="stylesheet" href="assets/css/<?php echo h(basename($regCssFile)); ?>?v=<?php echo filemtime($regCssFile); ?>">
  <?php endif; ?>
</head>
<body class="auth-page college-student-registration registration-prototype min-h-screen font-sans antialiased">
  <div class="reg-frame8-layout">
    <div class="reg-frame8-right" style="margin:0 auto;max-width:32rem;width:100%;">
      <header class="reg-frame8-header">
        <span class="reg-frame8-brand"><span class="blue">LCRC</span> <span class="amber">eReview</span></span>
        <img src="image%20assets/lcrc-logo-reg.png" alt="LCRC Review School &amp; Training Center" class="reg-frame8-logo-right" width="140" height="36" loading="eager" decoding="async">
      </header>
      <main class="reg-frame8-main">
        <h1 class="reg-frame8-title"><?php echo $done ? 'Password updated' : 'Set a new password'; ?></h1>
        <?php if ($done): ?>
          <div class="auth-alert auth-alert--success mb-4" role="status" style="background:rgba(220,252,231,0.95);border-color:rgba(34,197,94,0.35);border-left-color:#22c55e;color:#166534;">
            <i class="auth-alert-icon bi bi-check-circle-fill" aria-hidden="true"></i>
            <span class="auth-alert-text">Your College Examination password is updated.</span>
          </div>
          <p class="text-sm"><a href="login">Continue to login</a></p>
        <?php else: ?>
          <?php if ($error): ?>
            <div class="auth-alert auth-alert--error mb-4" role="alert">
              <i class="auth-alert-icon bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
              <span class="auth-alert-text"><?php echo h($error); ?></span>
            </div>
          <?php endif; ?>
          <?php if ($tokenOk): ?>
            <p class="reg-frame8-subtitle">College Examination only. Choose a new password for this account.</p>
            <form method="post" action="<?php echo h(ereview_url('college_reset_password')); ?>" class="space-y-4" id="reg-form">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="token" value="<?php echo h($tokenRaw); ?>">
              <p class="reg-security-subheading">Password must be at least 8 characters and include uppercase, lowercase, a number, and a symbol.</p>
              <div class="space-y-2">
                <div class="float-label-wrap auth-password-wrap" data-float-wrap>
                  <label class="float-label" for="csr-password">New password</label>
                  <input type="password" name="password" id="csr-password" required minlength="8" placeholder=" " class="auth-input w-full rounded-xl border px-4 pr-12 py-3 text-sm" autocomplete="new-password">
                  <button type="button" id="csr-toggle-password" class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 transition-colors" aria-label="Show password">
                    <i class="bi bi-eye-fill text-lg"></i>
                  </button>
                </div>
                <div class="reg-pw-strength-wrap" aria-live="polite">
                  <div class="reg-pw-strength-bar" role="progressbar" aria-valuemin="0" aria-valuemax="12" aria-label="Password strength">
                    <div class="reg-pw-strength-fill" id="csr-pw-strength-fill"></div>
                  </div>
                  <p class="reg-pw-strength-label" id="csr-pw-strength-label">—</p>
                </div>
                <p class="reg-pw-checklist-heading">Password requirements</p>
                <div class="reg-pw-checklist">
                  <div class="reg-pw-check-item" id="csr-pw-check-length"><i class="bi bi-circle" aria-hidden="true"></i><span>At least 8 characters</span></div>
                  <div class="reg-pw-check-item" id="csr-pw-check-upper"><i class="bi bi-circle" aria-hidden="true"></i><span>One uppercase letter</span></div>
                  <div class="reg-pw-check-item" id="csr-pw-check-lower"><i class="bi bi-circle" aria-hidden="true"></i><span>One lowercase letter</span></div>
                  <div class="reg-pw-check-item" id="csr-pw-check-number"><i class="bi bi-circle" aria-hidden="true"></i><span>One number</span></div>
                  <div class="reg-pw-check-item" id="csr-pw-check-symbol"><i class="bi bi-circle" aria-hidden="true"></i><span>One symbol</span></div>
                </div>
                <div class="float-label-wrap auth-password-wrap" data-float-wrap>
                  <label class="float-label" for="csr-password-confirm">Confirm password</label>
                  <input type="password" name="confirm_password" id="csr-password-confirm" required minlength="8" placeholder=" " class="auth-input w-full rounded-xl border px-4 pr-12 py-3 text-sm" autocomplete="new-password">
                  <button type="button" id="csr-toggle-password-confirm" class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 transition-colors" aria-label="Show confirm password">
                    <i class="bi bi-eye-fill text-lg"></i>
                  </button>
                </div>
                <p class="reg-confirm-error hidden" id="csr-confirm-error" role="alert"></p>
                <p class="reg-confirm-success hidden" id="csr-confirm-success" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Passwords match</p>
              </div>
              <button type="submit" class="reg-submit w-full rounded-xl px-6 py-3 text-sm" id="csr-submit-btn">Update password</button>
            </form>
          <?php else: ?>
            <p class="text-sm"><a href="<?php echo h(ereview_url('college_forgot_password')); ?>">Request a new reset email</a>
              · <a href="login">Login</a></p>
          <?php endif; ?>
        <?php endif; ?>
      </main>
    </div>
  </div>
  <?php if (is_file($regJsFile)): ?>
  <script src="assets/js/<?php echo h(basename($regJsFile)); ?>?v=<?php echo filemtime($regJsFile); ?>" defer></script>
  <?php endif; ?>
</body>
</html>
