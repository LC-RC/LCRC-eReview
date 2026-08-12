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
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'per_page' => 20,
];
$list = student_cpa_concepts_list($conn, $userId, $filters);
$subjects = student_cpa_list_subjects($conn);
$pageTitle = 'Important Concepts';
$apiUrl = 'student_cpa_review_api';
$flash = '';
$editId = (int) ($_GET['edit'] ?? 0);
$editRow = $editId > 0 ? student_cpa_concept_get($conn, $userId, $editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save_concept') {
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $flash = 'Invalid CSRF token.';
    } else {
        $res = student_cpa_concept_save($conn, $userId, [
            'important_id' => (int) ($_POST['important_id'] ?? 0),
            'title' => (string) ($_POST['title'] ?? ''),
            'topic' => (string) ($_POST['topic'] ?? ''),
            'body' => (string) ($_POST['body'] ?? ''),
            'subject_id' => (int) ($_POST['subject_id'] ?? 0),
            'is_last_minute' => !empty($_POST['is_last_minute']) ? 1 : 0,
        ]);
        if (!empty($res['ok'])) {
            header('Location: student_cpa_important');
            exit;
        }
        $flash = $res['error'] ?? 'Could not save concept.';
    }
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
        <h1 class="text-xl sm:text-2xl font-bold m-0">Important Concepts</h1>
        <p class="text-sm text-white/90 mt-1 mb-0">Must-know CPA concepts you write yourself — separate from notes.</p>
      </div>
    </section>

    <?php if ($flash !== ''): ?>
      <div class="mb-3 rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-2 text-sm"><?php echo h($flash); ?></div>
    <?php endif; ?>

    <form method="post" class="mb-5 rounded-2xl border border-[#1665A0]/12 bg-white p-4 sm:p-5 grid grid-cols-1 md:grid-cols-2 gap-3">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="form" value="save_concept">
      <input type="hidden" name="important_id" value="<?php echo (int) ($editRow['important_id'] ?? 0); ?>">
      <div class="md:col-span-2">
        <label class="block text-xs font-bold uppercase text-[#64748b] mb-1"><?php echo $editRow ? 'Edit concept' : 'Add concept'; ?></label>
        <input type="text" name="title" required maxlength="255" value="<?php echo h((string) ($editRow['title'] ?? '')); ?>" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm" placeholder="Concept title (e.g. Lower of Cost and NRV)">
      </div>
      <div>
        <label class="block text-xs font-bold uppercase text-[#64748b] mb-1">Subject</label>
        <select name="subject_id" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm">
          <option value="0">Select subject</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?php echo (int) $s['subject_id']; ?>" <?php echo (int) ($editRow['subject_id'] ?? 0) === (int) $s['subject_id'] ? 'selected' : ''; ?>><?php echo h($s['subject_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase text-[#64748b] mb-1">Topic</label>
        <input type="text" name="topic" maxlength="255" value="<?php echo h((string) ($editRow['topic'] ?? '')); ?>" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm" placeholder="e.g. Inventories">
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-bold uppercase text-[#64748b] mb-1">Why is this important?</label>
        <textarea name="body" rows="3" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm" placeholder="Used when determining inventory valuation…"><?php echo h((string) ($editRow['body'] ?? '')); ?></textarea>
      </div>
      <div class="md:col-span-2 flex flex-wrap items-center gap-3">
        <label class="inline-flex items-center gap-2 text-sm font-medium text-[#143D59]">
          <input type="checkbox" name="is_last_minute" value="1" <?php echo !empty($editRow['is_last_minute']) ? 'checked' : ''; ?>>
          Pin to Last-Minute Review
        </label>
        <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold bg-[#1665A0] text-white"><?php echo $editRow ? 'Update concept' : 'Save concept'; ?></button>
        <?php if ($editRow): ?>
          <a href="student_cpa_important" class="text-sm font-semibold text-[#64748b] hover:underline">Cancel edit</a>
        <?php endif; ?>
      </div>
    </form>

    <?php
      $cpaFilterAction = 'student_cpa_important';
      $cpaSubjects = $subjects;
      $cpaShowLesson = false;
      $cpaShowSort = false;
      $cpaQ = $filters['q'];
      $cpaSubjectId = $filters['subject_id'];
      require __DIR__ . '/includes/components/cpa_review_filters.php';
    ?>

    <?php if (empty($list['rows'])): ?>
      <?php
        $cpaEmptyIcon = 'bi-star';
        $cpaEmptyTitle = 'Start your concept bank';
        $cpaEmptyText = 'Add must-know CPA rules here (title, subject, topic, why it matters). Pin any concept to Last-Minute Review before the exam.';
        $cpaEmptyCtaHref = '#';
        $cpaEmptyCtaLabel = 'Scroll up to add a concept';
        require __DIR__ . '/includes/components/cpa_review_empty.php';
      ?>
    <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($list['rows'] as $row): ?>
          <article data-cpa-row class="cpa-concept-card rounded-2xl border border-[#1665A0]/12 bg-white p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <h2 class="text-base sm:text-lg font-bold text-[#143D59] m-0 flex items-center gap-2">
                  <i class="bi bi-star-fill text-amber-500"></i>
                  <?php echo h($row['title'] ?: 'Concept'); ?>
                  <?php if (!empty($row['is_last_minute'])): ?>
                    <span class="text-[0.65rem] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 border border-amber-200">Last-minute</span>
                  <?php endif; ?>
                </h2>
                <p class="text-sm text-[#64748b] mt-1 mb-0">
                  <?php echo h($row['subject_name'] ?? 'No subject'); ?>
                  <?php if (!empty($row['topic'])): ?> · <?php echo h($row['topic']); ?><?php endif; ?>
                </p>
              </div>
              <div class="flex flex-wrap gap-2">
                <?php if (!empty($row['subject_id'])): ?>
                  <a href="student_subject?subject_id=<?php echo (int) $row['subject_id']; ?>" class="cpa-toolbar-btn">Open</a>
                <?php endif; ?>
                <a href="student_cpa_important?edit=<?php echo (int) $row['important_id']; ?>" class="cpa-toolbar-btn">Edit</a>
                <button type="button" class="cpa-toolbar-btn" data-cpa-action="concept_last_minute"
                  data-important-id="<?php echo (int) $row['important_id']; ?>"
                  data-is-last-minute="<?php echo empty($row['is_last_minute']) ? '1' : '0'; ?>">
                  <?php echo empty($row['is_last_minute']) ? 'Pin last-minute' : 'Unpin'; ?>
                </button>
                <button type="button" class="cpa-toolbar-btn" data-cpa-action="concept_delete"
                  data-important-id="<?php echo (int) $row['important_id']; ?>"
                  data-confirm="Remove this concept?">Remove</button>
              </div>
            </div>
            <?php if (!empty($row['body'])): ?>
              <p class="text-sm text-[#334155] mt-3 mb-0 whitespace-pre-wrap leading-relaxed"><?php echo h($row['body']); ?></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <?php
        $cpaTotal = $list['total'];
        $cpaPage = $filters['page'];
        $cpaPerPage = $filters['per_page'];
        $cpaBaseQuery = array_filter(['q' => $filters['q'], 'subject_id' => $filters['subject_id'] ?: null]);
        require __DIR__ . '/includes/components/cpa_review_pager.php';
      ?>
    <?php endif; ?>
  </div>
  <script>window.CPA_REVIEW = { apiUrl: <?php echo json_encode($apiUrl); ?>, csrf: <?php echo json_encode($csrf); ?> };</script>
  <script src="assets/js/student-cpa-review.js"></script>
</body>
</html>
