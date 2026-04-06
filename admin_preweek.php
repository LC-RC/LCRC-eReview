<?php
/**
 * Preweek admin: named pre-week entries, then lectures (preweek_topics) and materials.
 */
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/preweek_migrate.php';

$subjectIdLegacy = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectIdLegacy > 0) {
    header('Location: admin_preweek.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_preweek'])) {
    $name = trim((string)($_POST['preweek_name'] ?? ''));
    if ($name === '') {
        $_SESSION['admin_preweek_flash_error'] = 'Please enter a name for this preweek.';
    } else {
        $newId = ereview_create_preweek_named($conn, $name);
        if ($newId <= 0) {
            $_SESSION['admin_preweek_flash_error'] = 'Could not create preweek. Use a shorter name (max 255 characters).';
        } else {
            header('Location: admin_preweek_topics.php?preweek_unit_id=' . $newId);
            exit;
        }
    }
}

$returnQ = trim((string)($_POST['return_q'] ?? ''));
$returnSort = (string)($_POST['return_sort'] ?? 'newest');
if (!in_array($returnSort, ['newest', 'name_asc'], true)) {
    $returnSort = 'newest';
}
$preweekListRedirect = function () use ($returnQ, $returnSort): string {
    $p = [];
    if ($returnQ !== '') {
        $p['q'] = $returnQ;
    }
    if ($returnSort !== 'newest') {
        $p['sort'] = $returnSort;
    }
    $qs = http_build_query($p);

    return 'admin_preweek.php' . ($qs !== '' ? '?' . $qs : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_preweek'])) {
    $editId = sanitizeInt($_POST['preweek_unit_id'] ?? 0);
    $newName = trim((string)($_POST['preweek_name'] ?? ''));
    if ($editId <= 0 || $newName === '') {
        $_SESSION['admin_preweek_flash_error'] = 'Please enter a name for this pre-week.';
    } else {
        $stmt = mysqli_prepare($conn, 'UPDATE preweek_units SET title=? WHERE preweek_unit_id=? AND subject_id=0 LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'si', $newName, $editId);
            mysqli_stmt_execute($stmt);
            $aff = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            if ($aff > 0) {
                $_SESSION['admin_preweek_flash_success'] = 'Pre-week renamed.';
            } else {
                $noop = mysqli_prepare($conn, 'SELECT preweek_unit_id FROM preweek_units WHERE preweek_unit_id=? AND subject_id=0 AND title=? LIMIT 1');
                $stillOk = false;
                if ($noop) {
                    mysqli_stmt_bind_param($noop, 'is', $editId, $newName);
                    mysqli_stmt_execute($noop);
                    $nr = mysqli_stmt_get_result($noop);
                    $stillOk = $nr && mysqli_fetch_assoc($nr);
                    mysqli_stmt_close($noop);
                }
                if ($stillOk) {
                    $_SESSION['admin_preweek_flash_success'] = 'Pre-week updated.';
                } else {
                    $_SESSION['admin_preweek_flash_error'] = 'Could not update that pre-week.';
                }
            }
        } else {
            $_SESSION['admin_preweek_flash_error'] = 'Could not update pre-week.';
        }
    }
    header('Location: ' . $preweekListRedirect());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_preweek'])) {
    $delId = sanitizeInt($_POST['preweek_unit_id'] ?? 0);
    if ($delId <= 0) {
        $_SESSION['admin_preweek_flash_error'] = 'Invalid pre-week.';
    } else {
        $chk = mysqli_prepare(
            $conn,
            'SELECT u.preweek_unit_id,
              (SELECT COUNT(*) FROM preweek_topics t WHERE t.preweek_unit_id = u.preweek_unit_id) AS tc,
              (SELECT COUNT(*) FROM preweek_videos v INNER JOIN preweek_topics t ON v.preweek_topic_id = t.preweek_topic_id WHERE t.preweek_unit_id = u.preweek_unit_id) AS vc,
              (SELECT COUNT(*) FROM preweek_handouts h INNER JOIN preweek_topics t ON h.preweek_topic_id = t.preweek_topic_id WHERE t.preweek_unit_id = u.preweek_unit_id) AS hc
             FROM preweek_units u WHERE u.preweek_unit_id = ? AND u.subject_id = 0 LIMIT 1'
        );
        $rowOk = false;
        $tc = $vc = $hc = 0;
        if ($chk) {
            mysqli_stmt_bind_param($chk, 'i', $delId);
            mysqli_stmt_execute($chk);
            $cr = mysqli_stmt_get_result($chk);
            $rowOk = $cr && ($r = mysqli_fetch_assoc($cr));
            if ($rowOk) {
                $tc = (int)($r['tc'] ?? 0);
                $vc = (int)($r['vc'] ?? 0);
                $hc = (int)($r['hc'] ?? 0);
            }
            mysqli_stmt_close($chk);
        }
        if (!$rowOk) {
            $_SESSION['admin_preweek_flash_error'] = 'Pre-week not found.';
        } elseif ($tc + $vc + $hc > 0) {
            $_SESSION['admin_preweek_flash_error'] = 'Cannot delete: remove all lectures, videos, and handouts from this pre-week first.';
        } else {
            $stmt = mysqli_prepare($conn, 'DELETE FROM preweek_units WHERE preweek_unit_id=? AND subject_id=0 LIMIT 1');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $delId);
                mysqli_stmt_execute($stmt);
                $aff = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
                if ($aff <= 0) {
                    $_SESSION['admin_preweek_flash_error'] = 'Could not delete that pre-week.';
                } else {
                    $_SESSION['admin_preweek_flash_success'] = 'Pre-week deleted.';
                }
            } else {
                $_SESSION['admin_preweek_flash_error'] = 'Could not delete pre-week.';
            }
        }
    }
    header('Location: ' . $preweekListRedirect());
    exit;
}

