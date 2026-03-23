<?php
require_once 'auth.php';
requireRole('student');

$userId = getCurrentUserId();
$quizId = sanitizeInt($_GET['quiz_id'] ?? 0);

$where = "qa.user_id = " . (int)$userId . " AND qa.status = 'submitted'";
if ($quizId > 0) {
    $where .= " AND qa.quiz_id = " . (int)$quizId;
}

$historyRes = mysqli_query($conn, "
    SELECT
      qa.attempt_id,
      qa.quiz_id,
      qa.score,
      qa.correct_count,
      qa.total_count,
      qa.started_at,
      qa.submitted_at,
      q.title AS quiz_title,
      q.subject_id,
      s.subject_name
    FROM quiz_attempts qa
    INNER JOIN quizzes q ON q.quiz_id = qa.quiz_id
    LEFT JOIN subjects s ON s.subject_id = q.subject_id
    WHERE $where
    ORDER BY qa.attempt_id DESC
    LIMIT 120
");

$items = [];
if ($historyRes) {
    while ($row = mysqli_fetch_assoc($historyRes)) {
        $items[] = $row;
    }
}

$pageTitle = 'Quiz History';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .quiz-history-page .rounded-2xl { border-radius: 0.75rem !important; }
    .quiz-history-page .rounded-xl { border-radius: 0.625rem !important; }
    .quiz-history-page .rounded-lg { border-radius: 0.5rem !important; }
  </style>
</head>
<body class="font-sans antialiased quiz-history-page">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page min-h-full pb-8">
    <section class="mb-5">
      <div class="rounded-2xl px-6 py-5 bg-gradient-to-r from-[#1665A0] to-[#143D59] text-white shadow-[0_10px_30px_rgba(20,61,89,0.35)] flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md">
            <i class="bi bi-clock-history text-xl" aria-hidden="true"></i>
          </span>
          <div>
            <h1 class="text-xl sm:text-2xl font-bold m-0 tracking-tight">Quiz History</h1>
            <p class="text-sm sm:text-base text-white/90 mt-1 mb-0">Your submitted quiz attempts and scores.</p>
          </div>
        </div>
        <a href="student_subjects.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold bg-white/15 hover:bg-white/25 border border-white/20 transition">
          <i class="bi bi-arrow-left-circle" aria-hidden="true"></i>
          <span>Back to Subjects</span>
        </a>
      </div>
    </section>

    <?php if (empty($items)): ?>
      <div class="rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white shadow-[0_1px_4px_rgba(15,23,42,0.08),0_6px_18px_rgba(15,23,42,0.06)] p-10 text-center text-[#143D59]/80">
        <i class="bi bi-inbox text-5xl mb-3 text-[#1665A0]" aria-hidden="true"></i>
        <p class="text-lg font-semibold m-0">No quiz history yet.</p>
        <p class="text-sm mt-1 mb-0">Submitted quizzes will appear here.</p>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <?php foreach ($items as $i => $row): ?>
          <?php
            $score = (float)($row['score'] ?? 0);
            $isPass = $score >= 50;
            $submittedTs = !empty($row['submitted_at']) ? strtotime($row['submitted_at']) : 0;
            $startedTs = !empty($row['started_at']) ? strtotime($row['started_at']) : 0;
            $spent = ($submittedTs > 0 && $startedTs > 0 && $submittedTs >= $startedTs) ? ($submittedTs - $startedTs) : 0;
            $mins = (int)floor($spent / 60);
            $secs = (int)($spent % 60);
            $spentStr = $spent > 0 ? ($mins > 0 ? ($mins . 'm ' . $secs . 's') : ($secs . 's')) : '—';
          ?>
          <article class="rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white shadow-[0_1px_4px_rgba(15,23,42,0.08),0_6px_18px_rgba(15,23,42,0.06)] p-4 sm:p-5">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <h2 class="text-base sm:text-lg font-bold text-[#143D59] m-0 truncate"><?php echo h($row['quiz_title'] ?? 'Quiz'); ?></h2>
                <p class="text-xs uppercase tracking-[0.14em] text-[#143D59]/60 mt-1 mb-0"><?php echo h($row['subject_name'] ?? 'Subject'); ?></p>
              </div>
              <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold <?php echo $isPass ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200'; ?>">
                <i class="bi <?php echo $isPass ? 'bi-check-circle-fill' : 'bi-x-circle-fill'; ?>"></i>
                <?php echo $isPass ? 'Passed' : 'Failed'; ?>
              </span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-4">
              <div class="rounded-lg bg-white border border-[#1665A0]/12 px-3 py-2">
                <div class="text-[0.65rem] uppercase tracking-[0.06em] text-[#64748b] font-bold">Score</div>
                <div class="text-sm font-extrabold text-[#1e293b]"><?php echo number_format($score, 0); ?>%</div>
              </div>
              <div class="rounded-lg bg-white border border-[#1665A0]/12 px-3 py-2">
                <div class="text-[0.65rem] uppercase tracking-[0.06em] text-[#64748b] font-bold">Correct</div>
                <div class="text-sm font-extrabold text-[#1e293b]"><?php echo (int)$row['correct_count']; ?>/<?php echo (int)$row['total_count']; ?></div>
              </div>
              <div class="rounded-lg bg-white border border-[#1665A0]/12 px-3 py-2">
                <div class="text-[0.65rem] uppercase tracking-[0.06em] text-[#64748b] font-bold">Time spent</div>
                <div class="text-sm font-extrabold text-[#1e293b]"><?php echo h($spentStr); ?></div>
              </div>
              <div class="rounded-lg bg-white border border-[#1665A0]/12 px-3 py-2">
                <div class="text-[0.65rem] uppercase tracking-[0.06em] text-[#64748b] font-bold">Attempt</div>
                <div class="text-sm font-extrabold text-[#1e293b]">#<?php echo (int)$row['attempt_id']; ?></div>
              </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
              <span class="text-xs text-[#64748b]"><?php echo !empty($row['submitted_at']) ? date('M j, Y g:i A', strtotime($row['submitted_at'])) : '—'; ?></span>
              <a href="student_take_quiz.php?quiz_id=<?php echo (int)$row['quiz_id']; ?>&view_result=1&subject_id=<?php echo (int)($row['subject_id'] ?? 0); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-[#1665A0] text-white hover:bg-[#0f4d7a] transition">
                <i class="bi bi-eye"></i> Open Result
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>

