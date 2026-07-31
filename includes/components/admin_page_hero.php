<?php
/**
 * Premium glass admin page hero.
 * Optional: $adminHeroIcon, $adminHeroTitle, $adminHeroSubtitle, $adminHeroActions (HTML)
 */
$adminHeroIcon = $adminHeroIcon ?? 'speedometer2';
if (strpos($adminHeroIcon, 'bi-') !== 0) {
    $adminHeroIcon = 'bi-' . ltrim($adminHeroIcon, 'bi-');
}
$adminHeroTitle = $adminHeroTitle ?? ($pageTitle ?? 'Admin');
$adminHeroSubtitle = $adminHeroSubtitle ?? '';
$adminHeroActions = $adminHeroActions ?? '';
?>
<section class="quiz-admin-hero page-hero admin-glass-hero" aria-labelledby="admin-page-title">
  <?php if (!empty($adminBreadcrumbs) && is_array($adminBreadcrumbs)): ?>
    <?php include __DIR__ . '/../admin_breadcrumb.php'; ?>
  <?php endif; ?>
  <div class="admin-page-header">
    <div class="min-w-0">
      <h1 id="admin-page-title" class="admin-page-header__title flex flex-wrap items-center gap-3">
        <span class="quiz-admin-hero-icon" aria-hidden="true"><i class="bi <?php echo h($adminHeroIcon); ?>"></i></span>
        <span><?php echo h($adminHeroTitle); ?></span>
      </h1>
      <?php if ($adminHeroSubtitle !== ''): ?>
        <p class="admin-page-header__subtitle"><?php echo h($adminHeroSubtitle); ?></p>
      <?php endif; ?>
    </div>
    <?php if ($adminHeroActions !== ''): ?>
      <div class="admin-page-header__actions"><?php echo $adminHeroActions; ?></div>
    <?php endif; ?>
  </div>
</section>
