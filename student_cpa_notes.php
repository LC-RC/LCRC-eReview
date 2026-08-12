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
    'lesson_id' => (int) ($_GET['lesson_id'] ?? 0),
    'sort' => (string) ($_GET['sort'] ?? 'updated_desc'),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'per_page' => 20,
];
$list = student_cpa_notes_list($conn, $userId, $filters);
$subjects = student_cpa_list_subjects($conn);
$lessons = student_cpa_list_lessons($conn, $filters['subject_id']);
$pageTitle = 'My Notes';
$apiUrl = 'student_cpa_review_api';
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
      <div class="rounded-2xl px-6 py-5 bg-gradient-to-r from-[#1665A0] to-[#143D59] text-white shadow-[0_10px_30px_rgba(20,61,89,0.35)] flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 class="text-xl sm:text-2xl font-bold m-0">Notes</h1>
          <p class="text-sm text-white/90 mt-1 mb-0">Your digital notebook — capture ideas while studying lessons.</p>
        </div>
        <button type="button" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl font-semibold bg-white/15 hover:bg-white/25 border border-white/20 transition" data-cpa-action="open_note_modal">
          <i class="bi bi-plus-lg"></i> Add note
        </button>
      </div>
    </section>

    <?php
      $cpaFilterAction = 'student_cpa_notes';
      $cpaSubjects = $subjects;
      $cpaLessons = $lessons;
      $cpaShowLesson = true;
      $cpaQ = $filters['q'];
      $cpaSubjectId = $filters['subject_id'];
      $cpaLessonId = $filters['lesson_id'];
      $cpaSort = $filters['sort'];
      require __DIR__ . '/includes/components/cpa_review_filters.php';
    ?>

    <?php if (empty($list['rows'])): ?>
      <?php
        $cpaEmptyIcon = 'bi-journal-text';
        $cpaEmptyTitle = 'No notes yet';
        $cpaEmptyText = 'Use Add Note on a lesson, or create one here.';
        $cpaEmptyCtaHref = 'student_subjects';
        $cpaEmptyCtaLabel = 'Browse subjects';
        require __DIR__ . '/includes/components/cpa_review_empty.php';
      ?>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($list['rows'] as $n):
          $tags = array_filter(array_map('trim', preg_split('/[,#]+/', (string) ($n['tags'] ?? '')) ?: []));
        ?>
          <article data-cpa-row class="cpa-note-card rounded-2xl border border-[#1665A0]/12 bg-white overflow-hidden shadow-[0_1px_4px_rgba(15,23,42,0.06)]">
            <div class="px-4 sm:px-5 pt-4 sm:pt-5">
              <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                  <h2 class="text-lg font-bold text-[#143D59] m-0 flex items-center gap-2">
                    <i class="bi bi-journal-text text-[#1665A0]"></i>
                    <?php echo h($n['title'] ?: 'Untitled note'); ?>
                  </h2>
                  <div class="flex flex-wrap items-center gap-2 mt-2 text-sm">
                    <?php if (!empty($n['subject_name'])): ?>
                      <span class="font-semibold text-[#1665A0]"><?php echo h($n['subject_name']); ?></span>
                    <?php else: ?>
                      <span class="text-[#94a3b8]">No subject</span>
                    <?php endif; ?>
                    <?php if (!empty($n['lesson_title'])): ?>
                      <span class="text-[#94a3b8]">·</span>
                      <span class="text-[#475569]"><?php echo h($n['lesson_title']); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="flex items-center gap-1 text-[#1665A0]">
                  <?php if (!empty($n['is_starred'])): ?><i class="bi bi-star-fill text-amber-500" title="Starred"></i><?php endif; ?>
                  <?php if (!empty($n['is_pinned'])): ?><i class="bi bi-pin-angle-fill" title="Pinned"></i><?php endif; ?>
                </div>
              </div>
              <div class="mt-3 text-sm text-[#334155] leading-relaxed cpa-note-body"><?php echo student_cpa_sanitize_html((string) ($n['content'] ?? '')); ?></div>
              <?php if ($tags): ?>
                <div class="flex flex-wrap gap-1.5 mt-3">
                  <?php foreach ($tags as $tag): ?>
                    <span class="cpa-tag">#<?php echo h(ltrim($tag, '#')); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="mt-4 px-4 sm:px-5 py-3 bg-[#f8fafc] border-t border-[#1665A0]/8 flex flex-wrap items-center justify-between gap-2">
              <span class="text-xs text-[#64748b]"><?php echo !empty($n['updated_at']) ? h(date('M j, Y', strtotime($n['updated_at']))) : ''; ?></span>
              <div class="flex flex-wrap gap-2">
                <?php if (!empty($n['lesson_id'])): ?>
                  <a href="student_lesson_viewer?lesson_id=<?php echo (int) $n['lesson_id']; ?>&subject_id=<?php echo (int) ($n['subject_id'] ?? 0); ?>" class="cpa-toolbar-btn">Open lesson</a>
                <?php endif; ?>
                <button type="button" class="cpa-toolbar-btn" data-cpa-action="note_delete" data-note-id="<?php echo (int) $n['note_id']; ?>" data-confirm="Delete this note?">Delete</button>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <?php
        $cpaTotal = $list['total'];
        $cpaPage = $filters['page'];
        $cpaPerPage = $filters['per_page'];
        $cpaBaseQuery = array_filter([
            'q' => $filters['q'],
            'subject_id' => $filters['subject_id'] ?: null,
            'lesson_id' => $filters['lesson_id'] ?: null,
            'sort' => $filters['sort'],
        ], static fn($v) => $v !== null && $v !== '');
        require __DIR__ . '/includes/components/cpa_review_pager.php';
      ?>
    <?php endif; ?>
  </div>

  <?php
    $csrfToken = $csrf;
    $cpaNoteSubjectId = $filters['subject_id'];
    $cpaNoteLessonId = $filters['lesson_id'];
    require __DIR__ . '/includes/components/cpa_review_note_modal.php';
  ?>
  <script>window.CPA_REVIEW = Object.assign(window.CPA_REVIEW || {}, { apiUrl: <?php echo json_encode($apiUrl); ?>, csrf: <?php echo json_encode($csrf); ?>, reloadOnSave: true });</script>
  <script src="assets/js/student-cpa-review.js"></script>
</body>
</html>
