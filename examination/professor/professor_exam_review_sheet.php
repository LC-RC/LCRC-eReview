<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/college_exam_attempt_paper.php';
require_once dirname(__DIR__, 2) . '/includes/quiz_helpers.php';

$pageTitle = 'Exam review';
$uid = (int)getCurrentUserId();
$examId = (int)($_GET['exam_id'] ?? 0);
$studentUserId = (int)($_GET['user_id'] ?? 0);

if ($examId <= 0 || $studentUserId <= 0) {
    $_SESSION['message'] = 'Invalid exam or student.';
    header('Location: professor_exams');
    exit;
}

$stmt = mysqli_prepare($conn, 'SELECT * FROM college_exams WHERE exam_id=? AND created_by=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'ii', $examId, $uid);
mysqli_stmt_execute($stmt);
$exam = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$exam) {
    $_SESSION['message'] = 'Exam not found.';
    header('Location: professor_exams');
    exit;
}

if (!college_exam_student_on_professor_monitor_roster($conn, $examId, $studentUserId)) {
    $_SESSION['message'] = 'That student is not on this exam roster.';
    header('Location: professor_exam_monitor?exam_id=' . $examId);
    exit;
}

$ust = mysqli_prepare($conn, 'SELECT user_id, full_name, email, student_number, role, status FROM users WHERE user_id=? LIMIT 1');
mysqli_stmt_bind_param($ust, 'i', $studentUserId);
mysqli_stmt_execute($ust);
$studentUser = mysqli_fetch_assoc(mysqli_stmt_get_result($ust));
mysqli_stmt_close($ust);
if (!$studentUser) {
    $_SESSION['message'] = 'Student not found.';
    header('Location: professor_exam_monitor?exam_id=' . $examId);
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
if ($attempt && ($attemptStatus === 'in_progress' || $attemptSubmitted)) {
    $questions = college_exam_questions_for_student_attempt($conn, $exam, $attempt);
}

$masterByQid = [];
$authored = [];
$aq = mysqli_query($conn, 'SELECT * FROM college_exam_questions WHERE exam_id=' . (int)$examId . ' ORDER BY sort_order ASC, question_id ASC');
if ($aq) {
    while ($q = mysqli_fetch_assoc($aq)) {
        $authored[] = $q;
    }
    mysqli_free_result($aq);
}
foreach (college_exam_canonical_authoring_rows($conn, $examId, $authored) as $cq) {
    $cqid = (int)($cq['question_id'] ?? 0);
    if ($cqid > 0) {
        $masterByQid[$cqid] = (int)($cq['display_number'] ?? 0);
    }
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

$examQuestionCount = count($questions);
if ($examQuestionCount <= 0) {
    $examQuestionCount = college_exam_configured_attempt_length($conn, $examId);
}

$timeUsedSec = null;
if ($attempt && $attemptSubmitted && !empty($attempt['started_at']) && !empty($attempt['submitted_at'])) {
    $timeUsedSec = max(0, strtotime($attempt['submitted_at']) - strtotime($attempt['started_at']));
}

$scoreLine = '-';
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
$reviewCorrectN = 0;
$reviewIncorrectN = 0;
$reviewUnansweredN = 0;
if ($attemptSubmitted && $questions !== []) {
    $qi = 0;
    foreach ($questions as $q) {
        $qi++;
        $studentN = (int)($q['_display_position'] ?? $qi);
        $qid = (int)$q['question_id'];
        $sel = strtoupper(trim((string)($answersMap[$qid]['selected_answer'] ?? '')));
        $hasAns = $sel !== '';
        $cor = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
        if (!$hasAns) {
            $navPills[] = ['n' => $studentN, 'kind' => 'empty'];
            $reviewUnansweredN++;
        } elseif ($hasAns && $sel === $cor) {
            $navPills[] = ['n' => $studentN, 'kind' => 'ok'];
            $reviewCorrectN++;
        } else {
            $navPills[] = ['n' => $studentN, 'kind' => 'bad'];
            $reviewIncorrectN++;
        }
    }
}

$backHref = 'professor_exam_monitor?exam_id=' . (int)$examId;

$pageTitle = 'Exam review';
$professorFeatureHero = true;
$adminHeroIcon = 'layout-text-window-reverse';
$adminHeroEyebrow = 'Examination review';
$adminHeroTitle = (string)$studentUser['full_name'];
$adminHeroSubtitle = (string)$exam['title'] . ' — full question-by-question review for instructors.';
$adminBreadcrumbs = [['Dashboard', 'professor_admin_dashboard'], ['Monitoring', 'professor_examination_monitor'], ['Review']];
$adminBackHref = $backHref;
$adminBackLabel = 'Back to Monitoring';
$adminHeroActions = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
  <style>
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
    .perm-review-summary {
      display: flex; flex-wrap: wrap; gap: .55rem 1.1rem; margin: 0 0 .75rem;
      font-size: .82rem; color: #475569;
    }
    .perm-review-filters { display: flex; flex-wrap: wrap; gap: .4rem; margin: 0 0 1rem; }
    .perm-filter {
      border: 1px solid rgba(147,180,230,.55); background: #fff; color: #1e3a5f;
      border-radius: 999px; padding: .28rem .75rem; font-size: .78rem; font-weight: 750; cursor: pointer;
    }
    .perm-filter.is-active { background: linear-gradient(90deg,#3b82f6,#4f46e5); color: #fff; border-color: transparent; }
    .perm-q-student-num { margin: 0 0 .15rem; font-size: .72rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #64748b; }
    .perm-q-meta { margin: 0 0 .45rem; font-size: .78rem; color: #475569; }
    .perm-answer-line { margin: .75rem 0 0; font-size: .86rem; font-weight: 650; color: #0f2744; }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page professor-admin">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <div class="examination-page-shell max-w-6xl mx-auto">
    <div class="examination-kpi-grid mb-4">
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Email</div><div class="examination-kpi-card__value text-base"><?php echo h((string)$studentUser['email']); ?></div></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Score</div><div class="examination-kpi-card__value"><?php echo $attemptSubmitted ? h($scoreLine) : '-'; ?></div></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Status</div><div class="examination-kpi-card__value text-base"><?php if ($attemptSubmitted): ?>Submitted<?php elseif ($attempt && $attemptStatus === 'in_progress'): ?>In progress<?php else: ?>No submission<?php endif; ?></div></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Tab leaves</div><div class="examination-kpi-card__value"><?php echo $attempt ? (int)($attempt['tab_switch_count'] ?? 0) : '-'; ?></div></div>
    </div>

    <div class="pb-16">
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
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
              <h2 class="text-lg font-black text-emerald-950 m-0 flex items-center gap-2">
                <i class="bi bi-journal-richtext text-emerald-600"></i> Examination sheet
              </h2>
              <span class="text-xs font-bold text-slate-500"><?php echo count($questions); ?> student question<?php echo count($questions) === 1 ? '' : 's'; ?></span>
            </div>
            <div class="perm-review-summary" id="permReviewSummary">
              <span>Score <strong><?php echo h($scoreLine); ?></strong></span>
              <span>Correct <strong><?php echo (int)$reviewCorrectN; ?></strong></span>
              <span>Incorrect <strong><?php echo (int)$reviewIncorrectN; ?></strong></span>
              <span>Unanswered <strong><?php echo (int)$reviewUnansweredN; ?></strong></span>
            </div>
            <div class="perm-review-filters" role="tablist" aria-label="Filter answers">
              <button type="button" class="perm-filter is-active" data-perm-filter="all">All</button>
              <button type="button" class="perm-filter" data-perm-filter="bad">Incorrect</button>
              <button type="button" class="perm-filter" data-perm-filter="ok">Correct</button>
              <button type="button" class="perm-filter" data-perm-filter="empty">Unanswered</button>
            </div>
            <?php
            $i = 0;
            foreach ($questions as $q):
                $i++;
                $studentN = (int)($q['_display_position'] ?? $i);
                $qid = (int)$q['question_id'];
                $letters = ['A' => $q['choice_a'], 'B' => $q['choice_b'], 'C' => $q['choice_c'], 'D' => $q['choice_d']];
                $sel = strtoupper(trim((string)($answersMap[$qid]['selected_answer'] ?? '')));
                $hasAns = $sel !== '';
                $cor = strtoupper(trim((string)($q['correct_answer'] ?? 'A')));
                $isCorrect = $hasAns && $sel === $cor;
                $barKind = !$hasAns ? 'empty' : ($isCorrect ? 'ok' : 'bad');
                $subjLabel = trim((string)($q['_subject_name'] ?? ''));
                $topicLabel = trim((string)($q['_topic_name'] ?? ''));
                $masterN = (int)($masterByQid[$qid] ?? 0);
                ?>
              <article class="perm-q-card mb-5 overflow-hidden" id="perm-q<?php echo (int)$studentN; ?>" data-perm-kind="<?php echo h($barKind); ?>">
                <div class="perm-q-bar <?php echo h($barKind); ?>"></div>
                <div class="p-5 md:p-6">
                  <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                    <div class="flex items-start gap-3 min-w-0">
                      <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white font-black text-lg shadow-sm"><?php echo (int)$studentN; ?></span>
                      <div class="min-w-0">
                        <p class="perm-q-student-num">Question <?php echo (int)$studentN; ?></p>
                        <?php if ($subjLabel !== '' || $topicLabel !== '' || $masterN > 0): ?>
                          <p class="perm-q-meta">
                            <?php if ($subjLabel !== ''): ?>Subject: <?php echo h($subjLabel); ?><?php endif; ?>
                            <?php if ($topicLabel !== ''): ?><?php echo $subjLabel !== '' ? ' · ' : ''; ?>Topic: <?php echo h($topicLabel); ?><?php endif; ?>
                            <?php if ($masterN > 0): ?><?php echo ($subjLabel !== '' || $topicLabel !== '') ? ' · ' : ''; ?>Master #<?php echo (int)$masterN; ?><?php endif; ?>
                          </p>
                        <?php endif; ?>
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
                          <span class="text-slate-300">-</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <p class="perm-answer-line">Student answer: <strong><?php echo $hasAns ? h($sel) : '—'; ?></strong>
                    · Correct answer: <strong><?php echo h($cor); ?></strong>
                    · <?php echo !$hasAns ? 'Unanswered' : ($isCorrect ? 'Correct' : 'Incorrect'); ?></p>
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
                <a class="perm-toc-btn perm-toc-<?php echo h($p['kind']); ?>" data-perm-kind="<?php echo h($p['kind']); ?>" href="#perm-q<?php echo (int)$p['n']; ?>"><?php echo (int)$p['n']; ?></a>
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
              <a class="perm-toc-btn perm-toc-<?php echo h($p['kind']); ?>" data-perm-kind="<?php echo h($p['kind']); ?>" href="#perm-q<?php echo (int)$p['n']; ?>"><?php echo (int)$p['n']; ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script>
  (function () {
    var filters = document.querySelectorAll('[data-perm-filter]');
    if (!filters.length) return;
    function apply(kind) {
      document.querySelectorAll('.perm-q-card[data-perm-kind]').forEach(function (el) {
        el.style.display = (kind === 'all' || el.getAttribute('data-perm-kind') === kind) ? '' : 'none';
      });
      document.querySelectorAll('.perm-toc-btn[data-perm-kind]').forEach(function (el) {
        el.style.display = (kind === 'all' || el.getAttribute('data-perm-kind') === kind) ? '' : 'none';
      });
    }
    filters.forEach(function (btn) {
      btn.addEventListener('click', function () {
        filters.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
        apply(btn.getAttribute('data-perm-filter') || 'all');
      });
    });
  })();
  </script>
</body>
</html>
