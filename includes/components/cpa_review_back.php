<?php
/** Compact back link into My CPA Review workspace. */
$cpaBackLabel = $cpaBackLabel ?? 'My CPA Review';
?>
<a href="student_cpa_review" class="inline-flex items-center gap-1.5 text-sm font-semibold text-[#1665A0] hover:underline mb-3">
  <i class="bi bi-arrow-left"></i> <?php echo h($cpaBackLabel); ?>
</a>
