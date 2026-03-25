<?php
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'Exams';
$uid = getCurrentUserId();
$now = date('Y-m-d H:i:s');

$list = [];
$q = @mysqli_query($conn, "
  SELECT e.*,
    a.status AS attempt_status,
    a.score,
    a.correct_count,
    a.total_count,
    a.submitted_at
  FROM college_exams e
  LEFT JOIN college_exam_attempts a ON a.exam_id=e.exam_id AND a.user_id=" . (int)$uid . "
  WHERE e.is_published=1
  ORDER BY e.deadline IS NULL, e.deadline ASC, e.title ASC
");
if ($q) {
    while ($row = mysqli_fetch_assoc($q)) {
        $list[] = $row;
    }
    mysqli_free_result($q);
}

function college_exam_available(array $e, string $now): bool {
    if (empty($e['is_published'])) {
        return false;
    }
    if (!empty($e['available_from']) && $e['available_from'] > $now) {
        return false;
    }
    if (!empty($e['deadline']) && $e['deadline'] < $now) {
        return false;
    }
    return true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="max-w-5xl mx-auto w-full px-4 sm:px-5 pt-2 pb-10">
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-[#143D59] m-0 flex items-center gap-2"><i class="bi bi-journal-text text-[#1665A0]"></i> Quizzes &amp; exams</h1>
      <p class="text-gray-600 mt-1 mb-0">Open assessments published by your instructors.</p>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
      <table class="w-full text-left text-sm">
        <thead class="bg-[#f6f9ff] text-[#143D59] font-semibold border-b border-gray-200">
          <tr>
            <th class="px-4 py-3">Title</th>
            <th class="px-4 py-3 hidden sm:table-cell">Deadline</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($list)): ?>
          <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No exams published yet.</td></tr>
          <?php else: ?>
            <?php foreach ($list as $e):
              $avail = college_exam_available($e, $now);
              $st = $e['attempt_status'] ?? '';
            ?>
            <tr class="hover:bg-gray-50/80">
              <td class="px-4 py-3 font-medium text-gray-900"><?php echo h($e['title']); ?></td>
              <td class="px-4 py-3 text-gray-600 hidden sm:table-cell"><?php echo $e['deadline'] ? h(date('M j, Y g:i A', strtotime($e['deadline']))) : '—'; ?></td>
              <td class="px-4 py-3">
                <?php if ($st === 'submitted'): ?>
                  <span class="inline-flex items-center gap-1 text-emerald-700 font-medium"><i class="bi bi-check-circle"></i> Done<?php echo $e['score'] !== null ? ' · ' . h($e['score']) . '%' : ''; ?></span>
                <?php elseif ($st === 'expired'): ?>
                  <span class="text-amber-700">Expired</span>
                <?php elseif ($st === 'in_progress'): ?>
                  <span class="text-[#1665A0]">In progress</span>
                <?php elseif (!$avail): ?>
                  <span class="text-gray-500">Closed</span>
                <?php else: ?>
                  <span class="text-gray-600">Not started</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <?php if ($st === 'submitted'): ?>
                  <a class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-semibold border border-[#1665A0] text-[#1665A0] hover:bg-[#1665A0] hover:text-white transition" href="college_take_exam.php?exam_id=<?php echo (int)$e['exam_id']; ?>&review=1">Review</a>
                <?php elseif ($avail && $st !== 'expired'): ?>
                  <a class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-semibold bg-[#1665A0] text-white hover:bg-[#145a8f] transition" href="college_take_exam.php?exam_id=<?php echo (int)$e['exam_id']; ?>"><?php echo $st === 'in_progress' ? 'Continue' : 'Start'; ?></a>
                <?php else: ?>
                  <span class="text-gray-400">—</span>
                <?php endif; ?>
              </td>
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
