<?php
// Student Topbar – refined modern UI: elevation, search, actions, profile.
?>
<header class="student-topbar sticky top-0 z-[999] mt-0 mb-4 student-topbar-modern" x-data="{
    userMenuOpen: false,
    helpMenuOpen: false,
    prefsMenuOpen: false,
    searchFocused: false,
    toggleSidebar() { Alpine.store('sidebar') && Alpine.store('sidebar').toggle(); },
    closeAll() { this.userMenuOpen = this.helpMenuOpen = this.prefsMenuOpen = false; }
  }" @keydown.escape.window="closeAll()">
  <div class="student-topbar-inner">
    <!-- Left: menu + brand + search -->
    <div class="student-topbar-left">
      <button type="button" id="student-sidebar-toggle-btn" @click="toggleSidebar()" aria-label="Toggle sidebar" class="student-topbar-menu-btn" :class="{ 'burger--open': Alpine.store('sidebar') && Alpine.store('sidebar').collapsed }">
        <span class="burger-icon" aria-hidden="true">
          <span class="burger-line burger-line--1"></span>
          <span class="burger-line burger-line--2"></span>
          <span class="burger-line burger-line--3"></span>
        </span>
      </button>
      <a href="student_dashboard.php" class="student-topbar-brand flex items-center gap-2 shrink-0 rounded-xl px-3 py-2 transition-all duration-300">
        <i class="bi bi-mortarboard-fill text-[#1665A0]" style="font-size:1.25rem" aria-hidden="true"></i>
        <span class="font-bold text-gray-800 text-base tracking-tight whitespace-nowrap hidden sm:inline">LCRC eReview</span>
      </a>
      <div class="student-topbar-search-wrap" :class="{ 'is-focused': searchFocused }">
        <i class="bi bi-search student-topbar-search-icon" aria-hidden="true"></i>
        <input type="search" placeholder="Search courses, subjects..." aria-label="Search" class="student-topbar-search" @focus="searchFocused = true" @blur="searchFocused = false">
      </div>
    </div>

    <!-- Right: timezone + actions + profile -->
    <div class="student-topbar-right">
      <?php
        $tzName = 'Asia/Manila';
        $tzObj  = @timezone_open($tzName);
        $now    = new DateTime('now', $tzObj ?: null);
        $offsetLabel = 'PHT · UTC+08:00';
      ?>
      <div class="student-topbar-time" aria-label="Current timezone and local time">
        <span class="student-topbar-time-icon" aria-hidden="true">
          <i class="bi bi-clock"></i>
        </span>
        <div class="student-topbar-time-text">
          <span class="student-topbar-time-label">Local time</span>
          <span class="student-topbar-time-value">
            <span id="studentTopbarTimeMain"><?php echo htmlspecialchars($now->format('M j · g:i A')); ?></span>
            <span class="student-topbar-time-offset" id="studentTopbarTimeOffset"><?php echo htmlspecialchars($offsetLabel); ?></span>
          </span> 
        </div>
      </div>
      <nav class="student-topbar-actions" aria-label="Quick actions">
        <!-- Help Center -->
        <button type="button" aria-label="Help center" class="student-topbar-action" title="Help center"
          @click.stop="helpMenuOpen = !helpMenuOpen; prefsMenuOpen = false; userMenuOpen = false;">
          <i class="bi bi-question-circle" aria-hidden="true"></i>
        </button>
        <!-- Preferences -->
        <button type="button" aria-label="Preferences" class="student-topbar-action student-topbar-action--settings" title="Preferences"
          @click.stop="prefsMenuOpen = !prefsMenuOpen; helpMenuOpen = false; userMenuOpen = false;">
          <i class="bi bi-gear" aria-hidden="true"></i>
        </button>
        <!-- Notifications -->
        <button type="button" aria-label="Notifications" class="student-topbar-action student-topbar-action--notif has-unread" title="Notifications">
          <i class="bi bi-bell" aria-hidden="true"></i>
          <span class="student-topbar-badge" aria-hidden="true"></span>
        </button>
      </nav>
      <div class="student-topbar-profile-wrap">
        <button type="button" @click="userMenuOpen = !userMenuOpen" aria-haspopup="true" :aria-expanded="userMenuOpen" class="student-topbar-profile-btn">
          <span class="student-topbar-avatar" aria-hidden="true"><?php echo strtoupper(mb_substr(trim($_SESSION['full_name'] ?? 'U'), 0, 1)); ?></span>
          <span class="student-topbar-name"><?php echo h($_SESSION['full_name']); ?></span>
          <i class="bi bi-chevron-down student-topbar-chevron" aria-hidden="true" :class="{ 'is-open': userMenuOpen }"></i>
        </button>
        <div x-show="userMenuOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-0" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" @click.outside="userMenuOpen = false" class="student-topbar-dropdown" role="menu">
          <div class="student-topbar-dropdown-head">
            <p class="student-topbar-dropdown-label">Account</p>
            <p class="student-topbar-dropdown-name"><?php echo h($_SESSION['full_name']); ?></p>
          </div>
          <a href="logout.php" class="student-topbar-dropdown-item student-topbar-logout" role="menuitem">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            <span>Log out</span>
          </a>
        </div>
      </div>
    </div>
  </div>
