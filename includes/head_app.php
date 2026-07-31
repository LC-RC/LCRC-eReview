<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($pageTitle) ? h($pageTitle) . ' - ' : ''; ?>LCRC eReview</title>
<link rel="icon" type="image/png" href="/image%20assets/lms-logo.png">
<link rel="apple-touch-icon" href="/image%20assets/lms-logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
<?php
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$tailwindFile = __DIR__ . '/../assets/css/tailwind.css';
$arbitraryFile = __DIR__ . '/../assets/css/tailwind-arbitrary.css';
$appShellFile = __DIR__ . '/../assets/css/app-shell.css';
$useBuiltCss = is_file($tailwindFile) && filesize($tailwindFile) > 1000;
$forceTailwindCdn = !empty($forceTailwindCdn);
?>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript><link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet"></noscript>
<?php if ($useBuiltCss && !$forceTailwindCdn): ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/tailwind.css?v=<?php echo filemtime($tailwindFile); ?>">
<?php if (is_file($arbitraryFile)): ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/tailwind-arbitrary.css?v=<?php echo filemtime($arbitraryFile); ?>">
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
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="<?php echo h($base); ?>/assets/css/app-shell.css<?php echo is_file($appShellFile) ? '?v=' . filemtime($appShellFile) : ''; ?>">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
<style>
  /*
    Uniform student box corner radius (less rounded / more admin-like).
    Scoped to the student app shell only so admin UI is unaffected.
  */
  .app-shell-main--student .rounded-2xl { border-radius: 0.75rem !important; }
  .app-shell-main--student .rounded-xl { border-radius: 0.625rem !important; }
  .app-shell-main--student .rounded-lg { border-radius: 0.5rem !important; }
  body { font-family: Nunito, "Segoe UI", sans-serif; }
</style>
