<?php
/**
 * Renders submitted-exam review HTML (summary + optional locked gate + optional question cards).
 * Expects: $exam, $attempt, $now, $answersMap, $questions, $profName, $timeUsedSec
 * Optional: $studentDisplayName
 */
if (!isset($exam, $attempt, $now, $answersMap, $questions, $profName)) {
    return;
}

$reviewSheetOpen = college_exam_review_sheet_is_open($exam, $now);
$reviewAccessSt = college_exam_review_access_status($exam, $now);
$correctC = (int)($attempt['correct_count'] ?? 0);
$totalC = (int)($attempt['total_count'] ?? 0);
if ($totalC <= 0) {
    $totalC = count($questions);
}
$incorrectC = max(0, $totalC - $correctC);
$rawPct = $totalC > 0 ? round((100.0 * $correctC) / $totalC, 1) : 0.0;
$scoreF = $totalC > 0
    ? college_exam_compute_score_percentage($correctC, $totalC)
    : (is_numeric($attempt['score'] ?? null) ? (float)$attempt['score'] : 0.0);
$markPass = college_exam_is_pass_half_correct(
    isset($attempt['correct_count']) ? (int)$attempt['correct_count'] : null,
    $totalC > 0 ? $totalC : null,
    count($questions)
);

$opensLabel = (empty($exam['available_from']) || trim((string)$exam['available_from']) === '' || preg_match('/^0000-00-00/', (string)$exam['available_from']))
    ? 'Immediate'
    : college_exam_format_student_result_datetime($exam['available_from']);
$closesLabel = (empty($exam['deadline']) || trim((string)$exam['deadline']) === '' || preg_match('/^0000-00-00/', (string)$exam['deadline']))
    ? 'No closing time'
    : college_exam_format_student_result_datetime($exam['deadline']);
$durationLabel = max(0, (int)($exam['time_limit_seconds'] ?? 0)) > 0
    ? college_exam_human_duration((int)$exam['time_limit_seconds'])
    : 'No fixed timer';
$studentName = trim((string)($studentDisplayName ?? ''));
if ($studentName === '') {
    $studentName = 'You';
}

