<?php
/**
 * Unified topbar — set $appShellTopbarTheme to 'admin' or 'student' before include.
 */
require_once __DIR__ . '/../format_display_name.php';

$t = ($appShellTopbarTheme ?? 'admin') === 'student'
  ? 'student'
  : (($appShellTopbarTheme ?? '') === 'professor' ? 'professor' : 'admin');
if ($t === 'admin' || $t === 'professor') {
  $displayNameFull = trim($_SESSION['full_name'] ?? 'Admin');
} else {
  $displayNameFull = trim($_SESSION['full_name'] ?? 'Student');
}

$displayNameTopbar = ereview_format_topbar_display_name($displayNameFull);

$ereviewHelpHref = 'help_center.php';
$ereviewPrefsHref = 'account_preferences.php';
$ereviewProfilePageHref = $ereviewProfilePageHref ?? ($t === 'student' ? 'student_profile.php' : 'staff_profile.php');
$ereviewProfileMenuTone = ($t === 'admin') ? 'dark' : 'light';
$ereviewStaffSubtitle = ($t === 'professor') ? 'Professor workspace' : 'System administrator';

/** Student / reviewee: access window (users.access_start / access_end) — countdown, progress, popover */
$studentAccessEndMs = null;
$studentAccessStartMs = null;
$studentAccessEndLabel = '';
$studentAccessStartLabel = '';
$studentAccessSecondaryLine = '';
$studentAccessRelativeInitial = '';
$studentAccessProgressPct = null;
$studentAccessExpired = false;
$studentAccessState = '';
if ($t === 'student' && !empty($_SESSION['user_id']) && isset($conn) && $conn) {
  $uid = (int)$_SESSION['user_id'];
  $stmt = @mysqli_prepare($conn, 'SELECT access_start, access_end FROM users WHERE user_id = ? LIMIT 1');
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if ($row && !empty($row['access_end'])) {
      $endTs = strtotime((string)$row['access_end']);
      if ($endTs !== false) {
        $studentAccessEndMs = $endTs * 1000;
        $studentAccessEndLabel = date('M j, Y · g:i A', $endTs);
        $nowTs = time();
        $studentAccessExpired = ($endTs < $nowTs);
        $secLeft = $endTs - $nowTs;
        $startTs = null;
        if (!empty($row['access_start'])) {
          $startTs = strtotime((string)$row['access_start']);
          if ($startTs === false) {
            $startTs = null;
          }
        }
        if ($startTs !== null) {
          $studentAccessStartMs = $startTs * 1000;
          $studentAccessStartLabel = date('M j, Y · g:i A', $startTs);
        }
        if ($startTs !== null && $endTs > $startTs) {
          $total = $endTs - $startTs;
          if ($studentAccessExpired) {
            $studentAccessProgressPct = 100.0;
          } else {
            $elapsed = max(0, min($total, $nowTs - $startTs));
            $studentAccessProgressPct = round(($elapsed / $total) * 100, 2);
          }
        }
        if ($studentAccessExpired) {
          $studentAccessState = 'expired';
          $studentAccessRelativeInitial = 'Access ended';
        } else {
          $sl = max(0, $secLeft);
          if ($sl >= 86400) {
            $d = (int) floor($sl / 86400);
            $studentAccessRelativeInitial = $d === 1 ? '1 day left' : $d . ' days left';
          } elseif ($sl >= 3600) {
            $h = (int) floor($sl / 3600);
            $studentAccessRelativeInitial = $h === 1 ? '1 hour left' : $h . ' hours left';
          } elseif ($sl >= 60) {
            $m = (int) floor($sl / 60);
            $studentAccessRelativeInitial = $m === 1 ? '1 minute left' : $m . ' minutes left';
          } else {
            $studentAccessRelativeInitial = 'Less than a minute left';
          }
          if ($secLeft <= 86400) {
            $studentAccessState = 'urgent';
          } elseif ($secLeft <= 86400 * 7) {
            $studentAccessState = 'soon';
          }
        }
        $studentAccessSecondaryLine = $studentAccessExpired
          ? 'Ended ' . $studentAccessEndLabel . ' · PHT'
          : 'Until ' . $studentAccessEndLabel . ' · PHT';
      }
    }
  }
}
?>
<?php if ($t === 'admin' || $t === 'professor'): ?>
<header class="admin-topbar admin-topbar-modern sticky top-0 z-[999] mt-0 mb-4" x-data="{
    userMenuOpen: false,
    searchFocused: false,
    toggleSidebar() { window.toggleAppShellSidebar && window.toggleAppShellSidebar(); },
    closeAll() { this.userMenuOpen = false; }
  }" @keydown.escape.window="closeAll()">
  <div class="admin-topbar-inner">
    <div class="admin-topbar-left">
      <?php if ($t === 'admin' || $t === 'professor'): ?>
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
      <?php endif; ?>
      <div class="admin-topbar-search-wrap" :class="{ 'is-focused': searchFocused }">
        <i class="bi bi-search admin-topbar-search-icon" aria-hidden="true"></i>
        <input type="search" placeholder="Search students, subjects..." aria-label="Search" class="admin-topbar-search"
               @focus="searchFocused = true" @blur="searchFocused = false">
      </div>
    </div>

    <div class="admin-topbar-right">
      <nav class="admin-topbar-actions" aria-label="Quick actions">
        <button type="button" aria-label="Notifications" class="admin-topbar-action admin-topbar-action--notif" title="Notifications" data-notification-toggle aria-controls="ereviewNotificationPanel" aria-expanded="false">
          <i class="bi bi-bell" aria-hidden="true"></i>
          <span class="ere-notif__badge is-empty" data-notification-badge aria-hidden="true"></span>
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
             class="admin-topbar-dropdown ereview-profile-menu ereview-profile-menu--<?php echo h($ereviewProfileMenuTone); ?>" role="menu">
          <div class="ereview-profile-menu__hero <?php echo $ereviewProfileMenuTone === 'dark' ? 'ereview-profile-menu__hero--dark' : 'ereview-profile-menu__hero--light'; ?>">
            <div class="ereview-profile-menu__avatar-ring" aria-hidden="true">
              <span class="ereview-profile-menu__avatar"><?php echo function_exists('mb_substr') ? strtoupper(mb_substr(trim($displayNameFull ?: 'A'), 0, 1)) : strtoupper(substr(trim($displayNameFull ?: 'A'), 0, 1)); ?></span>
            </div>
            <div class="ereview-profile-menu__hero-text">
              <p class="ereview-profile-menu__eyebrow">Signed in</p>
              <p class="ereview-profile-menu__title"><?php echo h($displayNameFull); ?></p>
              <p class="ereview-profile-menu__subtitle"><?php echo h($ereviewStaffSubtitle); ?></p>
            </div>
          </div>
          <div class="ereview-profile-menu__section" role="group" aria-label="Account menu">
            <span class="ereview-profile-menu__section-label">Menu</span>
            <a href="<?php echo h($ereviewProfilePageHref); ?>" class="ereview-profile-menu__link ereview-profile-menu__link--nav" role="menuitem" @click="userMenuOpen = false">
              <span class="ereview-profile-menu__link-icon"><i class="bi bi-person-circle" aria-hidden="true"></i></span>
              <span class="ereview-profile-menu__link-text">My Profile</span>
              <i class="bi bi-chevron-right ereview-profile-menu__chev" aria-hidden="true"></i>
            </a>
            <a href="<?php echo h($ereviewHelpHref); ?>" class="ereview-profile-menu__link ereview-profile-menu__link--nav" role="menuitem" @click="userMenuOpen = false">
              <span class="ereview-profile-menu__link-icon"><i class="bi bi-life-preserver" aria-hidden="true"></i></span>
              <span class="ereview-profile-menu__link-text">Help Center</span>
              <i class="bi bi-chevron-right ereview-profile-menu__chev" aria-hidden="true"></i>
            </a>
            <a href="<?php echo h($ereviewPrefsHref); ?>" class="ereview-profile-menu__link ereview-profile-menu__link--nav" role="menuitem" @click="userMenuOpen = false">
              <span class="ereview-profile-menu__link-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span>
              <span class="ereview-profile-menu__link-text">Preferences</span>
              <i class="bi bi-chevron-right ereview-profile-menu__chev" aria-hidden="true"></i>
            </a>
          </div>
          <div class="ereview-profile-menu__divider" role="presentation"></div>
          <a href="logout.php" class="ereview-profile-menu__link ereview-profile-menu__link--danger ereview-logout-trigger" role="menuitem" @click="userMenuOpen = false">
            <span class="ereview-profile-menu__link-icon"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></span>
            <span class="ereview-profile-menu__link-text">Log out</span>
          </a>
        </div>
      </div>
    </div>
  </div>
