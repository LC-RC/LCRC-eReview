<?php
require_once 'auth.php';
require_once __DIR__ . '/includes/student_cpa_review.php';
requireRole('student');

$userId = (int) getCurrentUserId();
student_cpa_review_ensure_schema($conn);
$counts = student_cpa_dashboard_counts($conn, $userId);
$activity = student_cpa_recent_activity($conn, $userId, 10);
$continue = student_cpa_continue_review($conn, $userId);
$focus = student_cpa_your_focus($conn, $userId, 5);
$progressPct = student_cpa_overall_progress_pct($conn, $userId);
$lastMinutePack = student_cpa_last_minute_pack($conn, $userId);
$pageTitle = 'My CPA Review';

$cards = [
    ['label' => 'Notes', 'count' => $counts['notes'], 'href' => 'student_cpa_notes', 'icon' => 'bi-journal-text', 'hint' => 'Study notebook'],
    ['label' => 'Bookmarks', 'count' => $counts['bookmarks'], 'href' => 'student_cpa_bookmarks', 'icon' => 'bi-bookmark', 'hint' => 'Return later'],
    ['label' => 'Important', 'count' => $counts['important'], 'href' => 'student_cpa_important', 'icon' => 'bi-star', 'hint' => 'Key concepts'],
    ['label' => 'Mistakes', 'count' => $counts['mistakes'], 'href' => 'student_cpa_mistakes', 'icon' => 'bi-x-octagon', 'hint' => $counts['mistakes_unreviewed'] > 0 ? ($counts['mistakes_unreviewed'] . ' unreviewed') : 'Review wrong answers'],
    ['label' => 'Quick Review', 'count' => $counts['quick_review'], 'href' => 'student_cpa_quick_review', 'icon' => 'bi-lightning-charge', 'hint' => 'Condensed cards'],
    [
        'label' => 'Progress',
        'href' => 'student_cpa_progress',
        'icon' => 'bi-graph-up',
        'hint' => $progressPct !== null ? 'Overall study progress' : 'Videos & quiz accuracy',
        'count' => $progressPct,
        'display' => $progressPct !== null ? ((int) $progressPct . '%') : 'View',
        'is_progress' => true,
    ],
];

