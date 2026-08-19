<?php
/**
 * Examination edit step tabs (config → questions → review).
 *
 * @var string $examType
 * @var int $sourceId
 * @var string $activeStep config|questions|review
 */
$activeStep = $activeStep ?? 'config';
$steps = [
    'config' => ['1. Configuration', examination_domain_edit_url($examType, $sourceId, 'config')],
    'questions' => ['2. Questions', examination_domain_edit_url($examType, $sourceId, 'questions')],
    'review' => ['3. Review / Publish', examination_domain_edit_url($examType, $sourceId, 'review')],
];
?>
<nav class="students-view-tabs mb-3" aria-label="Examination edit steps">
  <?php foreach ($steps as $key => $meta): ?>
    <?php if ($key === $activeStep): ?>
      <span class="students-view-tab is-active"><?php echo h($meta[0]); ?></span>
    <?php elseif ($sourceId > 0): ?>
      <a href="<?php echo h($meta[1]); ?>" class="students-view-tab"><?php echo h($meta[0]); ?></a>
    <?php else: ?>
      <span class="students-view-tab opacity-50"><?php echo h($meta[0]); ?></span>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
