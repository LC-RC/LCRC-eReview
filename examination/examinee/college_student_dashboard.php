<?php
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
ereview_require_college_examination_portal();
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/examination_eligibility.php';

$pageTitle = 'College Portal';
$uid = getCurrentUserId();
$csrf = generateCSRFToken();

$now = examination_schedule_now_sql();
$uidDash = (int)$uid;
if ($uidDash > 0) {
    college_exam_finalize_expired_in_progress($conn, 0, $uidDash, 0);
    diagnostic_exam_finalize_expired_in_progress($conn, 0, $uidDash);
}

$diagnosticCards = [];
$assignedExams = examination_student_load_assigned_exams($conn, $uidDash, $now);
$activeExams = 0;
$completedExams = 0;
$dueSoonExams = 0;
$upcoming = [];
$soonTs = strtotime('+3 days', strtotime($now));

foreach ($assignedExams as $examItem) {
    $bucket = (string)($examItem['_bucket'] ?? '');
    $st = (string)($examItem['attempt_status'] ?? '');
    if ($bucket === 'open' || ($st === 'in_progress')) {
        $activeExams++;
    }
    if ($bucket === 'finished' || $st === 'submitted' || ($st === 'expired' && !empty($examItem['submitted_at']))) {
        $completedExams++;
    }
    $deadline = trim((string)($examItem['deadline'] ?? ''));
    if ($deadline !== '' && $deadline > $now) {
        $upcoming[] = [
            'title' => (string)($examItem['title'] ?? ''),
            'deadline' => $deadline,
            'exam_type' => (string)($examItem['exam_type'] ?? 'regular'),
        ];
        $dTs = strtotime($deadline);
        if ($dTs !== false && $dTs <= $soonTs) {
            $dueSoonExams++;
        }
    }
}

usort($upcoming, static function ($a, $b) {
    return strcmp((string)($a['deadline'] ?? ''), (string)($b['deadline'] ?? ''));
});
$upcoming = array_slice($upcoming, 0, 5);

$pendingUploads = 0;
$uploadDue = [];
$dueSoonUploads = 0;
$openUploadTasksTotal = 0;
$uploadsModuleEnabled = false;
require_once dirname(__DIR__, 2) . '/includes/college_student_uploads.php';
$uploadsModuleEnabled = college_student_uploads_is_enabled($conn);
if ($uploadsModuleEnabled) {
    require_once dirname(__DIR__) . '/includes/college_upload_helpers.php';
    $eligibleUploadTasks = college_upload_list_for_student($conn, $uidDash);
    foreach ($eligibleUploadTasks as $taskRow) {
        $deadline = (string)($taskRow['deadline'] ?? '');
        if ($deadline === '' || $deadline < $now) {
            continue;
        }
        $openUploadTasksTotal++;
        $taskId = (int)($taskRow['task_id'] ?? 0);
        $hasSubmission = false;
        if ($taskId > 0) {
            $sq = @mysqli_query(
                $conn,
                'SELECT submission_id FROM college_submissions WHERE task_id=' . $taskId . ' AND user_id=' . $uidDash . ' LIMIT 1'
            );
            if ($sq && mysqli_fetch_assoc($sq)) {
                $hasSubmission = true;
            }
            if ($sq) {
                mysqli_free_result($sq);
            }
        }
        if ($hasSubmission) {
            continue;
        }
        $pendingUploads++;
        $dTs = strtotime($deadline);
        if ($dTs !== false && $dTs <= $soonTs) {
            $dueSoonUploads++;
        }
        if (count($uploadDue) < 5) {
            $uploadDue[] = [
                'task_id' => $taskId,
                'title' => (string)($taskRow['title'] ?? ''),
                'deadline' => $deadline,
            ];
        }
    }
}

