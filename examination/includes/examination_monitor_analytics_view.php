<?php
/** College-exam item analytics tab panes. Expects $itemAnalytics, $collegeSectionFilter, $isCollege. */
if (empty($isCollege) || !isset($itemAnalytics) || !is_array($itemAnalytics)) {
    return;
}
require_once dirname(__DIR__, 2) . '/includes/quiz_helpers.php';
$ana = $itemAnalytics;
$anaEmpty = is_array($ana['empty'] ?? null) ? $ana['empty'] : [];
$anaCssFile = dirname(__DIR__, 2) . '/assets/css/examination-monitor-analytics.css';
$anaChartFile = dirname(__DIR__, 2) . '/assets/vendor/chart.js/4.4.1/chart.umd.min.js';
$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$anaBase = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if (preg_match('#/examination/(?:professor|examinee)$#', $anaBase)) {
    $anaBase = (string)preg_replace('#/examination/(?:professor|examinee)$#', '', $anaBase);
}
if ($anaBase === '.' || $anaBase === '') {
    $anaBase = '';
}
$anaCssHref = rtrim($anaBase, '/') . '/assets/css/examination-monitor-analytics.css';
$anaChartHref = rtrim($anaBase, '/') . '/assets/vendor/chart.js/4.4.1/chart.umd.min.js';
$secFilter = (string)($collegeSectionFilter ?? '');
$secBadge = $secFilter === ''
    ? 'All sections'
    : ($secFilter === '__none__' ? 'No section' : $secFilter);
