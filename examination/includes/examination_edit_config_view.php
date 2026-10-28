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
$professorFeatureHero = true;
$adminHeroIcon = 'journal-text';
$adminHeroEyebrow = 'Examination management';
if ($examType === 'diagnostic') {
    $adminHeroTitle = $isNew ? 'New Diagnostic Exam' : 'Diagnostic Exam';
    $adminHeroSubtitle = $isNew
        ? 'Configure subjects, audience, and schedule for a multi-subject CPA diagnostic.'
        : (string)($titleVal !== '' ? $titleVal : 'CPA Diagnostic Assessment');
} else {
    $adminHeroTitle = $modalTitle;
    $adminHeroSubtitle = $titleVal !== '' ? $titleVal : $modalSubtitle;
    $adminHeroMeta = '<span class="examination-edit-type-chip">Regular exam</span>';
}
$adminBreadcrumbs = [['Dashboard', 'professor_admin_dashboard'], ['Examinations', 'professor_examinations'], [$isNew ? 'New' : 'Edit']];
$adminBackHref = 'professor_examinations';
$adminBackLabel = 'Back to Examinations';
$lmsHeroVariant = 'navy';
$adminHeroActions = '';
$professorPageChrome = ($examType !== 'diagnostic');
$examinationLoadEditPageCss = ($examType !== 'diagnostic');

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page professor-admin prof-page--exam-edit<?php echo $examType === 'diagnostic' ? ' diag-exam-portal' : ' examination-edit-page examination-edit-page--regular'; ?><?php echo ereview_lms_premium_body_class(); ?>">
<?php include dirname(__DIR__) . '/professor/professor_admin_sidebar.php'; ?>
<?php $toastSuccess = ereview_lms_toast_take('success', !empty($flashMessage) ? (string) $flashMessage : null); ?>
<?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

<div class="examination-page-shell">
<?php if ($flashMessage && empty($toastSuccess)): ?>
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

<section class="prof-workspace prof-workspace--form lms-workspace">
<?php require __DIR__ . '/examination_edit_config_form.php'; ?>
</section>
</div>

</body>
</html>
