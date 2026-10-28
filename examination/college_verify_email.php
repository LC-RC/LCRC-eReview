<?php
require_once dirname(__DIR__) . '/session_config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/college_email_verification.php';

$pageTitle = 'Verify college email';
$csrf = generateCSRFToken();
$result = college_complete_verification($conn, (string) ($_GET['token'] ?? ''));
$ok = !empty($result['ok']);
$studentNumber = $ok ? trim((string) ($result['student_number'] ?? '')) : '';
$mailSent = $ok && !empty($result['number_email_sent']);
$error = $ok ? null : (string) ($result['error'] ?? COLLEGE_VERIFY_INVALID);
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
        <h1 class="reg-frame8-title">Email verification</h1>
        <?php if ($ok): ?>
          <div class="auth-alert auth-alert--success mb-4" role="status" style="background:rgba(220,252,231,0.95);border-color:rgba(34,197,94,0.35);border-left-color:#22c55e;color:#166534;">
            <i class="auth-alert-icon bi bi-check-circle-fill" aria-hidden="true"></i>
            <span class="auth-alert-text">Your College Examination account is verified.</span>
          </div>
          <p class="text-sm mb-2">Your College Exam ID is:</p>
          <p class="text-2xl font-bold tracking-wide mb-4"><?php echo h($studentNumber !== '' ? $studentNumber : 'Assigned on your account'); ?></p>
          <?php if ($mailSent): ?>
            <p class="text-sm mb-4">We also emailed this number to your verified address.</p>
          <?php else: ?>
            <p class="text-sm mb-4">We could not send the student-number email. Your account and number are saved. You can request the number by email without exposing it in the browser.</p>
            <form method="post" action="<?php echo h(ereview_url('college_resend_student_number')); ?>" class="space-y-3 mb-4">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <label class="reg-top-label" for="sn-email">Verified email</label>
              <input type="email" id="sn-email" name="email" required class="auth-input w-full rounded-xl border px-4 py-3 text-sm" value="<?php echo h((string) ($result['email'] ?? '')); ?>">
              <button type="submit" class="reg-submit w-full rounded-xl px-6 py-3 text-sm">Email my College Exam ID</button>
            </form>
          <?php endif; ?>
          <p class="text-sm"><a href="login">Continue to login</a></p>
        <?php else: ?>
          <div class="auth-alert auth-alert--error mb-4" role="alert">
            <i class="auth-alert-icon bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span class="auth-alert-text"><?php echo h($error); ?></span>
          </div>
          <p class="text-sm"><a href="<?php echo h(ereview_url('college_resend_verification')); ?>">Request a new verification email</a>
            · <a href="<?php echo h(ereview_url('student_registration')); ?>">Register</a></p>
        <?php endif; ?>
      </main>
    </div>
  </div>
</body>
</html>
