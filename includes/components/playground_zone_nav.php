<?php
/**
 * Game Zone tab navigation: Play | Career | Compete
 * Expects $pgZoneNavActive = 'play' | 'career' | 'compete'
 */
$pgZoneNavActive = $pgZoneNavActive ?? 'play';
$pgZoneTabs = [
    'play' => ['href' => 'student_playground', 'label' => 'Play', 'icon' => '🎮'],
    'career' => ['href' => 'student_playground_career', 'label' => 'Career', 'icon' => '🏆'],
    'compete' => ['href' => 'student_playground_leaderboard', 'label' => 'Compete', 'icon' => '🏅'],
];
?>
<nav class="pg-zone-nav" aria-label="Game Zone sections">
  <?php foreach ($pgZoneTabs as $key => $tab): ?>
    <a
      href="<?php echo h($tab['href']); ?>"
      class="pg-zone-nav__link<?php echo $pgZoneNavActive === $key ? ' is-active' : ''; ?>"
      <?php echo $pgZoneNavActive === $key ? 'aria-current="page"' : ''; ?>
    ><span class="pg-zone-nav__icon" aria-hidden="true"><?php echo $tab['icon']; ?></span> <?php echo h($tab['label']); ?></a>
  <?php endforeach; ?>
</nav>
