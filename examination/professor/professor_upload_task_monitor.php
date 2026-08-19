<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_upload_helpers.php';

$pageTitle = 'Task submissions';
$uid = (int)getCurrentUserId();
$taskId = sanitizeInt($_GET['task_id'] ?? 0);

if ($taskId <= 0) {
    $_SESSION['error'] = 'Invalid task.';
    header('Location: professor_upload_tasks');
    exit;
}

$stmt = mysqli_prepare($conn, 'SELECT * FROM college_upload_tasks WHERE task_id=? AND created_by=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'ii', $taskId, $uid);
mysqli_stmt_execute($stmt);
$task = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$task) {
    $_SESSION['error'] = 'Task not found.';
    header('Location: professor_upload_tasks');
    exit;
}

$csrf = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: professor_upload_task_monitor?task_id=' . (int) $taskId);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $targetUser = sanitizeInt($_POST['user_id'] ?? 0);
    $requestId = sanitizeInt($_POST['request_id'] ?? 0);

    if ($action === 'request_resubmission' && $targetUser > 0) {
        $res = college_upload_professor_request_resubmission($conn, $taskId, $targetUser, (int) $uid);
        if (!empty($res['ok'])) {
            $_SESSION['message'] = 'Resubmission requested for this student.';
        } else {
            $_SESSION['error'] = (string) ($res['error'] ?? 'Could not request resubmission.');
        }
    } elseif ($action === 'approve_resubmission' && $requestId > 0) {
        $res = college_upload_professor_resolve_resubmission($conn, $requestId, (int) $uid, 'approved');
        if (!empty($res['ok'])) {
            $_SESSION['message'] = 'Resubmission approved.';
        } else {
            $_SESSION['error'] = (string) ($res['error'] ?? 'Could not approve request.');
        }
    } elseif ($action === 'reject_resubmission' && $requestId > 0) {
        $res = college_upload_professor_resolve_resubmission($conn, $requestId, (int) $uid, 'rejected');
        if (!empty($res['ok'])) {
            $_SESSION['message'] = 'Resubmission request rejected.';
        } else {
            $_SESSION['error'] = (string) ($res['error'] ?? 'Could not reject request.');
        }
    } else {
        $_SESSION['error'] = 'Unknown action.';
    }
    header('Location: professor_upload_task_monitor?task_id=' . (int) $taskId);
    exit;
}

$resubPolicy = strtolower(trim((string) ($task['resubmission_policy'] ?? 'disabled')));

