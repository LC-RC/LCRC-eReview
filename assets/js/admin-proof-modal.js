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
  var img = document.getElementById('adminProofImg');
  var frame = document.getElementById('adminProofFrame');
  var lastFocus = null;

  function showLoading(on) {
    if (loading) loading.hidden = !on;
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
      frame.removeAttribute('src');
      frame.hidden = true;
    }
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
    img.hidden = false;
    img.onload = function () {
      showLoading(false);
    };
    img.onerror = function () {
      img.hidden = true;
      img.removeAttribute('src');
      if (allowIframeFallback !== false) {
        showAsFrame(url);
      } else {
        showLoading(false);
      }
    };
    img.src = url;
  }

  function showAsFrame(url) {
    if (!frame) {
      showLoading(false);
      return;
    }
    frame.hidden = false;
    frame.onload = function () {
      showLoading(false);
    };
    frame.src = url;
    // PDFs may not fire onload reliably in all browsers
    window.setTimeout(function () {
      showLoading(false);
    }, 1200);
  }

  function closeProof() {
    if (!modal.classList.contains('is-open')) return;
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
