<?php
/**
 * Shared admin confirm / notice dialogs (styled — not browser alert/confirm).
 * Include once before </body> on admin pages.
 */
if (!empty($GLOBALS['admin_ui_dialogs_included'])) {
    return;
}
$GLOBALS['admin_ui_dialogs_included'] = true;
?>
<style>
  .admin-ui-dialog-overlay {
    position: fixed; inset: 0; z-index: 12000;
    display: none; align-items: center; justify-content: center;
    padding: 1rem; background: rgba(15, 23, 42, 0.55);
  }
  .admin-ui-dialog-overlay.is-open { display: flex; }
  .admin-ui-dialog {
    width: min(100%, 26rem);
    border-radius: 1rem;
    padding: 1.15rem 1.2rem 1rem;
    background: #0f172a;
    border: 1px solid rgba(148, 163, 184, 0.35);
    color: #f8fafc;
    box-shadow: 0 20px 50px rgba(2, 6, 23, 0.45);
  }
  .admin-ui-dialog__hero { display: flex; gap: 0.75rem; align-items: flex-start; margin-bottom: 0.85rem; }
  .admin-ui-dialog__icon {
    flex: 0 0 auto; width: 2.5rem; height: 2.5rem; border-radius: 0.75rem;
    display: inline-flex; align-items: center; justify-content: center;
    background: rgba(37, 99, 235, 0.22); color: #93c5fd; font-size: 1.15rem;
  }
  .admin-ui-dialog__icon--success { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; }
  .admin-ui-dialog__icon--error { background: rgba(239, 68, 68, 0.2); color: #fca5a5; }
  .admin-ui-dialog__title { margin: 0; font-size: 1.05rem; font-weight: 700; color: #f8fafc; }
  .admin-ui-dialog__desc { margin: 0.35rem 0 0; font-size: 0.875rem; line-height: 1.45; color: rgba(226, 232, 240, 0.88); }
  .admin-ui-dialog__actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem; flex-wrap: wrap; }
  .admin-ui-dialog__btn {
    border-radius: 0.65rem; border: 1px solid transparent; padding: 0.45rem 0.9rem;
    font-size: 0.875rem; font-weight: 600; cursor: pointer;
  }
  .admin-ui-dialog__btn--ghost {
    background: rgba(30, 41, 59, 0.9); border-color: rgba(148, 163, 184, 0.35); color: #e2e8f0;
  }
  .admin-ui-dialog__btn--ok {
    background: linear-gradient(145deg, #2563eb 0%, #1d4ed8 100%);
    border-color: rgba(96, 165, 250, 0.55); color: #fff;
  }
  .admin-ui-dialog--center { text-align: center; }
  .admin-ui-dialog--center .admin-ui-dialog__icon { margin: 0 auto 0.65rem; }
  .admin-ui-dialog--center .admin-ui-dialog__actions { justify-content: center; }
</style>

<div id="adminUiConfirmOverlay" class="admin-ui-dialog-overlay" aria-hidden="true">
  <section class="admin-ui-dialog" role="dialog" aria-modal="true" aria-labelledby="adminUiConfirmTitle">
    <div class="admin-ui-dialog__hero">
      <span class="admin-ui-dialog__icon" id="adminUiConfirmIcon"><i class="bi bi-question-circle-fill"></i></span>
      <div>
        <h3 id="adminUiConfirmTitle" class="admin-ui-dialog__title">Confirm</h3>
        <p id="adminUiConfirmMessage" class="admin-ui-dialog__desc">Are you sure?</p>
      </div>
    </div>
    <div class="admin-ui-dialog__actions">
      <button type="button" id="adminUiConfirmCancelBtn" class="admin-ui-dialog__btn admin-ui-dialog__btn--ghost">Cancel</button>
      <button type="button" id="adminUiConfirmOkBtn" class="admin-ui-dialog__btn admin-ui-dialog__btn--ok">Confirm</button>
    </div>
  </section>
</div>

<div id="adminUiNoticeOverlay" class="admin-ui-dialog-overlay" aria-hidden="true">
  <section class="admin-ui-dialog admin-ui-dialog--center" role="dialog" aria-modal="true" aria-labelledby="adminUiNoticeTitle">
    <span class="admin-ui-dialog__icon admin-ui-dialog__icon--success" id="adminUiNoticeIcon"><i class="bi bi-check-circle-fill"></i></span>
    <h3 id="adminUiNoticeTitle" class="admin-ui-dialog__title">Notice</h3>
    <p id="adminUiNoticeMessage" class="admin-ui-dialog__desc">Message</p>
    <div class="admin-ui-dialog__actions">
      <button type="button" id="adminUiNoticeOkBtn" class="admin-ui-dialog__btn admin-ui-dialog__btn--ok">OK</button>
    </div>
  </section>
</div>

<script>
(function () {
  if (window.adminUiDialog) return;

  function openOverlay(el) {
    if (!el) return;
    el.classList.add('is-open');
    el.setAttribute('aria-hidden', 'false');
  }
  function closeOverlay(el) {
    if (!el) return;
    el.classList.remove('is-open');
    el.setAttribute('aria-hidden', 'true');
  }

  var confirmOverlay = document.getElementById('adminUiConfirmOverlay');
  var confirmTitle = document.getElementById('adminUiConfirmTitle');
  var confirmMsg = document.getElementById('adminUiConfirmMessage');
  var confirmIcon = document.getElementById('adminUiConfirmIcon');
  var confirmCancel = document.getElementById('adminUiConfirmCancelBtn');
  var confirmOk = document.getElementById('adminUiConfirmOkBtn');
  var confirmCb = null;

  var noticeOverlay = document.getElementById('adminUiNoticeOverlay');
  var noticeTitle = document.getElementById('adminUiNoticeTitle');
  var noticeMsg = document.getElementById('adminUiNoticeMessage');
  var noticeIcon = document.getElementById('adminUiNoticeIcon');
  var noticeOk = document.getElementById('adminUiNoticeOkBtn');

  function closeConfirm() {
    confirmCb = null;
    closeOverlay(confirmOverlay);
  }

  function confirm(opts) {
    opts = opts || {};
    if (confirmTitle) confirmTitle.textContent = opts.title || 'Confirm';
    if (confirmMsg) confirmMsg.textContent = opts.message || 'Are you sure?';
    if (confirmOk) confirmOk.textContent = opts.okLabel || 'Confirm';
    if (confirmCancel) confirmCancel.textContent = opts.cancelLabel || 'Cancel';
    if (confirmIcon) {
      confirmIcon.innerHTML = opts.iconHtml || '<i class="bi bi-question-circle-fill"></i>';
    }
    confirmCb = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
    openOverlay(confirmOverlay);
  }

  function notice(opts) {
    opts = opts || {};
    var type = opts.type || 'success';
    if (noticeTitle) noticeTitle.textContent = opts.title || 'Notice';
    if (noticeMsg) noticeMsg.textContent = opts.message || '';
    if (noticeIcon) {
      if (type === 'error') {
        noticeIcon.className = 'admin-ui-dialog__icon admin-ui-dialog__icon--error';
        noticeIcon.innerHTML = '<i class="bi bi-x-octagon-fill"></i>';
      } else if (type === 'info') {
        noticeIcon.className = 'admin-ui-dialog__icon';
        noticeIcon.innerHTML = '<i class="bi bi-info-circle-fill"></i>';
      } else {
        noticeIcon.className = 'admin-ui-dialog__icon admin-ui-dialog__icon--success';
        noticeIcon.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
      }
    }
    openOverlay(noticeOverlay);
  }

  if (confirmCancel) confirmCancel.addEventListener('click', closeConfirm);
  if (confirmOk) {
    confirmOk.addEventListener('click', function () {
      var cb = confirmCb;
      closeConfirm();
      if (cb) cb();
    });
  }
  if (confirmOverlay) {
    confirmOverlay.addEventListener('click', function (e) {
      if (e.target === confirmOverlay) closeConfirm();
    });
  }
  if (noticeOk) noticeOk.addEventListener('click', function () { closeOverlay(noticeOverlay); });
  if (noticeOverlay) {
    noticeOverlay.addEventListener('click', function (e) {
      if (e.target === noticeOverlay) closeOverlay(noticeOverlay);
    });
  }

  /** Intercept forms with data-admin-confirm-* attributes. */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.getAttribute) return;
    var msg = form.getAttribute('data-admin-confirm');
    if (!msg) return;
    if (form.getAttribute('data-admin-confirm-accepted') === '1') {
      form.removeAttribute('data-admin-confirm-accepted');
      return;
    }
    e.preventDefault();
    confirm({
      title: form.getAttribute('data-admin-confirm-title') || 'Confirm',
      message: msg,
      okLabel: form.getAttribute('data-admin-confirm-ok') || 'Confirm',
      iconHtml: form.getAttribute('data-admin-confirm-icon') || '<i class="bi bi-envelope"></i>',
      onConfirm: function () {
        form.setAttribute('data-admin-confirm-accepted', '1');
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      }
    });
  }, true);

  window.adminUiDialog = { confirm: confirm, notice: notice };
})();
</script>
