<?php
// Student Sidebar - Tailwind + Alpine
?>
<aside id="sidebar" class="fixed top-0 left-0 w-[260px] h-screen bg-[#012970] z-[1000] overflow-y-auto transition-all duration-300">
    <div class="p-5 bg-white/10 border-b border-white/10">
        <h3 class="text-white text-xl font-bold m-0 flex items-center gap-2">
            <i class="bi bi-mortarboard-fill"></i> LCRC eReview
        </h3>
    </div>
    <nav class="py-5">
        <ul class="flex flex-col gap-0">
            <li>
                <a href="student_dashboard.php" class="flex items-center gap-3 px-5 py-3 text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent hover:border-white transition <?php echo basename($_SERVER['PHP_SELF']) === 'student_dashboard.php' ? 'bg-white/15 text-white border-l-white font-semibold' : ''; ?>">
                    <i class="bi bi-speedometer2 text-lg w-6 text-center"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="student_subjects.php" class="flex items-center gap-3 px-5 py-3 text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent hover:border-white transition <?php echo basename($_SERVER['PHP_SELF']) === 'student_subjects.php' ? 'bg-white/15 text-white border-l-white font-semibold' : ''; ?>">
                    <i class="bi bi-book text-lg w-6 text-center"></i>
                    <span>Subjects</span>
                </a>
            </li>
            <li>
                <a href="student_quizzes.php" class="flex items-center gap-3 px-5 py-3 text-white/80 hover:bg-white/10 hover:text-white border-l-4 border-transparent hover:border-white transition <?php echo basename($_SERVER['PHP_SELF']) === 'student_quizzes.php' ? 'bg-white/15 text-white border-l-white font-semibold' : ''; ?>">
                    <i class="bi bi-question-circle text-lg w-6 text-center"></i>
                    <span>Quizzes</span>
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

<main id="main" class="ml-[260px] p-5 min-h-screen transition-all duration-300 bg-[#f6f9ff] text-gray-700 font-sans">