$flashErr = $_SESSION['admin_preweek_flash_error'] ?? null;
unset($_SESSION['admin_preweek_flash_error']);
$flashOk = $_SESSION['admin_preweek_flash_success'] ?? null;
unset($_SESSION['admin_preweek_flash_success']);

$filterQ = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
if (!in_array($sort, ['newest', 'name_asc'], true)) {
    $sort = 'newest';
}
$orderBy = $sort === 'name_asc' ? 'u.title ASC' : 'u.created_at DESC';

$listSelect = 'SELECT u.preweek_unit_id, u.title, u.created_at,
  (SELECT COUNT(*) FROM preweek_topics t WHERE t.preweek_unit_id = u.preweek_unit_id) AS topics_cnt,
  (SELECT COUNT(*) FROM preweek_videos v INNER JOIN preweek_topics t ON v.preweek_topic_id = t.preweek_topic_id WHERE t.preweek_unit_id = u.preweek_unit_id) AS videos_cnt,
  (SELECT COUNT(*) FROM preweek_handouts h INNER JOIN preweek_topics t ON h.preweek_topic_id = t.preweek_topic_id WHERE t.preweek_unit_id = u.preweek_unit_id) AS handouts_cnt
  FROM preweek_units u
  WHERE u.subject_id = 0';

