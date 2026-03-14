<?php
require_once 'auth.php';
requireRole('student');

$quizzesResult = mysqli_query($conn, "SELECT q.*, s.subject_name FROM quizzes q JOIN subjects s ON s.subject_id=q.subject_id WHERE s.status='active' ORDER BY q.quiz_id DESC");
$pageTitle = 'Quizzes';
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
      <i class="bi bi-question-circle"></i> Quizzes
    </h1>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php if ($quizzesResult && mysqli_num_rows($quizzesResult) > 0): ?>
      <?php while ($q = mysqli_fetch_assoc($quizzesResult)): ?>
        <?php
          $questionsCount = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM quiz_questions WHERE quiz_id=".(int)$q['quiz_id']);
          $questionsRow = $questionsCount ? mysqli_fetch_assoc($questionsCount) : ['cnt' => 0];
          $badgeClass = 'bg-sky-100 text-sky-800';
          if ($q['quiz_type'] === 'pre-test') $badgeClass = 'bg-primary text-white';
          elseif ($q['quiz_type'] === 'post-test') $badgeClass = 'bg-green-100 text-green-800';
          elseif ($q['quiz_type'] === 'mock') $badgeClass = 'bg-amber-100 text-amber-800';
        ?>
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 h-full flex flex-col">
          <div class="flex justify-between items-start mb-2">
            <h2 class="text-lg font-bold text-gray-800"><?php echo h($q['title']); ?></h2>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium <?php echo $badgeClass; ?>"><?php echo h($q['quiz_type']); ?></span>
          </div>
          <p class="text-gray-500 text-sm mb-3"><i class="bi bi-book"></i> <?php echo h($q['subject_name']); ?></p>
          <p class="text-gray-500 text-sm mb-4"><i class="bi bi-question-circle"></i> <?php echo (int)$questionsRow['cnt']; ?> Questions</p>
          <?php if ($questionsRow['cnt'] > 0): ?>
            <a href="student_take_quiz.php?quiz_id=<?php echo (int)$q['quiz_id']; ?>" class="mt-auto w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition">
              <i class="bi bi-play-circle"></i> Take Quiz
            </a>
          <?php else: ?>
            <button type="button" disabled class="mt-auto w-full py-2.5 rounded-lg font-semibold bg-gray-300 text-gray-500 cursor-not-allowed">No questions yet</button>
          <?php endif; ?>
        </div>
      <?php endwhile; ?>
    <?php else: ?>
      <div class="col-span-full">
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-12 text-center text-gray-500">
          <i class="bi bi-inbox text-5xl block mb-3"></i>
          <p class="text-lg font-semibold">No quizzes available yet.</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
