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
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>
  <div class="cp-page-shell ereview-shell-no-fade pt-2">
    <?php
      $cpPageEyebrow = 'Assessments';
      $cpPageTitle = 'Examinations';
      $cpPageSubtitle = 'Your assigned regular and diagnostic examinations — schedule, status, and actions.';
      $cpPageIcon = 'bi-journal-text';
      $cpPageStats = [
          ['label' => 'Total', 'value' => (int)$countMap['all']],
          ['label' => 'Open now', 'value' => (int)$countMap['open']],
          ['label' => 'Upcoming', 'value' => (int)$countMap['upcoming']],
          ['label' => 'Finished', 'value' => (int)$countMap['finished']],
      ];
      require dirname(__DIR__, 2) . '/includes/components/college_portal_page_header.php';
    ?>
    <div class="dash-card toolbar-sticky dash-anim delay-2 p-4 mb-4">
      <div class="toolbar-wrap">
        <div class="toolbar-top">
          <form method="get" class="search-sort-form">
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search exams by title or instructions..." class="search-input">
            <select name="sort" class="sort-select">
              <option value="deadline_asc" <?php echo $sort === 'deadline_asc' ? 'selected' : ''; ?>>Closes (soonest)</option>
              <option value="deadline_desc" <?php echo $sort === 'deadline_desc' ? 'selected' : ''; ?>>Closes (latest)</option>
              <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>Title A-Z</option>
              <option value="title_desc" <?php echo $sort === 'title_desc' ? 'selected' : ''; ?>>Title Z-A</option>
              <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Recently created</option>
            </select>
            <input type="hidden" name="display" value="<?php echo h($display); ?>">
            <button class="action-btn" type="submit"><i class="bi bi-search"></i> Apply</button>
          </form>
          <div class="flex flex-wrap gap-2">
            <a href="<?php echo h('?view=' . urlencode($view) . '&sort=' . urlencode($sort) . '&q=' . urlencode($q) . '&display=card'); ?>" class="view-chip <?php echo $display === 'card' ? 'is-active' : ''; ?>"><i class="bi bi-grid-3x3-gap"></i> Card view</a>
            <a href="<?php echo h('?view=' . urlencode($view) . '&sort=' . urlencode($sort) . '&q=' . urlencode($q) . '&display=list'); ?>" class="view-chip <?php echo $display === 'list' ? 'is-active' : ''; ?>"><i class="bi bi-table"></i> List view</a>
          </div>
        </div>
        <div class="toolbar-footer">
          <div class="filters-row">
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
              <a href="<?php echo h($url); ?>" class="filter-pill <?php echo $k === 'finished' ? 'filter-finished ' : ''; ?><?php echo $view === $k ? 'is-active' : ''; ?>"><i class="bi <?php echo h($v[2]); ?>"></i> <?php echo h($v[0]); ?> (<?php echo (int)$v[1]; ?>)</a>
            <?php endforeach; ?>
          </div>
          <div class="counter-row" aria-label="Exam counters">
            <span class="counter-chip"><i class="bi bi-grid"></i> Total: <?php echo (int)$countMap['all']; ?></span>
            <span class="counter-chip"><i class="bi bi-unlock"></i> Open: <?php echo (int)$countMap['open']; ?></span>
            <span class="counter-chip"><i class="bi bi-check2-circle"></i> Finished: <?php echo (int)$countMap['finished']; ?></span>
          </div>
        </div>
      </div>
    </div>

    <div class="dash-card dash-anim delay-3" data-ereview-exam-count="<?php echo (int)count($list); ?>">
      <?php if (count($list) === 0): ?>
        <div class="empty-state"><i class="bi bi-journal-x"></i><p class="m-0 font-medium">No exams match this filter.</p><p class="m-0 mt-1 text-sm">Try another search or view.</p></div>
      <?php elseif ($display === 'list'): ?>
        <div class="compact-list-head">
          <span>Exam</span><span>Type</span><span>Schedule</span><span>Duration</span><span>Questions</span><span>Status</span><span>Score</span><span>Action</span>
        </div>
        <?php foreach ($list as $e):
          $examType = (string)($e['exam_type'] ?? 'regular');
          $typeLabel = examination_exam_type_label($examType);
          $typeClass = $examType === 'diagnostic' ? 'type-diagnostic' : 'type-regular';
          $statusKey = (string)($e['_status_key'] ?? 'locked');
          $statusLabel = (string)($e['_status_label'] ?? 'Locked');
          $statusClass = college_exams_status_pill_class($statusKey);
          $statusIcon = college_exams_status_icon($statusKey);
          $duration = college_exam_human_duration((int)($e['time_limit_seconds'] ?? 0));
          $opens = college_exams_format_datetime(isset($e['available_from']) ? (string)$e['available_from'] : null, 'Immediate');
          $closes = college_exams_format_datetime(isset($e['deadline']) ? (string)$e['deadline'] : null);
          $desc = trim((string)($e['description'] ?? ''));
          $descText = $desc !== '' ? $desc : 'No instructions provided.';
          $st = (string)($e['attempt_status'] ?? '');
          $scoreHtml = '<span class="text-slate-400 text-xs font-semibold">-</span>';
          if ($st === 'submitted' || ($st === 'expired' && !empty($e['submitted_at']))) {
              $scoreLine = college_exam_format_score_total_line(
                  isset($e['correct_count']) ? (int)$e['correct_count'] : null,
                  isset($e['total_count']) ? (int)$e['total_count'] : null,
                  $e['score'] ?? null,
                  (int)($e['_q_count'] ?? 0)
              );
              $scoreHtml = '<span class="score-cell-text">' . h($scoreLine) . '</span>';
          }
          $actionMode = (string)($e['_action_mode'] ?? 'closed');
          $actionUrl = (string)($e['_action_url'] ?? '');
          $actionLabel = (string)($e['_action_label'] ?? 'Closed');
        ?>
          <div class="compact-list-row">
            <div class="list-cell list-cell--exam">
              <p class="list-exam-title"><?php echo h((string)($e['title'] ?? 'Untitled')); ?></p>
              <p class="list-exam-desc" title="<?php echo h($descText); ?>"><?php echo h($descText); ?></p>
            </div>
            <div class="list-cell list-cell--type"><span class="type-pill <?php echo h($typeClass); ?>"><?php echo h($typeLabel); ?></span></div>
            <div class="list-cell list-cell--schedule">
              <div class="schedule-stack">
                <span><span class="label">Opens</span> <?php echo h($opens); ?></span>
                <span><span class="label">Closes</span> <?php echo h($closes); ?></span>
              </div>
            </div>
            <div class="list-cell list-cell--duration meta-v is-highlight"><?php echo h($duration); ?></div>
            <div class="list-cell list-cell--questions meta-v is-highlight"><?php echo (int)($e['_q_count'] ?? 0); ?></div>
            <div class="list-cell list-cell--status"><span class="status-pill <?php echo h($statusClass); ?>"><i class="bi <?php echo h($statusIcon); ?>"></i> <?php echo h($statusLabel); ?></span></div>
            <div class="list-cell list-cell--score"><?php echo $scoreHtml; ?></div>
            <div class="list-cell list-cell--action">
              <?php if (in_array($actionMode, ['start', 'continue'], true) && $actionUrl !== ''): ?>
                <a class="action-btn action-start" href="<?php echo h($actionUrl); ?>"><i class="bi <?php echo $actionMode === 'continue' ? 'bi-arrow-right-circle' : 'bi-play-fill'; ?>"></i> <?php echo h($actionLabel); ?></a>
              <?php elseif ($actionMode === 'review' && $actionUrl !== ''): ?>
                <a class="action-btn action-review" href="<?php echo h($actionUrl); ?>"><i class="bi bi-eye"></i> <?php echo h($actionLabel); ?></a>
              <?php elseif ($actionMode === 'none'): ?>
                <span class="action-muted"><?php echo h($actionLabel); ?></span>
              <?php else: ?>
                <span class="action-closed-pill"><i class="bi bi-slash-circle"></i> <?php echo h($actionLabel); ?></span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="exam-cards">
          <?php foreach ($list as $e):
            $examType = (string)($e['exam_type'] ?? 'regular');
            $typeLabel = examination_exam_type_label($examType);
            $typeClass = $examType === 'diagnostic' ? 'type-diagnostic' : 'type-regular';
            $cardClass = $examType === 'diagnostic' ? 'exam-card is-diagnostic' : 'exam-card';
            $statusKey = (string)($e['_status_key'] ?? 'locked');
            $statusLabel = (string)($e['_status_label'] ?? 'Locked');
            $statusClass = college_exams_status_pill_class($statusKey);
            $statusIcon = college_exams_status_icon($statusKey);
            $duration = college_exam_human_duration((int)($e['time_limit_seconds'] ?? 0));
            $opens = college_exams_format_datetime(isset($e['available_from']) ? (string)$e['available_from'] : null, 'Immediate');
            $closes = college_exams_format_datetime(isset($e['deadline']) ? (string)$e['deadline'] : null);
            $desc = trim((string)($e['description'] ?? ''));
            $descText = $desc !== '' ? $desc : 'No instructions provided.';
            $st = (string)($e['attempt_status'] ?? '');
            $scoreHtml = '<span class="text-slate-400 text-xs font-semibold">-</span>';
            if ($st === 'submitted' || ($st === 'expired' && !empty($e['submitted_at']))) {
                $scoreLine = college_exam_format_score_total_line(
                    isset($e['correct_count']) ? (int)$e['correct_count'] : null,
                    isset($e['total_count']) ? (int)$e['total_count'] : null,
                    $e['score'] ?? null,
                    (int)($e['_q_count'] ?? 0)
                );
                $scoreHtml = '<span class="score-cell-text">' . h($scoreLine) . '</span>';
            }
            $actionMode = (string)($e['_action_mode'] ?? 'closed');
            $actionUrl = (string)($e['_action_url'] ?? '');
            $actionLabel = (string)($e['_action_label'] ?? 'Closed');
          ?>
            <article class="<?php echo h($cardClass); ?>">
              <div class="card-head">
                <div>
                  <h3 class="card-title"><?php echo h((string)($e['title'] ?? 'Untitled')); ?></h3>
                  <p class="card-desc" title="<?php echo h($descText); ?>"><?php echo h($descText); ?></p>
                </div>
                <span class="type-pill <?php echo h($typeClass); ?>"><?php echo h($typeLabel); ?></span>
              </div>
              <div class="schedule-grid">
                <div><div class="meta-k">Opens</div><div class="meta-v"><?php echo h($opens); ?></div></div>
                <div><div class="meta-k">Closes</div><div class="meta-v"><?php echo h($closes); ?></div></div>
                <div><div class="meta-k">Duration</div><div class="meta-v is-highlight"><?php echo h($duration); ?></div></div>
              </div>
              <div class="schedule-grid">
                <div><div class="meta-k">Status</div><div class="meta-v"><span class="status-pill <?php echo h($statusClass); ?>"><i class="bi <?php echo h($statusIcon); ?>"></i> <?php echo h($statusLabel); ?></span></div></div>
                <div><div class="meta-k">Score</div><div class="meta-v"><?php echo $scoreHtml; ?></div></div>
                <div><div class="meta-k">Questions</div><div class="meta-v is-highlight"><?php echo (int)($e['_q_count'] ?? 0); ?></div></div>
              </div>
              <div class="card-footer">
                <span class="text-[.72rem] text-slate-500 font-semibold"><i class="bi bi-shield-check"></i> Assigned via professor examination</span>
                <div class="card-actions">
                  <?php if (in_array($actionMode, ['start', 'continue'], true) && $actionUrl !== ''): ?>
                    <a class="action-btn action-start" href="<?php echo h($actionUrl); ?>"><i class="bi <?php echo $actionMode === 'continue' ? 'bi-arrow-right-circle' : 'bi-play-fill'; ?>"></i> <?php echo h($actionLabel); ?></a>
                  <?php elseif ($actionMode === 'review' && $actionUrl !== ''): ?>
                    <a class="action-btn action-review" href="<?php echo h($actionUrl); ?>"><i class="bi bi-eye"></i> <?php echo h($actionLabel); ?></a>
                  <?php elseif ($actionMode === 'none'): ?>
                    <span class="action-muted"><?php echo h($actionLabel); ?></span>
                  <?php else: ?>
                    <span class="action-closed-pill"><i class="bi bi-slash-circle"></i> <?php echo h($actionLabel); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
