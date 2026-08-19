<?php
/**
 * College Portal section heading.
 *
 * @var string      $cpSectionIcon
 * @var string      $cpSectionTitle
 * @var string|null $cpSectionId
 * @var string      $cpSectionClass
 */
$cpSectionIcon = trim((string)($cpSectionIcon ?? 'bi-dot'));
$cpSectionTitle = trim((string)($cpSectionTitle ?? ''));
$cpSectionId = isset($cpSectionId) ? trim((string)$cpSectionId) : '';
$cpSectionClass = trim((string)($cpSectionClass ?? 'cp-anim delay-2'));
?>
<h2 class="cp-section <?php echo h($cpSectionClass); ?>"<?php echo $cpSectionId !== '' ? ' id="' . h($cpSectionId) . '"' : ''; ?>>
  <i class="bi <?php echo h($cpSectionIcon); ?>" aria-hidden="true"></i>
  <span><?php echo h($cpSectionTitle); ?></span>
</h2>