if ($reviewAccessSt === 'no_schedule') {
    $reviewLockNote = '<strong>' . h($profName) . '</strong> has not scheduled review access yet. You can review your summary below; the full question sheet will unlock when your professor sets a date.';
} elseif ($reviewAccessSt === 'pending') {
    $reviewLockNote = 'The full review sheet is scheduled to open on <strong>' . h(college_exam_format_student_result_datetime($exam['review_sheet_available_from'] ?? '')) . '</strong>.';
} elseif ($reviewAccessSt === 'ended') {
    $reviewLockNote = 'The scheduled review period has ended. Only your results summary remains available.';
} else {
    $reviewLockNote = '';
}
?>
<section class="cer-results" aria-label="Exam results">
  <a href="college_exams" class="cer-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to exams</a>

  <header class="cer-results__header">
    <p class="cer-results__eyebrow">Exam results</p>
    <h2 class="cer-results__title"><?php echo h((string)($exam['title'] ?? 'Examination')); ?></h2>
    <p class="cer-results__meta"><span class="cp-type cp-type--regular">Regular examination</span></p>
  </header>

  <div class="cer-summary-grid" role="group" aria-label="Score summary">
    <div class="cer-summary-card">
      <div class="cer-summary-k">Score</div>
      <div class="cer-summary-v cer-summary-v--score"><?php echo h(number_format($scoreF, 0)); ?>%</div>
      <div class="cer-summary-sub"><?php echo (int)$correctC; ?> / <?php echo (int)$totalC; ?> correct</div>
      <div class="cer-summary-note">Curved score (CEO). Raw accuracy <?php echo h(number_format($rawPct, 1)); ?>%.</div>
    </div>
    <div class="cer-summary-card">
      <div class="cer-summary-k">Result</div>
      <div class="cer-summary-v">
        <?php if ($markPass): ?>
          <span class="cer-mark cer-mark--pass"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Passed</span>
        <?php else: ?>
          <span class="cer-mark cer-mark--fail"><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Failed</span>
        <?php endif; ?>
      </div>
      <div class="cer-summary-sub">Pass requires at least half correct</div>
    </div>
    <div class="cer-summary-card">
      <div class="cer-summary-k">Time used</div>
      <div class="cer-summary-v"><?php echo $timeUsedSec !== null ? h(gmdate('H:i:s', (int)$timeUsedSec)) : '—'; ?></div>
      <div class="cer-summary-sub">Duration allowance: <?php echo h($durationLabel); ?></div>
    </div>
  </div>

  <div class="cer-perf">
    <h3 class="cer-perf__title">Performance</h3>
    <div class="cer-perf__grid">
      <div class="cer-perf__item cer-perf__item--ok">
        <span class="cer-perf__n"><?php echo (int)$correctC; ?></span>
        <span class="cer-perf__l">Correct</span>
      </div>
      <div class="cer-perf__item cer-perf__item--bad">
        <span class="cer-perf__n"><?php echo (int)$incorrectC; ?></span>
        <span class="cer-perf__l">Incorrect / unanswered</span>
      </div>
      <div class="cer-perf__item">
        <span class="cer-perf__n"><?php echo h(number_format($rawPct, 1)); ?>%</span>
        <span class="cer-perf__l">Raw accuracy</span>
      </div>
      <div class="cer-perf__item cer-perf__item--score">
        <span class="cer-perf__n"><?php echo h(number_format($scoreF, 0)); ?>%</span>
        <span class="cer-perf__l">Curved score</span>
      </div>
    </div>
    <?php if ($totalC > 0): ?>
      <div class="cer-perf__bar" aria-hidden="true">
        <span class="cer-perf__fill cer-perf__fill--ok" style="width:<?php echo max(0, min(100, (100 * $correctC) / $totalC)); ?>%"></span>
      </div>
    <?php endif; ?>
  </div>

  <div class="cer-details">
    <h3 class="cer-details__title">Exam details</h3>
    <dl class="cer-details__grid">
      <div><dt>Student</dt><dd><?php echo h($studentName); ?></dd></div>
      <div><dt>Professor</dt><dd><?php echo h($profName); ?></dd></div>
      <div><dt>Opens</dt><dd><?php echo h($opensLabel); ?></dd></div>
      <div><dt>Started</dt><dd><?php echo !empty($attempt['started_at']) ? h(college_exam_format_student_result_datetime($attempt['started_at'])) : '—'; ?></dd></div>
      <div><dt>Submitted</dt><dd><?php echo !empty($attempt['submitted_at']) ? h(college_exam_format_student_result_datetime($attempt['submitted_at'])) : '—'; ?></dd></div>
      <div><dt>Closes</dt><dd><?php echo h($closesLabel); ?></dd></div>
      <div><dt>Duration</dt><dd><?php echo h($durationLabel); ?></dd></div>
      <div><dt>Questions</dt><dd><?php echo (int)count($questions); ?></dd></div>
    </dl>
  </div>

  <?php if (!$reviewSheetOpen): ?>
    <div class="cer-locked">
      <div class="cer-locked__icon"><i class="bi bi-shield-lock"></i></div>
      <h3 class="cer-locked__title">Question review locked</h3>
      <p class="cer-locked__text"><?php echo $reviewLockNote; ?></p>
    </div>
  <?php else: ?>
    <div class="cer-review">
      <h3 class="cer-review__title">Question review</h3>
      <?php $i = 1; foreach ($questions as $q): ?>
        <?php
          $letters = ['A' => $q['choice_a'], 'B' => $q['choice_b'], 'C' => $q['choice_c'], 'D' => $q['choice_d']];
          $sel = strtoupper(trim((string)($answersMap[(int)$q['question_id']]['selected_answer'] ?? '')));
          $hasAns = $sel !== '';
          $correctLetter = strtoupper(trim((string)($q['correct_answer'] ?? '')));
          $isQCorrect = $hasAns && $sel === $correctLetter;
          $cardState = !$hasAns ? 'unanswered' : ($isQCorrect ? 'correct' : 'incorrect');
          $explanation = '';
          if (!empty($q['explanation'])) {
              $explanation = (string)$q['explanation'];
          } elseif (!empty($q['question_explanation'])) {
              $explanation = (string)$q['question_explanation'];
          }
        ?>
        <article class="cer-q-card cer-q-card--<?php echo h($cardState); ?>">
          <div class="cer-q-card__head">
            <span class="cer-q-card__num">Question <?php echo (int)$i; ?></span>
            <?php if (!$hasAns): ?>
              <span class="cer-q-badge cer-q-badge--miss">Unanswered</span>
            <?php elseif ($isQCorrect): ?>
              <span class="cer-q-badge cer-q-badge--ok">Correct</span>
            <?php else: ?>
              <span class="cer-q-badge cer-q-badge--bad">Incorrect</span>
            <?php endif; ?>
          </div>
          <div class="cer-q-card__stem quiz-rich-text"><?php echo renderQuizRichText($q['question_text']); ?></div>
          <div class="cer-q-choices">
            <?php foreach ($letters as $L => $txt):
                if ($txt === null || $txt === '') {
                    continue;
                }
                $isCorrect = $correctLetter === $L;
                $picked = $hasAns && $sel === $L;
                $cls = 'cer-choice';
                if ($isCorrect) {
                    $cls .= ' is-correct';
                }
                if ($picked && !$isCorrect) {
                    $cls .= ' is-yours-wrong';
                }
                if ($picked && $isCorrect) {
                    $cls .= ' is-yours-correct';
                }
            ?>
              <div class="<?php echo h($cls); ?>">
                <span class="cer-choice__letter"><?php echo h($L); ?></span>
                <div class="cer-choice__text"><?php echo nl2br(h($txt)); ?></div>
                <div class="cer-choice__tags">
                  <?php if ($picked): ?><span class="cer-tag cer-tag--yours">Your answer</span><?php endif; ?>
                  <?php if ($isCorrect): ?><span class="cer-tag cer-tag--correct">Correct answer</span><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="cer-q-explain">
            <strong>Explanation</strong>
            <p><?php echo $explanation !== '' ? nl2br(h($explanation)) : 'No explanation provided.'; ?></p>
          </div>
        </article>
        <?php $i++; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
