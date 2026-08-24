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
if ($examType === 'diagnostic') {
    $adminHeroTitle = 'Diagnostic Exam';
    $adminHeroSubtitle = (string)($rec['title'] ?? 'CPA Diagnostic Assessment');
} else {
    $adminHeroTitle = 'Questions';
    $adminHeroSubtitle = ($rec['exam_type_label'] ?? '') . ' · ' . ($rec['title'] ?? '');
}
$adminHeroActions = '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_examinations"><i class="bi bi-arrow-left"></i> Back to Examinations</a>';
$activeStep = 'questions';

$regularQuestions = $examType === 'regular' ? examination_questions_load_regular($conn, $sourceId) : [];
$diagnosticSupply = $examType === 'diagnostic' ? examination_questions_diagnostic_supply($conn, $sourceId) : null;

$questionsBaseUrl = examination_domain_edit_url($examType, $sourceId, 'questions');
$importTemplateUrl = $questionsBaseUrl . (str_contains($questionsBaseUrl, '?') ? '&' : '?') . 'download_question_template=1';
$importTemplateCsvUrl = $importTemplateUrl . '&format=csv';
$importErrors = (isset($importErrors) && is_array($importErrors)) ? $importErrors : [];
$ajaxQuestionsUrl = 'professor_examination_questions_ajax';

/**
 * @param list<array> $rows
 * @return list<array<string,mixed>>
 */
$eqbMapClientRows = static function (array $rows): array {
    $out = [];
    foreach ($rows as $i => $q) {
        if (!is_array($q)) {
            continue;
        }
        $type = strtolower((string)($q['question_type'] ?? 'mcq')) === 'tf' ? 'tf' : 'mcq';
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($q['question_text'] ?? ''))) ?? '');
        $preview = $plain;
        if (function_exists('mb_strlen') && mb_strlen($preview) > 140) {
            $preview = mb_substr($preview, 0, 137) . '…';
        } elseif (strlen($preview) > 140) {
            $preview = substr($preview, 0, 137) . '…';
        }
        $ans = strtoupper((string)($q['correct_answer'] ?? ''));
        $ansLabel = $ans !== '' ? $ans : '—';
        if ($type === 'tf') {
            $ansLabel = $ans === 'A' ? 'True' : ($ans === 'B' ? 'False' : $ansLabel);
        }
        $out[] = [
            'question_id' => (int)($q['question_id'] ?? 0),
            'question_type' => $type,
            'question_text' => (string)($q['question_text'] ?? ''),
            'preview' => $preview !== '' ? $preview : '—',
            'choice_a' => (string)($q['choice_a'] ?? ''),
            'choice_b' => (string)($q['choice_b'] ?? ''),
            'choice_c' => (string)($q['choice_c'] ?? ''),
            'choice_d' => (string)($q['choice_d'] ?? ''),
            'extra_choices' => function_exists('examination_questions_diagnostic_extra_choices_decode')
                ? examination_questions_diagnostic_extra_choices_decode(isset($q['extra_choices_json']) ? (string)$q['extra_choices_json'] : null)
                : [],
            'correct_answer' => $ans,
            'type_label' => $type === 'tf' ? 'True/False' : 'Multiple',
            'answer_label' => $ansLabel,
            'display_number' => $i + 1,
        ];
    }

    return $out;
};
$regularClientRows = $examType === 'regular' ? $eqbMapClientRows($regularQuestions) : [];
$diagClientRows = [];
$diagSubjectCode = '';
$diagSubjectName = '';
$diagRequired = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page<?php echo $examType === 'diagnostic' ? ' diag-exam-portal' : ''; ?>">
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
<?php if (!empty($importErrors)): ?>
  <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl">
    <div class="font-bold mb-1">Import was not applied. Fix these rows and try again:</div>
    <ul class="m-0 pl-4 text-sm">
      <?php foreach (array_slice($importErrors, 0, 25) as $ie): ?>
        <li>Row <?php echo (int)($ie['row'] ?? 0); ?> — <?php echo h((string)($ie['message'] ?? '')); ?></li>
      <?php endforeach; ?>
      <?php if (count($importErrors) > 25): ?>
        <li>…and <?php echo count($importErrors) - 25; ?> more</li>
      <?php endif; ?>
    </ul>
  </div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/examination_edit_steps.php'; ?>

<?php if ($locked): ?>
  <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2">
    <i class="bi bi-lock-fill"></i>
    <span>Questions are locked — this examination already has <?php echo (int)$attemptCount; ?> student attempt(s). Existing question IDs and answers are preserved.</span>
  </div>
<?php endif; ?>