</header>
<style>
[x-cloak]{display:none!important}

/* ---- Topbar container ---- */
.student-topbar-modern {
  --topbar-bg: #ffffff;
  --topbar-border: rgba(22, 101, 160, 0.08);
  --topbar-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  --topbar-height: 80px;
  --topbar-radius: 16px;
  --brand: #1665A0;
  --brand-light: rgba(22, 101, 160, 0.1);
  --brand-focus: rgba(22, 101, 160, 0.2);
  --text: #334155;
  --text-muted: #64748b;
  --surface: #f8fafc;
  --surface-hover: #f1f5f9;
}

/* Full-bleed: cancel main's px-5 so topbar touches sidebar and viewport right edge */
.student-topbar-modern {
  margin-left: -20px;
  margin-right: -20px;
}
.student-topbar-inner {
  min-height: var(--topbar-height);
  padding-left: 12px;
  padding-right: 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  background: linear-gradient(to bottom, #ffffff 0%, #f5f8ff 55%, #eef4ff 100%);
  border-bottom: 1px solid var(--topbar-border);
  box-shadow: var(--topbar-shadow);
  border-radius: 0;
}

/* ---- Left: menu + search ---- */
.student-topbar-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
  flex: 1;
  max-width: 480px;
}

/* Brand link (LCRC eReview) – modern hover */
.student-topbar-brand {
  color: var(--text);
  text-decoration: none;
}
.student-topbar-brand:hover {
  color: var(--brand);
  background: var(--brand-light);
  box-shadow: 0 2px 10px rgba(22, 101, 160, 0.12);
}
.student-topbar-brand:hover i {
  transform: scale(1.08);
}
.student-topbar-brand:active {
  transform: scale(0.98);
}
.student-topbar-brand i {
  transition: transform 0.25s ease;
}

/* Burger button: 3-line → X morph when sidebar collapsed */
.student-topbar-menu-btn {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--text-muted);
  background: transparent;
  border: 1px solid transparent;
  transition: color 0.25s ease, background 0.25s ease, transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.25s ease;
  flex-shrink: 0;
}
.student-topbar-menu-btn:hover {
  color: var(--brand);
  background: var(--brand-light);
  box-shadow: 0 2px 12px rgba(22, 101, 160, 0.14);
  transform: scale(1.05);
}
.student-topbar-menu-btn:active {
  transform: scale(0.96);
  transition-duration: 0.15s;
}
.student-topbar-menu-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px var(--brand-focus);
}

.burger-icon {
  width: 22px;
  height: 18px;
  position: relative;
  display: block;
}
.burger-line {
  position: absolute;
  left: 0;
  width: 100%;
  height: 2.5px;
  border-radius: 2px;
  background: currentColor;
  transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.25s ease, top 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}
.burger-line--1 { top: 0; }
.burger-line--2 { top: 50%; transform: translateY(-50%); }
.burger-line--3 { top: 100%; transform: translateY(-100%); }

/* Morph to X when sidebar is collapsed */
.student-topbar-menu-btn.burger--open .burger-line--1 {
  top: 50%;
  transform: translateY(-50%) rotate(45deg);
}
.student-topbar-menu-btn.burger--open .burger-line--2 {
  opacity: 0;
  transform: translateY(-50%) scaleX(0);
}
.student-topbar-menu-btn.burger--open .burger-line--3 {
  top: 50%;
  transform: translateY(-50%) rotate(-45deg);
}

