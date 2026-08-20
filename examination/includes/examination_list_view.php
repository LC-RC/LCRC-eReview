<?php

/** @var array $examinations */

/** @var array $counts */

/** @var string $statusFilter */

/** @var string $typeFilter */

/** @var string $examineeFilter */

/** @var string $searchQ */



if (!function_exists('examination_list_format_datetime')) {

    function examination_list_format_datetime(?string $raw): string

    {

        if ($raw === null || trim($raw) === '' || preg_match('/^0000-00-00/', $raw)) {

            return '—';

        }

        $ts = strtotime($raw);



        return $ts !== false ? date('M j, Y g:i A', $ts) : '—';

    }

}



if (!function_exists('examination_list_format_time_limit')) {

    function examination_list_format_time_limit(int $seconds): string

    {

        if ($seconds <= 0) {

            return '—';

        }

        $hours = intdiv($seconds, 3600);

        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0 && $minutes > 0) {

            return $hours . 'h ' . $minutes . 'm';

        }

        if ($hours > 0) {

            return $hours . 'h';

        }



        return $minutes . 'm';

    }

}



if (!function_exists('examination_list_edit_url')) {

    function examination_list_edit_url(array $ex): string

    {

        if (($ex['exam_type'] ?? '') === 'diagnostic') {

            return 'professor_examination_edit?exam_type=diagnostic&batch_id=' . (int)($ex['source_id'] ?? 0) . '&modal=1';

        }



        return 'professor_examination_edit?exam_type=regular&exam_id=' . (int)($ex['source_id'] ?? 0) . '&modal=1';

    }

}



if (!function_exists('examination_list_monitor_url')) {

    function examination_list_monitor_url(array $ex): string

    {

        if (($ex['exam_type'] ?? '') === 'diagnostic') {

            return 'professor_examination_monitor?exam_type=diagnostic&batch_id=' . (int)($ex['source_id'] ?? 0);

        }



        return 'professor_examination_monitor?exam_type=regular&exam_id=' . (int)($ex['source_id'] ?? 0);

    }

}



if (!function_exists('examination_list_questions_url')) {
    function examination_list_questions_url(array $ex): string
    {
        $type = (string)($ex['exam_type'] ?? 'regular');
        $id = (int)($ex['source_id'] ?? 0);

        return examination_domain_edit_url($type, $id, 'questions');
    }
}




$pageTitle = 'Examinations';

$adminLoadStudentsCss = true;
$adminHeroIcon = 'journal-text';

$adminHeroTitle = 'Examinations';

$adminHeroSubtitle = 'Create, configure, and manage regular and diagnostic examinations.';

$adminHeroActions = '<button type="button" class="admin-btn admin-btn--primary admin-btn--sm js-open-examination-edit" data-edit-url="professor_examination_edit?modal=1"><i class="bi bi-plus-lg"></i> New Examination</button>';



$statusTabs = ['all' => 'All', 'draft' => 'Draft', 'published' => 'Published', 'finished' => 'Finished'];

?>

<!DOCTYPE html>

<html lang="en">

<head>

  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>

</head>

<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">

<?php include dirname(__DIR__) . '/professor/professor_admin_sidebar.php'; ?>



<?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

<?php if (!empty($flashMessage)): ?>
  <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2">
    <i class="bi bi-check-circle-fill"></i><span><?php echo h($flashMessage); ?></span>
  </div>
<?php endif; ?>
<?php if (!empty($flashError)): ?>
  <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($flashError); ?></span>
  </div>
<?php endif; ?>

