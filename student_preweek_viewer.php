<?php
/**
 * Full-page Preweek materials viewer (videos + handouts) — same UI/behavior as student_lesson_viewer.php.
 */
require_once 'auth.php';
requireRole('student');
require_once __DIR__ . '/includes/preweek_migrate.php';

$topicId = (int)($_GET['preweek_topic_id'] ?? 0);
$preweekUnitId = (int)($_GET['preweek_unit_id'] ?? 0);
$yearGet = (int)($_GET['year'] ?? 0);

if ($topicId <= 0 && $yearGet >= 2000 && $yearGet <= 2100) {
    header('Location: student_preweek.php');
    exit;
}

if ($topicId <= 0 && $preweekUnitId > 0) {
    header('Location: student_preweek_topics.php?preweek_unit_id=' . $preweekUnitId);
    exit;
}

$topicRow = null;
$unit = null;
$preweekUnitId = 0;

if ($topicId > 0) {
    $tr = mysqli_query(
        $conn,
        'SELECT t.preweek_topic_id, t.title AS topic_title, t.preweek_unit_id, u.title AS unit_title, u.subject_id
         FROM preweek_topics t
         INNER JOIN preweek_units u ON u.preweek_unit_id = t.preweek_unit_id
         WHERE t.preweek_topic_id=' . (int)$topicId . ' AND u.subject_id=0
         LIMIT 1'
    );
    $topicRow = $tr ? mysqli_fetch_assoc($tr) : null;
    if ($topicRow) {
        $preweekUnitId = (int)$topicRow['preweek_unit_id'];
        $unit = ['preweek_unit_id' => $preweekUnitId, 'title' => $topicRow['unit_title']];
    }
}

if (!$topicRow || $preweekUnitId <= 0) {
    $_SESSION['error'] = 'Pre-week materials were not found.';
    header('Location: student_preweek.php');
    exit;
}

$viewerQueryBase = ['preweek_topic_id' => $topicId];

$videosResult = mysqli_query($conn, "SELECT preweek_video_id, video_title, video_url FROM preweek_videos WHERE preweek_topic_id = " . (int)$topicId . " ORDER BY preweek_video_id ASC");
$handoutsResult = mysqli_query($conn, "SELECT preweek_handout_id, handout_title, file_path, file_name, file_size, allow_download FROM preweek_handouts WHERE preweek_topic_id = " . (int)$topicId . " ORDER BY preweek_handout_id DESC");

$handouts = [];
if ($handoutsResult) while ($h = mysqli_fetch_assoc($handoutsResult)) $handouts[] = $h;
$firstHandoutId = (count($handouts) > 0 && !empty($handouts[0]['preweek_handout_id'])) ? (int)$handouts[0]['preweek_handout_id'] : '';

$selectedVideoId = (int)($_GET['video'] ?? 0);
$videos = [];
while ($videosResult && ($row = mysqli_fetch_assoc($videosResult))) {
    $videos[] = $row;
}
$selectedVideo = null;
if ($selectedVideoId > 0) {
    foreach ($videos as $v) {
        if ((int)$v['preweek_video_id'] === $selectedVideoId) {
            $selectedVideo = $v;
            break;
        }
    }
}
if (!$selectedVideo && count($videos) > 0) {
    $selectedVideo = $videos[0];
}

