/**
 * Professor Admin LMS premium floating toasts.
 * Safe text only (no innerHTML for user content). Scoped to body.lms-premium.
 */
(function () {
  'use strict';

  var MAX_VISIBLE = 3;
  var ENTER_MS = 180;
  var ROOT_ID = 'lmsToastRoot';
  var ICONS = {
    success: 'bi-check-circle-fill',
    error: 'bi-x-circle-fill',
    warning: 'bi-exclamation-triangle-fill',
    info: 'bi-info-circle-fill'
  };

  var queue = [];
  var visible = [];
  var reduced = false;
  var modalPaused = false;

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function isPremium() {
    return !!(document.body && document.body.classList.contains('lms-premium'));
  }

  function prefersReduced() {
    try {
      return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (e) {
      return false;
    }
  }

  function modalIsOpen() {
    return !!document.querySelector('.admin-modal-overlay.is-open, .lms-dialog-overlay.is-open');
  }

  function ensureRoot() {
    var root = document.getElementById(ROOT_ID);
    if (root) return root;
    root = document.createElement('div');
    root.id = ROOT_ID;
    root.className = 'lms-toast-stack';
    root.setAttribute('data-lms-toast-root', '1');
    root.setAttribute('aria-live', 'polite');
    root.setAttribute('aria-relevant', 'additions');
    root.setAttribute('aria-atomic', 'false');
    document.body.appendChild(root);
    return root;
  }

  function normalize(item) {
    var type = String((item && item.type) || 'info').toLowerCase();
    if (['success', 'error', 'warning', 'info'].indexOf(type) === -1) type = 'info';
    var title = String((item && item.title) || '').trim();
    var detail = String((item && (item.detail || item.message || item.description)) || '').trim();
    if (!title && detail) {
      title = detail;
      detail = '';
    }
    var sticky = item && typeof item.sticky === 'boolean' ? item.sticky : type === 'error';
    var ttl = parseInt(item && item.ttl, 10);
    if (!ttl || ttl < 1500) ttl = type === 'warning' ? 6000 : 4500;
    return { type: type, title: title, detail: detail, sticky: sticky, ttl: ttl };
  }

  function fingerprint(item) {
    return item.type + '\n' + item.title + '\n' + item.detail;
  }

  function markShown(fp) {
    try {
      sessionStorage.setItem('lmsToastLast', fp);
      sessionStorage.setItem('lmsToastLastAt', String(Date.now()));
    } catch (e) {}
  }

  function recentlyShown(fp) {
    try {
      var last = sessionStorage.getItem('lmsToastLast') || '';
      var at = parseInt(sessionStorage.getItem('lmsToastLastAt') || '0', 10);
      return last === fp && at && (Date.now() - at) < 1200;
    } catch (e) {
      return false;
    }
  }

  function setLive(root, type) {
    if (type === 'error') {
      root.setAttribute('aria-live', 'assertive');
    } else {
      root.setAttribute('aria-live', 'polite');
    }
  }

  function pauseItem(entry, pause) {
    if (!entry || entry.sticky) return;
    if (pause) {
      if (entry.paused) return;
      entry.paused = true;
      if (entry.bar) entry.bar.style.animationPlayState = 'paused';
      if (entry.startedAt) {
        entry.remaining = Math.max(0, entry.remaining - (Date.now() - entry.startedAt));
      }
      if (entry.timer) {
        clearTimeout(entry.timer);
        entry.timer = null;
      }
      return;
    }
    if (!entry.paused) return;
    if (modalPaused) return;
    entry.paused = false;
    if (entry.bar) entry.bar.style.animationPlayState = 'running';
    schedule(entry);
  }

  function schedule(entry) {
    if (!entry || entry.sticky || entry.paused || modalPaused) return;
    if (entry.timer) clearTimeout(entry.timer);
    entry.startedAt = Date.now();
    entry.timer = setTimeout(function () {
      dismiss(entry);
    }, entry.remaining);
  }

  function dismiss(entry) {
    if (!entry || entry.leaving) return;
    entry.leaving = true;
    if (entry.timer) {
      clearTimeout(entry.timer);
      entry.timer = null;
    }
    var el = entry.el;
    if (!el) {
      finishRemove(entry);
      return;
    }
    el.classList.remove('is-in');
    el.classList.add('is-out');
    var wait = reduced ? 0 : ENTER_MS;
    setTimeout(function () {
      if (el.parentNode) el.parentNode.removeChild(el);
      finishRemove(entry);
    }, wait);
  }

  function finishRemove(entry) {
    visible = visible.filter(function (v) { return v !== entry; });
    pump();
  }

  function bindHover(entry) {
    var el = entry.el;
    function onPause() { pauseItem(entry, true); }
    function onResume() { pauseItem(entry, false); }
    el.addEventListener('mouseenter', onPause);
    el.addEventListener('mouseleave', onResume);
    el.addEventListener('focusin', onPause);
    el.addEventListener('focusout', function (ev) {
      if (!el.contains(ev.relatedTarget)) onResume();
    });
  }

  function render(item) {
    var root = ensureRoot();
    setLive(root, item.type);
    var el = document.createElement('div');
    el.className = 'lms-toast lms-toast--' + item.type;
    el.setAttribute('role', item.type === 'error' ? 'alert' : 'status');
    el.setAttribute('data-lms-toast', item.type);

    var well = document.createElement('span');
    well.className = 'lms-toast__icon';
    well.setAttribute('aria-hidden', 'true');
    var icon = document.createElement('i');
    icon.className = 'bi ' + (ICONS[item.type] || ICONS.info);
    well.appendChild(icon);

    var body = document.createElement('div');
    body.className = 'lms-toast__body';
    var title = document.createElement('p');
    title.className = 'lms-toast__title';
    title.textContent = item.title;
    body.appendChild(title);
    if (item.detail) {
      var detail = document.createElement('p');
      detail.className = 'lms-toast__detail';
      detail.textContent = item.detail;
      body.appendChild(detail);
    }

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'lms-toast__close';
    close.setAttribute('aria-label', 'Dismiss notification');
    var closeIcon = document.createElement('i');
    closeIcon.className = 'bi bi-x';
    closeIcon.setAttribute('aria-hidden', 'true');
    close.appendChild(closeIcon);

    el.appendChild(well);
    el.appendChild(body);
    el.appendChild(close);

    var bar = null;
    if (!item.sticky) {
      bar = document.createElement('span');
      bar.className = 'lms-toast__progress';
      bar.setAttribute('aria-hidden', 'true');
      bar.style.animationDuration = item.ttl + 'ms';
      if (reduced) bar.style.animation = 'none';
      el.appendChild(bar);
    }

    root.appendChild(el);

    var entry = {
      el: el,
      sticky: item.sticky,
      remaining: item.ttl,
      startedAt: Date.now(),
      paused: false,
      timer: null,
      bar: bar,
      leaving: false
    };
    close.addEventListener('click', function () { dismiss(entry); });
    bindHover(entry);
    visible.push(entry);

    requestAnimationFrame(function () {
      el.classList.add('is-in');
    });
    if (!item.sticky && !modalPaused) schedule(entry);
    return entry;
  }

  function pump() {
    while (visible.length < MAX_VISIBLE && queue.length) {
      render(queue.shift());
    }
  }

  function show(raw) {
    if (!isPremium()) return null;
    var item = normalize(raw);
    if (!item.title && !item.detail) return null;
    var fp = fingerprint(item);
    if (recentlyShown(fp) && queue.length === 0 && visible.length === 0) {
      return null;
    }
    markShown(fp);
    ensureRoot();
    queue.push(item);
    pump();
    return item;
  }

  function syncModal() {
    var open = modalIsOpen();
    var root = document.getElementById(ROOT_ID);
    if (root) root.classList.toggle('lms-toast-stack--behind', open);
    if (open === modalPaused) return;
    modalPaused = open;
    visible.forEach(function (entry) {
      pauseItem(entry, open);
    });
  }

  function observeModals() {
    if (typeof MutationObserver === 'undefined') return;
    var obs = new MutationObserver(syncModal);
    obs.observe(document.body, { attributes: true, subtree: true, attributeFilter: ['class', 'aria-hidden'] });
    syncModal();
  }

  function seedFromDom() {
    var node = document.getElementById('lmsToastSeed');
    if (!node) return;
    var data = [];
    try {
      data = JSON.parse(node.textContent || '[]');
    } catch (e) {
      data = [];
    }
    if (node.parentNode) node.parentNode.removeChild(node);
    if (!Array.isArray(data)) return;
    data.forEach(function (item) { show(item); });
  }

  var api = {
    show: show,
    success: function (title, detail) { return show({ type: 'success', title: title, detail: detail || '' }); },
    error: function (title, detail) { return show({ type: 'error', title: title, detail: detail || '', sticky: true }); },
    warning: function (title, detail) { return show({ type: 'warning', title: title, detail: detail || '' }); },
    info: function (title, detail) { return show({ type: 'info', title: title, detail: detail || '' }); },
    dismissAll: function () {
      visible.slice().forEach(dismiss);
      queue.length = 0;
    }
  };

  ready(function () {
    if (!isPremium()) return;
    reduced = prefersReduced();
    ensureRoot();
    observeModals();
    seedFromDom();
    window.LmsPremiumToast = api;
  });

  window.LmsPremiumToast = api;
})();
