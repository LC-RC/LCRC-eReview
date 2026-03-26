<?php
$notificationTheme = ($notificationTheme ?? 'admin') === 'student'
    ? 'student'
    : (($notificationTheme ?? '') === 'professor' ? 'professor' : 'admin');
$notificationRoleLabel = $notificationTheme === 'student'
    ? 'Student'
    : ($notificationTheme === 'professor' ? 'Professor Admin' : 'Admin');
$notificationCsrfToken = function_exists('generateCSRFToken') ? generateCSRFToken() : (string)($_SESSION['csrf_token'] ?? '');
?>
<aside
  id="ereviewNotificationPanel"
  class="ere-notif <?php echo $notificationTheme === 'student' ? 'ere-notif--student' : ($notificationTheme === 'professor' ? 'ere-notif--professor' : 'ere-notif--admin'); ?>"
  data-theme="<?php echo h($notificationTheme); ?>"
  data-role-label="<?php echo h($notificationRoleLabel); ?>"
  data-csrf-token="<?php echo h($notificationCsrfToken); ?>"
  aria-hidden="true"
  hidden
>
  <button type="button" class="ere-notif__backdrop" data-notification-close tabindex="-1" aria-hidden="true"></button>
  <section class="ere-notif__panel" role="dialog" aria-modal="true" aria-labelledby="ereviewNotificationTitle">
    <header class="ere-notif__header">
      <div class="ere-notif__header-main">
        <p class="ere-notif__eyebrow"><?php echo h($notificationRoleLabel); ?></p>
        <h2 class="ere-notif__title" id="ereviewNotificationTitle">Notifications</h2>
      </div>
      <button type="button" class="ere-notif__close" data-notification-close aria-label="Close notifications">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </header>

    <div class="ere-notif__meta">
      <span class="ere-notif__meta-pill">Inbox</span>
      <span class="ere-notif__meta-muted" data-notification-count>0 items</span>
      <button type="button" class="ere-notif__markall" data-mark-all-read>Mark all as read</button>
    </div>

    <div class="ere-notif__list" data-notification-list role="list">
      <div class="ere-notif__state">Loading notifications...</div>
    </div>
  </section>
</aside>
<div id="ereviewNotificationToasts" class="ere-notif-toasts" aria-live="polite" aria-atomic="true" hidden></div>

<template id="ereviewNotificationItemTemplate">
  <article class="ere-notif__item" role="listitem">
    <div class="ere-notif__actor">
      <span class="ere-notif__avatar" data-actor-avatar>U</span>
      <div class="ere-notif__actor-meta">
        <p class="ere-notif__actor-name" data-actor-name></p>
        <p class="ere-notif__actor-sub" data-actor-sub></p>
      </div>
    </div>
    <div class="ere-notif__item-head">
      <h3 class="ere-notif__item-title"></h3>
      <span class="ere-notif__dot" aria-label="Unread notification"></span>
    </div>
    <p class="ere-notif__item-message"></p>
    <div class="ere-notif__item-foot">
      <p class="ere-notif__item-time"></p>
      <div class="ere-notif__item-actions">
        <button type="button" class="ere-notif__item-mark">Mark read</button>
        <button type="button" class="ere-notif__item-delete" title="Delete notification"><i class="bi bi-trash3"></i></button>
      </div>
    </div>
  </article>
</template>

