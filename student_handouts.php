<?php
require_once 'auth.php';
requireRole('student');

$lessonId = sanitizeInt($_GET['lesson_id'] ?? 0);
$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($lessonId <= 0) { header('Location: student_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT l.*, s.subject_name FROM lessons l JOIN subjects s ON s.subject_id=l.subject_id WHERE l.lesson_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $lessonId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$lesson = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
if (!$lesson) { header('Location: student_subjects.php'); exit; }

$handoutsResult = mysqli_query($conn, "SELECT * FROM lesson_handouts WHERE lesson_id=".$lessonId." ORDER BY handout_id DESC");
$pageTitle = $lesson['title'] . ' - Handouts';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased" x-data="{ handoutModalOpen: false, handoutModalTitle: '', handoutModalSrc: '' }">
  <?php include 'student_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-file-earmark-pdf"></i> Handouts - <?php echo h($lesson['title']); ?> (<?php echo h($lesson['subject_name']); ?>)
    </h1>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php if ($handoutsResult && mysqli_num_rows($handoutsResult) > 0): ?>
      <?php while ($h = mysqli_fetch_assoc($handoutsResult)): ?>
        <?php
          $fileExt = pathinfo($h['file_path'], PATHINFO_EXTENSION);
          $iconClass = 'bi-file-earmark';
          if (in_array(strtolower($fileExt), ['pdf'])) $iconClass = 'bi-file-earmark-pdf';
          elseif (in_array(strtolower($fileExt), ['doc', 'docx'])) $iconClass = 'bi-file-earmark-word';
          elseif (in_array(strtolower($fileExt), ['ppt', 'pptx'])) $iconClass = 'bi-file-earmark-slides';
          elseif (in_array(strtolower($fileExt), ['xls', 'xlsx'])) $iconClass = 'bi-file-earmark-spreadsheet';
          $sizeLabel = '';
          if (!empty($h['file_size'])) {
            $size = (int)$h['file_size'];
            $units = ['B','KB','MB','GB','TB'];
            $unitIndex = 0;
            while ($size >= 1024 && $unitIndex < count($units)-1) { $size /= 1024; $unitIndex++; }
            $sizeLabel = number_format($size, ($size >= 10 || $unitIndex === 0) ? 0 : 1) . ' ' . $units[$unitIndex];
          }
          $updatedLabel = '';
          if (!empty($h['uploaded_at'])) {
            $ts = strtotime($h['uploaded_at']);
            if ($ts) $updatedLabel = date('M d, Y g:i A', $ts);
          }
        ?>
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 h-full flex flex-col">
          <div class="flex items-start gap-3 mb-3">
            <i class="bi <?php echo $iconClass; ?> text-primary text-3xl flex-shrink-0"></i>
            <div class="min-w-0">
              <h2 class="text-lg font-bold text-gray-800"><?php echo h($h['handout_title'] ?: 'Untitled Handout'); ?></h2>
              <?php if ($sizeLabel): ?><p class="text-gray-500 text-sm"><?php echo h($sizeLabel); ?></p><?php endif; ?>
              <?php if ($updatedLabel): ?><p class="text-gray-500 text-sm">Updated: <?php echo h($updatedLabel); ?></p><?php endif; ?>
            </div>
          </div>
          <div class="mt-auto space-y-2">
            <?php if (!empty($h['file_path'])): ?>
              <button type="button" data-handout-id="<?php echo (int)$h['handout_id']; ?>" data-handout-title="<?php echo h($h['handout_title'] ?: 'Handout'); ?>" @click="handoutModalTitle = $el.dataset.handoutTitle || 'Handout'; handoutModalSrc = 'handout_viewer.php?handout_id=' + $el.dataset.handoutId; handoutModalOpen = true" class="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition">
                <i class="bi bi-eye"></i> View
              </button>
            <?php endif; ?>
            <?php if (!empty($h['allow_download']) && !empty($h['file_path'])): ?>
              <a href="<?php echo h($h['file_path']); ?>" target="_blank" rel="noopener" class="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg font-semibold border-2 border-primary text-primary hover:bg-primary hover:text-white transition">
                <i class="bi bi-download"></i> Download
              </a>
            <?php elseif (empty($h['allow_download'])): ?>
              <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 flex items-center gap-2 text-amber-800 text-sm">
                <i class="bi bi-lock"></i> Downloads locked by administrator.
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endwhile; ?>
    <?php else: ?>
      <div class="col-span-full">
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-12 text-center text-gray-500">
          <i class="bi bi-inbox text-5xl block mb-3"></i>
          <p class="text-lg font-semibold">No handouts available yet.</p>
          <a href="student_lessons.php?subject_id=<?php echo (int)$lesson['subject_id']; ?>" class="mt-3 inline-flex items-center gap-2 px-4 py-2 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Back to Lessons</a>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Handout Viewer Modal (Alpine) -->
  <div x-show="handoutModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="handoutModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="handoutModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col" @click.stop>
      <div class="p-4 border-b border-gray-200 flex justify-between items-center flex-shrink-0">
        <h2 class="text-xl font-bold text-gray-800 m-0" x-text="handoutModalTitle || 'Handout Preview'"></h2>
        <button type="button" @click="handoutModalOpen = false; handoutModalSrc = ''" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="flex-1 min-h-0 relative" style="aspect-ratio: 4/3;">
        <iframe x-show="handoutModalSrc" :src="handoutModalSrc" class="absolute inset-0 w-full h-full border-0 rounded-b-xl" allowfullscreen></iframe>
      </div>
    </div>
  </div>
</main>
</body>
</html>
