<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'Upload tasks';
$uid = getCurrentUserId();
$csrf = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: professor_upload_tasks.php');
        exit;
    }
    $action = $_POST['action'] ?? '';
    $tid = sanitizeInt($_POST['task_id'] ?? 0);

    if ($action === 'delete' && $tid > 0) {
        mysqli_query($conn, "DELETE FROM college_upload_tasks WHERE task_id=" . $tid . " AND created_by=" . (int)$uid);
        $_SESSION['message'] = 'Task deleted.';
    } elseif ($action === 'save') {
        $title = trim($_POST['title'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        $deadline = trim($_POST['deadline'] ?? '');
        $maxSize = max(1024, sanitizeInt($_POST['max_file_size'] ?? 10485760));
        $allowed = trim($_POST['allowed_extensions'] ?? 'pdf,doc,docx');
        $isOpen = !empty($_POST['is_open']) ? 1 : 0;

        if ($title === '' || $deadline === '') {
            $_SESSION['error'] = 'Title and deadline are required.';
        } else {
            $deadSql = date('Y-m-d H:i:s', strtotime($deadline));
            if ($tid > 0) {
                $stmt = mysqli_prepare($conn, "UPDATE college_upload_tasks SET title=?, instructions=?, deadline=?, max_file_size=?, allowed_extensions=?, is_open=? WHERE task_id=? AND created_by=?");
                mysqli_stmt_bind_param($stmt, 'sssisiii', $title, $instructions, $deadSql, $maxSize, $allowed, $isOpen, $tid, $uid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            } else {
                $stmt = mysqli_prepare($conn, "INSERT INTO college_upload_tasks (title, instructions, deadline, max_file_size, allowed_extensions, is_open, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, 'sssisii', $title, $instructions, $deadSql, $maxSize, $allowed, $isOpen, $uid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            $_SESSION['message'] = 'Task saved.';
        }
    }
    header('Location: professor_upload_tasks.php');
    exit;
}

$list = [];
$q = mysqli_query($conn, "SELECT * FROM college_upload_tasks WHERE created_by=" . (int)$uid . " ORDER BY deadline DESC");
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $list[] = $r;
    }
    mysqli_free_result($q);
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = sanitizeInt($_GET['edit']);
    $stmt = mysqli_prepare($conn, "SELECT * FROM college_upload_tasks WHERE task_id=? AND created_by=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $eid, $uid);
    mysqli_stmt_execute($stmt);
    $edit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

$msg = $_SESSION['message'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['message'], $_SESSION['error']);
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
              <i class="bi bi-folder-plus text-green-700 text-xl"></i>
            </div>
            <div>
              <h1 class="text-2xl font-bold text-green-900 m-0 leading-tight">File upload tasks</h1>
              <p class="text-gray-600 mt-1 mb-0">Students can upload until the deadline.</p>
            </div>
          </div>
          <div class="hidden sm:block"></div>
        </div>
      </div>
    </div>

    <?php if ($msg): ?><div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900"><?php echo h($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-900"><?php echo h($err); ?></div><?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
      <div class="rounded-xl border border-green-200 bg-white p-6 shadow-sm hover:shadow-md transition-shadow">
        <h2 class="text-lg font-bold text-green-800 m-0 mb-4"><?php echo $edit ? 'Edit task' : 'New task'; ?></h2>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="task_id" value="<?php echo $edit ? (int)$edit['task_id'] : 0; ?>">

          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Title</label>
            <input type="text" name="title" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" value="<?php echo h($edit['title'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Instructions</label>
            <textarea name="instructions" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"><?php echo h($edit['instructions'] ?? ''); ?></textarea>
          </div>
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Deadline</label>
            <input type="datetime-local" name="deadline" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              value="<?php echo $edit && !empty($edit['deadline']) ? h(date('Y-m-d\TH:i', strtotime($edit['deadline']))) : ''; ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Max file size (bytes)</label>
            <input type="number" name="max_file_size" min="1024" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" value="<?php echo (int)($edit['max_file_size'] ?? 10485760); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Allowed extensions (comma)</label>
            <input type="text" name="allowed_extensions" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" value="<?php echo h($edit['allowed_extensions'] ?? 'pdf,doc,docx,png,jpg,jpeg'); ?>">
          </div>
          <div class="flex items-center gap-2">
            <input type="checkbox" name="is_open" id="op" value="1" <?php echo !isset($edit['is_open']) || !empty($edit['is_open']) ? 'checked' : ''; ?>>
            <label for="op" class="text-sm font-medium">Open for submissions</label>
          </div>
          <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold bg-green-600 text-white hover:bg-green-700 transition">Save</button>
          <?php if ($edit): ?><a href="professor_upload_tasks.php" class="ml-3 text-sm font-semibold text-green-700 hover:underline">Cancel</a><?php endif; ?>
        </form>
      </div>

      <div class="rounded-xl border border-green-200 bg-white shadow-sm overflow-hidden">
        <table class="w-full text-sm text-left">
          <thead class="bg-green-50 font-semibold text-green-800 border-b border-gray-200">
            <tr>
              <th class="px-4 py-3">Title</th>
              <th class="px-4 py-3 hidden sm:table-cell">Deadline</th>
              <th class="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-green-100">
            <?php if (empty($list)): ?>
            <tr><td colspan="3" class="px-4 py-8 text-center text-gray-500">No tasks yet.</td></tr>
            <?php else: ?>
              <?php foreach ($list as $t): ?>
              <tr class="hover:bg-green-50/80 transition-colors">
                <td class="px-4 py-3 font-medium"><?php echo h($t['title']); ?></td>
                <td class="px-4 py-3 text-gray-600 hidden sm:table-cell"><?php echo h(date('M j, Y g:i A', strtotime($t['deadline']))); ?></td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                  <a href="professor_upload_tasks.php?edit=<?php echo (int)$t['task_id']; ?>" class="text-green-700 font-semibold hover:underline mr-3">Edit</a>
                  <form method="post" class="inline" onsubmit="return confirm('Delete this task?');">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="task_id" value="<?php echo (int)$t['task_id']; ?>">
                    <button type="submit" class="text-red-600 font-semibold hover:underline">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
</body>
</html>
