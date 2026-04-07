<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';
require_once __DIR__ . '/includes/quiz_helpers.php';

$pageTitle = 'Exam review';
$uid = (int)getCurrentUserId();
$examId = (int)($_GET['exam_id'] ?? 0);
$studentUserId = (int)($_GET['user_id'] ?? 0);

if ($examId <= 0 || $studentUserId <= 0) {
    $_SESSION['message'] = 'Invalid exam or student.';
    header('Location: professor_exams.php');
    exit;
}

$stmt = mysqli_prepare($conn, 'SELECT * FROM college_exams WHERE exam_id=? AND created_by=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'ii', $examId, $uid);
mysqli_stmt_execute($stmt);
$exam = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$exam) {
    $_SESSION['message'] = 'Exam not found.';
    header('Location: professor_exams.php');
    exit;
}

if (!college_exam_student_on_professor_monitor_roster($conn, $examId, $studentUserId)) {
    $_SESSION['message'] = 'That student is not on this exam roster.';
    header('Location: professor_exam_monitor.php?exam_id=' . $examId);
    exit;
}

$ust = mysqli_prepare($conn, 'SELECT user_id, full_name, email, student_number, role, status FROM users WHERE user_id=? LIMIT 1');
mysqli_stmt_bind_param($ust, 'i', $studentUserId);
mysqli_stmt_execute($ust);
$studentUser = mysqli_fetch_assoc(mysqli_stmt_get_result($ust));
mysqli_stmt_close($ust);
if (!$studentUser) {
    $_SESSION['message'] = 'Student not found.';
    header('Location: professor_exam_monitor.php?exam_id=' . $examId);
    exit;
}

$attempt = null;
$ast = mysqli_prepare($conn, 'SELECT * FROM college_exam_attempts WHERE exam_id=? AND user_id=? LIMIT 1');
mysqli_stmt_bind_param($ast, 'ii', $examId, $studentUserId);
mysqli_stmt_execute($ast);
$attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($ast));
mysqli_stmt_close($ast);

$attemptSubmitted = college_exam_attempt_is_effectively_submitted($attempt);
$attemptStatus = college_exam_attempt_status_normalized($attempt);

$questions = [];
$qr = mysqli_query($conn, 'SELECT * FROM college_exam_questions WHERE exam_id=' . (int)$examId . ' ORDER BY sort_order ASC, question_id ASC');
if ($qr) {
    while ($q = mysqli_fetch_assoc($qr)) {
        $questions[] = $q;
    }
    mysqli_free_result($qr);
}

if ($attempt && ($attemptStatus === 'in_progress' || $attemptSubmitted)) {
    $questions = college_exam_prepare_questions_for_attempt($questions, $exam, (int)$attempt['attempt_id']);
}

$answersMap = [];
if ($attempt) {
    $ar = mysqli_query($conn, 'SELECT question_id, selected_answer FROM college_exam_answers WHERE attempt_id=' . (int)$attempt['attempt_id']);
    if ($ar) {
        while ($r = mysqli_fetch_assoc($ar)) {
            $answersMap[(int)$r['question_id']] = $r;
        }
        mysqli_free_result($ar);
    }
}

$examQuestionCount = 0;
$qc = @mysqli_query($conn, 'SELECT COUNT(*) AS c FROM college_exam_questions WHERE exam_id=' . (int)$examId);
if ($qc) {
    $qrow = mysqli_fetch_assoc($qc);
    $examQuestionCount = (int)($qrow['c'] ?? 0);
    mysqli_free_result($qc);
}

$timeUsedSec = null;
if ($attempt && $attemptSubmitted && !empty($attempt['started_at']) && !empty($attempt['submitted_at'])) {
    $timeUsedSec = max(0, strtotime($attempt['submitted_at']) - strtotime($attempt['started_at']));
}

