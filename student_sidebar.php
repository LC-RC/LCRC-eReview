<?php
// Student Sidebar – #1665A0 theme, collapsible, modern hover & iconography.
$currentPage = basename($_SERVER['PHP_SELF']);
// Shortened display name: "Kath Account" → "K. Account", "Lebron Zaire James" → "L.Z.James"
$fullName = trim($_SESSION['full_name'] ?? 'User');
$nameWords = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY);
if (count($nameWords) <= 1) {
  $studentShortName = $fullName ? mb_strtoupper(mb_substr($fullName, 0, 1)) . '.' : 'U.';
} elseif (count($nameWords) === 2) {
  $studentShortName = mb_strtoupper(mb_substr($nameWords[0], 0, 1)) . '. ' . $nameWords[1];
} else {
  $lastWord = array_pop($nameWords);
  $initials = implode('.', array_map(function ($w) { return mb_strtoupper(mb_substr($w, 0, 1)); }, $nameWords));
  $studentShortName = $initials . '.' . $lastWord;
}
?>
<div class="min-h-screen flex" id="student-layout-root" x-data="Alpine.store('sidebar')">
<aside id="student-sidebar"
  class="student-sidebar fixed top-0 left-0 h-screen z-[1000] overflow-y-auto overflow-x-hidden transition-all duration-300 ease-in-out rounded-br-2xl flex flex-col"
  :class="collapsed ? 'student-sidebar--collapsed' : 'student-sidebar--expanded'"
  style="background-color:#1665A0; box-shadow: 0 4px 24px rgba(22,101,160,0.25)">
  <!-- Header: profile placeholder (centered when collapsed) -->
  <div class="student-sidebar-header p-4 border-b border-white/15 flex items-center shrink-0 min-h-[80px] transition-all duration-300" :class="collapsed ? 'justify-center' : ''">
    <a href="student_dashboard.php" class="student-sidebar-brand flex items-center gap-3 min-w-0 overflow-hidden rounded-xl px-2 py-2 -mx-2 transition-all duration-300" :class="collapsed ? 'justify-center w-full mx-0' : ''">
      <span class="student-sidebar-avatar shrink-0 w-10 h-10 rounded-full flex items-center justify-center text-white font-bold text-lg bg-white/20 border-2 border-white/30 transition-transform duration-300" aria-hidden="true"><?php echo strtoupper(mb_substr(trim($_SESSION['full_name'] ?? 'U'), 0, 1)); ?></span>
      <span class="text-white font-bold text-lg tracking-tight truncate transition-all duration-300" :class="collapsed ? 'opacity-0 w-0 overflow-hidden absolute' : 'opacity-100'"><?php echo h($studentShortName); ?></span>
    </a>
  </div>
  <nav class="py-3 px-2 flex-1 flex flex-col overflow-y-auto min-h-0">
    <ul class="flex flex-col gap-1 flex-1">
      <li>
        <a href="student_dashboard.php" class="student-nav-item flex items-center gap-3 px-3 py-3 rounded-xl text-white transition-all duration-300 ease-out <?php echo $currentPage === 'student_dashboard.php' ? 'student-nav-item--active' : ''; ?>" :class="collapsed ? 'justify-center' : ''"
          <?php if ($currentPage === 'student_dashboard.php'): ?> style="background-color: rgba(255,255,255,0.22); box-shadow: 0 2px 10px rgba(0,0,0,0.1)" <?php endif; ?>>
          <i class="bi bi-speedometer2 shrink-0 w-8 h-8 flex items-center justify-center student-nav-icon" style="font-size:1.25rem"></i>
          <span class="font-medium truncate whitespace-nowrap transition-all duration-300" :class="collapsed ? 'opacity-0 w-0 overflow-hidden absolute pointer-events-none' : 'opacity-100'">Dashboard</span>
        </a>
      </li>
      <li>
        <a href="student_subjects.php" class="student-nav-item flex items-center gap-3 px-3 py-3 rounded-xl text-white transition-all duration-300 ease-out <?php echo $currentPage === 'student_subjects.php' ? 'student-nav-item--active' : ''; ?>" :class="collapsed ? 'justify-center' : ''"
          <?php if ($currentPage === 'student_subjects.php'): ?> style="background-color: rgba(255,255,255,0.22); box-shadow: 0 2px 10px rgba(0,0,0,0.1)" <?php endif; ?>>
          <i class="bi bi-journal-bookmark shrink-0 w-8 h-8 flex items-center justify-center student-nav-icon" style="font-size:1.25rem"></i>
          <span class="font-medium truncate whitespace-nowrap transition-all duration-300" :class="collapsed ? 'opacity-0 w-0 overflow-hidden absolute pointer-events-none' : 'opacity-100'">Subjects</span>
        </a>
      </li>
      <li>
        <a href="student_preboards.php" class="student-nav-item flex items-center gap-3 px-3 py-3 rounded-xl text-white transition-all duration-300 ease-out <?php echo $currentPage === 'student_preboards.php' ? 'student-nav-item--active' : ''; ?>" :class="collapsed ? 'justify-center' : ''"
          <?php if ($currentPage === 'student_preboards.php'): ?> style="background-color: rgba(255,255,255,0.22); box-shadow: 0 2px 10px rgba(0,0,0,0.1)" <?php endif; ?>>
          <i class="bi bi-clipboard-check shrink-0 w-8 h-8 flex items-center justify-center student-nav-icon" style="font-size:1.25rem"></i>
          <span class="font-medium truncate whitespace-nowrap transition-all duration-300" :class="collapsed ? 'opacity-0 w-0 overflow-hidden absolute pointer-events-none' : 'opacity-100'">Preboards</span>
        </a>
      </li>
      <li>
        <a href="student_preweek.php" class="student-nav-item flex items-center gap-3 px-3 py-3 rounded-xl text-white transition-all duration-300 ease-out <?php echo $currentPage === 'student_preweek.php' ? 'student-nav-item--active' : ''; ?>" :class="collapsed ? 'justify-center' : ''"
          <?php if ($currentPage === 'student_preweek.php'): ?> style="background-color: rgba(255,255,255,0.22); box-shadow: 0 2px 10px rgba(0,0,0,0.1)" <?php endif; ?>>
          <i class="bi bi-lightning-charge shrink-0 w-8 h-8 flex items-center justify-center student-nav-icon" style="font-size:1.25rem"></i>
          <span class="font-medium truncate whitespace-nowrap transition-all duration-300" :class="collapsed ? 'opacity-0 w-0 overflow-hidden absolute pointer-events-none' : 'opacity-100'">Preweek</span>
        </a>
      </li>
      <li class="mt-auto pt-3">
        <a href="logout.php" class="ereview-logout-trigger student-nav-item student-nav-item--logout flex items-center gap-3 px-3 py-3 rounded-xl text-white transition-all duration-300 ease-out" :class="collapsed ? 'justify-center' : ''">
          <i class="bi bi-box-arrow-right shrink-0 w-8 h-8 flex items-center justify-center student-nav-icon" style="font-size:1.25rem"></i>
          <span class="font-medium truncate whitespace-nowrap transition-all duration-300" :class="collapsed ? 'opacity-0 w-0 overflow-hidden absolute pointer-events-none' : 'opacity-100'">Logout</span>
        </a>
      </li>
    </ul>
  </nav>
