<?php
require_once dirname(__DIR__) . '/session_config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/college_email_verification.php';

$pageTitle = 'Resend college verification';
$csrf = generateCSRFToken();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $result = college_public_resend_verification($conn, (string) ($_POST['email'] ?? ''));
        if (empty($result['ok'])) {
            $error = (string) ($result['error'] ?? COLLEGE_PUBLIC_UNAVAILABLE);
        } else {
            $success = COLLEGE_PUBLIC_GENERIC_VERIFY;
        }
    }
}

$regCssFile = dirname(__DIR__) . '/assets/css/college-student-registration.css';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/head_public.php'; ?>
  <?php if (is_file($regCssFile)): ?>
  <link rel="stylesheet" href="assets/css/<?php echo h(basename($regCssFile)); ?>?v=<?php echo filemtime($regCssFile); ?>">
  <?php endif; ?>
</head>
<body class="auth-page college-student-registration min-h-screen font-sans antialiased">
  <div class="reg-frame8-layout">
    <div class="reg-frame8-right" style="margin:0 auto;max-width:32rem;">
      <header class="reg-frame8-header">
        <span class="reg-frame8-brand"><span class="blue">LCRC</span> <span class="amber">eReview</span></span>
      </header>
      <main class="reg-frame8-main">
        <h1 class="reg-frame8-title">Resend verification</h1>
        <p class="reg-frame8-subtitle">College Examination only. A new link invalidates the previous one.</p>
        <?php if ($success): ?>
          <div class="auth-alert auth-alert--success mb-4" role="status" style="background:rgba(220,252,231,0.95);border-color:rgba(34,197,94,0.35);border-left-color:#22c55e;color:#166534;">
            <i class="auth-alert-icon bi bi-check-circle-fill" aria-hidden="true"></i>
            <span class="auth-alert-text"><?php echo h($success); ?></span>
          </div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="auth-alert auth-alert--error mb-4" role="alert">
            <i class="auth-alert-icon bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span class="auth-alert-text"><?php echo h($error); ?></span>
          </div>
        <?php endif; ?>
        <form method="post" action="<?php echo h(ereview_url('college_resend_verification')); ?>" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <div class="float-label-wrap" data-float-wrap>
            <label class="float-label" for="email">Email address</label>
            <input type="email" name="email" id="email" required placeholder=" " class="auth-input w-full rounded-xl border px-4 py-3 text-sm" value="<?php echo h((string) ($_POST['email'] ?? '')); ?>">
          </div>
          <button type="submit" class="reg-submit w-full rounded-xl px-6 py-3.5 text-sm">Send verification email</button>
        </form>
        <p class="text-center text-xs subtext mt-4">
          <a href="<?php echo h(ereview_url('student_registration')); ?>">Register</a>
          · <a href="login">Login</a>
        </p>
      </main>
    </div>
  </div>
</body>
</html>
