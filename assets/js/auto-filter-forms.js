/**
 * Auto-apply GET list filters: sort/select/date change + debounced search.
 * Targets forms whose submit control label is "Apply" or "Apply filters".
 */
(function () {
  'use strict';

  var DEBOUNCE_MS = 350;

  function normalizeLabel(el) {
    if (!el) return '';
    if (el.tagName === 'INPUT') {
      return String(el.value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }
    return String(el.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function isApplyFilterControl(el) {
    var t = normalizeLabel(el);
    return t === 'apply' || t === 'apply filters';
  }

  function formMethod(form) {
    return String(form.getAttribute('method') || 'get').toLowerCase();
  }

  function submitForm(form) {
    if (typeof form.requestSubmit === 'function') {
      try {
        form.requestSubmit();
        return;
      } catch (e) {}
    }
    form.submit();
  }

  function hideApplyControl(el) {
    el.hidden = true;
    el.setAttribute('aria-hidden', 'true');
    el.tabIndex = -1;
    el.classList.add('is-auto-filter-hidden');
    el.style.display = 'none';
  }

  function isFilterField(el) {
    if (!el || el.disabled || el.readOnly) return false;
    if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button' || el.type === 'reset') return false;
    if (el.type === 'checkbox' || el.type === 'radio' || el.type === 'file' || el.type === 'password') return false;
    var tag = (el.tagName || '').toUpperCase();
    return tag === 'SELECT' || tag === 'INPUT' || tag === 'TEXTAREA';
  }

  function shouldDebounce(el) {
    var type = String(el.type || '').toLowerCase();
    if (type === 'search' || type === 'text' || type === 'email' || type === 'tel' || type === 'url') return true;
    if ((el.tagName || '').toUpperCase() === 'TEXTAREA') return true;
    return false;
  }

  function wireForm(form) {
    if (!form || form.getAttribute('data-auto-filter-wired') === '1') return;

    var applyControls = [];
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
      if (isApplyFilterControl(btn)) applyControls.push(btn);
    });
    if (!applyControls.length) return;
    if (formMethod(form) !== 'get') return;

    form.setAttribute('data-auto-filter-wired', '1');
    form.setAttribute('data-auto-filter', '1');

    applyControls.forEach(hideApplyControl);

    form.querySelectorAll('select, input, textarea').forEach(function (field) {
      if (!isFilterField(field)) return;

      if (shouldDebounce(field)) {
        var timer = null;
        field.addEventListener('input', function () {
          clearTimeout(timer);
          timer = setTimeout(function () {
            submitForm(form);
          }, DEBOUNCE_MS);
        });
        return;
      }

      field.addEventListener('change', function () {
        submitForm(form);
      });
    });
  }

  function init() {
    document.querySelectorAll('form').forEach(wireForm);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
