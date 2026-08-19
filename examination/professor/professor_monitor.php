<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';

$pageTitle = 'Monitor';

$attempts = [];
$q = @mysqli_query($conn, "
  SELECT a.attempt_id, a.score, a.correct_count, a.total_count, a.submitted_at, a.status,
         u.full_name, u.email, e.title AS exam_title, e.exam_id
  FROM college_exam_attempts a
  INNER JOIN users u ON u.user_id=a.user_id AND u.role='college_student'
  INNER JOIN college_exams e ON e.exam_id=a.exam_id
  ORDER BY a.submitted_at DESC
  LIMIT 100
");
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $attempts[] = $r;
    }
    mysqli_free_result($q);
}

$subs = [];
$s = @mysqli_query($conn, "
  SELECT s.submission_id, s.file_path, s.file_name, s.submitted_at, s.status, u.full_name, u.email, t.title AS task_title, t.task_id
  FROM college_submissions s
  INNER JOIN users u ON u.user_id=s.user_id
  INNER JOIN college_upload_tasks t ON t.task_id=s.task_id
  ORDER BY s.submitted_at DESC
  LIMIT 100
");
if ($s) {
    while ($r = mysqli_fetch_assoc($s)) {
        $subs[] = $r;
    }
    mysqli_free_result($s);
}

$pageTitle = 'Monitor';
$adminHeroIcon = 'graph-up';
$adminHeroTitle = 'Activity monitor';
$adminHeroSubtitle = 'Recent exam participation and file submissions across your account.';
$adminHeroActions = '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_examination_monitor"><i class="bi bi-speedometer2"></i> Examination monitor</a>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <div class="examination-page-shell">
    <h2 class="examination-section-title"><i class="bi bi-clipboard-check"></i> Exam attempts</h2>
    <div class="rounded-xl overflow-hidden page-table students-table-shell mb-6">
      <div class="students-table-scroll">
      <table class="w-full text-left admin-students-table students-table--compact min-w-[640px]">
        <thead>
          <tr>
            <th>Student</th>
            <th>Email</th>
            <th>Exam</th>
            <th>Score</th>
            <th>Status</th>
            <th>Submitted</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($attempts)): ?>
          <tr><td colspan="6" class="students-empty-cell">No data yet.</td></tr>
          <?php else: ?>
            <?php foreach ($attempts as $a): ?>
            <tr>
              <td class="font-medium"><?php echo h($a['full_name']); ?></td>
              <td><?php echo h($a['email']); ?></td>
              <td><?php echo h($a['exam_title']); ?></td>
              <td class="font-bold"><?php echo $a['score'] !== null ? h((string)$a['score']) . '%' : '-'; ?></td>
              <td><span class="admin-badge admin-badge--neutral"><?php echo h($a['status']); ?></span></td>
              <td><?php echo $a['submitted_at'] ? h(date('M j, g:i A', strtotime($a['submitted_at']))) : '-'; ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>

    <h2 class="examination-section-title"><i class="bi bi-folder2-open"></i> File submissions</h2>
    <div class="rounded-xl overflow-hidden page-table students-table-shell">
      <div class="students-table-scroll">
      <table class="w-full text-left admin-students-table students-table--compact min-w-[640px]">
        <thead>
          <tr>
            <th>Student</th>
            <th>Email</th>
            <th>Task</th>
            <th>File</th>
            <th>Submitted</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($subs)): ?>
          <tr><td colspan="5" class="students-empty-cell">No submissions yet.</td></tr>
          <?php else: ?>
            <?php foreach ($subs as $s): ?>
            <tr>
              <td class="font-medium"><?php echo h($s['full_name']); ?></td>
              <td><?php echo h($s['email']); ?></td>
              <td><?php echo h($s['task_title']); ?></td>
              <td>
                <?php if (!empty($s['file_name']) && !empty($s['submission_id'])): ?>
                  <a href="<?php echo h($s['file_path'] ?? ''); ?>" class="font-semibold hover:underline" target="_blank" rel="noopener"><?php echo h($s['file_name']); ?></a>
                <?php else: ?>-<?php endif; ?>
              </td>
              <td><?php echo h(date('M j, g:i A', strtotime($s['submitted_at']))); ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</body>
</html>
