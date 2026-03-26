<?php
require_once 'auth.php';
requireRole('student');

// Keep dashboard pages read-only: do not create tables during render.
$preboardsTableExists = false;
$tb = @mysqli_query($conn, "SHOW TABLES LIKE 'preboards_subjects'");
if ($tb && mysqli_num_rows($tb) > 0) {
    $preboardsTableExists = true;
}
$preboardsSetsTableExists = false;
$preboardsSetsHasPublished = false;
$preboardsSetsHasSubjectId = false;
$ts = @mysqli_query($conn, "SHOW TABLES LIKE 'preboards_sets'");
if ($ts && mysqli_num_rows($ts) > 0) {
    $preboardsSetsTableExists = true;
    $colPub = @mysqli_query($conn, "SHOW COLUMNS FROM preboards_sets LIKE 'is_published'");
    $preboardsSetsHasPublished = (bool)($colPub && mysqli_num_rows($colPub) > 0);
    $colSid = @mysqli_query($conn, "SHOW COLUMNS FROM preboards_sets LIKE 'preboards_subject_id'");
    $preboardsSetsHasSubjectId = (bool)($colSid && mysqli_num_rows($colSid) > 0);
}

// Optional: enforce access_end check on every page load (same as student_subjects.php)
$uid = (int)$_SESSION['user_id'];
$ur = mysqli_query($conn, "SELECT access_end FROM users WHERE user_id=" . $uid . " LIMIT 1");
$u = $ur ? mysqli_fetch_assoc($ur) : null;
if ($u && !empty($u['access_end']) && strtotime($u['access_end']) < time()) {
    $_SESSION['error'] = 'Your access has expired.';
    header('Location: index.php');
    exit;
}

$subjectsResult = $preboardsTableExists
    ? mysqli_query($conn, "SELECT * FROM preboards_subjects WHERE status='active' ORDER BY subject_name ASC")
    : false;
