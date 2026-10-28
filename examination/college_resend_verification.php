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
$confirmEmail = '';
$mailFailed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    college_public_confirm_session_clear(COLLEGE_RESEND_CONFIRM_SESSION_KEY);
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $result = college_public_resend_verification($conn, (string) ($_POST['email'] ?? ''));
        if (empty($result['ok'])) {
            $error = (string) ($result['error'] ?? COLLEGE_PUBLIC_UNAVAILABLE);
        } else {
            college_public_confirm_session_put(
                COLLEGE_RESEND_CONFIRM_SESSION_KEY,
                (string) ($_POST['email'] ?? ''),
                college_public_attempted_mail_failed($result)
            );
            header('Location: ' . college_public_safe_confirm_location('college_resend_verification'), true, 303);
            exit;
        }
    }
} elseif (college_public_confirm_query_requested()) {
    $confirm = college_public_confirm_session_get(COLLEGE_RESEND_CONFIRM_SESSION_KEY);
    if ($confirm !== null) {
        $confirmEmail = (string) ($confirm['email'] ?? '');
        $mailFailed = !empty($confirm['mail_failed']);
        if ($mailFailed) {
            $error = COLLEGE_PUBLIC_MAIL_FAILED;
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
<body class="auth-page college-student-registration registration-prototype min-h-screen font-sans antialiased<?php echo $success ? ' csr-confirm-mode' : ''; ?>">
  <div class="reg-frame8-layout">
    <div class="reg-frame8-right" style="margin:0 auto;max-width:32rem;">
      <header class="reg-frame8-header">
        <span class="reg-frame8-brand"><span class="blue">LCRC</span> <span class="amber">eReview</span></span>
      </header>
      <main class="reg-frame8-main">
        <h1 class="reg-frame8-title">Resend verification</h1>
        <p class="reg-frame8-subtitle">College Examination only. Request another message if your link expired.</p>
        <?php if ($success): ?>
          <div class="csr-confirm-card">
            <div class="csr-confirm-icon" aria-hidden="true"><i class="bi bi-envelope-check"></i></div>
            <h2 class="csr-confirm-title" id="csr-confirm-heading" tabindex="-1">Continue verification</h2>
            <p class="csr-confirm-lead" role="status"><?php echo h($success); ?></p>
            <p class="csr-confirm-hint">If a message was issued, check Inbox and Spam. A valid link expires in 24 hours. This screen does not confirm that the provider accepted the message.</p>
            <div class="csr-confirm-actions">
              <a class="csr-confirm-btn-secondary" href="login">Back to Login</a>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($mailFailed): ?>
          <h2 class="csr-confirm-title csr-resend-fail-title" id="csr-confirm-heading" tabindex="-1">Verification email not sent</h2>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="auth-alert auth-alert--error mb-4" role="alert">
            <i class="auth-alert-icon bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span class="auth-alert-text"><?php echo h($error); ?></span>
          </div>
        <?php endif; ?>
        <?php if (!$success): ?>
        <form method="post" action="<?php echo h(ereview_url('college_resend_verification')); ?>" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <div class="space-y-1">
            <label class="reg-top-label" for="email">Email address</label>
            <input type="email" name="email" id="email" required class="auth-input w-full rounded-xl border px-4 py-3 text-sm" value="<?php echo h($confirmEmail !== '' ? $confirmEmail : (string) ($_POST['email'] ?? '')); ?>" autocomplete="email">
          </div>
          <button type="submit" class="reg-submit w-full rounded-xl px-6 py-3.5 text-sm">Send verification email</button>
        </form>
        <p class="text-center text-xs subtext mt-4">
          <a href="<?php echo h(ereview_url('student_registration')); ?>">Register</a>
          · <a href="login">Login</a>
        </p>
        <?php endif; ?>
      </main>
    </div>
  </div>
  <?php if ($success || $mailFailed): ?>
  <script>
    (function () {
      var heading = document.getElementById('csr-confirm-heading');
      if (heading) {
        heading.focus();
      }
    })();
  </script>
  <?php endif; ?>
</body>
</html>
