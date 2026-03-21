<?php
/**
 * Logout confirmation modal — include once per layout.
 * Set $ereviewLogoutModalVariant to 'admin' or 'student' before including.
 */
$ereviewLogoutModalVariant = ($ereviewLogoutModalVariant ?? 'admin') === 'student' ? 'student' : 'admin';
$ereviewLogoutModalThemeClass = $ereviewLogoutModalVariant === 'student'
    ? 'ereview-logout-root--student'
    : 'ereview-logout-root--admin';
?>
<div id="ereviewLogoutModal"
     class="ereview-logout-root <?php echo $ereviewLogoutModalThemeClass; ?>"
     hidden
     aria-hidden="true"
     role="presentation">
  <div class="ereview-logout-backdrop" data-ereview-logout-dismiss tabindex="-1" aria-hidden="true"></div>
  <div class="ereview-logout-dialog-wrap" role="alertdialog" aria-modal="true" aria-labelledby="ereviewLogoutTitle" aria-describedby="ereviewLogoutDesc">
    <div class="ereview-logout-dialog">
      <div class="ereview-logout-icon" aria-hidden="true">
        <i class="bi bi-box-arrow-right"></i>
      </div>
      <h2 id="ereviewLogoutTitle" class="ereview-logout-title">Confirm logout</h2>
      <p id="ereviewLogoutDesc" class="ereview-logout-desc">Are you sure you want to log out of your account? You will need to sign in again to continue.</p>
      <div class="ereview-logout-actions">
        <button type="button" class="ereview-logout-btn ereview-logout-btn--cancel" data-ereview-logout-dismiss>Cancel</button>
        <a href="logout.php" class="ereview-logout-btn ereview-logout-btn--confirm">Log out</a>
      </div>
    </div>
  </div>
</div>
<style>
/* --- Logout modal (shared) --- */
.ereview-logout-root {
  position: fixed;
  inset: 0;
  z-index: 10050;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  box-sizing: border-box;
}
.ereview-logout-root[hidden] {
  display: none !important;
}
.ereview-logout-backdrop {
  position: absolute;
  inset: 0;
  cursor: pointer;
}
.ereview-logout-dialog-wrap {
  position: relative;
  width: 100%;
  max-width: 400px;
  transform: scale(0.94) translateY(8px);
  opacity: 0;
  transition: opacity 0.28s cubic-bezier(0.22, 1, 0.36, 1), transform 0.32s cubic-bezier(0.22, 1, 0.36, 1);
  pointer-events: none;
}
.ereview-logout-root.is-visible .ereview-logout-dialog-wrap {
  opacity: 1;
  transform: scale(1) translateY(0);
  pointer-events: auto;
}
.ereview-logout-dialog {
  border-radius: 16px;
  padding: 28px 26px 24px;
  text-align: center;
  box-shadow: 0 24px 48px rgba(0, 0, 0, 0.45);
}
.ereview-logout-icon {
  width: 52px;
  height: 52px;
  margin: 0 auto 16px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
}
.ereview-logout-title {
  margin: 0 0 10px;
  font-size: 1.25rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  line-height: 1.25;
}
.ereview-logout-desc {
  margin: 0 0 24px;
  font-size: 0.9rem;
  line-height: 1.5;
}
.ereview-logout-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: center;
}
.ereview-logout-btn {
  flex: 1 1 120px;
  min-height: 44px;
  padding: 0 18px;
  border-radius: 12px;
  font-size: 0.9rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease;
  box-sizing: border-box;
  border: 1px solid transparent;
}
.ereview-logout-btn:active {
  transform: scale(0.98);
}
.ereview-logout-btn--cancel {
  background: transparent;
}
.ereview-logout-btn--confirm {
  border: none;
  font-family: inherit;
}

