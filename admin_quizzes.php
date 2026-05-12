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
if (in_array('quiz_type', $quizCols, true)) {
    @mysqli_query($conn, "ALTER TABLE `quizzes` MODIFY COLUMN `quiz_type` ENUM('randomized','topical','pre-test','post-test','mock') NOT NULL DEFAULT 'topical'");
    @mysqli_query($conn, "UPDATE `quizzes` SET `quiz_type`='topical' WHERE `quiz_type` IN ('randomized','pre-test','post-test','mock') OR `quiz_type` IS NULL OR `quiz_type`=''");
}
if (!in_array('shuffle_mcq_questions', $quizCols, true)) {
    @mysqli_query($conn, "ALTER TABLE `quizzes` ADD COLUMN `shuffle_mcq_questions` tinyint(1) NOT NULL DEFAULT 0 AFTER `time_limit_seconds`");
}
if (!in_array('shuffle_mcq_choices', $quizCols, true)) {
    @mysqli_query($conn, "ALTER TABLE `quizzes` ADD COLUMN `shuffle_mcq_choices` tinyint(1) NOT NULL DEFAULT 0 AFTER `shuffle_mcq_questions`");
}
if (!in_array('mcq_pick_count', $quizCols, true)) {
    @mysqli_query($conn, "ALTER TABLE `quizzes` ADD COLUMN `mcq_pick_count` int(11) NOT NULL DEFAULT 0 AFTER `shuffle_mcq_choices`");
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
    $quizType = 'topical';
    $shuffleMcqQuestions = !empty($_POST['shuffle_mcq_questions']) ? 1 : 0;
    $shuffleMcqChoices = !empty($_POST['shuffle_mcq_choices']) ? 1 : 0;
    $mcqPickCount = max(0, (int)($_POST['mcq_pick_count'] ?? 0));
    if ($quizId > 0) {
        $stmt = mysqli_prepare($conn, "UPDATE quizzes SET title=?, quiz_type=?, time_limit_minutes=?, time_limit_seconds=?, shuffle_mcq_questions=?, shuffle_mcq_choices=?, mcq_pick_count=? WHERE quiz_id=? AND subject_id=?");
        mysqli_stmt_bind_param($stmt, 'ssiiiiiii', $title, $quizType, $timeLimitMinutes, $timeLimitSeconds, $shuffleMcqQuestions, $shuffleMcqChoices, $mcqPickCount, $quizId, $subjectId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Quiz updated.';
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO quizzes (subject_id, title, quiz_type, time_limit_minutes, time_limit_seconds, shuffle_mcq_questions, shuffle_mcq_choices, mcq_pick_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'issiiiii', $subjectId, $title, $quizType, $timeLimitMinutes, $timeLimitSeconds, $shuffleMcqQuestions, $shuffleMcqChoices, $mcqPickCount);
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
        $stmt = mysqli_prepare($conn, "SELECT q.*, (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.quiz_id) AS questions_cnt FROM quizzes q WHERE q.quiz_id=? AND q.subject_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ii', $eid, $subjectId);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $edit = mysqli_fetch_assoc($r);
        mysqli_stmt_close($stmt);
    }
}

$page = sanitizeInt($_GET['page'] ?? 1, 1);
$perPage = 15;
$offset = ($page - 1) * $perPage;

$searchQ = trim($_GET['q'] ?? '');
$countParts = ['subject_id=?'];
$countTypes = 'i';
$countVals = [$subjectId];
if ($searchQ !== '') {
    $countParts[] = 'title LIKE ?';
    $countTypes .= 's';
    $countVals[] = '%' . $searchQ . '%';
}
$countSql = 'SELECT COUNT(*) AS total FROM quizzes WHERE ' . implode(' AND ', $countParts);
$stmt = mysqli_prepare($conn, $countSql);
mysqli_stmt_bind_param($stmt, $countTypes, ...$countVals);
mysqli_stmt_execute($stmt);
$countRes = mysqli_stmt_get_result($stmt);
$countRow = mysqli_fetch_assoc($countRes);
$totalQuizzes = (int)($countRow['total'] ?? 0);
mysqli_stmt_close($stmt);
$totalPages = max(1, (int)ceil($totalQuizzes / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listParts = ['q.subject_id=?'];
$listTypes = 'i';
$listVals = [$subjectId];
if ($searchQ !== '') {
    $listParts[] = 'q.title LIKE ?';
    $listTypes .= 's';
    $listVals[] = '%' . $searchQ . '%';
}
$listSql = "SELECT q.*, (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.quiz_id) AS questions_cnt FROM quizzes q WHERE " . implode(' AND ', $listParts) . " ORDER BY q.quiz_id DESC LIMIT ? OFFSET ?";
$listTypes .= 'ii';
$listVals[] = $perPage;
$listVals[] = $offset;
$stmt = mysqli_prepare($conn, $listSql);
mysqli_stmt_bind_param($stmt, $listTypes, ...$listVals);
mysqli_stmt_execute($stmt);
$quizzes = mysqli_stmt_get_result($stmt);

$quizTypeLabels = ['topical' => 'Topical'];
$quizTypeTitles = ['topical' => 'Focused set grouped by topic'];

$pageTitle = 'Quizzes - ' . $subject['subject_name'];
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Content Hub', 'admin_subjects.php'], [ h($subject['subject_name']), 'admin_quizzes.php?subject_id=' . $subjectId ], ['Quizzes'] ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <link rel="stylesheet" href="assets/css/admin-quiz-ui.css?v=3">
</head>
<body class="font-sans antialiased admin-app admin-quizzes-page" x-data="quizzesApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <div class="quiz-admin-hero rounded-xl px-5 py-5 mb-5">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-gray-100 m-0 flex items-center gap-2">
      <span class="quiz-admin-hero-icon" aria-hidden="true"><i class="bi bi-question-circle"></i></span> Quizzes — <span class="admin-subject-text admin-subject-text--quiz"><?php echo h($subject['subject_name']); ?></span>
    </h1>
    <p class="text-gray-400 mt-2 mb-0 text-sm sm:text-base max-w-3xl">Create quizzes, then open <strong class="text-gray-300 font-semibold">Questions</strong> to build the question bank.</p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-4 mb-5 quiz-admin-toolbar">
    <div></div>
    <div class="flex flex-wrap gap-2">
      <a href="admin_subjects.php" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-arrow-left"></i> Back to Content Hub</a>
      <a href="admin_lessons.php?subject_id=<?php echo (int)$subjectId; ?>" class="admin-outline-btn admin-outline-btn--lessons px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-file-text"></i> Lessons for <?php echo h($subject['subject_name']); ?></a>
      <button type="button" @click="openNewQuiz()" class="admin-content-btn admin-content-btn--quiz px-4 py-2.5 rounded-lg font-semibold border-2 transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> New Quiz</button>
    </div>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--success mb-5 flex items-center gap-2">
      <i class="bi bi-check-circle-fill shrink-0"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--error mb-5 flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill shrink-0"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <form method="get" action="admin_quizzes.php" class="quiz-admin-filter quiz-admin-table-shell rounded-xl px-4 py-3 mb-4 flex flex-wrap items-end gap-3">
    <input type="hidden" name="subject_id" value="<?php echo (int)$subjectId; ?>">
    <div class="flex-1 min-w-[200px]">
      <label for="quiz-search-q" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Search</label>
      <input type="search" id="quiz-search-q" name="q" value="<?php echo h($searchQ); ?>" placeholder="Search by quiz title…" class="input-custom w-full" autocomplete="off">
    </div>
    <div class="flex flex-wrap gap-2">
      <button type="submit" class="quiz-admin-filter-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-funnel"></i> Apply</button>
      <?php if ($searchQ !== ''): ?>
        <a href="admin_quizzes.php?subject_id=<?php echo (int)$subjectId; ?>" class="quiz-admin-filter-clear px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2">Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="quiz-admin-table-shell rounded-xl overflow-hidden">
    <div class="quiz-admin-table-head px-5 py-4 flex flex-wrap justify-between items-center gap-2">
      <div class="flex items-center gap-2">
        <span class="font-semibold text-gray-100">Quizzes</span>
        <span class="quiz-admin-count-pill"><?php echo (int)$totalQuizzes; ?></span>
      </div>
      <p class="text-gray-500 text-sm hidden md:block m-0">Tip: Open <strong class="text-gray-400">Questions</strong> for each quiz to build the question bank.</p>
      <div class="text-gray-500 text-sm text-right">
        <?php if ($totalQuizzes > 0): ?>
          <span>Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalQuizzes); ?> of <?php echo $totalQuizzes; ?></span>
          <span class="mx-1">·</span>
        <?php endif; ?>
        <span>Subject: <span class="admin-subject-text admin-subject-text--quiz"><?php echo h($subject['subject_name']); ?></span></span>
      </div>
    </div>
    <div class="overflow-x-auto pl-3 pr-8">
      <table class="quiz-admin-data-table w-full text-left">
        <thead>
          <tr>
            <th class="px-5 py-3 font-semibold text-center">Quiz</th>
            <th class="px-5 py-3 font-semibold text-center">Type</th>
            <th class="px-5 py-3 font-semibold text-center">Questions</th>
            <th class="px-5 py-3 font-semibold text-center w-[220px]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $hasAny = false; while ($qz = mysqli_fetch_assoc($quizzes)): $hasAny = true;
            $qt = 'topical';
            $typeClass = 'quiz-type-pill quiz-type-pill--post';
            $typeLabel = $quizTypeLabels[$qt] ?? 'Topical';
            $typeTitle = $quizTypeTitles[$qt] ?? '';
            $qCnt = (int)($qz['questions_cnt'] ?? 0);
            $questionsCellClass = $qCnt === 0 ? 'quiz-qcount quiz-qcount--empty' : 'quiz-qcount quiz-qcount--ok';
            $questionsCellTitle = $qCnt === 0 ? 'No questions yet — add them via Questions' : $qCnt . ' question(s)';
          ?>
            <tr class="quiz-admin-row">
              <td class="px-5 py-3.5 text-center font-semibold text-gray-100"><?php echo h($qz['title']); ?></td>
              <td class="px-5 py-3.5 text-center"><span class="inline-block px-2.5 py-1 rounded-md text-xs font-semibold <?php echo $typeClass; ?>" title="<?php echo h($typeTitle); ?>"><?php echo h($typeLabel); ?></span></td>
              <td class="px-5 py-3.5 text-center" title="<?php echo h($questionsCellTitle); ?>">
                <span class="inline-flex min-w-[2.25rem] justify-center px-2.5 py-1 rounded-md text-sm font-bold tabular-nums <?php echo $questionsCellClass; ?>"><?php echo $qCnt; ?></span>
              </td>
              <td class="px-5 py-3 text-center">
                <div class="inline-block text-left w-[200px]" x-data="{ expanded: false }">
                  <div class="flex flex-col gap-2">
                    <a href="admin_quiz_questions.php?quiz_id=<?php echo (int)$qz['quiz_id']; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="quiz-admin-link-primary flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg text-sm font-semibold transition"><i class="bi bi-list-check"></i> Questions</a>
                    <button type="button" @click="expanded = !expanded" class="quiz-admin-more-btn flex items-center justify-center gap-1 w-full py-1.5 rounded-md text-xs border transition" :aria-expanded="expanded" title="More actions">
                      <i class="bi text-sm" :class="expanded ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                      <span class="opacity-80">More</span>
                    </button>
                  </div>
                  <div x-show="expanded" x-cloak x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="flex flex-col gap-2 mt-2">
                    <button type="button" data-id="<?php echo (int)$qz['quiz_id']; ?>" data-title="<?php echo h($qz['title'] ?? ''); ?>" data-seconds="<?php echo (int)getQuizTimeLimitSeconds($qz); ?>" data-qcount="<?php echo (int)$qCnt; ?>" data-shuffle-mcq="<?php echo !empty($qz['shuffle_mcq_questions']) ? '1' : '0'; ?>" data-shuffle-choices="<?php echo !empty($qz['shuffle_mcq_choices']) ? '1' : '0'; ?>" data-pick-count="<?php echo (int)($qz['mcq_pick_count'] ?? 0); ?>" @click="expanded = false; openEditQuiz($el.dataset.id, $el.dataset.title || '', parseInt($el.dataset.seconds || '1800', 10), parseInt($el.dataset.qcount || '0', 10), $el.dataset.shuffleMcq === '1', $el.dataset.shuffleChoices === '1', parseInt($el.dataset.pickCount || '0', 10))" class="quiz-admin-btn-secondary flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg text-sm font-semibold transition"><i class="bi bi-pencil"></i> Edit</button>
                    <button type="button" data-id="<?php echo (int)$qz['quiz_id']; ?>" data-title="<?php echo h($qz['title'] ?? ''); ?>" @click="expanded = false; openDeleteQuiz($el.dataset.id, $el.dataset.title || '')" class="flex items-center justify-center gap-2 w-full px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition"><i class="bi bi-trash"></i> Delete</button>
                  </div>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          <?php if (!$hasAny): ?>
            <tr>
              <td colspan="4" class="px-5 py-14 text-center quiz-admin-empty">
                <i class="bi bi-inbox text-4xl block mb-3 quiz-admin-empty-icon"></i>
                <div class="font-semibold text-gray-200">No quizzes yet</div>
                <p class="text-sm mt-1 text-gray-500">Create your first quiz, then add questions.</p>
                <button type="button" @click="openNewQuiz()" class="mt-4 px-4 py-2.5 rounded-lg font-semibold admin-content-btn admin-content-btn--quiz border-2 transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> New Quiz</button>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php mysqli_stmt_close($stmt); ?>
    <?php if ($totalPages > 1): ?>
      <nav class="quiz-admin-pagination px-5 py-4 flex justify-center" aria-label="Quiz pagination">
        <ul class="flex flex-wrap items-center gap-1">
          <?php
            $filterQs = [];
            if ($searchQ !== '') {
                $filterQs['q'] = $searchQ;
            }
            $filterSuffix = $filterQs ? '&' . http_build_query($filterQs) : '';
            $baseUrl = 'admin_quizzes.php?subject_id=' . (int)$subjectId . $filterSuffix;
            $mk = function ($p) use ($baseUrl) { return $baseUrl . ($p > 1 ? '&page=' . $p : ''); };
          ?>
          <?php if ($page > 1): ?>
            <li><a href="<?php echo h($mk($page - 1)); ?>" class="quiz-admin-page-link px-3 py-2 rounded-lg border transition">Previous</a></li>
          <?php endif; ?>
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <li>
              <a href="<?php echo h($mk($i)); ?>" class="quiz-admin-page-link px-3 py-2 rounded-lg border transition <?php echo $i === $page ? 'is-active' : ''; ?>"><?php echo $i; ?></a>
            </li>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <li><a href="<?php echo h($mk($page + 1)); ?>" class="quiz-admin-page-link px-3 py-2 rounded-lg border transition">Next</a></li>
          <?php endif; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>

  <!-- Create/Edit Quiz Modal -->
  <div x-show="quizModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="quizModalOpen = false">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-[2px]" @click="quizModalOpen = false"></div>
    <div class="relative quiz-modal-panel rounded-xl shadow-modal max-w-lg w-full max-h-[90vh] overflow-y-auto" @click.stop>
      <div class="p-5 border-b border-white/10 flex justify-between items-center quiz-modal-panel__head">
        <h2 class="text-xl font-bold text-gray-100 m-0" x-text="isEdit ? 'Edit Quiz' : 'New Quiz'"></h2>
        <button type="button" @click="quizModalOpen = false" class="p-2 rounded-lg text-gray-400 hover:bg-white/10 hover:text-white" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_quizzes.php?subject_id=<?php echo (int)$subjectId; ?>" class="p-5">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="quiz_id" :value="quiz_id">
        <div x-effect="normalizePickCount()" class="hidden"></div>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Quiz Title</label>
            <input type="text" name="title" x-model="title" required placeholder="e.g., Chapter 1 Quiz" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Quiz Type</label>
            <div class="rounded-lg border border-white/15 bg-white/5 px-3 py-2">
              <span class="inline-flex items-center gap-2 text-sm text-gray-200 font-medium">
                <i class="bi bi-bookmarks-fill text-violet-300"></i> Topical (default)
              </span>
            </div>
            <input type="hidden" name="quiz_type" value="topical">
            <p class="text-xs text-gray-500 mt-1">This workflow uses topical quizzes only.</p>
          </div>
          <div class="rounded-xl border border-white/15 bg-white/5 p-3 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h3 class="text-sm font-semibold text-gray-200 m-0 inline-flex items-center gap-2"><i class="bi bi-shuffle text-violet-300"></i> Shuffle configuration</h3>
              <span class="text-xs text-gray-400">Questions in this quiz: <strong x-text="question_count"></strong></span>
            </div>
            <label class="flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 cursor-pointer hover:bg-white/10 transition">
              <input type="checkbox" name="shuffle_mcq_questions" x-model="shuffle_mcq_questions" class="accent-violet-500">
              <span class="text-sm text-gray-200 font-medium">Shuffle MCQ questions</span>
            </label>
            <label class="flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 cursor-pointer hover:bg-white/10 transition">
              <input type="checkbox" name="shuffle_mcq_choices" x-model="shuffle_mcq_choices" class="accent-violet-500">
              <span class="text-sm text-gray-200 font-medium">Shuffle MCQ choices</span>
            </label>
            <div>
              <label class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Pick random questions per attempt</label>
              <select name="mcq_pick_count" x-model.number="mcq_pick_count" class="input-custom w-full" :disabled="pickOptions.length === 0 || !shuffle_mcq_questions">
                <option value="0">Use all questions</option>
                <template x-for="opt in pickOptions" :key="'pick-' + opt">
                  <option :value="opt" x-text="'Per ' + opt"></option>
                </template>
              </select>
              <p class="text-xs text-gray-500 mt-1" x-show="pickOptions.length === 0" x-cloak>Add at least 10 questions to enable per-set random pick.</p>
              <p class="text-xs text-gray-500 mt-1" x-show="pickOptions.length > 0 && !shuffle_mcq_questions" x-cloak>Enable “Shuffle MCQ questions” to activate per-set picking.</p>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Time limit (how long to take the quiz)</label>
            <div class="flex flex-wrap items-center gap-2">
              <input type="number" x-model.number="time_limit_hours" min="0" max="24" class="input-custom w-20" placeholder="0">
              <span class="text-gray-400 text-sm">hour(s)</span>
              <input type="number" x-model.number="time_limit_mins" min="0" max="59" class="input-custom w-20" placeholder="30">
              <span class="text-gray-400 text-sm">min(s)</span>
              <input type="number" x-model.number="time_limit_secs" min="0" max="59" class="input-custom w-20" placeholder="0">
              <span class="text-gray-400 text-sm">sec(s)</span>
            </div>
            <input type="hidden" name="time_limit_seconds" :value="Math.max(1, time_limit_hours * 3600 + time_limit_mins * 60 + time_limit_secs)">
            <p class="text-xs text-gray-500 mt-1">At least 1 second; max 24 hours.</p>
          </div>
          <p class="text-sm text-gray-500">You'll add the questions after creating the quiz.</p>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" @click="quizModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border border-white/20 text-gray-200 hover:bg-white/10 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-violet-600 text-white hover:bg-violet-500 transition inline-flex items-center gap-2 shadow-lg shadow-violet-900/30"><i class="bi bi-save"></i> <span x-text="isEdit ? 'Update' : 'Create'"></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Quiz Modal -->
  <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="deleteModalOpen = false">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-[2px]" @click="deleteModalOpen = false"></div>
    <div class="relative quiz-modal-panel rounded-xl shadow-modal max-w-md w-full p-5" @click.stop>
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold text-gray-100 m-0"><i class="bi bi-trash text-red-400 mr-2"></i> Delete Quiz</h2>
        <button type="button" @click="deleteModalOpen = false" class="p-2 rounded-lg text-gray-400 hover:bg-white/10" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_quizzes.php?subject_id=<?php echo (int)$subjectId; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="quiz_id" :value="delete_quiz_id">
        <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-100 mb-4">
          <div class="font-semibold">This will delete the quiz and its questions.</div>
          <div class="text-sm mt-1 text-amber-200/90">Quiz: <span class="font-semibold text-white" x-text="delete_quiz_title"></span></div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="deleteModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border border-white/20 text-gray-200 hover:bg-white/10 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-red-600 text-white hover:bg-red-500 transition inline-flex items-center gap-2"><i class="bi bi-trash"></i> Delete</button>
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
        question_count: 0,
        shuffle_mcq_questions: false,
        shuffle_mcq_choices: false,
        mcq_pick_count: 0,
        time_limit_hours: 0,
        time_limit_mins: 30,
        time_limit_secs: 0,
        delete_quiz_id: 0,
        delete_quiz_title: '',
        editFromServer: <?php echo !empty($edit) ? json_encode(['id' => (int)$edit['quiz_id'], 'title' => $edit['title'] ?? '', 'time_limit_seconds' => (int)getQuizTimeLimitSeconds($edit ?? []), 'question_count' => (int)($edit['questions_cnt'] ?? 0), 'shuffle_mcq_questions' => !empty($edit['shuffle_mcq_questions']), 'shuffle_mcq_choices' => !empty($edit['shuffle_mcq_choices']), 'mcq_pick_count' => (int)($edit['mcq_pick_count'] ?? 0)]) : 'null'; ?>,
        get pickOptions() {
          var max = parseInt(this.question_count || 0, 10);
          if (!max || max < 10) return [];
          var out = [];
          for (var n = 10; n < max; n += 10) out.push(n);
          return out;
        },
        normalizePickCount() {
          if (!this.shuffle_mcq_questions) {
            this.mcq_pick_count = 0;
            return;
          }
          var opts = this.pickOptions;
          if (!opts.length) {
            this.mcq_pick_count = 0;
            return;
          }
          if (this.mcq_pick_count === 0) return;
          if (opts.indexOf(this.mcq_pick_count) === -1) this.mcq_pick_count = 0;
        },
        openNewQuiz() {
          this.isEdit = false;
          this.quiz_id = 0;
          this.title = '';
          this.question_count = 0;
          this.shuffle_mcq_questions = false;
          this.shuffle_mcq_choices = false;
          this.mcq_pick_count = 0;
          this.time_limit_hours = 0;
          this.time_limit_mins = 30;
          this.time_limit_secs = 0;
          this.quizModalOpen = true;
        },
        openEditQuiz(id, title, totalSeconds, questionCount, shuffleMcqQuestions, shuffleMcqChoices, mcqPickCount) {
          this.isEdit = true;
          this.quiz_id = id;
          this.title = title || '';
          this.question_count = parseInt(questionCount || 0, 10) || 0;
          this.shuffle_mcq_questions = !!shuffleMcqQuestions;
          this.shuffle_mcq_choices = !!shuffleMcqChoices;
          this.mcq_pick_count = parseInt(mcqPickCount || 0, 10) || 0;
          totalSeconds = parseInt(totalSeconds, 10);
          if (isNaN(totalSeconds) || totalSeconds <= 0) totalSeconds = 1800;
          totalSeconds = (totalSeconds > 0 && totalSeconds <= 86400) ? totalSeconds : 1800;
          this.time_limit_hours = Math.floor(totalSeconds / 3600);
          this.time_limit_mins = Math.floor((totalSeconds % 3600) / 60);
          this.time_limit_secs = totalSeconds % 60;
          this.normalizePickCount();
          this.quizModalOpen = true;
        },
        openDeleteQuiz(id, title) {
          this.delete_quiz_id = id;
          this.delete_quiz_title = title || '';
          this.deleteModalOpen = true;
        },
        initEditFromServer() {
          if (this.editFromServer) this.openEditQuiz(
            this.editFromServer.id,
            this.editFromServer.title,
            this.editFromServer.time_limit_seconds || 1800,
            this.editFromServer.question_count || 0,
            !!this.editFromServer.shuffle_mcq_questions,
            !!this.editFromServer.shuffle_mcq_choices,
            this.editFromServer.mcq_pick_count || 0
          );
        }
      };
    }
  </script>
</div>
</main>
</body>
</html>
