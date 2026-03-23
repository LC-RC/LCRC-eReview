<?php
require_once 'auth.php';
requireRole('student');

// Ensure module table exists (safe no-op if already created)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preboards_subjects (
  preboards_subject_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_preboards_subject_name (subject_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Optional: enforce access_end check on every page load (same as student_subjects.php)
$uid = (int)$_SESSION['user_id'];
$ur = mysqli_query($conn, "SELECT access_end FROM users WHERE user_id=" . $uid . " LIMIT 1");
$u = $ur ? mysqli_fetch_assoc($ur) : null;
if ($u && !empty($u['access_end']) && strtotime($u['access_end']) < time()) {
    $_SESSION['error'] = 'Your access has expired.';
    header('Location: index.php');
    exit;
}

$subjectsResult = mysqli_query($conn, "SELECT * FROM preboards_subjects WHERE status='active' ORDER BY subject_name ASC");
$pageTitle = 'Preboards';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .student-dashboard-page .rounded-2xl { border-radius: 0.75rem !important; }
    .student-dashboard-page .rounded-xl { border-radius: 0.625rem !important; }
  </style>
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page min-h-full pb-8">
    <section class="mb-5">
      <div class="rounded-2xl px-6 py-5 bg-gradient-to-r from-[#1665A0] to-[#143D59] text-white shadow-[0_10px_30px_rgba(20,61,89,0.35)] flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md">
            <i class="bi bi-clipboard-check text-xl" aria-hidden="true"></i>
          </span>
          <div>
            <h1 class="text-xl sm:text-2xl font-bold m-0 tracking-tight">Preboards</h1>
            <p class="text-sm sm:text-base text-white/90 mt-1 mb-0">Select a preboard subject to begin.</p>
          </div>
        </div>
        <div class="text-xs sm:text-sm text-white/80 flex flex-col items-start sm:items-end gap-1">
          <span class="uppercase tracking-[0.16em] text-white/60 font-semibold">Overview</span>
          <span>
            <?php $totalSubjects = $subjectsResult ? mysqli_num_rows($subjectsResult) : 0; ?>
            <?php echo (int)$totalSubjects; ?> active preboard subject<?php echo $totalSubjects === 1 ? '' : 's'; ?>
          </span>
        </div>
      </div>
    </section>

    <?php if (isset($_SESSION['error'])): ?>
      <div class="mb-5 p-4 rounded-2xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800 shadow-[0_2px_8px_rgba(248,113,113,0.35)]">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?php echo h($_SESSION['error']); ?></span>
        <?php unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>

    <section aria-label="Preboards subjects list">
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        <?php if ($subjectsResult && mysqli_num_rows($subjectsResult) > 0): ?>
          <?php mysqli_data_seek($subjectsResult, 0); ?>
          <?php while ($s = mysqli_fetch_assoc($subjectsResult)): ?>
            <article class="rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white shadow-[0_1px_4px_rgba(15,23,42,0.08),0_6px_18px_rgba(15,23,42,0.06)] hover:shadow-[0_8px_26px_rgba(15,23,42,0.16)] hover:-translate-y-0.5 transition-all duration-300 flex flex-col overflow-hidden">
              <div class="px-5 pt-5 pb-4 flex items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                  <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#143D59] text-white shadow-md">
                    <i class="bi bi-clipboard-check text-lg" aria-hidden="true"></i>
                  </span>
                  <div>
                    <h2 class="text-base sm:text-lg font-bold text-[#143D59] m-0"><?php echo h($s['subject_name']); ?></h2>
                    <p class="text-xs uppercase tracking-[0.16em] text-[#143D59]/55 mt-1 mb-0">Preboards Subject</p>
                  </div>
                </div>
                <button type="button" class="text-gray-400 hover:text-[#1665A0] transition-colors" aria-label="More options">
                  <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                </button>
              </div>
              <div class="px-5 pb-4 flex-1">
                <p class="text-sm text-[#143D59]/80 m-0">
                  <?php echo h($s['description'] ?: 'Preboards preparation and materials for this subject.'); ?>
                </p>
              </div>
              <div class="px-5 pb-5 flex items-center justify-between gap-3 text-sm border-t border-[#1665A0]/10 bg-white/70">
                <div class="flex items-center gap-2 text-[#143D59]/75">
                  <i class="bi bi-info-circle" aria-hidden="true"></i>
                  <span>Ready</span>
                </div>
                <a href="student_preboards_view.php?preboards_subject_id=<?php echo (int)$s['preboards_subject_id']; ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold text-white bg-[#1665A0] hover:bg-[#0f4d7a] shadow-[0_2px_8px_rgba(22,101,160,0.45)] hover:shadow-[0_6px_18px_rgba(22,101,160,0.5)] active:scale-[0.97] transition-all duration-200">
                  <span>Open</span>
                  <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                </a>
              </div>
            </article>
          <?php endwhile; ?>
        <?php else: ?>
          <div class="col-span-full">
            <div class="rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white shadow-[0_1px_4px_rgba(15,23,42,0.08),0_6px_18px_rgba(15,23,42,0.06)] p-12 text-center text-[#143D59]/80">
              <i class="bi bi-inbox text-5xl mb-3 text-[#1665A0]" aria-hidden="true"></i>
              <p class="text-lg font-semibold m-0">No preboards subjects available yet.</p>
              <p class="text-sm mt-1 mb-0">Check back later or contact your administrator.</p>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</main>
</div>
</body>
</html>

