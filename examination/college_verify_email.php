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
$pageTitle = $ok ? 'Email verified' : 'Verification failed';
$examIdDisplay = $studentNumber !== '' ? $studentNumber : 'Assigned on your account';
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
<body class="auth-page college-student-registration registration-prototype college-verify-email-page min-h-screen font-sans antialiased">
  <div class="cve-shell">
    <main class="cve-card" <?php echo $ok ? 'role="status"' : 'role="alert"'; ?>>
      <header class="cve-brand">
        <span class="cve-brand-mark" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
        <div class="cve-brand-text">
          <span class="cve-brand-name"><span class="blue">LCRC</span> <span class="amber">eReview</span></span>
          <span class="cve-brand-sub">College Examination Portal</span>
        </div>
      </header>

      <?php if ($ok): ?>
        <div class="reg-success-check cve-icon" aria-hidden="true">
          <i class="bi bi-shield-check"></i>
        </div>
        <h1 class="cve-title">Email verified successfully!</h1>
        <p class="cve-lead">Your College Examination account is now verified. You're ready for the next step.</p>

        <div class="cve-id">
          <span class="cve-id__k">Your College Exam ID</span>
          <span class="cve-id__v" translate="no"><?php echo h($examIdDisplay); ?></span>
          <?php if ($studentNumber !== ''): ?>
            <span class="cve-id__hint">Keep this ID for your records.</span>
          <?php endif; ?>
        </div>

        <a href="login" class="cve-btn" id="cve-continue-login">Continue to login <span aria-hidden="true">→</span></a>

        <?php if ($mailSent): ?>
          <p class="cve-note">Your College Exam ID has also been sent to your verified email.</p>
        <?php else: ?>
          <p class="cve-note">We could not send the student-number email. Your account and number are saved. You can request the number by email without exposing it in the browser.</p>
          <form method="post" action="<?php echo h(ereview_url('college_resend_student_number')); ?>" class="cve-resend">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <label class="reg-top-label" for="sn-email">Verified email</label>
            <input type="email" id="sn-email" name="email" required class="auth-input w-full rounded-xl border px-4 py-3 text-sm" value="<?php echo h((string) ($result['email'] ?? '')); ?>">
            <button type="submit" class="cve-btn cve-btn--secondary">Email my College Exam ID</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <div class="reg-error-icon cve-icon" aria-hidden="true">
          <i class="bi bi-exclamation-triangle-fill"></i>
        </div>
        <h1 class="cve-title">Verification unsuccessful</h1>
        <p class="cve-lead"><?php echo h($error); ?></p>
        <div class="cve-links">
          <a href="<?php echo h(ereview_url('college_resend_verification')); ?>">Request a new verification email</a>
          <a href="<?php echo h(ereview_url('student_registration')); ?>">Register</a>
        </div>
      <?php endif; ?>
    </main>
    <p class="cve-foot">LCRC eReview · College Examination</p>
  </div>
  <?php if ($ok): ?>
  <script>
  (function () {
    try {
      if (typeof BroadcastChannel !== 'function') return;
      var ch = new BroadcastChannel('csr-college-email-verified');
      ch.postMessage({ ok: true });
      setTimeout(function () { try { ch.close(); } catch (e2) {} }, 1000);
    } catch (e) {}
  })();
  </script>
  <?php endif; ?>
</body>
</html>
