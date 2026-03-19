<?php
/**
 * Admin Topbar – Reusable across all admin pages.
 * Renders current time, date, and notification icon. Stays aligned with sidebar.
 */
$adminTopbarDate = date('l, F j, Y');
$adminTopbarTime = date('g:i A');
?>
<header class="admin-topbar flex items-center gap-4 px-5 py-3 border-b border-white/10 bg-[#0a0a0a]/80 backdrop-blur-sm shrink-0" aria-label="Admin top bar">
  <div class="flex items-center gap-6 text-sm shrink-0">
    <span class="text-white/90 font-medium" aria-live="polite">
      <i class="bi bi-calendar3 mr-1.5 text-white/60"></i>
      <time id="admin-topbar-date"><?php echo h($adminTopbarDate); ?></time>
    </span>
    <span class="text-white/90 font-medium tabular-nums" aria-live="polite">
      <i class="bi bi-clock mr-1.5 text-white/60"></i>
      <time id="admin-topbar-time"><?php echo h($adminTopbarTime); ?></time>
    </span>
  </div>
  <div class="flex-1 flex justify-center items-center gap-3 min-w-0">
    <img src="image%20assets/lms-logo.png" alt="LCRC" class="admin-topbar-logo h-8 w-auto max-h-9 object-contain object-center select-none" loading="eager" decoding="async">
    <span class="text-white/90 font-semibold text-sm whitespace-nowrap">Welcome Admin</span>
  </div>
  <div class="flex items-center gap-3 shrink-0">
    <button type="button" class="p-2 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition focus:outline-none focus:ring-2 focus:ring-white/30" title="Notifications" aria-label="Notifications">
      <i class="bi bi-bell text-lg"></i>
    </button>
  </div>
</header>
<script>
(function() {
  function pad(n) { return n < 10 ? '0' + n : n; }
  function updateTime() {
    var d = new Date();
    var timeEl = document.getElementById('admin-topbar-time');
    var dateEl = document.getElementById('admin-topbar-date');
    if (timeEl) {
      var h = d.getHours(), m = d.getMinutes(), am = h < 12;
      timeEl.textContent = (h % 12 || 12) + ':' + pad(m) + ' ' + (am ? 'AM' : 'PM');
    }
    if (dateEl) {
      var options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
      dateEl.textContent = d.toLocaleDateString(undefined, options);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function() { updateTime(); setInterval(updateTime, 60000); });
  else { updateTime(); setInterval(updateTime, 60000); }
})();
</script>
