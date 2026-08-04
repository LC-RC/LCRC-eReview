<?php
/**
 * Glass student page hero (same layout in light + dark).
 * Optional: $studentHeroIcon, $studentHeroTitle, $studentHeroSubtitle,
 *           $studentHeroMeta (trusted HTML), $studentHeroActions (trusted HTML)
 */
$studentHeroIcon = $studentHeroIcon ?? 'speedometer2';
if (strpos($studentHeroIcon, 'bi-') !== 0) {
    $studentHeroIcon = 'bi-' . ltrim($studentHeroIcon, 'bi-');
}
$studentHeroTitle = $studentHeroTitle ?? ($pageTitle ?? 'Student');
$studentHeroSubtitle = $studentHeroSubtitle ?? '';
$studentHeroMeta = $studentHeroMeta ?? '';
$studentHeroActions = $studentHeroActions ?? '';
?>
<section class="student-page-hero student-glass-hero" aria-labelledby="student-page-title">
  <div class="student-page-header">
    <div class="student-page-header__lead min-w-0">
      <h1 id="student-page-title" class="student-page-header__title student-hero__title">
        <span class="student-page-hero-icon student-hero__icon" aria-hidden="true"><i class="bi <?php echo h($studentHeroIcon); ?>"></i></span>
        <span class="student-page-header__title-text"><?php echo h($studentHeroTitle); ?></span>
      </h1>
      <?php if ($studentHeroSubtitle !== ''): ?>
        <p class="student-page-header__subtitle student-hero__lede"><?php echo h($studentHeroSubtitle); ?></p>
      <?php endif; ?>
      <?php if ($studentHeroMeta !== ''): ?>
        <div class="student-page-header__meta"><?php echo $studentHeroMeta; ?></div>
      <?php endif; ?>
    </div>
    <?php if ($studentHeroActions !== ''): ?>
      <div class="student-page-header__actions"><?php echo $studentHeroActions; ?></div>
    <?php endif; ?>
  </div>
</section>
