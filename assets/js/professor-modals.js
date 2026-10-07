/**
 * Professor Admin — hoist modals to document.body so they stack above the
 * sticky topbar. Overlays are authored inside #main > .admin-content, which
 * is a z-index:1 stacking context below header.admin-topbar (z-index 40).
 * Scoped: runs only on body.professor-admin.
 */
(function () {
  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function () {
    if (!document.body || !document.body.classList.contains('professor-admin')) {
      return;
    }

    var SELECTOR = '.admin-modal-overlay, .admin-ui-dialog-overlay';

    function hoist(el) {
      if (!el || el.nodeType !== 1) return;
      if (el.parentElement === document.body) return;
      document.body.appendChild(el);
    }

    function hoistAll() {
      document.querySelectorAll(SELECTOR).forEach(hoist);
    }

    function closeShellOverlays() {
      try { window.dispatchEvent(new CustomEvent('professor-close-profile')); } catch (e) {}
      if (window.matchMedia && window.matchMedia('(max-width: 991.98px)').matches && typeof window.closeAppShellSidebar === 'function') {
        window.closeAppShellSidebar();
      }
    }

    function overlayIsOpen(el) {
      if (!el || el.hasAttribute('hidden')) return false;
      return el.classList.contains('is-open') || el.classList.contains('show');
    }

    hoistAll();
    document.querySelectorAll(SELECTOR).forEach(function (el) {
      if (overlayIsOpen(el)) closeShellOverlays();
    });

    if (typeof MutationObserver === 'function') {
      var obs = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
          if (mutation.type === 'attributes') {
            var t = mutation.target;
            if (t.matches && t.matches(SELECTOR) && overlayIsOpen(t)) {
              closeShellOverlays();
            }
            return;
          }
          mutation.addedNodes.forEach(function (node) {
            if (node.nodeType !== 1) return;
            if (node.matches && node.matches(SELECTOR)) {
              hoist(node);
              if (overlayIsOpen(node)) closeShellOverlays();
            }
            if (node.querySelectorAll) {
              node.querySelectorAll(SELECTOR).forEach(function (el) {
                hoist(el);
                if (overlayIsOpen(el)) closeShellOverlays();
              });
            }
          });
        });
      });
      obs.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'hidden'] });
    }
  });
})();
