/**
 * Floating "back to top" control for student / professor app shells.
 */
(function () {
  'use strict';

  var THRESHOLD = 280;

  function shellReady() {
    var body = document.body;
    if (!body) return false;
    return body.classList.contains('app-shell--student')
      || body.classList.contains('app-shell--professor');
  }

  function init() {
    if (!shellReady()) {
      // Sidebar script may set the class just after parse; retry once.
      window.setTimeout(function () {
        if (shellReady()) init();
      }, 0);
      return;
    }
    // Full-screen playground / battle play: keep the bottom clear for Exit / Skip.
    if (document.body.classList.contains('pg-game-mode')) return;
    if (document.getElementById('ereviewScrollTopBtn')) return;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'ereviewScrollTopBtn';
    btn.className = 'ereview-scroll-top';
    btn.setAttribute('aria-label', 'Scroll to top');
    btn.setAttribute('title', 'Back to top');
    btn.innerHTML = '<i class="bi bi-arrow-up" aria-hidden="true"></i>';
    document.body.appendChild(btn);

    var ticking = false;

    function sync() {
      ticking = false;
      var y = window.pageYOffset || document.documentElement.scrollTop || 0;
      btn.classList.toggle('is-visible', y > THRESHOLD);
    }

    function onScroll() {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(sync);
    }

    btn.addEventListener('click', function () {
      try {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } catch (e) {
        window.scrollTo(0, 0);
      }
    });

    window.addEventListener('scroll', onScroll, { passive: true });
    sync();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
