<?php
require_once 'auth.php';
requireAdminPage('quizzes');
require_once __DIR__ . '/includes/quiz_admin_reports.php';
require_once __DIR__ . '/includes/quiz_helpers.php';

$attemptId = sanitizeInt($_GET['attempt_id'] ?? 0);
if ($attemptId <= 0) {
    header('Location: admin_quiz_monitor');
    exit;
}

$attempt = quiz_admin_fetch_attempt($conn, $attemptId);
if (!$attempt) {
    $_SESSION['error'] = 'Attempt not found.';
    header('Location: admin_quiz_monitor');
    exit;
}

$quizId = (int) ($attempt['quiz_id'] ?? 0);
$userId = (int) ($attempt['user_id'] ?? 0);
$subjectId = (int) ($attempt['subject_id'] ?? 0);
$questions = quiz_admin_fetch_attempt_questions($conn, $attemptId, $quizId);
$history = quiz_admin_student_attempt_history($conn, $userId, $quizId);
$isSubmitted = ($attempt['status'] ?? '') === 'submitted';
$monitorUrl = 'admin_quiz_monitor?subject_id=' . $subjectId . ($quizId > 0 ? '&quiz_id=' . $quizId : '');

$pageTitle = 'Quiz Review - ' . ($attempt['full_name'] ?? 'Student');
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard'],
    ['Quiz Monitor', $monitorUrl],
    ['Attempt review'],
];
$adminHeroIcon = 'journal-text';
$adminHeroTitle = 'Quiz attempt review';
$adminHeroSubtitle = ($attempt['full_name'] ?? '') . ' - ' . ($attempt['quiz_title'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    .qm-choice { display:flex; gap:.75rem; padding:.75rem 1rem; border:1px solid var(--admin-border); border-radius:.75rem; margin-bottom:.5rem; }
    .qm-choice--correct { border-color: rgba(34,197,94,.45); background: rgba(34,197,94,.08); }
    .qm-choice--picked { border-color: rgba(59,130,246,.45); }
    .qm-letter { width:2.1rem; height:2.1rem; border-radius:999px; display:grid; place-items:center; font-weight:800; background: rgba(148,163,184,.15); flex-shrink:0; }
  </style>
</head>
<body class="font-sans antialiased admin-app">
  <?php include 'admin_sidebar.php'; ?>
  <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

  <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
    <a href="<?php echo h($monitorUrl); ?>" class="admin-btn admin-btn--secondary"><i class="bi bi-arrow-left"></i> Back to monitor</a>
    <a href="admin_student_view?id=<?php echo $userId; ?>" class="admin-btn admin-btn--ghost">Student profile</a>
  </div>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
    <div class="rounded-xl border p-4 page-table"><div class="text-xs uppercase opacity-60">Status</div><div class="font-bold"><?php echo h((string) ($attempt['status'] ?? '')); ?></div></div>
    <div class="rounded-xl border p-4 page-table"><div class="text-xs uppercase opacity-60">Score</div><div class="font-bold"><?php echo h(quiz_admin_format_score(isset($attempt['score']) ? (float) $attempt['score'] : null)); ?></div></div>
    <div class="rounded-xl border p-4 page-table"><div class="text-xs uppercase opacity-60">Correct</div><div class="font-bold"><?php echo (int) ($attempt['correct_count'] ?? 0); ?>/<?php echo (int) ($attempt['total_count'] ?? 0); ?></div></div>
    <div class="rounded-xl border p-4 page-table"><div class="text-xs uppercase opacity-60">Tab switches</div><div class="font-bold"><?php echo (int) ($attempt['tab_switch_count'] ?? 0); ?></div></div>
  </div>

  <?php if ($history): ?>
    <div class="rounded-xl border p-4 mb-4 page-table">
      <h2 class="text-sm font-bold mb-2">Attempt history (this quiz)</h2>
      <div class="flex flex-wrap gap-2 text-xs">
        <?php foreach ($history as $hRow): ?>
          <a class="admin-btn admin-btn--sm <?php echo (int) $hRow['attempt_id'] === $attemptId ? 'admin-btn--primary' : 'admin-btn--ghost'; ?>"
             href="admin_quiz_attempt_review?attempt_id=<?php echo (int) $hRow['attempt_id']; ?>">
            #<?php echo (int) $hRow['attempt_id']; ?> <?php echo h((string) $hRow['status']); ?>
            <?php if ($hRow['score'] !== null): ?> · <?php echo h(quiz_admin_format_score((float) $hRow['score'])); ?><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$isSubmitted): ?>
    <div class="admin-flash admin-flash--error mb-4 p-3 rounded-xl">In progress - answers may be incomplete.</div>
  <?php endif; ?>

  <?php if (empty($questions)): ?>
    <div class="rounded-xl border p-6 page-table text-center opacity-70">No questions found for this quiz.</div>
  <?php else: ?>
    <?php $n = 0; foreach ($questions as $q): $n++;
      $letters = ['A','B','C','D','E','F','G','H','I','J'];
      $correct = strtoupper((string) ($q['correct_answer'] ?? ''));
      $picked = strtoupper((string) ($q['selected_answer'] ?? ''));
    ?>
      <div class="rounded-xl border p-5 mb-3 page-table">
        <div class="text-xs uppercase opacity-60 mb-1">Question <?php echo $n; ?></div>
        <div class="font-semibold mb-3"><?php echo h((string) ($q['question_text'] ?? '')); ?></div>
        <?php foreach ($letters as $L):
          $optKey = 'option_' . strtolower($L);
          if (empty($q[$optKey])) continue;
          $cls = 'qm-choice';
          if ($L === $correct) $cls .= ' qm-choice--correct';
          if ($L === $picked) $cls .= ' qm-choice--picked';
        ?>
          <div class="<?php echo $cls; ?>">
            <span class="qm-letter"><?php echo $L; ?></span>
            <div class="flex-1">
              <?php echo h((string) $q[$optKey]); ?>
              <?php if ($L === $correct): ?><div class="text-xs font-bold mt-1" style="color:#16a34a;">Correct</div><?php endif; ?>
              <?php if ($L === $picked && $L !== $correct): ?><div class="text-xs font-bold mt-1" style="color:#2563eb;">Student answer</div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($picked === ''): ?>
          <div class="text-xs opacity-60">No answer selected.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</main>
</body>
</html>
