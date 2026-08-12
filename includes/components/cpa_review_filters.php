<?php
/**
 * Filter bar for CPA Review list pages.
 * Vars: $cpaFilterAction, $cpaSubjects, $cpaLessons (optional), $cpaShowLesson, $cpaExtraFilters (HTML), $cpaQ, $cpaSubjectId, $cpaLessonId, $cpaSort, $cpaShowSort
 */
$cpaFilterAction = $cpaFilterAction ?? '';
$cpaSubjects = $cpaSubjects ?? [];
$cpaLessons = $cpaLessons ?? [];
$cpaShowLesson = !empty($cpaShowLesson);
$cpaShowSort = $cpaShowSort ?? true;
$cpaExtraFilters = $cpaExtraFilters ?? '';
$cpaQ = (string) ($cpaQ ?? ($_GET['q'] ?? ''));
$cpaSubjectId = (int) ($cpaSubjectId ?? ($_GET['subject_id'] ?? 0));
$cpaLessonId = (int) ($cpaLessonId ?? ($_GET['lesson_id'] ?? 0));
$cpaSort = (string) ($cpaSort ?? ($_GET['sort'] ?? 'updated_desc'));
?>
<form method="get" action="<?php echo h($cpaFilterAction); ?>" class="cpa-filters mb-4 rounded-2xl border border-[#1665A0]/12 bg-white/80 p-3 sm:p-4 flex flex-wrap items-end gap-3">
  <div class="flex-1 min-w-[160px]">
    <label class="block text-xs font-bold uppercase tracking-wide text-[#64748b] mb-1">Search</label>
    <input type="search" name="q" value="<?php echo h($cpaQ); ?>" placeholder="Search…" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm">
  </div>
  <div class="min-w-[140px]">
    <label class="block text-xs font-bold uppercase tracking-wide text-[#64748b] mb-1">Subject</label>
    <select name="subject_id" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm">
      <option value="0">All subjects</option>
      <?php foreach ($cpaSubjects as $s): ?>
        <option value="<?php echo (int) $s['subject_id']; ?>" <?php echo $cpaSubjectId === (int) $s['subject_id'] ? 'selected' : ''; ?>><?php echo h($s['subject_name']); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($cpaShowLesson): ?>
  <div class="min-w-[140px]">
    <label class="block text-xs font-bold uppercase tracking-wide text-[#64748b] mb-1">Lesson</label>
    <select name="lesson_id" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm">
      <option value="0">All lessons</option>
      <?php foreach ($cpaLessons as $l): ?>
        <option value="<?php echo (int) $l['lesson_id']; ?>" <?php echo $cpaLessonId === (int) $l['lesson_id'] ? 'selected' : ''; ?>><?php echo h($l['title']); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <?php if ($cpaShowSort): ?>
  <div class="min-w-[120px]">
    <label class="block text-xs font-bold uppercase tracking-wide text-[#64748b] mb-1">Sort</label>
    <select name="sort" class="w-full rounded-lg border border-[#1665A0]/25 px-3 py-2 text-sm">
      <option value="updated_desc" <?php echo $cpaSort === 'updated_desc' ? 'selected' : ''; ?>>Recently updated</option>
      <option value="newest" <?php echo $cpaSort === 'newest' ? 'selected' : ''; ?>>Newest</option>
      <option value="oldest" <?php echo $cpaSort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
      <option value="title" <?php echo $cpaSort === 'title' ? 'selected' : ''; ?>>Title A–Z</option>
    </select>
  </div>
  <?php endif; ?>
  <?php echo $cpaExtraFilters; ?>
  <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold bg-[#1665A0] text-white hover:bg-[#0f4d7a] transition">Filter</button>
</form>
