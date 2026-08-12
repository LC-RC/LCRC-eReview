<?php
/**
 * Empty state for My CPA Review list pages.
 * Vars: $cpaEmptyIcon, $cpaEmptyTitle, $cpaEmptyText, $cpaEmptyCtaHref, $cpaEmptyCtaLabel
 */
$cpaEmptyIcon = $cpaEmptyIcon ?? 'bi-inbox';
$cpaEmptyTitle = $cpaEmptyTitle ?? 'Nothing here yet';
$cpaEmptyText = $cpaEmptyText ?? '';
$cpaEmptyCtaHref = $cpaEmptyCtaHref ?? '';
$cpaEmptyCtaLabel = $cpaEmptyCtaLabel ?? 'Get started';
?>
<div class="cpa-empty rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white shadow-[0_1px_4px_rgba(15,23,42,0.08)] p-10 text-center text-[#143D59]/80">
  <i class="bi <?php echo h($cpaEmptyIcon); ?> text-5xl mb-3 text-[#1665A0]" aria-hidden="true"></i>
  <p class="text-lg font-semibold m-0"><?php echo h($cpaEmptyTitle); ?></p>
  <?php if ($cpaEmptyText !== ''): ?>
    <p class="text-sm mt-1 mb-0"><?php echo h($cpaEmptyText); ?></p>
  <?php endif; ?>
  <?php if ($cpaEmptyCtaHref !== '' && $cpaEmptyCtaHref !== '#'): ?>
    <a href="<?php echo h($cpaEmptyCtaHref); ?>" class="inline-flex items-center gap-2 mt-4 px-4 py-2 rounded-xl font-semibold bg-[#1665A0] text-white hover:bg-[#0f4d7a] transition">
      <?php echo h($cpaEmptyCtaLabel); ?>
    </a>
  <?php elseif ($cpaEmptyCtaHref === '#' && $cpaEmptyCtaLabel !== ''): ?>
    <p class="text-sm font-semibold text-[#1665A0] mt-4 mb-0"><?php echo h($cpaEmptyCtaLabel); ?></p>
  <?php endif; ?>
</div>