<div class="examination-page-shell">

  <nav class="students-view-tabs" aria-label="Examination status">

    <?php foreach ($statusTabs as $key => $label):

        $tabQs = http_build_query(array_filter([

            'status' => $key === 'all' ? null : $key,

            'exam_type' => $typeFilter !== '' ? $typeFilter : null,

            'examinee_type' => $examineeFilter !== '' ? $examineeFilter : null,

            'q' => $searchQ !== '' ? $searchQ : null,

        ]));

    ?>

      <a href="professor_examinations?<?php echo h($tabQs); ?>" class="students-view-tab <?php echo $statusFilter === $key ? 'is-active' : ''; ?>">

        <?php echo h($label); ?> (<?php echo (int)($counts[$key] ?? 0); ?>)

      </a>

    <?php endforeach; ?>

  </nav>



  <div class="students-toolbar page-filter">

    <form method="get" class="students-toolbar__search">

      <?php if ($statusFilter !== 'all'): ?>

        <input type="hidden" name="status" value="<?php echo h($statusFilter); ?>">

      <?php endif; ?>

      <select name="exam_type" aria-label="Exam type" class="admin-btn admin-btn--secondary admin-btn--sm" style="min-height:2.25rem;">

        <option value="">All types</option>

        <option value="regular" <?php echo $typeFilter === 'regular' ? 'selected' : ''; ?>>Regular</option>

        <option value="diagnostic" <?php echo $typeFilter === 'diagnostic' ? 'selected' : ''; ?>>Diagnostic</option>

      </select>

      <select name="examinee_type" aria-label="Examinee type" class="admin-btn admin-btn--secondary admin-btn--sm" style="min-height:2.25rem;">

        <option value="">All examinees</option>

        <option value="college_student" <?php echo $examineeFilter === 'college_student' ? 'selected' : ''; ?>>College Student</option>

        <option value="reviewee" <?php echo $examineeFilter === 'reviewee' ? 'selected' : ''; ?>>Reviewee</option>

        <option value="both" <?php echo $examineeFilter === 'both' ? 'selected' : ''; ?>>Both</option>

      </select>

      <div class="students-search">

        <i class="bi bi-search" aria-hidden="true"></i>

        <input type="search" name="q" value="<?php echo h($searchQ); ?>" placeholder="Search by title..." aria-label="Search examinations">

      </div>

      <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-funnel"></i> Filter</button>

      <?php if ($typeFilter !== '' || $examineeFilter !== '' || $searchQ !== ''): ?>

        <a href="professor_examinations<?php echo $statusFilter !== 'all' ? '?status=' . rawurlencode($statusFilter) : ''; ?>" class="students-clear-link">Clear</a>

      <?php endif; ?>

    </form>

    <span class="students-toolbar__meta"><?php echo count($examinations); ?> shown</span>

  </div>

  <div id="examBulkBar" class="students-bulk-bar" aria-live="polite">
    <span class="students-bulk-bar__count"><span id="examBulkCount">0</span> selected</span>
    <div class="students-bulk-bar__actions">
      <button type="button" id="examBulkClearBtn" class="admin-modal__btn admin-modal__btn--ghost">Clear</button>
      <button type="button" id="examBulkDeleteBtn" class="admin-modal__btn admin-modal__btn--danger"><i class="bi bi-trash"></i> Delete selected</button>
    </div>
  </div>

  <div class="rounded-xl page-table students-table-shell">

    <div class="students-table-meta">

      <span><?php echo count($examinations); ?> examination<?php echo count($examinations) === 1 ? '' : 's'; ?></span>

    </div>

    <div class="students-table-scroll">

      <table class="w-full text-left admin-students-table students-table--compact examinations-list-table pex-list-table">
        <colgroup>
          <col style="width:3rem">
          <col style="width:26%">
          <col style="width:11%">
          <col style="width:12%">
          <col style="width:18%">
          <col style="width:9%">
          <col style="width:7%">
          <col style="width:7%">
          <col style="width:9.5rem">
        </colgroup>
        <thead>

          <tr>

            <th class="student-select-col" scope="col">
              <input type="checkbox" id="examSelectAll" class="admin-bulk-check" title="Select all deletable examinations" aria-label="Select all deletable examinations">
            </th>

            <th scope="col">Examination</th>

            <th scope="col" class="col-exam-type">Exam type</th>

            <th scope="col" class="col-examinees">Examinees</th>

            <th scope="col" class="col-schedule">Schedule</th>
            <th scope="col">Status</th>
            <th scope="col" class="text-right col-counts">Questions</th>
            <th scope="col" class="text-right col-counts">Eligible</th>
            <th scope="col" class="student-actions-head">Actions</th>

          </tr>

        </thead>

        <tbody>

        <?php if ($examinations === []): ?>

          <tr>

            <td colspan="9" class="students-empty-cell">

              <div class="font-semibold">No examinations found</div>

              <p class="text-sm mt-1 mb-0">Try changing filters or <button type="button" class="students-clear-link js-open-examination-edit" data-edit-url="professor_examination_edit?modal=1">create an examination</button>.</p>

            </td>

          </tr>

        <?php else: ?>

          <?php foreach ($examinations as $ex): ?>

            <?php

              $windowState = (string)($ex['window_state'] ?? '');

              $statusBadge = match ($windowState) {

                  'finished' => 'admin-badge--neutral',

                  'open', 'scheduled' => 'admin-badge--info',

                  default => 'admin-badge--warning',

              };

              $typeBadge = ($ex['exam_type'] ?? '') === 'diagnostic' ? 'admin-badge--info' : 'admin-badge--success';
              $examTypeKey = (string)($ex['exam_type'] ?? 'regular');
              $sourceId = (int)($ex['source_id'] ?? 0);
              $examKey = $examTypeKey . ':' . $sourceId;
              $canDelete = empty($ex['is_running']);

            ?>

            <tr data-exam-key="<?php echo h($examKey); ?>">

              <td class="student-select-col" data-label="Select">
                <input type="checkbox"
                       class="js-exam-select admin-bulk-check"
                       value="<?php echo h($examKey); ?>"
                       aria-label="Select <?php echo h((string)($ex['title'] ?? '')); ?>"
                       data-exam-title="<?php echo h((string)($ex['title'] ?? '')); ?>"
                       data-exam-type="<?php echo h($examTypeKey); ?>"
                       data-source-id="<?php echo $sourceId; ?>"
                       <?php echo $canDelete ? '' : 'disabled'; ?>
                       <?php if ($canDelete): ?>data-deletable="1"<?php endif; ?>>
              </td>

              <td class="pcs-exam-title-cell pcs-primary-cell" data-label="Examination">

                <div class="examination-title-cell" title="<?php echo h($ex['title']); ?>"><?php echo h($ex['title']); ?></div>

                <?php if (trim((string)($ex['description'] ?? '')) !== ''): ?>

                  <div class="examination-desc-cell"><?php echo h(mb_strimwidth((string)$ex['description'], 0, 80, '…')); ?></div>

                <?php endif; ?>

              </td>

              <td class="col-exam-type pcs-badge-cell" data-label="Exam type"><span class="admin-badge <?php echo h($typeBadge); ?>"><?php echo h($ex['exam_type_label']); ?></span></td>

              <td class="col-examinees pcs-meta-cell" data-label="Examinees"><?php echo h($ex['examinee_scope_label']); ?></td>

              <td class="col-schedule pcs-meta-cell" data-label="Schedule">

                <span class="examination-schedule-line">From: <?php echo h(examination_list_format_datetime($ex['available_from'] ?? null)); ?></span>

                <span class="examination-schedule-line">Until: <?php echo h(examination_list_format_datetime($ex['deadline'] ?? null)); ?></span>

                <span class="examination-schedule-line">Limit: <?php echo h(examination_list_format_time_limit((int)($ex['time_limit_seconds'] ?? 0))); ?></span>

              </td>

              <td class="pcs-badge-cell" data-label="Status"><span class="admin-badge <?php echo h($statusBadge); ?>"><?php echo h($ex['status_label']); ?></span></td>

              <td class="text-right font-semibold col-counts" data-label="Questions"><?php echo (int)$ex['question_count']; ?></td>

              <td class="text-right font-semibold col-counts" data-label="Eligible"><?php echo (int)$ex['examinee_count']; ?></td>

              <td class="student-action-cell" data-label="Actions">
                <div class="examination-list-actions student-action-cluster">
                  <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm admin-btn--view js-open-examination-edit" data-edit-url="<?php echo h(examination_list_edit_url($ex)); ?>"><i class="bi bi-pencil"></i> Edit</button>
                  <div class="admin-student-action-menu-wrap examination-more-wrap" data-admin-student-action-menu>
                    <button type="button" class="admin-student-action-menu-trigger admin-student-action-menu-trigger--icon" data-action-menu-trigger aria-expanded="false" aria-haspopup="true" aria-label="More actions for <?php echo h($ex['title']); ?>">
                      <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                    </button>
                    <div class="admin-student-action-menu" data-action-menu-list role="menu">
                      <a role="menuitem" class="admin-student-action-item" href="<?php echo h(examination_list_questions_url($ex)); ?>"><i class="bi bi-question-circle" aria-hidden="true"></i> Questions</a>
                      <a role="menuitem" class="admin-student-action-item" href="<?php echo h(examination_list_monitor_url($ex)); ?>"><i class="bi bi-graph-up" aria-hidden="true"></i> Monitor</a>
                      <a role="menuitem" class="admin-student-action-item" href="<?php echo h(examination_domain_edit_url((string)($ex['exam_type'] ?? 'regular'), (int)($ex['source_id'] ?? 0), 'review')); ?>"><i class="bi bi-check2-square" aria-hidden="true"></i> Review / Publish</a>
                      <button type="button"
                              role="menuitem"
                              class="admin-student-action-item js-duplicate-exam"
                              data-exam-type="<?php echo h($examTypeKey); ?>"
                              data-source-id="<?php echo $sourceId; ?>"
                              data-exam-title="<?php echo h((string)($ex['title'] ?? '')); ?>">
                        <i class="bi bi-files" aria-hidden="true"></i> Duplicate
                      </button>
                      <div class="examination-action-menu-sep" role="separator"></div>
                      <?php if ($canDelete): ?>
                        <button type="button"
                                role="menuitem"
                                class="admin-student-action-item admin-student-action-item--danger js-open-delete-exam"
                                data-exam-key="<?php echo h($examKey); ?>"
                                data-exam-type="<?php echo h($examTypeKey); ?>"
                                data-source-id="<?php echo $sourceId; ?>"
                                data-exam-title="<?php echo h((string)($ex['title'] ?? '')); ?>">
                          <i class="bi bi-trash" aria-hidden="true"></i> Delete
                        </button>
                      <?php else: ?>
                        <button type="button"
                                role="menuitem"
                                class="admin-student-action-item admin-student-action-item--danger is-disabled"
                                disabled
                                title="Cannot delete while this examination is running">
                          <i class="bi bi-trash" aria-hidden="true"></i> Delete (running)
                        </button>
                      <?php endif; ?>
                    </div>
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

