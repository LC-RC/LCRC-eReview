<?php

/** @var bool $isModalRender */
/** @var string $editSectionClass */
/** @var string $editSectionHeadingClass */
/** @var string $formAction */
/** @var string $csrf */
/** @var string $examType */
/** @var bool $isNew */
/** @var int $sourceId */
/** @var string $titleVal */
/** @var string $descVal */
/** @var string $availVal */
/** @var string $deadVal */
/** @var array $timeParts */
/** @var string $scopeVal */
/** @var array $sectionsVal */
/** @var array $suggestedSections */
/** @var bool $assignmentLocked */
/** @var array $examineeSearchResults */
/** @var array $userIdsVal */
/** @var array $subjectCatalog */
/** @var array $selectedSubjects */
/** @var array $questionsRequired */
/** @var bool $shuffleQ */
/** @var bool $shuffleC */
/** @var bool $shuffleMcq */
/** @var bool $shuffleTf */
/** @var bool $descMarkdown */
/** @var string $reviewFromVal */
/** @var string $reviewUntilVal */
/** @var string $sectionSelectOptionsHtml */
/** @var string $modeVal */
/** @var string|null $error */

$formClass = $isModalRender ? 'admin-modal-form' : 'space-y-4';

?>
<form method="post" id="examinationConfigForm" class="<?php echo h($formClass); ?>" action="<?php echo h($formAction); ?>">

  <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
  <input type="hidden" name="action" value="save_config">
  <input type="hidden" name="exam_type" value="<?php echo h($examType); ?>">
  <?php if ($isModalRender): ?>
    <input type="hidden" name="modal" value="1">
  <?php endif; ?>
  <?php if ($examType === 'diagnostic' && !$isNew): ?>
    <input type="hidden" name="batch_id" value="<?php echo (int)$sourceId; ?>">
  <?php elseif (!$isNew): ?>
    <input type="hidden" name="exam_id" value="<?php echo (int)$sourceId; ?>">
  <?php endif; ?>

  <?php if ($isModalRender): ?>
  <div class="admin-modal-form__body">
  <?php endif; ?>

  <?php if (!empty($error) && $isModalRender): ?>
    <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($error); ?></span>
    </div>
  <?php endif; ?>

  <section class="<?php echo h($editSectionClass); ?>">
    <h2 class="<?php echo h($editSectionHeadingClass); ?>">Basic information</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <label class="block">
        <span class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Title</span>
        <input class="w-full" type="text" name="title" required value="<?php echo h($titleVal); ?>">
      </label>
      <label class="block md:col-span-2">
        <span class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Description</span>
        <textarea class="w-full" name="description" rows="2"><?php echo h($descVal); ?></textarea>
      </label>
    </div>
  </section>

  <?php if ($isNew): ?>
  <section class="<?php echo h($editSectionClass); ?>">
    <h2 class="<?php echo h($editSectionHeadingClass); ?>">Exam type</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <?php foreach (['regular' => 'Regular Exam', 'diagnostic' => 'Diagnostic Exam'] as $typeKey => $typeLabel): ?>
        <label class="rounded-xl border p-3 cursor-pointer <?php echo $examType === $typeKey ? 'ring-2 ring-blue-500/40' : ''; ?>">
          <input type="radio" name="exam_type_choice" value="<?php echo h($typeKey); ?>" <?php echo $examType === $typeKey ? 'checked' : ''; ?> class="mr-2">
          <span class="font-semibold"><?php echo h($typeLabel); ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <p class="text-sm opacity-70 mt-3 mb-0">Exam type is fixed after the examination is created.</p>
  </section>
  <?php endif; ?>

  <section class="<?php echo h($editSectionClass); ?>" id="examineePanel">
    <h2 class="<?php echo h($editSectionHeadingClass); ?>">Examinee type</h2>
    <div class="flex flex-wrap gap-4">
      <?php foreach (['college_student' => 'College Student', 'reviewee' => 'Reviewee', 'both' => 'Both'] as $sk => $sl): ?>
        <label class="font-semibold"><input type="radio" name="examinee_scope" value="<?php echo h($sk); ?>" <?php echo $scopeVal === $sk ? 'checked' : ''; ?>> <?php echo h($sl); ?></label>
      <?php endforeach; ?>
    </div>
    <p class="text-sm opacity-70 mt-3 mb-0" id="scopeHelp">College students can be assigned by section. Reviewees are assigned individually or as all reviewees — not by college section.</p>
  </section>

  <section class="<?php echo h($editSectionClass); ?>" id="assignmentPanel">
    <h2 class="<?php echo h($editSectionHeadingClass); ?>">Audience / Assignment</h2>

    <div id="assignmentCollegeStudent" class="hidden">
      <p class="text-sm font-semibold mb-2">College students</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-3">
        <label class="font-semibold"><input type="radio" name="assignment_mode" value="all" data-assignment-option> All students</label>
        <label class="font-semibold"><input type="radio" name="assignment_mode" value="sections" data-assignment-option> By section</label>
        <label class="font-semibold"><input type="radio" name="assignment_mode" value="users" data-assignment-option> Selected students</label>
        <label class="font-semibold"><input type="radio" name="assignment_mode" value="sections_and_users" data-assignment-option> Sections + selected students</label>
      </div>
    </div>

    <div id="assignmentReviewee" class="hidden">
      <p class="text-sm font-semibold mb-2">Reviewees</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-3">
        <label class="font-semibold"><input type="radio" name="assignment_mode" value="all" data-assignment-option> All reviewees</label>
        <label class="font-semibold"><input type="radio" name="assignment_mode" value="users" data-assignment-option> Selected reviewees</label>
      </div>
      <p class="text-sm opacity-70 mb-0">Section-based assignment does not apply to reviewees.</p>
    </div>

    <div id="assignmentBoth" class="hidden">
      <p class="text-sm opacity-70 mb-2">Assign college students and reviewees separately. Sections apply only to college students; selected individuals can include reviewees.</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-3">
        <label class="font-semibold"><input type="radio" name="assignment_mode" value="all" data-assignment-option> All students and all reviewees</label>
        <label class="font-semibold"><input type="radio" name="assignment_mode" value="sections_and_users" data-assignment-option> By section (students) + selected individuals</label>
        <label class="font-semibold"><input type="radio" name="assignment_mode" value="users" data-assignment-option> Selected individuals only</label>
      </div>
    </div>

    <div id="sectionsBlock" class="hidden mt-4">
      <span class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">College student sections</span>
      <p class="text-sm opacity-70 mt-0 mb-2">Choose from centralized Sections. Only college students with these sections will be included. Reviewees are never matched by section.</p>
      <?php if ($suggestedSections === []): ?>
        <p class="text-sm text-amber-700 mb-2">No active sections yet. Create sections under <a class="underline font-semibold" href="professor_college_sections">Sections</a> first.</p>
      <?php endif; ?>
      <div id="sectionsList">
        <?php foreach ($sectionsVal as $idx => $sec): ?>
          <div class="flex gap-2 mb-2 section-row">
            <select class="flex-1" name="sections[]" <?php echo $assignmentLocked ? 'disabled data-locked="1"' : ''; ?>>
              <option value="">Select section</option>
              <?php foreach ($suggestedSections as $sg): ?>
                <?php $sg = trim((string) $sg); if ($sg === '') continue; ?>
                <option value="<?php echo h($sg); ?>" <?php echo trim((string) $sec) === $sg ? 'selected' : ''; ?>><?php echo h($sg); ?></option>
              <?php endforeach; ?>
              <?php
                $secTrim = trim((string) $sec);
                if ($secTrim !== '' && !in_array($secTrim, $suggestedSections, true)):
              ?>
                <option value="<?php echo h($secTrim); ?>" selected><?php echo h($secTrim); ?> (legacy)</option>
              <?php endif; ?>
            </select>
            <?php if ($idx > 0 && !$assignmentLocked): ?><button type="button" class="admin-btn admin-btn--ghost admin-btn--sm remove-section">Remove</button><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$assignmentLocked): ?>
      <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm mt-1" id="addSectionBtn"><i class="bi bi-plus"></i> Add section</button>
      <?php endif; ?>
    </div>

    <div id="usersBlock" class="hidden mt-4">
      <span class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1" id="usersBlockLabel">Selected individuals</span>
      <div class="max-h-56 overflow-y-auto rounded-xl border p-3 page-table">
        <?php if ($examineeSearchResults === []): ?>
          <p class="text-sm m-0 opacity-70">No accounts match the current examinee type.</p>
        <?php else: ?>
          <?php foreach ($examineeSearchResults as $eu): ?>
            <?php
              $euid = (int)($eu['user_id'] ?? 0);
              $euRt = strtolower(trim((string)($eu['review_type'] ?? 'reviewee')));
              $euType = ($euRt === 'undergrad') ? 'Student' : 'Reviewee';
              $euSection = trim((string)($eu['section'] ?? ''));
              $euStudentNum = trim((string)($eu['student_number'] ?? ''));
              $euLabel = (string)($eu['full_name'] ?? '');
              if ($euStudentNum !== '') {
                $euLabel .= ' — ' . $euStudentNum;
              }
              if ($euSection !== '') {
                $euLabel .= ' — ' . $euSection;
              } elseif ($euType === 'Student') {
                $euLabel .= ' — (no section)';
              }
            ?>
            <label class="flex items-center gap-2 py-1 text-sm">
              <input type="checkbox" name="user_ids[]" value="<?php echo $euid; ?>" <?php echo in_array($euid, $userIdsVal, true) ? 'checked' : ''; ?>>
              <span><?php echo h($euLabel); ?></span>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($examType === 'diagnostic'): ?>
  <section class="<?php echo h($editSectionClass); ?>" id="subjectsPanel">
    <h2 class="<?php echo h($editSectionHeadingClass); ?>">Subjects</h2>
    <p class="text-sm opacity-70 mt-0 mb-3">Select subjects and optional question caps (0 = use all authored questions per subject). Detailed question authoring is in the Questions step.</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
      <?php foreach ($subjectCatalog as $sub): ?>
        <?php $sid = (int)($sub['subject_id'] ?? 0); ?>
        <div class="rounded-xl border p-3 page-table">
          <label class="font-semibold">
            <input type="checkbox" name="subject_ids[]" value="<?php echo $sid; ?>" <?php echo in_array($sid, $selectedSubjects, true) ? 'checked' : ''; ?>>
            <?php echo h((string)($sub['subject_code'] ?? '')); ?> — <?php echo h((string)($sub['subject_name'] ?? '')); ?>
          </label>
          <label class="block text-xs mt-2 opacity-70">Questions to use
            <input class="w-full mt-1" type="number" min="0" name="questions_required[<?php echo $sid; ?>]" value="<?php echo (int)($questionsRequired[$sid] ?? 0); ?>">
          </label>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="<?php echo h($editSectionClass); ?>">
    <h2 class="<?php echo h($editSectionHeadingClass); ?>">Schedule &amp; Access</h2>
    <div class="examination-form-section">
      <p class="examination-form-section__title">Exam availability</p>
      <p class="examination-form-hint">When students may start the exam.</p>
      <p class="examination-form-hint examination-form-hint--secondary">Students can access the examination only within this availability window.</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
        <label class="<?php echo $isModalRender ? 'admin-modal__field ' : ''; ?>block">
          <span class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Opens</span>
          <input class="w-full" type="datetime-local" name="available_from" id="cfgAvailableFrom" value="<?php echo h($availVal); ?>">
        </label>
        <label class="<?php echo $isModalRender ? 'admin-modal__field ' : ''; ?>block">
          <span class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Closes</span>
          <input class="w-full" type="datetime-local" name="deadline" id="cfgDeadline" value="<?php echo h($deadVal); ?>">
        </label>
      </div>
    </div>
    <div class="examination-form-section mt-4">
      <p class="examination-form-section__title">Time limit</p>
      <p class="examination-form-hint">How long they may take the exam after starting.</p>
      <p class="examination-form-hint examination-form-hint--secondary">Maximum time allowed after a student starts the examination. This may end earlier if the exam closes first.</p>
      <div class="grid grid-cols-2 gap-3 mt-2">
        <label class="<?php echo $isModalRender ? 'admin-modal__field ' : ''; ?>block">
          <span class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Hours</span>
          <input class="w-full" type="number" min="0" max="999" name="time_limit_hours" id="cfgTimeLimitHours" value="<?php echo (int)$timeParts['hours']; ?>">
        </label>
        <label class="<?php echo $isModalRender ? 'admin-modal__field ' : ''; ?>block">
          <span class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Minutes</span>
          <input class="w-full" type="number" min="0" max="59" name="time_limit_minutes" id="cfgTimeLimitMinutes" value="<?php echo (int)$timeParts['minutes']; ?>">
        </label>
      </div>
    </div>
  </section>

  <section class="<?php echo h($editSectionClass); ?>">
    <h2 class="<?php echo h($editSectionHeadingClass); ?>">Advanced Options</h2>
    <div class="flex flex-col gap-2">
      <label class="font-semibold text-sm"><input type="checkbox" name="shuffle_questions" id="cfgShuffleQ" value="1" <?php echo $shuffleQ ? 'checked' : ''; ?>> Shuffle questions</label>
      <label class="font-semibold text-sm"><input type="checkbox" name="shuffle_choices" id="cfgShuffleC" value="1" <?php echo $shuffleC ? 'checked' : ''; ?>> Shuffle choices</label>
      <?php if ($examType === 'regular'): ?>
      <label class="font-semibold text-sm"><input type="checkbox" name="shuffle_mcq_questions" id="cfgShuffleMcq" value="1" <?php echo $shuffleMcq ? 'checked' : ''; ?>> Shuffle MCQ questions</label>
      <label class="font-semibold text-sm"><input type="checkbox" name="shuffle_tf_questions" id="cfgShuffleTf" value="1" <?php echo $shuffleTf ? 'checked' : ''; ?>> Shuffle True/False questions</label>
      <label class="font-semibold text-sm"><input type="checkbox" name="description_markdown" id="cfgDescMarkdown" value="1" <?php echo $descMarkdown ? 'checked' : ''; ?>> Description supports Markdown</label>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($examType === 'regular'): ?>
  <section class="<?php echo h($editSectionClass); ?>">
    <h2 class="<?php echo h($editSectionHeadingClass); ?>">Review Sheet</h2>
    <p class="examination-form-hint examination-form-hint--secondary">Controls when students can access the submitted-question review.</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
      <label class="<?php echo $isModalRender ? 'admin-modal__field ' : ''; ?>block">
        <span class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Available from</span>
        <input class="w-full" type="datetime-local" name="review_sheet_available_from" id="cfgReviewFrom" value="<?php echo h($reviewFromVal); ?>">
      </label>
      <label class="<?php echo $isModalRender ? 'admin-modal__field ' : ''; ?>block">
        <span class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Available until</span>
        <input class="w-full" type="datetime-local" name="review_sheet_available_until" id="cfgReviewUntil" value="<?php echo h($reviewUntilVal); ?>">
      </label>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($isModalRender && !$isNew): ?>
    <div class="examination-edit-modal__links">
      <a class="admin-btn admin-btn--ghost admin-btn--sm" href="<?php echo h(examination_domain_edit_url($examType, $sourceId, 'questions')); ?>"><i class="bi bi-question-circle"></i> Questions</a>
      <a class="admin-btn admin-btn--ghost admin-btn--sm" href="<?php echo h(examination_domain_edit_url($examType, $sourceId, 'review')); ?>"><i class="bi bi-check2-square"></i> Review / Publish</a>
    </div>
  <?php endif; ?>

  <?php if ($isModalRender): ?>
  </div>
  <div class="admin-modal__actions">
    <button type="button" class="admin-modal__btn admin-modal__btn--ghost" data-exam-edit-cancel>Cancel</button>
    <button type="submit" name="save_action" value="draft" class="admin-modal__btn admin-modal__btn--ok"><i class="bi bi-check2"></i> Save Changes</button>
  </div>
  <?php else: ?>
  <div class="flex flex-wrap gap-3 items-center">
    <button type="submit" name="save_action" value="draft" class="admin-btn admin-btn--secondary"><i class="bi bi-save"></i> Save Draft</button>
    <?php if (!$isNew): ?>
      <a href="<?php echo h(examination_domain_edit_url($examType, $sourceId, 'questions')); ?>" class="admin-btn admin-btn--ghost">Continue to Questions</a>
      <a href="<?php echo h(examination_domain_edit_url($examType, $sourceId, 'review')); ?>" class="admin-btn admin-btn--ghost">Review / Publish</a>
    <?php endif; ?>
    <a href="professor_examinations" class="admin-btn admin-btn--ghost">Cancel</a>
  </div>
  <p class="text-sm opacity-70">Saving a draft does not require questions. Full publish validation for question banks is deferred to a later phase.</p>
  <?php endif; ?>

