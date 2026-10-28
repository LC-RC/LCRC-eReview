(function () {
  'use strict';

  function updateFloatLabel(wrap, input) {
    if (!wrap || !input) return;
    wrap.classList.toggle('focused', document.activeElement === input);
    wrap.classList.toggle('has-value', (input.value || '').trim() !== '');
  }

  function passwordChecks(pw) {
    return {
      length: pw.length >= 8,
      upper: /[A-Z]/.test(pw),
      lower: /[a-z]/.test(pw),
      number: /[0-9]/.test(pw),
      symbol: /[^A-Za-z0-9]/.test(pw)
    };
  }

  function allPasswordChecksMet(pw) {
    var c = passwordChecks(pw);
    return c.length && c.upper && c.lower && c.number && c.symbol;
  }

  function updatePasswordStrength(pw) {
    var fill = document.getElementById('csr-pw-strength-fill');
    var label = document.getElementById('csr-pw-strength-label');
    var bar = fill && fill.parentElement;
    if (!fill || !label) return;
    var len = (pw || '').length;
    var cls = 'weak';
    if (len >= 12) cls = 'very-strong';
    else if (len >= 10) cls = 'strong';
    else if (len >= 8) cls = 'good';
    else if (len >= 4) cls = 'fair';
    fill.className = 'reg-pw-strength-fill ' + cls;
    label.className = 'reg-pw-strength-label ' + cls;
    label.textContent = allPasswordChecksMet(pw) ? 'Ready' : len >= 8 ? 'Add remaining requirements' : len >= 4 ? 'Fair' : 'Too short';
    if (bar) bar.setAttribute('aria-valuenow', String(Math.min(len, 12)));
  }

  function updatePasswordChecklist(pw) {
    var checks = passwordChecks(pw);
    var map = {
      'csr-pw-check-length': checks.length,
      'csr-pw-check-upper': checks.upper,
      'csr-pw-check-lower': checks.lower,
      'csr-pw-check-number': checks.number,
      'csr-pw-check-symbol': checks.symbol
    };
    Object.keys(map).forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.classList.toggle('met', !!map[id]);
      var icon = el.querySelector('i');
      if (icon) icon.className = map[id] ? 'bi bi-check-circle-fill' : 'bi bi-circle';
    });
  }

  function updateConfirmMatch() {
    var pw = document.getElementById('csr-password');
    var confirm = document.getElementById('csr-password-confirm');
    var err = document.getElementById('csr-confirm-error');
    var ok = document.getElementById('csr-confirm-success');
    if (!pw || !confirm) return;
    var p = pw.value;
    var c = confirm.value;
    if (!c) {
      if (err) { err.textContent = ''; err.classList.add('hidden'); }
      if (ok) ok.classList.add('hidden');
      return;
    }
    if (p === c && allPasswordChecksMet(p)) {
      if (err) { err.textContent = ''; err.classList.add('hidden'); }
      if (ok) ok.classList.remove('hidden');
    } else {
      if (ok) ok.classList.add('hidden');
      if (err) {
        err.textContent = p !== c ? 'Passwords do not match.' : 'Password does not meet the requirements.';
        err.classList.remove('hidden');
      }
    }
  }

  function bindFloatLabels() {
    document.querySelectorAll('[data-float-wrap]').forEach(function (wrap) {
      var input = wrap.querySelector('input, textarea');
      if (!input) return;
      ['focus', 'blur', 'input'].forEach(function (ev) {
        input.addEventListener(ev, function () { updateFloatLabel(wrap, input); });
      });
      updateFloatLabel(wrap, input);
    });
  }

  function bindPassword() {
    var pw = document.getElementById('csr-password');
    var confirm = document.getElementById('csr-password-confirm');
    var togglePw = document.getElementById('csr-toggle-password');
    var toggleConfirm = document.getElementById('csr-toggle-password-confirm');
    if (togglePw && pw) {
      togglePw.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        togglePw.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        var icon = togglePw.querySelector('i');
        if (icon) icon.className = show ? 'bi bi-eye-slash-fill text-lg' : 'bi bi-eye-fill text-lg';
      });
    }
    if (toggleConfirm && confirm) {
      toggleConfirm.addEventListener('click', function () {
        var show = confirm.type === 'password';
        confirm.type = show ? 'text' : 'password';
        toggleConfirm.setAttribute('aria-label', show ? 'Hide confirm password' : 'Show confirm password');
        var icon = toggleConfirm.querySelector('i');
        if (icon) icon.className = show ? 'bi bi-eye-slash-fill text-lg' : 'bi bi-eye-fill text-lg';
      });
    }
    if (pw) {
      pw.addEventListener('input', function () {
        updatePasswordStrength(pw.value);
        updatePasswordChecklist(pw.value);
        updateConfirmMatch();
      });
    }
    if (confirm) confirm.addEventListener('input', updateConfirmMatch);
  }

  function bindAvatar() {
    var zone = document.getElementById('csr-avatar-zone');
    var input = document.getElementById('csr-profile-picture');
    var browse = document.getElementById('csr-avatar-browse');
    var clear = document.getElementById('csr-avatar-clear');
    var placeholder = document.getElementById('csr-avatar-placeholder');
    var previewWrap = document.getElementById('csr-avatar-preview');
    var thumb = document.getElementById('csr-avatar-thumb');
    var nameEl = document.getElementById('csr-avatar-name');
    var sizeEl = document.getElementById('csr-avatar-size');
    var useDefault = document.getElementById('csr-use-default-avatar');

    function showFile(file) {
      if (!file || !previewWrap || !thumb) return;
      var reader = new FileReader();
      reader.onload = function (e) {
        thumb.src = String(e.target && e.target.result || '');
        thumb.classList.remove('hidden');
        if (nameEl) nameEl.textContent = file.name;
        if (sizeEl) sizeEl.textContent = Math.round(file.size / 1024) + ' KB';
        if (placeholder) placeholder.classList.add('hidden');
        previewWrap.classList.remove('hidden');
        if (useDefault) useDefault.checked = false;
      };
      reader.readAsDataURL(file);
    }

    if (browse && input) browse.addEventListener('click', function () { input.click(); });
    if (zone && input) {
      zone.addEventListener('click', function (e) {
        if (e.target === browse || (e.target.closest && e.target.closest('#csr-avatar-clear'))) return;
        if (!useDefault || !useDefault.checked) input.click();
      });
      zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('dragover'); });
      zone.addEventListener('dragleave', function () { zone.classList.remove('dragover'); });
      zone.addEventListener('drop', function (e) {
        e.preventDefault();
        zone.classList.remove('dragover');
        var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) { input.files = e.dataTransfer.files; showFile(f); }
      });
      input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        if (f) showFile(f);
      });
    }
    if (clear && input) {
      clear.addEventListener('click', function (e) {
        e.stopPropagation();
        input.value = '';
        if (previewWrap) previewWrap.classList.add('hidden');
        if (placeholder) placeholder.classList.remove('hidden');
      });
    }
    if (useDefault && input && zone) {
      useDefault.addEventListener('change', function () {
        input.disabled = !!useDefault.checked;
        zone.classList.toggle('opacity-60', !!useDefault.checked);
        if (useDefault.checked) {
          input.value = '';
          if (previewWrap) previewWrap.classList.add('hidden');
          if (placeholder) placeholder.classList.remove('hidden');
        }
      });
      input.disabled = !!useDefault.checked;
      zone.classList.toggle('opacity-60', !!useDefault.checked);
    }
  }

  function bindSubmitLoading() {
    var form = document.getElementById('reg-form') || document.getElementById('csr-resend-form');
    var btn = document.getElementById('csr-submit-btn');
    var loadingModal = document.getElementById('csr-modal-loading');
    if (!form || !btn) return;
    form.addEventListener('submit', function () {
      // Do not set disabled=true here: some browsers drop the POST if the submitter is disabled in this handler.
      btn.classList.add('is-loading');
      btn.setAttribute('aria-busy', 'true');
      var label = btn.querySelector('span');
      if (label) label.textContent = 'Sending…';
      if (loadingModal) {
        loadingModal.classList.add('is-active');
        loadingModal.setAttribute('aria-hidden', 'false');
      }
    });
  }

  function modalFocusable(container) {
    if (!container) return [];
    return Array.prototype.slice.call(container.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(function (el) {
      return !el.hasAttribute('hidden') && el.getClientRects().length > 0;
    });
  }

  function setPageInert(on) {
    var shell = document.querySelector('.reg-frame8-layout, .csr-resend-shell');
    if (!shell) return;
    if (on) {
      shell.setAttribute('inert', '');
      shell.setAttribute('aria-hidden', 'true');
    } else {
      shell.removeAttribute('inert');
      shell.removeAttribute('aria-hidden');
    }
  }

  function activateBackdrop(el, on) {
    if (!el) return;
    el.classList.toggle('is-active', !!on);
    el.setAttribute('aria-hidden', on ? 'false' : 'true');
  }

  function focusIn(el, preferred) {
    var target = preferred || (el && el.querySelector('.btn-primary')) || (el && el.querySelector('h2'));
    if (!target || typeof target.focus !== 'function') return;
    if (target.tabIndex < 0 && target.tagName === 'H2') {
      target.setAttribute('tabindex', '-1');
    }
    try {
      target.focus({ preventScroll: true });
    } catch (e) {
      target.focus();
    }
  }

  function bindVerifyModals() {
    var sent = document.getElementById('csr-modal-email-sent');
    var waiting = document.getElementById('csr-modal-waiting');
    var err = document.getElementById('csr-modal-error');
    var ok = document.getElementById('csr-modal-email-sent-ok');
    var errClose = document.getElementById('csr-modal-error-close');

    function activeModal() {
      return document.querySelector('.reg-modal-backdrop.is-active, .reg-error-backdrop.is-active');
    }

    if (ok && sent && waiting) {
      ok.addEventListener('click', function () {
        activateBackdrop(sent, false);
        activateBackdrop(waiting, true);
        setPageInert(true);
        focusIn(waiting, document.getElementById('csr-waiting-heading'));
      });
    }

    document.querySelectorAll('.csr-confirm-resend').forEach(function (resendForm) {
      resendForm.addEventListener('submit', function () {
        var loadingModal = document.getElementById('csr-modal-loading');
        activateBackdrop(sent, false);
        activateBackdrop(waiting, false);
        activateBackdrop(err, false);
        activateBackdrop(loadingModal, true);
      });
    });

    if (errClose && err && errClose.type === 'button') {
      errClose.addEventListener('click', function () {
        activateBackdrop(err, false);
        setPageInert(false);
        var email = document.getElementById('email') || document.getElementById('csr-email');
        if (email) email.focus();
      });
    }

    document.addEventListener('keydown', function (e) {
      var modal = activeModal();
      if (!modal || modal.id === 'csr-modal-loading') return;

      if (e.key === 'Escape') {
        if (err && err.classList.contains('is-active')) {
          if (errClose && errClose.type === 'button') {
            errClose.click();
          }
          return;
        }
        if (sent && sent.classList.contains('is-active') && ok) {
          e.preventDefault();
          ok.click();
        }
        return;
      }

      if (e.key !== 'Tab') return;
      var nodes = modalFocusable(modal);
      if (!nodes.length) {
        e.preventDefault();
        focusIn(modal);
        return;
      }
      var first = nodes[0];
      var last = nodes[nodes.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    });

    if (sent && sent.classList.contains('is-active')) {
      setPageInert(true);
      focusIn(sent, ok);
      return;
    }
    if (err && err.classList.contains('is-active')) {
      setPageInert(true);
      focusIn(err, errClose);
    }
  }

  function focusStatusTarget() {
    if (document.querySelector('.reg-modal-backdrop.is-active, .reg-error-backdrop.is-active')) {
      return;
    }
    var alertEl = document.querySelector('#reg-form-scroll .auth-alert--error, .csr-resend-form-card .auth-alert--error');
    if (alertEl) {
      if (!alertEl.hasAttribute('tabindex')) {
        alertEl.setAttribute('tabindex', '-1');
      }
      alertEl.focus();
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindFloatLabels();
    bindPassword();
    bindAvatar();
    bindSubmitLoading();
    bindVerifyModals();
    focusStatusTarget();
    var pw = document.getElementById('csr-password');
    if (pw && pw.value) {
      updatePasswordStrength(pw.value);
      updatePasswordChecklist(pw.value);
    }
  });
})();