$topicTitle = trim((string)($topicRow['topic_title'] ?? '')) ?: 'Lecture';
$unitTitle = trim((string)($topicRow['unit_title'] ?? '')) ?: 'Preweek';
$lessonTitle = $topicTitle;
$backUrl = 'student_preweek_topics.php?preweek_unit_id=' . (int)$preweekUnitId;
$pageTitle = $topicTitle . ' — ' . $unitTitle;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .student-shell-page { background: linear-gradient(180deg, #eef5fc 0%, #e4f0fa 45%, #ebf4fc 100%); min-height: 100%; }
    .student-hero {
      border-radius: 0.75rem;
      border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
      box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22);
    }
    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; } .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    .lesson-viewer-page .rounded-2xl { border-radius: 0.75rem !important; }
    .lesson-viewer-page .rounded-xl { border-radius: 0.625rem !important; }
    .lesson-viewer-page .rounded-lg { border-radius: 0.5rem !important; }
    .video-embed { aspect-ratio: 16/9; width: 100%; border: 0; border-radius: 12px; background: #000; }
    .lesson-split-container { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; min-height: 65vh; }
    @media (max-width: 900px) {
      .lesson-split-container { grid-template-columns: 1fr; min-height: auto; }
    }
    .lesson-split-pane { display: flex; flex-direction: column; min-height: 0; overflow: hidden; background: #fff; border: 1px solid rgba(22, 101, 160, 0.2); border-radius: 12px; box-shadow: 0 2px 8px rgba(20,61,89,0.08); }
    .lesson-split-pane iframe { flex: 1; width: 100%; min-height: 400px; border: 0; border-radius: 0 0 12px 12px; }
    @media (max-width: 768px) {
      .lesson-viewer-title-card { padding: 1rem !important; flex-direction: column; align-items: flex-start !important; gap: 0.75rem !important; }
      .lesson-viewer-title-card .lesson-viewer-title-block h1 { font-size: 1.125rem !important; }
      .lesson-view-row { flex-direction: column; align-items: stretch !important; }
      #lesson-fullscreen-btn { width: 100%; justify-content: center; }
      #lesson-viewer-wrap { min-height: 50vh !important; }
    }
    @media (max-width: 480px) {
      #lesson-viewer-wrap:fullscreen .lesson-fs-header,
      #lesson-viewer-wrap:-webkit-full-screen .lesson-fs-header { padding: 0.5rem 0.75rem; flex-wrap: wrap; gap: 0.5rem; }
      #lesson-viewer-wrap:fullscreen .lesson-fs-header .lesson-fs-title,
      #lesson-viewer-wrap:-webkit-full-screen .lesson-fs-header .lesson-fs-title { font-size: 0.8125rem; max-width: 60vw; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      #lesson-viewer-wrap:fullscreen .lesson-exit-fullscreen,
      #lesson-viewer-wrap:-webkit-full-screen .lesson-exit-fullscreen { padding: 0.4rem 0.75rem; font-size: 0.8125rem; }
    }
    /* Full screen: header + content like Test Bank */
    #lesson-viewer-wrap { display: flex; flex-direction: column; min-height: 60vh; background: #fff; border: 1px solid rgba(22,101,160,0.2); border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(20,61,89,0.08); }
    #lesson-viewer-wrap .lesson-fs-header { display: none; }
    #lesson-viewer-wrap:fullscreen,
    #lesson-viewer-wrap:-webkit-full-screen { display: flex; flex-direction: column; background: #0f172a; padding: 0; min-height: 0; }
    #lesson-viewer-wrap:fullscreen .lesson-fs-header,
    #lesson-viewer-wrap:-webkit-full-screen .lesson-fs-header { display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; padding: 0.75rem 1rem; background: #1e293b; border-bottom: 1px solid #334155; }
    #lesson-viewer-wrap:fullscreen .lesson-fs-header .lesson-fs-title,
    #lesson-viewer-wrap:-webkit-full-screen .lesson-fs-header .lesson-fs-title { color: #f1f5f9; font-size: 0.9375rem; font-weight: 600; }
    #lesson-viewer-wrap:fullscreen .lesson-fs-body,
    #lesson-viewer-wrap:-webkit-full-screen .lesson-fs-body { flex: 1; min-height: 0; display: flex; flex-direction: column; padding: 0.75rem; overflow: auto; }
    #lesson-viewer-wrap:fullscreen .lesson-split-container,
    #lesson-viewer-wrap:-webkit-full-screen .lesson-split-container { flex: 1; min-height: 0; display: grid; }
    #lesson-viewer-wrap:fullscreen .lesson-split-pane,
    #lesson-viewer-wrap:-webkit-full-screen .lesson-split-pane { min-height: 0; }
    #lesson-viewer-wrap .lesson-exit-fullscreen { display: none; }
    #lesson-viewer-wrap:fullscreen .lesson-exit-fullscreen,
    #lesson-viewer-wrap:-webkit-full-screen .lesson-exit-fullscreen { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 600; color: #f1f5f9; background: #334155; border: 1px solid #475569; border-radius: 8px; cursor: pointer; }
    #lesson-viewer-wrap:fullscreen .lesson-exit-fullscreen:hover,
    #lesson-viewer-wrap:-webkit-full-screen .lesson-exit-fullscreen:hover { background: #475569; color: #fff; }
  </style>
  <style>
    .student-protected {
      -webkit-user-select: none;
      -moz-user-select: none;
      -ms-user-select: none;
      user-select: none;
    }
    .student-protected ::selection {
      background: transparent;
    }
  </style>
</head>
<body class="font-sans antialiased student-protected lesson-viewer-page student-shell-page" x-data="{ viewMode: 'normal', selectedHandoutId: '<?php echo $firstHandoutId; ?>' }">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page min-h-full pb-8" x-data>
    <!-- Title card (same style as Test Bank viewer) -->
    <section class="mb-4 sm:mb-5 dash-anim delay-1">
      <div class="lesson-viewer-title-card student-hero px-4 sm:px-6 py-4 sm:py-5 text-white flex flex-wrap items-center justify-between gap-3">
        <div class="lesson-viewer-title-block flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
          <a href="<?php echo htmlspecialchars($backUrl); ?>" class="flex h-10 w-10 sm:h-11 sm:w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md hover:bg-white/25 transition" aria-label="Back"><i class="bi bi-arrow-left text-lg sm:text-xl" aria-hidden="true"></i></a>
          <span class="flex h-10 w-10 sm:h-11 sm:w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md">
            <i class="bi bi-play-circle-fill text-lg sm:text-xl" aria-hidden="true"></i>
          </span>
          <div class="min-w-0 flex-1">
            <h1 class="text-lg sm:text-xl md:text-2xl font-bold m-0 tracking-tight truncate"><?php echo h($lessonTitle); ?></h1>
            <p class="text-xs sm:text-sm text-white/90 mt-1 mb-0"><?php echo h($unitTitle); ?> · <?php echo h($topicTitle); ?> · Videos and handouts</p>
          </div>
        </div>
      </div>
    </section>

    <!-- View mode toggle + Full screen (like Test Bank) -->
    <div class="lesson-view-row dash-anim delay-2 flex flex-wrap items-center justify-between gap-3 mb-4">
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm font-semibold text-[#143D59]/80 mr-1">View:</span>
        <button type="button" @click="viewMode = 'normal'" :class="viewMode === 'normal' ? 'bg-[#1665A0] text-white shadow-md' : 'bg-[#e8f2fa] text-[#143D59] hover:bg-[#d4e8f7]'" class="px-4 py-2 rounded-lg text-sm font-semibold transition inline-flex items-center gap-1.5"><i class="bi bi-layout-split"></i> Normal</button>
        <button type="button" @click="viewMode = 'split'" :class="viewMode === 'split' ? 'bg-[#1665A0] text-white shadow-md' : 'bg-[#e8f2fa] text-[#143D59] hover:bg-[#d4e8f7]'" class="px-4 py-2 rounded-lg text-sm font-semibold transition inline-flex items-center gap-1.5"><i class="bi bi-columns-gap"></i> Split screen</button>
      </div>
      <button type="button" id="lesson-fullscreen-btn" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold bg-[#e8f2fa] text-[#143D59] hover:bg-[#d4e8f7] border border-[#1665A0]/20 transition" title="Full screen"><i class="bi bi-fullscreen"></i> Full screen</button>
    </div>

    <!-- Viewer content (can go full screen like Test Bank) -->
    <div id="lesson-viewer-wrap" class="dash-anim delay-2">
      <div class="lesson-fs-header">
        <span class="lesson-fs-title"><?php echo h($lessonTitle); ?></span>
        <button type="button" id="lesson-exit-fullscreen-btn" class="lesson-exit-fullscreen" title="Exit full screen"><i class="bi bi-fullscreen-exit"></i> Exit full screen</button>
      </div>
      <div class="lesson-fs-body">
    <!-- Split screen: video left, handout right -->
    <div x-show="viewMode === 'split'" x-cloak class="lesson-split-container mb-5">
      <div class="lesson-split-pane">
        <div class="px-4 py-2.5 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50 text-sm font-semibold text-[#143D59] flex items-center gap-2"><i class="bi bi-play-circle-fill"></i> Video</div>
        <div class="flex-1 min-h-0 p-3 flex flex-col">
          <?php if ($selectedVideo): ?>
            <?php
              $url = $selectedVideo['video_url'];
              $embed = $url;
              $isLocal = strpos($url, 'uploads/videos/') === 0;
              if (!$isLocal && (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false)) {
                if (preg_match('/(?:v=|\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) $embed = 'https://www.youtube.com/embed/' . $m[1] . '?rel=0';
              } elseif (!$isLocal && strpos($url, 'vimeo.com') !== false) {
                if (preg_match('/vimeo.com\/(\d+)/', $url, $m)) $embed = 'https://player.vimeo.com/video/' . $m[1];
              }
            ?>
            <?php if ($isLocal): ?>
              <video class="video-embed" controls><source src="<?php echo h($url); ?>" type="video/mp4">Your browser does not support the video tag.</video>
            <?php else: ?>
              <iframe class="video-embed" src="<?php echo h($embed); ?>" allowfullscreen></iframe>
            <?php endif; ?>
          <?php else: ?>
            <div class="flex-1 flex items-center justify-center text-[#143D59]/60"><i class="bi bi-play-circle text-4xl block mb-2"></i><p>No video</p></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="lesson-split-pane">
        <div class="px-4 py-2.5 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50 flex flex-wrap items-center gap-2">
          <span class="text-sm font-semibold text-[#143D59] flex items-center gap-2"><i class="bi bi-file-earmark-pdf"></i> Handout</span>
          <select x-model="selectedHandoutId" class="rounded-lg border border-[#1665A0]/30 bg-white text-[#143D59] px-3 py-1.5 text-sm min-w-[180px] focus:ring-2 focus:ring-[#1665A0]/50">
            <option value="">Select handout...</option>
            <?php foreach ($handouts as $h): ?>
              <option value="<?php echo (int)$h['preweek_handout_id']; ?>"><?php echo h($h['handout_title'] ?: 'Untitled Handout'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex-1 min-h-0 relative">
          <iframe x-show="selectedHandoutId" :src="selectedHandoutId ? ('handout_viewer.php?preweek_handout_id=' + selectedHandoutId) : ''" class="absolute inset-0 w-full h-full border-0 rounded-b-xl" title="Handout"></iframe>
          <div x-show="!selectedHandoutId" class="absolute inset-0 flex items-center justify-center flex-col text-[#143D59]/60 bg-[#f8fafc]">
            <i class="bi bi-file-earmark-pdf text-4xl block mb-2"></i>
            <p>Select a handout to view</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Normal view: video + playlist (handouts list is outside #lesson-viewer-wrap) -->
    <div x-show="viewMode === 'normal'" class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-5">
      <!-- Video + Playlist -->
      <div class="lg:col-span-8">
        <div class="rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1)] overflow-hidden bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0]">
          <div class="px-4 py-3 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50 font-semibold text-[#143D59] flex items-center gap-2"><i class="bi bi-play-circle-fill"></i> Video</div>
          <div class="p-4 bg-white/50">
            <?php if ($selectedVideo): ?>
              <?php
                $url = $selectedVideo['video_url'];
                $embed = $url;
                $isLocal = strpos($url, 'uploads/videos/') === 0;
                if (!$isLocal && (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false)) {
                  if (preg_match('/(?:v=|\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) $embed = 'https://www.youtube.com/embed/' . $m[1] . '?rel=0';
                } elseif (!$isLocal && strpos($url, 'vimeo.com') !== false) {
                  if (preg_match('/vimeo.com\/(\d+)/', $url, $m)) $embed = 'https://player.vimeo.com/video/' . $m[1];
                }
              ?>
              <?php if ($isLocal): ?>
                <video class="video-embed" controls><source src="<?php echo h($url); ?>" type="video/mp4">Your browser does not support the video tag.</video>
              <?php else: ?>
                <iframe class="video-embed" src="<?php echo h($embed); ?>" allowfullscreen></iframe>
              <?php endif; ?>
              <p class="mt-2 text-sm font-semibold text-[#143D59]"><?php echo h($selectedVideo['video_title'] ?: 'Untitled Video'); ?></p>
            <?php else: ?>
              <div class="text-center text-[#143D59]/60 py-12">
                <i class="bi bi-play-circle text-4xl block mb-2"></i>
                <p class="mt-2 mb-0">No videos for this preweek yet.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="lg:col-span-4">
        <div class="rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1)] overflow-hidden bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0] max-h-[70vh] overflow-y-auto">
          <div class="px-4 py-3 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50 font-semibold text-[#143D59] flex items-center gap-2"><i class="bi bi-list"></i> Playlist</div>
          <div class="divide-y divide-[#1665A0]/10">
            <?php foreach ($videos as $v): $isActive = $selectedVideo && (int)$v['preweek_video_id'] === (int)$selectedVideo['preweek_video_id']; ?>
              <a href="student_preweek_viewer.php?<?php echo h(http_build_query(array_merge($viewerQueryBase, ['video' => (int)$v['preweek_video_id']]))); ?>" class="flex items-center gap-2 px-4 py-3 text-left transition <?php echo $isActive ? 'bg-[#1665A0]/15 text-[#1665A0] font-semibold border-l-4 border-[#1665A0]' : 'hover:bg-[#e8f2fa]/60 text-[#143D59]'; ?>">
                <i class="bi bi-play-circle"></i> <?php echo h($v['video_title'] ?: 'Untitled Video'); ?>
              </a>
            <?php endforeach; ?>
            <?php if (count($videos) === 0): ?>
              <div class="px-4 py-6 text-center text-[#143D59]/60 text-sm">No videos</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

      </div>
    </div>

    <!-- Handouts (only in Normal view) — outside #lesson-viewer-wrap so fullscreen shows video + playlist only -->
    <div x-show="viewMode === 'normal'" class="mt-5 rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1)] overflow-hidden bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#143D59]">
      <div class="px-4 sm:px-6 py-3 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50 font-semibold text-[#143D59] flex items-center gap-2"><i class="bi bi-file-earmark-pdf"></i> Handouts</div>
      <div class="p-4 sm:p-5">
        <?php if (count($handouts) === 0): ?>
          <p class="text-[#143D59]/70 text-sm m-0">No handouts for this preweek yet.</p>
        <?php else: ?>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <?php foreach ($handouts as $h): ?>
              <div class="border border-[#1665A0]/15 rounded-xl p-4 bg-white/80 shadow-[0_2px_8px_rgba(20,61,89,0.06)]">
                <h3 class="font-semibold text-[#143D59] mb-1"><?php echo h($h['handout_title'] ?: 'Untitled Handout'); ?></h3>
                <?php if (!empty($h['file_size'])): ?>
                  <p class="text-[#143D59]/70 text-sm mb-2"><?php
                    $bytes = (int)$h['file_size'];
                    $units = ['B', 'KB', 'MB', 'GB'];
                    $i = 0; $size = $bytes;
                    while ($size >= 1024 && $i < count($units) - 1) { $size /= 1024; $i++; }
                    echo number_format($size, $size >= 10 || $i === 0 ? 0 : 1) . ' ' . $units[$i];
                  ?></p>
                <?php endif; ?>
                <div class="flex flex-wrap gap-2 mt-2">
                  <?php if (!empty($h['file_path'])): ?>
                    <a href="handout_viewer.php?preweek_handout_id=<?php echo (int)$h['preweek_handout_id']; ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg font-semibold bg-[#1665A0] text-white hover:bg-[#143D59] transition shadow-[0_2px_8px_rgba(22,101,160,0.25)]"><i class="bi bi-eye"></i> View</a>
                    <?php if (!empty($h['allow_download'])): ?>
                      <a href="<?php echo h($h['file_path']); ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 px-3 py-2 rounded-lg font-semibold border-2 border-[#1665A0] text-[#1665A0] hover:bg-[#1665A0] hover:text-white transition"><i class="bi bi-download"></i> Download</a>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script>
  (function() {
    var wrap = document.getElementById('lesson-viewer-wrap');
    var btn = document.getElementById('lesson-fullscreen-btn');
    var exitBtn = document.getElementById('lesson-exit-fullscreen-btn');
    if (wrap && btn) {
      btn.addEventListener('click', function() {
        if (!document.fullscreenElement && !document.webkitFullscreenElement) {
          wrap.requestFullscreen ? wrap.requestFullscreen() : (wrap.webkitRequestFullscreen && wrap.webkitRequestFullscreen());
        }
      });
    }
    if (wrap && exitBtn) {
      exitBtn.addEventListener('click', function() {
        if (document.fullscreenElement || document.webkitFullscreenElement) {
          document.exitFullscreen ? document.exitFullscreen() : (document.webkitExitFullscreen && document.webkitExitFullscreen());
        }
      });
    }
    function updateFullscreenBtn() {
      if (btn) btn.style.visibility = (document.fullscreenElement || document.webkitFullscreenElement) ? 'hidden' : 'visible';
    }
    document.addEventListener('fullscreenchange', updateFullscreenBtn);
    document.addEventListener('webkitfullscreenchange', updateFullscreenBtn);
  })();
  </script>
  <script>
  (function() {
    function isInputLike(el) {
      if (!el) return false;
      var tag = (el.tagName || '').toLowerCase();
      var type = (el.type || '').toLowerCase();
      return tag === 'input' || tag === 'textarea' || el.isContentEditable || type === 'text' || type === 'password';
    }
    document.addEventListener('contextmenu', function(e) {
      if (!isInputLike(e.target)) e.preventDefault();
    });
    document.addEventListener('selectstart', function(e) {
      if (!isInputLike(e.target)) e.preventDefault();
    });
    window.addEventListener('keydown', function(e) {
      var ctrlLike = e.ctrlKey || e.metaKey;
      var key = (e.key || '').toLowerCase();
      if (ctrlLike && ['c','x','s','p','u','a'].indexOf(key) !== -1 && !isInputLike(e.target)) {
        e.preventDefault();
      }
    }, true);
  })();
  </script>
</main>
</body>
</html>
