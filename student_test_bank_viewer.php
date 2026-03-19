<?php
/**
 * Full-page Test Bank viewer. View only – no download/print in UI.
 * Uses same layout as student subject: sidebar + topbar + title card.
 */
require_once 'auth.php';
requireLogin();
if (!hasRole('student') && !hasRole('admin')) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
$subjectId = (int)($_GET['subject_id'] ?? 0);
if ($id <= 0) {
    header('Location: student_subjects.php');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT id, title, description, question_file_path, solution_file_path FROM test_bank WHERE id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$row) {
    $_SESSION['error'] = 'Test bank entry not found.';
    header('Location: student_subjects.php');
    exit;
}

$hasQuestion = !empty(trim($row['question_file_path'] ?? ''));
$hasSolution = !empty(trim($row['solution_file_path'] ?? ''));
$questionExt = $hasQuestion ? strtolower(pathinfo($row['question_file_path'], PATHINFO_EXTENSION)) : '';
$solutionExt = $hasSolution ? strtolower(pathinfo($row['solution_file_path'], PATHINFO_EXTENSION)) : '';
$viewMode = isset($_GET['view']) ? trim($_GET['view']) : '';
if (!in_array($viewMode, ['split', 'questions', 'solutions'], true)) {
    $viewMode = ($hasQuestion && $hasSolution) ? 'split' : ($hasQuestion ? 'questions' : 'solutions');
}

$title = $row['title'] ?: 'Test Bank';
$description = $row['description'] ?? '';
$backUrl = $subjectId > 0 ? 'student_subject.php?subject_id=' . $subjectId . '#testbank' : 'student_subjects.php';

$fileUrl = function ($type) use ($id) {
    $t = ($type === 'solution') ? '2' : '1';
    return 'test_bank_file.php?id=' . (int)$id . '&type=' . $t . '#toolbar=0&navpanes=0';
};

