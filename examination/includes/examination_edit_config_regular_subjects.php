<?php
/**
 * Regular exam question breakdown config panel.
 * Modes: overall | subject | subject_topic
 * POST: question_breakdown_mode, total_questions_required,
 *       reg_subjects[i][name|questions], reg_subjects[i][topics][j][name|questions]
 *
 * @var array $extras
 */
require_once __DIR__ . '/college_exam_subject_topic_helpers.php';
$regMode = college_exam_normalize_breakdown_mode((string)($extras['regular_breakdown_mode'] ?? 'overall'));
$regOverallTotal = max(0, (int)($extras['regular_total_questions_required'] ?? 0));
$regTree = is_array($extras['regular_subject_tree'] ?? null) ? $extras['regular_subject_tree'] : [];
$regSupply = is_array($extras['regular_topic_supply'] ?? null) ? $extras['regular_topic_supply'] : [];
$regTotal = (int)($regSupply['total_required'] ?? 0);
if ($regMode === 'overall') {
    $regTotal = $regOverallTotal;
} elseif ($regTotal <= 0 && $regTree !== []) {
    foreach ($regTree as $rs) {
        $regTotal += max(0, (int)($rs['questions_required'] ?? 0));
    }
}
?>
<style>
#regularSubjectsPanel.reg-bd.examination-form-section {
  background: transparent;
  border: none;
  box-shadow: none;
  padding: 0;
}
#regularSubjectsPanel.reg-bd { padding-bottom: 0.2rem; }
#regularSubjectsPanel .reg-bd-head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.85rem 1.5rem;
  margin-bottom: 0.95rem;
}
#regularSubjectsPanel .reg-bd-head h2 { margin: 0; }
#regularSubjectsPanel .reg-bd-head p { margin: 0.3rem 0 0; max-width: 38rem; color: #64748b; font-size: 0.84rem; }
#regularSubjectsPanel .reg-bd-total {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  min-width: 8.5rem;
}
#regularSubjectsPanel .reg-bd-total__k {
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: #64748b;
}
#regularSubjectsPanel .reg-bd-total__v {
  font-size: 1.7rem;
  font-weight: 800;
  letter-spacing: -0.04em;
  color: #0f2744;
  line-height: 1.05;
}
#regularSubjectsPanel .reg-bd-total__u {
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #475569;
}
#regularSubjectsPanel .reg-bd-contrib {
  list-style: none;
  margin: 0.35rem 0 0;
  padding: 0;
  min-width: 9rem;
}
#regularSubjectsPanel .reg-bd-contrib li {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  font-size: 0.78rem;
  font-weight: 650;
  color: #334155;
  line-height: 1.45;
}
#regularSubjectsPanel .reg-bd-contrib li span:last-child {
  font-variant-numeric: tabular-nums;
  color: #0f2744;
}
#regularSubjectsPanel .reg-bd-modes {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
  margin-bottom: 1rem;
}
#regularSubjectsPanel .reg-bd-mode {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  column-gap: 0.45rem;
  row-gap: 0.12rem;
  align-items: start;
  margin: 0;
  padding: 0.55rem 0.7rem 0.6rem;
  border-radius: 12px;
  border: 1px solid rgba(186, 210, 245, 0.75);
  background: #fff;
  cursor: pointer;
  box-shadow: 0 8px 18px -16px rgba(37, 76, 150, 0.28);
}
#regularSubjectsPanel .reg-bd-mode input {
  grid-row: 1 / span 2;
  margin-top: 0.18rem;
  accent-color: #2563eb;
}
#regularSubjectsPanel .reg-bd-mode__title {
  font-weight: 800;
  font-size: 0.78rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #0f2744;
}
#regularSubjectsPanel .reg-bd-mode__hint {
  grid-column: 2;
  font-size: 0.72rem;
  font-weight: 500;
  color: #64748b;
  line-height: 1.35;
}
#regularSubjectsPanel .reg-bd-mode:has(input:checked) {
  border-color: rgba(99, 102, 241, 0.55);
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.14), 0 10px 22px -16px rgba(37, 76, 150, 0.35);
}
#regularSubjectsPanel .reg-bd-mode:has(input:checked) .reg-bd-mode__title { color: #1d4ed8; }
#regularSubjectsPanel .reg-bd-subject {
  position: relative;
  margin: 0 0 0.85rem;
  padding: 0.95rem 1.05rem 1rem;
  border: 1px solid rgba(186, 210, 245, 0.55);
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 10px 24px -18px rgba(37, 76, 150, 0.28);
}
#regularSubjectsPanel .reg-bd-subject.is-invalid {
  border-color: rgba(248, 113, 113, 0.7);
  box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.08), 0 10px 24px -18px rgba(185, 28, 28, 0.18);
}
#regularSubjectsPanel .reg-bd-subject__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.7rem;
  padding-bottom: 0.55rem;
  border-bottom: 1px solid rgba(226, 232, 240, 0.95);
}
#regularSubjectsPanel .reg-bd-subject__label {
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: #64748b;
}
#regularSubjectsPanel .reg-bd-subject__quota {
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #1e3a5f;
  font-variant-numeric: tabular-nums;
}
#regularSubjectsPanel .reg-bd-subject__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 8.25rem auto;
  gap: 0.6rem 0.75rem;
  align-items: end;
}
#regularSubjectsPanel .reg-bd-field label,
#regularSubjectsPanel .reg-bd-field .reg-bd-k {
  display: block;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: #475569;
  margin-bottom: 0.22rem;
}
#regularSubjectsPanel .reg-bd-field input[type="text"],
#regularSubjectsPanel .reg-bd-field input[type="number"] {
  width: 100%;
  min-height: 2.45rem;
  border-radius: 10px;
  border: 1px solid rgba(147, 180, 230, 0.55);
  background: #fff;
  padding: 0.4rem 0.7rem;
  color: #0f2744;
}
#regularSubjectsPanel .reg-bd-field--qty input[type="number"] {
  width: 100%;
  min-width: 5.25rem;
  text-align: center;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  padding-left: 0.4rem;
  padding-right: 0.4rem;
}
#regularSubjectsPanel input[type="number"] {
  -moz-appearance: textfield;
  appearance: textfield;
}
#regularSubjectsPanel input[type="number"]::-webkit-inner-spin-button,
#regularSubjectsPanel input[type="number"]::-webkit-outer-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
#regularSubjectsPanel .reg-bd-field input:focus {
  outline: none;
  border-color: #60a5fa;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.16);
}
#regularSubjectsPanel .reg-bd-remove {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.45rem;
  height: 2.45rem;
  border-radius: 10px;
  border: 1px solid rgba(203, 213, 225, 0.95);
  background: #fff;
  color: #64748b;
  cursor: pointer;
}
#regularSubjectsPanel .reg-bd-remove:hover,
#regularSubjectsPanel .reg-bd-remove:focus-visible {
  background: #fef2f2;
  border-color: #fecaca;
  color: #b91c1c;
  outline: none;
}
#regularSubjectsPanel .reg-bd-topics {
  margin-top: 0.95rem;
  padding-top: 0.75rem;
  border-top: 1px solid rgba(226, 232, 240, 0.95);
}
#regularSubjectsPanel .reg-bd-topics__head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.4rem 1rem;
  margin-bottom: 0.55rem;
}
#regularSubjectsPanel .reg-bd-topics__title {
  display: block;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #1e3a5f;
}
#regularSubjectsPanel .reg-bd-topics__opt {
  display: block;
  margin-top: 0.15rem;
  font-size: 0.72rem;
  font-weight: 500;
  color: #64748b;
  line-height: 1.35;
}
#regularSubjectsPanel .reg-bd-alloc {
  font-size: 0.8rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: #475569;
}
#regularSubjectsPanel .reg-bd-alloc.is-complete { color: #047857; }
#regularSubjectsPanel .reg-bd-alloc.is-remaining { color: #b45309; }
#regularSubjectsPanel .reg-bd-alloc.is-over { color: #b91c1c; }
#regularSubjectsPanel .reg-bd-empty {
  margin: 0 0 0.55rem;
  padding: 0.15rem 0 0.1rem;
}
#regularSubjectsPanel .reg-bd-empty[hidden] { display: none; }
#regularSubjectsPanel .reg-bd-empty__title {
  margin: 0;
  font-size: 0.86rem;
  font-weight: 750;
  color: #0f2744;
}
#regularSubjectsPanel .reg-bd-empty__copy {
  margin: 0.2rem 0 0;
  font-size: 0.8rem;
  color: #64748b;
}
#regularSubjectsPanel .reg-bd-topic-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 5.75rem auto;
  gap: 0.45rem 0.55rem;
  align-items: end;
  margin-bottom: 0.45rem;
}
#regularSubjectsPanel .reg-bd-add-topic {
  margin-top: 0.15rem;
}
#regularSubjectsPanel .reg-bd-msg {
  margin: 0.45rem 0 0;
  font-size: 0.8rem;
  font-weight: 650;
}
#regularSubjectsPanel .reg-bd-msg.is-error { color: #b91c1c; }
#regularSubjectsPanel .reg-bd-msg.is-ok { color: #047857; }
#regularSubjectsPanel .reg-bd-summary { display: none; }
#regularSubjectsPanel .reg-bd-add { margin: 0.15rem 0 0.35rem; }
#regularSubjectsPanel .reg-bd-overall {
  max-width: 18rem;
  margin-bottom: 0.35rem;
}
@media (max-width: 900px) {
  #regularSubjectsPanel .reg-bd-modes { grid-template-columns: 1fr; }
}
@media (max-width: 700px) {
  #regularSubjectsPanel .reg-bd-total { align-items: flex-start; }
  #regularSubjectsPanel .reg-bd-subject__row,
  #regularSubjectsPanel .reg-bd-topic-row {
    grid-template-columns: minmax(0, 1fr) auto;
  }
  #regularSubjectsPanel .reg-bd-subject__row .reg-bd-field--qty,
  #regularSubjectsPanel .reg-bd-topic-row .reg-bd-field--qty {
    grid-column: 1 / 2;
  }
  #regularSubjectsPanel .reg-bd-subject__row .reg-bd-remove,
  #regularSubjectsPanel .reg-bd-topic-row .reg-bd-remove {
    grid-column: 2;
    grid-row: 2;
    align-self: end;
  }
}
</style>
<section class="<?php echo h($editSectionClass); ?> reg-bd" id="regularSubjectsPanel">
  <?php if (!empty($assignmentLocked)): ?>
    <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl text-sm">
      This examination already has student attempts. Question allocation and randomization settings are locked to preserve existing attempt data.
    </div>
  <?php endif; ?>
  <div class="reg-bd-head">
    <div>
      <h2 class="<?php echo h($editSectionHeadingClass); ?>">Question breakdown</h2>
      <p>Allocate the exam by subject. Topics are optional and divide a subject’s quota — they do not change the exam total.</p>
    </div>
    <div>
      <div class="reg-bd-total" aria-live="polite">
        <span class="reg-bd-total__k">Exam total</span>
        <span class="reg-bd-total__v" id="regTopicTotalQty"><?php echo (int)$regTotal; ?></span>
        <span class="reg-bd-total__u">questions</span>
      </div>
      <ul class="reg-bd-contrib" id="regExamContribution" hidden></ul>
    </div>
  </div>
  <input type="hidden" name="reg_subjects_present" value="1">

  <div class="reg-bd-modes" id="regBreakdownModeGroup" role="radiogroup" aria-label="Question breakdown mode">
    <label class="reg-bd-mode">
      <input type="radio" name="question_breakdown_mode" value="overall" <?php echo $regMode === 'overall' ? 'checked' : ''; ?> data-reg-mode>
      <span class="reg-bd-mode__title">Overall</span>
      <span class="reg-bd-mode__hint">Set only the total number of questions.</span>
    </label>
    <label class="reg-bd-mode">
      <input type="radio" name="question_breakdown_mode" value="subject" <?php echo $regMode === 'subject' ? 'checked' : ''; ?> data-reg-mode>
      <span class="reg-bd-mode__title">By Subject</span>
      <span class="reg-bd-mode__hint">Allocate questions across subjects.</span>
    </label>
    <label class="reg-bd-mode">
      <input type="radio" name="question_breakdown_mode" value="subject_topic" <?php echo $regMode === 'subject_topic' ? 'checked' : ''; ?> data-reg-mode>
      <span class="reg-bd-mode__title">By Subject &amp; Topic</span>
      <span class="reg-bd-mode__hint">Allocate by subject, with optional topic-level control.</span>
    </label>
  </div>

  <div id="regOverallPanel" <?php echo $regMode === 'overall' ? '' : 'hidden'; ?>>
    <div class="reg-bd-field reg-bd-overall">
      <label for="regTotalQuestionsRequired">Question allocation</label>
      <input type="number" min="1" step="1" id="regTotalQuestionsRequired" name="total_questions_required" value="<?php echo $regOverallTotal > 0 ? (int)$regOverallTotal : ''; ?>" placeholder="e.g. 100" data-reg-overall-qty>
    </div>
    <p class="reg-bd-topics__opt">The exam uses this total only. Subjects and topics are not used in Overall mode.</p>
  </div>

  <div id="regSubjectsPanelBody" <?php echo $regMode === 'overall' ? 'hidden' : ''; ?>>
    <div id="regSubjectList">
      <?php foreach ($regTree as $si => $subj):
          $subjQty = max(0, (int)($subj['questions_required'] ?? 0));
          $subjName = trim((string)($subj['subject_name'] ?? ''));
          $subjTopics = is_array($subj['topics'] ?? null) ? $subj['topics'] : [];
          $hasTopics = $subjTopics !== [];
          $addTopicLabel = $subjName !== '' ? ('+ Add topic to ' . $subjName) : '+ Add topic';
          $emptyCopy = $subjName !== ''
              ? ('Questions may be selected from the entire ' . $subjName . ' subject.')
              : 'Questions may be selected from the entire subject.';
      ?>
        <article class="reg-bd-subject" data-reg-subject>
          <div class="reg-bd-subject__head">
            <span class="reg-bd-subject__label">Subject <?php echo str_pad((string)((int)$si + 1), 2, '0', STR_PAD_LEFT); ?></span>
            <span class="reg-bd-subject__quota" data-reg-subject-quota><?php echo $subjQty > 0 ? ((int)$subjQty . ' questions') : '—'; ?></span>
          </div>
          <div class="reg-bd-subject__row">
            <div class="reg-bd-field">
              <span class="reg-bd-k">Subject</span>
              <input type="text" name="reg_subjects[<?php echo (int)$si; ?>][name]" value="<?php echo h($subjName); ?>" placeholder="e.g. TAX" data-reg-subject-name>
            </div>
            <div class="reg-bd-field reg-bd-field--qty">
              <label>Question allocation</label>
              <input type="number" min="1" step="1" name="reg_subjects[<?php echo (int)$si; ?>][questions]" value="<?php echo $subjQty > 0 ? (int)$subjQty : ''; ?>" placeholder="10" data-reg-subject-qty>
            </div>
            <button type="button" class="reg-bd-remove" data-reg-remove-subject aria-label="Remove subject" title="Remove subject"><i class="bi bi-trash" aria-hidden="true"></i></button>
          </div>
          <div class="reg-bd-topics" data-reg-topic-wrap <?php echo $regMode === 'subject_topic' ? '' : 'hidden'; ?>>
            <div class="reg-bd-topics__head">
              <div>
                <span class="reg-bd-topics__title">Topic allocation</span>
                <span class="reg-bd-topics__opt">Topics · optional. Leave topics empty to select from the entire subject.</span>
              </div>
              <span class="reg-bd-alloc" data-reg-alloc></span>
            </div>
            <div class="reg-bd-empty" data-reg-topic-empty <?php echo $hasTopics ? 'hidden' : ''; ?>>
              <p class="reg-bd-empty__title">No topics added</p>
              <p class="reg-bd-empty__copy" data-reg-empty-copy><?php echo h($emptyCopy); ?></p>
            </div>
            <div data-reg-topic-body>
              <?php foreach ($subjTopics as $ti => $topic): ?>
                <div class="reg-bd-topic-row" data-reg-topic>
                  <div class="reg-bd-field">
                    <span class="reg-bd-k">Topic</span>
                    <input type="text" name="reg_subjects[<?php echo (int)$si; ?>][topics][<?php echo (int)$ti; ?>][name]" value="<?php echo h((string)$topic['topic_name']); ?>" placeholder="Topic name" data-reg-topic-name aria-label="Topic name">
                  </div>
                  <div class="reg-bd-field reg-bd-field--qty">
                    <label>Questions</label>
                    <input type="number" min="1" step="1" name="reg_subjects[<?php echo (int)$si; ?>][topics][<?php echo (int)$ti; ?>][questions]" value="<?php echo max(0, (int)$topic['questions_required']) > 0 ? (int)$topic['questions_required'] : ''; ?>" placeholder="0" data-reg-topic-qty aria-label="Topic questions">
                  </div>
                  <button type="button" class="reg-bd-remove" data-reg-remove-topic aria-label="Remove topic" title="Remove topic"><i class="bi bi-trash" aria-hidden="true"></i></button>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm reg-bd-add-topic" data-reg-add-topic><?php echo h($addTopicLabel); ?></button>
            <p class="reg-bd-msg" data-reg-subject-msg hidden></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="reg-bd-add">
      <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" id="regAddSubjectBtn">+ Add another subject</button>
    </div>
  </div>

  <div class="reg-bd-summary" id="regBreakdownSummary" role="alert"></div>

  <?php if (!empty($regSupply['errors'])): ?>
    <div class="admin-flash admin-flash--error mt-3 p-3 rounded-xl text-sm">
      <div class="font-semibold mb-1">Question supply on this examination</div>
      <?php foreach ($regSupply['errors'] as $errLine): ?>
        <div><?php echo h((string)$errLine); ?></div>
      <?php endforeach; ?>
      <p class="mb-0 mt-2 text-xs opacity-80">These counts are authored questions already saved on this exam — not a separate question bank.</p>
    </div>
  <?php endif; ?>
