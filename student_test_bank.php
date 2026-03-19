<?php
require_once 'auth.php';
requireRole('student');

$pageTitle = 'Test Bank';

// Ensure table exists (match admin)
$conn->query("CREATE TABLE IF NOT EXISTS `test_bank` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `description` text,
  `question_file_path` varchar(512) NOT NULL DEFAULT '',
  `question_file_name` varchar(255) NOT NULL DEFAULT '',
  `solution_file_path` varchar(512) NOT NULL DEFAULT '',
  `solution_file_name` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$list = mysqli_query($conn, "SELECT id, title, description, question_file_path, question_file_name, solution_file_path, solution_file_name, created_at, updated_at FROM test_bank ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .student-protected {
      -webkit-user-select: none;
      -moz-user-select: none;
      -ms-user-select: none;
      user-select: none;
    }
    .student-protected ::selection {
      background: transparent;
    }
  </style>
</head>
<body class="font-sans antialiased student-protected">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page min-h-full pb-8">
    <!-- Page header: consistent with dashboard -->
    <section class="dashboard-welcome-hero relative overflow-hidden rounded-2xl mb-6 px-6 py-6 sm:py-8 border-0 shadow-[0_8px_30px_rgba(20,61,89,0.25),0_0_0_1px_rgba(255,255,255,0.08)_inset]" aria-label="Test Bank">
      <div class="absolute inset-0 bg-gradient-to-br from-[#1665A0] via-[#145a8f] to-[#143D59] opacity-100"></div>
      <div class="absolute top-0 right-0 w-72 h-72 sm:w-96 sm:h-96 rounded-full bg-white/10 -translate-y-1/2 translate-x-1/2"></div>
      <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">
        <div class="flex items-center gap-4">
          <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white/20 backdrop-blur-sm border border-white/30 text-white shadow-lg">
            <i class="bi bi-folder2-open text-2xl" aria-hidden="true"></i>
          </span>
          <div>
            <h1 class="text-xl sm:text-2xl md:text-3xl font-bold m-0 text-white drop-shadow-sm">Test Bank</h1>
            <p class="text-white/95 text-sm sm:text-base mt-1 mb-0">Review materials: practice questions and answers.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- List of test bank entries -->
    <section class="space-y-4" aria-label="Available materials">
      <?php if ($list && mysqli_num_rows($list) > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <?php while ($row = mysqli_fetch_assoc($list)): ?>
            <article class="rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1),0_4px_16px_rgba(20,61,89,0.06)] overflow-hidden transition-shadow duration-300 hover:shadow-[0_8px_24px_rgba(20,61,89,0.14)] bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0]">
              <div class="px-6 py-4 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50 flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#1665A0] text-white shadow-lg shadow-[#1665A0]/25">
                  <i class="bi bi-file-earmark-text text-lg" aria-hidden="true"></i>
                </span>
                <div class="min-w-0 flex-1">
                  <h2 class="text-lg font-bold text-[#143D59] m-0 truncate"><?php echo h($row['title']); ?></h2>
                </div>
              </div>
              <div class="p-4 sm:p-6 space-y-3 sm:space-y-4 bg-white/50">
                <div class="flex flex-wrap gap-3">
                  <a href="student_test_bank_viewer.php?id=<?php echo (int)$row['id']; ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold bg-[#1665A0] text-white hover:bg-[#143D59] transition shadow-[0_2px_8px_rgba(22,101,160,0.3)]">
                    <i class="bi bi-eye"></i> View files
                  </a>
                </div>
              </div>
            </article>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <div class="rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1)] bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0] p-8 text-center">
          <i class="bi bi-folder2-open text-4xl text-[#1665A0]/60 block mb-3" aria-hidden="true"></i>
          <h2 class="text-lg font-bold text-[#143D59] m-0">No test bank materials yet</h2>
          <p class="text-[#143D59]/70 mt-1 mb-0">Your instructor may add practice questions and answers here. Check back later.</p>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>
<script>
(function() {
  function isInputLike(el) {
    if (!el) return false;
    var tag = (el.tagName || '').toLowerCase();
    var type = (el.type || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || el.isContentEditable || type === 'text' || type === 'password';
  }
  document.addEventListener('contextmenu', function(e) {
    if (!isInputLike(e.target)) e.preventDefault();
  });
  document.addEventListener('selectstart', function(e) {
    if (!isInputLike(e.target)) e.preventDefault();
  });
  window.addEventListener('keydown', function(e) {
    var ctrlLike = e.ctrlKey || e.metaKey;
    var key = (e.key || '').toLowerCase();
    if (ctrlLike && ['c','x','s','p','u','a'].indexOf(key) !== -1 && !isInputLike(e.target)) {
      e.preventDefault();
    }
  }, true);
})();
</script>
</body>
</html>
