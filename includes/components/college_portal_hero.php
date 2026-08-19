<?php
/**
 * Informational College Portal hero — no duplicate sidebar navigation.
 *
 * @var string      $cpHeroIcon       Bootstrap icon class (e.g. bi-journal-text)
 * @var string      $cpHeroTitle      Page title
 * @var string|null $cpHeroSubtitle   Optional description
 * @var array|null  $cpHeroStats      Optional [['label'=>'','value'=>''], ...]
 * @var string|null $cpHeroBackHref   Page-specific back link (not sidebar dupes)
 * @var string|null $cpHeroBackLabel
 * @var string      $cpHeroClass      Extra section classes
 * @var string      $cpHeroAnimClass  Animation classes
 * @var array|null  $cpHeroBadge      Optional ['class'=>'','icon'=>'','text'=>'']
 */
$cpHeroIcon = trim((string)($cpHeroIcon ?? 'bi-mortarboard'));
$cpHeroTitle = trim((string)($cpHeroTitle ?? ''));
$cpHeroSubtitle = isset($cpHeroSubtitle) ? trim((string)$cpHeroSubtitle) : '';
$cpHeroStats = is_array($cpHeroStats ?? null) ? $cpHeroStats : [];
$cpHeroBackHref = isset($cpHeroBackHref) ? trim((string)$cpHeroBackHref) : '';
$cpHeroBackLabel = trim((string)($cpHeroBackLabel ?? 'Back'));
$cpHeroClass = trim((string)($cpHeroClass ?? ''));
$cpHeroAnimClass = trim((string)($cpHeroAnimClass ?? 'cp-anim delay-1'));
$cpHeroBadge = is_array($cpHeroBadge ?? null) ? $cpHeroBadge : null;
?>
<section class="cp-hero <?php echo h($cpHeroAnimClass); ?> <?php echo h($cpHeroClass); ?>" aria-label="Page header">
  <div class="cp-hero__inner">
    <?php if ($cpHeroBackHref !== ''): ?>
      <a href="<?php echo h($cpHeroBackHref); ?>" class="cp-hero__back"><i class="bi bi-arrow-left"></i> <?php echo h($cpHeroBackLabel); ?></a>
    <?php endif; ?>
    <div class="cp-hero__layout">
      <div class="cp-hero__main">
        <h1 class="cp-hero__title">
          <span class="cp-hero__icon" aria-hidden="true"><i class="bi <?php echo h($cpHeroIcon); ?>"></i></span>
          <span><?php echo h($cpHeroTitle); ?></span>
        </h1>
        <?php if ($cpHeroSubtitle !== ''): ?>
          <p class="cp-hero__subtitle"><?php echo $cpHeroSubtitle; ?></p>
        <?php endif; ?>
      </div>
      <?php if ($cpHeroBadge !== null && ($cpHeroBadge['text'] ?? '') !== ''): ?>
        <span class="cp-status <?php echo h((string)($cpHeroBadge['class'] ?? '')); ?>">
          <?php if (!empty($cpHeroBadge['icon'])): ?><i class="bi <?php echo h((string)$cpHeroBadge['icon']); ?>"></i><?php endif; ?>
          <?php echo h((string)($cpHeroBadge['text'] ?? '')); ?>
        </span>
      <?php elseif ($cpHeroStats !== []): ?>
        <div class="cp-hero__stats" aria-label="Summary">
          <?php foreach ($cpHeroStats as $stat): ?>
            <div class="cp-hero__stat">
              <div class="cp-hero__stat-k"><?php echo h((string)($stat['label'] ?? '')); ?></div>
              <div class="cp-hero__stat-v"><?php echo h((string)($stat['value'] ?? '')); ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
