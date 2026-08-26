(function () {
  'use strict';
  document.querySelectorAll('[data-career-reward]').forEach(function (banner) {
    var btn = banner.querySelector('[data-career-reward-dismiss]');
    if (!btn) return;
    btn.addEventListener('click', function () {
      banner.classList.add('is-dismissed');
      setTimeout(function () {
        if (banner.parentNode) banner.parentNode.removeChild(banner);
      }, 220);
    });
  });
})();
