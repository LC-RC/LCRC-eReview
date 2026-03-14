<?php
require_once 'auth.php';
requireRole('student');

$quizId = sanitizeInt($_GET['quiz_id'] ?? 0);
if ($quizId <= 0) { header('Location: student_quizzes.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT q.*, s.subject_name FROM quizzes q JOIN subjects s ON s.subject_id=q.subject_id WHERE q.quiz_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $quizId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$quiz = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$quiz) { header('Location: student_quizzes.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    $userId = getCurrentUserId();
    $answers = $_POST['answers'] ?? [];
    $stmt = mysqli_prepare($conn, "DELETE qa FROM quiz_answers qa JOIN quiz_questions qq ON qa.question_id=qq.question_id WHERE qa.user_id=? AND qq.quiz_id=?");
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $quizId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $correct = 0;
    $total = 0;
    foreach ($answers as $questionId => $selectedAnswer) {
        $questionId = sanitizeInt($questionId);
        $stmt = mysqli_prepare($conn, "SELECT correct_answer FROM quiz_questions WHERE question_id=? AND quiz_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $questionId, $quizId);
        mysqli_stmt_execute($stmt);
        $qResult = mysqli_stmt_get_result($stmt);
        $question = mysqli_fetch_assoc($qResult);
        mysqli_stmt_close($stmt);
        if ($question) {
            $total++;
            $isCorrect = ($selectedAnswer === $question['correct_answer']);
            if ($isCorrect) $correct++;
            $stmt = mysqli_prepare($conn, "INSERT INTO quiz_answers (user_id, question_id, selected_answer, is_correct) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iisi', $userId, $questionId, $selectedAnswer, $isCorrect);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    $score = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
    $_SESSION['quiz_result'] = ['quiz_id' => $quizId, 'score' => $score, 'correct' => $correct, 'total' => $total];
    header('Location: student_take_quiz.php?quiz_id='.$quizId.'&result=1');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY question_id ASC");
mysqli_stmt_bind_param($stmt, 'i', $quizId);
mysqli_stmt_execute($stmt);
$questions = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

$pageTitle = 'Take Quiz - ' . $quiz['title'];
$showResult = isset($_GET['result']) && isset($_SESSION['quiz_result']) && $_SESSION['quiz_result']['quiz_id'] == $quizId;
$result = $showResult ? $_SESSION['quiz_result'] : null;
if ($showResult) unset($_SESSION['quiz_result']);

$questionsCount = mysqli_num_rows($questions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-question-circle"></i> <?php echo h($quiz['title']); ?> (<?php echo h($quiz['subject_name']); ?>)
    </h1>
  </div>

  <?php if ($showResult && $result): ?>
    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-8 mb-5 text-center">
      <h2 class="text-xl font-bold text-gray-800 mb-4">Quiz Results</h2>
      <div class="text-5xl font-bold mb-3 <?php echo $result['score'] >= 70 ? 'text-green-600' : ($result['score'] >= 50 ? 'text-amber-600' : 'text-red-600'); ?>">
        <?php echo $result['score']; ?>%
      </div>
      <p class="text-gray-600 mb-4">You got <strong><?php echo $result['correct']; ?></strong> out of <strong><?php echo $result['total']; ?></strong> questions correct.</p>
      <a href="student_quizzes.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition">Back to Quizzes</a>
    </div>
  <?php endif; ?>

  <?php if (!$showResult): ?>
    <form method="POST" id="quizForm">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-4">
        <div class="flex justify-between items-center flex-wrap gap-2 mb-2">
          <span class="font-semibold text-gray-800 flex items-center gap-2"><i class="bi bi-info-circle"></i> Instructions</span>
          <span class="px-2.5 py-1 rounded-full text-sm font-medium bg-primary text-white"><?php echo $questionsCount; ?> Questions</span>
        </div>
        <p class="text-gray-600 mb-0">Read each question carefully and select the best answer. You can review your answers before submitting.</p>
      </div>

      <?php $qNum = 1; while ($question = mysqli_fetch_assoc($questions)): ?>
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-4">
          <h3 class="font-semibold text-gray-800 mb-3">Question <?php echo $qNum++; ?>: <?php echo h($question['question_text']); ?></h3>
          <div class="space-y-2">
            <label class="flex items-start gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer">
              <input type="radio" name="answers[<?php echo (int)$question['question_id']; ?>]" value="A" required class="mt-1 rounded-full border-gray-300 text-primary focus:ring-primary">
              <span>A. <?php echo h($question['choice_a']); ?></span>
            </label>
            <label class="flex items-start gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer">
              <input type="radio" name="answers[<?php echo (int)$question['question_id']; ?>]" value="B" class="mt-1 rounded-full border-gray-300 text-primary focus:ring-primary">
              <span>B. <?php echo h($question['choice_b']); ?></span>
            </label>
            <label class="flex items-start gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer">
              <input type="radio" name="answers[<?php echo (int)$question['question_id']; ?>]" value="C" class="mt-1 rounded-full border-gray-300 text-primary focus:ring-primary">
              <span>C. <?php echo h($question['choice_c']); ?></span>
            </label>
            <label class="flex items-start gap-3 p-3 rounded-lg border border-gray-200 hover:bg-gray-50 cursor-pointer">
              <input type="radio" name="answers[<?php echo (int)$question['question_id']; ?>]" value="D" class="mt-1 rounded-full border-gray-300 text-primary focus:ring-primary">
              <span>D. <?php echo h($question['choice_d']); ?></span>
            </label>
          </div>
        </div>
      <?php endwhile; ?>

      <?php if ($questionsCount > 0): ?>
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 flex flex-wrap justify-between items-center gap-4">
          <a href="student_quizzes.php" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Cancel</a>
          <button type="submit" name="submit_quiz" class="px-6 py-3 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2">
            <i class="bi bi-check-circle"></i> Submit Quiz
          </button>
        </div>
      <?php else: ?>
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-12 text-center text-gray-500">
          <i class="bi bi-inbox text-5xl block mb-3"></i>
          <p class="text-lg font-semibold">No questions available for this quiz.</p>
          <a href="student_quizzes.php" class="mt-3 inline-flex items-center gap-2 px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Back to Quizzes</a>
        </div>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
