<?php
require_once 'auth.php';
requireRole('student');

$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectId <= 0) { header('Location: student_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE subject_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
if (!$subject) { header('Location: student_subjects.php'); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM lessons WHERE subject_id=? ORDER BY lesson_id ASC");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$lessons = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT * FROM quizzes WHERE subject_id=? ORDER BY quiz_id DESC");
mysqli_stmt_bind_param($stmt, 'i', $subjectId);
mysqli_stmt_execute($stmt);
$quizzesAll = mysqli_stmt_get_result($stmt);
mysqli_stmt_close($stmt);

$pageTitle = 'Subject - ' . $subject['subject_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>.video-embed { aspect-ratio: 16/9; width: 100%; border: 0; border-radius: 8px; background: #000; } .split-view-container { display: none; position: fixed; top: 0; left: 20%; right: 0; bottom: 0; z-index: 1000; background: #f6f9ff; padding: 6px; gap: 6px; overflow: hidden; } .split-view-container.active { display: flex !important; } .split-view-container .split-panel { flex: 1; min-width: 0; display: flex; flex-direction: column; } .split-view-container iframe, .split-view-container video { flex: 1; width: 100%; min-height: 0; } .separate-view.hidden { display: none !important; }</style>
</head>
<body class="font-sans antialiased" x-data="{ activeTab: 'materials', viewMode: 'separate' }">
  <?php include 'student_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2"><i class="bi bi-book"></i> <?php echo h($subject['subject_name']); ?></h1>
    <p class="text-gray-500 mt-1 mb-0"><?php echo h($subject['description'] ?? ''); ?></p>
  </div>

  <!-- Tabs -->
  <nav class="flex flex-wrap gap-2 mb-4" role="tablist">
    <button type="button" @click="activeTab = 'materials'" :class="activeTab === 'materials' ? 'bg-primary text-white border-primary' : 'bg-gray-100 text-gray-700 border-gray-200 hover:bg-gray-200'" class="px-4 py-2 rounded-lg font-medium border-2 transition inline-flex items-center gap-2"><i class="bi bi-collection-play"></i> Materials (Videos + Handouts)</button>
    <button type="button" @click="activeTab = 'quizzers'" :class="activeTab === 'quizzers' ? 'bg-primary text-white border-primary' : 'bg-gray-100 text-gray-700 border-gray-200 hover:bg-gray-200'" class="px-4 py-2 rounded-lg font-medium border-2 transition inline-flex items-center gap-2"><i class="bi bi-question-circle"></i> Quizzers</button>
    <button type="button" @click="activeTab = 'testbank'" :class="activeTab === 'testbank' ? 'bg-primary text-white border-primary' : 'bg-gray-100 text-gray-700 border-gray-200 hover:bg-gray-200'" class="px-4 py-2 rounded-lg font-medium border-2 transition inline-flex items-center gap-2"><i class="bi bi-journal-text"></i> Test Bank</button>
  </nav>

  <!-- Split View Overlay -->
  <div class="split-view-container" :class="{ 'active': viewMode === 'split' }">
    <button type="button" @click="viewMode = 'separate'" class="absolute top-4 right-4 z-10 px-3 py-1.5 rounded-lg text-sm font-semibold bg-red-600 text-white hover:bg-red-700 transition" title="Close Split View"><i class="bi bi-x-lg"></i></button>
    <div class="split-panel bg-white rounded-xl shadow-card overflow-hidden">
      <div class="px-3 py-2 border-b border-gray-100 text-sm font-semibold text-gray-800"><i class="bi bi-play-circle mr-2"></i> Video Player</div>
      <div id="splitVideoPlayer" class="flex-1 min-h-0 bg-gray-900 flex items-center justify-center text-gray-500">
        <div class="text-center py-8"><i class="bi bi-play-circle text-4xl block mb-2"></i><p>Select a lesson to load materials.</p></div>
      </div>
    </div>
    <div class="split-panel bg-white rounded-xl shadow-card overflow-hidden" style="border-left: 2px solid #e5e7eb;">
      <div class="px-3 py-2 border-b border-gray-100 flex justify-between items-center flex-wrap gap-2">
        <span class="text-sm font-semibold text-gray-800"><i class="bi bi-file-earmark-pdf mr-2"></i> Handout</span>
        <select id="splitHandoutSelect" class="rounded-lg border border-gray-300 px-2 py-1 text-sm min-w-[180px]">
          <option value="">Select Handout...</option>
        </select>
      </div>
      <div class="flex-1 min-h-0 relative">
        <iframe id="splitHandoutFrame" class="absolute inset-0 w-full h-full border-0 rounded-b-xl bg-white" style="display: none;"></iframe>
        <div id="splitHandoutEmpty" class="absolute inset-0 flex items-center justify-center flex-col text-gray-500"><i class="bi bi-file-earmark-pdf text-4xl block mb-2"></i><p>Select a handout to view</p></div>
      </div>
    </div>
  </div>

  <!-- Tab: Materials -->
  <div x-show="activeTab === 'materials'" x-cloak class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-5">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
      <div class="lg:col-span-4">
        <div class="bg-white rounded-xl border border-gray-100 overflow-hidden max-h-[60vh] overflow-y-auto">
          <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-800"><i class="bi bi-file-text mr-2"></i> Lessons</div>
          <div class="divide-y divide-gray-100">
            <?php mysqli_data_seek($lessons, 0); while ($l = mysqli_fetch_assoc($lessons)): ?>
              <a href="?subject_id=<?php echo (int)$subjectId; ?>#materials" onclick="loadLessonMaterials(<?php echo (int)$l['lesson_id']; ?>); return false;" class="block px-4 py-3 hover:bg-gray-50 text-gray-700 transition"><?php echo h($l['title']); ?></a>
            <?php endwhile; ?>
          </div>
        </div>
      </div>
      <div class="lg:col-span-8">
        <div id="viewToggleButtons" class="hidden gap-2 mb-3">
          <button type="button" @click="viewMode = 'separate'" :class="viewMode === 'separate' ? 'bg-primary text-white border-primary' : 'border-gray-300 text-gray-600'" class="px-3 py-1.5 rounded-lg text-sm font-medium border-2 transition"><i class="bi bi-layout-split"></i> Separate</button>
          <button type="button" @click="viewMode = 'split'" :class="viewMode === 'split' ? 'bg-primary text-white border-primary' : 'border-gray-300 text-gray-600'" class="px-3 py-1.5 rounded-lg text-sm font-medium border-2 transition"><i class="bi bi-columns"></i> Split View</button>
        </div>
        <div class="border border-gray-100 rounded-xl overflow-hidden">
          <div class="px-4 py-3 border-b border-gray-100 flex justify-between items-center">
            <span class="font-semibold text-gray-800"><i class="bi bi-list mr-2"></i> Playlist</span>
            <span class="text-gray-500 text-sm" id="lessonTitle"></span>
          </div>
          <div class="p-4">
            <div id="videoPlayer" class="mb-4">
              <div class="text-center text-gray-500 py-12"><i class="bi bi-play-circle text-4xl block mb-2"></i><p class="mt-2 mb-0">Select a lesson to load materials.</p></div>
            </div>
            <div id="videoList" class="space-y-1 mb-4"></div>
            <hr class="my-4 border-gray-200">
            <h4 class="font-semibold text-gray-800 mb-3"><i class="bi bi-file-earmark-pdf mr-2"></i> Handouts</h4>
            <div id="handoutList" class="grid grid-cols-1 md:grid-cols-2 gap-3"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tab: Quizzers -->
  <div x-show="activeTab === 'quizzers'" x-cloak class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-5">
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php mysqli_data_seek($quizzesAll, 0); while ($q = mysqli_fetch_assoc($quizzesAll)): if ($q['quiz_type'] === 'mock') continue; ?>
        <?php
          $stmt = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM quiz_questions WHERE quiz_id=?");
          mysqli_stmt_bind_param($stmt, 'i', (int)$q['quiz_id']);
          mysqli_stmt_execute($stmt);
          $cResult = mysqli_stmt_get_result($stmt);
          $rc = mysqli_fetch_assoc($cResult);
          mysqli_stmt_close($stmt);
          $cnt = (int)($rc['cnt'] ?? 0);
        ?>
        <div class="bg-white rounded-xl border border-gray-100 p-5 flex flex-col">
          <div class="flex justify-between items-start mb-2">
            <h3 class="font-bold text-gray-800"><?php echo h($q['title']); ?></h3>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-primary text-white"><?php echo h($q['quiz_type']); ?></span>
          </div>
          <p class="text-gray-500 text-sm mb-3"><?php echo $cnt; ?> Questions</p>
          <?php if ($cnt > 0): ?>
            <a href="student_take_quiz.php?quiz_id=<?php echo (int)$q['quiz_id']; ?>" class="mt-auto w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition"><i class="bi bi-play-circle"></i> Take Quiz</a>
          <?php else: ?>
            <button type="button" disabled class="mt-auto w-full py-2.5 rounded-lg font-semibold bg-gray-300 text-gray-500 cursor-not-allowed">No questions yet</button>
          <?php endif; ?>
        </div>
      <?php endwhile; ?>
    </div>
  </div>

  <!-- Tab: Test Bank -->
  <div x-show="activeTab === 'testbank'" x-cloak class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-5">
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php mysqli_data_seek($quizzesAll, 0); while ($q = mysqli_fetch_assoc($quizzesAll)): if ($q['quiz_type'] !== 'mock') continue; ?>
        <?php
          $stmt = mysqli_prepare($conn, "SELECT COUNT(*) as cnt FROM quiz_questions WHERE quiz_id=?");
          mysqli_stmt_bind_param($stmt, 'i', (int)$q['quiz_id']);
          mysqli_stmt_execute($stmt);
          $cResult = mysqli_stmt_get_result($stmt);
          $rc = mysqli_fetch_assoc($cResult);
          mysqli_stmt_close($stmt);
          $cnt = (int)($rc['cnt'] ?? 0);
        ?>
        <div class="bg-white rounded-xl border border-gray-100 p-5 flex flex-col">
          <div class="flex justify-between items-start mb-2">
            <h3 class="font-bold text-gray-800"><?php echo h($q['title']); ?></h3>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Test Bank</span>
          </div>
          <p class="text-gray-500 text-sm mb-3"><?php echo $cnt; ?> Questions</p>
          <?php if ($cnt > 0): ?>
            <a href="student_take_quiz.php?quiz_id=<?php echo (int)$q['quiz_id']; ?>" class="mt-auto w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg font-semibold bg-amber-500 text-white hover:bg-amber-600 transition"><i class="bi bi-journal-text"></i> Start Practice</a>
          <?php else: ?>
            <button type="button" disabled class="mt-auto w-full py-2.5 rounded-lg font-semibold bg-gray-300 text-gray-500 cursor-not-allowed">No items yet</button>
          <?php endif; ?>
        </div>
      <?php endwhile; ?>
    </div>
  </div>

  <!-- Handout Viewer Modal (Alpine store driven from openHandoutViewer()) -->
  <div x-show="$store.handoutViewer && $store.handoutViewer.open" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" @keydown.escape.window="$store.handoutViewer && ($store.handoutViewer.open = false)">
    <div class="absolute inset-0 bg-black/50" @click="$store.handoutViewer && ($store.handoutViewer.open = false)"></div>
    <div class="relative bg-white rounded-xl shadow-modal w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col" @click.stop>
      <div class="p-4 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-xl font-bold text-gray-800 m-0" x-text="($store.handoutViewer && $store.handoutViewer.title) || 'Handout'"></h2>
        <button type="button" @click="$store.handoutViewer && ($store.handoutViewer.open = false, $store.handoutViewer.id = '')" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="flex-1 min-h-0 relative" style="aspect-ratio: 4/3;">
        <iframe x-show="$store.handoutViewer && $store.handoutViewer.id" :src="($store.handoutViewer && $store.handoutViewer.id) ? ('handout_viewer.php?handout_id=' + $store.handoutViewer.id) : ''" class="absolute inset-0 w-full h-full border-0 rounded-b-xl" allowfullscreen></iframe>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('alpine:init', function() {
      Alpine.store('handoutViewer', { open: false, id: '', title: '' });
    });
    window.openHandoutViewer = function(handoutId, title) {
      if (!handoutId) return;
      if (typeof Alpine !== 'undefined' && Alpine.store && Alpine.store('handoutViewer')) {
        Alpine.store('handoutViewer').open = true;
        Alpine.store('handoutViewer').id = String(handoutId);
        Alpine.store('handoutViewer').title = title || 'Handout Preview';
      }
    };

    document.addEventListener('DOMContentLoaded', function() {
      const btnSeparateView = document.getElementById('btnSeparateView');
      const btnSplitView = document.getElementById('btnSplitView');
      const viewToggleButtons = document.getElementById('viewToggleButtons');
      const splitHandoutSelect = document.getElementById('splitHandoutSelect');
      const splitHandoutFrame = document.getElementById('splitHandoutFrame');
      const splitHandoutEmpty = document.getElementById('splitHandoutEmpty');
      if (splitHandoutSelect && splitHandoutFrame && splitHandoutEmpty) {
        splitHandoutSelect.addEventListener('change', function() {
          const val = this.value;
          if (val) {
            splitHandoutFrame.src = 'handout_viewer.php?handout_id=' + encodeURIComponent(val);
            splitHandoutFrame.style.display = 'block';
            splitHandoutEmpty.style.display = 'none';
          } else {
            splitHandoutFrame.src = '';
            splitHandoutFrame.style.display = 'none';
            splitHandoutEmpty.style.display = 'flex';
          }
        });
      }
    });

    async function loadLessonMaterials(lessonId) {
      const titleEl = document.getElementById('lessonTitle');
      const videoListEl = document.getElementById('videoList');
      const videoPlayerEl = document.getElementById('videoPlayer');
      const handoutListEl = document.getElementById('handoutList');
      const splitVideoPlayerEl = document.getElementById('splitVideoPlayer');
      const viewToggleButtons = document.getElementById('viewToggleButtons');
      if (viewToggleButtons) viewToggleButtons.style.display = 'flex';
      titleEl.textContent = '';
      videoListEl.innerHTML = '';
      videoPlayerEl.innerHTML = '<div class="text-center text-gray-500 py-8"><span class="inline-block w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin"></span><p class="mt-3 mb-0">Loading videos...</p></div>';
      if (splitVideoPlayerEl) splitVideoPlayerEl.innerHTML = '<div class="text-center text-gray-500 py-8" style="height:100%;display:flex;align-items:center;justify-content:center;flex-direction:column"><span class="inline-block w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin"></span><p class="mt-3 mb-0">Loading videos...</p></div>';
      handoutListEl.innerHTML = '';
      try {
        const [vRes, hRes] = await Promise.all([
          fetch('subject_api.php?action=videos&lesson_id=' + lessonId, { credentials: 'same-origin' }),
          fetch('subject_api.php?action=handouts&lesson_id=' + lessonId, { credentials: 'same-origin' })
        ]);
        if (!vRes.ok || !hRes.ok) throw new Error('Request failed');
        const videos = await vRes.json();
        const handouts = await hRes.json();
        if (videos.error) throw new Error(videos.error);
        if (handouts.error) throw new Error(handouts.error);
        titleEl.textContent = (videos.lesson && videos.lesson.title) || '';
        const videoItems = Array.isArray(videos.videos) ? videos.videos : [];
        if (videoItems.length === 0) {
          videoPlayerEl.replaceChildren(renderEmptyState('No videos.', false, 'bi bi-play-circle'));
          if (splitVideoPlayerEl) splitVideoPlayerEl.innerHTML = '<div class="text-center text-gray-500 py-8"><i class="bi bi-play-circle text-4xl block mb-2"></i><p>No videos available.</p></div>';
        } else {
          renderVideoPlayer(videoItems[0].video_url || '');
          renderSplitVideoPlayer(videoItems[0].video_url || '');
          videoItems.forEach(v => {
            const link = document.createElement('a');
            link.href = '#'; link.className = 'flex items-center gap-2 px-4 py-3 rounded-lg hover:bg-gray-50 text-gray-700 transition';
            const icon = document.createElement('i'); icon.className = 'bi bi-play-circle'; link.appendChild(icon);
            link.appendChild(document.createTextNode(' ' + (v.video_title || 'Untitled Video')));
            link.addEventListener('click', (e) => { e.preventDefault(); renderVideoPlayer(v.video_url || ''); renderSplitVideoPlayer(v.video_url || ''); });
            videoListEl.appendChild(link);
          });
        }
        const handoutItems = Array.isArray(handouts.handouts) ? handouts.handouts : [];
        const splitHandoutSelect = document.getElementById('splitHandoutSelect');
        const splitHandoutFrame = document.getElementById('splitHandoutFrame');
        const splitHandoutEmpty = document.getElementById('splitHandoutEmpty');
        if (splitHandoutSelect) {
          splitHandoutSelect.innerHTML = '<option value="">Select Handout...</option>';
          handoutItems.forEach(h => {
            if (h.file_path && h.handout_id) {
              const opt = document.createElement('option');
              opt.value = h.handout_id;
              opt.textContent = h.handout_title || 'Untitled Handout';
              splitHandoutSelect.appendChild(opt);
            }
          });
          if (handoutItems.length > 0 && handoutItems[0].handout_id) {
            splitHandoutSelect.value = handoutItems[0].handout_id;
            if (splitHandoutFrame) { splitHandoutFrame.src = 'handout_viewer.php?handout_id=' + handoutItems[0].handout_id; splitHandoutFrame.style.display = 'block'; }
            if (splitHandoutEmpty) splitHandoutEmpty.style.display = 'none';
          } else {
            if (splitHandoutFrame) splitHandoutFrame.style.display = 'none';
            if (splitHandoutEmpty) splitHandoutEmpty.style.display = 'flex';
          }
        }
        if (handoutItems.length === 0) {
          handoutListEl.appendChild(renderEmptyState('No handouts.', true));
        } else {
          handoutItems.forEach(h => {
            const col = document.createElement('div');
            col.className = 'border border-gray-100 rounded-xl p-4';
            const title = document.createElement('h6');
            title.className = 'font-semibold text-gray-800 mb-1';
            title.textContent = h.handout_title || 'Untitled Handout';
            col.appendChild(title);
            if (Number(h.file_size)) {
              const size = document.createElement('div');
              size.className = 'text-gray-500 text-sm mb-2';
              size.textContent = formatFileSize(Number(h.file_size));
              col.appendChild(size);
            }
            const actions = document.createElement('div');
            actions.className = 'flex flex-wrap gap-2 mt-3';
            if (h.file_path) {
              const viewBtn = document.createElement('button');
              viewBtn.type = 'button';
              viewBtn.className = 'inline-flex items-center gap-1 px-3 py-2 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition';
              viewBtn.textContent = 'View';
              viewBtn.addEventListener('click', () => openHandoutViewer(h.handout_id, h.handout_title || 'Handout'));
              actions.appendChild(viewBtn);
            }
            if (Number(h.allow_download) === 1 && h.file_path) {
              const a = document.createElement('a');
              a.className = 'inline-flex items-center gap-1 px-3 py-2 rounded-lg font-semibold border-2 border-primary text-primary hover:bg-primary hover:text-white transition';
              a.href = h.file_path; a.target = '_blank'; a.rel = 'noopener';
              a.innerHTML = '<i class="bi bi-download"></i> Download';
              actions.appendChild(a);
            } else if (Number(h.allow_download) !== 1) {
              const lock = document.createElement('div');
              lock.className = 'p-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-center gap-2';
              lock.innerHTML = '<i class="bi bi-lock"></i> Downloads locked by administrator.';
              actions.appendChild(lock);
            }
            col.appendChild(actions);
            handoutListEl.appendChild(col);
          });
        }
      } catch (e) {
        videoPlayerEl.replaceChildren(renderErrorState('Failed to load. ' + (e.message || '')));
        handoutListEl.appendChild(renderErrorState('Failed to load handouts. ' + (e.message || '')));
      }
    }
    function renderVideoPlayer(url) {
      const el = document.getElementById('videoPlayer');
      el.innerHTML = '';
      if (!url) { el.appendChild(renderEmptyState('No videos.', false, 'bi bi-play-circle')); return; }
      const isLocal = url.indexOf('uploads/videos/') === 0;
      let embedUrl = url;
      if (!isLocal && (url.includes('youtube.com') || url.includes('youtu.be'))) { const m = url.match(/(?:v=|\.be\/)([A-Za-z0-9_-]{6,})/); if (m) embedUrl = 'https://www.youtube.com/embed/' + m[1] + '?rel=0'; }
      else if (!isLocal && url.includes('vimeo.com')) { const m = url.match(/vimeo.com\/(\d+)/); if (m) embedUrl = 'https://player.vimeo.com/video/' + m[1]; }
      if (isLocal) {
        const v = document.createElement('video');
        v.className = 'video-embed'; v.controls = true;
        const src = document.createElement('source'); src.src = embedUrl; src.type = 'video/mp4';
        v.appendChild(src); el.appendChild(v);
      } else {
        const iframe = document.createElement('iframe');
        iframe.className = 'video-embed'; iframe.src = embedUrl; iframe.allowFullscreen = true;
        el.appendChild(iframe);
      }
    }
    function renderSplitVideoPlayer(url) {
      const el = document.getElementById('splitVideoPlayer');
      if (!el) return;
      el.innerHTML = '';
      if (!url) { el.innerHTML = '<div class="text-center text-gray-500 py-8"><i class="bi bi-play-circle text-4xl block mb-2"></i><p>No video selected.</p></div>'; return; }
      const isLocal = url.indexOf('uploads/videos/') === 0;
      let embedUrl = url;
      if (!isLocal && (url.includes('youtube.com') || url.includes('youtu.be'))) { const m = url.match(/(?:v=|\.be\/)([A-Za-z0-9_-]{6,})/); if (m) embedUrl = 'https://www.youtube.com/embed/' + m[1] + '?rel=0'; }
      else if (!isLocal && url.includes('vimeo.com')) { const m = url.match(/vimeo.com\/(\d+)/); if (m) embedUrl = 'https://player.vimeo.com/video/' + m[1]; }
      if (isLocal) {
        const v = document.createElement('video');
        v.className = 'video-embed'; v.controls = true; v.style.height = '100%';
        const src = document.createElement('source'); src.src = embedUrl; src.type = 'video/mp4';
        v.appendChild(src); el.appendChild(v);
      } else {
        const iframe = document.createElement('iframe');
        iframe.className = 'video-embed'; iframe.src = embedUrl; iframe.allowFullscreen = true; iframe.style.height = '100%';
        el.appendChild(iframe);
      }
    }
    function renderEmptyState(message, wrapInColumn, iconClass) {
      const div = document.createElement('div');
      div.className = 'text-center text-gray-500 py-8';
      const icon = document.createElement('i');
      icon.className = iconClass || 'bi bi-inbox';
      icon.style.fontSize = '2.5rem';
      div.appendChild(icon);
      const p = document.createElement('p');
      p.className = 'mt-2 mb-0';
      p.textContent = message;
      div.appendChild(p);
      if (wrapInColumn) {
        const col = document.createElement('div');
        col.className = 'col-span-2';
        col.appendChild(div);
        return col;
      }
      return div;
    }
    function renderErrorState(message) {
      const col = document.createElement('div');
      col.className = 'col-span-2';
      const alert = document.createElement('div');
      alert.className = 'p-4 rounded-xl bg-red-50 border border-red-200 text-red-800';
      alert.textContent = message;
      col.appendChild(alert);
      return col;
    }
    function formatFileSize(bytes) {
      if (!bytes || isNaN(bytes)) return '';
      const units = ['B', 'KB', 'MB', 'GB'];
      let size = bytes;
      let i = 0;
      while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
      return size.toFixed(size >= 10 || i === 0 ? 0 : 1) + ' ' + units[i];
    }
  </script>
</main>
</body>
</html>
