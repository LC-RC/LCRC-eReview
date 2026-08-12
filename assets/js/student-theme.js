/**
 * Student theme bootstrap - apply before paint to avoid FOUC.
 * Persistence: localStorage key ereview_student_theme = 'light' | 'dark'
 * Default: light
 */
(function () {
  var KEY = 'ereview_student_theme';
  var root = document.documentElement;

  function resolveTheme(explicit) {
    if (explicit === 'light' || explicit === 'dark') return explicit;
    try {
      var saved = localStorage.getItem(KEY);
      if (saved === 'light' || saved === 'dark') return saved;
    } catch (e) {}
    return 'light';
  }

  function applyTheme(theme) {
    var next = resolveTheme(theme);
    root.setAttribute('data-student-theme', next);
    root.style.colorScheme = next;
    try { localStorage.setItem(KEY, next); } catch (e) {}
    var toggles = document.querySelectorAll('[data-student-theme-toggle]');
    toggles.forEach(function (btn) {
      btn.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
      btn.setAttribute('title', next === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
      btn.setAttribute('aria-label', next === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    });
    document.querySelectorAll('.ereview-profile-menu--student-theme').forEach(function (menu) {
      menu.classList.toggle('ereview-profile-menu--light', next === 'light');
      menu.classList.toggle('ereview-profile-menu--dark', next === 'dark');
      var hero = menu.querySelector('.ereview-profile-menu__hero');
      if (hero) {
        hero.classList.toggle('ereview-profile-menu__hero--light', next === 'light');
        hero.classList.toggle('ereview-profile-menu__hero--dark', next === 'dark');
      }
    });
    try {
      document.dispatchEvent(new CustomEvent('ereview:student-theme-change', { detail: { theme: next } }));
    } catch (e) {}
    return next;
  }

  function toggleTheme() {
    var cur = root.getAttribute('data-student-theme') || 'light';
    return applyTheme(cur === 'dark' ? 'light' : 'dark');
  }

  applyTheme();

  window.EreviewStudentTheme = {
    apply: applyTheme,
    toggle: toggleTheme,
    get: function () { return root.getAttribute('data-student-theme') || 'light'; }
  };

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-student-theme-toggle]');
    if (!btn) return;
    e.preventDefault();
    toggleTheme();
  });
})();
