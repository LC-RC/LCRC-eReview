<?php
/**
 * Shared Game Zone header for Play, Career, and Compete pages.
 * Expects $pgZoneNavActive and optional $pgZoneHeaderMeta (HTML string for right-side meta, e.g. music on Play hub).
 */
$pgZoneNavActive = $pgZoneNavActive ?? 'play';
$pgZoneHeaderMeta = $pgZoneHeaderMeta ?? '';
?>
<header class="pg-zone-header">
  <div class="pg-zone-header__main">
    <p class="pg-lobby-kicker">LCRC eReview · Game Zone</p>
    <h1 class="pg-lobby-title">🎮 CPA Playground</h1>
    <p class="pg-lobby-sub">Practice smarter. Compete. Level up.</p>
    <?php require __DIR__ . '/playground_zone_nav.php'; ?>
  </div>
  <?php if ($pgZoneHeaderMeta !== ''): ?>
    <div class="pg-zone-header__meta"><?php echo $pgZoneHeaderMeta; ?></div>
  <?php endif; ?>
</header>
