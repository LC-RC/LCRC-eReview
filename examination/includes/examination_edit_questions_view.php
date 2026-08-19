<?php
declare(strict_types=1);

/** @var mysqli $conn */
/** @var array|null $record */
/** @var string $examType */
/** @var int $sourceId */
/** @var string $csrf */
/** @var int $uid */
/** @var string|null $error */
/** @var string|null $flashMessage */
/** @var string|null $flashError */

$rec = is_array($record) ? $record : null;
if (!$rec) {
    header('Location: professor_examinations');
    exit;
}

$locked = examination_questions_mutations_locked($conn, $examType, $sourceId);
$attemptCount = examination_questions_attempt_count($conn, $examType, $sourceId);
$editQuestionId = (int)($_GET['edit'] ?? 0);
$focusSubjectId = (int)($_GET['subject_id'] ?? 0);

$pageTitle = 'Examination Questions';
$adminHeroIcon = 'question-circle';
$adminHeroTitle = 'Questions';
$adminHeroSubtitle = ($rec['exam_type_label'] ?? '') . ' · ' . ($rec['title'] ?? '');
$adminHeroActions = '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_examinations"><i class="bi bi-arrow-left"></i> Back to Examinations</a>';
$activeStep = 'questions';

$regularQuestions = $examType === 'regular' ? examination_questions_load_regular($conn, $sourceId) : [];
$diagnosticSupply = $examType === 'diagnostic' ? examination_questions_diagnostic_supply($conn, $sourceId) : null;

$editingRegular = null;
if ($examType === 'regular' && $editQuestionId > 0) {
    foreach ($regularQuestions as $q) {
        if ((int)($q['question_id'] ?? 0) === $editQuestionId) {
            $editingRegular = $q;
            break;
        }
    }
}

$editingDiagnostic = null;
if ($examType === 'diagnostic' && $editQuestionId > 0 && $focusSubjectId > 0) {
    foreach (($diagnosticSupply['subjects'] ?? []) as $sub) {
        if ((int)$sub['subject_id'] !== $focusSubjectId) {
            continue;
        }
        foreach ($sub['questions'] as $q) {
            if ((int)($q['question_id'] ?? 0) === $editQuestionId) {
                $editingDiagnostic = $q;
                break 2;
            }
        }
    }
}

$questionsBaseUrl = examination_domain_edit_url($examType, $sourceId, 'questions');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">
<?php include dirname(__DIR__) . '/professor/professor_admin_sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

<?php if ($flashMessage): ?>
  <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?php echo h($flashMessage); ?></span></div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($flashError); ?></span></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($error); ?></span></div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/examination_edit_steps.php'; ?>

<?php if ($locked): ?>
  <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2">
    <i class="bi bi-lock-fill"></i>
    <span>Questions are locked — this examination already has <?php echo (int)$attemptCount; ?> student attempt(s). Existing question IDs and answers are preserved.</span>
  </div>
<?php endif; ?>

