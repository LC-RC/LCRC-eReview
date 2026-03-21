<?php
/**
 * Admin Topbar – Reusable across all admin pages.
 * Built from the student topbar pattern, but tailored for admin (black/white theme).
 */
$tzName = 'Asia/Manila';
$tzObj  = @timezone_open($tzName);
$now    = new DateTime('now', $tzObj ?: null);
$offsetLabel = 'PHT · UTC+08:00';
$adminName = $_SESSION['full_name'] ?? 'Admin';
?>
<header class="admin-topbar admin-topbar-modern sticky top-0 z-[999] mt-0 mb-4" x-data="{
    userMenuOpen: false,
    searchFocused: false,
    toggleSidebar() { window.toggleAdminSidebar && window.toggleAdminSidebar(); },
    closeAll() { this.userMenuOpen = false; }
  }" @keydown.escape.window="closeAll()">
  <div class="admin-topbar-inner">
    <div class="admin-topbar-left">
      <button type="button" id="admin-sidebar-toggle-btn" aria-label="Toggle sidebar" aria-expanded="false" aria-controls="sidebar" class="admin-topbar-menu-btn" @click="toggleSidebar()">
        <span class="burger-icon" aria-hidden="true">
          <span class="burger-line burger-line--1"></span>
          <span class="burger-line burger-line--2"></span>
          <span class="burger-line burger-line--3"></span>
        </span>
      </button>
      <a href="admin_dashboard.php" class="admin-topbar-brand flex items-center gap-2 shrink-0 rounded-xl px-3 py-2 transition-all duration-300">
        <i class="bi bi-mortarboard-fill admin-topbar-brand-icon" aria-hidden="true"></i>
        <span class="admin-topbar-brand-text font-bold tracking-tight whitespace-nowrap hidden sm:inline">LCRC eReview</span>
      </a>
      <div class="admin-topbar-search-wrap" :class="{ 'is-focused': searchFocused }">
        <i class="bi bi-search admin-topbar-search-icon" aria-hidden="true"></i>
        <input type="search" placeholder="Search students, subjects..." aria-label="Search" class="admin-topbar-search"
               @focus="searchFocused = true" @blur="searchFocused = false">
      </div>
    </div>

    <div class="admin-topbar-right">
      <div class="admin-topbar-time" aria-label="Current timezone and local time">
        <span class="admin-topbar-time-icon" aria-hidden="true"><i class="bi bi-clock"></i></span>
        <div class="admin-topbar-time-text">
          <span class="admin-topbar-time-label">Local time</span>
          <span class="admin-topbar-time-value">
            <span id="adminTopbarTimeMain"><?php echo htmlspecialchars($now->format('M j · g:i A')); ?></span>
            <span class="admin-topbar-time-offset" id="adminTopbarTimeOffset"><?php echo htmlspecialchars($offsetLabel); ?></span>
          </span>
        </div>
      </div>

      <nav class="admin-topbar-actions" aria-label="Quick actions">
        <button type="button" aria-label="Notifications" class="admin-topbar-action admin-topbar-action--notif" title="Notifications">
          <i class="bi bi-bell" aria-hidden="true"></i>
        </button>
      </nav>

      <div class="admin-topbar-profile-wrap">
        <button type="button" @click="userMenuOpen = !userMenuOpen" aria-haspopup="true" :aria-expanded="userMenuOpen" class="admin-topbar-profile-btn">
          <span class="admin-topbar-avatar" aria-hidden="true"><?php echo strtoupper(mb_substr(trim($adminName ?: 'A'), 0, 1)); ?></span>
          <span class="admin-topbar-name"><?php echo h($adminName); ?></span>
          <i class="bi bi-chevron-down admin-topbar-chevron" aria-hidden="true" :class="{ 'is-open': userMenuOpen }"></i>
        </button>
        <div x-show="userMenuOpen" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95 translate-y-0"
             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             @click.outside="userMenuOpen = false"
             class="admin-topbar-dropdown" role="menu">
          <div class="admin-topbar-dropdown-head">
            <p class="admin-topbar-dropdown-label">Account</p>
            <p class="admin-topbar-dropdown-name"><?php echo h($adminName); ?></p>
          </div>
          <a href="logout.php" class="ereview-logout-trigger admin-topbar-dropdown-item admin-topbar-logout" role="menuitem" @click="userMenuOpen = false">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            <span>Log out</span>
          </a>
        </div>
      </div>
    </div>
  </div>
</header>
<style>[x-cloak]{display:none!important}</style>
<script>
  // Real-time Philippines (Asia/Manila) clock in topbar
  document.addEventListener('DOMContentLoaded', function () {
    var mainEl = document.getElementById('adminTopbarTimeMain');
    var offsetEl = document.getElementById('adminTopbarTimeOffset');
    if (!mainEl || !offsetEl) return;
    var formatter = new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
      timeZone: 'Asia/Manila'
    });
    var offsetLabel = 'PHT · UTC+08:00';
    function updateTime() {
      var now = new Date();
      var formatted = formatter.format(now).replace(',', ' ·');
      mainEl.textContent = formatted;
      offsetEl.textContent = offsetLabel;
    }
    updateTime();
    setInterval(updateTime, 60 * 1000);
  });
</script>