$avgLabel = $ana['avg_score'] !== null ? number_format((float)$ana['avg_score'], 1) . '%' : '—';
$accLabel = examination_monitor_analytics_pct_label($ana['totals']['accuracy'] ?? null);
$passRateLabel = (string)($ana['passing_rate_label'] ?? '—');
$weakest = is_array($ana['weakest_subject'] ?? null) ? $ana['weakest_subject'] : null;
$pctHeat = static function (?float $pct): string {
    if ($pct === null) {
        return 'pem-analytics__heat pem-analytics__heat--na';
    }
    if ($pct < 50.0) {
        return 'pem-analytics__heat pem-analytics__heat--bad';
    }
    if ($pct < 70.0) {
        return 'pem-analytics__heat pem-analytics__heat--mid';
    }

    return 'pem-analytics__heat pem-analytics__heat--ok';
};
$meterFill = static function (?float $pct): string {
    if ($pct === null) {
        return 'pem-meter__fill pem-meter__fill--na';
    }
    if ($pct < 50.0) {
        return 'pem-meter__fill pem-meter__fill--bad';
    }
    if ($pct < 70.0) {
        return 'pem-meter__fill pem-meter__fill--mid';
    }

    return 'pem-meter__fill pem-meter__fill--ok';
};
$meter = static function (?float $pct, int $n, string $label = 'Accuracy') use ($meterFill): string {
    $n = max(0, $n);
    if ($pct === null) {
        $aria = $label . ' unavailable (no answered items), n=' . $n;
        $val = '—';
        $w = '0%';
    } else {
        $clamped = max(0.0, min(100.0, (float)$pct));
        $val = number_format($clamped, 1) . '%';
        $w = $clamped . '%';
        $aria = $label . ' ' . $val . ', n=' . $n;
    }

    return '<div class="pem-meter" role="img" aria-label="' . h($aria) . '">'
        . '<span class="pem-meter__track"><span class="' . h($meterFill($pct)) . '" style="width:' . h($w) . '"></span></span>'
        . '<span class="pem-meter__val">' . h($val) . '</span>'
        . '<span class="pem-meter__n">n=' . $n . '</span>'
        . '</div>';
};
$topicsAll = $ana['topics'] ?? [];
$questionsAll = $ana['questions'] ?? [];
$topicsNeed = [];
foreach (($ana['weak_topics'] ?? []) as $wt) {
    $topicsNeed[] = $wt;
}
if ($topicsNeed === []) {
    foreach ($topicsAll as $tRow) {
        if (count($topicsNeed) >= 5) {
            break;
        }
        $topicsNeed[] = $tRow;
    }
}
$examineeProfiles = [];
foreach ($ana['examinee_topics'] ?? [] as $exRow) {
    $exUid = (int)($exRow['user_id'] ?? 0);
    if ($exUid <= 0) {
        continue;
    }
    if (!isset($examineeProfiles[$exUid])) {
        $examineeProfiles[$exUid] = [
            'user_id' => $exUid,
            'full_name' => (string)($exRow['full_name'] ?? ''),
            'student_number' => (string)($exRow['student_number'] ?? ''),
            'section' => (string)($exRow['section'] ?? ''),
            'exam_score_label' => (string)($exRow['exam_score_label'] ?? '—'),
            'weak_count' => 0,
            'topics' => [],
        ];
    }
    $examineeProfiles[$exUid]['topics'][] = $exRow;
    if (!empty($exRow['weak'])) {
        $examineeProfiles[$exUid]['weak_count']++;
    }
}
if (is_file($anaCssFile) && empty($pemAnalyticsCssLinked)) {
    echo '<link rel="stylesheet" href="' . h($anaCssHref) . '?v=' . filemtime($anaCssFile) . '">' . "\n";
}
?>
<?php /* Drill filters are in pem-chrome: id="pemAnaFilterSubject" id="pemAnaFilterReset" id="pemAnaSubnav" */ ?>
<section class="pem-analytics pem-tab-pane" id="pemTab-overview" data-pem-tab="analytics" data-pem-ana="overview" hidden aria-labelledby="pemAnalyticsTitle">
  <div class="pem-analytics__head">
    <div>
      <h2 class="pem-analytics__title" id="pemAnalyticsTitle">Overview</h2>
      <p class="pem-analytics__sub">Submitted attempts only. Exam score uses paper total (unanswered counts as not correct). Answer accuracy uses answered A–D items. Weak = ≥3 answered and accuracy below 50%.</p>
    </div>
    <span class="pem-analytics__scope" aria-live="polite">Scope: <?php echo h($secBadge); ?></span>
  </div>

  <?php if (!empty($anaEmpty['no_section_data'])): ?>
    <p class="pem-analytics__empty">No submitted attempts in this section yet. Choose All sections or another section to see results.</p>
  <?php endif; ?>

  <div class="pem-analytics__kpis" role="group" aria-label="Exam analytics summary">
    <div class="pem-analytics__kpi pem-analytics__kpi--hero">
      <span class="pem-analytics__kpi-label">Average exam score</span>
      <span class="pem-analytics__kpi-value"><?php echo h($avgLabel); ?></span>
      <span class="pem-analytics__kpi-hint">Paper total, unanswered counts as not correct</span>
    </div>
    <div class="pem-analytics__kpi">
      <span class="pem-analytics__kpi-label">Submitted attempts</span>
      <span class="pem-analytics__kpi-value"><?php echo (int)$ana['submitted_count']; ?></span>
      <span class="pem-analytics__kpi-hint">In this section scope</span>
    </div>
    <div class="pem-analytics__kpi">
      <span class="pem-analytics__kpi-label">Passing rate</span>
      <span class="pem-analytics__kpi-value"><?php echo h($passRateLabel); ?></span>
      <span class="pem-analytics__kpi-hint"><?php echo (int)$ana['pass_count']; ?> pass · <?php echo (int)$ana['fail_count']; ?> fail · submitted denominator</span>
    </div>
    <div class="pem-analytics__kpi">
      <span class="pem-analytics__kpi-label">Weakest subject</span>
      <?php if ($weakest): ?>
        <span class="pem-analytics__kpi-value pem-analytics__kpi-value--sm"><?php echo h((string)$weakest['name']); ?></span>
        <span class="pem-analytics__kpi-hint"><?php echo h((string)$weakest['accuracy_label']); ?> accuracy · n=<?php echo (int)$weakest['answered']; ?></span>
      <?php else: ?>
        <span class="pem-analytics__kpi-value">—</span>
        <span class="pem-analytics__kpi-hint">Needs ≥3 answered items</span>
      <?php endif; ?>
    </div>
  </div>
  <p class="pem-analytics__hint">Answer accuracy (this scope): <strong><?php echo h($accLabel); ?></strong> of answered A–D items. Exam score and answer accuracy are not interchangeable.</p>

  <div class="pem-ana-dash">
    <div class="pem-analytics__card pem-ana-dash__hist">
      <div class="pem-card-head">
        <h3>Score distribution</h3>
        <span class="pem-card-head__meta">Exam score buckets · paper total</span>
      </div>
      <?php if ((int)$ana['submitted_count'] <= 0): ?>
        <p class="pem-analytics__empty">No submitted attempts yet, so there is no score distribution.</p>
      <?php else: ?>
        <div class="pem-analytics__chart pem-analytics__chart--hero"><canvas id="pemAnaHist" aria-label="Score distribution histogram"></canvas></div>
        <table class="pem-analytics__table vis-hidden" id="pemAnaHistTable"><caption>Traditional exam-score buckets</caption>
          <thead><tr><th>Range</th><th>Students</th></tr></thead>
          <tbody>
          <?php foreach ($ana['histogram'] as $b): ?>
            <tr><td><?php echo h((string)$b['label']); ?></td><td><?php echo (int)$b['count']; ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <div class="pem-analytics__card pem-ana-dash__need">
      <div class="pem-card-head">
        <h3>Priority topics</h3>
        <span class="pem-card-head__meta">Highest incorrect rate first</span>
      </div>
      <?php if ($topicsNeed === []): ?>
        <p class="pem-analytics__empty">No topic ranking for this scope yet.</p>
      <?php else: ?>
      <ol class="pem-rank-list" id="pemAnaNeedList">
        <?php foreach ($topicsNeed as $ri => $tn): ?>
          <?php
            $tnN = (int)($tn['answered'] ?? 0);
            $tnAcc = $tn['accuracy'] ?? null;
          ?>
          <li class="pem-rank-list__item<?php echo !empty($tn['weak']) ? ' is-weak' : ''; ?>">
            <span class="pem-rank-list__pos"><?php echo (int)$ri + 1; ?></span>
            <div class="pem-rank-list__body">
              <div class="pem-rank-list__title"><?php echo h((string)($tn['name'] ?? '')); ?><?php echo !empty($tn['unassigned']) ? ' <span class="pem-analytics__n">Unassigned</span>' : ''; ?></div>
              <div class="pem-rank-list__meta"><?php echo h((string)($tn['subject_name'] ?? '')); ?> · <?php echo $tnN; ?> answered · incorrect <?php echo h((string)($tn['incorrect_rate_label'] ?? '—')); ?></div>
              <?php echo $meter(is_numeric($tnAcc) ? (float)$tnAcc : null, $tnN); ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </div>
    <?php if ($ana['subjects'] !== []): ?>
    <div class="pem-analytics__card pem-ana-dash__subjects" id="pemAnaOverviewSubjects">
      <div class="pem-card-head">
        <h3>Subject accuracy</h3>
        <span class="pem-card-head__meta">Answered A–D items only</span>
      </div>
      <ul class="pem-bar-list">
        <?php foreach (array_slice($ana['subjects'], 0, 8) as $sBar): ?>
          <li class="pem-bar-list__item" data-ana-subject="<?php echo h((string)$sBar['key']); ?>">
            <div class="pem-bar-list__label">
              <strong><?php echo h((string)$sBar['name']); ?></strong>
              <span><?php echo (int)$sBar['answered']; ?> answered · <?php echo (int)$sBar['unanswered']; ?> unanswered</span>
            </div>
            <?php echo $meter($sBar['accuracy'] ?? null, (int)$sBar['answered']); ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>

  <?php
    require_once __DIR__ . '/examination_domain.php';
    $accessRow = is_array($ctx['row'] ?? null) ? $ctx['row'] : [];
    $accessNow = isset($now) && is_string($now) && $now !== '' ? $now : date('Y-m-d H:i:s');
    $accessWindow = [
        'is_running' => !empty($isRunning),
        'is_finished' => !empty($isFinished),
        'all_finished_open' => !empty($allFinishedOpenExam),
    ];
    $accessSum = examination_monitor_exam_access_summary($accessRow, $accessWindow, $accessNow);
    $accessOpens = examination_monitor_format_dt($accessRow['available_from'] ?? null);
    $accessCloses = examination_monitor_format_dt($accessRow['deadline'] ?? null);
    $accessLimitSec = (int)($accessRow['time_limit_seconds'] ?? 0);
    $accessLimit = function_exists('examination_format_time_limit_display')
        ? examination_format_time_limit_display($accessLimitSec)
        : (($accessLimitSec > 0) ? (string)$accessLimitSec . 's' : '—');
    $accessEditUrl = examination_domain_edit_url('regular', (int)($examIdSafe ?? $ctx['assessment_id'] ?? 0), 'config');
    $accessPublishUrl = examination_domain_edit_url('regular', (int)($examIdSafe ?? $ctx['assessment_id'] ?? 0), 'review');
    $accessBadgeClass = match ((string)$accessSum['access_key']) {
        'open' => 'admin-badge--success',
        'scheduled' => 'admin-badge--warning',
        'closed' => 'admin-badge--danger',
        default => 'admin-badge--neutral',
    };
  ?>
  <details class="pem-analytics__card pem-access-card" id="pemOverviewAccess">
    <summary class="pem-review-card__head">
      <h3>Exam access</h3>
      <span class="admin-badge <?php echo h($accessBadgeClass); ?>"><?php echo h((string)$accessSum['access_label']); ?></span>
    </summary>
    <p class="pem-analytics__hint pem-access-sub">When students can start the exam. This monitor does not start attempts.</p>
    <div class="pem-access-states">
      <div>
        <span class="pem-access-states__k">Publish status</span>
        <?php if (!empty($accessSum['published'])): ?>
          <span class="admin-badge admin-badge--success"><?php echo h((string)$accessSum['publish_label']); ?></span>
        <?php else: ?>
          <span class="admin-badge admin-badge--neutral"><?php echo h((string)$accessSum['publish_label']); ?></span>
        <?php endif; ?>
      </div>
      <div>
        <span class="pem-access-states__k">Student access</span>
        <span class="admin-badge <?php echo h($accessBadgeClass); ?>"><?php echo h((string)$accessSum['access_label']); ?></span>
      </div>
    </div>
    <p class="pem-analytics__hint"><?php echo h((string)$accessSum['access_detail']); ?></p>
    <dl class="pem-access-dl">
      <div><dt>Opens</dt><dd><?php echo $accessOpens !== '' ? h($accessOpens) : 'No open time set (available once published)'; ?></dd></div>
      <div><dt>Closes</dt><dd><?php echo $accessCloses !== '' ? h($accessCloses) : 'No close time set'; ?></dd></div>
      <div><dt>Time limit per attempt</dt><dd><?php echo h($accessLimit); ?></dd></div>
    </dl>
    <div class="flex flex-wrap gap-2 mt-2">
      <a class="admin-btn admin-btn--primary admin-btn--sm" href="<?php echo h($accessEditUrl); ?>"><i class="bi bi-sliders"></i> Schedule &amp; access</a>
      <a class="admin-btn admin-btn--ghost admin-btn--sm" href="<?php echo h($accessPublishUrl); ?>"><i class="bi bi-pencil-square"></i> Review / Publish in editor</a>
    </div>
  </details>

  <?php if ($secFilter === '' && $ana['section_comparisons'] !== []): ?>
  <div class="pem-analytics__card" id="pemAnaSectionCard">
    <h3>Section comparison</h3>
    <p class="pem-analytics__hint">Current section membership. Exam score uses paper total; accuracy uses answered items.</p>
    <div class="pem-analytics__table-wrap">
      <table class="pem-analytics__table">
        <thead>
          <tr><th>Section</th><th>Submitted</th><th>Exam score</th><th>Pass</th><th>Fail</th><th>Answer accuracy</th></tr>
        </thead>
        <tbody>
        <?php foreach ($ana['section_comparisons'] as $sc): ?>
          <tr>
            <td><?php echo h((string)$sc['name']); ?></td>
            <td><?php echo (int)$sc['submitted_count']; ?></td>
            <td><?php echo h((string)$sc['avg_score_label']); ?></td>
            <td><?php echo (int)$sc['pass_count']; ?></td>
            <td><?php echo (int)$sc['fail_count']; ?></td>
            <td><span class="<?php echo h($pctHeat($sc['accuracy'] ?? null)); ?>"><?php echo h((string)$sc['accuracy_label']); ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php elseif ($secFilter !== '' && empty($anaEmpty['no_section_data'])): ?>
    <div class="pem-analytics__card pem-analytics__card--note">
      <h3>Selected section</h3>
      <p class="pem-analytics__empty" style="padding-top:0">Showing <strong><?php echo h($secBadge); ?></strong> only. Cross-section comparison is hidden so another section’s responses cannot mix into these charts and rankings.</p>
    </div>
  <?php endif; ?>

  <?php if (!empty($ctx['supports_review_sheet'])): ?>
  <details class="pem-analytics__card pem-review-card" id="pemOverviewReview">
    <summary class="pem-review-card__head">
      <h3>Review sheet access</h3>
      <?php if ($reviewAccessStatus === 'no_schedule'): ?>
        <span class="admin-badge admin-badge--neutral">Locked</span>
      <?php elseif ($reviewAccessStatus === 'pending'): ?>
        <span class="admin-badge admin-badge--warning">Scheduled</span>
      <?php elseif ($reviewAccessStatus === 'open'): ?>
        <span class="admin-badge admin-badge--success">Open</span>
      <?php else: ?>
        <span class="admin-badge admin-badge--danger">Ended</span>
      <?php endif; ?>
    </summary>
    <p class="pem-analytics__hint pem-access-sub">After students submit — when they can open the full review sheet. This does not control who can start the exam.</p>
    <p class="pem-analytics__hint">
      <?php if ($reviewAccessStatus === 'no_schedule'): ?>
        No schedule set — review sheet is locked. Students still see a results summary after submit.
      <?php elseif ($reviewAccessStatus === 'pending'): ?>
        Scheduled — opens <?php echo h(examination_monitor_format_dt($ctx['row']['review_sheet_available_from'] ?? null)); ?>
      <?php elseif ($reviewAccessStatus === 'open'): ?>
        Open now for students who have submitted
      <?php else: ?>
        Window ended
      <?php endif; ?>
    </p>
    <?php if (!empty($reviewScheduleEligible)): ?>
    <form method="post" action="professor_examination_monitor?exam_type=regular&amp;exam_id=<?php echo (int)$examIdSafe; ?>" class="pem-review-form">
      <input type="hidden" name="csrf_token" value="<?php echo h($monitorCsrf); ?>">
      <input type="hidden" name="action" value="save_review_access">
      <input type="hidden" name="exam_id" value="<?php echo (int)$examIdSafe; ?>">
      <?php if ($collegeSectionFilter !== ''): ?>
        <input type="hidden" name="section" value="<?php echo h($collegeSectionFilter); ?>">
      <?php endif; ?>
      <div class="review-sched-grid">
        <div>
          <div class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Review opens</div>
          <input class="w-full" type="datetime-local" name="review_sheet_from" value="<?php echo h($reviewFromLocal); ?>" required>
        </div>
        <div>
          <div class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Review closes (optional)</div>
          <input class="w-full" type="datetime-local" name="review_sheet_until" value="<?php echo h($reviewUntilLocal); ?>">
        </div>
      </div>
      <div class="flex flex-wrap gap-2 mt-2">
        <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-check2-circle"></i> Save schedule</button>
        <button type="submit" name="clear_review_schedule" value="1" class="admin-btn admin-btn--ghost admin-btn--sm" formnovalidate onclick="return confirm('Clear review schedule? Students will only see the summary until you set a new window.');"><i class="bi bi-x-lg"></i> Clear schedule</button>
      </div>
    </form>
    <?php else: ?>
      <p class="pem-analytics__hint">The review-sheet window can be set here after the exam is finished or everyone on the roster has submitted.</p>
    <?php endif; ?>
  </details>
  <?php endif; ?>
