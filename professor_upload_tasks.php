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
  <style>
    .prof-page { background: linear-gradient(180deg, #eefaf3 0%, #e6f6ee 45%, #edf9f2 100%); min-height: 100%; }
    .dashboard-shell { padding-bottom: 1.5rem; color: #0f172a; }
    .prof-hero {
      border-radius: 0.75rem; border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #0f766e 0%, #0e9f6e 35%, #16a34a 75%, #15803d 100%);
      box-shadow: 0 14px 34px -20px rgba(5,46,22,.75), inset 0 1px 0 rgba(255,255,255,.22);
    }
    .prof-icon { background: rgba(255,255,255,.22); border: 1px solid rgba(255,255,255,.34); color: #fff; }
    .section-title {
      display: flex; align-items: center; gap: .5rem; margin: 0 0 .85rem; padding: .45rem .65rem;
      border: 1px solid #d1fae5; border-radius: .62rem; background: linear-gradient(180deg,#f5fff9 0%,#fff 100%);
      color: #14532d; font-size: 1.03rem; font-weight: 800;
    }
    .section-title i {
      width: 1.55rem; height: 1.55rem; border-radius: .45rem; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #bbf7d0; background: #ecfdf3; color: #15803d; font-size: .83rem;
    }
    .panel-card {
      border-radius: .75rem; border: 1px solid rgba(22,163,74,.22); overflow: hidden;
      background: linear-gradient(180deg, #f4fff8 0%, #fff 42%);
      box-shadow: 0 10px 28px -22px rgba(21,128,61,.58), 0 1px 0 rgba(255,255,255,.8) inset;
      transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .panel-card:hover { transform: translateY(-2px); border-color: rgba(22,163,74,.38); box-shadow: 0 20px 34px -24px rgba(15,118,110,.4); }
    .table-head { background: linear-gradient(180deg, #edfff4 0%, #f6fff9 100%); }
    .table-head th { font-size: .78rem; text-transform: uppercase; letter-spacing: .01em; font-weight: 800; color: #166534; }
    .table-row { transition: background-color .2s ease; }
    .table-row:hover { background: #f4fff8; }
    .save-btn { border-radius: .6rem; transition: transform .2s ease, box-shadow .2s ease; }
    .save-btn:hover { transform: translateY(-1px); box-shadow: 0 12px 20px -18px rgba(21,128,61,.9); }
    .field { border-color: #bbf7d0; }
    .field:focus { border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.15); outline: none; }
    .dash-anim { opacity: 0; transform: translateY(12px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; }
    .delay-2 { animation-delay: .12s; }
    .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
    }
  </style>
</head>
<body class="font-sans antialiased prof-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <main class="dashboard-shell w-full max-w-none">
    <div class="mb-6 dash-anim delay-1">
      <div class="prof-hero overflow-hidden">
        <div class="p-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex items-start gap-3">
            <div class="prof-icon w-11 h-11 rounded-xl flex items-center justify-center shrink-0">
              <i class="bi bi-folder-plus text-xl"></i>
            </div>
            <div>
              <h1 class="text-2xl font-bold text-white m-0 leading-tight">File upload tasks</h1>
              <p class="text-white/90 mt-1 mb-0">Students can upload until the deadline.</p>
            </div>
          </div>
          <div class="hidden sm:block"></div>
        </div>
      </div>
    </div>

    <?php if ($msg): ?><div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 dash-anim delay-2"><?php echo h($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-900 dash-anim delay-2"><?php echo h($err); ?></div><?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
      <div class="panel-card p-6 dash-anim delay-2">
        <h2 class="section-title mb-4"><i class="bi bi-pencil-square"></i><?php echo $edit ? 'Edit Task' : 'New Task'; ?></h2>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="task_id" value="<?php echo $edit ? (int)$edit['task_id'] : 0; ?>">

          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Title</label>
            <input type="text" name="title" required class="field w-full rounded-lg border px-3 py-2 text-sm" value="<?php echo h($edit['title'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Instructions</label>
            <textarea name="instructions" rows="3" class="field w-full rounded-lg border px-3 py-2 text-sm"><?php echo h($edit['instructions'] ?? ''); ?></textarea>
          </div>
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Deadline</label>
            <input type="datetime-local" name="deadline" required class="field w-full rounded-lg border px-3 py-2 text-sm"
              value="<?php echo $edit && !empty($edit['deadline']) ? h(date('Y-m-d\TH:i', strtotime($edit['deadline']))) : ''; ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Max file size (bytes)</label>
            <input type="number" name="max_file_size" min="1024" class="field w-full rounded-lg border px-3 py-2 text-sm" value="<?php echo (int)($edit['max_file_size'] ?? 10485760); ?>">
          </div>
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">Allowed extensions (comma)</label>
            <input type="text" name="allowed_extensions" class="field w-full rounded-lg border px-3 py-2 text-sm" value="<?php echo h($edit['allowed_extensions'] ?? 'pdf,doc,docx,png,jpg,jpeg'); ?>">
          </div>
          <div class="flex items-center gap-2">
            <input type="checkbox" name="is_open" id="op" value="1" <?php echo !isset($edit['is_open']) || !empty($edit['is_open']) ? 'checked' : ''; ?>>
            <label for="op" class="text-sm font-medium">Open for submissions</label>
          </div>
          <button type="submit" class="save-btn inline-flex items-center gap-2 px-4 py-2 font-semibold bg-green-600 text-white hover:bg-green-700 transition">Save</button>
          <?php if ($edit): ?><a href="professor_upload_tasks.php" class="ml-3 text-sm font-semibold text-green-700 hover:underline">Cancel</a><?php endif; ?>
        </form>
      </div>

      <div class="panel-card dash-anim delay-3">
        <table class="w-full text-sm text-left">
          <thead class="table-head border-b border-green-100">
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
              <tr class="table-row">
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