/* Search wrapper: pill, focus state, modern hover */
.student-topbar-search-wrap {
  position: relative;
  flex: 1;
  min-width: 0;
  border-radius: 12px;
  background: var(--surface);
  border: 1px solid transparent;
  transition: background 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
}
.student-topbar-search-wrap:hover {
  background: var(--surface-hover);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.student-topbar-search-wrap.is-focused {
  background: #fff;
  border-color: var(--brand);
  box-shadow: 0 0 0 3px var(--brand-light);
}
.student-topbar-search-wrap.is-focused .student-topbar-search-icon {
  color: var(--brand);
}

.student-topbar-search-icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-muted);
  font-size: 1.1rem;
  pointer-events: none;
  transition: color 0.2s;
}

.student-topbar-search {
  width: 100%;
  padding: 12px 16px 12px 44px;
  border: none;
  border-radius: 12px;
  background: transparent;
  color: var(--text);
  font-size: 0.9375rem;
  transition: color 0.2s;
}
.student-topbar-search::placeholder {
  color: #94a3b8;
}
.student-topbar-search:focus {
  outline: none;
}

/* ---- Right: actions + profile ---- */
.student-topbar-right {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}

/* Timezone pill */
.student-topbar-time {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  border-radius: 999px;
  background: linear-gradient(135deg, rgba(22, 101, 160, 0.08), rgba(20, 61, 89, 0.06));
  border: 1px solid rgba(22, 101, 160, 0.16);
  color: var(--text);
  font-size: 0.8125rem;
  line-height: 1.2;
  white-space: nowrap;
}
.student-topbar-time-icon {
  width: 24px;
  height: 24px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #ffffff;
  color: var(--brand);
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.15);
}
.student-topbar-time-icon i {
  font-size: 0.9rem;
}
.student-topbar-time-text {
  display: flex;
  flex-direction: column;
}
.student-topbar-time-label {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-muted);
}
.student-topbar-time-value {
  font-weight: 600;
  color: var(--text);
}
.student-topbar-time-offset {
  margin-left: 4px;
  font-weight: 500;
  color: var(--brand);
}
@media (max-width: 768px) {
  .student-topbar-time {
    display: none;
  }
}

.student-topbar-actions {
  display: flex;
  align-items: center;
  gap: 4px;
}
.student-topbar-action {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--text-muted);
  background: transparent;
  transition: color 0.25s ease, background 0.25s ease, transform 0.2s ease, box-shadow 0.25s ease;
}
.student-topbar-action:hover {
  color: var(--brand);
  background: var(--brand-light);
  box-shadow: 0 2px 12px rgba(22, 101, 160, 0.14);
  transform: translateY(-2px) scale(1.02);
}
.student-topbar-action:hover i {
  transform: scale(1.08);
}
.student-topbar-action:active {
  transform: translateY(0) scale(0.97);
}
.student-topbar-action:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px var(--brand-focus);
}
.student-topbar-action i {
  font-size: 1.25rem;
  transition: transform 0.2s ease;
}
.student-topbar-action--settings {
  display: none;
}
@media (min-width: 640px) {
  .student-topbar-action--settings { display: inline-flex; }
}

.student-topbar-action--notif {
  position: relative;
}
.student-topbar-action--notif.has-unread {
  background: rgba(22, 101, 160, 0.12);
  color: var(--brand);
}
.student-topbar-action--notif.has-unread:hover {
  background: rgba(22, 101, 160, 0.22);
}
.student-topbar-badge {
  position: absolute;
  top: 9px;
  right: 9px;
  width: 9px;
  height: 9px;
  min-width: 9px;
  min-height: 9px;
  border-radius: 50%;
  background: #ef4444;
  box-shadow: 0 0 0 2px var(--topbar-bg);
}

/* Profile block */
.student-topbar-profile-wrap {
  position: relative;
  margin-left: 8px;
  padding-left: 16px;
  border-left: 1px solid var(--topbar-border);
}

.student-topbar-profile-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 10px 6px 6px;
  border-radius: 12px;
  background: transparent;
  border: 1px solid transparent;
  transition: background 0.25s ease, box-shadow 0.25s ease, transform 0.2s ease;
  cursor: pointer;
}
.student-topbar-profile-btn:hover {
  background: var(--brand-light);
  box-shadow: 0 2px 12px rgba(22, 101, 160, 0.12);
  transform: scale(1.02);
}
.student-topbar-profile-btn:hover .student-topbar-avatar {
  box-shadow: 0 3px 12px rgba(22, 101, 160, 0.4);
}
.student-topbar-profile-btn:active {
  transform: scale(0.98);
}
.student-topbar-profile-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px var(--brand-focus);
}

