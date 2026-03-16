<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/quiz_helpers.php';
requireRole('admin');

$csrf = generateCSRFToken();
$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectId <= 0) { header('Location: admin_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE subject_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$subRes = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($subRes);
mysqli_stmt_close($stmt);
if (!$subject) { header('Location: admin_subjects.php'); exit; }

// Ensure time_limit_minutes and time_limit_seconds exist (auto-migrate)
$quizCols = [];
$qc = @mysqli_query($conn, "SHOW COLUMNS FROM quizzes");
if ($qc) { while ($row = mysqli_fetch_assoc($qc)) $quizCols[] = $row['Field']; }
if (!in_array('time_limit_minutes', $quizCols, true)) {
    @mysqli_query($conn, "ALTER TABLE `quizzes` ADD COLUMN `time_limit_minutes` int(11) NOT NULL DEFAULT 30 AFTER `title`");
}
if (!in_array('time_limit_seconds', $quizCols, true)) {
    @mysqli_query($conn, "ALTER TABLE `quizzes` ADD COLUMN `time_limit_seconds` int(11) NOT NULL DEFAULT 1800 AFTER `time_limit_minutes`");
    @mysqli_query($conn, "UPDATE `quizzes` SET `time_limit_seconds` = `time_limit_minutes` * 60 WHERE `time_limit_seconds` = 0");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_quizzes.php?subject_id='.$subjectId);
        exit;
    }
    $action = $_POST['action'] ?? 'save';
    if ($action === 'delete') {
        $quizId = sanitizeInt($_POST['quiz_id'] ?? 0);
        if ($quizId > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM quizzes WHERE quiz_id=? AND subject_id=?");
            mysqli_stmt_bind_param($stmt, 'ii', $quizId, $subjectId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Quiz deleted.';
        }
        header('Location: admin_quizzes.php?subject_id='.$subjectId);
        exit;
    }
    $quizId = sanitizeInt($_POST['quiz_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $timeLimitSeconds = (int)($_POST['time_limit_seconds'] ?? 1800);
    if ($timeLimitSeconds < 1) $timeLimitSeconds = 1;
    if ($timeLimitSeconds > 86400) $timeLimitSeconds = 86400; // max 24 hours
    $timeLimitMinutes = (int)ceil($timeLimitSeconds / 60); // keep for backward compat
    if ($title === '') {
        $_SESSION['error'] = 'Quiz title is required.';
        header('Location: admin_quizzes.php?subject_id='.$subjectId);
        exit;
    }
    $quizType = 'pre-test'; // All quizzes go to Quizzers; Test Bank uses mock only
    if ($quizId > 0) {
        $stmt = mysqli_prepare($conn, "UPDATE quizzes SET title=?, quiz_type=?, time_limit_minutes=?, time_limit_seconds=? WHERE quiz_id=? AND subject_id=?");
        mysqli_stmt_bind_param($stmt, 'ssiiii', $title, $quizType, $timeLimitMinutes, $timeLimitSeconds, $quizId, $subjectId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Quiz updated.';
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO quizzes (subject_id, title, quiz_type, time_limit_minutes, time_limit_seconds) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'issii', $subjectId, $title, $quizType, $timeLimitMinutes, $timeLimitSeconds);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Quiz created.';
    }
    header('Location: admin_quizzes.php?subject_id='.$subjectId);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = sanitizeInt($_GET['edit']);
    if ($eid > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM quizzes WHERE quiz_id=? AND subject_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $eid, $subjectId);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $edit = mysqli_fetch_assoc($r);
        mysqli_stmt_close($stmt);
    }
}

$stmt = mysqli_prepare($conn, "SELECT q.*, (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.quiz_id) AS questions_cnt FROM quizzes q WHERE q.subject_id=? ORDER BY q.quiz_id DESC");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$quizzes = mysqli_stmt_get_result($stmt);

$pageTitle = 'Quizzes - ' . $subject['subject_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased" x-data="quizzesApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-question-circle"></i> Quizzes - <?php echo h($subject['subject_name']); ?>
    </h1>
    <p class="text-gray-500 mt-1">Create quizzes, then open Questions to build the question bank.</p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-4 mb-5">
    <div></div>
    <div class="flex gap-2">
      <a href="admin_subjects.php" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Back to Subjects</a>
      <button type="button" @click="openNewQuiz()" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> New Quiz</button>
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
      <span class="font-semibold text-gray-800">Quizzes</span>
      <span class="text-gray-500 text-sm">Subject: <?php echo h($subject['subject_name']); ?></span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-left">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-5 py-3 font-semibold text-gray-700">Quiz</th>
            <th class="px-5 py-3 font-semibold text-gray-700 w-28">Time limit</th>
            <th class="px-5 py-3 font-semibold text-gray-700">Questions</th>
            <th class="px-5 py-3 font-semibold text-gray-700 w-[340px]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $hasAny = false;
            while ($qz = mysqli_fetch_assoc($quizzes)): $hasAny = true;
            $timeSecs = getQuizTimeLimitSeconds($qz);
          ?>
            <tr class="border-b border-gray-100 hover:bg-gray-50/50">
              <td class="px-5 py-3 font-semibold text-gray-800"><?php echo h($qz['title']); ?></td>
              <td class="px-5 py-3 text-gray-600"><?php echo formatTimeLimitSeconds($timeSecs); ?></td>
              <td class="px-5 py-3"><span class="px-2.5 py-1 rounded-full text-sm bg-gray-100 text-gray-700"><?php echo (int)($qz['questions_cnt'] ?? 0); ?></span></td>
              <td class="px-5 py-3">
                <div class="flex flex-wrap gap-2">
                  <a href="admin_quiz_questions.php?quiz_id=<?php echo (int)$qz['quiz_id']; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-list-check"></i> Questions</a>
                  <button type="button" data-id="<?php echo (int)$qz['quiz_id']; ?>" data-title="<?php echo h($qz['title'] ?? ''); ?>" data-time-secs="<?php echo $timeSecs; ?>" @click="openEditQuiz($el.dataset.id, $el.dataset.title || '', parseInt($el.dataset.timeSecs) || 1800)" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition"><i class="bi bi-pencil"></i> Edit</button>
                  <button type="button" data-id="<?php echo (int)$qz['quiz_id']; ?>" data-title="<?php echo h($qz['title'] ?? ''); ?>" @click="openDeleteQuiz($el.dataset.id, $el.dataset.title || '')" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition"><i class="bi bi-trash"></i> Delete</button>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          <?php if (!$hasAny): ?>
            <tr>
              <td colspan="4" class="px-5 py-12 text-center text-gray-500">
                <i class="bi bi-inbox text-4xl block mb-2"></i>
                <div class="font-semibold">No quizzes yet</div>
                <p class="text-sm mt-1">Create your first quiz, then add questions.</p>
                <button type="button" @click="openNewQuiz()" class="mt-3 px-4 py-2 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> New Quiz</button>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php mysqli_stmt_close($stmt); ?>

  <!-- Create/Edit Quiz Modal -->
  <div x-show="quizModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="quizModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="quizModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal max-w-lg w-full max-h-[90vh] overflow-y-auto" @click.stop>
      <div class="p-5 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-xl font-bold text-gray-800 m-0" x-text="isEdit ? 'Edit Quiz' : 'New Quiz'"></h2>
        <button type="button" @click="quizModalOpen = false" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_quizzes.php?subject_id=<?php echo (int)$subjectId; ?>" class="p-5">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="quiz_id" :value="quiz_id">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Quiz Title</label>
            <input type="text" name="title" x-model="title" required placeholder="e.g., Chapter 1 Quiz" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Time limit (how long to take the quiz)</label>
            <div class="flex flex-wrap items-center gap-2">
              <input type="number" x-model.number="time_limit_hours" min="0" max="24" class="input-custom w-20" placeholder="0">
              <span class="text-gray-600">hour(s)</span>
              <input type="number" x-model.number="time_limit_mins" min="0" max="59" class="input-custom w-20" placeholder="30">
              <span class="text-gray-600">min(s)</span>
              <input type="number" x-model.number="time_limit_secs" min="0" max="59" class="input-custom w-20" placeholder="0">
              <span class="text-gray-600">sec(s)</span>
            </div>
            <input type="hidden" name="time_limit_seconds" :value="Math.max(1, time_limit_hours * 3600 + time_limit_mins * 60 + time_limit_secs)">
            <p class="text-xs text-gray-500 mt-1">e.g. 1 hour 3 mins 2 seconds. At least 1 second; max 24 hours. Zero hours/minutes allowed.</p>
          </div>
          <p class="text-sm text-gray-500">You'll add the questions after creating the quiz.</p>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" @click="quizModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-save"></i> <span x-text="isEdit ? 'Update' : 'Create'"></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Quiz Modal -->
  <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="deleteModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="deleteModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal max-w-md w-full p-5" @click.stop>
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold text-gray-800 m-0"><i class="bi bi-trash text-red-500 mr-2"></i> Delete Quiz</h2>
        <button type="button" @click="deleteModalOpen = false" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_quizzes.php?subject_id=<?php echo (int)$subjectId; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="quiz_id" :value="delete_quiz_id">
        <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 mb-4">
          <div class="font-semibold">This will delete the quiz and its questions.</div>
          <div class="text-sm mt-1">Quiz: <span class="font-semibold" x-text="delete_quiz_title"></span></div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="deleteModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-red-600 text-white hover:bg-red-700 transition inline-flex items-center gap-2"><i class="bi bi-trash"></i> Delete</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function quizzesApp() {
      return {
        quizModalOpen: false,
        deleteModalOpen: false,
        isEdit: false,
        quiz_id: 0,
        title: '',
        time_limit_hours: 0,
        time_limit_mins: 30,
        time_limit_secs: 0,
        delete_quiz_id: 0,
        delete_quiz_title: '',
        editFromServer: <?php echo !empty($edit) ? json_encode(['id' => (int)$edit['quiz_id'], 'title' => $edit['title'] ?? '', 'time_limit_seconds' => (int)getQuizTimeLimitSeconds($edit ?? [])]) : 'null'; ?>,
        openNewQuiz() {
          this.isEdit = false;
          this.quiz_id = 0;
          this.title = '';
          this.time_limit_hours = 0;
          this.time_limit_mins = 30;
          this.time_limit_secs = 0;
          this.quizModalOpen = true;
        },
        openEditQuiz(id, title, totalSeconds) {
          this.isEdit = true;
          this.quiz_id = id;
          this.title = title || '';
          totalSeconds = (totalSeconds > 0 && totalSeconds <= 86400) ? totalSeconds : 1800;
          this.time_limit_hours = Math.floor(totalSeconds / 3600);
          this.time_limit_mins = Math.floor((totalSeconds % 3600) / 60);
          this.time_limit_secs = totalSeconds % 60;
          this.quizModalOpen = true;
        },
        openDeleteQuiz(id, title) {
          this.delete_quiz_id = id;
          this.delete_quiz_title = title || '';
          this.deleteModalOpen = true;
        },
        initEditFromServer() {
          if (this.editFromServer) this.openEditQuiz(this.editFromServer.id, this.editFromServer.title, this.editFromServer.time_limit_seconds || 1800);
        }
      };
    }
  </script>
</main>
</body>
</html>
