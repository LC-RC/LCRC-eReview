<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($pageTitle) ? h($pageTitle) . ' - ' : ''; ?>LCRC eReview</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$tailwindFile = __DIR__ . '/../assets/css/tailwind.css';
if (file_exists($tailwindFile)): ?>
<link rel="stylesheet" href="<?php echo $base; ?>/assets/css/tailwind.css">
<?php else: ?>
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          brand: { navy: '#0e3a55', 'navy-dark': '#0d2b55', gold: '#f2b01e', 'gold-light': '#ffd166' },
          primary: { DEFAULT: '#4154f1', dark: '#2d3fc7' }
        },
        fontFamily: { sans: ['Nunito', 'Segoe UI', 'sans-serif'] },
        boxShadow: { card: '0 2px 10px rgba(0,0,0,0.05)', 'card-lg': '0 10px 24px rgba(0,0,0,0.06)', modal: '0 20px 50px rgba(0,0,0,0.12)' }
      }
    }
  };
</script>
<?php endif; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
