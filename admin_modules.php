<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/ereview_app_settings.php';
require_once __DIR__ . '/includes/student_playground.php';
requireAdminPage();

$csrf = generateCSRFToken();
ereview_app_settings_ensure_schema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_modules');
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_playground') {
        $enabled = !empty($_POST['playground_enabled']) ? '1' : '0';
        if (ereview_app_setting_set($conn, 'playground_enabled', $enabled)) {
            $_SESSION['message'] = $enabled === '1'
                ? 'CPA Playground is now enabled for students.'
                : 'CPA Playground is now disabled for students.';
        } else {
            $_SESSION['error'] = 'Could not save module setting.';
        }
    }
    header('Location: admin_modules');
    exit;
}

$playgroundEnabled = student_playground_is_enabled($conn);
$pageTitle = 'Modules';
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard'],
    ['Modules'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app">
  <?php include 'admin_sidebar.php'; ?>

  <?php
    $adminHeroIcon = 'toggles';
    $adminHeroEyebrow = 'Student modules';
    $adminHeroTitle = 'Student Modules';
    $adminHeroSubtitle = 'Student-facing feature flags (for example CPA Playground). This is not curriculum content — manage lessons in Content Hub.';
    $adminHeroMeta = '';
    $adminHeroActions = '';
    include __DIR__ . '/includes/components/admin_page_hero.php';
  ?>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--success mb-5 flex items-center gap-2">
      <i class="bi bi-check-circle-fill shrink-0"></i><span><?php echo h($_SESSION['message']); unset($_SESSION['message']); ?></span>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--error mb-5 flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill shrink-0"></i><span><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?></span>
    </div>
  <?php endif; ?>

  <section class="content-library" aria-labelledby="modules-playground-heading">
    <div class="content-library__head">
      <div>
        <h2 id="modules-playground-heading" class="content-library__title">CPA Playground</h2>
        <p class="content-library__sub">Includes Solo Playground and CPA Battle. When disabled, the sidebar link is hidden and students cannot open Playground pages.</p>
      </div>
      <span class="content-library__count"><?php echo $playgroundEnabled ? 'Enabled' : 'Disabled'; ?></span>
    </div>
    <form method="POST" action="admin_modules" class="content-library__toolbar modules-playground-form">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="save_playground">
      <label class="inline-flex items-center gap-2.5 cursor-pointer rounded-xl border border-slate-200 bg-white px-3 py-2">
        <input
          type="checkbox"
          name="playground_enabled"
          value="1"
          class="accent-violet-500"
          <?php echo $playgroundEnabled ? 'checked' : ''; ?>
        >
        <span class="text-sm font-semibold text-slate-900">Enable CPA Playground for students</span>
      </label>
      <button type="submit" class="admin-btn admin-btn--primary"><i class="bi bi-save"></i> Save</button>
    </form>
  </section>
</div>
</main>
</body>
</html>
