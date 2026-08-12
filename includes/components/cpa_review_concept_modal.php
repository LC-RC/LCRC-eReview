<?php
/**
 * Quick-add Important Concept modal (lesson context).
 * Vars: $csrfToken, $cpaNoteSubjectId, $cpaNoteLessonId, $cpaNoteLessonTitle
 */
$csrfToken = $csrfToken ?? (function_exists('generateCSRFToken') ? generateCSRFToken() : '');
$cpaNoteSubjectId = (int) ($cpaNoteSubjectId ?? 0);
$cpaNoteLessonId = (int) ($cpaNoteLessonId ?? 0);
$cpaNoteLessonTitle = (string) ($cpaNoteLessonTitle ?? '');
?>
<div id="cpa-concept-modal" class="cpa-modal" hidden aria-hidden="true">
  <div class="cpa-modal__backdrop" data-cpa-concept-close></div>
  <div class="cpa-modal__panel" role="dialog" aria-labelledby="cpa-concept-modal-title">
    <div class="cpa-modal__head">
      <h2 id="cpa-concept-modal-title" class="m-0 text-lg font-bold text-[#143D59]">Mark as important</h2>
      <button type="button" class="cpa-modal__close" data-cpa-concept-close aria-label="Close">&times;</button>
    </div>
    <form id="cpa-concept-form" class="cpa-modal__body">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
      <input type="hidden" name="subject_id" id="cpa-concept-subject" value="<?php echo $cpaNoteSubjectId; ?>">
      <input type="hidden" name="lesson_id" id="cpa-concept-lesson" value="<?php echo $cpaNoteLessonId; ?>">
      <?php if ($cpaNoteLessonTitle !== ''): ?>
        <p class="text-xs text-[#64748b] mb-2">From lesson: <strong><?php echo h($cpaNoteLessonTitle); ?></strong></p>
      <?php endif; ?>
      <label class="block text-xs font-bold uppercase text-[#64748b] mb-1">Concept title</label>
      <input type="text" name="title" id="cpa-concept-title" required maxlength="255" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm mb-3" value="<?php echo h($cpaNoteLessonTitle); ?>" placeholder="e.g. Lower of Cost and NRV">
      <label class="block text-xs font-bold uppercase text-[#64748b] mb-1">Topic</label>
      <input type="text" name="topic" id="cpa-concept-topic" maxlength="255" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm mb-3" value="<?php echo h($cpaNoteLessonTitle); ?>" placeholder="e.g. Inventories">
      <label class="block text-xs font-bold uppercase text-[#64748b] mb-1">Why is this important?</label>
      <textarea name="body" id="cpa-concept-body" rows="3" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm" placeholder="Short explanation for exam recall"></textarea>
      <label class="inline-flex items-center gap-2 mt-3 text-sm text-[#143D59]">
        <input type="checkbox" name="is_last_minute" id="cpa-concept-last-minute" value="1" checked> Pin to Last-Minute Review
      </label>
      <p id="cpa-concept-status" class="text-xs text-[#64748b] mt-2 mb-0" aria-live="polite"></p>
      <div class="flex flex-wrap justify-end gap-2 mt-4">
        <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold border border-[#1665A0]/25" data-cpa-concept-close>Cancel</button>
        <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold bg-[#1665A0] text-white">Save concept</button>
      </div>
    </form>
  </div>
</div>
