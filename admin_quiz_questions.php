<?php
require_once 'auth.php';
requireRole('admin');

$csrf = generateCSRFToken();
$quizId = sanitizeInt($_GET['quiz_id'] ?? 0);
if ($quizId <= 0) { header('Location: admin_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT q.*, s.subject_name FROM quizzes q JOIN subjects s ON s.subject_id=q.subject_id WHERE q.quiz_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $quizId);
mysqli_stmt_execute($stmt);
$quizRes = mysqli_stmt_get_result($stmt);
$quiz = mysqli_fetch_assoc($quizRes);
mysqli_stmt_close($stmt);
if (!$quiz) { header('Location: admin_subjects.php'); exit; }
$subjectId = (int)$quiz['subject_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_quiz_questions.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
        exit;
    }
    $action = $_POST['action'] ?? 'save';
    if ($action === 'delete') {
        $questionId = sanitizeInt($_POST['question_id'] ?? 0);
        if ($questionId > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM quiz_questions WHERE question_id=? AND quiz_id=?");
            mysqli_stmt_bind_param($stmt, 'ii', $questionId, $quizId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Question deleted.';
        }
        header('Location: admin_quiz_questions.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
        exit;
    }
    $questionId = sanitizeInt($_POST['question_id'] ?? 0);
    $questionText = trim($_POST['question_text'] ?? '');
    $choiceA = trim($_POST['choice_a'] ?? '');
    $choiceB = trim($_POST['choice_b'] ?? '');
    $choiceC = trim($_POST['choice_c'] ?? '');
    $choiceD = trim($_POST['choice_d'] ?? '');
    $correctAnswer = $_POST['correct_answer'] ?? 'A';
    if ($questionText === '' || $choiceA === '' || $choiceB === '' || $choiceC === '' || $choiceD === '') {
        $_SESSION['error'] = 'Please complete the question and all choices.';
        header('Location: admin_quiz_questions.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
        exit;
    }
    if (!in_array($correctAnswer, ['A','B','C','D'], true)) $correctAnswer = 'A';
    if ($questionId > 0) {
        $stmt = mysqli_prepare($conn, "UPDATE quiz_questions SET question_text=?, choice_a=?, choice_b=?, choice_c=?, choice_d=?, correct_answer=? WHERE question_id=? AND quiz_id=?");
        mysqli_stmt_bind_param($stmt, 'ssssssii', $questionText, $choiceA, $choiceB, $choiceC, $choiceD, $correctAnswer, $questionId, $quizId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Question updated.';
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO quiz_questions (quiz_id, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer) VALUES (?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'issssss', $quizId, $questionText, $choiceA, $choiceB, $choiceC, $choiceD, $correctAnswer);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Question added.';
    }
    header('Location: admin_quiz_questions.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = sanitizeInt($_GET['edit']);
    if ($eid > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM quiz_questions WHERE question_id=? AND quiz_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $eid, $quizId);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $edit = mysqli_fetch_assoc($r);
        mysqli_stmt_close($stmt);
    }
}

$stmt = mysqli_prepare($conn, "SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY question_id DESC");
mysqli_stmt_bind_param($stmt, 'i', $quizId);
mysqli_stmt_execute($stmt);
$questions = mysqli_stmt_get_result($stmt);

$pageTitle = 'Quiz Questions - ' . $quiz['title'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased" x-data="quizQuestionsApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-question-circle"></i> Quiz Questions - <?php echo h($quiz['title']); ?> (<?php echo h($quiz['subject_name']); ?>)
    </h1>
    <p class="text-gray-500 mt-1">Add and manage questions for this quiz.</p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-4 mb-5">
    <div></div>
    <div class="flex gap-2">
      <a href="admin_quizzes.php?subject_id=<?php echo (int)$subjectId; ?>" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Back to Quizzes</a>
      <button type="button" @click="openNewQuestion()" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> New Question</button>
    </div>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-green-50 border border-green-200 flex items-center gap-2 text-green-800">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
      <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 flex justify-between items-center">
      <span class="font-semibold text-gray-800">Questions</span>
      <span class="text-gray-500 text-sm">Quiz: <?php echo h($quiz['title']); ?></span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-left">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-5 py-3 font-semibold text-gray-700">Question</th>
            <th class="px-5 py-3 font-semibold text-gray-700">Correct</th>
            <th class="px-5 py-3 font-semibold text-gray-700 w-[280px]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $hasAny = false; while ($qq = mysqli_fetch_assoc($questions)): $hasAny = true;
            $qPreview = mb_substr((string)$qq['question_text'], 0, 80) . (mb_strlen((string)$qq['question_text']) > 80 ? '…' : '');
          ?>
            <tr class="border-b border-gray-100 hover:bg-gray-50/50">
              <td class="px-5 py-3 text-gray-800"><?php echo h($qPreview); ?></td>
              <td class="px-5 py-3"><span class="px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800"><?php echo h($qq['correct_answer']); ?></span></td>
              <td class="px-5 py-3">
                <div class="flex flex-wrap gap-2">
                  <button type="button"
                    data-id="<?php echo (int)$qq['question_id']; ?>"
                    data-text="<?php echo h($qq['question_text'] ?? ''); ?>"
                    data-a="<?php echo h($qq['choice_a'] ?? ''); ?>"
                    data-b="<?php echo h($qq['choice_b'] ?? ''); ?>"
                    data-c="<?php echo h($qq['choice_c'] ?? ''); ?>"
                    data-d="<?php echo h($qq['choice_d'] ?? ''); ?>"
                    data-correct="<?php echo h($qq['correct_answer'] ?? 'A'); ?>"
                    @click="openEditFromEl($el)"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition"><i class="bi bi-pencil"></i> Edit</button>
                  <button type="button" data-id="<?php echo (int)$qq['question_id']; ?>" data-text="<?php echo h($qPreview); ?>" @click="openDeleteQuestion($el.dataset.id, $el.dataset.text || '')" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition"><i class="bi bi-trash"></i> Delete</button>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          <?php if (!$hasAny): ?>
            <tr>
              <td colspan="3" class="px-5 py-12 text-center text-gray-500">
                <i class="bi bi-inbox text-4xl block mb-2"></i>
                <div class="font-semibold">No questions yet</div>
                <p class="text-sm mt-1">Add your first question to complete this quiz.</p>
                <button type="button" @click="openNewQuestion()" class="mt-3 px-4 py-2 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> New Question</button>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php mysqli_stmt_close($stmt); ?>

  <!-- Create/Edit Question Modal -->
  <div x-show="questionModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="questionModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="questionModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal max-w-2xl w-full max-h-[90vh] overflow-y-auto" @click.stop>
      <div class="p-5 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-xl font-bold text-gray-800 m-0" x-text="isEdit ? 'Edit Question' : 'New Question'"></h2>
        <button type="button" @click="questionModalOpen = false" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_quiz_questions.php?quiz_id=<?php echo (int)$quizId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="p-5">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="question_id" :value="question_id">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Question</label>
            <textarea name="question_text" x-model="question_text" rows="3" required class="input-custom"></textarea>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Choice A</label><input type="text" name="choice_a" x-model="choice_a" required class="input-custom"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Choice B</label><input type="text" name="choice_b" x-model="choice_b" required class="input-custom"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Choice C</label><input type="text" name="choice_c" x-model="choice_c" required class="input-custom"></div>
            <div><label class="block text-sm font-medium text-gray-700 mb-1">Choice D</label><input type="text" name="choice_d" x-model="choice_d" required class="input-custom"></div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Correct Answer</label>
            <select name="correct_answer" x-model="correct_answer" class="input-custom w-24" required>
              <option value="A">A</option>
              <option value="B">B</option>
              <option value="C">C</option>
              <option value="D">D</option>
            </select>
          </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" @click="questionModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-save"></i> <span x-text="isEdit ? 'Update' : 'Add'"></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Question Modal -->
  <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="deleteModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="deleteModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal max-w-md w-full p-5" @click.stop>
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold text-gray-800 m-0"><i class="bi bi-trash text-red-500 mr-2"></i> Delete Question</h2>
        <button type="button" @click="deleteModalOpen = false" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_quiz_questions.php?quiz_id=<?php echo (int)$quizId; ?>&subject_id=<?php echo (int)$subjectId; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="question_id" :value="delete_question_id">
        <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 mb-4">
          <div class="font-semibold">This will permanently delete the question.</div>
          <div class="text-sm mt-1">Question: <span class="font-semibold" x-text="delete_question_text"></span></div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="deleteModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-red-600 text-white hover:bg-red-700 transition inline-flex items-center gap-2"><i class="bi bi-trash"></i> Delete</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function quizQuestionsApp() {
      return {
        questionModalOpen: false,
        deleteModalOpen: false,
        isEdit: false,
        question_id: 0,
        question_text: '',
        choice_a: '',
        choice_b: '',
        choice_c: '',
        choice_d: '',
        correct_answer: 'A',
        delete_question_id: 0,
        delete_question_text: '',
        editFromServer: <?php echo !empty($edit) ? json_encode([
          'id' => (int)$edit['question_id'],
          'question_text' => $edit['question_text'] ?? '',
          'choice_a' => $edit['choice_a'] ?? '',
          'choice_b' => $edit['choice_b'] ?? '',
          'choice_c' => $edit['choice_c'] ?? '',
          'choice_d' => $edit['choice_d'] ?? '',
          'correct_answer' => $edit['correct_answer'] ?? 'A'
        ]) : 'null'; ?>,
        openNewQuestion() {
          this.isEdit = false;
          this.question_id = 0;
          this.question_text = '';
          this.choice_a = '';
          this.choice_b = '';
          this.choice_c = '';
          this.choice_d = '';
          this.correct_answer = 'A';
          this.questionModalOpen = true;
        },
        openEditFromEl(el) {
          const d = el.dataset;
          this.isEdit = true;
          this.question_id = d.id || 0;
          this.question_text = d.text || '';
          this.choice_a = d.a || '';
          this.choice_b = d.b || '';
          this.choice_c = d.c || '';
          this.choice_d = d.d || '';
          this.correct_answer = (d.correct === 'B' || d.correct === 'C' || d.correct === 'D') ? d.correct : 'A';
          this.questionModalOpen = true;
        },
        openDeleteQuestion(id, text) {
          this.delete_question_id = id;
          this.delete_question_text = text || '';
          this.deleteModalOpen = true;
        },
        initEditFromServer() {
          if (this.editFromServer) {
            this.isEdit = true;
            this.question_id = this.editFromServer.id;
            this.question_text = this.editFromServer.question_text || '';
            this.choice_a = this.editFromServer.choice_a || '';
            this.choice_b = this.editFromServer.choice_b || '';
            this.choice_c = this.editFromServer.choice_c || '';
            this.choice_d = this.editFromServer.choice_d || '';
            this.correct_answer = this.editFromServer.correct_answer || 'A';
            this.questionModalOpen = true;
          }
        }
      };
    }
  </script>
</main>
</body>
</html>
