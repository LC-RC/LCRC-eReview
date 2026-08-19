<?php
/**
 * Compact premium College Portal page header (preferred over full gradient hero).
 *
 * @var string      $cpPageEyebrow    Optional small label above title
 * @var string      $cpPageTitle      Page title
 * @var string|null $cpPageSubtitle
 * @var string|null $cpPageIcon      Bootstrap icon (optional)
 * @var string|null $cpPageBackHref
 * @var string|null $cpPageBackLabel
 * @var string|null $cpPageActionHtml Raw HTML for primary action (escaped by caller)
 * @var array|null  $cpPageStats     Optional [['label'=>'','value'=>''], ...]
 * @var string      $cpPageClass
 */
$cpPageEyebrow = trim((string)($cpPageEyebrow ?? ''));
$cpPageTitle = trim((string)($cpPageTitle ?? ''));
$cpPageSubtitle = isset($cpPageSubtitle) ? trim((string)$cpPageSubtitle) : '';
$cpPageIcon = trim((string)($cpPageIcon ?? ''));
$cpPageBackHref = isset($cpPageBackHref) ? trim((string)$cpPageBackHref) : '';
$cpPageBackLabel = trim((string)($cpPageBackLabel ?? 'Back'));
$cpPageActionHtml = (string)($cpPageActionHtml ?? '');
$cpPageStats = is_array($cpPageStats ?? null) ? $cpPageStats : [];
$cpPageClass = trim((string)($cpPageClass ?? 'cp-anim delay-1'));
?>
<header class="cp-page-header <?php echo h($cpPageClass); ?>" aria-label="Page header">
  <?php if ($cpPageBackHref !== ''): ?>
    <a href="<?php echo h($cpPageBackHref); ?>" class="cp-page-header__back"><i class="bi bi-arrow-left" aria-hidden="true"></i> <?php echo h($cpPageBackLabel); ?></a>
  <?php endif; ?>
  <div class="cp-page-header__row">
    <div class="cp-page-header__main">
      <?php if ($cpPageEyebrow !== ''): ?>
        <p class="cp-page-header__eyebrow"><?php echo h($cpPageEyebrow); ?></p>
      <?php endif; ?>
      <h1 class="cp-page-header__title">
        <?php if ($cpPageIcon !== ''): ?>
          <span class="cp-page-header__icon" aria-hidden="true"><i class="bi <?php echo h($cpPageIcon); ?>"></i></span>
        <?php endif; ?>
        <span><?php echo h($cpPageTitle); ?></span>
      </h1>
      <?php if ($cpPageSubtitle !== ''): ?>
        <p class="cp-page-header__subtitle"><?php echo $cpPageSubtitle; ?></p>
      <?php endif; ?>
    </div>
    <?php if ($cpPageActionHtml !== ''): ?>
      <div class="cp-page-header__actions"><?php echo $cpPageActionHtml; ?></div>
    <?php endif; ?>
  </div>
  <?php if ($cpPageStats !== []): ?>
    <div class="cp-page-header__stats" aria-label="Summary">
      <?php foreach ($cpPageStats as $stat): ?>
        <div class="cp-stat-chip">
          <span class="cp-stat-chip__v"><?php echo h((string)($stat['value'] ?? '')); ?></span>
          <span class="cp-stat-chip__k"><?php echo h((string)($stat['label'] ?? '')); ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</header>
