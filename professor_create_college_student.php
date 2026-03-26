<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'Add college student';
$csrf = generateCSRFToken();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = 'Invalid request.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $school = trim($_POST['school'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($fullName === '' || $section === '' || $school === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid full name, section, school, and email.';
        } elseif ($password === '' || strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($confirmPassword === '' || $confirmPassword !== $password) {
            $error = 'Passwords do not match.';
        } else {
            $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email=? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
                mysqli_stmt_close($stmt);
                $error = 'That email is already registered.';
            } else {
                mysqli_stmt_close($stmt);
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $hasEv = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_verified'");
                $evCol = $hasEv && mysqli_fetch_assoc($hasEv);
                if ($evCol) {
                    $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, email, password, role, status, email_verified) VALUES (?, 'reviewee', ?, ?, NULL, NULL, ?, ?, 'college_student', 'approved', 1)");
                    mysqli_stmt_bind_param($ins, 'sssss', $fullName, $school, $section, $email, $hash);
                } else {
                    $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, section, school_other, payment_proof, email, password, role, status) VALUES (?, 'reviewee', ?, ?, NULL, NULL, ?, ?, 'college_student', 'approved')");
                    mysqli_stmt_bind_param($ins, 'sssss', $fullName, $school, $section, $email, $hash);
                }
                if ($ins && mysqli_stmt_execute($ins)) {
                    mysqli_stmt_close($ins);
                    $success = 'Account created. The student can sign in at the main login page.';
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
      <form method="post" class="panel-card xl:col-span-2 p-6 space-y-5 dash-anim delay-2">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
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
</body>
</html>