</header>
<?php
$notificationTheme = $t === 'professor' ? 'professor' : 'admin';
include __DIR__ . '/notification_component.php';
?>
<style>[x-cloak]{display:none!important}</style>

<?php else: ?>

<header id="studentTopbarRoot" class="student-topbar sticky top-0 z-[999] mt-0 mb-4 student-topbar-modern"
  :class="{ 'student-topbar--mobile-search-open': mobileSearchOpen }"
  x-data="{
    userMenuOpen: false,
    accessPop: false,
    mobileMenuOpen: false,
    mobileSearchOpen: false,
    searchFocused: false,
    toggleSidebar() { window.toggleAppShellSidebar && window.toggleAppShellSidebar(); },
    toggleMobileAction(action) {
      if (action === 'search') {
        this.mobileSearchOpen = !this.mobileSearchOpen;
        this.mobileMenuOpen = false;
        if (this.mobileSearchOpen) {
          this.$nextTick(() => {
            var inp = document.getElementById('studentTopbarSubjectSearch');
            if (inp && inp.focus) inp.focus();
          });
        }
        return;
      }
      this.mobileMenuOpen = false;
    },
    closeAll() {
      this.userMenuOpen = false;
      this.accessPop = false;
      this.mobileMenuOpen = false;
    }
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
      <a href="student_dashboard.php" class="student-topbar-mobile-brand" aria-label="Go to dashboard">
        <span class="student-topbar-mobile-brand__icon" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
        <span class="student-topbar-mobile-brand__text">LCRC eReview</span>
      </a>
      <div class="student-topbar-search-host relative flex-1 min-w-0" data-student-subject-search="api/student/subject_search.php">
        <div class="student-topbar-search-wrap" :class="{ 'is-focused': searchFocused }">
          <i class="bi bi-search student-topbar-search-icon" aria-hidden="true"></i>
          <input type="search" id="studentTopbarSubjectSearch" autocomplete="off" placeholder="Search courses, subjects..." aria-label="Search subjects" class="student-topbar-search" @focus="searchFocused = true" @blur="searchFocused = false">
        </div>
        <div id="studentTopbarSearchPanel" class="student-topbar-search-panel" hidden role="listbox" aria-label="Matching subjects"></div>
      </div>
    </div>

    <div class="student-topbar-right">
      <?php if ($studentAccessEndMs !== null): ?>
      <div class="student-topbar-access-wrap relative shrink-0">
        <button type="button"
          class="student-topbar-access<?php
            echo $studentAccessState === 'expired' ? ' student-topbar-access--expired' : '';
            echo $studentAccessState === 'urgent' ? ' student-topbar-access--urgent' : '';
            echo $studentAccessState === 'soon' ? ' student-topbar-access--soon' : '';
          ?>"
          id="studentTopbarAccess"
          data-access-end-ms="<?php echo (int)$studentAccessEndMs; ?>"
          data-access-live-hint="<?php echo htmlspecialchars($studentAccessSecondaryLine, ENT_QUOTES, 'UTF-8'); ?>"
          data-expired="<?php echo $studentAccessExpired ? '1' : '0'; ?>"
          title="<?php echo htmlspecialchars($studentAccessSecondaryLine, ENT_QUOTES, 'UTF-8'); ?>"
          @click.stop="accessPop = !accessPop; userMenuOpen = false"
          :aria-expanded="accessPop ? 'true' : 'false'"
          aria-controls="studentAccessDetailPanel"
          aria-describedby="studentAccessAriaLive"
        >
          <span class="student-topbar-access-icon" aria-hidden="true">
            <span class="student-topbar-access-hourglass">
              <i class="bi bi-hourglass-split"></i>
            </span>
          </span>
          <span class="student-topbar-access-line">
            <span class="student-topbar-access-intro">Enrollment active through</span>
            <span class="student-topbar-access-sep" aria-hidden="true">&nbsp;|&nbsp;</span>
            <span class="student-topbar-access-count" id="studentAccessRelativeLine"><?php echo htmlspecialchars($studentAccessRelativeInitial, ENT_QUOTES, 'UTF-8'); ?></span>
          </span>
          <span class="student-topbar-access-chevron" :class="{ 'is-open': accessPop }" aria-hidden="true"><i class="bi bi-chevron-down"></i></span>
        </button>
        <span id="studentAccessAriaLive" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></span>
        <div
          id="studentAccessDetailPanel"
          class="student-topbar-access-popover"
          x-show="accessPop"
          x-cloak
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 translate-y-1"
          x-transition:enter-end="opacity-100 translate-y-0"
          x-transition:leave="transition ease-in duration-150"
          x-transition:leave-start="opacity-100 translate-y-0"
          x-transition:leave-end="opacity-0 translate-y-1"
          @click.outside="accessPop = false"
          role="dialog"
          aria-modal="true"
          aria-labelledby="studentAccessPopoverTitle"
        >
          <div class="student-topbar-access-popover__inner">
            <h3 class="student-topbar-access-popover__title" id="studentAccessPopoverTitle">Enrollment details</h3>
            <dl class="student-topbar-access-popover__dl">
              <?php if ($studentAccessStartLabel !== ''): ?>
              <div class="student-topbar-access-popover__row">
                <dt>Access began</dt>
                <dd><?php echo htmlspecialchars($studentAccessStartLabel, ENT_QUOTES, 'UTF-8'); ?> · PHT</dd>
              </div>
              <?php else: ?>
              <div class="student-topbar-access-popover__row">
                <dt>Access began</dt>
                <dd class="student-topbar-access-popover__muted">Not set on your account</dd>
              </div>
              <?php endif; ?>
              <div class="student-topbar-access-popover__row">
                <dt>Access ends</dt>
                <dd><?php echo htmlspecialchars($studentAccessEndLabel, ENT_QUOTES, 'UTF-8'); ?> · PHT (UTC+08:00)</dd>
              </div>
              <div class="student-topbar-access-popover__row student-topbar-access-popover__row--block">
                <dt>Timezone</dt>
                <dd>Philippines (PHT, UTC+08:00). Countdown uses your current device time compared to the end date stored for your account.</dd>
              </div>
              <div class="student-topbar-access-popover__row student-topbar-access-popover__row--block">
                <dt>When access ends</dt>
                <dd>You won’t be able to open subjects, lessons, quizzes, or preboards until your enrollment is renewed. Contact your administrator if you need an extension.</dd>
              </div>
            </dl>
            <div class="student-topbar-access-popover__actions">
              <a href="student_access_ics.php" class="student-topbar-access-popover__link student-topbar-access-popover__link--ics" download="lcrc-ereview-access-end.ics" @click.stop>
                <i class="bi bi-calendar-plus" aria-hidden="true"></i>
                Add to calendar (.ics)
              </a>
              <a href="<?php echo h($ereviewHelpHref); ?>" class="student-topbar-access-popover__link" @click="accessPop = false">Help Center</a>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <nav class="student-topbar-actions" aria-label="Quick actions">
        <button type="button" id="studentTopbarMoreBtn" aria-label="More actions" class="student-topbar-action student-topbar-action--more" title="More actions" aria-expanded="false" aria-controls="studentTopbarMobileActions">
          <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
        </button>
        <button type="button" x-ref="mainNotifBtn" aria-label="Notifications" class="student-topbar-action student-topbar-action--notif student-topbar-action--notif-main has-unread" title="Notifications" data-notification-toggle aria-controls="ereviewNotificationPanel" aria-expanded="false">
          <i class="bi bi-bell" aria-hidden="true"></i>
          <span class="student-topbar-badge ere-notif__badge is-empty" data-notification-badge aria-hidden="true"></span>
        </button>
      </nav>
      <div class="student-topbar-profile-wrap">
        <button type="button" x-ref="mainProfileBtn" @click="userMenuOpen = !userMenuOpen; accessPop = false; mobileMenuOpen = false" aria-haspopup="true" :aria-expanded="userMenuOpen" class="student-topbar-profile-btn">
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
        <div x-show="userMenuOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-0" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" @click.outside="userMenuOpen = false" class="student-topbar-dropdown ereview-profile-menu ereview-profile-menu--light" role="menu">
          <div class="ereview-profile-menu__hero ereview-profile-menu__hero--light">
            <div class="ereview-profile-menu__avatar-ring ereview-profile-menu__avatar-ring--student" aria-hidden="true">
              <span class="student-topbar-avatar ereview-profile-menu__hero-avatar overflow-hidden">
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
            </div>
            <div class="ereview-profile-menu__hero-text">
              <p class="ereview-profile-menu__eyebrow">Signed in as</p>
              <p class="ereview-profile-menu__title"><?php echo h($displayNameFull); ?></p>
              <p class="ereview-profile-menu__subtitle">Student account · LCRC eReview</p>
            </div>
          </div>
          <div class="ereview-profile-menu__section" role="group" aria-label="Account menu">
            <span class="ereview-profile-menu__section-label">Shortcuts</span>
            <a href="<?php echo h($ereviewProfilePageHref); ?>" class="ereview-profile-menu__link ereview-profile-menu__link--nav" role="menuitem" @click="userMenuOpen = false">
              <span class="ereview-profile-menu__link-icon"><i class="bi bi-person-circle" aria-hidden="true"></i></span>
              <span class="ereview-profile-menu__link-text">My Profile</span>
              <i class="bi bi-chevron-right ereview-profile-menu__chev" aria-hidden="true"></i>
            </a>
            <a href="<?php echo h($ereviewHelpHref); ?>" class="ereview-profile-menu__link ereview-profile-menu__link--nav" role="menuitem" @click="userMenuOpen = false">
              <span class="ereview-profile-menu__link-icon"><i class="bi bi-life-preserver" aria-hidden="true"></i></span>
              <span class="ereview-profile-menu__link-text">Help Center</span>
              <i class="bi bi-chevron-right ereview-profile-menu__chev" aria-hidden="true"></i>
            </a>
            <a href="<?php echo h($ereviewPrefsHref); ?>" class="ereview-profile-menu__link ereview-profile-menu__link--nav" role="menuitem" @click="userMenuOpen = false">
              <span class="ereview-profile-menu__link-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span>
              <span class="ereview-profile-menu__link-text">Preferences</span>
              <i class="bi bi-chevron-right ereview-profile-menu__chev" aria-hidden="true"></i>
            </a>
          </div>
          <div class="ereview-profile-menu__divider" role="presentation"></div>
          <a href="logout.php" class="ereview-profile-menu__link ereview-profile-menu__link--danger ereview-logout-trigger" role="menuitem" @click="userMenuOpen = false">
            <span class="ereview-profile-menu__link-icon"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></span>
            <span class="ereview-profile-menu__link-text">Log out</span>
          </a>
        </div>
      </div>
      <div id="studentTopbarMobileActions" class="student-topbar-mobile-actions-menu" role="menu" aria-label="Mobile quick actions">
        <button type="button" class="student-topbar-mobile-actions-item" role="menuitem" data-mobile-action="search">
          <span class="student-topbar-mobile-actions-item__icon"><i class="bi bi-search" aria-hidden="true"></i></span>
          <span class="student-topbar-mobile-actions-item__text">Search</span>
        </button>
        <button type="button" class="student-topbar-mobile-actions-item" role="menuitem" data-mobile-action="notifications">
          <span class="student-topbar-mobile-actions-item__icon"><i class="bi bi-bell" aria-hidden="true"></i></span>
          <span class="student-topbar-mobile-actions-item__text">Notifications</span>
        </button>
        <button type="button" class="student-topbar-mobile-actions-item student-topbar-mobile-actions-item--profile" role="menuitem" data-mobile-action="profile">
          <span class="student-topbar-mobile-actions-item__avatar">
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
          <span class="student-topbar-mobile-actions-item__text">Profile</span>
        </button>
      </div>
    </div>
  </div>