<style>
  .ere-notif {
    position: fixed;
    inset: 0;
    z-index: 1200;
    pointer-events: none;
  }
  [data-notification-toggle] { position: relative; }
  .ere-notif__badge,
  [data-notification-badge] {
    position: absolute;
    top: 0.1rem;
    right: 0.12rem;
    min-width: 1rem;
    height: 1rem;
    border-radius: 999px;
    padding: 0 0.24rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.62rem;
    font-weight: 800;
    color: #fff;
    background: #ef4444;
    border: 2px solid rgba(255, 255, 255, 0.95);
    line-height: 1;
  }
  [data-notification-badge].is-empty { display: none; }

  .ere-notif__backdrop {
    position: absolute;
    inset: 0;
    border: 0;
    padding: 0;
    background: rgba(6, 11, 20, 0.38);
    opacity: 0;
    transition: opacity 0.26s ease;
  }
  .ere-notif__panel {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: min(100vw, 430px);
    display: flex;
    flex-direction: column;
    background: #fff;
    color: #102a43;
    box-shadow: -22px 0 44px rgba(6, 18, 40, 0.16);
    transform: translate3d(100%, 0, 0);
    transition: transform 0.28s cubic-bezier(0.2, 0.7, 0.18, 1);
    will-change: transform;
  }
  .ere-notif.is-open { pointer-events: auto; }
  .ere-notif.is-open .ere-notif__backdrop { opacity: 1; }
  .ere-notif.is-open .ere-notif__panel { transform: translate3d(0, 0, 0); }

  .ere-notif__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1rem 1rem 0.65rem;
    border-bottom: 1px solid rgba(18, 59, 90, 0.08);
  }
  .ere-notif__eyebrow {
    margin: 0;
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 700;
    color: rgba(16, 42, 67, 0.52);
  }
  .ere-notif__title {
    margin: 0.15rem 0 0;
    font-size: 1.1rem;
    line-height: 1.25;
    font-weight: 800;
    color: #102a43;
  }
  .ere-notif__close {
    width: 2.2rem;
    height: 2.2rem;
    border: 1px solid rgba(22, 101, 160, 0.14);
    border-radius: 0.65rem;
    background: #fff;
    color: rgba(16, 42, 67, 0.72);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.16s ease;
  }
  .ere-notif__close:hover {
    color: #1665A0;
    border-color: rgba(22, 101, 160, 0.28);
    background: rgba(22, 101, 160, 0.06);
  }

  .ere-notif__meta {
    padding: 0.65rem 1rem 0.85rem;
    display: flex;
    align-items: center;
    gap: 0.55rem;
    border-bottom: 1px solid rgba(18, 59, 90, 0.06);
  }
  .ere-notif__meta-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.25rem 0.62rem;
    font-size: 0.72rem;
    font-weight: 700;
    background: rgba(22, 101, 160, 0.1);
    color: #125688;
  }
  .ere-notif__meta-muted {
    font-size: 0.78rem;
    color: rgba(16, 42, 67, 0.55);
    font-weight: 600;
  }
  .ere-notif__markall {
    margin-left: auto;
    border: 1px solid rgba(22, 101, 160, 0.2);
    background: #fff;
    color: #125688;
    border-radius: 0.58rem;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 0.34rem 0.56rem;
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .ere-notif__markall:hover {
    border-color: rgba(22, 101, 160, 0.36);
    background: rgba(22, 101, 160, 0.08);
  }

  .ere-notif__list {
    overflow: auto;
    padding: 0.8rem 0.85rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
  }
  .ere-notif__actor {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    margin-bottom: 0.5rem;
  }
  .ere-notif__avatar {
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    background: #334155;
    color: #fff;
    font-weight: 800;
    font-size: 0.78rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,0.24);
    flex-shrink: 0;
  }
  .ere-notif__avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .ere-notif__actor-meta { min-width: 0; }
  .ere-notif__actor-name {
    margin: 0;
    font-size: 0.8rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
  }
  .ere-notif__actor-sub {
    margin: 0.1rem 0 0;
    font-size: 0.7rem;
    font-weight: 600;
    color: rgba(15, 23, 42, 0.6);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 240px;
  }
  .ere-notif__state {
    text-align: center;
    color: rgba(16, 42, 67, 0.56);
    font-size: 0.86rem;
    border: 1px dashed rgba(22, 101, 160, 0.2);
    border-radius: 0.7rem;
    padding: 1rem 0.75rem;
    background: rgba(232, 242, 250, 0.35);
  }
  .ere-notif__item {
    border: 1px solid rgba(22, 101, 160, 0.11);
    border-radius: 0.75rem;
    background: linear-gradient(160deg, #fff 0%, #f8fbff 100%);
    padding: 0.75rem 0.78rem;
  }
  .ere-notif__item.is-unread {
    border-color: rgba(22, 101, 160, 0.2);
    box-shadow: 0 2px 10px rgba(18, 72, 115, 0.09);
  }
  .ere-notif__item-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.55rem;
  }
  .ere-notif__item-title {
    margin: 0;
    font-size: 0.92rem;
    font-weight: 800;
    color: #102a43;
  }
  .ere-notif__dot {
    width: 0.54rem;
    height: 0.54rem;
    border-radius: 999px;
    flex-shrink: 0;
    background: #2f80ed;
    box-shadow: 0 0 0 3px rgba(47, 128, 237, 0.16);
  }
  .ere-notif__item-message {
    margin: 0.45rem 0 0;
    color: rgba(16, 42, 67, 0.75);
    font-size: 0.83rem;
    line-height: 1.45;
  }
  .ere-notif__item-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    margin-top: 0.45rem;
  }
  .ere-notif__item-actions {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
  }
  .ere-notif__item-time {
    margin: 0;
    color: rgba(16, 42, 67, 0.52);
    font-size: 0.72rem;
    font-weight: 700;
  }
  .ere-notif__item-mark {
    border: 0;
    background: transparent;
    color: #1665A0;
    font-size: 0.72rem;
    font-weight: 700;
    cursor: pointer;
    padding: 0.2rem 0;
  }
  .ere-notif__item-delete {
    width: 1.7rem;
    height: 1.7rem;
    border-radius: 0.45rem;
    border: 1px solid rgba(239, 68, 68, 0.28);
    color: #ef4444;
    background: rgba(239, 68, 68, 0.08);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
  }
  .ere-notif__item-delete:hover {
    background: rgba(239, 68, 68, 0.16);
    border-color: rgba(239, 68, 68, 0.44);
  }
  .ere-notif__item.is-read .ere-notif__item-mark,
  .ere-notif__item.is-read .ere-notif__dot {
    display: none;
  }

  .ere-notif--admin .ere-notif__panel {
    background: #0d1117;
    color: #e6edf3;
  }
  .ere-notif--admin .ere-notif__header,
  .ere-notif--admin .ere-notif__meta {
    border-color: rgba(230, 237, 243, 0.1);
  }
  .ere-notif--admin .ere-notif__eyebrow,
  .ere-notif--admin .ere-notif__meta-muted,
  .ere-notif--admin .ere-notif__item-time {
    color: rgba(230, 237, 243, 0.58);
  }
  .ere-notif--admin .ere-notif__title,
  .ere-notif--admin .ere-notif__item-title {
    color: #f0f6fc;
  }
  .ere-notif--admin .ere-notif__item-message {
    color: rgba(230, 237, 243, 0.82);
  }
  .ere-notif--admin .ere-notif__actor-name { color: #f8fafc; }
  .ere-notif--admin .ere-notif__actor-sub { color: rgba(226, 232, 240, 0.7); }
  .ere-notif--admin .ere-notif__meta-pill {
    color: #93c5fd;
    background: rgba(96, 165, 250, 0.16);
  }
  .ere-notif--admin .ere-notif__close,
  .ere-notif--admin .ere-notif__markall {
    border-color: rgba(230, 237, 243, 0.16);
    background: #0f1722;
    color: rgba(230, 237, 243, 0.8);
  }
  .ere-notif--admin .ere-notif__close:hover,
  .ere-notif--admin .ere-notif__markall:hover {
    color: #bfdbfe;
    border-color: rgba(147, 197, 253, 0.42);
    background: rgba(147, 197, 253, 0.12);
  }
  .ere-notif--admin .ere-notif__item {
    border-color: rgba(230, 237, 243, 0.13);
    background: linear-gradient(170deg, #111827 0%, #0b1220 100%);
  }
  .ere-notif--admin .ere-notif__item.is-unread {
    border-color: rgba(147, 197, 253, 0.3);
    box-shadow: 0 4px 14px rgba(2, 6, 23, 0.45);
  }
  .ere-notif--admin [data-notification-badge] {
    border-color: rgba(13, 17, 23, 0.95);
  }

  .ere-notif--professor .ere-notif__meta-pill {
    background: rgba(22, 163, 74, 0.13);
    color: #15803d;
  }
  .ere-notif--professor .ere-notif__dot {
    background: #22c55e;
    box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.18);
  }

  .ere-notif-toasts {
    position: fixed;
    top: 5.35rem;
    right: 1.1rem;
    z-index: 1220;
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    pointer-events: none;
    width: min(370px, calc(100vw - 2rem));
  }
  .ere-notif-toast {
    pointer-events: auto;
    border-radius: 0.85rem;
    border: 1px solid rgba(147, 197, 253, 0.38);
    background: linear-gradient(160deg, #0f172a 0%, #111827 100%);
    color: #e2e8f0;
    box-shadow: 0 16px 36px rgba(2, 6, 23, 0.52);
    padding: 0.72rem 0.8rem;
    transform: translateX(120%);
    opacity: 0;
    transition: transform 0.34s ease, opacity 0.3s ease;
  }
  .ere-notif-toast.is-show {
    transform: translateX(0);
    opacity: 1;
  }
  .ere-notif-toast__head {
    display: flex;
    align-items: center;
    gap: 0.55rem;
  }
  .ere-notif-toast__name {
    margin: 0;
    font-size: 0.82rem;
    font-weight: 800;
    color: #f8fafc;
    line-height: 1.25;
  }
  .ere-notif-toast__sub {
    margin: 0.08rem 0 0;
    font-size: 0.68rem;
    color: rgba(191, 219, 254, 0.9);
    font-weight: 600;
  }
  .ere-notif-toast__msg {
    margin: 0.45rem 0 0;
    font-size: 0.76rem;
    color: rgba(226, 232, 240, 0.92);
    line-height: 1.42;
  }
  .ere-notif-toast__foot {
    margin-top: 0.36rem;
    font-size: 0.68rem;
    color: rgba(148, 163, 184, 0.95);
    font-weight: 700;
  }

  @media (max-width: 520px) {
    .ere-notif__panel { width: 100vw; }
    .ere-notif-toasts { top: 4.9rem; right: 0.6rem; width: calc(100vw - 1.2rem); }
  }
</style>

<script>
  (function() {
    if (window.__ereviewNotifInit) return;
    window.__ereviewNotifInit = true;

    var panel = document.getElementById('ereviewNotificationPanel');
    if (!panel) return;
    panel.hidden = false;
    var closeButtons = panel.querySelectorAll('[data-notification-close]');
    var listEl = panel.querySelector('[data-notification-list]');
    var countEl = panel.querySelector('[data-notification-count]');
    var markAllBtn = panel.querySelector('[data-mark-all-read]');
    var tpl = document.getElementById('ereviewNotificationItemTemplate');
    var toastWrap = document.getElementById('ereviewNotificationToasts');
    if (toastWrap) toastWrap.hidden = false;
    var theme = panel.getAttribute('data-theme') || '';
    var csrfToken = panel.getAttribute('data-csrf-token') || '';
    var apiUrl = 'notifications_api.php';
    var activeToggle = null;
    var hasLoaded = false;
    var inFlight = false;
    var toastQueue = [];
    var toastActive = false;

    function escapeHtml(str) {
      return String(str || '').replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
      });
    }

    function initials(name) {
      var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
      if (!parts.length) return 'U';
      var a = (parts[0] || '').charAt(0);
      var b = parts.length > 1 ? (parts[parts.length - 1] || '').charAt(0) : '';
      return (a + b).toUpperCase();
    }

    function avatarHtml(actor) {
      if (actor && actor.profile_picture && !actor.use_default_avatar) {
        return '<img src="' + escapeHtml(actor.profile_picture) + '" alt="">';
      }
      return escapeHtml(initials(actor && actor.name ? actor.name : 'User'));
    }

    function actorSub(actor) {
      if (!actor) return '';
      var rt = String(actor.review_type || '').toLowerCase() === 'undergrad' ? 'Undergrad' : 'Reviewee';
      var school = actor.school || 'School not set';
      var email = actor.email || '';
      return [school, rt, email].filter(Boolean).join(' • ');
    }

    function setState(text) {
      if (!listEl) return;
      listEl.innerHTML = '<div class="ere-notif__state">' + escapeHtml(text) + '</div>';
    }

    function updateBadge(unreadCount) {
      var badges = document.querySelectorAll('[data-notification-badge]');
      var count = Number(unreadCount || 0);
      badges.forEach(function(b) {
        if (count > 0) {
          b.classList.remove('is-empty');
          b.textContent = count > 99 ? '99+' : String(count);
        } else {
          b.classList.add('is-empty');
          b.textContent = '';
        }
      });
    }

    function renderList(items, unreadCount, totalCount) {
      if (!listEl) return;
      updateBadge(unreadCount);
      if (countEl) countEl.textContent = (totalCount || 0) + ' item' + ((totalCount || 0) === 1 ? '' : 's');
      if (!Array.isArray(items) || items.length === 0) {
        setState('No notifications yet.');
        if (markAllBtn) markAllBtn.disabled = true;
        return;
      }
      listEl.innerHTML = '';
      if (markAllBtn) markAllBtn.disabled = unreadCount <= 0;

      items.forEach(function(item) {
        var node;
        if (tpl && 'content' in tpl) node = tpl.content.firstElementChild.cloneNode(true);
        else {
          node = document.createElement('article');
          node.className = 'ere-notif__item';
          node.innerHTML = '<div class="ere-notif__item-head"><h3 class="ere-notif__item-title"></h3><span class="ere-notif__dot"></span></div><p class="ere-notif__item-message"></p><div class="ere-notif__item-foot"><p class="ere-notif__item-time"></p><button type="button" class="ere-notif__item-mark">Mark read</button></div>';
        }

        var isRead = !!item.is_read;
        node.setAttribute('data-id', String(item.id || '0'));
        node.classList.toggle('is-unread', !isRead);
        node.classList.toggle('is-read', isRead);
        node.querySelector('.ere-notif__item-title').textContent = item.title || 'Notification';
        node.querySelector('.ere-notif__item-message').textContent = item.message || '';
        node.querySelector('.ere-notif__item-time').textContent = item.time_label || 'Just now';
        var actor = item.actor || null;
        var avatar = node.querySelector('[data-actor-avatar]');
        var actorName = node.querySelector('[data-actor-name]');
        var actorSubEl = node.querySelector('[data-actor-sub]');
        if (avatar) avatar.innerHTML = avatarHtml(actor);
        if (actorName) actorName.textContent = actor && actor.name ? actor.name : 'System';
        if (actorSubEl) actorSubEl.textContent = actorSub(actor);

        var markBtn = node.querySelector('.ere-notif__item-mark');
        var delBtn = node.querySelector('.ere-notif__item-delete');
        if (markBtn) {
          markBtn.style.display = isRead ? 'none' : '';
          markBtn.addEventListener('click', function() {
            markRead(item.id, node);
          });
        }
        if (delBtn) {
          delBtn.addEventListener('click', function(ev) {
            ev.stopPropagation();
            deleteNotification(item.id, node);
          });
        }

        if (item.link_url) {
          node.style.cursor = 'pointer';
          node.addEventListener('click', function(ev) {
            if (ev.target.closest('button')) return;
            window.location.href = item.link_url;
          });
        }

        listEl.appendChild(node);
      });
    }

    function enqueueToasts(items) {
      if (theme !== 'admin') return;
      (items || []).forEach(function(item) {
        if (!item || !item.id) return;
        if (item.toast_shown) return;
        if ((item.category || '') !== 'pending_registration') return;
        if (toastQueue.some(function(q) { return q.id === item.id; })) return;
        toastQueue.push(item);
      });
      runToastQueue();
    }

    function markToastShown(notificationId) {
      if (!notificationId) return Promise.resolve();
      return postAction('mark_toast_shown', { notification_id: notificationId }).catch(function() {});
    }

    function runToastQueue() {
      if (toastActive || !toastWrap || !toastQueue.length) return;
      toastActive = true;
      var item = toastQueue.shift();
      var actor = item.actor || {};
      var el = document.createElement('article');
      el.className = 'ere-notif-toast';
      el.innerHTML =
        '<div class="ere-notif-toast__head">' +
          '<span class="ere-notif__avatar">' + avatarHtml(actor) + '</span>' +
          '<div><p class="ere-notif-toast__name">' + escapeHtml(actor.name || item.title || 'Notification') + '</p>' +
          '<p class="ere-notif-toast__sub">' + escapeHtml(actorSub(actor)) + '</p></div>' +
        '</div>' +
        '<p class="ere-notif-toast__msg">' + escapeHtml(item.message || 'New pending registration.') + '</p>' +
        '<p class="ere-notif-toast__foot">' + escapeHtml(item.time_label || 'Just now') + '</p>';
      toastWrap.appendChild(el);
      requestAnimationFrame(function() { el.classList.add('is-show'); });
      markToastShown(item.id).finally(function() {
        setTimeout(function() {
          el.classList.remove('is-show');
          setTimeout(function() {
            if (el.parentNode) el.parentNode.removeChild(el);
            toastActive = false;
            runToastQueue();
          }, 280);
        }, 3800);
      });
    }

    function fetchList(force) {
      if (inFlight && !force) return Promise.resolve();
      inFlight = true;
      return fetch(apiUrl + '?action=list', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (!data || !data.ok) throw new Error('Failed to load notifications');
          renderList(data.items || [], data.unread_count || 0, data.total_count || 0);
          enqueueToasts(data.items || []);
          hasLoaded = true;
        })
        .catch(function() {
          setState('Unable to load notifications right now.');
        })
        .finally(function() { inFlight = false; });
    }

    function postAction(action, payload) {
      var body = new URLSearchParams();
      body.set('action', action);
      body.set('csrf_token', csrfToken);
      Object.keys(payload || {}).forEach(function(k) { body.set(k, payload[k]); });
      return fetch(apiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      }).then(function(r) { return r.json(); });
    }

    function markRead(notificationId, rowEl) {
      if (!notificationId) return;
      postAction('mark_read', { notification_id: notificationId })
        .then(function(data) {
          if (!data || !data.ok) return;
          if (rowEl) {
            rowEl.classList.remove('is-unread');
            rowEl.classList.add('is-read');
            var btn = rowEl.querySelector('.ere-notif__item-mark');
            var dot = rowEl.querySelector('.ere-notif__dot');
            if (btn) btn.style.display = 'none';
            if (dot) dot.style.display = 'none';
          }
          updateBadge(data.unread_count || 0);
          fetchList(true);
        });
    }

    function markAllRead() {
      postAction('mark_all_read', {})
        .then(function(data) {
          if (!data || !data.ok) return;
          updateBadge(0);
          fetchList(true);
        });
    }

    function deleteNotification(notificationId, rowEl) {
      if (!notificationId) return;
      postAction('delete', { notification_id: notificationId })
        .then(function(data) {
          if (!data || !data.ok) return;
          if (rowEl && rowEl.parentNode) {
            rowEl.style.opacity = '0';
            rowEl.style.transform = 'translateY(-6px)';
            setTimeout(function() {
              if (rowEl.parentNode) rowEl.parentNode.removeChild(rowEl);
            }, 180);
          }
          updateBadge(data.unread_count || 0);
          fetchList(true);
        });
    }

    function syncButtonState(isOpen) {
      var toggles = document.querySelectorAll('[data-notification-toggle]');
      toggles.forEach(function(btn) {
        btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
      panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    }

    function openPanel(btn) {
      activeToggle = btn || null;
      panel.classList.add('is-open');
      syncButtonState(true);
      document.body.style.overflow = 'hidden';
      if (!hasLoaded) fetchList(false);
    }

    function closePanel(restoreFocus) {
      panel.classList.remove('is-open');
      syncButtonState(false);
      document.body.style.overflow = '';
      if (restoreFocus && activeToggle && typeof activeToggle.focus === 'function') {
        activeToggle.focus();
      }
    }

    document.addEventListener('click', function(e) {
      var toggle = e.target.closest('[data-notification-toggle]');
      if (toggle) {
        e.preventDefault();
        var isOpen = panel.classList.contains('is-open');
        if (isOpen) closePanel(false);
        else openPanel(toggle);
      }
    });

    closeButtons.forEach(function(btn) {
      btn.addEventListener('click', function() { closePanel(true); });
    });
    if (markAllBtn) {
      markAllBtn.addEventListener('click', function() { markAllRead(); });
    }

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && panel.classList.contains('is-open')) {
        closePanel(true);
      }
    });

    fetchList(false);
    setInterval(function() { fetchList(false); }, 45000);
  })();
</script>
