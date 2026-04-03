<?php
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';

$pageTitle = 'Exams';
$uid = getCurrentUserId();
$uidInt = (int)($uid ?? 0);
if ($uidInt > 0) {
    college_exam_finalize_expired_in_progress($conn, 0, $uidInt, 0);
}
$now = date('Y-m-d H:i:s');

$q = trim((string)($_GET['q'] ?? ''));
$view = (string)($_GET['view'] ?? 'all'); // all|open|upcoming|finished|missed
$sort = (string)($_GET['sort'] ?? 'deadline_asc'); // deadline_asc|deadline_desc|title_asc|title_desc|recent
$display = (string)($_GET['display'] ?? 'list'); // list|card
$validViews = ['all', 'open', 'upcoming', 'finished', 'completed', 'missed'];
$validSorts = ['deadline_asc', 'deadline_desc', 'title_asc', 'title_desc', 'recent'];
$validDisplays = ['list', 'card'];
if ($view === 'completed') { $view = 'finished'; }
if (!in_array($view, $validViews, true)) { $view = 'all'; }
if (!in_array($sort, $validSorts, true)) { $sort = 'deadline_asc'; }
if (!in_array($display, $validDisplays, true)) { $display = 'list'; }

function college_exam_available(array $e, string $now): bool {
    if (!college_exam_row_is_published($e)) {
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
function college_exam_relative_deadline(?string $deadline, string $now): string {
    if (!$deadline) { return 'No deadline'; }
    $d = strtotime($deadline);
    $n = strtotime($now);
    if ($d === false || $n === false) { return '—'; }
    $diff = $d - $n;
    $abs = abs($diff);
    $days = (int)floor($abs / 86400);
    $hours = (int)floor(($abs % 86400) / 3600);
    $mins = (int)floor(($abs % 3600) / 60);
    if ($diff >= 0) {
        if ($days > 0) return 'Due in ' . $days . 'd ' . $hours . 'h';
        if ($hours > 0) return 'Due in ' . $hours . 'h ' . $mins . 'm';
        return 'Due in ' . max(1, $mins) . 'm';
    }
    if ($days > 0) return $days . 'd late';
    if ($hours > 0) return $hours . 'h late';
    return max(1, $mins) . 'm late';
}

$examRows = college_exams_load_published_exams($conn);
$qCountByExam = [];
$profNameById = [];
$attemptByExam = [];
if ($examRows !== [] && $uidInt > 0) {
    $ids = array_values(array_unique(array_map(static function ($r) {
        return (int)($r['exam_id'] ?? 0);
    }, $examRows)));
    $ids = array_values(array_filter($ids, static function ($id) { return $id > 0; }));
    if ($ids !== []) {
        $inSql = implode(',', $ids);
        $aq = mysqli_query($conn, "
          SELECT exam_id, status AS attempt_status, score, correct_count, total_count, submitted_at, started_at
          FROM college_exam_attempts
          WHERE user_id = {$uidInt} AND exam_id IN ({$inSql})
        ");
        if ($aq) {
            while ($ar = mysqli_fetch_assoc($aq)) {
                $eid = (int)($ar['exam_id'] ?? 0);
                if ($eid > 0) {
                    $attemptByExam[$eid] = $ar;
                }
            }
            mysqli_free_result($aq);
        }
    }
}

if ($examRows !== []) {
    $examIds = array_values(array_unique(array_filter(array_map(static function ($r) {
        return (int)($r['exam_id'] ?? 0);
    }, $examRows), static function ($v) { return $v > 0; })));
    if ($examIds !== []) {
        $inExamIds = implode(',', $examIds);
        $qcr = mysqli_query($conn, "SELECT exam_id, COUNT(*) AS c FROM college_exam_questions WHERE exam_id IN ({$inExamIds}) GROUP BY exam_id");
        if ($qcr) {
            while ($qr = mysqli_fetch_assoc($qcr)) {
                $qCountByExam[(int)$qr['exam_id']] = (int)($qr['c'] ?? 0);
            }
            mysqli_free_result($qcr);
        }
    }

    $creatorIds = array_values(array_unique(array_filter(array_map(static function ($r) {
        return (int)($r['created_by'] ?? 0);
    }, $examRows), static function ($v) { return $v > 0; })));
    if ($creatorIds !== []) {
        $inCreatorIds = implode(',', $creatorIds);
        $ur = mysqli_query($conn, "SELECT user_id, full_name FROM users WHERE user_id IN ({$inCreatorIds})");
        if ($ur) {
            while ($u = mysqli_fetch_assoc($ur)) {
                $profNameById[(int)$u['user_id']] = (string)($u['full_name'] ?? 'Professor');
            }
            mysqli_free_result($ur);
        }
    }
}

$submittedCountByExam = [];
if ($examRows !== []) {
    $subEids = array_values(array_unique(array_filter(array_map(static function ($r) {
        return (int)($r['exam_id'] ?? 0);
    }, $examRows), static function ($v) { return $v > 0; })));
    if ($subEids !== []) {
        $inSub = implode(',', $subEids);
        $sqc = mysqli_query($conn, "
          SELECT exam_id,
            SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) AS submitted_count
          FROM college_exam_attempts
          WHERE exam_id IN ({$inSub})
          GROUP BY exam_id
        ");
        if ($sqc) {
            while ($sr = mysqli_fetch_assoc($sqc)) {
                $submittedCountByExam[(int)$sr['exam_id']] = (int)($sr['submitted_count'] ?? 0);
            }
            mysqli_free_result($sqc);
        }
    }
}

$list = [];
$countMap = ['all' => 0, 'open' => 0, 'upcoming' => 0, 'finished' => 0, 'missed' => 0];
foreach ($examRows as $row) {
    $eid = (int)($row['exam_id'] ?? 0);
    if ($eid > 0 && isset($attemptByExam[$eid])) {
        $row = array_merge($row, $attemptByExam[$eid]);
    } else {
        $row['attempt_status'] = null;
        $row['score'] = null;
        $row['correct_count'] = null;
        $row['total_count'] = null;
        $row['submitted_at'] = null;
        $row['started_at'] = null;
    }
    $st = (string)($row['attempt_status'] ?? '');
    $submittedAllClass = (int)($submittedCountByExam[$eid] ?? 0);
    $globalDoneNoDeadline = college_exam_finished_all_submitted_no_deadline($conn, $row, $submittedAllClass);
    $isAvail = college_exam_available($row, $now);
    if ($globalDoneNoDeadline && $st !== 'in_progress') {
        $isAvail = false;
    }
    $isUpcoming = (!empty($row['available_from']) && $row['available_from'] > $now);
    $isMissed = (!empty($row['deadline']) && $row['deadline'] < $now && $st !== 'submitted');
    $bucket = 'all';
    if ($st === 'submitted') {
        $bucket = 'finished';
    } elseif ($globalDoneNoDeadline && $st === 'in_progress') {
        $bucket = 'open';
    } elseif ($isMissed || $st === 'expired') {
        $bucket = 'missed';
    } elseif ($isUpcoming) {
        $bucket = 'upcoming';
    } elseif ($isAvail) {
        $bucket = 'open';
    } elseif ($globalDoneNoDeadline) {
        $bucket = 'missed';
    }
    $countMap['all']++;
    $countMap[$bucket]++;

    if ($q !== '') {
        $needle = mb_strtolower($q);
        $hay = mb_strtolower((string)($row['title'] ?? '') . ' ' . (string)($row['description'] ?? ''));
        if (mb_strpos($hay, $needle) === false) {
            continue;
        }
    }
    if ($view !== 'all' && $bucket !== $view) {
        continue;
    }
    $row['_bucket'] = $bucket;
    $row['_available'] = $isAvail;
    $row['_relative_deadline'] = college_exam_relative_deadline($row['deadline'] ?? null, $now);
    $row['_q_count'] = (int)($qCountByExam[$eid] ?? 0);
    $creatorId = (int)($row['created_by'] ?? 0);
    $row['_prof_name'] = $profNameById[$creatorId] ?? 'Professor';
    $list[] = $row;
}

usort($list, static function ($a, $b) use ($sort) {
    $ta = strtotime((string)($a['deadline'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['deadline'] ?? '')) ?: 0;
    $ca = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $cb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
    switch ($sort) {
        case 'deadline_desc': return $tb <=> $ta;
        case 'title_asc': return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        case 'title_desc': return strcasecmp((string)($b['title'] ?? ''), (string)($a['title'] ?? ''));
        case 'recent': return $cb <=> $ca;
        case 'deadline_asc':
        default: return $ta <=> $tb;
    }
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .college-page-shell{width:100%;max-width:none;padding:0 0 2rem;}
    .dash-anim{animation:dashFadeUp .55s ease-out both;}
    .delay-1{animation-delay:.05s}.delay-2{animation-delay:.11s}.delay-3{animation-delay:.17s}
    @keyframes dashFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
    .cstu-hero{border-radius:.75rem;border:1px solid rgba(255,255,255,.28);background:linear-gradient(130deg,#1665A0 0%,#145a8f 38%,#143D59 100%);box-shadow:0 14px 34px -20px rgba(20,61,89,.85),inset 0 1px 0 rgba(255,255,255,.22);}
    .cstu-hero-btn{border-radius:9999px;transition:transform .2s ease,box-shadow .2s ease;background-color .2s ease;}
    .cstu-hero-btn:hover{transform:translateY(-2px);box-shadow:0 12px 22px -20px rgba(14,64,105,.95);}
    .section-title{display:flex;align-items:center;gap:.5rem;margin:0 0 .85rem;padding:.45rem .65rem;border:1px solid #d8e8f6;border-radius:.62rem;background:linear-gradient(180deg,#f4f9fe 0%,#fff 100%);color:#143D59;font-size:1.03rem;font-weight:800;}
    .section-title i{width:1.55rem;height:1.55rem;border-radius:.45rem;display:inline-flex;align-items:center;justify-content:center;border:1px solid #b9daf2;background:#e8f2fa;color:#1665A0;font-size:.83rem;}
    .dash-card{border-radius:.75rem;border:1px solid rgba(22,101,160,.18);background:linear-gradient(180deg,#f8fbff 0%,#fff 60%);box-shadow:0 10px 28px -22px rgba(20,61,89,.55),0 1px 0 rgba(255,255,255,.85) inset;}
    .toolbar-wrap{display:flex;flex-direction:column;gap:.85rem}
    .toolbar-sticky{position:sticky;top:.6rem;z-index:45}
    .toolbar-top{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.75rem;align-items:center}
    .search-sort-form{display:flex;flex-wrap:wrap;gap:.55rem;align-items:center}
    .search-input{flex:1 1 320px;min-width:220px;border:1px solid #d6e8f7;background:#fff;border-radius:.65rem;padding:.58rem .72rem;font-size:.86rem;color:#0f172a;box-shadow:inset 0 1px 0 rgba(255,255,255,.7)}
    .sort-select{border:1px solid #d6e8f7;background:#fff;border-radius:.65rem;padding:.56rem .68rem;font-size:.84rem;color:#0f2f52;font-weight:700}
    .toolbar-footer{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.65rem}
    .filters-row{display:flex;flex-wrap:wrap;gap:.45rem}
    .counter-row{display:flex;flex-wrap:wrap;gap:.45rem}
    .counter-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .62rem;border-radius:.55rem;border:1px solid #dbe7f5;background:#fff;color:#1e3a5f;font-size:.74rem;font-weight:800;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}
    .counter-chip:hover{transform:translateY(-1px);border-color:#a8cfee;box-shadow:0 8px 16px -16px rgba(20,61,89,.7)}
    .filter-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .7rem;border-radius:999px;border:1px solid #dbe7f5;background:#fff;color:#143D59;font-size:.76rem;font-weight:700;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease;}
    .filter-pill:hover{transform:translateY(-1px);border-color:#a8cfee;box-shadow:0 8px 16px -16px rgba(20,61,89,.65)}
    .filter-pill.is-active{background:#1665A0;color:#fff;border-color:#1665A0;}
    .filter-pill.filter-finished{border-color:#e9ddff;color:#6b21a8;background:#fff;}
    .filter-pill.filter-finished:hover{border-color:#d8b4fe;box-shadow:0 8px 16px -16px rgba(107,33,168,.55);}
    .filter-pill.filter-finished.is-active{background:linear-gradient(135deg,#a855f7 0%,#7c3aed 100%);border-color:#7c3aed;color:#fff;box-shadow:0 10px 20px -18px rgba(124,58,237,.8);}
    .view-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .7rem;border-radius:.55rem;border:1px solid #dbe7f5;background:#fff;color:#0f3960;font-size:.78rem;font-weight:800;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease;}
    .view-chip:hover{transform:translateY(-1px);border-color:#a8cfee;box-shadow:0 8px 16px -16px rgba(20,61,89,.65)}
    .view-chip.is-active{background:#e8f2fa;border-color:#8fc0e8;color:#0a4f7e;}
    .ereview-exam-grid{min-width:1420px;}
    .score-cell-text{font-size:.76rem;font-weight:800;color:#0f3960;letter-spacing:.01em;line-height:1.3}
    .exam-grid-head,.exam-grid-row{display:grid;grid-template-columns:minmax(9rem,1.05fr) minmax(10rem,1.2fr) minmax(5rem,.52fr) minmax(8rem,.84fr) minmax(9.5rem,.95fr) minmax(9.5rem,.95fr) minmax(10.5rem,1.02fr) minmax(6.5rem,.68fr) minmax(7.25rem,.78fr) minmax(6.5rem,.65fr) minmax(8.5rem,.88fr);gap:.5rem .75rem;align-items:center;}
    .exam-grid-head{padding:.72rem 1.1rem;border-bottom:1px solid #dbe7f5;background:linear-gradient(180deg,#edf6ff 0%,#f7fbff 100%);font-size:.75rem;font-weight:800;text-transform:uppercase;color:#143D59;letter-spacing:.01em;}
    .exam-grid-row{padding:.85rem 1.1rem;border-top:1px solid #e8f0f8;transition:background-color .2s ease;}
    .exam-grid-row:hover{background:#f8fbff;transform:translateY(-1px)}
    .col-title{font-weight:800;color:#0f2f52;letter-spacing:.01em;}
    .col-instructions{font-size:.83rem;color:#475569;}
    .col-professor{font-size:.83rem;font-weight:700;color:#0f3960;}
    .col-published{font-size:.8rem;color:#64748b;}
    .col-deadline{font-size:.82rem;color:#1e3a5f;}
    .date-chip{display:inline-flex;align-items:center;gap:.34rem;padding:.2rem .52rem;border-radius:.55rem;border:1px solid #cde2f4;background:#f6fbff;color:#0f3960;font-size:.74rem;font-weight:700;white-space:nowrap;line-height:1.2;max-width:100%;}
    .date-chip.muted{border-color:#e2e8f0;background:#f8fafc;color:#64748b;}
    .date-stack{display:flex;flex-direction:column;gap:.18rem;min-width:0}
    .date-sub{font-size:.69rem;color:#64748b;font-weight:700}
    .date-sub.is-late{color:#b91c1c;font-weight:800}
    .exam-cell--published,.exam-cell--opening,.exam-cell--deadline{display:flex;align-items:flex-start;}
    .exam-grid-head > span:last-child{text-align:center;}
    .exam-cell--action{display:flex;justify-content:center;align-items:center;padding-right:.35rem;}
    .instructions-snippet{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.35;}
    .status-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .55rem;border-radius:9999px;font-size:.72rem;font-weight:700;border:1px solid transparent;}
    .status-open{color:#065f46;background:#ecfdf5;border-color:#a7f3d0;}
    .status-upcoming{color:#1e40af;background:#eff6ff;border-color:#bfdbfe;}
    .status-progress{color:#1d4ed8;background:#eff6ff;border-color:#bfdbfe;}
    .status-done{color:#047857;background:#ecfdf5;border-color:#a7f3d0;}
    .status-missed{color:#b45309;background:#fffbeb;border-color:#fde68a;}
    .status-closed{color:#64748b;background:#f8fafc;border-color:#e2e8f0;}
    .action-btn{display:inline-flex;align-items:center;gap:.35rem;padding:.44rem .82rem;border-radius:.6rem;font-size:.8rem;font-weight:800;border:1px solid #cde2f4;color:#1665A0;background:#fff;transition:all .2s ease;box-shadow:0 6px 18px -16px rgba(20,61,89,.55);}
    .action-btn:hover{transform:translateY(-1px);background:#f4f9fe;border-color:#8fc0e8;}
    .action-btn.action-review{background:#f8fbff;color:#0a4f7e;border-color:#bfe0f7;}
    .action-btn.action-review:hover{background:#eef6ff;border-color:#8fc0e8;}
    .action-btn.action-start{background:linear-gradient(135deg,#1665A0 0%,#0d4f80 100%);color:#fff;border-color:#1665A0;box-shadow:0 10px 20px -16px rgba(13,79,128,.95);}
    .action-btn.action-start:hover{background:linear-gradient(135deg,#145a8f 0%,#0b436c 100%);border-color:#145a8f;}
    .action-closed-pill{display:inline-flex;align-items:center;gap:.32rem;padding:.4rem .75rem;border-radius:.65rem;font-size:.74rem;font-weight:800;color:#44403c;background:linear-gradient(180deg,#fafaf9 0%,#f5f5f4 100%);border:1px solid #d6d3d1;box-shadow:0 4px 12px -8px rgba(28,25,23,.35),inset 0 1px 0 rgba(255,255,255,.9);white-space:nowrap;}
    .action-closed-pill i{font-size:.85rem;opacity:.85;}
    .empty-state{color:#64748b;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:2rem 1rem;}
    .empty-state i{color:#1665A0;background:#eef6ff;border:1px solid #cde2f4;border-radius:9999px;padding:.7rem;font-size:1.1rem;margin-bottom:.65rem;}
    .exam-cards{display:grid;grid-template-columns:1fr;gap:.9rem;padding:1rem;}
    .exam-card{
      position:relative;
      border:1px solid #dbe7f5;
      border-radius:1rem;
      background:
        radial-gradient(120% 110% at 100% 0%, rgba(22,101,160,.08) 0%, rgba(22,101,160,0) 55%),
        linear-gradient(180deg,#ffffff 0%,#f9fcff 100%);
      box-shadow:0 14px 30px -24px rgba(20,61,89,.58), 0 1px 0 rgba(255,255,255,.95) inset;
      padding:1rem;
      display:flex;
      flex-direction:column;
      gap:.82rem;
      transition:transform .25s ease,border-color .25s ease,box-shadow .25s ease,background-color .25s ease;
      overflow:hidden;
    }
    .exam-card::after{
      content:"";
      position:absolute;
      left:0; right:0; top:0;
      height:3px;
      background:linear-gradient(90deg,#1665A0 0%,#2b7cb5 35%,#7cc7f0 100%);
      opacity:.55;
    }
    .exam-card:hover{
      transform:translateY(-3px);
      border-color:#8fc0e8;
      background-color:#fcfeff;
      box-shadow:0 22px 36px -26px rgba(20,61,89,.48), 0 2px 0 rgba(255,255,255,.9) inset;
    }
    .card-title{font-size:1.04rem;font-weight:900;color:#0f2f52;line-height:1.2;letter-spacing:.01em}
    .card-instructions{font-size:.82rem;color:#556274;line-height:1.45;min-height:2.35rem}
    .meta-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.62rem .9rem}
    .meta-k{font-size:.64rem;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.055em;margin-bottom:.12rem}
    .meta-v{font-size:.84rem;color:#0f2f52;line-height:1.35}
    .meta-v.is-highlight{font-weight:800;color:#0d4f80}
    .card-footer{
      margin-top:auto;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:.55rem;
      padding-top:.5rem;
      border-top:1px solid #e8f1fa;
    }
    .card-actions{display:flex;justify-content:flex-end;align-items:center;gap:.45rem;}
    @media (max-width: 900px){
      .toolbar-top{grid-template-columns:1fr}
      .toolbar-footer{flex-direction:column;align-items:flex-start}
      .exam-grid-head{display:none;}
      .exam-grid-row{grid-template-columns:1fr;padding:.72rem .9rem;}
      .exam-cell::before{display:block;font-size:.64rem;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.04em;margin-bottom:.15rem;}
      .exam-cell--title::before{content:'Title';}.exam-cell--instructions::before{content:'Instructions';}.exam-cell--questions::before{content:'Questions';}.exam-cell--professor::before{content:'Professor';}.exam-cell--published::before{content:'Published On';}.exam-cell--opening::before{content:'Opening';}.exam-cell--deadline::before{content:'Deadline';}.exam-cell--status::before{content:'Status';}.exam-cell--score::before{content:'Score';}.exam-cell--mark::before{content:'Mark';}.exam-cell--action::before{content:'Action';}
      .exam-cards{grid-template-columns:1fr;padding:.8rem}
      .meta-grid{grid-template-columns:1fr 1fr}
      .card-exam-outcome{grid-template-columns:1fr}
      .card-footer{flex-direction:column;align-items:flex-start}
      .card-actions{width:100%;justify-content:flex-end}
    }
    @media (prefers-reduced-motion: reduce){.dash-anim{animation:none !important;opacity:1 !important;transform:none !important;}}
  </style>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/college_student_sidebar.php'; ?>
  <div class="college-page-shell ereview-shell-no-fade pt-2">
    <section class="cstu-hero dash-anim delay-1 relative overflow-hidden mb-6 px-6 py-7">
      <div class="relative z-10 text-white">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3"><span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 border border-white/30"><i class="bi bi-journal-text"></i></span>Quizzes &amp; exams</h1>
            <p class="text-white/90 mt-2 mb-0 max-w-2xl">Open assessments, status, and quick actions in one view.</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <a href="college_student_dashboard.php" class="cstu-hero-btn inline-flex items-center gap-2 px-4 py-2.5 bg-white text-[#145a8f] font-semibold"><i class="bi bi-house-door"></i> Dashboard</a>
            <a href="college_uploads.php" class="cstu-hero-btn inline-flex items-center gap-2 px-4 py-2.5 border border-white/35 bg-white/10 text-white font-semibold"><i class="bi bi-cloud-upload"></i> Upload tasks</a>
          </div>
        </div>
      </div>
    </section>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-list-check"></i> Exam Activity</h2>
    <div class="dash-card toolbar-sticky dash-anim delay-2 p-4 mb-4">
      <div class="toolbar-wrap">
        <div class="toolbar-top">
          <form method="get" class="search-sort-form">
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search exams by title or instructions..." class="search-input">
            <select name="sort" class="sort-select">
              <option value="deadline_asc" <?php echo $sort === 'deadline_asc' ? 'selected' : ''; ?>>Deadline (soonest)</option>
              <option value="deadline_desc" <?php echo $sort === 'deadline_desc' ? 'selected' : ''; ?>>Deadline (latest)</option>
              <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>Title A-Z</option>
              <option value="title_desc" <?php echo $sort === 'title_desc' ? 'selected' : ''; ?>>Title Z-A</option>
              <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Recently created</option>
            </select>
            <input type="hidden" name="display" value="<?php echo h($display); ?>">
            <button class="action-btn" type="submit"><i class="bi bi-search"></i> Apply</button>
          </form>
          <div class="flex flex-wrap gap-2">
            <a href="<?php echo h('?view=' . urlencode($view) . '&sort=' . urlencode($sort) . '&q=' . urlencode($q) . '&display=list'); ?>" class="view-chip <?php echo $display === 'list' ? 'is-active' : ''; ?>"><i class="bi bi-table"></i> List view</a>
            <a href="<?php echo h('?view=' . urlencode($view) . '&sort=' . urlencode($sort) . '&q=' . urlencode($q) . '&display=card'); ?>" class="view-chip <?php echo $display === 'card' ? 'is-active' : ''; ?>"><i class="bi bi-grid-3x3-gap"></i> Card view</a>
          </div>
        </div>
      <div class="toolbar-footer">
      <div class="filters-row">
        <?php
          $views = [
              'all' => ['All', $countMap['all'], 'bi-grid'],
              'open' => ['Open now', $countMap['open'], 'bi-play-circle'],
              'upcoming' => ['Upcoming', $countMap['upcoming'], 'bi-clock-history'],
              'finished' => ['Finished', $countMap['finished'], 'bi-check-circle'],
              'missed' => ['Missed', $countMap['missed'], 'bi-exclamation-circle'],
          ];
          foreach ($views as $k => $v):
            $url = '?view=' . urlencode($k) . '&sort=' . urlencode($sort) . '&q=' . urlencode($q) . '&display=' . urlencode($display);
        ?>
          <a href="<?php echo h($url); ?>" class="filter-pill <?php echo $k === 'finished' ? 'filter-finished ' : ''; ?><?php echo $view === $k ? 'is-active' : ''; ?>"><i class="bi <?php echo h($v[2]); ?>"></i> <?php echo h($v[0]); ?> (<?php echo (int)$v[1]; ?>)</a>
        <?php endforeach; ?>
      </div>
      <div class="counter-row" aria-label="Exam counters">
        <span class="counter-chip"><i class="bi bi-grid"></i> Total: <?php echo (int)$countMap['all']; ?></span>
        <span class="counter-chip"><i class="bi bi-unlock"></i> Open: <?php echo (int)$countMap['open']; ?></span>
        <span class="counter-chip"><i class="bi bi-check2-circle"></i> Finished: <?php echo (int)$countMap['finished']; ?></span>
      </div>
      </div>
      </div>
      </div>
    </div>

    <div class="dash-card dash-anim delay-3 overflow-x-auto ereview-exam-grid" data-ereview-exam-count="<?php echo (int)count($list); ?>">
      <?php if ($display === 'list'): ?>
      <div class="exam-grid-head">
        <span>Title</span><span>Instructions</span><span>Questions</span><span>Professor</span><span>Published On</span><span>Opening</span><span>Deadline</span><span>Status</span><span>Score</span><span>Mark</span><span>Action</span>
      </div>
      <?php if (count($list) === 0): ?>
        <div class="empty-state"><i class="bi bi-journal-x"></i><p class="m-0 font-medium">No exams match this filter.</p><p class="m-0 mt-1 text-sm">Try another search or view.</p></div>
      <?php else: ?>
        <?php foreach ($list as $e):
          $eid = (int)($e['exam_id'] ?? 0);
          $st = (string)($e['attempt_status'] ?? '');
          $bucket = (string)($e['_bucket'] ?? 'all');
          $deadline = !empty($e['deadline']) ? date('M j, Y g:i A', strtotime((string)$e['deadline'])) : '—';
          $relative = (string)($e['_relative_deadline'] ?? '');
          $publishedOn = !empty($e['created_at']) ? date('M j, Y g:i A', strtotime((string)$e['created_at'])) : '—';
          $openingOn = !empty($e['available_from']) ? date('M j, Y g:i A', strtotime((string)$e['available_from'])) : 'Immediate';
          $desc = trim((string)($e['description'] ?? ''));
          $descText = $desc !== '' ? $desc : 'No instructions provided.';
          $showDeadlineRelative = ($relative !== '' && $st !== 'submitted');
          $deadlineSubClass = 'date-sub' . ($showDeadlineRelative && strpos($relative, 'late') !== false ? ' is-late' : '');
          $statusHtml = '<span class="status-pill status-closed"><i class="bi bi-lock"></i> Closed</span>';
          $scoreHtml = '<span class="text-slate-400 text-xs font-semibold">—</span>';
          $markHtml = '<span class="text-slate-400 text-xs font-semibold">—</span>';
          if ($st === 'submitted') {
              $scoreLine = college_exam_format_score_total_line(
                  isset($e['correct_count']) ? (int)$e['correct_count'] : null,
                  isset($e['total_count']) ? (int)$e['total_count'] : null,
                  $e['score'] ?? null,
                  (int)($e['_q_count'] ?? 0)
              );
              $scoreHtml = '<span class="score-cell-text">' . h($scoreLine) . '</span>';
              $isPass = college_exam_is_pass_half_correct(
                  isset($e['correct_count']) ? (int)$e['correct_count'] : null,
                  isset($e['total_count']) ? (int)$e['total_count'] : null,
                  (int)($e['_q_count'] ?? 0)
              );
              $markHtml = '<span class="status-pill ' . ($isPass ? 'status-done' : 'status-missed') . '"><i class="bi ' . ($isPass ? 'bi-check-circle' : 'bi-x-circle') . '"></i> ' . ($isPass ? 'Pass' : 'Fail') . '</span>';
          }
          if ($st === 'submitted') {
              $statusHtml = '<span class="status-pill status-done"><i class="bi bi-check-circle"></i> Completed</span>';
          } elseif ($st === 'in_progress') {
              $statusHtml = '<span class="status-pill status-progress"><i class="bi bi-play-circle"></i> In progress</span>';
          } elseif ($bucket === 'open') {
              $statusHtml = '<span class="status-pill status-open"><i class="bi bi-unlock"></i> Open now</span>';
          } elseif ($bucket === 'upcoming') {
              $statusHtml = '<span class="status-pill status-upcoming"><i class="bi bi-clock"></i> Upcoming</span>';
          } elseif ($bucket === 'missed' || $st === 'expired') {
              $statusHtml = '<span class="status-pill status-missed"><i class="bi bi-exclamation-circle"></i> Missed</span>';
          }

          $actionHtml = '<span class="action-closed-pill" role="status"><i class="bi bi-slash-circle"></i> Closed</span>';
          if ($st === 'submitted') {
              $actionHtml = '<a class="action-btn action-review" href="college_take_exam.php?exam_id=' . $eid . '&review=1"><i class="bi bi-eye"></i> Review result</a>';
          } elseif ($st === 'in_progress') {
              $actionHtml = '<a class="action-btn action-start" href="college_take_exam.php?exam_id=' . $eid . '"><i class="bi bi-arrow-right-circle"></i> Continue</a>';
          } elseif ($bucket === 'open') {
              $actionHtml = '<a class="action-btn action-start" href="college_take_exam.php?exam_id=' . $eid . '"><i class="bi bi-play-fill"></i> Start now</a>';
          } elseif ($bucket === 'upcoming') {
              $actionHtml = '<span class="text-slate-500">Not yet available</span>';
          }
        ?>
          <div class="exam-grid-row">
            <div class="exam-cell exam-cell--title col-title"><?php echo h((string)($e['title'] ?? 'Untitled')); ?></div>
            <div class="exam-cell exam-cell--instructions col-instructions" title="<?php echo h($descText); ?>"><span class="instructions-snippet"><?php echo h($descText); ?></span></div>
            <div class="exam-cell exam-cell--questions text-slate-700 font-semibold"><?php echo (int)($e['_q_count'] ?? 0); ?></div>
            <div class="exam-cell exam-cell--professor col-professor"><?php echo h((string)($e['_prof_name'] ?? 'Professor')); ?></div>
            <div class="exam-cell exam-cell--published col-published">
              <span class="date-chip"><i class="bi bi-calendar-event"></i> <?php echo h($publishedOn); ?></span>
            </div>
            <div class="exam-cell exam-cell--opening col-published">
              <span class="date-chip <?php echo empty($e['available_from']) ? 'muted' : ''; ?>"><i class="bi bi-play-circle"></i> <?php echo h($openingOn); ?></span>
            </div>
            <div class="exam-cell exam-cell--deadline col-deadline" title="<?php echo h((string)($e['deadline'] ?? '')); ?>">
              <?php if ($deadline === '—'): ?>
                <span class="date-chip muted"><i class="bi bi-hourglass-split"></i> —</span>
              <?php else: ?>
                <span class="date-stack">
                  <span class="date-chip"><i class="bi bi-hourglass-split"></i> <?php echo h($deadline); ?></span>
                  <?php if ($showDeadlineRelative): ?><span class="<?php echo h($deadlineSubClass); ?>"><?php echo h($relative); ?></span><?php endif; ?>
                </span>
              <?php endif; ?>
            </div>
            <div class="exam-cell exam-cell--status"><?php echo $statusHtml; ?></div>
            <div class="exam-cell exam-cell--score"><?php echo $scoreHtml; ?></div>
            <div class="exam-cell exam-cell--mark"><?php echo $markHtml; ?></div>
            <div class="exam-cell exam-cell--action whitespace-nowrap"><?php echo $actionHtml; ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php else: ?>
        <?php if (count($list) === 0): ?>
          <div class="empty-state"><i class="bi bi-journal-x"></i><p class="m-0 font-medium">No exams match this filter.</p><p class="m-0 mt-1 text-sm">Try another search or view.</p></div>
        <?php else: ?>
          <div class="exam-cards">
            <?php foreach ($list as $e):
              $eid = (int)($e['exam_id'] ?? 0);
              $st = (string)($e['attempt_status'] ?? '');
              $bucket = (string)($e['_bucket'] ?? 'all');
              $deadline = !empty($e['deadline']) ? date('M j, Y g:i A', strtotime((string)$e['deadline'])) : '—';
              $relative = (string)($e['_relative_deadline'] ?? '');
              $publishedOn = !empty($e['created_at']) ? date('M j, Y g:i A', strtotime((string)$e['created_at'])) : '—';
              $openingOn = !empty($e['available_from']) ? date('M j, Y g:i A', strtotime((string)$e['available_from'])) : 'Immediate';
              $desc = trim((string)($e['description'] ?? ''));
              $descText = $desc !== '' ? $desc : 'No instructions provided.';
              $showDeadlineRelative = ($relative !== '' && $st !== 'submitted');
              $deadlineSubClass = 'date-sub' . ($showDeadlineRelative && strpos($relative, 'late') !== false ? ' is-late' : '');

              $statusHtml = '<span class="status-pill status-closed"><i class="bi bi-lock"></i> Closed</span>';
              $scoreHtml = '<span class="text-slate-400 text-xs font-semibold">—</span>';
              $markHtml = '<span class="text-slate-400 text-xs font-semibold">—</span>';
              if ($st === 'submitted') {
                  $scoreLine = college_exam_format_score_total_line(
                      isset($e['correct_count']) ? (int)$e['correct_count'] : null,
                      isset($e['total_count']) ? (int)$e['total_count'] : null,
                      $e['score'] ?? null,
                      (int)($e['_q_count'] ?? 0)
                  );
                  $scoreHtml = '<span class="score-cell-text">' . h($scoreLine) . '</span>';
                  $isPass = college_exam_is_pass_half_correct(
                      isset($e['correct_count']) ? (int)$e['correct_count'] : null,
                      isset($e['total_count']) ? (int)$e['total_count'] : null,
                      (int)($e['_q_count'] ?? 0)
                  );
                  $markHtml = '<span class="status-pill ' . ($isPass ? 'status-done' : 'status-missed') . '"><i class="bi ' . ($isPass ? 'bi-check-circle' : 'bi-x-circle') . '"></i> ' . ($isPass ? 'Pass' : 'Fail') . '</span>';
              }
              if ($st === 'submitted') {
                  $statusHtml = '<span class="status-pill status-done"><i class="bi bi-check-circle"></i> Completed</span>';
              } elseif ($st === 'in_progress') {
                  $statusHtml = '<span class="status-pill status-progress"><i class="bi bi-play-circle"></i> In progress</span>';
              } elseif ($bucket === 'open') {
                  $statusHtml = '<span class="status-pill status-open"><i class="bi bi-unlock"></i> Open now</span>';
              } elseif ($bucket === 'upcoming') {
                  $statusHtml = '<span class="status-pill status-upcoming"><i class="bi bi-clock"></i> Upcoming</span>';
              } elseif ($bucket === 'missed' || $st === 'expired') {
                  $statusHtml = '<span class="status-pill status-missed"><i class="bi bi-exclamation-circle"></i> Missed</span>';
              }

              $actionHtml = '<span class="action-closed-pill" role="status"><i class="bi bi-slash-circle"></i> Closed</span>';
              if ($st === 'submitted') {
                  $actionHtml = '<a class="action-btn action-review" href="college_take_exam.php?exam_id=' . $eid . '&review=1"><i class="bi bi-eye"></i> Review result</a>';
              } elseif ($st === 'in_progress') {
                  $actionHtml = '<a class="action-btn action-start" href="college_take_exam.php?exam_id=' . $eid . '"><i class="bi bi-arrow-right-circle"></i> Continue</a>';
              } elseif ($bucket === 'open') {
                  $actionHtml = '<a class="action-btn action-start" href="college_take_exam.php?exam_id=' . $eid . '"><i class="bi bi-play-fill"></i> Start now</a>';
              } elseif ($bucket === 'upcoming') {
                  $actionHtml = '<span class="text-slate-500">Not yet available</span>';
              }
            ?>
              <article class="exam-card">
                <div>
                  <h3 class="m-0 card-title"><?php echo h((string)($e['title'] ?? 'Untitled')); ?></h3>
                  <p class="m-0 mt-1 card-instructions instructions-snippet" title="<?php echo h($descText); ?>"><?php echo h($descText); ?></p>
                </div>
                <div class="card-exam-outcome">
                  <div><div class="meta-k">Status</div><div class="meta-v"><?php echo $statusHtml; ?></div></div>
                  <div><div class="meta-k">Score</div><div class="meta-v"><?php echo $scoreHtml; ?></div></div>
                  <div><div class="meta-k">Mark</div><div class="meta-v"><?php echo $markHtml; ?></div></div>
                </div>
                <div class="meta-grid">
                  <div><div class="meta-k">Questions</div><div class="meta-v is-highlight"><?php echo (int)($e['_q_count'] ?? 0); ?></div></div>
                  <div><div class="meta-k">Professor</div><div class="meta-v"><?php echo h((string)($e['_prof_name'] ?? 'Professor')); ?></div></div>
                  <div><div class="meta-k">Published On</div><div class="meta-v"><span class="date-chip"><i class="bi bi-calendar-event"></i> <?php echo h($publishedOn); ?></span></div></div>
                  <div><div class="meta-k">Opening</div><div class="meta-v"><span class="date-chip <?php echo empty($e['available_from']) ? 'muted' : ''; ?>"><i class="bi bi-play-circle"></i> <?php echo h($openingOn); ?></span></div></div>
                  <div><div class="meta-k">Deadline</div><div class="meta-v"><?php if ($deadline === '—'): ?><span class="date-chip muted"><i class="bi bi-hourglass-split"></i> —</span><?php else: ?><span class="date-chip"><i class="bi bi-hourglass-split"></i> <?php echo h($deadline); ?></span><?php endif; ?><?php if ($showDeadlineRelative): ?><div class="<?php echo h($deadlineSubClass); ?> mt-1"><?php echo h($relative); ?></div><?php endif; ?></div></div>
                </div>
                <div class="card-footer">
                  <div class="text-[.72rem] text-slate-500 font-semibold">
                    <i class="bi bi-lightning-charge"></i> Quick action ready
                  </div>
                  <div class="card-actions"><?php echo $actionHtml; ?></div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
