<?php
require_once 'session_config.php';
require_once 'auth.php';
require_once 'db.php';
require_once 'password_reset.php';

if (isLoggedIn() && verifySession()) {
    header('Location: ' . (getCurrentUserRole() === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php'));
    exit;
}

$pageTitle = 'Forgot Password';
$error = $_SESSION['error'] ?? null;
unset($_SESSION['error']);

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Please enter your email address.']);
            exit;
        }
        $error = 'Please enter your email address.';
    } else {
        $resetUrl = createPasswordResetToken($email);
        if ($resetUrl !== null) {
            sendPasswordResetEmail($email, $resetUrl);
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => true, 'message' => 'If an account exists for that email, we\'ve sent a reset link.']);
            exit;
        }
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
            <h1 class="text-xl font-bold tracking-tight">Forgot password?</h1>
            <p class="login-value-statement">Enter your email and we'll send you a link to reset your password.</p>
          </div>

          <?php if ($error): ?>
            <div class="auth-alert auth-alert-error">
              <i class="bi bi-exclamation-circle-fill auth-alert-icon" aria-hidden="true"></i>
              <span class="auth-alert-text"><?php echo h($error); ?></span>
            </div>
          <?php endif; ?>
          <form method="POST" action="forgot_password.php" class="space-y-4" id="forgot-form">
            <div class="space-y-2">
              <label for="forgot-email" class="block text-sm font-medium">Email</label>
              <div class="relative">
                <span class="auth-input-icon-wrap absolute left-0 top-0 bottom-0 flex items-center justify-center input-icon">
                  <i class="bi bi-envelope-fill text-lg" aria-hidden="true"></i>
                </span>
                <input
                  id="forgot-email"
                  name="email"
                  type="email"
                  required
                  autocomplete="email"
                  placeholder="you@example.com"
                  class="auth-input w-full rounded-xl border pl-11 pr-4 py-3 text-sm"
                >
              </div>
            </div>
            <button type="submit" class="auth-submit-btn btn-shine w-full" id="forgot-submit-btn">
              Send reset link
            </button>
          </form>
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

  <div id="forgot-loading" class="login-loading-backdrop" aria-hidden="true">
    <div class="login-loading-stack">
      <div class="login-loading-orb">
        <div class="login-loading-orb-inner">
          <span></span>
        </div>
      </div>
      <div class="login-loading-label">Sending reset password link to your email</div>
    </div>
  </div>

  <div id="forgot-success-modal" class="login-loading-backdrop" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="forgot-success-title">
    <div class="forgot-success-card" onclick="event.stopPropagation()">
      <div class="forgot-success-check-wrap">
        <svg class="forgot-success-check" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <circle class="forgot-success-circle" cx="26" cy="26" r="25" fill="none" stroke-width="2"/>
          <path class="forgot-success-path" fill="none" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" d="M14 27 l8 8 16-18"/>
        </svg>
      </div>
      <h2 id="forgot-success-title" class="forgot-success-title">Check your email</h2>
      <p class="forgot-success-text">If an account exists for that email, we've sent a link to reset your password.</p>
      <a href="login.php" class="forgot-success-btn btn-shine inline-flex items-center justify-center px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-[#1F58C3]">Back to login</a>
    </div>
  </div>

  <style>
    .login-loading-stack { display: flex; flex-direction: column; align-items: center; gap: 1rem; }
    .login-loading-label { font-size: 0.9rem; font-weight: 600; color: #e2e8f0; letter-spacing: 0.01em; }
    .forgot-success-card {
      max-width: 380px; width: 100%; border-radius: 1.25rem; padding: 2rem 1.75rem;
      background: linear-gradient(180deg, #111827 0%, #0f172a 100%);
      border: 1px solid rgba(255,255,255,0.06);
      box-shadow: 0 24px 48px -20px rgba(0,0,0,0.5), 0 0 0 1px rgba(34, 197, 94, 0.15);
      text-align: center;
      transform: translateY(12px) scale(0.96); opacity: 0;
      transition: transform 0.3s ease-out, opacity 0.3s ease-out;
    }
    #forgot-success-modal.is-active .forgot-success-card { transform: translateY(0) scale(1); opacity: 1; }
    .forgot-success-check-wrap { width: 72px; height: 72px; margin: 0 auto 1.25rem; }
    .forgot-success-check { width: 100%; height: 100%; }
    .forgot-success-circle { stroke: rgba(34, 197, 94, 0.3); stroke-dasharray: 166; stroke-dashoffset: 166; animation: forgot-check-circle 0.5s ease-out 0.2s forwards; }
    .forgot-success-path { stroke: #22c55e; stroke-dasharray: 48; stroke-dashoffset: 48; animation: forgot-check-path 0.35s ease-out 0.5s forwards; }
    @keyframes forgot-check-circle { to { stroke-dashoffset: 0; } }
    @keyframes forgot-check-path { to { stroke-dashoffset: 0; } }
    .forgot-success-title { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0 0 0.5rem; }
    .forgot-success-text { color: #94a3b8; font-size: 0.875rem; line-height: 1.5; margin: 0 0 1.5rem; }
    .forgot-success-btn { text-decoration: none; transition: background 0.2s, transform 0.2s; }
    .forgot-success-btn:hover { background: #1E40AF !important; transform: translateY(-2px); }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var form = document.getElementById('forgot-form');
      var loadingEl = document.getElementById('forgot-loading');
      var successModal = document.getElementById('forgot-success-modal');
      var submitBtn = document.getElementById('forgot-submit-btn');
      if (!form || !loadingEl || !successModal) return;

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var email = (document.getElementById('forgot-email') || {}).value;
        if (!email || !email.trim()) return;

        loadingEl.classList.add('is-active');
        loadingEl.setAttribute('aria-hidden', 'false');

        var fd = new FormData(form);

        fetch(form.action, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            loadingEl.classList.remove('is-active');
            loadingEl.setAttribute('aria-hidden', 'true');
            if (data && data.success) {
              successModal.classList.add('is-active');
              successModal.setAttribute('aria-hidden', 'false');
            } else {
              alert(data && data.error ? data.error : 'Something went wrong. Please try again.');
            }
          })
          .catch(function () {
            loadingEl.classList.remove('is-active');
            loadingEl.setAttribute('aria-hidden', 'true');
            alert('Network error. Please try again.');
          });
      });

      function closeSuccessModal() {
        successModal.classList.remove('is-active');
        successModal.setAttribute('aria-hidden', 'true');
      }
      document.querySelector('.forgot-success-btn') && document.querySelector('.forgot-success-btn').addEventListener('click', closeSuccessModal);
      successModal.addEventListener('click', function (ev) {
        if (ev.target === successModal) closeSuccessModal();
      });
      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && successModal.classList.contains('is-active')) closeSuccessModal();
      });
    });
  </script>
</body>
</html>