</section>

<section class="pem-tab-pane" id="pemTab-analytics" data-pem-tab="analytics" data-pem-ana="subjects" hidden aria-labelledby="pemAnaSubjectsTitle">
<section class="pem-analytics" id="pemTab-subjects">
  <div class="pem-analytics__head">
    <div>
      <h2 class="pem-analytics__title" id="pemAnaSubjectsTitle"><i class="bi bi-bar-chart" aria-hidden="true"></i> Subject &amp; topic analysis</h2>
      <p class="pem-analytics__sub">Lowest answer accuracy first. Uncategorized snapshot labels stay visible. Accuracy uses answered A–D items only.</p>
    </div>
    <span class="pem-analytics__scope">Scope: <?php echo h($secBadge); ?></span>
  </div>

  <?php if (!empty($anaEmpty['no_submitted'])): ?>
    <p class="pem-analytics__empty">No submitted attempts to analyze.</p>
  <?php elseif (!empty($anaEmpty['no_snapshots'])): ?>
    <p class="pem-analytics__empty">These submitted attempts have no paper snapshots, so subject and question analytics cannot use the live question bank.</p>
  <?php elseif (!empty($anaEmpty['overall_mode']) && empty($ana['has_uncategorized']) && $ana['subjects'] === []): ?>
    <p class="pem-analytics__empty">This exam is in overall mode with no subject metadata on the presented papers.</p>
  <?php elseif (!empty($anaEmpty['zero_answered'])): ?>
    <p class="pem-analytics__empty">Questions were presented, but none have a saved A–D answer yet. Accuracy is — (not 0%).</p>
  <?php endif; ?>

  <?php if ($ana['subjects'] !== []): ?>
  <article class="pem-insight" id="pemAnaSubjectCard">
    <div class="pem-insight__chart pem-analytics__card">
      <div class="pem-card-head">
        <h3>Subject accuracy</h3>
        <span class="pem-card-head__meta">Horizontal bars · n on each label</span>
      </div>
      <div class="pem-analytics__chart pem-analytics__chart--hero"><canvas id="pemAnaSubj" aria-label="Subject answer-accuracy chart"></canvas></div>
    </div>
    <div class="pem-insight__list pem-analytics__card">
      <div class="pem-card-head">
        <h3>Ranked subjects</h3>
        <span class="pem-card-head__meta">Lowest accuracy first</span>
      </div>
      <div class="pem-analytics__table-wrap">
        <table class="pem-analytics__table" id="pemAnaSubjectTable">
          <thead>
            <tr>
              <th>Subject</th><th>n</th><th>Unanswered</th><th>Accuracy</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($ana['subjects'] as $s): ?>
            <tr data-ana-subject="<?php echo h((string)$s['key']); ?>">
              <td>
                <?php echo h((string)$s['name']); ?><?php echo !empty($s['unassigned']) ? ' <span class="pem-analytics__n">Uncategorized</span>' : ''; ?>
                <span class="pem-analytics__meta"><?php echo (int)$s['correct']; ?> correct · <?php echo (int)$s['incorrect']; ?> incorrect · <?php echo (int)$s['presented']; ?> presented</span>
              </td>
              <td><?php echo (int)$s['answered']; ?></td>
              <td><?php echo (int)$s['unanswered']; ?></td>
              <td><?php echo $meter($s['accuracy'] ?? null, (int)$s['answered']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </article>
  <?php endif; ?>

  <?php if (!empty($ana['has_topics']) && $topicsAll !== []): ?>
  <article class="pem-insight" id="pemAnaTopicCard">
    <div class="pem-insight__chart pem-analytics__card">
      <div class="pem-card-head">
        <h3>Topic accuracy</h3>
        <span class="pem-card-head__meta">Answered A–D items · sample size in labels</span>
      </div>
      <div class="pem-analytics__chart pem-analytics__chart--hero"><canvas id="pemAnaTopic" aria-label="Topic answer-accuracy chart"></canvas></div>
    </div>
    <div class="pem-insight__list pem-analytics__card">
      <div class="pem-card-head">
        <h3>Ranked topics</h3>
        <span class="pem-card-head__meta">Incorrect rate and accuracy</span>
      </div>
      <div class="pem-analytics__table-wrap">
        <table class="pem-analytics__table" id="pemAnaTopicTable">
          <thead>
            <tr><th>Topic</th><th>n</th><th>Incorrect rate</th><th>Accuracy</th></tr>
          </thead>
          <tbody>
          <?php foreach ($topicsAll as $t): ?>
            <tr data-ana-subject="<?php echo h((string)$t['subject_key']); ?>" data-ana-topic="<?php echo h((string)$t['key']); ?>"<?php echo !empty($t['weak']) ? ' class="pem-ana-weak"' : ''; ?>>
              <td>
                <?php echo h((string)$t['name']); ?><?php echo !empty($t['unassigned']) ? ' <span class="pem-analytics__n">Uncategorized</span>' : ''; ?>
                <span class="pem-analytics__meta"><?php echo h((string)$t['subject_name']); ?> · <?php echo (int)$t['incorrect']; ?> incorrect</span>
              </td>
              <td><?php echo (int)$t['answered']; ?></td>
              <td><span class="<?php echo h($pctHeat($t['incorrect_rate'] !== null ? (100 - (float)$t['incorrect_rate']) : null)); ?>"><?php echo h((string)$t['incorrect_rate_label']); ?></span></td>
              <td><?php echo $meter($t['accuracy'] ?? null, (int)$t['answered']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </article>
  <?php elseif (!empty($anaEmpty['overall_mode'])): ?>
    <p class="pem-analytics__empty">Topic ranking is hidden because this exam is not in subject → topic mode.</p>
  <?php endif; ?>
</section>
</section>

<section class="pem-analytics pem-tab-pane" id="pemTab-questions" data-pem-tab="analytics" data-pem-ana="questions" hidden aria-labelledby="pemAnaQuestionsTitle">
  <div class="pem-analytics__head">
    <div>
      <h2 class="pem-analytics__title" id="pemAnaQuestionsTitle">Question analysis</h2>
      <p class="pem-analytics__sub">Answered n is A–D responses. Accuracy = correct / answered. Unanswered = presented − answered. A–D counts are displayed letters.</p>
    </div>
    <span class="pem-analytics__scope">Scope: <?php echo h($secBadge); ?></span>
  </div>

  <?php if ($questionsAll !== []): ?>
  <div class="pem-q-board" id="pemAnaQuestionCard">
    <div class="pem-analytics__card pem-q-board__list">
      <div class="pem-ana-table-tools">
        <label class="pem-analytics__flab" for="pemAnaQuestionSearch">Search</label>
        <input type="search" id="pemAnaQuestionSearch" class="pem-ana-search" placeholder="Question, subject, or topic" autocomplete="off">
        <span class="pem-analytics__hint pem-analytics__hint--inline">Select a question for A–D distribution. Expand a row for counts.</span>
      </div>
      <div class="pem-analytics__table-wrap">
        <table class="pem-analytics__table pem-analytics__table--sortable pem-analytics__table--rows pem-q-table" id="pemAnaQuestionTable">
          <colgroup>
            <col class="pem-q-col-q">
            <col class="pem-q-col-n">
            <col class="pem-q-col-n">
            <col class="pem-q-col-n">
            <col class="pem-q-col-acc">
          </colgroup>
          <thead>
            <tr>
              <th data-sort="text">Question</th>
              <th data-sort="num">Presented</th>
              <th data-sort="num">Answered (n)</th>
              <th data-sort="num">Unanswered</th>
              <th data-sort="num">Accuracy</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($questionsAll as $q): ?>
            <?php
              $qSearch = strtolower(trim((string)($q['stem_plain'] ?? $q['stem']) . ' ' . (string)$q['subject_name'] . ' ' . (string)($q['topic_name'] ?? '')));
              $qChoices = is_array($q['choices'] ?? null) ? $q['choices'] : ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
              $qChoiceSum = (int)($qChoices['A'] ?? 0) + (int)($qChoices['B'] ?? 0) + (int)($qChoices['C'] ?? 0) + (int)($qChoices['D'] ?? 0);
            ?>
            <tr tabindex="0" data-ana-subject="<?php echo h((string)$q['subject_key']); ?>" data-ana-topic="<?php echo h((string)$q['topic_key'] ?? ''); ?>" data-ana-q="<?php echo (int)$q['question_id']; ?>" data-ana-search="<?php echo h($qSearch); ?>">
              <td data-sort-value="<?php echo h((string)$q['stem']); ?>">
                <details class="pem-q-item">
                  <summary>
                    <span class="pem-analytics__qstem"><?php echo h((string)$q['stem']); ?></span>
                    <span class="pem-analytics__meta"><?php echo h((string)$q['subject_name']); ?><?php echo !empty($ana['has_topics']) ? ' · ' . h((string)$q['topic_name']) : ''; ?></span>
                  </summary>
                  <div class="pem-q-item__expand">
                    <span>Correct <?php echo (int)($q['correct'] ?? 0); ?></span>
                    <span>Incorrect <?php echo (int)($q['incorrect'] ?? 0); ?></span>
                    <span>Unanswered <?php echo (int)$q['unanswered']; ?></span>
                    <?php if ($qChoiceSum > 0): ?>
                      <span class="pem-q-dist" aria-label="Displayed choice counts">
                        A <?php echo (int)($qChoices['A'] ?? 0); ?>
                        · B <?php echo (int)($qChoices['B'] ?? 0); ?>
                        · C <?php echo (int)($qChoices['C'] ?? 0); ?>
                        · D <?php echo (int)($qChoices['D'] ?? 0); ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </details>
              </td>
              <td data-sort-value="<?php echo (int)$q['presented']; ?>"><?php echo (int)$q['presented']; ?></td>
              <td data-sort-value="<?php echo (int)$q['answered']; ?>"><?php echo (int)$q['answered']; ?></td>
              <td data-sort-value="<?php echo (int)$q['unanswered']; ?>"><?php echo (int)$q['unanswered']; ?></td>
              <td data-sort-value="<?php echo $q['accuracy'] === null ? -1 : (float)$q['accuracy']; ?>"><?php echo $meter($q['accuracy'] ?? null, (int)$q['answered']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="pem-analytics__empty pem-ana-none" id="pemAnaQuestionEmpty" hidden>No questions match the current filters.</p>
    </div>
    <aside class="pem-analytics__card pem-ana-detail pem-q-board__detail" id="pemAnaQuestionDetail">
      <div class="pem-card-head">
        <h3 id="pemAnaQuestionDetailTitle">Select a question</h3>
        <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="pemAnaQuestionDetailClose">Clear</button>
      </div>
      <div class="pem-q-detail__html" id="pemAnaQuestionDetailBody" hidden></div>
      <p class="pem-analytics__hint" id="pemAnaQuestionDetailMeta">Choose a row to see counts and displayed A–D letters.</p>
      <div class="pem-stat-pills" id="pemAnaQuestionDetailPills" aria-label="Correct, incorrect, and unanswered counts"></div>
      <div class="pem-card-head">
        <h3>Displayed choice distribution</h3>
        <span class="pem-card-head__meta">Letters as shown on the paper</span>
      </div>
      <div class="pem-analytics__chart pem-analytics__chart--md"><canvas id="pemAnaChoice" aria-label="Displayed A to D choice counts"></canvas></div>
    </aside>
  </div>
    <div id="pemAnaQuestionHtmlStore" hidden>
      <?php foreach ($questionsAll as $qHtml): ?>
        <div data-ana-q-html="<?php echo (int)$qHtml['question_id']; ?>"><?php echo renderQuizRichText((string)($qHtml['question_html'] ?? '')); ?></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="pem-analytics__empty">No presented questions to rank for this section.</p>
  <?php endif; ?>
</section>

<section class="pem-analytics pem-tab-pane" id="pemTab-examinees" data-pem-tab="analytics" data-pem-ana="examinees" hidden aria-labelledby="pemAnaExamineeTitle">
  <div class="pem-analytics__head">
    <div>
      <h2 class="pem-analytics__title" id="pemAnaExamineeTitle">Student weaknesses</h2>
      <p class="pem-analytics__sub">Submitted papers only. Accuracy uses answered A–D items. Weak = ≥3 answered and accuracy below 50%. Exam score is paper total and is not the weak flag. Select a student for topic detail.</p>
    </div>
    <span class="pem-analytics__scope">Scope: <?php echo h($secBadge); ?></span>
  </div>

  <?php if (!empty($anaEmpty['no_submitted'])): ?>
    <p class="pem-analytics__empty">No submitted attempts to analyze.</p>
  <?php elseif (!empty($anaEmpty['no_snapshots'])): ?>
    <p class="pem-analytics__empty">These submitted attempts have no paper snapshots, so examinee subject and topic rows cannot be built from the live question bank.</p>
  <?php elseif (($ana['examinee_topics'] ?? []) === []): ?>
    <p class="pem-analytics__empty">No snapshot topic rows for this section.</p>
  <?php else: ?>
  <div class="pem-weak-dash" id="pemAnaExamineeCard">
    <div class="pem-weak-dash__list">
      <div class="pem-card-head">
        <h3>Student performance</h3>
        <span class="pem-card-head__meta">Weak = n≥3 answered and accuracy below 50%. Exam score is paper total.</span>
      </div>
      <div class="pem-weak-cards" id="pemAnaExamineeCards">
        <?php foreach ($examineeProfiles as $exProf): ?>
          <?php
            $exSubKeys = [];
            $exTopKeys = [];
            foreach ($exProf['topics'] as $exTopic) {
                $exSubKeys[(string)($exTopic['subject_key'] ?? '')] = true;
                $exTopKeys[(string)($exTopic['topic_key'] ?? '')] = true;
            }
          ?>
          <button type="button" class="pem-weak-card" data-ana-open-user="<?php echo (int)$exProf['user_id']; ?>" data-ana-user="<?php echo (int)$exProf['user_id']; ?>" data-ana-subjects="<?php echo h(implode(' ', array_keys($exSubKeys))); ?>" data-ana-topics="<?php echo h(implode(' ', array_keys($exTopKeys))); ?>">
            <span class="pem-weak-card__name"><?php echo h((string)$exProf['full_name']); ?></span>
            <span class="pem-weak-card__meta"><?php echo h((string)$exProf['section']); ?><?php echo (string)$exProf['student_number'] !== '' ? ' · ' . h((string)$exProf['student_number']) : ''; ?></span>
            <span class="pem-weak-card__score"><?php echo h((string)$exProf['exam_score_label']); ?></span>
            <?php if ((int)$exProf['weak_count'] > 0): ?>
              <span class="admin-badge admin-badge--warning"><?php echo (int)$exProf['weak_count']; ?> weak topic<?php echo (int)$exProf['weak_count'] === 1 ? '' : 's'; ?></span>
            <?php else: ?>
              <span class="admin-badge admin-badge--success">No weak topics</span>
            <?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
      <div class="pem-analytics__table-wrap vis-hidden">
        <table class="pem-analytics__table pem-analytics__table--rows" id="pemAnaExamineeTable">
          <thead>
            <tr>
              <th>Student</th>
              <th>Topic</th>
              <th>n</th>
              <th>Accuracy</th>
              <th>Flag</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($ana['examinee_topics'] as $ex): ?>
            <tr data-ana-subject="<?php echo h((string)$ex['subject_key']); ?>" data-ana-topic="<?php echo h((string)($ex['topic_key'] ?? '')); ?>" data-ana-user="<?php echo (int)$ex['user_id']; ?>"<?php echo !empty($ex['weak']) ? ' class="pem-ana-weak"' : ''; ?>>
              <td>
                <button type="button" class="pem-ana-student-btn" data-ana-open-user="<?php echo (int)$ex['user_id']; ?>">
                  <span class="pem-analytics__qstem"><?php echo h((string)$ex['full_name']); ?></span>
                  <span class="pem-analytics__meta"><?php echo h((string)$ex['section']); ?><?php echo (string)($ex['student_number'] ?? '') !== '' ? ' · ' . h((string)$ex['student_number']) : ''; ?></span>
                </button>
              </td>
              <td>
                <?php echo h((string)$ex['topic_name']); ?>
                <span class="pem-analytics__meta"><?php echo h((string)$ex['subject_name']); ?> · <?php echo (int)$ex['unanswered']; ?> unanswered</span>
              </td>
              <td><?php echo (int)$ex['answered']; ?></td>
              <td><?php echo $meter($ex['accuracy'] ?? null, (int)$ex['answered']); ?></td>
              <td><?php echo !empty($ex['weak']) ? '<span class="admin-badge admin-badge--warning">Weak</span>' : '<span class="pem-analytics__n">—</span>'; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="pem-analytics__empty pem-ana-none" id="pemAnaExamineeEmpty" hidden>No examinee rows match the current subject/topic filter.</p>
    </div>
    <aside class="pem-analytics__card pem-ana-detail pem-weak-dash__detail" id="pemAnaStudentDetail">
      <div class="pem-card-head">
        <h3 id="pemAnaStudentDetailTitle">Select a student</h3>
        <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="pemAnaStudentDetailClose">Clear</button>
      </div>
      <p class="pem-analytics__hint" id="pemAnaStudentDetailMeta">Choose a student to see topic accuracy for this section.</p>
      <p class="pem-analytics__hint">Exam score (paper total): <strong id="pemAnaStudentDetailScore">—</strong></p>
      <ul class="pem-bar-list" id="pemAnaStudentDetailList"></ul>
      <div class="pem-analytics__table-wrap vis-hidden">
        <table class="pem-analytics__table" id="pemAnaStudentDetailTable">
          <thead>
            <tr><th>Topic</th><th>Presented</th><th>Answered</th><th>Correct</th><th>Incorrect</th><th>Unanswered</th><th>Accuracy</th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </aside>
  </div>
  <?php endif; ?>
</section>
<?php if (is_file($anaChartFile)): ?>
<script src="<?php echo h($anaChartHref); ?>?v=<?php echo filemtime($anaChartFile); ?>"></script>
<?php endif; ?>
<script>
(function () {
  var hist = <?php echo json_encode($ana['histogram'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
  var subjects = <?php echo json_encode($ana['subjects'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
  var topics = <?php echo json_encode($ana['topics'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
  var questions = <?php
    $questionsJs = [];
    foreach ($questionsAll as $qJs) {
        if (!is_array($qJs)) {
            continue;
        }
        unset($qJs['question_html']);
        $questionsJs[] = $qJs;
    }
    echo json_encode($questionsJs, JSON_UNESCAPED_UNICODE);
  ?>;
  var examineeProfiles = <?php echo json_encode($examineeProfiles, JSON_UNESCAPED_UNICODE); ?>;
  var questionsById = {};
  (questions || []).forEach(function (q) { questionsById[String(q.question_id)] = q; });
  var histEl = document.getElementById('pemAnaHist');
  var subjEl = document.getElementById('pemAnaSubj');
  var topicEl = document.getElementById('pemAnaTopic');
  var choiceEl = document.getElementById('pemAnaChoice');
  var subjChart = null;
  var histChart = null;
  var topicChart = null;
  var choiceChart = null;
  var gridColor = '#eef2f6';
  var tickColor = '#64748b';
  var axisColor = '#143d59';
  function pemChartTheme() {
    var cs = window.getComputedStyle(document.body);
    var g = (cs.getPropertyValue('--pem-chart-grid') || '').trim();
    var t = (cs.getPropertyValue('--pem-chart-tick') || '').trim();
    var a = (cs.getPropertyValue('--pem-chart-axis') || '').trim();
    gridColor = g || '#eef2f6';
    tickColor = t || '#64748b';
    axisColor = a || '#143d59';
  }
  function pemApplyScaleColors(chart) {
    if (!chart || !chart.options || !chart.options.scales) return;
    Object.keys(chart.options.scales).forEach(function (key) {
      var sc = chart.options.scales[key];
      if (!sc) return;
      if (sc.grid) sc.grid.color = gridColor;
      if (sc.ticks) {
        var numeric = (sc.min === 0 && sc.max === 100) || sc.beginAtZero;
        sc.ticks.color = numeric ? tickColor : axisColor;
      }
      if (sc.title) sc.title.color = tickColor;
    });
    if (chart.options.plugins && chart.options.plugins.legend && chart.options.plugins.legend.labels) {
      chart.options.plugins.legend.labels.color = tickColor;
    }
    if (chart.options.plugins && chart.options.plugins.tooltip) {
      chart.options.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.92)';
      chart.options.plugins.tooltip.titleColor = '#f8fafc';
      chart.options.plugins.tooltip.bodyColor = '#e2e8f0';
      chart.options.plugins.tooltip.borderColor = gridColor;
      chart.options.plugins.tooltip.borderWidth = 1;
    }
  }
  function pemRefreshCharts() {
    pemChartTheme();
    [histChart, subjChart, topicChart, choiceChart].forEach(function (ch) {
      if (!ch) return;
      pemApplyScaleColors(ch);
      ch.update('none');
    });
  }
  pemChartTheme();
  document.addEventListener('ereview:admin-theme-change', pemRefreshCharts);
  function accColor(v) {
    if (v == null || v === '') return '#cbd5e1';
    if (v < 50) return '#f59e0b';
    if (v < 70) return '#2563eb';
    return '#12b76a';
  }
  function barData(list) {
    return {
      labels: list.map(function (s) {
        var n = s.answered == null ? 0 : s.answered;
        return (s.name || '—') + ' (n=' + n + ')';
      }),
      datasets: [{
        label: 'Answer accuracy %',
        data: list.map(function (s) { return s.accuracy; }),
        backgroundColor: list.map(function (s) { return accColor(s.accuracy); }),
        borderRadius: 6,
        maxBarThickness: 22
      }]
    };
  }
  function hBarOptions(axisTitle) {
    return {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function (c) {
              return c.parsed.x == null ? 'No answered items (—)' : (c.parsed.x + '% of answered');
            }
          }
        }
      },
      scales: {
        x: {
          min: 0,
          max: 100,
          grid: { color: gridColor },
          ticks: { color: tickColor, callback: function (v) { return v + '%'; } },
          title: { display: true, text: axisTitle, color: tickColor, font: { size: 11 } }
        },
        y: { grid: { display: false }, ticks: { color: axisColor, font: { size: 11 } } }
      }
    };
  }
  function ensureHistChart() {
    if (histChart || !window.Chart || !histEl || !hist || !hist.length) return;
    histChart = new Chart(histEl, {
      type: 'bar',
      data: {
        labels: hist.map(function (b) { return b.label; }),
        datasets: [{
          label: 'Students',
          data: hist.map(function (b) { return b.count; }),
          backgroundColor: '#2563eb',
          borderRadius: 8,
          maxBarThickness: 36
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return (c.parsed.y || 0) + ' students'; } } } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0, color: tickColor }, grid: { color: gridColor } },
          x: { ticks: { color: axisColor, font: { size: 11 } }, grid: { display: false } }
        }
      }
    });
  }
  function ensureSubjChart() {
    if (subjChart || !window.Chart || !subjEl || !subjects || !subjects.length) return;
    subjChart = new Chart(subjEl, {
      type: 'bar',
      data: barData(subjects),
      options: hBarOptions('Answer accuracy (answered items)')
    });
  }
  function ensureTopicChart() {
    if (topicChart || !window.Chart || !topicEl || !topics || !topics.length) return;
    topicChart = new Chart(topicEl, {
      type: 'bar',
      data: barData(topics),
      options: hBarOptions('Answer accuracy (answered items)')
    });
  }
  function ensureChoiceChart(counts) {
    if (!window.Chart || !choiceEl) return;
    var data = {
      labels: ['A', 'B', 'C', 'D'],
      datasets: [{
        label: 'Displayed choices',
        data: ['A', 'B', 'C', 'D'].map(function (k) { return counts && counts[k] ? counts[k] : 0; }),
        backgroundColor: ['#2563eb', '#7c3aed', '#12b76a', '#0ea5e9'],
        borderRadius: 6,
        maxBarThickness: 18
      }]
    };
    if (!choiceChart) {
      choiceChart = new Chart(choiceEl, {
        type: 'bar',
        data: data,
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { beginAtZero: true, ticks: { precision: 0, color: tickColor }, grid: { color: gridColor } },
            y: { grid: { display: false }, ticks: { color: axisColor, font: { size: 12, weight: '700' } } }
          }
        }
      });
      return;
    }
    choiceChart.data = data;
    choiceChart.update();
  }
  function resizeAnaCharts() {
    ensureHistChart();
    ensureSubjChart();
    ensureTopicChart();
    requestAnimationFrame(function () {
      if (histChart) histChart.resize();
      if (subjChart) subjChart.resize();
      if (topicChart) topicChart.resize();
      if (choiceChart) choiceChart.resize();
    });
  }
  window.pemAnaResizeCharts = resizeAnaCharts;
  ensureHistChart();
  var subSel = document.getElementById('pemAnaFilterSubject');
  var topSel = document.getElementById('pemAnaFilterTopic');
  var resetBtn = document.getElementById('pemAnaFilterReset');
  function applyAnaFilters() {
    var sk = subSel ? subSel.value : '';
    var tk = topSel ? topSel.value : '';
    if (topSel) {
      Array.prototype.forEach.call(topSel.options, function (opt) {
        if (!opt.value) { opt.hidden = false; return; }
        opt.hidden = !!(sk && opt.getAttribute('data-subject') !== sk);
      });
      if (tk && topSel.selectedOptions[0] && topSel.selectedOptions[0].hidden) {
        topSel.value = '';
        tk = '';
      }
    }
    var qSearchEl = document.getElementById('pemAnaQuestionSearch');
    var qSearch = (qSearchEl && qSearchEl.value ? qSearchEl.value : '').trim().toLowerCase();
    var visQ = 0;
    document.querySelectorAll('#pemAnaQuestionTable tbody tr').forEach(function (tr) {
      var ok = true;
      if (sk && tr.getAttribute('data-ana-subject') !== sk) ok = false;
      if (ok && tk && tr.getAttribute('data-ana-topic') !== tk) ok = false;
      if (ok && qSearch) {
        var hay = tr.getAttribute('data-ana-search') || '';
        if (hay.indexOf(qSearch) === -1) ok = false;
      }
      tr.classList.toggle('is-hidden', !ok);
      if (ok) visQ += 1;
    });
    var qEmpty = document.getElementById('pemAnaQuestionEmpty');
    if (qEmpty) qEmpty.hidden = visQ !== 0;
    document.querySelectorAll('#pemAnaTopicTable tbody tr').forEach(function (tr) {
      var ok = true;
      if (sk && tr.getAttribute('data-ana-subject') !== sk) ok = false;
      if (ok && tk && tr.getAttribute('data-ana-topic') !== tk) ok = false;
      tr.classList.toggle('is-hidden', !ok);
    });
    document.querySelectorAll('#pemAnaSubjectTable tbody tr').forEach(function (tr) {
      tr.classList.toggle('is-hidden', !!(sk && tr.getAttribute('data-ana-subject') !== sk));
    });
    var visEx = 0;
    document.querySelectorAll('#pemAnaExamineeTable tbody tr').forEach(function (tr) {
      var ok = true;
      if (sk && tr.getAttribute('data-ana-subject') !== sk) ok = false;
      if (ok && tk && tr.getAttribute('data-ana-topic') !== tk) ok = false;
      tr.classList.toggle('is-hidden', !ok);
      if (ok) visEx += 1;
    });
    document.querySelectorAll('#pemAnaExamineeCards .pem-weak-card').forEach(function (card) {
      var ok = true;
      if (sk) {
        var subjects = ' ' + (card.getAttribute('data-ana-subjects') || '') + ' ';
        if (subjects.indexOf(' ' + sk + ' ') === -1) ok = false;
      }
      if (ok && tk) {
        var topics = ' ' + (card.getAttribute('data-ana-topics') || '') + ' ';
        if (topics.indexOf(' ' + tk + ' ') === -1) ok = false;
      }
      card.classList.toggle('is-hidden', !ok);
    });
    var exEmpty = document.getElementById('pemAnaExamineeEmpty');
    if (exEmpty) exEmpty.hidden = visEx !== 0;
    if (subjChart && subjects) {
      var list = sk ? subjects.filter(function (s) { return s.key === sk; }) : subjects;
      if (!list.length) list = subjects;
      subjChart.data = barData(list);
      subjChart.update();
    }
    if (topicChart && topics) {
      var tList = topics.filter(function (t) {
        if (sk && t.subject_key !== sk) return false;
        if (tk && t.key !== tk) return false;
        return true;
      });
      if (!tList.length) tList = topics;
      topicChart.data = barData(tList);
      topicChart.update();
    }
  }
  if (subSel) subSel.addEventListener('change', applyAnaFilters);
  if (topSel) topSel.addEventListener('change', applyAnaFilters);
  var qSearchInput = document.getElementById('pemAnaQuestionSearch');
  if (qSearchInput) qSearchInput.addEventListener('input', applyAnaFilters);
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      if (subSel) subSel.value = '';
      if (topSel) topSel.value = '';
      if (qSearchInput) qSearchInput.value = '';
      applyAnaFilters();
    });
  }
  document.querySelectorAll('.pem-ana-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-target');
      var table = id ? document.getElementById(id) : null;
      if (!table) return;
      var open = table.classList.toggle('is-expanded');
      table.querySelectorAll('tr.pem-ana-more').forEach(function (tr) {
        tr.classList.toggle('is-collapsed', !open);
      });
      btn.textContent = open ? 'Show less' : ('View all (' + (btn.getAttribute('data-more') || '') + ' more)');
    });
  });
  var qTable = document.getElementById('pemAnaQuestionTable');
  if (qTable) {
    qTable.querySelectorAll('th[data-sort]').forEach(function (th, idx) {
      th.setAttribute('tabindex', '0');
      th.addEventListener('click', function () { sortQuestionTable(idx, th.getAttribute('data-sort') === 'num'); });
      th.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sortQuestionTable(idx, th.getAttribute('data-sort') === 'num'); }
      });
    });
  }
  function sortQuestionTable(colIdx, numeric) {
    if (!qTable) return;
    var tbody = qTable.tBodies[0];
    if (!tbody) return;
    var rows = Array.prototype.slice.call(tbody.rows);
    var dir = qTable.getAttribute('data-sort-dir') === 'asc' && qTable.getAttribute('data-sort-col') === String(colIdx) ? -1 : 1;
    rows.sort(function (a, b) {
      var av = a.cells[colIdx] ? (a.cells[colIdx].getAttribute('data-sort-value') || a.cells[colIdx].textContent || '') : '';
      var bv = b.cells[colIdx] ? (b.cells[colIdx].getAttribute('data-sort-value') || b.cells[colIdx].textContent || '') : '';
      if (numeric) {
        return (parseFloat(av) - parseFloat(bv)) * dir;
      }
      return String(av).localeCompare(String(bv), undefined, { sensitivity: 'base' }) * dir;
    });
    rows.forEach(function (row) { tbody.appendChild(row); });
    qTable.setAttribute('data-sort-col', String(colIdx));
    qTable.setAttribute('data-sort-dir', dir === 1 ? 'asc' : 'desc');
  }
  function meterNode(pct, n) {
    var wrap = document.createElement('div');
    wrap.className = 'pem-meter';
    var track = document.createElement('span');
    track.className = 'pem-meter__track';
    var fill = document.createElement('span');
    var cls = 'pem-meter__fill';
    var w = '0%';
    var val = '—';
    if (pct == null || pct === '') {
      cls += ' pem-meter__fill--na';
    } else {
      var num = Number(pct);
      w = Math.max(0, Math.min(100, num)) + '%';
      val = (Math.round(num * 10) / 10) + '%';
      if (num < 50) cls += ' pem-meter__fill--bad';
      else if (num < 70) cls += ' pem-meter__fill--mid';
      else cls += ' pem-meter__fill--ok';
    }
    fill.className = cls;
    fill.style.width = w;
    track.appendChild(fill);
    var valEl = document.createElement('span');
    valEl.className = 'pem-meter__val';
    valEl.textContent = val;
    var nEl = document.createElement('span');
    nEl.className = 'pem-meter__n';
    nEl.textContent = 'n=' + String(n || 0);
    wrap.appendChild(track);
    wrap.appendChild(valEl);
    wrap.appendChild(nEl);
    wrap.setAttribute('role', 'img');
    wrap.setAttribute('aria-label', 'Accuracy ' + val + ', n=' + String(n || 0));
    return wrap;
  }
  function markSelected(tableId, attr, value) {
    document.querySelectorAll('#' + tableId + ' tbody tr').forEach(function (tr) {
      tr.classList.toggle('is-selected', tr.getAttribute(attr) === String(value));
    });
  }
  function openQuestionDetail(qid) {
    var q = questionsById[String(qid)];
    var panel = document.getElementById('pemAnaQuestionDetail');
    if (!panel || !q) return;
    var title = document.getElementById('pemAnaQuestionDetailTitle');
    var meta = document.getElementById('pemAnaQuestionDetailMeta');
    var pills = document.getElementById('pemAnaQuestionDetailPills');
    if (title) title.textContent = 'Question';
    var body = document.getElementById('pemAnaQuestionDetailBody');
    if (body) {
      body.replaceChildren();
      var src = document.querySelector('#pemAnaQuestionHtmlStore [data-ana-q-html="' + String(qid) + '"]');
      if (src && src.childNodes.length) {
        Array.prototype.forEach.call(src.childNodes, function (n) {
          body.appendChild(n.cloneNode(true));
        });
        body.hidden = false;
      } else {
        body.textContent = q.stem_plain || q.stem || '';
        body.hidden = !(body.textContent || '');
      }
    }
    if (meta) {
      meta.textContent = (q.subject_name || '') + (q.topic_name ? ' · ' + q.topic_name : '')
        + ' · presented ' + String(q.presented || 0);
    }
    if (pills) {
      pills.innerHTML = '';
      [
        ['Correct', q.correct || 0, 'ok'],
        ['Incorrect', q.incorrect || 0, 'bad'],
        ['Unanswered', q.unanswered || 0, 'na']
      ].forEach(function (row) {
        var el = document.createElement('div');
        el.className = 'pem-stat-pill pem-stat-pill--' + row[2];
        var k = document.createElement('span');
        k.className = 'pem-stat-pill__k';
        k.textContent = row[0];
        var v = document.createElement('span');
        v.className = 'pem-stat-pill__v';
        v.textContent = String(row[1]);
        el.appendChild(k);
        el.appendChild(v);
        pills.appendChild(el);
      });
    }
    ensureChoiceChart(q.choices || {});
    panel.hidden = false;
    markSelected('pemAnaQuestionTable', 'data-ana-q', qid);
    requestAnimationFrame(function () { if (choiceChart) choiceChart.resize(); });
  }
  function resetQuestionDetail() {
    var panel = document.getElementById('pemAnaQuestionDetail');
    var title = document.getElementById('pemAnaQuestionDetailTitle');
    var meta = document.getElementById('pemAnaQuestionDetailMeta');
    var pills = document.getElementById('pemAnaQuestionDetailPills');
    if (title) title.textContent = 'Select a question';
    var body = document.getElementById('pemAnaQuestionDetailBody');
    if (body) {
      body.replaceChildren();
      body.hidden = true;
    }
    if (meta) meta.textContent = 'Choose a row to see counts and displayed A–D letters.';
    if (pills) pills.innerHTML = '';
    if (panel) panel.hidden = false;
    markSelected('pemAnaQuestionTable', 'data-ana-q', '');
    ensureChoiceChart({ A: 0, B: 0, C: 0, D: 0 });
  }
  function openStudentDetail(uid) {
    var profile = examineeProfiles[String(uid)] || examineeProfiles[uid];
    var panel = document.getElementById('pemAnaStudentDetail');
    if (!panel || !profile) return;
    var title = document.getElementById('pemAnaStudentDetailTitle');
    var meta = document.getElementById('pemAnaStudentDetailMeta');
    var score = document.getElementById('pemAnaStudentDetailScore');
    var body = document.querySelector('#pemAnaStudentDetailTable tbody');
    var list = document.getElementById('pemAnaStudentDetailList');
    if (title) title.textContent = profile.full_name || 'Student detail';
    if (meta) {
      meta.textContent = (profile.student_number ? profile.student_number + ' · ' : '')
        + (profile.section ? profile.section + ' · ' : '')
        + (profile.weak_count || 0) + ' weak topic' + ((profile.weak_count || 0) === 1 ? '' : 's');
    }
    if (score) score.textContent = profile.exam_score_label || '—';
    if (body) {
      body.innerHTML = '';
      (profile.topics || []).forEach(function (t) {
        var tr = document.createElement('tr');
        if (t.weak) tr.className = 'pem-ana-weak';
        tr.innerHTML = '<td></td><td></td><td></td><td></td><td></td><td></td><td></td>';
        var cells = tr.querySelectorAll('td');
        cells[0].textContent = (t.subject_name || '') + ' · ' + (t.topic_name || '');
        cells[1].textContent = String(t.presented || 0);
        cells[2].textContent = String(t.answered || 0);
        cells[3].textContent = String(t.correct || 0);
        cells[4].textContent = String(t.incorrect || 0);
        cells[5].textContent = String(t.unanswered || 0);
        cells[6].textContent = t.accuracy_label || '—';
        body.appendChild(tr);
      });
    }
    if (list) {
      list.innerHTML = '';
      (profile.topics || []).forEach(function (t) {
        var li = document.createElement('li');
        li.className = 'pem-bar-list__item' + (t.weak ? ' is-weak' : '');
        var lab = document.createElement('div');
        lab.className = 'pem-bar-list__label';
        var strong = document.createElement('strong');
        strong.textContent = t.topic_name || 'Unassigned';
        var span = document.createElement('span');
        span.textContent = (t.subject_name || '') + ' · ' + String(t.correct || 0) + ' correct · '
          + String(t.incorrect || 0) + ' incorrect · ' + String(t.unanswered || 0) + ' unanswered';
        lab.appendChild(strong);
        lab.appendChild(span);
        li.appendChild(lab);
        li.appendChild(meterNode(t.accuracy, t.answered || 0));
        list.appendChild(li);
      });
    }
    panel.hidden = false;
    markSelected('pemAnaExamineeTable', 'data-ana-user', uid);
    document.querySelectorAll('#pemAnaExamineeCards .pem-weak-card').forEach(function (card) {
      card.classList.toggle('is-selected', card.getAttribute('data-ana-user') === String(uid));
    });
  }
  function resetStudentDetail() {
    var panel = document.getElementById('pemAnaStudentDetail');
    var title = document.getElementById('pemAnaStudentDetailTitle');
    var meta = document.getElementById('pemAnaStudentDetailMeta');
    var score = document.getElementById('pemAnaStudentDetailScore');
    var list = document.getElementById('pemAnaStudentDetailList');
    var body = document.querySelector('#pemAnaStudentDetailTable tbody');
    if (title) title.textContent = 'Select a student';
    if (meta) meta.textContent = 'Choose a student to see topic accuracy for this section.';
    if (score) score.textContent = '—';
    if (list) list.innerHTML = '';
    if (body) body.innerHTML = '';
    if (panel) panel.hidden = false;
    markSelected('pemAnaExamineeTable', 'data-ana-user', '');
    document.querySelectorAll('#pemAnaExamineeCards .pem-weak-card').forEach(function (card) {
      card.classList.remove('is-selected');
    });
  }
  if (qTable) {
    qTable.addEventListener('click', function (e) {
      if (e.target.closest('th')) return;
      var tr = e.target.closest('tr[data-ana-q]');
      if (!tr) return;
      openQuestionDetail(tr.getAttribute('data-ana-q'));
    });
    qTable.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      var tr = e.target.closest('tr[data-ana-q]');
      if (!tr) return;
      e.preventDefault();
      openQuestionDetail(tr.getAttribute('data-ana-q'));
    });
  }
  var qClose = document.getElementById('pemAnaQuestionDetailClose');
  if (qClose) {
    qClose.addEventListener('click', function () { resetQuestionDetail(); });
  }
  var sClose = document.getElementById('pemAnaStudentDetailClose');
  if (sClose) {
    sClose.addEventListener('click', function () { resetStudentDetail(); });
  }
  document.querySelectorAll('[data-ana-open-user]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openStudentDetail(btn.getAttribute('data-ana-open-user'));
    });
  });
  var firstQ = document.querySelector('#pemAnaQuestionTable tbody tr');
  if (firstQ) openQuestionDetail(firstQ.getAttribute('data-ana-q'));
  else resetQuestionDetail();
  var firstStu = document.querySelector('#pemAnaExamineeCards .pem-weak-card');
  if (firstStu) openStudentDetail(firstStu.getAttribute('data-ana-open-user'));
  else resetStudentDetail();
})();
</script>
<style>
  .pem-analytics .vis-hidden { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0,0,0,0); }
</style>
