<?php
require_once __DIR__ . '/auth.php';
requireLogin();
$role = (string)($_SESSION['role'] ?? '');
$pageTitle = 'Preferences';

if (!in_array($role, ['student', 'college_student', 'admin', 'professor_admin'], true)) {
    header('Location: index.php');
    exit;
}

$blurb = 'Notification preferences and display options will appear here in a future update. For now, use the bell in the top bar to read announcements and system messages.';
if ($role === 'admin' || $role === 'professor_admin') {
    $blurb = 'Staff preferences (defaults, notifications, and display) will be configurable here. Alerts from the bell remain the fastest way to see pending work.';
}

if ($role === 'student') {
    requireRole('student');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .ereview-static-hero {
      border-radius: 1rem;
      border: 1px solid rgba(255, 255, 255, 0.22);
      background: linear-gradient(130deg, #1665a0 0%, #145a8f 38%, #143d59 100%);
      box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85);
    }
  </style>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/student_sidebar.php'; ?>
  <div class="student-dashboard-page min-h-full pb-10 px-1 max-w-3xl ereview-static-page">
    <section class="ereview-static-hero mb-6 px-5 py-6 rounded-2xl text-white">
      <h1 class="text-2xl font-extrabold m-0 flex items-center gap-3"><i class="bi bi-sliders"></i> Preferences</h1>
      <p class="text-white/90 mt-2 mb-0 text-sm leading-relaxed">Customize how LCRC eReview works for you.</p>
    </section>
    <div class="ereview-static-card px-6 py-6 rounded-2xl border border-slate-200/80 bg-white shadow-[0_12px_40px_-24px_rgba(15,23,42,0.25)]">
      <p class="text-slate-600 m-0 text-sm leading-relaxed"><?php echo h($blurb); ?></p>
      <p class="mt-6 mb-0"><a href="student_dashboard.php" class="inline-flex items-center gap-2 text-sm font-bold text-[#1665A0] hover:underline"><i class="bi bi-arrow-left"></i> Back to dashboard</a></p>
    </div>
  </div>
</main>
</body>
</html>
    <?php
    exit;
}

if ($role === 'college_student') {
    requireRole('college_student');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .ereview-static-hero {
      border-radius: 1rem;
      border: 1px solid rgba(255, 255, 255, 0.22);
      background: linear-gradient(130deg, #1665a0 0%, #145a8f 38%, #143d59 100%);
      box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85);
    }
  </style>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>
  <div class="student-dashboard-page min-h-full pb-10 px-1 max-w-3xl ereview-static-page">
    <section class="ereview-static-hero mb-6 px-5 py-6 rounded-2xl text-white">
      <h1 class="text-2xl font-extrabold m-0 flex items-center gap-3"><i class="bi bi-sliders"></i> Preferences</h1>
      <p class="text-white/90 mt-2 mb-0 text-sm leading-relaxed">College portal settings.</p>
    </section>
    <div class="ereview-static-card px-6 py-6 rounded-2xl border border-slate-200/80 bg-white shadow-[0_12px_40px_-24px_rgba(15,23,42,0.25)]">
      <p class="text-slate-600 m-0 text-sm leading-relaxed"><?php echo h($blurb); ?></p>
      <p class="mt-6 mb-0"><a href="college_student_dashboard.php" class="inline-flex items-center gap-2 text-sm font-bold text-[#1665A0] hover:underline"><i class="bi bi-arrow-left"></i> Back to dashboard</a></p>
    </div>
  </div>
</main>
</body>
</html>
    <?php
    exit;
}

if ($role === 'admin') {
    requireRole('admin');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    .ereview-static-hero-admin {
      border-radius: 1rem;
      border: 1px solid rgba(255, 255, 255, 0.12);
      background: radial-gradient(120% 140% at 0% 0%, rgba(52, 211, 153, 0.15) 0%, transparent 55%),
        linear-gradient(130deg, #1e293b 0%, #0f172a 100%);
      box-shadow: 0 18px 44px rgba(0, 0, 0, 0.5);
    }
  </style>
</head>
<body class="font-sans antialiased admin-app">
  <?php include __DIR__ . '/admin_sidebar.php'; ?>
  <div class="max-w-3xl px-5 pb-10">
    <section class="ereview-static-hero-admin mb-6 px-5 py-6">
      <h1 class="text-2xl font-extrabold m-0 flex items-center gap-3 text-white"><i class="bi bi-sliders"></i> Preferences</h1>
      <p class="text-white/80 mt-2 mb-0 text-sm leading-relaxed">Administrator account settings (coming soon).</p>
    </section>
    <div class="px-6 py-6 rounded-2xl border border-white/10 bg-[#111] text-slate-200 shadow-xl">
      <p class="m-0 text-sm leading-relaxed text-slate-300"><?php echo h($blurb); ?></p>
      <p class="mt-6 mb-0"><a href="admin_dashboard.php" class="inline-flex items-center gap-2 text-sm font-bold text-sky-400 hover:underline"><i class="bi bi-arrow-left"></i> Back to dashboard</a></p>
    </div>
  </div>
  </div>
</main>
</body>
</html>
    <?php
    exit;
}

requireRole('professor_admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    .ereview-static-hero-prof {
      border-radius: 1rem;
      border: 1px solid rgba(22, 163, 74, 0.28);
      background: linear-gradient(130deg, #0f766e 0%, #0e9f6e 40%, #15803d 100%);
      box-shadow: 0 14px 34px -20px rgba(5, 46, 22, 0.75);
    }
    .ereview-static-card-prof {
      border-radius: 1rem;
      border: 1px solid rgba(22, 163, 74, 0.2);
      background: linear-gradient(180deg, #f4fff8 0%, #fff 55%);
      box-shadow: 0 12px 32px -22px rgba(21, 128, 61, 0.45);
    }
  </style>
</head>
<body class="font-sans antialiased prof-dashboard-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>
  <main class="dashboard-shell w-full max-w-none">
    <div class="px-4 md:px-6 pb-10 max-w-3xl">
      <section class="ereview-static-hero-prof mb-6 px-5 py-6 text-white">
        <h1 class="text-2xl font-extrabold m-0 flex items-center gap-3"><i class="bi bi-sliders"></i> Preferences</h1>
        <p class="text-white/90 mt-2 mb-0 text-sm leading-relaxed">Professor workspace settings (coming soon).</p>
      </section>
      <div class="ereview-static-card-prof px-6 py-6 text-slate-800">
        <p class="m-0 text-sm leading-relaxed text-slate-600"><?php echo h($blurb); ?></p>
        <p class="mt-6 mb-0"><a href="professor_admin_dashboard.php" class="inline-flex items-center gap-2 text-sm font-bold text-emerald-800 hover:underline"><i class="bi bi-arrow-left"></i> Back to dashboard</a></p>
      </div>
    </div>
  </main>
</body>
</html>