$examEngagementPct = ($completedExams + $activeExams) > 0 ? (int)round(($completedExams / ($completedExams + $activeExams)) * 100) : 0;
$uploadCompletionPct = $openUploadTasksTotal > 0 ? (int)round((($openUploadTasksTotal - $pendingUploads) / $openUploadTasksTotal) * 100) : 0;

$weeklyActivity = [];
$weeklyLabels = [];
for ($i = 7; $i >= 0; $i--) {
    $weekTs = strtotime("monday this week -{$i} week");
    $weekKey = date('o-W', $weekTs);
    $weeklyActivity[$weekKey] = 0;
    $weeklyLabels[$weekKey] = 'Wk ' . date('M j', $weekTs);
}

$r7 = @mysqli_query($conn, "
  SELECT DATE_FORMAT(submitted_at, '%x-%v') AS yw, COUNT(*) AS c
  FROM college_exam_attempts
  WHERE user_id=" . (int)$uid . "
    AND status='submitted'
    AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
  GROUP BY yw
");
if ($r7) {
    while ($row = mysqli_fetch_assoc($r7)) {
        $key = (string)($row['yw'] ?? '');
        if (isset($weeklyActivity[$key])) $weeklyActivity[$key] += (int)($row['c'] ?? 0);
    }
    mysqli_free_result($r7);
}

$r8 = null;
if ($uploadsModuleEnabled) {
    $r8 = @mysqli_query($conn, "
      SELECT DATE_FORMAT(submitted_at, '%x-%v') AS yw, COUNT(*) AS c
      FROM college_submissions
      WHERE user_id=" . (int)$uid . "
        AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
      GROUP BY yw
    ");
}
if ($r8) {
    while ($row = mysqli_fetch_assoc($r8)) {
        $key = (string)($row['yw'] ?? '');
        if (isset($weeklyActivity[$key])) $weeklyActivity[$key] += (int)($row['c'] ?? 0);
    }
    mysqli_free_result($r8);
}

$featuredExam = null;
foreach ($assignedExams as $examPick) {
    if ((string)($examPick['_action_mode'] ?? '') === 'continue') {
        $featuredExam = $examPick;
        break;
    }
}
if ($featuredExam === null) {
    foreach ($assignedExams as $examPick) {
        $pickBucket = (string)($examPick['_bucket'] ?? '');
        $pickAction = (string)($examPick['_action_mode'] ?? '');
        if (($pickBucket === 'open' || $pickAction === 'start') && in_array($pickAction, ['start', 'continue'], true)) {
            $featuredExam = $examPick;
            break;
        }
    }
}

$recentExams = [];
foreach ($assignedExams as $examPick) {
    $pickBucket = (string)($examPick['_bucket'] ?? '');
    $pickSt = (string)($examPick['attempt_status'] ?? '');
    if ($pickBucket === 'finished' || $pickSt === 'submitted' || ($pickSt === 'expired' && !empty($examPick['submitted_at']))) {
        $recentExams[] = $examPick;
    }
}
usort($recentExams, static function ($a, $b) {
    return strcmp((string)($b['submitted_at'] ?? ''), (string)($a['submitted_at'] ?? ''));
});
$recentExams = array_slice($recentExams, 0, 6);
$hasWeeklyActivity = array_sum($weeklyActivity) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_app.php'; ?>
</head>
<body class="font-sans antialiased<?php echo !empty($examinationStudentBodyClass) ? ' ' . h($examinationStudentBodyClass) : ''; ?>">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="cp-page-shell cp-content cp-content--dashboard ereview-shell-no-fade pt-2">
    <?php
      $hour = (int)date('G');
      $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
      $firstName = trim(explode(' ', trim((string)($_SESSION['full_name'] ?? 'Student')))[0] ?? 'Student');
    ?>
    <header class="cp-welcome-compact cp-welcome-surface cp-anim delay-1" aria-label="Welcome">
      <h1 class="cp-welcome-compact__title"><?php echo h($greeting); ?>, <?php echo h($firstName); ?></h1>
      <p class="cp-welcome-compact__sub">Stay on top of your examinations and academic progress.</p>
    </header>

    <section class="cp-dash-panel cp-anim delay-2" aria-labelledby="dash-your-exams">
      <div class="cp-dash-panel__head">
        <h2 class="cp-dash-panel__title" id="dash-your-exams">Your examinations</h2>
        <a href="college_exams" class="cp-text-link">View all</a>
      </div>
      <div class="cp-dash-panel__body">
      <?php if ($featuredExam !== null): ?>
        <?php
          $cpExam = $featuredExam;
          $cpExamFeatured = true;
          $cpExamLayout = 'featured';
          require dirname(__DIR__, 2) . '/includes/components/college_portal_exam_card.php';
        ?>
      <?php else: ?>
        <div class="cp-dash-empty-state">
          <div class="cp-dash-empty-state__icon" aria-hidden="true"><i class="bi bi-journal-check"></i></div>
          <p class="cp-dash-empty-state__text">No examinations need your attention right now. Check back when an exam opens or you have one in progress.</p>
        </div>
      <?php endif; ?>
      </div>
    </section>

    <?php if (!empty($recentExams)): ?>
    <section class="cp-dash-panel cp-anim delay-3" aria-labelledby="dash-recent-exams">
      <div class="cp-dash-panel__head">
        <h2 class="cp-dash-panel__title" id="dash-recent-exams">Recent activity</h2>
      </div>
      <div class="cp-dash-panel__body cp-dash-panel__body--flush">
      <div class="cp-data-table-wrap">
        <table class="cp-data-table">
          <thead>
            <tr>
              <th scope="col">Examination</th>
              <th scope="col">Type</th>
              <th scope="col">Status</th>
              <th scope="col">Score</th>
              <th scope="col">Submitted</th>
              <th scope="col"><span class="sr-only">Action</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentExams as $recent):
              $rtype = (string)($recent['exam_type'] ?? 'regular');
              $rtypeLabel = examination_exam_type_label($rtype);
              $rStatus = (string)($recent['_status_label'] ?? 'Finished');
              $rScore = '-';
              $rst = (string)($recent['attempt_status'] ?? '');
              if ($rst === 'submitted' || ($rst === 'expired' && !empty($recent['submitted_at']))) {
                  $rScore = college_exam_format_score_total_line_traditional(
                      isset($recent['correct_count']) ? (int)$recent['correct_count'] : null,
                      isset($recent['total_count']) ? (int)$recent['total_count'] : null,
                      (int)($recent['_q_count'] ?? 0)
                  );
              }
              $rSubmitted = !empty($recent['submitted_at']) ? date('M j, Y g:i A', strtotime((string)$recent['submitted_at'])) : '—';
              $rAction = (string)($recent['_action_url'] ?? '');
              $rActionLabel = (string)($recent['_action_label'] ?? 'View');
            ?>
            <tr>
              <td class="cp-data-table__primary"><?php echo h((string)($recent['title'] ?? 'Untitled')); ?></td>
              <td><span class="type-pill <?php echo $rtype === 'diagnostic' ? 'type-diagnostic' : 'type-regular'; ?>"><?php echo h($rtypeLabel); ?></span></td>
              <td><span class="status-pill status-done"><i class="bi bi-check-circle"></i> <?php echo h($rStatus); ?></span></td>
              <td class="cp-data-table__num"><?php echo h($rScore); ?></td>
              <td class="cp-data-table__muted"><?php echo h($rSubmitted); ?></td>
              <td class="cp-data-table__action">
                <?php if ($rAction !== ''): ?>
                  <a href="<?php echo h($rAction); ?>" class="cp-text-link"><?php echo h($rActionLabel); ?></a>
                <?php else: ?>
                  <span class="cp-data-table__muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($hasWeeklyActivity): ?>
    <section class="cp-dash-panel cp-anim delay-3" aria-labelledby="dash-activity">
      <div class="cp-dash-panel__head">
        <h2 class="cp-dash-panel__title" id="dash-activity">Activity trend</h2>
      </div>
      <div class="cp-dash-panel__body">
      <p class="cp-dash-panel__desc"><?php echo $uploadsModuleEnabled
          ? 'Exam submissions and uploads over the last 8 weeks · Engagement ' . (int)$examEngagementPct . '% · Upload completion ' . (int)$uploadCompletionPct . '%'
          : 'Exam submissions over the last 8 weeks · Engagement ' . (int)$examEngagementPct . '%'; ?></p>
      <div class="cp-chart-wrap cp-chart-wrap--compact">
        <canvas id="collegeActivityChart" aria-label="Weekly activity trend"></canvas>
      </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($upcoming) || ($uploadsModuleEnabled && !empty($uploadDue))): ?>
    <section class="cp-dash-panel cp-anim delay-4" aria-labelledby="dash-deadlines">
      <div class="cp-dash-panel__head">
        <h2 class="cp-dash-panel__title" id="dash-deadlines">Upcoming deadlines</h2>
      </div>
      <div class="cp-dash-panel__body">
      <div class="cp-split-panels">
        <div class="cp-split-panels__col">
          <h3 class="cp-split-panels__label"><i class="bi bi-alarm"></i> Exam deadlines</h3>
          <?php if (empty($upcoming)): ?>
            <p class="cp-dash-empty cp-dash-empty--inline">No upcoming exam deadlines.</p>
          <?php else: ?>
            <ul class="cp-timeline-list">
              <?php foreach ($upcoming as $u):
                $typeLabel = examination_exam_type_label((string)($u['exam_type'] ?? 'regular'));
              ?>
              <li class="cp-timeline-list__item">
                <div class="cp-timeline-list__main">
                  <span class="cp-timeline-list__title"><?php echo h($u['title']); ?></span>
                  <span class="type-pill <?php echo ($u['exam_type'] ?? '') === 'diagnostic' ? 'type-diagnostic' : 'type-regular'; ?>"><?php echo h($typeLabel); ?></span>
                </div>
                <time class="cp-timeline-list__when"><?php echo h(date('M j, g:i A', strtotime($u['deadline']))); ?></time>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <?php if ($uploadsModuleEnabled): ?>
        <div class="cp-split-panels__col">
          <h3 class="cp-split-panels__label"><i class="bi bi-upload"></i> Upload due</h3>
          <?php if (empty($uploadDue)): ?>
            <p class="cp-dash-empty cp-dash-empty--inline">No pending uploads.</p>
          <?php else: ?>
            <ul class="cp-timeline-list">
              <?php foreach ($uploadDue as $u): ?>
              <li class="cp-timeline-list__item">
                <div class="cp-timeline-list__main">
                  <span class="cp-timeline-list__title"><?php echo h($u['title']); ?></span>
                </div>
                <time class="cp-timeline-list__when"><?php echo h(date('M j, g:i A', strtotime($u['deadline']))); ?></time>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      </div>
    </section>
    <?php endif; ?>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
  (function () {
    if (typeof Chart === 'undefined') return;
    var canvas = document.getElementById('collegeActivityChart');
    if (!canvas) return;
    new Chart(canvas, {
      type: 'line',
      data: {
        labels: <?php echo json_encode(array_values($weeklyLabels)); ?>,
        datasets: [{
          label: 'Activity',
          data: <?php echo json_encode(array_values($weeklyActivity)); ?>,
          borderColor: '#1665A0',
          backgroundColor: 'rgba(22, 101, 160, 0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: 3.6,
          pointHoverRadius: 5.6,
          pointBackgroundColor: '#1665A0'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0, color: '#64748b' },
            grid: { color: 'rgba(22, 101, 160, 0.12)' }
          },
          x: {
            ticks: { color: '#64748b' },
            grid: { display: false }
          }
        }
      }
    });
  })();
  </script>
</main>
</body>
</html>