</section>

<template id="regSubjectTemplate">
  <article class="reg-bd-subject" data-reg-subject>
    <div class="reg-bd-subject__head">
      <span class="reg-bd-subject__label">Subject</span>
      <span class="reg-bd-subject__quota" data-reg-subject-quota>—</span>
    </div>
    <div class="reg-bd-subject__row">
      <div class="reg-bd-field">
        <span class="reg-bd-k">Subject</span>
        <input type="text" name="reg_subjects[__SI__][name]" value="" placeholder="e.g. TAX" data-reg-subject-name>
      </div>
      <div class="reg-bd-field reg-bd-field--qty">
        <label>Question allocation</label>
        <input type="number" min="1" step="1" name="reg_subjects[__SI__][questions]" value="10" placeholder="10" data-reg-subject-qty>
      </div>
      <button type="button" class="reg-bd-remove" data-reg-remove-subject aria-label="Remove subject" title="Remove subject"><i class="bi bi-trash" aria-hidden="true"></i></button>
    </div>
    <div class="reg-bd-topics" data-reg-topic-wrap>
      <div class="reg-bd-topics__head">
        <div>
          <span class="reg-bd-topics__title">Topic allocation</span>
          <span class="reg-bd-topics__opt">Topics · optional. Leave topics empty to select from the entire subject.</span>
        </div>
        <span class="reg-bd-alloc" data-reg-alloc></span>
      </div>
      <div class="reg-bd-empty" data-reg-topic-empty>
        <p class="reg-bd-empty__title">No topics added</p>
        <p class="reg-bd-empty__copy" data-reg-empty-copy>Questions may be selected from the entire subject.</p>
      </div>
      <div data-reg-topic-body></div>
      <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm reg-bd-add-topic" data-reg-add-topic>+ Add topic</button>
      <p class="reg-bd-msg" data-reg-subject-msg hidden></p>
    </div>
  </article>