$overviewLabel = ($hasQuestion && $hasSolution) ? 'Questions + Answers' : ($hasQuestion ? 'Questions only' : 'Answers only');
$pageTitle = $title . ' - Test Bank';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <style>
    .viewer-split { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; min-height: 60vh; }
    @media (max-width: 768px) {
      .viewer-split { grid-template-columns: 1fr; min-height: 50vh; gap: 0.5rem; }
      .viewer-iframe { min-height: 45vh !important; }
    }
    .viewer-pane { display: flex; flex-direction: column; min-height: 0; overflow: hidden; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
    .viewer-iframe { flex: 1; width: 100%; min-height: 55vh; border: 0; border-radius: 0 0 12px 12px; }
    @media (max-width: 640px) {
      .testbank-title-card { padding: 1rem 1rem !important; flex-direction: column; align-items: flex-start !important; gap: 0.75rem !important; }
      .testbank-title-card .testbank-title-block { min-width: 0; }
      .testbank-title-card .testbank-title-block h1 { font-size: 1.125rem !important; }
      .testbank-title-card .testbank-title-block p { font-size: 0.8125rem !important; }
      .testbank-view-row { flex-direction: column; align-items: stretch !important; }
      .testbank-view-row .testbank-view-links { width: 100%; }
      .testbank-view-row #testbank-fullscreen-btn { width: 100%; justify-content: center; }
      #testbank-viewer-wrap { min-height: 50vh !important; }
    }
    @media (max-width: 480px) {
      #testbank-viewer-wrap:fullscreen .testbank-fs-header,
      #testbank-viewer-wrap:-webkit-full-screen .testbank-fs-header { padding: 0.5rem 0.75rem; flex-wrap: wrap; gap: 0.5rem; }
      #testbank-viewer-wrap:fullscreen .testbank-fs-header .testbank-fs-title,
      #testbank-viewer-wrap:-webkit-full-screen .testbank-fs-header .testbank-fs-title { font-size: 0.8125rem; max-width: 60vw; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      #testbank-viewer-wrap:fullscreen .testbank-exit-fullscreen,
      #testbank-viewer-wrap:-webkit-full-screen .testbank-exit-fullscreen { padding: 0.4rem 0.75rem; font-size: 0.8125rem; }
    }
    /* Full screen: clean top bar + content */
    #testbank-viewer-wrap { display: flex; flex-direction: column; }
    #testbank-viewer-wrap .testbank-fs-header { display: none; }
    #testbank-viewer-wrap:fullscreen,
    #testbank-viewer-wrap:-webkit-full-screen { display: flex; flex-direction: column; background: #0f172a; padding: 0; }
    #testbank-viewer-wrap:fullscreen .testbank-fs-header,
    #testbank-viewer-wrap:-webkit-full-screen .testbank-fs-header { display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; padding: 0.75rem 1rem; background: #1e293b; border-bottom: 1px solid #334155; }
    #testbank-viewer-wrap:fullscreen .testbank-fs-header .testbank-fs-title,
    #testbank-viewer-wrap:-webkit-full-screen .testbank-fs-header .testbank-fs-title { color: #f1f5f9; font-size: 0.9375rem; font-weight: 600; }
    #testbank-viewer-wrap:fullscreen .testbank-fs-body,
    #testbank-viewer-wrap:-webkit-full-screen .testbank-fs-body { flex: 1; min-height: 0; display: flex; flex-direction: column; padding: 0.75rem; }
    #testbank-viewer-wrap:fullscreen .viewer-split,
    #testbank-viewer-wrap:-webkit-full-screen .viewer-split { min-height: 0; flex: 1; }
    #testbank-viewer-wrap:fullscreen .viewer-pane,
    #testbank-viewer-wrap:-webkit-full-screen .viewer-pane { min-height: 0; flex: 1; }
    #testbank-viewer-wrap:fullscreen .viewer-iframe,
    #testbank-viewer-wrap:-webkit-full-screen .viewer-iframe { min-height: 0; flex: 1; }
    #testbank-viewer-wrap .testbank-exit-fullscreen { display: none; }
    #testbank-viewer-wrap:fullscreen .testbank-exit-fullscreen,
    #testbank-viewer-wrap:-webkit-full-screen .testbank-exit-fullscreen { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 600; color: #f1f5f9; background: #334155; border: 1px solid #475569; border-radius: 8px; cursor: pointer; }
    #testbank-viewer-wrap:fullscreen .testbank-exit-fullscreen:hover,
    #testbank-viewer-wrap:-webkit-full-screen .testbank-exit-fullscreen:hover { background: #475569; color: #fff; }
    @media print { body, .viewer-full { display: none !important; } }
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
<body class="font-sans antialiased student-protected">
  <?php include 'student_sidebar.php'; ?>
  <?php $topbarSubtitle = false; include 'student_topbar.php'; ?>

  <div class="student-dashboard-page min-h-full pb-8">
    <!-- Title card (same style as Subjects page) -->
    <section class="mb-4 sm:mb-5">
      <div class="testbank-title-card rounded-2xl px-4 sm:px-6 py-4 sm:py-5 bg-gradient-to-r from-[#1665A0] to-[#143D59] text-white shadow-[0_10px_30px_rgba(20,61,89,0.35)] flex flex-wrap items-center justify-between gap-3">
        <div class="testbank-title-block flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
          <a href="<?php echo htmlspecialchars($backUrl); ?>" class="flex h-10 w-10 sm:h-11 sm:w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md hover:bg-white/25 transition" aria-label="Back"><i class="bi bi-arrow-left text-lg sm:text-xl" aria-hidden="true"></i></a>
          <span class="flex h-10 w-10 sm:h-11 sm:w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 border border-white/20 shadow-md">
            <i class="bi bi-folder2-open text-lg sm:text-xl" aria-hidden="true"></i>
          </span>
          <div class="min-w-0 flex-1">
            <h1 class="text-lg sm:text-xl md:text-2xl font-bold m-0 tracking-tight truncate"><?php echo h($title); ?></h1>
            <p class="text-xs sm:text-sm md:text-base text-white/90 mt-1 mb-0"><?php echo $description !== '' ? h(mb_substr($description, 0, 80)) . (mb_strlen($description) > 80 ? '…' : '') : 'Practice questions and answers. View only — no download.'; ?></p>
          </div>
        </div>
        <div class="text-xs sm:text-sm text-white/80 flex flex-col items-start sm:items-end gap-1 shrink-0">
          <span class="uppercase tracking-[0.16em] text-white/60 font-semibold">Overview</span>
          <span><?php echo h($overviewLabel); ?></span>
          <span class="text-amber-200 text-xs mt-0.5"><i class="bi bi-eye mr-1"></i>View only</span>
        </div>
      </div>
    </section>

    <!-- View mode + Full screen -->
    <div class="testbank-view-row flex flex-wrap items-center justify-between gap-3 mb-4">
      <div class="testbank-view-links flex flex-wrap items-center gap-2">
        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider mr-1 hidden sm:inline">View:</span>
        <?php if ($hasQuestion && $hasSolution): ?>
          <a href="?id=<?php echo $id; ?>&subject_id=<?php echo $subjectId; ?>&view=split" class="px-4 py-2 rounded-lg text-sm font-semibold transition <?php echo $viewMode === 'split' ? 'bg-[#1665A0] text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>"><i class="bi bi-layout-split mr-1"></i> Split (Questions + Answers)</a>
        <?php endif; ?>
        <?php if ($hasQuestion): ?>
          <a href="?id=<?php echo $id; ?>&subject_id=<?php echo $subjectId; ?>&view=questions" class="px-4 py-2 rounded-lg text-sm font-semibold transition <?php echo $viewMode === 'questions' ? 'bg-[#1665A0] text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>"><i class="bi bi-file-earmark-text mr-1"></i> Questions only</a>
        <?php endif; ?>
        <?php if ($hasSolution): ?>
          <a href="?id=<?php echo $id; ?>&subject_id=<?php echo $subjectId; ?>&view=solutions" class="px-4 py-2 rounded-lg text-sm font-semibold transition <?php echo $viewMode === 'solutions' ? 'bg-[#1665A0] text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>"><i class="bi bi-file-earmark-check mr-1"></i> Answers only</a>
        <?php endif; ?>
      </div>
      <button type="button" id="testbank-fullscreen-btn" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200 border border-gray-200 transition" title="Full screen"><i class="bi bi-fullscreen"></i> Full screen</button>
    </div>

    <!-- Viewer content (can go full screen) -->
    <div id="testbank-viewer-wrap" class="relative bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden min-h-[60vh]">
      <div class="testbank-fs-header">
        <span class="testbank-fs-title"><?php echo h($title); ?></span>
        <button type="button" id="testbank-exit-fullscreen-btn" class="testbank-exit-fullscreen" title="Exit full screen"><i class="bi bi-fullscreen-exit"></i> Exit full screen</button>
      </div>
      <div class="testbank-fs-body">
      <?php if ($viewMode === 'split' && $hasQuestion && $hasSolution): ?>
        <div class="viewer-split p-2 sm:p-3">
          <div class="viewer-pane">
            <div class="px-4 py-2 bg-[#e8f2fa] border-b border-[#1665A0]/20 text-sm font-semibold text-[#143D59]"><i class="bi bi-file-earmark-text mr-1"></i> Questions</div>
            <?php if ($questionExt === 'pdf'): ?>
              <div id="tb-question-viewer" class="viewer-iframe bg-[#111827] flex items-center justify-center">
                <div class="text-gray-200 text-sm">Loading questions…</div>
              </div>
            <?php else: ?>
              <iframe class="viewer-iframe" src="<?php echo $fileUrl('question'); ?>" title="Questions"></iframe>
            <?php endif; ?>
          </div>
          <div class="viewer-pane">
            <div class="px-4 py-2 bg-[#dcfce7] border-b border-emerald-500/20 text-sm font-semibold text-[#166534]"><i class="bi bi-file-earmark-check mr-1"></i> Answers</div>
            <?php if ($solutionExt === 'pdf'): ?>
              <div id="tb-solution-viewer" class="viewer-iframe bg-[#111827] flex items-center justify-center">
                <div class="text-gray-200 text-sm">Loading answers…</div>
              </div>
            <?php else: ?>
              <iframe class="viewer-iframe" src="<?php echo $fileUrl('solution'); ?>" title="Answers"></iframe>
            <?php endif; ?>
          </div>
        </div>
      <?php elseif ($viewMode === 'questions' && $hasQuestion): ?>
        <div class="viewer-pane" style="min-height: 65vh;">
          <?php if ($questionExt === 'pdf'): ?>
            <div id="tb-question-viewer" class="viewer-iframe bg-[#111827] flex items-center justify-center" style="min-height: 64vh;">
              <div class="text-gray-200 text-sm">Loading questions…</div>
            </div>
          <?php else: ?>
            <iframe class="viewer-iframe" src="<?php echo $fileUrl('question'); ?>" title="Questions" style="min-height: 64vh;"></iframe>
          <?php endif; ?>
        </div>
      <?php elseif ($viewMode === 'solutions' && $hasSolution): ?>
        <div class="viewer-pane" style="min-height: 65vh;">
          <?php if ($solutionExt === 'pdf'): ?>
            <div id="tb-solution-viewer" class="viewer-iframe bg-[#111827] flex items-center justify-center" style="min-height: 64vh;">
              <div class="text-gray-200 text-sm">Loading answers…</div>
            </div>
          <?php else: ?>
            <iframe class="viewer-iframe" src="<?php echo $fileUrl('solution'); ?>" title="Answers" style="min-height: 64vh;"></iframe>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      </div>
    </div>
  </div>
  <div id="testbankSecurityBanner" class="fixed bottom-4 left-1/2 -translate-x-1/2 z-[1200] hidden px-4 py-2 rounded-full bg-[#143D59] text-white text-sm shadow-lg">
    <i class="bi bi-shield-lock mr-2"></i> Screen activity detected. This action is not allowed.
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.min.js" integrity="sha512-w0dUYihsxAMpL7AQLEKUp8NRhdZwvp7CDv+/PiSu9yyet3vaea2g7f3CiC1pydG00zsVfCuuXNVPLYp5Xz9s5g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
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
  <script>
  (function() {
    var wrap = document.getElementById('testbank-viewer-wrap');
    var btn = document.getElementById('testbank-fullscreen-btn');
    var exitBtn = document.getElementById('testbank-exit-fullscreen-btn');
    if (wrap && btn) {
      btn.addEventListener('click', function() {
        if (!document.fullscreenElement) {
          wrap.requestFullscreen ? wrap.requestFullscreen() : (wrap.webkitRequestFullscreen && wrap.webkitRequestFullscreen());
        }
      });
    }
    if (wrap && exitBtn) {
      exitBtn.addEventListener('click', function() {
        if (document.fullscreenElement) {
          document.exitFullscreen ? document.exitFullscreen() : (document.webkitExitFullscreen && document.webkitExitFullscreen());
        }
      });
    }
    document.addEventListener('fullscreenchange', function() {
      if (btn) btn.style.visibility = document.fullscreenElement ? 'hidden' : 'visible';
    });
    document.addEventListener('webkitfullscreenchange', function() {
      if (btn) btn.style.visibility = document.webkitFullscreenElement ? 'hidden' : 'visible';
    });

    function preventPrintSave() {
      document.addEventListener('contextmenu', function(e) { e.preventDefault(); return false; }, true);
      window.addEventListener('keydown', function(e) {
        var ctrl = (navigator.platform || '').toUpperCase().indexOf('MAC') >= 0 ? e.metaKey : e.ctrlKey;
        if (ctrl && (e.key === 'p' || e.key === 'P' || e.key === 's' || e.key === 'S')) {
          e.preventDefault();
          e.stopPropagation();
          alert('Printing and saving are disabled.');
          return false;
        }
      }, true);
      window.addEventListener('beforeprint', function(e) { e.preventDefault(); }, true);
      window.print = function() { alert('Printing is disabled.'); };
    }
    preventPrintSave();
    var iframes = document.querySelectorAll('.viewer-iframe');
    iframes.forEach(function(fr) {
      fr.addEventListener('load', function() {
        try {
          var doc = fr.contentDocument || fr.contentWindow.document;
          if (doc) {
            doc.addEventListener('contextmenu', function(e) { e.preventDefault(); return false; }, true);
          }
        } catch (e) {}
      });
    });

    // PDF.js rendering for PDFs (canvas-only, no text layer)
    function renderPdfInto(containerId, url) {
      var container = document.getElementById(containerId);
      if (!container) return;
      // If PDF.js is not available (e.g., offline / CDN blocked), fall back to normal iframe
      if (!window['pdfjsLib']) {
        container.innerHTML =
          '<iframe class="w-full h-full border-0" src="' + url +
          '" title="Document"></iframe>';
        return;
      }
      container.innerHTML = '';
      container.style.overflow = 'auto';
      container.style.background = '#111827';
      var loading = document.createElement('div');
      loading.className = 'text-gray-200 text-sm py-4 text-center';
      loading.textContent = 'Loading…';
      container.appendChild(loading);
      pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.worker.min.js';
      var loadingTask = pdfjsLib.getDocument(url);
      loadingTask.promise.then(function(pdf) {
        container.removeChild(loading);
        var pageCount = pdf.numPages;
        for (var pageNum = 1; pageNum <= pageCount; pageNum++) {
          (function(num) {
            pdf.getPage(num).then(function(page) {
              var viewport = page.getViewport({ scale: 1.2 });
              var canvas = document.createElement('canvas');
              canvas.style.display = 'block';
              canvas.style.margin = '0 auto 12px auto';
              var context = canvas.getContext('2d');
              canvas.height = viewport.height;
              canvas.width = viewport.width;
              container.appendChild(canvas);
              var renderContext = { canvasContext: context, viewport: viewport };
              page.render(renderContext);
            });
          })(pageNum);
        }
      }).catch(function() {
        container.innerHTML = '<div class="text-gray-200 text-sm py-4 text-center">Unable to load document.</div>';
      });
    }

    // Auto-init PDF.js viewers when containers exist
    var qViewer = document.getElementById('tb-question-viewer');
    if (qViewer) {
      renderPdfInto('tb-question-viewer', '<?php echo $fileUrl('question'); ?>');
    }
    var sViewer = document.getElementById('tb-solution-viewer');
    if (sViewer) {
      renderPdfInto('tb-solution-viewer', '<?php echo $fileUrl('solution'); ?>');
    }

    // Screen activity protection: show banner on tab switch / blur
    var secBanner = document.getElementById('testbankSecurityBanner');
    function showBanner() {
      if (!secBanner) return;
      secBanner.classList.remove('hidden');
      clearTimeout(secBanner._hideTimer);
      secBanner._hideTimer = setTimeout(function() {
        secBanner.classList.add('hidden');
      }, 4000);
    }
    document.addEventListener('visibilitychange', function() {
      if (document.hidden) showBanner();
    });
    window.addEventListener('blur', showBanner);
  })();
  </script>
</main>
</div>
</body>
</html>
