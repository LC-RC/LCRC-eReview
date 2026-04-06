<?php
/**
 * Pre-week admin: compact trail (Dashboard → Pre-week → …). No extra instructional copy here.
 * Set before include:
 *   $preweekNavStep = 'list' | 'lectures' | 'materials'
 *   For lectures/materials: $preweekNavUnitId (int), $preweekNavUnitTitle (string)
 *   For materials: $preweekNavTopicId (int), $preweekNavTopicTitle (string)
 * Optional: $preweekNavTheme = 'light' | 'dark' (default: list=light, else dark)
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

$__theme = $preweekNavTheme ?? ($__pws === 'list' ? 'light' : 'dark');
$__wrap = $__theme === 'light'
    ? 'admin-preweek-context-nav admin-preweek-context-nav--light mb-5 rounded-xl border border-gray-200 bg-white px-4 py-2.5 shadow-sm'
    : 'admin-preweek-context-nav admin-preweek-context-nav--dark mb-5 rounded-xl border border-white/10 bg-[#141414] px-4 py-2.5';
$__link = $__theme === 'light'
    ? 'text-[#012970] hover:underline font-medium no-underline'
    : 'text-amber-200/90 hover:text-amber-100 hover:underline font-medium no-underline';
$__current = $__theme === 'light'
    ? 'text-gray-800 font-semibold'
    : 'text-gray-100 font-semibold';
$__sep = '<span class="' . ($__theme === 'light' ? 'text-gray-300' : 'text-gray-600') . ' px-1.5 select-none" aria-hidden="true">/</span>';

$lecturesUrl = $__uid > 0 ? 'admin_preweek_topics.php?preweek_unit_id=' . $__uid : 'admin_preweek.php';
?>
<nav class="<?php echo h($__wrap); ?>" aria-label="Pre-week location">
  <div class="flex flex-wrap items-center gap-y-1 text-sm leading-snug">
    <a href="admin_dashboard.php" class="<?php echo h($__link); ?>">Dashboard</a>
    <?php echo $__sep; ?>
    <?php if ($__pws === 'list'): ?>
      <span class="<?php echo h($__current); ?>" aria-current="page">Pre-week</span>
    <?php else: ?>
      <a href="admin_preweek.php" class="<?php echo h($__link); ?>">Pre-week</a>
      <?php echo $__sep; ?>
      <?php if ($__pws === 'lectures'): ?>
        <span class="<?php echo h($__current); ?>" aria-current="page"><?php echo h($__ut); ?> · Lectures</span>
      <?php else: ?>
        <a href="<?php echo h($lecturesUrl); ?>" class="<?php echo h($__link); ?>"><?php echo h($__ut); ?> · Lectures</a>
        <?php echo $__sep; ?>
        <span class="<?php echo h($__current); ?>" aria-current="page"><?php echo h($__tt); ?> · Materials</span>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</nav>
