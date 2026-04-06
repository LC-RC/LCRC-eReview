<?php
/**
 * Pre-week lectures (like lessons). DB: preweek_topics.
 * Materials are uploaded per lecture via admin_preweek_materials.php.
 */
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/preweek_migrate.php';

$unitId = (int)($_GET['preweek_unit_id'] ?? 0);
if ($unitId <= 0) {
    header('Location: admin_preweek.php');
    exit;
}

$unitRes = mysqli_query($conn, 'SELECT * FROM preweek_units WHERE preweek_unit_id=' . $unitId . ' AND subject_id=0 LIMIT 1');
$unit = $unitRes ? mysqli_fetch_assoc($unitRes) : null;
if (!$unit) {
    header('Location: admin_preweek.php');
    exit;
}

$unitTitle = trim((string)($unit['title'] ?? 'Preweek')) ?: 'Preweek';
$filterQ = trim($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_topic'])) {
    $topicId = (int)($_POST['topic_id'] ?? 0);
    $title = trim((string)($_POST['topic_title'] ?? ''));
    $desc = trim((string)($_POST['topic_description'] ?? ''));
    if ($title === '') {
        $_SESSION['error'] = 'Title is required.';
    } elseif ($topicId > 0) {
        $stmt = mysqli_prepare($conn, 'UPDATE preweek_topics SET title=?, description=? WHERE preweek_topic_id=? AND preweek_unit_id=?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ssii', $title, $desc, $topicId, $unitId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Lecture updated.';
        }
    } else {
        $stmt = mysqli_prepare($conn, 'INSERT INTO preweek_topics (preweek_unit_id, title, description, sort_order) VALUES (?, ?, ?, 0)');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'iss', $unitId, $title, $desc);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Lecture added. Open Materials to upload files.';
        }
    }
    header('Location: admin_preweek_topics.php?preweek_unit_id=' . $unitId);
    exit;
}

