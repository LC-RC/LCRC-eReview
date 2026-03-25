<?php
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';

$pageTitle = 'Take exam';
$uid = getCurrentUserId();
$examId = sanitizeInt($_GET['exam_id'] ?? 0);
$reviewMode = !empty($_GET['review']);
$csrf = generateCSRFToken();

if ($examId <= 0) {
    header('Location: college_exams.php');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM college_exams WHERE exam_id=? AND is_published=1 LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $examId);
mysqli_stmt_execute($stmt);
$exam = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$exam) {
    $_SESSION['error'] = 'Exam not found.';
    header('Location: college_exams.php');
    exit;
}

$now = date('Y-m-d H:i:s');

$stmt = mysqli_prepare($conn, "SELECT * FROM college_exam_attempts WHERE user_id=? AND exam_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'ii', $uid, $examId);
mysqli_stmt_execute($stmt);
$attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

/* Auto-submit if time ran out while in progress */
if ($attempt && $attempt['status'] === 'in_progress' && !empty($attempt['expires_at'])) {
    $expTs = strtotime($attempt['expires_at']);
    if ($expTs !== false && $expTs < time()) {
        college_exam_finalize_attempt($conn, (int)$attempt['attempt_id'], $uid);
        $stmt = mysqli_prepare($conn, "SELECT * FROM college_exam_attempts WHERE attempt_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $attempt['attempt_id']);
        mysqli_stmt_execute($stmt);
        $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
}

/* Start or restart (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_exam'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: college_take_exam.php?exam_id=' . $examId);
        exit;
    }
    if ($attempt && $attempt['status'] === 'submitted') {
        header('Location: college_take_exam.php?exam_id=' . $examId . '&review=1');
        exit;
    }
    if (!empty($exam['available_from']) && $exam['available_from'] > $now) {
        $_SESSION['error'] = 'This exam is not available yet.';
        header('Location: college_exams.php');
        exit;
    }
    if (!empty($exam['deadline']) && $exam['deadline'] < $now) {
        $_SESSION['error'] = 'The deadline has passed.';
        header('Location: college_exams.php');
        exit;
    }

    $expiresAt = college_exam_compute_expires_at((int)$exam['time_limit_seconds'], $exam['deadline'] ?? null);
    $started = date('Y-m-d H:i:s');

    if (!$attempt) {
        $ins = mysqli_prepare($conn, "INSERT INTO college_exam_attempts (exam_id, user_id, status, started_at, expires_at) VALUES (?, ?, 'in_progress', ?, ?)");
        $expParam = $expiresAt;
        mysqli_stmt_bind_param($ins, 'iiss', $examId, $uid, $started, $expParam);
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
    } elseif ($attempt['status'] === 'expired') {
        $aid = (int)$attempt['attempt_id'];
        mysqli_query($conn, "DELETE FROM college_exam_answers WHERE attempt_id=" . $aid);
        $upd = mysqli_prepare($conn, "UPDATE college_exam_attempts SET status='in_progress', started_at=?, expires_at=?, submitted_at=NULL, score=NULL, correct_count=NULL, total_count=NULL WHERE attempt_id=? AND user_id=?");
        mysqli_stmt_bind_param($upd, 'ssii', $started, $expiresAt, $aid, $uid);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }
    header('Location: college_take_exam.php?exam_id=' . $examId);
    exit;
}

$questions = [];
$qres = mysqli_query($conn, "SELECT * FROM college_exam_questions WHERE exam_id=" . (int)$examId . " ORDER BY sort_order ASC, question_id ASC");
if ($qres) {
    while ($q = mysqli_fetch_assoc($qres)) {
        $questions[] = $q;
    }
    mysqli_free_result($qres);
}

$answersMap = [];
if ($attempt && ($reviewMode || $attempt['status'] === 'submitted')) {
    $ar = mysqli_query($conn, "SELECT question_id, selected_answer, is_correct FROM college_exam_answers WHERE attempt_id=" . (int)$attempt['attempt_id']);
    if ($ar) {
        while ($r = mysqli_fetch_assoc($ar)) {
            $answersMap[(int)$r['question_id']] = $r;
        }
        mysqli_free_result($ar);
    }
} elseif ($attempt && $attempt['status'] === 'in_progress') {
    $ar = mysqli_query($conn, "SELECT question_id, selected_answer FROM college_exam_answers WHERE attempt_id=" . (int)$attempt['attempt_id']);
    if ($ar) {
        while ($r = mysqli_fetch_assoc($ar)) {
            $answersMap[(int)$r['question_id']] = $r;
        }
        mysqli_free_result($ar);
    }
}

if ($attempt && $attempt['status'] === 'submitted' && !$reviewMode) {
    header('Location: college_take_exam.php?exam_id=' . $examId . '&review=1');
    exit;
}

$showIntro = !$attempt || ($attempt['status'] === 'expired');

$remainingSeconds = null;
if ($attempt && $attempt['status'] === 'in_progress' && !empty($attempt['expires_at'])) {
    $remainingSeconds = max(0, strtotime($attempt['expires_at']) - time());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="max-w-3xl mx-auto w-full px-4 sm:px-5 pt-2 pb-12">
    <a href="college_exams.php" class="inline-flex items-center gap-1 text-sm font-semibold text-[#1665A0] hover:underline mb-4"><i class="bi bi-arrow-left"></i> Back to exams</a>

    <h1 class="text-2xl font-bold text-[#143D59] m-0"><?php echo h($exam['title']); ?></h1>
    <?php if (!empty($exam['description'])): ?>
      <p class="text-gray-600 mt-2"><?php echo nl2br(h($exam['description'])); ?></p>
    <?php endif; ?>

    <?php if ($showIntro): ?>
      <div class="mt-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <p class="text-gray-700 m-0 mb-4">
          <?php if ((int)$exam['time_limit_seconds'] > 0): ?>
            Time limit: <strong><?php echo (int)floor($exam['time_limit_seconds'] / 60); ?> min</strong>.
          <?php else: ?>
            No per-question timer (finish before the deadline if any).
          <?php endif; ?>
          <?php if (!empty($exam['deadline'])): ?>
            <br>Deadline: <strong><?php echo h(date('M j, Y g:i A', strtotime($exam['deadline']))); ?></strong>
          <?php endif; ?>
        </p>
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <button type="submit" name="start_exam" value="1" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-semibold bg-[#1665A0] text-white hover:bg-[#145a8f] transition">
            <i class="bi bi-play-fill"></i> Start exam
          </button>
        </form>
      </div>
    <?php elseif ($reviewMode && $attempt && $attempt['status'] === 'submitted'): ?>
      <div class="mt-6 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 px-4 py-3 mb-6">
        <strong>Score:</strong> <?php echo h((string)$attempt['score']); ?>% (<?php echo (int)$attempt['correct_count']; ?> / <?php echo (int)$attempt['total_count']; ?>)
      </div>
      <?php $i = 1; foreach ($questions as $q): ?>
        <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
          <p class="font-semibold text-[#143D59] m-0 mb-3"><?php echo $i++; ?>. <?php echo nl2br(h($q['question_text'])); ?></p>
          <?php
            $letters = ['A' => $q['choice_a'], 'B' => $q['choice_b'], 'C' => $q['choice_c'], 'D' => $q['choice_d']];
            $sel = $answersMap[(int)$q['question_id']]['selected_answer'] ?? '';
          foreach ($letters as $L => $txt):
            if ($txt === null || $txt === '') {
                continue;
            }
            $isCorrect = strtoupper(trim((string)$q['correct_answer'])) === $L;
            $picked = strtoupper((string)$sel) === $L;
          ?>
          <div class="flex items-start gap-2 py-1 text-sm <?php echo $isCorrect ? 'text-emerald-800 font-medium' : ($picked ? 'text-red-700' : 'text-gray-600'); ?>">
            <span class="font-mono w-6"><?php echo h($L); ?>.</span>
            <span><?php echo nl2br(h($txt)); ?><?php echo $isCorrect ? ' ✓' : ''; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

    <?php elseif ($attempt && $attempt['status'] === 'in_progress'): ?>
      <div id="examTimer" class="mt-4 mb-6 flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900 <?php echo $remainingSeconds === null ? 'hidden' : ''; ?>">
        <i class="bi bi-hourglass-split text-lg"></i>
        <span class="font-semibold">Time left:</span>
        <span id="timerDisplay" class="font-mono text-lg">--:--</span>
      </div>

      <form id="examForm" data-attempt-id="<?php echo (int)$attempt['attempt_id']; ?>" data-csrf="<?php echo h($csrf); ?>">
        <?php $i = 1; foreach ($questions as $q): ?>
        <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
          <p class="font-semibold text-[#143D59] m-0 mb-3"><?php echo $i++; ?>. <?php echo nl2br(h($q['question_text'])); ?></p>
          <?php
            $letters = ['A' => $q['choice_a'], 'B' => $q['choice_b'], 'C' => $q['choice_c'], 'D' => $q['choice_d']];
            $prev = $answersMap[(int)$q['question_id']]['selected_answer'] ?? '';
          foreach ($letters as $L => $txt):
            if ($txt === null || $txt === '') {
                continue;
            }
            $id = 'q_' . (int)$q['question_id'] . '_' . $L;
          ?>
          <label class="flex items-start gap-3 py-2 cursor-pointer rounded-lg hover:bg-gray-50 px-2 -mx-2">
            <input type="radio" name="q_<?php echo (int)$q['question_id']; ?>" value="<?php echo h($L); ?>"
              class="mt-1" data-question-id="<?php echo (int)$q['question_id']; ?>"
              <?php echo strtoupper((string)$prev) === $L ? 'checked' : ''; ?>>
            <span class="text-gray-800"><span class="font-mono text-[#1665A0]"><?php echo h($L); ?>.</span> <?php echo nl2br(h($txt)); ?></span>
          </label>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <div class="flex flex-wrap gap-3">
          <button type="button" id="submitExamBtn" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-semibold bg-emerald-600 text-white hover:bg-emerald-700 transition">
            <i class="bi bi-check2-circle"></i> Submit exam
          </button>
        </div>
      </form>

      <script>
(function(){
  var form = document.getElementById('examForm');
  if (!form) return;
  var attemptId = parseInt(form.getAttribute('data-attempt-id'), 10);
  var csrf = form.getAttribute('data-csrf');
  var ajaxUrl = 'college_exam_ajax.php';

  function saveAnswer(questionId, letter) {
    var body = new URLSearchParams();
    body.set('action', 'save_answer');
    body.set('csrf_token', csrf);
    body.set('attempt_id', String(attemptId));
    body.set('question_id', String(questionId));
    body.set('selected_answer', letter);
    return fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function(r){ return r.json(); });
  }

  form.querySelectorAll('input[type=radio]').forEach(function(inp){
    inp.addEventListener('change', function(){
      var qid = inp.getAttribute('data-question-id');
      saveAnswer(qid, inp.value);
    });
  });

  function submitExam() {
    var body = new URLSearchParams();
    body.set('action', 'submit');
    body.set('csrf_token', csrf);
    body.set('attempt_id', String(attemptId));
    fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.ok) {
          window.location.href = 'college_take_exam.php?exam_id=<?php echo (int)$examId; ?>&review=1';
        } else {
          alert(data.error || 'Could not submit');
        }
      });
  }
  document.getElementById('submitExamBtn').addEventListener('click', submitExam);

  <?php if ($remainingSeconds !== null): ?>
  var remaining = <?php echo (int)$remainingSeconds; ?>;
  var timerEl = document.getElementById('timerDisplay');
  function tick() {
    if (remaining <= 0) {
      timerEl.textContent = '0:00';
      submitExam();
      return;
    }
    var m = Math.floor(remaining / 60);
    var s = remaining % 60;
    timerEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
    remaining--;
    setTimeout(tick, 1000);
  }
  tick();
  setInterval(function(){
    var body = new URLSearchParams();
    body.set('action', 'get_time');
    body.set('attempt_id', String(attemptId));
    fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.ok && data.remaining_seconds !== undefined && data.remaining_seconds !== null) {
          if (data.remaining_seconds <= 0) submitExam();
        }
      });
  }, 30000);
  <?php endif; ?>
})();
      </script>
    <?php else: ?>
      <p class="text-gray-600 mt-6">Unable to load this exam.</p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