<?php if ($examType === 'regular'): ?>
  <?php
    $regQt = strtolower((string)($editingRegular['question_type'] ?? 'mcq'));
    if ($regQt !== 'tf') {
        $regQt = 'mcq';
    }
    $regCa = strtoupper((string)($editingRegular['correct_answer'] ?? ''));
    $regChoices = [
        'a' => (string)($editingRegular['choice_a'] ?? ''),
        'b' => (string)($editingRegular['choice_b'] ?? ''),
        'c' => (string)($editingRegular['choice_c'] ?? ''),
        'd' => (string)($editingRegular['choice_d'] ?? ''),
    ];
    $regVisibleChoices = 2;
    if ($editingRegular) {
        $regVisibleChoices = 2;
        foreach (['d', 'c'] as $ck) {
            if (trim($regChoices[$ck]) !== '') {
                $regVisibleChoices = $ck === 'd' ? 4 : 3;
                break;
            }
        }
        if (trim($regChoices['c']) !== '' && $regVisibleChoices < 3) {
            $regVisibleChoices = 3;
        }
    }
    $nextQuestionNum = $editingRegular
        ? (1 + (int)array_search($editingRegular, $regularQuestions, true))
        : (count($regularQuestions) + 1);
    if ($editingRegular) {
        foreach ($regularQuestions as $i => $rq) {
            if ((int)($rq['question_id'] ?? 0) === (int)$editingRegular['question_id']) {
                $nextQuestionNum = $i + 1;
                break;
            }
        }
    }
    $focusBuilder = (string)($_GET['focus'] ?? '') === 'builder';
  ?>
  <section class="eqb-panel page-table mb-4">
    <div class="eqb-panel__head">
      <div>
        <h2 class="eqb-panel__title">Questions</h2>
        <p class="eqb-panel__sub"><strong><?php echo count($regularQuestions); ?></strong> question<?php echo count($regularQuestions) === 1 ? '' : 's'; ?></p>
      </div>
      <?php if (!$locked): ?>
        <div class="eqb-panel__actions">
          <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" id="btnOpenImport"><i class="bi bi-upload"></i> Import</button>
          <a class="admin-btn admin-btn--primary admin-btn--sm" href="#questionForm"><i class="bi bi-plus-lg"></i> Add question</a>
        </div>
      <?php endif; ?>
    </div>

    <div class="eqb-toolbar">
      <input type="search" id="eqbSearch" class="eqb-search" placeholder="Search questions…" autocomplete="off">
    </div>

    <div class="students-table-scroll eqb-table-wrap">
      <table class="w-full text-left admin-students-table students-table--compact eqb-table">
        <thead>
          <tr>
            <th scope="col">#</th>
            <th scope="col">Question</th>
            <th scope="col">Type</th>
            <th scope="col">Answer</th>
            <th scope="col" class="student-actions-head">Actions</th>
          </tr>
        </thead>
        <tbody id="eqbQuestionRows">
          <?php if ($regularQuestions === []): ?>
            <tr><td colspan="5" class="students-empty-cell">No questions yet. Add or import questions below.</td></tr>
          <?php else: ?>
            <?php foreach ($regularQuestions as $i => $q): ?>
              <?php
                $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string)($q['question_text'] ?? ''))));
                $searchHay = mb_strtolower($plain . ' ' . (string)($q['correct_answer'] ?? ''));
                if (strlen($plain) > 140) {
                    $plain = substr($plain, 0, 137) . '…';
                }
                $typeLabel = strtolower((string)($q['question_type'] ?? 'mcq')) === 'tf' ? 'T/F' : 'MCQ';
                $ansLabel = strtoupper((string)($q['correct_answer'] ?? '—'));
                if ($typeLabel === 'T/F') {
                    $ansLabel = $ansLabel === 'A' ? 'True' : ($ansLabel === 'B' ? 'False' : $ansLabel);
                }
              ?>
              <tr data-eqb-search="<?php echo h($searchHay); ?>">
                <td><?php echo $i + 1; ?></td>
                <td><?php echo h($plain !== '' ? $plain : '—'); ?></td>
                <td><span class="eqb-type"><?php echo h($typeLabel); ?></span></td>
                <td><span class="eqb-ans"><?php echo h($ansLabel); ?></span></td>
                <td class="eqb-row-actions">
                  <?php if (!$locked): ?>
                    <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm eqb-preview-btn"
                      data-preview-id="<?php echo (int)$q['question_id']; ?>"
                      data-preview-type="<?php echo h($typeLabel); ?>"
                      data-preview-html="<?php echo h((string)($q['question_text'] ?? '')); ?>"
                      data-preview-a="<?php echo h((string)($q['choice_a'] ?? '')); ?>"
                      data-preview-b="<?php echo h((string)($q['choice_b'] ?? '')); ?>"
                      data-preview-c="<?php echo h((string)($q['choice_c'] ?? '')); ?>"
                      data-preview-d="<?php echo h((string)($q['choice_d'] ?? '')); ?>">Preview</button>
                    <a class="admin-btn admin-btn--ghost admin-btn--sm" href="<?php echo h($questionsBaseUrl . (str_contains($questionsBaseUrl, '?') ? '&' : '?') . 'edit=' . (int)$q['question_id']); ?>">Edit</a>
                    <form method="post" class="inline">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="duplicate_question">
                      <input type="hidden" name="exam_type" value="regular">
                      <input type="hidden" name="exam_id" value="<?php echo (int)$sourceId; ?>">
                      <input type="hidden" name="question_id" value="<?php echo (int)$q['question_id']; ?>">
                      <button type="submit" class="admin-btn admin-btn--ghost admin-btn--sm">Duplicate</button>
                    </form>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this question?');">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="delete_question">
                      <input type="hidden" name="exam_type" value="regular">
                      <input type="hidden" name="exam_id" value="<?php echo (int)$sourceId; ?>">
                      <input type="hidden" name="question_id" value="<?php echo (int)$q['question_id']; ?>">
                      <button type="submit" class="admin-btn admin-btn--ghost admin-btn--sm">Delete</button>
                    </form>
                  <?php else: ?>
                    <span class="opacity-60 text-sm">Locked</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if (!$locked): ?>
    <section class="eqb-builder page-table mb-4" id="questionForm">
      <div class="eqb-builder__head">
        <div>
          <p class="eqb-builder__eyebrow"><?php echo $editingRegular ? 'Editing' : 'Creating'; ?></p>
          <h2 class="eqb-builder__title">Question <?php echo (int)$nextQuestionNum; ?></h2>
        </div>
        <?php if ($editingRegular): ?>
          <a class="admin-btn admin-btn--ghost admin-btn--sm" href="<?php echo h($questionsBaseUrl); ?>">Cancel edit</a>
        <?php endif; ?>
      </div>

      <form method="post" class="eqb-form" id="regQuestionForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save_question">
        <input type="hidden" name="exam_type" value="regular">
        <input type="hidden" name="exam_id" value="<?php echo (int)$sourceId; ?>">
        <input type="hidden" name="question_id" value="<?php echo (int)($editingRegular['question_id'] ?? 0); ?>">
        <input type="hidden" name="save_and_continue" id="regSaveAndContinue" value="0">

        <label class="eqb-field">
          <span class="eqb-label">Question type</span>
          <select name="question_type" id="regQType" class="eqb-select">
            <option value="mcq" <?php echo $regQt !== 'tf' ? 'selected' : ''; ?>>Multiple choice</option>
            <option value="tf" <?php echo $regQt === 'tf' ? 'selected' : ''; ?>>True / False</option>
          </select>
        </label>

        <div class="eqb-field">
          <span class="eqb-label">Question</span>
          <p class="eqb-hint">Use formatting for readability. Tables are recommended for accounting computations and data-heavy questions.</p>
          <textarea name="question_text" id="regQuestionText" class="js-exam-q-richtext w-full" rows="6"><?php echo h((string)($editingRegular['question_text'] ?? '')); ?></textarea>
          <p class="eqb-error" id="regQTextErr" hidden>Question text is required.</p>
        </div>

        <div id="regMcqChoices" class="eqb-choices" data-visible="<?php echo (int)$regVisibleChoices; ?>">
          <div class="eqb-choices__head">
            <h3 class="eqb-section-title">Answer options</h3>
            <p class="eqb-hint m-0">Schema supports up to 4 choices (A–D). Add only what you need.</p>
          </div>
          <?php foreach (['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'] as $k => $L): ?>
            <?php $slot = ord($L) - ord('A') + 1; ?>
            <div class="eqb-choice-row" data-choice-slot="<?php echo $slot; ?>" <?php echo $slot > $regVisibleChoices ? 'hidden' : ''; ?>>
              <span class="eqb-choice-letter" aria-hidden="true"><?php echo $L; ?></span>
              <input class="eqb-choice-input" name="choice_<?php echo $k; ?>" id="regChoice<?php echo $L; ?>"
                value="<?php echo h($regChoices[$k]); ?>"
                placeholder="Enter choice <?php echo $L; ?>…"
                data-letter="<?php echo $L; ?>">
              <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm eqb-choice-remove" data-remove-slot="<?php echo $slot; ?>" <?php echo $slot <= 2 ? 'hidden' : ''; ?>>Remove</button>
            </div>
          <?php endforeach; ?>
          <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" id="regAddChoice"><i class="bi bi-plus-lg"></i> Add choice</button>
          <p class="eqb-error" id="regChoicesErr" hidden>Enter at least choices A and B.</p>
        </div>

        <div id="regTfNote" class="eqb-tf-note" <?php echo $regQt === 'tf' ? '' : 'hidden'; ?>>
          <p class="eqb-hint m-0">True / False choices are fixed as A = True and B = False.</p>
        </div>

        <div class="eqb-field eqb-correct-wrap">
          <span class="eqb-label">Correct answer</span>
          <p class="eqb-hint" id="regCorrectHint">Select the correct answer after entering all choices.</p>
          <select name="correct_answer" id="regCorrect" class="eqb-select" required>
            <option value=""><?php echo $regQt === 'tf' ? 'Select True or False' : 'Select the correct answer'; ?></option>
            <option value="A" <?php echo $regCa === 'A' ? 'selected' : ''; ?>><?php echo $regQt === 'tf' ? 'True' : 'A'; ?></option>
            <option value="B" <?php echo $regCa === 'B' ? 'selected' : ''; ?>><?php echo $regQt === 'tf' ? 'False' : 'B'; ?></option>
            <option value="C" <?php echo $regCa === 'C' ? 'selected' : ''; ?>>C</option>
            <option value="D" <?php echo $regCa === 'D' ? 'selected' : ''; ?>>D</option>
          </select>
          <p class="eqb-error" id="regCorrectErr" hidden>Please select the correct answer.</p>
        </div>

        <div class="eqb-form-actions">
          <button type="button" class="admin-btn admin-btn--secondary" id="regPreviewLive"><i class="bi bi-eye"></i> Preview</button>
          <button type="submit" class="admin-btn admin-btn--primary" id="regSaveBtn"><i class="bi bi-check2"></i> <?php echo $editingRegular ? 'Save question' : 'Add question'; ?></button>
          <?php if (!$editingRegular): ?>
            <button type="submit" class="admin-btn admin-btn--secondary" id="regSaveContinueBtn"><i class="bi bi-plus-circle"></i> Save &amp; add another</button>
          <?php endif; ?>
        </div>
      </form>
    </section>
  <?php endif; ?>

