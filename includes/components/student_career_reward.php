<?php
/**
 * Career XP reward feedback (read-only ledger display).
 * Expects $careerRewardContext from student_gamification_reward_context_for_sources().
 */
$careerRewardContext = $careerRewardContext ?? null;
if (empty($careerRewardContext['ready']) || empty($careerRewardContext['events'])) {
    return;
}
$events = $careerRewardContext['events'];
$totalDelta = (int) ($careerRewardContext['total_xp_delta'] ?? 0);
?>
<section class="career-reward-banner" aria-label="Career XP earned" data-career-reward>
  <?php if (!empty($careerRewardContext['level_up'])): ?>
    <div class="career-reward-levelup">
      <strong>LEVEL UP!</strong>
      <span>Level <?php echo (int) ($careerRewardContext['level_after'] ?? 0); ?> · <?php echo h((string) ($careerRewardContext['rank_after'] ?? '')); ?></span>
    </div>
  <?php endif; ?>
  <div class="career-reward-lines">
    <?php foreach ($events as $ev): ?>
      <?php
        $xp = (int) ($ev['xp_delta'] ?? 0);
        $capped = !empty($ev['capped']);
      ?>
      <div class="career-reward-line">
        <span class="career-reward-xp<?php echo $xp === 0 ? ' is-zero' : ''; ?>">
          <?php echo $xp >= 0 ? '+' : ''; ?><?php echo number_format($xp); ?> XP
        </span>
        <span class="career-reward-label"><?php echo h((string) ($ev['label'] ?? '')); ?></span>
        <?php if ($capped && $xp === 0): ?>
          <span class="career-reward-cap">Daily cap reached</span>
        <?php elseif ($capped): ?>
          <span class="career-reward-cap">Partial · daily cap</span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!empty($careerRewardContext['new_achievements'])): ?>
    <div class="career-reward-achievements">
      <?php foreach ($careerRewardContext['new_achievements'] as $ach): ?>
        <div class="career-reward-ach">
          <strong>Achievement Unlocked!</strong>
          <span><?php echo h((string) ($ach['label'] ?? '')); ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (count($events) > 1): ?>
    <p class="career-reward-total">Total this result: <?php echo $totalDelta >= 0 ? '+' : ''; ?><?php echo number_format($totalDelta); ?> Career XP</p>
  <?php endif; ?>
  <button type="button" class="career-reward-dismiss" data-career-reward-dismiss aria-label="Dismiss">×</button>
</section>
