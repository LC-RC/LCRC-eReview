<?php
// Admin Sidebar - Tailwind + Alpine
?>
<!-- Sidebar -->
<aside id="sidebar" class="fixed top-0 left-0 w-[260px] h-screen bg-[#012970] z-[1000] overflow-y-auto transition-all duration-300"
       x-data="{ mobileOpen: false }">
    <div class="p-5 bg-white/10 border-b border-white/10">
        <h3 class="text-white text-xl font-bold m-0 flex items-center gap-2">
            <i class="bi bi-mortarboard-fill"></i> LCRC eReview
        </h3>
    </div>
    <nav class="py-5">
        <ul class="flex flex-col gap-0">
            <li>
                <a href="admin_dashboard.php" class="flex items-center gap-3 px-5 py-3 text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent hover:border-white transition <?php echo basename($_SERVER['PHP_SELF']) === 'admin_dashboard.php' ? 'bg-white/15 text-white border-l-white font-semibold' : ''; ?>">
                    <i class="bi bi-speedometer2 text-lg w-6 text-center"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="admin_students.php" class="flex items-center gap-3 px-5 py-3 text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent hover:border-white transition <?php echo in_array(basename($_SERVER['PHP_SELF']), ['admin_students.php', 'admin_student_view.php']) ? 'bg-white/15 text-white border-l-white font-semibold' : ''; ?>">
                    <i class="bi bi-people text-lg w-6 text-center"></i>
                    <span>Students</span>
                </a>
            </li>
            <li>
                <a href="admin_subjects.php" class="flex items-center gap-3 px-5 py-3 text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent hover:border-white transition <?php echo in_array(basename($_SERVER['PHP_SELF']), ['admin_subjects.php', 'admin_lessons.php', 'admin_videos.php', 'admin_handouts.php', 'admin_quizzes.php', 'admin_quiz_questions.php']) ? 'bg-white/15 text-white border-l-white font-semibold' : ''; ?>">
                    <i class="bi bi-book text-lg w-6 text-center"></i>
                    <span>Content</span>
                </a>
            </li>
            <li>
                <a href="admin_preboards_subjects.php" class="flex items-center gap-3 px-5 py-3 text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent hover:border-white transition <?php echo in_array(basename($_SERVER['PHP_SELF']), ['admin_preboards_subjects.php', 'admin_preboards_sets.php', 'admin_preboards_questions.php']) ? 'bg-white/15 text-white border-l-white font-semibold' : ''; ?>">
                    <i class="bi bi-clipboard-check text-lg w-6 text-center"></i>
                    <span>Preboards</span>
                </a>
            </li>
            <li>
                <a href="logout.php" class="flex items-center gap-3 px-5 py-3 text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent hover:border-white transition">
                    <i class="bi bi-box-arrow-right text-lg w-6 text-center"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>
</aside>

<!-- Main Content -->
<main id="main" class="ml-[260px] p-5 min-h-screen transition-all duration-300 bg-[#f6f9ff] text-gray-700 font-sans">