<?php if ($examType === 'regular'): ?>
  <section class="eqb-panel page-table mb-4">
    <div class="eqb-panel__head">
      <div>
        <h2 class="eqb-panel__title">Questions</h2>
        <p class="eqb-panel__sub" id="eqbQuestionCount"><strong><?php echo count($regularQuestions); ?></strong> Question<?php echo count($regularQuestions) === 1 ? '' : 's'; ?></p>
      </div>
      <?php if (!$locked): ?>
        <div class="eqb-panel__actions">
          <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" data-eqb-open-add><i class="bi bi-plus-lg"></i> Add Questions</button>
          <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" id="btnOpenImport"><i class="bi bi-upload"></i> Import Questions</button>
        </div>
      <?php endif; ?>
    </div>

    <div class="eqb-toolbar eqb-toolbar--filters">
      <input type="search" id="eqbSearch" class="eqb-search" placeholder="Search questions…" autocomplete="off">
      <label class="eqb-filter">
        <span class="sr-only">Type</span>
        <select id="eqbTypeFilter" class="eqb-select eqb-filter-select" aria-label="Filter by type">
          <option value="all">Type: All</option>
          <option value="mcq">Multiple Choice</option>
          <option value="tf">True or False</option>
        </select>
      </label>
    </div>

    <div class="students-table-scroll eqb-table-wrap">
      <table class="w-full text-left admin-students-table students-table--compact eqb-table">
        <thead>
          <tr>
            <th scope="col">#</th>
            <th scope="col">Question Preview</th>
            <th scope="col">Type</th>
            <th scope="col" class="student-actions-head">Actions</th>
          </tr>
        </thead>
        <tbody id="eqbQuestionRows">
          <?php if ($regularClientRows === []): ?>
            <tr><td colspan="4" class="students-empty-cell">No questions yet. Use Add Questions or Import Questions.</td></tr>
          <?php else: ?>
            <?php foreach ($regularClientRows as $row): ?>
              <?php
                $hay = function_exists('mb_strtolower')
                  ? mb_strtolower($row['preview'] . ' ' . $row['question_text'] . ' ' . $row['correct_answer'])
                  : strtolower($row['preview'] . ' ' . $row['question_text'] . ' ' . $row['correct_answer']);
              ?>
              <tr data-eqb-row data-eqb-id="<?php echo (int)$row['question_id']; ?>" data-eqb-type="<?php echo h($row['question_type']); ?>" data-eqb-search="<?php echo h($hay); ?>">
                <td><?php echo (int)$row['display_number']; ?></td>
                <td><?php echo h($row['preview']); ?></td>
                <td><span class="eqb-type"><?php echo h($row['type_label']); ?></span></td>
                <td class="eqb-row-actions">
                  <?php if (!$locked): ?>
                    <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" data-eqb-edit="<?php echo (int)$row['question_id']; ?>">Edit</button>
                    <div class="admin-student-action-menu-wrap eqb-more-wrap">
                      <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm admin-student-action-menu-trigger" aria-label="More actions" data-eqb-more>⋮</button>
                      <div class="admin-student-action-menu" hidden>
                        <button type="button" class="admin-student-action-item" data-eqb-preview-id="<?php echo (int)$row['question_id']; ?>">Preview</button>
                        <form method="post" class="eqb-inline-form">
                          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                          <input type="hidden" name="action" value="duplicate_question">
                          <input type="hidden" name="exam_type" value="regular">
                          <input type="hidden" name="exam_id" value="<?php echo (int)$sourceId; ?>">
                          <input type="hidden" name="question_id" value="<?php echo (int)$row['question_id']; ?>">
                          <button type="submit" class="admin-student-action-item">Duplicate</button>
                        </form>
                        <form method="post" class="eqb-inline-form" data-eqb-delete-form>
                          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                          <input type="hidden" name="action" value="delete_question">
                          <input type="hidden" name="exam_type" value="regular">
                          <input type="hidden" name="exam_id" value="<?php echo (int)$sourceId; ?>">
                          <input type="hidden" name="question_id" value="<?php echo (int)$row['question_id']; ?>">
                          <button type="submit" class="admin-student-action-item is-danger">Delete</button>
                        </form>
                      </div>
                    </div>
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

