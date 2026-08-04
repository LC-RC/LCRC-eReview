/**
 * Admin payment proof modal — open via [data-admin-proof] links/buttons.
 */
(function () {
  'use strict';

  var modal = document.getElementById('adminProofModal');
  if (!modal) return;

  var titleEl = document.getElementById('adminProofTitle');
  var openTab = document.getElementById('adminProofOpenTab');
  var loading = document.getElementById('adminProofLoading');
  var errorEl = document.getElementById('adminProofError');
  var img = document.getElementById('adminProofImg');
  var frame = document.getElementById('adminProofFrame');
  var lastFocus = null;
  var loadToken = 0;

  function showLoading(on) {
    if (loading) loading.hidden = !on;
  }

  function showError(msg) {
    if (!errorEl) return;
    if (msg) {
      errorEl.innerHTML = msg;
      errorEl.hidden = false;
    } else {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
  }

  function resetMedia() {
    if (img) {
      img.onload = null;
      img.onerror = null;
      img.removeAttribute('src');
      img.hidden = true;
    }
    if (frame) {
      frame.onload = null;
      frame.onerror = null;
      frame.removeAttribute('src');
      frame.hidden = true;
    }
    showError(false);
  }

  function openProof(url, title) {
    if (!url) return;
    lastFocus = document.activeElement;
    if (titleEl) titleEl.textContent = title || 'Payment proof';
    if (openTab) openTab.href = url;
    resetMedia();
    showLoading(true);
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('admin-proof-modal-open');

    // payment_proof_file has no extension — try <img>, fall back to iframe (PDF/other).
    showAsImage(url, true);

    var closeBtn = modal.querySelector('.admin-proof-modal__close');
    if (closeBtn) closeBtn.focus();
  }

  function showAsImage(url, allowIframeFallback) {
    if (!img) {
      showAsFrame(url);
      return;
    }
    var token = ++loadToken;
    img.hidden = false;
    img.onload = function () {
      if (token !== loadToken) return;
      var w = img.naturalWidth || 0;
      var h = img.naturalHeight || 0;
      // Tiny / corrupt placeholders look like an empty white modal.
      if (w > 0 && h > 0 && (w < 16 || h < 16)) {
        img.hidden = true;
        showLoading(false);
        showError(
          'This proof file is too small or looks empty (' + w + '×' + h +
          '). Use <strong>Open in new tab</strong>, or ask the student to re-upload a clearer receipt.'
        );
        return;
      }
      showLoading(false);
      showError(false);
    };
    img.onerror = function () {
      if (token !== loadToken) return;
      img.hidden = true;
      img.removeAttribute('src');
      if (allowIframeFallback !== false) {
        showAsFrame(url);
      } else {
        showLoading(false);
        showError(
          'Could not display this proof. Use <strong>Open in new tab</strong>, or re-upload if the file is missing.'
        );
      }
    };
    img.src = url;
  }

  function showAsFrame(url) {
    if (!frame) {
      showLoading(false);
      showError(
        'Could not display this proof. Use <strong>Open in new tab</strong>, or re-upload if the file is missing.'
      );
      return;
    }
    var token = ++loadToken;
    frame.hidden = false;
    frame.onload = function () {
      if (token !== loadToken) return;
      showLoading(false);
    };
    frame.onerror = function () {
      if (token !== loadToken) return;
      frame.hidden = true;
      showLoading(false);
      showError(
        'Could not display this proof. Use <strong>Open in new tab</strong>, or re-upload if the file is missing.'
      );
    };
    frame.src = url;
    // PDFs may not fire onload reliably in all browsers
    window.setTimeout(function () {
      if (token !== loadToken) return;
      showLoading(false);
    }, 1200);
  }

  function closeProof() {
    if (!modal.classList.contains('is-open')) return;
    loadToken++;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('admin-proof-modal-open');
    resetMedia();
    showLoading(false);
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus(); } catch (e) { /* ignore */ }
    }
  }

  document.addEventListener('click', function (e) {
    var closer = e.target.closest('[data-admin-proof-close]');
    if (closer) {
      e.preventDefault();
      closeProof();
      return;
    }
    var trigger = e.target.closest('[data-admin-proof]');
    if (!trigger || !modal.contains || trigger.closest('#adminProofModal')) return;
    var url = trigger.getAttribute('data-proof-url') || trigger.getAttribute('href');
    if (!url || url === '#' || url.indexOf('javascript:') === 0) return;
    e.preventDefault();
    openProof(url, trigger.getAttribute('data-proof-title') || 'Payment proof');
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) {
      e.preventDefault();
      closeProof();
    }
  });
})();
