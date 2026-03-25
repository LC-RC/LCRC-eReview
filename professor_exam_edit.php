<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'Edit exam';
$uid = getCurrentUserId();
$examId = sanitizeInt($_GET['id'] ?? 0);
$csrf = generateCSRFToken();
$error = null;

$exam = null;
if ($examId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM college_exams WHERE exam_id=? AND created_by=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $examId, $uid);
    mysqli_stmt_execute($stmt);
    $exam = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$exam) {
        $_SESSION['error'] = 'Exam not found.';
        header('Location: professor_exams.php');
        exit;
    }
}

$questions = [];
if ($examId > 0) {
    $qr = mysqli_query($conn, "SELECT * FROM college_exam_questions WHERE exam_id=" . (int)$examId . " ORDER BY sort_order ASC, question_id ASC");
    if ($qr) {
        while ($q = mysqli_fetch_assoc($qr)) {
            $questions[] = $q;
        }
        mysqli_free_result($qr);
    }
}

if (empty($questions)) {
    for ($i = 0; $i < 5; $i++) {
        $questions[] = [
            'question_text' => '',
            'choice_a' => '',
            'choice_b' => '',
            'choice_c' => '',
            'choice_d' => '',
            'correct_answer' => 'A',
            'sort_order' => $i,
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = 'Invalid request.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $timeLimit = max(0, sanitizeInt($_POST['time_limit_seconds'] ?? 3600));
        $availableFrom = trim($_POST['available_from'] ?? '');
        $deadline = trim($_POST['deadline'] ?? '');
        $isPublished = !empty($_POST['is_published']) ? 1 : 0;

        $qTexts = $_POST['question_text'] ?? [];
        $ca = $_POST['choice_a'] ?? [];
        $cb = $_POST['choice_b'] ?? [];
        $cc = $_POST['choice_c'] ?? [];
        $cd = $_POST['choice_d'] ?? [];
        $corr = $_POST['correct_answer'] ?? [];

        if ($title === '') {
            $error = 'Title is required.';
        } else {
            $availSql = ($availableFrom !== '') ? date('Y-m-d H:i:s', strtotime($availableFrom)) : null;
            $deadSql = ($deadline !== '') ? date('Y-m-d H:i:s', strtotime($deadline)) : null;

            mysqli_begin_transaction($conn);
            try {
                if ($examId <= 0) {
                    $ins = mysqli_prepare($conn, "INSERT INTO college_exams (title, description, time_limit_seconds, available_from, deadline, is_published, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($ins, 'ssissii', $title, $description, $timeLimit, $availSql, $deadSql, $isPublished, $uid);
                    mysqli_stmt_execute($ins);
                    $examId = (int)mysqli_insert_id($conn);
                    mysqli_stmt_close($ins);
                } else {
                    $upd = mysqli_prepare($conn, "UPDATE college_exams SET title=?, description=?, time_limit_seconds=?, available_from=?, deadline=?, is_published=? WHERE exam_id=? AND created_by=?");
                    mysqli_stmt_bind_param($upd, 'ssissiii', $title, $description, $timeLimit, $availSql, $deadSql, $isPublished, $examId, $uid);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                    mysqli_query($conn, "DELETE FROM college_exam_questions WHERE exam_id=" . (int)$examId);
                }

                $sort = 0;
                $n = max(count($qTexts), count($ca), count($cb));
                for ($i = 0; $i < $n; $i++) {
                    $qt = trim((string)($qTexts[$i] ?? ''));
                    if ($qt === '') {
                        continue;
                    }
                    $a = trim((string)($ca[$i] ?? ''));
                    $b = trim((string)($cb[$i] ?? ''));
                    $c = trim((string)($cc[$i] ?? ''));
                    $d = trim((string)($cd[$i] ?? ''));
                    $ok = strtoupper(trim((string)($corr[$i] ?? 'A')));
                    if (!preg_match('/^[A-D]$/', $ok)) {
                        $ok = 'A';
                    }
                    $insq = mysqli_prepare($conn, "INSERT INTO college_exam_questions (exam_id, question_text, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($insq, 'issssssi', $examId, $qt, $a, $b, $c, $d, $ok, $sort);
                    mysqli_stmt_execute($insq);
                    mysqli_stmt_close($insq);
                    $sort++;
                }

                if ($sort === 0) {
                    mysqli_rollback($conn);
                    $error = 'Add at least one question with a prompt.';
                } else {
                    mysqli_commit($conn);
                    $_SESSION['message'] = 'Exam saved.';
                    header('Location: professor_exams.php');
                    exit;
                }
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $error = 'Could not save exam.';
            }
        }
    }
}

if ($exam) {
    $pageTitle = 'Edit exam';
} else {
    $pageTitle = 'New exam';
}

$prefill = $exam ?: [
    'title' => '',
    'description' => '',
    'time_limit_seconds' => 3600,
    'available_from' => null,
    'deadline' => null,
    'is_published' => 0,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <div class="admin-content max-w-4xl mx-auto w-full px-4 lg:px-6 pb-10">
    <a href="professor_exams.php" class="inline-flex items-center gap-1 text-sm font-semibold text-green-700 hover:underline mb-4"><i class="bi bi-arrow-left"></i> Back</a>

    <h1 class="text-2xl font-bold text-green-800 m-0"><?php echo (int)$examId > 0 ? 'Edit exam' : 'New exam'; ?></h1>

    <?php if ($error): ?><div class="mt-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-900"><?php echo h($error); ?></div><?php endif; ?>

    <form method="post" class="mt-6 space-y-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

      <div class="grid grid-cols-1 gap-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Title</label>
          <input type="text" name="title" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" value="<?php echo h($prefill['title'] ?? ''); ?>">
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Description</label>
          <textarea name="description" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"><?php echo h($prefill['description'] ?? ''); ?></textarea>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Time limit (seconds)</label>
            <input type="number" name="time_limit_seconds" min="0" step="60" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" value="<?php echo (int)($prefill['time_limit_seconds'] ?? 3600); ?>">
            <p class="text-xs text-gray-500 mt-1">0 = no countdown timer (deadline still applies if set).</p>
          </div>
          <div class="flex items-center gap-2 pt-6">
            <input type="checkbox" name="is_published" id="pub" value="1" <?php echo !empty($prefill['is_published']) ? 'checked' : ''; ?>>
            <label for="pub" class="text-sm font-medium text-gray-800">Published (visible to students)</label>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Available from (optional)</label>
            <input type="datetime-local" name="available_from" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              value="<?php echo !empty($prefill['available_from']) ? h(date('Y-m-d\TH:i', strtotime($prefill['available_from']))) : ''; ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Deadline (optional)</label>
            <input type="datetime-local" name="deadline" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              value="<?php echo !empty($prefill['deadline']) ? h(date('Y-m-d\TH:i', strtotime($prefill['deadline']))) : ''; ?>">
          </div>
        </div>
      </div>

      <div class="border-t border-gray-200 pt-6">
        <h2 class="text-lg font-bold text-green-800 m-0 mb-4">Questions (multiple choice)</h2>
        <p class="text-sm text-gray-600 mb-4">Leave blank rows out; each block needs prompt and choices A–D.</p>

        <?php foreach ($questions as $idx => $q): ?>
        <div class="mb-6 p-4 rounded-xl bg-gray-50 border border-gray-200">
          <p class="text-sm font-semibold text-gray-700 mb-2">Question <?php echo $idx + 1; ?></p>
          <textarea name="question_text[]" rows="2" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-2" placeholder="Question"><?php echo h($q['question_text'] ?? ''); ?></textarea>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-2">
            <input type="text" name="choice_a[]" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm" placeholder="Choice A" value="<?php echo h($q['choice_a'] ?? ''); ?>">
            <input type="text" name="choice_b[]" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm" placeholder="Choice B" value="<?php echo h($q['choice_b'] ?? ''); ?>">
            <input type="text" name="choice_c[]" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm" placeholder="Choice C" value="<?php echo h($q['choice_c'] ?? ''); ?>">
            <input type="text" name="choice_d[]" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm" placeholder="Choice D" value="<?php echo h($q['choice_d'] ?? ''); ?>">
          </div>
          <label class="text-sm font-semibold text-gray-700">Correct</label>
          <select name="correct_answer[]" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm ml-2">
            <?php foreach (['A','B','C','D'] as $L): ?>
            <option value="<?php echo $L; ?>" <?php echo strtoupper((string)($q['correct_answer'] ?? 'A')) === $L ? 'selected' : ''; ?>><?php echo $L; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endforeach; ?>

        <p class="text-xs text-gray-500">To add more questions, save and edit again — or duplicate rows in HTML (advanced).</p>
      </div>

      <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-semibold bg-green-600 text-white hover:bg-green-700 transition">Save exam</button>
    </form>
  </div>
</main>
</body>
</html>