$scoreLine = '—';
$markPass = null;
$scoreF = null;
$correctC = 0;
$totalC = 0;
if ($attempt && $attemptSubmitted) {
    $correctC = (int)($attempt['correct_count'] ?? 0);
    $totalC = (int)($attempt['total_count'] ?? 0);
    $scoreLine = college_exam_format_score_total_line(
        isset($attempt['correct_count']) ? (int)$attempt['correct_count'] : null,
        isset($attempt['total_count']) ? (int)$attempt['total_count'] : null,
        $attempt['score'] ?? null,
        $examQuestionCount
    );
    $scoreF = $totalC > 0
        ? college_exam_compute_score_percentage($correctC, $totalC)
        : (is_numeric($attempt['score'] ?? null) ? (float)$attempt['score'] : null);
    $markPass = college_exam_is_pass_half_correct(
        isset($attempt['correct_count']) ? (int)$attempt['correct_count'] : null,
        isset($attempt['total_count']) ? (int)$attempt['total_count'] : null,
        $examQuestionCount
    );
}

$navPills = [];
if ($attemptSubmitted && $questions !== []) {
    $qi = 0;
    foreach ($questions as $q) {
        $qi++;
        $qid = (int)$q['question_id'];
        $sel = strtoupper(trim((string)($answersMap[$qid]['selected_answer'] ?? '')));
        $hasAns = $sel !== '';
        $cor = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
        if (!$hasAns) {
            $navPills[] = ['n' => $qi, 'kind' => 'empty'];
        } elseif ($hasAns && $sel === $cor) {
            $navPills[] = ['n' => $qi, 'kind' => 'ok'];
        } else {
            $navPills[] = ['n' => $qi, 'kind' => 'bad'];
        }
    }
}