<?php else: /* diagnostic */ ?>
  <?php
    $diagSubjects = is_array($diagnosticSupply['subjects'] ?? null) ? $diagnosticSupply['subjects'] : [];
    if ($focusSubjectId <= 0 && $diagSubjects !== []) {
        $focusSubjectId = (int)($diagSubjects[0]['subject_id'] ?? 0);
    }
    $activeSubject = null;
    foreach ($diagSubjects as $sub) {
        if ((int)($sub['subject_id'] ?? 0) === $focusSubjectId) {
            $activeSubject = $sub;
            break;
        }
    }
    if (!$activeSubject && $diagSubjects !== []) {
        $activeSubject = $diagSubjects[0];
        $focusSubjectId = (int)($activeSubject['subject_id'] ?? 0);
    }
    $diagClientRows = $activeSubject ? $eqbMapClientRows($activeSubject['questions'] ?? []) : [];
    $diagSubjectCode = $activeSubject ? (string)($activeSubject['subject_code'] ?? '') : '';
    $diagSubjectName = $activeSubject ? (string)($activeSubject['subject_name'] ?? '') : '';
    $diagRequired = $activeSubject ? (int)($activeSubject['required'] ?? 0) : 0;
    $diagAuthored = $activeSubject ? (int)($activeSubject['authored'] ?? count($diagClientRows)) : 0;
    $diagCompletedToward = $diagRequired > 0 ? min($diagAuthored, $diagRequired) : $diagAuthored;
    $diagPct = ($diagRequired > 0) ? (int)round(($diagCompletedToward / $diagRequired) * 100) : ($diagAuthored > 0 ? 100 : 0);
    $diagRequirementMet = $activeSubject ? !empty($activeSubject['ok']) : false;
    $diagSlotCount = max($diagRequired > 0 ? $diagRequired : 0, $diagAuthored, $diagAuthored > 0 ? $diagAuthored : ($diagRequired > 0 ? $diagRequired : 0));
    if ($diagSlotCount < 1 && $diagRequired <= 0) {
        $diagSlotCount = 0;
    }
  ?>

  <section class="diag-authoring diag-panel mb-4">
    <header class="diag-authoring__header">
      <h2 class="diag-panel__title">Questions</h2>
      <p class="diag-panel__desc">Encode questions by subject. Required counts come from Configuration. Empty slots are not created in the database.</p>
    </header>

    <?php if ($diagSubjects === []): ?>
      <div class="examination-empty-box">
        <p class="font-bold m-0">No subjects configured</p>
        <p class="text-sm mt-2 mb-0 opacity-80">Select subjects and required counts in Configuration first.</p>
        <a class="admin-btn admin-btn--secondary admin-btn--sm mt-3" href="<?php echo h(examination_domain_edit_url($examType, $sourceId, 'config')); ?>">Open Configuration</a>
      </div>
    <?php else: ?>
      <div class="diag-authoring__subjects">
        <nav class="diag-subject-nav" aria-label="Diagnostic subjects" id="diagSubjectNav">
          <?php foreach ($diagSubjects as $sub): ?>
            <?php
              $sid = (int)$sub['subject_id'];
              $tabUrl = $questionsBaseUrl . (str_contains($questionsBaseUrl, '?') ? '&' : '?') . 'subject_id=' . $sid;
              $ok = !empty($sub['ok']);
              $req = (int)($sub['required'] ?? 0);
              $authored = (int)($sub['authored'] ?? 0);
              $toward = $req > 0 ? min($authored, $req) : $authored;
              $meta = $req > 0
                ? ($toward . '/' . $req)
                : ($authored . ' authored');
            ?>
            <a class="diag-subject-nav__tab <?php echo $focusSubjectId === $sid ? 'is-active' : ''; ?> <?php echo $ok ? 'is-complete' : ''; ?>"
               href="<?php echo h($tabUrl); ?>"
               data-diag-tab
               data-subject-id="<?php echo $sid; ?>"
               data-required="<?php echo $req; ?>"
               data-authored="<?php echo $authored; ?>">
              <span class="diag-subject-nav__code"><?php echo h((string)$sub['subject_code']); ?><?php echo $ok ? ' ✓' : ''; ?></span>
              <span class="diag-subject-nav__meta" data-diag-tab-meta><?php echo h($meta); ?></span>
            </a>
          <?php endforeach; ?>
        </nav>
      </div>

      <?php if ($activeSubject): ?>
        <div class="diag-subject-stage" id="diagSubjectStage"
             data-required="<?php echo (int)$diagRequired; ?>"
             data-authored="<?php echo (int)$diagAuthored; ?>"
             data-subject-code="<?php echo h($diagSubjectCode); ?>"
             data-subject-name="<?php echo h($diagSubjectName); ?>">
          <div class="diag-subject-stage__intro">
            <div>
              <h3 class="diag-subject-stage__code" id="diagStageCode"><?php echo h($diagSubjectCode); ?></h3>
              <p class="diag-subject-stage__fullname"><?php echo h($diagSubjectName); ?></p>
              <p class="diag-subject-stage__name" id="diagStageName">
                <?php if ($diagRequired > 0): ?>
                  <span id="diagStageProgressLabel"><?php echo (int)$diagRequired; ?> questions required · <?php echo (int)$diagAuthored; ?> / <?php echo (int)$diagRequired; ?> authored<?php echo $diagRequirementMet ? ' ✓' : ''; ?></span>
                <?php else: ?>
                  <span id="diagStageProgressLabel"><?php echo (int)$diagAuthored; ?> authored · use all (need ≥ 1)</span>
                <?php endif; ?>
              </p>
            </div>
            <?php if (!$locked): ?>
              <div class="diag-subject-stage__actions">
                <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" data-eqb-open-add><i class="bi bi-plus-lg"></i> Add Question</button>
                <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" id="btnOpenImportDiag"><i class="bi bi-upload"></i> Import</button>
              </div>
            <?php endif; ?>
          </div>

          <div class="diag-progress" id="diagProgressBlock">
            <?php if ($diagRequired > 0): ?>
              <div class="diag-progress__row">
                <span id="diagRequiredLabel"><strong><?php echo (int)$diagRequired; ?></strong> questions required</span>
                <span id="diagCompletedLabel"><strong id="diagCompletedNum"><?php echo (int)$diagCompletedToward; ?></strong> / <?php echo (int)$diagRequired; ?> authored</span>
              </div>
              <div class="diag-progress__bar" role="progressbar" aria-valuenow="<?php echo (int)$diagPct; ?>" aria-valuemin="0" aria-valuemax="100">
                <span class="diag-progress__fill" id="diagProgressFill" style="width:<?php echo (int)$diagPct; ?>%"></span>
              </div>
              <p class="diag-progress__status" id="diagProgressStatus">
                <?php if ($diagRequirementMet): ?>
                  <span class="diag-progress__done">✓ Target reached · <?php echo (int)$diagAuthored; ?> / <?php echo (int)$diagRequired; ?> questions</span>
                <?php elseif ($diagAuthored > $diagRequired): ?>
                  Required: <?php echo (int)$diagRequired; ?> · Authored: <?php echo (int)$diagAuthored; ?> (extra questions stay in the pool; first <?php echo (int)$diagRequired; ?> are used)
                <?php else: ?>
                  <?php echo (int)$diagAuthored; ?> / <?php echo (int)$diagRequired; ?> questions authored
                <?php endif; ?>
              </p>
            <?php else: ?>
              <div class="diag-progress__row">
                <span>Required: use all authored (need ≥ 1)</span>
                <span id="eqbQuestionCount" data-extra=""><strong><?php echo (int)$diagAuthored; ?></strong> authored</span>
              </div>
              <p class="diag-progress__status" id="diagProgressStatus">
                <?php echo $diagAuthored >= 1 ? '✓ At least one question authored for this subject.' : 'Add at least one question for this subject.'; ?>
              </p>
            <?php endif; ?>
            <?php if ($diagRequired > 0): ?>
              <span id="eqbQuestionCount" class="sr-only" data-extra="" hidden><strong><?php echo (int)$diagAuthored; ?></strong> Questions</span>
            <?php endif; ?>
          </div>

          <div class="students-table-scroll eqb-table-wrap diag-q-table-wrap">
            <table class="w-full text-left admin-students-table students-table--compact eqb-table eqb-table--diagnostic">
              <thead>
                <tr>
                  <th scope="col">#</th>
                  <th scope="col">Question</th>
                  <th scope="col">Type</th>
                  <th scope="col">Correct Answer</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>
              <tbody id="diagSlotList">
                <?php if ($diagClientRows === []): ?>
                  <tr class="diag-q-empty-row">
                    <td colspan="5" class="students-empty-cell">No questions authored yet for <?php echo h($diagSubjectCode); ?>. Use Add Question to begin.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($diagClientRows as $ri => $row): ?>
                    <tr data-eqb-row data-eqb-id="<?php echo (int)$row['question_id']; ?>" data-eqb-type="mcq"
                      data-eqb-search="<?php echo h(function_exists('mb_strtolower') ? mb_strtolower($row['preview'] . ' ' . $row['question_text']) : strtolower($row['preview'] . ' ' . $row['question_text'])); ?>">
                      <td><?php echo (int)($ri + 1); ?></td>
                      <td class="diag-q-table__question"><?php echo h($row['preview']); ?></td>
                      <td><span class="eqb-type"><?php echo h($row['type_label']); ?></span></td>
                      <td><?php echo h($row['answer_label']); ?></td>
                      <td class="eqb-row-actions">
                        <?php if (!$locked): ?>
                          <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" data-eqb-edit="<?php echo (int)$row['question_id']; ?>">Edit</button>
                          <form method="post" class="eqb-inline-form diag-q-inline-delete" data-eqb-delete-form>
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                            <input type="hidden" name="action" value="delete_question">
                            <input type="hidden" name="exam_type" value="diagnostic">
                            <input type="hidden" name="batch_id" value="<?php echo (int)$sourceId; ?>">
                            <input type="hidden" name="subject_id" value="<?php echo (int)$activeSubject['subject_id']; ?>">
                            <input type="hidden" name="question_id" value="<?php echo (int)$row['question_id']; ?>">
                            <button type="submit" class="admin-btn admin-btn--ghost admin-btn--sm is-danger">Delete</button>
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

          <!-- Mount for JS table refresh compatibility -->
          <div id="eqbQuestionRows" class="diag-js-mount" hidden aria-hidden="true"></div>
          <input type="search" id="eqbSearch" class="sr-only" tabindex="-1" aria-hidden="true">

          <?php if (!$locked): ?>
            <div class="diag-authoring__footer-add">
              <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" data-eqb-open-add><i class="bi bi-plus-lg"></i> Add Question</button>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>

