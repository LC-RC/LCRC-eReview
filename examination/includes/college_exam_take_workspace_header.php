<?php
/**
 * Shared exam-mode chrome header (regular + diagnostic take pages).
 *
 * Expected variables:
 * - $examChromeTitle (string)
 * - $examChromeSubtitle (string)
 * - $qTotal (int)
 * - $timerInitial (?int)
 * - $timerCircumference (string|float)
 * - $examChromeExitHref (string, optional, default college_exams)
 * - $examChromeSubmitLabel (string, optional, default Submit exam)
 */
$examChromeExitHref = isset($examChromeExitHref) && $examChromeExitHref !== ''
    ? (string)$examChromeExitHref
    : 'college_exams';
$examChromeSubmitLabel = isset($examChromeSubmitLabel) && $examChromeSubmitLabel !== ''
    ? (string)$examChromeSubmitLabel
    : 'Submit exam';
?>
<header class="exam-mode-chrome" aria-label="Exam controls">
  <div class="exam-mode-chrome__top">
    <button type="button" id="examExitBtn" class="exam-mode-chrome__exit focus-ring" data-exit-href="<?php echo h($examChromeExitHref); ?>" aria-label="Exit exam">
      <i class="bi bi-arrow-left" aria-hidden="true"></i>
      <span class="exam-mode-chrome__exit-text">Exit</span>
    </button>
    <div class="exam-mode-chrome__title-wrap">
      <h1 class="exam-mode-chrome__title"><?php echo h($examChromeTitle); ?></h1>
      <?php if (!empty($examChromeSubtitle)): ?>
        <p class="exam-mode-chrome__subtitle"><?php echo h($examChromeSubtitle); ?></p>
      <?php endif; ?>
    </div>
    <div class="exam-mode-chrome__timer exam-workspace-timer-compact" id="examTimerCompactWrap" aria-live="polite">
      <span class="exam-workspace-timer-compact__label">Time</span>
      <span id="examTimerCompact" class="exam-workspace-timer-compact__value">--:--</span>
    </div>
  </div>
  <div class="exam-mode-chrome__progress">
    <div class="exam-mode-chrome__progress-head">
      <p class="exam-mode-chrome__counter">Question <span id="examCurrentLabel">1</span> of <?php echo (int)$qTotal; ?></p>
      <button type="button" id="examQuestionsBtn" class="exam-mode-chrome__questions-btn focus-ring" aria-expanded="false" aria-controls="examQnavDrawer">
        <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
        <span>Questions</span>
      </button>
    </div>
    <div class="exam-progress-bar" aria-hidden="true"><div id="progressBar" class="exam-progress-fill" style="width:0%"></div></div>
    <p class="exam-mode-chrome__stats" aria-live="polite"><strong id="answeredCountNum">0</strong> of <?php echo (int)$qTotal; ?> answered · <span id="flaggedCount">0</span> flagged</p>
  </div>
  <div class="exam-mode-chrome__desktop-timer exam-workspace-bar__timer exam-workspace-bar__timer--desktop">
    <p class="exam-timer-card__label">Time remaining</p>
    <div id="examTimerCircle" class="exam-timer-circle-wrap exam-timer-circle-wrap--header" data-initial="<?php echo $timerInitial !== null ? (int)$timerInitial : 0; ?>">
      <svg viewBox="0 0 120 120" aria-hidden="true">
        <circle class="exam-timer-circle-track" cx="60" cy="60" r="54"></circle>
        <circle id="examTimerCircleProgress" class="exam-timer-circle-progress" cx="60" cy="60" r="54"
          stroke-dasharray="<?php echo h((string)$timerCircumference); ?>"
          stroke-dashoffset="0"></circle>
      </svg>
      <div class="exam-timer-circle-inner">
        <div id="examTimerCircleValue" class="exam-timer-circle-value">--:--</div>
      </div>
    </div>
  </div>
</header>
