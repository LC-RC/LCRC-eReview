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
// Full quiz-ui CSS only where quiz/content shells need it; admin-saas covers shared surfaces.
$adminQuizUiScripts = [
    'admin_quizzes', 'admin_quiz_questions', 'admin_question_sort', 'admin_test_bank',
    'admin_materials', 'admin_videos', 'admin_handouts', 'admin_lessons', 'admin_subjects',
    'admin_preweek', 'admin_preweek_topics', 'admin_preweek_materials',
    'admin_preboards_subjects', 'admin_preboards_sets', 'admin_preboards_questions',
    'admin_preboards_monitor', 'admin_preboards_attempt_review',
    'admin_commerce_packages', 'admin_commerce_topics', 'admin_commerce_payments',
    'admin_commerce_free_access', 'admin_commerce_gcash', 'admin_commerce_grants',
    'admin_commerce_reports', 'admin_student_access', 'admin_support_analytics',
];
$loadAdminQuizUiCss = empty($adminSkipQuizUiCss)
    && (!empty($adminLoadQuizUiCss) || in_array($adminScript, $adminQuizUiScripts, true));
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
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-components.css<?php echo is_file($adminComponentsFile) ? '?v=' . filemtime($adminComponentsFile) : ''; ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-components.css<?php echo is_file($adminComponentsFile) ? '?v=' . filemtime($adminComponentsFile) : ''; ?>"></noscript>
<?php if ($loadAdminQuizUiCss && is_file($adminQuizUiFile)): ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-quiz-ui.css?v=<?php echo filemtime($adminQuizUiFile); ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-quiz-ui.css?v=<?php echo filemtime($adminQuizUiFile); ?>"></noscript>
<?php endif; ?>
<?php if ($loadAdminStudentsCss && is_file($adminStudentsCssFile)): ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-students.css?v=<?php echo filemtime($adminStudentsCssFile); ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-students.css?v=<?php echo filemtime($adminStudentsCssFile); ?>"></noscript>
<?php endif; ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-saas.css<?php echo is_file($adminSaasFile) ? '?v=' . filemtime($adminSaasFile) : ''; ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/admin-saas.css<?php echo is_file($adminSaasFile) ? '?v=' . filemtime($adminSaasFile) : ''; ?>"></noscript>
<script src="<?php echo h($base); ?>/assets/js/admin-theme.js<?php echo is_file($adminThemeJsFile) ? '?v=' . filemtime($adminThemeJsFile) : ''; ?>" defer></script>
