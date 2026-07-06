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
    .student-dashboard-page {
      background:
        radial-gradient(ellipse 80% 50% at 50% -20%, rgba(31, 88, 195, 0.07) 0%, transparent 55%),
        radial-gradient(ellipse 60% 40% at 100% 0%, rgba(245, 158, 11, 0.05) 0%, transparent 45%),
        #f8fafc;
    }
    .student-hero {
      border-radius: 1rem;
      border: 1px solid rgba(255, 255, 255, 0.65);
      background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.2) inset,
        0 16px 40px -16px rgba(20, 61, 89, 0.45);
    }
    .student-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: inherit;
      background:
        radial-gradient(circle at 85% 15%, rgba(255, 255, 255, 0.14) 0%, transparent 42%),
        radial-gradient(circle at 10% 90%, rgba(255, 255, 255, 0.1) 0%, transparent 40%);
      pointer-events: none;
    }
    .hero-strip {
      background: rgba(255, 255, 255, 0.16);
      border: 1px solid rgba(255, 255, 255, 0.28);
      border-radius: 0.75rem;
      backdrop-filter: blur(8px);
    }
    .section-title {
      display: flex;
      align-items: center;
      gap: 0.625rem;
      margin: 0 0 1.125rem;
      padding: 0;
      border: none;
      background: transparent;
      color: #0f172a;
      font-size: 1.125rem;
      font-weight: 800;
      letter-spacing: -0.02em;
    }
    .section-title i {
      width: 2rem;
      height: 2rem;
      border-radius: 0.625rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #1F58C3, #3b82f6);
      color: #fff;
      font-size: 0.9rem;
      box-shadow: 0 4px 12px -4px rgba(31, 88, 195, 0.5);
    }
    .dash-card {
      border-radius: 1rem;
      border: 1px solid #e2e8f0;
      background: #fff;
      box-shadow: 0 4px 16px -8px rgba(15, 23, 42, 0.08);
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
    }
    .dash-card:hover {
      transform: translateY(-2px);
      border-color: #cbd5e1;
      box-shadow: 0 12px 28px -12px rgba(15, 23, 42, 0.12);
    }
    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp 0.55s ease-out forwards; }
    .delay-1 { animation-delay: 0.05s; }
    .delay-2 { animation-delay: 0.12s; }
    .delay-3 { animation-delay: 0.18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }

    /* Subject catalog — crisp, modern course cards */
    .subject-catalog-card {
      --subject-accent: #1665A0;
      --subject-accent-soft: rgba(22, 101, 160, 0.1);
      --subject-tint: rgba(22, 101, 160, 0.08);
      --subject-fallback: linear-gradient(145deg, #f4f9fe 0%, #eaf3fb 100%);
      position: relative;
      height: 100%;
      border-radius: 1rem;
      background: #fff;
      border: 1px solid #e2e8f0;
      box-shadow:
        0 1px 2px rgba(15, 23, 42, 0.04),
        0 4px 16px -6px rgba(15, 23, 42, 0.08);
      overflow: hidden;
      transition: transform 0.28s cubic-bezier(0.34, 1.2, 0.64, 1), box-shadow 0.28s ease, border-color 0.28s ease;
    }
    .subject-catalog-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--subject-accent), color-mix(in srgb, var(--subject-accent) 70%, white));
      z-index: 3;
    }
    .subject-catalog-card:hover {
      transform: translateY(-5px);
      border-color: color-mix(in srgb, var(--subject-accent) 35%, #e2e8f0);
      box-shadow:
        0 4px 8px rgba(15, 23, 42, 0.04),
        0 20px 40px -12px color-mix(in srgb, var(--subject-accent) 28%, transparent);
    }
    .subject-catalog-card__link {
      display: flex;
      flex-direction: column;
      height: 100%;
      min-height: 18rem;
      color: inherit;
      text-decoration: none;
    }
    .subject-catalog-card__link--locked { cursor: not-allowed; }
    .subject-catalog-card__media {
      position: relative;
      flex-shrink: 0;
      height: 148px;
      overflow: hidden;
      background: var(--subject-fallback);
    }
    .subject-catalog-card__media::after {
      content: '';
      position: absolute;
      inset: 0;
      z-index: 1;
      background: var(--subject-tint);
      pointer-events: none;
    }
    .subject-catalog-card__bg {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      filter: none;
      transition: transform 0.45s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }
    .subject-catalog-card:hover .subject-catalog-card__bg {
      transform: scale(1.06);
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
      padding: 1.5rem 1.15rem 1.1rem;
      background: #fff;
    }
    .subject-catalog-card__badge {
      position: absolute;
      top: -1.25rem;
      left: 1.1rem;
      z-index: 2;
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 0.75rem;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(145deg, var(--subject-accent), color-mix(in srgb, var(--subject-accent) 80%, #000));
      color: #fff;
      font-size: 1rem;
      box-shadow:
        0 4px 14px -4px color-mix(in srgb, var(--subject-accent) 55%, transparent),
        0 0 0 3px #fff;
    }
    .subject-catalog-card__title {
      margin: 0;
      font-size: 1.125rem;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -0.025em;
      line-height: 1.25;
    }
    .subject-catalog-card__desc {
      margin: 0.4rem 0 0;
      font-size: 0.8125rem;
      line-height: 1.5;
      font-weight: 500;
      color: #64748b;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      flex: 1;
    }
    .subject-catalog-card__footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin-top: auto;
      padding-top: 0.875rem;
    }
    .subject-catalog-card__lessons {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      min-width: 0;
      padding: 0.35rem 0.65rem;
      border-radius: 999px;
      background: var(--subject-accent-soft);
      font-size: 0.75rem;
      font-weight: 700;
      color: var(--subject-accent);
    }
    .subject-catalog-card__lessons i {
      font-size: 0.8rem;
      opacity: 0.85;
    }
    .subject-catalog-card__arrow {
      flex-shrink: 0;
      width: 2rem;
      height: 2rem;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 0.95rem;
      color: #64748b;
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      transition: transform 0.25s ease, background 0.25s ease, color 0.25s ease, border-color 0.25s ease;
    }
    .subject-catalog-card:hover .subject-catalog-card__arrow {
      transform: translateX(2px);
      background: var(--subject-accent);
      color: #fff;
      border-color: var(--subject-accent);
    }
    .subject-catalog-card.lms-locked-card .subject-catalog-card__arrow {
      background: #f1f5f9;
      color: #94a3b8;
    }
    .subject-catalog-card__link:focus-visible {
      outline: 2px solid var(--subject-accent);
      outline-offset: 3px;
      border-radius: 1rem;
    }

    .subject-catalog-card[data-subject-theme="afar"] {
      --subject-accent: #7c3aed;
      --subject-accent-soft: rgba(124, 58, 237, 0.1);
      --subject-tint: rgba(124, 58, 237, 0.1);
      --subject-fallback: linear-gradient(145deg, #faf8ff 0%, #f3eeff 100%);
    }
    .subject-catalog-card[data-subject-theme="aud-prob"] {
      --subject-accent: #2563eb;
      --subject-accent-soft: rgba(37, 99, 235, 0.1);
      --subject-tint: rgba(37, 99, 235, 0.1);
      --subject-fallback: linear-gradient(145deg, #f5f9ff 0%, #edf4ff 100%);
    }
    .subject-catalog-card[data-subject-theme="aud-theories"] {
      --subject-accent: #64748b;
      --subject-accent-soft: rgba(100, 116, 139, 0.1);
      --subject-tint: rgba(100, 116, 139, 0.1);
      --subject-fallback: linear-gradient(145deg, #f8fafc 0%, #f1f5f9 100%);
    }
    .subject-catalog-card[data-subject-theme="far"] {
      --subject-accent: #e11d48;
      --subject-accent-soft: rgba(225, 29, 72, 0.1);
      --subject-tint: rgba(225, 29, 72, 0.08);
      --subject-fallback: linear-gradient(145deg, #fff8f9 0%, #fff1f3 100%);
    }
    .subject-catalog-card[data-subject-theme="mas"] {
      --subject-accent: #059669;
      --subject-accent-soft: rgba(5, 150, 105, 0.1);
      --subject-tint: rgba(5, 150, 105, 0.1);
      --subject-fallback: linear-gradient(145deg, #f4fdf8 0%, #ecfdf5 100%);
    }
    .subject-catalog-card[data-subject-theme="rfbt"] {
      --subject-accent: #991b1b;
      --subject-accent-soft: rgba(153, 27, 27, 0.1);
      --subject-tint: rgba(153, 27, 27, 0.1);
      --subject-fallback: linear-gradient(145deg, #fff8f8 0%, #fef2f2 100%);
    }
    .subject-catalog-card[data-subject-theme="tax"] {
      --subject-accent: #d97706;
      --subject-accent-soft: rgba(217, 119, 6, 0.12);
      --subject-tint: rgba(217, 119, 6, 0.1);
      --subject-fallback: linear-gradient(145deg, #fffdf5 0%, #fffbeb 100%);
    }

    .subject-catalog-card.lms-locked-card {
      opacity: 0.78;
    }

    @media (prefers-reduced-motion: reduce) {
      .subject-catalog-card,
      .subject-catalog-card__bg,
      .subject-catalog-card__arrow {
        transition: none !important;
      }
      .subject-catalog-card:hover,
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

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 dash-anim delay-2 mb-4">
      <h2 class="section-title m-0"><i class="bi bi-grid-3x3-gap"></i> Subject Catalog</h2>
      <?php if ($totalSubjects > 0): ?>
        <p class="text-sm font-semibold text-slate-500 m-0"><?php echo (int)$totalSubjects; ?> subject<?php echo $totalSubjects === 1 ? '' : 's'; ?> · <?php echo (int)$totalLessons; ?> lessons</p>
      <?php endif; ?>
    </div>
    <section aria-label="Subjects list">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-6">
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
              <a href="student_subject?subject_id=<?php echo $subjectIdRow; ?>" class="subject-catalog-card__link">
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