$preweekRows = [];
if ($filterQ !== '') {
    $stmt = mysqli_prepare($conn, $listSelect . ' AND u.title LIKE ? ORDER BY ' . $orderBy);
    if ($stmt) {
        $like = '%' . $filterQ . '%';
        mysqli_stmt_bind_param($stmt, 's', $like);
        mysqli_stmt_execute($stmt);
        $listQ = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $listQ = false;
    }
} else {
    $listQ = mysqli_query($conn, $listSelect . ' ORDER BY ' . $orderBy);
}
if ($listQ) {
    while ($row = mysqli_fetch_assoc($listQ)) {
        $preweekRows[] = $row;
    }
    mysqli_free_result($listQ);
}
$rowTotal = count($preweekRows);
$pageTitle = 'Pre-week';
$preweekNavStep = 'list';
$preweekNavTheme = 'dark';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <link rel="stylesheet" href="assets/css/admin-quiz-ui.css?v=10">
  <style>
    /* Modal sits above #main; match admin dark chrome */
    .admin-preweek-page .preweek-modal-overlay {
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
    .admin-preweek-page .preweek-modal-overlay[hidden] { display: none !important; }
    .admin-preweek-page .preweek-modal-panel {
      width: 100%;
      max-width: 26rem;
      border-radius: 0.75rem;
      background: #141414;
      border: 1px solid rgba(255, 255, 255, 0.1);
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
      overflow: hidden;
    }
    .admin-preweek-page .preweek-modal-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.03);
    }
    .admin-preweek-page .preweek-modal-body { padding: 1.25rem; }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-preweek-page admin-preweek-list-page">
  <?php include 'admin_sidebar.php'; ?>

  <?php require __DIR__ . '/includes/admin_preweek_context_nav.php'; ?>

  <div class="quiz-admin-hero rounded-xl px-5 py-5 mb-4">
    <h1 class="text-2xl font-bold text-gray-100 m-0 flex flex-wrap items-center gap-2">
      <span class="quiz-admin-hero-icon quiz-admin-hero-icon--preweek" aria-hidden="true"><i class="bi bi-lightning-charge-fill"></i></span>
      Pre-week
    </h1>
    <p class="text-gray-400 mt-3 mb-0 max-w-3xl text-sm sm:text-base">Add and manage pre-weeks, then open each to add lectures and materials. Students see the same flow under Pre-week.</p>
  </div>

  <?php if (!empty($flashErr)): ?>
    <div class="quiz-admin-alert quiz-admin-alert--error mb-4 flex items-center gap-2" role="alert">
      <i class="bi bi-exclamation-triangle-fill shrink-0"></i>
      <span><?php echo h($flashErr); ?></span>
    </div>
  <?php endif; ?>
  <?php if (!empty($flashOk)): ?>
    <div class="quiz-admin-alert quiz-admin-alert--success mb-4 flex items-center gap-2" role="status">
      <i class="bi bi-check-circle-fill shrink-0"></i>
      <span><?php echo h($flashOk); ?></span>
    </div>
  <?php endif; ?>

  <?php
    $preweekFiltersActive = ($filterQ !== '' || $sort !== 'newest');
  ?>
  <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
    <?php if ($preweekFiltersActive): ?>
      <span class="text-xs font-medium uppercase tracking-wide text-amber-200/70 bg-amber-500/10 border border-amber-500/25 rounded-full px-2.5 py-1">Filters active</span>
    <?php endif; ?>
  </div>

  <form method="get" action="admin_preweek.php" class="quiz-admin-filter quiz-admin-table-shell rounded-xl px-4 py-3 mb-4 flex flex-wrap items-end gap-3">
    <div class="w-full sm:flex-1 sm:min-w-[200px] sm:max-w-lg">
      <label for="preweek-filter-q" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Search entries</label>
      <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 pointer-events-none"><i class="bi bi-search" aria-hidden="true"></i></span>
        <input type="search" name="q" id="preweek-filter-q" value="<?php echo h($filterQ); ?>" placeholder="Name…" autocomplete="off" class="input-custom w-full pl-10">
      </div>
    </div>
    <div class="w-full sm:w-44">
      <label for="preweek-filter-sort" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Sort</label>
      <select name="sort" id="preweek-filter-sort" class="input-custom w-full">
        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
        <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A–Z</option>
      </select>
    </div>
    <div class="flex flex-wrap gap-2 shrink-0">
      <button type="submit" class="quiz-admin-filter-btn px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2"><i class="bi bi-search" aria-hidden="true"></i> Apply</button>
      <?php if ($preweekFiltersActive): ?>
        <a href="admin_preweek.php" class="quiz-admin-filter-clear px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2">Clear</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="quiz-admin-table-shell rounded-xl overflow-hidden">
    <div class="quiz-admin-table-head px-5 py-3 flex flex-wrap justify-between items-center gap-3">
      <div class="flex items-center gap-2 min-w-0">
        <span class="font-semibold text-gray-100">Entries</span>
        <span class="quiz-admin-count-pill quiz-admin-count-pill--preweek"><?php echo (int)$rowTotal; ?></span>
      </div>
      <button type="button" id="preweekOpenAddModal" class="admin-content-btn admin-content-btn--subject px-4 py-2 rounded-lg font-semibold border-2 transition inline-flex items-center gap-2 shrink-0">
        <i class="bi bi-plus-circle" aria-hidden="true"></i> Add pre-week
      </button>
    </div>
    <div class="overflow-x-auto pl-3 pr-8">
      <table class="quiz-admin-data-table w-full text-left">
        <thead>
          <tr>
            <th class="px-5 py-3 font-semibold">Name</th>
            <th class="px-5 py-3 font-semibold">Contents</th>
            <th class="px-5 py-3 font-semibold text-right min-w-[17rem]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rowTotal === 0): ?>
            <tr>
              <td colspan="3" class="px-5 py-14 text-center quiz-admin-empty">
                <i class="bi bi-inbox text-4xl block mb-3 quiz-admin-empty-icon"></i>
                <div class="font-semibold text-gray-200"><?php echo ($filterQ !== '' || $sort !== 'newest') ? 'No matching entries' : 'No entries yet'; ?></div>
                <p class="text-sm mt-1 text-gray-500 m-0"><?php echo ($filterQ !== '' || $sort !== 'newest') ? 'Try Clear or change search.' : 'Use <strong class="text-gray-400">Add pre-week</strong> in the bar above.'; ?></p>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($preweekRows as $row): ?>
              <?php
                $pid = (int)$row['preweek_unit_id'];
                $ptitle = trim((string)($row['title'] ?? '')) ?: 'Preweek';
                $tc = (int)($row['topics_cnt'] ?? 0);
                $vc = (int)($row['videos_cnt'] ?? 0);
                $hc = (int)($row['handouts_cnt'] ?? 0);
                $hasPreweekContent = ($tc + $vc + $hc) > 0;
                $createdRaw = $row['created_at'] ?? '';
                $createdLabel = $createdRaw ? date('M j, Y', strtotime($createdRaw)) : '—';
              ?>
              <tr class="quiz-admin-row">
                <td class="px-5 py-3 align-top">
                  <div class="font-semibold text-gray-100"><?php echo h($ptitle); ?></div>
                  <div class="text-gray-500 text-xs mt-1">Added <?php echo h($createdLabel); ?></div>
                </td>
                <td class="px-5 py-3 align-top text-sm text-gray-400">
                  <span class="tabular-nums"><?php echo (int)$tc; ?></span> lecture<?php echo $tc === 1 ? '' : 's'; ?>
                  <span class="text-gray-600 mx-1">·</span>
                  <span class="tabular-nums"><?php echo (int)$vc; ?></span> video<?php echo $vc === 1 ? '' : 's'; ?>
                  <span class="text-gray-600 mx-1">·</span>
                  <span class="tabular-nums"><?php echo (int)$hc; ?></span> handout<?php echo $hc === 1 ? '' : 's'; ?>
                </td>
                <td class="px-5 py-3 align-top">
                  <div class="flex flex-wrap items-center justify-end gap-2">
                    <a href="admin_preweek_topics.php?preweek_unit_id=<?php echo (int)$pid; ?>" class="admin-action-btn admin-action-btn--preweek inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-sm font-medium border-2 transition no-underline">
                      <i class="bi bi-folder2-open" aria-hidden="true"></i> Open lectures
                    </a>
                    <button type="button" class="quiz-admin-btn-secondary inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold transition preweek-edit-open"
                      data-id="<?php echo (int)$pid; ?>"
                      data-title="<?php echo h($ptitle); ?>">
                      <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                    </button>
                    <?php if ($hasPreweekContent): ?>
                      <button type="button" class="preweek-delete-blocked-btn inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-sm font-medium border-2 border-red-500/40 text-red-400 hover:bg-red-950/25 transition"
                        data-tc="<?php echo (int)$tc; ?>"
                        data-vc="<?php echo (int)$vc; ?>"
                        data-hc="<?php echo (int)$hc; ?>">
                        <i class="bi bi-trash" aria-hidden="true"></i> Delete
                      </button>
                    <?php else: ?>
                      <form method="post" action="admin_preweek.php" class="inline m-0" onsubmit="return confirm('Delete this pre-week? This cannot be undone.');">
                        <input type="hidden" name="delete_preweek" value="1">
                        <input type="hidden" name="preweek_unit_id" value="<?php echo (int)$pid; ?>">
                        <input type="hidden" name="return_q" value="<?php echo h($filterQ); ?>">
                        <input type="hidden" name="return_sort" value="<?php echo h($sort); ?>">
                        <button type="submit" class="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-sm font-medium border-2 border-red-500/40 text-red-400 hover:bg-red-950/25 transition">
                          <i class="bi bi-trash" aria-hidden="true"></i> Delete
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div id="addPreweekModal" class="preweek-modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="addPreweekModalTitle">
    <div class="preweek-modal-panel">
      <div class="preweek-modal-head">
        <h2 id="addPreweekModalTitle" class="text-lg font-semibold text-white m-0">Add pre-week</h2>
        <button type="button" class="text-gray-400 hover:text-white text-2xl leading-none p-1 rounded-lg" id="preweekCloseAddModal" aria-label="Close">&times;</button>
      </div>
      <div class="preweek-modal-body">
        <form method="post" action="admin_preweek.php">
          <input type="hidden" name="add_preweek" value="1">
          <div class="space-y-4">
            <div>
              <label for="preweek_name_input" class="block text-sm font-medium text-gray-300 mb-1.5">Display name <span class="text-red-400">*</span></label>
              <input type="text" name="preweek_name" id="preweek_name_input" required maxlength="255" class="input-custom w-full" placeholder="e.g. Pre-Week Orientation, Batch A…" autocomplete="off">
              <p class="text-xs text-gray-500 mt-2 mb-0">Shown to students. Not tied to a subject.</p>
            </div>
          </div>
          <div class="flex flex-wrap gap-2 justify-end mt-6 pt-2 border-t border-white/10">
            <button type="button" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold border-2" id="preweekCancelAddModal">Cancel</button>
            <button type="submit" class="admin-content-btn admin-content-btn--subject px-4 py-2.5 rounded-lg font-semibold border-2 inline-flex items-center gap-2">
              <i class="bi bi-arrow-right-circle" aria-hidden="true"></i> Continue to lectures
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="editPreweekModal" class="preweek-modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="editPreweekModalTitle">
    <div class="preweek-modal-panel">
      <div class="preweek-modal-head">
        <h2 id="editPreweekModalTitle" class="text-lg font-semibold text-white m-0">Rename pre-week</h2>
        <button type="button" class="text-gray-400 hover:text-white text-2xl leading-none p-1 rounded-lg" id="preweekCloseEditModal" aria-label="Close">&times;</button>
      </div>
      <div class="preweek-modal-body">
        <form method="post" action="admin_preweek.php">
          <input type="hidden" name="update_preweek" value="1">
          <input type="hidden" name="preweek_unit_id" id="edit_preweek_unit_id" value="">
          <input type="hidden" name="return_q" value="<?php echo h($filterQ); ?>">
          <input type="hidden" name="return_sort" value="<?php echo h($sort); ?>">
          <div class="space-y-4">
            <div>
              <label for="edit_preweek_name_input" class="block text-sm font-medium text-gray-300 mb-1.5">Display name <span class="text-red-400">*</span></label>
              <input type="text" name="preweek_name" id="edit_preweek_name_input" required maxlength="255" class="input-custom w-full" placeholder="Pre-week name" autocomplete="off">
            </div>
          </div>
          <div class="flex flex-wrap gap-2 justify-end mt-6 pt-2 border-t border-white/10">
            <button type="button" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold border-2" id="preweekCancelEditModal">Cancel</button>
            <button type="submit" class="admin-content-btn admin-content-btn--subject px-4 py-2.5 rounded-lg font-semibold border-2 inline-flex items-center gap-2">
              <i class="bi bi-check2" aria-hidden="true"></i> Save
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="preweekDeleteBlockedModal" class="preweek-modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="preweekDeleteBlockedTitle">
    <div class="preweek-modal-panel">
      <div class="preweek-modal-head">
        <h2 id="preweekDeleteBlockedTitle" class="text-lg font-semibold text-white m-0 flex items-center gap-2">
          <i class="bi bi-exclamation-octagon text-amber-400" aria-hidden="true"></i> Cannot delete
        </h2>
        <button type="button" class="text-gray-400 hover:text-white text-2xl leading-none p-1 rounded-lg" id="preweekCloseDeleteBlockedModal" aria-label="Close">&times;</button>
      </div>
      <div class="preweek-modal-body">
        <p class="text-gray-300 text-sm leading-relaxed m-0" id="preweekDeleteBlockedMsg"></p>
        <div class="flex justify-end mt-6 pt-2 border-t border-white/10">
          <button type="button" class="admin-content-btn admin-content-btn--subject px-4 py-2.5 rounded-lg font-semibold border-2" id="preweekDeleteBlockedOk">OK</button>
        </div>
      </div>
    </div>
  </div>

