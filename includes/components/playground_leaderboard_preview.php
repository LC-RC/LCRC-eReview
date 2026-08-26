<?php
/**
 * Compact Compete preview for CPA Playground hub (dark Game Zone theme).
 * Expects $leaderboardPreview from student_gamification_leaderboard_preview().
 */
$leaderboardPreview = $leaderboardPreview ?? null;
if (empty($leaderboardPreview['ready'])) {
    return;
}
$top = $leaderboardPreview['top'] ?? [];
$standing = $leaderboardPreview['standing'] ?? [];
$medals = ['🥇', '🥈', '🥉'];
?>
<section class="pg-stats-panel pg-zone-widget" aria-label="Compete snapshot">
  <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
    <h2 class="m-0 pg-zone-widget__title">🏅 Compete</h2>
    <a href="student_playground_leaderboard" class="pg-zone-link">View Leaderboard →</a>
  </div>
  <p class="pg-setup-lead pg-zone-widget__lead">See how you rank on the all-time Career XP leaderboard.</p>
  <?php if (!empty($standing['joined']) && !empty($standing['rank'])): ?>
    <div class="pg-zone-standing-mini mb-2">
      <span class="pg-zone-standing-mini__rank">You are #<?php echo (int) $standing['rank']; ?></span>
      <span class="pg-zone-standing-mini__xp"><?php echo number_format((int) ($standing['score_xp'] ?? 0)); ?> XP</span>
    </div>
  <?php else: ?>
    <p class="pg-setup-lead pg-zone-widget__lead">Complete a quiz or Playground session to join the leaderboard.</p>
  <?php endif; ?>
  <?php if ($top === []): ?>
    <p class="pg-setup-lead m-0">No ranked students yet.</p>
  <?php else: ?>
    <ul class="pg-zone-preview-list">
      <?php foreach ($top as $i => $row): ?>
        <li class="pg-zone-preview-item">
          <span class="name"><?php echo h(($medals[$i] ?? '#') . ' ' . (string) ($row['display_name'] ?? '')); ?></span>
          <span class="score"><?php echo number_format((int) ($row['score_xp'] ?? 0)); ?> XP</span>
        </li>
      <?php endforeach; ?>
      <?php if (!empty($standing['joined']) && !empty($standing['rank']) && (int) ($standing['rank'] ?? 0) > 3): ?>
        <li class="pg-zone-preview-item is-you">
          <span class="name">You · #<?php echo (int) $standing['rank']; ?></span>
          <span class="score"><?php echo number_format((int) ($standing['score_xp'] ?? 0)); ?> XP</span>
        </li>
      <?php endif; ?>
    </ul>
  <?php endif; ?>
</section>
