<?php
/**
 * Admin breadcrumb (admin only).
 * Set $adminBreadcrumbs before including. Each item: [ 'Label', 'url' ] or [ 'Label' ] for current page.
 * Example: $adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Students'] ];
 */
if (empty($adminBreadcrumbs) || !is_array($adminBreadcrumbs)) {
  return;
}
?>
<nav class="admin-breadcrumb mb-3" aria-label="Breadcrumb">
  <ol class="flex flex-wrap items-center gap-1.5 text-sm">
    <?php
    $last = count($adminBreadcrumbs) - 1;
    foreach ($adminBreadcrumbs as $i => $item) {
      $label = is_array($item) ? $item[0] : $item;
      $url   = is_array($item) && isset($item[1]) ? $item[1] : null;
      $current = ($i === $last);
      if ($i > 0) {
        echo '<li class="admin-breadcrumb-sep text-gray-500" aria-hidden="true">/</li>';
      }
      echo '<li>';
      if ($url && !$current) {
        echo '<a href="' . h($url) . '" class="text-primary hover:underline">' . h($label) . '</a>';
      } else {
        echo '<span class="' . ($current ? 'admin-breadcrumb-current text-gray-400' : '') . '">' . h($label) . '</span>';
      }
      echo '</li>';
    }
    ?>
  </ol>
</nav>
