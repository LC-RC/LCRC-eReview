<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_cpa_review.php';
requireRole('student');

$userId = (int) getCurrentUserId();
student_cpa_review_ensure_schema($conn);
$csrf = generateCSRFToken();
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'subject_id' => (int) ($_GET['subject_id'] ?? 0),
    'reviewed' => (string) ($_GET['reviewed'] ?? 'all'),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'per_page' => 20,
];
$list = student_cpa_mistakes_list($conn, $userId, $filters);
$bySubject = student_cpa_mistakes_by_subject($conn, $userId);
$subjects = student_cpa_list_subjects($conn);
$pageTitle = 'My Mistakes';
$apiUrl = 'student_cpa_review_api';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'update_note') {
    if (verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        student_cpa_mistake_update($conn, $userId, (int) ($_POST['mistake_id'] ?? 0), [
            'personal_note' => (string) ($_POST['personal_note'] ?? ''),
            'is_reviewed' => !empty($_POST['is_reviewed']) ? 1 : 0,
        ]);
    }
    header('Location: student_cpa_mistakes?' . http_build_query(array_filter([
        'q' => $filters['q'] ?: null,
        'subject_id' => $filters['subject_id'] ?: null,
        'reviewed' => $filters['reviewed'] !== 'all' ? $filters['reviewed'] : null,
        'page' => $filters['page'] > 1 ? $filters['page'] : null,
    ])));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <?php require __DIR__ . '/includes/components/cpa_review_styles.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page cpa-page min-h-full pb-8">
    <?php require __DIR__ . '/includes/components/cpa_review_back.php'; ?>
    <section class="mb-5">
      <div class="rounded-2xl px-6 py-5 bg-gradient-to-r from-[#1665A0] to-[#143D59] text-white shadow-[0_10px_30px_rgba(20,61,89,0.35)]">
        <h1 class="text-xl sm:text-2xl font-bold m-0">Mistake Notebook</h1>
        <p class="text-sm text-white/90 mt-1 mb-0">Wrong answers you saved for review — with your personal notes.</p>
      </div>
    </section>

    <?php if (!empty($bySubject)): ?>
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2 mb-4">
        <?php foreach ($bySubject as $bs): ?>
          <a href="student_cpa_mistakes?subject_id=<?php echo (int) $bs['subject_id']; ?>"
             class="rounded-xl border border-[#1665A0]/12 bg-white px-3 py-2.5 hover:border-[#1665A0]/35 transition <?php echo $filters['subject_id'] === (int) $bs['subject_id'] ? 'ring-2 ring-[#1665A0]/30' : ''; ?>">
            <div class="text-xs font-bold uppercase tracking-wide text-[#64748b]"><?php echo h($bs['subject_name']); ?></div>
            <div class="text-lg font-extrabold text-[#143D59]"><?php echo (int) $bs['total']; ?> <span class="text-xs font-semibold text-[#64748b]">mistakes</span></div>
            <?php if ($bs['unreviewed'] > 0): ?>
              <div class="text-[0.7rem] font-semibold text-rose-600"><?php echo (int) $bs['unreviewed']; ?> unreviewed</div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php
      $cpaFilterAction = 'student_cpa_mistakes';
      $cpaSubjects = $subjects;
      $cpaShowLesson = false;
      $cpaShowSort = false;
      $cpaQ = $filters['q'];
      $cpaSubjectId = $filters['subject_id'];
      ob_start();
    ?>
      <div class="min-w-[120px]">
        <label class="block text-xs font-bold uppercase tracking-wide text-[#64748b] mb-1">Status</label>
        <select name="reviewed" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm">
          <option value="all" <?php echo $filters['reviewed'] === 'all' ? 'selected' : ''; ?>>All</option>
          <option value="no" <?php echo $filters['reviewed'] === 'no' ? 'selected' : ''; ?>>Unreviewed</option>
          <option value="yes" <?php echo $filters['reviewed'] === 'yes' ? 'selected' : ''; ?>>Reviewed</option>
        </select>
      </div>
    <?php
      $cpaExtraFilters = ob_get_clean();
      require __DIR__ . '/includes/components/cpa_review_filters.php';
    ?>

    <?php if (empty($list['rows'])): ?>
      <?php
        $cpaEmptyIcon = 'bi-exclamation-diamond';
        $cpaEmptyTitle = 'Mistake notebook is empty';
        $cpaEmptyText = 'After a quiz, add wrong answers from the review screen.';
        $cpaEmptyCtaHref = 'student_subjects';
        $cpaEmptyCtaLabel = 'Go to quizzes';
        require __DIR__ . '/includes/components/cpa_review_empty.php';
      ?>
    <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($list['rows'] as $m): ?>
          <article data-cpa-row class="rounded-2xl border border-[#1665A0]/12 bg-white p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-2 mb-2">
              <div>
                <span class="text-xs font-bold uppercase tracking-wide <?php echo empty($m['is_reviewed']) ? 'text-rose-600' : 'text-emerald-600'; ?>">
                  <?php echo empty($m['is_reviewed']) ? 'Needs review' : 'Reviewed'; ?>
                </span>
                <h2 class="text-sm font-bold text-[#143D59] m-0 mt-1"><?php echo h($m['quiz_title'] ?? 'Quiz'); ?><?php if (!empty($m['subject_name'])): ?> · <?php echo h($m['subject_name']); ?><?php endif; ?></h2>
              </div>
              <div class="flex flex-wrap gap-2">
                <button type="button" class="cpa-toolbar-btn" data-cpa-action="mistake_reviewed"
                  data-mistake-id="<?php echo (int) $m['mistake_id']; ?>"
                  data-is-reviewed="<?php echo empty($m['is_reviewed']) ? '1' : '0'; ?>">
                  <?php echo empty($m['is_reviewed']) ? 'Mark reviewed' : 'Mark unreviewed'; ?>
                </button>
                <button type="button" class="cpa-toolbar-btn" data-cpa-action="mistake_delete"
                  data-mistake-id="<?php echo (int) $m['mistake_id']; ?>"
                  data-confirm="Remove from mistake notebook?">Delete</button>
              </div>
            </div>
            <p class="text-sm text-[#334155] mb-2"><?php echo h($m['question_preview'] ?? 'Question'); ?>…</p>
            <div class="flex flex-wrap gap-3 text-xs mb-3">
              <span class="px-2 py-1 rounded-lg bg-rose-50 text-rose-700 border border-rose-200">Your answer: <?php echo h($m['selected_answer'] ?: '—'); ?></span>
              <span class="px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200">Correct: <?php echo h($m['correct_answer'] ?: '—'); ?></span>
            </div>
            <?php if (!empty($m['explanation_snapshot'])): ?>
              <p class="text-xs text-[#64748b] mb-3"><strong>Explanation:</strong> <?php echo h(mb_substr((string) $m['explanation_snapshot'], 0, 400)); ?></p>
            <?php endif; ?>
            <form method="post" class="border-t border-[#1665A0]/10 pt-3">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="form" value="update_note">
              <input type="hidden" name="mistake_id" value="<?php echo (int) $m['mistake_id']; ?>">
              <label class="block text-xs font-bold uppercase text-[#64748b] mb-1">Personal note</label>
              <textarea name="personal_note" rows="2" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm"><?php echo h((string) ($m['personal_note'] ?? '')); ?></textarea>
              <label class="inline-flex items-center gap-2 mt-2 text-sm">
                <input type="checkbox" name="is_reviewed" value="1" <?php echo !empty($m['is_reviewed']) ? 'checked' : ''; ?>> Reviewed
              </label>
              <button type="submit" class="ml-3 px-3 py-1.5 rounded-lg text-xs font-semibold bg-[#1665A0] text-white">Save</button>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
      <?php
        $cpaTotal = $list['total'];
        $cpaPage = $filters['page'];
        $cpaPerPage = $filters['per_page'];
        $cpaBaseQuery = array_filter([
            'q' => $filters['q'] ?: null,
            'subject_id' => $filters['subject_id'] ?: null,
            'reviewed' => $filters['reviewed'] !== 'all' ? $filters['reviewed'] : null,
        ]);
        require __DIR__ . '/includes/components/cpa_review_pager.php';
      ?>
    <?php endif; ?>
  </div>
  <script>window.CPA_REVIEW = { apiUrl: <?php echo json_encode($apiUrl); ?>, csrf: <?php echo json_encode($csrf); ?> };</script>
  <script src="assets/js/student-cpa-review.js"></script>
</body>
</html>
