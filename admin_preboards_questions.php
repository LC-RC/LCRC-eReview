<?php
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/preboards_migrate.php';

$csrf = generateCSRFToken();
$setId = sanitizeInt($_GET['preboards_set_id'] ?? 0);
$subjectId = sanitizeInt($_GET['preboards_subject_id'] ?? 0);
if ($setId <= 0 || $subjectId <= 0) {
    header('Location: admin_preboards_subjects.php');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT s.*, p.subject_name FROM preboards_sets s JOIN preboards_subjects p ON p.preboards_subject_id=s.preboards_subject_id WHERE s.preboards_set_id=? AND s.preboards_subject_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ii', $setId, $subjectId);
mysqli_stmt_execute($stmt);
$setRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$setRow) {
    header('Location: admin_preboards_subjects.php');
    exit;
}

$allChoiceCols = ['choice_a','choice_b','choice_c','choice_d','choice_e','choice_f','choice_g','choice_h','choice_i','choice_j'];
$validCorrectLetters = ['A','B','C','D','E','F','G','H','I','J'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_preboards_questions.php?preboards_set_id='.$setId.'&preboards_subject_id='.$subjectId);
        exit;
    }
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $qid = sanitizeInt($_POST['preboards_question_id'] ?? 0);
        if ($qid > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM preboards_questions WHERE preboards_question_id=? AND preboards_set_id=?");
            mysqli_stmt_bind_param($stmt, 'ii', $qid, $setId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Question deleted.';
        }
        header('Location: admin_preboards_questions.php?preboards_set_id='.$setId.'&preboards_subject_id='.$subjectId);
        exit;
    }

    if ($action === 'add_batch') {
        $rawQuestions = $_POST['questions'] ?? [];
        if (!is_array($rawQuestions)) $rawQuestions = [];
        $batch = [];
        foreach ($rawQuestions as $q) {
            if (!is_array($q)) continue;
            $questionText = trim($q['text'] ?? '');
            if ($questionText === '') continue;
            $choices = [];
            foreach ($allChoiceCols as $col) {
                $choices[$col] = trim($q[$col] ?? '');
            }
            $filled = array_filter($choices);
            if (count($filled) < 2) continue;
            $correct = trim($q['correct_answer'] ?? '');
            if (!in_array($correct, $validCorrectLetters, true)) {
                $_SESSION['error'] = 'Please select the correct answer for every question.';
                header('Location: admin_preboards_questions.php?preboards_set_id='.$setId.'&preboards_subject_id='.$subjectId);
                exit;
            }
            $correctCol = 'choice_' . strtolower($correct);
            if (!isset($choices[$correctCol]) || $choices[$correctCol] === '') {
                $_SESSION['error'] = 'Correct answer must be one of the filled choices.';
                header('Location: admin_preboards_questions.php?preboards_set_id='.$setId.'&preboards_subject_id='.$subjectId);
                exit;
            }
            $batch[] = [
                'question_text' => $questionText,
                'correct_answer' => $correct,
                'explanation' => trim($q['explanation'] ?? ''),
                'choices' => $choices,
            ];
        }
        if (count($batch) === 0) {
            $_SESSION['error'] = 'Add at least one question with at least 2 choices and a correct answer selected.';
        } else {
            mysqli_begin_transaction($conn);
            try {
                $cols = 'preboards_set_id,question_text,' . implode(',', $allChoiceCols) . ',correct_answer,explanation';
                $placeholders = '?,' . implode(',', array_fill(0, count($allChoiceCols) + 3, '?'));
                $sql = "INSERT INTO preboards_questions ($cols) VALUES ($placeholders)";
                $stmt = mysqli_prepare($conn, $sql);
                $types = 'is' . str_repeat('s', count($allChoiceCols)) . 'ss';
                foreach ($batch as $row) {
                    $a = $setId;
                    $b = $row['question_text'];
                    $ch = [];
                    foreach ($allChoiceCols as $c) { $ch[] = $row['choices'][$c] ?? ''; }
                    $cor = $row['correct_answer'];
                    $exp = $row['explanation'];
                    // bind_param requires references; also PHP forbids args after unpacking
                    $bindArgs = [$stmt, $types, &$a, &$b];
                    foreach ($ch as $i => $val) {
                        $ch[$i] = $val; // ensure variable slot exists
                        $bindArgs[] = &$ch[$i];
                    }
                    $bindArgs[] = &$cor;
                    $bindArgs[] = &$exp;
                    call_user_func_array('mysqli_stmt_bind_param', $bindArgs);
                    mysqli_stmt_execute($stmt);
                }
                mysqli_stmt_close($stmt);
                mysqli_commit($conn);
                $_SESSION['message'] = count($batch) . ' question(s) added.';
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $_SESSION['error'] = 'Could not save questions.';
            }
        }
        header('Location: admin_preboards_questions.php?preboards_set_id='.$setId.'&preboards_subject_id='.$subjectId);
        exit;
    }

    $qid = sanitizeInt($_POST['preboards_question_id'] ?? 0);
    $questionText = trim($_POST['question_text'] ?? '');
    $choiceVals = [];
    foreach ($allChoiceCols as $col) {
        $choiceVals[$col] = trim($_POST[$col] ?? '');
    }
    $filled = array_filter($choiceVals);
    if (count($filled) < 2) {
        $_SESSION['error'] = 'At least 2 choices required.';
        header('Location: admin_preboards_questions.php?preboards_set_id='.$setId.'&preboards_subject_id='.$subjectId.'&edit='.$qid);
        exit;
    }
    $correctAnswer = trim($_POST['correct_answer'] ?? '');
    if (!in_array($correctAnswer, $validCorrectLetters, true)) {
        $_SESSION['error'] = 'Please select the correct answer.';
        header('Location: admin_preboards_questions.php?preboards_set_id='.$setId.'&preboards_subject_id='.$subjectId.'&edit='.$qid);
        exit;
    }
    $explanation = trim($_POST['explanation'] ?? '');

    if ($qid > 0) {
        $setParts = ['question_text=?'];
        foreach ($allChoiceCols as $c) { $setParts[] = $c . '=?'; }
        $setParts[] = 'correct_answer=?';
        $setParts[] = 'explanation=?';
        $stmt = mysqli_prepare($conn, "UPDATE preboards_questions SET " . implode(', ', $setParts) . " WHERE preboards_question_id=? AND preboards_set_id=?");
        $params = [$questionText];
        foreach ($allChoiceCols as $c) { $params[] = $choiceVals[$c]; }
        $params[] = $correctAnswer;
        $params[] = $explanation;
        $params[] = $qid;
        $params[] = $setId;
        $types = 's' . str_repeat('s', count($allChoiceCols)) . 'ssii';
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Question updated.';
    } else {
        $cols = array_merge(['preboards_set_id', 'question_text'], $allChoiceCols, ['correct_answer', 'explanation']);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = mysqli_prepare($conn, "INSERT INTO preboards_questions (" . implode(', ', $cols) . ") VALUES ($placeholders)");
        $params = [$setId, $questionText];
        foreach ($allChoiceCols as $c) { $params[] = $choiceVals[$c]; }
        $params[] = $correctAnswer;
        $params[] = $explanation;
        $types = 'is' . str_repeat('s', count($allChoiceCols)) . 'ss';
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Question added.';
    }
    header('Location: admin_preboards_questions.php?preboards_set_id='.$setId.'&preboards_subject_id='.$subjectId);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = sanitizeInt($_GET['edit']);
    if ($eid > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM preboards_questions WHERE preboards_question_id=? AND preboards_set_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $eid, $setId);
        mysqli_stmt_execute($stmt);
        $edit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
}

