<?php
require_once 'auth.php';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_lessons.php?subject_id='.$subjectId);
        exit;
    }
    $action = $_POST['action'] ?? 'save';
    if ($action === 'delete') {
        $lessonId = sanitizeInt($_POST['lesson_id'] ?? 0);
        if ($lessonId > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM lessons WHERE lesson_id=? AND subject_id=?");
            mysqli_stmt_bind_param($stmt, 'ii', $lessonId, $subjectId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Lesson deleted.';
        }
        header('Location: admin_lessons.php?subject_id='.$subjectId);
        exit;
    }
    $lessonId = sanitizeInt($_POST['lesson_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($title === '') {
        $_SESSION['error'] = 'Lesson title is required.';
        header('Location: admin_lessons.php?subject_id='.$subjectId);
        exit;
    }
    if ($lessonId > 0) {
        $stmt = mysqli_prepare($conn, "UPDATE lessons SET title=?, description=? WHERE lesson_id=? AND subject_id=?");
        mysqli_stmt_bind_param($stmt, 'ssii', $title, $desc, $lessonId, $subjectId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Lesson updated.';
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO lessons (subject_id, title, description) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'iss', $subjectId, $title, $desc);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Lesson created.';
    }
    header('Location: admin_lessons.php?subject_id='.$subjectId);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = sanitizeInt($_GET['edit']);
    if ($eid > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM lessons WHERE lesson_id=? AND subject_id=? LIMIT 1");
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
    $countParts[] = '(title LIKE ? OR IFNULL(description, \'\') LIKE ?)';
    $countTypes .= 'ss';
    $like = '%' . $searchQ . '%';
    $countVals[] = $like;
    $countVals[] = $like;
}
$countSql = 'SELECT COUNT(*) AS total FROM lessons WHERE ' . implode(' AND ', $countParts);
$stmt = mysqli_prepare($conn, $countSql);
mysqli_stmt_bind_param($stmt, $countTypes, ...$countVals);
mysqli_stmt_execute($stmt);
$countRes = mysqli_stmt_get_result($stmt);
$countRow = mysqli_fetch_assoc($countRes);
$totalLessons = (int)($countRow['total'] ?? 0);
mysqli_stmt_close($stmt);
$totalPages = max(1, (int)ceil($totalLessons / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listParts = ['l.subject_id=?'];
$listTypes = 'i';
$listVals = [$subjectId];
if ($searchQ !== '') {
    $listParts[] = '(l.title LIKE ? OR IFNULL(l.description, \'\') LIKE ?)';
    $listTypes .= 'ss';
    $like = '%' . $searchQ . '%';
    $listVals[] = $like;
    $listVals[] = $like;
}
$listSql = '
    SELECT l.*,
      (SELECT COUNT(*) FROM lesson_videos v WHERE v.lesson_id=l.lesson_id) AS videos_cnt,
      (SELECT COUNT(*) FROM lesson_handouts h WHERE h.lesson_id=l.lesson_id) AS handouts_cnt
    FROM lessons l
    WHERE ' . implode(' AND ', $listParts) . '
    ORDER BY l.lesson_id DESC
    LIMIT ? OFFSET ?';
$listTypes .= 'ii';
$listVals[] = $perPage;
$listVals[] = $offset;
$stmt = mysqli_prepare($conn, $listSql);
mysqli_stmt_bind_param($stmt, $listTypes, ...$listVals);
mysqli_stmt_execute($stmt);
$lessons = mysqli_stmt_get_result($stmt);

$pageTitle = 'Lessons - ' . $subject['subject_name'];
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Content Hub', 'admin_subjects.php'], [ h($subject['subject_name']), 'admin_lessons.php?subject_id=' . $subjectId ], ['Lessons'] ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <link rel="stylesheet" href="assets/css/admin-quiz-ui.css?v=3">
</head>
<body class="font-sans antialiased admin-app admin-lessons-page" x-data="lessonsApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <div class="quiz-admin-hero rounded-xl px-5 py-5 mb-5">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-gray-100 m-0 flex items-center gap-2">
      <span class="quiz-admin-hero-icon" aria-hidden="true"><i class="bi bi-file-text"></i></span>
      Lessons — <span class="admin-subject-text admin-subject-text--lessons"><?php echo h($subject['subject_name']); ?></span>
    </h1>
    <p class="text-gray-400 mt-2 mb-0 text-sm sm:text-base max-w-3xl">Create lessons, then open <strong class="text-gray-300 font-semibold">Materials</strong> to add videos and handouts.</p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-4 mb-5 quiz-admin-toolbar">
    <div></div>
    <div class="flex flex-wrap gap-2">
      <a href="admin_subjects.php" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-arrow-left"></i> Back to Content Hub</a>
      <a href="admin_quizzes.php?subject_id=<?php echo (int)$subjectId; ?>" class="admin-outline-btn admin-outline-btn--quiz px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-question-circle"></i> Quizzes for <?php echo h($subject['subject_name']); ?></a>
      <button type="button" @click="openNewLesson()" class="admin-content-btn admin-content-btn--lessons px-4 py-2.5 rounded-lg font-semibold border-2 transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> New Lesson</button>
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

  <form method="get" action="admin_lessons.php" class="quiz-admin-filter quiz-admin-table-shell rounded-xl px-4 py-3 mb-4 flex flex-wrap items-end gap-3">
    <input type="hidden" name="subject_id" value="<?php echo (int)$subjectId; ?>">
    <div class="flex-1 min-w-[200px]">
      <label for="lessons-search-q" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Search</label>
      <input type="search" id="lessons-search-q" name="q" value="<?php echo h($searchQ); ?>" placeholder="Search title or description…" class="input-custom w-full" autocomplete="off">
    </div>
    <div class="flex flex-wrap gap-2">
      <button type="submit" class="quiz-admin-filter-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-search"></i> Apply</button>
      <?php if ($searchQ !== ''): ?>
        <a href="admin_lessons.php?subject_id=<?php echo (int)$subjectId; ?>" class="quiz-admin-filter-clear px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2">Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="quiz-admin-table-shell rounded-xl overflow-hidden">
    <div class="quiz-admin-table-head px-5 py-4 flex flex-wrap justify-between items-center gap-2">
      <div class="flex items-center gap-2">
        <span class="font-semibold text-gray-100">Lessons</span>
        <span class="quiz-admin-count-pill quiz-admin-count-pill--lessons"><?php echo (int)$totalLessons; ?></span>
      </div>
      <p class="text-gray-500 text-sm hidden md:block m-0">Tip: Open <strong class="text-gray-400">Materials</strong> for each lesson to add videos and handouts.</p>
      <div class="text-gray-500 text-sm text-right">
        <?php if ($totalLessons > 0): ?>
          <span>Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalLessons); ?> of <?php echo $totalLessons; ?></span>
          <span class="mx-1">·</span>
        <?php endif; ?>
        <span>Subject: <span class="admin-subject-text admin-subject-text--lessons"><?php echo h($subject['subject_name']); ?></span></span>
      </div>
    </div>
    <div class="overflow-x-auto pl-3 pr-8">
      <table class="quiz-admin-data-table w-full text-left">
        <thead>
          <tr>
            <th class="px-5 py-3 font-semibold text-center">Lesson</th>
            <th class="px-5 py-3 font-semibold text-center">Videos</th>
            <th class="px-5 py-3 font-semibold text-center">Handouts</th>
            <th class="px-5 py-3 font-semibold text-center w-[220px]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $hasAny = false; while ($l = mysqli_fetch_assoc($lessons)): $hasAny = true;
            $vCnt = (int)($l['videos_cnt'] ?? 0);
            $hCnt = (int)($l['handouts_cnt'] ?? 0);
            $vClass = $vCnt === 0 ? 'lesson-count-pill lesson-count-pill--warn' : 'lesson-count-pill lesson-count-pill--ok';
            $hClass = $hCnt === 0 ? 'lesson-count-pill lesson-count-pill--warn' : 'lesson-count-pill lesson-count-pill--ok';
            $vTitle = $vCnt === 0 ? 'No videos yet — add via Materials' : $vCnt . ' video(s)';
            $hTitle = $hCnt === 0 ? 'No handouts yet — add via Materials' : $hCnt . ' handout(s)';
          ?>
            <tr class="quiz-admin-row">
              <td class="px-5 py-3 text-center">
                <div class="font-semibold text-gray-100"><?php echo h($l['title']); ?></div>
                <?php if (!empty($l['description'])): ?>
                  <div class="text-gray-500 text-sm mt-0.5"><?php echo h(mb_strimwidth($l['description'], 0, 90, '…')); ?></div>
                <?php endif; ?>
              </td>
              <td class="px-5 py-3 text-center" title="<?php echo h($vTitle); ?>">
                <span class="inline-flex min-w-[2.25rem] justify-center px-2.5 py-1 rounded-md text-sm font-bold tabular-nums <?php echo $vClass; ?>"><?php echo $vCnt; ?></span>
              </td>
              <td class="px-5 py-3 text-center" title="<?php echo h($hTitle); ?>">
                <span class="inline-flex min-w-[2.25rem] justify-center px-2.5 py-1 rounded-md text-sm font-bold tabular-nums <?php echo $hClass; ?>"><?php echo $hCnt; ?></span>
              </td>
              <td class="px-5 py-3 text-center">
                <div class="inline-block text-left w-[200px]" x-data="{ expanded: false }">
                  <div class="flex flex-col gap-2">
                    <a href="admin_materials.php?lesson_id=<?php echo (int)$l['lesson_id']; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="quiz-admin-link-primary lesson-materials-link flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg text-sm font-semibold transition"><i class="bi bi-grid"></i> Materials</a>
                    <button type="button" @click="expanded = !expanded" class="quiz-admin-more-btn flex items-center justify-center gap-1 w-full py-1.5 rounded-md text-xs border transition" :aria-expanded="expanded" title="More actions">
                      <i class="bi text-sm" :class="expanded ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                      <span class="opacity-80">More</span>
                    </button>
                  </div>
                  <div x-show="expanded" x-cloak x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="flex flex-col gap-2 mt-2">
                    <button type="button" data-id="<?php echo (int)$l['lesson_id']; ?>" data-title="<?php echo h($l['title'] ?? ''); ?>" data-description="<?php echo h($l['description'] ?? ''); ?>" @click="expanded = false; openEditLesson($el.dataset.id, $el.dataset.title || '', $el.dataset.description || '')" class="quiz-admin-btn-secondary flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg text-sm font-semibold transition"><i class="bi bi-pencil"></i> Edit</button>
                    <button type="button" data-id="<?php echo (int)$l['lesson_id']; ?>" data-title="<?php echo h($l['title'] ?? ''); ?>" @click="expanded = false; openDeleteLesson($el.dataset.id, $el.dataset.title || '')" class="flex items-center justify-center gap-2 w-full px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-400 hover:bg-red-600 hover:text-white transition"><i class="bi bi-trash"></i> Delete</button>
                  </div>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          <?php if (!$hasAny): ?>
            <tr>
              <td colspan="4" class="px-5 py-14 text-center quiz-admin-empty">
                <i class="bi bi-inbox text-4xl block mb-3 quiz-admin-empty-icon"></i>
                <div class="font-semibold text-gray-200"><?php echo $searchQ !== '' ? 'No lessons match your search' : 'No lessons yet'; ?></div>
                <p class="text-sm mt-1 text-gray-500"><?php echo $searchQ !== '' ? 'Try different keywords or clear the filter.' : 'Create your first lesson to start uploading videos and handouts.'; ?></p>
                <?php if ($searchQ === ''): ?>
                  <button type="button" @click="openNewLesson()" class="mt-4 px-4 py-2.5 rounded-lg font-semibold admin-content-btn admin-content-btn--lessons border-2 transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> New Lesson</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php mysqli_stmt_close($stmt); ?>
    <?php if ($totalPages > 1): ?>
      <nav class="quiz-admin-pagination px-5 py-4 flex justify-center" aria-label="Lesson pagination">
        <ul class="flex flex-wrap items-center gap-1">
          <?php
            $filterQs = $searchQ !== '' ? ['q' => $searchQ] : [];
            $filterSuffix = $filterQs ? '&' . http_build_query($filterQs) : '';
            $baseUrl = 'admin_lessons.php?subject_id=' . (int)$subjectId . $filterSuffix;
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

  <!-- Create/Edit Lesson Modal -->
  <div x-show="lessonModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="lessonModalOpen = false">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-[2px]" @click="lessonModalOpen = false"></div>
    <div class="relative quiz-modal-panel rounded-xl shadow-modal max-w-lg w-full max-h-[90vh] overflow-y-auto" @click.stop>
      <div class="p-5 border-b border-white/10 flex justify-between items-center quiz-modal-panel__head">
        <h2 class="text-xl font-bold text-gray-100 m-0" x-text="isEdit ? 'Edit Lesson' : 'New Lesson'"></h2>
        <button type="button" @click="lessonModalOpen = false" class="p-2 rounded-lg text-gray-400 hover:bg-white/10 hover:text-white" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_lessons.php?subject_id=<?php echo (int)$subjectId; ?>" class="p-5">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="lesson_id" :value="lesson_id">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Title</label>
            <input type="text" name="title" x-model="title" required placeholder="e.g., Lesson 1: Introduction" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Description</label>
            <textarea name="description" x-model="description" rows="4" placeholder="Optional summary, outline, or notes" class="input-custom"></textarea>
          </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" @click="lessonModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border border-white/20 text-gray-200 hover:bg-white/10 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-emerald-600 text-white hover:bg-emerald-500 transition inline-flex items-center gap-2 shadow-lg shadow-emerald-900/30"><i class="bi bi-save"></i> <span x-text="isEdit ? 'Update' : 'Create'"></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Lesson Modal -->
  <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="deleteModalOpen = false">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-[2px]" @click="deleteModalOpen = false"></div>
    <div class="relative quiz-modal-panel rounded-xl shadow-modal max-w-md w-full p-5" @click.stop>
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold text-gray-100 m-0"><i class="bi bi-trash text-red-400 mr-2"></i> Delete Lesson</h2>
        <button type="button" @click="deleteModalOpen = false" class="p-2 rounded-lg text-gray-400 hover:bg-white/10 hover:text-white" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_lessons.php?subject_id=<?php echo (int)$subjectId; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="lesson_id" :value="delete_lesson_id">
        <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/35 text-amber-100 mb-4">
          <div class="font-semibold">This will delete the lesson and related materials.</div>
          <div class="text-sm mt-1 text-amber-200/90">Lesson: <span class="font-semibold" x-text="delete_lesson_title"></span></div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="deleteModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border border-white/20 text-gray-200 hover:bg-white/10 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-red-600 text-white hover:bg-red-500 transition inline-flex items-center gap-2"><i class="bi bi-trash"></i> Delete</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function lessonsApp() {
      return {
        lessonModalOpen: false,
        deleteModalOpen: false,
        isEdit: false,
        lesson_id: 0,
        title: '',
        description: '',
        delete_lesson_id: 0,
        delete_lesson_title: '',
        editFromServer: <?php echo !empty($edit) ? json_encode(['id' => (int)$edit['lesson_id'], 'title' => $edit['title'] ?? '', 'description' => $edit['description'] ?? '']) : 'null'; ?>,
        openNewLesson() {
          this.isEdit = false;
          this.lesson_id = 0;
          this.title = '';
          this.description = '';
          this.lessonModalOpen = true;
        },
        openEditLesson(id, title, description) {
          this.isEdit = true;
          this.lesson_id = id;
          this.title = title || '';
          this.description = description || '';
          this.lessonModalOpen = true;
        },
        openDeleteLesson(id, title) {
          this.delete_lesson_id = id;
          this.delete_lesson_title = title || '';
          this.deleteModalOpen = true;
        },
        initEditFromServer() {
          if (this.editFromServer) this.openEditLesson(this.editFromServer.id, this.editFromServer.title, this.editFromServer.description);
        }
      };
    }
  </script>
</div>
</main>
</body>
</html>
