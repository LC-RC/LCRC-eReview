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

$questionColumns = [];
$qqColRes = @mysqli_query($conn, "SHOW COLUMNS FROM quiz_questions");
if ($qqColRes) { while ($row = mysqli_fetch_assoc($qqColRes)) $questionColumns[] = $row['Field']; }
$hasExplanation = in_array('explanation', $questionColumns, true);
$hasChoiceFeedback = in_array('choice_a_feedback', $questionColumns, true);
// Support A-J choices (run quiz_choices_extend_migration.sql to add choice_e..choice_j)
$allChoiceCols = ['choice_a','choice_b','choice_c','choice_d','choice_e','choice_f','choice_g','choice_h','choice_i','choice_j'];
$choiceCols = array_values(array_intersect($allChoiceCols, $questionColumns));
$choiceLetters = array_map(function ($c) { return strtoupper(substr($c, -1)); }, $choiceCols);
$validCorrectLetters = ['A','B','C','D','E','F','G','H','I','J'];

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
    if ($action === 'add_batch') {
        $rawQuestions = $_POST['questions'] ?? [];
        if (!is_array($rawQuestions)) $rawQuestions = [];
        $batch = [];
        $batchIndex = 0;
        $needsExtendedChoices = false;
        foreach ($rawQuestions as $q) {
            if (!is_array($q)) continue;
            $questionText = trim($q['text'] ?? '');
            if ($questionText === '') continue;
            // Build choices from POST using all A–J so E,F,G,H,I,J are included for validation
            $choices = [];
            foreach ($allChoiceCols as $col) {
                $choices[$col] = trim($q[$col] ?? '');
            }
            $filled = array_filter($choices);
            if (count($filled) < 2) continue;
            $correct = trim($q['correct_answer'] ?? '');
            if (!in_array($correct, $validCorrectLetters, true)) {
                $_SESSION['error'] = 'Please select the correct answer for every question (Question ' . ($batchIndex + 1) . ').';
                header('Location: admin_quiz_questions.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
                exit;
            }
            $correctCol = 'choice_' . strtolower($correct);
            if (!isset($choices[$correctCol]) || $choices[$correctCol] === '') {
                $_SESSION['error'] = 'Correct answer must be one of the filled choices (Question ' . ($batchIndex + 1) . ').';
                header('Location: admin_quiz_questions.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
                exit;
            }
            if ($correct !== 'A' && $correct !== 'B' && $correct !== 'C' && $correct !== 'D') $needsExtendedChoices = true;
            foreach (['choice_e','choice_f','choice_g','choice_h','choice_i','choice_j'] as $ec) {
                if (!empty(trim($choices[$ec] ?? ''))) { $needsExtendedChoices = true; break; }
            }
            $row = ['question_text' => $questionText, 'correct_answer' => $correct, 'explanation' => $hasExplanation ? trim($q['explanation'] ?? '') : ''];
            foreach ($choiceCols as $col) {
                $row[$col] = $choices[$col] ?? '';
            }
            if ($hasChoiceFeedback) {
                foreach ($choiceCols as $col) {
                    $fbCol = $col . '_feedback';
                    if (in_array($fbCol, $questionColumns, true)) {
                        $row[$fbCol] = trim($q[$fbCol] ?? '');
                    }
                }
            }
            $batch[] = $row;
            $batchIndex++;
        }
        if (count($batch) === 0) {
            $_SESSION['error'] = 'Add at least one question with at least 2 choices and a correct answer selected.';
        } else {
            // Auto-run migration if user saved with E–J or more than 4 choices and DB not updated yet
            if ($needsExtendedChoices && !in_array('choice_e', $choiceCols, true)) {
                $alterSqls = [
                    "ALTER TABLE `quiz_questions` ADD COLUMN `choice_e` text DEFAULT NULL AFTER `choice_d`",
                    "ALTER TABLE `quiz_questions` ADD COLUMN `choice_f` text DEFAULT NULL AFTER `choice_e`",
                    "ALTER TABLE `quiz_questions` ADD COLUMN `choice_g` text DEFAULT NULL AFTER `choice_f`",
                    "ALTER TABLE `quiz_questions` ADD COLUMN `choice_h` text DEFAULT NULL AFTER `choice_g`",
                    "ALTER TABLE `quiz_questions` ADD COLUMN `choice_i` text DEFAULT NULL AFTER `choice_h`",
                    "ALTER TABLE `quiz_questions` ADD COLUMN `choice_j` text DEFAULT NULL AFTER `choice_i`",
                    "ALTER TABLE `quiz_questions` MODIFY `correct_answer` varchar(1) NOT NULL",
                    "ALTER TABLE `quiz_answers` MODIFY `selected_answer` varchar(1) DEFAULT NULL",
                ];
                foreach ($alterSqls as $sql) {
                    @mysqli_query($conn, $sql);
                }
                $questionColumns = [];
                $qqColRes = @mysqli_query($conn, "SHOW COLUMNS FROM quiz_questions");
                if ($qqColRes) { while ($r = mysqli_fetch_assoc($qqColRes)) $questionColumns[] = $r['Field']; }
                $hasChoiceFeedback = in_array('choice_a_feedback', $questionColumns, true);
                $choiceCols = array_values(array_intersect($allChoiceCols, $questionColumns));
                $batch = [];
                $batchIndex = 0;
                foreach ($rawQuestions as $q) {
                    if (!is_array($q)) continue;
                    $questionText = trim($q['text'] ?? '');
                    if ($questionText === '') continue;
                    $choices = [];
                    foreach ($allChoiceCols as $col) { $choices[$col] = trim($q[$col] ?? ''); }
                    $filled = array_filter($choices);
                    if (count($filled) < 2) continue;
                    $correct = trim($q['correct_answer'] ?? '');
                    if (!in_array($correct, $validCorrectLetters, true)) continue;
                    $correctCol = 'choice_' . strtolower($correct);
                    if (!isset($choices[$correctCol]) || $choices[$correctCol] === '') continue;
                    $row = ['question_text' => $questionText, 'correct_answer' => $correct, 'explanation' => $hasExplanation ? trim($q['explanation'] ?? '') : ''];
                    foreach ($choiceCols as $col) { $row[$col] = $choices[$col] ?? ''; }
                    if ($hasChoiceFeedback) {
                        foreach ($choiceCols as $col) {
                            $fc = $col . '_feedback';
                            if (in_array($fc, $questionColumns, true)) $row[$fc] = trim($q[$fc] ?? '');
                        }
                    }
                    $batch[] = $row;
                    $batchIndex++;
                }
            }
            if (count($batch) > 0) {
                mysqli_begin_transaction($conn);
                try {
                    $insertCols = ['quiz_id', 'question_text'];
                    foreach ($choiceCols as $c) { $insertCols[] = $c; }
                    $feedbackCols = [];
                    if ($hasChoiceFeedback) {
                        foreach ($choiceCols as $c) {
                            $fc = $c . '_feedback';
                            if (in_array($fc, $questionColumns, true)) $feedbackCols[] = $fc;
                        }
                        foreach ($feedbackCols as $fc) { $insertCols[] = $fc; }
                    }
                    $insertCols[] = 'correct_answer';
                    if ($hasExplanation) $insertCols[] = 'explanation';
                    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
                    $sql = "INSERT INTO quiz_questions (" . implode(', ', $insertCols) . ") VALUES ($placeholders)";
                    $stmt = mysqli_prepare($conn, $sql);
                    if ($stmt) {
                        foreach ($batch as $row) {
                            $types = 'is';
                            $refs = [&$quizId, &$row['question_text']];
                            foreach ($choiceCols as $col) {
                                $types .= 's';
                                $refs[] = &$row[$col];
                            }
                            foreach ($feedbackCols as $fc) {
                                $types .= 's';
                                $refs[] = &$row[$fc];
                            }
                            $types .= 's';
                            $refs[] = &$row['correct_answer'];
                            if ($hasExplanation) { $types .= 's'; $refs[] = &$row['explanation']; }
                            mysqli_stmt_bind_param($stmt, $types, ...$refs);
                            mysqli_stmt_execute($stmt);
                        }
                        mysqli_stmt_close($stmt);
                    }
                    mysqli_commit($conn);
                    $_SESSION['message'] = count($batch) . ' question(s) added.';
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $dbError = mysqli_error($conn);
                    $_SESSION['error'] = 'Could not save questions.' . ($dbError ? ' (' . h($dbError) . ')' : '');
                }
            }
        }
        header('Location: admin_quiz_questions.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
        exit;
    }
    $questionId = sanitizeInt($_POST['question_id'] ?? 0);
    $questionText = trim($_POST['question_text'] ?? '');
    // Read all choice columns from POST (A–J) so E,F,G,H,I,J save correctly
    $choiceVals = [];
    foreach ($allChoiceCols as $col) {
        $choiceVals[$col] = trim($_POST[$col] ?? '');
    }
    $filled = array_filter($choiceVals);
    if (count($filled) < 2) {
        $_SESSION['error'] = 'Please provide at least 2 choices.';
        header('Location: admin_quiz_questions.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
        exit;
    }
    $correctAnswer = trim($_POST['correct_answer'] ?? '');
    if ($correctAnswer === '' || !in_array($correctAnswer, $validCorrectLetters, true)) {
        $_SESSION['error'] = 'Please select the correct answer.';
        header('Location: admin_quiz_questions.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
        exit;
    }
    $correctCol = 'choice_' . strtolower($correctAnswer);
    if (!isset($choiceVals[$correctCol]) || $choiceVals[$correctCol] === '') {
        $_SESSION['error'] = 'Correct answer must be one of the filled choices.';
        header('Location: admin_quiz_questions.php?quiz_id='.$quizId.'&subject_id='.$subjectId);
        exit;
    }
    if ($questionId > 0) {
        $setParts = ['question_text=?'];
        foreach ($choiceCols as $c) { $setParts[] = $c . '=?'; }
        $setParts[] = 'correct_answer=?';
        $stmt = mysqli_prepare($conn, "UPDATE quiz_questions SET " . implode(', ', $setParts) . " WHERE question_id=? AND quiz_id=?");
        $types = 's' . str_repeat('s', count($choiceCols)) . 'sii';
        $refs = [&$questionText];
        foreach ($choiceCols as $c) { $refs[] = &$choiceVals[$c]; }
        $refs[] = &$correctAnswer;
        $refs[] = &$questionId;
        $refs[] = &$quizId;
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Question updated.';
    } else {
        $cols = array_merge(['quiz_id', 'question_text'], $choiceCols, ['correct_answer']);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = mysqli_prepare($conn, "INSERT INTO quiz_questions (" . implode(', ', $cols) . ") VALUES ($placeholders)");
        $types = 'is' . str_repeat('s', count($choiceCols)) . 's';
        $refs = [&$quizId, &$questionText];
        foreach ($choiceCols as $c) { $refs[] = &$choiceVals[$c]; }
        $refs[] = &$correctAnswer;
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
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
  <?php $baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>
  <link rel="stylesheet" href="<?php echo $baseUrl ? $baseUrl . '/' : ''; ?>assets/css/admin-quiz-ui.css">
</head>
<body class="font-sans antialiased bg-[#f6f9ff]" x-data="quizQuestionsApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <div class="admin-quiz-hero">
    <div class="flex items-center gap-3 mb-2">
      <a href="admin_quizzes.php?subject_id=<?php echo (int)$subjectId; ?>" class="text-primary hover:text-primary-dark text-sm font-medium inline-flex items-center gap-1"><i class="bi bi-arrow-left"></i> Quizzes</a>
      <span class="text-gray-400">/</span>
      <span class="text-gray-600 font-medium"><?php echo h($quiz['title']); ?></span>
    </div>
    <h1 class="text-2xl md:text-3xl font-bold text-[#012970] m-0 flex items-center gap-3">
      <span class="admin-quiz-hero-icon"><i class="bi bi-patch-question"></i></span>
      Quiz Questions
    </h1>
    <p class="text-gray-500 mt-2"><?php echo h($quiz['subject_name']); ?> — Add and manage questions in one go.</p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-4 mb-5">
    <div></div>
    <div class="flex flex-wrap gap-2">
      <a href="admin_quizzes.php?subject_id=<?php echo (int)$subjectId; ?>" class="admin-quiz-btn admin-quiz-btn-outline"><i class="bi bi-arrow-left-circle"></i> Back to Quizzes</a>
      <button type="button" @click="batchOpen = !batchOpen; batchError = ''" class="admin-quiz-btn admin-quiz-btn-primary"><i class="bi bi-collection-plus"></i> Add multiple questions</button>
    </div>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="admin-quiz-alert admin-quiz-alert-success">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="admin-quiz-alert admin-quiz-alert-error">
      <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <!-- Add multiple questions (batch) -->
  <div class="mb-6" x-show="batchOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
    <div class="admin-quiz-card">
      <div class="admin-quiz-card-header admin-quiz-card-header-accent">
        <span class="font-semibold text-gray-800 flex items-center gap-2"><i class="bi bi-stack"></i> Add multiple questions</span>
        <button type="button" @click="batchOpen = false" class="admin-quiz-close-btn" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_quiz_questions.php?quiz_id=<?php echo (int)$quizId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="p-6" @submit.prevent="validateBatchSubmit($event)">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="add_batch">
        <p x-show="batchError" x-text="batchError" class="admin-quiz-alert admin-quiz-alert-error mb-5"></p>
        <div class="space-y-6">
          <template x-for="(q, index) in batchQuestions" :key="index">
            <div class="admin-quiz-batch-question">
              <div class="admin-quiz-batch-question-label" x-text="'Question ' + (index + 1)"></div>
                <div class="space-y-4">
                <div>
                  <label class="admin-quiz-label">Question text</label>
                  <textarea :name="'questions[' + index + '][text]'" x-model="q.text" rows="2" required class="input-custom w-full admin-quiz-input" placeholder="Enter the question..."></textarea>
                </div>
                <div class="space-y-2">
                  <div>
                    <span class="admin-quiz-label">Choices (min 2, max 10)</span>
                  </div>
                  <template x-for="(ch, ci) in q.choices" :key="ci">
                    <div class="flex flex-wrap gap-2 items-center">
                      <span class="admin-quiz-choice-letter" x-text="ch.letter"></span>
                      <input type="text" :name="'questions[' + index + '][choice_' + ch.letter.toLowerCase() + ']'" x-model="ch.text" class="input-custom flex-1 min-w-0 admin-quiz-input" :placeholder="'Choice ' + ch.letter">
                      <?php if ($hasChoiceFeedback): ?>
                      <input type="text" :name="'questions[' + index + '][choice_' + ch.letter.toLowerCase() + '_feedback]'" x-model="ch.feedback" class="input-custom flex-1 min-w-0 admin-quiz-input text-sm text-gray-600" placeholder="Feedback (optional)">
                      <?php endif; ?>
                      <label class="admin-quiz-radio-wrap">
                        <input type="radio" :name="'questions[' + index + '][correct_answer]'" :value="ch.letter" x-model="q.correct_answer">
                        <span>Correct</span>
                      </label>
                      <button type="button" x-show="q.choices.length > 2" @click="removeChoice(index, ci)" class="admin-quiz-link-danger text-sm" title="Remove choice"><i class="bi bi-dash-circle"></i></button>
                    </div>
                  </template>
                  <div class="pt-1" x-show="q.choices.length < 10">
                    <button type="button" @click="addChoice(index)" class="admin-quiz-btn admin-quiz-btn-sm admin-quiz-btn-outline"><i class="bi bi-plus"></i> Add choice</button>
                  </div>
                </div>
                <?php if ($hasExplanation): ?>
                <div>
                  <label class="admin-quiz-label">Explanation (optional)</label>
                  <textarea :name="'questions[' + index + '][explanation]'" x-model="q.explanation" rows="1" class="input-custom w-full admin-quiz-input text-sm" placeholder="Why the correct answer is right..."></textarea>
                </div>
                <?php endif; ?>
              </div>
              <div class="admin-quiz-batch-question-footer">
                <button type="button" x-show="batchQuestions.length > 1" @click="removeBatchQuestion(index)" class="admin-quiz-link-danger"><i class="bi bi-trash"></i> Remove question</button>
              </div>
            </div>
          </template>
        </div>
        <div class="mt-6 pt-4 border-t border-gray-200 flex flex-wrap items-center gap-3">
          <button type="button" @click="addBatchQuestion()" class="admin-quiz-btn admin-quiz-btn-outline"><i class="bi bi-plus-lg"></i> Add question</button>
          <div class="flex-1"></div>
          <button type="button" @click="batchOpen = false" class="admin-quiz-btn admin-quiz-btn-outline">Cancel</button>
          <button type="submit" class="admin-quiz-btn admin-quiz-btn-primary"><i class="bi bi-check-lg"></i> Save all questions</button>
        </div>
      </form>
    </div>
  </div>

  <div class="admin-quiz-card">
    <div class="admin-quiz-card-header">
      <span class="font-semibold text-gray-800 flex items-center gap-2"><i class="bi bi-list-ol"></i> Questions list</span>
      <span class="text-gray-500 text-sm"><?php echo h($quiz['title']); ?></span>
    </div>
    <div class="overflow-x-auto">
      <table class="admin-quiz-table">
        <thead>
          <tr>
            <th>Question</th>
            <th class="w-24">Correct</th>
            <th class="w-[280px]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $hasAny = false; while ($qq = mysqli_fetch_assoc($questions)): $hasAny = true;
            $qPreview = mb_substr((string)$qq['question_text'], 0, 80) . (mb_strlen((string)$qq['question_text']) > 80 ? '…' : '');
          ?>
            <tr>
              <td class="font-medium text-gray-800"><?php echo h($qPreview); ?></td>
              <td><span class="admin-quiz-badge admin-quiz-badge-success"><?php echo h($qq['correct_answer']); ?></span></td>
              <td>
                <div class="flex flex-wrap gap-2">
                  <button type="button"
                    data-id="<?php echo (int)$qq['question_id']; ?>"
                    data-text="<?php echo h($qq['question_text'] ?? ''); ?>"
                    <?php foreach ($choiceCols as $col): $letter = strtolower(substr($col, -1)); ?>
                    data-<?php echo $letter; ?>="<?php echo h($qq[$col] ?? ''); ?>"
                    <?php if ($hasChoiceFeedback): $fc = $col . '_feedback'; if (in_array($fc, $questionColumns, true)): ?>
                    data-f<?php echo $letter; ?>="<?php echo h($qq[$fc] ?? ''); ?>"
                    <?php endif; endif; ?>
                    <?php endforeach; ?>
                    data-correct="<?php echo h($qq['correct_answer'] ?? 'A'); ?>"
                    data-explanation="<?php echo h($qq['explanation'] ?? ''); ?>"
                    @click="openEditFromEl($el)"
                    class="admin-quiz-btn admin-quiz-btn-sm admin-quiz-btn-outline"><i class="bi bi-pencil"></i> Edit</button>
                  <button type="button" data-id="<?php echo (int)$qq['question_id']; ?>" data-text="<?php echo h($qPreview); ?>" @click="openDeleteQuestion($el.dataset.id, $el.dataset.text || '')" class="admin-quiz-btn admin-quiz-btn-sm admin-quiz-btn-danger"><i class="bi bi-trash"></i> Delete</button>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          <?php if (!$hasAny): ?>
            <tr>
              <td colspan="3" class="admin-quiz-empty">
                <div class="admin-quiz-empty-icon"><i class="bi bi-inbox"></i></div>
                <div class="font-semibold text-gray-700">No questions yet</div>
                <p class="text-sm text-gray-500 mt-1">Click <strong>Add multiple questions</strong> above to add questions in one go.</p>
                <button type="button" @click="batchOpen = true; batchError = ''" class="admin-quiz-btn admin-quiz-btn-primary mt-4"><i class="bi bi-collection-plus"></i> Add multiple questions</button>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php mysqli_stmt_close($stmt); ?>

  <!-- Edit Question Modal (for editing existing questions only) -->
  <div x-show="questionModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="questionModalOpen = false">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="questionModalOpen = false"></div>
    <div class="relative admin-quiz-modal" @click.stop>
      <div class="admin-quiz-modal-header">
        <h2 class="m-0 text-lg font-bold text-gray-800">Edit Question</h2>
        <button type="button" @click="questionModalOpen = false" class="admin-quiz-close-btn" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_quiz_questions.php?quiz_id=<?php echo (int)$quizId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="p-6">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="question_id" :value="question_id">
        <div class="space-y-4">
          <div>
            <label class="admin-quiz-label">Question</label>
            <textarea name="question_text" x-model="question_text" rows="3" required class="input-custom w-full admin-quiz-input"></textarea>
          </div>
          <div class="space-y-2">
            <div>
              <label class="admin-quiz-label mb-0">Choices (min 2, max 10)</label>
            </div>
            <template x-for="(ch, ci) in editChoices" :key="ci">
              <div class="flex flex-wrap gap-2 items-center">
                <span class="admin-quiz-choice-letter" x-text="ch.letter"></span>
                <input type="text" :name="'choice_' + ch.letter.toLowerCase()" x-model="ch.text" class="input-custom flex-1 min-w-0 admin-quiz-input" :placeholder="'Choice ' + ch.letter">
                <label class="admin-quiz-radio-wrap">
                  <input type="radio" name="edit_correct_answer" :value="ch.letter" x-model="correct_answer">
                  <span>Correct</span>
                </label>
                <button type="button" x-show="editChoices.length > 2" @click="removeEditChoice(ci)" class="admin-quiz-link-danger text-sm" title="Remove choice"><i class="bi bi-dash-circle"></i></button>
              </div>
            </template>
            <div class="pt-1" x-show="editChoices.length < 10">
              <button type="button" @click="addEditChoice()" class="admin-quiz-btn admin-quiz-btn-sm admin-quiz-btn-outline"><i class="bi bi-plus"></i> Add choice</button>
            </div>
          </div>
          <input type="hidden" name="correct_answer" :value="correct_answer">
        </div>
        <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-gray-100">
          <button type="button" @click="questionModalOpen = false" class="admin-quiz-btn admin-quiz-btn-outline">Cancel</button>
          <button type="submit" class="admin-quiz-btn admin-quiz-btn-primary"><i class="bi bi-save"></i> Update</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Question Modal -->
  <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="deleteModalOpen = false">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="deleteModalOpen = false"></div>
    <div class="relative admin-quiz-modal admin-quiz-modal-sm" @click.stop>
      <div class="admin-quiz-modal-header">
        <h2 class="m-0 text-lg font-bold text-gray-800 flex items-center gap-2"><i class="bi bi-trash text-red-500"></i> Delete Question</h2>
        <button type="button" @click="deleteModalOpen = false" class="admin-quiz-close-btn" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_quiz_questions.php?quiz_id=<?php echo (int)$quizId; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="p-6">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="question_id" :value="delete_question_id">
        <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 mb-5">
          <div class="font-semibold">This will permanently delete the question.</div>
          <div class="text-sm mt-2 text-amber-700" x-text="'Question: ' + (delete_question_text || '')"></div>
        </div>
        <div class="flex justify-end gap-3">
          <button type="button" @click="deleteModalOpen = false" class="admin-quiz-btn admin-quiz-btn-outline">Cancel</button>
          <button type="submit" class="admin-quiz-btn admin-quiz-btn-danger"><i class="bi bi-trash"></i> Delete</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function newBatchQuestion() {
      return {
        text: '',
        choices: [
          { letter: 'A', text: '', feedback: '' },
          { letter: 'B', text: '', feedback: '' },
          { letter: 'C', text: '', feedback: '' },
          { letter: 'D', text: '', feedback: '' }
        ],
        correct_answer: '',
        explanation: ''
      };
    }
    function quizQuestionsApp() {
      return {
        questionModalOpen: false,
        deleteModalOpen: false,
        batchOpen: false,
        batchError: '',
        batchQuestions: [ newBatchQuestion() ],
        isEdit: false,
        question_id: 0,
        question_text: '',
        editChoices: [ {letter:'A',text:''}, {letter:'B',text:''}, {letter:'C',text:''}, {letter:'D',text:''} ],
        correct_answer: '',
        delete_question_id: 0,
        delete_question_text: '',
        addBatchQuestion() {
          this.batchQuestions.push(newBatchQuestion());
        },
        removeBatchQuestion(index) {
          if (this.batchQuestions.length <= 1) return;
          this.batchQuestions.splice(index, 1);
        },
        addChoice(index) {
          var q = this.batchQuestions[index];
          if (!q.choices || q.choices.length >= 10) return;
          var nextLetter = String.fromCharCode(65 + q.choices.length);
          q.choices.push({ letter: nextLetter, text: '', feedback: '' });
        },
        removeChoice(index, choiceIndex) {
          var q = this.batchQuestions[index];
          if (!q.choices || q.choices.length <= 2) return;
          q.choices.splice(choiceIndex, 1);
          for (var i = 0; i < q.choices.length; i++) q.choices[i].letter = String.fromCharCode(65 + i);
          if (!q.choices.some(function(c) { return c.letter === q.correct_answer; })) q.correct_answer = '';
        },
        validateBatchSubmit(ev) {
          this.batchError = '';
          var validLetters = ['A','B','C','D','E','F','G','H','I','J'];
          for (var i = 0; i < this.batchQuestions.length; i++) {
            var q = this.batchQuestions[i];
            var filled = (q.choices || []).filter(function(c) { return (c.text || '').trim() !== ''; });
            if (filled.length < 2) {
              this.batchError = 'Each question needs at least 2 choices filled (Question ' + (i + 1) + ').';
              return;
            }
            if (!q.correct_answer || !validLetters.includes(q.correct_answer)) {
              this.batchError = 'Please select the correct answer for every question (Question ' + (i + 1) + ').';
              return;
            }
            var filledLetters = filled.map(function(c) { return c.letter; });
            if (filledLetters.indexOf(q.correct_answer) === -1) {
              this.batchError = 'Correct answer must be one of the filled choices (Question ' + (i + 1) + ').';
              return;
            }
          }
          ev.target.submit();
        },
        editFromServer: <?php echo !empty($edit) ? json_encode(array_merge(
          ['id' => (int)$edit['question_id'], 'question_text' => $edit['question_text'] ?? '', 'correct_answer' => $edit['correct_answer'] ?? 'A'],
          array_reduce($choiceCols, function ($acc, $col) use ($edit) { $acc[$col] = $edit[$col] ?? ''; return $acc; }, [])
        )) : 'null'; ?>,
        addEditChoice() {
          if (this.editChoices.length >= 10) return;
          var nextLetter = String.fromCharCode(65 + this.editChoices.length);
          this.editChoices.push({ letter: nextLetter, text: '' });
        },
        removeEditChoice(ci) {
          if (this.editChoices.length <= 2) return;
          this.editChoices.splice(ci, 1);
          for (var i = 0; i < this.editChoices.length; i++) this.editChoices[i].letter = String.fromCharCode(65 + i);
          if (!this.editChoices.some(function(c) { return c.letter === this.correct_answer; }.bind(this))) this.correct_answer = this.editChoices[0] ? this.editChoices[0].letter : 'A';
        },
        openNewQuestion() {
          this.isEdit = false;
          this.question_id = 0;
          this.question_text = '';
          this.editChoices = [ {letter:'A',text:''}, {letter:'B',text:''}, {letter:'C',text:''}, {letter:'D',text:''} ];
          this.correct_answer = '';
          this.questionModalOpen = true;
        },
        openEditFromEl(el) {
          const d = el.dataset;
          this.isEdit = true;
          this.question_id = d.id || 0;
          this.question_text = d.text || '';
          this.editChoices = [];
          for (var i = 0; i < 10; i++) {
            var letter = String.fromCharCode(65 + i);
            var key = letter.toLowerCase();
            var text = (d[key] || '').trim();
            if (i < 4 || text !== '') this.editChoices.push({ letter: letter, text: text });
          }
          if (this.editChoices.length < 2) this.editChoices = [ {letter:'A',text:''}, {letter:'B',text:''} ];
          this.correct_answer = (d.correct && 'ABCDEFGHIJ'.indexOf(d.correct) >= 0) ? d.correct : '';
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
            this.editChoices = [];
            var letters = ['A','B','C','D','E','F','G','H','I','J'];
            for (var i = 0; i < letters.length; i++) {
              var col = 'choice_' + letters[i].toLowerCase();
              var text = (this.editFromServer[col] || '').trim();
              if (i < 4 || text !== '') this.editChoices.push({ letter: letters[i], text: text });
            }
            if (this.editChoices.length < 2) this.editChoices = [ {letter:'A',text:''}, {letter:'B',text:''} ];
            this.correct_answer = this.editFromServer.correct_answer || '';
            this.questionModalOpen = true;
          }
        }
      };
    }
  </script>
</main>
</body>
</html>
