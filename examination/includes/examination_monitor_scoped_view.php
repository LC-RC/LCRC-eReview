<?php
/** Scoped examination monitor view. Expects $ctx, $scope, $metrics, $students, $isRunning, $isFinished, etc. */
$examQuestionCount = (int)($ctx['question_count'] ?? 0);
$isCollege = ($ctx['exam_type'] ?? '') === 'college_exam';
$collegeSectionFilter = $isCollege ? examination_monitor_normalize_section_filter($collegeSectionFilter ?? '') : '';
$reportingRosterCount = isset($reportingRosterCount) ? (int)$reportingRosterCount : count($students ?? []);

$lmsPremiumOptIn = $isCollege ? false : true;
$lmsHeroVariant = $isCollege ? '' : 'navy';
$pageTitle = 'Examination Monitor';
$adminHeroTitle = (string)($ctx['title'] ?? 'Examination Monitor');
$adminHeroSubtitle = (string)($ctx['subtitle'] ?? '') !== ''
    ? (string) $ctx['subtitle']
    : 'Live monitoring for this examination. Track progress, performance, and keep the exam secure.';
if ($isCollege && $isFinished) {
    $ctxSub = (string)($ctx['subtitle'] ?? '');
    if ($ctxSub === '' || $ctxSub === 'Live monitoring for this examination.') {
        $adminHeroSubtitle = 'Examination monitoring and results.';
    }
}
$adminBreadcrumbs = [
    ['Dashboard', 'professor_admin_dashboard'],
    ['Monitoring', 'professor_examination_monitor'],
    [(string)($ctx['title'] ?? 'Exam')],
];
$statusPill = $isFinished
    ? '<span class="admin-badge admin-badge--neutral"><i class="bi bi-flag"></i> Finished</span>'
    : ($isRunning
        ? '<span class="admin-badge admin-badge--success"><i class="bi bi-broadcast"></i> Ongoing</span>'
        : '<span class="admin-badge admin-badge--warning"><i class="bi bi-clock"></i> Waiting</span>');