<?php else: /* diagnostic */ ?>
  <section class="rounded-xl overflow-hidden page-table p-6 mb-4">
    <h2 class="text-base font-bold m-0 mb-1">Questions by subject</h2>
    <p class="text-sm opacity-70 m-0 mb-4">Required counts come from Configuration. Add the actual question content here.</p>

    <?php if (empty($diagnosticSupply['subjects'])): ?>
      <div class="examination-empty-box">
        <p class="font-bold m-0">No subjects configured</p>
        <p class="text-sm mt-2 mb-0 opacity-80">Select subjects and required counts in Configuration first.</p>
        <a class="admin-btn admin-btn--secondary admin-btn--sm mt-3" href="<?php echo h(examination_domain_edit_url($examType, $sourceId, 'config')); ?>">Open Configuration</a>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($diagnosticSupply['subjects'] as $sub): ?>
          <?php
            $sid = (int)$sub['subject_id'];
            $need = (int)$sub['required'] > 0 ? (int)$sub['required'] : max(1, (int)$sub['authored']);
            $ok = !empty($sub['ok']);
            $manageUrl = $questionsBaseUrl . (str_contains($questionsBaseUrl, '?') ? '&' : '?') . 'subject_id=' . $sid;
          ?>
          <div class="rounded-xl border p-4 <?php echo $focusSubjectId === $sid ? 'ring-2 ring-blue-500/40' : ''; ?>">
            <div class="flex items-start justify-between gap-2">
              <div>
                <div class="font-bold"><?php echo h($sub['subject_code']); ?></div>
                <div class="text-sm opacity-70"><?php echo h($sub['subject_name']); ?></div>
              </div>
              <span class="admin-badge <?php echo $ok ? 'admin-badge--success' : 'admin-badge--warning'; ?>">
                <?php echo (int)$sub['authored']; ?> / <?php echo (int)$sub['required'] > 0 ? (int)$sub['required'] : 'all'; ?>
                <?php echo $ok ? '✓' : '✗'; ?>
              </span>
            </div>
            <p class="text-sm mt-2 mb-3 opacity-80">
              <?php echo (int)$sub['authored']; ?> authored
              <?php if ((int)$sub['required'] > 0): ?>
                / <?php echo (int)$sub['required']; ?> required
              <?php else: ?>
                (0 required = use all authored; need ≥1)
              <?php endif; ?>
            </p>
            <a class="admin-btn admin-btn--secondary admin-btn--sm" href="<?php echo h($manageUrl); ?>"><i class="bi bi-pencil-square"></i> Manage Questions</a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php
    $activeSubject = null;
    if ($focusSubjectId > 0 && $diagnosticSupply) {
        foreach ($diagnosticSupply['subjects'] as $sub) {
            if ((int)$sub['subject_id'] === $focusSubjectId) {
                $activeSubject = $sub;
                break;
            }
        }
    }
  ?>

  <?php if ($activeSubject): ?>
    <section class="rounded-xl overflow-hidden page-table p-6 mb-4" id="subjectManage">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
          <h2 class="text-base font-bold m-0"><?php echo h($activeSubject['subject_code']); ?> questions</h2>
          <p class="text-sm opacity-70 m-0 mt-1"><?php echo (int)$activeSubject['authored']; ?> authored
            <?php if ((int)$activeSubject['required'] > 0): ?> / <?php echo (int)$activeSubject['required']; ?> required<?php endif; ?>
          </p>
        </div>
        <?php if (!$locked): ?>
          <div class="flex flex-wrap gap-2">
            <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" id="btnOpenImportDiag"><i class="bi bi-upload"></i> Import Questions</button>
            <a class="admin-btn admin-btn--primary admin-btn--sm" href="#diagQuestionForm"><i class="bi bi-plus-lg"></i> Add Question</a>
          </div>
        <?php endif; ?>
      </div>

      <div class="students-table-scroll mb-4">
        <table class="w-full text-left admin-students-table students-table--compact">
          <thead>
            <tr>
              <th>#</th>
              <th>Question</th>
              <th>Answer</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($activeSubject['questions'])): ?>
              <tr><td colspan="4" class="students-empty-cell">No questions for this subject yet.</td></tr>
            <?php else: ?>
              <?php foreach ($activeSubject['questions'] as $i => $q): ?>
                <?php
                  $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string)($q['question_text'] ?? ''))));
                  if (strlen($plain) > 120) {
                      $plain = substr($plain, 0, 117) . '…';
                  }
                  $editUrl = $questionsBaseUrl . (str_contains($questionsBaseUrl, '?') ? '&' : '?') . 'subject_id=' . (int)$activeSubject['subject_id'] . '&edit=' . (int)$q['question_id'];
                ?>
                <tr>
                  <td><?php echo $i + 1; ?></td>
                  <td><?php echo h($plain !== '' ? $plain : '—'); ?></td>
                  <td><?php echo h((string)($q['correct_answer'] ?? '—')); ?></td>
                  <td>
                    <?php if (!$locked): ?>
                      <a class="admin-btn admin-btn--ghost admin-btn--sm" href="<?php echo h($editUrl); ?>">Edit</a>
                      <form method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="action" value="duplicate_question">
                        <input type="hidden" name="exam_type" value="diagnostic">
                        <input type="hidden" name="batch_id" value="<?php echo (int)$sourceId; ?>">
                        <input type="hidden" name="subject_id" value="<?php echo (int)$activeSubject['subject_id']; ?>">
                        <input type="hidden" name="question_id" value="<?php echo (int)$q['question_id']; ?>">
                        <button type="submit" class="admin-btn admin-btn--ghost admin-btn--sm">Duplicate</button>
                      </form>
                      <form method="post" class="inline" onsubmit="return confirm('Delete this question?');">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="action" value="delete_question">
                        <input type="hidden" name="exam_type" value="diagnostic">
                        <input type="hidden" name="batch_id" value="<?php echo (int)$sourceId; ?>">
                        <input type="hidden" name="subject_id" value="<?php echo (int)$activeSubject['subject_id']; ?>">
                        <input type="hidden" name="question_id" value="<?php echo (int)$q['question_id']; ?>">
                        <button type="submit" class="admin-btn admin-btn--ghost admin-btn--sm">Delete</button>
                      </form>
                    <?php else: ?>
                      <span class="opacity-60 text-sm">Locked</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if (!$locked): ?>
        <div id="diagQuestionForm" class="eqb-builder">
          <?php
            $dca = strtoupper((string)($editingDiagnostic['correct_answer'] ?? ''));
            $dChoices = [
                'a' => (string)($editingDiagnostic['choice_a'] ?? ''),
                'b' => (string)($editingDiagnostic['choice_b'] ?? ''),
                'c' => (string)($editingDiagnostic['choice_c'] ?? ''),
                'd' => (string)($editingDiagnostic['choice_d'] ?? ''),
            ];
            $dVisible = 2;
            if ($editingDiagnostic) {
                if (trim($dChoices['d']) !== '') {
                    $dVisible = 4;
                } elseif (trim($dChoices['c']) !== '') {
                    $dVisible = 3;
                }
            }
          ?>
          <div class="eqb-builder__head">
            <h3 class="eqb-builder__title m-0"><?php echo $editingDiagnostic ? 'Edit question' : 'Add question'; ?></h3>
            <?php if ($editingDiagnostic): ?>
              <a class="admin-btn admin-btn--ghost admin-btn--sm" href="<?php echo h($questionsBaseUrl . (str_contains($questionsBaseUrl, '?') ? '&' : '?') . 'subject_id=' . (int)$activeSubject['subject_id']); ?>">Cancel edit</a>
            <?php endif; ?>
          </div>
          <form method="post" class="eqb-form" id="diagQuestionFormEl" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="save_question">
            <input type="hidden" name="exam_type" value="diagnostic">
            <input type="hidden" name="batch_id" value="<?php echo (int)$sourceId; ?>">
            <input type="hidden" name="subject_id" value="<?php echo (int)$activeSubject['subject_id']; ?>">
            <input type="hidden" name="question_id" value="<?php echo (int)($editingDiagnostic['question_id'] ?? 0); ?>">
            <input type="hidden" name="save_and_continue" id="diagSaveAndContinue" value="0">
            <div class="eqb-field">
              <span class="eqb-label">Question</span>
              <p class="eqb-hint">Use formatting for readability. Tables are recommended for accounting data.</p>
              <textarea name="question_text" id="diagQuestionText" class="js-exam-q-richtext w-full" rows="6"><?php echo h((string)($editingDiagnostic['question_text'] ?? '')); ?></textarea>
            </div>
            <div id="diagMcqChoices" class="eqb-choices" data-visible="<?php echo (int)$dVisible; ?>">
              <div class="eqb-choices__head">
                <h3 class="eqb-section-title">Answer options</h3>
              </div>
              <?php foreach (['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'] as $k => $L): ?>
                <?php $slot = ord($L) - ord('A') + 1; ?>
                <div class="eqb-choice-row" data-choice-slot="<?php echo $slot; ?>" <?php echo $slot > $dVisible ? 'hidden' : ''; ?>>
                  <span class="eqb-choice-letter"><?php echo $L; ?></span>
                  <input class="eqb-choice-input" name="choice_<?php echo $k; ?>" id="diagChoice<?php echo $L; ?>"
                    value="<?php echo h($dChoices[$k]); ?>" placeholder="Enter choice <?php echo $L; ?>…" data-letter="<?php echo $L; ?>">
                  <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm eqb-choice-remove" data-remove-slot="<?php echo $slot; ?>" <?php echo $slot <= 2 ? 'hidden' : ''; ?>>Remove</button>
                </div>
              <?php endforeach; ?>
              <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" id="diagAddChoice"><i class="bi bi-plus-lg"></i> Add choice</button>
            </div>
            <div class="eqb-field eqb-correct-wrap">
              <span class="eqb-label">Correct answer</span>
              <p class="eqb-hint">Select the correct answer after entering all choices.</p>
              <select name="correct_answer" id="diagCorrect" class="eqb-select" required>
                <option value="">Select the correct answer</option>
                <?php foreach (['A', 'B', 'C', 'D'] as $L): ?>
                  <option value="<?php echo $L; ?>" <?php echo $dca === $L ? 'selected' : ''; ?>><?php echo $L; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="eqb-form-actions">
              <button type="submit" class="admin-btn admin-btn--primary"><?php echo $editingDiagnostic ? 'Save question' : 'Add question'; ?></button>
              <?php if (!$editingDiagnostic): ?>
                <button type="submit" class="admin-btn admin-btn--secondary" id="diagSaveContinueBtn">Save &amp; add another</button>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