</div>

<form method="post" action="professor_examinations" id="deleteExamForm" class="hidden" aria-hidden="true">
  <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken ?? generateCSRFToken()); ?>">
  <input type="hidden" name="action" id="deleteExamAction" value="delete">
  <input type="hidden" name="exam_type" id="deleteExamType" value="">
  <input type="hidden" name="source_id" id="deleteExamSourceId" value="">
  <input type="hidden" name="return_status" value="<?php echo h($statusFilter); ?>">
  <input type="hidden" name="return_exam_type" value="<?php echo h($typeFilter); ?>">
  <input type="hidden" name="return_examinee_type" value="<?php echo h($examineeFilter); ?>">
  <input type="hidden" name="return_q" value="<?php echo h($searchQ); ?>">
  <div id="deleteExamKeysMount"></div>
</form>

<form method="post" action="professor_examinations" id="duplicateExamForm" class="hidden" aria-hidden="true">
  <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken ?? generateCSRFToken()); ?>">
  <input type="hidden" name="action" value="duplicate">
  <input type="hidden" name="exam_type" id="duplicateExamType" value="">
  <input type="hidden" name="source_id" id="duplicateExamSourceId" value="">
  <input type="hidden" name="return_status" value="<?php echo h($statusFilter); ?>">
  <input type="hidden" name="return_exam_type" value="<?php echo h($typeFilter); ?>">
  <input type="hidden" name="return_examinee_type" value="<?php echo h($examineeFilter); ?>">
  <input type="hidden" name="return_q" value="<?php echo h($searchQ); ?>">