</div>
</main>
<script>
(function () {
  var modal = document.getElementById('addPreweekModal');
  var openBtn = document.getElementById('preweekOpenAddModal');
  var closeBtn = document.getElementById('preweekCloseAddModal');
  var cancelBtn = document.getElementById('preweekCancelAddModal');
  function openM() {
    if (!modal) return;
    modal.hidden = false;
    var nameIn = document.getElementById('preweek_name_input');
    if (nameIn) nameIn.focus();
  }
  function closeM() { if (modal) modal.hidden = true; }
  if (openBtn) openBtn.addEventListener('click', openM);
  if (closeBtn) closeBtn.addEventListener('click', closeM);
  if (cancelBtn) cancelBtn.addEventListener('click', closeM);
  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeM();
    });
  }

  var editModal = document.getElementById('editPreweekModal');
  var editIdEl = document.getElementById('edit_preweek_unit_id');
  var editNameEl = document.getElementById('edit_preweek_name_input');
  var closeEdit = document.getElementById('preweekCloseEditModal');
  var cancelEdit = document.getElementById('preweekCancelEditModal');
  function closeEditM() { if (editModal) editModal.hidden = true; }
  function openEditM(id, title) {
    if (!editModal || !editIdEl || !editNameEl) return;
    editIdEl.value = String(id);
    editNameEl.value = title;
    editModal.hidden = false;
    editNameEl.focus();
    editNameEl.select();
  }
  document.querySelectorAll('.preweek-edit-open').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openEditM(btn.getAttribute('data-id'), btn.getAttribute('data-title') || '');
    });
  });
  if (closeEdit) closeEdit.addEventListener('click', closeEditM);
  if (cancelEdit) cancelEdit.addEventListener('click', closeEditM);
  if (editModal) {
    editModal.addEventListener('click', function (e) {
      if (e.target === editModal) closeEditM();
    });
  }

  var blockedModal = document.getElementById('preweekDeleteBlockedModal');
  var blockedMsg = document.getElementById('preweekDeleteBlockedMsg');
  var closeBlocked = document.getElementById('preweekCloseDeleteBlockedModal');
  var okBlocked = document.getElementById('preweekDeleteBlockedOk');
  function closeBlockedM() { if (blockedModal) blockedModal.hidden = true; }
  function openBlockedM(tc, vc, hc) {
    if (!blockedModal || !blockedMsg) return;
    var parts = [];
    if (tc > 0) parts.push(tc + ' lecture' + (tc === 1 ? '' : 's'));
    if (vc > 0) parts.push(vc + ' video' + (vc === 1 ? '' : 's'));
    if (hc > 0) parts.push(hc + ' handout' + (hc === 1 ? '' : 's'));
    blockedMsg.textContent = 'This pre-week still has content (' + parts.join(', ') + '). Open lectures and remove lectures, videos, and handouts first, then you can delete the empty pre-week.';
    blockedModal.hidden = false;
  }
  document.querySelectorAll('.preweek-delete-blocked-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tc = parseInt(btn.getAttribute('data-tc'), 10) || 0;
      var vc = parseInt(btn.getAttribute('data-vc'), 10) || 0;
      var hc = parseInt(btn.getAttribute('data-hc'), 10) || 0;
      openBlockedM(tc, vc, hc);
    });
  });
  if (closeBlocked) closeBlocked.addEventListener('click', closeBlockedM);
  if (okBlocked) okBlocked.addEventListener('click', closeBlockedM);
  if (blockedModal) {
    blockedModal.addEventListener('click', function (e) {
      if (e.target === blockedModal) closeBlockedM();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (modal && !modal.hidden) { closeM(); return; }
    if (editModal && !editModal.hidden) { closeEditM(); return; }
    if (blockedModal && !blockedModal.hidden) { closeBlockedM(); }
  });
})();
</script>
</body>
</html>