$examTypeBadge = '<span class="admin-badge admin-badge--info">' . h(examination_monitor_exam_type_label($ctx['exam_type'])) . '</span>';
$heroWindow = '';
if ($isCollege) {
    $heroFrom = examination_monitor_format_dt($ctx['row']['available_from'] ?? null);
    $heroUntil = examination_monitor_format_dt($ctx['row']['deadline'] ?? null);
    $heroWindow = ($heroFrom !== '' || $heroUntil !== '')
        ? trim($heroFrom . ($heroFrom !== '' && $heroUntil !== '' ? ' – ' : '') . $heroUntil)
        : 'Schedule not set';
    $pemBackHref = (string) ($ctx['back_url'] ?? 'professor_examination_monitor');
    $pemBackLabel = (string) ($ctx['back_label'] ?? 'Back to Monitoring');
} else {
    $professorFeatureHero = true;
    $professorPageChrome = false;
    $adminHeroIcon = 'broadcast-pin';
    $adminHeroTint = 'blue';
    $adminHeroEyebrow = 'Exam command';
    $adminBackHref = (string) ($ctx['back_url'] ?? 'professor_examinations');
    $adminBackLabel = (string) ($ctx['back_label'] ?? 'Back to Examinations');
    $adminHeroActions = '<a class="admin-btn admin-btn--ghost admin-btn--sm" href="professor_examinations"><i class="bi bi-grid"></i> All examinations</a>'
        . $examTypeBadge
        . $statusPill
        . '<a class="admin-btn admin-btn--sm pem-export-pdf" href="' . h($pdfUrl) . '" title="Download PDF report"><i class="bi bi-file-earmark-pdf"></i> PDF</a>'
        . '<a class="admin-btn admin-btn--sm pem-export-xlsx" href="' . h($xlsxUrl) . '" title="Download Excel report"><i class="bi bi-file-earmark-spreadsheet"></i> Excel</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
  <?php
    if ($isCollege) {
        $pemAnaCssFile = dirname(__DIR__, 2) . '/assets/css/examination-monitor-analytics.css';
        if (is_file($pemAnaCssFile)) {
            $pemAnaCssHref = rtrim(str_replace('\\', '/', dirname(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')))), '/');
            if (preg_match('#/examination/(?:professor|examinee)$#', $pemAnaCssHref)) {
                $pemAnaCssHref = (string)preg_replace('#/examination/(?:professor|examinee)$#', '', $pemAnaCssHref);
            }
            if ($pemAnaCssHref === '.' || $pemAnaCssHref === '') {
                $pemAnaCssHref = '';
            }
            echo '<link rel="stylesheet" href="' . h(rtrim($pemAnaCssHref, '/') . '/assets/css/examination-monitor-analytics.css') . '?v=' . filemtime($pemAnaCssFile) . '">' . "\n";
            $pemAnalyticsCssLinked = true;
        }
    }
  ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page professor-admin prof-page--monitor-detail<?php echo $isCollege ? ' pem-monitor-tabs' : ' prof-page--diag-monitor'; ?><?php echo ereview_lms_premium_body_class(); ?>">
  <?php include dirname(__DIR__) . '/professor/professor_admin_sidebar.php'; ?>
  <?php $toastSuccess = false; ?>

  <?php if ($isCollege): ?>
  <header class="pem-page-head">
    <?php include dirname(__DIR__, 2) . '/includes/admin_breadcrumb.php'; ?>
    <div class="pem-page-head__card">
      <div class="pem-page-head__identity">
        <h1 id="admin-page-title" class="pem-page-head__title">
          <span class="pem-page-head__title-text"><?php echo h($adminHeroTitle); ?></span>
          <span class="pem-page-head__badges"><?php echo $statusPill . $examTypeBadge; ?></span>
        </h1>
        <?php if ($adminHeroSubtitle !== ''): ?>
          <p class="pem-page-head__subtitle"><?php echo h($adminHeroSubtitle); ?></p>
        <?php endif; ?>
        <p class="pem-page-head__schedule">
          <i class="bi bi-calendar3" aria-hidden="true"></i>
          <span class="pem-page-head__schedule-label">Schedule</span>
          <span><?php echo h($heroWindow); ?></span>
        </p>
      </div>
      <div class="pem-page-head__actions">
        <a class="pem-page-head__back" href="<?php echo h($pemBackHref); ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> <?php echo h($pemBackLabel); ?></a>
        <a class="pem-page-head__all" href="professor_examinations"><i class="bi bi-grid" aria-hidden="true"></i> All examinations</a>
      </div>
    </div>
  </header>
  <?php else: ?>
  <?php
      $toastSuccess = false;
      if ($monitorFlash !== null && $monitorFlash !== '') {
          $toastSuccess = ereview_lms_toast_take('success', (string) $monitorFlash);
      }
  ?>
  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>
  <?php endif; ?>

    <?php if ($monitorFlash !== null && $monitorFlash !== '' && empty($toastSuccess)): ?>
      <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?php echo h($monitorFlash); ?></span></div>
    <?php endif; ?>
    <?php if ($isCollege && !empty($collegeSectionInvalid)): ?>
      <div class="admin-flash admin-flash--warning mb-3 p-3 rounded-xl flex items-center gap-2" role="status">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>That section is not assigned to this exam. Showing all sections instead.</span>
      </div>
    <?php endif; ?>

    <?php
      $pemSectionOptions = [];
      $pemHasUnassignedSection = false;
      if ($isCollege && isset($collegeSectionOptions) && is_array($collegeSectionOptions)) {
          $pemSectionOptions = $collegeSectionOptions;
          $pemHasUnassignedSection = !empty($collegeHasUnassignedSection);
      } else {
          foreach ($students as $sxSec) {
              $secName = trim((string)($sxSec['section'] ?? ''));
              if ($secName !== '') {
                  $pemSectionOptions[$secName] = true;
              }
          }
          $pemSectionOptions = array_keys($pemSectionOptions);
          natcasesort($pemSectionOptions);
          $pemSectionOptions = array_values($pemSectionOptions);
          foreach ($students as $sxSec2) {
              if (trim((string)($sxSec2['section'] ?? '')) === '') {
                  $pemHasUnassignedSection = true;
                  break;
              }
          }
      }
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
      $pemSecBadge = $collegeSectionFilter === ''
          ? 'All sections'
          : ($collegeSectionFilter === '__none__' ? 'No section' : $collegeSectionFilter);
      $pemTop10 = [];
      $pemTop10Eligible = 0;
      if ($isCollege) {
          require_once __DIR__ . '/college_exam_helpers.php';
          $rankPool = [];
          foreach ($students as $stRank) {
              $rawStatus = strtolower(trim((string)($stRank['attempt_status'] ?? '')));
              if ($rawStatus !== 'submitted') {
                  continue;
              }
              $tot = isset($stRank['total_count']) ? (int)$stRank['total_count'] : 0;
              $cor = isset($stRank['correct_count']) ? (int)$stRank['correct_count'] : 0;
              $stored = $stRank['score'] ?? null;
              if ($tot > 0) {
                  $pct = college_exam_compute_traditional_score_percentage($cor, $tot);
                  $label = college_exam_format_score_percent($pct);
                  $fraction = $cor . '/' . $tot;
              } elseif (is_numeric($stored)) {
                  $pct = (float)$stored;
                  $label = college_exam_format_score_percent($pct);
                  $fraction = '';
              } else {
                  continue;
              }
              $rankPool[] = [
                  'user_id' => (int)($stRank['user_id'] ?? 0),
                  'full_name' => (string)($stRank['full_name'] ?? ''),
                  'student_number' => trim((string)($stRank['student_number'] ?? '')),
                  'section' => trim((string)($stRank['section'] ?? '')),
                  'score' => $pct,
                  'score_label' => $label,
                  'fraction' => $fraction,
              ];
          }
          $pemTop10Eligible = count($rankPool);
          usort($rankPool, static function (array $a, array $b): int {
              $byScore = $b['score'] <=> $a['score'];
              if ($byScore !== 0) {
                  return $byScore;
              }

              return ((int)$a['user_id']) <=> ((int)$b['user_id']);
          });
          $shown = $rankPool;
          if (count($rankPool) > 10) {
              $cutoffScore = (float)$rankPool[9]['score'];
              $shown = [];
              foreach ($rankPool as $row) {
                  if ((float)$row['score'] < $cutoffScore) {
                      break;
                  }
                  $shown[] = $row;
              }
          }
          $prevScore = null;
          $rankNo = 0;
          foreach ($shown as $row) {
              if ($prevScore === null || (float)$row['score'] !== (float)$prevScore) {
                  $rankNo++;
                  $prevScore = (float)$row['score'];
              }
              $row['rank'] = $rankNo;
              $pemTop10[] = $row;
          }
      }
      $overlapExamineeCount = 0;
      $clientTrackingLive = false;
      $concurrentAlerts = [];
      $overlapByAttempt = [];
      if ($isCollege) {
          $clientTrackingLive = examination_monitor_client_instance_tracking_wired();
          require_once __DIR__ . '/college_exam_client_heartbeat.php';
          $overlapByAttempt = college_exam_client_active_counts_for_exam($conn, (int)$examIdSafe);
          $concurrentAlerts = examination_monitor_college_concurrent_alerts($conn, (int)$examIdSafe);
          foreach ($students as $sxOv) {
              $aidOv = (int)($sxOv['attempt_id'] ?? 0);
              if ($aidOv > 0 && (int)($overlapByAttempt[$aidOv]['instances'] ?? 0) >= 2) {
                  $overlapExamineeCount++;
              }
          }
      }
      $pemRosterN = max(0, (int)$reportingRosterCount);
      $pemTakingN = (int)($metrics['taking_count'] ?? 0);
      $pemSubN = (int)$liveSubmitted;
      $pemNsN = (int)$liveNotStarted;
      $pemDenom = $pemRosterN > 0 ? $pemRosterN : 0;
      $pemPct = static function (int $n, int $d): string {
          if ($d <= 0) {
              return '—';
          }

          return number_format(100.0 * $n / $d, 1) . '% of total';
      };
      $pemBarW = static function (int $n, int $d): int {
          if ($d <= 0) {
              return 0;
          }

          return (int) max(0, min(100, (int) round(100.0 * $n / $d)));
      };
      $pemLiveActivity = [];
      foreach ($students as $sxAct) {
          $actName = trim((string)($sxAct['full_name'] ?? ''));
          if ($actName === '') {
              $actName = 'Examinee';
          }
          $rawAct = strtolower(trim((string)($sxAct['attempt_status'] ?? '')));
          $subAt = trim((string)($sxAct['submitted_at'] ?? ''));
          $stAt = trim((string)($sxAct['started_at'] ?? ''));
          $tabAt = trim((string)($sxAct['last_tab_switch_at'] ?? ''));
          if ($rawAct === 'submitted' && $subAt !== '') {
              $pemLiveActivity[] = ['ts' => $subAt, 'name' => $actName, 'text' => 'submitted the exam', 'kind' => 'submit'];
          } elseif ($rawAct === 'in_progress' && $stAt !== '') {
              $pemLiveActivity[] = ['ts' => $stAt, 'name' => $actName, 'text' => 'started the exam', 'kind' => 'start'];
          }
          if ($tabAt !== '' && (int)($sxAct['tab_switch_count'] ?? 0) > 0) {
              $pemLiveActivity[] = ['ts' => $tabAt, 'name' => $actName, 'text' => 'left the exam tab', 'kind' => 'tab'];
          }
      }
      usort($pemLiveActivity, static function (array $a, array $b): int {
          return strcmp((string)$b['ts'], (string)$a['ts']);
      });
      $pemLiveActivity = array_slice($pemLiveActivity, 0, 6);
      $pemRingTotal = max(0, $pemSubN + $pemTakingN + $pemNsN);
      $pemRingSub = $pemRingTotal > 0 ? (int)round(360 * $pemSubN / $pemRingTotal) : 0;
      $pemRingTake = $pemRingTotal > 0 ? (int)round(360 * $pemTakingN / $pemRingTotal) : 0;
      $pemExamEditUrl = '';
      if ($isCollege) {
          require_once __DIR__ . '/examination_domain.php';
          $pemExamEditUrl = examination_domain_edit_url('regular', (int)$examIdSafe, 'config');
      }
    ?>

    <?php if (!$isCollege): ?>
    <section class="prof-status-deck mb-4 lms-workspace">
    <div class="prof-workspace__head lms-workspace__head">
      <div>
        <h2 class="prof-workspace__title"><?php if ($isRunning): ?><span class="prof-live-dot" aria-hidden="true"></span><?php endif; ?><i class="bi bi-broadcast-pin"></i> Live Examination</h2>
        <p class="prof-workspace__sub">Total examinees: <span id="kpiRosterTotal"><?php echo (int)$reportingRosterCount; ?></span> &middot; Polling every 8 seconds</p>
      </div>
    </div>
    <div class="prof-status-deck__body">
      <div class="lms-kpi-row lms-kpi-row--inset">
        <article class="lms-kpi lms-kpi--live"><span class="lms-kpi__label">Active</span><span class="lms-kpi__value" id="kpiActiveCount"><?php echo (int)$liveActive; ?></span></article>
        <article class="lms-kpi lms-kpi--wait"><span class="lms-kpi__label">Not Started</span><span class="lms-kpi__value" id="kpiNotStartedCount"><?php echo (int)$liveNotStarted; ?></span></article>
        <article class="lms-kpi lms-kpi--ok"><span class="lms-kpi__label">Submitted</span><span class="lms-kpi__value" id="kpiSubmittedCount"><?php echo (int)$liveSubmitted; ?></span></article>
        <article class="lms-kpi"><span class="lms-kpi__label">Idle / Away</span><span class="lms-kpi__value" id="kpiIdleCount"><?php echo (int)$liveIdle; ?></span></article>
      </div>
      <div class="lms-kpi-row lms-kpi-row--inset">
        <article class="lms-kpi lms-kpi--total"><span class="lms-kpi__label">Still taking</span><span class="lms-kpi__value" id="kpiTakingCount"><?php echo (int)$metrics['taking_count']; ?></span></article>
        <article class="lms-kpi lms-kpi--ok"><span class="lms-kpi__label">Avg score</span><span class="lms-kpi__value" id="kpiAvgScore"><?php echo $metrics['avg_score'] !== null ? h(number_format((float)$metrics['avg_score'], 1)) . '%' : '-'; ?></span></article>
        <article class="lms-kpi lms-kpi--wait"><span class="lms-kpi__label">Tab leaves</span><span class="lms-kpi__value" id="kpiTabLeavesTotal"><?php echo (int)$totalTabLeaves; ?></span></article>
      </div>
      <?php if ($allFinishedOpenExam): ?>
        <div class="mt-3 text-sm font-semibold text-purple-700 bg-purple-50 border border-purple-200 rounded-lg px-3 py-2">
          There is no deadline and everyone on the roster has submitted, so this exam is treated as finished (even if it had a scheduled opening time).
        </div>
      <?php endif; ?>
    </div>
    </section>

    <div class="prof-monitor-split mb-4">
    <?php endif; ?>
    <?php if (!$isCollege && $ctx['supports_review_sheet'] && $reviewScheduleEligible): ?>
    <section class="prof-workspace prof-workspace--review lms-workspace">
    <div class="prof-panel__head">
      <div>
        <h2 class="examination-section-title"><i class="bi bi-calendar2-check"></i> Review Sheet Access</h2>
        <p class="prof-panel__sub">Set schedule for review access. After the exam, students always see a results summary. The full question-by-question review stays locked until you choose when it opens.</p>
      </div>
    </div>
    <div class="p-4">
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
    </section>
    <?php endif; ?>

    <?php if (!$isCollege): ?>
    <section class="prof-workspace prof-workspace--security" id="examSecurityAlertsWrap">
    <div class="prof-panel__head">
      <div>
        <h2 class="examination-section-title"><i class="bi bi-shield-exclamation"></i> Security &amp; Tab Visibility</h2>
        <p class="prof-panel__sub">Live alerts when students leave the exam tab</p>
      </div>
      <span class="text-xs text-slate-500" id="examSecurityPollStatus">Updating...</span>
    </div>
    <div class="p-4 sec-alert-card" id="examSecurityAlerts">
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
    </section>
    </div>
    <?php endif; ?>

    <?php if ($isCollege): ?>
    <div class="pem-tab-workspace" id="pemTabWorkspace">
      <header class="pem-dash-head">
      <nav class="pem-tab-nav" role="tablist" aria-label="Examination monitor views">
        <button type="button" class="pem-tab-btn is-active" role="tab" id="pemTabBtn-progress" data-pem-tab="progress"
          aria-controls="pemTab-progress" aria-selected="true" tabindex="0"><i class="bi bi-broadcast-pin" aria-hidden="true"></i> Live monitoring</button>
        <button type="button" class="pem-tab-btn" role="tab" id="pemTabBtn-analytics" data-pem-tab="analytics" data-pem-ana="overview"
          aria-controls="pemTab-overview" aria-selected="false" tabindex="-1"><i class="bi bi-bar-chart" aria-hidden="true"></i> Results &amp; analytics</button>
        <button type="button" class="pem-tab-btn" role="tab" id="pemTabBtn-questions" data-pem-tab="analytics" data-pem-ana="questions"
          aria-controls="pemTab-questions" aria-selected="false" tabindex="-1"><i class="bi bi-ui-checks" aria-hidden="true"></i> Question analysis</button>
        <button type="button" class="pem-tab-btn" role="tab" id="pemTabBtn-examinees" data-pem-tab="analytics" data-pem-ana="examinees"
          aria-controls="pemTab-examinees" aria-selected="false" tabindex="-1"><i class="bi bi-person-lines-fill" aria-hidden="true"></i> Student weaknesses</button>
      </nav>
      <div class="pem-page-toolbar" id="pemPageToolbar">
        <form method="get" action="professor_examination_monitor" class="pem-section-bar" id="pemSectionForm" aria-label="Section filter">
          <input type="hidden" name="exam_type" value="regular">
          <input type="hidden" name="exam_id" value="<?php echo (int)$examIdSafe; ?>">
          <label class="pem-analytics__flab" for="pemFilterSection">Section</label>
          <select id="pemFilterSection" name="section" class="admin-btn admin-btn--secondary admin-btn--sm" aria-label="Filter by section">
            <option value=""<?php echo $collegeSectionFilter === '' ? ' selected' : ''; ?>>All sections</option>
            <?php foreach ($pemSectionOptions as $secOpt): ?>
              <?php
                $secIsSelected = $collegeSectionFilter !== '' && $collegeSectionFilter !== '__none__'
                    && examination_section_is_in_list((string)$secOpt, [$collegeSectionFilter]);
              ?>
              <option value="<?php echo h($secOpt); ?>"<?php echo $secIsSelected ? ' selected' : ''; ?>><?php echo h($secOpt); ?></option>
            <?php endforeach; ?>
            <?php if ($pemHasUnassignedSection || $collegeSectionFilter === '__none__'): ?>
              <option value="__none__"<?php echo $collegeSectionFilter === '__none__' ? ' selected' : ''; ?>>No section</option>
            <?php endif; ?>
          </select>
        </form>
        <div class="pem-page-toolbar__actions">
          <?php if ($pemExamEditUrl !== ''): ?>
            <a class="admin-btn admin-btn--ghost admin-btn--sm" href="<?php echo h($pemExamEditUrl); ?>"><i class="bi bi-sliders"></i> View exam</a>
          <?php endif; ?>
          <details class="pem-export-menu">
            <summary class="admin-btn admin-btn--sm pem-export-menu__sum" aria-haspopup="menu"><i class="bi bi-download"></i> Export</summary>
            <div class="pem-export-menu__list" role="menu">
              <a href="<?php echo h($pdfUrl); ?>" class="pem-export-pdf" role="menuitem"><i class="bi bi-file-earmark-pdf"></i> Progress PDF</a>
              <a href="<?php echo h($xlsxUrl); ?>" class="pem-export-xlsx" role="menuitem"><i class="bi bi-file-earmark-spreadsheet"></i> Progress Excel</a>
              <?php if (!empty($analyticsPdfUrl)): ?>
                <a href="<?php echo h($analyticsPdfUrl); ?>" class="pem-export-pdf" role="menuitem"><i class="bi bi-file-earmark-pdf"></i> Analytics PDF</a>
              <?php endif; ?>
              <?php if (!empty($analyticsXlsxUrl)): ?>
                <a href="<?php echo h($analyticsXlsxUrl); ?>" class="pem-export-xlsx" role="menuitem"><i class="bi bi-file-earmark-spreadsheet"></i> Analytics Excel</a>
              <?php endif; ?>
            </div>
          </details>
        </div>
      </div>
      <?php
        $pemAnaChrome = (isset($itemAnalytics) && is_array($itemAnalytics)) ? $itemAnalytics : [];
        $pemAnaSubjects = is_array($pemAnaChrome['subjects'] ?? null) ? $pemAnaChrome['subjects'] : [];
        $pemAnaTopics = is_array($pemAnaChrome['topics'] ?? null) ? $pemAnaChrome['topics'] : [];
        $pemAnaHasTopics = !empty($pemAnaChrome['has_topics']);
      ?>
      </header>
      <div class="pem-ana-chrome">
      <nav class="pem-ana-subnav" id="pemAnaSubnav" role="tablist" aria-label="Results and analytics sections" hidden>
        <button type="button" class="pem-ana-subnav__btn is-active" role="tab" data-pem-ana="overview" aria-controls="pemTab-overview" aria-selected="true" tabindex="0">Overview</button>
        <button type="button" class="pem-ana-subnav__btn" role="tab" data-pem-ana="subjects" aria-controls="pemTab-analytics" aria-selected="false" tabindex="-1">Subject &amp; topic</button>
        <button type="button" class="pem-ana-subnav__btn pem-ana-subnav__btn--dup" role="tab" data-pem-ana="questions" aria-controls="pemTab-questions" aria-selected="false" tabindex="-1">Question analysis</button>
        <button type="button" class="pem-ana-subnav__btn pem-ana-subnav__btn--dup" role="tab" data-pem-ana="examinees" aria-controls="pemTab-examinees" aria-selected="false" tabindex="-1">Student weaknesses</button>
      </nav>
      <div class="pem-analytics__toolbar pem-analytics__toolbar--drill" id="pemAnaDrillToolbar" hidden>
        <label class="pem-analytics__flab" for="pemAnaFilterSubject">Subject</label>
        <select id="pemAnaFilterSubject" class="admin-btn admin-btn--secondary admin-btn--sm">
          <option value="">All subjects</option>
          <?php foreach ($pemAnaSubjects as $s): ?>
            <option value="<?php echo h((string)$s['key']); ?>"><?php echo h((string)$s['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($pemAnaHasTopics): ?>
        <label class="pem-analytics__flab" for="pemAnaFilterTopic">Topic</label>
        <select id="pemAnaFilterTopic" class="admin-btn admin-btn--secondary admin-btn--sm">
          <option value="">All topics</option>
          <?php foreach ($pemAnaTopics as $t): ?>
            <option value="<?php echo h((string)$t['key']); ?>" data-subject="<?php echo h((string)$t['subject_key']); ?>"><?php echo h((string)$t['subject_name'] . ' · ' . (string)$t['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="pemAnaFilterReset">Reset filters</button>
        <p class="pem-analytics__hint pem-analytics__hint--inline" id="pemAnaFilterHint">Drill down within the selected section. Reset does not change the section.</p>
      </div>
      </div>
      <div class="pem-tab-panels">
    <?php endif; ?>

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
          <tr class="border-t border-[var(--admin-border)]"><td class="px-4 py-3 font-semibold"><?php echo h((string)$sub['subject_code']); ?></td><td class="px-4 py-3 text-right"><?php echo $sub['average_score'] !== null ? h((string)$sub['average_score']) . '%' : '�'; ?></td><td class="px-4 py-3 text-right"><?php echo (int)$sub['attempt_count']; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($isCollege): ?>
      <?php require __DIR__ . '/examination_monitor_analytics_view.php'; ?>
    <?php endif; ?>

    <?php if ($isCollege): ?>
    <section class="pem-tab-pane" id="pemTab-progress" data-pem-tab="progress" aria-labelledby="pemProgressTitle">
    <div class="pem-live-dash">
      <div class="pem-kpi-row" role="group" aria-label="Live examination counts">
        <article class="pem-kpi-card pem-kpi-card--total">
          <span class="pem-kpi-card__ico pem-kpi-card__ico--navy" aria-hidden="true"><i class="bi bi-people"></i></span>
          <div>
            <span class="pem-kpi-card__label">Total examinees</span>
            <span class="pem-kpi-card__value" id="kpiRosterTotal"><?php echo (int)$pemRosterN; ?></span>
            <span class="pem-kpi-card__hint">On this roster</span>
            <span class="pem-kpi-card__bar" aria-hidden="true"><i style="width:100%"></i></span>
          </div>
        </article>
        <article class="pem-kpi-card pem-kpi-card--sub">
          <span class="pem-kpi-card__ico pem-kpi-card__ico--ok" aria-hidden="true"><i class="bi bi-check2-circle"></i></span>
          <div>
            <span class="pem-kpi-card__label">Submitted</span>
            <span class="pem-kpi-card__value" id="kpiSubmittedCount"><?php echo (int)$pemSubN; ?></span>
            <span class="pem-kpi-card__hint"><?php echo h($pemPct($pemSubN, $pemDenom)); ?></span>
            <span class="pem-kpi-card__bar" aria-hidden="true"><i style="width:<?php echo (int)$pemBarW($pemSubN, $pemDenom); ?>%"></i></span>
          </div>
        </article>
        <article class="pem-kpi-card pem-kpi-card--take">
          <span class="pem-kpi-card__ico pem-kpi-card__ico--live" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
          <div>
            <span class="pem-kpi-card__label">In progress</span>
            <span class="pem-kpi-card__value" id="kpiTakingCount"><?php echo (int)$pemTakingN; ?></span>
            <span class="pem-kpi-card__hint"><?php echo h($pemPct($pemTakingN, $pemDenom)); ?></span>
            <span class="pem-kpi-card__bar" aria-hidden="true"><i style="width:<?php echo (int)$pemBarW($pemTakingN, $pemDenom); ?>%"></i></span>
          </div>
        </article>
        <article class="pem-kpi-card pem-kpi-card--wait">
          <span class="pem-kpi-card__ico pem-kpi-card__ico--wait" aria-hidden="true"><i class="bi bi-clock-history"></i></span>
          <div>
            <span class="pem-kpi-card__label">Not started</span>
            <span class="pem-kpi-card__value" id="kpiNotStartedCount"><?php echo (int)$pemNsN; ?></span>
            <span class="pem-kpi-card__hint"><?php echo h($pemPct($pemNsN, $pemDenom)); ?></span>
            <span class="pem-kpi-card__bar" aria-hidden="true"><i style="width:<?php echo (int)$pemBarW($pemNsN, $pemDenom); ?>%"></i></span>
          </div>
        </article>
      </div>
      <div class="pem-live-kpi-poll" hidden>
        <span id="kpiActiveCount"><?php echo (int)$liveActive; ?></span>
        <span id="kpiIdleCount"><?php echo (int)$liveIdle; ?></span>
        <span id="kpiAvgScore"><?php echo $metrics['avg_score'] !== null ? h(number_format((float)$metrics['avg_score'], 1)) . '%' : '-'; ?></span>
        <span id="kpiTabLeavesTotal"><?php echo (int)$totalTabLeaves; ?></span>
      </div>
      <div class="pem-live-dash__mid">
      <section class="pem-panel pem-live-dash__progress" aria-label="Exam progress">
        <div class="pem-card-head">
          <h3>Exam progress</h3>
          <span class="pem-card-head__meta">Submitted / in progress / not started</span>
        </div>
        <div class="pem-ring-wrap pem-ring-wrap--hero">
          <div class="pem-ring pem-ring--hero" style="background: conic-gradient(#2563eb 0deg <?php echo (int)$pemRingSub; ?>deg, #38bdf8 <?php echo (int)$pemRingSub; ?>deg <?php echo (int)($pemRingSub + $pemRingTake); ?>deg, var(--pem-ring-track, #e2e8f0) <?php echo (int)($pemRingSub + $pemRingTake); ?>deg 360deg);" role="img" aria-label="Exam progress breakdown">
            <div class="pem-ring__hole">
              <strong><?php echo (int)$pemSubN; ?></strong>
              <span>Submitted</span>
            </div>
          </div>
          <ul class="pem-ring-legend">
            <li>
              <span class="pem-dot pem-dot--sub"></span>
              <span class="pem-ring-legend__lab">Submitted</span>
              <strong><?php echo (int)$pemSubN; ?></strong>
              <em><?php echo h($pemPct($pemSubN, $pemRingTotal)); ?></em>
              <span class="pem-ring-legend__track" aria-hidden="true"><i style="width:<?php echo (int)$pemBarW($pemSubN, $pemRingTotal); ?>%"></i></span>
            </li>
            <li>
              <span class="pem-dot pem-dot--take"></span>
              <span class="pem-ring-legend__lab">In progress</span>
              <strong><?php echo (int)$pemTakingN; ?></strong>
              <em><?php echo h($pemPct($pemTakingN, $pemRingTotal)); ?></em>
              <span class="pem-ring-legend__track" aria-hidden="true"><i style="width:<?php echo (int)$pemBarW($pemTakingN, $pemRingTotal); ?>%"></i></span>
            </li>
            <li>
              <span class="pem-dot pem-dot--ns"></span>
              <span class="pem-ring-legend__lab">Not started</span>
              <strong><?php echo (int)$pemNsN; ?></strong>
              <em><?php echo h($pemPct($pemNsN, $pemRingTotal)); ?></em>
              <span class="pem-ring-legend__track" aria-hidden="true"><i style="width:<?php echo (int)$pemBarW($pemNsN, $pemRingTotal); ?>%"></i></span>
            </li>
          </ul>
        </div>
      </section>
      <section class="pem-panel pem-live-dash__activity" aria-label="Live activity">
        <div class="pem-card-head">
          <h3>Live activity</h3>
          <span class="pem-live-pill"><?php if ($isRunning): ?><span class="prof-live-dot" aria-hidden="true"></span><?php endif; ?> Roster events</span>
        </div>
        <?php if ($pemLiveActivity === []): ?>
          <p class="pem-analytics__empty">No start, submit, or tab-leave timestamps on this roster yet.</p>
        <?php else: ?>
          <ul class="pem-activity">
            <?php foreach ($pemLiveActivity as $act): ?>
              <li class="pem-activity__item pem-activity__item--<?php echo h((string)$act['kind']); ?>">
                <span class="pem-activity__time"><?php echo h(examination_monitor_format_dt($act['ts'])); ?></span>
                <span class="pem-activity__text"><strong><?php echo h((string)$act['name']); ?></strong> <?php echo h((string)$act['text']); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <a class="pem-panel-link js-pem-jump-security" href="#security">View security details</a>
      </section>
      <div class="pem-live-dash__rank">
        <?php
          $pemTop10PanelId = 'pemTop10Live';
          $pemTop10Variant = 'live';
          require __DIR__ . '/examination_monitor_top10_panel.php';
        ?>
      </div>
      </div>
      <div class="pem-live-dash__notes">
        <div class="pem-client-overlap" id="pemClientOverlapBanner" role="status"<?php echo ($clientTrackingLive && $overlapExamineeCount > 0) ? '' : ' hidden'; ?>>
          Overlapping exam clients detected — this is not proof of cheating.
        </div>
        <?php if ($allFinishedOpenExam): ?>
          <div class="mt-2 text-sm font-semibold text-purple-700 bg-purple-50 border border-purple-200 rounded-lg px-3 py-2">
            There is no deadline and everyone on the roster has submitted, so this exam is treated as finished (even if it had a scheduled opening time).
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <section class="prof-workspace prof-workspace--progress pem-roster-card pem-live-dash__roster">
    <div class="pem-progress-head pem-roster-head">
      <div class="pem-roster-head__copy">
        <h2 class="prof-workspace__title" id="pemProgressTitle"><i class="bi bi-people"></i> Examinee roster</h2>
        <p class="pem-roster-head__sub">Live status, progress, and scores for this exam and section.</p>
      </div>
      <div class="pem-progress-head__end">
      <span class="prof-count pem-count-badge"><?php echo (int)$reportingRosterCount; ?> examinee<?php echo (int)$reportingRosterCount === 1 ? '' : 's'; ?></span>
      <?php if (!$isCollege): ?>
      <div class="pem-export-btns">
        <a href="<?php echo h($pdfUrl); ?>" class="admin-btn admin-btn--sm pem-export-pdf" title="Download PDF report">
          <i class="bi bi-file-earmark-pdf"></i> Download PDF
        </a>
        <a href="<?php echo h($xlsxUrl); ?>" class="admin-btn admin-btn--sm pem-export-xlsx" title="Download Excel report">
          <i class="bi bi-file-earmark-spreadsheet"></i> Download Excel
        </a>
      </div>
      <?php endif; ?>
      </div>
    </div>

    <?php if ($students !== []): ?>
    <div class="students-toolbar page-filter pem-roster-filters rounded-xl bg-white/80 border border-white/80 shadow-sm p-3" id="pemRosterFilters">
      <div class="students-search pem-roster-filters__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" id="pemFilterQ" placeholder="Search name or email..." autocomplete="off" aria-label="Search examinees">
      </div>
      <?php if (!$isCollege): ?>
      <select id="pemFilterSection" class="admin-btn admin-btn--secondary admin-btn--sm" aria-label="Filter by section">
        <option value="" selected>All sections</option>
        <?php foreach ($pemSectionOptions as $secOpt): ?>
          <option value="<?php echo h($secOpt); ?>"><?php echo h($secOpt); ?></option>
        <?php endforeach; ?>
        <?php if ($pemHasUnassignedSection): ?>
          <option value="__none__">No section</option>
        <?php endif; ?>
      </select>
      <?php endif; ?>
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
      <?php if ($isCollege): ?>
      <select id="pemFilterProgress" class="admin-btn admin-btn--secondary admin-btn--sm" aria-label="Filter by progress">
        <option value="">All progress</option>
        <option value="started_zero">Started — 0 answers</option>
        <option value="partial">1–99% answered</option>
        <option value="complete">100% answered</option>
      </select>
      <details class="pem-more-filters" id="pemMoreFilters">
        <summary class="admin-btn admin-btn--secondary admin-btn--sm pem-more-filters__sum">More filters</summary>
        <div class="pem-more-filters__panel" role="group" aria-label="More filters">
          <label class="pem-more-filters__opt"><input type="checkbox" id="pemFilterAway"> Away now</label>
          <label class="pem-more-filters__opt"><input type="checkbox" id="pemFilterTabs"> Has tab switches</label>
          <p class="pem-more-filters__hint">Away and tab switches are monitoring signals, not proof of cheating.</p>
        </div>
      </details>
      <?php endif; ?>
      <button type="button" id="pemFilterClear" class="admin-btn admin-btn--ghost admin-btn--sm" hidden>Clear filters</button>
      <span class="students-toolbar__meta" id="pemFilterMeta"><?php echo count($students); ?> examinee<?php echo count($students) === 1 ? '' : 's'; ?></span>
    </div>
    <?php endif; ?>

    <div class="page-table students-table-shell pem-roster-shell overflow-hidden">
      <div class="students-table-scroll">
      <table class="w-full text-left admin-students-table students-table--compact pex-monitor-detail-table pex-list-table<?php echo $isCollege ? ' pem-roster-compact pem-roster-monitor' : ''; ?>" id="pemRosterTable">
        <?php $rosterColspan = 12; ?>
        <colgroup>
          <?php if ($isCollege): ?>
          <col class="pem-col-num" style="width:30px">
          <col class="pem-col-examinee" style="width:185px">
          <col class="pem-col-status" style="width:96px">
          <col class="pem-col-live" style="width:110px">
          <col class="pem-col-progress" style="width:105px">
          <col class="pem-col-score" style="width:80px">
          <col class="pem-col-started" style="width:104px">
          <col class="pem-col-submitted" style="width:104px">
          <col class="pem-col-duration" style="width:70px">
          <col class="pem-col-tabs" style="width:74px">
          <col class="pem-col-seen" style="width:104px">
          <col class="pem-col-review" style="width:78px">
          <?php else: ?>
          <col class="pem-col-examinee" style="width:20%">
          <col class="pem-col-section" style="width:7%">
          <col class="pem-col-status" style="width:9%">
          <col class="pem-col-progress" style="width:9%">
          <col class="pem-col-score" style="width:7%">
          <col class="pem-col-current pem-col-secondary" style="width:5%">
          <col class="pem-col-time pem-col-secondary" style="width:6%">
          <col class="pem-col-tab pem-col-secondary" style="width:7%">
          <col class="pem-col-switches pem-col-secondary" style="width:5%">
          <col class="pem-col-seen pem-col-secondary" style="width:9%">
          <col class="pem-col-started pem-col-secondary" style="width:9%">
          <col class="pem-col-review" style="width:7%">
          <?php endif; ?>
        </colgroup>
        <thead>
          <tr>
            <?php if ($isCollege): ?><th class="pem-col-num" scope="col" aria-label="Row">#</th><?php endif; ?>
            <th><?php echo $isCollege ? 'Examinee / Section' : 'Examinee'; ?></th>
            <?php if (!$isCollege): ?><th>Section</th><?php endif; ?>
            <th>Status</th>
            <?php if ($isCollege): ?><th>Live</th><?php endif; ?>
            <th>Progress</th>
            <th>Score</th>
            <?php if ($isCollege): ?>
            <th>Started</th>
            <th>Submitted</th>
            <th>Duration</th>
            <th>Tab switches</th>
            <th>Last seen</th>
            <?php else: ?>
            <th class="pem-col-secondary">Current</th>
            <th class="pem-col-secondary">Time left</th>
            <th class="pem-col-secondary">Tab</th>
            <th class="pem-col-secondary">Switches</th>
            <th class="pem-col-secondary">Last seen</th>
            <th class="pem-col-secondary">Started</th>
            <?php endif; ?>
            <th><?php echo $isCollege ? 'Review' : 'Details'; ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($students === []): ?>
            <tr><td colspan="<?php echo (int)$rosterColspan; ?>" class="students-empty-cell">No examinees on the roster.</td></tr>
          <?php else: ?>
            <?php $pemRowN = 0; foreach ($students as $st): $pemRowN++; ?>
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
                $scoreCollegeKind = 'empty';
                if ($isCollege) {
                    if ($attemptNorm === 'in_progress') {
                        if ((int)($answeredLive ?? 0) > 0) {
                            $scoreCollegeKind = 'numeric';
                            $pct = (int)round(100 * $correctLive / $answeredLive);
                            $scorePrimary = $correctLive . ' / ' . $answeredLive;
                            $scorePct = $pct . '%';
                        } else {
                            $scoreCollegeKind = 'no_answers';
                        }
                    } elseif ($isSubmitted) {
                        $paperCorRaw = $st['correct_count'] ?? null;
                        $paperTotRaw = $st['total_count'] ?? null;
                        $paperTot = ($paperTotRaw !== null && $paperTotRaw !== '') ? (int)$paperTotRaw : 0;
                        if ($paperTot > 0 && $paperCorRaw !== null && $paperCorRaw !== '') {
                            $scoreCollegeKind = 'numeric';
                            $paperCor = (int)$paperCorRaw;
                            $scorePrimary = $paperCor . ' / ' . $paperTot;
                            $scorePct = (int)round(100 * $paperCor / $paperTot) . '%';
                        }
                    }
                } elseif ($attemptNorm === 'in_progress' || $isSubmitted) {
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
                $sessionMode = 'idle';
                if ($attemptNorm === 'in_progress') {
                    $sessionMode = 'live';
                } elseif ($isSubmitted) {
                    $sessionMode = 'closed';
                }
                $nameForFilter = strtolower(trim((string)($st['full_name'] ?? '')));
                $emailForFilter = strtolower(trim((string)($st['email'] ?? '')));
                $hasStartTs = trim((string)($st['started_at'] ?? '')) !== '';
                $attemptStarted = ($attemptNorm === 'in_progress' || $attemptNorm === 'submitted' || $hasStartTs);
                $progressBucket = 'not_started';
                if ($attemptStarted) {
                    if ($examQuestionCount <= 0) {
                        $progressBucket = 'unavailable';
                    } elseif ((int)($answeredLive ?? 0) <= 0) {
                        $progressBucket = 'started_zero';
                    } else {
                        $pctBucket = (int)round(100 * (int)$answeredLive / $examQuestionCount);
                        $progressBucket = ($pctBucket >= 100) ? 'complete' : 'partial';
                    }
                }
                $awayNowAttr = ($attemptNorm === 'in_progress' && $tabHidden) ? '1' : '0';
                $durSec = examination_monitor_attempt_duration_seconds(
                    isset($st['started_at']) ? (string)$st['started_at'] : null,
                    isset($st['submitted_at']) ? (string)$st['submitted_at'] : null,
                    (string)($st['attempt_status'] ?? ''),
                    time()
                );
                $durFmt = examination_monitor_format_duration_seconds($durSec);
              ?>
              <tr class="js-monitor-row"
                  data-user-id="<?php echo (int)$st['user_id']; ?>"
                  data-attempt-id="<?php echo $attemptIdRow; ?>"
                  data-section="<?php echo h($sectionTxt !== '' ? $sectionTxt : '__none__'); ?>"
                  data-status="<?php echo h($filterStatusKey); ?>"
                  data-session-mode="<?php echo h($sessionMode); ?>"
                  data-progress="<?php echo h($progressBucket); ?>"
                  data-tab-hidden="<?php echo h($awayNowAttr); ?>"
                  data-tab-count="<?php echo (int)$tabLeaveN; ?>"
                  data-name="<?php echo h($nameForFilter); ?>"
                  data-email="<?php echo h($emailForFilter); ?>">
                <?php if ($isCollege): ?>
                <td class="pem-row-num" data-label="Row"><?php echo (int)$pemRowN; ?></td>
                <?php endif; ?>
                <td class="college-student-account-cell" data-label="Examinee">
                  <div class="student-cell college-student-account">
                    <span class="student-avatar-cell" aria-hidden="true">
                      <span class="student-avatar-media">
                        <span class="student-avatar-initial"><?php
                          $pemIniName = trim((string)($st['full_name'] ?? ''));
                          $pemIniParts = preg_split('/\s+/', $pemIniName, -1, PREG_SPLIT_NO_EMPTY);
                          $pemIni = strtoupper(substr((string)($pemIniParts[0] ?? 'S'), 0, 1));
                          if (isset($pemIniParts[1])) {
                              $pemIni .= strtoupper(substr((string)$pemIniParts[1], 0, 1));
                          }
                          echo h($pemIni !== '' ? $pemIni : 'S');
                        ?></span>
                      </span>
                    </span>
                    <div class="student-cell__text">
                      <button type="button" class="student-meta-name student-name js-open-monitor-detail text-left" data-user-id="<?php echo (int)$st['user_id']; ?>">
                        <?php echo h((string)$st['full_name']); ?>
                      </button>
                      <span class="student-meta-sub"><?php
                        $typeLbl = examination_monitor_examinee_type_label((string)($st['review_type'] ?? ''));
                        if ($isCollege) {
                            echo h($typeLbl . ' · ' . ($sectionTxt !== '' ? $sectionTxt : 'No section'));
                        } else {
                            echo h($typeLbl);
                        }
                      ?></span>
                    </div>
                  </div>
                </td>
                <?php if (!$isCollege): ?>
                <td class="pem-section-cell" data-label="Section"><?php echo $sectionTxt !== '' ? '<span class="pem-section-val">' . h($sectionTxt) . '</span>' : '<span class="opacity-60">-</span>'; ?></td>
                <?php endif; ?>
                <td class="js-status-cell" data-label="Status"><?php echo $statusBadge; ?></td>
                <?php if ($isCollege): ?>
                <?php
                  $rowInst = 0;
                  $rowScopes = 0;
                  if ($attemptNorm === 'in_progress' && $clientTrackingLive && $attemptIdRow > 0) {
                      $rowInst = (int)($overlapByAttempt[$attemptIdRow]['instances'] ?? 0);
                      $rowScopes = (int)($overlapByAttempt[$attemptIdRow]['scopes'] ?? 0);
                  }
                  $isOverlap = $rowInst >= 2;
                  $startedHtml = examination_monitor_format_dt_html($st['started_at'] ?? null);
                  $endHtml = examination_monitor_format_dt_html($st['submitted_at'] ?? null);
                  $seenHtml = examination_monitor_format_dt_html($st['last_seen_at'] ?? null);
                ?>
                <td class="pem-live-cell" data-label="Live">
                  <span class="pem-live-empty"<?php echo $sessionMode === 'live' ? ' hidden' : ''; ?> aria-hidden="<?php echo $sessionMode === 'live' ? 'true' : 'false'; ?>">—</span>
                  <span class="js-current-cell"><?php echo ($attemptNorm === 'in_progress' && $curQ) ? ('Q' . (int)$curQ) : ''; ?></span>
                  <span class="js-time-cell"><?php echo ($remainFmt !== null && $remainFmt !== '') ? h($remainFmt) : ''; ?></span>
                  <span class="js-tab-vis-cell"><?php
                    if ($attemptNorm === 'in_progress') {
                        echo $tabHidden
                            ? '<span class="admin-badge admin-badge--warning">Away</span>'
                            : '<span class="admin-badge admin-badge--success">Visible</span>';
                    }
                  ?></span>
                  <span class="js-meta-clients<?php echo $isOverlap ? ' is-overlap' : ''; ?>" title="An exam page instance is one open exam page, not a verified physical device, and is not proof of cheating."><?php
                    if ($isOverlap) {
                        echo h($rowInst . ' pages');
                    } elseif ($attemptNorm === 'in_progress' && $clientTrackingLive) {
                        echo h(examination_monitor_client_instances_label(true, $rowInst, $rowScopes));
                    }
                  ?></span>
                </td>
                <?php endif; ?>
                <td class="js-progress-cell" data-label="Progress">
                  <?php if ($isCollege): ?>
                    <?php $showProgress = ($progressBucket === 'started_zero' || $progressBucket === 'partial' || $progressBucket === 'complete'); ?>
                    <div class="pem-progress-stack"<?php echo $showProgress ? '' : ' hidden'; ?>>
                      <div class="pem-progress-label js-progress-label"><?php echo (int)($answeredLive ?? 0); ?> / <?php echo (int)$examQuestionCount; ?></div>
                      <div class="pem-mini-progress" aria-hidden="true"><span class="js-progress-fill" style="width:<?php echo $progressPct; ?>%"></span></div>
                      <div class="pem-progress-pct js-progress-pct"><?php echo (int)$progressPct; ?>%</div>
                    </div>
                    <span class="pem-progress-empty opacity-60"<?php echo $showProgress ? ' hidden' : ''; ?>>—</span>
                  <?php elseif ($attemptNorm === 'in_progress' || $isSubmitted): ?>
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
                  <?php if ($isCollege && $scoreCollegeKind === 'no_answers'): ?>
                    <span class="pem-score-empty pem-score-empty--info">No answers yet</span>
                  <?php elseif ($isCollege && $scoreCollegeKind === 'empty'): ?>
                    <span class="opacity-60 pem-score-empty">—</span>
                  <?php elseif ($scorePrimary === '-'): ?>
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
                <?php if ($isCollege): ?>
                <td class="js-started-cell pem-dt-cell" data-label="Started"><?php echo $startedHtml !== '' ? $startedHtml : '<span class="opacity-60">—</span>'; ?></td>
                <td class="js-submitted-cell pem-dt-cell" data-label="Submitted"><?php echo $endHtml !== '' ? $endHtml : '<span class="opacity-60">—</span>'; ?></td>
                <td class="js-duration-cell pem-dur-cell" data-label="Duration"><?php echo $durFmt !== '' ? h($durFmt) : '<span class="opacity-60">—</span>'; ?></td>
                <td class="pem-tab-cell" data-label="Tab switches">
                  <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm js-open-tab-events pem-switch-btn" data-attempt-id="<?php echo $attemptIdRow; ?>" data-name="<?php echo h((string)$st['full_name']); ?>"<?php echo $attemptIdRow > 0 ? '' : ' hidden'; ?>>
                    <span class="js-tab-count"><?php echo (int)$tabLeaveN; ?></span>
                  </button>
                  <span class="pem-tab-empty opacity-60"<?php echo $attemptIdRow > 0 ? ' hidden' : ''; ?>>—</span>
                </td>
                <td class="js-seen-cell pem-dt-cell" data-label="Last seen"><?php echo $seenHtml !== '' ? $seenHtml : '<span class="opacity-60">—</span>'; ?></td>
                <?php else: ?>
                <td class="js-current-cell pem-center-cell pem-col-secondary" data-label="Current"><?php echo ($attemptNorm === 'in_progress' && $curQ) ? ('Q' . (int)$curQ) : '<span class="opacity-60">-</span>'; ?></td>
                <td class="js-time-cell pem-center-cell pem-time-cell pem-col-secondary" data-label="Time left"><?php echo ($remainFmt !== null && $remainFmt !== '') ? h($remainFmt) : '<span class="opacity-60">-</span>'; ?></td>
                <td class="js-tab-vis-cell pem-center-cell pem-col-secondary" data-label="Tab">
                  <?php if ($attemptNorm === 'in_progress'): ?>
                    <?php echo $tabHidden
                        ? '<span class="admin-badge admin-badge--warning">Hidden</span>'
                        : '<span class="admin-badge admin-badge--success">Visible</span>'; ?>
                  <?php else: ?>
                    <span class="opacity-60">-</span>
                  <?php endif; ?>
                </td>
                <td class="pem-center-cell pem-col-secondary" data-label="Switches">
                  <?php if ($tabLeaveN > 0 && $attemptIdRow > 0): ?>
                    <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm js-open-tab-events pem-switch-btn" data-attempt-id="<?php echo $attemptIdRow; ?>" data-name="<?php echo h((string)$st['full_name']); ?>">
                      <span class="js-tab-count"><?php echo $tabLeaveN; ?></span>
                    </button>
                  <?php else: ?>
                    <span class="pem-switch-n js-tab-count opacity-60"><?php echo $tabLeaveN; ?></span>
                  <?php endif; ?>
                </td>
                <td class="js-seen-cell pem-dt-cell pem-col-secondary" data-label="Last seen">
                  <?php
                    $seenHtml = examination_monitor_format_dt_html($st['last_seen_at'] ?? null);
                    echo $seenHtml !== '' ? $seenHtml : '<span class="opacity-60">-</span>';
                  ?>
                </td>
                <td class="js-started-cell pem-dt-cell pem-col-secondary" data-label="Started">
                  <?php
                    $startedHtml = examination_monitor_format_dt_html($st['started_at'] ?? null);
                    echo $startedHtml !== '' ? $startedHtml : '<span class="opacity-60">-</span>';
                  ?>
                </td>
                <?php endif; ?>
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
              <td colspan="<?php echo (int)$rosterColspan; ?>" class="students-empty-cell">No examinees match these filters.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
    </section>
    <?php if ($isCollege): ?>
    </div>
    </section>
    <section class="pem-tab-pane" id="pemTab-security" data-pem-tab="progress" aria-labelledby="pemSecurityTitle">
      <section class="prof-workspace pem-security-workspace mb-4">
        <div class="pem-progress-head pem-sec-head">
          <div class="pem-roster-head__copy">
            <h2 class="prof-workspace__title" id="pemSecurityTitle"><i class="bi bi-shield-exclamation"></i> Security Events</h2>
            <p class="pem-roster-head__sub">Review signals for this exam — not a cheating verdict.</p>
          </div>
          <span class="prof-count pem-count-badge" id="pemSecurityCount"><?php echo (int)($securityEventStats['matching'] ?? count($securityEventRows ?? [])); ?> matching</span>
        </div>
        <p class="pem-security-note pem-security-note--tight" role="note">
          <strong>Recorded activity, not proof of cheating.</strong> Stored session events only. The ordinary browser cannot reliably detect screenshots or screen recording. Tab leaves and overlapping page instances are review signals, not misconduct.
        </p>
        <div class="pem-sec-dash">
        <aside class="pem-sec-dash__side">
        <div class="pem-client-overlap pem-client-overlap--tab" id="pemClientOverlapNote" role="status"<?php echo ($clientTrackingLive && $overlapExamineeCount > 0) ? '' : ' hidden'; ?>>
          Overlapping exam clients detected — this is not proof of cheating.
        </div>
        <div class="pem-concurrent-alerts" id="pemConcurrentAlerts" data-tracking-live="<?php echo $clientTrackingLive ? '1' : '0'; ?>">
          <div class="pem-tab-live-feed__head">
            <strong>Overlapping exam instances</strong>
          </div>
          <p class="pem-security-note pem-security-note--inline mb-2">
            An <em>exam page instance</em> is one open exam page (for example a browser tab). A <em>browser scope</em> is a stored identifier in that browser profile. Neither value identifies a physical device, and overlap is not proof of cheating.
          </p>
          <?php if (!$clientTrackingLive): ?>
            <p class="mb-2 text-sm opacity-80" id="pemConcurrentAlertsEmpty">Exam-page instance tracking is not currently enabled, so live instance counts are not available. Stored alerts below are shown only if they already exist.</p>
          <?php endif; ?>
          <div id="pemConcurrentAlertsList">
            <?php if ($concurrentAlerts === []): ?>
              <p class="mb-0 text-sm opacity-70" id="pemConcurrentAlertsNone">No overlapping-instance alerts recorded for this exam.</p>
            <?php else: ?>
              <?php foreach ($concurrentAlerts as $cal): ?>
                <div class="sec-alert-row pem-concurrent-row">
                  <span class="sec-alert-dot" aria-hidden="true"></span>
                  <div>
                    <strong><?php echo h((string)($cal['full_name'] ?? 'Examinee')); ?></strong>
                    — overlapping exam instances detected — review recommended; this is not proof of cheating.
                    <div class="text-xs opacity-80 mt-0.5">
                      Observed page instances: <?php echo (int)$cal['instance_count']; ?>
                      · browser scopes: <?php echo (int)$cal['browser_scope_count']; ?>
                      · first: <?php echo h((string)($cal['first_detected_fmt'] !== '' ? $cal['first_detected_fmt'] : '—')); ?>
                      · last: <?php echo h((string)($cal['last_detected_fmt'] !== '' ? $cal['last_detected_fmt'] : '—')); ?>
                      · review: <?php echo h(str_replace('_', ' ', (string)($cal['review_state'] ?? 'pending review'))); ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        </aside>
        <?php
          $secNameByUser = [];
          foreach ($students as $sxSecNm) {
              $secNameByUser[(int)($sxSecNm['user_id'] ?? 0)] = (string)($sxSecNm['full_name'] ?? '');
          }
          $secRows = is_array($securityEventRows ?? null) ? $securityEventRows : [];
          $secShown = count($secRows);
          $secMatching = (int)($securityEventStats['matching'] ?? $secShown);
          $secExaminees = (int)($securityEventStats['examinees'] ?? 0);
          $secTypes = is_array($securityEventStats['types'] ?? null) ? $securityEventStats['types'] : [];
          $secFilt = is_array($securityEventFilters ?? null) ? $securityEventFilters : ['user_id' => 0, 'event_type' => '', 'from' => null, 'until' => null];
          $secFromLocal = '';
          if (!empty($secFilt['from']) && preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/', (string)$secFilt['from'], $sfm)) {
              $secFromLocal = $sfm[1] . 'T' . $sfm[2];
          }
          $secUntilLocal = '';
          if (!empty($secFilt['until']) && preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/', (string)$secFilt['until'], $sum)) {
              $secUntilLocal = $sum[1] . 'T' . $sum[2];
          }
          $liveLeavePeople = 0;
          $liveLeaveTotal = 0;
          foreach ($students as $sx) {
              $tn = (int)($sx['tab_switch_count'] ?? 0);
              if ($tn > 0) {
                  $liveLeavePeople++;
                  $liveLeaveTotal += $tn;
              }
          }
          $secTypeOptions = [
              'tab_hidden' => 'Tab left',
              'tab_visible' => 'Returned to exam',
              'exam_started' => 'Exam started',
              'exam_submitted' => 'Exam submitted',
              'exam_expired' => 'Exam expired / auto-submitted',
          ];
        ?>
        <div class="pem-sec-dash__main">
        <div class="pem-tab-live-feed pem-tab-live-feed--compact" id="examSecurityAlertsWrap">
          <div class="pem-tab-live-feed__head">
            <strong>Live tab leaves</strong>
            <span class="text-xs text-slate-500" id="examSecurityPollStatus">Updating...</span>
          </div>
          <p class="pem-sec-live-sum mb-1" id="examSecuritySummary"><?php
            echo $liveLeavePeople === 0
                ? 'No tab leaves on the current roster. This is a live count, not the history below.'
                : ((int)$liveLeavePeople . ' examinee' . ($liveLeavePeople === 1 ? '' : 's') . ' · ' . (int)$liveLeaveTotal . ' tab leave' . ($liveLeaveTotal === 1 ? '' : 's') . ' (roster summary, not the event log).');
          ?></p>
          <details class="pem-sec-live-details" id="examSecurityAlerts"<?php echo $liveLeavePeople > 0 ? '' : ' hidden'; ?>>
            <summary>Show roster breakdown</summary>
            <div id="examSecurityFeed" class="text-amber-950">
              <?php foreach ($students as $sx): ?>
                <?php if ((int)($sx['tab_switch_count'] ?? 0) <= 0) { continue; } ?>
                <div class="sec-alert-row" data-feed-user="<?php echo (int)$sx['user_id']; ?>">
                  <span class="sec-alert-dot" aria-hidden="true"></span>
                  <div>
                    <strong><?php echo h((string)$sx['full_name']); ?></strong>
                    · <?php echo (int)$sx['tab_switch_count']; ?>
                    <?php $lt = examination_monitor_format_dt($sx['last_tab_switch_at'] ?? null); ?>
                    <?php if ($lt !== ''): ?> · last <?php echo h($lt); ?><?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </details>
        </div>

        <div class="pem-sec-history" id="pemSecurityHistory">
          <div class="pem-progress-head">
            <h3 class="prof-workspace__title" id="pemRecordedSecurityTitle" style="font-size:1rem">Recorded Security Events</h3>
          </div>
          <p class="pem-analytics__hint pem-sec-history__hint">Stored event history for this exam and section. Live tab leaves above is a current roster summary and is not this list.</p>
          <div class="pem-sec-kpis" role="group" aria-label="Recorded event summary">
            <div class="pem-sec-kpi"><span class="pem-sec-kpi__l">Matching events</span><span class="pem-sec-kpi__v"><?php echo (int)$secMatching; ?></span></div>
            <div class="pem-sec-kpi"><span class="pem-sec-kpi__l">Examinees</span><span class="pem-sec-kpi__v"><?php echo (int)$secExaminees; ?></span></div>
            <div class="pem-sec-kpi"><span class="pem-sec-kpi__l">Shown</span><span class="pem-sec-kpi__v"><?php echo (int)$secShown; ?><?php echo $secMatching > $secShown ? ' of ' . (int)$secMatching : ''; ?></span></div>
          </div>
          <?php if ($secTypes !== []): ?>
            <p class="pem-sec-typecounts"><?php
              $chips = [];
              foreach ($secTypes as $tc) {
                  $chips[] = h((string)$tc['label']) . ' ' . (int)$tc['count'];
              }
              echo implode(' · ', $chips);
            ?></p>
          <?php endif; ?>
          <form method="get" action="professor_examination_monitor" class="pem-sec-filters" id="pemSecurityFilterForm">
            <input type="hidden" name="exam_type" value="regular">
            <input type="hidden" name="exam_id" value="<?php echo (int)$examIdSafe; ?>">
            <?php if ($collegeSectionFilter !== ''): ?>
              <input type="hidden" name="section" value="<?php echo h($collegeSectionFilter); ?>">
            <?php endif; ?>
            <label class="pem-analytics__flab" for="pemSecFilterUser">Examinee</label>
            <select id="pemSecFilterUser" name="sec_user" class="admin-btn admin-btn--secondary admin-btn--sm">
              <option value="">All examinees</option>
              <?php foreach ($secNameByUser as $sid => $sname): ?>
                <?php if ($sid <= 0 || trim($sname) === '') { continue; } ?>
                <option value="<?php echo (int)$sid; ?>"<?php echo (int)$secFilt['user_id'] === (int)$sid ? ' selected' : ''; ?>><?php echo h($sname); ?></option>
              <?php endforeach; ?>
            </select>
            <label class="pem-analytics__flab" for="pemSecFilterType">Event type</label>
            <select id="pemSecFilterType" name="sec_type" class="admin-btn admin-btn--secondary admin-btn--sm">
              <option value="">All types</option>
              <?php foreach ($secTypeOptions as $tKey => $tLab): ?>
                <option value="<?php echo h($tKey); ?>"<?php echo (string)$secFilt['event_type'] === $tKey ? ' selected' : ''; ?>><?php echo h($tLab); ?></option>
              <?php endforeach; ?>
            </select>
            <label class="pem-analytics__flab" for="pemSecFilterFrom">From</label>
            <input id="pemSecFilterFrom" class="pem-sec-dt" type="datetime-local" name="sec_from" value="<?php echo h($secFromLocal); ?>">
            <label class="pem-analytics__flab" for="pemSecFilterUntil">Until</label>
            <input id="pemSecFilterUntil" class="pem-sec-dt" type="datetime-local" name="sec_until" value="<?php echo h($secUntilLocal); ?>">
            <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm">Apply</button>
            <a class="admin-btn admin-btn--ghost admin-btn--sm" href="<?php echo h($scopeUrl); ?>#security">Reset</a>
          </form>
          <?php if ($secMatching > $secShown): ?>
            <p class="pem-analytics__hint">Showing the <?php echo (int)$secShown; ?> most recent of <?php echo (int)$secMatching; ?> matching events.</p>
          <?php endif; ?>
          <ol class="pem-sec-timeline" id="pemSecurityTimeline">
            <?php if ($secRows === []): ?>
              <li class="pem-sec-tl pem-sec-tl--empty">No recorded session events match these filters.</li>
            <?php else: ?>
              <?php foreach ($secRows as $sev): ?>
                <?php
                  $sevUid = (int)($sev['user_id'] ?? 0);
                  $sevName = $secNameByUser[$sevUid] ?? ('User #' . $sevUid);
                  $sevAid = (int)($sev['attempt_id'] ?? 0);
                  $sevType = (string)($sev['event_type'] ?? '');
                ?>
                <li class="pem-sec-tl" data-user-id="<?php echo $sevUid; ?>" data-type="<?php echo h($sevType); ?>">
                  <time class="pem-sec-tl__time" datetime="<?php echo h((string)($sev['occurred_at'] ?? '')); ?>"><?php echo h((string)($sev['occurred_fmt'] ?? '—')); ?></time>
                  <div class="pem-sec-tl__body">
                    <?php if ($sevAid > 0): ?>
                      <button type="button" class="student-meta-name js-open-tab-events text-left pem-sec-tl__name" data-attempt-id="<?php echo $sevAid; ?>" data-name="<?php echo h($sevName); ?>"><?php echo h($sevName); ?></button>
                    <?php else: ?>
                      <span class="pem-sec-tl__name"><?php echo h($sevName); ?></span>
                    <?php endif; ?>
                    <span class="admin-badge admin-badge--neutral"><?php echo h((string)($sev['label'] ?? $sevType)); ?></span>
                    <?php if ((string)($sev['detail'] ?? '') !== ''): ?>
                      <span class="pem-sec-tl__detail"><?php echo h((string)$sev['detail']); ?></span>
                    <?php endif; ?>
                  </div>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ol>
        </div>
        </div>
        </div>
      </section>
    </section>
      </div>
    </div>
    <?php endif; ?>

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
    /* Dense Examinee Progress table � scoped to this monitor page only */
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
    body.admin-app.examination-admin-page #pemRosterTable.pem-roster-compact { min-width: 1080px; }
    body.admin-app.examination-admin-page #pemRosterTable.pem-roster-compact col.pem-col-secondary,
    body.admin-app.examination-admin-page #pemRosterTable.pem-roster-compact .pem-col-secondary { display: none !important; }
    .pem-concurrent-alerts {
      margin: 0 0 0.85rem;
      padding: 0.65rem 0.85rem;
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 0.85rem;
    }
    .pem-security-note--inline { font-size: 0.8rem; }
    .pem-concurrent-row { margin-bottom: 0.45rem; }
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
    body.admin-app.examination-admin-page #pemRosterTable.pem-roster-compact .student-cell {
      align-items: center;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-activity {
      display: flex;
      flex-direction: column;
      gap: 0.12rem;
      min-width: 0;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-activity__row {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 0.35rem;
      min-width: 0;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-activity__row.is-empty { display: none; }
    body.admin-app.examination-admin-page #pemRosterTable .pem-activity__k {
      font-size: 0.62rem;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      color: #64748b;
      flex: 0 0 auto;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-activity__v {
      font-size: 0.72rem;
      font-weight: 600;
      text-align: right;
      min-width: 0;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-activity__v .pem-dt { align-items: flex-end; }
    body.admin-app.examination-admin-page #pemRosterTable .pem-activity__aux {
      display: flex;
      flex-wrap: wrap;
      gap: 0.25rem;
      align-items: center;
    }
    body.admin-app.examination-admin-page #pemRosterTable .pem-activity__aux:empty,
    body.admin-app.examination-admin-page #pemRosterTable .pem-activity__aux.is-empty { display: none; }
    body.admin-app.examination-admin-page #pemRosterTable .pem-activity__inst {
      font-size: 0.65rem;
      color: #64748b;
      line-height: 1.25;
    }
    body.admin-app.examination-admin-page #pemRosterTable .js-duration-cell {
      font-variant-numeric: tabular-nums;
      font-weight: 700;
      font-size: 0.78rem;
      white-space: nowrap;
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
    #pemSectionForm { display: inline-flex; margin: 0; align-items: center; gap: 0.4rem; }
  </style>
  <script>
  (function () {
    var workspace = document.getElementById('pemTabWorkspace');
    if (!workspace) return;
    var allowed = { overview: true, progress: true, analytics: true, security: true };
    var tabOrder = ['progress', 'analytics'];
    var anaPanes = { overview: true, subjects: true, questions: true, examinees: true };
    var currentAnaPane = 'overview';
    function normalizeTab(name) {
      if (name === 'subjects' || name === 'questions' || name === 'overview' || name === 'examinees' || name === 'weaknesses') return 'analytics';
      if (name === 'security') return 'progress';
      return allowed[name] ? name : 'progress';
    }
    function anaPaneFromHash() {
      var raw = (window.location.hash || '').replace(/^#/, '');
      if (raw === 'weaknesses') return 'examinees';
      return anaPanes[raw] ? raw : 'overview';
    }
    function tabFromHash() {
      var raw = (window.location.hash || '').replace(/^#/, '');
      return normalizeTab(raw);
    }
    function showAnaPane(pane, pushHash) {
      currentAnaPane = anaPanes[pane] ? pane : 'overview';
      workspace.querySelectorAll('[data-pem-ana]').forEach(function (el) {
        if (!el.classList.contains('pem-tab-pane')) {
          return;
        }
        el.hidden = el.getAttribute('data-pem-ana') !== currentAnaPane;
      });
      var subnav = document.getElementById('pemAnaSubnav');
      if (subnav) {
        subnav.hidden = currentAnaPane !== 'overview' && currentAnaPane !== 'subjects';
        subnav.querySelectorAll('[data-pem-ana]').forEach(function (btn) {
          var on = btn.getAttribute('data-pem-ana') === currentAnaPane;
          btn.classList.toggle('is-active', on);
          btn.setAttribute('aria-selected', on ? 'true' : 'false');
          btn.setAttribute('tabindex', on ? '0' : '-1');
        });
      }
      var drill = document.getElementById('pemAnaDrillToolbar');
      if (drill) drill.hidden = currentAnaPane === 'overview';
      var hash = currentAnaPane === 'overview' ? 'analytics' : currentAnaPane;
      if (pushHash && window.location.hash.replace(/^#/, '') !== hash) {
        if (history.replaceState) {
          history.replaceState(null, '', '#' + hash);
        } else {
          window.location.hash = hash;
        }
      }
      if (typeof window.pemAnaResizeCharts === 'function') {
        window.requestAnimationFrame(function () {
          window.pemAnaResizeCharts();
        });
      }
    }
    function showTab(name, pushHash) {
      name = normalizeTab(name);
      workspace.querySelectorAll('.pem-tab-nav .pem-tab-btn').forEach(function (btn) {
        var tab = btn.getAttribute('data-pem-tab');
        var ana = btn.getAttribute('data-pem-ana');
        var on = tab === name;
        if (name === 'analytics' && ana) {
          if (ana === 'overview') {
            on = currentAnaPane === 'overview' || currentAnaPane === 'subjects';
          } else {
            on = currentAnaPane === ana;
          }
        } else if (name !== 'analytics') {
          on = tab === name && !ana;
        }
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
        btn.setAttribute('tabindex', on ? '0' : '-1');
      });
      workspace.querySelectorAll('.pem-tab-pane').forEach(function (pane) {
        var on = pane.getAttribute('data-pem-tab') === name;
        if (on && name === 'analytics') {
          var ana = pane.getAttribute('data-pem-ana');
          if (ana) {
            on = ana === currentAnaPane;
          }
        }
        pane.hidden = !on;
      });
      var subnav = document.getElementById('pemAnaSubnav');
      if (subnav) {
        subnav.hidden = name !== 'analytics' || (currentAnaPane !== 'overview' && currentAnaPane !== 'subjects');
      }
      var drill = document.getElementById('pemAnaDrillToolbar');
      if (drill) drill.hidden = name !== 'analytics' || currentAnaPane === 'overview';
      workspace.classList.toggle('is-drill', name === 'analytics');
      var hashName = name;
      if (name === 'analytics') {
        hashName = currentAnaPane === 'overview' ? 'analytics' : currentAnaPane;
      }
      if (pushHash && window.location.hash.replace(/^#/, '') !== hashName) {
        if (history.replaceState) {
          history.replaceState(null, '', '#' + hashName);
        } else {
          window.location.hash = hashName;
        }
      }
      if (name === 'analytics') {
        showAnaPane(currentAnaPane, false);
      }
    }
    workspace.querySelectorAll('.pem-tab-nav .pem-tab-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ana = btn.getAttribute('data-pem-ana');
        if (ana) currentAnaPane = ana;
        showTab(btn.getAttribute('data-pem-tab') || 'progress', true);
      });
      btn.addEventListener('keydown', function (e) {
        var i = tabOrder.indexOf(btn.getAttribute('data-pem-tab') || '');
        if (i < 0) return;
        var next = -1;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = (i + 1) % tabOrder.length;
        if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = (i - 1 + tabOrder.length) % tabOrder.length;
        if (e.key === 'Home') next = 0;
        if (e.key === 'End') next = tabOrder.length - 1;
        if (next < 0) return;
        e.preventDefault();
        var target = workspace.querySelector('.pem-tab-btn[data-pem-tab="' + tabOrder[next] + '"]');
        if (target) {
          target.focus();
          showTab(tabOrder[next], true);
        }
      });
    });
    window.addEventListener('hashchange', function () {
      currentAnaPane = anaPaneFromHash();
      showTab(tabFromHash(), false);
    });
    var subnav = document.getElementById('pemAnaSubnav');
    if (subnav) {
      subnav.querySelectorAll('[data-pem-ana]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          currentAnaPane = btn.getAttribute('data-pem-ana') || 'overview';
          showTab('analytics', false);
          showAnaPane(currentAnaPane, true);
        });
      });
    }
    currentAnaPane = anaPaneFromHash();
    showTab(tabFromHash(), false);
    document.querySelectorAll('.js-pem-jump-analytics').forEach(function (btn) {
      btn.addEventListener('click', function () {
        currentAnaPane = btn.getAttribute('data-pem-ana') || 'overview';
        showTab('analytics', true);
      });
    });
    document.querySelectorAll('.js-pem-jump-security').forEach(function (el) {
      el.addEventListener('click', function (e) {
        e.preventDefault();
        showTab('progress', true);
        var sec = document.getElementById('pemTab-security');
        if (sec && sec.scrollIntoView) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
    var quickExport = document.getElementById('pemQuickExport');
    if (quickExport) {
      quickExport.addEventListener('click', function () {
        var menu = document.querySelector('#pemPageToolbar .pem-export-menu');
        if (menu) {
          menu.open = true;
          menu.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      });
    }
    var secFilterForm = document.getElementById('pemSecurityFilterForm');
    if (secFilterForm) {
      secFilterForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var params = new URLSearchParams(new FormData(secFilterForm));
        window.location.assign((secFilterForm.getAttribute('action') || 'professor_examination_monitor') + '?' + params.toString() + '#progress');
      });
    }
    var sectionForm = document.getElementById('pemSectionForm');
    if (sectionForm) {
      sectionForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var params = new URLSearchParams(new FormData(sectionForm));
        window.location.assign(sectionForm.getAttribute('action') + '?' + params.toString() + (window.location.hash || ''));
      });
    }
  })();
  </script>
  <script>
  (function () {
    var liveUrl = <?php echo json_encode($liveUrl, JSON_UNESCAPED_SLASHES); ?>;
    var isCollegeMonitor = <?php echo $isCollege ? 'true' : 'false'; ?>;
    var examFinished = <?php echo !empty($isFinished) ? 'true' : 'false'; ?>;
    var pollEl = document.getElementById('examSecurityPollStatus');
    var feedEl = document.getElementById('examSecurityFeed');
    var kpiTab = document.getElementById('kpiTabLeavesTotal');
    var prevByUser = {};
    var studentCache = {};
    var filterQ = document.getElementById('pemFilterQ');
    var filterSection = document.getElementById('pemFilterSection');
    var filterStatus = document.getElementById('pemFilterStatus');
    var filterProgress = document.getElementById('pemFilterProgress');
    var filterAway = document.getElementById('pemFilterAway');
    var filterTabs = document.getElementById('pemFilterTabs');
    var filterClear = document.getElementById('pemFilterClear');
    var filterMeta = document.getElementById('pemFilterMeta');
    var filterEmpty = document.getElementById('pemFilterEmpty');
    var totalRoster = document.querySelectorAll('.js-monitor-row').length;

    document.querySelectorAll('.js-monitor-row').forEach(function (tr) {
      var uid = tr.getAttribute('data-user-id');
      var cell = tr.querySelector('.js-tab-count');
      if (uid && cell) prevByUser[uid] = parseInt(String(cell.textContent).replace(/\D+/g, ''), 10) || 0;
    });

    function rosterFiltersActive(q, sec, st) {
      var prog = filterProgress ? filterProgress.value : '';
      var away = !!(filterAway && filterAway.checked);
      var tabs = !!(filterTabs && filterTabs.checked);
      var clientSec = isCollegeMonitor ? '' : sec;
      return !!(q || clientSec || st || prog || away || tabs);
    }

    function applyRosterFilters() {
      var q = (filterQ && filterQ.value ? filterQ.value : '').trim().toLowerCase();
      var sec = filterSection ? filterSection.value : '';
      var st = filterStatus ? filterStatus.value : '';
      var prog = filterProgress ? filterProgress.value : '';
      var away = !!(filterAway && filterAway.checked);
      var tabs = !!(filterTabs && filterTabs.checked);
      var visible = 0;
      document.querySelectorAll('.js-monitor-row').forEach(function (tr) {
        var ok = true;
        if (!isCollegeMonitor && sec && (tr.getAttribute('data-section') || '') !== sec) ok = false;
        if (ok && st && (tr.getAttribute('data-status') || '') !== st) ok = false;
        if (ok && prog && (tr.getAttribute('data-progress') || '') !== prog) ok = false;
        if (ok && away && tr.getAttribute('data-tab-hidden') !== '1') ok = false;
        if (ok && tabs && (parseInt(tr.getAttribute('data-tab-count'), 10) || 0) <= 0) ok = false;
        if (ok && q) {
          var name = tr.getAttribute('data-name') || '';
          var email = tr.getAttribute('data-email') || '';
          if (name.indexOf(q) === -1 && email.indexOf(q) === -1) ok = false;
        }
        tr.classList.toggle('is-filtered-out', !ok);
        if (ok) {
          visible += 1;
          var numCell = tr.querySelector('.pem-row-num');
          if (numCell) numCell.textContent = String(visible);
        }
      });
      if (filterEmpty) filterEmpty.hidden = visible !== 0 || totalRoster === 0;
      if (filterMeta) {
        if (!rosterFiltersActive(q, sec, st)) {
          filterMeta.textContent = totalRoster + ' examinee' + (totalRoster === 1 ? '' : 's');
        } else {
          filterMeta.textContent = 'Showing ' + visible + ' of ' + totalRoster;
        }
      }
      if (filterClear) filterClear.hidden = !rosterFiltersActive(q, sec, st);
    }

    function clearRosterFilters() {
      if (filterQ) filterQ.value = '';
      if (!isCollegeMonitor && filterSection) filterSection.value = '';
      if (filterStatus) filterStatus.value = '';
      if (filterProgress) filterProgress.value = '';
      if (filterAway) filterAway.checked = false;
      if (filterTabs) filterTabs.checked = false;
      applyRosterFilters();
    }

    if (filterQ) filterQ.addEventListener('input', applyRosterFilters);
    if (filterSection) {
      filterSection.addEventListener('change', function () {
        if (isCollegeMonitor) {
          var form = document.getElementById('pemSectionForm');
          var v = filterSection.value;
          var hash = window.location.hash || '';
          if (form && !v) {
            var examIdInput = form.querySelector('input[name="exam_id"]');
            var examId = examIdInput ? examIdInput.value : '';
            window.location.assign('professor_examination_monitor?exam_type=regular&exam_id=' + encodeURIComponent(examId) + hash);
            return;
          }
          if (form) {
            var params = new URLSearchParams(new FormData(form));
            window.location.assign(form.getAttribute('action') + '?' + params.toString() + hash);
            return;
          }
        }
        applyRosterFilters();
      });
    }
    if (filterStatus) filterStatus.addEventListener('change', applyRosterFilters);
    if (filterProgress) filterProgress.addEventListener('change', applyRosterFilters);
    if (filterAway) filterAway.addEventListener('change', applyRosterFilters);
    if (filterTabs) filterTabs.addEventListener('change', applyRosterFilters);
    if (filterClear) filterClear.addEventListener('click', clearRosterFilters);

    function esc(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
    }
    function formatDtHtml(fmt) {
      if (!fmt) return '<span class="opacity-60">' + (isCollegeMonitor ? '—' : '-') + '</span>';
      var m = String(fmt).match(/^(.+,\s*\d{4})\s+(.+)$/);
      if (m) {
        return '<span class="pem-dt"><span class="pem-dt__d">' + esc(m[1]) + '</span><span class="pem-dt__t">' + esc(m[2]) + '</span></span>';
      }
      return '<span class="pem-dt">' + esc(fmt) + '</span>';
    }
    function resolvedRosterStatus(s) {
      var att = String(s.attempt_status || '').toLowerCase();
      if (examFinished && att !== 'in_progress' && att !== 'submitted' && att !== 'expired') {
        return 'absent';
      }
      return String(s.presence_status || 'not_started');
    }
    function progressBucketFromLive(s) {
      var att = String(s.attempt_status || '');
      var hasStart = !!(s.started_at || s.started_fmt);
      var started = (att === 'in_progress' || att === 'submitted' || att === 'expired' || hasStart);
      var tot = parseInt(s.total_questions, 10) || 0;
      var ans = parseInt(s.answered_count, 10) || 0;
      if (!started) return 'not_started';
      if (tot <= 0) return 'unavailable';
      if (ans <= 0) return 'started_zero';
      var pct = Math.round((ans / tot) * 100);
      return (pct >= 100) ? 'complete' : 'partial';
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
      var tot = parseInt(s.total_questions, 10) || 0;
      var st = String(s.attempt_status || '');
      if (isCollegeMonitor) {
        if (st === 'in_progress') {
          if (ans <= 0) return '<span class="pem-score-empty pem-score-empty--info">No answers yet</span>';
          var livePct = s.score_pct_answered != null ? Math.round(s.score_pct_answered) : Math.round(100 * cor / ans);
          return '<div class="pem-score"><span class="pem-score__n js-score-n">' + esc(cor + ' / ' + ans) + '</span><span class="pem-score__p js-score-p">' + esc(livePct + '%') + '</span></div>';
        }
        if (st === 'submitted' || st === 'expired') {
          if (tot <= 0) return '<span class="opacity-60 pem-score-empty">—</span>';
          var paperPct = s.score_pct_answered != null ? Math.round(s.score_pct_answered) : Math.round(100 * cor / tot);
          return '<div class="pem-score"><span class="pem-score__n js-score-n">' + esc(cor + ' / ' + tot) + '</span><span class="pem-score__p js-score-p">' + esc(paperPct + '%') + '</span></div>';
        }
        return '<span class="opacity-60 pem-score-empty">—</span>';
      }
      if (ans <= 0 && st !== 'submitted' && st !== 'expired') {
        return '<div class="pem-score"><span class="pem-score__n js-score-n">0 / 0</span></div>';
      }
      if (ans <= 0) return '<span class="opacity-60">-</span>';
      var pct = s.score_pct_answered != null ? Math.round(s.score_pct_answered) : Math.round(100 * cor / ans);
      return '<div class="pem-score"><span class="pem-score__n js-score-n">' + esc(cor + ' / ' + ans) + '</span><span class="pem-score__p js-score-p">' + esc(pct + '%') + '</span></div>';
    }
    function scoreText(s) {
      var ans = parseInt(s.answered_count, 10) || 0;
      var cor = parseInt(s.correct_count, 10) || 0;
      var tot = parseInt(s.total_questions, 10) || 0;
      var st = String(s.attempt_status || '');
      if (isCollegeMonitor) {
        if (st === 'in_progress') {
          if (ans <= 0) return 'No answers yet';
          var livePct = s.score_pct_answered != null ? Math.round(s.score_pct_answered) : Math.round(100 * cor / ans);
          return cor + ' / ' + ans + ' · ' + livePct + '%';
        }
        if (st === 'submitted' || st === 'expired') {
          if (tot <= 0) return '—';
          var paperPct = s.score_pct_answered != null ? Math.round(s.score_pct_answered) : Math.round(100 * cor / tot);
          return cor + ' / ' + tot + ' · ' + paperPct + '%';
        }
        return '—';
      }
      if (ans <= 0 && st !== 'submitted' && st !== 'expired') return '0 / 0';
      if (ans <= 0) return '-';
      var pct = s.score_pct_answered != null ? Math.round(s.score_pct_answered) : Math.round(100 * cor / ans);
      return cor + ' / ' + ans + ' · ' + pct + '%';
    }
    function cellText(tr, sel) {
      var el = tr ? tr.querySelector(sel) : null;
      if (!el) return '';
      return String(el.textContent || '').replace(/\s+/g, ' ').trim();
    }
    function metaText(tr, sel, prefix) {
      var raw = cellText(tr, sel);
      if (!raw) return '';
      if (prefix && raw.indexOf(prefix) === 0) {
        raw = raw.slice(prefix.length).trim();
      }
      return raw === '—' || raw === '-' ? '' : raw;
    }
    function hydrateFromRow(tr) {
      if (!tr) return null;
      var nameBtn = tr.querySelector('.js-open-monitor-detail');
      var visTxt = cellText(tr, '.js-tab-vis-cell').toLowerCase();
      var curTxt = cellText(tr, '.js-current-cell');
      var curN = curTxt.match(/(\d+)/);
      var progTxt = cellText(tr, '.js-progress-label');
      var progParts = progTxt.split('/');
      var seenTxt = cellText(tr, '.js-seen-cell');
      var startedTxt = cellText(tr, '.js-started-cell');
      var endTxt = cellText(tr, '.js-submitted-cell');
      var durTxt = cellText(tr, '.js-duration-cell');
      var remTxt = cellText(tr, '.js-time-cell');
      return {
        user_id: tr.getAttribute('data-user-id'),
        attempt_id: parseInt(tr.getAttribute('data-attempt-id') || '0', 10) || 0,
        full_name: nameBtn ? String(nameBtn.textContent || '').replace(/\s+/g, ' ').trim() : 'Examinee',
        section: (tr.getAttribute('data-section') || '') === '__none__' ? '' : (tr.getAttribute('data-section') || ''),
        presence_status: tr.getAttribute('data-status') || 'not_started',
        answered_count: progParts.length ? parseInt(progParts[0], 10) || 0 : 0,
        total_questions: progParts.length > 1 ? parseInt(progParts[1], 10) || 0 : 0,
        current_question: curN ? parseInt(curN[1], 10) : null,
        remaining_fmt: remTxt.replace(/^[-—]$/, ''),
        tab_hidden: visTxt.indexOf('hidden') !== -1 || visTxt.indexOf('away') !== -1,
        tab_switch_count: parseInt(cellText(tr, '.js-tab-count').replace(/\D+/g, ''), 10) || 0,
        last_seen_fmt: (seenTxt === '-' || seenTxt === '—') ? '' : seenTxt,
        started_fmt: (startedTxt === '-' || startedTxt === '—') ? '' : startedTxt,
        submitted_fmt: (endTxt === '-' || endTxt === '—') ? '' : endTxt,
        duration_fmt: (durTxt === '-' || durTxt === '—') ? '' : durTxt,
        client_instances_label: cellText(tr, '.js-meta-clients') || 'Instances unavailable',
        attempt_status: (function () {
          var ps = tr.getAttribute('data-status') || 'not_started';
          if (ps === 'submitted' || ps === 'expired') return ps;
          if (ps === 'not_started' || ps === 'absent') return 'not_started';
          return 'in_progress';
        })(),
        correct_count: 0,
        score_pct_answered: null
      };
    }
    function openTabModal(attemptId, name) {
      var modal = document.getElementById('pemTabEventsModal');
      var body = document.getElementById('pemTabEventsBody');
      var sub = document.getElementById('pemTabEventsSub');
      if (!modal || !body) return;
      if (sub) sub.textContent = (name || 'Student') + ' � tab leave / return timeline';
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
      if (btn && !btn.hidden) {
        var aidBtn = btn.getAttribute('data-attempt-id');
        if (aidBtn && aidBtn !== '0') {
          openTabModal(aidBtn, btn.getAttribute('data-name'));
        }
      }
      var det = e.target.closest('.js-open-monitor-detail');
      if (det) {
        var uid = det.getAttribute('data-user-id');
        var s = studentCache[uid];
        if (!s) {
          s = hydrateFromRow(document.querySelector('.js-monitor-row[data-user-id="' + uid + '"]'));
        }
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
            '<div><dt class="opacity-60">Last activity</dt><dd>' + esc(s.last_seen_fmt || '-') + '</dd></div>' +
            '<div><dt class="opacity-60">Started</dt><dd>' + esc(s.started_fmt || '-') + '</dd></div>' +
            '<div><dt class="opacity-60">Submitted / end</dt><dd>' + esc(s.submitted_fmt || '-') + '</dd></div>' +
            '<div><dt class="opacity-60">Duration</dt><dd>' + esc(s.duration_fmt || '-') + '</dd></div>' +
            '<div><dt class="opacity-60">Exam page instances</dt><dd>' + esc(s.client_instances_label || 'Not currently tracked') + '</dd></div>' +
            '</dl>' +
            '<p class="text-xs opacity-70 m-0 mb-2">Page instances and browser scopes are untrusted correlation IDs, not physical devices, and are not proof of cheating.</p>' +
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
              box.innerHTML = '<h4 class="font-bold m-0 mb-2 ' + (isCollegeMonitor ? 'pem-activity-title' : 'text-[#143D59]') + '">Activity Timeline</h4><ul class="m-0 pl-4 space-y-1">' +
                rows.map(function (ev) {
                  var extra = ev.detail ? (' <span class="opacity-70">(' + esc(ev.detail) + ')</span>') : '';
                  return '<li><strong>' + esc(ev.occurred_fmt || '') + '</strong> � ' + esc(ev.label || '') + extra + '</li>';
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
    var lastHtml = typeof WeakMap === 'function' ? new WeakMap() : null;
    function setTextIfChanged(el, value) {
      if (!el) return;
      var next = String(value);
      if (el.textContent !== next) el.textContent = next;
    }
    function setHtmlIfChanged(el, html) {
      if (!el) return;
      var hasNode = !!el.firstChild;
      if (lastHtml) {
        if (lastHtml.get(el) === html) {
          if (html === '') {
            if (!hasNode) return;
          } else if (hasNode) {
            return;
          }
        }
        el.innerHTML = html;
        lastHtml.set(el, html);
        return;
      }
      if (html === '') {
        if (!hasNode) return;
        el.innerHTML = '';
        return;
      }
      if (el.innerHTML !== html) el.innerHTML = html;
    }
    function setAttrIfChanged(el, name, value) {
      if (!el) return;
      var next = String(value);
      if (el.getAttribute(name) !== next) el.setAttribute(name, next);
    }
    function setHiddenIfChanged(el, hidden) {
      if (!el) return;
      if (el.hidden !== !!hidden) el.hidden = !!hidden;
    }
    function setClassIfChanged(el, name, on) {
      if (!el) return;
      if (el.classList.contains(name) === !!on) return;
      el.classList.toggle(name, !!on);
    }
    function tick() {
      fetch(liveUrl, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) throw new Error('poll');
          var elT = document.getElementById('kpiTakingCount');
          var elS = document.getElementById('kpiSubmittedCount');
          var elRoster = document.getElementById('kpiRosterTotal');
          var elAvg = document.getElementById('kpiAvgScore');
          if (elT && data.taking_count !== undefined && parseInt(elT.textContent, 10) !== data.taking_count) {
            location.reload();
            return;
          }
          if (elS && data.submitted_count !== undefined && parseInt(elS.textContent, 10) !== data.submitted_count) {
            location.reload();
            return;
          }
          if (elRoster && data.roster_total !== undefined) {
            setTextIfChanged(elRoster, String(data.roster_total));
          }
          if (elAvg && data.avg_score !== undefined) {
            setTextIfChanged(elAvg, data.avg_score !== null && data.avg_score !== ''
              ? (Number(data.avg_score).toFixed(1) + '%')
              : '-');
          }
          if ((data.auto_finalized || 0) > 0) {
            location.reload();
            return;
          }
          if (pollEl) pollEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
          if (kpiTab) setTextIfChanged(kpiTab, String(data.total_tab_leaves || 0));
          var trackingLive = !!data.client_tracking_live;
          var overlapN = trackingLive ? (parseInt(data.overlap_examinee_count, 10) || 0) : 0;
          ['pemClientOverlapBanner', 'pemClientOverlapNote'].forEach(function (id) {
            setHiddenIfChanged(document.getElementById(id), overlapN <= 0);
          });
          var alertBox = document.getElementById('pemConcurrentAlertsList');
          if (alertBox && Array.isArray(data.concurrent_alerts)) {
            var cal = data.concurrent_alerts;
            if (!cal.length) {
              setHtmlIfChanged(alertBox, '<p class="mb-0 text-sm opacity-70">No overlapping-instance alerts recorded for this exam.</p>');
            } else {
              setHtmlIfChanged(alertBox, cal.map(function (a) {
                return '<div class="sec-alert-row pem-concurrent-row"><span class="sec-alert-dot" aria-hidden="true"></span><div><strong>' +
                  esc(a.full_name || 'Examinee') +
                  '</strong> — overlapping exam instances detected — review recommended; this is not proof of cheating.' +
                  '<div class="text-xs opacity-80 mt-0.5">Observed page instances: ' + esc(String(a.instance_count || 0)) +
                  ' · browser scopes: ' + esc(String(a.browser_scope_count || 0)) +
                  ' · first: ' + esc(a.first_detected_fmt || '—') +
                  ' · last: ' + esc(a.last_detected_fmt || '—') +
                  ' · review: ' + esc(String(a.review_state || 'pending review').replace(/_/g, ' ')) +
                  '</div></div></div>';
              }).join(''));
            }
          }
          if (data.presence) {
            var elA = document.getElementById('kpiActiveCount');
            var elI = document.getElementById('kpiIdleCount');
            var elN = document.getElementById('kpiNotStartedCount');
            if (elA) setTextIfChanged(elA, String((data.presence.active || 0)));
            if (elI) setTextIfChanged(elI, String((data.presence.idle || 0) + (data.presence.disconnected || 0)));
            if (elN) setTextIfChanged(elN, String(data.presence.not_started || 0));
          }
          var list = data.students || [];
          var liveIds = {};
          list.forEach(function (s) { liveIds[String(s.user_id)] = true; });
          var rowNodes = document.querySelectorAll('.js-monitor-row');
          var rosterMismatch = list.length !== rowNodes.length;
          if (!rosterMismatch) {
            rowNodes.forEach(function (tr) {
              var uid = tr.getAttribute('data-user-id') || '';
              if (!liveIds[uid]) rosterMismatch = true;
            });
          }
          if (rosterMismatch) {
            location.reload();
            return;
          }
          list.forEach(function (s) {
            var uid = String(s.user_id);
            studentCache[uid] = s;
            var tr = document.querySelector('.js-monitor-row[data-user-id="' + uid + '"]');
            if (!tr) return;
            var displaySt = resolvedRosterStatus(s);
            setAttrIfChanged(tr, 'data-status', displaySt);
            setAttrIfChanged(tr, 'data-progress', progressBucketFromLive(s));
            if (s.section != null) {
              var secVal = String(s.section || '').trim();
              setAttrIfChanged(tr, 'data-section', secVal !== '' ? secVal : '__none__');
            }
            var n = parseInt(s.tab_switch_count, 10) || 0;
            var prev = prevByUser[uid] || 0;
            var statusCell = tr.querySelector('.js-status-cell');
            if (statusCell) setHtmlIfChanged(statusCell, presenceBadge(displaySt));
            var attSt = String(s.attempt_status || '');
            setAttrIfChanged(tr, 'data-tab-hidden', (attSt === 'in_progress' && s.tab_hidden) ? '1' : '0');
            setAttrIfChanged(tr, 'data-tab-count', String(n));
            var progBucket = tr.getAttribute('data-progress') || '';
            var showProgress = (progBucket === 'started_zero' || progBucket === 'partial' || progBucket === 'complete');
            var progStack = tr.querySelector('.pem-progress-stack');
            var progEmpty = tr.querySelector('.pem-progress-empty');
            setHiddenIfChanged(progStack, isCollegeMonitor ? !showProgress : false);
            setHiddenIfChanged(progEmpty, isCollegeMonitor ? showProgress : true);
            var progLabel = tr.querySelector('.js-progress-label');
            var progFill = tr.querySelector('.js-progress-fill');
            var progPct = tr.querySelector('.js-progress-pct');
            if (progLabel && s.answered_count != null) {
              setTextIfChanged(progLabel, (s.answered_count || 0) + ' / ' + (s.total_questions || 0));
            }
            if (progFill && s.total_questions) {
              var pctW = Math.round(((s.answered_count || 0) / s.total_questions) * 100);
              var pctWStr = pctW + '%';
              if (progFill.style.width !== pctWStr) progFill.style.width = pctWStr;
              if (progPct) setTextIfChanged(progPct, pctWStr);
            }
            var scoreCell = tr.querySelector('.js-score-cell');
            if (scoreCell) setHtmlIfChanged(scoreCell, scoreHtml(s));
            var sessMode = (attSt === 'in_progress') ? 'live' : ((attSt === 'submitted' || attSt === 'expired') ? 'closed' : 'idle');
            if (isCollegeMonitor) setAttrIfChanged(tr, 'data-session-mode', sessMode);
            var isLiveAtt = (attSt === 'in_progress');
            var curCell = tr.querySelector('.js-current-cell');
            if (curCell) {
              if (isCollegeMonitor && !isLiveAtt) {
                setHtmlIfChanged(curCell, '');
              } else {
                setHtmlIfChanged(curCell, s.current_question
                  ? ('Q' + s.current_question)
                  : (isCollegeMonitor ? '' : '<span class="opacity-60">-</span>'));
              }
            }
            var timeCell = tr.querySelector('.js-time-cell');
            if (timeCell) {
              if (isCollegeMonitor && !isLiveAtt) {
                setHtmlIfChanged(timeCell, '');
              } else {
                setHtmlIfChanged(timeCell, s.remaining_fmt ? esc(s.remaining_fmt) : (isCollegeMonitor ? '' : '<span class="opacity-60">-</span>'));
              }
              var timeRow = timeCell.closest('.pem-activity__row');
              if (timeRow) {
                setClassIfChanged(timeRow, 'is-empty', !(s.remaining_fmt && !(isCollegeMonitor && !isLiveAtt)));
              }
            }
            var visCell = tr.querySelector('.js-tab-vis-cell');
            if (visCell) {
              if (attSt === 'in_progress') {
                setHtmlIfChanged(visCell, s.tab_hidden
                  ? '<span class="admin-badge admin-badge--warning">Away</span>'
                  : '<span class="admin-badge admin-badge--success">Visible</span>');
              } else if (isCollegeMonitor) {
                setHtmlIfChanged(visCell, '');
              }
            }
            var liveEmpty = tr.querySelector('.pem-live-empty');
            if (liveEmpty) {
              var hasLiveBits = !!(
                (curCell && String(curCell.textContent || '').trim()) ||
                (timeCell && String(timeCell.textContent || '').trim()) ||
                (visCell && String(visCell.textContent || '').trim())
              );
              setHiddenIfChanged(liveEmpty, hasLiveBits);
            }
            var aux = tr.querySelector('.pem-activity__aux');
            if (aux) setClassIfChanged(aux, 'is-empty', attSt !== 'in_progress');
            var aid = parseInt(s.attempt_id, 10) || 0;
            if (aid > 0) setAttrIfChanged(tr, 'data-attempt-id', String(aid));
            var tabBtn = tr.querySelector('.js-open-tab-events');
            var tabEmpty = tr.querySelector('.pem-tab-empty');
            if (isCollegeMonitor && tabBtn) {
              if (aid > 0) {
                setAttrIfChanged(tabBtn, 'data-attempt-id', String(aid));
                setHiddenIfChanged(tabBtn, false);
                setHiddenIfChanged(tabEmpty, true);
              }
            }
            var countCell = tr.querySelector('.js-tab-count');
            if (countCell) setTextIfChanged(countCell, String(n));
            var seenCell = tr.querySelector('.js-seen-cell');
            if (seenCell) setHtmlIfChanged(seenCell, formatDtHtml(s.last_seen_fmt || ''));
            var startedCell = tr.querySelector('.js-started-cell');
            if (startedCell) setHtmlIfChanged(startedCell, formatDtHtml(s.started_fmt || ''));
            var endCell = tr.querySelector('.js-submitted-cell');
            if (endCell) setHtmlIfChanged(endCell, formatDtHtml(s.submitted_fmt || ''));
            var durCell = tr.querySelector('.js-duration-cell');
            if (durCell) {
              setHtmlIfChanged(durCell, s.duration_fmt
                ? esc(s.duration_fmt)
                : '<span class="opacity-60">' + (isCollegeMonitor ? '—' : '-') + '</span>');
            }
            var metaCli = tr.querySelector('.js-meta-clients');
            if (metaCli) {
              var instN = parseInt(s.active_instances, 10) || 0;
              if (isCollegeMonitor) {
                if (attSt === 'in_progress' && instN >= 2) {
                  setTextIfChanged(metaCli, instN + ' pages');
                  setClassIfChanged(metaCli, 'is-overlap', true);
                } else if (attSt === 'in_progress' && trackingLive) {
                  setTextIfChanged(metaCli, s.client_instances_label || '');
                  setClassIfChanged(metaCli, 'is-overlap', false);
                } else {
                  setTextIfChanged(metaCli, '');
                  setClassIfChanged(metaCli, 'is-overlap', false);
                }
              } else if (trackingLive) {
                setTextIfChanged(metaCli, s.client_instances_label || 'No active exam page instances');
              } else {
                setTextIfChanged(metaCli, 'Instances unavailable');
              }
            }
            if (n > prev) {
              tr.classList.remove('tab-flash');
              void tr.offsetWidth;
              tr.classList.add('tab-flash');
            }
            prevByUser[uid] = n;
          });
          applyRosterFilters();
          var alerts = list.filter(function (x) { return (parseInt(x.tab_switch_count, 10) || 0) > 0; });
          var leaveN = 0;
          alerts.forEach(function (a) { leaveN += parseInt(a.tab_switch_count, 10) || 0; });
          var sumEl = document.getElementById('examSecuritySummary');
          if (sumEl) {
            setTextIfChanged(sumEl, alerts.length === 0
              ? 'No tab leaves on the current roster. This is a live count, not the history below.'
              : (alerts.length + ' examinee' + (alerts.length === 1 ? '' : 's') + ' · ' + leaveN + ' tab leave' + (leaveN === 1 ? '' : 's') + ' (roster summary, not the event log).'));
          }
          var detailsEl = document.getElementById('examSecurityAlerts');
          if (detailsEl && String(detailsEl.tagName || '').toLowerCase() === 'details') {
            setHiddenIfChanged(detailsEl, alerts.length === 0);
          }
          if (feedEl) {
            if (alerts.length === 0) {
              setHtmlIfChanged(feedEl, '<p class="mt-1 mb-0 text-sm ' + (isCollegeMonitor ? 'pem-sec-empty' : 'text-amber-900/80') + '">No tab leaves recorded yet. Counts update every 12 seconds while this page is open.</p>');
            } else {
              setHtmlIfChanged(feedEl, alerts.map(function (a) {
                var n = parseInt(a.tab_switch_count, 10) || 0;
                var lt = a.last_tab_switch_fmt || '-';
                return '<div class="sec-alert-row"><span class="sec-alert-dot" aria-hidden="true"></span><div><strong>' +
                  esc(a.full_name || '') + '</strong> left the exam tab <strong>' + n + '</strong> time(s). Last: ' + esc(lt) + '</div></div>';
              }).join(''));
            }
          }
        })
        .catch(function () {
          if (pollEl) setTextIfChanged(pollEl, 'Update failed - retrying...');
        });
    }
    applyRosterFilters();
    tick();
    setInterval(tick, 11000);
  })();
  </script>
</body>
</html>
