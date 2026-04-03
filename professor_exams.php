<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';

$pageTitle = 'College exams';
$uid = getCurrentUserId();
$csrf = generateCSRFToken();
$now = date('Y-m-d H:i:s');

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
        $canDelete = true;
        $er = @mysqli_query($conn, "SELECT exam_id, is_published, available_from, deadline FROM college_exams WHERE exam_id=" . $eid . " AND created_by=" . (int)$uid . " LIMIT 1");
        $examRow = $er ? mysqli_fetch_assoc($er) : null;
        if ($examRow) {
            $nowTs = time();
            $openTs = !empty($examRow['available_from']) ? strtotime((string)$examRow['available_from']) : null;
            $deadlineTs = !empty($examRow['deadline']) ? strtotime((string)$examRow['deadline']) : null;
            $isPublished = !empty($examRow['is_published']);
            $withinOpen = ($openTs === false || $openTs === null || $openTs <= $nowTs);
            $withinDeadline = ($deadlineTs === false || $deadlineTs === null || $deadlineTs >= $nowTs);
            $submittedDel = 0;
            $subQr = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exam_attempts WHERE exam_id=" . (int)$eid . " AND status='submitted'");
            if ($subQr) {
                $subRow = mysqli_fetch_assoc($subQr);
                $submittedDel = (int)($subRow['c'] ?? 0);
                mysqli_free_result($subQr);
            }
            $finishedOpenNoDeadline = college_exam_finished_all_submitted_no_deadline($conn, $examRow, $submittedDel);
            $isLiveRunning = $isPublished && $withinOpen && $withinDeadline && !$finishedOpenNoDeadline;
            if ($isLiveRunning) {
                $canDelete = false;
            }
        }
        if (!$canDelete) {
            $_SESSION['delete_feedback'] = ['ok' => false, 'text' => 'Deletion is not allowed while this exam is running.'];
            header('Location: professor_exams.php');
            exit;
        }
        $passInput = trim((string)($_POST['delete_password'] ?? ''));
        $pwHash = null;
        $pst = mysqli_prepare($conn, "SELECT password FROM users WHERE user_id=? LIMIT 1");
        if ($pst) {
            mysqli_stmt_bind_param($pst, 'i', $uid);
            mysqli_stmt_execute($pst);
            $pres = mysqli_stmt_get_result($pst);
            $prow = $pres ? mysqli_fetch_assoc($pres) : null;
            $pwHash = $prow['password'] ?? null;
            mysqli_stmt_close($pst);
        }
        $passOk = false;
        if (is_string($pwHash) && $pwHash !== '' && $passInput !== '') {
            // Match login behavior: support hashed passwords and legacy plain-text seed data.
            $passOk = password_verify($passInput, $pwHash) || hash_equals($pwHash, $passInput);
        }
        if (!$passOk) {
            $_SESSION['delete_feedback'] = ['ok' => false, 'text' => 'Wrong password. Please try again.'];
            header('Location: professor_exams.php');
            exit;
        }
        mysqli_query($conn, "DELETE FROM college_exams WHERE exam_id=" . $eid . " AND created_by=" . (int)$uid);
        $_SESSION['delete_feedback'] = ['ok' => true, 'text' => 'Exam deleted successfully.'];
        $_SESSION['message'] = 'Exam deleted.';
    } elseif ($action === 'toggle_publish' && $eid > 0) {
        mysqli_query($conn, "UPDATE college_exams SET is_published=1-is_published WHERE exam_id=" . $eid . " AND created_by=" . (int)$uid);
        $_SESSION['message'] = 'Publication updated.';
    }
    header('Location: professor_exams.php');
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$pubFilter = (string)($_GET['pub'] ?? 'all'); // all|published|draft|finished
$sort = (string)($_GET['sort'] ?? 'updated_desc'); // updated_desc|updated_asc|title_asc|title_desc|deadline_asc|deadline_desc
$display = (string)($_GET['display'] ?? 'list'); // list|card
$validPub = ['all', 'published', 'draft', 'finished'];
$validSort = ['updated_desc', 'updated_asc', 'title_asc', 'title_desc', 'deadline_asc', 'deadline_desc'];
$validDisplays = ['list', 'card'];
if (!in_array($pubFilter, $validPub, true)) { $pubFilter = 'all'; }
if (!in_array($sort, $validSort, true)) { $sort = 'updated_desc'; }
if (!in_array($display, $validDisplays, true)) { $display = 'list'; }

college_exam_finalize_expired_in_progress($conn, 0, 0, (int)$uid);

$rawList = [];
$q = mysqli_query($conn, "SELECT e.*, (SELECT COUNT(*) FROM college_exam_questions q WHERE q.exam_id=e.exam_id) AS q_count FROM college_exams e WHERE e.created_by=" . (int)$uid . " ORDER BY e.updated_at DESC");
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $rawList[] = $r;
    }
    mysqli_free_result($q);
}

$examIds = [];
if ($rawList !== []) {
    $examIds = array_values(array_unique(array_filter(array_map(static function ($r) {
        return (int)($r['exam_id'] ?? 0);
    }, $rawList), static function ($id) { return $id > 0; })));
}