</header>
<?php
$notificationTheme = 'student';
include __DIR__ . '/notification_component.php';
?>
<style>[x-cloak]{display:none!important}</style>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var accRoot = document.getElementById('studentTopbarAccess');
    var relEl = document.getElementById('studentAccessRelativeLine');
    var liveEl = document.getElementById('studentAccessAriaLive');
    if (!accRoot || !relEl) {
      return;
    }

    var endMs = parseInt(accRoot.getAttribute('data-access-end-ms'), 10);
    if (isNaN(endMs)) {
      return;
    }

    var liveHint = accRoot.getAttribute('data-access-live-hint') || '';

    function formatRelativeHuman(ms) {
      if (ms <= 0) return 'Access ended';
      var t = Math.floor(ms / 1000);
      var d = Math.floor(t / 86400);
      t %= 86400;
      var h = Math.floor(t / 3600);
      t %= 3600;
      var m = Math.floor(t / 60);
      if (d >= 1) return d === 1 ? '1 day left' : d + ' days left';
      if (h >= 1) return h === 1 ? '1 hour left' : h + ' hours left';
      if (m >= 1) return m === 1 ? '1 minute left' : m + ' minutes left';
      return 'Less than a minute left';
    }

    function pulseUrgency(leftMs) {
      accRoot.classList.remove('student-topbar-access--urgent', 'student-topbar-access--soon');
      if (leftMs <= 0) {
        accRoot.classList.add('student-topbar-access--expired');
        accRoot.setAttribute('data-expired', '1');
        return;
      }
      var sec = leftMs / 1000;
      if (sec <= 86400) {
        accRoot.classList.add('student-topbar-access--urgent');
      } else if (sec <= 86400 * 7) {
        accRoot.classList.add('student-topbar-access--soon');
      }
    }

    function liveAnnounce(human) {
      if (!liveEl) return;
      if (accRoot.getAttribute('data-expired') === '1') {
        liveEl.textContent = 'Enrollment access has ended.' + (liveHint ? ' ' + liveHint : '');
        return;
      }
      liveEl.textContent =
        'Enrollment active through, ' + human + (liveHint ? '. ' + liveHint : '') + '.';
    }

    function tick() {
      if (accRoot.getAttribute('data-expired') === '1') {
        relEl.textContent = 'Access ended';
        liveAnnounce('');
        return;
      }
      var left = endMs - Date.now();
      if (left <= 0) {
        pulseUrgency(0);
        relEl.textContent = 'Access ended';
        liveAnnounce('');
        return;
      }
      pulseUrgency(left);
      var human = formatRelativeHuman(left);
      relEl.textContent = human;
      liveAnnounce(human);
    }

    function showAccessMilestoneToast(message) {
      var el = document.createElement('div');
      el.className = 'ereview-access-milestone-toast';
      el.setAttribute('role', 'status');
      el.innerHTML = '<span class="ereview-access-milestone-toast__inner"><span class="ereview-access-milestone-toast__text"></span><button type="button" class="ereview-access-milestone-toast__close" aria-label="Dismiss"><i class="bi bi-x-lg" aria-hidden="true"></i></button></span>';
      el.querySelector('.ereview-access-milestone-toast__text').textContent = message;
      document.body.appendChild(el);
      var close = function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      };
      el.querySelector('.ereview-access-milestone-toast__close').addEventListener('click', close);
      setTimeout(close, 9000);
    }

    function maybeAccessMilestones() {
      if (accRoot.getAttribute('data-expired') === '1') return;
      var leftSec = Math.max(0, (endMs - Date.now()) / 1000);
      if (leftSec <= 0) return;
      try {
        var ss = window.sessionStorage;
        if (leftSec <= 86400) {
          if (!ss.getItem('ereview_access_m_24h')) {
            ss.setItem('ereview_access_m_24h', '1');
            showAccessMilestoneToast('Your enrollment access ends in less than 24 hours.');
          }
          return;
        }
        if (leftSec <= 3 * 86400) {
          if (!ss.getItem('ereview_access_m_3d')) {
            ss.setItem('ereview_access_m_3d', '1');
            showAccessMilestoneToast('Your enrollment access ends in 3 days or less.');
          }
          return;
        }
        if (leftSec <= 7 * 86400) {
          if (!ss.getItem('ereview_access_m_7d')) {
            ss.setItem('ereview_access_m_7d', '1');
            showAccessMilestoneToast('Your enrollment access ends in one week or less.');
          }
        }
      } catch (e) {}
    }

    if (accRoot.getAttribute('data-expired') === '1') {
      relEl.textContent = 'Access ended';
      liveAnnounce('');
    } else {
      tick();
      setInterval(tick, 1000);
      maybeAccessMilestones();
    }
  });
