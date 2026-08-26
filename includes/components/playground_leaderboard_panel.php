<?php
/**
 * Compete / leaderboard panel for Game Zone (dark theme, read-only).
 *
 * Expects:
 * - bool $careerReady (leaderboard ready)
 * - string $boardType ('lifetime' | 'season')
 * - array $board, $standing, $pagination
 * - int $perPage, $seasonId
 * - ?array $activeSeason
 * - string $seasonTitle, $scoreLabel
 * - callable|string $pageUrlBuilder — function(string $board, int $page, int $per, int $season = 0): string
 */
if (!$careerReady): ?>
  <section class="pg-stats-panel pg-zone-empty">
    <h2 class="m-0">Compete</h2>
    <p class="pg-setup-lead m-0 mt-2">Leaderboard is currently unavailable. Please check back later.</p>
  </section>
<?php return; endif;

$pageUrlBuilder = $pageUrlBuilder ?? static function (string $board, int $pageNum, int $per, int $season = 0): string {
    $q = ['board' => $board, 'page' => $pageNum, 'per_page' => $per];
    if ($board === 'season' && $season > 0) {
        $q['season_id'] = $season;
    }
    return 'student_playground_leaderboard?' . http_build_query($q);
};
?>
<div class="pg-zone-board-tabs" role="tablist" aria-label="Leaderboard type">
  <a
    href="<?php echo h($pageUrlBuilder('lifetime', 1, $perPage)); ?>"
    class="pg-zone-board-tab<?php echo $boardType === 'lifetime' ? ' is-active' : ''; ?>"
    <?php echo $boardType === 'lifetime' ? 'aria-current="page"' : ''; ?>
  >All Time</a>
  <?php if ($activeSeason && student_gamification_seasons_tables_ready($conn)): ?>
    <a
      href="<?php echo h($pageUrlBuilder('season', 1, $perPage, (int) $activeSeason['season_id'])); ?>"
      class="pg-zone-board-tab<?php echo $boardType === 'season' ? ' is-active' : ''; ?>"
      <?php echo $boardType === 'season' ? 'aria-current="page"' : ''; ?>
    >Season: <?php echo h((string) $activeSeason['title']); ?></a>
  <?php else: ?>
    <span class="pg-zone-board-tab is-disabled" title="Seasonal competition coming soon">Season</span>
  <?php endif; ?>
</div>

<section class="pg-zone-standing-card" aria-label="Your standing">
  <h2>Your Standing</h2>
  <?php if (!empty($standing['joined']) && !empty($standing['rank'])): ?>
    <p class="pg-zone-standing-rank m-0">You are #<?php echo (int) $standing['rank']; ?> of <?php echo number_format((int) ($standing['ranked_total'] ?? 0)); ?></p>
    <p class="pg-zone-standing-meta">
      <?php echo number_format((int) ($standing['score_xp'] ?? 0)); ?>
      <?php echo h($boardType === 'season' ? 'Season XP' : 'XP'); ?>
      · Level <?php echo (int) ($standing['level'] ?? 1); ?>
      · <?php echo h((string) ($standing['rank_title'] ?? '')); ?>
    </p>
    <?php if (!empty($standing['above_rank']) && (int) ($standing['xp_gap_above'] ?? 0) > 0): ?>
      <p class="pg-zone-standing-gap"><?php echo number_format((int) $standing['xp_gap_above']); ?> XP to reach #<?php echo (int) $standing['above_rank']; ?></p>
    <?php elseif ((int) ($standing['rank'] ?? 0) === 1): ?>
      <p class="pg-zone-standing-gap">You're at the top!</p>
    <?php endif; ?>
  <?php else: ?>
    <p class="pg-zone-standing-rank m-0 pg-zone-standing-rank--muted">You haven't joined the leaderboard yet.</p>
    <p class="pg-zone-standing-meta">Complete a quiz or Playground session to earn your first Career XP.</p>
    <p class="pg-zone-standing-meta mt-2"><a href="student_playground" class="pg-zone-link">Go to Play →</a></p>
  <?php endif; ?>
</section>

<section class="pg-stats-panel" aria-label="Leaderboard rankings">
  <h2><?php echo h($boardType === 'season' ? (string) $seasonTitle : 'All Time Career XP'); ?></h2>
  <?php if ($boardType === 'season' && !student_gamification_seasons_tables_ready($conn)): ?>
    <p class="pg-setup-lead m-0">Seasonal leaderboard is not available yet.</p>
  <?php elseif (($board['rows'] ?? []) === []): ?>
    <p class="pg-setup-lead m-0"><?php echo $boardType === 'season' ? 'No seasonal XP recorded yet.' : 'No ranked students yet.'; ?></p>
  <?php else: ?>
    <div class="pg-zone-table-wrap">
      <table class="pg-zone-leaderboard-table">
        <thead>
          <tr>
            <th class="rank-col">Rank</th>
            <th>Student</th>
            <th class="xp-col"><?php echo h($scoreLabel); ?></th>
            <th>Level</th>
            <th>Rank</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($board['rows'] as $row): ?>
            <tr class="<?php echo !empty($row['is_viewer']) ? 'is-viewer' : ''; ?>">
              <td class="rank-col">#<?php echo (int) ($row['rank'] ?? 0); ?></td>
              <td><?php echo h((string) ($row['display_name'] ?? '')); ?><?php echo !empty($row['is_viewer']) ? ' (You)' : ''; ?></td>
              <td class="xp-col"><?php echo number_format((int) ($row['score_xp'] ?? 0)); ?></td>
              <td><?php echo (int) ($row['level'] ?? 1); ?></td>
              <td><?php echo h((string) ($row['rank_title'] ?? '')); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
      <nav class="pg-zone-pagination" aria-label="Leaderboard pagination">
        <?php
          $cur = (int) ($pagination['current'] ?? 1);
          $totalPages = (int) ($pagination['total_pages'] ?? 1);
          $prev = max(1, $cur - 1);
          $next = min($totalPages, $cur + 1);
          $seasonParam = ($boardType === 'season') ? $seasonId : 0;
        ?>
        <a href="<?php echo h($pageUrlBuilder($boardType, $prev, $perPage, $seasonParam)); ?>" class="<?php echo $cur <= 1 ? 'is-disabled' : ''; ?>" <?php echo $cur <= 1 ? 'aria-disabled="true"' : ''; ?>>Prev</a>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <?php if ($p === $cur): ?>
            <span class="is-current" aria-current="page"><?php echo $p; ?></span>
          <?php else: ?>
            <a href="<?php echo h($pageUrlBuilder($boardType, $p, $perPage, $seasonParam)); ?>"><?php echo $p; ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <a href="<?php echo h($pageUrlBuilder($boardType, $next, $perPage, $seasonParam)); ?>" class="<?php echo $cur >= $totalPages ? 'is-disabled' : ''; ?>" <?php echo $cur >= $totalPages ? 'aria-disabled="true"' : ''; ?>>Next</a>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
