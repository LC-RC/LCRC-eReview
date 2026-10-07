<?php
/**
 * Premium glass admin page hero (same layout in light + dark).
 * Optional: $adminHeroIcon, $adminHeroTitle, $adminHeroSubtitle,
 *           $adminHeroEyebrow (plain text), $adminHeroMeta (trusted HTML),
 *           $adminHeroActions (trusted HTML),
 *           $adminHeroTint (blue|cyan|violet|indigo|teal|amber|emerald)
 */
$adminHeroIcon = $adminHeroIcon ?? 'speedometer2';
if (strpos($adminHeroIcon, 'bi-') !== 0) {
    $adminHeroIcon = 'bi-' . ltrim($adminHeroIcon, 'bi-');
}
$adminHeroTitle = $adminHeroTitle ?? ($pageTitle ?? 'Admin');
$adminHeroEyebrow = trim((string) ($adminHeroEyebrow ?? ''));
$adminHeroSubtitle = $adminHeroSubtitle ?? '';
$adminHeroMeta = $adminHeroMeta ?? '';
$adminHeroActions = $adminHeroActions ?? '';
$adminHeroTint = trim((string) ($adminHeroTint ?? ''));
if ($adminHeroTint === '') {
    $heroScript = function_exists('ereview_page_basename') ? ereview_page_basename() : basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $heroScript = preg_replace('/\.php$/i', '', (string) $heroScript);
    if (str_starts_with($heroScript, 'admin_commerce')) {
        $adminHeroTint = 'emerald';
    } elseif (str_starts_with($heroScript, 'admin_preboard') || str_starts_with($heroScript, 'admin_preweek')) {
        $adminHeroTint = 'violet';
    } elseif ($heroScript === 'admin_student_access') {
        $adminHeroTint = 'indigo';
    } elseif ($heroScript === 'admin_support_analytics') {
        $adminHeroTint = 'teal';
    } elseif ($heroScript === 'admin_student_live') {
        $adminHeroTint = 'cyan';
    } elseif (in_array($heroScript, ['admin_quiz_monitor', 'admin_quizzes', 'admin_quiz_questions', 'admin_quiz_attempt_review', 'admin_question_sort', 'admin_test_bank'], true)) {
        $adminHeroTint = 'violet';
    } elseif (in_array($heroScript, ['admin_subjects', 'admin_materials', 'admin_lessons', 'admin_modules', 'admin_videos', 'admin_handouts'], true)) {
        $adminHeroTint = 'violet';
    } else {
        $adminHeroTint = 'blue';
    }
}

