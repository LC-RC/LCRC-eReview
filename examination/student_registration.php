<?php
require_once dirname(__DIR__) . '/session_config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_sections.php';
require_once __DIR__ . '/includes/college_student_create.php';

$pageTitle = 'Student Registration';
$csrf = generateCSRFToken();
$error = null;
$success = null;
$formValues = [
    'full_name' => '',
    'email' => '',
    'student_number' => '',
    'school' => '',
    'section' => '',
];
$avatarUseDefault = 1;
$sectionOptions = college_sections_active_names($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $result = college_student_create_from_request($conn, $_POST, $_FILES, ['initial_status' => 'pending']);
        if (!$result['ok']) {
            $error = $result['error'] ?? 'Could not create account.';
            $formValues = [
                'full_name' => trim((string) ($_POST['full_name'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'student_number' => trim((string) ($_POST['student_number'] ?? '')),
                'school' => trim((string) ($_POST['school'] ?? '')),
                'section' => trim((string) ($_POST['section'] ?? '')),
            ];
            $avatarUseDefault = !empty($_POST['use_default_avatar']) ? 1 : 0;
        } else {
            $name = trim((string) ($_POST['full_name'] ?? 'Student'));
            $success = 'Registration submitted for ' . $name . '. Please wait for a professor or administrator to approve your account before you can sign in.';
            $formValues = [
                'full_name' => '',
                'email' => '',
                'student_number' => '',
                'school' => '',
                'section' => '',
            ];
            $avatarUseDefault = 1;
        }
    }
}

$regCssFile = dirname(__DIR__) . '/assets/css/college-student-registration.css';
$regJsFile = dirname(__DIR__) . '/assets/js/college-student-registration.js';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <?php require_once dirname(__DIR__) . '/includes/head_public.php'; ?>
  <?php if (is_file($regCssFile)): ?>
  <link rel="stylesheet" href="assets/css/<?php echo h(basename($regCssFile)); ?>?v=<?php echo filemtime($regCssFile); ?>">
  <?php endif; ?>
</head>
<body class="auth-page registration-prototype college-student-registration min-h-screen font-sans antialiased">
  <div class="reg-frame8-layout">
    <div class="reg-frame8-left" aria-hidden="true">
      <div class="reg-grok-blob reg-grok-blob-1" aria-hidden="true"></div>
      <div class="reg-grok-blob reg-grok-blob-2" aria-hidden="true"></div>
      <div class="reg-grok-blob reg-grok-blob-3" aria-hidden="true"></div>
      <div class="reg-frame8-left-bg-shape" aria-hidden="true">
        <svg class="reg-left-logo-svg" viewBox="0 0 80 100" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
          <defs>
            <linearGradient id="csr-left-logo-grad" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%" stop-color="rgba(191, 219, 254, 0.75)"/>
              <stop offset="100%" stop-color="rgba(96, 165, 250, 0.6)"/>
            </linearGradient>
          </defs>
          <g class="reg-left-logo-g" fill="none" stroke="url(#csr-left-logo-grad)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path class="reg-paper-body" d="M 12 8 L 12 88 L 68 88 L 68 28 L 42 28 L 42 8 Z"/>
            <path class="reg-paper-fold" d="M 42 8 L 42 28 L 68 28 Z"/>
          </g>
        </svg>
      </div>
      <div class="reg-frame8-hero reg-left-statement" aria-live="polite">
        <div class="reg-left-statement-inner">
          <p class="reg-left-statement-headline">College Examination student registration.</p>
          <p class="reg-left-statement-metrics">Section · School · Secure login</p>
          <p class="reg-left-statement-blurb">Create your college examination account here. After registering, wait for professor or admin approval before signing in. This is separate from the main eReview LMS registration page.</p>
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
        <p class="reg-frame8-subtitle">Register for the College Examination portal.</p>

        <form action="student_registration" method="post" enctype="multipart/form-data" novalidate id="reg-form">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

          <div class="reg-frame8-form-scroll" id="reg-form-scroll">
            <?php if ($success): ?>
              <div class="auth-alert auth-alert--success mb-4" role="status" style="background:rgba(220,252,231,0.95);border-color:rgba(34,197,94,0.35);border-left-color:#22c55e;color:#166534;">
                <i class="auth-alert-icon bi bi-check-circle-fill" aria-hidden="true"></i>
                <span class="auth-alert-text"><?php echo h($success); ?></span>
              </div>
            <?php endif; ?>
            <?php if ($error): ?>
              <div class="auth-alert auth-alert--error" role="alert" aria-live="assertive">
                <i class="auth-alert-icon bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span class="auth-alert-text"><?php echo h($error); ?></span>
              </div>
            <?php endif; ?>

            <span class="reg-section-label"><i class="bi bi-check-circle-fill reg-section-label-check" aria-hidden="true"></i>Account</span>
            <div class="reg-section reg-frame8-grid reg-frame8-grid-2">
              <div class="space-y-1">
                <div class="float-label-wrap" data-float-wrap>
                  <label class="float-label" for="csr-full_name">Full name</label>
                  <input type="text" name="full_name" id="csr-full_name" required placeholder=" " class="auth-input w-full rounded-xl border px-4 py-3 text-sm" value="<?php echo h($formValues['full_name']); ?>">
                </div>
              </div>
              <div class="space-y-1">
                <div class="float-label-wrap" data-float-wrap>
                  <label class="float-label" for="csr-email">Email address</label>
                  <input type="email" name="email" id="csr-email" required placeholder=" " autocomplete="off" class="auth-input w-full rounded-xl border px-4 py-3 text-sm" value="<?php echo h($formValues['email']); ?>">
                </div>
              </div>
              <div class="space-y-1">
                <div class="float-label-wrap" data-float-wrap>
                  <label class="float-label" for="csr-student_number">Student number (optional)</label>
                  <input type="text" name="student_number" id="csr-student_number" maxlength="32" pattern="[A-Za-z0-9_-]*" placeholder=" " class="auth-input w-full rounded-xl border px-4 py-3 text-sm" value="<?php echo h($formValues['student_number']); ?>">
                </div>
              </div>
              <div class="space-y-1">
                <div class="float-label-wrap" data-float-wrap>
                  <label class="float-label" for="csr-school">School</label>
                  <input type="text" name="school" id="csr-school" required placeholder=" " class="auth-input w-full rounded-xl border px-4 py-3 text-sm" value="<?php echo h($formValues['school']); ?>">
                </div>
              </div>
              <div class="space-y-1 reg-frame8-full">
                <label class="reg-top-label" for="csr-section">Section</label>
                <select name="section" id="csr-section" required class="auth-input w-full rounded-xl border px-4 py-3 text-sm">
                  <option value="" disabled <?php echo $formValues['section'] === '' ? 'selected' : ''; ?>><?php echo $sectionOptions === [] ? 'No sections available yet' : 'Select section'; ?></option>
                  <?php foreach ($sectionOptions as $secOpt): ?>
                    <option value="<?php echo h($secOpt); ?>" <?php echo ($formValues['section'] === $secOpt) ? 'selected' : ''; ?>><?php echo h($secOpt); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <span class="reg-section-label"><i class="bi bi-check-circle-fill reg-section-label-check" aria-hidden="true"></i>Security</span>
            <p class="reg-security-subheading">Choose a password for your account (minimum 8 characters).</p>
            <div class="reg-section reg-frame8-full space-y-2">
              <div class="float-label-wrap auth-password-wrap" data-float-wrap>
                <label class="float-label" for="csr-password">Password</label>
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
              <span>Create Account</span>
              <i class="bi bi-arrow-right text-lg" aria-hidden="true"></i>
            </button>
            <p class="text-center text-xs subtext mt-3">
              Already have an account? <a href="login">Login</a>
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

  <?php if (is_file($regJsFile)): ?>
  <script src="assets/js/<?php echo h(basename($regJsFile)); ?>?v=<?php echo filemtime($regJsFile); ?>" defer></script>
  <?php endif; ?>
</body>
</html>
