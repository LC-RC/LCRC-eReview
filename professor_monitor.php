<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <div class="admin-content max-w-7xl mx-auto w-full px-4 lg:px-6">
    <div class="mb-6">
      <div class="rounded-xl border border-green-200 bg-gradient-to-r from-green-50/70 via-white to-white shadow-sm overflow-hidden">
        <div class="p-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-green-600/10 border border-green-200 flex items-center justify-center shrink-0">
              <i class="bi bi-graph-up text-green-700 text-xl"></i>
            </div>
            <div>
              <h1 class="text-2xl font-bold text-green-900 m-0 leading-tight">Monitoring</h1>
              <p class="text-gray-600 mt-1 mb-0">Exam participation and file submissions.</p>
            </div>
          </div>
          <div class="hidden sm:block"></div>
        </div>
      </div>
    </div>

    <h2 class="text-lg font-bold text-green-800 mb-3">Exam attempts</h2>
    <div class="rounded-xl border border-green-200 bg-white shadow-sm overflow-x-auto mb-10">
      <table class="w-full text-sm text-left min-w-[640px]">
        <thead class="bg-green-50 text-green-800 font-semibold border-b border-gray-200">
          <tr>
            <th class="px-4 py-3">Student</th>
            <th class="px-4 py-3">Email</th>
            <th class="px-4 py-3">Exam</th>
            <th class="px-4 py-3">Score</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3">Submitted</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-green-100">
          <?php if (empty($attempts)): ?>
          <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No data yet.</td></tr>
          <?php else: ?>
            <?php foreach ($attempts as $a): ?>
            <tr class="hover:bg-green-50/80 transition-colors">
              <td class="px-4 py-3 font-medium"><?php echo h($a['full_name']); ?></td>
              <td class="px-4 py-3 text-gray-600"><?php echo h($a['email']); ?></td>
              <td class="px-4 py-3"><?php echo h($a['exam_title']); ?></td>
              <td class="px-4 py-3"><?php echo $a['score'] !== null ? h((string)$a['score']) . '%' : '—'; ?></td>
              <td class="px-4 py-3"><?php echo h($a['status']); ?></td>
              <td class="px-4 py-3 text-gray-600"><?php echo $a['submitted_at'] ? h(date('M j, g:i A', strtotime($a['submitted_at']))) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <h2 class="text-lg font-bold text-green-800 mb-3">File submissions</h2>
    <div class="rounded-xl border border-green-200 bg-white shadow-sm overflow-x-auto">
      <table class="w-full text-sm text-left min-w-[640px]">
        <thead class="bg-green-50 text-green-800 font-semibold border-b border-gray-200">
          <tr>
            <th class="px-4 py-3">Student</th>
            <th class="px-4 py-3">Email</th>
            <th class="px-4 py-3">Task</th>
            <th class="px-4 py-3">File</th>
            <th class="px-4 py-3">Submitted</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-green-100">
          <?php if (empty($subs)): ?>
          <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No submissions yet.</td></tr>
          <?php else: ?>
            <?php foreach ($subs as $s): ?>
            <tr class="hover:bg-green-50/80 transition-colors">
              <td class="px-4 py-3 font-medium"><?php echo h($s['full_name']); ?></td>
              <td class="px-4 py-3 text-gray-600"><?php echo h($s['email']); ?></td>
              <td class="px-4 py-3"><?php echo h($s['task_title']); ?></td>
              <td class="px-4 py-3">
                <?php if (!empty($s['file_name']) && !empty($s['submission_id'])): ?>
                  <a href="<?php echo h($s['file_path'] ?? ''); ?>" class="text-green-700 font-medium hover:underline" target="_blank" rel="noopener"><?php echo h($s['file_name']); ?></a>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-600"><?php echo h(date('M j, g:i A', strtotime($s['submitted_at']))); ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
</body>
</html>
