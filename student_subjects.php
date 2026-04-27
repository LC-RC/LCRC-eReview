<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/profile_avatar.php';
requireRole('student');

// Optional: enforce access_end check on every page load
$uid = (int)$_SESSION['user_id'];
$ur = mysqli_query($conn, "SELECT access_end FROM users WHERE user_id=" . $uid . " LIMIT 1");
$u = $ur ? mysqli_fetch_assoc($ur) : null;
if ($u && !empty($u['access_end']) && strtotime($u['access_end']) < time()) {
    $_SESSION['error'] = 'Your access has expired.';
    header('Location: index.php');
    exit;
}

$subjectsResult = mysqli_query($conn, "SELECT * FROM subjects WHERE status='active' ORDER BY subject_name ASC");
$lessonCounts = [];
$totalLessons = 0;
$lessonRes = @mysqli_query($conn, "SELECT subject_id, COUNT(*) AS c FROM lessons GROUP BY subject_id");
if ($lessonRes) {
    while ($lr = mysqli_fetch_assoc($lessonRes)) {
        $sid = (int)($lr['subject_id'] ?? 0);
        $cnt = (int)($lr['c'] ?? 0);
        $lessonCounts[$sid] = $cnt;
        $totalLessons += $cnt;
    }
    mysqli_free_result($lessonRes);
}

/** Theme slug for subject catalog card (matches fixed CPA subject list). */
function ereview_subject_card_theme(string $subjectName): string
{
    $k = strtolower(trim($subjectName));
    $map = [
        'afar' => 'afar',
        'aud prob' => 'aud-prob',
        'aud theories' => 'aud-theories',
        'far' => 'far',
        'mas' => 'mas',
        'rfbt' => 'rfbt',
        'tax' => 'tax',
    ];

    return $map[$k] ?? 'default';
}

