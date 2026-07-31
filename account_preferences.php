<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/remember_me.php';
requireLogin();
$role = (string)($_SESSION['role'] ?? '');
$pageTitle = 'Preferences';
$csrf = generateCSRFToken();
$rememberMessage = null;
$rememberError = null;
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCSRFToken($postedToken)) {
        $rememberError = 'Invalid request. Please refresh and try again.';
    } elseif (isset($_POST['remember_revoke_all'])) {
        clearRememberMeForUserId($currentUserId);
        clearCurrentRememberedDevice($currentUserId);
        $rememberMessage = 'All remembered devices were signed out.';
    } elseif (isset($_POST['remember_revoke_one'])) {
        $tokenId = (int)($_POST['token_id'] ?? 0);
        if ($tokenId > 0 && revokeRememberedDevice($currentUserId, $tokenId)) {
            $rememberMessage = 'Selected remembered device was removed.';
        } else {
            $rememberError = 'Could not remove that remembered device.';
        }
    }
}

$rememberDevices = getRememberedDevices($currentUserId);

if (!in_array($role, ['student', 'college_student', 'admin', 'professor_admin'], true)) {
    header('Location: index');
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
      <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
        <h2 class="text-base font-bold text-slate-800 m-0">Remembered devices</h2>
        <p class="mt-1 text-xs text-slate-600">Manage browsers that can auto sign-in using Remember Me.</p>
        <?php if ($rememberMessage): ?><p class="mt-3 text-sm text-emerald-700"><?php echo h($rememberMessage); ?></p><?php endif; ?>
        <?php if ($rememberError): ?><p class="mt-3 text-sm text-rose-700"><?php echo h($rememberError); ?></p><?php endif; ?>
        <?php if (empty($rememberDevices)): ?>
          <p class="mt-3 text-sm text-slate-600">No active remembered devices.</p>
        <?php else: ?>
          <div class="mt-3 space-y-2">
            <?php foreach ($rememberDevices as $device): ?>
              <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="m-0 text-sm font-semibold text-slate-800">Token #<?php echo (int)$device['id']; ?> · Expires <?php echo h((string)($device['expires_at'] ?? '')); ?></p>
                <p class="m-0 mt-1 text-xs text-slate-600">Last used: <?php echo h((string)($device['last_used_at'] ?? ($device['created_at'] ?? 'unknown'))); ?></p>
                <?php if (!empty($device['last_used_ip'])): ?><p class="m-0 mt-1 text-xs text-slate-500">IP: <?php echo h((string)$device['last_used_ip']); ?></p><?php endif; ?>
                <form method="POST" action="account_preferences" class="mt-2">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                  <input type="hidden" name="token_id" value="<?php echo (int)$device['id']; ?>">
                  <button type="submit" name="remember_revoke_one" class="text-xs font-semibold text-rose-700 hover:underline">Sign out this device</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
          <form method="POST" action="account_preferences" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <button type="submit" name="remember_revoke_all" class="text-sm font-semibold text-rose-700 hover:underline">Sign out all remembered devices</button>
          </form>
        <?php endif; ?>
      </div>
      <p class="mt-6 mb-0"><a href="student_dashboard" class="inline-flex items-center gap-2 text-sm font-bold text-[#1665A0] hover:underline"><i class="bi bi-arrow-left"></i> Back to dashboard</a></p>
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
      <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
        <h2 class="text-base font-bold text-slate-800 m-0">Remembered devices</h2>
        <p class="mt-1 text-xs text-slate-600">Manage browsers that can auto sign-in using Remember Me.</p>
        <?php if ($rememberMessage): ?><p class="mt-3 text-sm text-emerald-700"><?php echo h($rememberMessage); ?></p><?php endif; ?>
        <?php if ($rememberError): ?><p class="mt-3 text-sm text-rose-700"><?php echo h($rememberError); ?></p><?php endif; ?>
        <?php if (empty($rememberDevices)): ?>
          <p class="mt-3 text-sm text-slate-600">No active remembered devices.</p>
        <?php else: ?>
          <div class="mt-3 space-y-2">
            <?php foreach ($rememberDevices as $device): ?>
              <div class="rounded-lg border border-slate-200 bg-white p-3">
                <p class="m-0 text-sm font-semibold text-slate-800">Token #<?php echo (int)$device['id']; ?> · Expires <?php echo h((string)($device['expires_at'] ?? '')); ?></p>
                <p class="m-0 mt-1 text-xs text-slate-600">Last used: <?php echo h((string)($device['last_used_at'] ?? ($device['created_at'] ?? 'unknown'))); ?></p>
                <?php if (!empty($device['last_used_ip'])): ?><p class="m-0 mt-1 text-xs text-slate-500">IP: <?php echo h((string)$device['last_used_ip']); ?></p><?php endif; ?>
                <form method="POST" action="account_preferences" class="mt-2">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                  <input type="hidden" name="token_id" value="<?php echo (int)$device['id']; ?>">
                  <button type="submit" name="remember_revoke_one" class="text-xs font-semibold text-rose-700 hover:underline">Sign out this device</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
          <form method="POST" action="account_preferences" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <button type="submit" name="remember_revoke_all" class="text-sm font-semibold text-rose-700 hover:underline">Sign out all remembered devices</button>
          </form>
        <?php endif; ?>
      </div>
      <p class="mt-6 mb-0"><a href="college_student_dashboard" class="inline-flex items-center gap-2 text-sm font-bold text-[#1665A0] hover:underline"><i class="bi bi-arrow-left"></i> Back to dashboard</a></p>
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
      <div class="mt-6 rounded-xl border border-white/15 bg-[#0b0b0b] p-4">
        <h2 class="text-base font-bold text-white m-0">Remembered devices</h2>
        <p class="mt-1 text-xs text-slate-400">Manage browsers that can auto sign-in using Remember Me.</p>
        <?php if ($rememberMessage): ?><p class="mt-3 text-sm text-emerald-400"><?php echo h($rememberMessage); ?></p><?php endif; ?>
        <?php if ($rememberError): ?><p class="mt-3 text-sm text-rose-400"><?php echo h($rememberError); ?></p><?php endif; ?>
        <?php if (empty($rememberDevices)): ?>
          <p class="mt-3 text-sm text-slate-400">No active remembered devices.</p>
        <?php else: ?>
          <div class="mt-3 space-y-2">
            <?php foreach ($rememberDevices as $device): ?>
              <div class="rounded-lg border border-white/10 bg-[#151515] p-3">
                <p class="m-0 text-sm font-semibold text-white">Token #<?php echo (int)$device['id']; ?> · Expires <?php echo h((string)($device['expires_at'] ?? '')); ?></p>
                <p class="m-0 mt-1 text-xs text-slate-400">Last used: <?php echo h((string)($device['last_used_at'] ?? ($device['created_at'] ?? 'unknown'))); ?></p>
                <?php if (!empty($device['last_used_ip'])): ?><p class="m-0 mt-1 text-xs text-slate-500">IP: <?php echo h((string)$device['last_used_ip']); ?></p><?php endif; ?>
                <form method="POST" action="account_preferences" class="mt-2">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                  <input type="hidden" name="token_id" value="<?php echo (int)$device['id']; ?>">
                  <button type="submit" name="remember_revoke_one" class="text-xs font-semibold text-rose-400 hover:underline">Sign out this device</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
          <form method="POST" action="account_preferences" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <button type="submit" name="remember_revoke_all" class="text-sm font-semibold text-rose-400 hover:underline">Sign out all remembered devices</button>
          </form>
        <?php endif; ?>
      </div>
      <p class="mt-6 mb-0"><a href="admin_dashboard" class="inline-flex items-center gap-2 text-sm font-bold text-sky-400 hover:underline"><i class="bi bi-arrow-left"></i> Back to dashboard</a></p>
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
        <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50/40 p-4">
          <h2 class="text-base font-bold text-emerald-900 m-0">Remembered devices</h2>
          <p class="mt-1 text-xs text-emerald-800/80">Manage browsers that can auto sign-in using Remember Me.</p>
          <?php if ($rememberMessage): ?><p class="mt-3 text-sm text-emerald-700"><?php echo h($rememberMessage); ?></p><?php endif; ?>
          <?php if ($rememberError): ?><p class="mt-3 text-sm text-rose-700"><?php echo h($rememberError); ?></p><?php endif; ?>
          <?php if (empty($rememberDevices)): ?>
            <p class="mt-3 text-sm text-slate-600">No active remembered devices.</p>
          <?php else: ?>
            <div class="mt-3 space-y-2">
              <?php foreach ($rememberDevices as $device): ?>
                <div class="rounded-lg border border-emerald-200 bg-white p-3">
                  <p class="m-0 text-sm font-semibold text-slate-800">Token #<?php echo (int)$device['id']; ?> · Expires <?php echo h((string)($device['expires_at'] ?? '')); ?></p>
                  <p class="m-0 mt-1 text-xs text-slate-600">Last used: <?php echo h((string)($device['last_used_at'] ?? ($device['created_at'] ?? 'unknown'))); ?></p>
                  <?php if (!empty($device['last_used_ip'])): ?><p class="m-0 mt-1 text-xs text-slate-500">IP: <?php echo h((string)$device['last_used_ip']); ?></p><?php endif; ?>
                  <form method="POST" action="account_preferences" class="mt-2">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="token_id" value="<?php echo (int)$device['id']; ?>">
                    <button type="submit" name="remember_revoke_one" class="text-xs font-semibold text-rose-700 hover:underline">Sign out this device</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
            <form method="POST" action="account_preferences" class="mt-3">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <button type="submit" name="remember_revoke_all" class="text-sm font-semibold text-rose-700 hover:underline">Sign out all remembered devices</button>
            </form>
          <?php endif; ?>
        </div>
        <p class="mt-6 mb-0"><a href="professor_admin_dashboard" class="inline-flex items-center gap-2 text-sm font-bold text-emerald-800 hover:underline"><i class="bi bi-arrow-left"></i> Back to dashboard</a></p>
      </div>
    </div>
  </main>
</body>
</html>