<div class="flex flex-wrap gap-3 mb-6">
  <a class="admin-btn admin-btn--secondary" href="<?php echo h(examination_domain_edit_url($examType, $sourceId, 'config')); ?>"><i class="bi bi-arrow-left"></i> Configuration</a>
  <a class="admin-btn admin-btn--primary" href="<?php echo h(examination_domain_edit_url($examType, $sourceId, 'review')); ?>">Continue to Review <i class="bi bi-arrow-right"></i></a>
</div>

<?php
  $eqbWorkspace = ($examType === 'regular') || ($examType === 'diagnostic' && $focusSubjectId > 0);
  $rapidEnabled = $eqbWorkspace && !$locked;
  $rapidQuestions = $examType === 'regular' ? $regularClientRows : $diagClientRows;
  $diagSubjectLabel = ($examType === 'diagnostic' && !empty($diagSubjectCode)) ? $diagSubjectCode : '';
  $diagSubjectNameBoot = ($examType === 'diagnostic' && !empty($diagSubjectName)) ? $diagSubjectName : '';
  $diagRequiredBoot = ($examType === 'diagnostic') ? (int)($diagRequired ?? 0) : 0;
?>

<?php if ($rapidEnabled): ?>
<div id="eqbRapidOverlay" class="admin-modal-overlay" hidden>
  <div class="admin-modal page-table p-0 eqb-rapid-modal" role="dialog" aria-modal="true" aria-labelledby="eqbRapidTitle">
    <div class="eqb-rapid-modal__header">
      <div>
        <h3 id="eqbRapidTitle" class="text-base font-bold m-0"><?php echo $examType === 'diagnostic' ? 'Add Question' : 'Add Questions'; ?></h3>
        <p id="eqbRapidSubtitle" class="eqb-rapid-modal__subtitle m-0"><?php echo $examType === 'diagnostic' ? h($diagSubjectLabel . ($diagRequiredBoot > 0 ? ' · Question 1 of ' . $diagRequiredBoot : '')) : ''; ?></p>
      </div>
      <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="eqbRapidClose" aria-label="Close">×</button>
    </div>
    <div class="eqb-rapid-modal__body" id="eqbRapidList"></div>
    <div class="eqb-rapid-modal__footer">
      <?php if ($examType === 'diagnostic'): ?>
        <button type="button" class="admin-btn admin-btn--ghost" id="eqbRapidCancel">Cancel</button>
        <div class="eqb-rapid-modal__footer-right">
          <button type="button" class="admin-btn admin-btn--secondary" id="eqbAddAnotherBtn"><i class="bi bi-plus-lg"></i> Save &amp; Add Another</button>
          <button type="button" class="admin-btn admin-btn--primary" id="eqbRapidSaveBtn">Save</button>
        </div>
      <?php else: ?>
        <button type="button" class="admin-btn admin-btn--secondary" id="eqbAddAnotherBtn"><i class="bi bi-plus-lg"></i> Add Another Question</button>
        <button type="button" class="admin-btn admin-btn--primary" id="eqbRapidCloseFooter">Close</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<div id="eqbEditOverlay" class="admin-modal-overlay" hidden>
  <div class="admin-modal page-table p-0 eqb-rapid-modal eqb-rapid-modal--edit" role="dialog" aria-modal="true" aria-labelledby="eqbEditTitle">
    <div class="eqb-rapid-modal__header">
      <div>
        <h3 id="eqbEditTitle" class="text-base font-bold m-0"><?php echo $examType === 'diagnostic' ? ('Edit Question' . ($diagSubjectLabel !== '' ? ' — ' . h($diagSubjectLabel) : '')) : 'Edit Question'; ?></h3>
        <p id="eqbEditSubtitle" class="eqb-rapid-modal__subtitle m-0"></p>
      </div>
      <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" id="eqbEditClose" aria-label="Close">×</button>
    </div>
    <div class="eqb-rapid-modal__body" id="eqbEditMount"></div>
    <div class="eqb-rapid-modal__footer">
      <button type="button" class="admin-btn admin-btn--primary" id="eqbEditCloseFooter"><?php echo $examType === 'diagnostic' ? 'Save &amp; Close' : 'Close'; ?></button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!$locked): ?>
