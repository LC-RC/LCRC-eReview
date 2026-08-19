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
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="cp-page-shell ereview-shell-no-fade pt-2">
    <?php
      $cpPageEyebrow = 'Assignments';
      $cpPageTitle = 'Upload tasks';
      $cpPageSubtitle = 'Open a task for instructions and submission. Allowed types: <strong>' . h($allowedTypesLabel) . '</strong>.';
      $cpPageIcon = 'bi-cloud-upload';
      $cpPageStats = $taskCount > 0 ? [
          ['label' => 'Open', 'value' => (int)$openCount],
          ['label' => 'Submitted', 'value' => (int)$submittedCount],
          ['label' => 'Due 72h', 'value' => (int)$dueSoon],
      ] : [];
      require dirname(__DIR__, 2) . '/includes/components/college_portal_page_header.php';
    ?>

    <div class="cu-body-pad">
    <?php
      $cpFlashMessage = $msg;
      $cpFlashError = $err;
      require dirname(__DIR__, 2) . '/includes/components/college_portal_flash.php';
    ?>

    <div class="cp-section-head cp-anim delay-2">
      <?php
        $cpSectionIcon = 'bi-folder2-open';
        $cpSectionTitle = 'Your tasks';
        $cpSectionClass = 'm-0';
        require dirname(__DIR__, 2) . '/includes/components/college_portal_section.php';
      ?>
      <?php if ($taskCount > 0): ?>
        <span class="cu-pill"><?php echo (int)$taskCount; ?> active</span>
      <?php endif; ?>
    </div>

    <div class="cu-task-board">
      <?php foreach ($tasks as $t):
        $state = (string) ($t['window_state'] ?? 'open');
        $canUpload = ($state === 'open');
        $excerpt = college_upload_instruction_excerpt($t['instructions'] ?? '', 90);
        $tileClass = 'cu-tile dash-anim delay-2';
        if (!$canUpload) {
            $tileClass .= ' cu-tile--muted';
        }
        $dueLabel = match ($state) {
            'upcoming' => 'Opens ' . date('M j', strtotime((string) ($t['open_at'] ?? $t['deadline']))),
            'locked' => 'Closed',
            default => 'Due ' . date('M j', strtotime($t['deadline'])),
        };
        ?>
      <a href="college_upload_task?id=<?php echo (int)$t['task_id']; ?>" class="<?php echo h($tileClass); ?>">
        <div class="cu-tile-top">
          <span class="cu-tile-icon" aria-hidden="true"><i class="bi bi-file-earmark-arrow-up"></i></span>
          <?php if (!empty($t['submission_id'])): ?>
            <span class="cu-tile-done"><i class="bi bi-check2"></i> File</span>
          <?php endif; ?>
        </div>
        <h3 class="cu-tile-title"><?php echo h($t['title']); ?></h3>
        <?php if ($excerpt !== ''): ?>
          <p class="cu-tile-excerpt"><?php echo h($excerpt); ?></p>
        <?php else: ?>
          <div class="cu-tile-grow" aria-hidden="true"></div>
        <?php endif; ?>
        <div class="cu-tile-foot">
          <span class="cu-tile-due <?php echo $canUpload ? 'cu-tile-due--open' : 'cu-tile-due--closed'; ?>">
            <?php echo h($dueLabel); ?>
          </span>
          <span class="cu-tile-go">Open <i class="bi bi-chevron-right"></i></span>
        </div>
      </a>
      <?php endforeach; ?>

      <?php if (empty($tasks)): ?>
        <div class="cu-empty dash-anim delay-3">
          <div class="inline-flex items-center justify-center h-11 w-11 rounded-xl border border-[#cde2f4] bg-[#eef6ff] text-[#1665a0] text-xl mb-3 shadow-sm">
            <i class="bi bi-inbox"></i>
          </div>
          <p class="text-slate-700 font-bold text-base m-0">No upload tasks yet</p>
          <p class="text-slate-500 text-xs mt-1.5 mb-4 leading-relaxed">When your instructor publishes a task, it will appear here as a tile.</p>
          <a href="college_student_dashboard" class="cu-hint-btn text-xs py-2 px-4"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
        </div>
      <?php endif; ?>
    </div>
    </div>
  </div>
</main>
</body>
</html>