$pageTitle = 'Subjects';
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
    .subject-catalog-card {
      position: relative;
      display: block;
      border-radius: .75rem;
      border: 1px solid rgba(15, 23, 42, 0.12);
      box-shadow: 0 12px 32px -20px rgba(20, 61, 89, 0.55);
      overflow: hidden;
      transition:
        transform 0.38s cubic-bezier(0.22, 1, 0.36, 1),
        box-shadow 0.38s cubic-bezier(0.22, 1, 0.36, 1),
        border-color 0.28s ease;
      background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
      will-change: transform;
    }
    .subject-catalog-card:hover {
      transform: translateY(-6px) scale(1.012);
      border-color: rgba(255, 255, 255, 0.28);
      box-shadow:
        0 28px 48px -22px rgba(15, 23, 42, 0.42),
        0 0 0 1px rgba(255, 255, 255, 0.12) inset;
    }
    .subject-catalog-card__fill {
      position: relative;
      width: 100%;
      display: flex;
      flex-direction: column;
      aspect-ratio: 2.55 / 1;
      min-height: 11rem;
    }
    @supports not (aspect-ratio: 1) {
      .subject-catalog-card__fill { min-height: 13.5rem; }
    }
    .subject-catalog-card__bg {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      transform: scale(1.045);
      transform-origin: center center;
      transition: transform 0.5s cubic-bezier(0.22, 1, 0.36, 1);
    }
    .subject-catalog-card:hover .subject-catalog-card__bg {
      transform: scale(1.08);
    }
    .subject-catalog-card__bg-fallback {
      position: absolute;
      inset: 0;
      background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
    }
    .subject-catalog-card__inner {
      position: relative;
      z-index: 2;
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      min-height: 100%;
      height: 100%;
      box-sizing: border-box;
      padding: .75rem 1rem .85rem;
    }
    .subject-catalog-card__top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: .75rem;
      flex-shrink: 0;
    }
    .subject-catalog-card__icon {
      display: flex;
      height: 2.35rem;
      width: 2.35rem;
      align-items: center;
      justify-content: center;
      border-radius: .65rem;
      background: rgba(255, 255, 255, 0.22);
      border: 1px solid rgba(255, 255, 255, 0.4);
      color: #fff;
      box-shadow: 0 4px 14px -6px rgba(2, 6, 23, 0.45);
      transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.3s ease, border-color 0.3s ease, background 0.3s ease;
    }
    .subject-catalog-card:hover .subject-catalog-card__icon {
      transform: scale(1.06);
    }
    .subject-catalog-card__menu {
      color: rgba(255, 255, 255, 0.88);
      padding: .2rem;
      border-radius: .35rem;
      border: 0;
      background: transparent;
      cursor: default;
      transition: color 0.25s ease, transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
    }
    .subject-catalog-card:hover .subject-catalog-card__menu {
      color: #fff;
      transform: scale(1.05);
    }
    .subject-catalog-card__main {
      display: flex;
      flex-direction: column;
      gap: .4rem;
      flex-shrink: 0;
      margin-top: .35rem;
    }
    .subject-catalog-card__title {
      margin: 0;
      font-size: clamp(1.2rem, 2.6vw, 1.55rem);
      font-weight: 800;
      color: #fff;
      letter-spacing: -.02em;
      line-height: 1.15;
      text-shadow:
        0 1px 2px rgba(2, 6, 23, 0.85),
        0 2px 16px rgba(2, 6, 23, 0.65),
        0 0 1px rgba(2, 6, 23, 0.9);
    }
    .subject-catalog-card__desc {
      margin: .1rem 0 0;
      font-size: .875rem;
      line-height: 1.45;
      font-weight: 500;
      color: rgba(255, 255, 255, 0.96);
      text-shadow:
        0 1px 2px rgba(2, 6, 23, 0.9),
        0 1px 12px rgba(2, 6, 23, 0.75);
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .subject-catalog-card__footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .75rem;
      margin-top: .65rem;
      padding-top: .75rem;
      border-top: 1px solid rgba(255, 255, 255, 0.22);
      font-size: .875rem;
      font-weight: 600;
      color: rgba(255, 255, 255, 0.96);
      text-shadow: 0 1px 3px rgba(2, 6, 23, 0.85);
    }
    .subject-catalog-card__lessons {
      display: flex;
      align-items: center;
      gap: 0.45rem;
    }
    .subject-catalog-card__lessons i {
      font-size: 1.05rem;
      transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1), color 0.25s ease;
    }
    .subject-catalog-card:hover .subject-catalog-card__lessons i {
      transform: translateY(-1px);
    }
    .subject-catalog-card .subject-btn {
      flex-shrink: 0;
      text-decoration: none;
      border-radius: 9999px;
      border: 1px solid rgba(255, 255, 255, 0.52);
      background: rgba(255, 255, 255, 0.12);
      color: #fff;
      font-weight: 700;
      padding: 0.45rem 0.95rem;
      font-size: 0.8rem;
      backdrop-filter: blur(14px) saturate(140%);
      -webkit-backdrop-filter: blur(14px) saturate(140%);
      box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.38),
        0 6px 18px -8px rgba(2, 6, 23, 0.55);
      transition:
        background 0.3s cubic-bezier(0.22, 1, 0.36, 1),
        border-color 0.3s ease,
        box-shadow 0.3s ease,
        transform 0.3s cubic-bezier(0.22, 1, 0.36, 1),
        filter 0.25s ease;
    }
    .subject-catalog-card .subject-btn:hover {
      background: rgba(255, 255, 255, 0.3);
      border-color: rgba(255, 255, 255, 0.85);
      transform: translateY(-2px) scale(1.04);
      filter: brightness(1.05);
      box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.55),
        0 14px 28px -10px rgba(2, 6, 23, 0.55);
    }
    .subject-catalog-card .subject-btn:focus-visible {
      outline: 2px solid rgba(191, 219, 254, 0.95);
      outline-offset: 2px;
    }
    @supports not ((-webkit-backdrop-filter: blur(1px)) or (backdrop-filter: blur(1px))) {
      .subject-catalog-card .subject-btn {
        background: rgba(22, 101, 160, 0.55);
        border-color: rgba(255, 255, 255, 0.35);
      }
      .subject-catalog-card .subject-btn:hover {
        background: rgba(22, 101, 160, 0.72);
      }
    }
    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }

    /* Per-subject icon + menu tint (fixed catalog); fallbacks when no cover photo */
    .subject-catalog-card[data-subject-theme="afar"] .subject-catalog-card__bg-fallback {
      background: linear-gradient(125deg, #6d28d9 0%, #a855f7 45%, #c026d3 100%);
    }
    .subject-catalog-card[data-subject-theme="afar"] .subject-catalog-card__icon {
      background: rgba(168, 85, 247, 0.42);
      border-color: rgba(232, 121, 249, 0.65);
      color: #faf5ff;
    }
    .subject-catalog-card[data-subject-theme="afar"] .subject-catalog-card__menu,
    .subject-catalog-card[data-subject-theme="afar"] .subject-catalog-card__lessons i {
      color: #f5d0fe;
    }

    .subject-catalog-card[data-subject-theme="aud-prob"] .subject-catalog-card__bg-fallback {
      background: linear-gradient(125deg, #0c4a6e 0%, #0e7490 42%, #155e75 100%);
    }
    .subject-catalog-card[data-subject-theme="aud-prob"] .subject-catalog-card__icon {
      background: rgba(14, 165, 233, 0.35);
      border-color: rgba(103, 232, 249, 0.55);
      color: #ecfeff;
    }
    .subject-catalog-card[data-subject-theme="aud-prob"] .subject-catalog-card__menu,
    .subject-catalog-card[data-subject-theme="aud-prob"] .subject-catalog-card__lessons i {
      color: #a5f3fc;
    }

    .subject-catalog-card[data-subject-theme="aud-theories"] .subject-catalog-card__bg-fallback {
      background: linear-gradient(125deg, #334155 0%, #475569 50%, #64748b 100%);
    }
    .subject-catalog-card[data-subject-theme="aud-theories"] .subject-catalog-card__icon {
      background: rgba(148, 163, 184, 0.38);
      border-color: rgba(203, 213, 225, 0.55);
      color: #f8fafc;
    }
    .subject-catalog-card[data-subject-theme="aud-theories"] .subject-catalog-card__menu,
    .subject-catalog-card[data-subject-theme="aud-theories"] .subject-catalog-card__lessons i {
      color: #e2e8f0;
    }

    .subject-catalog-card[data-subject-theme="far"] .subject-catalog-card__bg-fallback {
      background: linear-gradient(125deg, #9f1239 0%, #be123c 38%, #c2410c 100%);
    }
    .subject-catalog-card[data-subject-theme="far"] .subject-catalog-card__icon {
      background: rgba(254, 202, 202, 0.35);
      border-color: rgba(252, 165, 165, 0.6);
      color: #fff7ed;
    }
    .subject-catalog-card[data-subject-theme="far"] .subject-catalog-card__menu,
    .subject-catalog-card[data-subject-theme="far"] .subject-catalog-card__lessons i {
      color: #fecdd3;
    }

    .subject-catalog-card[data-subject-theme="mas"] .subject-catalog-card__bg-fallback {
      background: linear-gradient(125deg, #3f6212 0%, #4d7c0f 40%, #57534e 100%);
    }
    .subject-catalog-card[data-subject-theme="mas"] .subject-catalog-card__icon {
      background: rgba(134, 239, 172, 0.32);
      border-color: rgba(187, 247, 208, 0.55);
      color: #f0fdf4;
    }
    .subject-catalog-card[data-subject-theme="mas"] .subject-catalog-card__menu,
    .subject-catalog-card[data-subject-theme="mas"] .subject-catalog-card__lessons i {
      color: #bbf7d0;
    }

    .subject-catalog-card[data-subject-theme="rfbt"] .subject-catalog-card__bg-fallback {
      background: linear-gradient(125deg, #431407 0%, #7c2d12 45%, #450a0a 100%);
    }
    .subject-catalog-card[data-subject-theme="rfbt"] .subject-catalog-card__icon {
      background: rgba(185, 28, 28, 0.42);
      border-color: rgba(252, 165, 165, 0.45);
      color: #fef2f2;
    }
    .subject-catalog-card[data-subject-theme="rfbt"] .subject-catalog-card__menu,
    .subject-catalog-card[data-subject-theme="rfbt"] .subject-catalog-card__lessons i {
      color: #fecaca;
    }

    .subject-catalog-card[data-subject-theme="tax"] .subject-catalog-card__bg-fallback {
      background: linear-gradient(125deg, #9a3412 0%, #c2410c 42%, #b45309 100%);
    }
    .subject-catalog-card[data-subject-theme="tax"] .subject-catalog-card__icon {
      background: rgba(251, 191, 36, 0.38);
      border-color: rgba(253, 224, 71, 0.55);
      color: #fffbeb;
    }
    .subject-catalog-card[data-subject-theme="tax"] .subject-catalog-card__menu,
    .subject-catalog-card[data-subject-theme="tax"] .subject-catalog-card__lessons i {
      color: #fde68a;
    }

    @media (prefers-reduced-motion: reduce) {
      .subject-catalog-card,
      .subject-catalog-card__bg,
      .subject-catalog-card__icon,
      .subject-catalog-card__menu,
      .subject-catalog-card .subject-btn,
      .subject-catalog-card__lessons i {
        transition: none !important;
      }
      .subject-catalog-card:hover {
        transform: none;
      }
      .subject-catalog-card:hover .subject-catalog-card__bg {
        transform: scale(1.045);
      }
    }
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
            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 border border-white/30"><i class="bi bi-book"></i></span>
            Subjects
          </h1>
          <p class="text-white/90 mt-2 mb-0 max-w-2xl">Choose a subject to open lessons, videos, handouts, and quizzes.</p>
        </div>
      </div>
      <div class="hero-strip mt-4 px-4 py-2.5 text-sm flex flex-wrap gap-x-3 gap-y-1">
        <?php $totalSubjects = $subjectsResult ? mysqli_num_rows($subjectsResult) : 0; ?>
        <span class="font-semibold">Active subjects: <?php echo (int)$totalSubjects; ?></span>
        <span class="text-white/50">·</span>
        <span class="font-semibold">Total lessons: <?php echo (int)$totalLessons; ?></span>
      </div>
    </section>

    <?php if (isset($_SESSION['error'])): ?>
      <div class="dash-anim delay-1 mb-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?php echo h($_SESSION['error']); ?></span>
        <?php unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-grid-3x3-gap"></i> Subject Catalog</h2>
    <section aria-label="Subjects list">
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        <?php if ($subjectsResult && mysqli_num_rows($subjectsResult) > 0): ?>
          <?php mysqli_data_seek($subjectsResult, 0); ?>
          <?php while ($s = mysqli_fetch_assoc($subjectsResult)): ?>
            <?php $cnt = (int)($lessonCounts[(int)$s['subject_id']] ?? 0); ?>
            <?php
              $coverImgSrc = '';
              $rawCover = isset($s['subject_cover']) ? trim((string)$s['subject_cover']) : '';
              if ($rawCover !== '') {
                  $coverImgSrc = ereview_avatar_img_src($rawCover);
              }
              $cardTheme = ereview_subject_card_theme((string)($s['subject_name'] ?? ''));
            ?>
            <article class="subject-catalog-card dash-anim delay-2" data-subject-theme="<?php echo h($cardTheme); ?>">
              <div class="subject-catalog-card__fill">
                <?php if ($coverImgSrc !== ''): ?>
                  <img src="<?php echo h($coverImgSrc); ?>" alt="" class="subject-catalog-card__bg" width="640" height="400" loading="lazy" decoding="async">
                <?php else: ?>
                  <div class="subject-catalog-card__bg-fallback" aria-hidden="true"></div>
                <?php endif; ?>
                <div class="subject-catalog-card__inner">
                  <div class="subject-catalog-card__top">
                    <span class="subject-catalog-card__icon" aria-hidden="true">
                      <i class="bi bi-journal-bookmark text-lg"></i>
                    </span>
                    <span class="subject-catalog-card__menu" aria-hidden="true">
                      <i class="bi bi-three-dots-vertical text-lg"></i>
                    </span>
                  </div>
                  <div class="subject-catalog-card__main">
                    <h2 class="subject-catalog-card__title"><?php echo h($s['subject_name']); ?></h2>
                    <p class="subject-catalog-card__desc"><?php echo h($s['description'] ?: 'Focused coverage of key exam topics for this subject.'); ?></p>
                    <div class="subject-catalog-card__footer">
                      <div class="subject-catalog-card__lessons opacity-95">
                        <i class="bi bi-file-text" aria-hidden="true"></i>
                        <span><?php echo $cnt; ?> lesson<?php echo $cnt === 1 ? '' : 's'; ?></span>
                      </div>
                      <a href="student_subject.php?subject_id=<?php echo (int)$s['subject_id']; ?>" class="subject-btn">
                        <span>Open subject</span>
                        <i class="bi bi-arrow-right-circle" aria-hidden="true"></i>
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            </article>
          <?php endwhile; ?>
        <?php else: ?>
          <div class="col-span-full">
            <div class="dash-card dash-anim delay-3 p-12 text-center text-[#143D59]/80">
              <i class="bi bi-inbox text-5xl mb-3 text-[#1665A0]" aria-hidden="true"></i>
              <p class="text-lg font-semibold m-0">No subjects available yet.</p>
              <p class="text-sm mt-1 mb-0">Check back later or contact your administrator for enrollment assistance.</p>
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