$heroWashes = [
    'blue' => 'admin-hero-wash admin-hero-wash--blue',
    'cyan' => 'admin-hero-wash admin-hero-wash--cyan',
    'violet' => 'admin-hero-wash admin-hero-wash--violet',
    'indigo' => 'admin-hero-wash admin-hero-wash--indigo',
    'teal' => 'admin-hero-wash admin-hero-wash--teal',
    'amber' => 'admin-hero-wash admin-hero-wash--amber',
    'emerald' => 'admin-hero-wash admin-hero-wash--emerald',
];
$heroIcons = [
    'blue' => 'admin-hero-icon-tile admin-hero-icon-tile--blue',
    'cyan' => 'admin-hero-icon-tile admin-hero-icon-tile--cyan',
    'violet' => 'admin-hero-icon-tile admin-hero-icon-tile--violet',
    'indigo' => 'admin-hero-icon-tile admin-hero-icon-tile--indigo',
    'teal' => 'admin-hero-icon-tile admin-hero-icon-tile--teal',
    'amber' => 'admin-hero-icon-tile admin-hero-icon-tile--amber',
    'emerald' => 'admin-hero-icon-tile admin-hero-icon-tile--emerald',
];
$heroOrbs = [
    'blue' => ['bg-blue-400/15', 'bg-cyan-400/12', 'bg-indigo-400/15'],
    'cyan' => ['bg-cyan-400/15', 'bg-blue-400/12', 'bg-sky-400/15'],
    'violet' => ['bg-violet-400/15', 'bg-indigo-400/12', 'bg-blue-400/15'],
    'indigo' => ['bg-indigo-400/15', 'bg-blue-400/12', 'bg-violet-400/15'],
    'teal' => ['bg-teal-400/15', 'bg-blue-400/12', 'bg-cyan-400/15'],
    'amber' => ['bg-amber-400/15', 'bg-violet-400/12', 'bg-orange-400/12'],
    'emerald' => ['bg-emerald-400/15', 'bg-blue-400/12', 'bg-teal-400/12'],
];
$heroWash = $heroWashes[$adminHeroTint] ?? $heroWashes['blue'];
$heroIcon = $heroIcons[$adminHeroTint] ?? $heroIcons['blue'];
$heroOrb = $heroOrbs[$adminHeroTint] ?? $heroOrbs['blue'];
$professorFeatureHero = !empty($professorFeatureHero);
$professorPageChrome = !empty($professorPageChrome);
$adminBackHref = trim((string) ($adminBackHref ?? ''));
$adminBackLabel = trim((string) ($adminBackLabel ?? ''));
?>
<section class="quiz-admin-hero page-hero admin-glass-hero<?php echo $professorFeatureHero ? ' prof-feature-hero' : ''; ?>" aria-labelledby="admin-page-title">
  <?php if (!empty($adminBreadcrumbs) && is_array($adminBreadcrumbs)): ?>
    <?php include __DIR__ . '/../admin_breadcrumb.php'; ?>
  <?php endif; ?>
  <?php if ($professorPageChrome && $adminBackHref !== '' && $adminBackLabel !== ''): ?>
    <a class="admin-back-link" href="<?php echo h($adminBackHref); ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> <?php echo h($adminBackLabel); ?></a>
  <?php endif; ?>
  <div class="relative overflow-hidden rounded-[16px] px-4 py-2.5 md:px-5 <?php echo h($heroWash); ?>">
    <div class="admin-page-header relative z-[1] flex flex-wrap items-center justify-between gap-2 md:gap-3">
      <div class="admin-page-header__lead min-w-0 flex-1">
        <?php if ($adminHeroEyebrow !== ''): ?>
          <p class="admin-page-header__eyebrow"><?php echo h($adminHeroEyebrow); ?></p>
        <?php endif; ?>
        <h1 id="admin-page-title" class="admin-page-header__title flex flex-wrap items-center gap-2.5 m-0 font-bold tracking-tight">
          <span class="quiz-admin-hero-icon inline-flex h-11 w-11 items-center justify-center rounded-xl text-base <?php echo h($heroIcon); ?>" aria-hidden="true"><i class="bi <?php echo h($adminHeroIcon); ?>"></i></span>
          <span class="admin-page-header__title-text"><?php echo h($adminHeroTitle); ?></span>
        </h1>
        <?php if ($adminHeroSubtitle !== ''): ?>
          <p class="admin-page-header__subtitle mt-0.5 mb-0 max-w-2xl font-medium pl-[3.4rem]"><?php echo h($adminHeroSubtitle); ?></p>
        <?php endif; ?>
        <?php if ($adminHeroMeta !== '' && !$professorFeatureHero): ?>
          <div class="admin-page-header__meta mt-2 flex flex-wrap gap-2"><?php echo $adminHeroMeta; ?></div>
        <?php endif; ?>
      </div>
      <?php if ($adminHeroMeta !== '' && $professorFeatureHero): ?>
        <div class="admin-page-header__meta prof-hero-statwrap"><?php echo $adminHeroMeta; ?></div>
      <?php endif; ?>
      <?php if ($adminHeroActions !== ''): ?>
        <div class="admin-page-header__actions flex flex-wrap items-center justify-end gap-2 shrink-0"><?php echo $adminHeroActions; ?></div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php
if (!$professorPageChrome) {
  $adminBackHref = trim((string) ($adminBackHref ?? ''));
  $adminBackLabel = trim((string) ($adminBackLabel ?? ''));
  if ($adminBackHref !== '' && $adminBackLabel !== ''):
?>
  <a class="admin-back-link" href="<?php echo h($adminBackHref); ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> <?php echo h($adminBackLabel); ?></a>
<?php
  endif;
}
$adminHeroEyebrow = '';
$adminBackHref = '';
$adminBackLabel = '';
$professorFeatureHero = false;
$adminHeroMeta = '';
$adminHeroActions = '';
?>
