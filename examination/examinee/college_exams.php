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
$display = in_array($displayParam, $validDisplays, true) ? $displayParam : 'list';

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
    return $display === 'list' ? '' : '&display=' . urlencode($display);
}

function college_exams_format_when(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return '—';
    }
    $ts = strtotime($raw);

    return $ts !== false ? date('M j, Y g:i A', $ts) : '—';
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
  <?php
    $__csAlignCssFile = dirname(__DIR__, 2) . '/assets/css/college-student-admin-align.css';
    if (is_file($__csAlignCssFile)) {
        $__sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $__asset = $__sn;
        $__adj = preg_replace('#/examination/(?:professor|examinee)/[^/]+$#', '/index.php', $__sn);
        if (is_string($__adj) && $__adj !== '') {
            $__asset = $__adj;
        }
        $__base = rtrim(str_replace('\\', '/', dirname($__asset)), '/');
        if ($__base === '.' || $__base === '') {
            $__base = '';
        }
        echo '<link rel="stylesheet" href="' . h($__base) . '/assets/css/college-student-admin-align.css?v=' . filemtime($__csAlignCssFile) . '">' . "\n";
    }
  ?>
</head>
<body class="font-sans antialiased college-student-admin-align cs-page--exams<?php echo !empty($examinationStudentBodyClass) ? ' ' . h($examinationStudentBodyClass) : ''; ?>">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>
  <div class="cs-admin-page ereview-shell-no-fade">
    <?php
      $views = [
          'all' => ['All', $countMap['all']],
          'open' => ['Open now', $countMap['open']],
          'upcoming' => ['Upcoming', $countMap['upcoming']],
          'finished' => ['Finished', $countMap['finished']],
          'missed' => ['Missed', $countMap['missed']],
      ];
      $listUrl = '?' . ($view !== 'all' ? 'view=' . urlencode($view) . '&' : '') . 'sort=' . urlencode($sort) . '&q=' . urlencode($q);
      $cardUrl = '?display=card' . ($view !== 'all' ? '&view=' . urlencode($view) : '') . '&sort=' . urlencode($sort) . '&q=' . urlencode($q);
      $statQuery = $filterQuerySuffix;
    ?>
    <section class="cs-admin-page-hero cs-exams-hero" aria-labelledby="cs-exams-title">
      <svg class="cs-exams-hero__waves" viewBox="0 0 420 140" preserveAspectRatio="xMaxYMid slice" aria-hidden="true" focusable="false">
        <path fill="rgba(147,197,253,0.35)" d="M40,110 C120,20 210,30 420,18 L420,140 L0,140 Z"/>
        <path fill="rgba(196,181,253,0.28)" d="M80,120 C180,40 280,55 420,48 L420,140 L20,140 Z"/>
        <path fill="rgba(186,230,253,0.35)" d="M140,128 C230,70 320,80 420,72 L420,140 L60,140 Z"/>
      </svg>
      <div class="cs-exams-hero__copy">
        <p class="cs-admin-page-hero__eyebrow">College portal</p>
        <h1 id="cs-exams-title" class="cs-admin-page-hero__title">
          <span class="cs-admin-page-hero__icon" aria-hidden="true"><i class="bi bi-journal-text"></i></span>
          Examinations
        </h1>
        <p class="cs-admin-page-hero__sub">Manage your available college examinations.</p>
      </div>
      <p class="cs-exams-hero__quote">Learn Today<br>Build Tomorrow</p>
    </section>

    <section class="cs-admin-summary-rail cs-exams-stats" aria-label="Examination summary">
      <a class="cs-admin-summary-rail__cell cs-exams-stat cs-exams-stat--all" href="<?php echo h('?view=all' . $statQuery); ?>">
        <span class="cs-admin-summary-rail__ico" aria-hidden="true"><i class="bi bi-journal-bookmark-fill"></i></span>
        <span class="cs-admin-summary-rail__label">All</span>
        <span class="cs-admin-summary-rail__value"><?php echo (int)$countMap['all']; ?></span>
        <span class="cs-admin-summary-rail__hint">Total Examinations</span>
        <i class="bi bi-chevron-right cs-exams-stat__go" aria-hidden="true"></i>
      </a>
      <a class="cs-admin-summary-rail__cell cs-exams-stat cs-exams-stat--open" href="<?php echo h('?view=open' . $statQuery); ?>">
        <span class="cs-admin-summary-rail__ico" aria-hidden="true"><i class="bi bi-unlock-fill"></i></span>
        <span class="cs-admin-summary-rail__label">Open now</span>
        <span class="cs-admin-summary-rail__value"><?php echo (int)$countMap['open']; ?></span>
        <span class="cs-admin-summary-rail__hint">Ready to take</span>
        <i class="bi bi-chevron-right cs-exams-stat__go" aria-hidden="true"></i>
      </a>
      <a class="cs-admin-summary-rail__cell cs-exams-stat cs-exams-stat--upcoming" href="<?php echo h('?view=upcoming' . $statQuery); ?>">
        <span class="cs-admin-summary-rail__ico" aria-hidden="true"><i class="bi bi-clock-fill"></i></span>
        <span class="cs-admin-summary-rail__label">Upcoming</span>
        <span class="cs-admin-summary-rail__value"><?php echo (int)$countMap['upcoming']; ?></span>
        <span class="cs-admin-summary-rail__hint">Not open yet</span>
        <i class="bi bi-chevron-right cs-exams-stat__go" aria-hidden="true"></i>
      </a>
      <a class="cs-admin-summary-rail__cell cs-exams-stat cs-exams-stat--finished" href="<?php echo h('?view=finished' . $statQuery); ?>">
        <span class="cs-admin-summary-rail__ico" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></span>
        <span class="cs-admin-summary-rail__label">Finished</span>
        <span class="cs-admin-summary-rail__value"><?php echo (int)$countMap['finished']; ?></span>
        <span class="cs-admin-summary-rail__hint">Completed</span>
        <i class="bi bi-chevron-right cs-exams-stat__go" aria-hidden="true"></i>
      </a>
    </section>

    <section class="cs-admin-workspace cs-exams-browser" aria-label="Examination browser">
      <nav class="cs-admin-tabs" aria-label="Examination status">
        <?php foreach ($views as $k => $v):
          $url = '?view=' . urlencode($k) . $filterQuerySuffix;
        ?>
          <a href="<?php echo h($url); ?>" class="cs-admin-tab <?php echo $view === $k ? 'is-active' : ''; ?>"><?php echo h($v[0]); ?> <span class="cs-admin-tab__count">(<?php echo (int)$v[1]; ?>)</span></a>
        <?php endforeach; ?>
      </nav>

      <div class="cs-admin-toolbar">
        <form method="get" class="cs-admin-toolbar__search">
          <div class="cs-admin-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search examinations..." aria-label="Search examinations">
          </div>
          <select name="sort" aria-label="Sort examinations" onchange="this.form.submit()">
            <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Newest</option>
            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
            <option value="deadline_asc" <?php echo $sort === 'deadline_asc' ? 'selected' : ''; ?>>Closing soon</option>
            <option value="opens_asc" <?php echo $sort === 'opens_asc' ? 'selected' : ''; ?>>Opening soon</option>
          </select>
          <?php if ($view !== 'all'): ?>
            <input type="hidden" name="view" value="<?php echo h($view); ?>">
          <?php endif; ?>
          <?php if ($display === 'card'): ?>
            <input type="hidden" name="display" value="card">
          <?php endif; ?>
        </form>
        <div class="cs-admin-view-toggle" role="group" aria-label="Display layout">
          <a href="<?php echo h($listUrl); ?>" class="<?php echo $display === 'list' ? 'is-active' : ''; ?>" aria-pressed="<?php echo $display === 'list' ? 'true' : 'false'; ?>"><i class="bi bi-list-ul" aria-hidden="true"></i><span>List</span></a>
          <a href="<?php echo h($cardUrl); ?>" class="<?php echo $display === 'card' ? 'is-active' : ''; ?>" aria-pressed="<?php echo $display === 'card' ? 'true' : 'false'; ?>"><i class="bi bi-grid" aria-hidden="true"></i><span>Grid</span></a>
        </div>
      </div>

      <?php if ($display === 'card'): ?>
        <div class="cs-admin-exam-grid cs-exams-catalog cs-exams-catalog--atelier" data-ereview-exam-count="<?php echo (int)count($list); ?>">
          <?php if (count($list) === 0): ?>
            <div class="cs-admin-empty-state">
              <div class="cs-admin-empty-state__icon"><i class="bi <?php echo h($emptyState['icon']); ?>"></i></div>
              <h3 class="cs-admin-empty-state__title"><?php echo h($emptyState['title']); ?></h3>
              <p class="cs-admin-empty-state__text"><?php echo h($emptyState['text']); ?></p>
            </div>
          <?php else: ?>
            <?php $examIndex = 0; foreach ($list as $e): ?>
              <?php
                $cpExam = $e;
                $cpExamLayout = 'card';
                $cpExamFeatured = false;
                $cpExamIsNewest = ($sort === 'recent' && $examIndex === 0);
                require dirname(__DIR__, 2) . '/includes/components/college_portal_exam_card.php';
                $examIndex++;
              ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="cs-admin-table-shell cs-exams-table" data-ereview-exam-count="<?php echo (int)count($list); ?>">
          <div class="cs-admin-table-scroll">
            <table class="cs-admin-table">
              <thead>
                <tr>
                  <th scope="col">Examination</th>
                  <th scope="col">Type</th>
                  <th scope="col">Status</th>
                  <th scope="col">Questions</th>
                  <th scope="col">Duration</th>
                  <th scope="col">Closes</th>
                  <th scope="col">Score</th>
                  <th scope="col">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($list) === 0): ?>
                  <tr>
                    <td colspan="8">
                      <div class="cs-admin-empty-state">
                        <div class="cs-admin-empty-state__icon"><i class="bi <?php echo h($emptyState['icon']); ?>"></i></div>
                        <h3 class="cs-admin-empty-state__title"><?php echo h($emptyState['title']); ?></h3>
                        <p class="cs-admin-empty-state__text"><?php echo h($emptyState['text']); ?></p>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($list as $e):
                    $examType = (string)($e['exam_type'] ?? 'regular');
                    $typeLabel = examination_exam_type_label($examType);
                    $statusKey = (string)($e['_status_key'] ?? 'locked');
                    $statusLabel = (string)($e['_status_label'] ?? 'Locked');
                    $statusClass = match ($statusKey) {
                        'open' => 'status-open',
                        'upcoming' => 'status-upcoming',
                        'in_progress' => 'status-progress',
                        'submitted', 'finished' => 'status-done',
                        'missed' => 'status-missed',
                        default => 'status-closed',
                    };
                    $qCount = (int)($e['_q_count'] ?? 0);
                    $duration = function_exists('college_exam_human_duration')
                        ? college_exam_human_duration((int)($e['time_limit_seconds'] ?? 0))
                        : '—';
                    $closes = college_exams_format_when(isset($e['deadline']) ? (string)$e['deadline'] : null);
                    $st = (string)($e['attempt_status'] ?? '');
                    $scoreText = '—';
                    if ($st === 'submitted' || ($st === 'expired' && !empty($e['submitted_at']))) {
                        $scoreText = college_exam_format_score_total_line_traditional(
                            isset($e['correct_count']) ? (int)$e['correct_count'] : null,
                            isset($e['total_count']) ? (int)$e['total_count'] : null,
                            $qCount
                        );
                    }
                    $actionMode = (string)($e['_action_mode'] ?? 'closed');
                    $actionUrl = (string)($e['_action_url'] ?? '');
                    $actionLabel = (string)($e['_action_label'] ?? 'Closed');
                    $desc = trim((string)($e['description'] ?? ''));
                  ?>
                  <tr>
                    <td class="cs-admin-table__primary" data-label="Examination">
                      <div><?php echo h((string)($e['title'] ?? 'Untitled')); ?></div>
                      <?php if ($desc !== ''): ?>
                        <div class="cs-admin-workspace__sub"><?php echo h(function_exists('mb_strimwidth') ? mb_strimwidth($desc, 0, 80, '…') : $desc); ?></div>
                      <?php endif; ?>
                    </td>
                    <td data-label="Type"><span class="type-pill <?php echo $examType === 'diagnostic' ? 'type-diagnostic' : 'type-regular'; ?>"><?php echo h($typeLabel); ?></span></td>
                    <td data-label="Status"><span class="status-pill <?php echo h($statusClass); ?>"><?php echo h($statusLabel); ?></span></td>
                    <td data-label="Questions"><?php echo $qCount; ?></td>
                    <td data-label="Duration"><?php echo h($duration); ?></td>
                    <td data-label="Closes"><?php echo h($closes); ?></td>
                    <td data-label="Score"><?php echo h($scoreText); ?></td>
                    <td data-label="Action">
                      <?php if (in_array($actionMode, ['start', 'continue'], true) && $actionUrl !== ''): ?>
                        <a class="cs-admin-btn cs-admin-btn--primary" href="<?php echo h($actionUrl); ?>"><i class="bi <?php echo $actionMode === 'continue' ? 'bi-arrow-right-circle' : 'bi-play-fill'; ?>"></i> <?php echo h($actionLabel); ?></a>
                      <?php elseif ($actionMode === 'review' && $actionUrl !== ''): ?>
                        <a class="cs-admin-btn cs-admin-btn--secondary" href="<?php echo h($actionUrl); ?>"><i class="bi bi-eye"></i> <?php echo h($actionLabel); ?></a>
                      <?php elseif ($actionMode === 'none'): ?>
                        <span class="cs-admin-btn-muted"><?php echo h($actionLabel); ?></span>
                      <?php else: ?>
                        <span class="cs-admin-btn-closed"><i class="bi bi-slash-circle"></i> <?php echo h($actionLabel); ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>
</body>
</html>
