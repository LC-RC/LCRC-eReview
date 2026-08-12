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
$list = student_cpa_generic_list($conn, $userId, 'student_quick_review', 'quick_id', $filters, 'title,content,tags');
$subjects = student_cpa_list_subjects($conn);
$pageTitle = 'Quick Review';
$apiUrl = 'student_cpa_review_api';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'quick_save') {
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $flash = 'Invalid CSRF token.';
    } else {
        $res = student_cpa_quick_save($conn, $userId, [
            'quick_id' => (int) ($_POST['quick_id'] ?? 0),
            'title' => (string) ($_POST['title'] ?? ''),
            'content' => (string) ($_POST['content'] ?? ''),
            'tags' => (string) ($_POST['tags'] ?? ''),
            'subject_id' => (int) ($_POST['subject_id'] ?? 0),
            'lesson_id' => (int) ($_POST['lesson_id'] ?? 0),
            'is_important' => !empty($_POST['is_important']) ? 1 : 0,
        ]);
        if (!empty($res['ok'])) {
            header('Location: student_cpa_quick_review');
            exit;
        }
        $flash = $res['error'] ?? 'Save failed.';
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
        <h1 class="text-xl sm:text-2xl font-bold m-0">Quick Review</h1>
        <p class="text-sm text-white/90 mt-1 mb-0">Short cards for rapid recall before exams.</p>
      </div>
    </section>

    <?php if ($flash !== ''): ?>
      <div class="mb-3 rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-2 text-sm"><?php echo h($flash); ?></div>
    <?php endif; ?>

    <form method="post" class="mb-4 rounded-2xl border border-[#1665A0]/12 bg-white p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="form" value="quick_save">
      <div class="md:col-span-2">
        <label class="block text-xs font-bold uppercase text-[#64748b] mb-1">New card</label>
        <input type="text" name="title" required maxlength="255" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm" placeholder="Title">
      </div>
      <div>
        <select name="subject_id" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm">
          <option value="0">No subject</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?php echo (int) $s['subject_id']; ?>"><?php echo h($s['subject_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <input type="text" name="tags" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm" placeholder="Tags (optional)">
      </div>
      <div class="md:col-span-2">
        <textarea name="content" required rows="3" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm" placeholder="Condensed content"></textarea>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_important" value="1"> Include in Last-Minute Review</label>
        <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold bg-[#1665A0] text-white">Save card</button>
      </div>
    </form>

    <?php
      $cpaFilterAction = 'student_cpa_quick_review';
      $cpaSubjects = $subjects;
      $cpaShowLesson = false;
      $cpaShowSort = false;
      $cpaQ = $filters['q'];
      $cpaSubjectId = $filters['subject_id'];
      require __DIR__ . '/includes/components/cpa_review_filters.php';
    ?>

    <?php if (empty($list['rows'])): ?>
      <?php
        $cpaEmptyIcon = 'bi-lightning-charge';
        $cpaEmptyTitle = 'Build quick-recall cards';
        $cpaEmptyText = 'Write short cards for formulas, definitions, and rules. Mark important cards so they appear in Last-Minute Review.';
        $cpaEmptyCtaHref = '#';
        $cpaEmptyCtaLabel = 'Create your first card above';
        require __DIR__ . '/includes/components/cpa_review_empty.php';
      ?>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <?php foreach ($list['rows'] as $q): ?>
          <article data-cpa-row class="rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white p-4">
            <div class="flex items-start justify-between gap-2">
              <h2 class="text-base font-bold text-[#143D59] m-0">
                <?php if (!empty($q['is_important'])): ?><i class="bi bi-star-fill text-amber-500"></i> <?php endif; ?>
                <?php echo h($q['title']); ?>
              </h2>
              <button type="button" class="cpa-toolbar-btn" data-cpa-action="quick_delete" data-quick-id="<?php echo (int) $q['quick_id']; ?>" data-confirm="Delete this card?">Delete</button>
            </div>
            <p class="text-xs text-[#64748b] mt-1 mb-2"><?php echo h($q['subject_name'] ?? 'No subject'); ?></p>
            <p class="text-sm text-[#334155] whitespace-pre-wrap m-0"><?php echo h($q['content']); ?></p>
            <?php if (!empty($q['tags'])): ?>
              <p class="text-xs text-[#64748b] mt-2 mb-0"><?php echo h($q['tags']); ?></p>
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
