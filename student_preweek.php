<?php
require_once 'auth.php';
requireRole('student');
require_once __DIR__ . '/includes/preweek_migrate.php';

$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectId <= 0) {
    $subjectsResult = mysqli_query($conn, "SELECT subject_id, subject_name FROM subjects WHERE status='active' ORDER BY subject_name ASC");
    $pageTitle = 'Preweek';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <?php require_once __DIR__ . '/includes/head_app.php'; ?>
    </head>
    <body class="font-sans antialiased">
      <?php include 'student_sidebar.php'; ?>
      <?php include 'student_topbar.php'; ?>

      <div class="student-page-wrap max-w-6xl mx-auto">
        <div class="rounded-2xl px-6 py-5 bg-gradient-to-r from-[#1665A0] to-[#143D59] text-white shadow-[0_10px_30px_rgba(20,61,89,0.35)] flex flex-wrap items-center justify-between gap-3 mb-6">
          <div class="flex items-center gap-3">
            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md">
              <i class="bi bi-lightning-charge text-xl" aria-hidden="true"></i>
            </span>
            <div>
              <h1 class="text-xl sm:text-2xl font-bold m-0 tracking-tight">Preweek</h1>
              <p class="text-sm sm:text-base text-white/90 mt-1 mb-0">Select a subject to view its Preweek videos and handouts.</p>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
          <?php if ($subjectsResult && mysqli_num_rows($subjectsResult) > 0): ?>
            <?php while ($s = mysqli_fetch_assoc($subjectsResult)): ?>
              <article class="rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white shadow-[0_1px_4px_rgba(15,23,42,0.08),0_6px_18px_rgba(15,23,42,0.06)] hover:shadow-[0_8px_26px_rgba(15,23,42,0.16)] hover:-translate-y-0.5 transition-all duration-300 flex flex-col overflow-hidden">
                <div class="px-5 pt-5 pb-4 flex items-start justify-between gap-3">
                  <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#1665A0] text-white shadow-md">
                      <i class="bi bi-journal-bookmark text-lg" aria-hidden="true"></i>
                    </span>
                    <div>
                      <h2 class="text-base sm:text-lg font-bold text-[#143D59] m-0"><?php echo h($s['subject_name']); ?></h2>
                      <p class="text-xs uppercase tracking-[0.16em] text-[#143D59]/55 mt-1 mb-0">Preweek</p>
                    </div>
                  </div>
                </div>
                <div class="px-5 pb-5 flex items-center justify-end gap-3 text-sm border-t border-[#1665A0]/10 bg-white/70">
                  <a href="student_preweek.php?subject_id=<?php echo (int)$s['subject_id']; ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold text-white bg-[#1665A0] hover:bg-[#0f4d7a] shadow-[0_2px_8px_rgba(22,101,160,0.45)] hover:shadow-[0_6px_18px_rgba(22,101,160,0.5)] active:scale-[0.97] transition-all duration-200">
                    <span>Open</span>
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                  </a>
                </div>
              </article>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="col-span-full text-center text-gray-500 py-12">No subjects available.</div>
          <?php endif; ?>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT subject_name FROM subjects WHERE subject_id=? AND status='active' LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$subject = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$subject) { header('Location: student_preweek.php'); exit; }
$subjectName = $subject['subject_name'];

$videosStmt = mysqli_prepare($conn, "SELECT * FROM preweek_videos WHERE subject_id=? ORDER BY preweek_video_id DESC");
mysqli_stmt_bind_param($videosStmt, 'i', $subjectId);
mysqli_stmt_execute($videosStmt);
$videos = mysqli_stmt_get_result($videosStmt);

$handoutsStmt = mysqli_prepare($conn, "SELECT * FROM preweek_handouts WHERE subject_id=? ORDER BY preweek_handout_id DESC");
mysqli_stmt_bind_param($handoutsStmt, 'i', $subjectId);
mysqli_stmt_execute($handoutsStmt);
$handouts = mysqli_stmt_get_result($handoutsStmt);

