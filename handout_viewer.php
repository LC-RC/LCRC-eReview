<?php
require_once 'auth.php';
requireRole('student');

$handoutId = sanitizeInt($_GET['handout_id'] ?? 0);
if ($handoutId <= 0) { http_response_code(400); exit('Bad Request'); }

$stmt = mysqli_prepare($conn, "SELECT handout_title, file_path, allow_download FROM lesson_handouts WHERE handout_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $handoutId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$handout = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
if (!$handout || empty($handout['file_path'])) { http_response_code(404); exit('Not Found'); }

$title = $handout['handout_title'] ?: 'Handout';
$file = $handout['file_path'];
$allowDownload = (int)$handout['allow_download'] === 1;

$physicalPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
if (!file_exists($physicalPath)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = htmlspecialchars($title);
    $safeFile = htmlspecialchars($file);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>File not found</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">';
    echo '<div class="bg-white rounded-2xl shadow-lg border border-gray-200 p-6 sm:p-8 max-w-md w-full text-center">';
    echo '<div class="w-16 h-16 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-4"><i class="bi bi-file-earmark-x text-3xl text-amber-600"></i></div>';
    echo '<h1 class="text-xl font-bold text-gray-800 mb-2">File not found</h1>';
    echo '<p class="text-gray-600 text-sm mb-4">The handout <strong>' . $safeTitle . '</strong> could not be loaded. The file may have been moved or deleted.</p>';
    echo '<p class="text-gray-500 text-xs mb-4 break-all">' . $safeFile . '</p>';
    echo '<p class="text-gray-500 text-sm">Please contact the admin to re-upload this handout if needed.</p>';
    echo '<a href="javascript:history.back()" class="mt-6 inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-[#1665A0] text-white font-semibold hover:bg-[#143D59] transition"><i class="bi bi-arrow-left"></i> Go back</a>';
    echo '</div></body></html>';
    exit;
}

if ($allowDownload) {
    $viewerSrc = htmlspecialchars($file . '#toolbar=1', ENT_QUOTES);
} else {
    $viewerSrc = htmlspecialchars($file . '#toolbar=0&navpanes=0&scrollbar=0', ENT_QUOTES);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($title); ?> - Viewer</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    html, body { height: 100%; margin: 0; overflow: hidden; }
    .viewer-container { position: fixed; inset: 0; display: flex; flex-direction: column; }
    .viewer-wrapper { flex: 1; position: relative; overflow: auto; background: #525252; }
    .pdf-frame { width: 100%; height: 100%; border: 0; background: white; }
    <?php if (!$allowDownload): ?>
    @media print { body { display: none !important; } }
    <?php endif; ?>
  </style>
</head>
<body class="bg-gray-700">
  <?php if (!$allowDownload): ?>
    <div class="bg-amber-500/90 text-amber-900 py-2 px-3 text-sm font-medium flex items-center gap-2">
      <i class="bi bi-lock"></i> Downloads and printing are disabled for this handout.
    </div>
  <?php endif; ?>

  <div class="viewer-container">
    <div class="viewer-wrapper flex-1 min-h-0">
      <iframe id="pdfFrame" class="pdf-frame" src="<?php echo $viewerSrc; ?>" allowfullscreen></iframe>
    </div>
  </div>

  <script>
  const allowDownload = <?php echo $allowDownload ? 'true' : 'false'; ?>;

  if (!allowDownload) {
    window.addEventListener('keydown', function(e){
      const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
      const ctrl = isMac ? e.metaKey : e.ctrlKey;
      if (!ctrl) return;
      const k = (e.key || '').toLowerCase();
      if (k === 'p' || k === 's' || (k === 's' && e.shiftKey)) {
        e.preventDefault();
        e.stopPropagation();
        alert('Printing and saving are disabled for this handout.');
        return false;
      }
    }, { capture: true });

    document.addEventListener('contextmenu', function(e) {
      e.preventDefault();
      return false;
    }, { capture: true });

    window.addEventListener('beforeprint', function(e) {
      e.preventDefault();
      alert('Printing is disabled for this handout.');
      return false;
    });

    window.print = function() {
      alert('Printing is disabled for this handout.');
      return false;
    };

    const pdfFrame = document.getElementById('pdfFrame');
    if (pdfFrame) {
      pdfFrame.addEventListener('load', function() {
        try {
          const iframeDoc = pdfFrame.contentDocument || pdfFrame.contentWindow.document;
          if (iframeDoc) {
            iframeDoc.addEventListener('contextmenu', function(e) {
              e.preventDefault();
              return false;
            }, true);
            iframeDoc.addEventListener('keydown', function(e) {
              const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
              const ctrl = isMac ? e.metaKey : e.ctrlKey;
              if (ctrl && (e.key === 'p' || e.key === 'P' || e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                e.stopPropagation();
                alert('Printing and saving are disabled for this handout.');
                return false;
              }
            }, true);
          }
        } catch (e) {
          console.log('Cannot access iframe content (cross-origin)');
        }
      });
    }
  }
  </script>
</body>
</html>
