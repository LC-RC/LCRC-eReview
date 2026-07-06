<?php
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/preboards_migrate.php';
require_once __DIR__ . '/includes/preboards_admin_reports.php';
require_once __DIR__ . '/includes/quiz_helpers.php';

$attemptId = sanitizeInt($_GET['preboards_attempt_id'] ?? 0);
if ($attemptId <= 0) {
    header('Location: admin_preboards_monitor');
    exit;
}

$attempt = preboards_admin_fetch_attempt($conn, $attemptId);
if (!$attempt) {
    $_SESSION['error'] = 'Attempt not found.';
    header('Location: admin_preboards_monitor');
    exit;
}

$setId = (int) ($attempt['preboards_set_id'] ?? 0);
$userId = (int) ($attempt['user_id'] ?? 0);
$subjectId = (int) ($attempt['preboards_subject_id'] ?? 0);
$questions = preboards_admin_fetch_attempt_questions($conn, $attemptId, $setId);
$history = preboards_admin_student_attempt_history($conn, $userId, $setId);

$isSubmitted = ($attempt['status'] ?? '') === 'submitted';
$score = isset($attempt['score']) ? (float) $attempt['score'] : null;
$monitorUrl = 'admin_preboards_monitor?preboards_subject_id=' . $subjectId;

