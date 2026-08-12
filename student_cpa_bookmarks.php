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
$list = student_cpa_generic_list($conn, $userId, 'student_bookmarks', 'bookmark_id', $filters, 'title,url');
$subjects = student_cpa_list_subjects($conn);
$pageTitle = 'Bookmarks';
$apiUrl = function_exists('ereview_url') ? ereview_url('student_cpa_review_api') : 'student_cpa_review_api';
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
        <h1 class="text-xl sm:text-2xl font-bold m-0">Bookmarks</h1>
        <p class="text-sm text-white/90 mt-1 mb-0">Lessons and resources to return to later before quizzes or preboards.</p>
      </div>
    </section>

    <?php
      $cpaFilterAction = 'student_cpa_bookmarks';
      $cpaSubjects = $subjects;
      $cpaShowLesson = false;
      $cpaShowSort = false;
      $cpaQ = $filters['q'];
      $cpaSubjectId = $filters['subject_id'];
      require __DIR__ . '/includes/components/cpa_review_filters.php';
    ?>

    <?php if (empty($list['rows'])): ?>
      <?php
        $cpaEmptyIcon = 'bi-bookmark';
        $cpaEmptyTitle = 'No bookmarks yet';
        $cpaEmptyText = 'Bookmark a lesson from the lesson viewer to see it here.';
        $cpaEmptyCtaHref = 'student_subjects';
        $cpaEmptyCtaLabel = 'Browse subjects';
        require __DIR__ . '/includes/components/cpa_review_empty.php';
      ?>
    <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($list['rows'] as $b):
          $href = trim((string) ($b['url'] ?? ''));
          if ($href === '' && ($b['item_type'] ?? '') === 'lesson' && !empty($b['item_id'])) {
              $href = 'student_lesson_viewer?lesson_id=' . (int) $b['item_id'] . '&subject_id=' . (int) ($b['subject_id'] ?? 0);
          }
        ?>
          <article data-cpa-row class="rounded-2xl border border-[#1665A0]/12 bg-white p-4 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <h2 class="text-base font-bold text-[#143D59] m-0"><?php echo h($b['title'] ?: 'Bookmark'); ?></h2>
              <p class="text-xs text-[#64748b] mt-1 mb-0">
                <?php echo h(ucfirst((string) ($b['item_type'] ?? 'item'))); ?>
                <?php if (!empty($b['subject_name'])): ?> · <?php echo h($b['subject_name']); ?><?php endif; ?>
                · <?php echo !empty($b['created_at']) ? h(date('M j, Y', strtotime($b['created_at']))) : ''; ?>
              </p>
            </div>
            <div class="flex gap-2">
              <?php if ($href !== ''): ?>
                <a href="<?php echo h($href); ?>" class="cpa-toolbar-btn">Open</a>
              <?php endif; ?>
              <button type="button" class="cpa-toolbar-btn is-active" data-cpa-action="bookmark_toggle"
                data-item-type="<?php echo h($b['item_type']); ?>"
                data-item-id="<?php echo (int) $b['item_id']; ?>"
                data-title="<?php echo h($b['title']); ?>"
                data-subject-id="<?php echo (int) ($b['subject_id'] ?? 0); ?>"
                data-lesson-id="<?php echo (int) ($b['lesson_id'] ?? 0); ?>">
                <span data-cpa-label>Remove</span>
              </button>
            </div>
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
