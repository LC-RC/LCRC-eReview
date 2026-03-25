<?php
require_once 'session_config.php';
require_once 'auth.php';
require_once 'db.php';
require_once 'magic_link.php';

if (isLoggedIn() && verifySession()) {
    header('Location: ' . dashboardUrlForRole(getCurrentUserRole()));
    exit;
}

$pageTitle = 'Email sign-in link';
$sent = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $magicUrl = createMagicLinkToken($email);
        if ($magicUrl !== null) {
            sendMagicLinkEmail($email, $magicUrl);
        }
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <?php require_once __DIR__ . '/includes/head_public.php'; ?>
  <?php require_once __DIR__ . '/includes/auth_theme_login_prototype.php'; ?>
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
            <h1 class="text-xl font-bold tracking-tight">Email me a sign-in link</h1>
            <p class="login-value-statement">Enter your email and we'll send you a one-time link to sign in—no password needed.</p>
          </div>

          <?php if ($sent): ?>
            <p class="auth-success-msg text-center mb-4">If an account exists for that address, we've sent a sign-in link. Check your email and use the link to sign in (valid for <?php echo MAGIC_LINK_EXPIRY_MINUTES; ?> minutes).</p>
            <p class="text-center mt-4">
              <a href="login.php" class="auth-back-link text-sm font-semibold">Back to login</a>
            </p>
          <?php else: ?>
            <?php if ($error): ?>
              <div class="auth-alert auth-alert-error">
                <i class="bi bi-exclamation-circle-fill auth-alert-icon" aria-hidden="true"></i>
                <span class="auth-alert-text"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
            <?php endif; ?>
            <form method="POST" action="request_magic_link.php" class="space-y-4">
              <div class="space-y-2">
                <label for="magic-email" class="block text-sm font-medium">Email</label>
                <div class="relative">
                  <span class="auth-input-icon-wrap absolute left-0 top-0 bottom-0 flex items-center justify-center input-icon">
                    <i class="bi bi-envelope-fill text-lg" aria-hidden="true"></i>
                  </span>
                  <input
                    id="magic-email"
                    name="email"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="you@example.com"
                    class="auth-input w-full rounded-xl border pl-11 pr-4 py-3 text-sm"
                  >
                </div>
              </div>
              <button type="submit" class="auth-submit-btn btn-shine w-full">
                Send sign-in link
              </button>
            </form>
            <p class="mt-8 text-center subtext">
              <a href="login.php" class="auth-back-link font-semibold">Back to login</a>
            </p>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <footer class="login-footer-copy text-center shrink-0">
      © Copyright 2026 LCRC eReview. All rights reserved. · Built for aspiring CPAs
    </footer>
  </div>
</body>
</html>
