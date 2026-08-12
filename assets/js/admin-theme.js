/**
 * Admin theme bootstrap - apply before paint to avoid FOUC.
 * Persistence: localStorage key ereview_admin_theme = 'light' | 'dark'
 */
(function () {
  var KEY = 'ereview_admin_theme';
  var root = document.documentElement;

  function resolveTheme(explicit) {
    if (explicit === 'light' || explicit === 'dark') return explicit;
    try {
      var saved = localStorage.getItem(KEY);
      if (saved === 'light' || saved === 'dark') return saved;
    } catch (e) {}
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
      return 'light';
    }
    return 'dark';
  }

  function applyTheme(theme) {
    var next = resolveTheme(theme);
    root.setAttribute('data-admin-theme', next);
    root.style.colorScheme = next;
    try { localStorage.setItem(KEY, next); } catch (e) {}
    var toggles = document.querySelectorAll('[data-admin-theme-toggle]');
    toggles.forEach(function (btn) {
      btn.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
      btn.setAttribute('title', next === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
      btn.setAttribute('aria-label', next === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    });
    try {
      document.dispatchEvent(new CustomEvent('ereview:admin-theme-change', { detail: { theme: next } }));
    } catch (e) {}
    return next;
  }

  function toggleTheme() {
    var cur = root.getAttribute('data-admin-theme') || 'dark';
    return applyTheme(cur === 'dark' ? 'light' : 'dark');
  }

  applyTheme();

  window.EreviewAdminTheme = {
    apply: applyTheme,
    toggle: toggleTheme,
    get: function () { return root.getAttribute('data-admin-theme') || 'dark'; }
  };

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-admin-theme-toggle]');
    if (!btn) return;
    e.preventDefault();
    toggleTheme();
  });
})();
