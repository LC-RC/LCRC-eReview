<?php
/**
 * Post-login success screen: dark modern UI, then redirect to dashboard.
 * Used by both email/password login (login_process) and Google Sign-In (google_callback).
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth.php';

if (!isLoggedIn() || !verifySession()) {
    header('Location: login.php');
    exit;
}

$target = isset($_GET['target']) ? trim($_GET['target']) : '';
$name = isset($_GET['name']) ? trim($_GET['name']) : '';

// Only allow relative paths (no protocol, no //)
if ($target === '' || preg_match('#^https?://#i', $target) || strpos($target, '//') === 0) {
    $role = getCurrentUserRole();
    $target = ($role === 'admin') ? 'admin_dashboard.php' : 'student_dashboard.php';
}
$firstName = $name !== '' ? $name : 'User';

$targetEnc = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
$targetJs = json_encode($target);
$nameEnc = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Signing In... - LCRC eReview</title>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <meta http-equiv="refresh" content="4;url=<?php echo $targetEnc; ?>">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; height: 100%; -webkit-font-smoothing: antialiased; }
    body {
      font-family: 'Nunito', Segoe UI, sans-serif;
      background: #0b1220;
      color: #e5e7eb;
      overflow-x: hidden;
    }
    .auth-success-page {
      position: relative;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      z-index: 1;
    }
    /* Grid background (match login) */
    .auth-success-bg {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
      background-image:
        linear-gradient(rgba(31, 88, 195, 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(31, 88, 195, 0.05) 1px, transparent 1px);
      background-size: 32px 32px;
      animation: auth-success-grid 12s ease-in-out infinite;
    }
    @keyframes auth-success-grid {
      0%, 100% { opacity: 0.6; }
      50% { opacity: 1; }
    }
    /* Floating nodes */
    .auth-success-nodes { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
    .auth-success-node {
      position: absolute;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      animation: auth-success-float 24s ease-in-out infinite;
    }
    .auth-success-node--blue {
      background: rgba(31, 88, 195, 0.35);
      box-shadow: 0 0 12px rgba(31, 88, 195, 0.25);
    }
    .auth-success-node--gold {
      background: rgba(245, 158, 11, 0.3);
      box-shadow: 0 0 10px rgba(245, 158, 11, 0.2);
    }
    @keyframes auth-success-float {
      0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.7; }
      50% { transform: translate(8px, -10px) scale(1.05); opacity: 1; }
    }
    /* Card */
    .auth-success-card {
      position: relative;
      width: 100%;
      max-width: 420px;
      background: linear-gradient(180deg, #111827 0%, #0f172a 100%);
      border: 1px solid rgba(255, 255, 255, 0.06);
      box-shadow: 0 24px 48px -20px rgba(0,0,0,0.55), 0 0 0 1px rgba(31, 88, 195, 0.1), 0 1px 0 rgba(255,255,255,0.03) inset;
      border-radius: 1rem;
      padding: 2rem 1.75rem 2rem;
      text-align: center;
      transform: translateY(12px);
      opacity: 0;
      animation: auth-success-card-in 0.5s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    }
    @keyframes auth-success-card-in {
      to { transform: translateY(0); opacity: 1; }
    }
    .auth-success-logo {
      display: block;
      margin: 0 auto 1.25rem;
      height: 2.5rem;
      width: auto;
      max-width: 120px;
      object-fit: contain;
      animation: auth-success-logo-in 0.5s 0.1s cubic-bezier(0.22, 1, 0.36, 1) both;
    }
    @keyframes auth-success-logo-in {
      from { opacity: 0; transform: scale(0.95); }
      to { opacity: 1; transform: scale(1); }
    }
    /* Success icon */
    .auth-success-icon-wrap {
      width: 80px;
      height: 80px;
      margin: 0 auto 1.25rem;
      border-radius: 50%;
      background: radial-gradient(circle at 30% 20%, rgba(34, 197, 94, 0.25), rgba(22, 163, 74, 0.4));
      border: 2px solid rgba(34, 197, 94, 0.35);
      display: flex;
      align-items: center;
      justify-content: center;
      transform: scale(0.6);
      opacity: 0;
      animation: auth-success-icon-pop 0.55s 0.25s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    }
    .auth-success-icon-wrap i {
      font-size: 2.25rem;
      color: #22c55e;
      text-shadow: 0 0 20px rgba(34, 197, 94, 0.4);
    }
    @keyframes auth-success-icon-pop {
      0% { transform: scale(0.6); opacity: 0; }
      70% { transform: scale(1.08); opacity: 1; }
      100% { transform: scale(1); opacity: 1; }
    }
    .auth-success-title {
      font-size: 1.5rem;
      font-weight: 800;
      color: #fff;
      letter-spacing: -0.02em;
      margin: 0 0 0.35rem;
      animation: auth-success-text-in 0.4s 0.4s both;
    }
    .auth-success-subtitle {
      font-size: 0.9rem;
      font-weight: 600;
      color: #1F58C3;
      margin: 0 0 1.5rem;
      animation: auth-success-text-in 0.4s 0.5s both;
    }
    .auth-success-progress {
      height: 6px;
      background: rgba(15, 23, 42, 0.8);
      border-radius: 999px;
      overflow: hidden;
      margin-bottom: 1rem;
      animation: auth-success-text-in 0.4s 0.55s both;
    }
    .auth-success-progress-bar {
      position: relative;
      height: 100%;
      width: 0;
      background: linear-gradient(90deg, #1F58C3, #F59E0B);
      border-radius: 999px;
      box-shadow: 0 0 12px rgba(245, 158, 11, 0.35);
      animation: auth-success-bar 2s 0.6s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    }
    .auth-success-progress-bar::after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
      transform: translateX(-100%);
      animation: auth-success-shimmer 1.2s ease-in-out infinite;
    }
    @keyframes auth-success-bar {
      to { width: 100%; }
    }
    @keyframes auth-success-shimmer {
      0% { transform: translateX(-100%); }
      60% { transform: translateX(100%); }
      100% { transform: translateX(100%); }
    }
    .auth-success-note {
      font-size: 0.8rem;
      font-weight: 700;
      color: #F59E0B;
      margin: 0 0 0.25rem;
      animation: auth-success-text-in 0.4s 0.65s both;
    }
    .auth-success-redirect {
      font-size: 0.75rem;
      color: #94a3b8;
      margin: 0;
      animation: auth-success-text-in 0.4s 0.7s both;
    }
    @keyframes auth-success-text-in {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body>
  <div class="auth-success-bg" aria-hidden="true"></div>
  <div class="auth-success-nodes" aria-hidden="true">
    <span class="auth-success-node auth-success-node--blue" style="left:12%;top:18%;animation-delay:0s"></span>
    <span class="auth-success-node auth-success-node--blue" style="left:88%;top:22%;animation-delay:3s"></span>
    <span class="auth-success-node auth-success-node--gold" style="left:8%;top:55%;animation-delay:1s"></span>
    <span class="auth-success-node auth-success-node--gold" style="left:92%;top:45%;animation-delay:4s"></span>
  </div>
  <div class="auth-success-page">
    <div class="auth-success-card">
      <img src="image%20assets/lms-logo.png" alt="LCRC eReview" class="auth-success-logo" width="120" height="48" loading="eager">
      <div class="auth-success-icon-wrap">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
      </div>
      <h1 class="auth-success-title">Welcome back, <?php echo $nameEnc; ?>!</h1>
      <p class="auth-success-subtitle">Signing you in to LCRC eReview</p>
      <div class="auth-success-progress">
        <div class="auth-success-progress-bar"></div>
      </div>
      <p class="auth-success-note">Authentication successful</p>
      <p class="auth-success-redirect">Redirecting...</p>
    </div>
  </div>
  <script>
    setTimeout(function () { window.location.href = <?php echo $targetJs; ?>; }, 2200);
  </script>
</body>
</html>
