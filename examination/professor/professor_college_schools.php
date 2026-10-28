<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_schools.php';
require_once dirname(__DIR__) . '/includes/college_school_archive.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';

$pageTitle = 'Schools';
$csrf = generateCSRFToken();
$actorId = (int) (getCurrentUserId() ?? 0);
$error = null;

if (!college_school_catalog_ready($conn)) {
    $error = 'School directory is not installed. Apply migration 055_college_schools_directory.sql.';
}

if ($error === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $res = college_schools_create($conn, (string) ($_POST['school_name'] ?? ''), $actorId, (string) ($_POST['status'] ?? 'active'));
            if (!empty($res['ok'])) {
                $_SESSION['message'] = 'School created.';
                header('Location: professor_college_schools');
                exit;
            }
            $error = (string) ($res['error'] ?? 'Could not create school.');
        } elseif ($action === 'update') {
            $res = college_schools_update(
                $conn,
                (int) ($_POST['school_id'] ?? 0),
                (string) ($_POST['school_name'] ?? ''),
                (string) ($_POST['status'] ?? 'active'),
                $actorId
            );
            if (!empty($res['ok'])) {
                $_SESSION['message'] = 'School updated.';
                header('Location: professor_college_schools');
                exit;
            }
            $error = (string) ($res['error'] ?? 'Could not update school.');
        } elseif ($action === 'set_status') {
            $res = college_schools_set_status(
                $conn,
                (int) ($_POST['school_id'] ?? 0),
                (string) ($_POST['status'] ?? 'active'),
                $actorId
            );
            if (!empty($res['ok'])) {
                $_SESSION['message'] = 'School status updated.';
                header('Location: professor_college_schools');
                exit;
            }
            $error = (string) ($res['error'] ?? 'Could not update status.');
        } elseif ($action === 'archive') {
            $res = college_schools_archive($conn, (int) ($_POST['school_id'] ?? 0), $actorId);
            if (!empty($res['ok'])) {
                $_SESSION['message'] = 'School moved to archive. It is hidden from registration and can be restored for 30 days.';
                header('Location: professor_college_schools');
                exit;
            }
            $error = (string) ($res['error'] ?? 'Could not archive school.');
        } elseif ($action === 'restore') {
            $res = college_schools_restore($conn, (int) ($_POST['school_id'] ?? 0), $actorId);
            if (!empty($res['ok'])) {
                $_SESSION['message'] = 'School restored to the directory.';
                header('Location: professor_college_schools');
                exit;
            }
            $error = (string) ($res['error'] ?? 'Could not restore school.');
        } elseif ($action === 'purge') {
            $res = college_schools_purge($conn, (int) ($_POST['school_id'] ?? 0), $actorId);
            if (!empty($res['ok'])) {
                $_SESSION['message'] = 'School permanently removed from the directory. Student records were not changed.';
                header('Location: professor_college_schools?view=archived');
                exit;
            }
            $error = (string) ($res['error'] ?? $res['reason'] ?? 'Could not delete school.');
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$view = strtolower(trim((string) (($_POST['view'] ?? '') !== '' ? $_POST['view'] : ($_GET['view'] ?? 'directory'))));
if (!in_array($view, ['directory', 'archived'], true)) {
    $view = 'directory';
}
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}

$schools = college_school_catalog_ready($conn) ? college_schools_list($conn, false, 'directory') : [];
$archivedSchools = college_school_catalog_ready($conn) ? college_schools_list($conn, false, 'archived') : [];
$studentCounts = college_school_catalog_ready($conn) ? college_schools_student_counts($conn) : [];
$isArchivedView = $view === 'archived';
$listSource = $isArchivedView ? $archivedSchools : $schools;

$rows = [];
foreach ($listSource as $sch) {
    $name = (string) $sch['school_name'];
    if ($q !== '' && stripos($name, $q) === false) {
        continue;
    }
    if (!$isArchivedView && $statusFilter !== 'all' && ($sch['status'] ?? '') !== $statusFilter) {
        continue;
    }
    $sch['student_count'] = (int) ($studentCounts[(int) $sch['school_id']] ?? 0);
    if ($isArchivedView) {
        $sch['purge_state'] = college_schools_purge_state($conn, $sch);
    }
    $rows[] = $sch;
}

$flashMessage = $_SESSION['message'] ?? null;
unset($_SESSION['message']);