$pageTitle = 'Preweek';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>
  <?php include 'student_topbar.php'; ?>

  <div class="student-page-wrap max-w-6xl mx-auto">
    <div class="bg-white rounded-2xl shadow-card px-6 py-6 mb-6 border border-gray-100">
      <h1 class="text-2xl font-bold text-[#143D59] m-0 flex items-center gap-2">
        <i class="bi bi-lightning-charge text-[#1665A0]"></i> Preweek
      </h1>
      <p class="text-gray-500 mt-2 mb-0">Subject: <strong><?php echo h($subjectName); ?></strong> · Watch the Preweek videos and download handouts provided by your instructors.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <section class="rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1)] overflow-hidden bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0]">
        <div class="px-6 py-4 border-b border-[#1665A0]/10 bg-[#e8f2fa]/60 flex items-center justify-between gap-3">
          <div class="flex items-center gap-2">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#1665A0] text-white shadow-md shadow-[#1665A0]/30">
              <i class="bi bi-play-circle"></i>
            </span>
            <div>
              <div class="font-semibold text-[#143D59]">Videos</div>
              <div class="text-xs text-[#143D59]/70">Preweek video lessons</div>
            </div>
          </div>
          <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-white/80 text-[#1665A0] border border-[#1665A0]/20"><?php echo (int)($videos ? mysqli_num_rows($videos) : 0); ?></span>
        </div>
        <div class="p-5 space-y-3">
          <?php if (!$videos || mysqli_num_rows($videos) === 0): ?>
            <div class="text-center text-gray-500 py-12">
              <i class="bi bi-inbox text-4xl block mb-2 opacity-60"></i>
              <div class="font-semibold text-gray-600">No preweek videos yet</div>
              <p class="text-sm mt-1">Check back later.</p>
            </div>
          <?php else: ?>
            <?php mysqli_data_seek($videos, 0); while ($v = mysqli_fetch_assoc($videos)): ?>
              <a href="<?php echo h($v['video_url']); ?>" target="_blank" rel="noopener"
                 class="group block rounded-2xl p-4 bg-white/70 border border-[#1665A0]/10 hover:border-[#1665A0]/25 hover:bg-white transition shadow-sm">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="font-semibold text-[#143D59] truncate"><?php echo h($v['video_title']); ?></div>
                    <div class="text-xs text-[#143D59]/70 mt-1">Type: <?php echo h(($v['upload_type'] ?? '') === 'file' ? 'File' : 'URL'); ?></div>
                  </div>
                  <span class="shrink-0 inline-flex items-center gap-1 text-sm font-semibold text-[#1665A0] group-hover:text-[#145a8f]">
                    Open <i class="bi bi-box-arrow-up-right"></i>
                  </span>
                </div>
              </a>
            <?php endwhile; ?>
          <?php endif; ?>
        </div>
      </section>

      <section class="rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1)] overflow-hidden bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#143D59]">
        <div class="px-6 py-4 border-b border-[#1665A0]/10 bg-[#e8f2fa]/60 flex items-center justify-between gap-3">
          <div class="flex items-center gap-2">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#143D59] text-white shadow-md shadow-[#143D59]/25">
              <i class="bi bi-file-earmark-pdf"></i>
            </span>
            <div>
              <div class="font-semibold text-[#143D59]">Handouts</div>
              <div class="text-xs text-[#143D59]/70">PDF/DOC/PPT downloads</div>
            </div>
          </div>
          <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-white/80 text-[#143D59] border border-[#143D59]/20"><?php echo (int)($handouts ? mysqli_num_rows($handouts) : 0); ?></span>
        </div>
        <div class="p-5 space-y-3">
          <?php if (!$handouts || mysqli_num_rows($handouts) === 0): ?>
            <div class="text-center text-gray-500 py-12">
              <i class="bi bi-inbox text-4xl block mb-2 opacity-60"></i>
              <div class="font-semibold text-gray-600">No preweek handouts yet</div>
              <p class="text-sm mt-1">Check back later.</p>
            </div>
          <?php else: ?>
            <?php mysqli_data_seek($handouts, 0); while ($h = mysqli_fetch_assoc($handouts)): ?>
              <div class="rounded-2xl p-4 bg-white/70 border border-[#1665A0]/10 hover:border-[#1665A0]/25 hover:bg-white transition shadow-sm">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="font-semibold text-[#143D59] truncate"><?php echo h($h['handout_title']); ?></div>
                    <div class="text-xs text-[#143D59]/70 mt-1">
                      <?php if (!empty($h['file_size'])): ?>
                        <?php echo number_format(((int)$h['file_size']) / 1024, 2); ?> KB
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="shrink-0">
                    <?php if (!empty($h['allow_download'])): ?>
                      <a href="<?php echo h($h['file_path']); ?>" target="_blank" rel="noopener"
                         class="inline-flex items-center gap-1 px-3 py-2 rounded-xl text-sm font-semibold bg-[#1665A0] text-white hover:bg-[#145a8f] transition">
                        <i class="bi bi-download"></i> Download
                      </a>
                    <?php else: ?>
                      <span class="inline-flex items-center gap-1 px-3 py-2 rounded-xl text-sm font-semibold bg-gray-200 text-gray-600" title="Download locked">
                        <i class="bi bi-lock"></i> Locked
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endwhile; ?>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </div>
</body>
</html>

