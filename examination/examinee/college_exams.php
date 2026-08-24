<?php
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
ereview_require_college_examination_portal();
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/examination_eligibility.php';

$pageTitle = 'Exams';
$uid = getCurrentUserId();
$uidInt = (int)($uid ?? 0);
$now = date('Y-m-d H:i:s');
if ($uidInt > 0) {
    college_exam_finalize_expired_in_progress($conn, 0, $uidInt, 0);
    diagnostic_exam_finalize_expired_in_progress($conn, 0, $uidInt);
}

$q = trim((string)($_GET['q'] ?? ''));
$view = (string)($_GET['view'] ?? 'all');
$sort = (string)($_GET['sort'] ?? 'deadline_asc');
$display = (string)($_GET['display'] ?? 'card');
$validViews = ['all', 'open', 'upcoming', 'finished', 'completed', 'missed'];
$validSorts = ['deadline_asc', 'deadline_desc', 'title_asc', 'title_desc', 'recent'];
$validDisplays = ['list', 'card'];
if ($view === 'completed') {
    $view = 'finished';
}
if (!in_array($view, $validViews, true)) {
    $view = 'all';
}
if (!in_array($sort, $validSorts, true)) {
    $sort = 'deadline_asc';
}
if (!in_array($display, $validDisplays, true)) {
    $display = 'card';
}

function college_exams_status_pill_class(string $statusKey): string
{
    return match ($statusKey) {
        'open' => 'status-open',
        'upcoming' => 'status-upcoming',
        'in_progress' => 'status-progress',
        'submitted', 'finished' => 'status-done',
        'missed' => 'status-missed',
        default => 'status-closed',
    };
}

function college_exams_status_icon(string $statusKey): string
{
    return match ($statusKey) {
        'open' => 'bi-unlock',
        'upcoming' => 'bi-clock',
        'in_progress' => 'bi-play-circle',
        'submitted', 'finished' => 'bi-check-circle',
        'missed' => 'bi-exclamation-circle',
        default => 'bi-lock',
    };
}

function college_exams_format_datetime(?string $value, string $fallback = '-'): string
{
    if ($value === null || trim($value) === '') {
        return $fallback;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $fallback;
    }

    return date('M j, Y g:i A', $ts);
}

$allItems = examination_student_load_assigned_exams($conn, $uidInt, $now);
$list = [];
$countMap = ['all' => 0, 'open' => 0, 'upcoming' => 0, 'finished' => 0, 'missed' => 0];

foreach ($allItems as $item) {
    $bucket = (string)($item['_bucket'] ?? 'all');
    $countMap['all']++;
    if (isset($countMap[$bucket])) {
        $countMap[$bucket]++;
    }

    if ($q !== '') {
        $needle = mb_strtolower($q);
        $hay = mb_strtolower(
            (string)($item['title'] ?? '') . ' ' . (string)($item['description'] ?? '') . ' '
            . examination_exam_type_label((string)($item['exam_type'] ?? 'regular'))
        );
        if (mb_strpos($hay, $needle) === false) {
            continue;
        }
    }
    if ($view !== 'all' && $bucket !== $view) {
        continue;
    }
    $list[] = $item;
}