$pageTitle = 'Preboard Review — ' . ($attempt['full_name'] ?? 'Student');
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard'],
    ['Preboards', 'admin_preboards_subjects'],
    ['Monitoring', $monitorUrl],
    ['Attempt review'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
<style>
    .pb-review-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 0.75rem;
      padding: 1.25rem 1.5rem;
      margin-bottom: 1rem;
    }
    .pb-review-choice {
      display: flex;
      align-items: flex-start;
      gap: 0.75rem;
      padding: 0.75rem 1rem;
      border-radius: 0.75rem;
      border: 1px solid #e2e8f0;
      margin-bottom: 0.5rem;
    }
    .pb-review-choice--correct { background: #ecfdf5; border-color: #a7f3d0; }
    .pb-review-choice-letter {
      width: 2.25rem;
      height: 2.25rem;
      border-radius: 9999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      background: #f1f5f9;
      color: #334155;
      flex-shrink: 0;
    }
    .pb-review-tag {
      display: inline-block;
      padding: 0.15rem 0.5rem;
      border-radius: 9999px;
      font-size: 0.6875rem;
      font-weight: 700;
      margin-right: 0.35rem;
    }
    .pb-review-tag--student { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    .pb-review-tag--correct { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; }
    .pb-review-summary {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 0.75rem;
    }
    .pb-review-summary-item {
      background: rgba(15, 23, 42, 0.85);
      border: 1px solid rgba(148, 163, 184, 0.15);
      border-radius: 0.75rem;
      padding: 0.85rem 1rem;
    }
    .pb-review-summary-item strong { display: block; font-size: 1.25rem; color: #f8fafc; }
    .pb-review-summary-item span { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-preboards-review-page">
  <?php include 'admin_sidebar.php'; ?>

  <div class="quiz-admin-hero rounded-xl px-5 py-5 mb-5">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-gray-100 m-0 flex flex-wrap items-center gap-2">
      <span class="quiz-admin-hero-icon" aria-hidden="true"><i class="bi bi-journal-text"></i></span>
      Full attempt review
    </h1>
    <p class="text-gray-400 mt-2 mb-0 max-w-3xl text-sm sm:text-base">
      <?php echo h($attempt['full_name'] ?? ''); ?> — <?php echo h($attempt['subject_name'] ?? ''); ?>, Set <?php echo h($attempt['set_label'] ?? ''); ?>
      <?php if (!empty($attempt['set_title'])): ?> · <?php echo h($attempt['set_title']); ?><?php endif; ?>
    </p>
  </div>

  <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
    <a href="<?php echo h($monitorUrl); ?>" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold border-2 transition inline-flex items-center gap-2"><i class="bi bi-arrow-left"></i> Back to monitoring</a>
    <?php if (!$isSubmitted): ?>
      <span class="px-3 py-1.5 rounded-full text-sm font-semibold bg-amber-500/15 text-amber-300 border border-amber-500/30">In progress — answers may be incomplete</span>
    <?php endif; ?>
  </div>

  <div class="pb-review-summary mb-5">
    <div class="pb-review-summary-item"><strong><?php echo $isSubmitted ? preboards_format_score($score) : '—'; ?></strong><span>Score</span></div>
    <div class="pb-review-summary-item"><strong><?php echo (int) ($attempt['correct_count'] ?? 0); ?> / <?php echo (int) ($attempt['total_count'] ?? 0); ?></strong><span>Correct</span></div>
    <div class="pb-review-summary-item"><strong>#<?php echo (int) ($attempt['attempt_no'] ?? 1); ?></strong><span>Attempt</span></div>
    <div class="pb-review-summary-item"><strong><?php echo h(preboards_format_datetime($attempt['submitted_at'] ?? null)); ?></strong><span>Submitted</span></div>
  </div>

  <?php if (count($history) > 1): ?>
  <div class="quiz-admin-table-shell rounded-xl overflow-hidden mb-5">
    <div class="quiz-admin-table-head px-5 py-3">
      <span class="font-semibold text-gray-100 text-sm">Other attempts for this set</span>
    </div>
    <div class="overflow-x-auto">
      <table class="quiz-admin-data-table w-full text-left text-sm">
        <thead>
          <tr>
            <th class="px-5 py-2 font-semibold">Attempt</th>
            <th class="px-5 py-2 font-semibold">Score</th>
            <th class="px-5 py-2 font-semibold">Status</th>
            <th class="px-5 py-2 font-semibold">Submitted</th>
            <th class="px-5 py-2 font-semibold text-right">Open</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h):
            $hid = (int) ($h['preboards_attempt_id'] ?? 0);
            $current = $hid === $attemptId;
          ?>
            <tr class="quiz-admin-row <?php echo $current ? 'opacity-100' : ''; ?>">
              <td class="px-5 py-2 text-gray-300">#<?php echo (int) ($h['attempt_no'] ?? 1); ?><?php echo $current ? ' (viewing)' : ''; ?></td>
              <td class="px-5 py-2 text-gray-300"><?php echo ($h['status'] ?? '') === 'submitted' ? preboards_format_score(isset($h['score']) ? (float) $h['score'] : null) : '—'; ?></td>
              <td class="px-5 py-2"><span class="admin-status-pill inline-block px-2 py-0.5 rounded-full text-xs"><?php echo h($h['status'] ?? ''); ?></span></td>
              <td class="px-5 py-2 text-gray-400"><?php echo h(preboards_format_datetime($h['submitted_at'] ?? null)); ?></td>
              <td class="px-5 py-2 text-right">
                <?php if (!$current): ?>
                  <a href="admin_preboards_attempt_review?preboards_attempt_id=<?php echo $hid; ?>" class="admin-quiz-btn admin-quiz-btn-sm">View</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="quiz-admin-table-shell rounded-xl px-5 py-4 mb-5">
    <div class="font-semibold text-gray-100 mb-1">Questions &amp; answers</div>
    <p class="text-sm text-gray-500 mb-4"><?php echo count($questions); ?> question<?php echo count($questions) === 1 ? '' : 's'; ?> in this set.</p>

    <?php if (empty($questions)): ?>
      <p class="text-gray-500 mb-0">No questions found for this set.</p>
    <?php else: ?>
      <?php foreach ($questions as $i => $q):
        $choices = preboards_get_question_choices($q);
        $sel = strtoupper(trim((string) ($q['selected_answer'] ?? '')));
        $correctAns = strtoupper(trim((string) ($q['correct_answer'] ?? '')));
        $isCorrect = (int) ($q['is_correct'] ?? 0) === 1;
      ?>
        <div class="pb-review-card">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Question <?php echo $i + 1; ?> of <?php echo count($questions); ?></div>
          <div class="text-gray-900 font-medium mb-4 quiz-rich-text"><?php echo renderQuizRichText($q['question_text'] ?? ''); ?></div>
          <div class="space-y-2 mb-3">
            <?php foreach ($choices as $letter => $choiceText):
              $isStudent = ($sel === $letter);
              $isCorrectChoice = ($correctAns === $letter);
              $cls = $isCorrectChoice ? 'pb-review-choice pb-review-choice--correct' : 'pb-review-choice';
            ?>
              <div class="<?php echo $cls; ?>">
                <span class="pb-review-choice-letter"><?php echo h($letter); ?></span>
                <div class="flex-1">
                  <div class="text-gray-800 quiz-rich-text"><?php echo renderQuizRichText($choiceText); ?></div>
                  <div class="mt-1">
                    <?php if ($isStudent): ?><span class="pb-review-tag pb-review-tag--student">Student answer</span><?php endif; ?>
                    <?php if ($isCorrectChoice): ?><span class="pb-review-tag pb-review-tag--correct">Correct answer</span><?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($sel === ''): ?>
            <div class="text-sm font-semibold text-slate-500"><i class="bi bi-dash-circle mr-1"></i> Not answered</div>
          <?php else: ?>
            <div class="text-sm font-semibold <?php echo $isCorrect ? 'text-emerald-700' : 'text-red-700'; ?>">
              <?php if ($isCorrect): ?><i class="bi bi-check-circle-fill mr-1"></i> Correct<?php else: ?><i class="bi bi-x-circle-fill mr-1"></i> Incorrect<?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</body>
</html>
