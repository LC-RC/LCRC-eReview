<?php
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
ereview_require_college_examination_portal();
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_upload_helpers.php';

$pageTitle = 'Uploads';
$uid = getCurrentUserId();
$allowedTypesLabel = college_upload_allowed_types_label();

$tasks = [];
$rawTasks = college_upload_list_for_student($conn, (int) $uid);
foreach ($rawTasks as $t) {
    $tid = (int) ($t['task_id'] ?? 0);
    $latest = college_upload_latest_submission($conn, $tid, (int) $uid);
    $t['window_state'] = college_upload_task_window_state($t);
    $t['submission_id'] = $latest['submission_id'] ?? null;
    $t['submitted_file'] = $latest['file_name'] ?? null;
    $t['submitted_at'] = $latest['submitted_at'] ?? null;
    $tasks[] = $t;
}

$msg = $_SESSION['message'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['message'], $_SESSION['error']);

$taskCount = count($tasks);
$openCount = 0;
$submittedCount = 0;
$dueSoon = 0;
$soonTs = strtotime('+72 hours');
foreach ($tasks as $tr) {
    $state = (string) ($tr['window_state'] ?? 'open');
    if ($state === 'open' && college_upload_deadline_allows_upload($tr['deadline'] ?? null)) {
        $openCount++;
        $dTs = college_upload_deadline_to_timestamp($tr['deadline'] ?? null);
        if ($dTs !== false && $dTs <= $soonTs) {
            $dueSoon++;
        }
    }
    if (!empty($tr['submission_id'])) {
        $submittedCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_app.php'; ?>
</head>
<body class="font-sans antialiased<?php echo !empty($examinationStudentBodyClass) ? ' ' . h($examinationStudentBodyClass) : ''; ?>">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="cp-page-shell cp-content cp-content--uploads ereview-shell-no-fade pt-2">
    <?php
      $cpPageVariant = 'compact';
      $cpPageTitle = 'Uploads';
      $cpPageSubtitle = 'Manage your examination-related upload tasks.';
      if ($taskCount > 0) {
          $cpPageSubtitle .= ' · ' . (int)$openCount . ' open · ' . (int)$submittedCount . ' submitted';
      }
      require dirname(__DIR__, 2) . '/includes/components/college_portal_page_header.php';
      $cpFlashMessage = $msg;
      $cpFlashError = $err;
      require dirname(__DIR__, 2) . '/includes/components/college_portal_flash.php';
    ?>

    <section class="cp-dash-panel cp-anim delay-2" aria-label="Upload tasks">
      <div class="cp-dash-panel__head">
        <h2 class="cp-dash-panel__title">Upload tasks</h2>
        <?php if ($taskCount > 0): ?>
          <span class="cp-dash-panel__meta"><?php echo (int)$taskCount; ?> task<?php echo $taskCount === 1 ? '' : 's'; ?></span>
        <?php endif; ?>
      </div>
      <div class="cp-dash-panel__body">
    <div class="cp-upload-grid">
      <?php foreach ($tasks as $t):
        $state = (string) ($t['window_state'] ?? 'open');
        $canUpload = ($state === 'open');
        $excerpt = college_upload_instruction_excerpt($t['instructions'] ?? '', 90);
        $tileClass = 'cp-upload-card dash-anim delay-2';
        if (!$canUpload) {
            $tileClass .= ' cp-upload-card--muted';
        }
        $dueLabel = match ($state) {
            'upcoming' => 'Opens ' . date('M j', strtotime((string) ($t['open_at'] ?? $t['deadline']))),
            'locked' => 'Closed',
            default => 'Due ' . date('M j', strtotime($t['deadline'])),
        };
        ?>
      <a href="college_upload_task?id=<?php echo (int)$t['task_id']; ?>" class="<?php echo h($tileClass); ?>">
        <div class="cp-upload-card__icon" aria-hidden="true"><i class="bi bi-file-earmark-arrow-up"></i></div>
        <div class="cp-upload-card__body">
          <div class="cp-upload-card__top">
            <?php if (!empty($t['submission_id'])): ?>
              <span class="cu-tile-done"><i class="bi bi-check2"></i> Submitted</span>
            <?php endif; ?>
            <span class="cu-tile-due <?php echo $canUpload ? 'cu-tile-due--open' : 'cu-tile-due--closed'; ?>"><?php echo h($dueLabel); ?></span>
          </div>
          <h3 class="cp-upload-card__title"><?php echo h($t['title']); ?></h3>
          <?php if ($excerpt !== ''): ?>
            <p class="cp-upload-card__excerpt"><?php echo h($excerpt); ?></p>
          <?php endif; ?>
        </div>
        <span class="cp-upload-card__go">Open task <i class="bi bi-arrow-right"></i></span>
      </a>
      <?php endforeach; ?>

      <?php if (empty($tasks)): ?>
        <div class="cp-empty-surface cp-empty-surface--wide">
          <div class="cp-empty-surface__icon"><i class="bi bi-inbox"></i></div>
          <h3 class="cp-empty-surface__title">No upload tasks yet</h3>
          <p class="cp-empty-surface__text">When your instructor publishes a task, it will appear here as a workspace card.</p>
          <a href="college_student_dashboard" class="cp-btn cp-btn--secondary"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
        </div>
      <?php endif; ?>
    </div>
      </div>
    </section>
    <?php if ($taskCount > 0): ?>
      <p class="cp-page-note">Allowed file types: <strong><?php echo h($allowedTypesLabel); ?></strong></p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