if (isset($_GET['delete_topic'])) {
    $delId = (int)$_GET['delete_topic'];
    if ($delId > 0) {
        $chk = mysqli_prepare($conn, 'SELECT preweek_topic_id FROM preweek_topics WHERE preweek_topic_id=? AND preweek_unit_id=? LIMIT 1');
        if ($chk) {
            mysqli_stmt_bind_param($chk, 'ii', $delId, $unitId);
            mysqli_stmt_execute($chk);
            $ok = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
            mysqli_stmt_close($chk);
            if ($ok) {
                $vr = mysqli_query($conn, 'SELECT preweek_video_id, video_url FROM preweek_videos WHERE preweek_topic_id=' . $delId);
                if ($vr) {
                    while ($row = mysqli_fetch_assoc($vr)) {
                        $u = (string)($row['video_url'] ?? '');
                        if (strpos($u, 'uploads/videos/') === 0) {
                            $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $u);
                            if (is_file($abs)) {
                                @unlink($abs);
                            }
                        }
                    }
                }
                $hr = mysqli_query($conn, 'SELECT file_path FROM preweek_handouts WHERE preweek_topic_id=' . $delId);
                if ($hr) {
                    while ($row = mysqli_fetch_assoc($hr)) {
                        $fp = (string)($row['file_path'] ?? '');
                        if ($fp !== '') {
                            $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fp);
                            if (is_file($abs)) {
                                @unlink($abs);
                            }
                        }
                    }
                }
                mysqli_query($conn, 'DELETE FROM preweek_videos WHERE preweek_topic_id=' . $delId);
                mysqli_query($conn, 'DELETE FROM preweek_handouts WHERE preweek_topic_id=' . $delId);
                mysqli_query($conn, 'DELETE FROM preweek_topics WHERE preweek_topic_id=' . $delId . ' AND preweek_unit_id=' . $unitId);
                $_SESSION['message'] = 'Lecture and its materials were removed.';
            }
        }
    }
    header('Location: admin_preweek_topics.php?preweek_unit_id=' . $unitId);
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    if ($eid > 0) {
        $stmt = mysqli_prepare($conn, 'SELECT * FROM preweek_topics WHERE preweek_topic_id=? AND preweek_unit_id=? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'ii', $eid, $unitId);
        mysqli_stmt_execute($stmt);
        $edit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
}

$listSql = '
  SELECT t.*,
    (SELECT COUNT(*) FROM preweek_videos v WHERE v.preweek_topic_id = t.preweek_topic_id) AS videos_cnt,
    (SELECT COUNT(*) FROM preweek_handouts h WHERE h.preweek_topic_id = t.preweek_topic_id) AS handouts_cnt
  FROM preweek_topics t
  WHERE t.preweek_unit_id=?
';
$topicRows = [];
if ($filterQ !== '') {
    $listSql .= ' AND (t.title LIKE ? OR IFNULL(t.description, \'\') LIKE ?)';
    $listSql .= ' ORDER BY t.sort_order ASC, t.preweek_topic_id DESC';
    $stmt = mysqli_prepare($conn, $listSql);
    if ($stmt) {
        $like = '%' . $filterQ . '%';
        mysqli_stmt_bind_param($stmt, 'iss', $unitId, $like, $like);
        mysqli_stmt_execute($stmt);
        $listQ = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $listQ = false;
    }
} else {
    $listSql .= ' ORDER BY t.sort_order ASC, t.preweek_topic_id DESC';
    $stmt = mysqli_prepare($conn, $listSql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $unitId);
        mysqli_stmt_execute($stmt);
        $listQ = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $listQ = false;
    }
}
if ($listQ) {
    while ($row = mysqli_fetch_assoc($listQ)) {
        $topicRows[] = $row;
    }
    mysqli_free_result($listQ);
}
$rowTotal = count($topicRows);
$lectureModalOpenEdit = ($edit !== null);
$pageTitle = 'Pre-week lectures — ' . $unitTitle;
$preweekNavStep = 'lectures';
$preweekNavUnitId = $unitId;
$preweekNavUnitTitle = $unitTitle;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <link rel="stylesheet" href="assets/css/admin-quiz-ui.css?v=10">
  <style>
    [x-cloak] { display: none !important; }
    .admin-preweek-lectures-page .preweek-lecture-modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 2000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      background: rgba(0, 0, 0, 0.65);
      backdrop-filter: blur(6px);
    }
    .admin-preweek-lectures-page .preweek-lecture-modal-overlay[hidden] { display: none !important; }
    .admin-preweek-lectures-page .preweek-lecture-modal-panel {
      width: 100%;
      max-width: 28rem;
      border-radius: 0.75rem;
      background: #141414;
      border: 1px solid rgba(255, 255, 255, 0.1);
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
      overflow: hidden;
    }
    .admin-preweek-lectures-page .preweek-lecture-modal-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.03);
    }
    .admin-preweek-lectures-page .preweek-lecture-modal-body { padding: 1.25rem; }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-preweek-lectures-page">
  <?php include 'admin_sidebar.php'; ?>

  <?php require __DIR__ . '/includes/admin_preweek_context_nav.php'; ?>

  <div class="quiz-admin-hero rounded-xl px-5 py-5 mb-4">
    <h1 class="text-2xl font-bold text-gray-100 m-0 flex flex-wrap items-center gap-2">
      <span class="quiz-admin-hero-icon quiz-admin-hero-icon--preweek" aria-hidden="true"><i class="bi bi-folder2-open"></i></span>
      Lectures <span class="text-gray-500 font-semibold">—</span> <span class="text-amber-200 font-semibold"><?php echo h($unitTitle); ?></span>
    </h1>
    <p class="text-gray-400 mt-3 mb-0 max-w-3xl text-sm sm:text-base">Add or edit lectures for this pre-week. Use <strong class="text-gray-300 font-semibold">Materials</strong> on each row to attach videos and handouts.</p>
  </div>

  <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
    <a href="admin_preweek.php" class="text-sm font-medium text-gray-400 hover:text-amber-200/90 inline-flex items-center gap-2 no-underline transition-colors">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Pre-week home
    </a>
    <?php if ($filterQ !== ''): ?>
      <span class="text-xs font-medium uppercase tracking-wide text-amber-200/70 bg-amber-500/10 border border-amber-500/25 rounded-full px-2.5 py-1">Search active</span>
    <?php endif; ?>
  </div>

  <form method="get" action="admin_preweek_topics.php" class="quiz-admin-filter quiz-admin-table-shell rounded-xl px-4 py-3 mb-4 flex flex-wrap items-end gap-3">
    <input type="hidden" name="preweek_unit_id" value="<?php echo (int)$unitId; ?>">
    <div class="w-full sm:flex-1 sm:min-w-[200px] sm:max-w-lg">
      <label for="lecture-filter-q" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Search</label>
      <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 pointer-events-none"><i class="bi bi-search" aria-hidden="true"></i></span>
        <input type="search" name="q" id="lecture-filter-q" value="<?php echo h($filterQ); ?>" placeholder="Title or description…" autocomplete="off" class="input-custom w-full pl-10">
      </div>
    </div>
    <div class="flex flex-wrap gap-2 shrink-0">
      <button type="submit" class="quiz-admin-filter-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-search" aria-hidden="true"></i> Apply</button>
      <?php if ($filterQ !== ''): ?>
        <a href="admin_preweek_topics.php?preweek_unit_id=<?php echo (int)$unitId; ?>" class="quiz-admin-filter-clear px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2">Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--success mb-5 flex items-center gap-2">
      <i class="bi bi-check-circle-fill shrink-0"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--error mb-5 flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill shrink-0"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <div class="quiz-admin-table-shell rounded-xl overflow-hidden">
    <div class="quiz-admin-table-head px-5 py-3 flex flex-wrap justify-between items-center gap-3">
      <div class="flex items-center gap-2 min-w-0">
        <span class="font-semibold text-gray-100">Lectures</span>
        <span class="quiz-admin-count-pill quiz-admin-count-pill--preweek"><?php echo (int)$rowTotal; ?></span>
      </div>
      <button type="button" id="openAddLectureModal" class="admin-content-btn admin-content-btn--subject px-4 py-2 rounded-lg font-semibold border-2 transition inline-flex items-center gap-2 shrink-0">
        <i class="bi bi-plus-circle" aria-hidden="true"></i> Add lecture
      </button>
    </div>
    <div class="overflow-x-auto pl-3 pr-8">
      <table class="quiz-admin-data-table w-full text-left">
        <thead>
          <tr>
            <th class="px-5 py-3 font-semibold">Title</th>
            <th class="px-5 py-3 font-semibold">Contents</th>
            <th class="px-5 py-3 font-semibold text-center w-[240px]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rowTotal === 0): ?>
            <tr>
              <td colspan="3" class="px-5 py-14 text-center quiz-admin-empty">
                <i class="bi bi-journal-plus text-4xl block mb-3 quiz-admin-empty-icon"></i>
                <div class="font-semibold text-gray-200">No lectures yet</div>
                <p class="text-sm mt-1 text-gray-500 m-0 max-w-md mx-auto"><?php echo $filterQ !== '' ? 'Try Clear search or use Add lecture in the header.' : 'Use <strong class="text-gray-400">Add lecture</strong> above, then open <strong class="text-gray-400">Materials</strong> on a row.'; ?></p>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($topicRows as $row): ?>
              <?php
                $tid = (int)$row['preweek_topic_id'];
                $tt = trim((string)($row['title'] ?? '')) ?: 'Untitled';
                $vc = (int)($row['videos_cnt'] ?? 0);
                $hc = (int)($row['handouts_cnt'] ?? 0);
              ?>
              <tr class="quiz-admin-row">
                <td class="px-5 py-3 align-top">
                  <div class="font-semibold text-gray-100"><?php echo h($tt); ?></div>
                  <?php if (trim((string)($row['description'] ?? '')) !== ''): ?>
                    <div class="text-gray-500 text-xs mt-1"><?php echo h((string)$row['description']); ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3 align-top text-sm text-gray-400">
                  <span class="tabular-nums"><?php echo (int)$vc; ?></span> video<?php echo $vc === 1 ? '' : 's'; ?>
                  <span class="text-gray-600 mx-1">·</span>
                  <span class="tabular-nums"><?php echo (int)$hc; ?></span> handout<?php echo $hc === 1 ? '' : 's'; ?>
                </td>
                <td class="px-5 py-3 align-middle text-center">
                  <div class="inline-block text-left w-full max-w-[220px]" x-data="{ expanded: false }">
                    <div class="flex flex-nowrap items-stretch justify-center gap-2">
                      <a href="admin_preweek_materials.php?preweek_topic_id=<?php echo (int)$tid; ?>" class="preweek-materials-link flex items-center justify-center gap-1.5 min-w-0 flex-1 px-2.5 py-2 rounded-lg text-sm font-semibold transition">
                        <i class="bi bi-collection-play shrink-0" aria-hidden="true"></i><span class="truncate">Materials</span>
                      </a>
                      <button type="button" @click="expanded = !expanded" class="quiz-admin-more-btn flex items-center justify-center gap-1 shrink-0 px-2.5 py-2 rounded-md text-xs border transition" :aria-expanded="expanded" title="More actions">
                        <i class="bi text-sm" :class="expanded ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                        <span class="opacity-80 hidden sm:inline">More</span>
                      </button>
                    </div>
                    <div x-show="expanded" x-cloak class="flex flex-col gap-2 mt-2 w-full">
                      <a href="admin_preweek_topics.php?preweek_unit_id=<?php echo (int)$unitId; ?>&edit=<?php echo (int)$tid; ?>" @click="expanded = false" class="quiz-admin-btn-secondary flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg text-sm font-semibold transition no-underline"><i class="bi bi-pencil"></i> Edit</a>
                      <a href="admin_preweek_topics.php?preweek_unit_id=<?php echo (int)$unitId; ?>&delete_topic=<?php echo (int)$tid; ?>" onclick="return confirm('Delete this lecture and all videos and handouts inside it? This cannot be undone.');" class="flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg text-sm font-medium border-2 border-red-500/50 text-red-400 hover:bg-red-950/30 transition no-underline"><i class="bi bi-trash"></i> Delete</a>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div id="lectureModal" class="preweek-lecture-modal-overlay" <?php echo $lectureModalOpenEdit ? '' : 'hidden'; ?> role="dialog" aria-modal="true" aria-labelledby="lectureModalTitle">
    <div class="preweek-lecture-modal-panel">
      <div class="preweek-lecture-modal-head">
        <h2 id="lectureModalTitle" class="text-lg font-semibold text-white m-0"><?php echo $edit ? 'Edit lecture' : 'Add lecture'; ?></h2>
        <button type="button" class="text-gray-400 hover:text-white text-2xl leading-none p-1 rounded-lg" id="lectureModalCloseBtn" aria-label="Close">&times;</button>
      </div>
      <div class="preweek-lecture-modal-body">
        <p class="text-xs text-gray-500 m-0 mb-4">Students see these lectures before opening materials.</p>
        <form method="post" action="admin_preweek_topics.php?preweek_unit_id=<?php echo (int)$unitId; ?>" id="lectureModalForm">
          <input type="hidden" name="save_topic" value="1">
          <input type="hidden" name="topic_id" id="lecture_topic_id" value="<?php echo $edit ? (int)$edit['preweek_topic_id'] : 0; ?>">
          <div class="space-y-4">
            <div>
              <label for="lecture_topic_title" class="block text-sm font-medium text-gray-300 mb-1.5">Title <span class="text-red-400">*</span></label>
              <input type="text" name="topic_title" id="lecture_topic_title" required maxlength="255" value="<?php echo h($edit['title'] ?? ''); ?>" class="input-custom w-full" placeholder="e.g. Pre-week lecture 1, Orientation…" autocomplete="off">
            </div>
            <div>
              <label for="lecture_topic_description" class="block text-sm font-medium text-gray-300 mb-1.5">Description <span class="text-gray-500 font-normal">(optional)</span></label>
              <textarea name="topic_description" id="lecture_topic_description" rows="3" class="input-custom w-full" placeholder="Short note for admins"><?php echo h($edit['description'] ?? ''); ?></textarea>
            </div>
          </div>
          <div class="flex flex-wrap gap-2 justify-end mt-6 pt-4 border-t border-white/10">
            <button type="button" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold border-2" id="lectureModalCancelBtn">Cancel</button>
            <button type="submit" class="admin-content-btn admin-content-btn--subject px-4 py-2.5 rounded-lg font-semibold border-2 inline-flex items-center gap-2" id="lectureModalSubmitBtn">
              <i class="bi bi-check-lg"></i> <span id="lectureModalSubmitLabel"><?php echo $edit ? 'Save changes' : 'Add lecture'; ?></span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

