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
$now = examination_schedule_now_sql();
if ($uidInt > 0) {
    college_exam_finalize_expired_in_progress($conn, 0, $uidInt, 0);
    diagnostic_exam_finalize_expired_in_progress($conn, 0, $uidInt);
}

$q = trim((string)($_GET['q'] ?? ''));
$view = (string)($_GET['view'] ?? 'all');
$sort = (string)($_GET['sort'] ?? 'recent');
$displayParam = isset($_GET['display']) ? trim((string)$_GET['display']) : '';
$validViews = ['all', 'open', 'upcoming', 'finished', 'completed', 'missed'];
$validSorts = ['recent', 'oldest', 'deadline_asc', 'deadline_desc', 'title_asc', 'title_desc', 'opens_asc'];
$validDisplays = ['list', 'card'];
if ($view === 'completed') {
    $view = 'finished';
}
if (!in_array($view, $validViews, true)) {
    $view = 'all';
}
if (!in_array($sort, $validSorts, true)) {
    $sort = 'recent';
}
$display = in_array($displayParam, $validDisplays, true) ? $displayParam : 'card';

function college_exams_empty_state(string $viewKey): array
{
    return match ($viewKey) {
        'open' => [
            'icon' => 'bi-unlock',
            'title' => 'No open examinations',
            'text' => 'You do not have any examinations open right now. Check upcoming exams or switch to All.',
        ],
        'upcoming' => [
            'icon' => 'bi-clock-history',
            'title' => 'No upcoming examinations',
            'text' => 'You do not have any upcoming examinations at the moment.',
        ],
        'finished' => [
            'icon' => 'bi-check-circle',
            'title' => 'No finished examinations',
            'text' => 'Completed examinations will appear here after you submit an attempt.',
        ],
        'missed' => [
            'icon' => 'bi-exclamation-circle',
            'title' => 'No missed examinations',
            'text' => 'Examinations you miss after the deadline will be listed here.',
        ],
        default => [
            'icon' => 'bi-journal-x',
            'title' => 'No examinations match this filter',
            'text' => 'Try another search term or switch to a different status filter.',
        ],
    };
}

