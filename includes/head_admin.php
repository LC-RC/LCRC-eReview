<?php
require_once __DIR__ . '/head_app.php';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$adminTokensFile = __DIR__ . '/../assets/css/admin-tokens.css';
$adminComponentsFile = __DIR__ . '/../assets/css/admin-components.css';
$adminCssFile = __DIR__ . '/../assets/css/admin.css';
$adminQuizUiFile = __DIR__ . '/../assets/css/admin-quiz-ui.css';
$adminStudentsCssFile = __DIR__ . '/../assets/css/admin-students.css';
$adminSaasFile = __DIR__ . '/../assets/css/admin-saas.css';
$adminThemeJsFile = __DIR__ . '/../assets/js/admin-theme.js';
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
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-tokens.css<?php echo file_exists($adminTokensFile) ? '?v=' . filemtime($adminTokensFile) : ''; ?>">
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin.css<?php echo file_exists($adminCssFile) ? '?v=' . filemtime($adminCssFile) : ''; ?>">
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-components.css<?php echo file_exists($adminComponentsFile) ? '?v=' . filemtime($adminComponentsFile) : ''; ?>">
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-quiz-ui.css<?php echo file_exists($adminQuizUiFile) ? '?v=' . filemtime($adminQuizUiFile) : ''; ?>">
<?php if (file_exists($adminStudentsCssFile)): ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-students.css?v=<?php echo filemtime($adminStudentsCssFile); ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-saas.css<?php echo file_exists($adminSaasFile) ? '?v=' . filemtime($adminSaasFile) : ''; ?>">
<script src="<?php echo h($base); ?>/assets/js/admin-theme.js<?php echo file_exists($adminThemeJsFile) ? '?v=' . filemtime($adminThemeJsFile) : ''; ?>" defer></script>
