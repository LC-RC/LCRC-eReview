<?php
require_once dirname(__DIR__) . '/session_config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/college_email_verification.php';

$pageTitle = 'Resend verification';
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
$regJsFile = dirname(__DIR__) . '/assets/js/college-student-registration.js';
$showModal = $success || $mailFailed;
$emailValue = $confirmEmail !== '' ? $confirmEmail : (string) ($_POST['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/head_public.php'; ?>
  <?php if (is_file($regCssFile)): ?>
  <link rel="stylesheet" href="assets/css/<?php echo h(basename($regCssFile)); ?>?v=<?php echo filemtime($regCssFile); ?>">
  <?php endif; ?>
</head>
<body class="auth-page college-student-registration registration-prototype csr-resend-page min-h-screen font-sans antialiased<?php echo $showModal ? ' csr-modal-open' : ''; ?>"<?php echo ($success && !$mailFailed) ? ' data-csr-status-url="' . h(ereview_url('college_registration_status')) . '"' : ''; ?>>
  <div class="reg-frame8-layout"<?php echo $showModal ? ' inert aria-hidden="true"' : ''; ?>>
    <div class="reg-frame8-left" aria-hidden="true">
      <div class="reg-grok-blob reg-grok-blob-1" aria-hidden="true"></div>
      <div class="reg-grok-blob reg-grok-blob-2" aria-hidden="true"></div>
      <div class="reg-grok-blob reg-grok-blob-3" aria-hidden="true"></div>
      <div class="reg-frame8-left-bg-shape" aria-hidden="true">
        <svg class="reg-left-logo-svg" viewBox="0 0 80 100" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
          <defs>
            <linearGradient id="reg-left-logo-grad" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%" stop-color="rgba(191, 219, 254, 0.75)"/>
              <stop offset="100%" stop-color="rgba(96, 165, 250, 0.6)"/>
            </linearGradient>
          </defs>
          <g class="reg-left-logo-g" fill="none" stroke="url(#reg-left-logo-grad)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path class="reg-paper-body" d="M 12 8 L 12 88 L 68 88 L 68 28 L 42 28 L 42 8 Z"/>
            <path class="reg-paper-fold" d="M 42 8 L 42 28 L 68 28 Z"/>
          </g>
        </svg>
      </div>
      <div class="reg-frame8-hero reg-left-statement">
        <div class="reg-left-statement-inner">
          <p class="reg-left-statement-headline">Open any valid verification link within 24 hours.</p>
          <p class="reg-left-statement-blurb">A verification message is issued only when this portal can send one.</p>
        </div>
      </div>
    </div>
    <div class="reg-frame8-right">
      <header class="reg-frame8-header">
        <span class="reg-frame8-brand"><span class="blue">LCRC</span> <span class="amber">eReview</span></span>
        <img src="image%20assets/lcrc-logo-reg.png" alt="LCRC Review School &amp; Training Center" class="reg-frame8-logo-right" width="140" height="36" loading="eager" decoding="async">
      </header>
      <main class="reg-frame8-main">
        <h1 class="reg-frame8-title">Resend verification</h1>
        <p class="reg-frame8-subtitle">College Examination only. Request another message if your link expired.</p>
        <form method="post" action="<?php echo h(ereview_url('college_resend_verification')); ?>" class="space-y-4" id="csr-resend-form">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <?php if ($error && !$mailFailed): ?>
            <div class="auth-alert auth-alert--error" role="alert">
              <i class="auth-alert-icon bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
              <span class="auth-alert-text"><?php echo h($error); ?></span>
            </div>
          <?php endif; ?>
          <div class="space-y-1">
            <label class="reg-top-label" for="email">Email address</label>
            <input type="email" name="email" id="email" required class="auth-input w-full rounded-xl border px-4 py-3 text-sm" value="<?php echo h($emailValue); ?>" autocomplete="email">
          </div>
          <div class="reg-frame8-form-fixed-bottom">
            <button type="submit" class="reg-submit btn-shine w-full rounded-xl px-6 py-3.5 text-sm" id="csr-submit-btn">Send verification email</button>
            <p class="text-center text-xs subtext mt-3">
              <a href="<?php echo h(ereview_url('student_registration')); ?>">Register</a>
              · <a href="login">Login</a>
            </p>
          </div>
        </form>
      </main>
      <footer class="reg-frame8-footer">
        <p class="reg-frame8-footer-copy">© <?php echo date('Y'); ?> <strong>LCRC eReview</strong> · College Examination</p>
      </footer>
    </div>
  </div>

  <div id="csr-modal-loading" class="reg-modal-backdrop" aria-hidden="true">
    <div class="reg-modal-card">
      <div class="reg-loading-orb" aria-hidden="true">
        <div class="reg-loading-orb-inner"><span></span></div>
      </div>
      <p style="margin:0;color:#64748b;">Sending your request. Please wait...</p>
    </div>
  </div>

  <?php if ($success): ?>
  <div id="csr-modal-email-sent" class="reg-modal-backdrop is-active" role="dialog" aria-modal="true" aria-labelledby="csr-confirm-heading" aria-describedby="csr-confirm-lead">
    <div class="reg-modal-card">
      <div class="csr-modal-mail-icon" aria-hidden="true">
        <i class="bi bi-envelope-check-fill"></i>
      </div>
      <h2 id="csr-confirm-heading">Continue verification</h2>
      <p class="reg-flow-steps__modal-steps">College Examination · email verification</p>
      <p class="csr-confirm-lead" id="csr-confirm-lead" role="status"><?php echo h($success); ?></p>
      <?php if ($confirmEmail !== ''): ?>
        <p class="csr-confirm-email">If a message was issued, check this address: <strong><?php echo h($confirmEmail); ?></strong></p>
      <?php endif; ?>
      <p class="csr-confirm-hint">If a message was issued, check Inbox and Spam. A valid link expires in 24 hours. This screen does not confirm that the provider accepted the message.</p>
      <button type="button" id="csr-modal-email-sent-ok" class="btn-primary">OK, I'll check my email</button>
    </div>
  </div>
  <div id="csr-modal-waiting" class="reg-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="csr-waiting-heading" aria-hidden="true">
    <div class="reg-modal-card">
      <div class="reg-loading-orb" aria-hidden="true">
        <div class="reg-loading-orb-inner"><span></span></div>
      </div>
      <h2 id="csr-waiting-heading" tabindex="-1">Waiting for email verification</h2>
      <p>If a verification message was issued, open any valid link within 24 hours. Your account and College Exam ID are created only after you verify.</p>
      <p class="text-sm" style="color:#64748b;margin-top:0.75rem;">This tab does not confirm Inbox delivery. If you already verified, continue to login. If you opened the link in another tab on this browser, this screen can update.</p>
      <a href="login" class="btn-primary" id="csr-waiting-continue">Continue to login</a>
    </div>
  </div>
  <div id="csr-modal-verified" class="reg-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="csr-verified-heading" aria-hidden="true">
    <div class="reg-modal-card">
      <div class="reg-success-check" aria-hidden="true">
        <i class="bi bi-check-lg"></i>
      </div>
      <h2 id="csr-verified-heading" tabindex="-1">Email verified successfully!</h2>
      <p>Your College Examination account is verified. You can now log in.</p>
      <a href="login" class="btn-primary" id="csr-verified-continue">Continue to login</a>
    </div>
  </div>
  <?php elseif ($mailFailed): ?>
  <div id="csr-modal-error" class="reg-error-backdrop is-active" role="alertdialog" aria-modal="true" aria-labelledby="csr-error-title" aria-describedby="csr-error-message">
    <div class="reg-error-card">
      <div class="reg-error-icon" aria-hidden="true">
        <i class="bi bi-exclamation-circle-fill"></i>
      </div>
      <h2 id="csr-error-title">Verification email not sent</h2>
      <p id="csr-error-message" class="reg-error-message csr-confirm-lead" role="alert"><?php echo h($error); ?></p>
      <button type="button" class="btn-primary" id="csr-modal-error-close">OK, try again</button>
    </div>
  </div>
  <?php endif; ?>

  <?php if (is_file($regJsFile)): ?>
  <script src="assets/js/<?php echo h(basename($regJsFile)); ?>?v=<?php echo filemtime($regJsFile); ?>" defer></script>
  <?php endif; ?>
</body>
</html>