function college_exams_display_query(string $display): string
{
    return $display === 'card' ? '' : '&display=' . urlencode($display);
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

$list = examination_student_sort_items($list, $sort);

$emptyState = college_exams_empty_state($view);
$displayQuery = college_exams_display_query($display);
$filterQuerySuffix = '&sort=' . urlencode($sort) . '&q=' . urlencode($q) . $displayQuery;
$listLayout = $display === 'card' ? 'card' : 'lms';
$listContainerClass = $display === 'card' ? 'cp-exam-grid cp-exam-grid--catalog' : 'cp-exam-lms-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_app.php'; ?>
</head>
<body class="font-sans antialiased<?php echo !empty($examinationStudentBodyClass) ? ' ' . h($examinationStudentBodyClass) : ''; ?>">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>
  <div class="cp-page-shell cp-content cp-content--catalog cp-exams-lms cp-exams-catalog ereview-shell-no-fade pt-2">
    <?php
      $cpPageVariant = 'editorial';
      $cpPageIcon = 'bi-journal-text';
      $cpPageTitle = 'Examinations';
      $cpPageSubtitle = 'Manage your available college examinations.';
      $cpPageStatsVariant = 'inline';
      $cpPageStats = [
          ['label' => 'Total', 'value' => (int)$countMap['all']],
          ['label' => 'Open now', 'value' => (int)$countMap['open']],
          ['label' => 'Upcoming', 'value' => (int)$countMap['upcoming']],
          ['label' => 'Finished', 'value' => (int)$countMap['finished']],
      ];
      require dirname(__DIR__, 2) . '/includes/components/college_portal_page_header.php';
    ?>

    <section class="cp-exams-browser cp-anim delay-2" aria-label="Examination browser">
      <div class="cp-exams-browser__toolbar cp-glass-surface">
        <form method="get" class="cp-exams-browser__search search-sort-form">
          <div class="cp-search-bar__field cp-search-bar__field--wide">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search examinations..." class="search-input" aria-label="Search examinations">
          </div>
          <select name="sort" class="sort-select" aria-label="Sort examinations" onchange="this.form.submit()">
            <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Newest</option>
            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
            <option value="deadline_asc" <?php echo $sort === 'deadline_asc' ? 'selected' : ''; ?>>Closing soon</option>
            <option value="opens_asc" <?php echo $sort === 'opens_asc' ? 'selected' : ''; ?>>Opening soon</option>
          </select>
          <?php if ($view !== 'all'): ?>
            <input type="hidden" name="view" value="<?php echo h($view); ?>">
          <?php endif; ?>
          <?php if ($display === 'list'): ?>
            <input type="hidden" name="display" value="list">
          <?php endif; ?>
        </form>

        <div class="cp-exams-browser__controls">
          <div class="cp-exams-browser__filters filters-row">
            <?php
              $views = [
                  'all' => ['All', $countMap['all']],
                  'open' => ['Open now', $countMap['open']],
                  'upcoming' => ['Upcoming', $countMap['upcoming']],
                  'finished' => ['Finished', $countMap['finished']],
                  'missed' => ['Missed', $countMap['missed']],
              ];
              foreach ($views as $k => $v):
                $url = '?view=' . urlencode($k) . $filterQuerySuffix;
            ?>
              <a href="<?php echo h($url); ?>" class="filter-pill filter-pill--compact <?php echo $k === 'finished' ? 'filter-finished ' : ''; ?><?php echo $view === $k ? 'is-active' : ''; ?>"><?php echo h($v[0]); ?> <span class="filter-pill__count"><?php echo (int)$v[1]; ?></span></a>
            <?php endforeach; ?>
          </div>

          <div class="cp-view-toggle" role="group" aria-label="Display layout">
            <?php
              $listUrl = '?display=list' . ($view !== 'all' ? '&view=' . urlencode($view) : '') . '&sort=' . urlencode($sort) . '&q=' . urlencode($q);
              $cardUrl = '?' . ($view !== 'all' ? 'view=' . urlencode($view) . '&' : '') . 'sort=' . urlencode($sort) . '&q=' . urlencode($q);
              if ($cardUrl === '?') {
                  $cardUrl = 'college_exams';
              }
            ?>
            <a href="<?php echo h($listUrl); ?>" class="cp-view-toggle__btn<?php echo $display === 'list' ? ' is-active' : ''; ?>" aria-pressed="<?php echo $display === 'list' ? 'true' : 'false'; ?>"><i class="bi bi-list-ul" aria-hidden="true"></i><span>List</span></a>
            <a href="<?php echo h($cardUrl); ?>" class="cp-view-toggle__btn<?php echo $display === 'card' ? ' is-active' : ''; ?>" aria-pressed="<?php echo $display === 'card' ? 'true' : 'false'; ?>"><i class="bi bi-grid" aria-hidden="true"></i><span>Grid</span></a>
          </div>
        </div>
      </div>

      <div class="<?php echo h($listContainerClass); ?>" data-ereview-exam-count="<?php echo (int)count($list); ?>">
        <?php if (count($list) === 0): ?>
          <div class="cp-empty-surface cp-empty-surface--catalog">
            <div class="cp-empty-surface__icon"><i class="bi <?php echo h($emptyState['icon']); ?>"></i></div>
            <h3 class="cp-empty-surface__title"><?php echo h($emptyState['title']); ?></h3>
            <p class="cp-empty-surface__text"><?php echo h($emptyState['text']); ?></p>
          </div>
        <?php else: ?>
          <?php $examIndex = 0; foreach ($list as $e): ?>
            <?php
              $cpExam = $e;
              $cpExamLayout = $listLayout;
              $cpExamFeatured = false;
              $cpExamIsNewest = ($sort === 'recent' && $examIndex === 0);
              require dirname(__DIR__, 2) . '/includes/components/college_portal_exam_card.php';
              $examIndex++;
            ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
  </div>
</main>
</body>
</html>
