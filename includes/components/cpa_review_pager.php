<?php
/**
 * Server-side pagination links.
 * Vars: $cpaTotal, $cpaPage, $cpaPerPage, $cpaBaseQuery (array of query params without page)
 */
$cpaTotal = (int) ($cpaTotal ?? 0);
$cpaPage = max(1, (int) ($cpaPage ?? 1));
$cpaPerPage = max(1, (int) ($cpaPerPage ?? 20));
$cpaBaseQuery = $cpaBaseQuery ?? [];
$pages = (int) max(1, (int) ceil($cpaTotal / $cpaPerPage));
if ($cpaTotal <= $cpaPerPage) {
    return;
}
if (!function_exists('cpa_review_page_url')) {
    function cpa_review_page_url(array $base, int $page): string
    {
        $base['page'] = $page;
        return '?' . http_build_query($base);
    }
}
?>
<nav class="cpa-pager" aria-label="Pagination">
  <?php if ($cpaPage > 1): ?>
    <a href="<?php echo h(cpa_review_page_url($cpaBaseQuery, $cpaPage - 1)); ?>">Prev</a>
  <?php endif; ?>
  <span class="is-current">Page <?php echo $cpaPage; ?> of <?php echo $pages; ?></span>
  <?php if ($cpaPage < $pages): ?>
    <a href="<?php echo h(cpa_review_page_url($cpaBaseQuery, $cpaPage + 1)); ?>">Next</a>
  <?php endif; ?>
</nav>