$activityIcon = static function (string $action): string {
    if (str_contains($action, 'note')) {
        return 'bi-journal-text';
    }
    if (str_contains($action, 'bookmark')) {
        return 'bi-bookmark';
    }
    if (str_contains($action, 'mistake')) {
        return 'bi-x-octagon';
    }
    if (str_contains($action, 'concept') || str_contains($action, 'important')) {
        return 'bi-star';
    }
    if (str_contains($action, 'quick')) {
        return 'bi-lightning-charge';
    }
    return 'bi-circle';
};
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
    <section class="mb-5">
      <div class="cpa-hero rounded-2xl px-6 py-6 shadow-[0_10px_30px_rgba(20,61,89,0.35)]">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="min-w-0">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-white/70 m-0 mb-2">Personal workspace</p>
            <h1 class="text-2xl sm:text-3xl font-bold m-0 tracking-tight text-white">My CPA Review</h1>
            <p class="text-sm sm:text-base text-white/90 mt-2 mb-0 max-w-xl">Your personal CPA study workspace. Everything you’ve saved, written, and need to review.</p>
          </div>
          <a href="student_cpa_last_minute" class="cpa-last-minute-btn">
            <i class="bi bi-pin-angle-fill" aria-hidden="true"></i>
            <span>Last-Minute Review<?php if ($lastMinutePack['total'] > 0): ?> (<?php echo (int) $lastMinutePack['total']; ?>)<?php endif; ?></span>
          </a>
        </div>
      </div>
    </section>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-6">
      <?php foreach ($cards as $c): ?>
        <a href="<?php echo h($c['href']); ?>" class="cpa-count-card cpa-workspace-card">
          <div class="flex items-center justify-between mb-3">
            <span class="cpa-card-icon"><i class="bi <?php echo h($c['icon']); ?>"></i></span>
            <i class="bi bi-chevron-right text-[#1665A0]/40"></i>
          </div>
          <div class="cpa-count"><?php echo h((string) ($c['display'] ?? (string) (int) $c['count'])); ?></div>
          <div class="cpa-count-label"><?php echo h($c['label']); ?></div>
          <?php if (!empty($c['hint'])): ?>
            <div class="text-xs text-[#64748b] mt-1"><?php echo h($c['hint']); ?></div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($continue): ?>
      <?php
        $headline = $continue['subject_name'];
        if (!empty($continue['topic'])) {
            $headline .= ' — ' . $continue['topic'];
        }
      ?>
      <section class="mb-5 rounded-2xl border border-[#1665A0]/15 bg-white p-5 shadow-[0_1px_4px_rgba(15,23,42,0.06)]">
        <h2 class="text-base font-bold text-[#143D59] m-0 mb-3 flex items-center gap-2"><i class="bi bi-play-circle text-[#1665A0]"></i> Continue your review</h2>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="font-semibold text-[#143D59] m-0 text-lg"><?php echo h($headline); ?></p>
            <p class="text-sm text-[#64748b] mt-1 mb-0">Last activity: <?php echo h($continue['activity_label']); ?><?php if (!empty($continue['detail'])): ?> — <?php echo h($continue['detail']); ?><?php endif; ?></p>
          </div>
          <div class="flex flex-wrap gap-2">
            <?php if (!empty($continue['note_id'])): ?>
              <a href="student_cpa_notes" class="cpa-toolbar-btn is-active">View note</a>
            <?php elseif (!empty($continue['important_id'])): ?>
              <a href="student_cpa_important?edit=<?php echo (int) $continue['important_id']; ?>" class="cpa-toolbar-btn is-active">View concept</a>
            <?php elseif (!empty($continue['bookmarks'])): ?>
              <a href="student_cpa_bookmarks" class="cpa-toolbar-btn is-active">Open bookmarks</a>
            <?php elseif (!empty($continue['mistakes'])): ?>
              <a href="student_cpa_mistakes?reviewed=no" class="cpa-toolbar-btn is-active">Review mistakes</a>
            <?php endif; ?>
            <?php if (!empty($continue['lesson_id'])): ?>
              <a href="student_lesson_viewer?lesson_id=<?php echo (int) $continue['lesson_id']; ?>&subject_id=<?php echo (int) ($continue['subject_id'] ?? 0); ?>" class="cpa-toolbar-btn">Open lesson</a>
            <?php elseif (!empty($continue['subject_id'])): ?>
              <a href="student_subject?subject_id=<?php echo (int) $continue['subject_id']; ?>" class="cpa-toolbar-btn">Open subject</a>
            <?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <section class="mb-5 rounded-2xl border border-[#1665A0]/15 bg-gradient-to-b from-[#f4f8fe] to-white p-5 shadow-[0_1px_4px_rgba(15,23,42,0.06)]">
      <h2 class="text-base font-bold text-[#143D59] m-0 mb-3 flex items-center gap-2"><i class="bi bi-bullseye text-[#1665A0]"></i> Your focus</h2>
      <?php if (empty($focus)): ?>
        <p class="text-sm text-[#64748b] m-0">No focus areas yet. Submit quizzes and save mistakes from wrong answers — subjects with lower accuracy or unreviewed mistakes will appear here.</p>
      <?php else: ?>
        <ul class="space-y-3 m-0 p-0 list-none">
          <?php foreach ($focus as $f): ?>
            <li class="rounded-xl bg-white border border-[#1665A0]/10 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
              <div class="min-w-0">
                <p class="font-semibold text-[#143D59] m-0"><?php echo h($f['subject_name']); ?></p>
                <p class="text-sm text-[#64748b] mt-1 mb-0">
                  <?php if ($f['accuracy'] !== null): ?>
                    <?php echo h(number_format((float) $f['accuracy'], 0)); ?>% quiz accuracy
                  <?php else: ?>
                    Quiz accuracy unavailable
                  <?php endif; ?>
                  <?php if ((int) $f['unreviewed'] > 0): ?>
                    · <?php echo (int) $f['unreviewed']; ?> unreviewed mistake<?php echo (int) $f['unreviewed'] === 1 ? '' : 's'; ?>
                  <?php elseif ((int) $f['total_mistakes'] > 0): ?>
                    · <?php echo (int) $f['total_mistakes']; ?> saved mistake<?php echo (int) $f['total_mistakes'] === 1 ? '' : 's'; ?>
                  <?php endif; ?>
                </p>
              </div>
              <div class="flex flex-wrap gap-2">
                <?php if ((int) $f['unreviewed'] > 0 || (int) $f['total_mistakes'] > 0): ?>
                  <a href="student_cpa_mistakes?subject_id=<?php echo (int) $f['subject_id']; ?>&reviewed=no" class="cpa-toolbar-btn">Review mistakes</a>
                <?php endif; ?>
                <a href="student_subject?subject_id=<?php echo (int) $f['subject_id']; ?>" class="cpa-toolbar-btn is-active">Review topic</a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <section class="rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white shadow-[0_1px_4px_rgba(15,23,42,0.08)] p-5">
        <h2 class="text-base font-bold text-[#143D59] m-0 mb-3 flex items-center gap-2"><i class="bi bi-activity"></i> Recent activity</h2>
        <?php
          $visibleActivity = [];
          foreach ($activity as $a) {
              $sum = trim((string) ($a['summary'] ?? $a['action'] ?? ''));
              if ($sum === '' || preg_match('/\b(sample|smoke|demo)\b/i', $sum)) {
                  continue;
              }
              $visibleActivity[] = $a + ['_sum' => $sum];
          }
        ?>
        <?php if (empty($visibleActivity)): ?>
          <p class="text-sm text-[#64748b] m-0">No activity yet. Use Add Note or Bookmark on a lesson, then manage everything here.</p>
        <?php else: ?>
          <ul class="space-y-2.5 m-0 p-0 list-none">
            <?php foreach ($visibleActivity as $a): ?>
              <li class="flex items-start gap-2.5 text-sm">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#e8f2fa] text-[#1665A0]"><i class="bi <?php echo h($activityIcon((string) ($a['action'] ?? ''))); ?>"></i></span>
                <div class="min-w-0 flex-1 border-b border-[#1665A0]/8 pb-2">
                  <div class="text-[#143D59] font-medium"><?php echo h($a['_sum']); ?></div>
                  <div class="text-xs text-[#64748b]"><?php echo !empty($a['created_at']) ? h(date('M j, g:i A', strtotime($a['created_at']))) : ''; ?></div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <section class="rounded-2xl border border-[#1665A0]/12 bg-gradient-to-b from-[#f4f8fe] to-white shadow-[0_1px_4px_rgba(15,23,42,0.08)] p-5">
        <div class="flex items-center justify-between gap-2 mb-3">
          <h2 class="text-base font-bold text-[#143D59] m-0 flex items-center gap-2"><i class="bi bi-pin-angle"></i> Pre-exam pack</h2>
          <a href="student_cpa_last_minute" class="text-xs font-semibold text-[#1665A0] hover:underline">Open Last-Minute Review</a>
        </div>
        <p class="text-sm text-[#64748b] m-0 mb-3">Pinned concepts, starred notes, unreviewed mistakes, and important quick cards — ready for exam week.</p>
        <div class="grid grid-cols-2 gap-2 text-sm">
          <div class="rounded-lg bg-white border border-[#1665A0]/10 px-3 py-2"><span class="font-bold text-[#143D59]"><?php echo count($lastMinutePack['concepts']); ?></span> <span class="text-[#64748b]">concepts</span></div>
          <div class="rounded-lg bg-white border border-[#1665A0]/10 px-3 py-2"><span class="font-bold text-[#143D59]"><?php echo count($lastMinutePack['notes']); ?></span> <span class="text-[#64748b]">starred notes</span></div>
          <div class="rounded-lg bg-white border border-[#1665A0]/10 px-3 py-2"><span class="font-bold text-[#143D59]"><?php echo count($lastMinutePack['mistakes']); ?></span> <span class="text-[#64748b]">unreviewed</span></div>
          <div class="rounded-lg bg-white border border-[#1665A0]/10 px-3 py-2"><span class="font-bold text-[#143D59]"><?php echo count($lastMinutePack['quick']); ?></span> <span class="text-[#64748b]">quick cards</span></div>
        </div>
      </section>
    </div>
  </div>
</body>
</html>
