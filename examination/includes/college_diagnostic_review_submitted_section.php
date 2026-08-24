<?php
/**
 * Renders submitted diagnostic exam report (full-page assessment analytics).
 * Expects: $batch, $attempt, $questions, $answersMap, $breakdown, $profName
 * Optional: $studentDisplayName, $timeUsedSec, $analytics (pre-built)
 */

if (!isset($batch, $attempt, $questions, $answersMap, $profName)) {
    return;
}

if (!isset($breakdown) || !is_array($breakdown)) {
    $breakdown = [];
}

$analytics = isset($analytics) && is_array($analytics)
    ? $analytics
    : diagnostic_exam_build_result_analytics($questions, $answersMap, $breakdown);

$batchTitle = (string)($batch['title'] ?? 'Diagnostic examination');
$scoreDisplay = diagnostic_exam_format_score_percent($analytics['overall_pct']);
$accDisplay = diagnostic_exam_format_score_percent($analytics['accuracy_pct']);
$overallBand = $analytics['overall_band'];
$timeUsedLabel = isset($timeUsedSec) && $timeUsedSec !== null ? gmdate('H:i:s', (int)$timeUsedSec) : '—';
$startedLabel = !empty($attempt['started_at']) ? date('M j, Y · g:i A', strtotime((string)$attempt['started_at'])) : '—';
$submittedLabel = !empty($attempt['submitted_at']) ? date('M j, Y · g:i A', strtotime((string)$attempt['submitted_at'])) : '—';
$submittedShort = !empty($attempt['submitted_at']) ? date('M j, Y', strtotime((string)$attempt['submitted_at'])) : '';
$durationLabel = max(0, (int)($batch['time_limit_seconds'] ?? 0)) > 0
    ? diagnostic_exam_human_duration((int)$batch['time_limit_seconds'])
    : 'No fixed timer';
$studentName = trim((string)($studentDisplayName ?? ''));
if ($studentName === '') {
    $studentName = 'You';
}

$answeredC = (int)$analytics['correct'] + (int)$analytics['incorrect'];
$donutPct = max(0, min(100, (float)$analytics['overall_pct']));
$donutCirc = 2 * M_PI * 54;
$donutOffset = $donutCirc * (1 - ($donutPct / 100));

