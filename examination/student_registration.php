<?php
require_once dirname(__DIR__) . '/session_config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_sections.php';
require_once __DIR__ . '/includes/college_email_verification.php';
require_once __DIR__ . '/includes/college_schools.php';

$pageTitle = 'Student Registration';
$csrf = generateCSRFToken();
$error = null;
$success = null;
$confirmEmail = '';
$mailFailed = false;
$existingCollege = false;
$confirmVerified = false;
$confirmWaiting = false;
$formValues = [
    'full_name' => '',
    'email' => '',
    'school_id' => '',
    'section' => '',
];
$avatarUseDefault = 1;
$sectionOptions = college_sections_active_names($conn);
$schoolOptions = college_schools_active_list($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    college_public_confirm_session_clear(COLLEGE_REG_CONFIRM_SESSION_KEY);
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $result = college_public_register_from_request($conn, $_POST, $_FILES);
        if (empty($result['ok'])) {
            $error = (string) ($result['error'] ?? 'Could not submit registration.');
            $formValues = [
                'full_name' => trim((string) ($_POST['full_name'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'school_id' => (string) ((int) ($_POST['school_id'] ?? 0) ?: ''),
                'section' => trim((string) ($_POST['section'] ?? '')),
            ];
            $avatarUseDefault = !empty($_POST['use_default_avatar']) ? 1 : 0;
        } else {
            college_public_confirm_session_put(
                COLLEGE_REG_CONFIRM_SESSION_KEY,
                (string) ($_POST['email'] ?? ''),
                college_public_attempted_mail_failed($result),
                !empty($result['existing_college'])
            );
            header('Location: ' . college_public_safe_confirm_location('student_registration'), true, 303);
            exit;
        }
    }
} elseif (college_public_confirm_query_requested()) {
    $confirm = college_public_confirm_session_get(COLLEGE_REG_CONFIRM_SESSION_KEY);
    if ($confirm !== null) {
        $mailFailed = !empty($confirm['mail_failed']);
        $existingCollege = !empty($confirm['existing_college']);
        if ($existingCollege) {
            $success = COLLEGE_PUBLIC_EXISTING_ACCOUNT;
        } else {
            $success = $mailFailed ? COLLEGE_PUBLIC_MAIL_FAILED : COLLEGE_PUBLIC_GENERIC_VERIFY;
        }
        $confirmEmail = (string) ($confirm['email'] ?? '');
        if ($confirmEmail !== '') {
            $formValues['email'] = $confirmEmail;
        }
        if (!$existingCollege && !$mailFailed) {
            $verifyStatus = college_public_confirm_verified_status($conn);
            $confirmVerified = !empty($verifyStatus['verified']);
            $confirmWaiting = !$confirmVerified && !empty($confirm['waiting']);
        }
    }
}

$regCssFile = dirname(__DIR__) . '/assets/css/college-student-registration.css';
$regJsFile = dirname(__DIR__) . '/assets/js/college-student-registration.js';
$pendingVerifyModal = $success && !$mailFailed && !$existingCollege;
$showConfirmIntro = $pendingVerifyModal && !$confirmVerified && !$confirmWaiting;
$showConfirmWaiting = $pendingVerifyModal && !$confirmVerified && $confirmWaiting;
$showConfirmVerified = $pendingVerifyModal && $confirmVerified;
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <?php require_once dirname(__DIR__) . '/includes/head_public.php'; ?>
  <?php if (is_file($regCssFile)): ?>
  <link rel="stylesheet" href="assets/css/<?php echo h(basename($regCssFile)); ?>?v=<?php echo filemtime($regCssFile); ?>">
  <?php endif; ?>
</head>
<body class="auth-page registration-prototype college-student-registration min-h-screen font-sans antialiased<?php echo $success ? ' csr-modal-open' : ''; ?>"<?php echo $pendingVerifyModal ? ' data-csr-status-url="' . h(ereview_url('college_registration_status')) . '" data-csr-csrf="' . h($csrf) . '"' : ''; ?>>
  <div class="reg-frame8-layout"<?php echo $success ? ' inert aria-hidden="true"' : ''; ?>>
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
          <p class="reg-left-statement-headline">Register, verify, then sit your College Examination.</p>
          <p class="reg-left-statement-blurb">College Examination portal only. Account and College Exam ID are created after email verification. This is separate from LMS review-package registration and does not involve payment.</p>
        </div>
      </div>
    </div>

    <div class="reg-frame8-right">
      <header class="reg-frame8-header">
        <span class="reg-frame8-brand"><span class="blue">LCRC</span> <span class="amber">eReview</span></span>
        <img src="image%20assets/lcrc-logo-reg.png" alt="LCRC Review School &amp; Training Center" class="reg-frame8-logo-right" width="140" height="36" loading="eager" decoding="async">
      </header>

      <main class="reg-frame8-main">
        <h1 class="reg-frame8-title">Student Registration</h1>
        <p class="reg-frame8-subtitle">College Examination portal. Email verification required.</p>
        <form action="student_registration" method="post" enctype="multipart/form-data" novalidate id="reg-form">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

          <div class="reg-frame8-form-scroll" id="reg-form-scroll">
            <?php if ($error): ?>
              <div class="auth-alert auth-alert--error" role="alert" aria-live="assertive">
                <i class="auth-alert-icon bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span class="auth-alert-text"><?php echo h($error); ?></span>
              </div>
            <?php endif; ?>

            <nav class="reg-flow-steps mb-4" aria-label="College Examination steps">
              <ol class="reg-flow-steps__list">
                <li class="reg-flow-steps__item is-current"><span class="reg-flow-steps__num">1</span><span class="reg-flow-steps__label">Create Account</span></li>
                <li class="reg-flow-steps__item"><span class="reg-flow-steps__num">2</span><span class="reg-flow-steps__label">Verify Email</span></li>
                <li class="reg-flow-steps__item"><span class="reg-flow-steps__num">3</span><span class="reg-flow-steps__label">Sit Exam</span></li>
              </ol>
              <p class="reg-flow-steps__hint">First create your account, then verify your email. No payment.</p>
            </nav>

            <span class="reg-section-label"><i class="bi bi-check-circle-fill reg-section-label-check" aria-hidden="true"></i>Account</span>
            <div class="reg-section reg-frame8-grid reg-frame8-grid-2">
              <div class="space-y-1">
                <div class="float-label-wrap" data-float-wrap>
                  <label class="float-label" for="csr-full_name">Full name <span class="csr-req" aria-hidden="true">*</span></label>
                  <input type="text" name="full_name" id="csr-full_name" required placeholder=" " class="auth-input w-full rounded-xl border px-4 py-3 text-sm" value="<?php echo h($formValues['full_name']); ?>" autocomplete="name">
                </div>
              </div>
              <div class="space-y-1">
                <div class="float-label-wrap" data-float-wrap>
                  <label class="float-label" for="csr-email">Email address <span class="csr-req" aria-hidden="true">*</span></label>
                  <input type="email" name="email" id="csr-email" required placeholder=" " autocomplete="off" class="auth-input w-full rounded-xl border px-4 py-3 text-sm" value="<?php echo h($formValues['email']); ?>">
                </div>
              </div>
            </div>

            <div class="reg-section reg-frame8-grid reg-frame8-grid-2">
              <div class="space-y-1">
                <label class="reg-top-label" for="csr-school">School <span class="csr-req" aria-hidden="true">*</span></label>
                <select name="school_id" id="csr-school" required class="auth-input w-full rounded-xl border px-4 py-3 text-sm" aria-required="true">
                  <option value="" disabled <?php echo $formValues['school_id'] === '' ? 'selected' : ''; ?>><?php echo $schoolOptions === [] ? 'No schools available yet' : 'Select your school'; ?></option>
                  <?php foreach ($schoolOptions as $schOpt): ?>
                    <option value="<?php echo (int) $schOpt['school_id']; ?>" <?php echo ((string) (int) $schOpt['school_id'] === (string) $formValues['school_id']) ? 'selected' : ''; ?>><?php echo h((string) $schOpt['school_name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if ($schoolOptions === []): ?>
                  <p class="text-xs mt-1 opacity-80">Schools are configured by Professor Admin. Try again after a school is added and set to Active.</p>
                <?php endif; ?>
              </div>
              <div class="space-y-1">
                <label class="reg-top-label" for="csr-section">Section <span class="csr-req" aria-hidden="true">*</span></label>
                <select name="section" id="csr-section" required class="auth-input w-full rounded-xl border px-4 py-3 text-sm">
                  <option value="" disabled <?php echo $formValues['section'] === '' ? 'selected' : ''; ?>><?php echo $sectionOptions === [] ? 'No sections available yet' : 'Select section'; ?></option>
                  <?php foreach ($sectionOptions as $secOpt): ?>
                    <option value="<?php echo h($secOpt); ?>" <?php echo ($formValues['section'] === $secOpt) ? 'selected' : ''; ?>><?php echo h($secOpt); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <span class="reg-section-label"><i class="bi bi-check-circle-fill reg-section-label-check" aria-hidden="true"></i>Security</span>
            <p class="reg-security-subheading">Password must be at least 8 characters and include uppercase, lowercase, a number, and a symbol.</p>
            <div class="reg-section reg-frame8-full space-y-2">
              <div class="float-label-wrap auth-password-wrap" data-float-wrap>
                <label class="float-label" for="csr-password">Password <span class="csr-req" aria-hidden="true">*</span></label>
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
                <div class="reg-pw-check-item" id="csr-pw-check-length">
                  <i class="bi bi-circle" aria-hidden="true"></i>
                  <span>At least 8 characters</span>
                </div>
                <div class="reg-pw-check-item" id="csr-pw-check-upper">
                  <i class="bi bi-circle" aria-hidden="true"></i>
                  <span>One uppercase letter</span>
                </div>
                <div class="reg-pw-check-item" id="csr-pw-check-lower">
                  <i class="bi bi-circle" aria-hidden="true"></i>
                  <span>One lowercase letter</span>
                </div>
                <div class="reg-pw-check-item" id="csr-pw-check-number">
                  <i class="bi bi-circle" aria-hidden="true"></i>
                  <span>One number</span>
                </div>
                <div class="reg-pw-check-item" id="csr-pw-check-symbol">
                  <i class="bi bi-circle" aria-hidden="true"></i>
                  <span>One symbol</span>
                </div>
              </div>
              <div class="float-label-wrap auth-password-wrap" data-float-wrap>
                <label class="float-label" for="csr-password-confirm">Confirm password <span class="csr-req" aria-hidden="true">*</span></label>
                <input type="password" name="confirm_password" id="csr-password-confirm" required minlength="8" placeholder=" " class="auth-input w-full rounded-xl border px-4 pr-12 py-3 text-sm" autocomplete="new-password">
                <button type="button" id="csr-toggle-password-confirm" class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 transition-colors" aria-label="Show confirm password">
                  <i class="bi bi-eye-fill text-lg"></i>
                </button>
              </div>
              <p class="reg-confirm-error hidden" id="csr-confirm-error" role="alert"></p>
              <p class="reg-confirm-success hidden" id="csr-confirm-success" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Passwords match</p>
            </div>

            <span class="reg-section-label"><i class="bi bi-check-circle-fill reg-section-label-check" aria-hidden="true"></i>Profile</span>
            <div class="reg-section reg-frame8-full">
              <div class="reg-upload-box-wrap max-w-md">
                <label class="reg-top-label" for="csr-profile-picture">Profile picture</label>
                <div class="reg-file-zone border-2 border-dashed rounded-xl p-4 text-center transition-colors" id="csr-avatar-zone">
                  <input type="file" name="profile_picture" id="csr-profile-picture" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden">
                  <div id="csr-avatar-placeholder">
                    <p class="text-sm text-slate-500 mb-2"><i class="bi bi-person-circle text-lg text-[#1F58C3] mr-1.5" aria-hidden="true"></i>Upload a photo or <button type="button" class="text-[#1F58C3] hover:underline" id="csr-avatar-browse">browse</button></p>
                    <p class="file-hint text-xs text-slate-400">JPG, PNG, WEBP, GIF — max 4MB</p>
                  </div>
                  <div id="csr-avatar-preview" class="hidden">
                    <div class="flex items-center gap-3 justify-center flex-wrap">
                      <img id="csr-avatar-thumb" src="" alt="" class="w-14 h-14 object-cover rounded-full border border-slate-300 hidden">
                      <div class="text-left">
                        <p class="text-sm font-medium text-slate-700" id="csr-avatar-name"></p>
                        <p class="text-xs text-slate-500" id="csr-avatar-size"></p>
                      </div>
                      <button type="button" id="csr-avatar-clear" class="text-slate-500 hover:text-slate-800 text-sm" aria-label="Remove image">Remove</button>
                    </div>
                  </div>
                </div>
                <label class="reg-default-avatar-toggle cursor-pointer">
                  <input type="checkbox" id="csr-use-default-avatar" name="use_default_avatar" value="1" <?php echo $avatarUseDefault ? 'checked' : ''; ?>>
                  Use default avatar
                </label>
              </div>
            </div>
          </div>

          <div class="reg-frame8-form-fixed-bottom">
            <button type="submit" class="reg-submit btn-shine w-full inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3.5 text-sm" id="csr-submit-btn">
              <span>Send verification email</span>
              <i class="bi bi-arrow-right text-lg" aria-hidden="true"></i>
            </button>
            <p class="text-center text-xs subtext mt-3">
              Already have an account? <a href="login">Login</a>
              · <a href="<?php echo h(ereview_url('college_forgot_password')); ?>">Reset password</a>
              · <a href="<?php echo h(ereview_url('college_resend_verification')); ?>">Resend verification</a>
              · Main eReview registration: <a href="registration">Register</a>
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
      <p style="margin:0;color:#64748b;">Submitting your registration. Please wait...</p>
    </div>
  </div>

  <?php if ($success && $existingCollege): ?>
  <div id="csr-modal-existing" class="reg-modal-backdrop is-active" role="dialog" aria-modal="true" aria-labelledby="csr-existing-heading" aria-describedby="csr-existing-lead">
    <div class="reg-modal-card">
      <div class="csr-modal-mail-icon" aria-hidden="true">
        <i class="bi bi-person-check-fill"></i>
      </div>
      <h2 id="csr-existing-heading">Account already exists</h2>
      <p class="reg-flow-steps__modal-steps">College Examination · existing account</p>
      <p class="csr-confirm-lead" id="csr-existing-lead" role="status"><?php echo h($success); ?></p>
      <?php if ($confirmEmail !== ''): ?>
        <p class="csr-confirm-email">Use this address to sign in or reset the password: <strong><?php echo h($confirmEmail); ?></strong></p>
      <?php endif; ?>
      <a href="login" class="btn-primary" id="csr-existing-login">Continue to login</a>
      <form method="post" action="<?php echo h(ereview_url('college_forgot_password')); ?>" class="csr-confirm-resend">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="email" value="<?php echo h($confirmEmail); ?>">
        <button type="submit" class="csr-modal-text-action">Reset password</button>
      </form>
    </div>
  </div>
  <?php elseif ($success && !$mailFailed): ?>
  <div id="csr-modal-email-sent" class="reg-modal-backdrop<?php echo $showConfirmIntro ? ' is-active' : ''; ?>" role="dialog" aria-modal="true" aria-labelledby="csr-confirm-heading" aria-describedby="csr-confirm-lead"<?php echo $showConfirmIntro ? '' : ' aria-hidden="true"'; ?>>
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
      <p class="csr-confirm-hint">This screen does not confirm that the provider accepted the message.</p>
      <button type="button" id="csr-modal-email-sent-ok" class="btn-primary">OK, I'll check my email</button>
      <form method="post" action="<?php echo h(ereview_url('college_resend_verification')); ?>" class="csr-confirm-resend">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="email" id="csr-confirm-resend-email" value="<?php echo h($confirmEmail); ?>">
        <button type="submit" class="csr-modal-text-action">Resend verification</button>
      </form>
    </div>
  </div>
  <div id="csr-modal-waiting" class="reg-modal-backdrop<?php echo $showConfirmWaiting ? ' is-active' : ''; ?>" role="dialog" aria-modal="true" aria-labelledby="csr-waiting-heading"<?php echo $showConfirmWaiting ? '' : ' aria-hidden="true"'; ?>>
    <div class="reg-modal-card">
      <div class="reg-loading-orb" aria-hidden="true">
        <div class="reg-loading-orb-inner"><span></span></div>
      </div>
      <h2 id="csr-waiting-heading" tabindex="-1">Waiting for email verification</h2>
      <p>If a verification message was issued, open any valid link within 24 hours. Your account and College Exam ID are created only after you verify.</p>
      <p class="text-sm" style="color:#64748b;margin-top:0.75rem;">This tab does not confirm Inbox delivery. If you opened the verification link in another tab on this browser, this screen updates when verification completes.</p>
      <form method="post" action="<?php echo h(ereview_url('college_resend_verification')); ?>" class="csr-confirm-resend">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="email" value="<?php echo h($confirmEmail); ?>">
        <button type="submit" class="csr-modal-text-action">Resend verification</button>
      </form>
    </div>
  </div>
  <div id="csr-modal-verified" class="reg-modal-backdrop<?php echo $showConfirmVerified ? ' is-active' : ''; ?>" role="dialog" aria-modal="true" aria-labelledby="csr-verified-heading"<?php echo $showConfirmVerified ? '' : ' aria-hidden="true"'; ?>>
    <div class="reg-modal-card">
      <div class="reg-success-check" aria-hidden="true">
        <i class="bi bi-check-lg"></i>
      </div>
      <h2 id="csr-verified-heading" tabindex="-1">Email verified successfully!</h2>
      <p>Your College Examination account is verified. You can now log in.</p>
      <a href="login" class="btn-primary" id="csr-verified-continue">Continue to login</a>
    </div>
  </div>
  <?php elseif ($success && $mailFailed): ?>
  <div id="csr-modal-error" class="reg-error-backdrop is-active" role="alertdialog" aria-modal="true" aria-labelledby="csr-error-title" aria-describedby="csr-error-message">
    <div class="reg-error-card">
      <div class="reg-error-icon" aria-hidden="true">
        <i class="bi bi-exclamation-circle-fill"></i>
      </div>
      <h2 id="csr-error-title">Verification email not sent</h2>
      <p id="csr-error-message" class="reg-error-message csr-confirm-lead" role="alert"><?php echo h($success); ?></p>
      <form method="post" action="<?php echo h(ereview_url('college_resend_verification')); ?>" class="csr-confirm-resend">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="email" id="csr-confirm-resend-email" value="<?php echo h($confirmEmail); ?>">
        <button type="submit" class="btn-primary" id="csr-modal-error-close">OK, try again</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if (is_file($regJsFile)): ?>
  <script src="assets/js/<?php echo h(basename($regJsFile)); ?>?v=<?php echo filemtime($regJsFile); ?>" defer></script>
  <?php endif; ?>
</body>
</html>
