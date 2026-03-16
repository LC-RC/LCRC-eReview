<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($pageTitle) ? h($pageTitle) . ' - ' : ''; ?>LCRC eReview</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$tailwindFile = __DIR__ . '/../assets/css/tailwind.css';
if (file_exists($tailwindFile)): ?>
<link rel="stylesheet" href="<?php echo $base; ?>/assets/css/tailwind.css">
<?php endif; ?>
<script>
  // Tailwind CDN (JIT) – ensures arbitrary values like bg-[#1665A0] work
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
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script>
document.addEventListener('alpine:init', function() {
  var key = 'studentSidebarCollapsed';
  var collapsed = false;
  try { collapsed = JSON.parse(localStorage.getItem(key) || 'false'); } catch (e) {}
  function persist(val) { try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) {} }
  Alpine.store('sidebar', {
    collapsed: collapsed,
    toggle: function() {
      this.collapsed = !this.collapsed;
      persist(this.collapsed);
    },
    expand: function() {
      this.collapsed = false;
      persist(this.collapsed);
    }
  });
});
</script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
