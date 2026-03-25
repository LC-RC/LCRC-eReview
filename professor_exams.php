<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'College exams';
$uid = getCurrentUserId();
$csrf = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: professor_exams.php');
        exit;
    }
    $action = $_POST['action'] ?? '';
    $eid = sanitizeInt($_POST['exam_id'] ?? 0);
    if ($action === 'delete' && $eid > 0) {
        mysqli_query($conn, "DELETE FROM college_exams WHERE exam_id=" . $eid . " AND created_by=" . (int)$uid);
        $_SESSION['message'] = 'Exam deleted.';
    } elseif ($action === 'toggle_publish' && $eid > 0) {
        mysqli_query($conn, "UPDATE college_exams SET is_published=1-is_published WHERE exam_id=" . $eid . " AND created_by=" . (int)$uid);
        $_SESSION['message'] = 'Publication updated.';
    }
    header('Location: professor_exams.php');
    exit;
}

$list = [];
$q = mysqli_query($conn, "SELECT e.*, (SELECT COUNT(*) FROM college_exam_questions q WHERE q.exam_id=e.exam_id) AS q_count FROM college_exams e WHERE e.created_by=" . (int)$uid . " ORDER BY e.updated_at DESC");
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $list[] = $r;
    }
    mysqli_free_result($q);
}

$msg = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <main class="admin-content max-w-7xl mx-auto w-full px-4 lg:px-6">
    <div class="mb-6">
      <div class="rounded-xl border border-green-200 bg-gradient-to-r from-green-50/70 via-white to-white shadow-sm overflow-hidden">
        <div class="p-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-green-600/10 border border-green-200 flex items-center justify-center shrink-0">
              <i class="bi bi-journal-text text-green-700 text-xl"></i>
            </div>
            <div>
              <h1 class="text-2xl font-bold text-green-900 m-0 leading-tight">Exams &amp; quizzes</h1>
              <p class="text-gray-600 mt-1 mb-0">Create timed assessments for college students.</p>
            </div>
          </div>
          <a href="professor_exam_edit.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold bg-green-600 text-white hover:bg-green-700 transition-all duration-300 hover:-translate-y-0.5 shadow-sm hover:shadow-md">
            <i class="bi bi-plus-lg"></i> New exam
          </a>
        </div>
      </div>
    </div>

    <?php if ($msg): ?><div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900"><?php echo h($msg); ?></div><?php endif; ?>

    <div class="rounded-xl border border-green-200 bg-gradient-to-b from-green-50/50 to-white shadow-sm overflow-hidden">
      <table class="w-full text-sm text-left">
        <thead class="bg-green-50 text-green-800 font-semibold border-b border-gray-200">
          <tr>
            <th class="px-4 py-3">Title</th>
            <th class="px-4 py-3 hidden sm:table-cell">Questions</th>
            <th class="px-4 py-3">Published</th>
            <th class="px-4 py-3 hidden md:table-cell">Deadline</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-green-100">
          <?php if (empty($list)): ?>
          <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">No exams yet.</td></tr>
          <?php else: ?>
            <?php foreach ($list as $e): ?>
            <tr class="hover:bg-green-50/80 transition-colors">
              <td class="px-4 py-3 font-medium"><?php echo h($e['title']); ?></td>
              <td class="px-4 py-3 hidden sm:table-cell"><?php echo (int)$e['q_count']; ?></td>
              <td class="px-4 py-3"><?php echo !empty($e['is_published']) ? 'Yes' : 'No'; ?></td>
              <td class="px-4 py-3 text-gray-600 hidden md:table-cell"><?php echo $e['deadline'] ? h(date('M j, Y g:i A', strtotime($e['deadline']))) : '—'; ?></td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <a class="text-green-700 font-semibold hover:underline mr-3" href="professor_exam_edit.php?id=<?php echo (int)$e['exam_id']; ?>">Edit</a>
                <form method="post" class="inline" onsubmit="return confirm('Delete this exam?');">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="exam_id" value="<?php echo (int)$e['exam_id']; ?>">
                  <button type="submit" class="text-red-600 font-semibold hover:underline">Delete</button>
                </form>
                <form method="post" class="inline ml-2">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                  <input type="hidden" name="action" value="toggle_publish">
                  <input type="hidden" name="exam_id" value="<?php echo (int)$e['exam_id']; ?>">
                  <button type="submit" class="text-gray-700 font-semibold hover:underline"><?php echo !empty($e['is_published']) ? 'Unpublish' : 'Publish'; ?></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
</body>
</html>
