<?php
declare(strict_types=1);

/** @var mysqli $conn */
/** @var array|null $record */
/** @var string $examType */
/** @var int $sourceId */
/** @var string $csrf */
/** @var string|null $error */
/** @var string|null $flashMessage */
/** @var string|null $flashError */

$rec = is_array($record) ? $record : null;
if (!$rec) {
    header('Location: professor_examinations');
    exit;
}

require_once dirname(__DIR__) . '/includes/examination_questions.php';

$publishCheck = examination_questions_validate_for_publish($conn, $examType, $sourceId);
$diagSupply = $examType === 'diagnostic' ? examination_questions_diagnostic_supply($conn, $sourceId) : null;

$pageTitle = 'Review & Publish';
$adminHeroIcon = 'check2-circle';
$adminHeroTitle = 'Review & Publish';
$adminHeroSubtitle = (string)($rec['title'] ?? '');
$adminHeroActions = '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_examinations"><i class="bi bi-arrow-left"></i> Back to Examinations</a>';
$activeStep = 'review';
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

<section class="rounded-xl overflow-hidden page-table p-6 mb-4">
  <h2 class="text-base font-bold mb-3">Examination</h2>
  <div class="examination-summary-row"><span class="examination-summary-label">Title</span><span class="examination-summary-value"><?php echo h($rec['title']); ?></span></div>
  <div class="examination-summary-row"><span class="examination-summary-label">Type</span><span class="examination-summary-value"><?php echo h($rec['exam_type_label']); ?></span></div>
  <div class="examination-summary-row"><span class="examination-summary-label">Audience</span><span class="examination-summary-value"><?php echo h($rec['assignment_summary']); ?></span></div>
  <div class="examination-summary-row"><span class="examination-summary-label">Schedule</span><span class="examination-summary-value"><?php echo h($rec['schedule_line']); ?></span></div>
  <div class="examination-summary-row"><span class="examination-summary-label">Time limit</span><span class="examination-summary-value"><?php echo h(examination_format_time_limit_display((int)$rec['time_limit_seconds'])); ?></span></div>
  <div class="examination-summary-row"><span class="examination-summary-label">Status</span><span class="examination-summary-value"><?php echo h($rec['status_label']); ?></span></div>
</section>

<section class="rounded-xl overflow-hidden page-table p-6 mb-4">
  <h2 class="text-base font-bold mb-3">Questions</h2>
  <div class="examination-summary-row"><span class="examination-summary-label">Total questions</span><span class="examination-summary-value"><?php echo (int)$rec['question_count']; ?></span></div>

  <?php if ($examType === 'diagnostic' && $diagSupply): ?>
    <div class="mt-3 space-y-2">
      <?php foreach ($diagSupply['subjects'] as $sub): ?>
        <div class="examination-summary-row">
          <span class="examination-summary-label"><?php echo h($sub['subject_code']); ?></span>
          <span class="examination-summary-value">
            <?php echo (int)$sub['authored']; ?> / <?php echo (int)$sub['required'] > 0 ? (int)$sub['required'] : 'all'; ?>
            <?php echo !empty($sub['ok']) ? '✓' : '✗'; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($publishCheck['ok'])): ?>
    <div class="admin-flash admin-flash--error mt-4 mb-0 p-3 rounded-xl">
      <div class="font-semibold mb-1">Cannot publish yet</div>
      <div class="text-sm"><?php echo h((string)($publishCheck['error'] ?? 'Questions incomplete.')); ?></div>
      <?php if (!empty($publishCheck['details']) && is_array($publishCheck['details'])): ?>
        <ul class="text-sm mt-2 mb-0 list-disc pl-5">
          <?php foreach ($publishCheck['details'] as $d): ?>
            <li><?php echo h((string)$d); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <a class="admin-btn admin-btn--secondary admin-btn--sm mt-3" href="<?php echo h(examination_domain_edit_url($examType, $sourceId, 'questions')); ?>">Fix questions</a>
    </div>
  <?php else: ?>
    <p class="text-sm mt-3 mb-0 opacity-80"><i class="bi bi-check-circle text-emerald-600"></i> Question requirements are satisfied.</p>
  <?php endif; ?>
</section>

<form method="post" class="flex flex-wrap gap-3 items-center mb-4">
  <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
  <input type="hidden" name="action" value="save_config">
  <input type="hidden" name="exam_type" value="<?php echo h($examType); ?>">
  <?php if ($examType === 'diagnostic'): ?>
    <input type="hidden" name="batch_id" value="<?php echo (int)$sourceId; ?>">
  <?php else: ?>
    <input type="hidden" name="exam_id" value="<?php echo (int)$sourceId; ?>">
  <?php endif; ?>
  <button type="submit" name="save_action" value="draft" class="admin-btn admin-btn--secondary"><i class="bi bi-save"></i> Save Draft</button>
  <button type="submit" name="save_action" value="publish" class="admin-btn admin-btn--primary" <?php echo empty($publishCheck['ok']) ? 'disabled title="Complete questions before publishing"' : ''; ?>>
    <i class="bi bi-check2-circle"></i> Publish Examination
  </button>
  <a href="<?php echo h(examination_domain_monitor_url($examType, $sourceId)); ?>" class="admin-btn admin-btn--ghost"><i class="bi bi-graph-up"></i> Monitor</a>
</form>

<p class="text-sm opacity-70 mb-6">Drafts may be incomplete. Publishing requires valid configuration, assignment, and complete questions.</p>
</body>
</html>
