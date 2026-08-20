<?php
/** Scoped examination monitor view. Expects $ctx, $scope, $metrics, $students, $isRunning, $isFinished, etc. */
$examQuestionCount = (int)($ctx['question_count'] ?? 0);
$isCollege = ($ctx['exam_type'] ?? '') === 'college_exam';

$pageTitle = 'Examination Monitor';
$adminHeroIcon = 'graph-up';
$adminHeroTitle = (string)($ctx['title'] ?? 'Examination Monitor');
$adminHeroSubtitle = (string)($ctx['subtitle'] ?? '');
$statusPill = $isFinished
    ? '<span class="admin-badge admin-badge--neutral"><i class="bi bi-flag"></i> Finished</span>'
    : ($isRunning
        ? '<span class="admin-badge admin-badge--success"><i class="bi bi-broadcast"></i> Running</span>'
        : '<span class="admin-badge admin-badge--warning"><i class="bi bi-clock"></i> Waiting</span>');
$adminHeroActions = '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="' . h($ctx['back_url']) . '"><i class="bi bi-arrow-left"></i> ' . h($ctx['back_label']) . '</a>'
    . '<a class="admin-btn admin-btn--ghost admin-btn--sm" href="professor_examinations"><i class="bi bi-grid"></i> All examinations</a>'
    . '<span class="admin-badge admin-badge--info">' . h(examination_monitor_exam_type_label($ctx['exam_type'])) . '</span>'
    . $statusPill;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">
  <?php include dirname(__DIR__) . '/professor/professor_admin_sidebar.php'; ?>

  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

    <?php if ($monitorFlash !== null && $monitorFlash !== ''): ?>
      <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?php echo h($monitorFlash); ?></span></div>
    <?php endif; ?>

    <h2 class="examination-section-title"><i class="bi bi-broadcast-pin"></i> Live Examination</h2>
    <div class="rounded-xl overflow-hidden page-table p-4 mb-4">
      <p class="text-sm font-bold text-slate-600 m-0 mb-3">Total examinees: <span id="kpiRosterTotal"><?php echo (int)$totalStudents; ?></span> · Polling every 8 seconds</p>
      <div class="examination-monitor-kpi-grid examination-monitor-kpi-grid--live">
        <?php
          $liveActive = 0;
          $liveIdle = 0;
          $liveNotStarted = 0;
          $liveSubmitted = (int)$metrics['submitted_count'];
          foreach ($students as $sx0) {
              $p0 = examination_monitor_presence_status((string)($sx0['attempt_status'] ?? ''), $sx0['last_seen_at'] ?? null);
              if ($p0 === 'active') {
                  $liveActive++;
              } elseif ($p0 === 'idle' || $p0 === 'disconnected') {
                  $liveIdle++;
              } elseif ($p0 === 'not_started') {
                  $liveNotStarted++;
              }
          }
        ?>
        <div class="examination-kpi-card"><div class="examination-kpi-card__label">Active</div><div class="examination-kpi-card__value" id="kpiActiveCount"><?php echo (int)$liveActive; ?></div></div>
        <div class="examination-kpi-card"><div class="examination-kpi-card__label">Not Started</div><div class="examination-kpi-card__value" id="kpiNotStartedCount"><?php echo (int)$liveNotStarted; ?></div></div>
        <div class="examination-kpi-card"><div class="examination-kpi-card__label">Submitted</div><div class="examination-kpi-card__value" id="kpiSubmittedCount"><?php echo (int)$liveSubmitted; ?></div></div>
        <div class="examination-kpi-card"><div class="examination-kpi-card__label">Idle / Away</div><div class="examination-kpi-card__value" id="kpiIdleCount"><?php echo (int)$liveIdle; ?></div></div>
        <div class="examination-kpi-card"><div class="examination-kpi-card__label">Still taking</div><div class="examination-kpi-card__value" id="kpiTakingCount"><?php echo (int)$metrics['taking_count']; ?></div></div>
        <div class="examination-kpi-card"><div class="examination-kpi-card__label">Avg score</div><div class="examination-kpi-card__value" id="kpiAvgScore"><?php echo $metrics['avg_score'] !== null ? h(number_format((float)$metrics['avg_score'], 1)) . '%' : '-'; ?></div></div>
        <div class="examination-kpi-card"><div class="examination-kpi-card__label">Tab leaves</div><div class="examination-kpi-card__value" id="kpiTabLeavesTotal"><?php echo (int)$totalTabLeaves; ?></div></div>
      </div>
      <?php if ($allFinishedOpenExam): ?>
        <div class="mt-3 text-sm font-semibold text-purple-700 bg-purple-50 border border-purple-200 rounded-lg px-3 py-2">
          There is no deadline and everyone on the roster has submitted, so this exam is treated as finished (even if it had a scheduled opening time).
        </div>
      <?php endif; ?>
    </div>

    <?php if ($ctx['supports_review_sheet'] && $reviewScheduleEligible): ?>
    <h2 class="examination-section-title"><i class="bi bi-calendar2-check"></i> Review sheet access (students)</h2>
    <div class="rounded-xl overflow-hidden page-table p-4 mb-4">
      <p class="text-base font-bold m-0 mb-2 flex items-center gap-2"><i class="bi bi-lock-fill"></i> Set schedule for review access</p>
      <p class="text-sm text-slate-600 m-0 mb-3">After the exam, students always see a <strong>results summary</strong>. The <strong>full question-by-question review</strong> stays locked until you choose when it opens (and optionally when it closes).</p>
      <p class="m-0 mb-3">
        <?php if ($reviewAccessStatus === 'no_schedule'): ?>
          <span class="admin-badge admin-badge--neutral"><i class="bi bi-dash-circle"></i> No schedule set - review sheet is locked</span>
        <?php elseif ($reviewAccessStatus === 'pending'): ?>
          <span class="admin-badge admin-badge--warning"><i class="bi bi-hourglass-split"></i> Scheduled - opens <?php echo h(examination_monitor_format_dt($ctx['row']['review_sheet_available_from'] ?? null)); ?></span>
        <?php elseif ($reviewAccessStatus === 'open'): ?>
          <span class="admin-badge admin-badge--success"><i class="bi bi-unlock-fill"></i> Open now for students</span>
        <?php else: ?>
          <span class="admin-badge admin-badge--danger"><i class="bi bi-lock-fill"></i> Window ended</span>
        <?php endif; ?>
      </p>
      <form method="post" action="professor_examination_monitor?exam_type=regular&amp;exam_id=<?php echo (int)$examIdSafe; ?>" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?php echo h($monitorCsrf); ?>">
        <input type="hidden" name="action" value="save_review_access">
        <input type="hidden" name="exam_id" value="<?php echo (int)$examIdSafe; ?>">
        <div class="review-sched-grid">
          <div>
            <div class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Review opens</div>
            <input class="w-full" type="datetime-local" name="review_sheet_from" value="<?php echo h($reviewFromLocal); ?>" required>
          </div>
          <div>
            <div class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Review closes (optional)</div>
            <input class="w-full" type="datetime-local" name="review_sheet_until" value="<?php echo h($reviewUntilLocal); ?>">
            <p class="text-xs text-slate-500 m-0 mt-1">Leave empty for no end date.</p>
          </div>
        </div>
        <div class="flex flex-wrap gap-2 mt-3">
          <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-check2-circle"></i> Save schedule</button>
          <button type="submit" name="clear_review_schedule" value="1" class="admin-btn admin-btn--ghost admin-btn--sm" formnovalidate onclick="return confirm('Clear review schedule? Students will only see the summary until you set a new window.');"><i class="bi bi-x-lg"></i> Clear schedule</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <h2 class="examination-section-title"><i class="bi bi-shield-exclamation"></i> Security - tab visibility</h2>
    <div class="rounded-xl overflow-hidden page-table p-4 mb-4 sec-alert-card" id="examSecurityAlerts">
      <div class="flex items-center justify-between gap-3 mb-2">
        <span class="text-sm font-extrabold text-amber-900">Live alerts when students leave the exam tab</span>
        <span class="text-xs text-amber-800/80" id="examSecurityPollStatus">Updating...</span>
      </div>
      <div id="examSecurityFeed" class="text-amber-950">
        <?php
        $hasTabAlerts = false;
        foreach ($students as $sx) {
            if ((int)($sx['tab_switch_count'] ?? 0) > 0) {
                $hasTabAlerts = true;
                break;
            }
        }
        ?>
        <?php if (!$hasTabAlerts): ?>
          <p class="mt-1 mb-0 text-sm text-amber-900/80">No tab leaves recorded yet. Counts update every 12 seconds while this page is open.</p>
        <?php else: ?>
          <?php foreach ($students as $sx): ?>
            <?php if ((int)($sx['tab_switch_count'] ?? 0) <= 0) { continue; } ?>
            <div class="sec-alert-row" data-feed-user="<?php echo (int)$sx['user_id']; ?>">
              <span class="sec-alert-dot" aria-hidden="true"></span>
              <div>
                <strong><?php echo h((string)$sx['full_name']); ?></strong>
                left the exam tab <strong><?php echo (int)$sx['tab_switch_count']; ?></strong> time(s).
                <?php $lt = examination_monitor_format_dt($sx['last_tab_switch_at'] ?? null); ?>
                Last: <?php echo $lt !== '' ? h($lt) : '-'; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$isCollege && ($sectionRows !== [] || $subjectAvgs !== [])): ?>
    <h2 class="examination-section-title"><i class="bi bi-pie-chart"></i> Diagnostic breakdown</h2>
    <?php if ($assignmentMode !== 'users' && $sectionRows !== []): ?>
    <div class="rounded-xl overflow-hidden page-table overflow-x-auto mb-4">
      <table class="w-full text-left admin-students-table students-table--compact">
        <thead><tr><th class="px-4 py-3 text-left">Section</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Submitted</th><th class="px-4 py-3 text-right">Completion</th></tr></thead>
        <tbody>
        <?php foreach ($sectionRows as $row): ?>
          <tr class="border-t border-[var(--admin-border)]"><td><?php echo h((string)$row['section']); ?></td><td class="px-4 py-3 text-right"><?php echo (int)$row['total']; ?></td><td class="px-4 py-3 text-right"><?php echo (int)$row['submitted']; ?></td><td class="px-4 py-3 text-right font-bold"><?php echo (int)$row['completion_pct']; ?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($subjectAvgs !== []): ?>
    <div class="rounded-xl overflow-hidden page-table overflow-x-auto mb-4">
      <table class="w-full text-left admin-students-table students-table--compact">
        <thead><tr><th class="px-4 py-3 text-left">Subject</th><th class="px-4 py-3 text-right">Average</th><th class="px-4 py-3 text-right">Attempts</th></tr></thead>
        <tbody>
        <?php foreach ($subjectAvgs as $sub): ?>
          <tr class="border-t border-[var(--admin-border)]"><td class="px-4 py-3 font-semibold"><?php echo h((string)$sub['subject_code']); ?></td><td class="px-4 py-3 text-right"><?php echo $sub['average_score'] !== null ? h((string)$sub['average_score']) . '%' : '—'; ?></td><td class="px-4 py-3 text-right"><?php echo (int)$sub['attempt_count']; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php
      $pemSectionOptions = [];
      foreach ($students as $sxSec) {
          $secName = trim((string)($sxSec['section'] ?? ''));
          if ($secName !== '') {
              $pemSectionOptions[$secName] = true;
          }
      }
      $pemSectionOptions = array_keys($pemSectionOptions);
      natcasesort($pemSectionOptions);
      $pemSectionOptions = array_values($pemSectionOptions);
      $pemHasUnassignedSection = false;
      foreach ($students as $sxSec2) {
          if (trim((string)($sxSec2['section'] ?? '')) === '') {
              $pemHasUnassignedSection = true;
              break;
          }
      }
    ?>
    <div class="pem-progress-head">
      <h2 class="examination-section-title"><i class="bi bi-people"></i> Examinee Progress</h2>
      <?php if ($isFinished): ?>
        <div class="pem-export-btns">
          <a href="<?php echo h($pdfUrl); ?>" class="admin-btn admin-btn--primary admin-btn--sm" title="Download PDF (finished assessments only)">
            <i class="bi bi-file-earmark-pdf"></i> Download PDF report
          </a>
          <a href="<?php echo h($xlsxUrl); ?>" class="admin-btn admin-btn--secondary admin-btn--sm" title="Download Excel (finished assessments only)">
            <i class="bi bi-file-earmark-spreadsheet"></i> Download Excel report
          </a>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($students !== []): ?>
    <div class="students-toolbar page-filter pem-roster-filters" id="pemRosterFilters">
      <div class="students-toolbar__search flex-1">
        <div class="students-search">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" id="pemFilterQ" placeholder="Search name or email..." autocomplete="off" aria-label="Search examinees">
        </div>
        <select id="pemFilterSection" class="admin-btn admin-btn--secondary admin-btn--sm" aria-label="Filter by section">
          <option value="">All sections</option>
          <?php foreach ($pemSectionOptions as $secOpt): ?>
            <option value="<?php echo h($secOpt); ?>"><?php echo h($secOpt); ?></option>
          <?php endforeach; ?>
          <?php if ($pemHasUnassignedSection): ?>
            <option value="__none__">No section</option>
          <?php endif; ?>
        </select>
        <select id="pemFilterStatus" class="admin-btn admin-btn--secondary admin-btn--sm" aria-label="Filter by status">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="not_started">Not started</option>
          <option value="submitted">Submitted</option>
          <option value="idle">Idle</option>
          <option value="disconnected">Disconnected</option>
          <option value="expired">Expired</option>
          <?php if ($isFinished): ?>
            <option value="absent">Absent</option>
          <?php endif; ?>
        </select>
        <button type="button" id="pemFilterClear" class="admin-btn admin-btn--ghost admin-btn--sm" hidden>Clear filters</button>
      </div>
      <span class="students-toolbar__meta" id="pemFilterMeta"><?php echo count($students); ?> examinee<?php echo count($students) === 1 ? '' : 's'; ?></span>
    </div>
    <?php endif; ?>

    <div class="rounded-xl page-table students-table-shell pem-roster-shell mb-4">
      <div class="students-table-scroll">
      <table class="w-full text-left admin-students-table students-table--compact pex-monitor-detail-table pex-list-table" id="pemRosterTable">
        <colgroup>
          <col class="pem-col-examinee" style="width:20%">
          <col class="pem-col-section" style="width:7%">
          <col class="pem-col-status" style="width:9%">
          <col class="pem-col-progress" style="width:9%">
          <col class="pem-col-score" style="width:7%">
          <col class="pem-col-current" style="width:5%">
          <col class="pem-col-time" style="width:6%">
          <col class="pem-col-tab" style="width:7%">
          <col class="pem-col-switches" style="width:5%">
          <col class="pem-col-seen" style="width:9%">
          <col class="pem-col-started" style="width:9%">
          <col class="pem-col-review" style="width:7%">
        </colgroup>
        <thead>
          <tr>
            <th>Examinee</th>
            <th>Section</th>
            <th>Status</th>
            <th>Progress</th>
            <th>Score</th>
            <th>Current</th>
            <th>Time left</th>
            <th>Tab</th>
            <th>Switches</th>
            <th>Last seen</th>
            <th>Started</th>
            <th><?php echo $isCollege ? 'Review' : 'Details'; ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($students === []): ?>
            <tr><td colspan="12" class="students-empty-cell">No examinees on the roster.</td></tr>
          <?php else: ?>
            <?php foreach ($students as $st): ?>
              <?php
                $attemptStatusRaw = (string)($st['attempt_status'] ?? '');
                $attemptNorm = examination_monitor_normalized_attempt_status($attemptStatusRaw);
                $isSubmitted = $attemptNorm === 'submitted';
                $presence = examination_monitor_presence_status($attemptStatusRaw, $st['last_seen_at'] ?? null);
                $answeredLive = isset($st['answered_live']) ? (int)$st['answered_live'] : null;
                $ui = [];
                $rawUi = (string)($st['ui_state_json'] ?? '');
                if ($rawUi !== '') {
                    $decoded = json_decode($rawUi, true);
                    if (is_array($decoded)) {
                        $ui = $decoded;
                    }
                }
                $curQ = isset($ui['current_index']) ? ((int)$ui['current_index'] + 1) : null;
                $remainFmt = ($attemptNorm === 'in_progress')
                    ? examination_monitor_format_remaining(isset($st['expires_at']) ? (string)$st['expires_at'] : null)
                    : null;
                $tabLeaveN = (int)($st['tab_switch_count'] ?? 0);
                $lastEvent = strtolower(trim((string)($st['last_tab_event'] ?? '')));
                $tabHidden = ($lastEvent === 'tab_hidden');
                $sectionTxt = trim((string)($st['section'] ?? ''));
                $attemptIdRow = (int)($st['attempt_id'] ?? 0);
                $canReviewSheet = false;
                if ($isCollege && $attemptIdRow > 0) {
                    $canReviewSheet = college_exam_attempt_is_effectively_submitted([
                        'status' => $st['attempt_status'] ?? null,
                        'submitted_at' => $st['submitted_at'] ?? null,
                    ]);
                }
                $filterStatusKey = 'not_started';
                $statusBadge = '<span class="admin-badge admin-badge--neutral">Not started</span>';
                if ($isFinished && !$isSubmitted && $attemptNorm !== 'in_progress') {
                    $statusBadge = '<span class="admin-badge admin-badge--warning">Absent</span>';
                    $filterStatusKey = 'absent';
                } elseif ($presence === 'submitted') {
                    $statusBadge = '<span class="admin-badge admin-badge--success">Submitted</span>';
                    $filterStatusKey = 'submitted';
                } elseif ($presence === 'expired') {
                    $statusBadge = '<span class="admin-badge admin-badge--danger">Expired</span>';
                    $filterStatusKey = 'expired';
                } elseif ($presence === 'active') {
                    $statusBadge = '<span class="admin-badge admin-badge--success">Active</span>';
                    $filterStatusKey = 'active';
                } elseif ($presence === 'idle') {
                    $statusBadge = '<span class="admin-badge admin-badge--warning">Idle</span>';
                    $filterStatusKey = 'idle';
                } elseif ($presence === 'disconnected') {
                    $statusBadge = '<span class="admin-badge admin-badge--danger">Disconnected</span>';
                    $filterStatusKey = 'disconnected';
                } elseif ($attemptNorm === 'in_progress') {
                    $statusBadge = '<span class="admin-badge admin-badge--info">Active</span>';
                    $filterStatusKey = 'active';
                }
                $progressPct = ($examQuestionCount > 0 && $answeredLive !== null)
                    ? (int)round(($answeredLive / $examQuestionCount) * 100)
                    : 0;
                $correctLive = isset($st['correct_live']) ? (int)$st['correct_live'] : 0;
                $scorePrimary = '-';
                $scorePct = '';
                if ($attemptNorm === 'in_progress' || $isSubmitted) {
                    if ($answeredLive > 0) {
                        $pct = (int)round(100 * $correctLive / $answeredLive);
                        $scorePrimary = $correctLive . ' / ' . $answeredLive;
                        $scorePct = $pct . '%';
                    } elseif ($isSubmitted && isset($st['correct_count'])) {
                        $scorePrimary = (int)$st['correct_count'] . ' / ' . (int)($st['total_count'] ?? $examQuestionCount);
                    } else {
                        $scorePrimary = '0 / 0';
                    }
                }
                $nameForFilter = strtolower(trim((string)($st['full_name'] ?? '')));
                $emailForFilter = strtolower(trim((string)($st['email'] ?? '')));
              ?>
              <tr class="js-monitor-row"
                  data-user-id="<?php echo (int)$st['user_id']; ?>"
                  data-attempt-id="<?php echo $attemptIdRow; ?>"
                  data-section="<?php echo h($sectionTxt !== '' ? $sectionTxt : '__none__'); ?>"
                  data-status="<?php echo h($filterStatusKey); ?>"
                  data-name="<?php echo h($nameForFilter); ?>"
                  data-email="<?php echo h($emailForFilter); ?>">
                <td class="college-student-account-cell" data-label="Examinee">
                  <div class="student-cell college-student-account">
                    <span class="student-avatar-cell" aria-hidden="true">
                      <span class="student-avatar-media">
                        <span class="student-avatar-initial"><?php echo h(strtoupper(substr(trim((string)$st['full_name']), 0, 1) ?: 'S')); ?></span>
                      </span>
                    </span>
                    <div class="student-cell__text">
                      <button type="button" class="student-meta-name student-name js-open-monitor-detail text-left" data-user-id="<?php echo (int)$st['user_id']; ?>">
                        <?php echo h((string)$st['full_name']); ?>
                      </button>
                      <span class="student-meta-sub"><?php echo h(examination_monitor_examinee_type_label((string)($st['review_type'] ?? ''))); ?></span>
                    </div>
                  </div>
                </td>
                <td class="pem-section-cell" data-label="Section"><?php echo $sectionTxt !== '' ? '<span class="pem-section-val">' . h($sectionTxt) . '</span>' : '<span class="opacity-60">-</span>'; ?></td>
                <td class="js-status-cell" data-label="Status"><?php echo $statusBadge; ?></td>
                <td class="js-progress-cell" data-label="Progress">
                  <?php if ($attemptNorm === 'in_progress' || $isSubmitted): ?>
                    <div class="pem-progress-stack">
                      <div class="pem-progress-label js-progress-label"><?php echo (int)($answeredLive ?? 0); ?> / <?php echo (int)$examQuestionCount; ?></div>
                      <div class="pem-mini-progress" aria-hidden="true"><span class="js-progress-fill" style="width:<?php echo $progressPct; ?>%"></span></div>
                      <div class="pem-progress-pct js-progress-pct"><?php echo (int)$progressPct; ?>%</div>
                    </div>
                  <?php else: ?>
                    <span class="opacity-60">-</span>
                  <?php endif; ?>
                </td>
                <td class="js-score-cell" data-label="Score">
                  <?php if ($scorePrimary === '-'): ?>
                    <span class="opacity-60">-</span>
                  <?php else: ?>
                    <div class="pem-score">
                      <span class="pem-score__n js-score-n"><?php echo h($scorePrimary); ?></span>
                      <?php if ($scorePct !== ''): ?>
                        <span class="pem-score__p js-score-p"><?php echo h($scorePct); ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="js-current-cell pem-center-cell" data-label="Current"><?php echo ($attemptNorm === 'in_progress' && $curQ) ? ('Q' . (int)$curQ) : '<span class="opacity-60">-</span>'; ?></td>
                <td class="js-time-cell pem-center-cell pem-time-cell" data-label="Time left"><?php echo ($remainFmt !== null && $remainFmt !== '') ? h($remainFmt) : '<span class="opacity-60">-</span>'; ?></td>
                <td class="js-tab-vis-cell pem-center-cell" data-label="Tab">
                  <?php if ($attemptNorm === 'in_progress'): ?>
                    <?php echo $tabHidden
                        ? '<span class="admin-badge admin-badge--warning">Hidden</span>'
                        : '<span class="admin-badge admin-badge--success">Visible</span>'; ?>
                  <?php else: ?>
                    <span class="opacity-60">-</span>
                  <?php endif; ?>
                </td>
                <td class="pem-center-cell" data-label="Switches">
                  <?php if ($tabLeaveN > 0 && $attemptIdRow > 0): ?>
                    <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm js-open-tab-events pem-switch-btn" data-attempt-id="<?php echo $attemptIdRow; ?>" data-name="<?php echo h((string)$st['full_name']); ?>">
                      <span class="js-tab-count"><?php echo $tabLeaveN; ?></span>
                    </button>
                  <?php else: ?>
                    <span class="pem-switch-n js-tab-count opacity-60"><?php echo $tabLeaveN; ?></span>
                  <?php endif; ?>
                </td>
                <td class="js-seen-cell pem-dt-cell" data-label="Last seen">
                  <?php
                    $seenHtml = examination_monitor_format_dt_html($st['last_seen_at'] ?? null);
                    echo $seenHtml !== '' ? $seenHtml : '<span class="opacity-60">-</span>';
                  ?>
                </td>
                <td class="pem-dt-cell" data-label="Started">
                  <?php
                    $startedHtml = examination_monitor_format_dt_html($st['started_at'] ?? null);
                    echo $startedHtml !== '' ? $startedHtml : '<span class="opacity-60">-</span>';
                  ?>
                </td>
                <td class="student-action-cell" data-label="<?php echo $isCollege ? 'Review' : 'Details'; ?>">
                  <?php if ($canReviewSheet): ?>
                    <a href="professor_exam_review_sheet?exam_id=<?php echo (int)$examIdSafe; ?>&amp;user_id=<?php echo (int)$st['user_id']; ?>" class="admin-btn admin-btn--ghost admin-btn--sm pem-review-btn">
                      <i class="bi bi-layout-text-window-reverse"></i> Review
                    </a>
                  <?php elseif ($attemptNorm === 'in_progress'): ?>
                    <span class="student-meta pem-review-wait"><i class="bi bi-hourglass-split"></i> After submit</span>
                  <?php else: ?>
                    <span class="pem-review-muted">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <tr id="pemFilterEmpty" class="pem-filter-empty" hidden>
              <td colspan="12" class="students-empty-cell">No examinees match these filters.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>

    <div id="pemTabEventsModal" class="admin-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="pemTabEventsTitle" aria-hidden="true">
      <div class="admin-modal admin-modal--form" style="max-width:32rem;width:100%;padding:1rem 1.1rem">
        <div class="admin-modal__hero">
          <div>
            <h3 id="pemTabEventsTitle" class="admin-modal__title m-0">Tab Activity</h3>
            <p class="admin-modal__desc mb-0" id="pemTabEventsSub">Timeline of tab leave / return events.</p>
          </div>
        </div>
        <div id="pemTabEventsBody" class="text-sm">Loading...</div>
        <div class="admin-modal__actions mt-3">
          <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm js-close-tab-events">Close</button>
        </div>
      </div>
    </div>

    <div id="pemDetailModal" class="admin-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
      <div class="admin-modal admin-modal--form" style="max-width:36rem;width:100%;padding:1rem 1.1rem">
        <div class="admin-modal__hero">
          <div>
            <h3 id="pemDetailTitle" class="admin-modal__title m-0">Examinee</h3>
          </div>
        </div>
        <div id="pemDetailBody"></div>
        <div class="admin-modal__actions mt-3">
          <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm js-close-detail">Close</button>
        </div>
      </div>
    </div>

  <style>
    /* Dense Examinee Progress table — scoped to this monitor page only */
    .pem-progress-head { margin-bottom: 0.45rem; }
    .pem-roster-filters {
      margin: 0 0 0.55rem;
      padding: 0.45rem 0.65rem !important;
      gap: 0.45rem 0.65rem !important;
      min-height: 0;
    }
    .pem-roster-filters .students-search { min-height: 2rem; }
    .pem-roster-filters .students-search input { min-height: 2rem; font-size: 0.8125rem; }
    .pem-roster-filters .admin-btn--sm { min-height: 2rem; padding: 0.25rem 0.55rem; font-size: 0.75rem; }
    .pem-roster-filters .students-toolbar__meta { font-size: 0.75rem; opacity: 0.75; white-space: nowrap; }
    .pem-roster-shell .students-table-scroll { max-height: calc(100vh - 11rem); }

    body.admin-app.examination-admin-page #pemRosterTable.pex-monitor-detail-table { table-layout: fixed !important; min-width: 980px; }
    body.admin-app.examination-admin-page #pemRosterTable.pex-monitor-detail-table thead th {
      padding: 0.45rem 0.55rem !important;
      font-size: 0.68rem !important;
      letter-spacing: 0.04em;
      line-height: 1.2;
    }
    body.admin-app.examination-admin-page #pemRosterTable.pex-monitor-detail-table thead th:first-child,
    body.admin-app.examination-admin-page #pemRosterTable.pex-monitor-detail-table tbody td:first-child {
      padding-left: 0.75rem !important;
    }
    body.admin-app.examination-admin-page #pemRosterTable.pex-monitor-detail-table thead th:last-child,
    body.admin-app.examination-admin-page #pemRosterTable.pex-monitor-detail-table tbody td:last-child {
      padding-right: 0.75rem !important;
    }
    body.admin-app.examination-admin-page #pemRosterTable.pex-monitor-detail-table tbody td {
      padding: 0.4rem 0.55rem !important;
      font-size: 0.8125rem;
      line-height: 1.25;
      vertical-align: middle;
    }
    body.admin-app.examination-admin-page #pemRosterTable .student-cell {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      min-width: 0;
    }
    body.admin-app.examination-admin-page #pemRosterTable .student-avatar-cell { flex: 0 0 auto; }
    body.admin-app.examination-admin-page #pemRosterTable .student-avatar-media {
      width: 2.05rem !important;
      height: 2.05rem !important;
      min-width: 2.05rem !important;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 0.72rem;
      font-weight: 700;
      background: rgba(22, 101, 160, 0.12);
      color: #143D59;
    }
    body.admin-app.examination-admin-page #pemRosterTable .student-cell__text {
      min-width: 0;
      display: flex;
      flex-direction: column;
      gap: 0.05rem;
    }
    body.admin-app.examination-admin-page #pemRosterTable .student-name {
      display: block;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-size: 0.8125rem;
      font-weight: 700;
      line-height: 1.2;
      color: #143D59;
      background: none;
      border: 0;
      padding: 0;
      cursor: pointer;
    }
    body.admin-app.examination-admin-page #pemRosterTable .student-name:hover { text-decoration: underline; }
    body.admin-app.examination-admin-page #pemRosterTable .student-meta-sub {
      display: block;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-size: 0.68rem;
      line-height: 1.15;
      color: var(--admin-text-muted, #64748b);
      font-weight: 500;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-section-val {
      display: inline-block;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      font-size: 0.78rem;
      font-weight: 600;
    }
    body.admin-app.examination-admin-page #pemRosterTable .admin-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      min-height: 1.5rem;
      padding: 0.12rem 0.45rem;
      font-size: 0.68rem;
      font-weight: 700;
      line-height: 1.2;
      border-radius: 999px;
      white-space: nowrap;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-progress-stack {
      display: flex;
      flex-direction: column;
      gap: 0.15rem;
      min-width: 0;
      max-width: 7.5rem;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-progress-label {
      font-size: 0.78rem;
      font-weight: 700;
      line-height: 1.15;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-mini-progress {
      height: 4px;
      background: #e2e8f0;
      border-radius: 999px;
      overflow: hidden;
      margin: 0;
      max-width: 100%;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-mini-progress > span {
      display: block;
      height: 100%;
      background: linear-gradient(90deg, #1665A0, #3393FF);
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-progress-pct {
      font-size: 0.65rem;
      font-weight: 600;
      color: var(--admin-text-muted, #64748b);
      line-height: 1.1;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-score {
      display: flex;
      flex-direction: column;
      gap: 0.05rem;
      line-height: 1.15;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-score__n { font-size: 0.78rem; font-weight: 700; }
    body.admin-app.examination-admin-page #pemRosterTable .pem-score__p {
      font-size: 0.68rem;
      font-weight: 600;
      color: var(--admin-text-muted, #64748b);
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-center-cell { text-align: center; }
    body.admin-app.examination-admin-page #pemRosterTable .pem-time-cell {
      font-variant-numeric: tabular-nums;
      font-weight: 700;
      font-size: 0.78rem;
    }
    body.admin-app.examination-admin-page #pemRosterTable .js-current-cell {
      font-weight: 650;
      font-size: 0.78rem;
      color: var(--admin-text-secondary, #475569);
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-switch-btn {
      min-height: 1.55rem;
      padding: 0.1rem 0.4rem;
      font-size: 0.78rem;
      font-weight: 700;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-switch-n { font-size: 0.78rem; font-weight: 650; }
    body.admin-app.examination-admin-page #pemRosterTable .pem-dt {
      display: flex;
      flex-direction: column;
      gap: 0.02rem;
      line-height: 1.15;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-dt__d {
      font-size: 0.7rem;
      font-weight: 600;
      color: var(--admin-text-secondary, #475569);
      white-space: nowrap;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-dt__t {
      font-size: 0.65rem;
      font-weight: 500;
      color: var(--admin-text-muted, #64748b);
      white-space: nowrap;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-review-btn {
      min-height: 1.55rem;
      padding: 0.15rem 0.45rem;
      font-size: 0.72rem;
      gap: 0.25rem;
      white-space: nowrap;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-review-wait {
      font-size: 0.68rem;
      white-space: nowrap;
      opacity: 0.75;
    }
    .pem-filter-empty[hidden],
    .js-monitor-row.is-filtered-out { display: none !important; }
  </style>
  <script>
  (function () {
    var liveUrl = <?php echo json_encode($liveUrl, JSON_UNESCAPED_SLASHES); ?>;
    var pollEl = document.getElementById('examSecurityPollStatus');
    var feedEl = document.getElementById('examSecurityFeed');
    var kpiTab = document.getElementById('kpiTabLeavesTotal');
    var prevByUser = {};
    var studentCache = {};
    var filterQ = document.getElementById('pemFilterQ');
    var filterSection = document.getElementById('pemFilterSection');
    var filterStatus = document.getElementById('pemFilterStatus');
    var filterClear = document.getElementById('pemFilterClear');
    var filterMeta = document.getElementById('pemFilterMeta');
    var filterEmpty = document.getElementById('pemFilterEmpty');
    var totalRoster = document.querySelectorAll('.js-monitor-row').length;

    document.querySelectorAll('.js-monitor-row').forEach(function (tr) {
      var uid = tr.getAttribute('data-user-id');
      var cell = tr.querySelector('.js-tab-count');
      if (uid && cell) prevByUser[uid] = parseInt(String(cell.textContent).replace(/\D+/g, ''), 10) || 0;
    });

    function applyRosterFilters() {
      var q = (filterQ && filterQ.value ? filterQ.value : '').trim().toLowerCase();
      var sec = filterSection ? filterSection.value : '';
      var st = filterStatus ? filterStatus.value : '';
      var visible = 0;
      document.querySelectorAll('.js-monitor-row').forEach(function (tr) {
        var ok = true;
        if (sec && (tr.getAttribute('data-section') || '') !== sec) ok = false;
        if (ok && st && (tr.getAttribute('data-status') || '') !== st) ok = false;
        if (ok && q) {
          var name = tr.getAttribute('data-name') || '';
          var email = tr.getAttribute('data-email') || '';
          if (name.indexOf(q) === -1 && email.indexOf(q) === -1) ok = false;
        }
        tr.classList.toggle('is-filtered-out', !ok);
        if (ok) visible += 1;
      });
      if (filterEmpty) filterEmpty.hidden = visible !== 0 || totalRoster === 0;
      if (filterMeta) {
        if (!q && !sec && !st) {
          filterMeta.textContent = totalRoster + ' examinee' + (totalRoster === 1 ? '' : 's');
        } else {
          filterMeta.textContent = 'Showing ' + visible + ' of ' + totalRoster;
        }
      }
      if (filterClear) filterClear.hidden = !(q || sec || st);
    }

    function clearRosterFilters() {
      if (filterQ) filterQ.value = '';
      if (filterSection) filterSection.value = '';
      if (filterStatus) filterStatus.value = '';
      applyRosterFilters();
    }

    if (filterQ) filterQ.addEventListener('input', applyRosterFilters);
    if (filterSection) filterSection.addEventListener('change', applyRosterFilters);
    if (filterStatus) filterStatus.addEventListener('change', applyRosterFilters);
    if (filterClear) filterClear.addEventListener('click', clearRosterFilters);

    function esc(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
    }
    function formatDtHtml(fmt) {
      if (!fmt) return '<span class="opacity-60">-</span>';
      var m = String(fmt).match(/^(.+,\s*\d{4})\s+(.+)$/);
      if (m) {
        return '<span class="pem-dt"><span class="pem-dt__d">' + esc(m[1]) + '</span><span class="pem-dt__t">' + esc(m[2]) + '</span></span>';
      }
      return '<span class="pem-dt">' + esc(fmt) + '</span>';
    }
    function presenceBadge(p) {
      var map = {
        active: ['admin-badge--success', 'Active'],
        idle: ['admin-badge--warning', 'Idle'],
        disconnected: ['admin-badge--danger', 'Disconnected'],
        submitted: ['admin-badge--success', 'Submitted'],
        expired: ['admin-badge--danger', 'Expired'],
        not_started: ['admin-badge--neutral', 'Not started'],
        absent: ['admin-badge--warning', 'Absent']
      };
      var m = map[p] || map.not_started;
      return '<span class="admin-badge ' + m[0] + '">' + m[1] + '</span>';
    }
    function scoreHtml(s) {
      var ans = parseInt(s.answered_count, 10) || 0;
      var cor = parseInt(s.correct_count, 10) || 0;
      if (ans <= 0 && s.attempt_status !== 'submitted' && s.attempt_status !== 'expired') {
        return '<div class="pem-score"><span class="pem-score__n js-score-n">0 / 0</span></div>';
      }
      if (ans <= 0) return '<span class="opacity-60">-</span>';
      var pct = s.score_pct_answered != null ? Math.round(s.score_pct_answered) : Math.round(100 * cor / ans);
      return '<div class="pem-score"><span class="pem-score__n js-score-n">' + esc(cor + ' / ' + ans) + '</span><span class="pem-score__p js-score-p">' + esc(pct + '%') + '</span></div>';
    }
    function scoreText(s) {
      var ans = parseInt(s.answered_count, 10) || 0;
      var cor = parseInt(s.correct_count, 10) || 0;
      if (ans <= 0 && s.attempt_status !== 'submitted' && s.attempt_status !== 'expired') return '0 / 0';
      if (ans <= 0) return '-';
      var pct = s.score_pct_answered != null ? Math.round(s.score_pct_answered) : Math.round(100 * cor / ans);
      return cor + ' / ' + ans + ' · ' + pct + '%';
    }
    function openTabModal(attemptId, name) {
      var modal = document.getElementById('pemTabEventsModal');
      var body = document.getElementById('pemTabEventsBody');
      var sub = document.getElementById('pemTabEventsSub');
      if (!modal || !body) return;
      if (sub) sub.textContent = (name || 'Student') + ' — tab leave / return timeline';
      body.textContent = 'Loading...';
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      var url = liveUrl + (liveUrl.indexOf('?') >= 0 ? '&' : '?') + 'tab_events_attempt_id=' + encodeURIComponent(attemptId);
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var rows = (data && (data.activity_timeline || data.tab_events)) || [];
          if (!rows.length) {
            body.innerHTML = '<p class="opacity-70 m-0">No tab events recorded yet.</p>';
            return;
          }
          body.innerHTML = '<table class="w-full text-left"><thead><tr><th class="py-1">Event</th><th class="py-1">Time</th><th class="py-1">Away</th></tr></thead><tbody>' +
            rows.map(function (ev) {
              var away = '';
              if (ev.away_seconds != null) {
                var s = parseInt(ev.away_seconds, 10) || 0;
                away = s >= 60 ? (Math.floor(s / 60) + 'm ' + (s % 60) + 's') : (s + 's');
              } else if (ev.detail) {
                away = ev.detail;
              }
              return '<tr><td class="py-1.5">' + esc(ev.label || ev.event_type || '') + '</td><td class="py-1.5">' + esc(ev.occurred_fmt || '') + '</td><td class="py-1.5">' + (away ? esc(away) : '-') + '</td></tr>';
            }).join('') + '</tbody></table>';
        })
        .catch(function () { body.textContent = 'Could not load tab events.'; });
    }
    function closeTabModal() {
      var modal = document.getElementById('pemTabEventsModal');
      if (modal) {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
      }
    }
    document.querySelectorAll('.js-close-tab-events').forEach(function (el) {
      el.addEventListener('click', closeTabModal);
    });
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.js-open-tab-events');
      if (btn) {
        openTabModal(btn.getAttribute('data-attempt-id'), btn.getAttribute('data-name'));
      }
      var det = e.target.closest('.js-open-monitor-detail');
      if (det) {
        var uid = det.getAttribute('data-user-id');
        var s = studentCache[uid];
        var modal = document.getElementById('pemDetailModal');
        var title = document.getElementById('pemDetailTitle');
        var body = document.getElementById('pemDetailBody');
        if (!modal || !body) return;
        if (title) title.textContent = (s && s.full_name) || 'Examinee';
        if (!s) {
          body.innerHTML = '<p class="opacity-70">Waiting for live data...</p>';
        } else {
          body.innerHTML =
            '<dl class="grid grid-cols-2 gap-2 text-sm mb-3">' +
            '<div><dt class="opacity-60">Status</dt><dd>' + presenceBadge(s.presence_status) + '</dd></div>' +
            '<div><dt class="opacity-60">Section</dt><dd>' + esc(s.section || '-') + '</dd></div>' +
            '<div><dt class="opacity-60">Progress</dt><dd>' + esc(String(s.answered_count != null ? s.answered_count : 0)) + ' / ' + esc(String(s.total_questions != null ? s.total_questions : '-')) + '</dd></div>' +
            '<div><dt class="opacity-60">Score</dt><dd>' + esc(scoreText(s)) + '</dd></div>' +
            '<div><dt class="opacity-60">Current</dt><dd>' + (s.current_question ? ('Question ' + esc(String(s.current_question))) : '-') + '</dd></div>' +
            '<div><dt class="opacity-60">Time left</dt><dd>' + esc(s.remaining_fmt || '-') + '</dd></div>' +
            '<div><dt class="opacity-60">Tab</dt><dd>' + (s.tab_hidden ? 'Away' : 'Visible') + '</dd></div>' +
            '<div><dt class="opacity-60">Tab switches</dt><dd>' + esc(String(s.tab_switch_count || 0)) + '</dd></div>' +
            '<div><dt class="opacity-60">Last seen</dt><dd>' + esc(s.last_seen_fmt || '-') + '</dd></div>' +
            '<div><dt class="opacity-60">Started</dt><dd>' + esc(s.started_fmt || '-') + '</dd></div>' +
            '</dl>' +
            '<div id="pemDetailTimeline" class="text-sm border-t border-slate-200 pt-3"><p class="opacity-70 m-0">Loading activity timeline...</p></div>' +
            (s.attempt_id ? ('<p class="mt-3 mb-0"><button type="button" class="admin-btn admin-btn--secondary admin-btn--sm js-open-tab-events" data-attempt-id="' + esc(String(s.attempt_id)) + '" data-name="' + esc(s.full_name || '') + '">Open full timeline</button></p>') : '');
          if (s.attempt_id) {
            var url = liveUrl + (liveUrl.indexOf('?') >= 0 ? '&' : '?') + 'tab_events_attempt_id=' + encodeURIComponent(s.attempt_id);
            fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
              var box = document.getElementById('pemDetailTimeline');
              if (!box) return;
              var rows = (data && (data.activity_timeline || data.tab_events)) || [];
              if (!rows.length) {
                box.innerHTML = '<p class="opacity-70 m-0">No activity events yet.</p>';
                return;
              }
              box.innerHTML = '<h4 class="font-bold m-0 mb-2 text-[#143D59]">Activity Timeline</h4><ul class="m-0 pl-4 space-y-1">' +
                rows.map(function (ev) {
                  var extra = ev.detail ? (' <span class="opacity-70">(' + esc(ev.detail) + ')</span>') : '';
                  return '<li><strong>' + esc(ev.occurred_fmt || '') + '</strong> — ' + esc(ev.label || '') + extra + '</li>';
                }).join('') + '</ul>';
            }).catch(function () {});
          }
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
      }
      if (e.target.closest('.js-close-detail')) {
        var dm = document.getElementById('pemDetailModal');
        if (dm) {
          dm.classList.remove('is-open');
          dm.setAttribute('aria-hidden', 'true');
        }
      }
    });
    function tick() {
      fetch(liveUrl, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) throw new Error('poll');
          var elT = document.getElementById('kpiTakingCount');
          var elS = document.getElementById('kpiSubmittedCount');
          if (elT && data.taking_count !== undefined && parseInt(elT.textContent, 10) !== data.taking_count) {
            location.reload();
            return;
          }
          if (elS && data.submitted_count !== undefined && parseInt(elS.textContent, 10) !== data.submitted_count) {
            location.reload();
            return;
          }
          if ((data.auto_finalized || 0) > 0) {
            location.reload();
            return;
          }
          if (pollEl) pollEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
          if (kpiTab) kpiTab.textContent = String(data.total_tab_leaves || 0);
          if (data.presence) {
            var elA = document.getElementById('kpiActiveCount');
            var elI = document.getElementById('kpiIdleCount');
            var elN = document.getElementById('kpiNotStartedCount');
            if (elA) elA.textContent = String((data.presence.active || 0));
            if (elI) elI.textContent = String((data.presence.idle || 0) + (data.presence.disconnected || 0));
            if (elN) elN.textContent = String(data.presence.not_started || 0);
          }
          var list = data.students || [];
          list.forEach(function (s) {
            var uid = String(s.user_id);
            studentCache[uid] = s;
            var tr = document.querySelector('.js-monitor-row[data-user-id="' + uid + '"]');
            if (!tr) return;
            if (s.presence_status) tr.setAttribute('data-status', String(s.presence_status));
            if (s.section != null) {
              var secVal = String(s.section || '').trim();
              tr.setAttribute('data-section', secVal !== '' ? secVal : '__none__');
            }
            var n = parseInt(s.tab_switch_count, 10) || 0;
            var prev = prevByUser[uid] || 0;
            var statusCell = tr.querySelector('.js-status-cell');
            if (statusCell) statusCell.innerHTML = presenceBadge(s.presence_status || 'not_started');
            var progLabel = tr.querySelector('.js-progress-label');
            var progFill = tr.querySelector('.js-progress-fill');
            var progPct = tr.querySelector('.js-progress-pct');
            if (progLabel && s.answered_count != null) {
              progLabel.textContent = (s.answered_count || 0) + ' / ' + (s.total_questions || 0);
            }
            if (progFill && s.total_questions) {
              var pctW = Math.round(((s.answered_count || 0) / s.total_questions) * 100);
              progFill.style.width = pctW + '%';
              if (progPct) progPct.textContent = pctW + '%';
            }
            var scoreCell = tr.querySelector('.js-score-cell');
            if (scoreCell) scoreCell.innerHTML = scoreHtml(s);
            var curCell = tr.querySelector('.js-current-cell');
            if (curCell) curCell.innerHTML = s.current_question ? ('Q' + s.current_question) : '<span class="opacity-60">-</span>';
            var timeCell = tr.querySelector('.js-time-cell');
            if (timeCell) timeCell.innerHTML = s.remaining_fmt ? esc(s.remaining_fmt) : '<span class="opacity-60">-</span>';
            var visCell = tr.querySelector('.js-tab-vis-cell');
            if (visCell && (s.attempt_status === 'in_progress')) {
              visCell.innerHTML = s.tab_hidden
                ? '<span class="admin-badge admin-badge--warning">Hidden</span>'
                : '<span class="admin-badge admin-badge--success">Visible</span>';
            }
            var countCell = tr.querySelector('.js-tab-count');
            if (countCell) countCell.textContent = String(n);
            var seenCell = tr.querySelector('.js-seen-cell');
            if (seenCell) seenCell.innerHTML = formatDtHtml(s.last_seen_fmt || '');
            if (n > prev) {
              tr.classList.remove('tab-flash');
              void tr.offsetWidth;
              tr.classList.add('tab-flash');
            }
            prevByUser[uid] = n;
          });
          applyRosterFilters();
          var alerts = list.filter(function (x) { return (parseInt(x.tab_switch_count, 10) || 0) > 0; });
          if (feedEl) {
            if (alerts.length === 0) {
              feedEl.innerHTML = '<p class="mt-1 mb-0 text-sm text-amber-900/80">No tab leaves recorded yet. Counts update every 12 seconds while this page is open.</p>';
            } else {
              feedEl.innerHTML = alerts.map(function (a) {
                var n = parseInt(a.tab_switch_count, 10) || 0;
                var lt = a.last_tab_switch_fmt || '-';
                return '<div class="sec-alert-row"><span class="sec-alert-dot" aria-hidden="true"></span><div><strong>' +
                  esc(a.full_name || '') + '</strong> left the exam tab <strong>' + n + '</strong> time(s). Last: ' + esc(lt) + '</div></div>';
              }).join('');
            }
          }
        })
        .catch(function () {
          if (pollEl) pollEl.textContent = 'Update failed - retrying...';
        });
    }
    applyRosterFilters();
    tick();
    setInterval(tick, 11000);
  })();
  </script>
</body>
</html>
