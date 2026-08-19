<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_sections.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';

$pageTitle = 'Sections';
$csrf = generateCSRFToken();
$actorId = (int) (getCurrentUserId() ?? 0);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $res = college_sections_create($conn, (string) ($_POST['section_name'] ?? ''), $actorId);
            if (!empty($res['ok'])) {
                $newStatus = strtolower(trim((string) ($_POST['status'] ?? 'active')));
                if ($newStatus === 'inactive' && !empty($res['section_id'])) {
                    college_sections_set_status($conn, (int) $res['section_id'], 'inactive', $actorId);
                }
                $_SESSION['message'] = 'Section created.';
                header('Location: professor_college_sections');
                exit;
            }
            $error = (string) ($res['error'] ?? 'Could not create section.');
        } elseif ($action === 'update') {
            $res = college_sections_update(
                $conn,
                (int) ($_POST['section_id'] ?? 0),
                (string) ($_POST['section_name'] ?? ''),
                (string) ($_POST['status'] ?? 'active'),
                $actorId
            );
            if (!empty($res['ok'])) {
                $_SESSION['message'] = 'Section updated.';
                header('Location: professor_college_sections');
                exit;
            }
            $error = (string) ($res['error'] ?? 'Could not update section.');
        } elseif ($action === 'set_status') {
            $res = college_sections_set_status(
                $conn,
                (int) ($_POST['section_id'] ?? 0),
                (string) ($_POST['status'] ?? 'active'),
                $actorId
            );
            if (!empty($res['ok'])) {
                $_SESSION['message'] = 'Section status updated.';
                header('Location: professor_college_sections');
                exit;
            }
            $error = (string) ($res['error'] ?? 'Could not update status.');
        } elseif ($action === 'delete') {
            $res = college_sections_delete($conn, (int) ($_POST['section_id'] ?? 0), $actorId);
            if (!empty($res['ok'])) {
                $_SESSION['message'] = 'Section deleted.';
                header('Location: professor_college_sections');
                exit;
            }
            $_SESSION['section_delete_error'] = [
                'message' => (string) ($res['error'] ?? 'Could not delete section.'),
                'references' => $res['references'] ?? [],
                'section_name' => (string) ($_POST['section_name'] ?? ''),
            ];
            header('Location: professor_college_sections');
            exit;
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}

$sections = college_sections_list($conn, false);
$studentCounts = college_sections_student_counts($conn);
$examCounts = college_sections_exam_assignment_counts($conn);
$diagCounts = college_sections_diagnostic_assignment_counts($conn);

$rows = [];
foreach ($sections as $sec) {
    $name = (string) $sec['section_name'];
    if ($q !== '' && stripos($name, $q) === false) {
        continue;
    }
    if ($statusFilter !== 'all' && ($sec['status'] ?? '') !== $statusFilter) {
        continue;
    }
    $sec['student_count'] = (int) ($studentCounts[$name] ?? 0);
    $sec['exam_count'] = (int) ($examCounts[$name] ?? 0);
    $sec['diag_count'] = (int) ($diagCounts[$name] ?? 0);
    $rows[] = $sec;
}

$flashMessage = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
$deleteError = $_SESSION['section_delete_error'] ?? null;
unset($_SESSION['section_delete_error']);

