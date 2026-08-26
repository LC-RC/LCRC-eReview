<?php
/**
 * College Portal page header — compact (utility pages) or editorial (focus pages).
 *
 * @var string      $cpPageEyebrow
 * @var string      $cpPageTitle
 * @var string|null $cpPageSubtitle
 * @var string|null $cpPageIcon
 * @var string|null $cpPageBackHref
 * @var string|null $cpPageBackLabel
 * @var string|null $cpPageActionHtml
 * @var array|null  $cpPageStats
 * @var string      $cpPageStatsVariant  inline|cards (default inline)
 * @var string      $cpPageClass
 * @var string      $cpPageVariant   compact|editorial (default compact)
 */
$cpPageEyebrow = trim((string)($cpPageEyebrow ?? ''));
$cpPageTitle = trim((string)($cpPageTitle ?? ''));
$cpPageSubtitle = isset($cpPageSubtitle) ? trim((string)$cpPageSubtitle) : '';
$cpPageIcon = trim((string)($cpPageIcon ?? ''));
$cpPageBackHref = isset($cpPageBackHref) ? trim((string)$cpPageBackHref) : '';
$cpPageBackLabel = trim((string)($cpPageBackLabel ?? 'Back'));
$cpPageActionHtml = (string)($cpPageActionHtml ?? '');
$cpPageStats = is_array($cpPageStats ?? null) ? $cpPageStats : [];
$cpPageStatsVariant = trim((string)($cpPageStatsVariant ?? 'inline'));
$cpPageClass = trim((string)($cpPageClass ?? 'cp-anim delay-1'));
$cpPageVariant = trim((string)($cpPageVariant ?? 'compact'));
$isCompact = ($cpPageVariant !== 'editorial');
$headerClass = $isCompact ? 'cp-page-header--compact' : 'cp-page-header--editorial';
$titleClass = $isCompact ? 'cp-page-header__title' : 'cp-page-header__title cp-title-xl';
?>
<header class="cp-page-header cp-welcome-surface <?php echo h($headerClass); ?> <?php echo h($cpPageClass); ?>" aria-label="Page header">
  <?php if ($cpPageBackHref !== ''): ?>
    <a href="<?php echo h($cpPageBackHref); ?>" class="cp-page-header__back"><i class="bi bi-arrow-left" aria-hidden="true"></i> <?php echo h($cpPageBackLabel); ?></a>
  <?php endif; ?>
  <div class="cp-page-header__row">
    <div class="cp-page-header__main">
      <?php if (!$isCompact && $cpPageEyebrow !== ''): ?>
        <p class="cp-page-header__eyebrow cp-eyebrow"><?php echo h($cpPageEyebrow); ?></p>
      <?php endif; ?>
      <h1 class="<?php echo h($titleClass); ?>">
        <?php if (!$isCompact && $cpPageIcon !== ''): ?>
          <span class="cp-page-header__icon" aria-hidden="true"><i class="bi <?php echo h($cpPageIcon); ?>"></i></span>
        <?php endif; ?>
        <span><?php echo h($cpPageTitle); ?></span>
      </h1>
      <?php if ($cpPageSubtitle !== ''): ?>
        <p class="cp-page-header__subtitle<?php echo $isCompact ? ' cp-page-header__subtitle--compact' : ' cp-lead'; ?>"><?php echo $cpPageSubtitle; ?></p>
      <?php endif; ?>
    </div>
    <?php if ($cpPageActionHtml !== ''): ?>
      <div class="cp-page-header__actions"><?php echo $cpPageActionHtml; ?></div>
    <?php endif; ?>
  </div>
  <?php if ($cpPageStats !== []): ?>
    <?php if ($cpPageStatsVariant === 'cards'): ?>
      <div class="cp-page-header__stats cp-exam-stat-cards" aria-label="Summary">
        <?php foreach ($cpPageStats as $stat):
          $statTone = trim((string)($stat['tone'] ?? 'blue'));
          $statIcon = trim((string)($stat['icon'] ?? 'bi-grid'));
        ?>
          <div class="cp-exam-stat-card cp-exam-stat-card--<?php echo h($statTone); ?>">
            <span class="cp-exam-stat-card__icon" aria-hidden="true"><i class="bi <?php echo h($statIcon); ?>"></i></span>
            <div class="cp-exam-stat-card__body">
              <span class="cp-exam-stat-card__k"><?php echo h((string)($stat['label'] ?? '')); ?></span>
              <span class="cp-exam-stat-card__v"><?php echo h((string)($stat['value'] ?? '')); ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="cp-page-header__stats cp-summary-inline" aria-label="Summary">
        <?php foreach ($cpPageStats as $i => $stat): ?>
          <?php if ($i > 0): ?><span class="cp-summary-inline__sep" aria-hidden="true"></span><?php endif; ?>
          <div class="cp-summary-inline__item">
            <span class="cp-summary-inline__k"><?php echo h((string)($stat['label'] ?? '')); ?></span>
            <span class="cp-summary-inline__v"><?php echo h((string)($stat['value'] ?? '')); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</header>
