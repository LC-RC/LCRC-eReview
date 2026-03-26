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
  <style>
    .prof-page { background: linear-gradient(180deg, #eefaf3 0%, #e6f6ee 45%, #edf9f2 100%); min-height: 100%; }
    .dashboard-shell { padding-bottom: 1.5rem; color: #0f172a; }
    .prof-hero {
      border-radius: 0.75rem; border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #0f766e 0%, #0e9f6e 35%, #16a34a 75%, #15803d 100%);
      box-shadow: 0 14px 34px -20px rgba(5,46,22,.75), inset 0 1px 0 rgba(255,255,255,.22);
    }
    .prof-icon { background: rgba(255,255,255,.22); border: 1px solid rgba(255,255,255,.34); color: #fff; }
    .prof-btn { border-radius: 9999px; transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease; }
    .prof-btn:hover { transform: translateY(-2px); box-shadow: 0 14px 24px -20px rgba(21,128,61,.85); }
    .section-title {
      display: flex; align-items: center; gap: .5rem; margin: 0 0 .85rem; padding: .45rem .65rem;
      border: 1px solid #d1fae5; border-radius: .62rem; background: linear-gradient(180deg,#f5fff9 0%,#fff 100%);
      color: #14532d; font-size: 1.03rem; font-weight: 800;
    }
    .section-title i {
      width: 1.55rem; height: 1.55rem; border-radius: .45rem; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #bbf7d0; background: #ecfdf3; color: #15803d; font-size: .83rem;
    }
    .table-card {
      border-radius: .75rem; border: 1px solid rgba(22,163,74,.22); overflow: hidden;
      background: linear-gradient(180deg, #f4fff8 0%, #fff 40%);
      box-shadow: 0 10px 28px -22px rgba(21,128,61,.58), 0 1px 0 rgba(255,255,255,.8) inset;
      transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .table-card:hover { transform: translateY(-2px); border-color: rgba(22,163,74,.38); box-shadow: 0 20px 34px -24px rgba(15,118,110,.4); }
    .table-head { background: linear-gradient(180deg, #edfff4 0%, #f6fff9 100%); }
    .table-head th { font-size: .78rem; text-transform: uppercase; letter-spacing: .01em; font-weight: 800; color: #166534; }
    .table-row { transition: background-color .2s ease; }
    .table-row:hover { background: #f4fff8; }
    .action-link { font-weight: 700; transition: color .2s ease; }
    .action-link:hover { color: #14532d; }
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
              <i class="bi bi-journal-text text-xl"></i>
            </div>
            <div>
              <h1 class="text-2xl font-bold text-white m-0 leading-tight">Exams &amp; quizzes</h1>
              <p class="text-white/90 mt-1 mb-0">Create timed assessments for college students.</p>
            </div>
          </div>
          <a href="professor_exam_edit.php" class="prof-btn inline-flex items-center gap-2 px-4 py-2.5 font-semibold bg-white text-green-800 hover:bg-green-50 shadow-sm">
            <i class="bi bi-plus-lg"></i> New exam
          </a>
        </div>
      </div>
    </div>

    <?php if ($msg): ?><div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 dash-anim delay-2"><?php echo h($msg); ?></div><?php endif; ?>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-list-check"></i> Exam Library</h2>
    <div class="table-card dash-anim delay-3">
      <table class="w-full text-sm text-left">
        <thead class="table-head border-b border-green-100">
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
            <tr class="table-row">
              <td class="px-4 py-3 font-medium"><?php echo h($e['title']); ?></td>
              <td class="px-4 py-3 hidden sm:table-cell"><?php echo (int)$e['q_count']; ?></td>
              <td class="px-4 py-3"><?php echo !empty($e['is_published']) ? 'Yes' : 'No'; ?></td>
              <td class="px-4 py-3 text-gray-600 hidden md:table-cell"><?php echo $e['deadline'] ? h(date('M j, Y g:i A', strtotime($e['deadline']))) : '—'; ?></td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <a class="action-link text-green-700 hover:underline mr-3" href="professor_exam_edit.php?id=<?php echo (int)$e['exam_id']; ?>">Edit</a>
                <form method="post" class="inline" onsubmit="return confirm('Delete this exam?');">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="exam_id" value="<?php echo (int)$e['exam_id']; ?>">
                  <button type="submit" class="action-link text-red-600 hover:underline">Delete</button>
                </form>
                <form method="post" class="inline ml-2">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                  <input type="hidden" name="action" value="toggle_publish">
                  <input type="hidden" name="exam_id" value="<?php echo (int)$e['exam_id']; ?>">
                  <button type="submit" class="action-link text-gray-700 hover:underline"><?php echo !empty($e['is_published']) ? 'Unpublish' : 'Publish'; ?></button>
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
