<?php
require_once __DIR__ . '/url_helpers.php';
require_once __DIR__ . '/head_app.php';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$adminTokensFile = __DIR__ . '/../assets/css/admin-tokens.css';
$adminComponentsFile = __DIR__ . '/../assets/css/admin-components.css';
$adminCssFile = __DIR__ . '/../assets/css/admin.css';
$adminQuizUiFile = __DIR__ . '/../assets/css/admin-quiz-ui.css';
$adminStudentsCssFile = __DIR__ . '/../assets/css/admin-students.css';
$adminSaasFile = __DIR__ . '/../assets/css/admin-saas.css';
$adminThemeJsFile = __DIR__ . '/../assets/js/admin-theme.js';

$adminScript = strtolower((string) (function_exists('ereview_page_basename') ? ereview_page_basename() : basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php')));
$loadAdminStudentsCss = in_array($adminScript, ['admin_students', 'admin_student_view'], true)
    || !empty($adminLoadStudentsCss);
// quiz-admin styles are used broadly across commerce/content admin pages
$loadAdminQuizUiCss = empty($adminSkipQuizUiCss);
?>
<script>
  (function () {
    try {
      var t = localStorage.getItem('ereview_admin_theme');
      if (t !== 'light' && t !== 'dark') {
        t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
      }
      document.documentElement.setAttribute('data-admin-theme', t);
      document.documentElement.style.colorScheme = t;
    } catch (e) {
      document.documentElement.setAttribute('data-admin-theme', 'dark');
    }
  })();
</script>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-tokens.css<?php echo is_file($adminTokensFile) ? '?v=' . filemtime($adminTokensFile) : ''; ?>">
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin.css<?php echo is_file($adminCssFile) ? '?v=' . filemtime($adminCssFile) : ''; ?>">
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-components.css<?php echo is_file($adminComponentsFile) ? '?v=' . filemtime($adminComponentsFile) : ''; ?>">
<?php if ($loadAdminQuizUiCss && is_file($adminQuizUiFile)): ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-quiz-ui.css?v=<?php echo filemtime($adminQuizUiFile); ?>">
<?php endif; ?>
<?php if ($loadAdminStudentsCss && is_file($adminStudentsCssFile)): ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-students.css?v=<?php echo filemtime($adminStudentsCssFile); ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-saas.css<?php echo is_file($adminSaasFile) ? '?v=' . filemtime($adminSaasFile) : ''; ?>">
<script src="<?php echo h($base); ?>/assets/js/admin-theme.js<?php echo is_file($adminThemeJsFile) ? '?v=' . filemtime($adminThemeJsFile) : ''; ?>" defer></script>
