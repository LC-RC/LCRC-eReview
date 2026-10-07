<?php
/**
 * Pre-week lectures (like lessons). DB: preweek_topics.
 * Materials are uploaded per lecture via admin_preweek_materials.php.
 */
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/preweek_migrate.php';

$unitId = (int)($_GET['preweek_unit_id'] ?? 0);
if ($unitId <= 0) {
    header('Location: admin_preweek');
    exit;
}

$unitRes = mysqli_query($conn, 'SELECT * FROM preweek_units WHERE preweek_unit_id=' . $unitId . ' AND subject_id=0 LIMIT 1');
$unit = $unitRes ? mysqli_fetch_assoc($unitRes) : null;
if (!$unit) {
    header('Location: admin_preweek');
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
    header('Location: admin_preweek_topics?preweek_unit_id=' . $unitId);
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
    header('Location: admin_preweek_topics?preweek_unit_id=' . $unitId);
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
$pageTitle = 'Pre-week lectures - ' . $unitTitle;
$preweekNavStep = 'lectures';
$preweekNavUnitId = $unitId;
$preweekNavUnitTitle = $unitTitle;
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard'],
    ['Pre-week', 'admin_preweek'],
    [$unitTitle, 'admin_preweek_topics?preweek_unit_id=' . (int)$unitId],
    ['Lectures'],
];
$adminHeroIcon = 'folder2-open';
$adminHeroTint = 'violet';
$adminHeroEyebrow = 'Pre-week / ' . $unitTitle;
$adminHeroTitle = 'Lectures';
$adminHeroSubtitle = 'Manage lectures and learning materials for ' . $unitTitle . '.';
$adminHeroMeta = '<span class="quiz-admin-count-pill">' . (int)$rowTotal . ' lecture' . ((int)$rowTotal === 1 ? '' : 's') . '</span>';
$adminHeroActions = '<button type="button" id="openAddLectureModal" class="admin-btn admin-btn--primary"><i class="bi bi-plus-lg" aria-hidden="true"></i> New Lecture</button>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
<style>
    [x-cloak] { display: none !important; }
    .admin-preweek-lectures-page .preweek-lecture-modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 910;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      overflow: hidden;
      background: rgba(2, 6, 15, 0.62);
      backdrop-filter: blur(8px);
    }
    .admin-preweek-lectures-page .preweek-lecture-modal-overlay[hidden] { display: none !important; }
    .admin-preweek-lectures-page .preweek-lecture-modal-panel {
      width: 100%;
      max-width: 28rem;
      max-height: calc(100dvh - 48px);
      display: flex;
      flex-direction: column;
      border-radius: 0.75rem;
      background: var(--admin-glass-strong, var(--admin-surface, #fff));
      border: 1px solid var(--admin-border-strong, rgba(30, 58, 110, 0.16));
      box-shadow: var(--admin-shadow-lg, 0 25px 50px -12px rgba(15, 35, 70, 0.18));
      color: var(--admin-text-secondary, #445468);
      overflow: hidden;
    }
    .admin-preweek-lectures-page .preweek-lecture-modal-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid var(--admin-border, rgba(30, 58, 110, 0.1));
      background: var(--glass-surface-inner);
      flex: 0 0 auto;
    }
    .admin-preweek-lectures-page .preweek-lecture-modal-body {
      padding: 1.25rem;
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
    }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-preweek-lectures-page">
  <?php include 'admin_sidebar.php'; ?>

  <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

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

  <section class="content-library" aria-labelledby="preweek-lectures-heading">
    <div class="content-library__head">
      <div>
        <h2 id="preweek-lectures-heading" class="content-library__title">Lectures</h2>
        <p class="content-library__sub">Manage lectures and their attached materials.</p>
      </div>
      <span class="content-library__count"><?php echo (int)$rowTotal; ?> lecture<?php echo (int)$rowTotal === 1 ? '' : 's'; ?></span>
    </div>
    <form method="get" action="admin_preweek_topics" class="content-library__toolbar">
      <input type="hidden" name="preweek_unit_id" value="<?php echo (int)$unitId; ?>">
      <div class="content-library__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="q" id="lecture-filter-q" value="<?php echo h($filterQ); ?>" placeholder="Search title or description..." autocomplete="off" class="input-custom" aria-label="Search lectures">
      </div>
      <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-funnel"></i> Apply</button>
      <?php if ($filterQ !== ''): ?>
        <a href="admin_preweek_topics?preweek_unit_id=<?php echo (int)$unitId; ?>" class="admin-btn admin-btn--ghost admin-btn--sm">Clear</a>
      <?php endif; ?>
    </form>
    <?php if ($rowTotal === 0): ?>
      <div class="content-library-empty">
        <i class="bi bi-journal-plus" aria-hidden="true"></i>
        <h3><?php echo $filterQ !== '' ? 'No lectures match your search' : 'No lectures yet'; ?></h3>
        <p class="content-library__sub"><?php echo $filterQ !== '' ? 'Try Clear search or use New Lecture in the header.' : 'Use New Lecture, then open Materials on a row.'; ?></p>
      </div>
    <?php else: ?>
    <div class="content-library-table-scroll">
      <table class="content-library-table">
        <thead>
          <tr>
            <th class="col-desktop" scope="col">#</th>
            <th scope="col">Lecture</th>
            <th class="col-desktop" scope="col">Videos</th>
            <th class="col-desktop" scope="col">Handouts</th>
            <th class="col-actions" scope="col">Actions</th>
          </tr>
        </thead>
        <tbody>
            <?php $rowIndex = 0; foreach ($topicRows as $row): $rowIndex++; ?>
              <?php
                $tid = (int)$row['preweek_topic_id'];
                $tt = trim((string)($row['title'] ?? '')) ?: 'Untitled';
                $vc = (int)($row['videos_cnt'] ?? 0);
                $hc = (int)($row['handouts_cnt'] ?? 0);
                $materialsUrl = 'admin_preweek_materials?preweek_topic_id=' . (int)$tid;
                $vClass = $vc === 0 ? 'lesson-count-pill lesson-count-pill--warn' : 'lesson-count-pill lesson-count-pill--ok';
                $hClass = $hc === 0 ? 'lesson-count-pill lesson-count-pill--warn' : 'lesson-count-pill lesson-count-pill--ok';
              ?>
              <tr>
                <td class="col-desktop"><span class="content-reorder-ord"><?php echo (int)$rowIndex; ?></span></td>
                <td>
                  <div class="content-library-subject">
                    <span class="lms-icon-tile inline-flex h-10 w-10 items-center justify-center rounded-xl" aria-hidden="true"><i class="bi bi-file-text"></i></span>
                    <span class="content-library-subject__text">
                      <a href="<?php echo h($materialsUrl); ?>" class="content-library-subject__name"><?php echo h($tt); ?></a>
                      <?php if (trim((string)($row['description'] ?? '')) !== ''): ?>
                        <span class="content-library-subject__desc"><?php echo h(mb_strimwidth((string)$row['description'], 0, 90, '...')); ?></span>
                      <?php endif; ?>
                      <span class="content-library-subject__mobile-meta"><?php echo (int)$vc; ?> video<?php echo $vc === 1 ? '' : 's'; ?> · <?php echo (int)$hc; ?> handout<?php echo $hc === 1 ? '' : 's'; ?></span>
                    </span>
                  </div>
                </td>
                <td class="col-desktop">
                  <span class="inline-flex items-center gap-1.5 rounded-xl border border-sky-100 bg-sky-50/80 px-2.5 py-1.5 text-xs font-bold tabular-nums <?php echo $vClass; ?>">
                    <i class="bi bi-play-circle"></i> <?php echo (int)$vc; ?>
                  </span>
                </td>
                <td class="col-desktop">
                  <span class="inline-flex items-center gap-1.5 rounded-xl border border-violet-100 bg-violet-50/80 px-2.5 py-1.5 text-xs font-bold tabular-nums <?php echo $hClass; ?>">
                    <i class="bi bi-file-earmark-pdf"></i> <?php echo (int)$hc; ?>
                  </span>
                </td>
                <td class="col-actions">
                  <div class="admin-row-actions inline-flex items-center justify-end gap-1.5" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
                    <a href="<?php echo h($materialsUrl); ?>" class="hub-next-btn">Materials <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    <div class="admin-row-menu-wrap">
                      <button type="button" class="admin-row-action admin-row-action--more" :class="menuOpen ? 'is-open' : ''" :aria-expanded="menuOpen" title="More actions" @click.stop="menuOpen = !menuOpen"><i class="bi bi-three-dots"></i><span class="sr-only">More actions</span></button>
                      <div x-show="menuOpen" x-cloak @click.outside="menuOpen = false" class="admin-row-menu">
                        <a href="admin_preweek_topics?preweek_unit_id=<?php echo (int)$unitId; ?>&edit=<?php echo (int)$tid; ?>" @click="menuOpen = false" class="admin-row-menu__item"><i class="bi bi-pencil"></i> Edit</a>
                        <a href="admin_preweek_topics?preweek_unit_id=<?php echo (int)$unitId; ?>&delete_topic=<?php echo (int)$tid; ?>" onclick="return confirm('Delete this lecture and all videos and handouts inside it? This cannot be undone.');" class="admin-row-menu__item admin-row-menu__item--danger"><i class="bi bi-trash"></i> Delete</a>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <div id="lectureModal" class="preweek-lecture-modal-overlay" <?php echo $lectureModalOpenEdit ? '' : 'hidden'; ?> role="dialog" aria-modal="true" aria-labelledby="lectureModalTitle">
    <div class="preweek-lecture-modal-panel">
      <div class="preweek-lecture-modal-head">
        <h2 id="lectureModalTitle" class="text-lg font-semibold text-white m-0"><?php echo $edit ? 'Edit lecture' : 'Add lecture'; ?></h2>
        <button type="button" class="text-gray-400 hover:text-white text-2xl leading-none p-1 rounded-lg" id="lectureModalCloseBtn" aria-label="Close">&times;</button>
      </div>
      <div class="preweek-lecture-modal-body">
        <p class="text-xs text-gray-500 m-0 mb-4">Students see these lectures before opening materials.</p>
        <form method="post" action="admin_preweek_topics?preweek_unit_id=<?php echo (int)$unitId; ?>" id="lectureModalForm">
          <input type="hidden" name="save_topic" value="1">
          <input type="hidden" name="topic_id" id="lecture_topic_id" value="<?php echo $edit ? (int)$edit['preweek_topic_id'] : 0; ?>">
          <div class="space-y-4">
            <div>
              <label for="lecture_topic_title" class="block text-sm font-medium text-gray-300 mb-1.5">Title <span class="text-red-400">*</span></label>
              <input type="text" name="topic_title" id="lecture_topic_title" required maxlength="255" value="<?php echo h($edit['title'] ?? ''); ?>" class="input-custom w-full" placeholder="e.g. Pre-week lecture 1, Orientation..." autocomplete="off">
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
  var unitListUrl = <?php echo json_encode('admin_preweek_topics?preweek_unit_id=' . (int)$unitId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

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