$preboardsSetsCount = 0;
if ($preboardsSetsTableExists && $preboardsTableExists) {
    $countSql = $preboardsSetsHasPublished
        ? "SELECT COUNT(*) AS c FROM preboards_sets WHERE is_published=1"
        : "SELECT COUNT(*) AS c FROM preboards_sets";
    $psr = @mysqli_query($conn, $countSql);
    if ($psr && ($row = mysqli_fetch_assoc($psr))) {
        $preboardsSetsCount = (int)($row['c'] ?? 0);
    }
}
$pageTitle = 'Preboards';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .student-dashboard-page { background: linear-gradient(180deg, #eef5fc 0%, #e4f0fa 45%, #ebf4fc 100%); }
    .student-hero {
      border-radius: 0.75rem;
      border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
      box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22);
    }
    .hero-strip {
      background: rgba(255,255,255,0.14);
      border: 1px solid rgba(255,255,255,0.24);
      border-radius: 0.62rem;
    }
    .section-title {
      display: flex; align-items: center; gap: .5rem;
      margin: 0 0 .85rem; padding: .45rem .65rem;
      border: 1px solid #d8e8f6; border-radius: .62rem;
      background: linear-gradient(180deg,#f4f9fe 0%,#fff 100%);
      color: #143D59; font-size: 1.03rem; font-weight: 800;
    }
    .section-title i {
      width: 1.55rem; height: 1.55rem; border-radius: .45rem;
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #b9daf2; background: #e8f2fa; color: #1665A0; font-size: .83rem;
    }
    .dash-card {
      border-radius: .75rem;
      border: 1px solid rgba(22,101,160,.18);
      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 60%);
      box-shadow: 0 10px 28px -22px rgba(20,61,89,.55), 0 1px 0 rgba(255,255,255,.85) inset;
      transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease, background-color .22s ease;
    }
    .dash-card:hover {
      transform: translateY(-2px);
      border-color: rgba(22,101,160,.32);
      background-color: #fdfeff;
      box-shadow: 0 20px 34px -24px rgba(20,61,89,.35);
    }
    .subject-btn {
      display: inline-flex; align-items: center; gap: .45rem;
      border-radius: .55rem; border: 1px solid #1665A0;
      background: #1665A0; color: #fff; font-weight: 700;
      padding: .5rem .85rem; font-size: .81rem; transition: all .2s ease;
    }
    .subject-btn:hover { background: #145a8f; border-color: #145a8f; transform: translateY(-1px); }
    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
  </style>
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page min-h-full pb-8">
    <section class="student-hero dash-anim delay-1 relative overflow-hidden mb-6 px-6 py-7 text-white">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3">
            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 border border-white/30"><i class="bi bi-clipboard-check"></i></span>
            Preboards
          </h1>
          <p class="text-white/90 mt-2 mb-0 max-w-2xl">Open a preboard subject and practice with real set-based assessments.</p>
        </div>
      </div>
      <div class="hero-strip mt-4 px-4 py-2.5 text-sm flex flex-wrap gap-x-3 gap-y-1">
        <?php $totalSubjects = $subjectsResult ? mysqli_num_rows($subjectsResult) : 0; ?>
        <span class="font-semibold">Active subjects: <?php echo (int)$totalSubjects; ?></span>
        <span class="text-white/50">·</span>
        <span class="font-semibold">Published sets: <?php echo (int)$preboardsSetsCount; ?></span>
      </div>
    </section>

    <?php if (isset($_SESSION['error'])): ?>
      <div class="dash-anim delay-1 mb-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?php echo h($_SESSION['error']); ?></span>
        <?php unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-grid-3x3-gap"></i> Preboard Subjects</h2>
    <section aria-label="Preboards subjects list">
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        <?php if ($subjectsResult && mysqli_num_rows($subjectsResult) > 0): ?>
          <?php mysqli_data_seek($subjectsResult, 0); ?>
          <?php while ($s = mysqli_fetch_assoc($subjectsResult)): ?>
            <?php
              $setsCount = 0;
              $sid = (int)($s['preboards_subject_id'] ?? 0);
              if ($sid > 0 && $preboardsSetsTableExists && $preboardsSetsHasSubjectId) {
                  $subjectCountSql = $preboardsSetsHasPublished
                      ? "SELECT COUNT(*) AS c FROM preboards_sets WHERE preboards_subject_id={$sid} AND is_published=1"
                      : "SELECT COUNT(*) AS c FROM preboards_sets WHERE preboards_subject_id={$sid}";
                  $sr = @mysqli_query($conn, $subjectCountSql);
                  if ($sr && ($srow = mysqli_fetch_assoc($sr))) $setsCount = (int)($srow['c'] ?? 0);
              }
            ?>
            <article class="dash-card dash-anim delay-2 flex flex-col overflow-hidden">
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
                <span class="text-[11px] font-semibold uppercase tracking-wide text-[#143D59]/60">Ready</span>
              </div>
              <div class="px-5 pb-4 flex-1">
                <p class="text-sm text-[#143D59]/80 m-0">
                  <?php echo h($s['description'] ?: 'Preboards preparation and materials for this subject.'); ?>
                </p>
              </div>
              <div class="px-5 pb-5 flex items-center justify-between gap-3 text-sm border-t border-[#d6e8f7] bg-white/70">
                <div class="flex items-center gap-2 text-[#143D59]/75">
                  <i class="bi bi-info-circle" aria-hidden="true"></i>
                  <span><?php echo (int)$setsCount; ?> set<?php echo $setsCount === 1 ? '' : 's'; ?></span>
                </div>
                <a href="student_preboards_view.php?preboards_subject_id=<?php echo (int)$s['preboards_subject_id']; ?>" class="subject-btn">
                  <span>Open</span>
                  <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                </a>
              </div>
            </article>
          <?php endwhile; ?>
        <?php else: ?>
          <div class="col-span-full">
            <div class="dash-card dash-anim delay-3 p-12 text-center text-[#143D59]/80">
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