</div>
</main>
<script>
(function () {
  var modal = document.getElementById('lectureModal');
  var form = document.getElementById('lectureModalForm');
  var titleEl = document.getElementById('lectureModalTitle');
  var topicIdEl = document.getElementById('lecture_topic_id');
  var titleIn = document.getElementById('lecture_topic_title');
  var descIn = document.getElementById('lecture_topic_description');
  var submitLabel = document.getElementById('lectureModalSubmitLabel');
  var unitListUrl = <?php echo json_encode('admin_preweek_topics.php?preweek_unit_id=' . (int)$unitId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  function openAdd() {
    if (!modal || !form) return;
    modal.hidden = false;
    if (titleEl) titleEl.textContent = 'Add lecture';
    if (topicIdEl) topicIdEl.value = '0';
    if (titleIn) { titleIn.value = ''; titleIn.focus(); }
    if (descIn) descIn.value = '';
    if (submitLabel) submitLabel.textContent = 'Add lecture';
  }

  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
    if (window.location.search.indexOf('edit=') !== -1) {
      window.location.href = unitListUrl;
    }
  }

  function bindOpen(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', openAdd);
  }
  bindOpen('openAddLectureModal');

  var closeBtn = document.getElementById('lectureModalCloseBtn');
  var cancelBtn = document.getElementById('lectureModalCancelBtn');
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && !modal.hidden) closeModal();
    });
  }
})();
</script>
</body>
</html>