if (!function_exists('cdr_review_choice_text')) {
    function cdr_review_choice_text(array $choices, string $letter): string
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
<section class="cdr-page" aria-label="Diagnostic assessment report">

  <header class="cdr-hero">
    <div class="cdr-hero__col cdr-hero__col--info">
      <p class="cdr-hero__eyebrow">Diagnostic assessment</p>
      <h1 class="cdr-hero__title"><?php echo h($batchTitle); ?></h1>
      <p class="cdr-hero__type">CPA readiness diagnostic</p>
      <?php if ($submittedShort !== ''): ?>
        <p class="cdr-hero__meta">Assessment completed on <?php echo h($submittedShort); ?>.</p>
      <?php endif; ?>
      <?php if ((string)($analytics['hero_insight'] ?? '') !== ''): ?>
        <p class="cdr-hero__insight"><?php echo h((string)$analytics['hero_insight']); ?></p>
      <?php endif; ?>
    </div>

    <div class="cdr-hero__col cdr-hero__col--score">
      <p class="cdr-hero__score-k">Overall diagnostic score</p>
      <div class="cer-donut cer-donut--hero" aria-label="Diagnostic score <?php echo h($scoreDisplay); ?>">
        <svg class="cer-donut__svg" viewBox="0 0 120 120" aria-hidden="true">
          <circle class="cer-donut__track" cx="60" cy="60" r="54"></circle>
          <circle class="cer-donut__fill cer-donut__fill--pass" cx="60" cy="60" r="54"
            stroke-dasharray="<?php echo h((string)$donutCirc); ?>"
            stroke-dashoffset="<?php echo h((string)$donutOffset); ?>"></circle>
        </svg>
        <div class="cer-donut__center">
          <span class="cer-donut__value"><?php echo h($scoreDisplay); ?></span>
          <span class="cer-donut__label">Diagnostic score</span>
        </div>
      </div>
    </div>

    <div class="cdr-hero__col cdr-hero__col--level">
      <div class="cdr-level-card cdr-level-card--<?php echo h((string)$overallBand['state']); ?>">
        <p class="cdr-level-card__k">Performance level</p>
        <p class="cdr-level-card__v"><?php echo h((string)$overallBand['label']); ?></p>
        <p class="cdr-level-card__sub">Visual guidance based on your overall accuracy on this diagnostic.</p>
      </div>
    </div>
  </header>

  <section class="cer-summary-strip cdr-summary" aria-labelledby="cdr-summary-title">
    <h2 class="cer-summary-strip__title" id="cdr-summary-title">Overall performance</h2>
    <div class="cer-summary-strip__metrics" role="list">
      <div class="cer-summary-metric cer-summary-metric--ok" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-check-circle-fill"></i></span>
        <span class="cer-summary-metric__k">Correct</span>
        <span class="cer-summary-metric__v"><?php echo (int)$analytics['correct']; ?></span>
      </div>
      <div class="cer-summary-metric cer-summary-metric--bad" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-x-circle-fill"></i></span>
        <span class="cer-summary-metric__k">Incorrect</span>
        <span class="cer-summary-metric__v"><?php echo (int)$analytics['incorrect']; ?></span>
      </div>
      <div class="cer-summary-metric" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-question-circle"></i></span>
        <span class="cer-summary-metric__k">Unanswered</span>
        <span class="cer-summary-metric__v"><?php echo (int)$analytics['unanswered']; ?></span>
      </div>
      <div class="cer-summary-metric cer-summary-metric--accent" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-bullseye"></i></span>
        <span class="cer-summary-metric__k">Accuracy</span>
        <span class="cer-summary-metric__v"><?php echo h($accDisplay); ?></span>
      </div>
      <div class="cer-summary-metric" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-journal-text"></i></span>
        <span class="cer-summary-metric__k">Total questions</span>
        <span class="cer-summary-metric__v"><?php echo (int)$analytics['total']; ?></span>
      </div>
      <div class="cer-summary-metric" role="listitem">
        <span class="cer-summary-metric__icon"><i class="bi bi-stopwatch"></i></span>
        <span class="cer-summary-metric__k">Time used</span>
        <span class="cer-summary-metric__v"><?php echo h($timeUsedLabel); ?></span>
      </div>
    </div>
  </section>

  <section class="cdr-readiness" aria-labelledby="cdr-readiness-title">
    <div class="cdr-readiness__inner">
      <div class="cdr-readiness__score">
        <span class="cdr-readiness__pct"><?php echo h($scoreDisplay); ?></span>
        <span class="cdr-readiness__label" id="cdr-readiness-title">Overall performance</span>
        <span class="cdr-band cdr-band--<?php echo h((string)$overallBand['state']); ?>"><?php echo h((string)$overallBand['label']); ?></span>
      </div>
      <p class="cdr-readiness__note"><?php echo h((string)$analytics['readiness_note']); ?></p>
    </div>
  </section>

  <?php if (!empty($analytics['subjects'])): ?>
  <section class="cdr-subjects" aria-labelledby="cdr-subjects-title">
    <h2 class="cdr-section-title" id="cdr-subjects-title">Subject performance</h2>

    <div class="cdr-compare" aria-label="Subject comparison">
      <?php foreach ($analytics['subjects'] as $subj): ?>
        <?php if ((int)$subj['total'] <= 0) { continue; } ?>
        <?php
          $subPct = diagnostic_exam_format_score_percent($subj['score_pct']);
          $subBand = $subj['band'];
          $subLabel = (string)($subj['subject_code'] !== '' ? $subj['subject_code'] : $subj['subject_name']);
        ?>
        <div class="cdr-compare__row">
          <span class="cdr-compare__name"><?php echo h($subLabel); ?></span>
          <span class="cdr-compare__pct"><?php echo h($subPct); ?></span>
          <div class="cdr-compare__bar" aria-hidden="true">
            <span class="cdr-compare__fill cdr-compare__fill--<?php echo h((string)$subBand['state']); ?>" style="width:<?php echo max(0, min(100, (float)$subj['score_pct'])); ?>%"></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="cdr-subject-list">
      <?php foreach ($analytics['subjects'] as $subj): ?>
        <?php if ((int)$subj['total'] <= 0) { continue; } ?>
        <?php
          $subPct = diagnostic_exam_format_score_percent($subj['score_pct']);
          $subBand = $subj['band'];
          $subLabel = (string)($subj['subject_code'] !== '' ? $subj['subject_code'] : $subj['subject_name']);
          $subFull = trim((string)($subj['subject_name'] ?? ''));
        ?>
        <details class="cdr-subject-acc">
          <summary class="cdr-subject-acc__head">
            <span class="cdr-subject-acc__main">
              <span class="cdr-subject-acc__code"><?php echo h($subLabel); ?></span>
              <?php if ($subFull !== '' && $subFull !== $subLabel): ?>
                <span class="cdr-subject-acc__name"><?php echo h($subFull); ?></span>
              <?php endif; ?>
            </span>
            <span class="cdr-subject-acc__stats">
              <span class="cdr-subject-acc__pct"><?php echo h($subPct); ?></span>
              <span class="cdr-band cdr-band--<?php echo h((string)$subBand['state']); ?>"><?php echo h((string)$subBand['label']); ?></span>
              <span class="cdr-subject-acc__toggle"><i class="bi bi-chevron-down"></i></span>
            </span>
          </summary>
          <div class="cdr-subject-acc__body">
            <div class="cdr-subject-acc__bar" aria-hidden="true">
              <span class="cdr-subject-acc__fill cdr-subject-acc__fill--<?php echo h((string)$subBand['state']); ?>" style="width:<?php echo max(0, min(100, (float)$subj['score_pct'])); ?>%"></span>
            </div>
            <p class="cdr-subject-acc__ratio"><?php echo (int)$subj['correct']; ?> / <?php echo (int)$subj['total']; ?> correct</p>

            <?php if (!empty($subj['strong_topics']) || !empty($subj['weak_topics'])): ?>
            <div class="cdr-subject-acc__highlights">
              <?php if (!empty($subj['strong_topics'])): ?>
              <div class="cdr-highlight cdr-highlight--ok">
                <span class="cdr-highlight__k">Strong areas</span>
                <span class="cdr-highlight__v"><?php echo h(implode(', ', $subj['strong_topics'])); ?></span>
              </div>
              <?php endif; ?>
              <?php if (!empty($subj['weak_topics'])): ?>
              <div class="cdr-highlight cdr-highlight--warn">
                <span class="cdr-highlight__k">Needs review</span>
                <span class="cdr-highlight__v"><?php echo h(implode(', ', $subj['weak_topics'])); ?></span>
              </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($analytics['has_topics']) && !empty($subj['topics'])): ?>
            <div class="cdr-topic-block">
              <h3 class="cdr-topic-block__title">Topic / area breakdown</h3>
              <?php foreach ($subj['topics'] as $topic): ?>
                <?php $tpDisplay = diagnostic_exam_format_score_percent($topic['score_pct']); ?>
                <div class="cdr-topic-row">
                  <div class="cdr-topic-row__head">
                    <span class="cdr-topic-row__label"><?php echo h((string)$topic['label']); ?></span>
                    <span class="cdr-topic-row__pct"><?php echo h($tpDisplay); ?></span>
                  </div>
                  <div class="cdr-topic-row__bar" aria-hidden="true">
                    <span class="cdr-topic-row__fill cdr-topic-row__fill--<?php echo h((string)$topic['band']['state']); ?>" style="width:<?php echo max(0, min(100, (float)$topic['score_pct'])); ?>%"></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php elseif (!$analytics['has_topics'] && !empty($subj['questions'])): ?>
            <div class="cdr-topic-block">
              <h3 class="cdr-topic-block__title">Question breakdown</h3>
              <ul class="cdr-q-mini-list">
                <?php $qi = 1; foreach ($subj['questions'] as $qmini): ?>
                  <li class="cdr-q-mini cdr-q-mini--<?php echo !$qmini['is_answered'] ? 'miss' : ($qmini['is_correct'] ? 'ok' : 'bad'); ?>">
                    <span class="cdr-q-mini__num">Q<?php echo str_pad((string)$qi, 2, '0', STR_PAD_LEFT); ?></span>
                    <span class="cdr-q-mini__text"><?php echo h((string)$qmini['preview']); ?></span>
                    <span class="cdr-q-mini__status"><?php echo !$qmini['is_answered'] ? 'Unanswered' : ($qmini['is_correct'] ? 'Correct' : 'Incorrect'); ?></span>
                  </li>
                  <?php $qi++; ?>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php endif; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($analytics['show_insights'])): ?>
  <section class="cdr-insights" aria-labelledby="cdr-insights-title">
    <h2 class="cdr-section-title" id="cdr-insights-title">Performance insights</h2>
    <div class="cdr-insights__grid">
      <?php if (!empty($analytics['strongest_subject']) && (int)$analytics['strongest_subject']['total'] > 0): ?>
        <?php $ss = $analytics['strongest_subject']; ?>
        <article class="cdr-insight-card cdr-insight-card--strong">
          <span class="cdr-insight-card__icon"><i class="bi bi-star-fill"></i></span>
          <p class="cdr-insight-card__k">Strongest subject</p>
          <p class="cdr-insight-card__v"><?php echo h((string)($ss['subject_code'] !== '' ? $ss['subject_code'] : $ss['subject_name'])); ?></p>
          <p class="cdr-insight-card__pct"><?php echo h(diagnostic_exam_format_score_percent($ss['score_pct'])); ?></p>
        </article>
      <?php endif; ?>
      <?php if (!empty($analytics['weakest_subject']) && (int)$analytics['weakest_subject']['total'] > 0): ?>
        <?php
          $ws = $analytics['weakest_subject'];
          $hideWeakest = !empty($analytics['strongest_subject'])
              && (int)$ws['subject_id'] === (int)$analytics['strongest_subject']['subject_id']
              && count(array_filter($analytics['subjects'], static fn($s) => (int)$s['total'] > 0)) < 2;
        ?>
        <?php if (!$hideWeakest): ?>
        <article class="cdr-insight-card cdr-insight-card--weak">
          <span class="cdr-insight-card__icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
          <p class="cdr-insight-card__k">Needs most review</p>
          <p class="cdr-insight-card__v"><?php echo h((string)($ws['subject_code'] !== '' ? $ws['subject_code'] : $ws['subject_name'])); ?></p>
          <p class="cdr-insight-card__pct"><?php echo h(diagnostic_exam_format_score_percent($ws['score_pct'])); ?></p>
        </article>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($analytics['priority_topic']) && (int)$analytics['priority_topic']['total'] > 0): ?>
        <?php $pt = $analytics['priority_topic']; ?>
        <article class="cdr-insight-card cdr-insight-card--focus">
          <span class="cdr-insight-card__icon"><i class="bi bi-bullseye"></i></span>
          <p class="cdr-insight-card__k">Priority topic</p>
          <p class="cdr-insight-card__v"><?php echo h((string)$pt['label']); ?></p>
          <p class="cdr-insight-card__pct"><?php echo h(diagnostic_exam_format_score_percent($pt['score_pct'])); ?></p>
        </article>
      <?php elseif (!empty($analytics['weakest_subject']) && empty($analytics['priority_topic'])): ?>
        <?php $ws = $analytics['weakest_subject']; ?>
        <article class="cdr-insight-card cdr-insight-card--focus">
          <span class="cdr-insight-card__icon"><i class="bi bi-bullseye"></i></span>
          <p class="cdr-insight-card__k">Priority focus</p>
          <p class="cdr-insight-card__v"><?php echo h((string)($ws['subject_code'] !== '' ? $ws['subject_code'] : $ws['subject_name'])); ?></p>
          <p class="cdr-insight-card__pct"><?php echo h(diagnostic_exam_format_score_percent($ws['score_pct'])); ?></p>
        </article>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="cdr-info cer-split__panel" aria-labelledby="cdr-info-title">
    <h2 class="cdr-section-title" id="cdr-info-title">Diagnostic information</h2>
    <dl class="cer-info-grid">
      <div class="cer-info-item"><dt><i class="bi bi-person"></i> Student</dt><dd><?php echo h($studentName); ?></dd></div>
      <div class="cer-info-item"><dt><i class="bi bi-clipboard2-pulse"></i> Examination</dt><dd><?php echo h($batchTitle); ?></dd></div>
      <div class="cer-info-item"><dt><i class="bi bi-person-badge"></i> Professor</dt><dd><?php echo h($profName); ?></dd></div>
      <div class="cer-info-item"><dt><i class="bi bi-hourglass-split"></i> Duration</dt><dd><?php echo h($durationLabel); ?></dd></div>
      <div class="cer-info-item"><dt><i class="bi bi-list-ol"></i> Total questions</dt><dd><?php echo (int)$analytics['total']; ?></dd></div>
      <div class="cer-info-item"><dt><i class="bi bi-pencil-square"></i> Questions answered</dt><dd><?php echo (int)$answeredC; ?></dd></div>
      <div class="cer-info-item"><dt><i class="bi bi-play-circle"></i> Date started</dt><dd><?php echo h($startedLabel); ?></dd></div>
      <div class="cer-info-item"><dt><i class="bi bi-send-check"></i> Date submitted</dt><dd><?php echo h($submittedLabel); ?></dd></div>
      <?php if ($timeUsedSec !== null): ?>
      <div class="cer-info-item"><dt><i class="bi bi-stopwatch"></i> Time used</dt><dd><?php echo h($timeUsedLabel); ?></dd></div>
      <?php endif; ?>
    </dl>
  </section>

  <?php if ($questions !== []): ?>
  <section class="cer-review-full cdr-review" id="cdr-answer-review" aria-labelledby="cdr-review-title">
    <h2 class="cer-review-full__title" id="cdr-review-title">Answer review</h2>
    <div class="cer-review-full__list">
      <?php $i = 1; foreach ($questions as $q): ?>
        <?php
          $displayChoices = diagnostic_take_question_display_choices($q);
          $qid = (int)($q['question_id'] ?? 0);
          $sel = strtoupper(trim((string)($answersMap[$qid]['selected_answer'] ?? '')));
          $hasAns = $sel !== '';
          $correctLetter = strtoupper(trim((string)($q['correct_answer'] ?? '')));
          $isQCorrect = $hasAns && $sel === $correctLetter;
          $cardState = !$hasAns ? 'unanswered' : ($isQCorrect ? 'correct' : 'incorrect');
          $yourText = $hasAns ? cdr_review_choice_text($displayChoices, $sel) : '—';
          $correctText = $correctLetter !== '' ? cdr_review_choice_text($displayChoices, $correctLetter) : '—';
          $subjCode = trim((string)($q['_subject_code'] ?? ''));
        ?>
        <article class="cer-q-card cer-q-card--<?php echo h($cardState); ?>">
          <div class="cer-q-card__head">
            <span class="cer-q-card__num">
              Question <?php echo str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?>
              <?php if ($subjCode !== ''): ?><span class="diag-subject-chip"><?php echo h($subjCode); ?></span><?php endif; ?>
            </span>
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
        </article>
        <?php $i++; ?>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <footer class="cer-page-foot cdr-page-foot">
    <a href="college_exams" class="cer-page-foot__back"><i class="bi bi-arrow-left"></i> Back to examinations</a>
    <div class="cer-page-foot__actions">
      <?php if ($questions !== []): ?>
        <a href="#cdr-answer-review" class="cer-page-foot__btn cer-page-foot__btn--ghost">Review answers</a>
      <?php endif; ?>
    </div>
  </footer>

</section>
