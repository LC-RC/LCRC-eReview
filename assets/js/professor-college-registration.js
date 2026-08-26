(function () {
  'use strict';

  function updateFloatLabel(wrap, input) {
    if (!wrap || !input) return;
    var has = (input.value || '').trim() !== '' || document.activeElement === input;
    wrap.classList.toggle('focused', document.activeElement === input);
    wrap.classList.toggle('has-value', (input.value || '').trim() !== '');
  }

  function allPasswordChecksMet(pw) {
    return pw.length >= 8;
  }

  function updatePasswordStrength(pw) {
    var fill = document.getElementById('pcs-pw-strength-fill');
    var label = document.getElementById('pcs-pw-strength-label');
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
    label.textContent = len >= 8 ? 'Ready' : len >= 4 ? 'Fair' : 'Too short';
    if (bar) bar.setAttribute('aria-valuenow', String(Math.min(len, 12)));
  }

  function updatePasswordChecklist(pw) {
    var lenEl = document.getElementById('pcs-pw-check-length');
    if (lenEl) {
      var ok = pw.length >= 8;
      lenEl.classList.toggle('met', ok);
      var icon = lenEl.querySelector('i');
      if (icon) icon.className = ok ? 'bi bi-check-circle-fill' : 'bi bi-circle';
    }
  }

  function updateConfirmMatch() {
    var pw = document.getElementById('pcs-password');
    var confirm = document.getElementById('pcs-password-confirm');
    var err = document.getElementById('pcs-confirm-error');
    var ok = document.getElementById('pcs-confirm-success');
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
        err.textContent = p !== c ? 'Passwords do not match.' : 'Password must be at least 8 characters.';
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
    var pw = document.getElementById('pcs-password');
    var confirm = document.getElementById('pcs-password-confirm');
    var togglePw = document.getElementById('pcs-toggle-password');
    var toggleConfirm = document.getElementById('pcs-toggle-password-confirm');
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
    var zone = document.getElementById('pcs-avatar-zone');
    var input = document.getElementById('pcs-profile-picture');
    var browse = document.getElementById('pcs-avatar-browse');
    var clear = document.getElementById('pcs-avatar-clear');
    var placeholder = document.getElementById('pcs-avatar-placeholder');
    var previewWrap = document.getElementById('pcs-avatar-preview');
    var thumb = document.getElementById('pcs-avatar-thumb');
    var nameEl = document.getElementById('pcs-avatar-name');
    var sizeEl = document.getElementById('pcs-avatar-size');
    var useDefault = document.getElementById('pcs-use-default-avatar');

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
        if (e.target === browse || e.target.closest('#pcs-avatar-clear')) return;
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

  document.addEventListener('DOMContentLoaded', function () {
    bindFloatLabels();
    bindPassword();
    bindAvatar();
    var pw = document.getElementById('pcs-password');
    if (pw && pw.value) {
      updatePasswordStrength(pw.value);
      updatePasswordChecklist(pw.value);
    }
  });
})();
