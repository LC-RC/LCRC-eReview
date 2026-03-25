<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'Professor dashboard';
$csrf = generateCSRFToken();

$uid = getCurrentUserId();

// --- Students (from professor_college_students.php) ---
$collegeStudents = 0;
$studentStatus = [
  'pending' => 0,
  'approved' => 0,
  'rejected' => 0,
];

$qStudents = @mysqli_query($conn, "
  SELECT status, COUNT(*) AS c
  FROM users
  WHERE role='college_student'
  GROUP BY status
");
if ($qStudents) {
  while ($r = mysqli_fetch_assoc($qStudents)) {
    $st = strtolower((string)($r['status'] ?? ''));
    $cnt = (int)($r['c'] ?? 0);
    if (array_key_exists($st, $studentStatus)) {
      $studentStatus[$st] = $cnt;
    }
    $collegeStudents += $cnt;
  }
  mysqli_free_result($qStudents);
}

// --- Exams (from professor_exams.php) ---
$examCount = 0;
$examPublishedCount = 0;
$examOpenCount = 0;

$nowSql = date('Y-m-d H:i:s');
$nowEsc = mysqli_real_escape_string($conn, $nowSql);

$qExams = @mysqli_query($conn, "
  SELECT
    COUNT(*) AS total_count,
    SUM(CASE WHEN is_published=1 THEN 1 ELSE 0 END) AS published_count,
    SUM(CASE WHEN (deadline IS NULL OR deadline > '{$nowEsc}') THEN 1 ELSE 0 END) AS open_count
  FROM college_exams
  WHERE created_by=" . (int)$uid . "
");
if ($qExams) {
  $er = mysqli_fetch_assoc($qExams);
  $examCount = (int)($er['total_count'] ?? 0);
  $examPublishedCount = (int)($er['published_count'] ?? 0);
  $examOpenCount = (int)($er['open_count'] ?? 0);
  mysqli_free_result($qExams);
}

// Next exams by deadline
$nextExams = [];
$qNextExams = @mysqli_query($conn, "
  SELECT exam_id, title, deadline, is_published
  FROM college_exams
  WHERE created_by=" . (int)$uid . "
    AND (deadline IS NULL OR deadline >= '{$nowEsc}')
  ORDER BY deadline ASC, updated_at DESC
  LIMIT 4
");
if ($qNextExams) {
  while ($r = mysqli_fetch_assoc($qNextExams)) {
    $nextExams[] = $r;
  }
  mysqli_free_result($qNextExams);
}

// --- Upload tasks (from professor_upload_tasks.php) ---
$taskCount = 0;
$taskOpenCount = 0;
$taskDueSoonCount = 0;

$in7Sql = date('Y-m-d H:i:s', strtotime('+7 days'));
$in7Esc = mysqli_real_escape_string($conn, $in7Sql);

$qTasks = @mysqli_query($conn, "
  SELECT
    COUNT(*) AS total_count,
    SUM(CASE WHEN is_open=1 THEN 1 ELSE 0 END) AS open_count,
    SUM(CASE
      WHEN is_open=1
       AND deadline IS NOT NULL
       AND deadline >= '{$nowEsc}'
       AND deadline <= '{$in7Esc}'
      THEN 1 ELSE 0
    END) AS due_soon_count
  FROM college_upload_tasks
  WHERE created_by=" . (int)$uid . "
");
if ($qTasks) {
  $tr = mysqli_fetch_assoc($qTasks);
  $taskCount = (int)($tr['total_count'] ?? 0);
  $taskOpenCount = (int)($tr['open_count'] ?? 0);
  $taskDueSoonCount = (int)($tr['due_soon_count'] ?? 0);
  mysqli_free_result($qTasks);
}

$nextTasks = [];
$qNextTasks = @mysqli_query($conn, "
  SELECT task_id, title, deadline, is_open
  FROM college_upload_tasks
  WHERE created_by=" . (int)$uid . "
    AND (deadline IS NULL OR deadline >= '{$nowEsc}')
  ORDER BY deadline ASC
  LIMIT 4
");
if ($qNextTasks) {
  while ($r = mysqli_fetch_assoc($qNextTasks)) {
    $nextTasks[] = $r;
  }
  mysqli_free_result($qNextTasks);
}

// --- Recent activity (from professor_monitor.php) ---
$attemptRows = [];
$recentAttempts = @mysqli_query($conn, "
  SELECT a.attempt_id, a.score, a.submitted_at, a.status,
         u.full_name, u.email,
         e.title AS exam_title
  FROM college_exam_attempts a
  INNER JOIN users u ON u.user_id=a.user_id AND u.role='college_student'
  INNER JOIN college_exams e ON e.exam_id=a.exam_id
  WHERE a.status='submitted'
    AND e.created_by=" . (int)$uid . "
  ORDER BY a.submitted_at DESC
  LIMIT 6
");
if ($recentAttempts) {
  while ($r = mysqli_fetch_assoc($recentAttempts)) {
    $attemptRows[] = $r;
  }
  mysqli_free_result($recentAttempts);
}

$subRows = [];
$recentSubs = @mysqli_query($conn, "
  SELECT s.submission_id, s.file_path, s.file_name, s.submitted_at, s.status,
         u.full_name, u.email,
         t.title AS task_title
  FROM college_submissions s
  INNER JOIN users u ON u.user_id=s.user_id
  INNER JOIN college_upload_tasks t ON t.task_id=s.task_id
  WHERE t.created_by=" . (int)$uid . "
  ORDER BY s.submitted_at DESC
  LIMIT 6
");
if ($recentSubs) {
  while ($r = mysqli_fetch_assoc($recentSubs)) {
    $subRows[] = $r;
  }
  mysqli_free_result($recentSubs);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <main class="admin-content max-w-7xl mx-auto w-full px-4 lg:px-6">
    <div class="mb-6">
      <div class="rounded-xl border border-green-200 bg-gradient-to-r from-green-50/70 via-white to-white shadow-sm overflow-hidden">
        <div class="p-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-green-600/10 border border-green-200 flex items-center justify-center shrink-0">
              <i class="bi bi-mortarboard-fill text-green-700 text-xl"></i>
            </div>
            <div>
              <h1 class="text-2xl font-bold text-green-900 m-0 leading-tight">Professor dashboard</h1>
              <p class="text-gray-600 mt-1 mb-0">A modern overview of students, exams, and submissions.</p>
            </div>
          </div>
          <div class="flex flex-wrap gap-3">
        <a href="professor_college_students.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold bg-green-600 text-white hover:bg-green-700 transition-all duration-300 hover:-translate-y-0.5 shadow-sm hover:shadow-md">
          <i class="bi bi-person-plus"></i> Add student
        </a>
        <a href="professor_exams.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold border border-green-200 bg-white text-green-700 hover:bg-green-50 transition-all duration-300 hover:-translate-y-0.5">
          <i class="bi bi-journal-text"></i> Manage exams
        </a>
        <a href="professor_upload_tasks.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold border border-green-200 bg-white text-green-700 hover:bg-green-50 transition-all duration-300 hover:-translate-y-0.5">
          <i class="bi bi-folder-plus"></i> Upload tasks
        </a>
          </div>
        </div>
      </div>
    </div>

    <!-- KPI widgets -->
    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
      <div class="group rounded-xl border border-green-200 bg-white p-5 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm text-gray-500 m-0">College students</p>
            <p class="text-3xl font-bold text-green-700 m-0 mt-1"><?php echo $collegeStudents; ?></p>
            <div class="mt-2 flex flex-wrap gap-2">
              <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold bg-green-50 text-green-800 border border-green-100">Approved: <?php echo (int)$studentStatus['approved']; ?></span>
              <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold bg-gray-50 text-gray-700 border border-gray-100">Pending: <?php echo (int)$studentStatus['pending']; ?></span>
            </div>
          </div>
          <div class="w-12 h-12 rounded-xl bg-gradient-to-b from-green-50 to-white border border-green-100 flex items-center justify-center shrink-0">
            <i class="bi bi-people text-green-700 text-2xl"></i>
          </div>
        </div>
      </div>

      <a href="professor_exams.php" class="group rounded-xl border border-green-200 bg-white p-5 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm text-gray-500 m-0">Exams</p>
            <p class="text-3xl font-bold text-green-700 m-0 mt-1"><?php echo $examCount; ?></p>
            <p class="text-xs text-gray-500 mt-2 mb-0">Published: <?php echo (int)$examPublishedCount; ?> · Open: <?php echo (int)$examOpenCount; ?></p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-gradient-to-b from-green-50 to-white border border-green-100 flex items-center justify-center shrink-0">
            <i class="bi bi-journal-text text-green-700 text-2xl"></i>
          </div>
        </div>
      </a>

      <a href="professor_upload_tasks.php" class="group rounded-xl border border-green-200 bg-white p-5 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm text-gray-500 m-0">Upload tasks</p>
            <p class="text-3xl font-bold text-green-700 m-0 mt-1"><?php echo $taskCount; ?></p>
            <p class="text-xs text-gray-500 mt-2 mb-0">Open: <?php echo (int)$taskOpenCount; ?> · Due soon: <?php echo (int)$taskDueSoonCount; ?></p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-gradient-to-b from-green-50 to-white border border-green-100 flex items-center justify-center shrink-0">
            <i class="bi bi-folder-plus text-green-700 text-2xl"></i>
          </div>
        </div>
      </a>

      <a href="professor_monitor.php" class="group rounded-xl border border-green-200 bg-white p-5 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300">
        <div class="flex items-start justify-between gap-4">
          <div>
            <p class="text-sm text-gray-500 m-0">Recent activity</p>
            <p class="text-3xl font-bold text-green-700 m-0 mt-1"><?php echo count($attemptRows) + count($subRows); ?></p>
            <p class="text-xs text-gray-500 mt-2 mb-0">Latest exam results + files</p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-gradient-to-b from-green-50 to-white border border-green-100 flex items-center justify-center shrink-0">
            <i class="bi bi-activity text-green-700 text-2xl"></i>
          </div>
        </div>
      </a>
    </section>

    <section class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
      <!-- Recent exam attempts -->
      <div class="lg:col-span-2 rounded-xl border border-green-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-green-100 bg-gradient-to-r from-green-50/70 to-white flex items-center justify-between gap-3">
          <div>
            <h2 class="text-lg font-bold text-green-800 m-0">Recent exam results</h2>
            <p class="text-sm text-gray-500 m-0 mt-1">Latest scores from your exams</p>
          </div>
          <a href="professor_monitor.php" class="text-green-700 font-semibold hover:underline inline-flex items-center gap-1">
            View monitor <i class="bi bi-arrow-right"></i>
          </a>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm text-left">
            <thead class="bg-green-50 text-green-800 font-semibold">
              <tr>
                <th class="px-4 py-3">Student</th>
                <th class="px-4 py-3">Exam</th>
                <th class="px-4 py-3">Score</th>
                <th class="px-4 py-3">Submitted</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-green-100">
              <?php if (empty($attemptRows)): ?>
                <tr><td colspan="4" class="px-4 py-10 text-center text-gray-500">No exam submissions yet.</td></tr>
              <?php else: ?>
                <?php foreach ($attemptRows as $r): ?>
                  <tr class="hover:bg-green-50/80 transition-colors">
                    <td class="px-4 py-3 font-medium"><?php echo h($r['full_name']); ?></td>
                    <td class="px-4 py-3"><?php echo h($r['exam_title'] ?? ''); ?></td>
                    <td class="px-4 py-3 font-semibold text-green-700"><?php echo ($r['score'] !== null && $r['score'] !== '') ? h((string)$r['score']) . '%' : '—'; ?></td>
                    <td class="px-4 py-3 text-gray-600"><?php echo !empty($r['submitted_at']) ? h(date('M j, g:i A', strtotime($r['submitted_at']))) : '—'; ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Recent file submissions -->
      <div class="rounded-xl border border-green-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-green-100 bg-gradient-to-r from-green-50/70 to-white">
          <h2 class="text-lg font-bold text-green-800 m-0">Latest file uploads</h2>
          <p class="text-sm text-gray-500 m-0 mt-1">Student documents & assignments</p>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm text-left">
            <thead class="bg-green-50 text-green-800 font-semibold">
              <tr>
                <th class="px-4 py-3">Student</th>
                <th class="px-4 py-3">Task</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-green-100">
              <?php if (empty($subRows)): ?>
                <tr><td colspan="2" class="px-4 py-10 text-center text-gray-500">No file submissions yet.</td></tr>
              <?php else: ?>
                <?php foreach ($subRows as $s): ?>
                  <tr class="hover:bg-green-50/80 transition-colors">
                    <td class="px-4 py-3 font-medium"><?php echo h($s['full_name']); ?></td>
                    <td class="px-4 py-3">
                      <div class="flex flex-col">
                        <span class="font-medium text-gray-800"><?php echo h($s['task_title'] ?? ''); ?></span>
                        <?php if (!empty($s['file_name']) && !empty($s['file_path'])): ?>
                          <a href="<?php echo h($s['file_path']); ?>" class="text-green-700 font-semibold hover:underline text-xs mt-1" target="_blank" rel="noopener"><?php echo h($s['file_name']); ?></a>
                        <?php else: ?>
                          <span class="text-gray-500 text-xs mt-1">—</span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Upcoming exams -->
      <div class="rounded-xl border border-green-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-green-100 bg-gradient-to-r from-green-50/70 to-white">
          <h2 class="text-lg font-bold text-green-800 m-0">Upcoming exams</h2>
          <p class="text-sm text-gray-500 m-0 mt-1">Deadlines for your next assessments</p>
        </div>
        <div class="p-4">
          <?php if (empty($nextExams)): ?>
            <div class="text-center py-10 text-gray-500">No upcoming exams scheduled.</div>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($nextExams as $e): ?>
                <a href="professor_exam_edit.php?id=<?php echo (int)($e['exam_id'] ?? 0); ?>" class="group flex items-center justify-between gap-3 rounded-xl border border-green-100 bg-white p-3 hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
                  <div class="min-w-0">
                    <p class="font-semibold text-green-900 truncate"><?php echo h($e['title'] ?? ''); ?></p>
                    <p class="text-xs text-gray-500 mt-1 mb-0">
                      <?php echo !empty($e['deadline']) ? h(date('M j, Y g:i A', strtotime($e['deadline']))) : 'No deadline'; ?>
                    </p>
                  </div>
                  <div class="shrink-0 inline-flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded-lg text-xs font-semibold border border-green-100 <?php echo !empty($e['is_published']) ? 'bg-green-50 text-green-800' : 'bg-gray-50 text-gray-700'; ?>">
                      <?php echo !empty($e['is_published']) ? 'Published' : 'Draft'; ?>
                    </span>
                    <i class="bi bi-arrow-right text-green-700 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Upcoming tasks -->
      <div class="rounded-xl border border-green-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-green-100 bg-gradient-to-r from-green-50/70 to-white">
          <h2 class="text-lg font-bold text-green-800 m-0">Upcoming upload tasks</h2>
          <p class="text-sm text-gray-500 m-0 mt-1">Deadlines for student submissions</p>
        </div>
        <div class="p-4">
          <?php if (empty($nextTasks)): ?>
            <div class="text-center py-10 text-gray-500">No upcoming upload tasks.</div>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($nextTasks as $t): ?>
                <a href="professor_upload_tasks.php?edit=<?php echo (int)($t['task_id'] ?? 0); ?>" class="group flex items-center justify-between gap-3 rounded-xl border border-green-100 bg-white p-3 hover:shadow-md hover:-translate-y-0.5 transition-all duration-300">
                  <div class="min-w-0">
                    <p class="font-semibold text-green-900 truncate"><?php echo h($t['title'] ?? ''); ?></p>
                    <p class="text-xs text-gray-500 mt-1 mb-0">
                      <?php echo !empty($t['deadline']) ? h(date('M j, Y g:i A', strtotime($t['deadline']))) : 'No deadline'; ?>
                    </p>
                  </div>
                  <div class="shrink-0 inline-flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded-lg text-xs font-semibold border border-green-100 <?php echo !empty($t['is_open']) ? 'bg-green-50 text-green-800' : 'bg-gray-50 text-gray-700'; ?>">
                      <?php echo !empty($t['is_open']) ? 'Open' : 'Closed'; ?>
                    </span>
                    <i class="bi bi-arrow-right text-green-700 opacity-0 group-hover:opacity-100 transition-opacity"></i>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