$attemptStatsByExam = [];
if ($examIds !== []) {
    $inSql = implode(',', $examIds);
    $aq = @mysqli_query($conn, "
      SELECT
        exam_id,
        SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS taking_count,
        SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) AS submitted_count,
        AVG(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
          THEN (50 + 0.5 * (100.0 * COALESCE(correct_count,0) / total_count))
          WHEN status='submitted' THEN score END) AS avg_score,
        SUM(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
          AND COALESCE(correct_count,0) >= CEILING(total_count / 2) THEN 1
          ELSE 0 END) AS pass_count,
        SUM(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
          AND COALESCE(correct_count,0) < CEILING(total_count / 2) THEN 1
          WHEN status='submitted' AND IFNULL(total_count,0) <= 0 THEN 1
          ELSE 0 END) AS fail_count
      FROM college_exam_attempts
      WHERE exam_id IN ({$inSql})
      GROUP BY exam_id
    ");
    if ($aq) {
        while ($ar = mysqli_fetch_assoc($aq)) {
            $attemptStatsByExam[(int)$ar['exam_id']] = [
                'taking_count' => (int)($ar['taking_count'] ?? 0),
                'submitted_count' => (int)($ar['submitted_count'] ?? 0),
                'avg_score' => $ar['avg_score'] !== null ? (float)$ar['avg_score'] : null,
                'pass_count' => (int)($ar['pass_count'] ?? 0),
                'fail_count' => (int)($ar['fail_count'] ?? 0),
            ];
        }
        mysqli_free_result($aq);
    }
}

$countTotal = count($rawList);
$countPublished = 0;
$countDraft = 0;
$countFinished = 0;
$list = [];
foreach ($rawList as $row) {
    $isPublished = !empty($row['is_published']);
    $isFinished = !empty($row['deadline']) && (string)$row['deadline'] < $now;
    $eid = (int)($row['exam_id'] ?? 0);
    $stats = $attemptStatsByExam[$eid] ?? ['taking_count' => 0, 'submitted_count' => 0, 'avg_score' => null, 'pass_count' => 0, 'fail_count' => 0];
    $submittedCount = (int)$stats['submitted_count'];
    $allFinishedOpenExam = college_exam_finished_all_submitted_no_deadline($conn, $row, $submittedCount);
    if ($allFinishedOpenExam) {
        $isFinished = true;
    }
    $isOpenBySchedule = $isPublished
        && (empty($row['available_from']) || (string)$row['available_from'] <= $now)
        && (empty($row['deadline']) || (string)$row['deadline'] >= $now);
    $isRunning = $isOpenBySchedule && !$isFinished;
    if ($isPublished) { $countPublished++; } else { $countDraft++; }
    if ($isFinished) { $countFinished++; }

    if ($search !== '') {
        $needle = mb_strtolower($search);
        $hay = mb_strtolower((string)($row['title'] ?? '') . ' ' . (string)($row['description'] ?? ''));
        if (mb_strpos($hay, $needle) === false) {
            continue;
        }
    }
    if ($pubFilter === 'published' && !$isPublished) { continue; }
    if ($pubFilter === 'draft' && $isPublished) { continue; }
    if ($pubFilter === 'finished' && !$isFinished) { continue; }
    $row['_taking_count'] = (int)$stats['taking_count'];
    $row['_submitted_count'] = $submittedCount;
    $row['_avg_score'] = $stats['avg_score'];
    $row['_pass_count'] = (int)$stats['pass_count'];
    $row['_fail_count'] = (int)$stats['fail_count'];
    $row['_is_finished'] = $isFinished;
    $row['_is_running'] = $isRunning;
    $row['_all_finished_open_exam'] = $allFinishedOpenExam;
    $list[] = $row;
}

