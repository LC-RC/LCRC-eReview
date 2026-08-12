<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_cpa_review.php';
requireRole('student');

$userId = (int) getCurrentUserId();
student_cpa_review_ensure_schema($conn);
$progress = student_cpa_progress_by_subject($conn, $userId);
$weak = student_cpa_weak_areas($conn, $userId, 5);
$pageTitle = 'My Progress';
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
        <h1 class="text-xl sm:text-2xl font-bold m-0">Progress</h1>
        <p class="text-sm text-white/90 mt-1 mb-0">Video completion and quiz accuracy by subject from your real activity.</p>
      </div>
    </section>

    <?php if (empty($progress)): ?>
      <?php
        $cpaEmptyIcon = 'bi-graph-up';
        $cpaEmptyTitle = 'No progress data yet';
        $cpaEmptyText = 'Watch lesson videos or submit quizzes to build your progress map.';
        $cpaEmptyCtaHref = 'student_subjects';
        $cpaEmptyCtaLabel = 'Start learning';
        require __DIR__ . '/includes/components/cpa_review_empty.php';
      ?>
    <?php else: ?>
      <div class="space-y-3 mb-6">
        <?php foreach ($progress as $p): ?>
          <article class="rounded-2xl border border-[#1665A0]/12 bg-white p-4 sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
              <h2 class="text-base font-bold text-[#143D59] m-0">
                <a href="student_subject?subject_id=<?php echo (int) $p['subject_id']; ?>" class="hover:underline"><?php echo h($p['subject_name']); ?></a>
              </h2>
              <?php if ($p['quiz_label']): ?>
                <span class="text-xs font-bold px-2 py-1 rounded-full <?php echo ($p['quiz_accuracy'] ?? 100) < 75 ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'; ?>">
                  Quiz: <?php echo h(number_format((float) $p['quiz_accuracy'], 0)); ?>% · <?php echo h($p['quiz_label']); ?>
                </span>
              <?php endif; ?>
            </div>
            <?php if ($p['video_pct'] !== null): ?>
              <div class="mb-2">
                <div class="flex justify-between text-xs text-[#64748b] mb-1">
                  <span>Videos watched</span>
                  <span><?php echo (int) $p['video_done']; ?>/<?php echo (int) $p['video_total']; ?> (<?php echo h(number_format((float) $p['video_pct'], 0)); ?>%)</span>
                </div>
                <div class="h-2 rounded-full bg-[#1665A0]/15 overflow-hidden">
                  <div class="h-full bg-[#1665A0]" style="width: <?php echo h((string) min(100, max(0, (float) $p['video_pct']))); ?>%"></div>
                </div>
              </div>
            <?php endif; ?>
            <?php if ($p['quiz_accuracy'] !== null): ?>
              <div class="text-xs text-[#64748b]">Based on <?php echo (int) $p['quiz_answers']; ?> submitted quiz answers.</div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <section class="rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white p-5">
      <h2 class="text-base font-bold text-[#143D59] m-0 mb-3">Weak areas (under 75%)</h2>
      <?php
        $needs = array_values(array_filter($weak, static fn($w) => ($w['accuracy'] ?? 100) < 75));
      ?>
      <?php if (empty($needs)): ?>
        <p class="text-sm text-[#64748b] m-0">No weak areas with enough data, or all subjects are at Good (≥75%).</p>
      <?php else: ?>
        <ul class="space-y-2 m-0 p-0 list-none">
          <?php foreach ($needs as $w): ?>
            <li class="flex items-center justify-between gap-2 rounded-lg bg-white border border-rose-100 px-3 py-2">
              <a href="student_subject?subject_id=<?php echo (int) $w['subject_id']; ?>" class="font-semibold text-[#143D59] hover:underline"><?php echo h($w['subject_name']); ?></a>
              <span class="text-xs font-bold text-rose-700"><?php echo h(number_format((float) $w['accuracy'], 0)); ?>%</span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
