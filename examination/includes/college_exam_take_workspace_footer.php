<?php
/**
 * Shared bottom navigation + submit area for exam take pages.
 *
 * Expected variables:
 * - $qTotal (int)
 * - $examChromeSubmitLabel (string, optional)
 */
$examChromeSubmitLabel = isset($examChromeSubmitLabel) && $examChromeSubmitLabel !== ''
    ? (string)$examChromeSubmitLabel
    : 'Submit exam';
?>
<div class="exam-submit-area">
  <p class="exam-submit-area__status" id="examSubmitStatus" role="status">
    <span id="submitAnsweredNum">0</span> of <?php echo (int)$qTotal; ?> answered
  </p>
  <button type="button" id="submitExamBtn" class="exam-submit-area__btn exam-btn-submit focus-ring" aria-disabled="true" hidden>
    <i class="bi bi-send-fill" aria-hidden="true"></i> <span id="submitExamBtnText"><?php echo h($examChromeSubmitLabel); ?></span>
  </button>
  <p id="submitIncompleteHint" class="exam-submit-hint hidden" role="status"></p>
</div>
<nav class="exam-nav-bottom" id="examActionBar" aria-label="Question navigation">
  <button type="button" id="examPrevBtn" class="exam-nav-bottom__btn exam-nav-bottom__btn--ghost" disabled aria-label="Previous question">
    <i class="bi bi-arrow-left" aria-hidden="true"></i><span>Previous</span>
  </button>
  <div class="exam-nav-bottom__center" aria-live="polite">
    Question <span id="examNavCurrentLabel">1</span> of <?php echo (int)$qTotal; ?>
  </div>
  <button type="button" id="examNextBtn" class="exam-nav-bottom__btn exam-nav-bottom__btn--ghost" aria-label="Next question">
    <span>Next</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
  </button>
</nav>