usort($list, static function ($a, $b) use ($sort) {
    $ta = strtotime((string)($a['deadline'] ?? '')) ?: PHP_INT_MAX;
    $tb = strtotime((string)($b['deadline'] ?? '')) ?: PHP_INT_MAX;
    $ca = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $cb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
    switch ($sort) {
        case 'deadline_desc':
            return $tb <=> $ta;
        case 'title_asc':
            return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        case 'title_desc':
            return strcasecmp((string)($b['title'] ?? ''), (string)($a['title'] ?? ''));
        case 'recent':
            return $cb <=> $ca;
        case 'deadline_asc':
        default:
            return $ta <=> $tb;
    }
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_app.php'; ?>
</head>
<body class="font-sans antialiased<?php echo !empty($examinationStudentBodyClass) ? ' ' . h($examinationStudentBodyClass) : ''; ?>">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>
  <div class="cp-page-shell cp-content cp-content--catalog ereview-shell-no-fade pt-2">
    <?php
      $cpPageVariant = 'compact';
      $cpPageTitle = 'Examinations';
      $cpPageSubtitle = 'Manage your available college examinations.';
      $cpPageStats = [
          ['label' => 'Total', 'value' => (int)$countMap['all']],
          ['label' => 'Open now', 'value' => (int)$countMap['open']],
          ['label' => 'Upcoming', 'value' => (int)$countMap['upcoming']],
          ['label' => 'Finished', 'value' => (int)$countMap['finished']],
      ];
      require dirname(__DIR__, 2) . '/includes/components/college_portal_page_header.php';
    ?>

    <section class="cp-dash-panel cp-anim delay-2" aria-label="Examination filters">
      <div class="cp-dash-panel__head">
        <h2 class="cp-dash-panel__title">Browse examinations</h2>
      </div>
      <div class="cp-dash-panel__body">
    <div class="cp-toolbar-inline">
      <form method="get" class="cp-toolbar-inline__search search-sort-form">
        <div class="cp-search-bar__field">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search examinations..." class="search-input" aria-label="Search examinations">
        </div>
        <select name="sort" class="sort-select" aria-label="Sort examinations">
          <option value="deadline_asc" <?php echo $sort === 'deadline_asc' ? 'selected' : ''; ?>>Closes (soonest)</option>
          <option value="deadline_desc" <?php echo $sort === 'deadline_desc' ? 'selected' : ''; ?>>Closes (latest)</option>
          <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>Title A–Z</option>
          <option value="title_desc" <?php echo $sort === 'title_desc' ? 'selected' : ''; ?>>Title Z–A</option>
          <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Recently created</option>
        </select>
        <input type="hidden" name="display" value="<?php echo h($display); ?>">
        <button class="cp-btn cp-btn--secondary cp-btn--sm" type="submit">Apply</button>
        <div class="cp-view-toggle">
          <a href="<?php echo h('?view=' . urlencode($view) . '&sort=' . urlencode($sort) . '&q=' . urlencode($q) . '&display=card'); ?>" class="view-chip <?php echo $display === 'card' ? 'is-active' : ''; ?>" title="Card view"><i class="bi bi-grid-3x3-gap"></i></a>
          <a href="<?php echo h('?view=' . urlencode($view) . '&sort=' . urlencode($sort) . '&q=' . urlencode($q) . '&display=list'); ?>" class="view-chip <?php echo $display === 'list' ? 'is-active' : ''; ?>" title="List view"><i class="bi bi-list-ul"></i></a>
        </div>
      </form>
      <div class="filters-row cp-toolbar-inline__filters">
        <?php
          $views = [
              'all' => ['All', $countMap['all'], 'bi-grid'],
              'open' => ['Open now', $countMap['open'], 'bi-play-circle'],
              'upcoming' => ['Upcoming', $countMap['upcoming'], 'bi-clock-history'],
              'finished' => ['Finished', $countMap['finished'], 'bi-check-circle'],
              'missed' => ['Missed', $countMap['missed'], 'bi-exclamation-circle'],
          ];
          foreach ($views as $k => $v):
            $url = '?view=' . urlencode($k) . '&sort=' . urlencode($sort) . '&q=' . urlencode($q) . '&display=' . urlencode($display);
        ?>
          <a href="<?php echo h($url); ?>" class="filter-pill filter-pill--compact <?php echo $k === 'finished' ? 'filter-finished ' : ''; ?><?php echo $view === $k ? 'is-active' : ''; ?>"><?php echo h($v[0]); ?> <span class="filter-pill__count"><?php echo (int)$v[1]; ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
      </div>
    </section>

    <section class="cp-dash-panel cp-anim delay-3" aria-label="Examination list">
      <div class="cp-dash-panel__head">
        <h2 class="cp-dash-panel__title">Examination list</h2>
        <span class="cp-dash-panel__meta"><?php echo (int)count($list); ?> shown</span>
      </div>
      <div class="cp-dash-panel__body">
    <div class="cp-catalog-list" data-ereview-exam-count="<?php echo (int)count($list); ?>">
      <?php if (count($list) === 0): ?>
        <div class="cp-empty-surface">
          <div class="cp-empty-surface__icon"><i class="bi bi-journal-x"></i></div>
          <h3 class="cp-empty-surface__title">No examinations match this filter</h3>
          <p class="cp-empty-surface__text">Try another search term or switch to a different status filter.</p>
        </div>
      <?php elseif ($display === 'list'): ?>
        <div class="cp-exam-list">
          <?php foreach ($list as $e):
            $cpExam = $e;
            $cpExamLayout = 'row';
            $cpExamFeatured = false;
            require dirname(__DIR__, 2) . '/includes/components/college_portal_exam_card.php';
          endforeach; ?>
        </div>
      <?php else: ?>
        <div class="cp-exam-grid">
          <?php foreach ($list as $e):
            $cpExam = $e;
            $cpExamLayout = 'card';
            $cpExamFeatured = false;
            require dirname(__DIR__, 2) . '/includes/components/college_portal_exam_card.php';
          endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
      </div>
    </section>
  </div>
</main>
</body>
</html>
