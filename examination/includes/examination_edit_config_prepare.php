<?php

/** @var string|null $error */
/** @var string|null $flashMessage */
/** @var string $csrf */
/** @var bool $isNew */
/** @var string $examType */
/** @var int $sourceId */
/** @var array|null $record */
/** @var array $extras */
/** @var array $examineeSearchResults */

$examinationEditRenderMode = $examinationEditRenderMode ?? 'page';
$isModalRender = ($examinationEditRenderMode === 'modal');

$rec = is_array($record) ? $record : null;

$titleVal = (string)($_POST['title'] ?? ($rec['title'] ?? ''));
$descVal = (string)($_POST['description'] ?? ($rec['description'] ?? ''));

$timeSec = (int)($_POST['time_limit_seconds'] ?? ($rec['time_limit_seconds'] ?? 3600));
if (isset($_POST['time_limit_hours']) || isset($_POST['time_limit_minutes'])) {
    $timeSec = examination_time_limit_from_post($_POST);
}
$timeParts = examination_format_time_limit_parts($timeSec);

$scopeVal = examination_normalize_examinee_scope((string)($_POST['examinee_scope'] ?? ($rec['examinee_scope'] ?? 'college_student')));
$modeVal = examination_normalize_assignment_mode((string)($_POST['assignment_mode'] ?? ($rec['assignment_mode'] ?? ($examType === 'diagnostic' ? 'all' : 'all'))));

$availVal = (string)($_POST['available_from'] ?? '');
if ($availVal === '' && $rec && !empty($rec['available_from'])) {
    $availVal = college_exam_format_datetime_local($rec['available_from']);
}

$deadVal = (string)($_POST['deadline'] ?? '');
if ($deadVal === '' && $rec && !empty($rec['deadline'])) {
    $deadVal = college_exam_format_datetime_local($rec['deadline']);
}

$sectionsVal = $_POST['sections'] ?? ($extras['sections'] ?? ['']);
if (!is_array($sectionsVal) || $sectionsVal === []) {
    $sectionsVal = [''];
}

$userIdsVal = array_map('intval', is_array($_POST['user_ids'] ?? null) ? $_POST['user_ids'] : ($extras['assigned_user_ids'] ?? []));
$selectedSubjects = array_map('intval', is_array($_POST['subject_ids'] ?? null) ? $_POST['subject_ids'] : array_map(static fn($r) => (int)($r['subject_id'] ?? 0), $extras['batch_subjects'] ?? []));
$questionsRequired = is_array($extras['questions_required'] ?? null) ? $extras['questions_required'] : [];

$shuffleQ = !empty($_POST['shuffle_questions']) || (!isset($_POST['title']) && !empty($extras['shuffle_questions']));
$shuffleC = !empty($_POST['shuffle_choices']) || (!isset($_POST['title']) && !empty($extras['shuffle_choices']));
$shuffleMcq = !empty($_POST['shuffle_mcq_questions']) || (!isset($_POST['title']) && !empty($extras['shuffle_mcq_questions']));
$shuffleTf = !empty($_POST['shuffle_tf_questions']) || (!isset($_POST['title']) && !empty($extras['shuffle_tf_questions']));
$descMarkdown = !empty($_POST['description_markdown']) || (!isset($_POST['title']) && !empty($extras['description_markdown']));

$reviewFromVal = (string)($_POST['review_sheet_available_from'] ?? '');
if ($reviewFromVal === '' && !empty($extras['review_sheet_available_from'])) {
    $reviewFromVal = college_exam_format_datetime_local($extras['review_sheet_available_from']);
}

$reviewUntilVal = (string)($_POST['review_sheet_available_until'] ?? '');
if ($reviewUntilVal === '' && !empty($extras['review_sheet_available_until'])) {
    $reviewUntilVal = college_exam_format_datetime_local($extras['review_sheet_available_until']);
}

$suggestedSections = is_array($extras['suggested_sections'] ?? null) ? $extras['suggested_sections'] : [];
if ($suggestedSections === []) {
    require_once __DIR__ . '/college_sections.php';
    $suggestedSections = college_sections_active_names($conn);
}

$sectionSelectOptionsHtml = '<option value="">Select section</option>';
foreach ($suggestedSections as $sg) {
    $sg = trim((string) $sg);
    if ($sg === '') {
        continue;
    }
    $sectionSelectOptionsHtml .= '<option value="' . h($sg) . '">' . h($sg) . '</option>';
}

$assignmentLocked = (!$isNew && $sourceId > 0 && function_exists('examination_assignment_mutations_locked'))
    ? examination_assignment_mutations_locked($conn, $examType, $sourceId)
    : false;

$subjectCatalog = is_array($extras['subjects'] ?? null) ? $extras['subjects'] : [];

$editSectionClass = $isModalRender ? 'examination-form-section' : 'rounded-xl overflow-hidden page-table p-6';
$editSectionHeadingClass = $isModalRender ? 'examination-form-section__title' : 'text-base font-bold mb-3';

if ($examType === 'diagnostic' && !$isModalRender) {
    $editSectionClass = 'diag-portal-section';
    $editSectionHeadingClass = 'diag-portal-section__title';
}

$formAction = $isNew
    ? ('professor_examination_edit?exam_type=' . rawurlencode($examType) . ($isModalRender ? '&modal=1' : ''))
    : (examination_domain_edit_url($examType, $sourceId, 'config') . ($isModalRender ? '&modal=1' : ''));

$modalTitle = $isNew ? 'New Examination' : 'Edit Examination';
$modalSubtitle = $isNew
    ? 'Configure examination details, assignment, and schedule.'
    : (($rec ? ($rec['exam_type_label'] . ' · ' . $rec['title']) : 'Examination configuration'));
