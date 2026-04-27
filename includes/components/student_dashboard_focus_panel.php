<?php
/**
 * Inner content for student dashboard Focus panel (included twice: mobile <details> + desktop <aside>).
 * Expects variables from student_dashboard.php scope.
 */
$fpQuizInProgress = (int)($quizInProgressCount ?? 0);
$fpPreSubjects = (int)($preboardsSubjectsCount ?? 0);
$fpPreSets = (int)($preboardsSetsCount ?? 0);
$fpQuizSub = (int)($quizSubmittedCount ?? 0);
$fpPreSub = (int)($preboardsSubmittedCount ?? 0);
$fpQzDistinct = (int)($quizzesSubmittedDistinct ?? 0);
$fpPreDistinct = (int)($preboardsSetsSubmittedDistinct ?? 0);
$fpAccessDays = isset($focusAccessDaysLeft) ? (int)$focusAccessDaysLeft : null;
$fpAccessEndTs = isset($focusAccessEndTs) ? (int)$focusAccessEndTs : 0;
$fpAccessPct = isset($focusAccessPctUsed) ? (int)$focusAccessPctUsed : null;
?>
<div class="p-4 space-y-3">
  <?php if (!empty($nextExpireQuizRow)): ?>
  <div class="focus-deadline-banner" role="status">
    <p class="focus-deadline-label m-0"><i class="bi bi-alarm"></i> Next quiz timer</p>
    <p class="focus-deadline-title m-0 mt-1"><?php echo h((string)($nextExpireQuizRow['quiz_title'] ?? 'Quiz')); ?></p>
    <p class="focus-deadline-sub m-0 mt-0.5"><?php echo h((string)($nextExpireQuizRow['subject_name'] ?? '')); ?> · <?php echo h((string)($nextExpireQuizTimeNote ?? '')); ?></p>
    <a href="<?php echo h((string)($nextExpireQuizRow['href'] ?? '#')); ?>" class="focus-deadline-btn"><i class="bi bi-play-fill"></i> Resume now</a>
  </div>
  <?php endif; ?>

  <?php if ($fpAccessDays !== null && $fpAccessDays > 0 && $fpAccessEndTs > 0): ?>
  <div class="focus-access-inline">
    <div class="flex items-start justify-between gap-2">
      <div>
        <p class="focus-access-inline-label m-0">Enrollment access</p>
        <p class="focus-access-inline-copy m-0 mt-1">Ends <?php echo h(date('M j, Y', $fpAccessEndTs)); ?> · <strong><?php echo (int)$fpAccessDays; ?></strong> day<?php echo $fpAccessDays === 1 ? '' : 's'; ?> left</p>
      </div>
      <?php if ($fpAccessPct !== null): ?>
        <span class="focus-access-inline-pct"><?php echo (int)$fpAccessPct; ?>%</span>
      <?php endif; ?>
    </div>
    <?php if ($fpAccessPct !== null): ?>
    <div class="focus-access-inline-track mt-2"><div class="focus-access-inline-fill" style="width:<?php echo (int)max(0, min(100, $fpAccessPct)); ?>%"></div></div>
    <?php endif; ?>
    <a href="student_access_ics.php" class="focus-access-inline-ics" download><i class="bi bi-calendar-plus"></i> Add end date to calendar</a>
  </div>
  <?php endif; ?>

  <div class="focus-card is-primary">
    <div class="flex items-start justify-between gap-2">
      <div>
        <p class="focus-title">In-progress quizzes</p>
        <p class="focus-copy"><?php echo $fpQuizInProgress; ?> active attempt<?php echo $fpQuizInProgress === 1 ? '' : 's'; ?> — resume where you left off.</p>
      </div>
      <?php if ($fpQuizInProgress > 0): ?>
        <span class="focus-badge" aria-hidden="true"><?php echo $fpQuizInProgress; ?></span>
      <?php endif; ?>
    </div>
    <?php if (!empty($focusQuizRows)): ?>
      <ul class="focus-action-list">
        <?php foreach ($focusQuizRows as $row):
            $qid = (int)($row['quiz_id'] ?? 0);
            $sid = (int)($row['subject_id'] ?? 0);
            $expiresRaw = $row['expires_at'] ?? null;
            $timeNote = '';
            if ($expiresRaw) {
                $ex = strtotime((string)$expiresRaw);
                if ($ex !== false) {
                    $remain = $ex - time();
                    if ($remain <= 0) {
                        $timeNote = 'Timer may expire soon';
                    } elseif ($remain < 3600) {
                        $timeNote = (int)ceil($remain / 60) . ' min left';
                    } else {
                        $timeNote = (int)floor($remain / 3600) . 'h left';
                    }
                }
            }
            $href = 'student_take_quiz.php?quiz_id=' . $qid . '&subject_id=' . $sid;
        ?>
        <li class="focus-action-item">
          <div class="focus-action-meta">
            <span class="focus-action-title"><?php echo h((string)($row['quiz_title'] ?? 'Quiz')); ?></span>
            <span class="focus-action-sub"><?php echo h((string)($row['subject_name'] ?? '')); ?><?php echo $timeNote !== '' ? ' · ' . h($timeNote) : ''; ?></span>
          </div>
          <a href="<?php echo h($href); ?>" class="focus-resume-btn"><i class="bi bi-play-fill"></i> Resume</a>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="focus-empty mt-2">
        <p class="focus-empty-copy m-0">No quizzes in progress. Start one from any subject.</p>
        <a href="student_subjects.php" class="focus-empty-link"><i class="bi bi-journal-bookmark"></i> Go to subjects</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="focus-card focus-card--soft">
    <p class="focus-title">Preboards library</p>
    <p class="focus-copy"><?php echo $fpPreSubjects; ?> subject<?php echo $fpPreSubjects === 1 ? '' : 's'; ?> · <?php echo $fpPreSets; ?> practice set<?php echo $fpPreSets === 1 ? '' : 's'; ?> available.</p>
    <?php if (!empty($focusPreboardRows)): ?>
      <p class="focus-subheading m-0">Continue in progress</p>
      <ul class="focus-action-list focus-action-list--compact">
        <?php foreach ($focusPreboardRows as $row):
            $pset = (int)($row['preboards_set_id'] ?? 0);
            $psub = (int)($row['preboards_subject_id'] ?? 0);
            $phref = 'student_take_preboard.php?preboards_set_id=' . $pset . '&preboards_subject_id=' . $psub;
        ?>
        <li class="focus-action-item">
          <div class="focus-action-meta">
            <span class="focus-action-title"><?php echo h((string)($row['set_title'] ?? 'Preboard')); ?></span>
            <span class="focus-action-sub"><?php echo h((string)($row['subject_name'] ?? '')); ?></span>
          </div>
          <a href="<?php echo h($phref); ?>" class="focus-resume-btn focus-resume-btn--amber"><i class="bi bi-play-fill"></i> Resume</a>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <a href="student_preboards.php" class="focus-library-btn"><i class="bi bi-grid-3x3-gap"></i> Browse all preboards</a>
  </div>

  <div class="focus-card focus-card--soft">
    <p class="focus-title">Completed assessments</p>
    <p class="focus-copy m-0"><?php echo $fpQuizSub; ?> quiz submission<?php echo $fpQuizSub === 1 ? '' : 's'; ?> · <?php echo $fpPreSub; ?> preboard submission<?php echo $fpPreSub === 1 ? '' : 's'; ?>.</p>
    <div class="focus-stat-grid">
      <div class="focus-stat-pill" title="Distinct quizzes submitted at least once">
        <span class="focus-stat-num"><?php echo $fpQzDistinct; ?></span>
        <span class="focus-stat-lbl">Quizzes done</span>
      </div>
      <div class="focus-stat-pill" title="Distinct preboard sets completed">
        <span class="focus-stat-num"><?php echo $fpPreDistinct; ?></span>
        <span class="focus-stat-lbl">Sets done</span>
      </div>
    </div>
    <div class="focus-detail-actions">
      <?php if ($fpQuizSub > 0): ?>
        <a href="student_quiz_history.php" class="focus-inline-link"><i class="bi bi-clock-history"></i> Quiz history</a>
      <?php endif; ?>
      <?php if ($fpPreSub > 0): ?>
        <a href="student_preboards.php" class="focus-inline-link"><i class="bi bi-clipboard-data"></i> Review preboards</a>
      <?php endif; ?>
    </div>
  </div>
</div>
