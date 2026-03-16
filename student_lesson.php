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
$subjectId = (int)$lesson['subject_id'];

$stmt = mysqli_prepare($conn, "SELECT * FROM lesson_videos WHERE lesson_id=? ORDER BY video_id ASC");
mysqli_stmt_bind_param($stmt, 'i', $lessonId);
mysqli_stmt_execute($stmt);
$videos = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT * FROM lesson_handouts WHERE lesson_id=? ORDER BY handout_id DESC");
mysqli_stmt_bind_param($stmt, 'i', $lessonId);
mysqli_stmt_execute($stmt);
$handouts = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

$pageTitle = $lesson['subject_name'].' - '.$lesson['title'].' - Materials';
$firstVideo = $videos ? mysqli_fetch_assoc($videos) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>.video-embed { aspect-ratio: 16/9; width: 100%; border: 0; border-radius: 8px; background: #000; } .split-view-container { display: none; gap: 15px; height: calc(100vh - 200px); } .split-view-container.active { display: flex !important; } .separate-view.hidden { display: none !important; }</style>
</head>
<body class="font-sans antialiased" x-data="{ viewMode: 'separate', handoutModalOpen: false, handoutModalTitle: '', handoutModalSrc: '', splitHandoutId: '' }">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5 flex flex-wrap justify-between items-center gap-4">
    <div>
      <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
        <i class="bi bi-collection-play"></i> <?php echo h($lesson['subject_name']); ?>
      </h1>
      <p class="text-gray-500 mt-1">Lesson: <?php echo h($lesson['title']); ?></p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <div class="inline-flex rounded-lg border-2 border-gray-200 p-1">
        <button type="button" @click="viewMode = 'separate'" :class="viewMode === 'separate' ? 'bg-primary text-white border-primary' : 'bg-white text-gray-600'" class="px-3 py-1.5 rounded-md text-sm font-medium transition"><i class="bi bi-layout-split"></i> Separate</button>
        <button type="button" @click="viewMode = 'split'" :class="viewMode === 'split' ? 'bg-primary text-white border-primary' : 'bg-white text-gray-600'" class="px-3 py-1.5 rounded-md text-sm font-medium transition"><i class="bi bi-columns"></i> Split View</button>
      </div>
      <a href="student_lessons.php?subject_id=<?php echo (int)$subjectId; ?>" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition">Back to Lessons</a>
    </div>
  </div>

  <!-- Split View -->
  <div class="split-view-container mb-5" :class="{ 'active': viewMode === 'split' }">
    <div class="flex-1 min-w-0 bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
      <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-800"><i class="bi bi-play-circle mr-2"></i> Video Player</div>
      <div class="p-0">
        <?php if ($firstVideo): ?>
          <?php
            $url = $firstVideo['video_url'];
            $embed = $url;
            $isLocal = strpos($url, 'uploads/videos/') === 0;
            if (!$isLocal && (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false)) {
              if (preg_match('/(?:v=|\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) $embed = 'https://www.youtube.com/embed/'.$m[1].'?rel=0';
            } elseif (!$isLocal && strpos($url, 'vimeo.com') !== false) {
              if (preg_match('/vimeo.com\/(\d+)/', $url, $m)) $embed = 'https://player.vimeo.com/video/'.$m[1];
            }
          ?>
          <?php if ($isLocal): ?>
            <video class="video-embed" controls style="height: 100%;"><source src="<?php echo h($url); ?>" type="video/mp4"></video>
          <?php else: ?>
            <iframe class="video-embed" src="<?php echo h($embed); ?>" allowfullscreen style="height: 100%;"></iframe>
          <?php endif; ?>
        <?php else: ?>
          <div class="text-center text-gray-500 py-12"><i class="bi bi-play-circle text-4xl block mb-2"></i><p>No videos available for this lesson.</p></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="flex-1 min-w-0 bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden flex flex-col" style="border-left: 2px solid #e5e7eb;">
      <div class="px-4 py-3 border-b border-gray-100 flex justify-between items-center flex-wrap gap-2">
        <span class="font-semibold text-gray-800"><i class="bi bi-file-earmark-pdf mr-2"></i> Handout</span>
        <select x-model="splitHandoutId" @change="if ($event.target.value) { handoutModalSrc = 'handout_viewer.php?handout_id=' + $event.target.value; handoutModalOpen = true; handoutModalTitle = $event.target.options[$event.target.selectedIndex].text; }" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-auto min-w-[200px]">
          <option value="">Select Handout...</option>
          <?php mysqli_data_seek($handouts, 0); while ($h = mysqli_fetch_assoc($handouts)): if (!empty($h['file_path'])): ?>
            <option value="<?php echo (int)$h['handout_id']; ?>"><?php echo h($h['handout_title'] ?: 'Untitled Handout'); ?></option>
          <?php endif; endwhile; ?>
        </select>
      </div>
      <div class="flex-1 min-h-0 relative bg-gray-100">
        <template x-if="splitHandoutId">
          <iframe :src="'handout_viewer.php?handout_id=' + splitHandoutId" class="absolute inset-0 w-full h-full border-0 rounded-b-xl bg-white"></iframe>
        </template>
        <div x-show="!splitHandoutId" class="absolute inset-0 flex items-center justify-center flex-col text-gray-500">
          <i class="bi bi-file-earmark-pdf text-4xl block mb-2"></i>
          <p>Select a handout to view</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Separate View (default) -->
  <div class="separate-view" :class="{ 'hidden': viewMode === 'split' }">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
      <div class="lg:col-span-8 space-y-5">
        <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 font-semibold text-gray-800"><i class="bi bi-play-circle mr-2"></i> Video Player</div>
          <div class="p-5">
            <?php if ($firstVideo): ?>
              <?php $url = $firstVideo['video_url']; $embed = $url; $isLocal = strpos($url, 'uploads/videos/') === 0;
                if (!$isLocal && (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false)) { if (preg_match('/(?:v=|\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) $embed = 'https://www.youtube.com/embed/'.$m[1].'?rel=0'; }
                elseif (!$isLocal && strpos($url, 'vimeo.com') !== false) { if (preg_match('/vimeo.com\/(\d+)/', $url, $m)) $embed = 'https://player.vimeo.com/video/'.$m[1]; }
              ?>
              <?php if ($isLocal): ?>
                <video class="video-embed" controls><source src="<?php echo h($url); ?>" type="video/mp4"></video>
              <?php else: ?>
                <iframe class="video-embed" src="<?php echo h($embed); ?>" allowfullscreen></iframe>
              <?php endif; ?>
              <h2 class="mt-3 font-semibold text-gray-800"><?php echo h($firstVideo['video_title'] ?: 'Untitled Video'); ?></h2>
            <?php else: ?>
              <div class="text-center text-gray-500 py-12"><i class="bi bi-play-circle text-4xl block mb-2"></i><p>No videos available for this lesson.</p></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5">
          <h3 class="font-semibold text-gray-800 mb-3"><i class="bi bi-file-earmark-pdf mr-2"></i> Handouts</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php mysqli_data_seek($handouts, 0); while ($h = mysqli_fetch_assoc($handouts)): ?>
              <?php
                $fileExt = pathinfo($h['file_path'], PATHINFO_EXTENSION);
                $iconClass = in_array(strtolower($fileExt), ['pdf']) ? 'bi-file-earmark-pdf' : (in_array(strtolower($fileExt), ['doc','docx']) ? 'bi-file-earmark-word' : (in_array(strtolower($fileExt), ['ppt','pptx']) ? 'bi-file-earmark-slides' : 'bi-file-earmark'));
                $sizeLabel = !empty($h['file_size']) ? number_format((int)$h['file_size']/1024, 1) . ' KB' : '';
              ?>
              <div class="border border-gray-100 rounded-xl p-4">
                <div class="flex items-start gap-3 mb-2">
                  <i class="bi <?php echo $iconClass; ?> text-primary text-2xl"></i>
                  <div>
                    <div class="font-semibold text-gray-800"><?php echo h($h['handout_title'] ?: 'Untitled Handout'); ?></div>
                    <?php if ($sizeLabel): ?><div class="text-gray-500 text-sm"><?php echo $sizeLabel; ?></div><?php endif; ?>
                  </div>
                </div>
                <div class="flex flex-wrap gap-2 mt-3">
                  <?php if (!empty($h['file_path'])): ?>
                    <button type="button" data-handout-id="<?php echo (int)$h['handout_id']; ?>" data-handout-title="<?php echo h($h['handout_title'] ?: 'Handout'); ?>" @click="handoutModalTitle = $el.dataset.handoutTitle || 'Handout'; handoutModalSrc = 'handout_viewer.php?handout_id=' + $el.dataset.handoutId; handoutModalOpen = true" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition"><i class="bi bi-eye"></i> View</button>
                  <?php endif; ?>
                  <?php if (!empty($h['allow_download']) && !empty($h['file_path'])): ?>
                    <a href="<?php echo h($h['file_path']); ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg font-semibold border-2 border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-download"></i> Download</a>
                  <?php elseif (empty($h['allow_download'])): ?>
                    <div class="p-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-center gap-2"><i class="bi bi-lock"></i> Downloads locked by administrator.</div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endwhile; ?>
            <?php if (mysqli_num_rows($handouts) == 0): ?>
              <div class="col-span-2 text-center text-gray-500 py-6"><i class="bi bi-inbox text-3xl block mb-2"></i><p>No handouts for this lesson.</p></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="lg:col-span-4">
        <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden max-h-[65vh] overflow-y-auto">
          <div class="px-5 py-4 border-b border-gray-100 font-semibold text-gray-800"><i class="bi bi-list mr-2"></i> Playlist</div>
          <div class="divide-y divide-gray-100">
            <?php mysqli_data_seek($videos, 0); while ($v = mysqli_fetch_assoc($videos)): ?>
              <a href="student_videos.php?lesson_id=<?php echo (int)$lessonId; ?>&subject_id=<?php echo (int)$subjectId; ?>&video=<?php echo (int)$v['video_id']; ?>" class="flex items-center gap-2 px-5 py-3 hover:bg-gray-50 text-gray-700 transition">
                <i class="bi bi-play-circle"></i> <?php echo h($v['video_title'] ?: 'Untitled Video'); ?>
              </a>
            <?php endwhile; ?>
            <?php if (!$firstVideo): ?>
              <div class="px-5 py-6 text-center text-gray-500">No videos</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Handout Viewer Modal -->
  <div x-show="handoutModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="handoutModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="handoutModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col" @click.stop>
      <div class="p-4 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-xl font-bold text-gray-800 m-0" x-text="handoutModalTitle || 'Handout Preview'"></h2>
        <button type="button" @click="handoutModalOpen = false; handoutModalSrc = ''" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="flex-1 min-h-0 relative" style="aspect-ratio: 4/3;">
        <iframe x-show="handoutModalSrc" :src="handoutModalSrc" class="absolute inset-0 w-full h-full border-0 rounded-b-xl" allowfullscreen></iframe>
      </div>
    </div>
  </div>
</main>
</div>
</body>
</html>