</form>

<div class="admin-modal-overlay" id="deleteExamModal" aria-hidden="true">
  <section class="admin-modal admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="deleteExamTitle">
    <div class="admin-modal__hero">
      <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-trash"></i></span>
      <div>
        <h3 id="deleteExamTitle" class="admin-modal__title">Delete examination?</h3>
        <p class="admin-modal__desc" id="deleteExamDesc">This permanently deletes <strong id="deleteExamNameDisplay"></strong>, including questions and attempts. This cannot be undone.</p>
      </div>
    </div>
    <div class="admin-modal__actions">
      <button type="button" class="admin-btn admin-btn--secondary" id="deleteExamCancel">Cancel</button>
      <button type="button" class="admin-btn admin-btn--danger" id="deleteExamConfirm">Delete</button>
    </div>
  </section>
</div>

<div id="examinationEditModalOverlay" class="admin-modal-overlay" aria-hidden="true">
  <div id="examinationEditModalMount" class="examination-edit-modal-mount"></div>
</div>

<script>
(function () {
  var overlay = document.getElementById('examinationEditModalOverlay');
  var mount = document.getElementById('examinationEditModalMount');
  var currentEditUrl = '';

  function runInjectedScripts(container) {
    container.querySelectorAll('script').forEach(function (oldScript) {
      var script = document.createElement('script');
      if (oldScript.src) {
        script.src = oldScript.src;
      } else {
        script.textContent = oldScript.textContent;
      }
      oldScript.replaceWith(script);
    });
  }

  function bindModalCancel() {
    if (!mount) return;
    mount.querySelectorAll('[data-exam-edit-cancel]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        closeExamEditModal();
      });
    });
  }

  function openOverlay() {
    if (!overlay) return;
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('examination-edit-modal-open');
  }

  function closeExamEditModal() {
    if (!overlay || !mount) return;
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
    mount.innerHTML = '';
    currentEditUrl = '';
    document.body.classList.remove('examination-edit-modal-open');
  }

  function openExamEditModal(url) {
    if (!overlay || !mount || !url) return;
    currentEditUrl = url;
    mount.innerHTML = '<div class="admin-modal admin-modal--examination-edit examination-edit-modal-loading"><div class="p-6 text-center opacity-70">Loading examination editor…</div></div>';
    openOverlay();
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (res) {
        if (!res.ok) throw new Error('Could not load editor.');
        return res.text();
      })
      .then(function (html) {
        mount.innerHTML = html;
        runInjectedScripts(mount);
        bindModalCancel();
      })
      .catch(function () {
        mount.innerHTML = '<div class="admin-modal admin-modal--examination-edit"><div class="p-6"><p class="m-0">Could not load the examination editor.</p><button type="button" class="admin-btn admin-btn--secondary admin-btn--sm mt-3" data-exam-edit-cancel>Close</button></div></div>';
        bindModalCancel();
      });
  }

  window.examinationEditOpenUrl = openExamEditModal;
  window.examinationEditModalReload = function (scope) {
    if (!currentEditUrl) return;
    var base = currentEditUrl.split('?')[0];
    var params = new URLSearchParams(currentEditUrl.split('?')[1] || '');
    if (scope) params.set('examinee_scope', scope);
    openExamEditModal(base + '?' + params.toString());
  };

  document.querySelectorAll('.js-open-examination-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openExamEditModal(btn.getAttribute('data-edit-url') || '');
    });
  });

  if (overlay) {
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeExamEditModal();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay && overlay.classList.contains('is-open')) {
      closeExamEditModal();
    }
  });

  <?php if (!empty($openEditUrl)): ?>
  openExamEditModal(<?php echo json_encode($openEditUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);
  if (window.history && window.history.replaceState) {
    window.history.replaceState({}, '', 'professor_examinations');
  }
  <?php endif; ?>

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
    if (window.innerHeight - rect.bottom < mh + 12) top = Math.max(10, rect.top - mh - 6);
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
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAllMenus(); });

  // Bulk select + delete
  var selectAll = document.getElementById('examSelectAll');
  var bulkBar = document.getElementById('examBulkBar');
  var bulkCount = document.getElementById('examBulkCount');
  var bulkClearBtn = document.getElementById('examBulkClearBtn');
  var bulkDeleteBtn = document.getElementById('examBulkDeleteBtn');
  var deleteModal = document.getElementById('deleteExamModal');
  var deleteForm = document.getElementById('deleteExamForm');
  var deleteAction = document.getElementById('deleteExamAction');
  var deleteType = document.getElementById('deleteExamType');
  var deleteSourceId = document.getElementById('deleteExamSourceId');
  var deleteKeysMount = document.getElementById('deleteExamKeysMount');
  var deleteTitle = document.getElementById('deleteExamTitle');
  var deleteDesc = document.getElementById('deleteExamDesc');
  var deleteNameDisplay = document.getElementById('deleteExamNameDisplay');
  var deleteCancel = document.getElementById('deleteExamCancel');
  var deleteConfirm = document.getElementById('deleteExamConfirm');
  var pendingBulkKeys = [];

  function deletableChecks() {
    return Array.prototype.slice.call(document.querySelectorAll('.js-exam-select[data-deletable="1"]'));
  }

  function selectedChecks() {
    return deletableChecks().filter(function (cb) { return cb.checked; });
  }

  function syncBulkBar() {
    var selected = selectedChecks();
    var n = selected.length;
    if (bulkCount) bulkCount.textContent = String(n);
    if (bulkBar) {
      if (n > 0) bulkBar.classList.add('is-visible');
      else bulkBar.classList.remove('is-visible');
    }
    if (selectAll) {
      var all = deletableChecks();
      selectAll.checked = all.length > 0 && selected.length === all.length;
      selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
    }
  }

  function openDeleteModal(mode, payload) {
    if (!deleteModal) return;
    pendingBulkKeys = [];
    if (mode === 'bulk') {
      pendingBulkKeys = payload.keys || [];
      if (deleteTitle) deleteTitle.textContent = 'Delete selected examinations?';
      if (deleteDesc) {
        deleteDesc.innerHTML = 'This permanently deletes <strong id="deleteExamNameDisplay">' +
          pendingBulkKeys.length + ' examination(s)</strong>, including questions and attempts. This cannot be undone.';
      }
    } else {
      if (deleteTitle) deleteTitle.textContent = 'Delete examination?';
      if (deleteDesc) {
        deleteDesc.innerHTML = 'This permanently deletes <strong id="deleteExamNameDisplay"></strong>, including questions and attempts. This cannot be undone.';
        var nested = document.getElementById('deleteExamNameDisplay');
        if (nested) nested.textContent = payload.title || 'this examination';
      }
      if (deleteType) deleteType.value = payload.examType || '';
      if (deleteSourceId) deleteSourceId.value = String(payload.sourceId || '');
    }
    deleteModal.dataset.mode = mode;
    deleteModal.classList.add('is-open');
    deleteModal.setAttribute('aria-hidden', 'false');
  }

  function closeDeleteModal() {
    if (!deleteModal) return;
    deleteModal.classList.remove('is-open');
    deleteModal.setAttribute('aria-hidden', 'true');
    pendingBulkKeys = [];
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      deletableChecks().forEach(function (cb) { cb.checked = selectAll.checked; });
      syncBulkBar();
    });
  }
  document.querySelectorAll('.js-exam-select').forEach(function (cb) {
    cb.addEventListener('change', syncBulkBar);
  });
  if (bulkClearBtn) {
    bulkClearBtn.addEventListener('click', function () {
      deletableChecks().forEach(function (cb) { cb.checked = false; });
      syncBulkBar();
    });
  }
  if (bulkDeleteBtn) {
    bulkDeleteBtn.addEventListener('click', function () {
      var keys = selectedChecks().map(function (cb) { return cb.value; });
      if (!keys.length) return;
      openDeleteModal('bulk', { keys: keys });
    });
  }
  document.querySelectorAll('.js-open-delete-exam').forEach(function (btn) {
    btn.addEventListener('click', function () {
      closeAllMenus();
      openDeleteModal('single', {
        examType: btn.getAttribute('data-exam-type') || '',
        sourceId: parseInt(btn.getAttribute('data-source-id') || '0', 10) || 0,
        title: btn.getAttribute('data-exam-title') || 'this examination'
      });
    });
  });
  if (deleteCancel) deleteCancel.addEventListener('click', closeDeleteModal);
  if (deleteModal) {
    deleteModal.addEventListener('click', function (e) {
      if (e.target === deleteModal) closeDeleteModal();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && deleteModal && deleteModal.classList.contains('is-open')) {
      closeDeleteModal();
    }
  });
  if (deleteConfirm && deleteForm) {
    deleteConfirm.addEventListener('click', function () {
      var mode = deleteModal ? deleteModal.dataset.mode : 'single';
      if (deleteKeysMount) deleteKeysMount.innerHTML = '';
      if (mode === 'bulk') {
        if (deleteAction) deleteAction.value = 'bulk_delete';
        if (deleteType) deleteType.value = '';
        if (deleteSourceId) deleteSourceId.value = '';
        pendingBulkKeys.forEach(function (key) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'exam_keys[]';
          input.value = key;
          if (deleteKeysMount) deleteKeysMount.appendChild(input);
        });
      } else {
        if (deleteAction) deleteAction.value = 'delete';
      }
      deleteForm.submit();
    });
  }

  var duplicateForm = document.getElementById('duplicateExamForm');
  var duplicateType = document.getElementById('duplicateExamType');
  var duplicateSourceId = document.getElementById('duplicateExamSourceId');
  document.querySelectorAll('.js-duplicate-exam').forEach(function (btn) {
    btn.addEventListener('click', function () {
      closeAllMenus();
      if (!duplicateForm) return;
      var examType = btn.getAttribute('data-exam-type') || '';
      var sourceId = parseInt(btn.getAttribute('data-source-id') || '0', 10) || 0;
      var title = btn.getAttribute('data-exam-title') || 'this examination';
      if (!examType || sourceId <= 0) return;
      if (!window.confirm('Duplicate "' + title + '" including all questions?\n\nA new draft copy will be created.')) {
        return;
      }
      if (duplicateType) duplicateType.value = examType;
      if (duplicateSourceId) duplicateSourceId.value = String(sourceId);
      btn.disabled = true;
      duplicateForm.submit();
    });
  });

  syncBulkBar();
})();
</script>
</body>

</html>

