<?php
/**
 * Renders submitted-exam review HTML (full-page assessment report).
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
$scoreF = $totalC > 0
    ? college_exam_compute_score_percentage($correctC, $totalC)
    : (is_numeric($attempt['score'] ?? null) ? (float)$attempt['score'] : 0.0);
$rawPct = $scoreF;
$markPass = college_exam_is_pass_half_correct(
    isset($attempt['correct_count']) ? (int)$attempt['correct_count'] : null,
    $totalC > 0 ? $totalC : null,
    count($questions)
);

$unansweredC = 0;
$wrongAnsweredC = 0;
foreach ($questions as $qRow) {
    $selRow = strtoupper(trim((string)($answersMap[(int)$qRow['question_id']]['selected_answer'] ?? '')));
    if ($selRow === '') {
        $unansweredC++;
    } elseif (strtoupper(trim((string)($qRow['correct_answer'] ?? ''))) !== $selRow) {
        $wrongAnsweredC++;
    }
}

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
$timeUsedLabel = $timeUsedSec !== null ? gmdate('H:i:s', (int)$timeUsedSec) : '—';
$startedLabel = !empty($attempt['started_at']) ? college_exam_format_student_result_datetime($attempt['started_at']) : '—';
$submittedLabel = !empty($attempt['submitted_at']) ? college_exam_format_student_result_datetime($attempt['submitted_at']) : '—';
$submittedShort = !empty($attempt['submitted_at']) ? date('M j, Y', strtotime((string)$attempt['submitted_at'])) : '';

$examTypeLabel = function_exists('examination_exam_type_label')
    ? examination_exam_type_label((string)($exam['exam_type'] ?? 'regular'))
    : ucfirst((string)($exam['exam_type'] ?? 'regular'));

$scoreDisplay = college_exam_format_score_percent($scoreF);
$rawDisplay = college_exam_format_score_percent($rawPct);
$scoreNum = college_exam_format_score_percent($scoreF, false);
$rawNum = college_exam_format_score_percent($rawPct, false);

$donutPct = max(0, min(100, $scoreF));
$donutCirc = 2 * M_PI * 54;
$donutOffset = $donutCirc * (1 - ($donutPct / 100));
$accDonutCirc = 2 * M_PI * 42;
$accDonutOffset = $accDonutCirc * (1 - (max(0, min(100, $rawPct)) / 100));

$regularBreakdown = [];
$regularBreakdownMode = 'overall';
if (!empty($attempt['subject_breakdown_json'])) {
    $decodedBd = json_decode((string)$attempt['subject_breakdown_json'], true);
    if (is_array($decodedBd)) {
        $regularBreakdown = $decodedBd;
    }
}
if ($regularBreakdown === [] && !empty($questions) && is_array($answersMap ?? null)) {
    require_once __DIR__ . '/college_exam_subject_topic_helpers.php';
    $regularBreakdownMode = college_exam_get_breakdown_mode($conn, (int)($attempt['exam_id'] ?? $examId ?? 0));
    if (($regularBreakdownMode === 'subject' || $regularBreakdownMode === 'subject_topic')
        && function_exists('college_exam_build_subject_topic_breakdown')) {
        $regularBreakdown = college_exam_build_subject_topic_breakdown($questions, $answersMap, $regularBreakdownMode);
    }
} elseif ($regularBreakdown !== []) {
    // Infer display mode from stored payload (topics present → subject_topic).
    $hasTopics = false;
    foreach ($regularBreakdown as $bdRow) {
        if (!empty($bdRow['topics']) && is_array($bdRow['topics'])) {
            $hasTopics = true;
            break;
        }
    }
    $regularBreakdownMode = $hasTopics ? 'subject_topic' : 'subject';
}

if ($markPass && $correctC === $totalC && $totalC > 0) {
    $passHeadline = 'Excellent Performance!';
    $passSub = 'You answered all questions correctly on this examination.';
} elseif ($markPass) {
    $passHeadline = 'Examination Passed';
    $passSub = 'You met the passing requirement for this examination.';
} else {
    $passHeadline = 'Needs Improvement';
    $passSub = 'Review the questions you missed and consult your professor for guidance.';
}

if ($reviewAccessSt === 'no_schedule') {
    $reviewLockNote = '<strong>' . h($profName) . '</strong> has not scheduled review access yet. The full question sheet will unlock when your professor sets a date.';
} elseif ($reviewAccessSt === 'pending') {
    $reviewLockNote = 'The full review sheet is scheduled to open on <strong>' . h(college_exam_format_student_result_datetime($exam['review_sheet_available_from'] ?? '')) . '</strong>.';
} elseif ($reviewAccessSt === 'ended') {
    $reviewLockNote = 'The scheduled review period has ended. Only your results summary remains available.';
} else {
    $reviewLockNote = '';
}

if (!function_exists('cer_review_choice_text')) {
    function cer_review_choice_text(array $choices, string $letter): string
    {
        foreach ($choices as $choice) {
            if (strtoupper((string)($choice['letter'] ?? '')) === strtoupper($letter)) {
                return (string)($choice['label'] ?? '');
            }
        }
        return '';
    }
}
?>
<section class="cer-page" aria-label="Examination result">
  <header class="cer-full-hero">
    <div class="cer-full-hero__col cer-full-hero__col--info">
      <p class="cer-full-hero__eyebrow">Examination result</p>
      <h1 class="cer-full-hero__title"><?php echo h((string)($exam['title'] ?? 'Examination')); ?></h1>
      <p class="cer-full-hero__type"><?php echo h($examTypeLabel); ?></p>
      <?php if ($submittedShort !== ''): ?>
        <p class="cer-full-hero__meta">Examination successfully completed on <?php echo h($submittedShort); ?>.</p>
      <?php endif; ?>
    </div>

    <div class="cer-full-hero__col cer-full-hero__col--score">
      <div class="cer-donut cer-donut--hero" aria-label="Final score <?php echo h($scoreDisplay); ?>">
        <svg class="cer-donut__svg" viewBox="0 0 120 120" aria-hidden="true">
          <circle class="cer-donut__track" cx="60" cy="60" r="54"></circle>
          <circle class="cer-donut__fill<?php echo $markPass ? ' cer-donut__fill--pass' : ' cer-donut__fill--fail'; ?>"
            cx="60" cy="60" r="54"
            stroke-dasharray="<?php echo h((string)$donutCirc); ?>"
            stroke-dashoffset="<?php echo h((string)$donutOffset); ?>"></circle>
        </svg>
        <div class="cer-donut__center">
          <span class="cer-donut__value"><?php echo h($scoreDisplay); ?></span>
          <span class="cer-donut__fraction"><?php echo (int)$correctC; ?>/<?php echo (int)$totalC; ?></span>
          <span class="cer-donut__label"><i class="bi bi-trophy"></i> Final score</span>
        </div>
      </div>
    </div>

    <div class="cer-full-hero__col cer-full-hero__col--status">
      <?php if ($markPass): ?>
        <div class="cer-pass-card cer-pass-card--pass">
          <div class="cer-pass-card__icon" aria-hidden="true"><i class="bi bi-check-lg"></i></div>
          <p class="cer-pass-card__label">Passed</p>
          <p class="cer-pass-card__headline"><?php echo h($passHeadline); ?></p>
          <p class="cer-pass-card__sub"><?php echo h($passSub); ?></p>
        </div>
      <?php else: ?>
        <div class="cer-pass-card cer-pass-card--fail">
          <div class="cer-pass-card__icon" aria-hidden="true"><i class="bi bi-x-lg"></i></div>
          <p class="cer-pass-card__label">Not passed</p>
          <p class="cer-pass-card__headline"><?php echo h($passHeadline); ?></p>
          <p class="cer-pass-card__sub"><?php echo h($passSub); ?></p>
        </div>
      <?php endif; ?>
    </div>
  </header>

  <section class="cer-summary-strip" aria-labelledby="cer-summary-title">
    <h2 class="cer-summary-strip__title" id="cer-summary-title">Performance summary</h2>
    <div class="cer-summary-strip__metrics" role="list">
      <div class="cer-summary-metric cer-summary-metric--ok" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-check-circle-fill"></i></span>
        <span class="cer-summary-metric__k">Correct</span>
        <span class="cer-summary-metric__v"><?php echo (int)$correctC; ?></span>
      </div>
      <div class="cer-summary-metric cer-summary-metric--bad" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-x-circle-fill"></i></span>
        <span class="cer-summary-metric__k">Incorrect</span>
        <span class="cer-summary-metric__v"><?php echo (int)$wrongAnsweredC; ?></span>
      </div>
      <div class="cer-summary-metric" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-question-circle"></i></span>
        <span class="cer-summary-metric__k">Unanswered</span>
        <span class="cer-summary-metric__v"><?php echo (int)$unansweredC; ?></span>
      </div>
      <div class="cer-summary-metric cer-summary-metric--accent" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-bullseye"></i></span>
        <span class="cer-summary-metric__k">Accuracy</span>
        <span class="cer-summary-metric__v"><?php echo h($rawDisplay); ?></span>
      </div>
      <div class="cer-summary-metric" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-stopwatch"></i></span>
        <span class="cer-summary-metric__k">Time used</span>
        <span class="cer-summary-metric__v"><?php echo h($timeUsedLabel); ?></span>
      </div>
      <div class="cer-summary-metric" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-journal-text"></i></span>
        <span class="cer-summary-metric__k">Total questions</span>
        <span class="cer-summary-metric__v"><?php echo (int)$totalC; ?></span>
      </div>
    </div>
  </section>

  <?php
    $cerWeakTopics = [];
    foreach ($regularBreakdown as $cerWeakSubject) {
        if (!is_array($cerWeakSubject) || empty($cerWeakSubject['topics']) || !is_array($cerWeakSubject['topics'])) {
            continue;
        }
        $cerWeakSubjectName = trim((string)($cerWeakSubject['subject_name'] ?? ''));
        if ($cerWeakSubjectName === '') {
            $cerWeakSubjectName = 'Subject';
        }
        foreach ($cerWeakSubject['topics'] as $cerWeakTopic) {
            if (!is_array($cerWeakTopic)) {
                continue;
            }
            $cerWeakName = trim((string)($cerWeakTopic['topic_name'] ?? ''));
            $cerWeakTotal = (int)($cerWeakTopic['total'] ?? 0);
            if ($cerWeakName === '' || $cerWeakTotal <= 0 || !is_numeric($cerWeakTopic['score_pct'] ?? null)) {
                continue;
            }
            $cerWeakPct = (float)$cerWeakTopic['score_pct'];
            if ($cerWeakPct >= 50) {
                continue;
            }
            $cerWeakTopics[] = [
                'topic_name' => $cerWeakName,
                'subject_name' => $cerWeakSubjectName,
                'correct' => (int)($cerWeakTopic['correct'] ?? 0),
                'total' => $cerWeakTotal,
                'score_pct' => $cerWeakPct,
            ];
        }
    }
    $cerWeakCount = count($cerWeakTopics);
  ?>
  <?php if ($cerWeakTopics !== []): ?>
  <?php $cerWeakNoun = $cerWeakCount === 1 ? 'topic' : 'topics'; ?>
  <section class="cer-weak" aria-labelledby="cer-weak-title">
    <div class="cer-weak__head">
      <div class="cer-weak__medal" aria-hidden="true">
        <span class="cer-weak__medal-num"><?php echo (int)$cerWeakCount; ?></span>
        <span class="cer-weak__medal-k"><?php echo h($cerWeakNoun); ?></span>
      </div>
      <div class="cer-weak__intro">
        <h2 class="cer-weak__title" id="cer-weak-title">Weak topics</h2>
        <p class="cer-weak__count"><?php echo (int)$cerWeakCount . ' ' . h($cerWeakNoun); ?> scored below 50%</p>
      </div>
    </div>
    <ul class="cer-weak__list">
      <?php foreach ($cerWeakTopics as $cerWeakRow): ?>
        <?php
          $cerWeakWidth = college_exam_format_score_percent(max(0, min(100, (float)$cerWeakRow['score_pct'])), false);
          $cerWeakFraction = (int)$cerWeakRow['correct'] . '/' . (int)$cerWeakRow['total'] . ' correct';
          $cerWeakPctLabel = college_exam_format_score_percent($cerWeakRow['score_pct']);
        ?>
        <li class="cer-weak__item">
          <span class="cer-weak__mark" aria-hidden="true"><i class="bi bi-bookmark"></i></span>
          <span class="cer-weak__identity">
            <span class="cer-weak__topic"><?php echo h($cerWeakRow['topic_name']); ?></span>
            <span class="cer-weak__subject"><?php echo h($cerWeakRow['subject_name']); ?></span>
          </span>
          <span class="cer-weak__score">
            <span class="cer-weak__fraction"><?php echo h($cerWeakFraction); ?></span>
            <span class="cer-weak__pct"><?php echo h($cerWeakPctLabel); ?></span>
            <span class="cer-weak__label">Review</span>
          </span>
          <span class="cer-weak__bar" aria-hidden="true"><span class="cer-weak__bar-fill" style="width: <?php echo h($cerWeakWidth); ?>%"></span></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if ($regularBreakdown !== []): ?>
  <section class="cer-breakdown" aria-labelledby="cer-breakdown-title">
    <h2 class="cer-breakdown__title" id="cer-breakdown-title"><?php echo $regularBreakdownMode === 'subject_topic' ? 'Subject &amp; topic breakdown' : 'Subject breakdown'; ?></h2>
    <div class="cer-breakdown__list">
      <?php $cerBdIndex = 0; ?>
      <?php foreach ($regularBreakdown as $subjBd): ?>
        <?php
          if (!is_array($subjBd)) {
              continue;
          }
          $sName = (string)($subjBd['subject_name'] ?? 'Subject');
          $sPct = (float)($subjBd['score_pct'] ?? 0);
          $sCorrect = (int)($subjBd['correct'] ?? 0);
          $sTotal = (int)($subjBd['total'] ?? 0);
          $sOnTrack = $sPct >= 50;
          $sTone = $sOnTrack ? 'on-track' : 'review';
          $sLabel = $sOnTrack ? 'On track' : 'Review';
          $sWidth = college_exam_format_score_percent(max(0, min(100, $sPct)), false);
          $sScoreLine = $sCorrect . '/' . $sTotal . ' correct — ' . college_exam_format_score_percent($sPct);
          $sTopics = [];
          if ($regularBreakdownMode === 'subject_topic' && !empty($subjBd['topics']) && is_array($subjBd['topics'])) {
              foreach ($subjBd['topics'] as $topicBd) {
                  if (is_array($topicBd)) {
                      $sTopics[] = $topicBd;
                  }
              }
          }
          $sHasTopics = $sTopics !== [];
        ?>
        <?php if ($sHasTopics): ?>
        <details class="cer-breakdown__subject"<?php echo $cerBdIndex === 0 ? ' open' : ''; ?>>
          <summary class="cer-breakdown__summary">
            <span class="cer-breakdown__chevron" aria-hidden="true"></span>
            <span class="cer-breakdown__row">
              <span class="cer-breakdown__name"><?php echo h($sName); ?></span>
              <span class="cer-breakdown__score">
                <span class="cer-breakdown__fraction"><?php echo h($sScoreLine); ?></span>
                <span class="cer-breakdown__label cer-breakdown__label--<?php echo h($sTone); ?>"><?php echo h($sLabel); ?></span>
              </span>
              <span class="cer-breakdown__bar" aria-hidden="true"><span class="cer-breakdown__bar-fill cer-breakdown__bar-fill--<?php echo h($sTone); ?>" style="width: <?php echo h($sWidth); ?>%"></span></span>
            </span>
          </summary>
          <ul class="cer-breakdown__topics">
            <?php foreach ($sTopics as $topicBd): ?>
              <?php
                $tName = (string)($topicBd['topic_name'] ?? 'Topic');
                $tPct = (float)($topicBd['score_pct'] ?? 0);
                $tCorrect = (int)($topicBd['correct'] ?? 0);
                $tTotal = (int)($topicBd['total'] ?? 0);
                $tOnTrack = $tPct >= 50;
                $tTone = $tOnTrack ? 'on-track' : 'review';
                $tLabel = $tOnTrack ? 'On track' : 'Review';
                $tWidth = college_exam_format_score_percent(max(0, min(100, $tPct)), false);
                $tScoreLine = $tCorrect . '/' . $tTotal . ' correct — ' . college_exam_format_score_percent($tPct);
              ?>
              <li class="cer-breakdown__topic cer-breakdown__row">
                <span class="cer-breakdown__name"><?php echo h($tName); ?></span>
                <span class="cer-breakdown__score">
                  <span class="cer-breakdown__fraction"><?php echo h($tScoreLine); ?></span>
                  <span class="cer-breakdown__label cer-breakdown__label--<?php echo h($tTone); ?>"><?php echo h($tLabel); ?></span>
                </span>
                <span class="cer-breakdown__bar" aria-hidden="true"><span class="cer-breakdown__bar-fill cer-breakdown__bar-fill--<?php echo h($tTone); ?>" style="width: <?php echo h($tWidth); ?>%"></span></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </details>
        <?php else: ?>
        <div class="cer-breakdown__subject">
          <div class="cer-breakdown__summary cer-breakdown__summary--static">
            <span class="cer-breakdown__row">
              <span class="cer-breakdown__name"><?php echo h($sName); ?></span>
              <span class="cer-breakdown__score">
                <span class="cer-breakdown__fraction"><?php echo h($sScoreLine); ?></span>
                <span class="cer-breakdown__label cer-breakdown__label--<?php echo h($sTone); ?>"><?php echo h($sLabel); ?></span>
              </span>
              <span class="cer-breakdown__bar" aria-hidden="true"><span class="cer-breakdown__bar-fill cer-breakdown__bar-fill--<?php echo h($sTone); ?>" style="width: <?php echo h($sWidth); ?>%"></span></span>
            </span>
          </div>
        </div>
        <?php endif; ?>
        <?php $cerBdIndex++; ?>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <div class="cer-split">
    <section class="cer-split__panel cer-analysis" aria-labelledby="cer-analysis-title">
      <h2 class="cer-split__title" id="cer-analysis-title">Performance analysis</h2>
      <div class="cer-analysis__body">
        <div class="cer-analysis__gauge">
          <div class="cer-donut cer-donut--sm" aria-hidden="true">
            <svg class="cer-donut__svg" viewBox="0 0 96 96">
              <circle class="cer-donut__track" cx="48" cy="48" r="42"></circle>
              <circle class="cer-donut__fill cer-donut__fill--pass" cx="48" cy="48" r="42"
                stroke-dasharray="<?php echo h((string)$accDonutCirc); ?>"
                stroke-dashoffset="<?php echo h((string)$accDonutOffset); ?>"></circle>
            </svg>
            <div class="cer-donut__center">
              <span class="cer-donut__value cer-donut__value--sm"><?php echo h($rawDisplay); ?></span>
              <span class="cer-donut__label">Accuracy</span>
            </div>
          </div>
          <div class="cer-analysis__bars">
            <div class="cer-bar-row">
              <div class="cer-bar-row__head"><span>Correct</span><strong><?php echo (int)$correctC; ?></strong></div>
              <div class="cer-bar"><span class="cer-bar__fill cer-bar__fill--ok" style="width:<?php echo $totalC > 0 ? max(0, min(100, (100 * $correctC) / $totalC)) : 0; ?>%"></span></div>
            </div>
            <div class="cer-bar-row">
              <div class="cer-bar-row__head"><span>Incorrect</span><strong><?php echo (int)$wrongAnsweredC; ?></strong></div>
              <div class="cer-bar"><span class="cer-bar__fill cer-bar__fill--bad" style="width:<?php echo $totalC > 0 ? max(0, min(100, (100 * $wrongAnsweredC) / $totalC)) : 0; ?>%"></span></div>
            </div>
            <div class="cer-bar-row">
              <div class="cer-bar-row__head"><span>Unanswered</span><strong><?php echo (int)$unansweredC; ?></strong></div>
              <div class="cer-bar"><span class="cer-bar__fill cer-bar__fill--muted" style="width:<?php echo $totalC > 0 ? max(0, min(100, (100 * $unansweredC) / $totalC)) : 0; ?>%"></span></div>
            </div>
          </div>
        </div>
        <div class="cer-analysis__scores">
          <div class="cer-score-chip">
            <span class="cer-score-chip__k">Raw accuracy</span>
            <span class="cer-score-chip__v"><?php echo h($rawDisplay); ?></span>
          </div>
          <div class="cer-score-chip cer-score-chip--accent">
            <span class="cer-score-chip__k">Curved score (CEO)</span>
            <span class="cer-score-chip__v"><?php echo h($scoreDisplay); ?></span>
          </div>
        </div>
      </div>
    </section>

    <section class="cer-split__panel cer-info" aria-labelledby="cer-info-title">
      <h2 class="cer-split__title" id="cer-info-title">Examination information</h2>
      <dl class="cer-info-grid">
        <div class="cer-info-item"><dt><i class="bi bi-person"></i> Student</dt><dd><?php echo h($studentName); ?></dd></div>
        <div class="cer-info-item"><dt><i class="bi bi-journal-text"></i> Examination</dt><dd><?php echo h((string)($exam['title'] ?? 'Examination')); ?></dd></div>
        <div class="cer-info-item"><dt><i class="bi bi-person-badge"></i> Professor</dt><dd><?php echo h($profName); ?></dd></div>
        <div class="cer-info-item"><dt><i class="bi bi-hourglass-split"></i> Duration</dt><dd><?php echo h($durationLabel); ?></dd></div>
        <div class="cer-info-item"><dt><i class="bi bi-list-ol"></i> Total questions</dt><dd><?php echo (int)count($questions); ?></dd></div>
        <div class="cer-info-item"><dt><i class="bi bi-door-open"></i> Opened</dt><dd><?php echo h($opensLabel); ?></dd></div>
        <div class="cer-info-item"><dt><i class="bi bi-play-circle"></i> Started</dt><dd><?php echo h($startedLabel); ?></dd></div>
        <div class="cer-info-item"><dt><i class="bi bi-send-check"></i> Submitted</dt><dd><?php echo h($submittedLabel); ?></dd></div>
        <div class="cer-info-item"><dt><i class="bi bi-door-closed"></i> Closes</dt><dd><?php echo h($closesLabel); ?></dd></div>
        <?php if ($timeUsedSec !== null): ?>
        <div class="cer-info-item"><dt><i class="bi bi-stopwatch"></i> Time used</dt><dd><?php echo h($timeUsedLabel); ?></dd></div>
        <?php endif; ?>
      </dl>
    </section>
  </div>

  <?php if (!$reviewSheetOpen): ?>
    <section class="cer-review-full" aria-labelledby="cer-review-locked-title">
      <h2 class="cer-review-full__title" id="cer-review-locked-title">Answer review</h2>
      <div class="cer-locked">
        <div class="cer-locked__icon"><i class="bi bi-shield-lock"></i></div>
        <h3 class="cer-locked__title">Question review locked</h3>
        <p class="cer-locked__text"><?php echo $reviewLockNote; ?></p>
      </div>
    </section>
  <?php else: ?>
    <section class="cer-review-full" id="cer-answer-review" aria-labelledby="cer-review-title">
      <h2 class="cer-review-full__title" id="cer-review-title">Answer review</h2>
      <div class="cer-review-full__list">
        <?php $i = 1; foreach ($questions as $q): ?>
          <?php
            $studentN = (int)($q['_display_position'] ?? $i);
            $displayChoices = college_exam_question_display_choices($q);
            $sel = strtoupper(trim((string)($answersMap[(int)$q['question_id']]['selected_answer'] ?? '')));
            $hasAns = $sel !== '';
            $correctLetter = strtoupper(trim((string)($q['correct_answer'] ?? '')));
            $isQCorrect = $hasAns && $sel === $correctLetter;
            $cardState = !$hasAns ? 'unanswered' : ($isQCorrect ? 'correct' : 'incorrect');
            $yourText = $hasAns ? cer_review_choice_text($displayChoices, $sel) : '—';
            $correctText = $correctLetter !== '' ? cer_review_choice_text($displayChoices, $correctLetter) : '—';
            $explanation = '';
            if (!empty($q['explanation'])) {
                $explanation = (string)$q['explanation'];
            } elseif (!empty($q['question_explanation'])) {
                $explanation = (string)$q['question_explanation'];
            }
          ?>
          <article class="cer-q-card cer-q-card--<?php echo h($cardState); ?>">
            <div class="cer-q-card__head">
              <span class="cer-q-card__num">Question <?php echo str_pad((string)$studentN, 2, '0', STR_PAD_LEFT); ?></span>
              <?php if (!$hasAns): ?>
                <span class="cer-q-badge cer-q-badge--miss"><i class="bi bi-dash-circle"></i> Unanswered</span>
              <?php elseif ($isQCorrect): ?>
                <span class="cer-q-badge cer-q-badge--ok"><i class="bi bi-check-circle-fill"></i> Correct</span>
              <?php else: ?>
                <span class="cer-q-badge cer-q-badge--bad"><i class="bi bi-x-circle-fill"></i> Incorrect</span>
              <?php endif; ?>
            </div>
            <div class="cer-q-card__stem quiz-rich-text"><?php echo renderQuizRichText($q['question_text']); ?></div>
            <div class="cer-answer-compare">
              <div class="cer-answer-compare__col">
                <p class="cer-answer-compare__label">Your answer</p>
                <?php if ($hasAns): ?>
                  <div class="cer-answer-pill<?php echo $isQCorrect ? ' cer-answer-pill--ok' : ' cer-answer-pill--bad'; ?>">
                    <span class="cer-answer-pill__letter"><?php echo h($sel); ?></span>
                    <span class="cer-answer-pill__text"><?php echo nl2br(h($yourText)); ?></span>
                  </div>
                <?php else: ?>
                  <p class="cer-answer-pill cer-answer-pill--empty">No answer submitted</p>
                <?php endif; ?>
              </div>
              <div class="cer-answer-compare__col">
                <p class="cer-answer-compare__label">Correct answer</p>
                <div class="cer-answer-pill cer-answer-pill--ok">
                  <span class="cer-answer-pill__letter"><?php echo h($correctLetter !== '' ? $correctLetter : '—'); ?></span>
                  <span class="cer-answer-pill__text"><?php echo nl2br(h($correctText)); ?></span>
                </div>
              </div>
            </div>
            <?php if ($explanation !== ''): ?>
            <div class="cer-q-explain">
              <strong>Explanation</strong>
              <p><?php echo nl2br(h($explanation)); ?></p>
            </div>
            <?php endif; ?>
          </article>
          <?php $i++; ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <footer class="cer-page-foot">
    <a href="college_exams" class="cer-page-foot__back"><i class="bi bi-arrow-left"></i> Back to examinations</a>
    <div class="cer-page-foot__actions">
      <?php if ($reviewSheetOpen): ?>
        <a href="#cer-answer-review" class="cer-page-foot__btn cer-page-foot__btn--ghost">Review answers</a>
      <?php endif; ?>
    </div>
  </footer>
</section>
