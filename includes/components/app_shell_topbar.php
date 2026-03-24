<?php
/**
 * Unified topbar — set $appShellTopbarTheme to 'admin' or 'student' before include.
 */
require_once __DIR__ . '/../format_display_name.php';

$t = ($appShellTopbarTheme ?? 'admin') === 'student' ? 'student' : 'admin';
$tzName = 'Asia/Manila';
$tzObj  = @timezone_open($tzName);
$now    = new DateTime('now', $tzObj ?: null);
$offsetLabel = 'PHT · UTC+08:00';

if ($t === 'admin') {
  $displayNameFull = trim($_SESSION['full_name'] ?? 'Admin');
  $timeIdMain = 'adminTopbarTimeMain';
  $timeIdOffset = 'adminTopbarTimeOffset';
} else {
  $displayNameFull = trim($_SESSION['full_name'] ?? 'Student');
  $timeIdMain = 'studentTopbarTimeMain';
  $timeIdOffset = 'studentTopbarTimeOffset';
}

$displayNameTopbar = ereview_format_topbar_display_name($displayNameFull);
?>
<?php if ($t === 'admin'): ?>
<header class="admin-topbar admin-topbar-modern sticky top-0 z-[999] mt-0 mb-4" x-data="{
    userMenuOpen: false,
    searchFocused: false,
    toggleSidebar() { window.toggleAppShellSidebar && window.toggleAppShellSidebar(); },
    closeAll() { this.userMenuOpen = false; }
  }" @keydown.escape.window="closeAll()">
  <div class="admin-topbar-inner">
    <div class="admin-topbar-left">
      <button type="button" id="app-sidebar-toggle-btn" aria-label="Toggle sidebar" aria-expanded="false" aria-controls="app-sidebar" class="admin-topbar-menu-btn app-shell-menu-btn" @click="toggleSidebar()">
        <span class="burger-icon" aria-hidden="true">
          <span class="burger-line burger-line--1"></span>
          <span class="burger-line burger-line--2"></span>
          <span class="burger-line burger-line--3"></span>
        </span>
        <span class="app-shell-arrow-icon" aria-hidden="true">
          <i class="bi bi-arrow-right"></i>
        </span>
      </button>
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
            <span id="<?php echo h($timeIdMain); ?>"><?php echo htmlspecialchars($now->format('M j · g:i A')); ?></span>
            <span class="admin-topbar-time-offset" id="<?php echo h($timeIdOffset); ?>"><?php echo htmlspecialchars($offsetLabel); ?></span>
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
          <span class="admin-topbar-avatar" aria-hidden="true"><?php echo function_exists('mb_substr') ? strtoupper(mb_substr(trim($displayNameFull ?: 'A'), 0, 1)) : strtoupper(substr(trim($displayNameFull ?: 'A'), 0, 1)); ?></span>
          <span class="admin-topbar-name" title="<?php echo h($displayNameFull); ?>"><?php echo h($displayNameTopbar); ?></span>
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
            <p class="admin-topbar-dropdown-name"><?php echo h($displayNameFull); ?></p>
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
  document.addEventListener('DOMContentLoaded', function () {
    var mainEl = document.getElementById('adminTopbarTimeMain');
    var offsetEl = document.getElementById('adminTopbarTimeOffset');
    if (!mainEl || !offsetEl) return;
    var formatter = new Intl.DateTimeFormat('en-US', {
      month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'Asia/Manila'
    });
    var offsetLabel = 'PHT · UTC+08:00';
    function updateTime() {
      var now = new Date();
      mainEl.textContent = formatter.format(now).replace(',', ' ·');
      offsetEl.textContent = offsetLabel;
    }
    updateTime();
    setInterval(updateTime, 60 * 1000);
  });
</script>

<?php else: ?>