/* Admin (dark / Grok-style — matches body.admin-app) */
.ereview-logout-root--admin .ereview-logout-backdrop {
  background: rgba(0, 0, 0, 0.78);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
}
.ereview-logout-root--admin .ereview-logout-dialog {
  background: #141414;
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: #e4e4e7;
}
.ereview-logout-root--admin .ereview-logout-icon {
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.9);
}
.ereview-logout-root--admin .ereview-logout-title {
  color: #fafafa;
}
.ereview-logout-root--admin .ereview-logout-desc {
  color: #a1a1aa;
}
.ereview-logout-root--admin .ereview-logout-btn--cancel {
  border-color: rgba(255, 255, 255, 0.35);
  color: #fafafa;
}
.ereview-logout-root--admin .ereview-logout-btn--cancel:hover {
  background: rgba(255, 255, 255, 0.08);
  border-color: rgba(255, 255, 255, 0.5);
}
.ereview-logout-root--admin .ereview-logout-btn--confirm {
  background: #fafafa;
  color: #0a0a0a;
  box-shadow: 0 4px 14px rgba(255, 255, 255, 0.12);
}
.ereview-logout-root--admin .ereview-logout-btn--confirm:hover {
  background: #ffffff;
  box-shadow: 0 6px 20px rgba(255, 255, 255, 0.18);
}

/* Student (navy + white cards) */
.ereview-logout-root--student .ereview-logout-backdrop {
  background: rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}
.ereview-logout-root--student .ereview-logout-dialog {
  background: #ffffff;
  border: 1px solid rgba(22, 101, 160, 0.12);
  color: #334155;
  box-shadow: 0 20px 50px rgba(20, 61, 89, 0.2), 0 0 0 1px rgba(255, 255, 255, 0.6) inset;
}
.ereview-logout-root--student .ereview-logout-icon {
  background: rgba(22, 101, 160, 0.1);
  border: 1px solid rgba(22, 101, 160, 0.2);
  color: #1665a0;
}
.ereview-logout-root--student .ereview-logout-title {
  color: #0f172a;
}
.ereview-logout-root--student .ereview-logout-desc {
  color: #64748b;
}
.ereview-logout-root--student .ereview-logout-btn--cancel {
  border-color: #e2e8f0;
  color: #475569;
  background: #f8fafc;
}
.ereview-logout-root--student .ereview-logout-btn--cancel:hover {
  background: #f1f5f9;
  border-color: #cbd5e1;
}
.ereview-logout-root--student .ereview-logout-btn--confirm {
  background: linear-gradient(135deg, #1665a0 0%, #145a8f 100%);
  color: #ffffff;
  box-shadow: 0 4px 14px rgba(22, 101, 160, 0.35);
}
.ereview-logout-root--student .ereview-logout-btn--confirm:hover {
  filter: brightness(1.05);
  box-shadow: 0 6px 20px rgba(22, 101, 160, 0.4);
}
@media (prefers-reduced-motion: reduce) {
  .ereview-logout-dialog-wrap {
    transition: none;
  }
  .ereview-logout-root.is-visible .ereview-logout-dialog-wrap {
    transform: none;
  }
  .ereview-logout-btn:active {
    transform: none;
  }
}
</style>
<script>
(function () {
  var modal = document.getElementById('ereviewLogoutModal');
  if (!modal) return;

  var dismissEls = modal.querySelectorAll('[data-ereview-logout-dismiss]');
  var confirmBtn = modal.querySelector('.ereview-logout-btn--confirm');
  var lastFocus = null;
  var closeTimer = null;

  function openModal() {
    if (closeTimer) {
      clearTimeout(closeTimer);
      closeTimer = null;
    }
    lastFocus = document.activeElement;
    modal.hidden = false;
    modal.removeAttribute('aria-hidden');
    requestAnimationFrame(function () {
      modal.classList.add('is-visible');
    });
    document.body.style.overflow = 'hidden';
    window.setTimeout(function () {
      if (confirmBtn) confirmBtn.focus();
    }, 50);
  }

  function closeModal() {
    modal.classList.remove('is-visible');
    document.body.style.overflow = '';
    closeTimer = window.setTimeout(function () {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      if (lastFocus && typeof lastFocus.focus === 'function') {
        try { lastFocus.focus(); } catch (err) {}
      }
      closeTimer = null;
    }, 320);
  }

  /* Delegation: triggers may appear later in the document (e.g. admin topbar after this include). */
  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('a.ereview-logout-trigger') : null;
    if (!t) return;
    e.preventDefault();
    openModal();
  });

  dismissEls.forEach(function (el) {
    el.addEventListener('click', function () {
      closeModal();
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) {
      closeModal();
    }
  });
})();
</script>
