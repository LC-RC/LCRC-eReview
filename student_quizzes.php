<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/quiz_helpers.php';
requireRole('student');

// Quizzers are inside each subject; redirect to Subjects
header('Location: student_subjects.php');
exit;

// Load attempt state per quiz (in_progress + last submitted) for this user
$attemptsByQuiz = [];
$lastResultByQuiz = [];
if ($userId) {
    try {
        $ar = mysqli_query($conn, "SELECT attempt_id, quiz_id, status, score, correct_count, total_count FROM quiz_attempts WHERE user_id=".(int)$userId." ORDER BY attempt_id DESC");
    } catch (mysqli_sql_exception $e) {
        $ar = false; // table may not exist yet; run run_once_migration_quiz.php or quiz_schema_migration.sql
    }
    if ($ar) {
        while ($row = mysqli_fetch_assoc($ar)) {
            $qid = (int)$row['quiz_id'];
            if ($row['status'] === 'in_progress' && !isset($attemptsByQuiz[$qid])) {
                $attemptsByQuiz[$qid] = (int)$row['attempt_id'];
            }
            if ($row['status'] === 'submitted' && !isset($lastResultByQuiz[$qid])) {
                $lastResultByQuiz[$qid] = ['score' => (float)$row['score'], 'correct' => (int)$row['correct_count'], 'total' => (int)$row['total_count']];
            }
        }
    }
}
function formatTimeLimitMinutes($minutes) {
  $m = (int) $minutes;
  if ($m < 60) return $m . ' minute' . ($m !== 1 ? 's' : '');
  $hours = floor($m / 60);
  $mins = $m % 60;
  $hStr = $hours . ' hour' . ($hours !== 1 ? 's' : '');
  if ($mins === 0) return $hStr;
  return $hStr . ' and ' . $mins . ' min' . ($mins !== 1 ? 's' : '');
}
$pageTitle = 'Quizzers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-question-circle"></i> Quizzers
    </h1>
  </div>

  <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
    <?php if ($quizzesResult && mysqli_num_rows($quizzesResult) > 0): ?>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-left">
          <thead>
            <tr class="bg-gray-50 border-b border-gray-200">
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Quiz</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Subject</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Questions</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Duration</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Last score</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Action</th>
            </tr>
          </thead>
          <tbody>
      <?php while ($q = mysqli_fetch_assoc($quizzesResult)): ?>
        <?php
          $questionsCount = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM quiz_questions WHERE quiz_id=".(int)$q['quiz_id']);
          $questionsRow = $questionsCount ? mysqli_fetch_assoc($questionsCount) : ['cnt' => 0];
          $quizId = (int)$q['quiz_id'];
          $hasQuestions = (int)($questionsRow['cnt'] ?? 0) > 0;
          $inProgressAttemptId = $attemptsByQuiz[$quizId] ?? null;
          $lastResult = $lastResultByQuiz[$quizId] ?? null;
          $timeLimitSeconds = getQuizTimeLimitSeconds($q);
        ?>
            <tr class="border-b border-gray-100 hover:bg-gray-50/80 transition">
              <td class="px-4 py-3">
                <span class="font-semibold text-gray-800"><?php echo h($q['title']); ?></span>
              </td>
              <td class="px-4 py-3 text-gray-600 text-sm"><?php echo h($q['subject_name']); ?></td>
              <td class="px-4 py-3 text-gray-600 text-sm"><?php echo (int)($questionsRow['cnt'] ?? 0); ?></td>
              <td class="px-4 py-3 text-gray-600 text-sm"><?php echo formatTimeLimitSeconds($timeLimitSeconds); ?></td>
              <td class="px-4 py-3 text-sm">
                <?php if ($lastResult): ?>
                  <span class="font-medium text-gray-800"><?php echo number_format($lastResult['score'], 0); ?>%</span>
                  <span class="text-gray-500">(<?php echo $lastResult['correct']; ?>/<?php echo $lastResult['total']; ?>)</span>
                <?php else: ?>
                  <span class="text-gray-400">—</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-right">
                <?php if ($hasQuestions): ?>
                  <?php if ($inProgressAttemptId): ?>
                    <a href="student_take_quiz.php?quiz_id=<?php echo $quizId; ?>&attempt_id=<?php echo $inProgressAttemptId; ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold bg-amber-500 text-white hover:bg-amber-600 transition">
                      <i class="bi bi-play-fill"></i> Resume
                    </a>
                  <?php else: ?>
                    <a href="student_take_quiz.php?quiz_id=<?php echo $quizId; ?><?php echo $lastResult ? '&retake=1' : ''; ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold bg-primary text-white hover:bg-primary-dark transition">
                      <i class="bi bi-play-circle"></i> <?php echo $lastResult ? 'Take Again' : 'Take Quiz'; ?>
                    </a>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-gray-400 text-sm">No questions yet</span>
                <?php endif; ?>
              </td>
            </tr>
      <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="p-12 text-center text-gray-500">
        <i class="bi bi-inbox text-5xl block mb-3"></i>
        <p class="text-lg font-semibold">No quizzes available yet.</p>
      </div>
    <?php endif; ?>
  </div>
</main>
</div>
</body>
</html>
