<?php
/**
 * Admin Sidebar – Reusable component across all admin pages.
 * Maintains nav links, active state, spacing, and icons. Edit $adminNavConfig to add/remove items.
 */
$adminPendingCount = 0;
if (!empty($conn)) {
    $pr = @mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM users WHERE role='student' AND status='pending'");
    if ($pr && $prRow = mysqli_fetch_assoc($pr)) {
        $adminPendingCount = (int)($prRow['cnt'] ?? 0);
        mysqli_free_result($pr);
    }
}

$adminCurrentScript = basename($_SERVER['PHP_SELF']);

$adminNavConfig = [
    [
        'label' => 'Manage',
        'items' => [
            ['label' => 'Dashboard', 'href' => 'admin_dashboard.php', 'icon' => 'bi-speedometer2', 'title' => 'Overview and key numbers', 'active' => ['admin_dashboard.php']],
            ['label' => 'Students', 'href' => 'admin_students.php', 'icon' => 'bi-people', 'title' => 'Enrollments, approvals, and access', 'active' => ['admin_students.php', 'admin_student_view.php'], 'badge' => $adminPendingCount],
        ],
    ],
    [
        'label' => 'Content',
        'items' => [
            ['label' => 'Content', 'href' => 'admin_subjects.php', 'icon' => 'bi-book', 'title' => 'Subjects, lessons, materials, quizzes', 'active' => ['admin_subjects.php', 'admin_lessons.php', 'admin_videos.php', 'admin_handouts.php', 'admin_materials.php', 'admin_quizzes.php', 'admin_quiz_questions.php']],
            ['label' => 'Preboards', 'href' => 'admin_preboards_subjects.php', 'icon' => 'bi-clipboard-check', 'title' => 'Preboards: subjects, sets, questions', 'active' => ['admin_preboards_subjects.php', 'admin_preboards_sets.php', 'admin_preboards_questions.php']],
        ],
    ],
];
?>
<!-- Sidebar -->
<aside id="sidebar" class="fixed top-0 left-0 w-[260px] h-screen bg-[#012970] z-[1000] flex flex-col transition-transform duration-200 ease-out" x-data="{ mobileOpen: false }">
    <div class="p-5 bg-white/10 border-b border-white/10 shrink-0 flex items-center justify-between gap-2">
        <h3 class="admin-sidebar-brand text-white text-xl font-bold m-0 flex items-center gap-2">
            <i class="bi bi-mortarboard-fill"></i> <span>LCRC eReview</span>
        </h3>
        <button type="button"
                class="p-1.5 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition focus:outline-none focus:ring-2 focus:ring-white/30"
                aria-label="Toggle sidebar"
                onclick="window.toggleAdminSidebar && window.toggleAdminSidebar()">
            <i class="bi bi-list text-xl"></i>
        </button>
    </div>
    <nav class="py-5 flex-1 overflow-y-auto" aria-label="Admin navigation">
        <ul class="flex flex-col gap-0">
            <?php foreach ($adminNavConfig as $section): ?>
            <li class="admin-sidebar-section">
                <span class="admin-sidebar-section-label" aria-hidden="true"><?php echo h($section['label']); ?></span>
            </li>
            <?php foreach ($section['items'] as $item):
                $isActive = in_array($adminCurrentScript, $item['active'] ?? [], true);
                $classes = 'flex items-center gap-3 px-5 py-3 text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent hover:border-white transition';
                if ($isActive) $classes .= ' bg-white/15 text-white border-l-white font-semibold';
            ?>
            <li>
                <a href="<?php echo h($item['href']); ?>" title="<?php echo h($item['title'] ?? ''); ?>" class="<?php echo $classes; ?>">
                    <i class="bi <?php echo h($item['icon']); ?> text-lg w-6 text-center"></i>
                    <span><?php echo h($item['label']); ?></span>
                    <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
                        <span class="admin-sidebar-badge" aria-label="<?php echo (int)$item['badge']; ?> pending"><?php echo (int)$item['badge']; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
    </nav>
    <div class="admin-sidebar-footer shrink-0 border-t border-white/10">
        <a href="logout.php" title="Sign out" class="flex items-center gap-3 px-5 py-3 text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent hover:border-white transition w-full">
            <i class="bi bi-box-arrow-right text-lg w-6 text-center"></i>
            <span>Logout</span>
        </a>
    </div>
<?php /* Sidebar markup end */ ?>
</aside>
<div id="sidebar-backdrop"></div>
<script>
(function () {
  var body = document.body;
  var sidebar = document.getElementById('sidebar');
  var backdrop = document.getElementById('sidebar-backdrop');

  function openSidebar() {
    body.classList.add('sidebar-expanded');
  }

  function closeSidebar() {
    body.classList.remove('sidebar-expanded');
  }

  window.toggleAdminSidebar = function () {
    if (body.classList.contains('sidebar-expanded')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  };

  if (backdrop) {
    backdrop.addEventListener('click', closeSidebar);
  }
})();
</script>

<!-- Main: topbar + content wrapper -->
<main id="main" class="min-h-screen flex flex-col bg-[#f6f9ff] text-gray-700 font-sans">
<?php include __DIR__ . '/includes/admin_topbar.php'; ?>
<div class="admin-content flex-1 pt-5 pb-5">
