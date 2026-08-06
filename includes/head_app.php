<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($pageTitle) ? h($pageTitle) . ' - ' : ''; ?>LCRC eReview</title>
<link rel="icon" type="image/png" href="/image%20assets/lms-logo.png">
<link rel="apple-touch-icon" href="/image%20assets/lms-logo.png">
<?php
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$tailwindFile = __DIR__ . '/../assets/css/tailwind.css';
$arbitraryFile = __DIR__ . '/../assets/css/tailwind-arbitrary.css';
$appShellFile = __DIR__ . '/../assets/css/app-shell.css';
$fontsNunitoFile = __DIR__ . '/../assets/css/fonts-nunito.css';
$bootstrapIconsFile = __DIR__ . '/../assets/vendor/bootstrap-icons/bootstrap-icons.min.css';
$alpineJsFile = __DIR__ . '/../assets/js/alpine.min.js';
$studentTokensFile = __DIR__ . '/../assets/css/student-tokens.css';
$studentSaasFile = __DIR__ . '/../assets/css/student-saas.css';
$studentThemeJsFile = __DIR__ . '/../assets/js/student-theme.js';
$autoFilterFormsJsFile = __DIR__ . '/../assets/js/auto-filter-forms.js';
$scrollTopJsFile = __DIR__ . '/../assets/js/scroll-top.js';
$useBuiltCss = is_file($tailwindFile) && filesize($tailwindFile) > 1000;
$forceTailwindCdn = !empty($forceTailwindCdn);
$__headScript = strtolower((string) basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php'));
$loadStudentTheme = !empty($loadStudentTheme)
    || (strpos($__headScript, 'student_') === 0);
?>
<?php if ($useBuiltCss && !$forceTailwindCdn): ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/tailwind.css?v=<?php echo filemtime($tailwindFile); ?>">
<?php if (is_file($arbitraryFile)): ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/tailwind-arbitrary.css?v=<?php echo filemtime($arbitraryFile); ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/tailwind-arbitrary.css?v=<?php echo filemtime($arbitraryFile); ?>"></noscript>
<?php endif; ?>
<?php else: ?>
<script>
  tailwind = window.tailwind || {};
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          brand: { navy: '#0e3a55', 'navy-dark': '#0d2b55', gold: '#f2b01e', 'gold-light': '#ffd166' },
          primary: { DEFAULT: '#4154f1', dark: '#2d3fc7' },
          student: { sidebar: '#143D59', accent: '#1665A0', 'accent-hover': '#0f4d7a', 'accent-light': '#e8f2fa', danger: '#dc2626', 'danger-hover': '#b91c1c' }
        },
        fontFamily: { sans: ['Nunito', 'Segoe UI', 'sans-serif'] },
        boxShadow: {
          card: '0 2px 10px rgba(0,0,0,0.05)',
          'card-lg': '0 10px 24px rgba(0,0,0,0.06)',
          modal: '0 20px 50px rgba(0,0,0,0.12)',
          'student-card': '0 1px 3px rgba(20,61,89,0.08), 0 4px 12px rgba(20,61,89,0.06)',
          'student-card-hover': '0 4px 12px rgba(20,61,89,0.1), 0 8px 24px rgba(20,61,89,0.08)'
        }
      }
    }
  };
</script>
<script src="https://cdn.tailwindcss.com"></script>
<?php endif; ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/app-shell.css<?php echo is_file($appShellFile) ? '?v=' . filemtime($appShellFile) : ''; ?>">
<?php if (is_file($fontsNunitoFile)): ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/fonts-nunito.css?v=<?php echo filemtime($fontsNunitoFile); ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/fonts-nunito.css?v=<?php echo filemtime($fontsNunitoFile); ?>"></noscript>
<?php endif; ?>
<?php if (is_file($bootstrapIconsFile)): ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css?v=<?php echo filemtime($bootstrapIconsFile); ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="<?php echo h($base); ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css?v=<?php echo filemtime($bootstrapIconsFile); ?>"></noscript>
<?php else: ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" media="print" onload="this.media='all'">
<?php endif; ?>
<?php if ($loadStudentTheme): ?>
<script>
  (function () {
    try {
      var t = localStorage.getItem('ereview_student_theme');
      if (t !== 'light' && t !== 'dark') t = 'light';
      document.documentElement.setAttribute('data-student-theme', t);
      document.documentElement.style.colorScheme = t;
      document.documentElement.classList.add('student-theme-boot');
    } catch (e) {
      document.documentElement.setAttribute('data-student-theme', 'light');
    }
  })();
</script>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/student-tokens.css<?php echo is_file($studentTokensFile) ? '?v=' . filemtime($studentTokensFile) : ''; ?>">
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/student-saas.css<?php echo is_file($studentSaasFile) ? '?v=' . filemtime($studentSaasFile) : ''; ?>">
<script src="<?php echo h($base); ?>/assets/js/student-theme.js<?php echo is_file($studentThemeJsFile) ? '?v=' . filemtime($studentThemeJsFile) : ''; ?>" defer></script>
<?php endif; ?>
<?php if (is_file($autoFilterFormsJsFile)): ?>
<script src="<?php echo h($base); ?>/assets/js/auto-filter-forms.js?v=<?php echo filemtime($autoFilterFormsJsFile); ?>" defer></script>
<?php endif; ?>
<?php if (is_file($scrollTopJsFile)): ?>
<script src="<?php echo h($base); ?>/assets/js/scroll-top.js?v=<?php echo filemtime($scrollTopJsFile); ?>" defer></script>
<?php endif; ?>
<?php if (is_file($alpineJsFile)): ?>
<script defer src="<?php echo h($base); ?>/assets/js/alpine.min.js?v=<?php echo filemtime($alpineJsFile); ?>"></script>
<?php else: ?>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
<?php endif; ?>
<style>
  /*
    Uniform student box corner radius (less rounded / more admin-like).
    Scoped to the student app shell only so admin UI is unaffected.
  */
  .app-shell-main--student .rounded-2xl { border-radius: 0.75rem !important; }
  .app-shell-main--student .rounded-xl { border-radius: 0.625rem !important; }
  .app-shell-main--student .rounded-lg { border-radius: 0.5rem !important; }
  body { font-family: Nunito, "Segoe UI", system-ui, sans-serif; }
  <?php if ($loadStudentTheme): ?>
  html.student-theme-boot body { background: var(--student-bg, #e8eef6); }
  <?php endif; ?>
</style>