<header class="student-topbar sticky top-0 z-[999] mt-0 mb-4 student-topbar-modern" x-data="{
    userMenuOpen: false,
    helpMenuOpen: false,
    prefsMenuOpen: false,
    searchFocused: false,
    toggleSidebar() { window.toggleAppShellSidebar && window.toggleAppShellSidebar(); },
    closeAll() { this.userMenuOpen = this.helpMenuOpen = this.prefsMenuOpen = false; }
  }" @keydown.escape.window="closeAll()">
  <div class="student-topbar-inner">
    <div class="student-topbar-left">
      <button type="button" id="app-sidebar-toggle-btn" aria-label="Toggle sidebar" aria-expanded="false" aria-controls="app-sidebar" class="student-topbar-menu-btn app-shell-menu-btn" @click="toggleSidebar()">
        <span class="burger-icon" aria-hidden="true">
          <span class="burger-line burger-line--1"></span>
          <span class="burger-line burger-line--2"></span>
          <span class="burger-line burger-line--3"></span>
        </span>
        <span class="app-shell-arrow-icon" aria-hidden="true">
          <i class="bi bi-arrow-right"></i>
        </span>
      </button>
      <div class="student-topbar-search-wrap" :class="{ 'is-focused': searchFocused }">
        <i class="bi bi-search student-topbar-search-icon" aria-hidden="true"></i>
        <input type="search" placeholder="Search courses, subjects..." aria-label="Search" class="student-topbar-search" @focus="searchFocused = true" @blur="searchFocused = false">
      </div>
    </div>

    <div class="student-topbar-right">
      <div class="student-topbar-time" aria-label="Current timezone and local time">
        <span class="student-topbar-time-icon" aria-hidden="true"><i class="bi bi-clock"></i></span>
        <div class="student-topbar-time-text">
          <span class="student-topbar-time-label">Local time</span>
          <span class="student-topbar-time-value">
            <span id="<?php echo h($timeIdMain); ?>"><?php echo htmlspecialchars($now->format('M j · g:i A')); ?></span>
            <span class="student-topbar-time-offset" id="<?php echo h($timeIdOffset); ?>"><?php echo htmlspecialchars($offsetLabel); ?></span>
          </span>
        </div>
      </div>
      <nav class="student-topbar-actions" aria-label="Quick actions">
        <button type="button" aria-label="Help center" class="student-topbar-action" title="Help center"
          @click.stop="helpMenuOpen = !helpMenuOpen; prefsMenuOpen = false; userMenuOpen = false;">
          <i class="bi bi-question-circle" aria-hidden="true"></i>
        </button>
        <button type="button" aria-label="Preferences" class="student-topbar-action student-topbar-action--settings" title="Preferences"
          @click.stop="prefsMenuOpen = !prefsMenuOpen; helpMenuOpen = false; userMenuOpen = false;">
          <i class="bi bi-gear" aria-hidden="true"></i>
        </button>
        <button type="button" aria-label="Notifications" class="student-topbar-action student-topbar-action--notif has-unread" title="Notifications">
          <i class="bi bi-bell" aria-hidden="true"></i>
          <span class="student-topbar-badge" aria-hidden="true"></span>
        </button>
      </nav>
      <div class="student-topbar-profile-wrap">
        <button type="button" @click="userMenuOpen = !userMenuOpen" aria-haspopup="true" :aria-expanded="userMenuOpen" class="student-topbar-profile-btn">
          <span class="student-topbar-avatar overflow-hidden" aria-hidden="true">
            <?php if (!empty($appShellTopbarAvatarImage)): ?>
              <img src="<?php echo h($appShellTopbarAvatarImage); ?>" alt="" class="w-full h-full object-cover" loading="lazy">
            <?php else: ?>
              <?php
                $__dn = trim($displayNameFull ?: 'U');
                $__initial = function_exists('mb_substr') ? strtoupper(mb_substr($__dn, 0, 1)) : strtoupper(substr($__dn, 0, 1));
                echo h($appShellTopbarAvatarInitial ?? $__initial);
              ?>
            <?php endif; ?>
          </span>
          <span class="student-topbar-name" title="<?php echo h($displayNameFull); ?>"><?php echo h($displayNameTopbar); ?></span>
          <i class="bi bi-chevron-down student-topbar-chevron" aria-hidden="true" :class="{ 'is-open': userMenuOpen }"></i>
        </button>
        <div x-show="userMenuOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-0" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" @click.outside="userMenuOpen = false" class="student-topbar-dropdown" role="menu">
          <div class="student-topbar-dropdown-head">
            <p class="student-topbar-dropdown-label">Account</p>
            <p class="student-topbar-dropdown-name"><?php echo h($displayNameFull); ?></p>
          </div>
          <a href="logout.php" class="ereview-logout-trigger student-topbar-dropdown-item student-topbar-logout" role="menuitem" @click="userMenuOpen = false">
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
  document.addEventListener('DOMContentLoaded', function () {
    var mainEl = document.getElementById('studentTopbarTimeMain');
    var offsetEl = document.getElementById('studentTopbarTimeOffset');
    if (!mainEl || !offsetEl) return;
    var formatter = new Intl.DateTimeFormat('en-US', {
      month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'Asia/Manila'
    });
    var offsetLabel = 'PHT · UTC+08:00';
    function updateTime() {
      var now = new Date();
      mainEl.textContent = formatter.format(now).replace(',', ' ·');
      offsetEl.textContent = offsetLabel;
    }
    updateTime();
    setInterval(updateTime, 60 * 1000);
  });
</script>
<?php endif; ?>