</script>
<script>
(function () {
  var host = document.querySelector('[data-student-subject-search]');
  var input = document.getElementById('studentTopbarSubjectSearch');
  var panel = document.getElementById('studentTopbarSearchPanel');
  if (!host || !input || !panel) return;
  var endpoint = host.getAttribute('data-student-subject-search') || 'api/student/subject_search.php';
  var t = null;
  var lastQ = '';

  function hide() {
    panel.hidden = true;
    panel.innerHTML = '';
  }

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
  }

  function render(items) {
    if (!items || !items.length) {
      panel.innerHTML = '<div class="student-topbar-search-panel__empty">No matching subjects.</div>';
      panel.hidden = false;
      return;
    }
    var html = items.map(function (it) {
      var href = 'student_subject.php?subject_id=' + encodeURIComponent(String(it.id));
      return '<a role="option" class="student-topbar-search-panel__item" href="' + href + '">' +
        '<span class="student-topbar-search-panel__icon"><i class="bi bi-journal-bookmark" aria-hidden="true"></i></span>' +
        '<span class="student-topbar-search-panel__text">' + esc(it.name) + '</span>' +
        '<i class="bi bi-chevron-right student-topbar-search-panel__chev" aria-hidden="true"></i></a>';
    }).join('');
    panel.innerHTML = html;
    panel.hidden = false;
  }

  function fetchQ(q) {
    if (!q || q.length < 1) {
      hide();
      return;
    }
    fetch(endpoint + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          hide();
          return;
        }
        render(data.items || []);
      })
      .catch(function () { hide(); });
  }

  input.addEventListener('input', function () {
    var q = String(input.value || '').trim();
    clearTimeout(t);
    if (q.length < 1) {
      hide();
      return;
    }
    t = setTimeout(function () {
      if (q === lastQ) return;
      lastQ = q;
      fetchQ(q);
    }, 220);
  });

  panel.addEventListener('mousedown', function (e) {
    if (e.target.closest('a')) e.preventDefault();
  });

  input.addEventListener('blur', function () {
    setTimeout(function () {
      if (!panel.matches(':hover') && document.activeElement !== input) hide();
    }, 160);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') hide();
  });
})();
</script>
<script>
(function () {
  function initTopbarMobileActions() {
    var topbar = document.getElementById('studentTopbarRoot');
    var moreBtn = document.getElementById('studentTopbarMoreBtn');
    var menu = document.getElementById('studentTopbarMobileActions');
    if (!topbar || !moreBtn || !menu) return;

    var notifBtn = document.querySelector('.student-topbar-action--notif-main');
    var profileBtn = document.querySelector('.student-topbar-profile-btn');

    function setMenuOpen(open) {
      topbar.classList.toggle('student-topbar--mobile-actions-open', !!open);
      moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) menu.classList.add('student-topbar-mobile-actions-menu--open');
      else menu.classList.remove('student-topbar-mobile-actions-menu--open');
    }

    function closeMenu() {
      setMenuOpen(false);
    }

    function toggleSearch() {
      topbar.classList.toggle('student-topbar--mobile-search-open');
      if (topbar.classList.contains('student-topbar--mobile-search-open')) {
        setTimeout(function () {
          var input = document.getElementById('studentTopbarSubjectSearch');
          if (input && input.focus) input.focus();
        }, 140);
      }
    }

    moreBtn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      setMenuOpen(!topbar.classList.contains('student-topbar--mobile-actions-open'));
    });

    menu.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-mobile-action]');
      if (!btn) return;
      var action = btn.getAttribute('data-mobile-action') || '';
      if (action === 'search') {
        toggleSearch();
      } else if (action === 'notifications') {
        if (notifBtn) notifBtn.click();
      } else if (action === 'profile') {
        if (profileBtn) profileBtn.click();
      }
      closeMenu();
    });

    document.addEventListener('click', function (ev) {
      if (!topbar.classList.contains('student-topbar--mobile-actions-open')) return;
      if (menu.contains(ev.target) || moreBtn.contains(ev.target)) return;
      closeMenu();
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') closeMenu();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTopbarMobileActions);
  } else {
    initTopbarMobileActions();
  }
})();
</script>
<?php endif; ?>

<?php
if (empty($GLOBALS['ereview_profile_edit_modal_included'])) {
    $GLOBALS['ereview_profile_edit_modal_included'] = true;
    require __DIR__ . '/profile_edit_modal.php';
}
?>
