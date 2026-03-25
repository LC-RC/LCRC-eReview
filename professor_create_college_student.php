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
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid name and email.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
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
                $school = 'College';
                $hasEv = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'email_verified'");
                $evCol = $hasEv && mysqli_fetch_assoc($hasEv);
                if ($evCol) {
                    $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, school_other, payment_proof, email, password, role, status, email_verified) VALUES (?, 'reviewee', ?, NULL, NULL, ?, ?, 'college_student', 'approved', 1)");
                    mysqli_stmt_bind_param($ins, 'ssss', $fullName, $school, $email, $hash);
                } else {
                    $ins = mysqli_prepare($conn, "INSERT INTO users (full_name, review_type, school, school_other, payment_proof, email, password, role, status) VALUES (?, 'reviewee', ?, NULL, NULL, ?, ?, 'college_student', 'approved')");
                    mysqli_stmt_bind_param($ins, 'ssss', $fullName, $school, $email, $hash);
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
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <div class="admin-content max-w-lg mx-auto w-full px-4 lg:px-6">
    <a href="professor_college_students.php" class="inline-flex items-center gap-1 text-sm font-semibold text-green-700 hover:underline mb-4"><i class="bi bi-arrow-left"></i> Back</a>

    <h1 class="text-2xl font-bold text-green-800 m-0">Create college student</h1>
    <p class="text-gray-600 mt-1 mb-6">Creates an approved account with role <code class="text-sm bg-gray-100 px-1 rounded">college_student</code>.</p>

    <?php if ($error): ?><div class="mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-900"><?php echo h($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900"><?php echo h($success); ?></div><?php endif; ?>

    <form method="post" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Full name</label>
        <input type="text" name="full_name" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" value="<?php echo h($_POST['full_name'] ?? ''); ?>">
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Email (login)</label>
        <input type="email" name="email" required autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" value="<?php echo h($_POST['email'] ?? ''); ?>">
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Password</label>
        <input type="password" name="password" required minlength="8" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" autocomplete="new-password">
      </div>
      <button type="submit" class="w-full sm:w-auto inline-flex justify-center items-center gap-2 px-5 py-2.5 rounded-xl font-semibold bg-green-600 text-white hover:bg-green-700 transition">Create account</button>
    </form>
  </div>
</main>
</body>
</html>