$adminLoadStudentsCss = true;
$professorFeatureHero = true;
$lmsAll = count($schools);
$lmsActive = 0;
$lmsInactive = 0;
$lmsArchived = count($archivedSchools);
foreach ($schools as $__sch) {
    if (($__sch['status'] ?? '') === 'inactive') {
        $lmsInactive++;
    } else {
        $lmsActive++;
    }
}
$lmsHeroVariant = 'navy';
$adminHeroIcon = 'building';
$adminHeroEyebrow = 'Student management';
$adminHeroTitle = 'Schools';
$adminHeroSubtitle = 'Directory used by College Student Registration and roster assignment.';
$adminHeroMeta = '<span class="lms-status lms-status--info">' . $lmsAll . ' school' . ($lmsAll === 1 ? '' : 's') . '</span>';
$adminBreadcrumbs = [['Dashboard', 'professor_admin_dashboard'], ['Students', 'professor_college_students'], ['Schools']];
$adminBackHref = 'professor_college_students';
$adminBackLabel = 'Back to Students';
$adminHeroActions = $isArchivedView
    ? ''
    : '<button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="openAddSchoolBtn"><i class="bi bi-plus-lg"></i> Add School</button>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page professor-admin prof-page--schools<?php echo ereview_lms_premium_body_class(); ?>">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php $toastSuccess = ereview_lms_toast_take('success', is_string($flashMessage) ? $flashMessage : null); ?>
  <?php $toastError = ereview_lms_toast_take('error', is_string($error) ? $error : null); ?>
  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <?php if ($flashMessage && empty($toastSuccess)): ?>
    <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2" role="status">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h((string) $flashMessage); ?></span>
    </div>
  <?php endif; ?>
  <?php if ($error && empty($toastError)): ?>
    <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2" role="alert">
      <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($error); ?></span>
    </div>
  <?php endif; ?>

  <div class="students-page-shell">
    <section class="lms-kpi-row" aria-label="School summary">
      <article class="lms-kpi lms-kpi--total">
        <span class="lms-kpi__label"><i class="bi bi-building" aria-hidden="true"></i> Schools</span>
        <span class="lms-kpi__value"><?php echo (int) $lmsAll; ?></span>
        <span class="lms-kpi__hint">Directory total</span>
      </article>
      <article class="lms-kpi lms-kpi--ok">
        <span class="lms-kpi__label"><i class="bi bi-check2-circle" aria-hidden="true"></i> Active</span>
        <span class="lms-kpi__value"><?php echo (int) $lmsActive; ?></span>
        <span class="lms-kpi__hint">Shown on registration</span>
      </article>
      <article class="lms-kpi lms-kpi--wait">
        <span class="lms-kpi__label"><i class="bi bi-pause-circle" aria-hidden="true"></i> Inactive</span>
        <span class="lms-kpi__value"><?php echo (int) $lmsInactive; ?></span>
        <span class="lms-kpi__hint">Hidden from new sign-ups</span>
      </article>
      <article class="lms-kpi lms-kpi--live">
        <span class="lms-kpi__label"><i class="bi bi-archive" aria-hidden="true"></i> Archived</span>
        <span class="lms-kpi__value"><?php echo (int) $lmsArchived; ?></span>
        <span class="lms-kpi__hint">30-day recovery window</span>
      </article>
    </section>
    <div class="prof-workspace prof-workspace--registry lms-workspace">
    <div class="prof-workspace__head lms-workspace__head">
      <div class="lms-workspace__heading">
        <div class="lms-workspace__title-row">
          <h2 class="prof-workspace__title"><?php echo $isArchivedView ? 'Archived schools' : 'School directory'; ?></h2>
          <span class="prof-count lms-count-badge"><?php echo count($rows); ?> school<?php echo count($rows) === 1 ? '' : 's'; ?></span>
        </div>
        <p class="prof-workspace__sub"><?php echo $isArchivedView
            ? 'Archived schools are hidden from registration. Student associations stay intact. Permanent deletion is blocked while students remain linked.'
            : 'Active schools appear in the public College Student Registration dropdown. Legacy unmatched student text is kept.'; ?></p>
      </div>
    </div>
    <div class="students-toolbar page-filter lms-toolbar">
      <nav class="students-status-chips" aria-label="School views">
        <a href="professor_college_schools" class="students-status-chip <?php echo !$isArchivedView ? 'is-active' : ''; ?>">
          <i class="bi bi-building" aria-hidden="true"></i>
          <span>Directory</span>
          <span class="students-status-chip__count"><?php echo (int) $lmsAll; ?></span>
        </a>
        <a href="professor_college_schools?view=archived" class="students-status-chip <?php echo $isArchivedView ? 'is-active' : ''; ?>">
          <i class="bi bi-archive" aria-hidden="true"></i>
          <span>Archived</span>
          <span class="students-status-chip__count"><?php echo (int) $lmsArchived; ?></span>
        </a>
      </nav>
      <form method="get" class="students-toolbar__search">
        <?php if ($isArchivedView): ?><input type="hidden" name="view" value="archived"><?php endif; ?>
        <div class="students-search">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" id="schQ" name="q" value="<?php echo h($q); ?>" placeholder="Search schools..." aria-label="Search schools">
        </div>
        <?php if (!$isArchivedView): ?>
        <select id="schStatus" name="status" aria-label="Filter by status" class="admin-btn admin-btn--secondary admin-btn--sm">
          <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
          <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
          <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <?php endif; ?>
        <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-funnel"></i> Filter</button>
        <?php if ($q !== '' || (!$isArchivedView && $statusFilter !== 'all')): ?>
          <a href="<?php echo $isArchivedView ? 'professor_college_schools?view=archived' : 'professor_college_schools'; ?>" class="students-clear-link">Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <?php if ($rows === []): ?>
        <div class="students-empty-cell lms-empty" style="padding:3rem 1.5rem;">
          <div class="font-semibold text-lg mb-1"><?php echo $isArchivedView ? 'No archived schools' : 'No schools found'; ?></div>
          <p class="text-sm mt-1 mb-3 opacity-80"><?php echo $isArchivedView
              ? 'After 30 days, a school may become eligible for permanent deletion only when no protected dependencies exist. Deletion is not automatic.'
              : 'Add a school so College Student Registration can offer it in the School dropdown.'; ?></p>
          <?php if (!$isArchivedView): ?>
          <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="openAddSchoolEmptyBtn"><i class="bi bi-plus-lg"></i> Add School</button>
          <?php endif; ?>
        </div>
      <?php elseif ($isArchivedView): ?>
        <div class="students-table-scroll">
          <table class="w-full text-left admin-students-table students-table--compact lms-table lms-table--archived">
            <colgroup>
              <col style="width:28%">
              <col style="width:14%">
              <col style="width:16%">
              <col style="width:14%">
              <col style="width:16rem">
            </colgroup>
            <thead>
              <tr>
                <th scope="col">School</th>
                <th scope="col">Archived</th>
                <th scope="col">Deletion eligibility</th>
                <th scope="col">Days remaining</th>
                <th scope="col" class="student-actions-head">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <?php
                  $sid = (int) $row['school_id'];
                  $schName = (string) $row['school_name'];
                  $purge = is_array($row['purge_state'] ?? null) ? $row['purge_state'] : [];
                  $archTs = strtotime((string) ($row['archived_at'] ?? ''));
                  $eligTs = strtotime((string) ($row['purge_eligible_at'] ?? ''));
                  $archLabel = $archTs ? date('M j, Y', $archTs) : '—';
                  $eligLabel = $eligTs ? date('M j, Y', $eligTs) : '—';
                  $daysLeft = (int) ($purge['days_remaining'] ?? 0);
                  $canPurge = !empty($purge['eligible']);
                  $blocked = !empty($purge['blocked']);
                  $reason = (string) ($purge['reason'] ?? '');
                ?>
                <tr>
                  <td class="pcs-primary-cell" data-label="School">
                    <div class="font-semibold"><?php echo h($schName); ?></div>
                    <div class="text-xs opacity-70 mt-1"><?php echo (int) ($row['student_count'] ?? 0); ?> linked student<?php echo (int) ($row['student_count'] ?? 0) === 1 ? '' : 's'; ?></div>
                  </td>
                  <td data-label="Archived"><?php echo h($archLabel); ?></td>
                  <td data-label="Deletion eligibility"><?php echo h($eligLabel); ?></td>
                  <td data-label="Days remaining">
                    <?php if ($blocked): ?>
                      <span class="lms-status lms-status--warn">Retained</span>
                    <?php elseif ($canPurge): ?>
                      <span class="lms-status lms-status--ok">Eligible</span>
                    <?php else: ?>
                      <?php echo (int) $daysLeft; ?>
                    <?php endif; ?>
                  </td>
                  <td class="student-action-cell" data-label="Actions">
                    <div class="student-action-cluster">
                      <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm js-restore-school"
                              data-id="<?php echo $sid; ?>" data-name="<?php echo h($schName); ?>">
                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore
                      </button>
                      <div class="admin-student-action-menu-wrap" data-admin-student-action-menu>
                        <button type="button" class="admin-student-action-menu-trigger admin-student-action-menu-trigger--icon" data-action-menu-trigger aria-expanded="false" aria-haspopup="true" aria-label="More actions for <?php echo h($schName); ?>">
                          <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="admin-student-action-menu" data-action-menu-list role="menu">
                          <?php if ($canPurge): ?>
                            <button type="button" class="admin-student-action-item js-purge-school" role="menuitem"
                                    data-id="<?php echo $sid; ?>" data-name="<?php echo h($schName); ?>">Permanently delete</button>
                          <?php else: ?>
                            <button type="button" class="admin-student-action-item is-disabled" role="menuitem" disabled title="<?php echo h($reason !== '' ? $reason : 'Not eligible yet'); ?>">Permanent delete unavailable</button>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
                <?php if ($blocked && $reason !== ''): ?>
                <tr class="lms-retain-reason">
                  <td colspan="5"><p class="text-xs m-0 opacity-80 px-1 pb-2"><?php echo h($reason); ?></p></td>
                </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="students-table-scroll">
          <table class="w-full text-left admin-students-table students-table--compact lms-table">
            <colgroup>
              <col style="width:42%">
              <col style="width:16%">
              <col style="width:18%">
              <col style="width:13rem">
            </colgroup>
            <thead>
              <tr>
                <th scope="col">School</th>
                <th scope="col">Status</th>
                <th scope="col">Students</th>
                <th scope="col" class="student-actions-head">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <?php
                  $sid = (int) $row['school_id'];
                  $isActive = ($row['status'] ?? '') === 'active';
                  $schName = (string) $row['school_name'];
                  $studentCount = (int) ($row['student_count'] ?? 0);
                ?>
                <tr>
                  <td class="pcs-primary-cell" data-label="School">
                    <div class="font-semibold"><?php echo h($schName); ?></div>
                  </td>
                  <td class="pcs-badge-cell" data-label="Status">
                    <span class="<?php echo $isActive ? 'lms-status lms-status--ok' : 'lms-status lms-status--warn'; ?>">
                      <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                    </span>
                  </td>
                  <td class="pcs-meta-cell" data-label="Students"><?php echo $studentCount; ?></td>
                  <td class="student-action-cell" data-label="Actions">
                    <div class="student-action-cluster">
                      <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm js-edit-school"
                              data-id="<?php echo $sid; ?>"
                              data-name="<?php echo h($schName); ?>"
                              data-status="<?php echo h((string) $row['status']); ?>">
                        <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                      </button>
                      <div class="admin-student-action-menu-wrap" data-admin-student-action-menu>
                        <button type="button" class="admin-student-action-menu-trigger admin-student-action-menu-trigger--icon" data-action-menu-trigger aria-expanded="false" aria-haspopup="true" aria-label="More actions for <?php echo h($schName); ?>">
                          <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="admin-student-action-menu" data-action-menu-list role="menu">
                          <button type="button" class="admin-student-action-item js-toggle-school" role="menuitem"
                                  data-id="<?php echo $sid; ?>"
                                  data-name="<?php echo h($schName); ?>"
                                  data-status="<?php echo $isActive ? 'inactive' : 'active'; ?>"
                                  data-action-label="<?php echo $isActive ? 'Deactivate' : 'Activate'; ?>"><i class="bi bi-<?php echo $isActive ? 'pause-circle' : 'play-circle'; ?>" aria-hidden="true"></i> <?php echo $isActive ? 'Deactivate' : 'Activate'; ?></button>
                          <button type="button" class="admin-student-action-item js-archive-school" role="menuitem"
                                  data-id="<?php echo $sid; ?>"
                                  data-name="<?php echo h($schName); ?>"><i class="bi bi-archive" aria-hidden="true"></i> Move to Archive</button>
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

  <div id="schoolFormModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="schoolModalTitle">
      <form method="post" id="schoolForm">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" id="schoolFormAction" value="create">
        <input type="hidden" name="school_id" id="schoolFormId" value="">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-building"></i></span>
          <div>
            <h3 id="schoolModalTitle" class="admin-modal__title">Add School</h3>
            <p id="schoolModalDesc" class="admin-modal__desc">Active schools appear in College Student Registration.</p>
          </div>
        </div>
        <div class="admin-modal__field">
          <label for="schoolFormName">School name</label>
          <input type="text" id="schoolFormName" name="school_name" maxlength="150" required placeholder="e.g. Laguna State Polytechnic University">
        </div>
        <div class="admin-modal__field">
          <label for="schoolFormStatus">Status</label>
          <select id="schoolFormStatus" name="status">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="schoolModalCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--ok" id="schoolFormSubmit">Save School</button>
        </div>
      </form>
    </section>
  </div>

  <div id="schoolStatusModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="schoolStatusTitle">
      <form method="post" id="schoolStatusForm">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="set_status">
        <input type="hidden" name="school_id" id="schoolStatusId" value="">
        <input type="hidden" name="status" id="schoolStatusValue" value="">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-toggle-on"></i></span>
          <div>
            <h3 id="schoolStatusTitle" class="admin-modal__title">Change school status</h3>
            <p class="admin-modal__desc"><span id="schoolStatusActionLabel">Deactivate</span> <strong id="schoolStatusName"></strong>? Inactive schools stay on existing student records but cannot be chosen on new registrations.</p>
          </div>
        </div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="schoolStatusCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--ok" id="schoolStatusConfirm">Confirm</button>
        </div>
      </form>
    </section>
  </div>

  <div id="schoolArchiveModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="schoolArchiveTitle">
      <form method="post" id="schoolArchiveForm">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="school_id" id="schoolArchiveId" value="">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-archive"></i></span>
          <div>
            <h3 id="schoolArchiveTitle" class="admin-modal__title">Move to Archive</h3>
            <p class="admin-modal__desc">Archive <strong id="schoolArchiveName"></strong>? It will disappear from registration immediately. Student records stay linked. After 30 days, a school may become eligible for permanent deletion only when no protected dependencies exist. Deletion is not automatic.</p>
          </div>
        </div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="schoolArchiveCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--ok">Archive school</button>
        </div>
      </form>
    </section>
  </div>

  <div id="schoolRestoreModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="schoolRestoreTitle">
      <form method="post" id="schoolRestoreForm">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="view" value="archived">
        <input type="hidden" name="school_id" id="schoolRestoreId" value="">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-arrow-counterclockwise"></i></span>
          <div>
            <h3 id="schoolRestoreTitle" class="admin-modal__title">Restore school</h3>
            <p class="admin-modal__desc">Restore <strong id="schoolRestoreName"></strong> to the directory? If its saved status is Active, it will appear on registration again.</p>
          </div>
        </div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="schoolRestoreCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--ok">Restore</button>
        </div>
      </form>
    </section>
  </div>

  <div id="schoolPurgeModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="schoolPurgeTitle">
      <form method="post" id="schoolPurgeForm">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="purge">
        <input type="hidden" name="view" value="archived">
        <input type="hidden" name="school_id" id="schoolPurgeId" value="">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon"><i class="bi bi-trash"></i></span>
          <div>
            <h3 id="schoolPurgeTitle" class="admin-modal__title">Permanently delete school?</h3>
            <p class="admin-modal__desc">Remove <strong id="schoolPurgeName"></strong> from the directory. This does not delete students, exams, or scores. The server will refuse if linked records remain.</p>
          </div>
        </div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="schoolPurgeCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--ok">Delete permanently</button>
        </div>
      </form>
    </section>
  </div>

  <script>
  (function () {
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
      var mh = menu.offsetHeight || 160;
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

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeAllMenus();
        document.querySelectorAll('.admin-modal-overlay.is-open').forEach(function (el) { closeOverlay(el.id); });
      }
    });

    var tableScroll = document.querySelector('.prof-page--schools .students-table-scroll');
    if (tableScroll) tableScroll.addEventListener('scroll', closeAllMenus, { passive: true });
    window.addEventListener('resize', closeAllMenus);
    function openFormModal(mode, data) {
      var title = document.getElementById('schoolModalTitle');
      var desc = document.getElementById('schoolModalDesc');
      var actionEl = document.getElementById('schoolFormAction');
      var idEl = document.getElementById('schoolFormId');
      var nameEl = document.getElementById('schoolFormName');
      var statusEl = document.getElementById('schoolFormStatus');
      var submitBtn = document.getElementById('schoolFormSubmit');
      if (mode === 'edit') {
        if (title) title.textContent = 'Edit School';
        if (desc) desc.textContent = 'Update the school name and whether it appears on registration.';
        if (actionEl) actionEl.value = 'update';
        if (idEl) idEl.value = String(data.id || '');
        if (nameEl) nameEl.value = data.name || '';
        if (statusEl) statusEl.value = data.status === 'inactive' ? 'inactive' : 'active';
        if (submitBtn) submitBtn.textContent = 'Save Changes';
      } else {
        if (title) title.textContent = 'Add School';
        if (desc) desc.textContent = 'Active schools appear in College Student Registration.';
        if (actionEl) actionEl.value = 'create';
        if (idEl) idEl.value = '';
        if (nameEl) nameEl.value = '';
        if (statusEl) statusEl.value = 'active';
        if (submitBtn) submitBtn.textContent = 'Save School';
      }
      openOverlay('schoolFormModalOverlay');
      if (nameEl) setTimeout(function () { nameEl.focus(); }, 40);
    }
    ['openAddSchoolBtn', 'openAddSchoolEmptyBtn'].forEach(function (id) {
      var btn = document.getElementById(id);
      if (btn) btn.addEventListener('click', function () { openFormModal('create'); });
    });
    document.querySelectorAll('.js-edit-school').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        openFormModal('edit', {
          id: btn.getAttribute('data-id'),
          name: btn.getAttribute('data-name'),
          status: btn.getAttribute('data-status')
        });
      });
    });
    document.querySelectorAll('.js-toggle-school').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        var idEl = document.getElementById('schoolStatusId');
        var valEl = document.getElementById('schoolStatusValue');
        var nameEl = document.getElementById('schoolStatusName');
        var labelEl = document.getElementById('schoolStatusActionLabel');
        if (idEl) idEl.value = btn.getAttribute('data-id') || '';
        if (valEl) valEl.value = btn.getAttribute('data-status') || 'inactive';
        if (nameEl) nameEl.textContent = btn.getAttribute('data-name') || '';
        if (labelEl) labelEl.textContent = btn.getAttribute('data-action-label') || 'Update';
        openOverlay('schoolStatusModalOverlay');
      });
    });
    document.querySelectorAll('.js-archive-school').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        var idEl = document.getElementById('schoolArchiveId');
        var nameEl = document.getElementById('schoolArchiveName');
        if (idEl) idEl.value = btn.getAttribute('data-id') || '';
        if (nameEl) nameEl.textContent = btn.getAttribute('data-name') || '';
        openOverlay('schoolArchiveModalOverlay');
      });
    });
    document.querySelectorAll('.js-restore-school').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        var idEl = document.getElementById('schoolRestoreId');
        var nameEl = document.getElementById('schoolRestoreName');
        if (idEl) idEl.value = btn.getAttribute('data-id') || '';
        if (nameEl) nameEl.textContent = btn.getAttribute('data-name') || '';
        openOverlay('schoolRestoreModalOverlay');
      });
    });
    document.querySelectorAll('.js-purge-school').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        var idEl = document.getElementById('schoolPurgeId');
        var nameEl = document.getElementById('schoolPurgeName');
        if (idEl) idEl.value = btn.getAttribute('data-id') || '';
        if (nameEl) nameEl.textContent = btn.getAttribute('data-name') || '';
        openOverlay('schoolPurgeModalOverlay');
      });
    });
    var formCancel = document.getElementById('schoolModalCancel');
    if (formCancel) formCancel.addEventListener('click', function () { closeOverlay('schoolFormModalOverlay'); });
    var statusCancel = document.getElementById('schoolStatusCancel');
    if (statusCancel) statusCancel.addEventListener('click', function () { closeOverlay('schoolStatusModalOverlay'); });
    var archiveCancel = document.getElementById('schoolArchiveCancel');
    if (archiveCancel) archiveCancel.addEventListener('click', function () { closeOverlay('schoolArchiveModalOverlay'); });
    var restoreCancel = document.getElementById('schoolRestoreCancel');
    if (restoreCancel) restoreCancel.addEventListener('click', function () { closeOverlay('schoolRestoreModalOverlay'); });
    var purgeCancel = document.getElementById('schoolPurgeCancel');
    if (purgeCancel) purgeCancel.addEventListener('click', function () { closeOverlay('schoolPurgeModalOverlay'); });
    ['schoolFormModalOverlay', 'schoolStatusModalOverlay', 'schoolArchiveModalOverlay', 'schoolRestoreModalOverlay', 'schoolPurgeModalOverlay'].forEach(function (id) {
      var ov = document.getElementById(id);
      if (ov) ov.addEventListener('click', function (e) { if (e.target === ov) closeOverlay(id); });
    });
  })();
  </script>
</body>
</html>
