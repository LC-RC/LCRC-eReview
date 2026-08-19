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



  <div class="rounded-xl page-table students-table-shell">

    <div class="students-table-meta">

      <span><?php echo count($examinations); ?> examination<?php echo count($examinations) === 1 ? '' : 's'; ?></span>

    </div>

    <div class="students-table-scroll">

      <table class="w-full text-left admin-students-table students-table--compact min-w-[1180px]">

        <thead>

          <tr>

            <th scope="col">Examination</th>

            <th scope="col">Exam type</th>

            <th scope="col">Examinees</th>

            <th scope="col">Schedule</th>
            <th scope="col">Status</th>
            <th scope="col" class="text-right">Questions</th>
            <th scope="col" class="text-right">Eligible</th>
            <th scope="col" class="student-actions-head">Actions</th>

          </tr>

        </thead>

        <tbody>

        <?php if ($examinations === []): ?>

          <tr>

            <td colspan="8" class="students-empty-cell">

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

            ?>

            <tr>

              <td>

                <div class="examination-title-cell"><?php echo h($ex['title']); ?></div>

                <?php if (trim((string)($ex['description'] ?? '')) !== ''): ?>

                  <div class="examination-desc-cell"><?php echo h(mb_strimwidth((string)$ex['description'], 0, 80, '…')); ?></div>

                <?php endif; ?>

              </td>

              <td><span class="admin-badge <?php echo h($typeBadge); ?>"><?php echo h($ex['exam_type_label']); ?></span></td>

              <td><?php echo h($ex['examinee_scope_label']); ?></td>

              <td>

                <span class="examination-schedule-line">From: <?php echo h(examination_list_format_datetime($ex['available_from'] ?? null)); ?></span>

                <span class="examination-schedule-line">Until: <?php echo h(examination_list_format_datetime($ex['deadline'] ?? null)); ?></span>

                <span class="examination-schedule-line">Limit: <?php echo h(examination_list_format_time_limit((int)($ex['time_limit_seconds'] ?? 0))); ?></span>

              </td>

              <td><span class="admin-badge <?php echo h($statusBadge); ?>"><?php echo h($ex['status_label']); ?></span></td>

              <td class="text-right font-semibold"><?php echo (int)$ex['question_count']; ?></td>

              <td class="text-right font-semibold"><?php echo (int)$ex['examinee_count']; ?></td>

              <td class="student-action-cell">
                <div class="examination-list-actions student-action-cluster">
                  <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm admin-btn--view js-open-examination-edit" data-edit-url="<?php echo h(examination_list_edit_url($ex)); ?>"><i class="bi bi-pencil"></i> Edit</button>
                  <div class="admin-student-action-menu-wrap examination-more-wrap" data-admin-student-action-menu>
                    <button type="button" class="admin-student-action-menu-trigger admin-student-action-menu-trigger--icon" data-action-menu-trigger aria-expanded="false" aria-haspopup="true" aria-label="More actions for <?php echo h($ex['title']); ?>">
                      <i class="bi bi-three-dots" aria-hidden="true"></i>
                    </button>
                    <div class="admin-student-action-menu" data-action-menu-list role="menu">
                      <a role="menuitem" class="admin-student-action-item" href="<?php echo h(examination_list_questions_url($ex)); ?>"><i class="bi bi-question-circle" aria-hidden="true"></i> Questions</a>
                      <a role="menuitem" class="admin-student-action-item" href="<?php echo h(examination_list_monitor_url($ex)); ?>"><i class="bi bi-graph-up" aria-hidden="true"></i> Monitor</a>
                      <a role="menuitem" class="admin-student-action-item" href="<?php echo h(examination_domain_edit_url((string)($ex['exam_type'] ?? 'regular'), (int)($ex['source_id'] ?? 0), 'review')); ?>"><i class="bi bi-check2-square" aria-hidden="true"></i> Review / Publish</a>
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
})();
</script>
</body>

</html>

