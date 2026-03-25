<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'Professor dashboard';
$csrf = generateCSRFToken();

$cs = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE role='college_student'");
$collegeStudents = $cs ? (int)mysqli_fetch_assoc($cs)['c'] : 0;
if ($cs) {
    mysqli_free_result($cs);
}

$exams = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exams");
$examCount = $exams ? (int)mysqli_fetch_assoc($exams)['c'] : 0;
if ($exams) {
    mysqli_free_result($exams);
}

$tasks = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_upload_tasks");
$taskCount = $tasks ? (int)mysqli_fetch_assoc($tasks)['c'] : 0;
if ($tasks) {
    mysqli_free_result($tasks);
}

$recentAttempts = @mysqli_query($conn, "
  SELECT a.score, a.submitted_at, u.full_name, e.title
  FROM college_exam_attempts a
  INNER JOIN users u ON u.user_id=a.user_id
  INNER JOIN college_exams e ON e.exam_id=a.exam_id
  WHERE a.status='submitted'
  ORDER BY a.submitted_at DESC
  LIMIT 8
");
$attemptRows = [];
if ($recentAttempts) {
    while ($r = mysqli_fetch_assoc($recentAttempts)) {
        $attemptRows[] = $r;
    }
    mysqli_free_result($recentAttempts);
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
      <h1 class="text-2xl font-bold text-green-800 m-0">Professor dashboard</h1>
      <p class="text-gray-600 mt-1 mb-0">Manage college students, exams, and file submissions.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
      <a href="professor_college_students.php" class="rounded-xl border border-green-200 bg-white p-5 shadow-sm hover:shadow-md transition">
        <p class="text-sm text-gray-500 m-0">College students</p>
        <p class="text-3xl font-bold text-green-700 m-0 mt-1"><?php echo $collegeStudents; ?></p>
      </a>
      <a href="professor_exams.php" class="rounded-xl border border-green-200 bg-white p-5 shadow-sm hover:shadow-md transition">
        <p class="text-sm text-gray-500 m-0">Exams</p>
        <p class="text-3xl font-bold text-green-700 m-0 mt-1"><?php echo $examCount; ?></p>
      </a>
      <a href="professor_upload_tasks.php" class="rounded-xl border border-green-200 bg-white p-5 shadow-sm hover:shadow-md transition">
        <p class="text-sm text-gray-500 m-0">Upload tasks</p>
        <p class="text-3xl font-bold text-green-700 m-0 mt-1"><?php echo $taskCount; ?></p>
      </a>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
      <div class="px-5 py-4 border-b border-gray-100 bg-[#f6f9ff]">
        <h2 class="text-lg font-bold text-green-800 m-0">Recent exam results</h2>
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
          <tbody class="divide-y divide-gray-100">
            <?php if (empty($attemptRows)): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No submissions yet.</td></tr>
            <?php else: ?>
              <?php foreach ($attemptRows as $r): ?>
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium"><?php echo h($r['full_name']); ?></td>
                <td class="px-4 py-3"><?php echo h($r['title']); ?></td>
                <td class="px-4 py-3"><?php echo h((string)$r['score']); ?>%</td>
                <td class="px-4 py-3 text-gray-600"><?php echo h(date('M j, g:i A', strtotime($r['submitted_at']))); ?></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
</body>
</html>
