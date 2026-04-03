<?php
/**
 * Renders submitted-exam review HTML (hero tiles + optional locked gate + optional question list).
 * Expects: $exam, $attempt, $now, $answersMap, $questions, $profName, $timeUsedSec
 */
if (!isset($exam, $attempt, $now, $answersMap, $questions, $profName)) {
    return;
}

$reviewSheetOpen = college_exam_review_sheet_is_open($exam, $now);
$reviewAccessSt = college_exam_review_access_status($exam, $now);
$correctC = (int)($attempt['correct_count'] ?? 0);
$totalC = (int)($attempt['total_count'] ?? 0);
$accuracyPct = $totalC > 0 ? round(100 * $correctC / $totalC, 2) : 0.0;
$scoreF = $totalC > 0
    ? college_exam_compute_score_percentage($correctC, $totalC)
    : (is_numeric($attempt['score'] ?? null) ? (float)$attempt['score'] : 0.0);
$totalForMark = $totalC > 0 ? $totalC : count($questions);
$markPass = college_exam_is_pass_half_correct(
    isset($attempt['correct_count']) ? (int)$attempt['correct_count'] : null,
    $totalForMark > 0 ? $totalForMark : null,
    count($questions)
);
$openingLabel = (empty($exam['available_from']) || trim((string)$exam['available_from']) === '' || preg_match('/^0000-00-00/', (string)$exam['available_from']))
    ? 'Immediate'
    : college_exam_format_student_result_datetime($exam['available_from']);
$deadlineLabel = (empty($exam['deadline']) || trim((string)$exam['deadline']) === '' || preg_match('/^0000-00-00/', (string)$exam['deadline']))
    ? 'No deadline'
    : college_exam_format_student_result_datetime($exam['deadline']);
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
<div class="review-result-hero">
  <p class="review-hero-title m-0">Exam results</p>
  <p class="review-hero-sub m-0">Submitted <?php echo !empty($attempt['submitted_at']) ? h(college_exam_format_student_result_datetime($attempt['submitted_at'])) : '—'; ?></p>
  <div class="review-summary-grid">
    <div class="review-sum-card">
      <div class="review-sum-k">Score</div>
      <div class="review-sum-v text-sky-700"><?php echo h(number_format($scoreF, 2)); ?>%</div>
    </div>
    <div class="review-sum-card">
      <div class="review-sum-k">Accuracy</div>
      <div class="review-sum-v"><?php echo (int)$correctC; ?>/<?php echo (int)$totalC; ?> <span class="text-slate-500 font-bold">(<?php echo h(number_format($accuracyPct, 2)); ?>%)</span></div>
    </div>
    <div class="review-sum-card">
      <div class="review-sum-k">Mark</div>
      <div class="review-sum-v">
        <?php if ($markPass): ?>
          <span class="review-mark-pass"><i class="bi bi-check-circle-fill"></i> Pass</span>
        <?php else: ?>
          <span class="review-mark-fail"><i class="bi bi-x-circle-fill"></i> Fail</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="review-sum-card">
      <div class="review-sum-k">Started</div>
      <div class="review-sum-v"><?php echo !empty($attempt['started_at']) ? h(college_exam_format_student_result_datetime($attempt['started_at'])) : '—'; ?></div>
    </div>
    <div class="review-sum-card">
      <div class="review-sum-k">Time used</div>
      <div class="review-sum-v"><?php echo $timeUsedSec !== null ? h(gmdate('H:i:s', $timeUsedSec)) : '—'; ?></div>
    </div>
    <div class="review-sum-card">
      <div class="review-sum-k">Professor</div>
      <div class="review-sum-v"><?php echo h($profName); ?></div>
    </div>
    <div class="review-sum-card">
      <div class="review-sum-k">Published on</div>
      <div class="review-sum-v"><?php echo h(college_exam_format_student_result_datetime($exam['created_at'] ?? null)); ?></div>
    </div>
    <div class="review-sum-card">
      <div class="review-sum-k">Opening time</div>
      <div class="review-sum-v"><?php echo h($openingLabel); ?></div>
    </div>
    <div class="review-sum-card">
      <div class="review-sum-k">Deadline</div>
      <div class="review-sum-v"><?php echo h($deadlineLabel); ?></div>
    </div>
  </div>
</div>

<?php if (!$reviewSheetOpen): ?>
  <div class="review-locked-gate">
    <div class="review-locked-icon"><i class="bi bi-shield-lock"></i></div>
    <h2 class="review-locked-title">Review sheet locked</h2>
    <p class="review-locked-text"><?php echo $reviewLockNote; ?></p>
  </div>
<?php endif; ?>

<?php if ($reviewSheetOpen): ?>
  <h2 class="text-lg font-extrabold text-[#143D59] mt-6 mb-3 m-0">Question review</h2>
  <?php $i = 1;
  foreach ($questions as $q): ?>
    <div class="review-q-card">
      <?php
        $letters = ['A' => $q['choice_a'], 'B' => $q['choice_b'], 'C' => $q['choice_c'], 'D' => $q['choice_d']];
        $sel = strtoupper(trim((string)($answersMap[(int)$q['question_id']]['selected_answer'] ?? '')));
        $hasAns = $sel !== '';
      ?>
      <?php if (!$hasAns): ?>
        <div class="review-no-answer-strip" role="status">
          <i class="bi bi-exclamation-octagon-fill"></i>
          <span>No answer submitted for question <?php echo (int)$i; ?>.</span>
        </div>
      <?php endif; ?>
      <div class="flex gap-2 items-start mb-3">
        <span class="font-bold text-[#143D59] shrink-0 text-lg"><?php echo (int)$i; ?>.</span>
        <div class="question-text flex-1 min-w-0"><?php echo renderQuizRichText($q['question_text']); ?></div>
      </div>
      <?php
      foreach ($letters as $L => $txt):
          if ($txt === null || $txt === '') {
              continue;
          }
          $isCorrect = strtoupper(trim((string)$q['correct_answer'])) === $L;
          $picked = $hasAns && $sel === $L;
          $pillClass = $isCorrect ? 'review-pill-correct' : ($picked ? 'review-pill-picked' : 'border-gray-200 text-gray-600');
      ?>
      <div class="flex items-start justify-between gap-3 py-2 px-3 rounded-lg border <?php echo $pillClass; ?> text-sm mb-2">
        <div><span class="font-mono w-6 inline-block"><?php echo h($L); ?>.</span> <?php echo nl2br(h($txt)); ?></div>
        <?php if ($isCorrect): ?>
          <span class="font-bold text-emerald-800">Correct answer</span>
        <?php elseif ($picked): ?>
          <span class="font-bold text-red-800">Your answer</span>
        <?php elseif (!$hasAns && !$isCorrect): ?>
          <span class="text-slate-400 font-semibold text-xs">—</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php
        $explanation = '';
        if (!empty($q['explanation'])) {
            $explanation = (string)$q['explanation'];
        } elseif (!empty($q['question_explanation'])) {
            $explanation = (string)$q['question_explanation'];
        }
      ?>
      <div class="mt-3 text-sm rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-slate-700 leading-relaxed">
        <span class="font-extrabold text-slate-800">Explanation</span>
        <span class="block mt-1"><?php echo $explanation !== '' ? nl2br(h($explanation)) : 'No explanation provided by instructor.'; ?></span>
      </div>
    </div>
    <?php $i++; ?>
  <?php endforeach; ?>
<?php endif; ?>
