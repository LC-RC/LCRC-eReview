<?php
/**
 * Pre-week admin: compact trail (Dashboard → Pre-week → ...).
 * Set before include:
 *   $preweekNavStep = 'list' | 'lectures' | 'materials'
 *   For lectures/materials: $preweekNavUnitId (int), $preweekNavUnitTitle (string)
 *   For materials: $preweekNavTopicId (int), $preweekNavTopicTitle (string)
 *
 * Theme is CSS-driven via html[data-admin-theme] - do not hardcode dark/light surfaces here.
 */
if (empty($preweekNavStep) || !in_array($preweekNavStep, ['list', 'lectures', 'materials'], true)) {
    return;
}
$__pws = $preweekNavStep;
$__uid = isset($preweekNavUnitId) ? (int)$preweekNavUnitId : 0;
$__ut = isset($preweekNavUnitTitle) ? trim((string)$preweekNavUnitTitle) : '';
if ($__ut === '') {
    $__ut = 'Pre-week';
}
$__tid = isset($preweekNavTopicId) ? (int)$preweekNavTopicId : 0;
$__tt = isset($preweekNavTopicTitle) ? trim((string)$preweekNavTopicTitle) : '';
if ($__tt === '') {
    $__tt = 'Lecture';
}

$lecturesUrl = $__uid > 0 ? 'admin_preweek_topics?preweek_unit_id=' . $__uid : 'admin_preweek';
?>
<nav class="admin-preweek-context-nav mb-5 rounded-xl px-4 py-2.5" aria-label="Pre-week location">
  <div class="flex flex-wrap items-center gap-y-1 text-sm leading-snug">
    <a href="admin_dashboard" class="admin-preweek-context-nav__link">Dashboard</a>
    <span class="admin-preweek-context-nav__sep px-1.5 select-none" aria-hidden="true">/</span>
    <?php if ($__pws === 'list'): ?>
      <span class="admin-preweek-context-nav__current" aria-current="page">Pre-week</span>
    <?php else: ?>
      <a href="admin_preweek" class="admin-preweek-context-nav__link">Pre-week</a>
      <span class="admin-preweek-context-nav__sep px-1.5 select-none" aria-hidden="true">/</span>
      <?php if ($__pws === 'lectures'): ?>
        <span class="admin-preweek-context-nav__current" aria-current="page"><?php echo h($__ut); ?> · Lectures</span>
      <?php else: ?>
        <a href="<?php echo h($lecturesUrl); ?>" class="admin-preweek-context-nav__link"><?php echo h($__ut); ?> · Lectures</a>
        <span class="admin-preweek-context-nav__sep px-1.5 select-none" aria-hidden="true">/</span>
        <span class="admin-preweek-context-nav__current" aria-current="page"><?php echo h($__tt); ?> · Materials</span>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</nav>