.student-topbar-avatar {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.875rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(135deg, var(--brand) 0%, #1a7bc4 100%);
  box-shadow: 0 2px 8px rgba(22, 101, 160, 0.3);
  flex-shrink: 0;
  transition: box-shadow 0.25s ease;
}

.student-topbar-name {
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--text);
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: none;
}
@media (min-width: 640px) {
  .student-topbar-name { display: inline; }
}

.student-topbar-chevron {
  font-size: 0.75rem;
  color: var(--text-muted);
  transition: transform 0.2s ease;
}
.student-topbar-chevron.is-open {
  transform: rotate(180deg);
}

/* Dropdown */
.student-topbar-dropdown {
  position: absolute;
  right: 0;
  top: calc(100% + 8px);
  width: 260px;
  padding: 6px;
  background: #fff;
  border: 1px solid var(--topbar-border);
  border-radius: 16px;
  box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.15), 0 4px 12px -4px rgba(0, 0, 0, 0.08);
  z-index: 50;
  overflow: hidden;
}

.student-topbar-dropdown-head {
  padding: 14px 16px;
  background: var(--surface);
  border-radius: 10px;
  margin-bottom: 4px;
}
.student-topbar-dropdown-label {
  font-size: 0.6875rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-muted);
  margin: 0;
}
.student-topbar-dropdown-name {
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--text);
  margin: 4px 0 0 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.student-topbar-dropdown-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-radius: 10px;
  font-size: 0.875rem;
  font-weight: 500;
  color: #dc2626;
  text-decoration: none;
  transition: background 0.2s ease, color 0.2s ease, transform 0.15s ease;
}
.student-topbar-dropdown-item:hover {
  background: #fef2f2;
  color: #b91c1c;
  transform: translateX(2px);
}
.student-topbar-dropdown-item:active {
  transform: translateX(0);
}
.student-topbar-dropdown-item i {
  font-size: 1.125rem;
}

/* Logout link */
.student-topbar-logout {
  border: none;
}
/* Help & Preferences dropdowns */
.student-topbar-small-dropdown {
  position: absolute;
  right: 0;
  top: calc(100% + 8px);
  width: 240px;
  padding: 6px;
  background: #ffffff;
  border-radius: 14px;
  border: 1px solid var(--topbar-border);
  box-shadow: 0 12px 30px rgba(15, 23, 42, 0.18);
  z-index: 40;
}
.student-topbar-small-section {
  padding: 10px 12px;
  border-radius: 10px;
}
.student-topbar-small-title {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text);
  margin: 0 0 4px 0;
}
.student-topbar-small-link {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.8rem;
  padding: 6px 8px;
  border-radius: 8px;
  color: var(--text-muted);
  text-decoration: none;
  transition: background 0.2s ease, color 0.2s ease, transform 0.15s ease;
}
.student-topbar-small-link i {
  font-size: 0.95rem;
}
.student-topbar-small-link:hover {
  background: var(--brand-light);
  color: var(--brand);
  transform: translateX(2px);
}
.student-topbar-toggle-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  font-size: 0.8rem;
  color: var(--text-muted);
}
.student-topbar-toggle-pill {
  width: 34px;
  height: 18px;
  border-radius: 999px;
  background: var(--surface);
  border: 1px solid var(--topbar-border);
  position: relative;
}
.student-topbar-toggle-pill::after {
  content: '';
  position: absolute;
  top: 1px;
  left: 1px;
  width: 14px;
  height: 14px;
  border-radius: 999px;
  background: #ffffff;
  box-shadow: 0 1px 4px rgba(15, 23, 42, 0.3);
}

/* Micro-animations for clock */
.student-topbar-time:hover .student-topbar-time-icon {
  transform: rotate(-6deg);
  transition: transform 0.2s ease;
}
.student-topbar-time-offset {
  margin-left: 4px;
  font-weight: 500;
  color: var(--brand);
  position: relative;
}
.student-topbar-time:hover .student-topbar-time-offset::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: -2px;
  height: 2px;
  border-radius: 999px;
  background: rgba(22, 101, 160, 0.45);
  animation: student-topbar-pulse 1s ease-out;
}
@keyframes student-topbar-pulse {
  0% { transform: scaleX(0); opacity: 0; }
  40% { transform: scaleX(1.02); opacity: 1; }
  100% { transform: scaleX(0.4); opacity: 0; }
}
</style>
<script>
  // Real-time Philippines (Asia/Manila) clock in topbar
  document.addEventListener('DOMContentLoaded', function () {
    var mainEl = document.getElementById('studentTopbarTimeMain');
    var offsetEl = document.getElementById('studentTopbarTimeOffset');
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
