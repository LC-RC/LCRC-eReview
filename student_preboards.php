<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_content_access.php';
requireRole('student');

sca_ensure_schema($conn);
sca_enforce_student_session($conn);

$uid = (int)$_SESSION['user_id'];
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

$subjectsResult = $preboardsTableExists
    ? mysqli_query($conn, "SELECT * FROM preboards_subjects WHERE status='active' ORDER BY subject_name ASC")
    : false;
$totalSubjects = ($subjectsResult && mysqli_num_rows($subjectsResult) > 0) ? (int)mysqli_num_rows($subjectsResult) : 0;
$pageTitle = 'Preboards';

function ereview_preboard_card_theme(string $subjectName): string
{
    $n = strtolower(trim($subjectName));
    if ($n === '') {
        return 'default';
    }
    if (strpos($n, 'afar') !== false) {
        return 'afar';
    }
    if (strpos($n, 'far') !== false) {
        return 'far';
    }
    if (strpos($n, 'mas') !== false) {
        return 'mas';
    }
    if (strpos($n, 'rfbt') !== false || strpos($n, 'law') !== false) {
        return 'rfbt';
    }
    if (strpos($n, 'tax') !== false) {
        return 'tax';
    }
    if (strpos($n, 'aud') !== false) {
        return 'aud';
    }
    return 'default';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <?php require_once __DIR__ . '/includes/student_lock_styles.php'; ?>
  <style>
    .student-dashboard-page { background: transparent; }
    html[data-student-theme="light"] .student-dashboard-page {
      background: linear-gradient(180deg, #eef5fc 0%, #e4f0fa 45%, #ebf4fc 100%);
    }
    .section-title {
      display: flex; align-items: center; gap: .625rem;
      margin: 0; padding: .65rem .95rem;
      border: 1px solid var(--student-border, #d8e8f6); border-radius: .75rem;
      background: linear-gradient(180deg, var(--student-surface-2, #f4f9fe) 0%, var(--student-glass, #fff) 100%);
      color: var(--student-text, #143D59); font-size: 1.03rem; font-weight: 800;
    }
    .section-title i {
      width: 2rem; height: 2rem; border-radius: .55rem;
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid var(--student-border, #b9daf2);
      background: var(--student-primary-soft, #e8f2fa);
      color: var(--student-primary, #1665A0); font-size: .9rem;
    }
    .preboard-catalog-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1.1rem;
    }
    @media (min-width: 640px) {
      .preboard-catalog-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (min-width: 1100px) {
      .preboard-catalog-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.25rem; }
    }
    .preboard-catalog-card {
      --pb-accent: #1665A0;
      --pb-accent-soft: rgba(22, 101, 160, 0.12);
      --pb-glow: rgba(22, 101, 160, 0.28);
      position: relative;
      height: 100%;
      border-radius: 1rem;
      border: 1px solid rgba(22, 101, 160, 0.14);
      background:
        radial-gradient(ellipse 90% 70% at 100% 0%, var(--pb-accent-soft), transparent 55%),
        linear-gradient(165deg, #ffffff 0%, #f7fbff 52%, #f2f8fd 100%);
      box-shadow:
        0 12px 28px -22px rgba(20, 61, 89, 0.45),
        inset 0 1px 0 rgba(255, 255, 255, 0.9);
      overflow: hidden;
      transition: transform 0.24s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.24s ease, border-color 0.2s ease;
    }
    .preboard-catalog-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--pb-accent), color-mix(in srgb, var(--pb-accent) 55%, #7dd3fc));
      z-index: 2;
    }
    .preboard-catalog-card[data-subject-theme="afar"] { --pb-accent: #0e7490; --pb-accent-soft: rgba(14, 116, 144, 0.14); --pb-glow: rgba(14, 116, 144, 0.3); }
    .preboard-catalog-card[data-subject-theme="far"] { --pb-accent: #1665A0; --pb-accent-soft: rgba(22, 101, 160, 0.14); --pb-glow: rgba(22, 101, 160, 0.3); }
    .preboard-catalog-card[data-subject-theme="mas"] { --pb-accent: #047857; --pb-accent-soft: rgba(4, 120, 87, 0.12); --pb-glow: rgba(4, 120, 87, 0.28); }
    .preboard-catalog-card[data-subject-theme="rfbt"] { --pb-accent: #b45309; --pb-accent-soft: rgba(242, 176, 30, 0.16); --pb-glow: rgba(180, 83, 9, 0.24); }
    .preboard-catalog-card[data-subject-theme="tax"] { --pb-accent: #9a3412; --pb-accent-soft: rgba(194, 65, 12, 0.12); --pb-glow: rgba(154, 52, 18, 0.24); }
    .preboard-catalog-card[data-subject-theme="aud"] { --pb-accent: #1d4ed8; --pb-accent-soft: rgba(29, 78, 216, 0.12); --pb-glow: rgba(29, 78, 216, 0.26); }
    .preboard-catalog-card:hover {
      transform: translateY(-4px);
      border-color: color-mix(in srgb, var(--pb-accent) 42%, transparent);
      box-shadow:
        0 22px 40px -24px var(--pb-glow),
        0 10px 24px -20px rgba(20, 61, 89, 0.3),
        inset 0 1px 0 rgba(255, 255, 255, 0.95);
    }
    .preboard-catalog-card__link {
      display: flex;
      flex-direction: column;
      height: 100%;
      min-height: 12.5rem;
      padding: 1.25rem 1.25rem 1.1rem;
      color: inherit;
      text-decoration: none;
    }
    .preboard-catalog-card__link--locked { cursor: not-allowed; }
    .preboard-catalog-card__top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin-bottom: 1rem;
    }
    .preboard-catalog-card__icon {
      width: 2.75rem;
      height: 2.75rem;
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 0.85rem;
      background: linear-gradient(145deg, var(--pb-accent), color-mix(in srgb, var(--pb-accent) 72%, #0f2740));
      color: #fff;
      font-size: 1.15rem;
      border: 1px solid rgba(255, 255, 255, 0.22);
      box-shadow:
        0 12px 22px -12px var(--pb-glow),
        inset 0 1px 0 rgba(255, 255, 255, 0.28);
      transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
    }
    .preboard-catalog-card:hover .preboard-catalog-card__icon {
      transform: scale(1.06) rotate(-3deg);
    }
    .preboard-catalog-card__status {
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      padding: 0.28rem 0.6rem;
      border-radius: 9999px;
      font-size: 0.65rem;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--pb-accent);
      background: var(--pb-accent-soft);
      border: 1px solid color-mix(in srgb, var(--pb-accent) 22%, transparent);
    }
    .preboard-catalog-card__status--locked {
      color: #64748b;
      background: rgba(148, 163, 184, 0.16);
      border-color: rgba(148, 163, 184, 0.35);
    }
    .preboard-catalog-card__title {
      margin: 0;
      font-size: 1.2rem;
      font-weight: 800;
      letter-spacing: -0.025em;
      line-height: 1.25;
      color: var(--student-text, #143D59);
    }
    .preboard-catalog-card__eyebrow {
      margin: 0.3rem 0 0;
      font-size: 0.68rem;
      font-weight: 750;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--student-text-muted, #64748b);
    }
    .preboard-catalog-card__desc {
      margin: 0.55rem 0 0;
      font-size: 0.84rem;
      line-height: 1.5;
      font-weight: 500;
      color: var(--student-text-secondary, #475569);
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      flex: 1;
    }
    .preboard-catalog-card__footer {
      margin-top: 1.1rem;
      padding-top: 0.9rem;
      border-top: 1px solid rgba(22, 101, 160, 0.1);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
    }
    .preboard-catalog-card__meta {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      font-size: 0.8rem;
      font-weight: 700;
      color: var(--student-text-secondary, #475569);
    }
    .preboard-catalog-card__meta i { color: var(--pb-accent); opacity: 0.9; }
    .preboard-catalog-card__cta {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.82rem;
      font-weight: 750;
      color: var(--pb-accent);
    }
    .preboard-catalog-card__cta i {
      transition: transform 0.22s cubic-bezier(0.22, 1, 0.36, 1);
    }
    .preboard-catalog-card:hover .preboard-catalog-card__cta i {
      transform: translateX(4px);
    }
    .preboard-catalog-card.lms-locked-card {
      opacity: 0.88;
    }
    .preboard-catalog-empty {
      grid-column: 1 / -1;
      border-radius: 1rem;
      border: 1px solid rgba(22, 101, 160, 0.14);
      background: linear-gradient(165deg, #ffffff 0%, #f7fbff 100%);
      padding: 2.75rem 1.5rem;
      text-align: center;
      color: var(--student-text-secondary, #475569);
    }
    .preboard-catalog-empty i {
      font-size: 2.75rem;
      color: #1665A0;
      margin-bottom: 0.65rem;
      display: block;
    }
    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
      .preboard-catalog-card,
      .preboard-catalog-card__icon,
      .preboard-catalog-card__cta i { transition: none !important; }
      .preboard-catalog-card:hover { transform: none; }
      .preboard-catalog-card:hover .preboard-catalog-card__icon,
      .preboard-catalog-card:hover .preboard-catalog-card__cta i { transform: none; }
    }
  </style>
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page min-h-full pb-8">
    <section class="student-hero student-hero--glass dash-anim delay-1 relative overflow-hidden mb-7">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="student-hero__title text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3">
            <span class="student-hero__icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span>
            Preboards
          </h1>
          <p class="student-hero__lede mt-2 mb-0">Open a preboard subject and practice with real set-based assessments.</p>
        </div>
      </div>
    </section>

    <?php if (isset($_SESSION['error'])): ?>
      <div class="dash-anim delay-1 mb-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?php echo h($_SESSION['error']); ?></span>
        <?php unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 dash-anim delay-2 mb-5">
      <h2 class="section-title"><i class="bi bi-grid-3x3-gap"></i> Preboard Subjects</h2>
      <?php if ($totalSubjects > 0): ?>
        <p class="text-sm font-semibold text-slate-500 m-0"><?php echo (int)$totalSubjects; ?> subject<?php echo $totalSubjects === 1 ? '' : 's'; ?></p>
      <?php endif; ?>
    </div>

    <section aria-label="Preboards subjects list">
      <div class="preboard-catalog-grid">
        <?php if ($subjectsResult && $totalSubjects > 0): ?>
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
                  if ($sr && ($srow = mysqli_fetch_assoc($sr))) {
                      $setsCount = (int)($srow['c'] ?? 0);
                  }
              }
              $pbsOpen = sca_preboard_subject_has_any_access($conn, $uid, $sid);
              $cardTheme = ereview_preboard_card_theme((string)($s['subject_name'] ?? ''));
              $openHref = 'student_preboards_view?preboards_subject_id=' . $sid;
            ?>
            <article
              class="preboard-catalog-card dash-anim delay-2<?php echo $pbsOpen ? '' : ' lms-locked-card'; ?>"
              data-subject-theme="<?php echo h($cardTheme); ?>"
            >
              <?php if (!$pbsOpen): ?>
                <span class="lms-lock-overlay lms-lock-badge"><i class="bi bi-lock-fill"></i> Locked</span>
              <?php endif; ?>
              <?php if ($pbsOpen): ?>
              <a href="<?php echo h($openHref); ?>" class="preboard-catalog-card__link">
              <?php else: ?>
              <div class="preboard-catalog-card__link preboard-catalog-card__link--locked" aria-disabled="true">
              <?php endif; ?>
                <div class="preboard-catalog-card__top">
                  <span class="preboard-catalog-card__icon" aria-hidden="true">
                    <i class="bi bi-clipboard-check"></i>
                  </span>
                  <span class="preboard-catalog-card__status<?php echo $pbsOpen ? '' : ' preboard-catalog-card__status--locked'; ?>">
                    <?php echo $pbsOpen ? 'Ready' : 'Locked'; ?>
                  </span>
                </div>
                <h2 class="preboard-catalog-card__title"><?php echo h($s['subject_name']); ?></h2>
                <p class="preboard-catalog-card__eyebrow">Preboards subject</p>
                <p class="preboard-catalog-card__desc">
                  <?php echo h($s['description'] ?: 'Preboards preparation and materials for this subject.'); ?>
                </p>
                <div class="preboard-catalog-card__footer">
                  <span class="preboard-catalog-card__meta">
                    <i class="bi bi-collection" aria-hidden="true"></i>
                    <?php echo (int)$setsCount; ?> set<?php echo $setsCount === 1 ? '' : 's'; ?>
                  </span>
                  <?php if ($pbsOpen): ?>
                  <span class="preboard-catalog-card__cta">
                    Open <i class="bi bi-arrow-right" aria-hidden="true"></i>
                  </span>
                  <?php else: ?>
                  <span class="preboard-catalog-card__cta" style="color:#94a3b8;">
                    Locked <i class="bi bi-lock-fill" aria-hidden="true"></i>
                  </span>
                  <?php endif; ?>
                </div>
              <?php echo $pbsOpen ? '</a>' : '</div>'; ?>
            </article>
          <?php endwhile; ?>
        <?php else: ?>
          <div class="preboard-catalog-empty dash-anim delay-3">
            <i class="bi bi-inbox" aria-hidden="true"></i>
            <p class="text-lg font-semibold m-0 text-[#143D59]">No preboards subjects available yet.</p>
            <p class="text-sm mt-1 mb-0">Check back later or contact your administrator.</p>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</main>
</div>
</body>
</html>