$backHref = 'professor_exam_monitor.php?exam_id=' . (int)$examId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .perm-page { background: linear-gradient(180deg, #eefaf3 0%, #e6f6ee 45%, #edf9f2 100%); min-height: 100%; }
    .perm-topbar {
      position: sticky; top: 0; z-index: 40;
      border-bottom: 1px solid rgba(22,163,74,.2);
      background: linear-gradient(180deg, rgba(255,255,255,.94) 0%, rgba(248,255,252,.92) 100%);
      backdrop-filter: blur(10px);
      box-shadow: 0 8px 24px -18px rgba(15,80,45,.35);
    }
    .perm-hero {
      border-radius: 1rem;
      border: 1px solid rgba(255,255,255,.28);
      background: linear-gradient(135deg, #0f766e 0%, #0e9f6e 38%, #15803d 92%);
      box-shadow: 0 18px 40px -24px rgba(5,46,22,.65), inset 0 1px 0 rgba(255,255,255,.2);
    }
    .perm-stat-pill {
      display: inline-flex; align-items: center; gap: .35rem;
      padding: .35rem .7rem; border-radius: 999px; font-size: .78rem; font-weight: 800;
      border: 1px solid rgba(255,255,255,.35); background: rgba(255,255,255,.12); color: #fff;
    }
    .perm-layout { display: grid; grid-template-columns: minmax(0, 1fr) 200px; gap: 1.25rem; align-items: start; }
    @media (max-width: 1100px) { .perm-layout { grid-template-columns: 1fr; } }
    .perm-toc {
      position: sticky; top: 5.5rem;
      border-radius: .85rem;
      border: 1px solid rgba(22,163,74,.22);
      background: linear-gradient(180deg, #f4fff8 0%, #fff 55%);
      box-shadow: 0 12px 28px -22px rgba(21,128,61,.45);
      padding: .85rem .75rem;
      max-height: calc(100vh - 6.5rem);
      overflow: auto;
    }
    .perm-toc-title { font-size: .68rem; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; color: #166534; margin: 0 0 .5rem; }
    .perm-toc-grid { display: flex; flex-wrap: wrap; gap: .35rem; }
    .perm-toc-btn {
      width: 2.1rem; height: 2.1rem; border-radius: .5rem; font-size: .78rem; font-weight: 900;
      display: inline-flex; align-items: center; justify-content: center; text-decoration: none;
      border: 1px solid transparent; transition: transform .12s ease, box-shadow .12s ease;
    }
    .perm-toc-btn:hover { transform: translateY(-1px); }
    .perm-toc-ok { background: #ecfdf5; border-color: #6ee7b7; color: #047857; }
    .perm-toc-bad { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
    .perm-toc-empty { background: #f8fafc; border-color: #e2e8f0; color: #64748b; }
    .perm-q-card {
      border-radius: 1rem;
      border: 1px solid rgba(22,163,74,.18);
      background: linear-gradient(180deg, #fff 0%, #fafffe 100%);
      box-shadow: 0 14px 32px -26px rgba(15,80,45,.4);
      scroll-margin-top: 6.5rem;
    }
    .perm-q-bar { height: 4px; border-radius: 1rem 1rem 0 0; }
    .perm-q-bar.ok { background: linear-gradient(90deg, #10b981, #34d399); }
    .perm-q-bar.bad { background: linear-gradient(90deg, #ef4444, #f87171); }
    .perm-q-bar.empty { background: linear-gradient(90deg, #94a3b8, #cbd5e1); }
    .perm-badge {
      font-size: .68rem; font-weight: 900; text-transform: uppercase; letter-spacing: .06em;
      padding: .2rem .5rem; border-radius: 999px; display: inline-flex; align-items: center; gap: .25rem;
    }
    .perm-badge.ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .perm-badge.bad { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .perm-badge.empty { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; }
    .perm-choice {
      display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem;
      padding: .65rem .85rem; border-radius: .65rem; border: 1px solid #e2e8f0; font-size: .88rem;
    }
    .perm-choice.cor { border-color: #6ee7b7; background: #f0fdf4; }
    .perm-choice.wrong-pick { border-color: #fecaca; background: #fef2f2; }
    .perm-choice.neutral { background: #fff; color: #475569; }
    .perm-expl { border-radius: .65rem; border: 1px dashed rgba(22,163,74,.35); background: rgba(236,253,245,.65); padding: .75rem 1rem; font-size: .86rem; color: #14532d; }
    .perm-empty-panel {
      border-radius: 1rem; border: 1px solid #e2e8f0; background: #fff;
      box-shadow: 0 14px 32px -26px rgba(15,23,42,.2); padding: 2.5rem 1.5rem; text-align: center;
    }
  </style>
</head>
<body class="font-sans antialiased perm-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>
  <main class="dashboard-shell w-full max-w-none">
    <div class="perm-topbar px-4 py-3 mb-4">
      <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between max-w-6xl mx-auto">
        <div class="flex flex-wrap items-center gap-3 min-w-0">
          <a href="<?php echo h($backHref); ?>" class="inline-flex items-center gap-2 text-sm font-bold text-emerald-800 hover:text-emerald-950 shrink-0">
            <i class="bi bi-arrow-left"></i> Back to monitor
          </a>
          <span class="hidden sm:inline text-slate-300">|</span>
          <span class="text-sm font-extrabold text-slate-800 truncate"><?php echo h((string)$exam['title']); ?></span>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-600">
          <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 border border-emerald-200 px-2.5 py-1 font-bold text-emerald-900">
            <i class="bi bi-mortarboard"></i> Instructor review
          </span>
          <span class="text-slate-400">Student answers match the order and shuffle shown to the student.</span>
        </div>
      </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 pb-16">
      <div class="perm-hero p-5 md:p-6 mb-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-widest text-white/75 m-0 mb-1">Student</p>
            <h1 class="text-2xl md:text-3xl font-black text-white m-0 leading-tight"><?php echo h((string)$studentUser['full_name']); ?></h1>
            <p class="mt-2 mb-0 text-sm text-white/90 flex flex-wrap gap-x-3 gap-y-1">
              <span><i class="bi bi-envelope"></i> <?php echo h((string)$studentUser['email']); ?></span>
              <?php $sn = trim((string)($studentUser['student_number'] ?? '')); ?>
              <?php if ($sn !== ''): ?>
                <span><i class="bi bi-hash"></i> <?php echo h($sn); ?></span>
              <?php endif; ?>
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <?php if ($attemptSubmitted): ?>
              <span class="perm-stat-pill"><i class="bi bi-clipboard-data"></i> <?php echo h($scoreLine); ?></span>
              <?php if ($markPass !== null): ?>
                <span class="perm-stat-pill" style="background:rgba(255,255,255,.2);">
                  <?php if ($markPass): ?><i class="bi bi-check-circle"></i> Pass<?php else: ?><i class="bi bi-x-circle"></i> Fail<?php endif; ?>
                </span>
              <?php endif; ?>
              <?php if ($scoreF !== null): ?>
                <span class="perm-stat-pill"><i class="bi bi-percent"></i> <?php echo h(number_format((float)$scoreF, 2)); ?>%</span>
              <?php endif; ?>
            <?php elseif ($attempt && $attemptStatus === 'in_progress'): ?>
              <span class="perm-stat-pill"><i class="bi bi-broadcast"></i> In progress</span>
            <?php else: ?>
              <span class="perm-stat-pill"><i class="bi bi-dash-circle"></i> No submission</span>
            <?php endif; ?>
            <?php if ($timeUsedSec !== null): ?>
              <span class="perm-stat-pill"><i class="bi bi-stopwatch"></i> <?php echo h(gmdate('H:i:s', $timeUsedSec)); ?> time used</span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($attemptSubmitted): ?>
          <div class="mt-5 pt-4 border-t border-white/20 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm text-white/95">
            <div><span class="text-white/70 font-bold">Started</span><br><?php echo h(college_exam_format_student_result_datetime($attempt['started_at'] ?? null)); ?></div>
            <div><span class="text-white/70 font-bold">Submitted</span><br><?php echo h(college_exam_format_student_result_datetime($attempt['submitted_at'] ?? null)); ?></div>
            <div><span class="text-white/70 font-bold">Tab leaves</span><br><?php echo (int)($attempt['tab_switch_count'] ?? 0); ?></div>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!$attempt): ?>
        <div class="perm-empty-panel">
          <div class="w-14 h-14 rounded-2xl mx-auto mb-3 flex items-center justify-center bg-slate-100 text-slate-500 text-2xl"><i class="bi bi-inbox"></i></div>
          <h2 class="text-lg font-black text-slate-900 m-0">No attempt yet</h2>
          <p class="text-slate-600 mt-2 mb-0 max-w-md mx-auto">This student has not started this exam. When they submit, you can review their full answer sheet here.</p>
        </div>
      <?php elseif (!$attemptSubmitted): ?>
        <div class="perm-empty-panel">
          <div class="w-14 h-14 rounded-2xl mx-auto mb-3 flex items-center justify-center bg-amber-50 text-amber-600 text-2xl border border-amber-100"><i class="bi bi-hourglass-split"></i></div>
          <h2 class="text-lg font-black text-slate-900 m-0">Exam not finished</h2>
          <p class="text-slate-600 mt-2 mb-0 max-w-md mx-auto">
            This attempt is still <strong><?php echo h($attemptStatus !== '' ? $attemptStatus : 'open'); ?></strong>.
            The full question-by-question review is available after the student submits.
          </p>
          <?php if (!empty($attempt['started_at'])): ?>
            <p class="text-sm text-slate-500 mt-3 mb-0">Started: <?php echo h(college_exam_format_student_result_datetime($attempt['started_at'])); ?></p>
          <?php endif; ?>
        </div>
      <?php elseif ($questions === []): ?>
        <div class="perm-empty-panel">
          <h2 class="text-lg font-black text-slate-900 m-0">No questions</h2>
          <p class="text-slate-600 mt-2 mb-0">This exam has no questions on file.</p>
        </div>
      <?php else: ?>
        <div class="perm-layout">
          <div>
            <div class="flex items-center justify-between gap-3 mb-4">
              <h2 class="text-lg font-black text-emerald-950 m-0 flex items-center gap-2">
                <i class="bi bi-journal-richtext text-emerald-600"></i> Examination sheet
              </h2>
              <span class="text-xs font-bold text-slate-500"><?php echo count($questions); ?> item(s)</span>
            </div>
            <?php
            $i = 0;
            foreach ($questions as $q):
                $i++;
                $letters = ['A' => $q['choice_a'], 'B' => $q['choice_b'], 'C' => $q['choice_c'], 'D' => $q['choice_d']];
                $sel = strtoupper(trim((string)($answersMap[(int)$q['question_id']]['selected_answer'] ?? '')));
                $hasAns = $sel !== '';
                $cor = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
                $isCorrect = $hasAns && $sel === $cor;
                $barKind = !$hasAns ? 'empty' : ($isCorrect ? 'ok' : 'bad');
                ?>
              <article class="perm-q-card mb-5 overflow-hidden" id="perm-q<?php echo (int)$i; ?>">
                <div class="perm-q-bar <?php echo h($barKind); ?>"></div>
                <div class="p-5 md:p-6">
                  <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                    <div class="flex items-start gap-3 min-w-0">
                      <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white font-black text-lg shadow-sm"><?php echo (int)$i; ?></span>
                      <div class="min-w-0">
                        <div class="question-text text-slate-900 font-semibold leading-relaxed"><?php echo renderQuizRichText($q['question_text']); ?></div>
                      </div>
                    </div>
                    <?php if ($isCorrect): ?>
                      <span class="perm-badge ok"><i class="bi bi-check-circle-fill"></i> Correct</span>
                    <?php elseif ($hasAns): ?>
                      <span class="perm-badge bad"><i class="bi bi-x-circle-fill"></i> Incorrect</span>
                    <?php else: ?>
                      <span class="perm-badge empty"><i class="bi bi-dash-lg"></i> No answer</span>
                    <?php endif; ?>
                  </div>
                  <?php if (!$hasAns): ?>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-950 text-sm font-bold mb-4 flex items-center gap-2">
                      <i class="bi bi-exclamation-octagon-fill"></i> No answer submitted for this item.
                    </div>
                  <?php endif; ?>
                  <div class="space-y-2">
                    <?php foreach ($letters as $L => $txt):
                        if ($txt === null || $txt === '') {
                            continue;
                        }
                        $isCor = $cor === $L;
                        $picked = $hasAns && $sel === $L;
                        $cls = 'perm-choice neutral';
                        if ($isCor) {
                            $cls = 'perm-choice cor';
                        } elseif ($picked) {
                            $cls = 'perm-choice wrong-pick';
                        }
                        ?>
                    <div class="<?php echo h($cls); ?>">
                      <div class="min-w-0">
                        <span class="font-mono font-black text-slate-500 w-7 inline-block"><?php echo h($L); ?>.</span>
                        <?php echo nl2br(h((string)$txt)); ?>
                      </div>
                      <div class="shrink-0 text-xs font-extrabold uppercase tracking-wide">
                        <?php if ($isCor): ?>
                          <span class="text-emerald-700">Correct</span>
                        <?php elseif ($picked): ?>
                          <span class="text-red-700">Student</span>
                        <?php else: ?>
                          <span class="text-slate-300">—</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <?php
                    $explanation = '';
                    if (!empty($q['explanation'])) {
                        $explanation = (string)$q['explanation'];
                    } elseif (!empty($q['question_explanation'])) {
                        $explanation = (string)$q['question_explanation'];
                    }
                    ?>
                  <div class="perm-expl mt-4">
                    <span class="font-black text-emerald-900">Explanation</span>
                    <span class="block mt-1.5 leading-relaxed"><?php echo $explanation !== '' ? nl2br(h($explanation)) : 'No explanation provided for this question.'; ?></span>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <?php if ($navPills !== []): ?>
          <aside class="perm-toc hidden lg:block" aria-label="Jump to question">
            <p class="perm-toc-title">Jump</p>
            <div class="perm-toc-grid">
              <?php foreach ($navPills as $p): ?>
                <a class="perm-toc-btn perm-toc-<?php echo h($p['kind']); ?>" href="#perm-q<?php echo (int)$p['n']; ?>"><?php echo (int)$p['n']; ?></a>
              <?php endforeach; ?>
            </div>
            <p class="text-[0.65rem] text-slate-500 mt-3 m-0 leading-snug">Green = correct · red = wrong · gray = blank</p>
          </aside>
          <?php endif; ?>
        </div>

        <div class="lg:hidden mt-2 rounded-xl border border-emerald-100 bg-white/90 p-3">
          <p class="text-xs font-black text-emerald-900 uppercase tracking-wide m-0 mb-2">Jump to question</p>
          <div class="flex flex-wrap gap-2">
            <?php foreach ($navPills as $p): ?>
              <a class="perm-toc-btn perm-toc-<?php echo h($p['kind']); ?>" href="#perm-q<?php echo (int)$p['n']; ?>"><?php echo (int)$p['n']; ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
