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

require_once __DIR__ . '/examination_config_draft.php';
$examinationConfigStashApplied = false;
$posted = is_array($_POST) ? $_POST : [];
$hasPostedConfig = $posted !== [] && (
    array_key_exists('title', $posted)
    || array_key_exists('description', $posted)
    || array_key_exists('exam_type', $posted)
);
if (!$hasPostedConfig) {
    $stashPost = examination_config_draft_peek(
        (int) (getCurrentUserId() ?? 0),
        (string) $examType,
        (int) $sourceId
    );
    if (is_array($stashPost)) {
        $posted = $stashPost;
        $hasPostedConfig = true;
        $examinationConfigStashApplied = true;
    }
}

$titleVal = (string)($posted['title'] ?? ($rec['title'] ?? ''));
$descVal = (string)($posted['description'] ?? ($rec['description'] ?? ''));

$timeSec = (int)($posted['time_limit_seconds'] ?? ($rec['time_limit_seconds'] ?? 3600));
if (isset($posted['time_limit_hours']) || isset($posted['time_limit_minutes'])) {
    $timeSec = examination_time_limit_from_post($posted);
}
$timeParts = examination_format_time_limit_parts($timeSec);

$scopeVal = examination_normalize_examinee_scope((string)($posted['examinee_scope'] ?? ($rec['examinee_scope'] ?? 'college_student')));
$modeVal = examination_normalize_assignment_mode((string)($posted['assignment_mode'] ?? ($rec['assignment_mode'] ?? ($examType === 'diagnostic' ? 'all' : 'all'))));

$availVal = (string)($posted['available_from'] ?? '');
if ($availVal === '' && $rec && !empty($rec['available_from'])) {
    $availVal = college_exam_format_datetime_local($rec['available_from']);
}

$deadVal = (string)($posted['deadline'] ?? '');
if ($deadVal === '' && $rec && !empty($rec['deadline'])) {
    $deadVal = college_exam_format_datetime_local($rec['deadline']);
}

$sectionsVal = $posted['sections'] ?? ($extras['sections'] ?? ['']);
if (!is_array($sectionsVal) || $sectionsVal === []) {
    $sectionsVal = [''];
}

$userIdsVal = array_map('intval', is_array($posted['user_ids'] ?? null) ? $posted['user_ids'] : ($extras['assigned_user_ids'] ?? []));
$selectedSubjects = array_map('intval', is_array($posted['subject_ids'] ?? null) ? $posted['subject_ids'] : array_map(static fn($r) => (int)($r['subject_id'] ?? 0), $extras['batch_subjects'] ?? []));
$questionsRequired = is_array($extras['questions_required'] ?? null) ? $extras['questions_required'] : [];
if (is_array($posted['questions_required'] ?? null)) {
    foreach ($posted['questions_required'] as $qidKey => $qreq) {
        $questionsRequired[(int) $qidKey] = (int) $qreq;
    }
}

$shuffleQ = !empty($posted['shuffle_questions']) || (!$hasPostedConfig && !empty($extras['shuffle_questions']));
$shuffleC = !empty($posted['shuffle_choices']) || (!$hasPostedConfig && !empty($extras['shuffle_choices']));
$shuffleMcq = !empty($posted['shuffle_mcq_questions']) || (!$hasPostedConfig && !empty($extras['shuffle_mcq_questions']));
$shuffleTf = !empty($posted['shuffle_tf_questions']) || (!$hasPostedConfig && !empty($extras['shuffle_tf_questions']));
$descMarkdown = !empty($posted['description_markdown']) || (!$hasPostedConfig && !empty($extras['description_markdown']));

$reviewFromVal = (string)($posted['review_sheet_available_from'] ?? '');
if ($reviewFromVal === '' && !empty($extras['review_sheet_available_from'])) {
    $reviewFromVal = college_exam_format_datetime_local($extras['review_sheet_available_from']);
}

$reviewUntilVal = (string)($posted['review_sheet_available_until'] ?? '');
if ($reviewUntilVal === '' && !empty($extras['review_sheet_available_until'])) {
    $reviewUntilVal = college_exam_format_datetime_local($extras['review_sheet_available_until']);
}

if ($hasPostedConfig && !empty($posted['reg_subjects_present'])) {
    $fromPost = examination_config_draft_regular_tree_from_post($posted);
    $extras['regular_breakdown_mode'] = $fromPost['mode'];
    $extras['regular_total_questions_required'] = $fromPost['total'];
    $extras['regular_subject_tree'] = $fromPost['tree'];
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

$editSectionClass = $isModalRender ? 'examination-form-section' : 'prof-form-section';
$editSectionHeadingClass = $isModalRender ? 'examination-form-section__title' : 'prof-form-section__title';

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
