<?php
/**
 * Premium glass admin page hero (same layout in light + dark).
 * Optional: $adminHeroIcon, $adminHeroTitle, $adminHeroSubtitle,
 *           $adminHeroMeta (trusted HTML), $adminHeroActions (trusted HTML)
 */
$adminHeroIcon = $adminHeroIcon ?? 'speedometer2';
if (strpos($adminHeroIcon, 'bi-') !== 0) {
    $adminHeroIcon = 'bi-' . ltrim($adminHeroIcon, 'bi-');
}
$adminHeroTitle = $adminHeroTitle ?? ($pageTitle ?? 'Admin');
$adminHeroSubtitle = $adminHeroSubtitle ?? '';
$adminHeroMeta = $adminHeroMeta ?? '';
$adminHeroActions = $adminHeroActions ?? '';
?>
<section class="quiz-admin-hero page-hero admin-glass-hero" aria-labelledby="admin-page-title">
  <?php if (!empty($adminBreadcrumbs) && is_array($adminBreadcrumbs)): ?>
    <?php include __DIR__ . '/../admin_breadcrumb.php'; ?>
  <?php endif; ?>
  <div class="admin-page-header">
    <div class="admin-page-header__lead min-w-0">
      <h1 id="admin-page-title" class="admin-page-header__title">
        <span class="quiz-admin-hero-icon" aria-hidden="true"><i class="bi <?php echo h($adminHeroIcon); ?>"></i></span>
        <span class="admin-page-header__title-text"><?php echo h($adminHeroTitle); ?></span>
      </h1>
      <?php if ($adminHeroSubtitle !== ''): ?>
        <p class="admin-page-header__subtitle"><?php echo h($adminHeroSubtitle); ?></p>
      <?php endif; ?>
      <?php if ($adminHeroMeta !== ''): ?>
        <div class="admin-page-header__meta"><?php echo $adminHeroMeta; ?></div>
      <?php endif; ?>
    </div>
    <?php if ($adminHeroActions !== ''): ?>
      <div class="admin-page-header__actions"><?php echo $adminHeroActions; ?></div>
    <?php endif; ?>
  </div>
</section>