<?php endif; ?>

<div class="flex flex-wrap gap-3 mb-6">
  <a class="admin-btn admin-btn--secondary" href="<?php echo h(examination_domain_edit_url($examType, $sourceId, 'config')); ?>"><i class="bi bi-arrow-left"></i> Configuration</a>
  <a class="admin-btn admin-btn--primary" href="<?php echo h(examination_domain_edit_url($examType, $sourceId, 'review')); ?>">Continue to Review <i class="bi bi-arrow-right"></i></a>
</div>

<?php if (!$locked): ?>
<div id="importOverlay" class="admin-modal-overlay" hidden>
  <div class="admin-modal page-table p-5" style="width:min(100%,36rem);">
    <h3 class="text-base font-bold m-0 mb-2">Import questions</h3>
    <p class="text-sm opacity-70 mb-3">CSV or structured paste (same format as the previous exam editor). Questions are <strong>appended</strong> — existing questions are not deleted.</p>
    <div class="flex gap-2 mb-3">
      <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm import-tab is-active" data-tab="csv">CSV</button>
      <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm import-tab" data-tab="paste">Structured paste</button>
    </div>
    <textarea id="importCsvText" class="w-full font-mono text-xs mb-2" rows="8" placeholder="question,type,choice_a,choice_b,choice_c,choice_d,correct&#10;What is 2+2?,mcq,3,4,5,6,B"></textarea>
    <textarea id="importPasteText" class="w-full font-mono text-xs mb-2 hidden" rows="8" placeholder="What is the capital of France?&#10;A) Berlin&#10;B) Paris&#10;C) Madrid&#10;D) Rome&#10;Answer: B"></textarea>
    <p id="importPreview" class="text-sm opacity-80 mb-3">0 questions ready</p>
    <form method="post" id="importForm">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="import_questions">
      <input type="hidden" name="exam_type" value="<?php echo h($examType); ?>">
      <?php if ($examType === 'diagnostic'): ?>
        <input type="hidden" name="batch_id" value="<?php echo (int)$sourceId; ?>">
        <input type="hidden" name="subject_id" value="<?php echo (int)$focusSubjectId; ?>">
      <?php else: ?>
        <input type="hidden" name="exam_id" value="<?php echo (int)$sourceId; ?>">
      <?php endif; ?>
      <input type="hidden" name="import_json" id="importJson" value="[]">
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="admin-btn admin-btn--primary" id="importSubmit" disabled>Import</button>
        <button type="button" class="admin-btn admin-btn--ghost" id="importCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var overlay = document.getElementById('importOverlay');
  if (!overlay) return;
  var csvEl = document.getElementById('importCsvText');
  var pasteEl = document.getElementById('importPasteText');
  var preview = document.getElementById('importPreview');
  var jsonEl = document.getElementById('importJson');
  var submitBtn = document.getElementById('importSubmit');
  var tab = 'csv';

  function parseCsv(text) {
    var lines = String(text || '').split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
    if (!lines.length) return [];
    var start = 0;
    if (/question/i.test(lines[0]) && /correct/i.test(lines[0])) start = 1;
    var rows = [];
    for (var i = start; i < lines.length; i++) {
      var parts = [];
      var cur = '';
      var inQ = false;
      for (var c = 0; c < lines[i].length; c++) {
        var ch = lines[i][c];
        if (ch === '"') { inQ = !inQ; continue; }
        if (ch === ',' && !inQ) { parts.push(cur); cur = ''; continue; }
        cur += ch;
      }
      parts.push(cur);
      if (parts.length < 2) continue;
      var qtext = (parts[0] || '').trim();
      if (!qtext) continue;
      var type = (parts[1] || 'mcq').trim().toLowerCase();
      if (type !== 'tf') type = 'mcq';
      rows.push({
        question_text: qtext,
        question_type: type,
        choice_a: (parts[2] || '').trim(),
        choice_b: (parts[3] || '').trim(),
        choice_c: (parts[4] || '').trim(),
        choice_d: (parts[5] || '').trim(),
        correct_answer: (parts[6] || 'A').trim().toUpperCase()
      });
    }
    return rows;
  }

  function parsePaste(text) {
    var blocks = String(text || '').split(/\n\s*\n/);
    var rows = [];
    blocks.forEach(function (block) {
      var lines = block.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
      if (!lines.length) return;
      var qtext = lines[0];
      var choices = { A: '', B: '', C: '', D: '' };
      var ans = 'A';
      lines.slice(1).forEach(function (line) {
        var m = line.match(/^([A-Da-d])[\)\.\:\-\s]\s*(.*)$/);
        if (m) { choices[m[1].toUpperCase()] = m[2]; return; }
        var a = line.match(/^answer\s*[:\-]\s*([A-Da-d])/i);
        if (a) ans = a[1].toUpperCase();
      });
      if (!qtext) return;
      rows.push({
        question_text: qtext,
        question_type: 'mcq',
        choice_a: choices.A,
        choice_b: choices.B,
        choice_c: choices.C,
        choice_d: choices.D,
        correct_answer: ans
      });
    });
    return rows;
  }

  function refresh() {
    var rows = tab === 'csv' ? parseCsv(csvEl.value) : parsePaste(pasteEl.value);
    jsonEl.value = JSON.stringify(rows);
    preview.textContent = rows.length + ' question' + (rows.length === 1 ? '' : 's') + ' ready';
    submitBtn.disabled = rows.length === 0;
  }

  document.querySelectorAll('.import-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
      tab = btn.getAttribute('data-tab') || 'csv';
      document.querySelectorAll('.import-tab').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      csvEl.classList.toggle('hidden', tab !== 'csv');
      pasteEl.classList.toggle('hidden', tab !== 'paste');
      refresh();
    });
  });
  csvEl.addEventListener('input', refresh);
  pasteEl.addEventListener('input', refresh);

  function openImport() {
    overlay.hidden = false;
    overlay.classList.add('is-open');
    refresh();
  }
  function closeImport() {
    overlay.hidden = true;
    overlay.classList.remove('is-open');
  }
  var openReg = document.getElementById('btnOpenImport');
  var openDiag = document.getElementById('btnOpenImportDiag');
  if (openReg) openReg.addEventListener('click', openImport);
  if (openDiag) openDiag.addEventListener('click', openImport);
  document.getElementById('importCancel').addEventListener('click', closeImport);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) closeImport(); });
})();
</script>
<?php endif; ?>

