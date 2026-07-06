<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/profile_avatar.php';
require_once __DIR__ . '/includes/student_content_access.php';
requireRole('student');

sca_ensure_schema($conn);
sca_enforce_student_session($conn);

$uid = (int)$_SESSION['user_id'];

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
  <?php require_once __DIR__ . '/includes/student_lock_styles.php'; ?>
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
    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }

    /* Subject catalog cards — modern course-card layout */
    .subject-catalog-card {
      --subject-accent: #5b9fd4;
      --subject-overlay: rgba(91, 159, 212, 0.12);
      --subject-fallback: linear-gradient(135deg, #f4f9fe 0%, #eaf3fb 100%);
      position: relative;
      height: 100%;
      border-radius: 10px;
      background: #fff;
      border: 1px solid #e8edf2;
      border-top: 3px solid var(--subject-accent);
      box-shadow: 0 1px 5px rgba(15, 23, 42, 0.05);
      overflow: hidden;
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
    }
    .subject-catalog-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 20px -10px rgba(15, 23, 42, 0.1);
      border-color: #e2e8f0;
    }
    .subject-catalog-card__link {
      display: flex;
      flex-direction: column;
      height: 100%;
      min-height: 17.5rem;
      color: inherit;
      text-decoration: none;
    }
    .subject-catalog-card__link--locked {
      cursor: not-allowed;
    }
    .subject-catalog-card__media {
      position: relative;
      flex-shrink: 0;
      height: 140px;
      overflow: hidden;
      background: var(--subject-fallback);
    }
    .subject-catalog-card__media::after {
      content: '';
      position: absolute;
      inset: 0;
      z-index: 1;
      background: var(--subject-overlay);
      pointer-events: none;
    }
    .subject-catalog-card__bg {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      transition: transform 0.35s ease;
    }
    .subject-catalog-card:hover .subject-catalog-card__bg {
      transform: scale(1.03);
    }
    .subject-catalog-card__bg-fallback {
      width: 100%;
      height: 100%;
      background: var(--subject-fallback);
    }
    .subject-catalog-card__body {
      position: relative;
      flex: 1;
      display: flex;
      flex-direction: column;
      padding: 1.35rem 1.1rem 1rem;
      background: #fff;
    }
    .subject-catalog-card__badge {
      position: absolute;
      top: -1.15rem;
      left: 1rem;
      z-index: 2;
      width: 2.15rem;
      height: 2.15rem;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--subject-accent);
      color: #fff;
      font-size: 0.88rem;
      box-shadow: 0 2px 8px -3px rgba(15, 23, 42, 0.18);
      border: 2px solid #fff;
    }
    .subject-catalog-card__title {
      margin: 0;
      font-size: 1.0625rem;
      font-weight: 800;
      color: #1e3a5f;
      letter-spacing: -0.02em;
      line-height: 1.2;
    }
    .subject-catalog-card__desc {
      margin: 0.35rem 0 0;
      font-size: 0.8125rem;
      line-height: 1.45;
      font-weight: 500;
      color: #7b8da0;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
      flex: 1;
    }
    .subject-catalog-card__footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin-top: 0.85rem;
      padding-top: 0.75rem;
      border-top: 1px solid #f3f6f9;
      font-size: 0.8125rem;
      font-weight: 600;
      color: #8b9aab;
    }
    .subject-catalog-card__lessons {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      min-width: 0;
    }
    .subject-catalog-card__lessons i {
      color: #b0bec8;
      font-size: 0.95rem;
    }
    .subject-catalog-card__arrow {
      flex-shrink: 0;
      font-size: 1.05rem;
      color: #5a6f85;
      transition: transform 0.22s ease, color 0.22s ease;
    }
    .subject-catalog-card:hover .subject-catalog-card__arrow {
      transform: translateX(3px);
      color: var(--subject-accent);
    }
    .subject-catalog-card.lms-locked-card .subject-catalog-card__arrow {
      color: #94a3b8;
    }
    .subject-catalog-card__link:focus-visible {
      outline: 2px solid var(--subject-accent);
      outline-offset: 2px;
      border-radius: 12px;
    }

    .subject-catalog-card[data-subject-theme="afar"] {
      --subject-accent: #a78bfa;
      --subject-overlay: rgba(167, 139, 250, 0.11);
      --subject-fallback: linear-gradient(135deg, #f5f0ff 0%, #ede4ff 100%);
    }
    .subject-catalog-card[data-subject-theme="aud-prob"] {
      --subject-accent: #60a5fa;
      --subject-overlay: rgba(96, 165, 250, 0.11);
      --subject-fallback: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    }
    .subject-catalog-card[data-subject-theme="aud-theories"] {
      --subject-accent: #7b8fa3;
      --subject-overlay: rgba(123, 143, 163, 0.12);
      --subject-fallback: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    }
    .subject-catalog-card[data-subject-theme="far"] {
      --subject-accent: #f47288;
      --subject-overlay: rgba(244, 114, 136, 0.1);
      --subject-fallback: linear-gradient(135deg, #fff1f3 0%, #ffe4e8 100%);
    }
    .subject-catalog-card[data-subject-theme="mas"] {
      --subject-accent: #5cb87a;
      --subject-overlay: rgba(92, 184, 122, 0.1);
      --subject-fallback: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    }
    .subject-catalog-card[data-subject-theme="rfbt"] {
      --subject-accent: #b85c5c;
      --subject-overlay: rgba(184, 92, 92, 0.11);
      --subject-fallback: linear-gradient(135deg, #fef2f2 0%, #fde8e8 100%);
    }
    .subject-catalog-card[data-subject-theme="tax"] {
      --subject-accent: #e8b339;
      --subject-overlay: rgba(232, 179, 57, 0.12);
      --subject-fallback: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    }

    @media (prefers-reduced-motion: reduce) {
      .subject-catalog-card,
      .subject-catalog-card__bg,
      .subject-catalog-card__arrow {
        transition: none !important;
      }
      .subject-catalog-card:hover {
        transform: none;
      }
      .subject-catalog-card:hover .subject-catalog-card__bg {
        transform: none;
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
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-5">
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
              $subjectIdRow = (int)($s['subject_id'] ?? 0);
              $subjectOpen = sca_has_access($conn, $uid, 'subject', $subjectIdRow);
            ?>
            <article class="subject-catalog-card dash-anim delay-2<?php echo $subjectOpen ? '' : ' lms-locked-card'; ?>" data-subject-theme="<?php echo h($cardTheme); ?>">
              <?php if (!$subjectOpen): ?><span class="lms-lock-overlay lms-lock-badge"><i class="bi bi-lock-fill"></i> Locked</span><?php endif; ?>
              <?php if ($subjectOpen): ?>
              <a href="student_subject.php?subject_id=<?php echo $subjectIdRow; ?>" class="subject-catalog-card__link">
              <?php else: ?>
              <div class="subject-catalog-card__link subject-catalog-card__link--locked" aria-disabled="true">
              <?php endif; ?>
                <div class="subject-catalog-card__media">
                  <?php if ($coverImgSrc !== ''): ?>
                    <img src="<?php echo h($coverImgSrc); ?>" alt="" class="subject-catalog-card__bg" width="640" height="280" loading="lazy" decoding="async">
                  <?php else: ?>
                    <div class="subject-catalog-card__bg-fallback" aria-hidden="true"></div>
                  <?php endif; ?>
                </div>
                <div class="subject-catalog-card__body">
                  <span class="subject-catalog-card__badge" aria-hidden="true">
                    <i class="bi bi-journal-bookmark"></i>
                  </span>
                  <h2 class="subject-catalog-card__title"><?php echo h($s['subject_name']); ?></h2>
                  <p class="subject-catalog-card__desc"><?php echo h($s['description'] ?: 'Focused coverage of key exam topics for this subject.'); ?></p>
                  <div class="subject-catalog-card__footer">
                    <div class="subject-catalog-card__lessons">
                      <i class="bi bi-file-text" aria-hidden="true"></i>
                      <span><?php echo $cnt; ?> lesson<?php echo $cnt === 1 ? '' : 's'; ?></span>
                    </div>
                    <i class="bi bi-arrow-right subject-catalog-card__arrow" aria-hidden="true"></i>
                  </div>
                </div>
              <?php echo $subjectOpen ? '</a>' : '</div>'; ?>
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