<?php $diagImportNeedsSubject = ($examType === 'diagnostic' && $focusSubjectId <= 0); ?>
<div id="importOverlay" class="admin-modal-overlay" hidden>
  <div class="admin-modal page-table p-5" style="width:min(100%,42rem);max-height:90vh;overflow:auto;">
    <h3 class="text-base font-bold m-0 mb-1">Import Questions</h3>
    <p class="text-sm opacity-70 mb-3">
      Recommended: download the template, fill it in, then upload.
      Importing <strong>adds</strong> questions — it does not replace existing ones.
      <?php if ($examType === 'diagnostic' && $diagSubjectLabel !== ''): ?>
        Importing into <strong><?php echo h($diagSubjectLabel); ?></strong>.
      <?php endif; ?>
    </p>
    <form method="post" id="importForm" enctype="multipart/form-data">
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
      <input type="hidden" name="import_mode" id="importMode" value="csv">
      <input type="hidden" name="import_paste" id="importPasteHidden" value="">
      <ol class="eqb-import-steps text-sm mb-4 pl-4" style="list-style:decimal;">
        <li class="mb-3">
          <strong>Download Template</strong>
          <div class="mt-1 flex flex-wrap gap-2 items-center">
            <a class="admin-btn admin-btn--secondary admin-btn--sm" href="<?php echo h($importTemplateUrl); ?>"><i class="bi bi-download"></i> Download Word Template</a>
            <a class="admin-btn admin-btn--ghost admin-btn--sm" href="<?php echo h($importTemplateCsvUrl); ?>">CSV fallback</a>
          </div>
          <p class="text-xs opacity-70 m-0 mt-1">Preferred: Word (.docx) with numbered questions, A.–D. choices, and Answer: … True/False uses True / False and Answer: True.</p>
        </li>
        <li class="mb-3">
          <strong>Add Your Questions</strong>
          <p class="text-xs opacity-70 m-0 mt-1">Edit the Word template (or CSV fallback). Keep the numbered structure and Answer lines. Remove the examples before importing.</p>
        </li>
        <li class="mb-3">
          <strong>Upload / Paste</strong>
          <div class="flex gap-2 mt-2 mb-2">
            <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm import-tab is-active" data-tab="file">Upload Completed Template</button>
            <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm import-tab" data-tab="paste">Paste Data</button>
          </div>
          <div id="importFilePanel">
            <input type="file" id="importFileInput" name="import_file" accept=".docx,.csv,.txt,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/csv,text/plain" class="text-sm w-full">
            <p class="text-xs opacity-70 m-0 mt-1">Upload the completed Word template (.docx). CSV is accepted as a fallback.</p>
          </div>
          <div id="importPastePanel" class="hidden">
            <textarea id="importPasteText" class="w-full font-mono text-xs mb-1" rows="7" placeholder="1. Question text&#10;&#10;A. Choice&#10;B. Choice&#10;C. Choice&#10;D. Choice&#10;&#10;Answer: B"></textarea>
            <p class="text-xs opacity-70 m-0">Paste the same Word structure, or CSV rows as a fallback.</p>
          </div>
        </li>
        <li class="mb-3">
          <strong>Validate &amp; Preview</strong>
          <div class="mt-1">
            <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" id="importValidateBtn"><i class="bi bi-shield-check"></i> Validate &amp; Preview</button>
          </div>
          <div id="importPreviewBox" class="mt-2 p-3 rounded-lg border text-sm" hidden></div>
        </li>
        <li>
          <strong>Import</strong>
          <p class="text-xs opacity-70 m-0 mt-1">Import stays disabled until every question passes validation. Server re-checks all rows and imports atomically.</p>
        </li>
      </ol>
      <?php if ($diagImportNeedsSubject): ?>
        <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl text-sm">Select a subject tab before importing diagnostic questions.</div>
      <?php endif; ?>
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="admin-btn admin-btn--primary" id="importSubmit" disabled <?php echo $diagImportNeedsSubject ? 'aria-disabled="true"' : ''; ?>>Import Questions</button>
        <button type="button" class="admin-btn admin-btn--ghost" id="importCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var overlay = document.getElementById('importOverlay');
  if (!overlay) return;
  var examType = <?php echo json_encode($examType); ?>;
  var diagNeedsSubject = <?php echo $diagImportNeedsSubject ? 'true' : 'false'; ?>;
  var csvEl = document.getElementById('importPasteText');
  var fileInput = document.getElementById('importFileInput');
  var filePanel = document.getElementById('importFilePanel');
  var pastePanel = document.getElementById('importPastePanel');
  var previewBox = document.getElementById('importPreviewBox');
  var jsonEl = document.getElementById('importJson');
  var modeEl = document.getElementById('importMode');
  var pasteHidden = document.getElementById('importPasteHidden');
  var submitBtn = document.getElementById('importSubmit');
  var validateBtn = document.getElementById('importValidateBtn');
  var tab = 'file';
  var validatedOk = false;
  var pendingRows = [];

  function splitCsvLine(line) {
    var parts = [], cur = '', inQ = false;
    for (var c = 0; c < line.length; c++) {
      var ch = line[c];
      if (ch === '"') { inQ = !inQ; continue; }
      if (ch === ',' && !inQ) { parts.push(cur); cur = ''; continue; }
      cur += ch;
    }
    parts.push(cur);
    return parts;
  }
  function parseCsv(text) {
    var lines = String(text || '').split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
    if (!lines.length) return [];
    var start = 0;
    if (/question/i.test(lines[0]) && /correct/i.test(lines[0])) start = 1;
    var rows = [];
    for (var i = start; i < lines.length; i++) {
      var parts = splitCsvLine(lines[i]);
      if (parts.length < 2) continue;
      var type = String(parts[1] || 'mcq').toLowerCase();
      if (type !== 'tf') type = 'mcq';
      rows.push({
        question_text: String(parts[0] || '').trim(),
        question_type: type,
        choice_a: String(parts[2] || '').trim(),
        choice_b: String(parts[3] || '').trim(),
        choice_c: String(parts[4] || '').trim(),
        choice_d: String(parts[5] || '').trim(),
        correct_answer: String(parts[6] || '').trim().toUpperCase(),
        _source_row: i + 1
      });
    }
    return rows;
  }
  function parsePaste(text) {
    var raw = String(text || '').split(/\r?\n/).map(function (l) { return l.trim(); });
    var rows = [], i = 0;
    function isChoice(line) { return /^[A-Da-d][\)\.\:\-]\s*/.test(line); }
    function isAnswer(line) { return /^answer\s*[:\-]/i.test(line); }
    function isBareTf(line) { return /^(true|false)$/i.test(line); }
    while (i < raw.length) {
      while (i < raw.length && raw[i] === '') i++;
      if (i >= raw.length) break;
      var startLine = i + 1, stemParts = [];
      while (i < raw.length && raw[i] !== '' && !isChoice(raw[i]) && !isAnswer(raw[i]) && !isBareTf(raw[i])) {
        stemParts.push(raw[i]); i++;
      }
      while (i < raw.length && raw[i] === '') i++;
      var choices = { A: '', B: '', C: '', D: '' }, type = 'mcq', sawTf = false, ans = '';
      if (i < raw.length && isBareTf(raw[i])) {
        sawTf = true; type = 'tf';
        while (i < raw.length && isBareTf(raw[i])) {
          if (/^true$/i.test(raw[i])) choices.A = 'True'; else choices.B = 'False';
          i++;
        }
      } else {
        while (i < raw.length && isChoice(raw[i])) {
          var m = raw[i].match(/^([A-Da-d])[\)\.\:\-]\s*(.*)$/);
          if (m) choices[m[1].toUpperCase()] = (m[2] || '').trim();
          i++;
        }
      }
      while (i < raw.length && raw[i] === '') i++;
      if (i < raw.length && isAnswer(raw[i])) {
        var am = raw[i].match(/^answer\s*[:\-]\s*(.+)$/i);
        var ansRaw = am ? String(am[1] || '').trim() : '';
        if (sawTf || /^(true|false)$/i.test(ansRaw)) {
          type = 'tf'; choices = { A: 'True', B: 'False', C: '', D: '' };
          ans = (/^true$/i.test(ansRaw) || /^a$/i.test(ansRaw)) ? 'A' : 'B';
        } else if (/^[A-Da-d]/.test(ansRaw)) ans = ansRaw.charAt(0).toUpperCase();
        i++;
      }
      var stem = stemParts.join(' ').replace(/^\d+[\.\)]\s+/, '').trim();
      if (!stem && !ans && !choices.A && !choices.B) continue;
      if (type === 'tf') choices = { A: 'True', B: 'False', C: '', D: '' };
      rows.push({ question_text: stem, question_type: type, choice_a: choices.A, choice_b: choices.B, choice_c: choices.C, choice_d: choices.D, correct_answer: ans, _source_row: startLine });
    }
    return rows;
  }
  function validateRowClient(row) {
    var type = (row.question_type || 'mcq').toLowerCase();
    if (type !== 'tf') type = 'mcq';
    var qt = String(row.question_text || '').replace(/<[^>]+>/g, '').trim();
    if (!qt) return 'Question text is required.';
    if (examType === 'diagnostic' && type === 'tf') return 'Diagnostic examinations currently support Multiple Choice questions only.';
    var a = String(row.choice_a || '').trim(), b = String(row.choice_b || '').trim();
    var c = String(row.choice_c || '').trim(), d = String(row.choice_d || '').trim();
    var cor = String(row.correct_answer || '').trim().toUpperCase();
    if (type === 'tf') {
      if (c || d) return 'True or False questions may only contain True and False choices. Leave choice C and D blank.';
      if (cor !== 'A' && cor !== 'B') return 'Select True or False as the correct answer.';
      return '';
    }
    if (!a || !b) return 'Multiple Choice questions require at least two choices (A and B).';
    if (!c && d) return 'Choice D cannot be filled while Choice C is empty.';
    if (!cor || !/^[A-D]$/.test(cor)) return 'Please select the correct answer.';
    var map = { A: a, B: b, C: c, D: d };
    if (!String(map[cor] || '').trim()) return 'Correct answer must match one of the available choices.';
    return '';
  }
  function invalidate() {
    validatedOk = false; pendingRows = []; jsonEl.value = '[]';
    if (submitBtn) submitBtn.disabled = true;
    if (previewBox) { previewBox.hidden = true; previewBox.innerHTML = ''; }
  }
  function selectedFileExt() {
    if (!fileInput || !fileInput.files || !fileInput.files[0]) return '';
    var name = String(fileInput.files[0].name || ''), dot = name.lastIndexOf('.');
    return dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
  }
  function collectRows() {
    if (tab === 'file') {
      return Promise.resolve().then(function () {
        if (!fileInput || !fileInput.files || !fileInput.files[0]) return [];
        var ext = selectedFileExt();
        if (ext === 'docx') return { __docx: true };
        return new Promise(function (resolve, reject) {
          var reader = new FileReader();
          reader.onload = function () {
            var text = String(reader.result || '');
            if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);
            resolve(ext === 'csv' || ext === 'txt' ? parseCsv(text) : parsePaste(text));
          };
          reader.onerror = function () { reject(new Error('read_failed')); };
          reader.readAsText(fileInput.files[0]);
        });
      });
    }
    return Promise.resolve(parsePaste(csvEl ? csvEl.value : ''));
  }
  function runValidate() {
    if (diagNeedsSubject) {
      previewBox.hidden = false;
      previewBox.innerHTML = '<strong>Please select a subject before importing diagnostic questions.</strong>';
      invalidate(); return;
    }
    collectRows().then(function (rows) {
      if (rows && rows.__docx) {
        previewBox.hidden = false;
        previewBox.innerHTML = '<strong>Import Preview</strong><br>Word (.docx) file selected.<br>All questions will be checked on the server when you click <strong>Import Questions</strong>. If anything is invalid, nothing will be imported.';
        validatedOk = true; pendingRows = [{ __docx: true }]; jsonEl.value = '[]'; submitBtn.disabled = false; return;
      }
      pendingRows = rows;
      var errors = [];
      rows.forEach(function (row) { var msg = validateRowClient(row); if (msg) errors.push({ row: row._source_row || 0, message: msg }); });
      previewBox.hidden = false;
      if (!rows.length) {
        previewBox.innerHTML = '<strong>Import Preview</strong><br>No questions detected.';
        validatedOk = false; submitBtn.disabled = true; jsonEl.value = '[]'; return;
      }
      var invalid = errors.length, valid = rows.length - invalid;
      var html = '<strong>Import Preview</strong><br>' + rows.length + ' question' + (rows.length === 1 ? '' : 's') + ' detected<br>';
      if (!invalid) {
        html += '<span style="color:#15803d;">✓ ' + valid + ' valid</span><br>Ready to import.';
        validatedOk = true; jsonEl.value = JSON.stringify(rows); submitBtn.disabled = false;
      } else {
        html += '<span style="color:#15803d;">✓ ' + Math.max(0, valid) + ' valid</span><br><span style="color:#b91c1c;">✗ ' + invalid + ' invalid</span><br><br><strong>Errors:</strong><ul class="m-0 pl-4">';
        errors.slice(0, 20).forEach(function (e) { html += '<li>Row ' + e.row + ' — ' + e.message.replace(/</g, '&lt;') + '</li>'; });
        if (errors.length > 20) html += '<li>…and ' + (errors.length - 20) + ' more</li>';
        html += '</ul>';
        validatedOk = false; submitBtn.disabled = true; jsonEl.value = '[]';
      }
      previewBox.innerHTML = html;
    }).catch(function () {
      previewBox.hidden = false;
      previewBox.innerHTML = '<strong>Could not read the file. Please try again or paste the data.</strong>';
      invalidate();
    });
  }
  document.querySelectorAll('.import-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
      tab = btn.getAttribute('data-tab') || 'file';
      document.querySelectorAll('.import-tab').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      if (filePanel) filePanel.classList.toggle('hidden', tab !== 'file');
      if (pastePanel) pastePanel.classList.toggle('hidden', tab !== 'paste');
      if (modeEl) modeEl.value = tab === 'paste' ? 'paste' : 'csv';
      invalidate();
    });
  });
  if (fileInput) fileInput.addEventListener('change', invalidate);
  if (csvEl) csvEl.addEventListener('input', invalidate);
  if (validateBtn) validateBtn.addEventListener('click', runValidate);
  document.getElementById('importForm').addEventListener('submit', function (e) {
    var isDocx = tab === 'file' && selectedFileExt() === 'docx';
    if (diagNeedsSubject) { e.preventDefault(); runValidate(); return; }
    if (isDocx) { jsonEl.value = '[]'; if (pasteHidden) pasteHidden.value = ''; return; }
    if (!validatedOk || !pendingRows.length) { e.preventDefault(); runValidate(); return; }
    jsonEl.value = JSON.stringify(pendingRows.filter(function (r) { return !r.__docx; }));
    if (tab === 'paste' && pasteHidden && csvEl) pasteHidden.value = csvEl.value;
  });
  function openImport() { overlay.hidden = false; overlay.classList.add('is-open'); invalidate(); }
  function closeImport() { overlay.hidden = true; overlay.classList.remove('is-open'); }
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

