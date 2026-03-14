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

$stmt = mysqli_prepare($conn, "
    SELECT l.*,
      (SELECT COUNT(*) FROM lesson_videos v WHERE v.lesson_id=l.lesson_id) AS videos_cnt,
      (SELECT COUNT(*) FROM lesson_handouts h WHERE h.lesson_id=l.lesson_id) AS handouts_cnt
    FROM lessons l
    WHERE l.subject_id=?
    ORDER BY l.lesson_id DESC
");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$lessons = mysqli_stmt_get_result($stmt);

$pageTitle = 'Lessons - ' . $subject['subject_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased" x-data="lessonsApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-file-text"></i> Lessons - <?php echo h($subject['subject_name']); ?>
    </h1>
    <p class="text-gray-500 mt-1">Create lessons then open Materials to add videos and handouts.</p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-4 mb-5">
    <div></div>
    <div class="flex gap-2">
      <a href="admin_subjects.php" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Back to Subjects</a>
      <button type="button" @click="openNewLesson()" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> New Lesson</button>
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
      <span class="font-semibold text-gray-800">Lessons</span>
      <span class="text-gray-500 text-sm">Subject: <?php echo h($subject['subject_name']); ?></span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-left">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-5 py-3 font-semibold text-gray-700">Lesson</th>
            <th class="px-5 py-3 font-semibold text-gray-700">Videos</th>
            <th class="px-5 py-3 font-semibold text-gray-700">Handouts</th>
            <th class="px-5 py-3 font-semibold text-gray-700 w-[320px]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $hasAny = false; while ($l = mysqli_fetch_assoc($lessons)): $hasAny = true; ?>
            <tr class="border-b border-gray-100 hover:bg-gray-50/50">
              <td class="px-5 py-3">
                <div class="font-semibold text-gray-800"><?php echo h($l['title']); ?></div>
                <?php if (!empty($l['description'])): ?>
                  <div class="text-gray-500 text-sm mt-0.5"><?php echo h(mb_strimwidth($l['description'], 0, 90, '…')); ?></div>
                <?php endif; ?>
              </td>
              <td class="px-5 py-3"><span class="px-2.5 py-1 rounded-full text-sm bg-gray-100 text-gray-700"><?php echo (int)($l['videos_cnt'] ?? 0); ?></span></td>
              <td class="px-5 py-3"><span class="px-2.5 py-1 rounded-full text-sm bg-gray-100 text-gray-700"><?php echo (int)($l['handouts_cnt'] ?? 0); ?></span></td>
              <td class="px-5 py-3">
                <div class="flex flex-wrap gap-2">
                  <a href="admin_materials.php?lesson_id=<?php echo (int)$l['lesson_id']; ?>&subject_id=<?php echo (int)$subjectId; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-grid"></i> Materials</a>
                  <button type="button" data-id="<?php echo (int)$l['lesson_id']; ?>" data-title="<?php echo h($l['title'] ?? ''); ?>" data-description="<?php echo h($l['description'] ?? ''); ?>" @click="openEditLesson($el.dataset.id, $el.dataset.title || '', $el.dataset.description || '')" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition"><i class="bi bi-pencil"></i> Edit</button>
                  <button type="button" data-id="<?php echo (int)$l['lesson_id']; ?>" data-title="<?php echo h($l['title'] ?? ''); ?>" @click="openDeleteLesson($el.dataset.id, $el.dataset.title || '')" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition"><i class="bi bi-trash"></i> Delete</button>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          <?php if (!$hasAny): ?>
            <tr>
              <td colspan="4" class="px-5 py-12 text-center text-gray-500">
                <i class="bi bi-inbox text-4xl block mb-2"></i>
                <div class="font-semibold">No lessons yet</div>
                <p class="text-sm mt-1">Create your first lesson to start uploading videos and handouts.</p>
                <button type="button" @click="openNewLesson()" class="mt-3 px-4 py-2 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> New Lesson</button>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php mysqli_stmt_close($stmt); ?>

  <!-- Create/Edit Lesson Modal -->
  <div x-show="lessonModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="lessonModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="lessonModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal max-w-lg w-full max-h-[90vh] overflow-y-auto" @click.stop>
      <div class="p-5 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-xl font-bold text-gray-800 m-0" x-text="isEdit ? 'Edit Lesson' : 'New Lesson'"></h2>
        <button type="button" @click="lessonModalOpen = false" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_lessons.php?subject_id=<?php echo (int)$subjectId; ?>" class="p-5">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="lesson_id" :value="lesson_id">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
            <input type="text" name="title" x-model="title" required placeholder="e.g., Lesson 1: Introduction" class="input-custom">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea name="description" x-model="description" rows="4" placeholder="Optional summary, outline, or notes" class="input-custom"></textarea>
          </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" @click="lessonModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-save"></i> <span x-text="isEdit ? 'Update' : 'Create'"></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Lesson Modal -->
  <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="deleteModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="deleteModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal max-w-md w-full p-5" @click.stop>
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold text-gray-800 m-0"><i class="bi bi-trash text-red-500 mr-2"></i> Delete Lesson</h2>
        <button type="button" @click="deleteModalOpen = false" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_lessons.php?subject_id=<?php echo (int)$subjectId; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="lesson_id" :value="delete_lesson_id">
        <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 mb-4">
          <div class="font-semibold">This will delete the lesson and related materials.</div>
          <div class="text-sm mt-1">Lesson: <span class="font-semibold" x-text="delete_lesson_title"></span></div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="deleteModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-red-600 text-white hover:bg-red-700 transition inline-flex items-center gap-2"><i class="bi bi-trash"></i> Delete</button>
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
</main>
</body>
</html>
