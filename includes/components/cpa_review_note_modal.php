<?php
/**
 * Note modal for lesson viewer / notes page.
 * Expects $csrfToken; optional prefill: $cpaNoteSubjectId, $cpaNoteLessonId, $cpaNoteLessonTitle
 */
$csrfToken = $csrfToken ?? (function_exists('generateCSRFToken') ? generateCSRFToken() : '');
$cpaNoteSubjectId = (int) ($cpaNoteSubjectId ?? 0);
$cpaNoteLessonId = (int) ($cpaNoteLessonId ?? 0);
$cpaNoteLessonTitle = (string) ($cpaNoteLessonTitle ?? '');
$apiUrl = function_exists('ereview_url') ? ereview_url('student_cpa_review_api') : 'student_cpa_review_api';
?>
<div id="cpa-note-modal" class="cpa-modal" hidden aria-hidden="true">
  <div class="cpa-modal__backdrop" data-cpa-close></div>
  <div class="cpa-modal__panel" role="dialog" aria-labelledby="cpa-note-modal-title">
    <div class="cpa-modal__head">
      <h2 id="cpa-note-modal-title" class="m-0 text-lg font-bold text-[#143D59]">Add note</h2>
      <button type="button" class="cpa-modal__close" data-cpa-close aria-label="Close">&times;</button>
    </div>
    <form id="cpa-note-form" class="cpa-modal__body">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
      <input type="hidden" name="note_id" id="cpa-note-id" value="0">
      <input type="hidden" name="subject_id" id="cpa-note-subject" value="<?php echo $cpaNoteSubjectId; ?>">
      <input type="hidden" name="lesson_id" id="cpa-note-lesson" value="<?php echo $cpaNoteLessonId; ?>">
      <?php if ($cpaNoteLessonTitle !== ''): ?>
        <p class="text-xs text-[#64748b] mb-2">Lesson: <strong><?php echo h($cpaNoteLessonTitle); ?></strong></p>
      <?php endif; ?>
      <label class="block text-xs font-bold uppercase text-[#64748b] mb-1">Title</label>
      <input type="text" name="title" id="cpa-note-title" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm mb-3" placeholder="Note title" maxlength="255">
      <label class="block text-xs font-bold uppercase text-[#64748b] mb-1">Content</label>
      <div class="cpa-editor-toolbar mb-1 flex flex-wrap gap-1">
        <button type="button" data-cmd="bold" class="cpa-ed-btn" title="Bold"><b>B</b></button>
        <button type="button" data-cmd="italic" class="cpa-ed-btn" title="Italic"><i>I</i></button>
        <button type="button" data-cmd="underline" class="cpa-ed-btn" title="Underline"><u>U</u></button>
        <button type="button" data-cmd="insertUnorderedList" class="cpa-ed-btn" title="List">• List</button>
        <button type="button" data-cmd="formatBlock" data-value="h3" class="cpa-ed-btn" title="Heading">H</button>
        <button type="button" data-cmd="createLink" class="cpa-ed-btn" title="Link">Link</button>
      </div>
      <div id="cpa-note-content" class="cpa-editor" contenteditable="true" role="textbox" aria-multiline="true"></div>
      <label class="block text-xs font-bold uppercase text-[#64748b] mt-3 mb-1">Tags</label>
      <input type="text" name="tags" id="cpa-note-tags" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm" placeholder="e.g. FAR, inventory">
      <label class="inline-flex items-center gap-2 mt-3 text-sm text-[#143D59]">
        <input type="checkbox" name="is_starred" id="cpa-note-starred" value="1"> Star this note
      </label>
      <p id="cpa-note-status" class="text-xs text-[#64748b] mt-2 mb-0" aria-live="polite"></p>
      <div class="flex flex-wrap justify-end gap-2 mt-4">
        <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold border border-[#1665A0]/25" data-cpa-close>Cancel</button>
        <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold bg-[#1665A0] text-white">Save note</button>
      </div>
    </form>
  </div>
</div>
<script>
window.CPA_REVIEW = window.CPA_REVIEW || {};
window.CPA_REVIEW.apiUrl = <?php echo json_encode($apiUrl); ?>;
window.CPA_REVIEW.csrf = <?php echo json_encode($csrfToken); ?>;
</script>
