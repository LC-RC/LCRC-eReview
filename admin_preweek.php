<?php
/**
 * Preweek admin: named pre-week entries, then lectures (preweek_topics) and materials.
 */
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/preweek_migrate.php';

$subjectIdLegacy = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectIdLegacy > 0) {
    header('Location: admin_preweek');
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
            header('Location: admin_preweek_topics?preweek_unit_id=' . $newId);
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

    return 'admin_preweek' . ($qs !== '' ? '?' . $qs : '');
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
$adminBreadcrumbs = [['Dashboard', 'admin_dashboard'], ['Pre-week']];
$adminHeroIcon = 'lightning-charge-fill';
$adminHeroTint = 'violet';
$adminHeroEyebrow = 'Pre-week management';
$adminHeroTitle = 'Pre-week';
$adminHeroSubtitle = 'Add and manage pre-weeks, then open each for lectures and materials.';
$adminHeroMeta = '<span class="quiz-admin-count-pill">' . (int)$rowTotal . ' ' . ((int)$rowTotal === 1 ? 'entry' : 'entries') . '</span>';
$adminHeroActions = '<button type="button" id="preweekOpenAddModal" class="admin-btn admin-btn--primary"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Pre-week</button>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
<style>
    /* Modal sits above #main; theme via tokens */
    .admin-preweek-page .preweek-modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 2000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      overflow: hidden;
      background: rgba(0, 0, 0, 0.65);
      backdrop-filter: blur(6px);
    }
    .admin-preweek-page .preweek-modal-overlay[hidden] { display: none !important; }
    .admin-preweek-page .preweek-modal-panel {
      width: 100%;
      max-width: 26rem;
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
    .admin-preweek-page .preweek-modal-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid var(--admin-border, rgba(30, 58, 110, 0.1));
      background: var(--glass-surface-inner);
      flex: 0 0 auto;
    }
    .admin-preweek-page .preweek-modal-body {
      padding: 1.25rem;
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
    }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-preweek-page admin-preweek-list-page">
  <?php include 'admin_sidebar.php'; ?>

  <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

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

  <section class="content-library" aria-labelledby="preweek-entries-heading">
    <div class="content-library__head">
      <div>
        <h2 id="preweek-entries-heading" class="content-library__title">Pre-week entries</h2>
        <p class="content-library__sub">Manage pre-week units, lectures and materials.</p>
      </div>
      <span class="content-library__count"><?php echo (int)$rowTotal; ?> <?php echo (int)$rowTotal === 1 ? 'entry' : 'entries'; ?></span>
    </div>
    <form method="get" action="admin_preweek" class="content-library__toolbar">
      <div class="content-library__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="q" id="preweek-filter-q" value="<?php echo h($filterQ); ?>" placeholder="Search pre-weeks..." autocomplete="off" class="input-custom" aria-label="Search pre-weeks">
      </div>
      <div class="content-library__sort">
        <label class="sr-only" for="preweek-filter-sort">Sort</label>
        <select name="sort" id="preweek-filter-sort" class="input-custom">
          <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest first</option>
          <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
        </select>
      </div>
      <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-funnel"></i> Filter</button>
      <?php if ($preweekFiltersActive): ?>
        <a href="admin_preweek" class="admin-btn admin-btn--ghost admin-btn--sm">Clear</a>
      <?php endif; ?>
    </form>
      <?php if ($rowTotal === 0): ?>
        <div class="content-library-empty">
          <i class="bi bi-inbox" aria-hidden="true"></i>
          <h3><?php echo ($filterQ !== '' || $sort !== 'newest') ? 'No matching entries' : 'No entries yet'; ?></h3>
          <p class="content-library__sub"><?php echo ($filterQ !== '' || $sort !== 'newest') ? 'Try Clear or change search.' : 'Use Add Pre-week in the page header.'; ?></p>
        </div>
      <?php else: ?>
        <div class="content-library-table-scroll">
          <table class="content-library-table">
            <thead>
              <tr>
                <th scope="col">Pre-week</th>
                <th class="col-desktop" scope="col">Lectures</th>
                <th class="col-desktop" scope="col">Videos</th>
                <th class="col-desktop" scope="col">Handouts</th>
                <th class="col-desktop" scope="col">Added</th>
                <th class="col-actions" scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
          <?php foreach ($preweekRows as $row): ?>
            <?php
              $pid = (int)$row['preweek_unit_id'];
              $ptitle = trim((string)($row['title'] ?? '')) ?: 'Preweek';
              $tc = (int)($row['topics_cnt'] ?? 0);
              $vc = (int)($row['videos_cnt'] ?? 0);
              $hc = (int)($row['handouts_cnt'] ?? 0);
              $hasPreweekContent = ($tc + $vc + $hc) > 0;
              $createdRaw = $row['created_at'] ?? '';
              $createdLabel = $createdRaw ? date('M j, Y', strtotime($createdRaw)) : '-';
              $lecturesUrl = 'admin_preweek_topics?preweek_unit_id=' . (int)$pid;
            ?>
            <tr>
              <td>
                <div class="content-library-subject">
                  <span class="lms-icon-tile inline-flex h-10 w-10 items-center justify-center rounded-xl" aria-hidden="true"><i class="bi bi-calendar-week"></i></span>
                  <span class="content-library-subject__text">
                    <a href="<?php echo h($lecturesUrl); ?>" class="content-library-subject__name"><?php echo h($ptitle); ?></a>
                    <span class="content-library-subject__mobile-meta"><?php echo (int)$tc; ?> lecture<?php echo $tc === 1 ? '' : 's'; ?> · <?php echo (int)$vc; ?> video<?php echo $vc === 1 ? '' : 's'; ?> · <?php echo (int)$hc; ?> handout<?php echo $hc === 1 ? '' : 's'; ?></span>
                  </span>
                </div>
              </td>
              <td class="col-desktop"><span class="content-library-metric"><i class="bi bi-journal-text" aria-hidden="true"></i> <?php echo (int)$tc; ?></span></td>
              <td class="col-desktop"><span class="content-library-metric"><i class="bi bi-play-circle" aria-hidden="true"></i> <?php echo (int)$vc; ?></span></td>
              <td class="col-desktop"><span class="content-library-metric"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> <?php echo (int)$hc; ?></span></td>
              <td class="col-desktop"><?php echo h($createdLabel); ?></td>
              <td class="col-actions">
                <div class="admin-row-actions inline-flex items-center justify-end gap-1.5" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
                  <a href="<?php echo h($lecturesUrl); ?>" class="hub-next-btn">Lectures <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                  <div class="admin-row-menu-wrap">
                    <button type="button" class="admin-row-action admin-row-action--more" :class="menuOpen ? 'is-open' : ''" :aria-expanded="menuOpen" title="More actions" @click.stop="menuOpen = !menuOpen"><i class="bi bi-three-dots"></i><span class="sr-only">More actions</span></button>
                    <div x-show="menuOpen" x-cloak @click.outside="menuOpen = false" class="admin-row-menu">
                      <button type="button" class="admin-row-menu__item preweek-edit-open" data-id="<?php echo (int)$pid; ?>" data-title="<?php echo h($ptitle); ?>" @click="menuOpen = false"><i class="bi bi-pencil"></i> Edit</button>
                      <?php if ($hasPreweekContent): ?>
                      <button type="button" class="admin-row-menu__item admin-row-menu__item--danger preweek-delete-blocked-btn" data-tc="<?php echo (int)$tc; ?>" data-vc="<?php echo (int)$vc; ?>" data-hc="<?php echo (int)$hc; ?>" @click="menuOpen = false"><i class="bi bi-trash"></i> Delete</button>
                      <?php else: ?>
                      <form method="post" action="admin_preweek" class="m-0" onsubmit="return confirm('Delete this pre-week? This cannot be undone.');">
                        <input type="hidden" name="delete_preweek" value="1">
                        <input type="hidden" name="preweek_unit_id" value="<?php echo (int)$pid; ?>">
                        <input type="hidden" name="return_q" value="<?php echo h($filterQ); ?>">
                        <input type="hidden" name="return_sort" value="<?php echo h($sort); ?>">
                        <button type="submit" class="admin-row-menu__item admin-row-menu__item--danger w-full" @click="menuOpen = false"><i class="bi bi-trash"></i> Delete</button>
                      </form>
                      <?php endif; ?>
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

  <div id="addPreweekModal" class="preweek-modal-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="addPreweekModalTitle">
    <div class="preweek-modal-panel">
      <div class="preweek-modal-head">
        <h2 id="addPreweekModalTitle" class="text-lg font-semibold text-white m-0">Add pre-week</h2>
        <button type="button" class="text-gray-400 hover:text-white text-2xl leading-none p-1 rounded-lg" id="preweekCloseAddModal" aria-label="Close">&times;</button>
      </div>
      <div class="preweek-modal-body">
        <form method="post" action="admin_preweek">
          <input type="hidden" name="add_preweek" value="1">
          <div class="space-y-4">
            <div>
              <label for="preweek_name_input" class="block text-sm font-medium text-gray-300 mb-1.5">Display name <span class="text-red-400">*</span></label>
              <input type="text" name="preweek_name" id="preweek_name_input" required maxlength="255" class="input-custom w-full" placeholder="e.g. Pre-Week Orientation, Batch A..." autocomplete="off">
              <p class="text-xs text-gray-500 mt-2 mb-0">Shown to students. Not tied to a subject.</p>
            </div>
          </div>
          <div class="flex flex-wrap gap-2 justify-end mt-6 pt-2 border-t border-slate-100">
            <button type="button" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold border-2" id="preweekCancelAddModal">Cancel</button>
            <button type="submit" class="admin-btn admin-btn--primary px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2">
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
        <form method="post" action="admin_preweek">
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
          <div class="flex flex-wrap gap-2 justify-end mt-6 pt-2 border-t border-slate-100">
            <button type="button" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold border-2" id="preweekCancelEditModal">Cancel</button>
            <button type="submit" class="admin-btn admin-btn--primary px-4 py-2.5 rounded-lg font-semibold inline-flex items-center gap-2">
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
        <div class="flex justify-end mt-6 pt-2 border-t border-slate-100">
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