$subs = [];
$sq = mysqli_prepare($conn, '
  SELECT s.submission_id, s.user_id, s.file_name, s.file_size, s.submitted_at, s.file_path,
    s.submission_number, s.review_status, u.full_name, u.email
  FROM college_submissions s
  INNER JOIN users u ON u.user_id = s.user_id
  WHERE s.task_id = ? AND s.is_latest = 1
  ORDER BY s.submitted_at DESC
');
mysqli_stmt_bind_param($sq, 'i', $taskId);
mysqli_stmt_execute($sq);
$sres = mysqli_stmt_get_result($sq);
if ($sres) {
    while ($r = mysqli_fetch_assoc($sres)) {
        $uidRow = (int) ($r['user_id'] ?? 0);
        $req = college_upload_active_resubmission_request($conn, $taskId, $uidRow);
        $r['resub_label'] = college_upload_resubmission_label($conn, $taskId, $uidRow);
        $r['resub_request_id'] = $req ? (int) ($req['request_id'] ?? 0) : 0;
        $r['resub_status'] = $req ? (string) ($req['status'] ?? '') : '';
        $subs[] = $r;
    }
}
mysqli_stmt_close($sq);

$historyByUser = [];
$hq = mysqli_prepare(
    $conn,
    'SELECT submission_id, user_id, submission_number, file_name, submitted_at, review_status, is_latest
     FROM college_submissions WHERE task_id=? ORDER BY user_id ASC, submission_number DESC'
);
if ($hq) {
    mysqli_stmt_bind_param($hq, 'i', $taskId);
    mysqli_stmt_execute($hq);
    $hres = mysqli_stmt_get_result($hq);
    while ($hr = mysqli_fetch_assoc($hres)) {
        $uidHist = (int) ($hr['user_id'] ?? 0);
        if ($uidHist <= 0) {
            continue;
        }
        if (!isset($historyByUser[$uidHist])) {
            $historyByUser[$uidHist] = [];
        }
        $historyByUser[$uidHist][] = $hr;
    }
    mysqli_stmt_close($hq);
}

$rosterCount = college_upload_count_eligible_students($conn, $task);
$subCount = college_upload_count_latest_submissions($conn, $taskId);
$windowState = college_upload_task_window_state($task);
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
    return 'professor_upload_task_monitor?' . http_build_query(array_merge([
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
        return '-';
    }
    $ts = strtotime($raw);

    return $ts ? date('M j, Y g:i A', $ts) : '-';
}

$openLabel = put_monitor_fmt_dt($task['open_at'] ?? null);
$deadlineLabel = put_monitor_fmt_dt($task['deadline'] ?? null);
$statusBadgeHtml = match ($windowState) {
    'draft' => '<span class="admin-badge admin-badge--neutral"><i class="bi bi-pencil"></i> Draft</span>',
    'upcoming' => '<span class="admin-badge admin-badge--warning"><i class="bi bi-calendar-event"></i> Upcoming</span>',
    'locked' => '<span class="admin-badge admin-badge--neutral"><i class="bi bi-lock"></i> Locked</span>',
    default => '<span class="admin-badge admin-badge--success"><i class="bi bi-unlock"></i> Open</span>',
};
$pageTitle = 'Task submissions';
$adminHeroIcon = 'inboxes';
$adminHeroTitle = (string)$task['title'];
$adminHeroSubtitle = 'Submissions for this assignment. Open files in the browser or a new tab.';
$adminHeroMeta = '<span class="text-sm opacity-80">Opens: <strong>' . h($openLabel !== '-' ? $openLabel : 'Immediately') . '</strong></span>'
    . '<span class="text-sm opacity-80">Closes: <strong>' . h($deadlineLabel) . '</strong></span>';
$adminHeroActions = '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_upload_tasks"><i class="bi bi-arrow-left"></i> Back to tasks</a>'
    . '<a class="admin-btn admin-btn--ghost admin-btn--sm" href="professor_upload_tasks?edit=' . (int)$task['task_id'] . '"><i class="bi bi-pencil-square"></i> Edit task</a>'
    . $statusBadgeHtml;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <?php if ($msg): ?>
    <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?php echo h($msg); ?></span></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($err); ?></span></div>
  <?php endif; ?>

  <div class="examination-page-shell">
    <div class="examination-kpi-grid mb-4">
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Submissions</div><div class="examination-kpi-card__value"><?php echo (int)$subCount; ?></div></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Eligible students</div><div class="examination-kpi-card__value"><?php echo (int)$rosterCount; ?></div></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Pending</div><div class="examination-kpi-card__value"><?php echo (int)max(0, $rosterCount - $subCount); ?></div></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Showing</div><div class="examination-kpi-card__value"><?php echo (int)$shownCount; ?></div></div>
    </div>

    <div class="rounded-xl overflow-hidden page-table students-table-shell">
      <div class="examination-table-card-head flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-bold m-0 flex items-center gap-2"><i class="bi bi-inboxes"></i> Student files</h2>
        <span class="text-xs font-bold uppercase tracking-wider opacity-60"><?php echo (int)$countImages; ?> images · <?php echo (int)$countPdfs; ?> PDFs</span>
      </div>
      <?php if (!empty($subs)): ?>
      <div class="students-toolbar page-filter px-4 py-3 border-b border-[var(--admin-border)]">
        <form method="get" class="students-toolbar__search" action="professor_upload_task_monitor">
          <input type="hidden" name="task_id" value="<?php echo (int)$taskId; ?>">
          <input type="hidden" name="type" value="<?php echo h($typeFilter); ?>">
          <input type="search" name="q" value="<?php echo h($searchQ); ?>" placeholder="Search student, email, or filename..." autocomplete="off" class="w-full">
          <select name="sort" aria-label="Sort submissions" class="admin-btn admin-btn--secondary admin-btn--sm" style="min-height:2.25rem;">
            <option value="submitted_desc" <?php echo $sortOpt === 'submitted_desc' ? 'selected' : ''; ?>>Newest submitted</option>
            <option value="submitted_asc" <?php echo $sortOpt === 'submitted_asc' ? 'selected' : ''; ?>>Oldest submitted</option>
            <option value="name_asc" <?php echo $sortOpt === 'name_asc' ? 'selected' : ''; ?>>Student A-Z</option>
            <option value="name_desc" <?php echo $sortOpt === 'name_desc' ? 'selected' : ''; ?>>Student Z-A</option>
          </select>
          <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-search"></i> Apply</button>
        </form>
        <nav class="students-status-chips" aria-label="Filter by file type">
          <a href="<?php echo h($putMonQs(['type' => 'all'])); ?>" class="students-status-chip <?php echo $typeFilter === 'all' ? 'is-active' : ''; ?>"><i class="bi bi-grid"></i> All</a>
          <a href="<?php echo h($putMonQs(['type' => 'image'])); ?>" class="students-status-chip <?php echo $typeFilter === 'image' ? 'is-active' : ''; ?>"><i class="bi bi-image"></i> Images</a>
          <a href="<?php echo h($putMonQs(['type' => 'pdf'])); ?>" class="students-status-chip <?php echo $typeFilter === 'pdf' ? 'is-active' : ''; ?>"><i class="bi bi-file-earmark-pdf"></i> PDFs</a>
        </nav>
      </div>
      <?php endif; ?>
      <div class="students-table-scroll">
        <?php if (empty($subs)): ?>
          <div class="p-12 text-center">
            <div class="inline-flex h-14 w-14 items-center justify-center rounded-2xl border opacity-80 text-2xl mb-4">
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
            <p class="text-sm text-slate-500 mt-1 mb-0">Try another search or <a class="students-clear-link" href="<?php echo h($putMonQs(['q' => '', 'type' => 'all'])); ?>">clear filters</a>.</p>
          </div>
        <?php else: ?>
          <table class="admin-students-table students-table--compact w-full text-left">
            <thead>
              <tr>
                <th class="w-20">Preview</th>
                <th>Student</th>
                <th class="hidden lg:table-cell">Email</th>
                <th>File</th>
                <th class="hidden md:table-cell">Size</th>
                <th class="hidden sm:table-cell">Submitted</th>
                <th class="hidden md:table-cell">Status</th>
                <th class="hidden lg:table-cell">Resubmission</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($subsFiltered as $s):
                $sid = (int)$s['submission_id'];
                $viewUrl = 'college_upload_file?s=' . $sid;
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
                    <button type="button" class="examination-upload-thumb-btn" data-ufl-open
                      data-ufl-kind="image"
                      data-ufl-url="<?php echo h($viewUrl); ?>"
                      data-ufl-download="<?php echo h($dlUrl); ?>"
                      data-ufl-name="<?php echo $fnEsc; ?>"
                      title="Preview full size">
                      <img class="examination-upload-thumb" src="<?php echo h($viewUrl); ?>" alt="" loading="lazy" width="52" height="52">
                    </button>
                  <?php elseif ($kind === 'pdf'): ?>
                    <button type="button" class="examination-upload-thumb-btn" data-ufl-open title="Open PDF viewer"
                      data-ufl-kind="pdf"
                      data-ufl-url="<?php echo h($viewUrl); ?>"
                      data-ufl-download="<?php echo h($dlUrl); ?>"
                      data-ufl-name="<?php echo $fnEsc; ?>"><i class="bi bi-file-earmark-pdf"></i></button>
                  <?php else: ?>
                    <button type="button" class="examination-upload-thumb-btn opacity-70" data-ufl-open title="File"
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
                <td class="text-slate-800 text-sm font-medium break-all max-w-[200px]">
                  <div><?php echo h((string)$s['file_name']); ?></div>
                  <?php
                    $studentId = (int) ($s['user_id'] ?? 0);
                    $studentHistory = $historyByUser[$studentId] ?? [];
                    if (count($studentHistory) > 1):
                  ?>
                  <details class="mt-2 text-xs">
                    <summary class="cursor-pointer text-[#1665a0] font-semibold"><?php echo count($studentHistory); ?> submissions</summary>
                    <ul class="mt-2 space-y-1 pl-0 list-none">
                      <?php foreach ($studentHistory as $histRow):
                        $hSid = (int) ($histRow['submission_id'] ?? 0);
                        $hView = 'college_upload_file?s=' . $hSid;
                      ?>
                      <li class="border-t border-slate-100 pt-1">
                        <span class="font-semibold">#<?php echo (int) ($histRow['submission_number'] ?? 0); ?></span>
                        · <?php echo h(put_monitor_fmt_dt($histRow['submitted_at'] ?? null)); ?>
                        · <a href="<?php echo h($hView); ?>" target="_blank" rel="noopener" class="text-[#1665a0]">View</a>
                      </li>
                      <?php endforeach; ?>
                    </ul>
                  </details>
                  <?php endif; ?>
                </td>
                <td class="hidden md:table-cell text-slate-600 text-sm whitespace-nowrap"><?php echo h($szLabel); ?></td>
                <td class="hidden sm:table-cell text-slate-600 text-sm whitespace-nowrap">
                  <div>#<?php echo (int) ($s['submission_number'] ?? 1); ?></div>
                  <div><?php echo h(put_monitor_fmt_dt($s['submitted_at'] ?? null)); ?></div>
                </td>
                <td class="hidden md:table-cell text-sm whitespace-nowrap">
                  <?php
                    $review = strtolower((string) ($s['review_status'] ?? 'submitted'));
                    $reviewBadge = $review === 'reviewed'
                        ? 'admin-badge admin-badge--neutral'
                        : 'admin-badge admin-badge--success';
                  ?>
                  <span class="<?php echo h($reviewBadge); ?>"><?php echo h(ucfirst($review)); ?></span>
                </td>
                <td class="hidden lg:table-cell text-sm">
                  <?php
                    $resubLabel = (string) ($s['resub_label'] ?? 'No request');
                    $resubStatus = (string) ($s['resub_status'] ?? '');
                    $resubReqId = (int) ($s['resub_request_id'] ?? 0);
                    $studentId = (int) ($s['user_id'] ?? 0);
                  ?>
                  <div class="font-semibold text-slate-700 mb-1"><?php echo h($resubLabel); ?></div>
                  <?php if ($resubPolicy === 'allowed'): ?>
                    <span class="text-xs text-slate-500">Policy: allowed</span>
                  <?php elseif ($resubStatus === 'pending' && $resubReqId > 0): ?>
                    <form method="post" class="inline-flex flex-wrap gap-1 mt-1">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="request_id" value="<?php echo $resubReqId; ?>">
                      <button type="submit" name="action" value="approve_resubmission" class="admin-btn admin-btn--secondary admin-btn--sm">Approve</button>
                      <button type="submit" name="action" value="reject_resubmission" class="admin-btn admin-btn--ghost admin-btn--sm">Reject</button>
                    </form>
                  <?php elseif ($resubStatus !== 'approved' && $studentId > 0): ?>
                    <form method="post" class="mt-1">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="user_id" value="<?php echo $studentId; ?>">
                      <button type="submit" name="action" value="request_resubmission" class="admin-btn admin-btn--ghost admin-btn--sm">Request resubmission</button>
                    </form>
                  <?php elseif ($resubStatus === 'approved'): ?>
                    <span class="text-xs text-emerald-700 font-semibold">Student may resubmit</span>
                  <?php endif; ?>
                </td>
                <td class="text-right">
                  <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" data-ufl-open
                    data-ufl-kind="<?php echo h($kind); ?>"
                    data-ufl-url="<?php echo h($viewUrl); ?>"
                    data-ufl-download="<?php echo h($dlUrl); ?>"
                    data-ufl-name="<?php echo $fnEsc; ?>"><i class="bi bi-arrows-fullscreen"></i> View</button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

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
