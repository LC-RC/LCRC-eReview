<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'Monitor';

$attempts = [];
$q = @mysqli_query($conn, "
  SELECT a.attempt_id, a.score, a.correct_count, a.total_count, a.submitted_at, a.status,
         u.full_name, u.email, e.title AS exam_title, e.exam_id
  FROM college_exam_attempts a
  INNER JOIN users u ON u.user_id=a.user_id AND u.role='college_student'
  INNER JOIN college_exams e ON e.exam_id=a.exam_id
  ORDER BY a.submitted_at DESC
  LIMIT 100
");
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $attempts[] = $r;
    }
    mysqli_free_result($q);
}

$subs = [];
$s = @mysqli_query($conn, "
  SELECT s.submission_id, s.file_path, s.file_name, s.submitted_at, s.status, u.full_name, u.email, t.title AS task_title, t.task_id
  FROM college_submissions s
  INNER JOIN users u ON u.user_id=s.user_id
  INNER JOIN college_upload_tasks t ON t.task_id=s.task_id
  ORDER BY s.submitted_at DESC
  LIMIT 100
");
if ($s) {
    while ($r = mysqli_fetch_assoc($s)) {
        $subs[] = $r;
    }
    mysqli_free_result($s);
}
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
    .file-link { font-weight: 700; }
    .dash-anim { opacity: 0; transform: translateY(12px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; }
    .delay-2 { animation-delay: .12s; }
    .delay-3 { animation-delay: .18s; }
    .delay-4 { animation-delay: .24s; }
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
              <i class="bi bi-graph-up text-xl"></i>
            </div>
            <div>
              <h1 class="text-2xl font-bold text-white m-0 leading-tight">Monitoring</h1>
              <p class="text-white/90 mt-1 mb-0">Exam participation and file submissions.</p>
            </div>
          </div>
          <div class="hidden sm:block"></div>
        </div>
      </div>
    </div>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-clipboard-check"></i> Exam Attempts</h2>
    <div class="table-card overflow-x-auto mb-10 dash-anim delay-3">
      <table class="w-full text-sm text-left min-w-[640px]">
        <thead class="table-head border-b border-green-100">
          <tr>
            <th class="px-4 py-3">Student</th>
            <th class="px-4 py-3">Email</th>
            <th class="px-4 py-3">Exam</th>
            <th class="px-4 py-3">Score</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3">Submitted</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-green-100">
          <?php if (empty($attempts)): ?>
          <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No data yet.</td></tr>
          <?php else: ?>
            <?php foreach ($attempts as $a): ?>
            <tr class="table-row">
              <td class="px-4 py-3 font-medium"><?php echo h($a['full_name']); ?></td>
              <td class="px-4 py-3 text-gray-600"><?php echo h($a['email']); ?></td>
              <td class="px-4 py-3"><?php echo h($a['exam_title']); ?></td>
              <td class="px-4 py-3"><?php echo $a['score'] !== null ? h((string)$a['score']) . '%' : '—'; ?></td>
              <td class="px-4 py-3"><?php echo h($a['status']); ?></td>
              <td class="px-4 py-3 text-gray-600"><?php echo $a['submitted_at'] ? h(date('M j, g:i A', strtotime($a['submitted_at']))) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <h2 class="section-title dash-anim delay-3"><i class="bi bi-folder2-open"></i> File Submissions</h2>
    <div class="table-card overflow-x-auto dash-anim delay-4">
      <table class="w-full text-sm text-left min-w-[640px]">
        <thead class="table-head border-b border-green-100">
          <tr>
            <th class="px-4 py-3">Student</th>
            <th class="px-4 py-3">Email</th>
            <th class="px-4 py-3">Task</th>
            <th class="px-4 py-3">File</th>
            <th class="px-4 py-3">Submitted</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-green-100">
          <?php if (empty($subs)): ?>
          <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No submissions yet.</td></tr>
          <?php else: ?>
            <?php foreach ($subs as $s): ?>
            <tr class="table-row">
              <td class="px-4 py-3 font-medium"><?php echo h($s['full_name']); ?></td>
              <td class="px-4 py-3 text-gray-600"><?php echo h($s['email']); ?></td>
              <td class="px-4 py-3"><?php echo h($s['task_title']); ?></td>
              <td class="px-4 py-3">
                <?php if (!empty($s['file_name']) && !empty($s['submission_id'])): ?>
                  <a href="<?php echo h($s['file_path'] ?? ''); ?>" class="file-link text-green-700 hover:underline" target="_blank" rel="noopener"><?php echo h($s['file_name']); ?></a>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-600"><?php echo h(date('M j, g:i A', strtotime($s['submitted_at']))); ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</body>
</html>
