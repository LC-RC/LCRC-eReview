<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_cpa_review.php';
requireRole('student');

$userId = (int) getCurrentUserId();
student_cpa_review_ensure_schema($conn);
$csrf = generateCSRFToken();
$pack = student_cpa_last_minute_pack($conn, $userId);
$pageTitle = 'Last-Minute Review';
$apiUrl = 'student_cpa_review_api';
$empty = $pack['total'] <= 0;
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
      <div class="cpa-hero rounded-2xl px-6 py-5 shadow-[0_10px_30px_rgba(20,61,89,0.35)]">
        <h1 class="text-xl sm:text-2xl font-bold m-0 text-white flex items-center gap-2"><i class="bi bi-pin-angle-fill text-[#F2B01E]"></i> Last-Minute Review</h1>
        <p class="text-sm text-white/90 mt-1 mb-0">Your pre-exam sprint board — pinned concepts, starred notes, unreviewed mistakes, and important quick cards.</p>
      </div>
    </section>

    <?php if ($empty): ?>
      <?php
        $cpaEmptyIcon = 'bi-pin-angle';
        $cpaEmptyTitle = 'Build your last-minute pack';
        $cpaEmptyText = 'Pin concepts on Important Concepts, star key notes, mark Quick Review cards as important, and save wrong answers to Mistakes.';
        $cpaEmptyCtaHref = 'student_cpa_important';
        $cpaEmptyCtaLabel = 'Add important concepts';
        require __DIR__ . '/includes/components/cpa_review_empty.php';
      ?>
    <?php else: ?>

      <?php if (!empty($pack['concepts'])): ?>
        <section class="mb-4 rounded-2xl border border-amber-200 bg-gradient-to-b from-amber-50/80 to-white p-4 sm:p-5">
          <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-amber-900 m-0 mb-3"><i class="bi bi-star-fill"></i> Pinned concepts</h2>
          <ul class="space-y-2 m-0 p-0 list-none">
            <?php foreach ($pack['concepts'] as $c): ?>
              <li data-cpa-row class="rounded-xl bg-white border border-amber-100 px-4 py-3 flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                  <div class="font-bold text-[#143D59]"><?php echo h($c['title']); ?></div>
                  <div class="text-xs text-[#64748b] mt-0.5"><?php echo h($c['subject_name'] ?? 'General'); ?><?php if (!empty($c['topic'])): ?> · <?php echo h($c['topic']); ?><?php endif; ?></div>
                  <?php if (!empty($c['body'])): ?>
                    <p class="text-sm text-[#334155] mt-2 mb-0 whitespace-pre-wrap"><?php echo h($c['body']); ?></p>
                  <?php endif; ?>
                </div>
                <button type="button" class="cpa-toolbar-btn" data-cpa-action="concept_last_minute"
                  data-important-id="<?php echo (int) $c['important_id']; ?>"
                  data-is-last-minute="0">Unpin</button>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if (!empty($pack['notes'])): ?>
        <section class="mb-4 rounded-2xl border border-[#1665A0]/15 bg-white p-4 sm:p-5">
          <div class="flex items-center justify-between gap-2 mb-3">
            <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-[#143D59] m-0"><i class="bi bi-journal-text"></i> Starred notes</h2>
            <a href="student_cpa_notes" class="text-xs font-semibold text-[#1665A0] hover:underline">All notes</a>
          </div>
          <ul class="space-y-2 m-0 p-0 list-none">
            <?php foreach ($pack['notes'] as $n): ?>
              <li class="rounded-xl border border-[#1665A0]/10 px-4 py-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                  <div class="font-semibold text-[#143D59]"><?php echo h($n['title'] ?: 'Note'); ?></div>
                  <div class="text-xs text-[#64748b]"><?php echo h($n['subject_name'] ?? ''); ?><?php if (!empty($n['lesson_title'])): ?> · <?php echo h($n['lesson_title']); ?><?php endif; ?></div>
                </div>
                <a href="student_cpa_notes" class="cpa-toolbar-btn">View note</a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if (!empty($pack['mistakes'])): ?>
        <section class="mb-4 rounded-2xl border border-rose-200 bg-gradient-to-b from-rose-50/50 to-white p-4 sm:p-5">
          <div class="flex items-center justify-between gap-2 mb-3">
            <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-rose-900 m-0"><i class="bi bi-x-octagon"></i> Unreviewed mistakes</h2>
            <a href="student_cpa_mistakes?reviewed=no" class="text-xs font-semibold text-[#1665A0] hover:underline">Mistake notebook</a>
          </div>
          <ul class="space-y-2 m-0 p-0 list-none">
            <?php foreach ($pack['mistakes'] as $m): ?>
              <li class="rounded-xl bg-white border border-rose-100 px-4 py-3">
                <div class="text-xs font-bold text-rose-700"><?php echo h($m['subject_name'] ?? 'Subject'); ?><?php if (!empty($m['quiz_title'])): ?> · <?php echo h($m['quiz_title']); ?><?php endif; ?></div>
                <p class="text-sm text-[#334155] mt-1 mb-0"><?php echo h($m['question_preview'] ?? 'Question'); ?>…</p>
                <div class="text-xs mt-2 text-[#64748b]">Your answer: <?php echo h($m['selected_answer'] ?: '—'); ?> · Correct: <?php echo h($m['correct_answer'] ?: '—'); ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if (!empty($pack['quick'])): ?>
        <section class="mb-4 rounded-2xl border border-[#1665A0]/15 bg-white p-4 sm:p-5">
          <div class="flex items-center justify-between gap-2 mb-3">
            <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-[#143D59] m-0"><i class="bi bi-lightning-charge"></i> Important quick cards</h2>
            <a href="student_cpa_quick_review" class="text-xs font-semibold text-[#1665A0] hover:underline">All cards</a>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <?php foreach ($pack['quick'] as $q): ?>
              <article class="rounded-xl border border-[#1665A0]/10 px-4 py-3">
                <div class="font-semibold text-[#143D59]"><?php echo h($q['title']); ?></div>
                <div class="text-xs text-[#64748b] mb-1"><?php echo h($q['subject_name'] ?? ''); ?></div>
                <p class="text-sm text-[#334155] m-0 whitespace-pre-wrap"><?php echo h(mb_substr((string) $q['content'], 0, 220)); ?><?php echo mb_strlen((string) $q['content']) > 220 ? '…' : ''; ?></p>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

    <?php endif; ?>
  </div>
  <script>window.CPA_REVIEW = { apiUrl: <?php echo json_encode($apiUrl); ?>, csrf: <?php echo json_encode($csrf); ?> };</script>
  <script src="assets/js/student-cpa-review.js"></script>
</body>
</html>