usort($list, static function ($a, $b) use ($sort) {
    $ta = strtotime((string)($a['updated_at'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['updated_at'] ?? '')) ?: 0;
    $da = strtotime((string)($a['deadline'] ?? '')) ?: 0;
    $db = strtotime((string)($b['deadline'] ?? '')) ?: 0;
    switch ($sort) {
        case 'updated_asc': return $ta <=> $tb;
        case 'title_asc': return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        case 'title_desc': return strcasecmp((string)($b['title'] ?? ''), (string)($a['title'] ?? ''));
        case 'deadline_asc': return $da <=> $db;
        case 'deadline_desc': return $db <=> $da;
        case 'updated_desc':
        default: return $tb <=> $ta;
    }
});

$msg = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
$deleteFeedback = $_SESSION['delete_feedback'] ?? null;
unset($_SESSION['delete_feedback']);
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
      border-radius: .75rem; border: 1px solid rgba(22,163,74,.22); overflow: visible;
      background: linear-gradient(180deg, #f4fff8 0%, #fff 40%);
      box-shadow: 0 10px 28px -22px rgba(21,128,61,.58), 0 1px 0 rgba(255,255,255,.8) inset;
      transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .table-card:hover { transform: translateY(-2px); border-color: rgba(22,163,74,.38); box-shadow: 0 20px 34px -24px rgba(15,118,110,.4); }
    .toolbar-sticky { position: sticky; top: .6rem; z-index: 45; }
    .toolbar-wrap { display:flex; flex-direction:column; gap:.8rem; }
    .toolbar-top { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:.75rem; align-items:center; }
    .search-sort-form { display:flex; flex-wrap:wrap; gap:.55rem; align-items:center; }
    .search-input { flex:1 1 320px; min-width:220px; border:1px solid #ccefdc; border-radius:.65rem; background:#fff; padding:.58rem .72rem; font-size:.86rem; color:#14532d; }
    .sort-select { border:1px solid #ccefdc; border-radius:.65rem; background:#fff; padding:.56rem .68rem; font-size:.84rem; color:#14532d; font-weight:700; }
    .apply-btn-prof{
      display:inline-flex;align-items:center;gap:.38rem;
      padding:.52rem .95rem;border-radius:.62rem;
      border:1px solid #15803d;
      background:linear-gradient(135deg,#16a34a 0%,#15803d 100%);
      color:#fff;font-size:.82rem;font-weight:800;
      box-shadow:0 10px 18px -16px rgba(21,128,61,.9);
      transition:transform .2s ease,box-shadow .2s ease,background-color .2s ease,border-color .2s ease;
    }
    .apply-btn-prof:hover{
      transform:translateY(-1px);
      border-color:#166534;
      background:linear-gradient(135deg,#15803d 0%,#166534 100%);
      box-shadow:0 14px 22px -18px rgba(22,101,52,.9);
    }
    .chip { display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .7rem; border-radius:999px; border:1px solid #cceddc; background:#fff; color:#14532d; font-size:.76rem; font-weight:800; transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease; }
    .chip:hover { transform:translateY(-1px); border-color:#86efac; box-shadow:0 8px 16px -16px rgba(20,83,45,.8); }
    .chip.is-active { background:#15803d; color:#fff; border-color:#15803d; }
    .chip.chip-finished { border-color:#e9ddff; color:#6b21a8; background:#fff; }
    .chip.chip-finished:hover { border-color:#d8b4fe; box-shadow:0 8px 16px -16px rgba(107,33,168,.55); }
    .chip.chip-finished.is-active { background:linear-gradient(135deg,#a855f7 0%,#7c3aed 100%); border-color:#7c3aed; color:#fff; box-shadow:0 10px 20px -18px rgba(124,58,237,.8); }
    .view-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .7rem; border-radius:.55rem; border:1px solid #cceddc; background:#fff; color:#14532d; font-size:.78rem; font-weight:800; transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease; }
    .view-chip:hover { transform:translateY(-1px); border-color:#86efac; box-shadow:0 8px 16px -16px rgba(20,83,45,.8); }
    .view-chip.is-active { background:#ecfdf3; border-color:#86efac; color:#166534; }
    .counter-row { display:flex; flex-wrap:wrap; gap:.45rem; }
    .counter-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .62rem; border-radius:.55rem; border:1px solid #cceddc; background:#fff; color:#14532d; font-size:.74rem; font-weight:800; }
    .table-head { background: linear-gradient(180deg, #edfff4 0%, #f6fff9 100%); }
    .table-head th { font-size: .74rem; text-transform: uppercase; letter-spacing: .02em; font-weight: 800; color: #166534; }
    .table-row { transition: background-color .2s ease, transform .2s ease; }
    .table-row:hover { background: #f4fff8; transform: translateY(-1px); }
    .table-scroll {
      overflow-x: auto;
      overflow-y: visible;
      position: relative;
      z-index: 1;
      border-radius: .75rem;
    }
    .table-scroll::-webkit-scrollbar { height: 9px; }
    .table-scroll::-webkit-scrollbar-thumb { background: #b7dcc8; border-radius: 999px; }
    .table-scroll::-webkit-scrollbar-track { background: #ecfdf3; border-radius: 999px; }
    .publish-pill { display:inline-flex; align-items:center; gap:.32rem; padding:.2rem .55rem; border-radius:999px; font-size:.72rem; font-weight:700; border:1px solid transparent; }
    .publish-live { color:#047857; background:#ecfdf5; border-color:#a7f3d0; }
    .publish-draft { color:#6b7280; background:#f8fafc; border-color:#e2e8f0; }
    .instructions-cell { width: 230px; max-width: 230px; }
    .instructions-snippet {
      display:-webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      line-height: 1.34;
      color:#4b5563;
      max-width: 220px;
      max-height: 2.7em;
      word-break: break-word;
    }
    .title-main { font-weight:800; color:#14532d; letter-spacing:.01em; }
    .muted-meta { font-size:.78rem; color:#64748b; }
    .action-link { font-weight:700; transition: color .2s ease, transform .2s ease; display:inline-flex; align-items:center; gap:.25rem; }
    .action-link:hover { color: #14532d; transform: translateY(-1px); }
    .status-pill { display:inline-flex; align-items:center; gap:.32rem; padding:.2rem .55rem; border-radius:999px; font-size:.72rem; font-weight:800; border:1px solid transparent; }
    .status-running { color:#065f46; background:#ecfdf5; border-color:#a7f3d0; }
    .status-finished { color:#6b21a8; background:#f5f3ff; border-color:#ddd6fe; }
    .status-draft { color:#6b7280; background:#f8fafc; border-color:#e2e8f0; }
    .monitor-btn { display:inline-flex; align-items:center; gap:.35rem; padding:.38rem .64rem; border-radius:.55rem; border:1px solid #86efac; background:#ecfdf3; color:#166534; font-size:.74rem; font-weight:800; transition:all .2s ease; }
    .monitor-btn:hover { transform:translateY(-1px); border-color:#4ade80; background:#dcfce7; }
    .action-menu-wrap { position: relative; display: inline-block; text-align: left; }
    .action-menu-wrap.is-open { z-index: 95; }
    .action-menu-trigger {
      display:inline-flex; align-items:center; gap:.35rem;
      padding:.36rem .62rem; border-radius:.58rem;
      border:1px solid #bbf7d0; background:#f0fdf4; color:#166534;
      font-size:.74rem; font-weight:800;
      transition:all .2s ease;
    }
    .action-menu-trigger:hover { transform:translateY(-1px); border-color:#86efac; background:#dcfce7; }
    .action-menu {
      position:fixed; z-index:1200;
      min-width: 170px; padding:.3rem;
      border:1px solid #bbf7d0; border-radius:.62rem;
      background:#fff; box-shadow:0 16px 34px -24px rgba(21,128,61,.55);
      display:none;
    }
    .action-menu.open { display:block; }
    .action-item, .action-item-btn {
      width:100%; display:flex; align-items:center; gap:.42rem;
      padding:.42rem .52rem; border-radius:.48rem;
      font-size:.78rem; font-weight:700; text-decoration:none;
      color:#14532d; border:0; background:transparent; text-align:left;
      transition:background-color .16s ease, color .16s ease;
    }
    .action-item:hover, .action-item-btn:hover { background:#f0fdf4; color:#166534; }
    .action-item-btn.action-danger { color:#b91c1c; }
    .action-item-btn.action-danger:hover { background:#fef2f2; color:#991b1b; }
    .modal-overlay { position:fixed; inset:0; z-index:1400; background:rgba(15,23,42,.45); display:none; align-items:center; justify-content:center; padding:1rem; }
    .modal-overlay.open { display:flex; }
    .modal-card { width:100%; max-width:420px; border-radius:.85rem; border:1px solid #d1fae5; background:#fff; box-shadow:0 24px 48px -28px rgba(21,128,61,.45); padding:1rem; }
    .modal-title { margin:0; font-size:1.05rem; font-weight:900; color:#14532d; }
    .modal-desc { margin:.35rem 0 0; font-size:.86rem; color:#475569; }
    .modal-input { width:100%; margin-top:.7rem; border:1px solid #ccefdc; border-radius:.6rem; padding:.58rem .72rem; font-size:.86rem; color:#14532d; }
    .modal-actions { display:flex; justify-content:flex-end; gap:.5rem; margin-top:.85rem; }
    .btn-ghost { border:1px solid #d1d5db; background:#fff; color:#334155; border-radius:.58rem; font-weight:700; padding:.45rem .75rem; }
    .btn-danger { border:1px solid #dc2626; background:linear-gradient(135deg,#ef4444 0%,#dc2626 100%); color:#fff; border-radius:.58rem; font-weight:800; padding:.45rem .85rem; }
    .loading-spinner { width:42px; height:42px; border-radius:999px; border:4px solid #dcfce7; border-top-color:#16a34a; animation:spin .9s linear infinite; margin: .2rem auto .7rem; }
    .success-check { width:54px; height:54px; margin:.1rem auto .7rem; border-radius:999px; background:#ecfdf5; border:2px solid #86efac; display:flex; align-items:center; justify-content:center; color:#16a34a; font-size:1.5rem; animation:popIn .28s ease-out; }
    @keyframes spin { to { transform:rotate(360deg);} }
    @keyframes popIn { 0%{transform:scale(.6);opacity:.2} 100%{transform:scale(1);opacity:1} }
    .date-chip { display:inline-flex; align-items:center; gap:.36rem; padding:.2rem .5rem; border-radius:.55rem; border:1px solid #dcfce7; background:#f7fff9; color:#14532d; font-size:.74rem; font-weight:700; white-space:nowrap; }
    .date-chip.muted { border-color:#e2e8f0; background:#f8fafc; color:#64748b; }
    .exam-cards { display:grid; grid-template-columns:1fr; gap:.9rem; padding:1rem; }
    .exam-card { border:1px solid #d1fae5; border-radius:.9rem; background:linear-gradient(180deg,#f8fff9 0%,#fff 75%); box-shadow:0 12px 28px -24px rgba(21,128,61,.48), 0 1px 0 rgba(255,255,255,.9) inset; padding:1rem; display:flex; flex-direction:column; gap:.65rem; }
    .exam-card-head { display:flex; justify-content:space-between; gap:.65rem; align-items:flex-start; }
    .exam-card-title { margin:0; color:#14532d; font-size:1rem; font-weight:900; line-height:1.2; }
    .card-meta-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.52rem .72rem; }
    .meta-k { font-size:.64rem; font-weight:900; text-transform:uppercase; letter-spacing:.05em; color:#64748b; margin-bottom:.08rem; }
    .meta-v { font-size:.82rem; color:#14532d; }
    .card-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:.4rem; margin-top:auto; padding-top:.45rem; border-top:1px solid #dcfce7; }
    .card-action-btn {
      display:inline-flex; align-items:center; gap:.34rem;
      padding:.34rem .6rem; border-radius:.55rem;
      border:1px solid #ccefdc; background:#fff; color:#166534;
      font-size:.75rem; font-weight:800;
      transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease,background-color .18s ease;
    }
    .card-action-btn:hover { transform:translateY(-1px); border-color:#86efac; background:#f0fdf4; box-shadow:0 10px 18px -18px rgba(21,128,61,.75); }
    .card-action-btn.monitor { border-color:#86efac; background:#ecfdf3; color:#166534; }
    .card-action-btn.monitor:hover { background:#dcfce7; }
    .card-action-btn.delete { border-color:#fecaca; color:#b91c1c; }
    .card-action-btn.delete:hover { background:#fef2f2; border-color:#fca5a5; }
    .card-action-btn.publish { border-color:#d1d5db; color:#374151; }
    .dash-anim { opacity: 0; transform: translateY(12px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; }
    .delay-2 { animation-delay: .12s; }
    .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
    }
    @media (max-width: 980px) {
      .toolbar-top { grid-template-columns: 1fr; }
      .table-card { overflow-x:auto; }
      .card-meta-grid { grid-template-columns:1fr 1fr; }
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
    <div class="table-card toolbar-sticky dash-anim delay-2 p-4 mb-4">
      <div class="toolbar-wrap">
        <div class="toolbar-top">
          <form method="get" class="search-sort-form">
            <input type="text" name="q" value="<?php echo h($search); ?>" class="search-input" placeholder="Search by title or instructions...">
            <select name="sort" class="sort-select">
              <option value="updated_desc" <?php echo $sort === 'updated_desc' ? 'selected' : ''; ?>>Recently updated</option>
              <option value="updated_asc" <?php echo $sort === 'updated_asc' ? 'selected' : ''; ?>>Oldest updated</option>
              <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>Title A-Z</option>
              <option value="title_desc" <?php echo $sort === 'title_desc' ? 'selected' : ''; ?>>Title Z-A</option>
              <option value="deadline_asc" <?php echo $sort === 'deadline_asc' ? 'selected' : ''; ?>>Deadline (soonest)</option>
              <option value="deadline_desc" <?php echo $sort === 'deadline_desc' ? 'selected' : ''; ?>>Deadline (latest)</option>
            </select>
            <input type="hidden" name="display" value="<?php echo h($display); ?>">
            <button class="apply-btn-prof" type="submit"><i class="bi bi-search"></i> Apply</button>
          </form>
          <div class="flex flex-wrap gap-2">
            <a href="<?php echo h('?pub=' . urlencode($pubFilter) . '&sort=' . urlencode($sort) . '&q=' . urlencode($search) . '&display=list'); ?>" class="view-chip <?php echo $display === 'list' ? 'is-active' : ''; ?>"><i class="bi bi-table"></i> List view</a>
            <a href="<?php echo h('?pub=' . urlencode($pubFilter) . '&sort=' . urlencode($sort) . '&q=' . urlencode($search) . '&display=card'); ?>" class="view-chip <?php echo $display === 'card' ? 'is-active' : ''; ?>"><i class="bi bi-grid-3x3-gap"></i> Card view</a>
          </div>
        </div>
        <div class="toolbar-top">
          <div class="flex flex-wrap gap-2">
          <?php
            $pubTabs = ['all' => ['All', 'bi-grid'], 'published' => ['Published', 'bi-check-circle'], 'draft' => ['Draft', 'bi-circle'], 'finished' => ['Finished', 'bi-flag']];
            foreach ($pubTabs as $k => $tab):
              $tabUrl = '?pub=' . urlencode($k) . '&sort=' . urlencode($sort) . '&q=' . urlencode($search) . '&display=' . urlencode($display);
          ?>
            <a href="<?php echo h($tabUrl); ?>" class="chip <?php echo $k === 'finished' ? 'chip-finished ' : ''; ?><?php echo $pubFilter === $k ? 'is-active' : ''; ?>"><i class="bi <?php echo h($tab[1]); ?>"></i> <?php echo h($tab[0]); ?></a>
          <?php endforeach; ?>
          </div>
          <div class="counter-row">
            <span class="counter-chip"><i class="bi bi-grid"></i> Total: <?php echo (int)$countTotal; ?></span>
            <span class="counter-chip"><i class="bi bi-check-circle"></i> Published: <?php echo (int)$countPublished; ?></span>
            <span class="counter-chip"><i class="bi bi-pencil-square"></i> Draft: <?php echo (int)$countDraft; ?></span>
            <span class="counter-chip"><i class="bi bi-flag"></i> Finished: <?php echo (int)$countFinished; ?></span>
          </div>
        </div>
      </div>
    </div>

    <div class="table-card dash-anim delay-3">
      <?php if ($display === 'list'): ?>
      <div class="table-scroll">
      <table class="w-full text-sm text-left min-w-[1450px]">
        <thead class="table-head border-b border-green-100">
          <tr>
            <th class="px-4 py-3">Title</th>
            <th class="px-4 py-3">Instructions</th>
            <th class="px-4 py-3">Questions</th>
            <th class="px-4 py-3">Published</th>
            <th class="px-4 py-3">Published On</th>
            <th class="px-4 py-3">Opening</th>
            <th class="px-4 py-3">Deadline</th>
            <th class="px-4 py-3">Exam State</th>
            <th class="px-4 py-3 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-green-100">
          <?php if (empty($list)): ?>
          <tr><td colspan="9" class="px-4 py-10 text-center text-gray-500">No exams yet.</td></tr>
          <?php else: ?>
            <?php foreach ($list as $e): ?>
            <?php
              $isRunning = !empty($e['_is_running']);
              $isFinished = !empty($e['_is_finished']);
              $publishedOn = !empty($e['created_at']) ? date('M j, Y g:i A', strtotime((string)$e['created_at'])) : '—';
              $openingOn = !empty($e['available_from']) ? date('M j, Y g:i A', strtotime((string)$e['available_from'])) : 'Immediate';
              $deadlineOn = !empty($e['deadline']) ? date('M j, Y g:i A', strtotime((string)$e['deadline'])) : '—';
            ?>
            <tr class="table-row">
              <td class="px-4 py-3">
                <div class="title-main"><?php echo h($e['title']); ?></div>
                <div class="muted-meta mt-0.5">Updated <?php echo !empty($e['updated_at']) ? h(date('M j, g:i A', strtotime((string)$e['updated_at']))) : '—'; ?></div>
              </td>
              <td class="px-4 py-3 instructions-cell"><div class="instructions-snippet" title="<?php echo h((string)($e['description'] ?? '')); ?>"><?php echo h((string)($e['description'] ?? 'No instructions provided.')); ?></div></td>
              <td class="px-4 py-3 font-semibold text-green-800"><?php echo (int)$e['q_count']; ?></td>
              <td class="px-4 py-3"><?php echo !empty($e['is_published']) ? '<span class="publish-pill publish-live"><i class="bi bi-check-circle"></i> Live</span>' : '<span class="publish-pill publish-draft"><i class="bi bi-circle"></i> Draft</span>'; ?></td>
              <td class="px-4 py-3"><span class="date-chip"><i class="bi bi-calendar-event"></i> <?php echo h($publishedOn); ?></span></td>
              <td class="px-4 py-3"><span class="date-chip <?php echo empty($e['available_from']) ? 'muted' : ''; ?>"><i class="bi bi-play-circle"></i> <?php echo h($openingOn); ?></span></td>
              <td class="px-4 py-3"><span class="date-chip <?php echo empty($e['deadline']) ? 'muted' : ''; ?>"><i class="bi bi-hourglass-split"></i> <?php echo h($deadlineOn); ?></span></td>
              <td class="px-4 py-3">
                <?php if (empty($e['is_published'])): ?>
                  <span class="status-pill status-draft"><i class="bi bi-circle"></i> Draft</span>
                <?php elseif ($isFinished): ?>
                  <span class="status-pill status-finished"><i class="bi bi-flag"></i> Finished</span>
                <?php elseif ($isRunning): ?>
                  <span class="status-pill status-running"><i class="bi bi-broadcast"></i> Running</span>
                <?php else: ?>
                  <span class="status-pill status-draft"><i class="bi bi-clock"></i> Waiting</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <div class="action-menu-wrap" data-action-menu>
                  <button type="button" class="action-menu-trigger" data-action-menu-trigger>
                    <i class="bi bi-three-dots"></i> Actions
                  </button>
                  <div class="action-menu" data-action-menu-list>
                    <?php if (!empty($e['is_published'])): ?>
                      <a class="action-item" href="professor_exam_monitor.php?exam_id=<?php echo (int)$e['exam_id']; ?>"><i class="bi bi-speedometer2"></i> Monitor</a>
                    <?php endif; ?>
                    <a class="action-item" href="professor_exam_edit.php?id=<?php echo (int)$e['exam_id']; ?>"><i class="bi bi-pencil"></i> Edit</a>
                    <a class="action-item" href="professor_exam_edit.php?duplicate_from=<?php echo (int)$e['exam_id']; ?>"><i class="bi bi-copy"></i> Duplicate</a>
                    <form method="post" onsubmit="return confirm('Delete this exam?');">
                      <button type="button" class="action-item-btn action-danger" data-open-delete-modal data-exam-id="<?php echo (int)$e['exam_id']; ?>" data-exam-title="<?php echo h((string)$e['title']); ?>"><i class="bi bi-trash"></i> Delete</button>
                    </form>
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="action" value="toggle_publish">
                      <input type="hidden" name="exam_id" value="<?php echo (int)$e['exam_id']; ?>">
                      <button type="submit" class="action-item-btn"><i class="bi bi-megaphone"></i> <?php echo !empty($e['is_published']) ? 'Unpublish' : 'Publish'; ?></button>
                    </form>
                  </div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
      <?php else: ?>
        <?php if (empty($list)): ?>
          <div class="px-4 py-10 text-center text-gray-500">No exams yet.</div>
        <?php else: ?>
          <div class="exam-cards">
            <?php foreach ($list as $e): ?>
              <?php
                $isRunning = !empty($e['_is_running']);
                $isFinished = !empty($e['_is_finished']);
                $publishedOn = !empty($e['created_at']) ? date('M j, Y g:i A', strtotime((string)$e['created_at'])) : '—';
                $openingOn = !empty($e['available_from']) ? date('M j, Y g:i A', strtotime((string)$e['available_from'])) : 'Immediate';
                $deadlineOn = !empty($e['deadline']) ? date('M j, Y g:i A', strtotime((string)$e['deadline'])) : '—';
              ?>
              <article class="exam-card">
                <div class="exam-card-head">
                  <div>
                    <h3 class="exam-card-title"><?php echo h((string)$e['title']); ?></h3>
                    <div class="muted-meta mt-1 instructions-snippet" title="<?php echo h((string)($e['description'] ?? '')); ?>"><?php echo h((string)($e['description'] ?: 'No instructions provided.')); ?></div>
                  </div>
                  <div>
                    <?php if (empty($e['is_published'])): ?>
                      <span class="status-pill status-draft"><i class="bi bi-circle"></i> Draft</span>
                    <?php elseif ($isFinished): ?>
                      <span class="status-pill status-finished"><i class="bi bi-flag"></i> Finished</span>
                    <?php elseif ($isRunning): ?>
                      <span class="status-pill status-running"><i class="bi bi-broadcast"></i> Running</span>
                    <?php else: ?>
                      <span class="status-pill status-draft"><i class="bi bi-clock"></i> Waiting</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="card-meta-grid">
                  <div><div class="meta-k">Published On</div><div class="meta-v"><?php echo h($publishedOn); ?></div></div>
                  <div><div class="meta-k">Opening</div><div class="meta-v"><?php echo h($openingOn); ?></div></div>
                  <div><div class="meta-k">Deadline</div><div class="meta-v"><?php echo h($deadlineOn); ?></div></div>
                  <div><div class="meta-k">Published</div><div class="meta-v"><?php echo !empty($e['is_published']) ? 'Live' : 'Draft'; ?></div></div>
                </div>
                <div class="card-actions">
                  <?php if (!empty($e['is_published'])): ?>
                    <a class="card-action-btn monitor" href="professor_exam_monitor.php?exam_id=<?php echo (int)$e['exam_id']; ?>"><i class="bi bi-speedometer2"></i> Monitor</a>
                  <?php endif; ?>
                  <a class="card-action-btn" href="professor_exam_edit.php?id=<?php echo (int)$e['exam_id']; ?>"><i class="bi bi-pencil"></i> Edit</a>
                  <a class="card-action-btn" href="professor_exam_edit.php?duplicate_from=<?php echo (int)$e['exam_id']; ?>"><i class="bi bi-copy"></i> Duplicate</a>
                  <form method="post" class="inline" onsubmit="return confirm('Delete this exam?');">
                    <button type="button" class="card-action-btn delete" data-open-delete-modal data-exam-id="<?php echo (int)$e['exam_id']; ?>" data-exam-title="<?php echo h((string)$e['title']); ?>"><i class="bi bi-trash"></i> Delete</button>
                  </form>
                  <form method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="toggle_publish">
                    <input type="hidden" name="exam_id" value="<?php echo (int)$e['exam_id']; ?>">
                    <button type="submit" class="card-action-btn publish"><i class="bi bi-megaphone"></i> <?php echo !empty($e['is_published']) ? 'Unpublish' : 'Publish'; ?></button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
</main>
  <form id="secureDeleteForm" method="post" class="hidden">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="exam_id" id="secureDeleteExamId" value="">
    <input type="hidden" name="delete_password" id="secureDeletePasswordHidden" value="">
  </form>
  <div id="deleteConfirmModal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card">
      <h3 class="modal-title">Delete exam?</h3>
      <p class="modal-desc">Enter your professor admin password to confirm delete: <strong id="deleteExamTitle"></strong></p>
      <input id="deletePasswordInput" type="password" class="modal-input" placeholder="Enter password">
      <div class="modal-actions">
        <button type="button" id="cancelDeleteBtn" class="btn-ghost">Cancel</button>
        <button type="button" id="confirmDeleteBtn" class="btn-danger">Delete exam</button>
      </div>
    </div>
  </div>
  <div id="deleteLoadingModal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card text-center">
      <div class="loading-spinner"></div>
      <h3 class="modal-title">Validating request...</h3>
      <p class="modal-desc">Please wait while we process your action.</p>
    </div>
  </div>
  <div id="deleteResultModal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card text-center">
      <div id="deleteResultIcon"></div>
      <h3 id="deleteResultTitle" class="modal-title"></h3>
      <p id="deleteResultText" class="modal-desc"></p>
      <div class="modal-actions justify-center">
        <button type="button" id="closeDeleteResultBtn" class="btn-ghost">Close</button>
      </div>
    </div>
  </div>
  <script>
    (function () {
      var wraps = document.querySelectorAll('[data-action-menu]');
      if (!wraps.length) return;
      var menuPairs = [];
      function positionMenu(wrap, menu) {
        var trigger = wrap.querySelector('[data-action-menu-trigger]');
        if (!trigger || !menu) return;
        var rect = trigger.getBoundingClientRect();
        var menuWidth = 176;
        var wasOpen = menu.classList.contains('open');
        if (!wasOpen) {
          menu.style.visibility = 'hidden';
          menu.classList.add('open');
        }
        var menuHeight = menu.offsetHeight || 220;
        if (!wasOpen) {
          menu.classList.remove('open');
          menu.style.visibility = '';
        }
        var left = Math.max(10, rect.right - menuWidth);
        var top = rect.bottom + 6;
        var spaceBelow = window.innerHeight - rect.bottom;
        if (spaceBelow < (menuHeight + 12)) {
          var need = (menuHeight + 16) - spaceBelow;
          if (need > 0) {
            window.scrollBy({ top: need, behavior: 'smooth' });
          }
          top = Math.max(10, window.innerHeight - menuHeight - 10);
        }
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
      }
      function closeAllMenus() {
        menuPairs.forEach(function (pair) {
          var wrap = pair.wrap;
          var menu = pair.menu;
          if (menu) menu.classList.remove('open');
          wrap.classList.remove('is-open');
        });
      }
      wraps.forEach(function (wrap) {
        var trigger = wrap.querySelector('[data-action-menu-trigger]');
        var menu = wrap.querySelector('[data-action-menu-list]');
        if (!trigger || !menu) return;
        document.body.appendChild(menu);
        menuPairs.push({ wrap: wrap, menu: menu });
        trigger.addEventListener('click', function (e) {
          e.stopPropagation();
          var wasOpen = menu.classList.contains('open');
          closeAllMenus();
          if (wasOpen) return;
          positionMenu(wrap, menu);
          menu.classList.add('open');
          wrap.classList.add('is-open');
        });
        menu.addEventListener('click', function (e) { e.stopPropagation(); });
      });
      window.addEventListener('resize', closeAllMenus);
      window.addEventListener('scroll', closeAllMenus, true);
      document.addEventListener('click', closeAllMenus);

      var deleteModal = document.getElementById('deleteConfirmModal');
      var deleteLoading = document.getElementById('deleteLoadingModal');
      var deleteForm = document.getElementById('secureDeleteForm');
      var deleteExamId = document.getElementById('secureDeleteExamId');
      var deletePasswordInput = document.getElementById('deletePasswordInput');
      var deletePasswordHidden = document.getElementById('secureDeletePasswordHidden');
      var deleteExamTitle = document.getElementById('deleteExamTitle');
      var cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
      var confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
      var resultModal = document.getElementById('deleteResultModal');
      var resultIcon = document.getElementById('deleteResultIcon');
      var resultTitle = document.getElementById('deleteResultTitle');
      var resultText = document.getElementById('deleteResultText');
      var closeResultBtn = document.getElementById('closeDeleteResultBtn');

      function openDeleteModal(examId, title) {
        deleteExamId.value = String(examId || '');
        deletePasswordInput.value = '';
        deleteExamTitle.textContent = title || 'selected exam';
        deleteModal.classList.add('open');
      }
      function closeDeleteModal() { deleteModal.classList.remove('open'); }
      function openLoadingModal() { deleteLoading.classList.add('open'); }
      function closeLoadingModal() { deleteLoading.classList.remove('open'); }

      document.querySelectorAll('[data-open-delete-modal]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          closeAllMenus();
          openDeleteModal(btn.getAttribute('data-exam-id') || '', btn.getAttribute('data-exam-title') || 'selected exam');
        });
      });
      cancelDeleteBtn.addEventListener('click', closeDeleteModal);
      deleteModal.addEventListener('click', function (e) { if (e.target === deleteModal) closeDeleteModal(); });
      confirmDeleteBtn.addEventListener('click', function () {
        if (!deletePasswordInput.value.trim()) { deletePasswordInput.focus(); return; }
        deletePasswordHidden.value = deletePasswordInput.value;
        closeDeleteModal();
        openLoadingModal();
        setTimeout(function () { deleteForm.submit(); }, 260);
      });

      <?php if (is_array($deleteFeedback)): ?>
      closeLoadingModal();
      resultModal.classList.add('open');
      <?php if (!empty($deleteFeedback['ok'])): ?>
        resultIcon.innerHTML = '<div class="success-check"><i class="bi bi-check2"></i></div>';
        resultTitle.textContent = 'Delete successful';
      <?php else: ?>
        resultIcon.innerHTML = '<div class="success-check" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c;"><i class="bi bi-x-lg"></i></div>';
        resultTitle.textContent = 'Delete failed';
      <?php endif; ?>
      resultText.textContent = <?php echo json_encode((string)($deleteFeedback['text'] ?? '')); ?>;
      <?php endif; ?>
      closeResultBtn.addEventListener('click', function(){ resultModal.classList.remove('open'); });
      resultModal.addEventListener('click', function(e){ if (e.target === resultModal) resultModal.classList.remove('open'); });
    })();
  </script>
</body>
</html>
