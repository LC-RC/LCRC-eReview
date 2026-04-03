<?php
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_upload_helpers.php';

$pageTitle = 'Uploads';
$uid = getCurrentUserId();
$allowedTypesLabel = college_upload_allowed_types_label();

$tasks = [];
$tq = mysqli_query($conn, '
  SELECT
    t.task_id, t.title, t.instructions, t.deadline, t.max_file_size, t.allowed_extensions, t.is_open, t.created_by, t.created_at,
    s.submission_id, s.file_name AS submitted_file, s.submitted_at AS submitted_at
  FROM college_upload_tasks t
  LEFT JOIN college_submissions s ON s.task_id = t.task_id AND s.user_id = ' . (int)$uid . '
  WHERE t.is_open = 1
  ORDER BY t.deadline ASC
');
if ($tq) {
    while ($row = mysqli_fetch_assoc($tq)) {
        $tasks[] = $row;
    }
    mysqli_free_result($tq);
}

$msg = $_SESSION['message'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['message'], $_SESSION['error']);

$taskCount = count($tasks);
$openCount = 0;
$submittedCount = 0;
$dueSoon = 0;
$soonTs = strtotime('+72 hours');
foreach ($tasks as $tr) {
    if (college_upload_deadline_allows_upload($tr['deadline'] ?? null)) {
        $openCount++;
        $dTs = college_upload_deadline_to_timestamp($tr['deadline'] ?? null);
        if ($dTs !== false && $dTs <= $soonTs) {
            $dueSoon++;
        }
    }
    if (!empty($tr['submission_id'])) {
        $submittedCount++;
    }
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
      padding: 0 0 1.75rem;
    }

    .cu-hero-wrap {
      width: 100%;
      margin-bottom: 1.25rem;
    }
    .cu-hero {
      width: 100%;
      border-radius: 0.85rem;
      border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(125deg, #155a94 0%, #124a78 42%, #0f3550 100%);
      box-shadow: 0 16px 40px -24px rgba(12, 45, 72, 0.88), inset 0 1px 0 rgba(255,255,255,0.2);
      position: relative;
      overflow: hidden;
    }
    .cu-hero::before {
      content: "";
      position: absolute;
      inset: -40% 40% auto -20%;
      height: 120%;
      background: radial-gradient(ellipse at center, rgba(120, 200, 255, 0.18) 0%, transparent 65%);
      pointer-events: none;
    }
    .cu-hero-inner {
      position: relative;
      z-index: 1;
      width: 100%;
      padding: 1.25rem 1.25rem 1.35rem;
    }
    @media (min-width: 640px) {
      .cu-hero-inner { padding: 1.35rem 1.5rem 1.5rem; }
    }
    @media (min-width: 1024px) {
      .cu-hero-inner { padding: 1.5rem 1.75rem 1.65rem; }
    }
    .cu-hero-btn {
      border-radius: 9999px;
      transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease, border-color .2s ease;
    }
    .cu-hero-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 14px 28px -18px rgba(0, 24, 48, 0.65);
    }

    .cu-hero-grid {
      display: flex;
      flex-direction: column;
      gap: 1.15rem;
      width: 100%;
    }
    @media (min-width: 1024px) {
      .cu-hero-grid {
        flex-direction: row;
        align-items: stretch;
        justify-content: space-between;
        gap: 1.5rem;
      }
    }

    .cu-hero-main { min-width: 0; flex: 1; }
    .cu-hero-aside {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      flex-shrink: 0;
    }
    @media (min-width: 1024px) {
      .cu-hero-aside {
        flex-direction: row;
        align-items: stretch;
        gap: 1rem;
      }
    }

    .cu-stat-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.5rem;
      width: 100%;
    }
    @media (min-width: 1024px) {
      .cu-stat-grid { min-width: 15rem; max-width: 22rem; }
    }
    @media (max-width: 639px) { .cu-stat-grid { grid-template-columns: 1fr; } }
    .cu-stat {
      border-radius: 0.55rem;
      border: 1px solid rgba(255,255,255,0.22);
      background: rgba(255,255,255,0.1);
      backdrop-filter: blur(8px);
      padding: 0.5rem 0.6rem;
      text-align: center;
    }
    .cu-stat-k { font-size: 0.58rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(255,255,255,0.72); }
    .cu-stat-v { font-size: 1.15rem; font-weight: 900; color: #fff; line-height: 1.2; margin-top: 0.1rem; }

    .cu-hero-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      align-items: center;
    }
    @media (min-width: 1024px) {
      .cu-hero-actions { flex-direction: column; align-items: stretch; justify-content: center; }
    }

    .cu-body-pad {
      padding-left: 0.25rem;
      padding-right: 0.25rem;
    }
    @media (min-width: 640px) {
      .cu-body-pad { padding-left: 0.5rem; padding-right: 0.5rem; }
    }

    .cu-section-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin: 0 0 0.85rem;
    }
    .cu-section-title {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      margin: 0;
      font-size: 0.92rem;
      font-weight: 800;
      color: #0f3550;
    }
    .cu-section-title i {
      width: 1.65rem;
      height: 1.65rem;
      border-radius: 0.4rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(180deg, #e8f2fa 0%, #d4e9f8 100%);
      border: 1px solid #b9daf2;
      color: #1665a0;
      font-size: 0.8rem;
    }
    .cu-pill {
      font-size: 0.7rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      padding: 0.35rem 0.65rem;
      border-radius: 999px;
      border: 1px solid #cde2f4;
      background: #f4f9fe;
      color: #145a8f;
    }

    .cu-task-board {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(10.5rem, 1fr));
      gap: 0.75rem;
      width: 100%;
    }
    @media (min-width: 480px) {
      .cu-task-board { grid-template-columns: repeat(auto-fill, minmax(11.5rem, 1fr)); gap: 0.85rem; }
    }
    @media (min-width: 768px) {
      .cu-task-board { grid-template-columns: repeat(auto-fill, minmax(12.5rem, 1fr)); }
    }

    .cu-tile {
      display: flex;
      flex-direction: column;
      aspect-ratio: 1;
      border-radius: 0.85rem;
      border: 1px solid rgba(22, 101, 160, 0.16);
      background: linear-gradient(160deg, #fbfdff 0%, #ffffff 55%);
      box-shadow: 0 10px 28px -22px rgba(20, 61, 89, 0.42), 0 1px 0 rgba(255,255,255,0.9) inset;
      padding: 0.75rem 0.7rem 0.65rem 0.85rem;
      text-decoration: none;
      color: inherit;
      position: relative;
      overflow: hidden;
      transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .cu-tile::before {
      content: "";
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      width: 3px;
      background: linear-gradient(180deg, #1665a0 0%, #3d9ae8 100%);
      opacity: 0.9;
    }
    .cu-tile:hover {
      transform: translateY(-3px);
      border-color: rgba(22, 101, 160, 0.3);
      box-shadow: 0 18px 36px -22px rgba(20, 61, 89, 0.38);
    }
    .cu-tile:focus-visible {
      outline: 2px solid #1665a0;
      outline-offset: 2px;
    }
    .cu-tile--muted { opacity: 0.92; }
    .cu-tile-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.35rem;
      margin-bottom: 0.4rem;
    }
    .cu-tile-icon {
      width: 2rem;
      height: 2rem;
      border-radius: 0.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(180deg, #e8f2fa 0%, #d4e9f8 100%);
      border: 1px solid #b9daf2;
      color: #1665a0;
      font-size: 1rem;
      flex-shrink: 0;
    }
    .cu-tile-done {
      font-size: 0.65rem;
      font-weight: 800;
      color: #047857;
      background: #ecfdf5;
      border: 1px solid #a7f3d0;
      border-radius: 999px;
      padding: 0.15rem 0.45rem;
      white-space: nowrap;
    }
    .cu-tile-title {
      font-size: 0.78rem;
      font-weight: 800;
      color: #0f3550;
      line-height: 1.3;
      margin: 0 0 0.35rem;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .cu-tile-grow { flex: 1; min-height: 0; }
    .cu-tile-excerpt {
      font-size: 0.65rem;
      line-height: 1.45;
      color: #64748b;
      margin: 0;
      flex: 1;
      min-height: 0;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .cu-task-board .cu-empty { grid-column: 1 / -1; max-width: 24rem; margin-left: auto; margin-right: auto; }
    .cu-tile-foot {
      margin-top: auto;
      padding-top: 0.45rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.35rem;
    }
    .cu-tile-due {
      font-size: 0.6rem;
      font-weight: 800;
      padding: 0.2rem 0.45rem;
      border-radius: 999px;
      border: 1px solid transparent;
    }
    .cu-tile-due--open { color: #b45309; background: #fffbeb; border-color: #fde68a; }
    .cu-tile-due--closed { color: #64748b; background: #f8fafc; border-color: #e2e8f0; }
    .cu-tile-go {
      font-size: 0.75rem;
      color: #1665a0;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      gap: 0.15rem;
    }

    .cu-empty {
      border-radius: 0.85rem;
      border: 1px solid rgba(22, 101, 160, 0.14);
      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
      box-shadow: 0 12px 32px -24px rgba(20, 61, 89, 0.4);
      padding: 2rem 1.25rem;
      text-align: center;
      max-width: 24rem;
      margin-left: auto;
      margin-right: auto;
    }
    .cu-hint-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      border: 1px solid #cde2f4;
      border-radius: 999px;
      background: #fff;
      color: #1665a0;
      font-weight: 800;
      font-size: 0.82rem;
      padding: 0.55rem 1rem;
      transition: all .2s ease;
    }
    .cu-hint-btn:hover { background: #f4f9fe; border-color: #8fc0e8; transform: translateY(-1px); }

    .dash-anim { opacity: 0; transform: translateY(12px); animation: cuFadeUp 0.55s ease-out forwards; }
    .delay-1 { animation-delay: 0.04s; }
    .delay-2 { animation-delay: 0.1s; }
    .delay-3 { animation-delay: 0.16s; }
    @keyframes cuFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
      .cu-tile, .cu-hero-btn, .cu-hint-btn { transition: none !important; }
    }
  </style>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>

  <div class="college-page-shell ereview-shell-no-fade pt-2">
    <div class="cu-hero-wrap">
      <section class="cu-hero dash-anim delay-1">
        <div class="cu-hero-inner text-white">
          <div class="cu-hero-grid">
            <div class="cu-hero-main">
              <h1 class="text-xl sm:text-2xl lg:text-[1.65rem] font-black m-0 tracking-tight flex flex-wrap items-center gap-2.5">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/15 border border-white/25 shadow-inner text-lg"><i class="bi bi-cloud-upload"></i></span>
                <span>Assignment uploads</span>
              </h1>
              <p class="text-white/88 mt-2 mb-0 text-xs sm:text-sm leading-relaxed max-w-3xl">
                Open a task tile for full instructions and your upload. Only <strong class="text-white"><?php echo h($allowedTypesLabel); ?></strong> — enforced on the server.
              </p>
            </div>
            <div class="cu-hero-aside">
              <?php if ($taskCount > 0): ?>
              <div class="cu-stat-grid">
                <div class="cu-stat">
                  <div class="cu-stat-k">Open</div>
                  <div class="cu-stat-v"><?php echo (int)$openCount; ?></div>
                </div>
                <div class="cu-stat">
                  <div class="cu-stat-k">Submitted</div>
                  <div class="cu-stat-v"><?php echo (int)$submittedCount; ?></div>
                </div>
                <div class="cu-stat">
                  <div class="cu-stat-k">Due 72h</div>
                  <div class="cu-stat-v"><?php echo (int)$dueSoon; ?></div>
                </div>
              </div>
              <?php endif; ?>
              <div class="cu-hero-actions">
                <a href="college_student_dashboard.php" class="cu-hero-btn inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-white text-[#124a78] font-bold text-xs shadow-md w-full sm:w-auto">
                  <i class="bi bi-house-door"></i> Dashboard
                </a>
                <a href="college_exams.php" class="cu-hero-btn inline-flex items-center justify-center gap-1.5 px-4 py-2.5 border border-white/40 bg-white/12 text-white font-bold text-xs w-full sm:w-auto">
                  <i class="bi bi-journal-text"></i> Exams
                </a>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <div class="cu-body-pad">
    <?php if ($msg): ?><div class="dash-anim delay-1 mb-3 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900 font-semibold text-xs"><?php echo h($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="dash-anim delay-1 mb-3 p-3 rounded-lg bg-red-50 border border-red-200 text-red-900 font-semibold text-xs"><?php echo h($err); ?></div><?php endif; ?>

    <div class="cu-section-head dash-anim delay-2">
      <h2 class="cu-section-title"><i class="bi bi-folder2-open"></i> Your tasks</h2>
      <?php if ($taskCount > 0): ?>
        <span class="cu-pill"><?php echo (int)$taskCount; ?> active</span>
      <?php endif; ?>
    </div>

    <div class="cu-task-board">
      <?php foreach ($tasks as $t):
        $open = college_upload_deadline_allows_upload($t['deadline'] ?? null);
        $excerpt = college_upload_instruction_excerpt($t['instructions'] ?? '', 90);
        $tileClass = 'cu-tile dash-anim delay-2';
        if (!$open) {
            $tileClass .= ' cu-tile--muted';
        }
        ?>
      <a href="college_upload_task.php?id=<?php echo (int)$t['task_id']; ?>" class="<?php echo h($tileClass); ?>">
        <div class="cu-tile-top">
          <span class="cu-tile-icon" aria-hidden="true"><i class="bi bi-file-earmark-arrow-up"></i></span>
          <?php if (!empty($t['submission_id'])): ?>
            <span class="cu-tile-done"><i class="bi bi-check2"></i> File</span>
          <?php endif; ?>
        </div>
        <h3 class="cu-tile-title"><?php echo h($t['title']); ?></h3>
        <?php if ($excerpt !== ''): ?>
          <p class="cu-tile-excerpt"><?php echo h($excerpt); ?></p>
        <?php else: ?>
          <div class="cu-tile-grow" aria-hidden="true"></div>
        <?php endif; ?>
        <div class="cu-tile-foot">
          <span class="cu-tile-due <?php echo $open ? 'cu-tile-due--open' : 'cu-tile-due--closed'; ?>">
            <?php echo $open ? 'Due' : 'Ended'; ?> <?php echo h(date('M j', strtotime($t['deadline']))); ?>
          </span>
          <span class="cu-tile-go">Open <i class="bi bi-chevron-right"></i></span>
        </div>
      </a>
      <?php endforeach; ?>

      <?php if (empty($tasks)): ?>
        <div class="cu-empty dash-anim delay-3">
          <div class="inline-flex items-center justify-center h-11 w-11 rounded-xl border border-[#cde2f4] bg-[#eef6ff] text-[#1665a0] text-xl mb-3 shadow-sm">
            <i class="bi bi-inbox"></i>
          </div>
          <p class="text-slate-700 font-bold text-base m-0">No upload tasks yet</p>
          <p class="text-slate-500 text-xs mt-1.5 mb-4 leading-relaxed">When your instructor publishes a task, it will appear here as a tile.</p>
          <a href="college_student_dashboard.php" class="cu-hint-btn text-xs py-2 px-4"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
        </div>
      <?php endif; ?>
    </div>
    </div>
  </div>
</main>
</body>
</html>