</template>

<template id="regTopicTemplate">
  <div class="reg-bd-topic-row" data-reg-topic>
    <div class="reg-bd-field">
      <span class="reg-bd-k">Topic</span>
      <input type="text" name="reg_subjects[__SI__][topics][__TI__][name]" value="" placeholder="Topic name" data-reg-topic-name aria-label="Topic name">
    </div>
    <div class="reg-bd-field reg-bd-field--qty">
      <label>Questions</label>
      <input type="number" min="1" step="1" name="reg_subjects[__SI__][topics][__TI__][questions]" value="" placeholder="0" data-reg-topic-qty aria-label="Topic questions">
    </div>
    <button type="button" class="reg-bd-remove" data-reg-remove-topic aria-label="Remove topic" title="Remove topic"><i class="bi bi-trash" aria-hidden="true"></i></button>
  </div>
</template>

<script>
(function () {
  var panel = document.getElementById('regularSubjectsPanel');
  var list = document.getElementById('regSubjectList');
  var addBtn = document.getElementById('regAddSubjectBtn');
  var subTpl = document.getElementById('regSubjectTemplate');
  var topicTpl = document.getElementById('regTopicTemplate');
  var totalEl = document.getElementById('regTopicTotalQty');
  var overallPanel = document.getElementById('regOverallPanel');
  var subjectsBody = document.getElementById('regSubjectsPanelBody');
  var overallQty = document.getElementById('regTotalQuestionsRequired');
  var summaryEl = document.getElementById('regBreakdownSummary');
  var contribEl = document.getElementById('regExamContribution');
  var footerEl = document.getElementById('regBreakdownFooterStatus');
  var form = document.getElementById('examinationConfigForm');
  var paperLocked = <?php echo !empty($assignmentLocked) ? 'true' : 'false'; ?>;
  if (!list || !addBtn || !subTpl || !topicTpl) return;
  if (paperLocked) {
    panel.querySelectorAll('input:not([type="hidden"]), button, select, textarea').forEach(function (el) {
      el.disabled = true;
    });
  }

  function currentMode() {
    var el = panel.querySelector('input[name="question_breakdown_mode"]:checked');
    return el ? el.value : 'overall';
  }

  function parseQty(el) {
    if (!el) return 0;
    var raw = String(el.value || '').trim();
    if (raw === '') return 0;
    if (!/^\d+$/.test(raw)) return NaN;
    return parseInt(raw, 10);
  }

  function setDisabledTree(root, disabled) {
    if (!root) return;
    root.querySelectorAll('input, select, textarea').forEach(function (el) {
      if (el.getAttribute('data-reg-mode') !== null) return;
      el.disabled = !!disabled;
    });
  }

  function subjectName(sub) {
    var el = sub.querySelector('[data-reg-subject-name]');
    return el ? String(el.value || '').trim() : '';
  }

  function refreshSubjectChrome(sub) {
    var name = subjectName(sub);
    var addBtnLocal = sub.querySelector('[data-reg-add-topic]');
    if (addBtnLocal) addBtnLocal.textContent = name ? ('+ Add topic to ' + name) : '+ Add topic';
    var emptyCopy = sub.querySelector('[data-reg-empty-copy]');
    if (emptyCopy) {
      emptyCopy.textContent = name
        ? ('Questions may be selected from the entire ' + name + ' subject.')
        : 'Questions may be selected from the entire subject.';
    }
    var qtyEl = sub.querySelector('[data-reg-subject-qty]');
    var quotaEl = sub.querySelector('[data-reg-subject-quota]');
    if (quotaEl) {
      var q = parseQty(qtyEl);
      quotaEl.textContent = (isFinite(q) && q > 0) ? (q + ' questions') : '—';
    }
    var emptyEl = sub.querySelector('[data-reg-topic-empty]');
    var topicCount = sub.querySelectorAll('[data-reg-topic]').length;
    if (emptyEl) emptyEl.hidden = topicCount > 0;
  }

  function applyModeUi() {
    var mode = currentMode();
    if (overallPanel) overallPanel.hidden = mode !== 'overall';
    if (subjectsBody) subjectsBody.hidden = mode === 'overall';
    setDisabledTree(overallPanel, mode !== 'overall');
    setDisabledTree(subjectsBody, mode === 'overall');
    list.querySelectorAll('[data-reg-topic-wrap]').forEach(function (wrap) {
      wrap.hidden = mode !== 'subject_topic';
      wrap.querySelectorAll('input').forEach(function (inp) {
        inp.disabled = mode !== 'subject_topic' || mode === 'overall';
      });
    });
    if (mode === 'overall') {
      setDisabledTree(overallPanel, false);
    }
    syncValidity();
  }

  function reindex() {
    var subjects = list.querySelectorAll('[data-reg-subject]');
    subjects.forEach(function (sub, si) {
      var label = sub.querySelector('.reg-bd-subject__label');
      if (label) label.textContent = 'Subject ' + String(si + 1).padStart(2, '0');
      var nameInput = sub.querySelector('[data-reg-subject-name]');
      if (nameInput) nameInput.name = 'reg_subjects[' + si + '][name]';
      var qtyInput = sub.querySelector('[data-reg-subject-qty]');
      if (qtyInput) qtyInput.name = 'reg_subjects[' + si + '][questions]';
      var topics = sub.querySelectorAll('[data-reg-topic]');
      topics.forEach(function (tr, ti) {
        var nameInp = tr.querySelector('[data-reg-topic-name]');
        var qtyInp = tr.querySelector('[data-reg-topic-qty]');
        if (nameInp) nameInp.name = 'reg_subjects[' + si + '][topics][' + ti + '][name]';
        if (qtyInp) qtyInp.name = 'reg_subjects[' + si + '][topics][' + ti + '][questions]';
      });
      refreshSubjectChrome(sub);
    });
    applyModeUi();
  }

  function setSubmitEnabled(ok) {
    if (!form) return;
    if (paperLocked) ok = true;
    form.querySelectorAll('button[type="submit"][name="save_action"]').forEach(function (btn) {
      btn.disabled = !ok;
      btn.setAttribute('aria-disabled', ok ? 'false' : 'true');
    });
  }

  function setFooter(ok, invalidCount) {
    if (!footerEl) return;
    footerEl.classList.remove('is-ok', 'is-warn');
    if (currentMode() === 'overall') {
      footerEl.hidden = true;
      footerEl.textContent = '';
      return;
    }
    footerEl.hidden = false;
    if (!ok) {
      footerEl.classList.add('is-warn');
      footerEl.textContent = invalidCount === 1
        ? '⚠ 1 subject needs attention'
        : ('⚠ ' + invalidCount + ' subjects need attention');
    } else {
      footerEl.classList.add('is-ok');
      footerEl.textContent = '✓ Question allocation complete';
    }
  }

  function renderContribution(rows) {
    if (!contribEl) return;
    if (rows.length < 2) {
      contribEl.hidden = true;
      contribEl.innerHTML = '';
      return;
    }
    contribEl.hidden = false;
    contribEl.innerHTML = rows.map(function (row) {
      return '<li><span>' + row.name.replace(/[&<>"]/g, function (ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[ch];
      }) + '</span><span>' + row.qty + '</span></li>';
    }).join('');
  }

  function syncValidity() {
    var mode = currentMode();
    var sum = 0;
    var errors = [];
    var seenSubjects = {};
    var contrib = [];
    var invalidSubjects = 0;

    if (mode === 'overall') {
      var n = parseQty(overallQty);
      sum = (isFinite(n) && n > 0) ? n : 0;
      if (totalEl) totalEl.textContent = String(sum);
      if (summaryEl) summaryEl.textContent = '';
      renderContribution([]);
      setFooter(true, 0);
      setSubmitEnabled(true);
      return;
    }

    list.querySelectorAll('[data-reg-subject]').forEach(function (sub) {
      var nameEl = sub.querySelector('[data-reg-subject-name]');
      var qtyEl = sub.querySelector('[data-reg-subject-qty]');
      var msgEl = sub.querySelector('[data-reg-subject-msg]');
      var allocEl = sub.querySelector('[data-reg-alloc]');
      var sName = nameEl ? String(nameEl.value || '').trim() : '';
      var sQty = parseQty(qtyEl);
      var localErrors = [];
      refreshSubjectChrome(sub);

      if (sName !== '') {
        var sKey = sName.toLowerCase();
        if (seenSubjects[sKey]) {
          localErrors.push('Duplicate subject name "' + sName + '".');
        }
        seenSubjects[sKey] = true;
        if (!isFinite(sQty) || sQty < 1) {
          localErrors.push('Enter a whole number of questions greater than 0 for ' + sName + '.');
        } else {
          sum += sQty;
          contrib.push({ name: sName, qty: sQty });
        }
      }

      var topicSum = 0;
      var namedTopics = 0;
      var seenTopics = {};
      if (mode === 'subject_topic') {
        sub.querySelectorAll('[data-reg-topic]').forEach(function (row) {
          var tNameEl = row.querySelector('[data-reg-topic-name]');
          var tQtyEl = row.querySelector('[data-reg-topic-qty]');
          var tName = tNameEl ? String(tNameEl.value || '').trim() : '';
          if (tName === '') return;
          namedTopics += 1;
          var tKey = tName.toLowerCase();
          if (seenTopics[tKey]) {
            localErrors.push('Duplicate topic "' + tName + '"' + (sName ? ' under ' + sName : '') + '.');
          }
          seenTopics[tKey] = true;
          var tQty = parseQty(tQtyEl);
          if (!isFinite(tQty) || tQty < 1) {
            localErrors.push('Topic "' + tName + '" needs a whole number of questions greater than 0.');
            return;
          }
          topicSum += tQty;
        });
      }

      if (mode === 'subject_topic' && allocEl) {
        allocEl.classList.remove('is-complete', 'is-remaining', 'is-over');
        if (namedTopics === 0) {
          allocEl.textContent = '';
        } else {
          var target = (isFinite(sQty) && sQty > 0) ? sQty : 0;
          allocEl.textContent = topicSum + ' / ' + target;
          if (target > 0 && topicSum === target) allocEl.classList.add('is-complete');
          else if (target > 0 && topicSum < target) allocEl.classList.add('is-remaining');
          else if (target > 0 && topicSum > target) allocEl.classList.add('is-over');
        }
      }

      if (mode === 'subject_topic' && namedTopics > 0 && isFinite(sQty) && sQty > 0 && topicSum !== sQty) {
        var label = sName !== '' ? sName : 'This subject';
        var diff = Math.abs(topicSum - sQty);
        if (topicSum > sQty) {
          localErrors.push('⚠ Topic allocations exceed ' + label + ' by ' + diff + ' question' + (diff === 1 ? '' : 's') + '.');
        } else {
          localErrors.push(diff + ' question' + (diff === 1 ? '' : 's') + ' remaining.');
        }
      }

      if (msgEl) {
        if (localErrors.length) {
          msgEl.hidden = false;
          msgEl.className = 'reg-bd-msg is-error';
          msgEl.textContent = localErrors[0];
        } else if (mode === 'subject_topic' && namedTopics > 0 && topicSum === sQty && sQty > 0) {
          msgEl.hidden = false;
          msgEl.className = 'reg-bd-msg is-ok';
          msgEl.textContent = '✓ Allocation complete';
        } else {
          msgEl.hidden = true;
          msgEl.textContent = '';
        }
      }
      sub.classList.toggle('is-invalid', localErrors.length > 0);
      if (localErrors.length) invalidSubjects += 1;
      localErrors.forEach(function (err) { errors.push(err); });
    });

    if (totalEl) totalEl.textContent = String(sum);
    renderContribution(contrib);
    if (summaryEl) summaryEl.textContent = errors.length ? errors[0] : '';
    setFooter(errors.length === 0, invalidSubjects);
    setSubmitEnabled(errors.length === 0);
  }

  function addTopic(subEl) {
    var body = subEl.querySelector('[data-reg-topic-body]');
    if (!body) return;
    var html = topicTpl.innerHTML.replace(/__SI__/g, '0').replace(/__TI__/g, '0');
    body.insertAdjacentHTML('beforeend', html);
    reindex();
  }

  function addSubject() {
    var html = subTpl.innerHTML.replace(/__SI__/g, '0');
    list.insertAdjacentHTML('beforeend', html);
    reindex();
  }

  panel.querySelectorAll('[data-reg-mode]').forEach(function (radio) {
    radio.addEventListener('change', applyModeUi);
  });

  if (overallQty) {
    overallQty.addEventListener('input', syncValidity);
    overallQty.addEventListener('change', syncValidity);
  }

  addBtn.addEventListener('click', function (e) {
    e.preventDefault();
    addSubject();
  });

  list.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof Element)) return;
    if (t.closest('[data-reg-add-topic]')) {
      e.preventDefault();
      var sub = t.closest('[data-reg-subject]');
      if (sub) addTopic(sub);
    }
    if (t.closest('[data-reg-remove-topic]')) {
      e.preventDefault();
      var row = t.closest('[data-reg-topic]');
      if (row) row.remove();
      reindex();
    }
    if (t.closest('[data-reg-remove-subject]')) {
      e.preventDefault();
      var subRm = t.closest('[data-reg-subject]');
      if (subRm) subRm.remove();
      reindex();
    }
  });

  list.addEventListener('input', function () {
    syncValidity();
  });

  if (form) {
    form.addEventListener('submit', function (e) {
      syncValidity();
      var blocked = form.querySelector('button[type="submit"][name="save_action"][disabled]');
      if (blocked) {
        e.preventDefault();
        var firstMsg = list.querySelector('.reg-bd-subject.is-invalid');
        if (firstMsg) firstMsg.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }

  applyModeUi();
})();
</script>