$searchQ = trim($_GET['q'] ?? '');
$qParts = ['preboards_set_id=?'];
$qTypes = 'i';
$qVals = [$setId];
if ($searchQ !== '') {
    $qParts[] = 'question_text LIKE ?';
    $qTypes .= 's';
    $qVals[] = '%' . $searchQ . '%';
}
$qSql = 'SELECT * FROM preboards_questions WHERE ' . implode(' AND ', $qParts) . ' ORDER BY sort_order ASC, preboards_question_id ASC';
$stmt = mysqli_prepare($conn, $qSql);
mysqli_stmt_bind_param($stmt, $qTypes, ...$qVals);
mysqli_stmt_execute($stmt);
$questions = mysqli_stmt_get_result($stmt);

$pageTitle = 'Preboards Questions - Set ' . $setRow['set_label'];
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard.php'],
    ['Preboards', 'admin_preboards_subjects.php'],
    [($setRow['subject_name'] ?? 'Subject'), 'admin_preboards_sets.php?preboards_subject_id=' . (int)$subjectId],
    ['Set ' . ($setRow['set_label'] ?? ''), 'admin_preboards_sets.php?preboards_subject_id=' . (int)$subjectId],
    ['Questions'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <link rel="stylesheet" href="assets/css/admin-quiz-ui.css?v=3">
</head>
<body class="font-sans antialiased admin-app admin-quiz-questions-page" x-data="preboardsQuestionsApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <div class="quiz-admin-hero rounded-xl px-6 py-5 mb-5">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-gray-100 m-0 flex flex-wrap items-center gap-2">
      <span class="quiz-admin-hero-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span>
      <span>Preboard Questions — Set <?php echo h($setRow['set_label'] ?? ''); ?></span>
      <span class="text-gray-500 font-medium text-lg">(<?php echo h($setRow['subject_name'] ?? ''); ?>)</span>
    </h1>
    <p class="text-gray-400 mt-2 mb-0 max-w-3xl text-sm sm:text-base"><?php echo h($setRow['subject_name'] ?? ''); ?> — Add and manage questions for this set. Students get one attempt per set.</p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-4 mb-5 quiz-admin-toolbar">
    <div>
      <a href="admin_preboards_sets.php?preboards_subject_id=<?php echo (int)$subjectId; ?>" class="admin-quiz-btn admin-quiz-btn-outline"><i class="bi bi-arrow-left-circle"></i> Back to sets</a>
    </div>
    <div class="flex flex-wrap gap-2">
      <button type="button" @click="batchOpen = !batchOpen; batchError = ''" class="admin-quiz-btn admin-quiz-btn-primary"><i class="bi bi-collection-plus"></i> Add multiple questions</button>
    </div>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--success admin-quiz-alert">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--error admin-quiz-alert">
      <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <form method="get" action="admin_preboards_questions.php" class="quiz-admin-filter quiz-admin-table-shell rounded-xl px-4 py-3 mb-4 flex flex-wrap items-end gap-3">
    <input type="hidden" name="preboards_set_id" value="<?php echo (int)$setId; ?>">
    <input type="hidden" name="preboards_subject_id" value="<?php echo (int)$subjectId; ?>">
    <div class="flex-1 min-w-[220px]">
      <label for="pb-search-q" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Search questions</label>
      <input type="search" id="pb-search-q" name="q" value="<?php echo h($searchQ); ?>" placeholder="Filter by question text…" class="input-custom w-full" autocomplete="off">
    </div>
    <div class="flex flex-wrap gap-2">
      <button type="submit" class="quiz-admin-filter-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-search"></i> Apply</button>
      <?php if ($searchQ !== ''): ?>
        <a href="admin_preboards_questions.php?preboards_set_id=<?php echo (int)$setId; ?>&preboards_subject_id=<?php echo (int)$subjectId; ?>" class="quiz-admin-filter-clear px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2">Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Batch add -->
  <div class="mb-6" x-show="batchOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
    <div class="admin-quiz-card quiz-admin-batch-card">
      <div class="admin-quiz-card-header admin-quiz-card-header-accent quiz-admin-card-head">
        <span class="font-semibold flex items-center gap-2"><i class="bi bi-stack"></i> Add multiple questions</span>
        <button type="button" @click="batchOpen = false" class="admin-quiz-close-btn" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_preboards_questions.php?preboards_set_id=<?php echo (int)$setId; ?>&preboards_subject_id=<?php echo (int)$subjectId; ?>" class="p-6" @submit.prevent="validateBatchSubmit($event)">
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
                  <textarea :name="'questions[' + index + '][text]'" x-model="q.text" rows="2" required class="input-custom w-full admin-quiz-input js-preboard-richtext" placeholder="Enter the question..."></textarea>
                  <p class="text-xs text-gray-500 mt-1">You can use basic HTML, including table tags (<code>&lt;table&gt;</code>, <code>&lt;tr&gt;</code>, <code>&lt;td&gt;</code>).</p>
                </div>
                <div class="space-y-2">
                  <span class="admin-quiz-label">Choices (min 2, max 10)</span>
                  <template x-for="(ch, ci) in q.choices" :key="ci">
                    <div class="flex flex-wrap gap-2 items-center">
                      <span class="admin-quiz-choice-letter" x-text="ch.letter"></span>
                      <input type="text" :name="'questions[' + index + '][choice_' + ch.letter.toLowerCase() + ']'" x-model="ch.text" class="input-custom flex-1 min-w-0 admin-quiz-input" :placeholder="'Choice ' + ch.letter">
                      <label class="admin-quiz-radio-wrap">
                        <input type="radio" :name="'questions[' + index + '][correct_answer]'" :value="ch.letter" x-model="q.correct_answer">
                        <span>Correct</span>
                      </label>
                      <button type="button" x-show="q.choices.length > 2" @click="removeChoice(index, ci)" class="admin-quiz-link-danger text-sm"><i class="bi bi-dash-circle"></i></button>
                    </div>
                  </template>
                  <div class="pt-1" x-show="q.choices.length < 10">
                    <button type="button" @click="addChoice(index)" class="admin-quiz-btn admin-quiz-btn-sm admin-quiz-btn-outline"><i class="bi bi-plus"></i> Add choice</button>
                  </div>
                </div>
                <div>
                  <label class="admin-quiz-label">Explanation (optional)</label>
                  <textarea :name="'questions[' + index + '][explanation]'" x-model="q.explanation" rows="1" class="input-custom w-full admin-quiz-input text-sm js-preboard-richtext" placeholder="Why the correct answer is right..."></textarea>
                </div>
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

  <div class="admin-quiz-card quiz-admin-questions-card">
    <div class="admin-quiz-card-header quiz-admin-card-head">
      <span class="font-semibold flex items-center gap-2"><i class="bi bi-list-ol"></i> Questions list</span>
      <span class="text-gray-500 text-sm">Set <?php echo h($setRow['set_label']); ?></span>
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
          <?php $hasAny = false;
          while ($qq = mysqli_fetch_assoc($questions)):
              $hasAny = true;
              $rawQt = (string)($qq['question_text'] ?? '');
              $plainQt = trim(preg_replace('/\s+/u', ' ', strip_tags($rawQt)));
              if ($plainQt === '') {
                  $plainQt = trim(preg_replace('/\s+/u', ' ', $rawQt));
              }
              $qPreview = mb_substr($plainQt, 0, 120) . (mb_strlen($plainQt) > 120 ? '…' : '');
          ?>
            <tr class="quiz-admin-q-row">
              <td class="font-medium quiz-admin-q-preview"><?php echo $plainQt !== '' ? h($qPreview) : '<span class="quiz-admin-q-empty">No text preview</span>'; ?></td>
              <td><span class="admin-quiz-badge admin-quiz-badge-success"><?php echo h($qq['correct_answer']); ?></span></td>
              <td>
                <div class="flex flex-wrap gap-2">
                  <button type="button"
                    data-id="<?php echo (int)$qq['preboards_question_id']; ?>"
                    data-text="<?php echo h($qq['question_text'] ?? ''); ?>"
                    <?php foreach ($allChoiceCols as $col): $letter = strtolower(substr($col, -1)); ?>
                    data-<?php echo $letter; ?>="<?php echo h($qq[$col] ?? ''); ?>"
                    <?php endforeach; ?>
                    data-correct="<?php echo h($qq['correct_answer'] ?? 'A'); ?>"
                    data-explanation="<?php echo h($qq['explanation'] ?? ''); ?>"
                    @click="openEditFromEl($el)"
                    class="admin-quiz-btn admin-quiz-btn-sm admin-quiz-btn-outline"><i class="bi bi-pencil"></i> Edit</button>
                  <button type="button" data-id="<?php echo (int)$qq['preboards_question_id']; ?>" data-text="<?php echo h($qPreview); ?>" @click="openDeleteQuestion($el.dataset.id, $el.dataset.text || '')" class="admin-quiz-btn admin-quiz-btn-sm admin-quiz-btn-danger"><i class="bi bi-trash"></i> Delete</button>
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

  <!-- Edit modal -->
  <div x-show="questionModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="questionModalOpen = false">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="questionModalOpen = false"></div>
    <div class="relative admin-quiz-modal quiz-modal-panel" @click.stop>
      <div class="admin-quiz-modal-header quiz-modal-panel__head">
        <h2 class="m-0 text-lg font-bold text-gray-100">Edit Question</h2>
        <button type="button" @click="questionModalOpen = false" class="admin-quiz-close-btn quiz-modal-close" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_preboards_questions.php?preboards_set_id=<?php echo (int)$setId; ?>&preboards_subject_id=<?php echo (int)$subjectId; ?>" class="p-6 quiz-modal-form">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="preboards_question_id" :value="question_id">
        <div class="space-y-4">
          <div>
            <label class="admin-quiz-label">Question</label>
            <textarea id="edit-preboard-question-text" name="question_text" x-model="question_text" rows="3" required class="input-custom w-full admin-quiz-input js-preboard-richtext"></textarea>
            <p class="text-xs text-gray-500 mt-1">You can use basic HTML, including table tags (<code>&lt;table&gt;</code>, <code>&lt;tr&gt;</code>, <code>&lt;td&gt;</code>).</p>
          </div>
          <div class="space-y-2">
            <div>
              <label class="admin-quiz-label mb-0">Choices (min 2, max 10)</label>
            </div>
            <template x-for="(ch, ci) in editChoices" :key="ci">
              <div class="flex flex-wrap gap-2 items-center">
                <span class="admin-quiz-choice-letter" x-text="ch.letter"></span>
                <input type="text" :name="'choice_' + ch.letter.toLowerCase()" x-model="ch.text" class="input-custom flex-1 min-w-0 admin-quiz-input">
                <label class="admin-quiz-radio-wrap">
                  <input type="radio" name="correct_answer" :value="ch.letter" x-model="correct_answer">
                  <span>Correct</span>
                </label>
                <button type="button" x-show="editChoices.length > 2" @click="removeEditChoice(ci)" class="admin-quiz-link-danger text-sm"><i class="bi bi-dash-circle"></i></button>
              </div>
            </template>
            <div class="pt-1" x-show="editChoices.length < 10">
              <button type="button" @click="addEditChoice()" class="admin-quiz-btn admin-quiz-btn-sm admin-quiz-btn-outline"><i class="bi bi-plus"></i> Add choice</button>
            </div>
          </div>
          <div>
            <label class="admin-quiz-label">Explanation (optional)</label>
            <textarea id="edit-preboard-explanation" name="explanation" x-model="explanation" rows="2" class="input-custom w-full admin-quiz-input text-sm js-preboard-richtext"></textarea>
          </div>
        </div>
        <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-gray-100">
          <button type="button" @click="questionModalOpen = false" class="admin-quiz-btn admin-quiz-btn-outline">Cancel</button>
          <button type="submit" class="admin-quiz-btn admin-quiz-btn-primary"><i class="bi bi-save"></i> Update</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete question modal -->
  <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="deleteModalOpen = false">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="deleteModalOpen = false"></div>
    <div class="relative admin-quiz-modal admin-quiz-modal-sm quiz-modal-panel" @click.stop>
      <div class="admin-quiz-modal-header quiz-modal-panel__head">
        <h2 class="m-0 text-lg font-bold text-gray-100 flex items-center gap-2"><i class="bi bi-trash text-red-400"></i> Delete Question</h2>
        <button type="button" @click="deleteModalOpen = false" class="admin-quiz-close-btn quiz-modal-close" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_preboards_questions.php?preboards_set_id=<?php echo (int)$setId; ?>&preboards_subject_id=<?php echo (int)$subjectId; ?>" class="p-6">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="preboards_question_id" :value="delete_question_id">
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

  <script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.6/tinymce.min.js" referrerpolicy="origin"></script>
  <script>
    function initPreboardRichEditors(scopeEl) {
      if (!window.tinymce) return;
      var root = scopeEl || document;
      var nodes = root.querySelectorAll ? root.querySelectorAll('textarea.js-preboard-richtext') : [];
      nodes.forEach(function (el) {
        if (!el.id) el.id = 'preboard-rich-' + Math.random().toString(36).slice(2, 10);
        if (window.tinymce.get(el.id)) return;
        tinymce.init({
          selector: '#' + el.id,
          menubar: false,
          height: 220,
          branding: false,
          plugins: 'table lists link',
          toolbar: 'undo redo | bold italic underline | bullist numlist | table | removeformat',
          valid_elements: 'p,br,strong/b,em/i,u,sub,sup,ul,ol,li,table,thead,tbody,tfoot,tr,th[colspan|rowspan|scope],td[colspan|rowspan]',
          forced_root_block: 'p',
          setup: function (editor) {
            editor.on('change input undo redo keyup', function () { editor.save(); });
          }
        });
      });
    }

    function refreshPreboardRichEditors() {
      window.setTimeout(function () { initPreboardRichEditors(document); }, 0);
    }

    document.addEventListener('DOMContentLoaded', function () {
      initPreboardRichEditors(document);
      var observer = new MutationObserver(function () { refreshPreboardRichEditors(); });
      observer.observe(document.body, { childList: true, subtree: true });
      document.querySelectorAll('form').forEach(function (formEl) {
        formEl.addEventListener('submit', function () {
          if (window.tinymce) tinymce.triggerSave();
        });
      });
    });

    function newBatchQuestion() {
      return {
        text: '',
        choices: [
          { letter: 'A', text: '' },
          { letter: 'B', text: '' },
          { letter: 'C', text: '' },
          { letter: 'D', text: '' }
        ],
        correct_answer: '',
        explanation: ''
      };
    }
    function preboardsQuestionsApp() {
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
        explanation: '',
        delete_question_id: 0,
        delete_question_text: '',
        addBatchQuestion() { this.batchQuestions.push(newBatchQuestion()); refreshPreboardRichEditors(); },
        removeBatchQuestion(index) {
          if (this.batchQuestions.length <= 1) return;
          this.batchQuestions.splice(index, 1);
        },
        addChoice(index) {
          var q = this.batchQuestions[index];
          if (!q.choices || q.choices.length >= 10) return;
          q.choices.push({ letter: String.fromCharCode(65 + q.choices.length), text: '' });
        },
        removeChoice(index, choiceIndex) {
          var q = this.batchQuestions[index];
          if (!q.choices || q.choices.length <= 2) return;
          q.choices.splice(choiceIndex, 1);
          for (var i = 0; i < q.choices.length; i++) q.choices[i].letter = String.fromCharCode(65 + i);
          if (!q.choices.some(function(c) { return c.letter === q.correct_answer; })) q.correct_answer = '';
        },
        validateBatchSubmit(ev) {
          if (window.tinymce) tinymce.triggerSave();
          this.batchError = '';
          var validLetters = ['A','B','C','D','E','F','G','H','I','J'];
          for (var i = 0; i < this.batchQuestions.length; i++) {
            var q = this.batchQuestions[i];
            var filled = (q.choices || []).filter(function(c) { return (c.text || '').trim() !== ''; });
            if (filled.length < 2) {
              this.batchError = 'Each question needs at least 2 choices (Question ' + (i + 1) + ').';
              return;
            }
            if (!q.correct_answer || !validLetters.includes(q.correct_answer)) {
              this.batchError = 'Select the correct answer for every question (Question ' + (i + 1) + ').';
              return;
            }
            if (filled.map(function(c) { return c.letter; }).indexOf(q.correct_answer) === -1) {
              this.batchError = 'Correct answer must be one of the filled choices (Question ' + (i + 1) + ').';
              return;
            }
          }
          ev.target.submit();
        },
        editFromServer: <?php echo !empty($edit) ? json_encode([
          'id' => (int)$edit['preboards_question_id'],
          'question_text' => $edit['question_text'] ?? '',
          'correct_answer' => $edit['correct_answer'] ?? 'A',
          'explanation' => $edit['explanation'] ?? '',
          'choice_a' => $edit['choice_a'] ?? '',
          'choice_b' => $edit['choice_b'] ?? '',
          'choice_c' => $edit['choice_c'] ?? '',
          'choice_d' => $edit['choice_d'] ?? '',
          'choice_e' => $edit['choice_e'] ?? '',
          'choice_f' => $edit['choice_f'] ?? '',
          'choice_g' => $edit['choice_g'] ?? '',
          'choice_h' => $edit['choice_h'] ?? '',
          'choice_i' => $edit['choice_i'] ?? '',
          'choice_j' => $edit['choice_j'] ?? ''
        ]) : 'null'; ?>,
        addEditChoice() {
          if (this.editChoices.length >= 10) return;
          this.editChoices.push({ letter: String.fromCharCode(65 + this.editChoices.length), text: '' });
        },
        removeEditChoice(ci) {
          if (this.editChoices.length <= 2) return;
          this.editChoices.splice(ci, 1);
          for (var i = 0; i < this.editChoices.length; i++) this.editChoices[i].letter = String.fromCharCode(65 + i);
          if (!this.editChoices.some(function(c) { return c.letter === this.correct_answer; }.bind(this))) this.correct_answer = this.editChoices[0] ? this.editChoices[0].letter : 'A';
        },
        openEditFromEl(el) {
          var d = el.dataset;
          this.isEdit = true;
          this.question_id = d.id || 0;
          this.question_text = d.text || '';
          this.editChoices = [];
          for (var i = 0; i < 10; i++) {
            var letter = String.fromCharCode(65 + i);
            var text = (d[letter.toLowerCase()] || '').trim();
            if (i < 4 || text !== '') this.editChoices.push({ letter: letter, text: text });
          }
          if (this.editChoices.length < 2) this.editChoices = [ {letter:'A',text:''}, {letter:'B',text:''} ];
          this.correct_answer = (d.correct && 'ABCDEFGHIJ'.indexOf(d.correct) >= 0) ? d.correct : '';
          this.explanation = d.explanation || '';
          this.questionModalOpen = true;
          this.$nextTick(function () {
            refreshPreboardRichEditors();
            if (window.tinymce) {
              var qEd = tinymce.get('edit-preboard-question-text');
              if (qEd) qEd.setContent(this.question_text || '');
              var eEd = tinymce.get('edit-preboard-explanation');
              if (eEd) eEd.setContent(this.explanation || '');
            }
          }.bind(this));
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
            for (var i = 0; i < 10; i++) {
              var letter = String.fromCharCode(65 + i);
              var col = 'choice_' + letter.toLowerCase();
              var text = (this.editFromServer[col] || '').trim();
              if (i < 4 || text !== '') this.editChoices.push({ letter: letter, text: text });
            }
            if (this.editChoices.length < 2) this.editChoices = [ {letter:'A',text:''}, {letter:'B',text:''} ];
            this.correct_answer = this.editFromServer.correct_answer || '';
            this.explanation = this.editFromServer.explanation || '';
            this.questionModalOpen = true;
            this.$nextTick(function () {
              refreshPreboardRichEditors();
              if (window.tinymce) {
                var qEd = tinymce.get('edit-preboard-question-text');
                if (qEd) qEd.setContent(this.question_text || '');
                var eEd = tinymce.get('edit-preboard-explanation');
                if (eEd) eEd.setContent(this.explanation || '');
              }
            }.bind(this));
          }
        }
      };
    }
  </script>
</div>
</main>
</body>
</html>
