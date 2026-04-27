<?php
/**
 * Unified app shell: sidebar, backdrop, toggle script, logout modal, opens <main>.
 *
 * Required before include:
 *   $appShellTheme          'admin' | 'student'
 *   $appShellNavConfig      array of [ 'label' => section, 'items' => [ href, label, icon, title?, active[], badge? ] ]
 *
 * Optional:
 *   $appShellCurrentScript  defaults to basename($_SERVER['PHP_SELF'])
 *   $appShellSidebarHeader  'brand' (admin/student) | 'profile' (legacy)
 *   $appShellProfileInitial, $appShellProfileName, $appShellProfileHref — when header is profile
 */
$appShellTheme = $appShellTheme ?? 'admin';
if ($appShellTheme === 'student' || $appShellTheme === 'professor') {
    // preserve new themes
} else {
    $appShellTheme = 'admin';
}
$appShellCurrentScript = $appShellCurrentScript ?? basename($_SERVER['PHP_SELF'] ?? '');
$appShellSidebarHeader = $appShellSidebarHeader ?? 'brand';
$appShellNavConfig = $appShellNavConfig ?? [];
/** @var string $appShellBrandHref Dashboard link in sidebar brand header (admin theme defaults to admin_dashboard) */
$appShellBrandHref = $appShellBrandHref ?? ($appShellTheme === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php');
$storageKey = 'ereview_app_shell_sidebar_' . $appShellTheme;

$appShellTzName = 'Asia/Manila';
$appShellTzObj = @timezone_open($appShellTzName);
$appShellSidebarNow = new DateTime('now', $appShellTzObj ?: null);
$appShellSidebarOffsetLabel = 'PHT · UTC+08:00';
$__sh = (int) $appShellSidebarNow->format('G');
$__si = (int) $appShellSidebarNow->format('i');
$__ss = (int) $appShellSidebarNow->format('s');
$__hour12 = $__sh % 12;
$appShellClockHourDeg = $__hour12 * 30 + $__si * 0.5 + $__ss * (0.5 / 60);
$appShellClockMinDeg = $__si * 6 + $__ss * 0.1;
$appShellClockSecDeg = $__ss * 6;
$appShellSidebarTimeDigital = $appShellSidebarNow->format('g:i:s A');
$appShellSidebarDateLine = $appShellSidebarNow->format('D, M j');
$appShellSidebarTimeTooltip = 'Program time: ' . $appShellSidebarNow->format('g:i A') . ' · ' . $appShellSidebarDateLine . ' · Philippines · ' . $appShellSidebarOffsetLabel;
?>
<aside id="app-sidebar"
       class="app-shell-sidebar app-shell-sidebar--<?php echo h($appShellTheme); ?> fixed top-0 left-0 h-screen z-[1000] flex flex-col overflow-x-hidden overflow-y-auto"
       data-app-theme="<?php echo h($appShellTheme); ?>"
       data-storage-key="<?php echo h($storageKey); ?>"
       aria-label="<?php echo $appShellTheme === 'admin' ? 'Staff' : ($appShellTheme === 'professor' ? 'Professor' : 'Student'); ?> navigation">
  <?php if ($appShellSidebarHeader === 'profile'): ?>
  <div class="app-shell-sidebar-header app-shell-sidebar-header--profile px-4 py-2.5 border-b border-white/15 flex items-center shrink-0 transition-all duration-300 app-shell-hide-when-collapsed-center">
    <a href="<?php echo h($appShellProfileHref ?? 'student_dashboard.php'); ?>" class="student-sidebar-brand flex items-center gap-3 min-w-0 overflow-hidden rounded-xl px-2 py-1.5 -mx-2 transition-all duration-300 w-full app-shell-brand-link">
      <span class="student-sidebar-avatar shrink-0 w-10 h-10 rounded-full flex items-center justify-center text-white font-bold text-lg bg-white/20 border-2 border-white/30 transition-transform duration-300 overflow-hidden" aria-hidden="true">
        <?php if (!empty($appShellProfileImage)): ?>
          <img src="<?php echo h($appShellProfileImage); ?>" alt="" class="w-full h-full object-cover" loading="lazy">
        <?php else: ?>
          <?php echo h($appShellProfileInitial ?? 'U'); ?>
        <?php endif; ?>
      </span>
      <span class="app-shell-sidebar-profile-name text-white font-bold text-lg tracking-tight truncate transition-all duration-300"><?php echo h($appShellProfileName ?? 'User'); ?></span>
    </a>
  </div>
  <?php else: ?>
  <?php if ($appShellTheme === 'professor'): ?>
    <div class="app-shell-sidebar-header app-shell-sidebar-header--brand p-5 bg-transparent border-b border-white/10 shrink-0 flex items-center">
      <a href="<?php echo h($appShellBrandHref); ?>" class="app-shell-sidebar-brand-link text-white text-xl font-bold m-0 flex items-center gap-2">
        <i class="bi bi-mortarboard-fill app-shell-sidebar-brand-icon text-green-200/90" aria-hidden="true"></i>
        <span class="app-shell-sidebar-brand-text text-white">LCRC eReview</span>
      </a>
    </div>
  <?php else: ?>
    <div class="app-shell-sidebar-header app-shell-sidebar-header--brand p-5 bg-white/10 border-b border-white/10 shrink-0 flex items-center">
      <a href="<?php echo h($appShellBrandHref); ?>" class="app-shell-sidebar-brand-link text-white text-xl font-bold m-0 flex items-center gap-2">
        <i class="bi bi-mortarboard-fill app-shell-sidebar-brand-icon" aria-hidden="true"></i>
        <span class="app-shell-sidebar-brand-text">LCRC eReview</span>
      </a>
      <?php if ($appShellTheme === 'student'): ?>
      <button type="button" id="app-sidebar-close-btn" class="app-shell-sidebar-close-btn" aria-label="Close menu">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>

  <nav class="app-shell-nav flex-1 overflow-y-auto min-h-0 <?php echo ($appShellTheme === 'admin' || $appShellTheme === 'professor') ? 'py-5' : 'py-3 px-2'; ?>" aria-label="Main navigation">
    <ul class="flex flex-col gap-0 <?php echo $appShellTheme === 'student' ? 'flex-1 min-h-0 gap-1' : ''; ?>">
      <?php foreach ($appShellNavConfig as $section): ?>
      <li class="admin-sidebar-section app-shell-nav-section">
        <span class="admin-sidebar-section-label app-shell-section-label" aria-hidden="true"><?php echo h($section['label'] ?? ''); ?></span>
      </li>
      <?php foreach ($section['items'] ?? [] as $item):
        $isActive = in_array($appShellCurrentScript, $item['active'] ?? [], true);

        if ($appShellTheme === 'admin') {
          $classes = 'app-shell-nav-link app-shell-nav-link--admin flex items-center gap-3 px-5 py-3 text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent hover:border-white transition';
          if ($isActive) {
            $classes .= ' bg-white/15 text-white border-l-white font-semibold';
          }
        } elseif ($appShellTheme === 'professor') {
          $classes = 'app-shell-nav-link app-shell-nav-link--professor flex items-center gap-3 px-5 py-3 text-white/75 hover:bg-white/10 hover:text-white hover:border-l-green-300 border-l-4 border-transparent transition';
          if ($isActive) {
            $classes .= ' bg-green-400/10 border-l-green-300 text-white font-semibold';
          }
        } else {
          $classes = 'app-shell-nav-link app-shell-nav-link--student student-nav-item flex items-center gap-3 px-3 py-3 rounded-xl text-white transition-all duration-300 ease-out';
          if ($isActive) {
            $classes .= ' student-nav-item--active';
          }
        }
      ?>
      <li>
        <?php if ($appShellTheme === 'student'): ?>
          <a href="<?php echo h($item['href']); ?>"
             class="<?php echo $classes; ?>"
             <?php if ($isActive): ?>style="background-color: rgba(255,255,255,0.22); box-shadow: 0 2px 10px rgba(0,0,0,0.1)"<?php endif; ?>>
            <i class="bi <?php echo h($item['icon']); ?> shrink-0 w-8 h-8 flex items-center justify-center student-nav-icon" style="font-size:1.25rem"></i>
            <span class="font-medium truncate whitespace-nowrap app-shell-nav-text transition-all duration-300"><?php echo h($item['label']); ?></span>
          </a>
        <?php else: ?>
          <a href="<?php echo h($item['href']); ?>"
             title="<?php echo h($item['title'] ?? ''); ?>"
             class="<?php echo $classes; ?>">
            <i class="bi <?php echo h($item['icon']); ?> text-lg w-6 text-center<?php echo ($appShellTheme === 'professor') ? ' text-green-200/90' : ''; ?>"></i>
            <span class="app-shell-nav-text"><?php echo h($item['label']); ?></span>
            <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
              <span class="admin-sidebar-badge" aria-label="<?php echo (int)$item['badge']; ?> pending"><?php echo (int)$item['badge']; ?></span>
            <?php endif; ?>
          </a>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </ul>
  </nav>

  <footer class="app-shell-sidebar-footer shrink-0 border-t border-white/10 px-3 py-3.5 mt-auto">
    <div
      class="app-shell-sidebar-time app-shell-sidebar-time--<?php echo h($appShellTheme); ?>"
      id="appShellSidebarTimeBlock"
      role="group"
      aria-label="Program time, Philippines, Manila"
      title="<?php echo htmlspecialchars($appShellSidebarTimeTooltip, ENT_QUOTES, 'UTF-8'); ?>"
    >
      <div class="app-shell-sidebar-time-icon" aria-hidden="true">
        <span class="app-shell-sidebar-time-ring"></span>
        <svg class="app-shell-sidebar-clock-svg" viewBox="0 0 36 36" width="36" height="36" focusable="false">
          <circle class="app-shell-sidebar-clock-face" cx="18" cy="18" r="15.25" />
          <circle class="app-shell-sidebar-clock-rim" cx="18" cy="18" r="15.25" fill="none" stroke-width="1" />
          <g class="app-shell-sidebar-clock-ticks" stroke-width="0.75" stroke-linecap="round">
            <?php for ($__t = 0; $__t < 12; $__t++): $__a = $__t * 30; ?>
            <line x1="18" y1="4" x2="18" y2="5.8" transform="rotate(<?php echo (int) $__a; ?> 18 18)" />
            <?php endfor; ?>
          </g>
          <g class="app-shell-sidebar-clock-hand app-shell-sidebar-clock-hand--hour" id="appSidebarClockHour" transform="rotate(<?php echo htmlspecialchars((string) round($appShellClockHourDeg, 4), ENT_QUOTES, 'UTF-8'); ?> 18 18)">
            <line x1="18" y1="18" x2="18" y2="11.5" stroke-width="2.25" stroke-linecap="round" />
          </g>
          <g class="app-shell-sidebar-clock-hand app-shell-sidebar-clock-hand--min" id="appSidebarClockMin" transform="rotate(<?php echo htmlspecialchars((string) round($appShellClockMinDeg, 4), ENT_QUOTES, 'UTF-8'); ?> 18 18)">
            <line x1="18" y1="18" x2="18" y2="8" stroke-width="1.6" stroke-linecap="round" />
          </g>
          <g class="app-shell-sidebar-clock-hand app-shell-sidebar-clock-hand--sec" id="appSidebarClockSec" transform="rotate(<?php echo htmlspecialchars((string) round($appShellClockSecDeg, 4), ENT_QUOTES, 'UTF-8'); ?> 18 18)">
            <line x1="18" y1="20" x2="18" y2="7" stroke-width="1" stroke-linecap="round" />
          </g>
          <circle class="app-shell-sidebar-clock-pivot" cx="18" cy="18" r="1.35" />
        </svg>
      </div>
      <div class="app-shell-sidebar-time-text app-shell-sidebar-time-details">
        <span class="app-shell-sidebar-time-label">Program time</span>
        <div class="app-shell-sidebar-time-hero" id="appSidebarLocalTimeHero">
          <time class="app-shell-sidebar-time-digital" id="appSidebarLocalTimeTime" datetime="<?php echo htmlspecialchars($appShellSidebarNow->format('c'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($appShellSidebarTimeDigital, ENT_QUOTES, 'UTF-8'); ?></time>
        </div>
        <div class="app-shell-sidebar-time-date" id="appSidebarLocalTimeDate"><?php echo htmlspecialchars($appShellSidebarDateLine, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="app-shell-sidebar-time-meta" id="appSidebarLocalTimeMeta">Philippines · <?php echo htmlspecialchars($appShellSidebarOffsetLabel, ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <span id="appSidebarLocalTimeLive" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></span>
    </div>
  </footer>

</aside>

<div id="sidebar-backdrop" class="app-shell-backdrop" aria-hidden="true"></div>

<script>
(function () {
  var aside = document.getElementById('app-sidebar');
  if (!aside) return;
  var theme = aside.getAttribute('data-app-theme') || 'admin';
  var STORAGE_KEY = aside.getAttribute('data-storage-key') || 'ereview_app_shell_sidebar_admin';
  var body = document.body;

  if (theme === 'student') {
    body.classList.add('app-shell--student');
  } else if (theme === 'professor') {
    body.classList.add('app-shell--professor');
  } else {
    body.classList.add('app-shell--admin');
  }

  function toggleBtn() {
    return document.getElementById('app-sidebar-toggle-btn');
  }

  function syncBrandHeader() {
    var collapsed = !body.classList.contains('sidebar-expanded');
    var brandTextEls = aside.querySelectorAll('.app-shell-sidebar-brand-text');
    for (var i = 0; i < brandTextEls.length; i++) {
      brandTextEls[i].style.display = collapsed ? 'none' : '';
    }
  }

  function syncToggleAria() {
    var btn = toggleBtn();
    if (btn) {
      btn.setAttribute('aria-expanded', body.classList.contains('sidebar-expanded') ? 'true' : 'false');
    }
    syncBrandHeader();
  }

  function openSidebar() {
    body.classList.add('sidebar-expanded');
    try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
    syncToggleAria();
  }

  function closeSidebar() {
    body.classList.remove('sidebar-expanded');
    try { localStorage.setItem(STORAGE_KEY, '0'); } catch (e) {}
    syncToggleAria();
  }

  function toggleSidebar() {
    if (body.classList.contains('sidebar-expanded')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  }

  window.toggleAppShellSidebar = toggleSidebar;
  window.toggleAdminSidebar = toggleSidebar;
  window.closeAppShellSidebar = closeSidebar;

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && body.classList.contains('sidebar-expanded')) {
      closeSidebar();
    }
  });

  var backdrop = document.getElementById('sidebar-backdrop');
  if (backdrop) {
    backdrop.addEventListener('click', function () {
      closeSidebar();
    });
  }

  var closeBtn = document.getElementById('app-sidebar-close-btn');
  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      closeSidebar();
    });
  }

  aside.addEventListener('click', function (ev) {
    var target = ev.target;
    if (!target || !target.closest) return;
    var navLink = target.closest('.app-shell-nav-link');
    if (!navLink) return;
    var isMobile = window.matchMedia ? window.matchMedia('(max-width: 1024px)').matches : false;
    if (isMobile) closeSidebar();
  });

  (function init() {
    var isDesktop = window.matchMedia ? window.matchMedia('(min-width: 1024px)').matches : true;
    var saved = null;
    try { saved = localStorage.getItem(STORAGE_KEY); } catch (e) {}
    if (saved === '1') openSidebar();
    else if (saved === '0') closeSidebar();
    else if (isDesktop) openSidebar();
    else closeSidebar();
  })();

  document.addEventListener('DOMContentLoaded', syncToggleAria);
})();

