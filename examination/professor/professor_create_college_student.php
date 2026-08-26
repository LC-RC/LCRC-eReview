<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_sections.php';
require_once dirname(__DIR__) . '/includes/college_student_create.php';
require_once dirname(__DIR__, 2) . '/includes/url_helpers.php';

$pageTitle = 'Add college student';
$csrf = generateCSRFToken();
$error = null;
$success = null;
$avatarPreviewPath = '';
$avatarUseDefault = 1;
$sectionOptions = college_sections_active_names($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = 'Invalid request.';
    } else {
        $result = college_student_create_from_request($conn, $_POST, $_FILES);
        if (!$result['ok']) {
            $error = $result['error'] ?? 'Could not create account.';
            $avatarUseDefault = !empty($_POST['use_default_avatar']) ? 1 : 0;
            if (!empty($result['avatar_preview_path'])) {
                $avatarPreviewPath = (string) $result['avatar_preview_path'];
            }
        } else {
            $success = 'Account created. The student can sign in at the main login page.';
            $_POST = [];
            $avatarPreviewPath = '';
            $avatarUseDefault = 1;
        }
    }
}

$pageTitle = 'Add college student';
$adminHeroIcon = 'person-plus';
$adminHeroTitle = 'Create college student';
$adminHeroSubtitle = 'Creates an approved account with review_type=undergrad (College Student).';
$adminHeroActions = '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_college_students"><i class="bi bi-arrow-left"></i> Back to students</a>'
    . ' <a class="admin-btn admin-btn--ghost admin-btn--sm" href="student_registration"><i class="bi bi-person-plus"></i> Registration form</a>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <?php if ($error): ?><div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($error); ?></span></div><?php endif; ?>
  <?php if ($success): ?><div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?php echo h($success); ?></span></div><?php endif; ?>

  <div class="examination-page-shell">
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
      <form method="post" enctype="multipart/form-data" class="rounded-xl overflow-hidden page-table xl:col-span-2 p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <div class="examination-avatar-zone">
          <div class="flex flex-wrap items-start gap-3">
            <img id="avatarPreview" class="examination-avatar-preview" src="<?php echo $avatarPreviewPath !== '' ? h($avatarPreviewPath) : 'data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22><rect width=%2264%22 height=%2264%22 rx=%2232%22 fill=%22%23ecfdf5%22/><text x=%2250%25%22 y=%2252%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 font-family=%22Arial%22 font-size=%2228%22 fill=%22%23166534%22>+</text></svg>'; ?>" alt="Profile preview">
            <div class="flex-1 min-w-[230px]">
              <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Profile picture</label>
              <input type="file" id="profilePictureInput" name="profile_picture" accept="image/jpeg,image/png,image/webp,image/gif" class="w-full">
              <div class="mt-2">
                <label class="inline-flex items-center gap-2 text-sm font-semibold cursor-pointer">
                  <input type="checkbox" id="useDefaultAvatarInput" name="use_default_avatar" value="1" <?php echo $avatarUseDefault ? 'checked' : ''; ?>>
                  Use default avatar
                </label>
              </div>
              <p class="examination-form-hint mb-0">Supported: JPG, PNG, WEBP, GIF (max 4MB)</p>
            </div>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Full name</label>
            <input type="text" name="full_name" required class="w-full" value="<?php echo h($_POST['full_name'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Section</label>
            <select name="section" required class="w-full">
              <option value=""><?php echo $sectionOptions === [] ? 'No active sections — create under Sections first' : 'Select section'; ?></option>
              <?php foreach ($sectionOptions as $secOpt): ?>
                <option value="<?php echo h($secOpt); ?>" <?php echo (($_POST['section'] ?? '') === $secOpt) ? 'selected' : ''; ?>><?php echo h($secOpt); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Student number <span class="normal-case opacity-70">(optional)</span></label>
            <input type="text" name="student_number" maxlength="32" pattern="[A-Za-z0-9_-]*" class="w-full" placeholder="e.g. 2008435" value="<?php echo h($_POST['student_number'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Email (login)</label>
            <input type="email" name="email" required autocomplete="off" class="w-full" value="<?php echo h($_POST['email'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">School</label>
            <input type="text" name="school" required class="w-full" value="<?php echo h($_POST['school'] ?? ''); ?>">
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Password</label>
            <input type="password" name="password" required minlength="8" class="w-full" autocomplete="new-password">
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Confirm password</label>
            <input type="password" name="confirm_password" required minlength="8" class="w-full" autocomplete="new-password">
          </div>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
          <button type="submit" class="admin-btn admin-btn--primary"><i class="bi bi-check2-circle"></i> Create account</button>
        </div>
      </form>

      <aside class="rounded-xl overflow-hidden page-table p-5">
        <h2 class="text-base font-bold m-0">Account setup notes</h2>
        <ul class="mt-3 text-sm space-y-2 opacity-90">
          <li><i class="bi bi-check-circle mr-1"></i> Student is created as <strong>approved</strong>.</li>
          <li><i class="bi bi-check-circle mr-1"></i> Minimum password length is 8 characters.</li>
          <li><i class="bi bi-check-circle mr-1"></i> Email must be unique to log in.</li>
          <li><i class="bi bi-check-circle mr-1"></i> You can review accounts in the students directory.</li>
        </ul>
        <a href="professor_college_students" class="admin-btn admin-btn--ghost admin-btn--sm mt-4"><i class="bi bi-arrow-right"></i> Open student directory</a>
      </aside>
    </div>
  </div>
  <script>
    (function () {
      var input = document.getElementById('profilePictureInput');
      var preview = document.getElementById('avatarPreview');
      var useDefault = document.getElementById('useDefaultAvatarInput');
      if (!input || !preview || !useDefault) return;
      function updateInputState() {
        input.disabled = !!useDefault.checked;
        if (useDefault.checked) {
          input.value = '';
        }
      }
      useDefault.addEventListener('change', updateInputState);
      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) { preview.src = String(e.target && e.target.result || ''); };
        reader.readAsDataURL(file);
        if (useDefault.checked) {
          useDefault.checked = false;
          updateInputState();
        }
      });
      updateInputState();
    })();
  </script>
</body>
</html>