</form>

<script>
(function(){

  var form = document.getElementById('examinationConfigForm');
  if (!form) return;

  var examType = <?php echo json_encode($examType); ?>;
  var initialMode = <?php echo json_encode($modeVal); ?>;
  var isModalRender = <?php echo $isModalRender ? 'true' : 'false'; ?>;

  function scope(){ var r = form.querySelector('input[name="examinee_scope"]:checked'); return r ? r.value : 'college_student'; }
  function mode(){
    var sc = scope();
    var groupId = sc === 'reviewee' ? 'assignmentReviewee' : (sc === 'both' ? 'assignmentBoth' : 'assignmentCollegeStudent');
    var group = document.getElementById(groupId);
    var r = group ? group.querySelector('input[name="assignment_mode"]:checked:not([disabled])') : null;
    if (!r) r = form.querySelector('input[name="assignment_mode"]:checked:not([disabled])');
    return r ? r.value : 'all';
  }

  function setCheckedMode(value) {
    var sc = scope();
    var groupId = sc === 'reviewee' ? 'assignmentReviewee' : (sc === 'both' ? 'assignmentBoth' : 'assignmentCollegeStudent');
    var group = document.getElementById(groupId);
    if (!group) return;
    var radios = group.querySelectorAll('input[name="assignment_mode"]');
    var matched = false;
    radios.forEach(function(r){
      if (r.value === value) { r.checked = true; matched = true; }
    });
    if (!matched && radios.length) radios[0].checked = true;
  }

  function syncAssignmentGroups() {
    var sc = scope();
    var groups = {
      assignmentCollegeStudent: sc === 'college_student',
      assignmentReviewee: sc === 'reviewee',
      assignmentBoth: sc === 'both'
    };
    Object.keys(groups).forEach(function(id){
      var el = document.getElementById(id);
      if (!el) return;
      var active = !!groups[id];
      el.classList.toggle('hidden', !active);
      el.querySelectorAll('input[name="assignment_mode"]').forEach(function(r){
        r.disabled = !active;
        if (!active) r.checked = false;
      });
    });
    var current = mode();
    if (sc === 'reviewee' && (current === 'sections' || current === 'sections_and_users')) {
      setCheckedMode('all');
    } else if (!form.querySelector('#' + (sc === 'reviewee' ? 'assignmentReviewee' : (sc === 'both' ? 'assignmentBoth' : 'assignmentCollegeStudent')) + ' input[name="assignment_mode"]:checked')) {
      setCheckedMode(current || initialMode || 'all');
    }
  }

  function syncPanels(){
    syncAssignmentGroups();
    var sc = scope();
    var m = mode();
    var showSections = (sc === 'college_student' && (m === 'sections' || m === 'sections_and_users'))
      || (sc === 'both' && m === 'sections_and_users');
    var showUsers = (sc === 'college_student' && (m === 'users' || m === 'sections_and_users'))
      || (sc === 'reviewee' && m === 'users')
      || (sc === 'both' && (m === 'users' || m === 'sections_and_users'));
    document.getElementById('sectionsBlock').classList.toggle('hidden', !showSections);
    document.getElementById('usersBlock').classList.toggle('hidden', !showUsers);
    form.querySelectorAll('#sectionsBlock select[name="sections[]"]').forEach(function(sel){
      if (sel.dataset.locked === '1') return;
      sel.disabled = !showSections;
    });
    form.querySelectorAll('#usersBlock input[name="user_ids[]"]').forEach(function(cb){
      if (cb.dataset.locked === '1') return;
      cb.disabled = !showUsers;
    });
    var usersLabel = document.getElementById('usersBlockLabel');
    if (usersLabel) {
      usersLabel.textContent = sc === 'reviewee' ? 'Selected reviewees' : (sc === 'both' ? 'Selected individuals (students or reviewees)' : 'Selected students');
    }
  }

  form.querySelectorAll('input[name="assignment_mode"], input[name="examinee_scope"]').forEach(function(el){
    el.addEventListener('change', function(){
      if (el.name === 'examinee_scope') {
        if (typeof window.examinationEditModalReload === 'function') {
          window.examinationEditModalReload(el.value);
          return;
        }
        var url = new URL(window.location.href);
        url.searchParams.set('examinee_scope', el.value);
        window.location = url.toString();
        return;
      }
      syncPanels();
    });
  });

  var addBtn = document.getElementById('addSectionBtn');
  var sectionOptionsHtml = <?php echo json_encode($sectionSelectOptionsHtml, JSON_UNESCAPED_UNICODE); ?>;
  if (addBtn) {
    addBtn.addEventListener('click', function(){
      var row = document.createElement('div');
      row.className = 'flex gap-2 mb-2 section-row';
      row.innerHTML = '<select class="flex-1" name="sections[]">' + sectionOptionsHtml + '</select><button type="button" class="admin-btn admin-btn--ghost admin-btn--sm remove-section">Remove</button>';
      document.getElementById('sectionsList').appendChild(row);
    });
  }

  var sectionsList = document.getElementById('sectionsList');
  if (sectionsList) {
    sectionsList.addEventListener('click', function(e){
      if (e.target.classList.contains('remove-section')) e.target.closest('.section-row').remove();
    });
  }

  form.querySelectorAll('input[name="exam_type_choice"]').forEach(function(r){
    r.addEventListener('change', function(){
      if (!r.checked) return;
      var url = 'professor_examination_edit?exam_type=' + encodeURIComponent(r.value);
      if (isModalRender) url += '&modal=1';
      if (isModalRender && typeof window.examinationEditOpenUrl === 'function') {
        window.examinationEditOpenUrl(url);
        return;
      }
      window.location = url;
    });
  });

  setCheckedMode(initialMode);
  syncPanels();

})();
</script>
