<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_upload_helpers.php';

$pageTitle = 'Task submissions';
$uid = (int)getCurrentUserId();
$taskId = sanitizeInt($_GET['task_id'] ?? 0);

if ($taskId <= 0) {
    $_SESSION['error'] = 'Invalid task.';
    header('Location: professor_upload_tasks.php');
    exit;
}

$stmt = mysqli_prepare($conn, 'SELECT * FROM college_upload_tasks WHERE task_id=? AND created_by=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'ii', $taskId, $uid);
mysqli_stmt_execute($stmt);
$task = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$task) {
    $_SESSION['error'] = 'Task not found.';
    header('Location: professor_upload_tasks.php');
    exit;
}

$subs = [];
$sq = mysqli_prepare($conn, '
  SELECT s.submission_id, s.user_id, s.file_name, s.file_size, s.submitted_at, s.file_path,
    u.full_name, u.email
  FROM college_submissions s
  INNER JOIN users u ON u.user_id = s.user_id
  WHERE s.task_id = ?
  ORDER BY s.submitted_at DESC
');
mysqli_stmt_bind_param($sq, 'i', $taskId);
mysqli_stmt_execute($sq);
$sres = mysqli_stmt_get_result($sq);
if ($sres) {
    while ($r = mysqli_fetch_assoc($sres)) {
        $subs[] = $r;
    }
}
mysqli_stmt_close($sq);

$rosterCount = 0;
$rq = mysqli_query($conn, "
  SELECT COUNT(*) AS c FROM users
  WHERE role='college_student'
    AND (status='approved' OR status IS NULL OR TRIM(COALESCE(status,''))='')
");
if ($rq) {
    $rosterCount = (int)(mysqli_fetch_assoc($rq)['c'] ?? 0);
    mysqli_free_result($rq);
}

$subCount = count($subs);
$isOpen = !empty($task['is_open']);
$past = college_upload_deadline_has_passed($task['deadline'] ?? null);

$searchQ = trim((string)($_GET['q'] ?? ''));
$sortOpt = (string)($_GET['sort'] ?? 'submitted_desc');
$typeFilter = (string)($_GET['type'] ?? 'all');
$validSort = ['submitted_desc', 'submitted_asc', 'name_asc', 'name_desc'];
$validType = ['all', 'image', 'pdf'];
if (!in_array($sortOpt, $validSort, true)) {
    $sortOpt = 'submitted_desc';
}
if (!in_array($typeFilter, $validType, true)) {
    $typeFilter = 'all';
}

$subsFiltered = $subs;
if ($searchQ !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($searchQ) : strtolower($searchQ);
    $subsFiltered = array_values(array_filter($subsFiltered, static function ($s) use ($needle) {
        $hay = ($s['full_name'] ?? '') . ' ' . ($s['email'] ?? '') . ' ' . ($s['file_name'] ?? '');
        $hay = function_exists('mb_strtolower') ? mb_strtolower($hay) : strtolower($hay);
        if (function_exists('mb_strpos')) {
            return mb_strpos($hay, $needle) !== false;
        }

        return strpos($hay, $needle) !== false;
    }));
}
if ($typeFilter === 'image') {
    $subsFiltered = array_values(array_filter($subsFiltered, static function ($s) {
        return college_upload_view_kind_from_filename((string)$s['file_name']) === 'image';
    }));
} elseif ($typeFilter === 'pdf') {
    $subsFiltered = array_values(array_filter($subsFiltered, static function ($s) {
        return college_upload_view_kind_from_filename((string)$s['file_name']) === 'pdf';
    }));
}

usort($subsFiltered, static function ($a, $b) use ($sortOpt) {
    $ta = strtotime((string)($a['submitted_at'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['submitted_at'] ?? '')) ?: 0;
    switch ($sortOpt) {
        case 'submitted_asc':
            return $ta <=> $tb;
        case 'name_asc':
            return strcasecmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? ''));
        case 'name_desc':
            return strcasecmp((string)($b['full_name'] ?? ''), (string)($a['full_name'] ?? ''));
        case 'submitted_desc':
        default:
            return $tb <=> $ta;
    }
});

$countImages = 0;
$countPdfs = 0;
foreach ($subs as $sx) {
    $vk = college_upload_view_kind_from_filename((string)($sx['file_name'] ?? ''));
    if ($vk === 'image') {
        $countImages++;
    }
    if ($vk === 'pdf') {
        $countPdfs++;
    }
}
$shownCount = count($subsFiltered);
$putMonQs = static function (array $over = []) use ($taskId, $searchQ, $sortOpt, $typeFilter): string {
    return 'professor_upload_task_monitor.php?' . http_build_query(array_merge([
        'task_id' => $taskId,
        'q' => $searchQ,
        'sort' => $sortOpt,
        'type' => $typeFilter,
    ], $over), '', '&', PHP_QUERY_RFC3986);
};

$msg = $_SESSION['message'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['message'], $_SESSION['error']);

function put_monitor_fmt_dt(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '—';
    }
    $ts = strtotime($raw);

    return $ts ? date('M j, Y g:i A', $ts) : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .prof-page { background: linear-gradient(180deg, #ecfdf5 0%, #f0fdf4 38%, #f8fafc 100%); min-height: 100%; }
    .dashboard-shell { padding-bottom: 2rem; color: #0f172a; }
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
    .put-section-title {
      display: flex; align-items: center; gap: 0.5rem;
      font-size: 1.05rem; font-weight: 900; color: #14532d; margin: 0;
    }
    .put-section-title i {
      width: 2rem; height: 2rem; border-radius: 0.55rem;
      display: inline-flex; align-items: center; justify-content: center;
      background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; font-size: 0.95rem;
    }
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
    .put-btn-view {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.45rem 0.75rem;
      border-radius: 0.55rem;
      font-size: 0.78rem;
      font-weight: 800;
      border: 1px solid #059669;
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: #fff;
      text-decoration: none;
      box-shadow: 0 8px 18px -12px rgba(5, 150, 105, 0.85);
      transition: transform 0.15s ease, filter 0.15s ease;
    }
    .put-btn-view:hover { transform: translateY(-1px); filter: brightness(1.05); color: #fff; }
    button.put-btn-view { cursor: pointer; font-family: inherit; }
    .put-thumb {
      width: 52px;
      height: 52px;
      object-fit: cover;
      border-radius: 0.45rem;
      border: 1px solid #bbf7d0;
      background: #f8fafc;
    }
    .put-thumb-pdf {
      width: 52px;
      height: 52px;
      border-radius: 0.45rem;
      border: 1px solid #fecaca;
      background: linear-gradient(180deg, #fef2f2 0%, #fff 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #b91c1c;
      font-size: 1.35rem;
    }
    .put-thumb-btn {
      display: block;
      padding: 0;
      margin: 0;
      border: none;
      background: transparent;
      cursor: zoom-in;
      border-radius: 0.45rem;
      line-height: 0;
    }
    .put-thumb-btn:focus-visible {
      outline: 2px solid #059669;
      outline-offset: 2px;
    }
    .put-thumb-btn-pdf {
      cursor: pointer;
      transition: transform 0.15s ease, filter 0.15s ease;
    }
    .put-thumb-btn-pdf:hover { transform: translateY(-1px); filter: brightness(1.02); }
    .put-toolbar-table-card {
      border-radius: 0;
      border: none;
      border-bottom: 1px solid rgba(16, 185, 129, 0.18);
      background: linear-gradient(180deg, #f4fff8 0%, #fff 45%);
      box-shadow: none;
    }
    .put-toolbar-sticky { position: sticky; top: 0.5rem; z-index: 40; }
    .put-toolbar-wrap { display: flex; flex-direction: column; gap: 0.75rem; }
    .put-toolbar-top { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 0.65rem; align-items: center; }
    .put-search-sort-form { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
    .put-search-input {
      flex: 1 1 260px;
      min-width: 200px;
      border: 1px solid #bbf7d0;
      border-radius: 0.6rem;
      background: #fff;
      padding: 0.52rem 0.68rem;
      font-size: 0.84rem;
      color: #14532d;
    }
    .put-sort-select {
      border: 1px solid #bbf7d0;
      border-radius: 0.6rem;
      background: #fff;
      padding: 0.5rem 0.62rem;
      font-size: 0.82rem;
      color: #14532d;
      font-weight: 700;
    }
    .put-apply-btn {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.5rem 0.88rem;
      border-radius: 0.58rem;
      border: 1px solid #059669;
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: #fff;
      font-size: 0.8rem;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 8px 16px -12px rgba(5, 150, 105, 0.85);
      transition: transform 0.15s ease, filter 0.15s ease;
    }
    .put-apply-btn:hover { transform: translateY(-1px); filter: brightness(1.05); }
    .put-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.32rem;
      padding: 0.32rem 0.65rem;
      border-radius: 999px;
      border: 1px solid #bbf7d0;
      background: #fff;
      color: #14532d;
      font-size: 0.74rem;
      font-weight: 800;
      text-decoration: none;
      transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .put-chip:hover { transform: translateY(-1px); border-color: #86efac; box-shadow: 0 6px 14px -12px rgba(5, 46, 22, 0.45); color: #14532d; }
    .put-chip.is-active { background: #059669; color: #fff; border-color: #059669; }
    .put-counter-row { display: flex; flex-wrap: wrap; gap: 0.4rem; }
    .put-counter-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      padding: 0.3rem 0.55rem;
      border-radius: 0.5rem;
      border: 1px solid #bbf7d0;
      background: #fff;
      color: #14532d;
      font-size: 0.72rem;
      font-weight: 800;
    }
    .ufl-overlay {
      position: fixed;
      inset: 0;
      z-index: 2000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 0.65rem;
    }
    .ufl-overlay.is-open { display: flex; }
    .ufl-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.76); backdrop-filter: blur(5px); }
    .ufl-dialog {
      position: relative;
      z-index: 1;
      width: 100%;
      max-width: min(96vw, 1180px);
      max-height: 92vh;
      display: flex;
      flex-direction: column;
      background: #0f172a;
      border-radius: 1rem;
      border: 1px solid rgba(255, 255, 255, 0.1);
      box-shadow: 0 28px 90px -24px rgba(0, 0, 0, 0.58);
      overflow: hidden;
    }
    .ufl-chrome {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.65rem;
      padding: 0.6rem 0.85rem;
      background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      flex-shrink: 0;
    }
    .ufl-title {
      font-size: 0.78rem;
      font-weight: 800;
      color: #e2e8f0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      min-width: 0;
    }
    .ufl-chrome-actions { display: flex; gap: 0.35rem; flex-shrink: 0; }
    .ufl-icon-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 2.2rem;
      height: 2.2rem;
      border-radius: 0.48rem;
      border: 1px solid rgba(255, 255, 255, 0.14);
      background: rgba(255, 255, 255, 0.06);
      color: #e2e8f0;
      cursor: pointer;
      text-decoration: none;
      transition: background 0.15s ease, color 0.15s ease;
    }
    .ufl-icon-btn:hover { background: rgba(255, 255, 255, 0.12); color: #fff; }
    .ufl-stage {
      flex: 1;
      min-height: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #020617;
      padding: 0.45rem;
    }
    .ufl-img {
      max-width: 100%;
      max-height: calc(92vh - 4.5rem);
      width: auto;
      height: auto;
      object-fit: contain;
      border-radius: 0.35rem;
    }
    .ufl-iframe {
      width: 100%;
      height: min(82vh, 880px);
      min-height: 360px;
      border: none;
      border-radius: 0.35rem;
      background: #fff;
    }
    .ufl-fallback { text-align: center; padding: 1.75rem 1.25rem; color: #94a3b8; font-size: 0.88rem; max-width: 28rem; }
    .ufl-fallback a { color: #6ee7b7; font-weight: 800; }
    @media (max-width: 768px) {
      .put-toolbar-top { grid-template-columns: 1fr; }
    }
    .dash-anim { opacity: 0; transform: translateY(12px); animation: putMonFade 0.55s ease-out forwards; }
    .delay-1 { animation-delay: 0.04s; }
    .delay-2 { animation-delay: 0.1s; }
    @keyframes putMonFade { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
    }
  </style>
</head>
<body class="font-sans antialiased prof-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <div class="dashboard-shell w-full max-w-none px-4 sm:px-5 lg:px-6">
    <?php if ($msg): ?>
      <div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 font-semibold text-sm"><?php echo h($msg); ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="mb-4 p-4 rounded-xl bg-red-50 border border-red-200 text-red-900 font-semibold text-sm"><?php echo h($err); ?></div>
    <?php endif; ?>

    <div class="put-hero mb-6 dash-anim delay-1">
      <div class="p-6 sm:p-7 relative z-[1]">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
          <div class="flex items-start gap-4 min-w-0">
            <div class="put-hero-icon shrink-0"><i class="bi bi-people-fill"></i></div>
            <div class="min-w-0">
              <a href="professor_upload_tasks.php" class="inline-flex items-center gap-2 text-sm text-white/90 hover:text-white font-bold mb-2">
                <i class="bi bi-arrow-left"></i> Back to upload tasks
              </a>
              <h1 class="text-xl sm:text-2xl font-black text-white m-0 tracking-tight leading-tight"><?php echo h($task['title']); ?></h1>
              <p class="text-white/88 text-sm mt-2 mb-0">Submissions for this assignment. Open files in the browser or a new tab.</p>
              <div class="flex flex-wrap gap-2 mt-3">
                <?php if (!$isOpen): ?>
                  <span class="put-pill put-pill-off bg-white/15 border-white/25 text-white"><i class="bi bi-lock"></i> Closed to students</span>
                <?php elseif ($past): ?>
                  <span class="put-pill put-pill-late"><i class="bi bi-calendar-x"></i> Past due</span>
                <?php else: ?>
                  <span class="put-pill put-pill-live"><i class="bi bi-unlock"></i> Live</span>
                <?php endif; ?>
                <span class="put-pill put-pill-off bg-white/15 border-white/25 text-white">
                  <i class="bi bi-clock-history"></i> Due <?php echo h(put_monitor_fmt_dt($task['deadline'] ?? null)); ?>
                </span>
              </div>
            </div>
          </div>
          <div class="put-stat-grid lg:min-w-[280px] shrink-0">
            <div class="put-stat">
              <div class="put-stat-k">Submissions</div>
              <div class="put-stat-v"><?php echo (int)$subCount; ?></div>
            </div>
            <div class="put-stat">
              <div class="put-stat-k">College students</div>
              <div class="put-stat-v"><?php echo (int)$rosterCount; ?></div>
            </div>
            <div class="put-stat">
              <div class="put-stat-k">Pending</div>
              <div class="put-stat-v"><?php echo (int)max(0, $rosterCount - $subCount); ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="put-card overflow-hidden dash-anim delay-2">
      <div class="px-5 py-4 border-b border-emerald-100 bg-gradient-to-r from-emerald-50/90 to-white flex flex-wrap items-center justify-between gap-3">
        <h2 class="put-section-title m-0"><i class="bi bi-inboxes"></i> Student files</h2>
        <a href="professor_upload_tasks.php?edit=<?php echo (int)$task['task_id']; ?>" class="put-link text-sm"><i class="bi bi-pencil-square"></i> Edit task</a>
      </div>
      <?php if (!empty($subs)): ?>
      <div class="put-toolbar-table-card put-toolbar-sticky px-4 py-4 sm:px-5">
        <div class="put-toolbar-wrap">
          <div class="put-toolbar-top">
            <form method="get" class="put-search-sort-form" action="professor_upload_task_monitor.php">
              <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
              <input type="hidden" name="type" value="<?php echo h($typeFilter); ?>">
              <input type="search" name="q" value="<?php echo h($searchQ); ?>" class="put-search-input" placeholder="Search student, email, or filename…" autocomplete="off">
              <select name="sort" class="put-sort-select" aria-label="Sort submissions">
                <option value="submitted_desc" <?php echo $sortOpt === 'submitted_desc' ? 'selected' : ''; ?>>Newest submitted</option>
                <option value="submitted_asc" <?php echo $sortOpt === 'submitted_asc' ? 'selected' : ''; ?>>Oldest submitted</option>
                <option value="name_asc" <?php echo $sortOpt === 'name_asc' ? 'selected' : ''; ?>>Student A–Z</option>
                <option value="name_desc" <?php echo $sortOpt === 'name_desc' ? 'selected' : ''; ?>>Student Z–A</option>
              </select>
              <button type="submit" class="put-apply-btn"><i class="bi bi-search"></i> Apply</button>
            </form>
          </div>
          <div class="put-toolbar-top">
            <div class="flex flex-wrap gap-2">
              <a href="<?php echo h($putMonQs(['type' => 'all'])); ?>" class="put-chip <?php echo $typeFilter === 'all' ? 'is-active' : ''; ?>"><i class="bi bi-grid"></i> All files</a>
              <a href="<?php echo h($putMonQs(['type' => 'image'])); ?>" class="put-chip <?php echo $typeFilter === 'image' ? 'is-active' : ''; ?>"><i class="bi bi-image"></i> Images</a>
              <a href="<?php echo h($putMonQs(['type' => 'pdf'])); ?>" class="put-chip <?php echo $typeFilter === 'pdf' ? 'is-active' : ''; ?>"><i class="bi bi-file-earmark-pdf"></i> PDFs</a>
            </div>
            <div class="put-counter-row">
              <span class="put-counter-chip"><i class="bi bi-inboxes"></i> Submissions: <?php echo (int)$subCount; ?></span>
              <span class="put-counter-chip"><i class="bi bi-funnel"></i> Showing: <?php echo (int)$shownCount; ?></span>
              <span class="put-counter-chip"><i class="bi bi-image"></i> Images: <?php echo (int)$countImages; ?></span>
              <span class="put-counter-chip"><i class="bi bi-file-earmark-pdf"></i> PDFs: <?php echo (int)$countPdfs; ?></span>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="put-table-wrap">
        <?php if (empty($subs)): ?>
          <div class="p-12 text-center">
            <div class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 border border-emerald-100 text-emerald-600 text-2xl mb-4">
              <i class="bi bi-inbox"></i>
            </div>
            <p class="text-slate-600 font-semibold m-0">No submissions yet</p>
            <p class="text-sm text-slate-500 mt-1 mb-0">Files appear here when students upload from College → Uploads.</p>
          </div>
        <?php elseif (empty($subsFiltered)): ?>
          <div class="p-10 text-center">
            <div class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 border border-amber-100 text-amber-700 text-xl mb-3">
              <i class="bi bi-search"></i>
            </div>
            <p class="text-slate-700 font-bold m-0">No files match your filters</p>
            <p class="text-sm text-slate-500 mt-1 mb-0">Try another search or <a class="put-link" href="<?php echo h($putMonQs(['q' => '', 'type' => 'all'])); ?>">clear filters</a>.</p>
          </div>
        <?php else: ?>
          <table class="put-table">
            <thead>
              <tr>
                <th class="w-20">Preview</th>
                <th>Student</th>
                <th class="hidden lg:table-cell">Email</th>
                <th>File</th>
                <th class="hidden md:table-cell">Size</th>
                <th class="hidden sm:table-cell">Submitted</th>
                <th class="text-right">View</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($subsFiltered as $s):
                $sid = (int)$s['submission_id'];
                $viewUrl = 'college_upload_file.php?s=' . $sid;
                $dlUrl = $viewUrl . '&download=1';
                $kind = college_upload_view_kind_from_filename((string)$s['file_name']);
                $sz = (int)($s['file_size'] ?? 0);
                $szLabel = $sz >= 1048576
                  ? round($sz / 1048576, 1) . ' MB'
                  : ($sz >= 1024 ? round($sz / 1024, 1) . ' KB' : (string)$sz . ' B');
                $fnEsc = h((string)$s['file_name']);
                ?>
              <tr>
                <td>
                  <?php if ($kind === 'image'): ?>
                    <button type="button" class="put-thumb-btn" data-ufl-open
                      data-ufl-kind="image"
                      data-ufl-url="<?php echo h($viewUrl); ?>"
                      data-ufl-download="<?php echo h($dlUrl); ?>"
                      data-ufl-name="<?php echo $fnEsc; ?>"
                      title="Preview full size">
                      <img class="put-thumb" src="<?php echo h($viewUrl); ?>" alt="" loading="lazy" width="52" height="52">
                    </button>
                  <?php elseif ($kind === 'pdf'): ?>
                    <button type="button" class="put-thumb-pdf put-thumb-btn-pdf" data-ufl-open title="Open PDF viewer"
                      data-ufl-kind="pdf"
                      data-ufl-url="<?php echo h($viewUrl); ?>"
                      data-ufl-download="<?php echo h($dlUrl); ?>"
                      data-ufl-name="<?php echo $fnEsc; ?>"><i class="bi bi-file-earmark-pdf"></i></button>
                  <?php else: ?>
                    <button type="button" class="put-thumb-pdf border-slate-200 text-slate-500 put-thumb-btn-pdf" data-ufl-open title="File"
                      data-ufl-kind="other"
                      data-ufl-url="<?php echo h($viewUrl); ?>"
                      data-ufl-download="<?php echo h($dlUrl); ?>"
                      data-ufl-name="<?php echo $fnEsc; ?>"><i class="bi bi-file-earmark"></i></button>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="font-bold text-slate-900"><?php echo h((string)$s['full_name']); ?></div>
                  <div class="lg:hidden text-xs text-slate-500 mt-1 break-all"><?php echo h((string)$s['email']); ?></div>
                </td>
                <td class="hidden lg:table-cell text-slate-600 text-sm break-all"><?php echo h((string)$s['email']); ?></td>
                <td class="text-slate-800 text-sm font-medium break-all max-w-[200px]"><?php echo h((string)$s['file_name']); ?></td>
                <td class="hidden md:table-cell text-slate-600 text-sm whitespace-nowrap"><?php echo h($szLabel); ?></td>
                <td class="hidden sm:table-cell text-slate-600 text-sm whitespace-nowrap"><?php echo h(put_monitor_fmt_dt($s['submitted_at'] ?? null)); ?></td>
                <td class="text-right">
                  <button type="button" class="put-btn-view" data-ufl-open
                    data-ufl-kind="<?php echo h($kind); ?>"
                    data-ufl-url="<?php echo h($viewUrl); ?>"
                    data-ufl-download="<?php echo h($dlUrl); ?>"
                    data-ufl-name="<?php echo $fnEsc; ?>"><i class="bi bi-arrows-fullscreen"></i> Full view</button>
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
  </main>

  <div id="ufl-overlay" class="ufl-overlay" aria-hidden="true">
    <div class="ufl-backdrop" data-ufl-close tabindex="-1" aria-hidden="true"></div>
    <div class="ufl-dialog" role="dialog" aria-modal="true" aria-labelledby="ufl-title">
      <div class="ufl-chrome">
        <span id="ufl-title" class="ufl-title">File</span>
        <div class="ufl-chrome-actions">
          <a id="ufl-newtab" class="ufl-icon-btn" href="#" target="_blank" rel="noopener" title="Open in new tab"><i class="bi bi-box-arrow-up-right"></i></a>
          <a id="ufl-download" class="ufl-icon-btn" href="#" title="Download"><i class="bi bi-download"></i></a>
          <button type="button" class="ufl-icon-btn" data-ufl-close title="Close (Esc)"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>
      <div class="ufl-stage">
        <img id="ufl-img" class="ufl-img" alt="" style="display:none">
        <iframe id="ufl-iframe" class="ufl-iframe" title="Document" style="display:none"></iframe>
        <div id="ufl-fallback" class="ufl-fallback" style="display:none">
          <p class="m-0">Inline preview is not available for this file type. Use the toolbar to open or download.</p>
          <p class="mt-3 mb-0"><a id="ufl-fallback-link" href="#" target="_blank" rel="noopener">Open in new tab</a></p>
        </div>
      </div>
    </div>
  </div>
  <script>
  (function () {
    var overlay = document.getElementById('ufl-overlay');
    if (!overlay) return;
    var titleEl = document.getElementById('ufl-title');
    var img = document.getElementById('ufl-img');
    var iframe = document.getElementById('ufl-iframe');
    var fallback = document.getElementById('ufl-fallback');
    var newTab = document.getElementById('ufl-newtab');
    var download = document.getElementById('ufl-download');
    var fallbackLink = document.getElementById('ufl-fallback-link');
    function close() {
      overlay.classList.remove('is-open');
      overlay.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      img.removeAttribute('src');
      iframe.removeAttribute('src');
    }
    function openFromBtn(btn) {
      var kind = (btn.getAttribute('data-ufl-kind') || 'other').toLowerCase();
      var url = btn.getAttribute('data-ufl-url') || '';
      var dl = btn.getAttribute('data-ufl-download') || '';
      var name = btn.getAttribute('data-ufl-name') || 'File';
      if (!url) return;
      titleEl.textContent = name;
      newTab.href = url;
      download.href = dl || url;
      if (fallbackLink) fallbackLink.href = url;
      img.style.display = 'none';
      iframe.style.display = 'none';
      fallback.style.display = 'none';
      if (kind === 'image') {
        img.style.display = 'block';
        img.src = url;
      } else if (kind === 'pdf') {
        iframe.style.display = 'block';
        iframe.src = url;
      } else {
        fallback.style.display = 'block';
      }
      overlay.classList.add('is-open');
      overlay.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }
    document.addEventListener('click', function (e) {
      var t = e.target.closest('[data-ufl-open]');
      if (t) {
        e.preventDefault();
        openFromBtn(t);
      }
      if (e.target.closest('[data-ufl-close]')) {
        e.preventDefault();
        close();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlay.classList.contains('is-open')) close();
    });
  })();
  </script>
</body>
</html>