<?php if ($eqbWorkspace): ?>
<?php
  $eqbJsFile = dirname(__DIR__, 2) . '/assets/js/examination_question_rapid_entry.js';
  $assetScriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  $adjusted = preg_replace('#/examination/(?:professor|examinee)/[^/]+$#', '/index.php', $assetScriptName);
  if (is_string($adjusted) && $adjusted !== '') {
      $assetScriptName = $adjusted;
  }
  $eqbJsBase = rtrim(str_replace('\\', '/', dirname($assetScriptName)), '/');
  if ($eqbJsBase === '.' || $eqbJsBase === '') {
      $eqbJsBase = '';
  }
  $eqbJsHref = $eqbJsBase . '/assets/js/examination_question_rapid_entry.js';
  if (is_file($eqbJsFile)) {
      $eqbJsHref .= '?v=' . filemtime($eqbJsFile);
  }
?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.6/tinymce.min.js" referrerpolicy="origin"></script>
<script src="<?php echo h($eqbJsHref); ?>"></script>
<script>
(function () {
  if (!window.EreviewQuestionRapidEntry) return;
  var bootstrap = <?php echo json_encode([
    'examType' => $examType,
    'sourceId' => (int)$sourceId,
    'subjectId' => (int)$focusSubjectId,
    'subjectLabel' => (string)$diagSubjectLabel,
    'subjectName' => (string)($diagSubjectNameBoot ?? ''),
    'requiredCount' => (int)($diagRequiredBoot ?? 0),
    'csrf' => $csrf,
    'ajaxUrl' => $ajaxQuestionsUrl,
    'locked' => (bool)$locked,
    'questions' => $rapidQuestions,
    'nextNumber' => count($rapidQuestions) + 1,
    'initialEditId' => $locked ? 0 : (int)$editQuestionId,
    'tableBodyId' => 'eqbQuestionRows',
    'countId' => 'eqbQuestionCount',
    'searchId' => 'eqbSearch',
    'filterId' => 'eqbTypeFilter',
    'slotListId' => 'diagSlotList',
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  window.__eqbRapid = window.EreviewQuestionRapidEntry.create(bootstrap);
})();
</script>
<?php endif; ?>
</body>
</html>