<div id="eqbPreviewOverlay" class="admin-modal-overlay" hidden>
  <div class="admin-modal page-table p-5 eqb-preview-modal">
    <div class="flex items-center justify-between gap-2 mb-3">
      <h3 class="text-base font-bold m-0">Student preview</h3>
      <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="eqbPreviewClose">Close</button>
    </div>
    <p class="text-xs opacity-70 mb-3">Correct answer is hidden in this preview.</p>
    <div id="eqbPreviewBody" class="eqb-preview-body"></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
  function initExamQRichEditors() {
    if (!window.tinymce) return;
    document.querySelectorAll('textarea.js-exam-q-richtext').forEach(function (el) {
      if (!el.id) el.id = 'exam-q-rich-' + Math.random().toString(36).slice(2, 9);
      if (window.tinymce.get(el.id)) return;
      tinymce.init({
        selector: '#' + el.id,
        menubar: false,
        height: 240,
        branding: false,
        plugins: 'table lists advlist hr',
        toolbar: 'undo redo | bold italic underline strikethrough | bullist numlist | alignleft aligncenter alignright | outdent indent | superscript subscript | table hr | removeformat',
        valid_elements: 'p[style],br,strong/b,em/i,u,s,strike,sub,sup,hr,ul,ol,li,table,thead,tbody,tfoot,tr,th[colspan|rowspan|scope|style],td[colspan|rowspan|style]',
        valid_styles: {
          '*': 'text-align'
        },
        content_style: 'body{font-family:Nunito,system-ui,sans-serif;font-size:15px;line-height:1.55;color:#0f172a} table{border-collapse:collapse;width:100%;margin:0.75rem 0} td,th{border:1px solid #cbd5e1;padding:0.4rem 0.55rem;vertical-align:top}',
        forced_root_block: 'p',
        setup: function (editor) {
          editor.on('change input undo redo keyup', function () {
            editor.save();
          });
        }
      });
    });
  }

  function syncChoices(rootSel, correctSel, addBtnId) {
    var root = document.querySelector(rootSel);
    var correct = document.querySelector(correctSel);
    if (!root || !correct) return null;
    var addBtn = addBtnId ? document.getElementById(addBtnId) : null;

    function isChoiceRowActive(row) {
      if (!row) return false;
      // Prefer explicit property; also treat missing/false hidden as active.
      return !row.hidden && !row.hasAttribute('hidden');
    }

    function getActiveChoiceRows() {
      return Array.prototype.slice.call(root.querySelectorAll('[data-choice-slot]')).filter(isChoiceRowActive);
    }

    /** Single source of truth: currently visible answer-option rows. */
    function getCurrentChoices() {
      return getActiveChoiceRows().map(function (row) {
        var inp = row.querySelector('input.eqb-choice-input, input[data-letter]');
        var letter = ((inp && inp.getAttribute('data-letter')) || '').toUpperCase();
        var text = inp ? String(inp.value || '').trim() : '';
        var slot = parseInt(row.getAttribute('data-choice-slot'), 10) || 0;
        return { letter: letter, text: text, slot: slot, input: inp, row: row };
      }).filter(function (c) { return !!c.letter; });
    }

    function visibleCount() {
      var n = getActiveChoiceRows().length;
      if (n > 0) return n;
      return parseInt(root.getAttribute('data-visible') || '2', 10) || 2;
    }

    function setVisible(n) {
      n = Math.max(2, Math.min(4, n | 0));
      root.setAttribute('data-visible', String(n));
      root.querySelectorAll('[data-choice-slot]').forEach(function (row) {
        var slot = parseInt(row.getAttribute('data-choice-slot'), 10);
        var show = slot <= n;
        if (show) {
          row.hidden = false;
          row.removeAttribute('hidden');
        } else {
          row.hidden = true;
          row.setAttribute('hidden', 'hidden');
          var inp = row.querySelector('input');
          if (inp) inp.value = '';
        }
        var rm = row.querySelector('.eqb-choice-remove');
        if (rm) rm.hidden = slot <= 2 || !show;
      });
      if (addBtn) addBtn.hidden = n >= 4;
      rebuildCorrectAnswerOptions();
    }

    function rebuildCorrectAnswerOptions() {
      var typeEl = document.getElementById('regQType');
      var isTf = !!(typeEl && typeEl.value === 'tf' && root.id === 'regMcqChoices');
      var prev = String(correct.value || '').toUpperCase();
      var choices = isTf
        ? [{ letter: 'A', text: 'True' }, { letter: 'B', text: 'False' }]
        : getCurrentChoices();

      // Rebuild from scratch so options always match Answer Options.
      while (correct.options.length) correct.remove(0);

      var placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = isTf ? 'Select True or False' : 'Select the correct answer';
      correct.appendChild(placeholder);

      var validLetters = [];
      choices.forEach(function (c) {
        var opt = document.createElement('option');
        opt.value = c.letter;
        if (isTf) {
          opt.textContent = c.text;
        } else {
          var label = c.letter;
          if (c.text) {
            var t = c.text;
            if (t.length > 42) t = t.slice(0, 39) + '…';
            label = c.letter + ' — ' + t;
          }
          opt.textContent = label;
        }
        correct.appendChild(opt);
        validLetters.push(c.letter);
      });

      if (prev && validLetters.indexOf(prev) !== -1) {
        correct.value = prev;
      } else {
        correct.value = '';
      }

      correct.disabled = false;
      var hint = document.getElementById('regCorrectHint');
      if (hint && root.id === 'regMcqChoices') {
        hint.textContent = (isTf || choices.length >= 2)
          ? 'Select the correct answer after entering all choices.'
          : 'Enter choices first, then select the correct answer.';
      }
    }

    if (addBtn) {
      addBtn.addEventListener('click', function () {
        setVisible(visibleCount() + 1);
      });
    }
    root.querySelectorAll('.eqb-choice-remove').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var slot = parseInt(btn.getAttribute('data-remove-slot'), 10);
        var n = visibleCount();
        if (slot === n) {
          setVisible(n - 1);
          return;
        }
        var row = root.querySelector('[data-choice-slot="' + slot + '"]');
        if (row) {
          var inp = row.querySelector('input');
          if (inp) inp.value = '';
        }
        rebuildCorrectAnswerOptions();
      });
    });
    root.querySelectorAll('.eqb-choice-input').forEach(function (inp) {
      inp.addEventListener('input', rebuildCorrectAnswerOptions);
      inp.addEventListener('change', rebuildCorrectAnswerOptions);
    });

    // Sync visibility attribute with actual rows, then rebuild dropdown.
    setVisible(Math.max(2, visibleCount()));
    return {
      setVisible: setVisible,
      refreshCorrect: rebuildCorrectAnswerOptions,
      rebuildCorrectAnswerOptions: rebuildCorrectAnswerOptions,
      getCurrentChoices: getCurrentChoices
    };
  }

  function wireTypeToggle() {
    var typeEl = document.getElementById('regQType');
    var mcq = document.getElementById('regMcqChoices');
    var tfNote = document.getElementById('regTfNote');
    var correct = document.getElementById('regCorrect');
    if (!typeEl || !mcq || !correct) return;
    function apply() {
      var isTf = typeEl.value === 'tf';
      mcq.hidden = isTf;
      if (tfNote) tfNote.hidden = !isTf;
      if (isTf && (correct.value === 'C' || correct.value === 'D')) {
        correct.value = '';
      }
      if (window.__eqbRegChoices && typeof window.__eqbRegChoices.rebuildCorrectAnswerOptions === 'function') {
        window.__eqbRegChoices.rebuildCorrectAnswerOptions();
      } else if (window.__eqbRegChoices && typeof window.__eqbRegChoices.refreshCorrect === 'function') {
        window.__eqbRegChoices.refreshCorrect();
      }
    }
    typeEl.addEventListener('change', apply);
    apply();
  }

  function openPreviewFromData(html, a, b, c, d, typeLabel) {
    var overlay = document.getElementById('eqbPreviewOverlay');
    var body = document.getElementById('eqbPreviewBody');
    if (!overlay || !body) return;
    var parts = [];
    parts.push('<div class="eqb-preview-stem">' + (html || '<em>Empty question</em>') + '</div>');
    parts.push('<div class="eqb-preview-choices">');
    [['A', a], ['B', b], ['C', c], ['D', d]].forEach(function (pair) {
      if (!pair[1] && typeLabel !== 'T/F') return;
      if (!pair[1] && (pair[0] === 'C' || pair[0] === 'D')) return;
      var label = pair[1];
      if (typeLabel === 'T/F') {
        label = pair[0] === 'A' ? 'True' : (pair[0] === 'B' ? 'False' : label);
        if (pair[0] !== 'A' && pair[0] !== 'B') return;
      }
      if (!label) return;
      parts.push('<div class="eqb-preview-choice"><span>' + pair[0] + '</span><div>' + String(label).replace(/</g, '&lt;') + '</div></div>');
    });
    parts.push('</div>');
    body.innerHTML = parts.join('');
    // Allow sanitized HTML for stem only (already from DB / editor). Choices escaped above.
    var stem = body.querySelector('.eqb-preview-stem');
    if (stem) stem.innerHTML = html || '<em>Empty question</em>';
    overlay.hidden = false;
    overlay.classList.add('is-open');
  }

  document.addEventListener('DOMContentLoaded', function () {
    initExamQRichEditors();
    document.querySelectorAll('form').forEach(function (formEl) {
      formEl.addEventListener('submit', function () {
        if (window.tinymce) tinymce.triggerSave();
      });
    });

    window.__eqbRegChoices = syncChoices('#regMcqChoices', '#regCorrect', 'regAddChoice');
    window.__eqbDiagChoices = syncChoices('#diagMcqChoices', '#diagCorrect', 'diagAddChoice');
    wireTypeToggle();

    var saveCont = document.getElementById('regSaveContinueBtn');
    var saveContFlag = document.getElementById('regSaveAndContinue');
    if (saveCont && saveContFlag) {
      saveCont.addEventListener('click', function () { saveContFlag.value = '1'; });
    }
    var saveBtn = document.getElementById('regSaveBtn');
    if (saveBtn && saveContFlag) {
      saveBtn.addEventListener('click', function () { saveContFlag.value = '0'; });
    }
    var dSaveCont = document.getElementById('diagSaveContinueBtn');
    var dFlag = document.getElementById('diagSaveAndContinue');
    if (dSaveCont && dFlag) {
      dSaveCont.addEventListener('click', function () { dFlag.value = '1'; });
    }

    var regForm = document.getElementById('regQuestionForm');
    if (regForm) {
      regForm.addEventListener('submit', function (e) {
        if (window.tinymce) tinymce.triggerSave();
        var typeEl = document.getElementById('regQType');
        var isTf = typeEl && typeEl.value === 'tf';
        var text = (document.getElementById('regQuestionText') || {}).value || '';
        var plain = text.replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').trim();
        var qErr = document.getElementById('regQTextErr');
        var cErr = document.getElementById('regChoicesErr');
        var aErr = document.getElementById('regCorrectErr');
        if (qErr) qErr.hidden = true;
        if (cErr) cErr.hidden = true;
        if (aErr) aErr.hidden = true;
        if (!plain) {
          e.preventDefault();
          if (qErr) qErr.hidden = false;
          return;
        }
        if (!isTf) {
          var a = (document.getElementById('regChoiceA') || {}).value || '';
          var b = (document.getElementById('regChoiceB') || {}).value || '';
          if (!String(a).trim() || !String(b).trim()) {
            e.preventDefault();
            if (cErr) cErr.hidden = false;
            return;
          }
        }
        var correct = document.getElementById('regCorrect');
        if (!correct || !correct.value) {
          e.preventDefault();
          if (aErr) {
            aErr.textContent = 'Please select the correct answer.';
            aErr.hidden = false;
          }
          return;
        }
      });
    }

    var search = document.getElementById('eqbSearch');
    if (search) {
      search.addEventListener('input', function () {
        var q = String(search.value || '').toLowerCase().trim();
        document.querySelectorAll('#eqbQuestionRows tr[data-eqb-search]').forEach(function (tr) {
          var hay = tr.getAttribute('data-eqb-search') || '';
          tr.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
        });
      });
    }

    document.querySelectorAll('.eqb-preview-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openPreviewFromData(
          btn.getAttribute('data-preview-html') || '',
          btn.getAttribute('data-preview-a') || '',
          btn.getAttribute('data-preview-b') || '',
          btn.getAttribute('data-preview-c') || '',
          btn.getAttribute('data-preview-d') || '',
          btn.getAttribute('data-preview-type') || 'MCQ'
        );
      });
    });
    var livePrev = document.getElementById('regPreviewLive');
    if (livePrev) {
      livePrev.addEventListener('click', function () {
        if (window.tinymce) tinymce.triggerSave();
        var typeEl = document.getElementById('regQType');
        openPreviewFromData(
          (document.getElementById('regQuestionText') || {}).value || '',
          (document.getElementById('regChoiceA') || {}).value || '',
          (document.getElementById('regChoiceB') || {}).value || '',
          (document.getElementById('regChoiceC') || {}).value || '',
          (document.getElementById('regChoiceD') || {}).value || '',
          typeEl && typeEl.value === 'tf' ? 'T/F' : 'MCQ'
        );
      });
    }
    var prevOverlay = document.getElementById('eqbPreviewOverlay');
    var prevClose = document.getElementById('eqbPreviewClose');
    if (prevClose && prevOverlay) {
      prevClose.addEventListener('click', function () {
        prevOverlay.hidden = true;
        prevOverlay.classList.remove('is-open');
      });
      prevOverlay.addEventListener('click', function (e) {
        if (e.target === prevOverlay) {
          prevOverlay.hidden = true;
          prevOverlay.classList.remove('is-open');
        }
      });
    }

    if (new URLSearchParams(location.search).get('focus') === 'builder') {
      var form = document.getElementById('questionForm') || document.getElementById('diagQuestionForm');
      if (form) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });
})();
</script>
</body>
</html>