</aside>

<main id="main" class="student-main flex-1 min-h-screen transition-all duration-300 ease-in-out bg-[#f6f9ff] text-gray-800 font-sans pt-0 px-5 pb-5"
  :class="collapsed ? 'student-main--collapsed' : 'student-main--expanded'">

<style>
/* Sidebar width – slightly reduced; collapse/expand via topbar burger only */
.student-sidebar.student-sidebar--expanded { width: 240px; }
.student-sidebar.student-sidebar--collapsed { width: 64px; }
.student-main.student-main--expanded { margin-left: 240px; }
.student-main.student-main--collapsed { margin-left: 64px; }
/* Default before Alpine: show expanded */
.student-sidebar:not(.student-sidebar--collapsed) { width: 240px; }
.student-main:not(.student-main--collapsed) { margin-left: 240px; }

/* ---- Profile placeholder (avatar) in header – centered when collapsed ---- */
.student-sidebar-avatar {
  flex-shrink: 0;
}
#student-sidebar .student-sidebar-brand:hover .student-sidebar-avatar {
  border-color: rgba(255,255,255,0.5);
}

/* ---- Modern hover: sidebar brand (user name) ---- */
#student-sidebar .student-sidebar-brand {
  background: transparent;
  box-shadow: 0 0 0 0 rgba(255,255,255,0);
}
#student-sidebar .student-sidebar-brand:hover {
  background: rgba(255,255,255,0.12);
  box-shadow: 0 2px 12px rgba(0,0,0,0.08);
}
#student-sidebar .student-sidebar-brand:hover .student-sidebar-avatar {
  transform: scale(1.05);
  border-color: rgba(255,255,255,0.5);
}
#student-sidebar .student-sidebar-brand:active {
  background: rgba(255,255,255,0.18);
  transform: scale(0.98);
}

/* ---- Modern hover: nav items ---- */
#student-sidebar .student-nav-item {
  position: relative;
  overflow: hidden;
}
#student-sidebar .student-nav-item::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0.04) 100%);
  opacity: 0;
  transition: opacity 0.3s ease;
  border-radius: inherit;
  pointer-events: none;
}
#student-sidebar .student-nav-item:not(.student-nav-item--active):hover {
  background-color: rgba(255,255,255,0.14) !important;
  box-shadow: 0 4px 14px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.08);
  transform: translateX(2px);
}
#student-sidebar .student-nav-item:not(.student-nav-item--active):hover::before {
  opacity: 1;
}
#student-sidebar .student-nav-item:not(.student-nav-item--active):hover .student-nav-icon {
  transform: scale(1.06);
}
#student-sidebar .student-nav-item:not(.student-nav-item--active):active {
  transform: translateX(0) scale(0.99);
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
#student-sidebar .student-nav-item--logout:hover {
  background-color: rgba(220,38,38,0.28) !important;
  box-shadow: 0 4px 14px rgba(220,38,38,0.2), inset 0 1px 0 rgba(255,255,255,0.06);
  transform: translateX(2px);
}
#student-sidebar .student-nav-item--logout:hover .student-nav-icon {
  transform: scale(1.06);
}
#student-sidebar .student-nav-item--logout:active {
  transform: translateX(0) scale(0.99);
}
#student-sidebar .student-nav-icon {
  transition: transform 0.25s ease;
}
</style>
<?php $ereviewLogoutModalVariant = 'student'; include __DIR__ . '/includes/logout_confirm_modal.php'; ?>