$adminLoadStudentsCss = true;
$adminHeroIcon = 'collection';
$adminHeroTitle = 'Sections';
$adminHeroSubtitle = 'Manage the sections used for College Examination student profiles and exam audience assignments.';
$adminHeroActions = '<button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="openAddSectionBtn"><i class="bi bi-plus-lg"></i> Add Section</button>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <?php if ($flashMessage): ?>
    <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2" role="status">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h((string) $flashMessage); ?></span>
    </div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2" role="alert">
      <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($error); ?></span>
    </div>
  <?php endif; ?>

  <div class="students-page-shell">
    <div class="students-toolbar page-filter">
      <form method="get" class="students-toolbar__search">
        <div class="students-search">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" id="secQ" name="q" value="<?php echo h($q); ?>" placeholder="Search sections..." aria-label="Search sections">
        </div>
        <select id="secStatus" name="status" aria-label="Filter by status" class="admin-btn admin-btn--secondary admin-btn--sm" style="min-height:2.25rem;">
          <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
          <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
          <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-funnel"></i> Filter</button>
        <?php if ($q !== '' || $statusFilter !== 'all'): ?>
          <a href="professor_college_sections" class="students-clear-link">Clear</a>
        <?php endif; ?>
      </form>
      <span class="students-toolbar__meta"><?php echo count($rows); ?> section<?php echo count($rows) === 1 ? '' : 's'; ?></span>
    </div>

    <div class="rounded-xl page-table students-table-shell">
      <div class="students-table-meta">
        <span><?php echo count($rows); ?> section<?php echo count($rows) === 1 ? '' : 's'; ?></span>
      </div>
      <?php if ($rows === []): ?>
        <div class="students-empty-cell" style="padding:3rem 1.5rem;">
          <div class="font-semibold text-lg mb-1">No sections found</div>
          <p class="text-sm mt-1 mb-3 opacity-80">Create a section to use it when enabling College Examination access or assigning exam audiences.</p>
          <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="openAddSectionEmptyBtn"><i class="bi bi-plus-lg"></i> Add Section</button>
        </div>
      <?php else: ?>
        <div class="students-table-scroll">
          <table class="w-full text-left admin-students-table students-table--compact sections-table min-w-[640px]">
            <thead>
              <tr>
                <th scope="col">Section</th>
                <th scope="col">Status</th>
                <th scope="col">Students</th>
                <th scope="col" class="student-actions-head">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <?php
                  $sid = (int) $row['section_id'];
                  $isActive = ($row['status'] ?? '') === 'active';
                  $refs = college_sections_reference_counts($conn, (string) $row['section_name']);
                  $studentCount = (int) ($row['student_count'] ?? 0);
                  $examCount = (int) ($row['exam_count'] ?? 0);
                  $diagCount = (int) ($row['diag_count'] ?? 0);
                ?>
                <tr>
                  <td class="font-semibold"><?php echo h((string) $row['section_name']); ?></td>
                  <td>
                    <span class="commerce-pill <?php echo $isActive ? 'commerce-pill--verified' : 'commerce-pill--awaiting'; ?>">
                      <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                    </span>
                  </td>
                  <td>
                    <span class="font-semibold"><?php echo $studentCount; ?></span>
                    <?php if ($examCount > 0 || $diagCount > 0): ?>
                      <span class="section-count-meta"><?php echo $examCount; ?> exam<?php echo $examCount === 1 ? '' : 's'; ?> · <?php echo $diagCount; ?> diagnostic<?php echo $diagCount === 1 ? '' : 's'; ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="student-action-cell">
                    <div class="student-action-cluster">
                      <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm admin-btn--view js-edit-section" title="Edit section"
                              data-id="<?php echo $sid; ?>"
                              data-name="<?php echo h((string) $row['section_name']); ?>"
                              data-status="<?php echo h((string) $row['status']); ?>">
                        <i class="bi bi-pencil"></i> Edit
                      </button>
                      <div class="admin-student-action-menu-wrap" data-admin-student-action-menu>
                        <button type="button" class="admin-student-action-menu-trigger admin-student-action-menu-trigger--icon" data-action-menu-trigger aria-expanded="false" aria-haspopup="true" aria-label="More actions for <?php echo h((string) $row['section_name']); ?>">
                          <i class="bi bi-three-dots" aria-hidden="true"></i>
                        </button>
                        <div class="admin-student-action-menu" data-action-menu-list role="menu">
                          <button type="button" class="admin-student-action-item js-edit-section" role="menuitem"
                                  data-id="<?php echo $sid; ?>"
                                  data-name="<?php echo h((string) $row['section_name']); ?>"
                                  data-status="<?php echo h((string) $row['status']); ?>"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</button>
                          <button type="button" class="admin-student-action-item js-toggle-section" role="menuitem"
                                  data-id="<?php echo $sid; ?>"
                                  data-name="<?php echo h((string) $row['section_name']); ?>"
                                  data-status="<?php echo $isActive ? 'inactive' : 'active'; ?>"
                                  data-action-label="<?php echo $isActive ? 'Deactivate' : 'Activate'; ?>"><i class="bi bi-<?php echo $isActive ? 'pause-circle' : 'play-circle'; ?>" aria-hidden="true"></i> <?php echo $isActive ? 'Deactivate' : 'Activate'; ?></button>
                          <?php if (($refs['total'] ?? 0) === 0): ?>
                            <button type="button" class="admin-student-action-item admin-student-action-item--danger js-delete-section" role="menuitem"
                                    data-id="<?php echo $sid; ?>"
                                    data-name="<?php echo h((string) $row['section_name']); ?>"
                                    data-ref-students="0"
                                    data-ref-exams="0"
                                    data-ref-diag="0"><i class="bi bi-trash" aria-hidden="true"></i> Delete</button>
                          <?php else: ?>
                            <button type="button" class="admin-student-action-item js-delete-section" role="menuitem"
                                    data-id="<?php echo $sid; ?>"
                                    data-name="<?php echo h((string) $row['section_name']); ?>"
                                    data-ref-students="<?php echo (int) $refs['students']; ?>"
                                    data-ref-exams="<?php echo (int) $refs['exam_assignments']; ?>"
                                    data-ref-diag="<?php echo (int) $refs['diagnostic_assignments']; ?>"><i class="bi bi-trash" aria-hidden="true"></i> Delete</button>
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
    </div>
  </div>

  <div id="sectionFormModalOverlay" class="admin-modal-overlay" aria-hidden="true">
    <section class="admin-modal admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="sectionModalTitle">
      <form method="post" id="sectionForm">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" id="sectionFormAction" value="create">
        <input type="hidden" name="section_id" id="sectionFormId" value="">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-collection"></i></span>
          <div>
            <h3 id="sectionModalTitle" class="admin-modal__title">Add Section</h3>
            <p id="sectionModalDesc" class="admin-modal__desc">Create a centralized section used by student profiles and exam assignments.</p>
          </div>
        </div>
        <div class="admin-modal__field">
          <label for="sectionFormName">Section Name</label>
          <input type="text" id="sectionFormName" name="section_name" maxlength="100" required placeholder="e.g. Section A">
        </div>
        <div class="admin-modal__field" id="sectionFormStatusWrap">
          <label for="sectionFormStatus">Status</label>
          <select id="sectionFormStatus" name="status">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="sectionModalCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--ok" id="sectionFormSubmit">Save Section</button>
        </div>
      </form>
    </section>
  </div>

  <div id="sectionStatusModalOverlay" class="admin-modal-overlay" aria-hidden="true">
    <section class="admin-modal admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="sectionStatusTitle">
      <form method="post" id="sectionStatusForm">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="set_status">
        <input type="hidden" name="section_id" id="sectionStatusId" value="">
        <input type="hidden" name="status" id="sectionStatusValue" value="">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-toggle-on"></i></span>
          <div>
            <h3 id="sectionStatusTitle" class="admin-modal__title">Change section status</h3>
            <p class="admin-modal__desc"><span id="sectionStatusActionLabel">Deactivate</span> <strong id="sectionStatusName"></strong>?</p>
          </div>
        </div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="sectionStatusCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--ok" id="sectionStatusConfirm">Confirm</button>
        </div>
      </form>
    </section>
  </div>

  <div id="sectionDeleteModalOverlay" class="admin-modal-overlay" aria-hidden="true">
    <section class="admin-modal admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="sectionDeleteTitle">
      <form method="post" id="sectionDeleteForm">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="section_id" id="sectionDeleteId" value="">
        <input type="hidden" name="section_name" id="sectionDeleteNameHidden" value="">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-trash"></i></span>
          <div>
            <h3 id="sectionDeleteTitle" class="admin-modal__title">Delete Section?</h3>
            <p id="sectionDeleteDesc" class="admin-modal__desc"></p>
            <div id="sectionDeleteRefs" class="admin-modal__desc" hidden></div>
          </div>
        </div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="sectionDeleteCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--danger" id="sectionDeleteConfirm">Delete Section</button>
        </div>
      </form>
    </section>
  </div>

  <div id="sectionBlockedModalOverlay" class="admin-modal-overlay" aria-hidden="true">
    <section class="admin-modal admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="sectionBlockedTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-shield-exclamation"></i></span>
        <div>
          <h3 id="sectionBlockedTitle" class="admin-modal__title">Cannot delete section</h3>
          <p id="sectionBlockedDesc" class="admin-modal__desc"></p>
          <div id="sectionBlockedRefs" class="examination-ref-grid" hidden></div>
          <p class="admin-modal__desc mb-0">Deactivate this section instead to prevent new assignments while preserving historical data.</p>
        </div>
      </div>
      <div class="admin-modal__actions">
        <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="sectionBlockedClose">Close</button>
        <button type="button" class="admin-modal__btn admin-modal__btn--ok" id="sectionBlockedDeactivate">Deactivate Section</button>
      </div>
    </section>
  </div>

  <script>
  (function () {
    var pendingDeactivate = { id: '', status: 'inactive' };

    function openOverlay(id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.classList.add('is-open');
      el.setAttribute('aria-hidden', 'false');
    }
    function closeOverlay(id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.classList.remove('is-open');
      el.setAttribute('aria-hidden', 'true');
    }

    function openFormModal(mode, data) {
      var title = document.getElementById('sectionModalTitle');
      var desc = document.getElementById('sectionModalDesc');
      var actionEl = document.getElementById('sectionFormAction');
      var idEl = document.getElementById('sectionFormId');
      var nameEl = document.getElementById('sectionFormName');
      var statusWrap = document.getElementById('sectionFormStatusWrap');
      var statusEl = document.getElementById('sectionFormStatus');
      var submitBtn = document.getElementById('sectionFormSubmit');
      if (mode === 'edit') {
        if (title) title.textContent = 'Edit Section';
        if (desc) desc.textContent = 'Update the canonical section name and status.';
        if (actionEl) actionEl.value = 'update';
        if (idEl) idEl.value = String(data.id || '');
        if (nameEl) nameEl.value = data.name || '';
        if (statusEl) statusEl.value = data.status === 'inactive' ? 'inactive' : 'active';
        if (statusWrap) statusWrap.hidden = false;
        if (submitBtn) submitBtn.textContent = 'Save Changes';
      } else {
        if (title) title.textContent = 'Add Section';
        if (desc) desc.textContent = 'Create a centralized section used by student profiles and exam assignments.';
        if (actionEl) actionEl.value = 'create';
        if (idEl) idEl.value = '';
        if (nameEl) nameEl.value = '';
        if (statusEl) statusEl.value = 'active';
        if (statusWrap) statusWrap.hidden = false;
        if (submitBtn) submitBtn.textContent = 'Save Section';
      }
      openOverlay('sectionFormModalOverlay');
      if (nameEl) setTimeout(function () { nameEl.focus(); }, 40);
    }

    ['openAddSectionBtn', 'openAddSectionEmptyBtn'].forEach(function (id) {
      var btn = document.getElementById(id);
      if (btn) btn.addEventListener('click', function () { openFormModal('create'); });
    });
    document.querySelectorAll('.js-edit-section').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        openFormModal('edit', {
          id: btn.getAttribute('data-id'),
          name: btn.getAttribute('data-name'),
          status: btn.getAttribute('data-status')
        });
      });
    });

    function renderRefGrid(container, students, exams, diag) {
      if (!container) return;
      container.innerHTML =
        '<div class="examination-ref-card"><div class="examination-ref-card__value">' + students + '</div><div class="examination-ref-card__label">Students</div></div>' +
        '<div class="examination-ref-card"><div class="examination-ref-card__value">' + exams + '</div><div class="examination-ref-card__label">Exam assignments</div></div>' +
        '<div class="examination-ref-card"><div class="examination-ref-card__value">' + diag + '</div><div class="examination-ref-card__label">Diagnostic assignments</div></div>';
      container.hidden = false;
    }

    function closeAllMenus() {
      document.querySelectorAll('.admin-student-action-menu.open').forEach(function (m) { m.classList.remove('open'); });
      document.querySelectorAll('[data-admin-student-action-menu].is-open').forEach(function (w) {
        w.classList.remove('is-open');
        var t = w.querySelector('[data-action-menu-trigger]');
        if (t) t.setAttribute('aria-expanded', 'false');
      });
    }

    document.addEventListener('click', function (e) {
      var trigger = e.target && e.target.closest ? e.target.closest('[data-action-menu-trigger]') : null;
      if (!trigger) return;
      var wrap = trigger.closest('[data-admin-student-action-menu]');
      if (!wrap) return;
      var menu = wrap._adminActionMenu || wrap.querySelector('[data-action-menu-list]');
      if (!menu) return;
      e.preventDefault();
      e.stopPropagation();
      var wasOpen = menu.classList.contains('open');
      closeAllMenus();
      if (wasOpen) return;
      if (menu.parentElement !== document.body) document.body.appendChild(menu);
      wrap._adminActionMenu = menu;
      var rect = trigger.getBoundingClientRect();
      menu.style.visibility = 'hidden';
      menu.classList.add('open');
      var mw = menu.offsetWidth || 220;
      var mh = menu.offsetHeight || 280;
      menu.classList.remove('open');
      menu.style.visibility = '';
      var left = Math.min(window.innerWidth - mw - 10, Math.max(10, rect.right - mw));
      var top = rect.bottom + 6;
      if (window.innerHeight - rect.bottom < mh + 12) top = Math.max(10, rect.height ? rect.top - mh - 6 : window.innerHeight - mh - 10);
      menu.style.left = left + 'px';
      menu.style.top = top + 'px';
      menu.classList.add('open');
      wrap.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
    }, true);

    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-admin-student-action-menu]') || e.target.closest('.admin-student-action-menu')) return;
      closeAllMenus();
    });

    document.querySelectorAll('.js-toggle-section').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        document.getElementById('sectionStatusId').value = btn.getAttribute('data-id') || '';
        document.getElementById('sectionStatusValue').value = btn.getAttribute('data-status') || 'inactive';
        document.getElementById('sectionStatusName').textContent = btn.getAttribute('data-name') || 'this section';
        document.getElementById('sectionStatusActionLabel').textContent = btn.getAttribute('data-action-label') || 'Change';
        openOverlay('sectionStatusModalOverlay');
      });
    });
    var statusCancel = document.getElementById('sectionStatusCancel');
    if (statusCancel) statusCancel.addEventListener('click', function () { closeOverlay('sectionStatusModalOverlay'); });

    var cancel = document.getElementById('sectionModalCancel');
    if (cancel) cancel.addEventListener('click', function () { closeOverlay('sectionFormModalOverlay'); });

    function openDeleteModal(btn) {
      closeAllMenus();
      var name = btn.getAttribute('data-name') || 'Section';
      var students = parseInt(btn.getAttribute('data-ref-students') || '0', 10);
      var exams = parseInt(btn.getAttribute('data-ref-exams') || '0', 10);
      var diag = parseInt(btn.getAttribute('data-ref-diag') || '0', 10);
      var total = students + exams + diag;
      pendingDeactivate = { id: btn.getAttribute('data-id') || '', status: 'inactive', name: name };
      if (total > 0) {
        document.getElementById('sectionBlockedDesc').textContent = '"' + name + '" cannot be deleted because it is currently in use.';
        renderRefGrid(document.getElementById('sectionBlockedRefs'), students, exams, diag);
        openOverlay('sectionBlockedModalOverlay');
        return;
      }
      var refsEl = document.getElementById('sectionDeleteRefs');
      if (refsEl) refsEl.hidden = true;
      document.getElementById('sectionDeleteId').value = btn.getAttribute('data-id') || '';
      document.getElementById('sectionDeleteNameHidden').value = name;
      document.getElementById('sectionDeleteDesc').textContent = '"' + name + '" is not currently used anywhere. This action cannot be undone.';
      openOverlay('sectionDeleteModalOverlay');
    }
    document.querySelectorAll('.js-delete-section').forEach(function (btn) {
      btn.addEventListener('click', function () { openDeleteModal(btn); });
    });
    var deleteCancel = document.getElementById('sectionDeleteCancel');
    if (deleteCancel) deleteCancel.addEventListener('click', function () { closeOverlay('sectionDeleteModalOverlay'); });
    var blockedClose = document.getElementById('sectionBlockedClose');
    if (blockedClose) blockedClose.addEventListener('click', function () { closeOverlay('sectionBlockedModalOverlay'); });
    var blockedDeactivate = document.getElementById('sectionBlockedDeactivate');
    if (blockedDeactivate) {
      blockedDeactivate.addEventListener('click', function () {
        closeOverlay('sectionBlockedModalOverlay');
        document.getElementById('sectionStatusId').value = pendingDeactivate.id;
        document.getElementById('sectionStatusValue').value = 'inactive';
        document.getElementById('sectionStatusName').textContent = pendingDeactivate.name || 'this section';
        document.getElementById('sectionStatusActionLabel').textContent = 'Deactivate';
        openOverlay('sectionStatusModalOverlay');
      });
    }

    document.querySelectorAll('.admin-modal-overlay').forEach(function (overlay) {
      overlay.addEventListener('click', function (e) { if (e.target === overlay) closeOverlay(overlay.id); });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.admin-modal-overlay.is-open').forEach(function (el) { closeOverlay(el.id); });
        closeAllMenus();
      }
    });

    <?php if (is_array($deleteError)): ?>
    (function () {
      var err = <?php echo json_encode($deleteError); ?>;
      document.getElementById('sectionBlockedDesc').textContent = (err.message || 'Cannot delete section.') + (err.section_name ? (' "' + err.section_name + '"') : '');
      var refs = err.references || {};
      renderRefGrid(document.getElementById('sectionBlockedRefs'), refs.students || 0, refs.exam_assignments || 0, refs.diagnostic_assignments || 0);
      openOverlay('sectionBlockedModalOverlay');
    })();
    <?php endif; ?>
  })();
  </script>
</body>
</html>
