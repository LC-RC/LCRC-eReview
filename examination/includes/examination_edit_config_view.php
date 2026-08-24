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

$examinationEditRenderMode = 'page';
require __DIR__ . '/examination_edit_config_prepare.php';

$pageTitle = $isNew ? 'New Examination' : 'Edit Examination';
$adminLoadStudentsCss = true;
$adminHeroIcon = 'journal-text';
if ($examType === 'diagnostic') {
    $adminHeroTitle = $isNew ? 'New Diagnostic Exam' : 'Diagnostic Exam';
    $adminHeroSubtitle = $isNew
        ? 'Configure subjects, audience, and schedule for a multi-subject CPA diagnostic.'
        : (string)($titleVal !== '' ? $titleVal : 'CPA Diagnostic Assessment');
} else {
    $adminHeroTitle = $modalTitle;
    $adminHeroSubtitle = $modalSubtitle;
}
$adminHeroActions = '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_examinations"><i class="bi bi-arrow-left"></i> Back to Examinations</a>';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page<?php echo $examType === 'diagnostic' ? ' diag-exam-portal' : ''; ?>">
<?php include dirname(__DIR__) . '/professor/professor_admin_sidebar.php'; ?>
<?php if ($examType === 'diagnostic'): ?>
  <!-- workspace bar provides Back + actions -->
<?php else: ?>
  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>
<?php endif; ?>

<?php if ($flashMessage): ?>
  <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2">
    <i class="bi bi-check-circle-fill"></i><span><?php echo h($flashMessage); ?></span>
  </div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($error); ?></span>
  </div>
<?php endif; ?>

<?php
$activeStep = 'config';
require dirname(__DIR__) . '/includes/examination_edit_steps.php';
?>

<?php require __DIR__ . '/examination_edit_config_form.php'; ?>

</body>
</html>
