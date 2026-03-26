<?php
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'Exams';
$uid = getCurrentUserId();
$now = date('Y-m-d H:i:s');

$list = [];
$q = @mysqli_query($conn, "
  SELECT e.*,
    a.status AS attempt_status,
    a.score,
    a.correct_count,
    a.total_count,
    a.submitted_at
  FROM college_exams e
  LEFT JOIN college_exam_attempts a ON a.exam_id=e.exam_id AND a.user_id=" . (int)$uid . "
  WHERE e.is_published=1
  ORDER BY e.deadline IS NULL, e.deadline ASC, e.title ASC
");
if ($q) {
    while ($row = mysqli_fetch_assoc($q)) {
        $list[] = $row;
    }
    mysqli_free_result($q);
}

function college_exam_available(array $e, string $now): bool {
    if (empty($e['is_published'])) {
        return false;
    }
    if (!empty($e['available_from']) && $e['available_from'] > $now) {
        return false;
    }
    if (!empty($e['deadline']) && $e['deadline'] < $now) {
        return false;
    }
    return true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .college-page-shell {
      width: 100%;
      margin-left: 0;
      margin-right: 0;
      max-width: none;
      padding: 0 0 2rem;
    }
    @media (min-width: 1024px) { .college-page-shell { padding: 0 0 2rem; } }
    @media (min-width: 1280px) { .college-page-shell { padding: 0 0 2rem; } }
    @media (min-width: 1536px) { .college-page-shell { padding: 0 0 2rem; } }

    .cstu-hero {
      border-radius: 0.75rem;
      border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
      box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22);
    }
    .cstu-hero-btn {
      border-radius: 9999px;
      transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
    }
    .cstu-hero-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 22px -20px rgba(14, 64, 105, .95);
    }
    .section-title {
      display: flex; align-items: center; gap: .5rem;
      margin: 0 0 .85rem; padding: .45rem .65rem;
      border: 1px solid #d8e8f6; border-radius: .62rem;
      background: linear-gradient(180deg,#f4f9fe 0%,#fff 100%);
      color: #143D59; font-size: 1.03rem; font-weight: 800;
      position: relative; overflow: hidden;
    }
    .section-title::after {
      content: "";
      position: absolute;
      left: 0.62rem;
      bottom: 0.36rem;
      width: 38px;
      height: 2px;
      border-radius: 9999px;
      background: linear-gradient(90deg, #1665A0 0%, rgba(22,101,160,0.12) 100%);
    }
    .section-title i {
      width: 1.55rem; height: 1.55rem; border-radius: .45rem;
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #b9daf2; background: #e8f2fa; color: #1665A0; font-size: .83rem;
    }
    .dash-card {
      border-radius: .75rem;
      border: 1px solid rgba(22,101,160,.18);
      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 60%);
      box-shadow: 0 10px 28px -22px rgba(20,61,89,.55), 0 1px 0 rgba(255,255,255,.85) inset;
      transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease, background-color .22s ease;
    }
    .dash-card:hover {
      transform: translateY(-2px);
      border-color: rgba(22,101,160,.32);
      background-color: #fdfeff;
      box-shadow: 0 20px 34px -24px rgba(20,61,89,.35);
    }
    .table-shell table { min-width: 720px; }
    .table-head {
      background: linear-gradient(180deg,#edf6ff 0%,#f7fbff 100%);
      color: #143D59;
      border-bottom: 1px solid #d6e8f7;
    }
    .table-head th {
      letter-spacing: .01em;
      font-size: .79rem;
      text-transform: uppercase;
      font-weight: 800;
    }
    .table-row { transition: background-color .2s ease, transform .2s ease; }
    .table-row:hover { background: #f7fbff; }
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .2rem .55rem;
      border-radius: 9999px;
      font-size: .72rem;
      font-weight: 700;
      border: 1px solid transparent;
    }
    .status-done { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
    .status-expired { color: #b45309; background: #fffbeb; border-color: #fde68a; }
    .status-progress { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe; }
    .status-closed { color: #64748b; background: #f8fafc; border-color: #e2e8f0; }
    .action-btn {
      display: inline-flex; align-items: center; gap: .35rem;
      padding: .38rem .72rem; border-radius: .55rem; font-size: .8rem; font-weight: 700;
      transition: all .2s ease;
      border: 1px solid #cde2f4; color: #1665A0; background: #fff;
    }
    .action-btn:hover { transform: translateY(-1px); background: #f4f9fe; border-color: #8fc0e8; }
    .action-btn.is-primary { background: #1665A0; color: #fff; border-color: #1665A0; }
    .action-btn.is-primary:hover { background: #145a8f; border-color: #145a8f; }
    .empty-state {
      color: #64748b;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 2rem 1rem;
    }
    .empty-state i {
      color: #1665A0;
      background: #eef6ff;
      border: 1px solid #cde2f4;
      border-radius: 9999px;
      padding: .7rem;
      font-size: 1.1rem;
      margin-bottom: .65rem;
    }
    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
      .dash-card, .table-row, .action-btn, .cstu-hero-btn { transition: none !important; }
    }
  </style>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="college-page-shell pt-2">
    <section class="cstu-hero dash-anim delay-1 relative overflow-hidden mb-6 px-6 py-7">
      <div class="relative z-10 text-white">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3">
              <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 border border-white/30"><i class="bi bi-journal-text"></i></span>
              Quizzes &amp; exams
            </h1>
            <p class="text-white/90 mt-2 mb-0 max-w-2xl">Open assessments, status, and quick actions in one view.</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <a href="college_student_dashboard.php" class="cstu-hero-btn inline-flex items-center gap-2 px-4 py-2.5 bg-white text-[#145a8f] font-semibold">
              <i class="bi bi-house-door"></i> Dashboard
            </a>
            <a href="college_uploads.php" class="cstu-hero-btn inline-flex items-center gap-2 px-4 py-2.5 border border-white/35 bg-white/10 text-white font-semibold">
              <i class="bi bi-cloud-upload"></i> Upload tasks
            </a>
          </div>
        </div>
      </div>
    </section>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-list-check"></i> Exam Activity</h2>
    <div class="dash-card dash-anim delay-2 overflow-hidden">
      <div class="table-shell overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="table-head">
            <tr>
              <th class="px-5 py-3">Title</th>
              <th class="px-5 py-3 hidden sm:table-cell">Deadline</th>
              <th class="px-5 py-3">Status</th>
              <th class="px-5 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[#e6eff8]">
            <?php if (empty($list)): ?>
            <tr>
              <td colspan="4">
                <div class="empty-state">
                  <i class="bi bi-journal-x"></i>
                  <p class="m-0 font-medium">No exams published yet.</p>
                  <p class="m-0 mt-1 text-sm">Check back later for new assessments from your instructor.</p>
                </div>
              </td>
            </tr>
            <?php else: ?>
              <?php foreach ($list as $e):
                $avail = college_exam_available($e, $now);
                $st = $e['attempt_status'] ?? '';
              ?>
              <tr class="table-row">
                <td class="px-5 py-3.5 font-semibold text-slate-800"><?php echo h($e['title']); ?></td>
                <td class="px-5 py-3.5 text-slate-600 hidden sm:table-cell"><?php echo $e['deadline'] ? h(date('M j, Y g:i A', strtotime($e['deadline']))) : '—'; ?></td>
                <td class="px-5 py-3.5">
                  <?php if ($st === 'submitted'): ?>
                    <span class="status-pill status-done"><i class="bi bi-check-circle"></i> Done<?php echo $e['score'] !== null ? ' · ' . h($e['score']) . '%' : ''; ?></span>
                  <?php elseif ($st === 'expired'): ?>
                    <span class="status-pill status-expired"><i class="bi bi-hourglass-split"></i> Expired</span>
                  <?php elseif ($st === 'in_progress'): ?>
                    <span class="status-pill status-progress"><i class="bi bi-play-circle"></i> In progress</span>
                  <?php elseif (!$avail): ?>
                    <span class="status-pill status-closed"><i class="bi bi-lock"></i> Closed</span>
                  <?php else: ?>
                    <span class="status-pill status-closed"><i class="bi bi-dash-circle"></i> Not started</span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                  <?php if ($st === 'submitted'): ?>
                    <a class="action-btn" href="college_take_exam.php?exam_id=<?php echo (int)$e['exam_id']; ?>&review=1">
                      <i class="bi bi-eye"></i> Review
                    </a>
                  <?php elseif ($avail && $st !== 'expired'): ?>
                    <a class="action-btn is-primary" href="college_take_exam.php?exam_id=<?php echo (int)$e['exam_id']; ?>">
                      <i class="bi bi-arrow-right-circle"></i> <?php echo $st === 'in_progress' ? 'Continue' : 'Start'; ?>
                    </a>
                  <?php else: ?>
                    <span class="text-gray-400">—</span>
                  <?php endif; ?>
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
