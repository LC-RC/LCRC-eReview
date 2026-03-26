<?php
require_once 'auth.php';
requireRole('student');
require_once __DIR__ . '/includes/preweek_migrate.php';

$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectId <= 0) {
    $subjectsResult = mysqli_query($conn, "SELECT subject_id, subject_name FROM subjects WHERE status='active' ORDER BY subject_name ASC");
    $subjectsTotal = $subjectsResult ? mysqli_num_rows($subjectsResult) : 0;
    $videosTotal = 0;
    $handoutsTotal = 0;
    $vr = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM preweek_videos");
    if ($vr && ($row = mysqli_fetch_assoc($vr))) $videosTotal = (int)($row['c'] ?? 0);
    $hr = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM preweek_handouts");
    if ($hr && ($row = mysqli_fetch_assoc($hr))) $handoutsTotal = (int)($row['c'] ?? 0);
    $pageTitle = 'Preweek';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <?php require_once __DIR__ . '/includes/head_app.php'; ?>
      <style>
        .preweek-page { background: linear-gradient(180deg, #eef5fc 0%, #e4f0fa 45%, #ebf4fc 100%); }
        .student-hero {
          border-radius: 0.75rem;
          border: 1px solid rgba(255,255,255,0.28);
          background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
          box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22);
        }
        .hero-strip { background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.24); border-radius: .62rem; }
        .section-title {
          display: flex; align-items: center; gap: .5rem; margin: 0 0 .85rem; padding: .45rem .65rem;
          border: 1px solid #d8e8f6; border-radius: .62rem; background: linear-gradient(180deg,#f4f9fe 0%,#fff 100%);
          color: #143D59; font-size: 1.03rem; font-weight: 800;
        }
        .section-title i {
          width: 1.55rem; height: 1.55rem; border-radius: .45rem; display: inline-flex; align-items: center; justify-content: center;
          border: 1px solid #b9daf2; background: #e8f2fa; color: #1665A0; font-size: .83rem;
        }
        .dash-card {
          border-radius: .75rem; border: 1px solid rgba(22,101,160,.18); background: linear-gradient(180deg, #f8fbff 0%, #ffffff 60%);
          box-shadow: 0 10px 28px -22px rgba(20,61,89,.55), 0 1px 0 rgba(255,255,255,.85) inset;
          transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease, background-color .22s ease;
        }
        .dash-card:hover { transform: translateY(-2px); border-color: rgba(22,101,160,.32); box-shadow: 0 20px 34px -24px rgba(20,61,89,.35); }
        .open-btn {
          display: inline-flex; align-items: center; gap: .45rem; border-radius: .55rem; border: 1px solid #1665A0;
          background: #1665A0; color: #fff; font-weight: 700; padding: .5rem .85rem; font-size: .81rem; transition: all .2s ease;
        }
        .open-btn:hover { background: #145a8f; border-color: #145a8f; transform: translateY(-1px); }
        .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
        .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; }
        @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
      </style>
    </head>
    <body class="font-sans antialiased preweek-page">
      <?php include 'student_sidebar.php'; ?>
      <?php include 'student_topbar.php'; ?>

      <div class="student-dashboard-page min-h-full pb-8">
        <section class="student-hero dash-anim delay-1 relative overflow-hidden mb-6 px-6 py-7 text-white">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 border border-white/30"><i class="bi bi-lightning-charge"></i></span>
                Preweek
              </h1>
              <p class="text-white/90 mt-2 mb-0 max-w-2xl">Select a subject to view preweek videos and handouts.</p>
            </div>
          </div>
          <div class="hero-strip mt-4 px-4 py-2.5 text-sm flex flex-wrap gap-x-3 gap-y-1">
            <span class="font-semibold">Active subjects: <?php echo (int)$subjectsTotal; ?></span>
            <span class="text-white/50">·</span>
            <span class="font-semibold">Videos: <?php echo (int)$videosTotal; ?></span>
            <span class="text-white/50">·</span>
            <span class="font-semibold">Handouts: <?php echo (int)$handoutsTotal; ?></span>
          </div>
        </section>

        <h2 class="section-title dash-anim delay-2"><i class="bi bi-grid-3x3-gap"></i> Preweek Subjects</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
          <?php if ($subjectsResult && mysqli_num_rows($subjectsResult) > 0): ?>
            <?php while ($s = mysqli_fetch_assoc($subjectsResult)): ?>
              <?php
                $sid = (int)$s['subject_id'];
                $vcount = 0;
                $hcount = 0;
                $qv = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM preweek_videos WHERE subject_id={$sid}");
                if ($qv && ($r = mysqli_fetch_assoc($qv))) $vcount = (int)($r['c'] ?? 0);
                $qh = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM preweek_handouts WHERE subject_id={$sid}");
                if ($qh && ($r = mysqli_fetch_assoc($qh))) $hcount = (int)($r['c'] ?? 0);
              ?>
              <article class="dash-card dash-anim delay-2 flex flex-col overflow-hidden">
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
                <div class="px-5 pb-5 flex items-center justify-between gap-3 text-sm border-t border-[#d6e8f7] bg-white/70">
                  <span class="text-[#143D59]/75"><?php echo (int)$vcount; ?> videos · <?php echo (int)$hcount; ?> handouts</span>
                  <a href="student_preweek.php?subject_id=<?php echo (int)$s['subject_id']; ?>" class="open-btn">
                    <span>Open</span>
                    <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                  </a>
                </div>
              </article>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="col-span-full dash-card dash-anim delay-3 text-center text-gray-500 py-12">No subjects available.</div>
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
  <style>
    .preweek-page { background: linear-gradient(180deg, #eef5fc 0%, #e4f0fa 45%, #ebf4fc 100%); }
    .student-hero { border-radius: 0.75rem; border: 1px solid rgba(255,255,255,0.28); background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%); box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22); }
    .hero-strip { background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.24); border-radius: .62rem; }
    .section-title { display:flex; align-items:center; gap:.5rem; margin:0 0 .85rem; padding:.45rem .65rem; border:1px solid #d8e8f6; border-radius:.62rem; background: linear-gradient(180deg,#f4f9fe 0%,#fff 100%); color:#143D59; font-size:1.03rem; font-weight:800; }
    .section-title i { width:1.55rem; height:1.55rem; border-radius:.45rem; display:inline-flex; align-items:center; justify-content:center; border:1px solid #b9daf2; background:#e8f2fa; color:#1665A0; font-size:.83rem; }
    .dash-card { border-radius:.75rem; border:1px solid rgba(22,101,160,.18); background: linear-gradient(180deg, #f8fbff 0%, #ffffff 60%); box-shadow:0 10px 28px -22px rgba(20,61,89,.55), 0 1px 0 rgba(255,255,255,.85) inset; transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease; }
    .dash-card:hover { transform: translateY(-2px); border-color: rgba(22,101,160,.32); box-shadow:0 20px 34px -24px rgba(20,61,89,.35); }
    .preweek-item { background: rgba(255,255,255,0.72) !important; border:1px solid rgba(22,101,160,.12); border-radius:.72rem; }
    .preweek-item:hover { border-color: rgba(22,101,160,.26); background:#fff !important; box-shadow:0 8px 18px rgba(20,61,89,0.12); }
    .open-btn { display:inline-flex; align-items:center; gap:.45rem; border-radius:.55rem; border:1px solid #1665A0; background:#1665A0; color:#fff; font-weight:700; padding:.5rem .85rem; font-size:.81rem; transition:all .2s ease; }
    .open-btn:hover { background:#145a8f; border-color:#145a8f; transform:translateY(-1px); }
    .dash-anim { opacity:0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay:.05s; } .delay-2 { animation-delay:.12s; } .delay-3 { animation-delay:.18s; }
    @keyframes dashFadeUp { to { opacity:1; transform: translateY(0);} }
  </style>
</head>
<body class="font-sans antialiased preweek-page">
  <?php include 'student_sidebar.php'; ?>
  <?php include 'student_topbar.php'; ?>

  <?php
    $videosCount = $videos ? mysqli_num_rows($videos) : 0;
    $handoutsCount = $handouts ? mysqli_num_rows($handouts) : 0;
    if ($videos) mysqli_data_seek($videos, 0);
    if ($handouts) mysqli_data_seek($handouts, 0);
  ?>
  <div class="student-dashboard-page min-h-full pb-8">
    <section class="student-hero dash-anim delay-1 relative overflow-hidden mb-6 px-6 py-7 text-white">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3">
            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 border border-white/30"><i class="bi bi-lightning-charge"></i></span>
            Preweek · <?php echo h($subjectName); ?>
          </h1>
          <p class="text-white/90 mt-2 mb-0">Watch assigned videos and access downloadable handouts.</p>
        </div>
        <a href="student_preweek.php" class="open-btn border-white/35 bg-white/10 text-white"><i class="bi bi-arrow-left"></i> Back to subjects</a>
      </div>
      <div class="hero-strip mt-4 px-4 py-2.5 text-sm flex flex-wrap gap-x-3 gap-y-1">
        <span class="font-semibold">Videos: <?php echo (int)$videosCount; ?></span>
        <span class="text-white/50">·</span>
        <span class="font-semibold">Handouts: <?php echo (int)$handoutsCount; ?></span>
      </div>
    </section>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-collection-play"></i> Learning Assets</h2>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <section class="dash-card dash-anim delay-2 overflow-hidden">
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
          <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-white/80 text-[#1665A0] border border-[#1665A0]/20"><?php echo (int)$videosCount; ?></span>
        </div>
        <div class="p-5 space-y-3">
          <?php if (!$videos || $videosCount === 0): ?>
            <div class="text-center text-gray-500 py-12">
              <i class="bi bi-inbox text-4xl block mb-2 opacity-60"></i>
              <div class="font-semibold text-gray-600">No preweek videos yet</div>
              <p class="text-sm mt-1">Check back later.</p>
            </div>
          <?php else: ?>
            <?php while ($v = mysqli_fetch_assoc($videos)): ?>
              <a href="<?php echo h($v['video_url']); ?>" target="_blank" rel="noopener"
                 class="preweek-item group block rounded-2xl p-4 border transition shadow-sm">
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

      <section class="dash-card dash-anim delay-3 overflow-hidden">
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
          <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-white/80 text-[#143D59] border border-[#143D59]/20"><?php echo (int)$handoutsCount; ?></span>
        </div>
        <div class="p-5 space-y-3">
          <?php if (!$handouts || $handoutsCount === 0): ?>
            <div class="text-center text-gray-500 py-12">
              <i class="bi bi-inbox text-4xl block mb-2 opacity-60"></i>
              <div class="font-semibold text-gray-600">No preweek handouts yet</div>
              <p class="text-sm mt-1">Check back later.</p>
            </div>
          <?php else: ?>
            <?php while ($h = mysqli_fetch_assoc($handouts)): ?>
              <div class="preweek-item rounded-2xl p-4 border transition shadow-sm">
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
                         class="open-btn">
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

