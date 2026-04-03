<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'Add college student';
$csrf = generateCSRFToken();
$error = null;
$success = null;
$avatarPreviewPath = '';
$avatarUseDefault = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = 'Invalid request.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $studentNumber = trim($_POST['student_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $school = trim($_POST['school'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $avatarUseDefault = !empty($_POST['use_default_avatar']) ? 1 : 0;
        $profilePicturePath = '';
        $uploadedAvatar = $_FILES['profile_picture'] ?? null;

        if ($fullName === '' || $section === '' || $school === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid full name, section, school, and email.';
        } elseif ($studentNumber !== '' && (strlen($studentNumber) > 32 || !preg_match('/^[A-Za-z0-9_-]+$/', $studentNumber))) {
            $error = 'Student number must be at most 32 characters and use only letters, digits, hyphen, or underscore.';
        } elseif ($password === '' || strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($confirmPassword === '' || $confirmPassword !== $password) {
            $error = 'Passwords do not match.';
        } else {
            if ($avatarUseDefault !== 1 && $uploadedAvatar && !empty($uploadedAvatar['name'])) {
                $errCode = (int)($uploadedAvatar['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($errCode !== UPLOAD_ERR_OK) {
                    $error = 'Could not upload profile picture.';
                } else {
                    $tmpFile = (string)($uploadedAvatar['tmp_name'] ?? '');
                    $origName = (string)($uploadedAvatar['name'] ?? '');
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                    if (!in_array($ext, $allowedExt, true)) {
                        $error = 'Profile picture must be JPG, PNG, WEBP, or GIF.';
                    } elseif (!is_uploaded_file($tmpFile)) {
                        $error = 'Invalid profile picture upload.';
                    } else {
                        $size = (int)($uploadedAvatar['size'] ?? 0);
                        if ($size <= 0 || $size > (4 * 1024 * 1024)) {
                            $error = 'Profile picture must be up to 4MB.';
                        } else {
                            $uploadDirAbs = __DIR__ . '/uploads/profile_pictures';
                            if (!is_dir($uploadDirAbs)) {
                                @mkdir($uploadDirAbs, 0775, true);
                            }
                            if (!is_dir($uploadDirAbs) || !is_writable($uploadDirAbs)) {
                                $error = 'Profile picture folder is not writable.';
                            } else {
                                $fileBase = 'college_student_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5));
                                $destAbs = $uploadDirAbs . '/' . $fileBase . '.' . $ext;
                                if (!@move_uploaded_file($tmpFile, $destAbs)) {
                                    $error = 'Failed to save profile picture.';
                                } else {
                                    $profilePicturePath = 'uploads/profile_pictures/' . basename($destAbs);
                                    $avatarPreviewPath = $profilePicturePath;
                                }
                            }
                        }
                    }
                }
            } elseif ($avatarUseDefault !== 1) {
                $error = 'Please upload a profile picture or enable default avatar.';
            }
        }
        if ($error === null) {
            $avatarPreviewPath = $profilePicturePath;
        }
        if ($error === null) {
            $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email=? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
                mysqli_stmt_close($stmt);
                $error = 'That email is already registered.';
            } else {
                mysqli_stmt_close($stmt);
                if ($error === null && $studentNumber !== '') {
                    $chkSn = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE student_number=? LIMIT 1');
                    mysqli_stmt_bind_param($chkSn, 's', $studentNumber);
                    mysqli_stmt_execute($chkSn);
                    if (mysqli_fetch_assoc(mysqli_stmt_get_result($chkSn))) {
                        mysqli_stmt_close($chkSn);
                        $error = 'That student number is already assigned.';
                    } else {
                        mysqli_stmt_close($chkSn);
                    }
                }
            }
            if ($error === null) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $hasEv = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_verified'");
                $evCol = $hasEv && mysqli_fetch_assoc($hasEv);

                $hasPp = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_picture'");
                $ppCol = $hasPp && mysqli_fetch_assoc($hasPp);
                $hasUa = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'use_default_avatar'");
                $uaCol = $hasUa && mysqli_fetch_assoc($hasUa);

                if ($evCol && $ppCol && $uaCol) {
                    $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, profile_picture, use_default_avatar, email, password, role, status, email_verified) VALUES (?, 'reviewee', ?, ?, NULL, NULL, ?, ?, ?, ?, 'college_student', 'approved', 1)");
                    mysqli_stmt_bind_param($ins, 'sssisss', $fullName, $school, $section, $profilePicturePath, $avatarUseDefault, $email, $hash);
                } elseif ($ppCol && $uaCol) {
                    $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, profile_picture, use_default_avatar, email, password, role, status) VALUES (?, 'reviewee', ?, ?, NULL, NULL, ?, ?, ?, ?, 'college_student', 'approved')");
                    mysqli_stmt_bind_param($ins, 'sssisss', $fullName, $school, $section, $profilePicturePath, $avatarUseDefault, $email, $hash);
                } elseif ($evCol) {
                    $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, email, password, role, status, email_verified) VALUES (?, 'reviewee', ?, ?, NULL, NULL, ?, ?, 'college_student', 'approved', 1)");
                    mysqli_stmt_bind_param($ins, 'sssss', $fullName, $school, $section, $email, $hash);
                } else {
                    $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, email, password, role, status) VALUES (?, 'reviewee', ?, ?, NULL, NULL, ?, ?, 'college_student', 'approved')");
                    mysqli_stmt_bind_param($ins, 'sssss', $fullName, $school, $section, $email, $hash);
                }
                if ($ins && mysqli_stmt_execute($ins)) {
                    $newId = (int)mysqli_insert_id($conn);
                    mysqli_stmt_close($ins);
                    if ($newId > 0 && $studentNumber !== '') {
                        $updSn = mysqli_prepare($conn, 'UPDATE users SET student_number=? WHERE user_id=?');
                        mysqli_stmt_bind_param($updSn, 'si', $studentNumber, $newId);
                        mysqli_stmt_execute($updSn);
                        mysqli_stmt_close($updSn);
                    }
                    $success = 'Account created. The student can sign in at the main login page.';
                    $_POST = [];
                    $avatarPreviewPath = '';
                    $avatarUseDefault = 1;
                } else {
                    $error = 'Could not create account.';
                    if ($ins) {
                        mysqli_stmt_close($ins);
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .prof-page { background: linear-gradient(180deg, #eefaf3 0%, #e6f6ee 45%, #edf9f2 100%); min-height: 100%; }
    .dashboard-shell { padding-bottom: 1.5rem; color: #0f172a; }
    .prof-hero {
      border-radius: 0.75rem; border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #0f766e 0%, #0e9f6e 35%, #16a34a 75%, #15803d 100%);
      box-shadow: 0 14px 34px -20px rgba(5,46,22,.75), inset 0 1px 0 rgba(255,255,255,.22);
    }
    .prof-icon { background: rgba(255,255,255,.22); border: 1px solid rgba(255,255,255,.34); color: #fff; }
    .panel-card {
      border-radius: .75rem; border: 1px solid rgba(22,163,74,.22);
      background: linear-gradient(180deg, #f4fff8 0%, #fff 42%);
      box-shadow: 0 10px 28px -22px rgba(21,128,61,.58), 0 1px 0 rgba(255,255,255,.8) inset;
    }
    .field { border-color: #bbf7d0; }
    .field:focus { border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.15); outline: none; }
    .save-btn { border-radius: .6rem; transition: transform .2s ease, box-shadow .2s ease; }
    .save-btn:hover { transform: translateY(-1px); box-shadow: 0 12px 20px -18px rgba(21,128,61,.9); }
    .avatar-zone { border:1px dashed #86efac; border-radius:.75rem; padding:.9rem; background:#f7fff9; transition:border-color .2s ease,background-color .2s ease; }
    .avatar-zone:hover { border-color:#22c55e; background:#f0fdf4; }
    .avatar-preview { width:64px; height:64px; border-radius:999px; border:2px solid #bbf7d0; object-fit:cover; background:#ecfdf5; }
    .pill-toggle { display:inline-flex; align-items:center; gap:.45rem; font-size:.76rem; font-weight:700; color:#166534; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:999px; padding:.24rem .56rem; }
    .dash-anim { opacity: 0; transform: translateY(12px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; }
    .delay-2 { animation-delay: .12s; }
    .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
    }
  </style>
</head>
<body class="font-sans antialiased prof-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <main class="dashboard-shell w-full max-w-none">
    <div class="mb-6 dash-anim delay-1">
      <a href="professor_college_students.php" class="inline-flex items-center gap-1 text-sm font-semibold text-green-700 hover:underline mb-4"><i class="bi bi-arrow-left"></i> Back</a>

      <div class="prof-hero overflow-hidden">
        <div class="p-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex items-start gap-3">
            <div class="prof-icon w-11 h-11 rounded-xl flex items-center justify-center shrink-0">
              <i class="bi bi-person-plus text-xl"></i>
            </div>
            <div>
              <h1 class="text-2xl font-bold text-white m-0 leading-tight">Create college student</h1>
              <p class="text-white/90 mt-1 mb-0">Creates an approved account with role <code class="text-sm bg-white/20 px-1 rounded">college_student</code>.</p>
            </div>
          </div>
          <div class="hidden sm:block"></div>
        </div>
      </div>
    </div>

    <?php if ($error): ?><div class="mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-900 dash-anim delay-2"><?php echo h($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 dash-anim delay-2"><?php echo h($success); ?></div><?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
      <form method="post" enctype="multipart/form-data" class="panel-card xl:col-span-2 p-6 space-y-5 dash-anim delay-2">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <div class="avatar-zone">
          <div class="flex flex-wrap items-start gap-3">
            <img id="avatarPreview" class="avatar-preview" src="<?php echo $avatarPreviewPath !== '' ? h($avatarPreviewPath) : 'data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22><rect width=%2264%22 height=%2264%22 rx=%2232%22 fill=%22%23ecfdf5%22/><text x=%2250%25%22 y=%2252%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 font-family=%22Arial%22 font-size=%2228%22 fill=%22%23166534%22>+</text></svg>'; ?>" alt="Profile preview">
            <div class="flex-1 min-w-[230px]">
              <label class="block text-sm font-semibold text-green-800 mb-1">Profile picture</label>
              <input type="file" id="profilePictureInput" name="profile_picture" accept="image/jpeg,image/png,image/webp,image/gif" class="field w-full rounded-lg border px-3 py-2 text-sm">
              <div class="mt-2">
                <label class="pill-toggle">
                  <input type="checkbox" id="useDefaultAvatarInput" name="use_default_avatar" value="1" <?php echo $avatarUseDefault ? 'checked' : ''; ?>>
                  Use default avatar
                </label>
              </div>
              <p class="text-xs text-slate-500 mt-2 mb-0">Supported: JPG, PNG, WEBP, GIF (max 4MB)</p>
            </div>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-semibold text-green-800 mb-1">Full name</label>
            <input type="text" name="full_name" required class="field w-full rounded-lg border px-3 py-2 text-sm" value="<?php echo h($_POST['full_name'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold text-green-800 mb-1">Section</label>
            <input type="text" name="section" required class="field w-full rounded-lg border px-3 py-2 text-sm" value="<?php echo h($_POST['section'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold text-green-800 mb-1">Student number <span class="text-slate-500 font-normal">(optional)</span></label>
            <input type="text" name="student_number" maxlength="32" pattern="[A-Za-z0-9_-]*" class="field w-full rounded-lg border px-3 py-2 text-sm" placeholder="e.g. 2008435" value="<?php echo h($_POST['student_number'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold text-green-800 mb-1">Email (login)</label>
            <input type="email" name="email" required autocomplete="off" class="field w-full rounded-lg border px-3 py-2 text-sm" value="<?php echo h($_POST['email'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold text-green-800 mb-1">School</label>
            <input type="text" name="school" required class="field w-full rounded-lg border px-3 py-2 text-sm" value="<?php echo h($_POST['school'] ?? ''); ?>">
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-semibold text-green-800 mb-1">Password</label>
            <input type="password" name="password" required minlength="8" class="field w-full rounded-lg border px-3 py-2 text-sm" autocomplete="new-password">
          </div>
          <div>
            <label class="block text-sm font-semibold text-green-800 mb-1">Confirm password</label>
            <input type="password" name="confirm_password" required minlength="8" class="field w-full rounded-lg border px-3 py-2 text-sm" autocomplete="new-password">
          </div>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
          <button type="submit" class="save-btn inline-flex justify-center items-center gap-2 px-5 py-2.5 font-semibold bg-green-600 text-white hover:bg-green-700 transition-all shadow-sm">
            <i class="bi bi-check2-circle"></i> Create account
          </button>
        </div>
      </form>

      <aside class="panel-card p-5 dash-anim delay-3">
        <h2 class="text-lg font-bold text-green-800 m-0">Account setup notes</h2>
        <ul class="mt-3 text-sm text-gray-700 space-y-2">
          <li><i class="bi bi-check-circle text-green-700 mr-1"></i> Student is created as <strong>approved</strong>.</li>
          <li><i class="bi bi-check-circle text-green-700 mr-1"></i> Minimum password length is 8 characters.</li>
          <li><i class="bi bi-check-circle text-green-700 mr-1"></i> Email must be unique to log in.</li>
          <li><i class="bi bi-check-circle text-green-700 mr-1"></i> You can review accounts in the students directory.</li>
        </ul>
        <a href="professor_college_students.php" class="inline-flex items-center gap-2 mt-4 text-sm font-semibold text-green-700 hover:underline">
          Open student directory <i class="bi bi-arrow-right"></i>
        </a>
      </aside>
    </div>
  </main>
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