(function () {
  function initSidebarLocalTime() {
    var block = document.getElementById('appShellSidebarTimeBlock');
    var timeEl = document.getElementById('appSidebarLocalTimeTime');
    var dateEl = document.getElementById('appSidebarLocalTimeDate');
    var liveEl = document.getElementById('appSidebarLocalTimeLive');
    var heroEl = document.getElementById('appSidebarLocalTimeHero');
    var hourHand = document.getElementById('appSidebarClockHour');
    var minHand = document.getElementById('appSidebarClockMin');
    var secHand = document.getElementById('appSidebarClockSec');
    if (!block || !timeEl || !dateEl) return;

    var tz = 'Asia/Manila';
    var reduceMotion =
      window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion) {
      block.classList.add('app-shell-sidebar-time--reduced-motion');
    }

    var fmtTimeSec = new Intl.DateTimeFormat('en-PH', {
      hour: 'numeric',
      minute: '2-digit',
      second: '2-digit',
      hour12: true,
      timeZone: tz
    });
    var fmtTimeMin = new Intl.DateTimeFormat('en-PH', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
      timeZone: tz
    });
    var fmtDate = new Intl.DateTimeFormat('en-PH', {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      timeZone: tz
    });
    var fmtLive = new Intl.DateTimeFormat('en-PH', {
      weekday: 'long',
      month: 'long',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
      timeZone: tz
    });

    var lastMinuteKey = '';

    function getManilaHms(now) {
      var f = new Intl.DateTimeFormat('en-US', {
        timeZone: tz,
        hour: 'numeric',
        minute: 'numeric',
        second: 'numeric',
        hour12: false
      });
      var parts = f.formatToParts(now);
      var h = 0;
      var m = 0;
      var s = 0;
      for (var i = 0; i < parts.length; i++) {
        var p = parts[i];
        if (p.type === 'hour') h = parseInt(p.value, 10);
        if (p.type === 'minute') m = parseInt(p.value, 10);
        if (p.type === 'second') s = parseInt(p.value, 10);
      }
      return { h: h, m: m, s: s };
    }

    function setHands(p) {
      if (!hourHand || !minHand) return;
      var hour12 = p.h % 12;
      var hourDeg = hour12 * 30 + p.m * 0.5 + p.s * (0.5 / 60);
      var minDeg = p.m * 6 + p.s * 0.1;
      var secDeg = p.s * 6;
      hourHand.setAttribute('transform', 'rotate(' + hourDeg + ' 18 18)');
      minHand.setAttribute('transform', 'rotate(' + minDeg + ' 18 18)');
      if (secHand && !reduceMotion) {
        secHand.setAttribute('transform', 'rotate(' + secDeg + ' 18 18)');
      }
    }

    function pulseHero() {
      if (!heroEl || reduceMotion) return;
      heroEl.classList.remove('app-shell-sidebar-time-hero--tick');
      void heroEl.offsetWidth;
      heroEl.classList.add('app-shell-sidebar-time-hero--tick');
      window.setTimeout(function () {
        heroEl.classList.remove('app-shell-sidebar-time-hero--tick');
      }, 280);
    }

    function tick() {
      var now = new Date();
      var p = getManilaHms(now);

      if (reduceMotion) {
        timeEl.textContent = fmtTimeMin.format(now);
      } else {
        timeEl.textContent = fmtTimeSec.format(now).replace(/\u202f/g, ' ');
      }
      dateEl.textContent = fmtDate.format(now);
      timeEl.setAttribute('datetime', now.toISOString());
      setHands(p);

      var minuteKey = p.h + ':' + p.m + ':' + fmtDate.format(now);
      if (minuteKey !== lastMinuteKey) {
        if (lastMinuteKey !== '') pulseHero();
        lastMinuteKey = minuteKey;
        if (liveEl) {
          liveEl.textContent =
            'Program time ' + fmtLive.format(now) + ', Philippines, PHT, UTC plus eight.';
        }
      }

      var tip =
        'Program time: ' +
        fmtTimeMin.format(now) +
        ' · ' +
        fmtDate.format(now) +
        ' · Philippines · PHT · UTC+08:00';
      block.setAttribute('title', tip);
    }

    tick();
    window.setInterval(tick, reduceMotion ? 60000 : 1000);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebarLocalTime);
  } else {
    initSidebarLocalTime();
  }
})();
</script>
<?php
$ereviewLogoutModalVariant = $appShellTheme;
include __DIR__ . '/../logout_confirm_modal.php';
?>

<?php if ($appShellTheme === 'admin'): ?>
<main id="main" class="min-h-screen flex flex-col bg-[#f6f9ff] text-gray-700 font-sans">
<?php
$appShellTopbarTheme = 'admin';
include __DIR__ . '/app_shell_topbar.php';
?>
<div class="admin-content flex-1 pt-5 pb-5">
<?php elseif ($appShellTheme === 'professor'): ?>
<main id="main" class="min-h-screen flex flex-col bg-white text-gray-700 font-sans">
<?php
$appShellTopbarTheme = 'professor';
include __DIR__ . '/app_shell_topbar.php';
?>
<div class="admin-content flex-1 pt-5 pb-5">
<?php else: ?>
<main id="main" class="app-shell-main app-shell-main--student min-h-screen flex-1 bg-[#f6f9ff] text-gray-800 font-sans pt-0 px-5 pb-5">
<?php
$appShellTopbarTheme = 'student';
include __DIR__ . '/app_shell_topbar.php';
?>
<?php endif; ?>
