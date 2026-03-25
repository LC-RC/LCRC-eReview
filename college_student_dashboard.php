<?php
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'College Portal';
$uid = getCurrentUserId();
$csrf = generateCSRFToken();

$now = date('Y-m-d H:i:s');

$activeExams = 0;
$r = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exams e WHERE e.is_published=1 AND (e.available_from IS NULL OR e.available_from <= '$now') AND (e.deadline IS NULL OR e.deadline >= '$now')");
if ($r) {
    $activeExams = (int)(mysqli_fetch_assoc($r)['c'] ?? 0);
    mysqli_free_result($r);
}

$pendingUploads = 0;
$r2 = @mysqli_query($conn, "
  SELECT COUNT(*) AS c FROM college_upload_tasks t
  LEFT JOIN college_submissions s ON s.task_id=t.task_id AND s.user_id=" . (int)$uid . "
  WHERE t.is_open=1 AND t.deadline >= '$now' AND s.submission_id IS NULL
");
if ($r2) {
    $pendingUploads = (int)(mysqli_fetch_assoc($r2)['c'] ?? 0);
    mysqli_free_result($r2);
}

$completedExams = 0;
$r3 = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exam_attempts WHERE user_id=" . (int)$uid . " AND status='submitted'");
if ($r3) {
    $completedExams = (int)(mysqli_fetch_assoc($r3)['c'] ?? 0);
    mysqli_free_result($r3);
}

$upcoming = [];
$uq = @mysqli_query($conn, "
  SELECT e.exam_id, e.title, e.deadline FROM college_exams e
  WHERE e.is_published=1 AND e.deadline IS NOT NULL AND e.deadline > '$now'
  ORDER BY e.deadline ASC LIMIT 5
");
if ($uq) {
    while ($row = mysqli_fetch_assoc($uq)) {
        $upcoming[] = $row;
    }
    mysqli_free_result($uq);
}

$uploadDue = [];
$tq = @mysqli_query($conn, "
  SELECT t.task_id, t.title, t.deadline FROM college_upload_tasks t
  LEFT JOIN college_submissions s ON s.task_id=t.task_id AND s.user_id=" . (int)$uid . "
  WHERE t.is_open=1 AND t.deadline >= '$now' AND s.submission_id IS NULL
  ORDER BY t.deadline ASC LIMIT 5
");
if ($tq) {
    while ($row = mysqli_fetch_assoc($tq)) {
        $uploadDue[] = $row;
    }
    mysqli_free_result($tq);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="student-dashboard-page max-w-7xl mx-auto w-full px-4 sm:px-5 pt-2 pb-10">
    <section class="relative overflow-hidden rounded-2xl mb-6 px-6 py-7 border-0 shadow-[0_8px_30px_rgba(20,61,89,0.2)]">
      <div class="absolute inset-0 bg-gradient-to-br from-[#1665A0] via-[#145a8f] to-[#143D59]"></div>
      <div class="relative z-10 text-white">
        <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3">
          <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 border border-white/30"><i class="bi bi-mortarboard"></i></span>
          College portal
        </h1>
        <p class="text-white/90 mt-2 mb-0 max-w-2xl">Quizzes, exams, and assignment uploads for your courses.</p>
      </div>
    </section>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
      <a href="college_exams.php" class="rounded-2xl border border-[#1665A0]/20 bg-white p-5 shadow-student-card hover:shadow-student-card-hover transition flex items-center gap-4">
        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-[#e8f2fa] text-[#1665A0] text-2xl"><i class="bi bi-journal-text"></i></span>
        <div>
          <p class="text-sm text-gray-500 m-0">Open exams</p>
          <p class="text-2xl font-bold text-[#143D59] m-0"><?php echo (int)$activeExams; ?></p>
        </div>
      </a>
      <a href="college_uploads.php" class="rounded-2xl border border-amber-200 bg-white p-5 shadow-student-card hover:shadow-student-card-hover transition flex items-center gap-4">
        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-600 text-2xl"><i class="bi bi-cloud-upload"></i></span>
        <div>
          <p class="text-sm text-gray-500 m-0">Pending uploads</p>
          <p class="text-2xl font-bold text-[#143D59] m-0"><?php echo (int)$pendingUploads; ?></p>
        </div>
      </a>
      <div class="rounded-2xl border border-emerald-200 bg-white p-5 shadow-student-card flex items-center gap-4">
        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 text-2xl"><i class="bi bi-check2-circle"></i></span>
        <div>
          <p class="text-sm text-gray-500 m-0">Exams completed</p>
          <p class="text-2xl font-bold text-[#143D59] m-0"><?php echo (int)$completedExams; ?></p>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <article class="rounded-2xl border border-[#1665A0]/15 bg-white overflow-hidden shadow-[0_2px_12px_rgba(20,61,89,0.08)]">
        <div class="px-5 py-4 border-b border-[#1665A0]/10 bg-gradient-to-r from-[#f0f7fc] to-white flex items-center justify-between">
          <h2 class="text-lg font-bold text-[#143D59] m-0 flex items-center gap-2"><i class="bi bi-alarm"></i> Exam deadlines</h2>
          <a href="college_exams.php" class="text-sm font-semibold text-[#1665A0] hover:underline">View all</a>
        </div>
        <div class="p-5">
          <?php if (empty($upcoming)): ?>
            <p class="text-gray-500 m-0">No upcoming deadlines.</p>
          <?php else: ?>
            <ul class="divide-y divide-gray-100 m-0 p-0 list-none">
              <?php foreach ($upcoming as $u): ?>
              <li class="py-3 flex justify-between gap-3">
                <span class="font-medium text-gray-800"><?php echo h($u['title']); ?></span>
                <span class="text-sm text-amber-700 whitespace-nowrap"><?php echo h(date('M j, g:i A', strtotime($u['deadline']))); ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </article>

      <article class="rounded-2xl border border-[#1665A0]/15 bg-white overflow-hidden shadow-[0_2px_12px_rgba(20,61,89,0.08)]">
        <div class="px-5 py-4 border-b border-[#1665A0]/10 bg-gradient-to-r from-[#f0f7fc] to-white flex items-center justify-between">
          <h2 class="text-lg font-bold text-[#143D59] m-0 flex items-center gap-2"><i class="bi bi-upload"></i> Upload due</h2>
          <a href="college_uploads.php" class="text-sm font-semibold text-[#1665A0] hover:underline">View all</a>
        </div>
        <div class="p-5">
          <?php if (empty($uploadDue)): ?>
            <p class="text-gray-500 m-0">No pending uploads.</p>
          <?php else: ?>
            <ul class="divide-y divide-gray-100 m-0 p-0 list-none">
              <?php foreach ($uploadDue as $u): ?>
              <li class="py-3 flex justify-between gap-3">
                <span class="font-medium text-gray-800"><?php echo h($u['title']); ?></span>
                <span class="text-sm text-amber-700 whitespace-nowrap"><?php echo h(date('M j, g:i A', strtotime($u['deadline']))); ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </article>
    </div>
  </div>
</main>
</body>
</html>
