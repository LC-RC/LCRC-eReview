<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_upload_helpers.php';

$pageTitle = 'Upload tasks';
$uid = getCurrentUserId();
$csrf = generateCSRFToken();
$allowedCsv = college_upload_allowed_extensions_csv();

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
        $chk = mysqli_prepare($conn, 'SELECT task_id FROM college_upload_tasks WHERE task_id=? AND created_by=? LIMIT 1');
        mysqli_stmt_bind_param($chk, 'ii', $tid, $uid);
        mysqli_stmt_execute($chk);
        $ok = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        mysqli_stmt_close($chk);
        if ($ok) {
            college_upload_delete_task_files($conn, $tid, __DIR__);
            mysqli_query($conn, 'DELETE FROM college_submissions WHERE task_id=' . (int)$tid);
            mysqli_query($conn, 'DELETE FROM college_upload_tasks WHERE task_id=' . (int)$tid . ' AND created_by=' . (int)$uid);
            $_SESSION['message'] = 'Task and related submissions removed.';
        }
    } elseif ($action === 'save') {
        $title = trim($_POST['title'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        $deadline = trim($_POST['deadline'] ?? '');
        $maxPreset = trim($_POST['max_file_preset'] ?? '10485760');
        $maxCustom = max(1024, sanitizeInt($_POST['max_file_size'] ?? 10485760));
        $maxSize = in_array($maxPreset, ['5242880', '10485760', '20971520', '52428800'], true)
            ? (int)$maxPreset
            : $maxCustom;
        $maxSize = max(1024, min($maxSize, 52428800));
        $isOpen = !empty($_POST['is_open']) ? 1 : 0;

        if ($title === '' || $deadline === '') {
            $_SESSION['error'] = 'Title and deadline are required.';
        } else {
            $deadTs = strtotime($deadline);
            if ($deadTs === false) {
                $_SESSION['error'] = 'Invalid deadline.';
            } else {
                $deadSql = date('Y-m-d H:i:s', $deadTs);
                if ($tid > 0) {
                    $stmt = mysqli_prepare($conn, 'UPDATE college_upload_tasks SET title=?, instructions=?, deadline=?, max_file_size=?, allowed_extensions=?, is_open=? WHERE task_id=? AND created_by=?');
                    mysqli_stmt_bind_param($stmt, 'sssisiii', $title, $instructions, $deadSql, $maxSize, $allowedCsv, $isOpen, $tid, $uid);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                } else {
                    $stmt = mysqli_prepare($conn, 'INSERT INTO college_upload_tasks (title, instructions, deadline, max_file_size, allowed_extensions, is_open, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    mysqli_stmt_bind_param($stmt, 'sssisii', $title, $instructions, $deadSql, $maxSize, $allowedCsv, $isOpen, $uid);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                $_SESSION['message'] = 'Task saved. Students see open tasks on Uploads immediately.';
            }
        }
    }
    header('Location: professor_upload_tasks.php');
    exit;
}

$list = [];
$q = mysqli_query($conn, '
  SELECT t.*,
    (SELECT COUNT(*) FROM college_submissions s WHERE s.task_id = t.task_id) AS submission_count
  FROM college_upload_tasks t
  WHERE t.created_by=' . (int)$uid . '
  ORDER BY t.deadline DESC
');
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $list[] = $r;
    }
    mysqli_free_result($q);
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = sanitizeInt($_GET['edit']);
    $stmt = mysqli_prepare($conn, 'SELECT * FROM college_upload_tasks WHERE task_id=? AND created_by=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $eid, $uid);
    mysqli_stmt_execute($stmt);
    $edit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

$msg = $_SESSION['message'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['message'], $_SESSION['error']);

$totalTasks = count($list);
$openTasks = 0;
$upcomingDeadlines = 0;
$totalSubs = 0;
foreach ($list as $row) {
    if (!empty($row['is_open'])) {
        $openTasks++;
    }
    if (!empty($row['deadline']) && !college_upload_deadline_has_passed($row['deadline'])) {
        $upcomingDeadlines++;
    }
    $totalSubs += (int)($row['submission_count'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .prof-page { background: linear-gradient(180deg, #ecfdf5 0%, #f0fdf4 38%, #f8fafc 100%); min-height: 100%; }
    .dashboard-shell { padding-bottom: 2rem; color: #0f172a; max-width: 1400px; margin: 0 auto; }
    .put-hero {
      border-radius: 1rem;
      border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(125deg, #0f766e 0%, #059669 42%, #10b981 78%, #047857 100%);
      box-shadow: 0 20px 50px -28px rgba(5, 46, 22, 0.55), inset 0 1px 0 rgba(255,255,255,0.2);
      position: relative;
      overflow: hidden;
    }
    .put-hero::after {
      content: "";
      position: absolute;
      right: -20%;
      top: -40%;
      width: 55%;
      height: 140%;
      background: radial-gradient(circle, rgba(255,255,255,0.14) 0%, transparent 70%);
      pointer-events: none;
    }
    .put-hero-icon {
      width: 3.25rem; height: 3.25rem;
      border-radius: 1rem;
      background: rgba(255,255,255,0.2);
      border: 1px solid rgba(255,255,255,0.35);
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: 1.5rem;
    }
    .put-stat-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.65rem;
      margin-top: 1rem;
    }
    @media (max-width: 640px) { .put-stat-grid { grid-template-columns: 1fr; } }
    .put-stat {
      border-radius: 0.75rem;
      border: 1px solid rgba(255,255,255,0.25);
      background: rgba(255,255,255,0.12);
      padding: 0.65rem 0.85rem;
      backdrop-filter: blur(6px);
    }
    .put-stat-k { font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(255,255,255,0.75); }
    .put-stat-v { font-size: 1.35rem; font-weight: 900; color: #fff; margin-top: 0.15rem; }
    .put-card {
      border-radius: 1rem;
      border: 1px solid rgba(16, 185, 129, 0.2);
      background: linear-gradient(180deg, #ffffff 0%, #f8fffb 100%);
      box-shadow: 0 16px 40px -28px rgba(15, 118, 110, 0.45), 0 1px 0 rgba(255,255,255,0.9) inset;
    }
    .put-card-head {
      padding: 1rem 1.25rem;
      border-bottom: 1px solid rgba(16, 185, 129, 0.12);
      background: linear-gradient(180deg, rgba(236, 253, 245, 0.9) 0%, transparent 100%);
    }
    .put-section-title {
      display: flex; align-items: center; gap: 0.5rem;
      font-size: 1.05rem; font-weight: 900; color: #14532d; margin: 0;
    }
    .put-section-title i {
      width: 2rem; height: 2rem; border-radius: 0.55rem;
      display: inline-flex; align-items: center; justify-content: center;
      background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; font-size: 0.95rem;
    }
    .put-field {
      border: 1px solid #bbf7d0;
      border-radius: 0.65rem;
      padding: 0.55rem 0.75rem;
      font-size: 0.9rem;
      width: 100%;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .put-field:focus {
      outline: none;
      border-color: #22c55e;
      box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
    }
    .put-label { font-size: 0.78rem; font-weight: 800; color: #365314; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; display: block; }
    .put-hint { font-size: 0.78rem; color: #64748b; margin-top: 0.35rem; line-height: 1.45; }
    .put-badge-file {
      display: inline-flex; align-items: center; gap: 0.35rem;
      flex-wrap: wrap;
      padding: 0.45rem 0.65rem;
      border-radius: 0.55rem;
      background: #ecfdf5;
      border: 1px dashed #6ee7b7;
      font-size: 0.78rem;
      font-weight: 700;
      color: #047857;
    }
    .put-save {
      display: inline-flex; align-items: center; gap: 0.45rem;
      padding: 0.65rem 1.25rem;
      border-radius: 0.7rem;
      font-weight: 800;
      font-size: 0.9rem;
      border: 1px solid #059669;
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: #fff;
      box-shadow: 0 12px 24px -16px rgba(5, 150, 105, 0.9);
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .put-save:hover { transform: translateY(-1px); box-shadow: 0 16px 28px -16px rgba(5, 150, 105, 0.95); }
    .put-table-wrap { overflow-x: auto; }
    .put-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    .put-table th {
      text-align: left;
      padding: 0.75rem 1rem;
      font-size: 0.68rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: #166534;
      background: linear-gradient(180deg, #ecfdf5 0%, #f0fdf4 100%);
      border-bottom: 1px solid #bbf7d0;
    }
    .put-table td { padding: 0.85rem 1rem; border-bottom: 1px solid #ecfdf5; vertical-align: middle; }
    .put-table tr:hover td { background: #f7fef9; }
    .put-pill {
      display: inline-flex; align-items: center; gap: 0.25rem;
      padding: 0.2rem 0.55rem;
      border-radius: 999px;
      font-size: 0.72rem;
      font-weight: 800;
      border: 1px solid transparent;
    }
    .put-pill-live { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
    .put-pill-off { background: #f8fafc; color: #64748b; border-color: #e2e8f0; }
    .put-pill-late { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }
    .put-link { font-weight: 800; color: #047857; text-decoration: none; }
    .put-link:hover { text-decoration: underline; }
    .put-danger { font-weight: 800; color: #b91c1c; background: none; border: none; cursor: pointer; padding: 0; }
    .put-danger:hover { text-decoration: underline; }
    .dash-anim { opacity: 0; transform: translateY(12px); animation: putFadeUp 0.55s ease-out forwards; }
    .delay-1 { animation-delay: 0.04s; }
    .delay-2 { animation-delay: 0.1s; }
    .delay-3 { animation-delay: 0.16s; }
    @keyframes putFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
    }
  </style>
</head>
<body class="font-sans antialiased prof-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <div class="dashboard-shell w-full max-w-none px-4 sm:px-5 lg:px-6">
    <div class="put-hero mb-8 dash-anim delay-1">
      <div class="p-6 sm:p-7 relative z-[1]">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
          <div class="flex items-start gap-4">
            <div class="put-hero-icon shrink-0"><i class="bi bi-cloud-arrow-up"></i></div>
            <div>
              <h1 class="text-2xl sm:text-3xl font-black text-white m-0 tracking-tight">Assignment uploads</h1>
              <p class="text-white/90 mt-2 mb-0 max-w-xl text-sm sm:text-base leading-relaxed">
                Publish tasks here — they appear instantly on <strong class="text-white">College → Uploads</strong> for every student. Files are limited to <strong class="text-white"><?php echo h(college_upload_allowed_types_label()); ?></strong> only.
              </p>
            </div>
          </div>
          <div class="put-stat-grid lg:min-w-[280px]">
            <div class="put-stat">
              <div class="put-stat-k">Open tasks</div>
              <div class="put-stat-v"><?php echo (int)$openTasks; ?></div>
            </div>
            <div class="put-stat">
              <div class="put-stat-k">Submissions</div>
              <div class="put-stat-v"><?php echo (int)$totalSubs; ?></div>
            </div>
            <div class="put-stat">
              <div class="put-stat-k">Upcoming</div>
              <div class="put-stat-v"><?php echo (int)$upcomingDeadlines; ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($msg): ?>
      <div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 font-semibold text-sm dash-anim delay-2"><?php echo h($msg); ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-900 font-semibold text-sm dash-anim delay-2"><?php echo h($err); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">
      <div class="xl:col-span-5 put-card dash-anim delay-2 overflow-hidden">
        <div class="put-card-head">
          <h2 class="put-section-title"><i class="bi bi-pencil-square"></i> <?php echo $edit ? 'Edit task' : 'New task'; ?></h2>
          <p class="text-xs text-slate-500 mt-2 mb-0">Required: title &amp; deadline. Students upload on their Uploads page.</p>
        </div>
        <div class="p-5 sm:p-6 space-y-4">
          <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="task_id" value="<?php echo $edit ? (int)$edit['task_id'] : 0; ?>">

            <div>
              <label class="put-label" for="put-title">Title</label>
              <input id="put-title" type="text" name="title" required class="put-field" placeholder="e.g. Week 3 proof of payment" value="<?php echo h($edit['title'] ?? ''); ?>">
            </div>
            <div>
              <label class="put-label" for="put-inst">Instructions</label>
              <textarea id="put-inst" name="instructions" rows="4" class="put-field resize-y min-h-[100px]" placeholder="What should students upload? Naming rules, etc."><?php echo h($edit['instructions'] ?? ''); ?></textarea>
            </div>
            <div>
              <label class="put-label" for="put-dead">Deadline</label>
              <input id="put-dead" type="datetime-local" name="deadline" required class="put-field"
                value="<?php echo $edit && !empty($edit['deadline']) ? h(date('Y-m-d\TH:i', strtotime($edit['deadline']))) : ''; ?>">
            </div>
            <div>
              <label class="put-label" for="put-preset">Max file size</label>
              <?php
                $curMax = (int)($edit['max_file_size'] ?? 10485760);
                $presetMatch = in_array($curMax, [5242880, 10485760, 20971520, 52428800], true) ? (string)$curMax : 'custom';
              ?>
              <select id="put-preset" name="max_file_preset" class="put-field put-size-preset">
                <option value="5242880" <?php echo $presetMatch === '5242880' ? 'selected' : ''; ?>>5 MB</option>
                <option value="10485760" <?php echo $presetMatch === '10485760' ? 'selected' : ''; ?>>10 MB</option>
                <option value="20971520" <?php echo $presetMatch === '20971520' ? 'selected' : ''; ?>>20 MB</option>
                <option value="52428800" <?php echo $presetMatch === '52428800' ? 'selected' : ''; ?>>50 MB</option>
                <option value="custom" <?php echo $presetMatch === 'custom' ? 'selected' : ''; ?>>Custom (bytes)</option>
              </select>
              <div class="put-custom-size mt-2 <?php echo $presetMatch === 'custom' ? '' : 'hidden'; ?>">
                <input type="number" name="max_file_size" min="1024" max="52428800" class="put-field" value="<?php echo (int)$curMax; ?>">
              </div>
              <p class="put-hint">Hard cap 50 MB. Students cannot upload other file types (enforced server-side).</p>
            </div>
            <div>
              <span class="put-label">Allowed file types</span>
              <div class="put-badge-file">
                <i class="bi bi-file-earmark-pdf"></i> PDF
                <i class="bi bi-image ms-1"></i> JPG · PNG
              </div>
              <p class="put-hint m-0">Fixed policy — Word, Excel, zip, and other types are rejected.</p>
            </div>
            <div class="flex items-center gap-3 pt-1">
              <input type="checkbox" name="is_open" id="put-open" value="1" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" <?php echo !isset($edit['is_open']) || !empty($edit['is_open']) ? 'checked' : ''; ?>>
              <label for="put-open" class="text-sm font-semibold text-slate-700 cursor-pointer">Open for student submissions</label>
            </div>
            <div class="flex flex-wrap items-center gap-3 pt-2">
              <button type="submit" class="put-save"><i class="bi bi-check2-circle"></i> <?php echo $edit ? 'Update task' : 'Publish task'; ?></button>
              <?php if ($edit): ?>
                <a href="professor_upload_tasks.php" class="text-sm font-bold text-slate-500 hover:text-slate-800">Cancel edit</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <div class="xl:col-span-7 put-card dash-anim delay-3 overflow-hidden">
        <div class="put-card-head flex flex-wrap items-center justify-between gap-3">
          <h2 class="put-section-title m-0"><i class="bi bi-kanban"></i> Your tasks</h2>
          <span class="text-xs font-bold text-slate-400 uppercase tracking-wider"><?php echo (int)$totalTasks; ?> total</span>
        </div>
        <div class="put-table-wrap">
          <?php if (empty($list)): ?>
            <div class="p-12 text-center">
              <div class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 border border-emerald-100 text-emerald-600 text-2xl mb-4">
                <i class="bi bi-inbox"></i>
              </div>
              <p class="text-slate-600 font-semibold m-0">No tasks yet</p>
              <p class="text-sm text-slate-500 mt-1 mb-0">Create one on the left — it will show up for all college students.</p>
            </div>
          <?php else: ?>
            <table class="put-table">
              <thead>
                <tr>
                  <th>Task</th>
                  <th class="hidden md:table-cell">Deadline</th>
                  <th class="hidden sm:table-cell">Files</th>
                  <th class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($list as $t):
                  $isOpen = !empty($t['is_open']);
                  $past = college_upload_deadline_has_passed($t['deadline'] ?? null);
                  $subc = (int)($t['submission_count'] ?? 0);
                  ?>
                  <tr>
                    <td>
                      <a href="professor_upload_task_monitor.php?task_id=<?php echo (int)$t['task_id']; ?>" class="block group">
                        <div class="font-bold text-slate-900 group-hover:text-emerald-800 transition-colors"><?php echo h($t['title']); ?></div>
                      </a>
                      <div class="flex flex-wrap gap-1.5 mt-1.5">
                        <?php if (!$isOpen): ?>
                          <span class="put-pill put-pill-off"><i class="bi bi-lock"></i> Closed to students</span>
                        <?php elseif ($past): ?>
                          <span class="put-pill put-pill-late"><i class="bi bi-calendar-x"></i> Past due</span>
                        <?php else: ?>
                          <span class="put-pill put-pill-live"><i class="bi bi-unlock"></i> Live</span>
                        <?php endif; ?>
                      </div>
                      <div class="md:hidden text-xs text-slate-500 mt-2">
                        <?php echo h(date('M j, Y g:i A', strtotime($t['deadline']))); ?>
                      </div>
                    </td>
                    <td class="hidden md:table-cell text-slate-600 whitespace-nowrap">
                      <?php echo h(date('M j, Y g:i A', strtotime($t['deadline']))); ?>
                    </td>
                    <td class="hidden sm:table-cell">
                      <?php if ($subc > 0): ?>
                        <a href="professor_upload_task_monitor.php?task_id=<?php echo (int)$t['task_id']; ?>" class="font-bold text-emerald-800 hover:text-emerald-950 hover:underline">
                          <?php echo (int)$subc; ?> <span class="text-slate-400 text-xs font-semibold">submissions</span>
                        </a>
                      <?php else: ?>
                        <span class="font-bold text-emerald-800"><?php echo (int)$subc; ?></span>
                        <span class="text-slate-400 text-xs font-semibold"> submissions</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-right whitespace-nowrap">
                      <a href="professor_upload_task_monitor.php?task_id=<?php echo (int)$t['task_id']; ?>" class="put-link mr-3">Monitor</a>
                      <a href="professor_upload_tasks.php?edit=<?php echo (int)$t['task_id']; ?>" class="put-link mr-3">Edit</a>
                      <form method="post" class="inline" onsubmit="return confirm('Delete this task and all <?php echo (int)$subc; ?> submission record(s)? Files on disk will be removed.');">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="task_id" value="<?php echo (int)$t['task_id']; ?>">
                        <button type="submit" class="put-danger">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  </div>
  </main>
  <script>
  (function () {
    var sel = document.querySelector('.put-size-preset');
    var custom = document.querySelector('.put-custom-size');
    if (!sel || !custom) return;
    function sync() {
      custom.classList.toggle('hidden', sel.value !== 'custom');
    }
    sel.addEventListener('change', sync);
    sync();
  })();
  </script>
</body>
</html>
