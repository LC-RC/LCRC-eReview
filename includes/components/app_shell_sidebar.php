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

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && body.classList.contains('sidebar-expanded')) {
      closeSidebar();
    }
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
