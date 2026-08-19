<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/examination_admin_bootstrap.php';
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_upload_helpers.php';
require_once dirname(__DIR__) . '/includes/college_sections.php';

$pageTitle = 'Upload tasks';
$uid = getCurrentUserId();
$csrf = generateCSRFToken();
$allowedCsv = college_upload_allowed_extensions_csv();
college_sections_ensure_schema($conn);
$sectionOptions = college_sections_active_names($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: professor_upload_tasks');
        exit;
    }
    $action = $_POST['action'] ?? '';
    $tid = sanitizeInt($_POST['task_id'] ?? 0);

    if ($action === 'delete' && $tid > 0) {
        $chk = mysqli_prepare($conn, 'SELECT task_id, title FROM college_upload_tasks WHERE task_id=? AND created_by=? LIMIT 1');
        mysqli_stmt_bind_param($chk, 'ii', $tid, $uid);
        mysqli_stmt_execute($chk);
        $ok = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        mysqli_stmt_close($chk);
        if ($ok) {
            college_upload_delete_task_files($conn, $tid, dirname(__DIR__, 2));
            mysqli_query($conn, 'DELETE FROM college_upload_resubmission_requests WHERE task_id=' . (int) $tid);
            mysqli_query($conn, 'DELETE FROM college_upload_task_sections WHERE task_id=' . (int) $tid);
            mysqli_query($conn, 'DELETE FROM college_submissions WHERE task_id=' . (int) $tid);
            mysqli_query($conn, 'DELETE FROM college_upload_tasks WHERE task_id=' . (int) $tid . ' AND created_by=' . (int) $uid);
            $_SESSION['message'] = 'Upload task permanently deleted.';
        } else {
            $_SESSION['error'] = 'Task not found or you do not have permission to delete it.';
        }
    } elseif ($action === 'save') {
        $title = trim($_POST['title'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        $openRaw = trim($_POST['open_at'] ?? '');
        $deadline = trim($_POST['deadline'] ?? '');
        $maxPreset = trim($_POST['max_file_preset'] ?? '10485760');
        $maxCustom = max(1024, sanitizeInt($_POST['max_file_size'] ?? 10485760));
        $maxSize = in_array($maxPreset, ['5242880', '10485760', '20971520', '52428800'], true)
            ? (int) $maxPreset
            : $maxCustom;
        $maxSize = max(1024, min($maxSize, 52428800));
        $isOpen = !empty($_POST['is_open']) ? 1 : 0;
        $assignmentMode = strtolower(trim((string) ($_POST['assignment_mode'] ?? 'all')));
        if (!in_array($assignmentMode, ['all', 'sections'], true)) {
            $assignmentMode = 'all';
        }
        $resubPolicy = strtolower(trim((string) ($_POST['resubmission_policy'] ?? 'disabled')));
        if (!college_upload_resubmission_policy_valid($resubPolicy)) {
            $resubPolicy = 'disabled';
        }
        // Keep legacy/canonical section names (same behavior as examination_parse_sections_from_post).
        require_once dirname(__DIR__) . '/includes/examination_assignment.php';
        $sectionsClean = examination_parse_sections_from_post($_POST);
        if ($assignmentMode !== 'sections') {
            $sectionsClean = [];
        }
        if ($assignmentMode === 'sections' && $sectionsClean === []) {
            $_SESSION['error'] = 'Select at least one section, or switch audience to All sections.';
            header('Location: professor_upload_tasks' . ($tid > 0 ? '?edit=' . $tid : ''));
            exit;
        }

        if ($title === '' || $deadline === '') {
            $_SESSION['error'] = 'Title and due/lock date are required.';
        } else {
            $deadTs = strtotime($deadline);
            if ($deadTs === false) {
                $_SESSION['error'] = 'Invalid due/lock date.';
            } else {
                $deadSql = date('Y-m-d H:i:s', $deadTs);
                $openSql = null;
                if ($openRaw !== '') {
                    $openTs = strtotime($openRaw);
                    if ($openTs === false) {
                        $_SESSION['error'] = 'Invalid open date.';
                        header('Location: professor_upload_tasks');
                        exit;
                    }
                    if ($openTs >= $deadTs) {
                        $_SESSION['error'] = 'Close time must be after the open time.';
                        header('Location: professor_upload_tasks' . ($tid > 0 ? '?edit=' . $tid : ''));
                        exit;
                    }
                    $openSql = date('Y-m-d H:i:s', $openTs);
                }
                if (!isset($_SESSION['error'])) {
                    $examineeScope = 'college_student';
                    if ($tid > 0) {
                        $stmt = mysqli_prepare($conn, 'UPDATE college_upload_tasks SET title=?, instructions=?, open_at=?, deadline=?, max_file_size=?, allowed_extensions=?, is_open=?, examinee_scope=?, assignment_mode=?, resubmission_policy=? WHERE task_id=? AND created_by=?');
                        mysqli_stmt_bind_param($stmt, 'ssssisssssii', $title, $instructions, $openSql, $deadSql, $maxSize, $allowedCsv, $isOpen, $examineeScope, $assignmentMode, $resubPolicy, $tid, $uid);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                        college_upload_save_task_sections($conn, $tid, $sectionsClean);
                    } else {
                        $stmt = mysqli_prepare($conn, 'INSERT INTO college_upload_tasks (title, instructions, open_at, deadline, max_file_size, allowed_extensions, is_open, examinee_scope, assignment_mode, resubmission_policy, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        mysqli_stmt_bind_param($stmt, 'ssssisssssi', $title, $instructions, $openSql, $deadSql, $maxSize, $allowedCsv, $isOpen, $examineeScope, $assignmentMode, $resubPolicy, $uid);
                        mysqli_stmt_execute($stmt);
                        $tid = (int) mysqli_insert_id($conn);
                        mysqli_stmt_close($stmt);
                        college_upload_save_task_sections($conn, $tid, $sectionsClean);
                    }
                    $_SESSION['message'] = $tid > 0 && sanitizeInt($_POST['task_id'] ?? 0) > 0 ? 'Upload task updated.' : 'Upload task created.';
                }
            }
        }
    }
    header('Location: professor_upload_tasks');
    exit;
}

$searchQ = trim((string) ($_GET['q'] ?? ''));

$list = [];
$q = mysqli_query($conn, '
  SELECT t.*
  FROM college_upload_tasks t
  WHERE t.created_by=' . (int) $uid . '
  ORDER BY t.deadline DESC
');
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        if ($searchQ !== '') {
            $hay = strtolower($r['title'] . ' ' . ($r['instructions'] ?? ''));
            $needle = strtolower($searchQ);
            if (strpos($hay, $needle) === false) {
                continue;
            }
        }
        $r['window_state'] = college_upload_task_window_state($r);
        $r['section_summary'] = college_upload_section_summary($conn, (int) ($r['task_id'] ?? 0), (string) ($r['assignment_mode'] ?? 'all'));
        $r['eligible_count'] = college_upload_count_eligible_students($conn, $r);
        $r['submission_count'] = college_upload_count_latest_submissions($conn, (int) ($r['task_id'] ?? 0));
        $list[] = $r;
    }
    mysqli_free_result($q);
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = sanitizeInt($_GET['edit']);
    $stmt = mysqli_prepare($conn, 'SELECT * FROM college_upload_tasks WHERE task_id=? AND created_by=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $eid, $uid);
    mysqli_stmt_execute($stmt);
    $edit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($edit) {
        $edit['task_sections'] = college_upload_load_task_sections($conn, (int) ($edit['task_id'] ?? 0));
    }
}

$editPayload = null;
if ($edit) {
    $curMax = (int) ($edit['max_file_size'] ?? 10485760);
    $presetMatch = in_array($curMax, [5242880, 10485760, 20971520, 52428800], true) ? (string) $curMax : 'custom';
    $editPayload = [
        'task_id' => (int) ($edit['task_id'] ?? 0),
        'title' => (string) ($edit['title'] ?? ''),
        'instructions' => (string) ($edit['instructions'] ?? ''),
        'open_at' => !empty($edit['open_at']) ? date('Y-m-d\TH:i', strtotime($edit['open_at'])) : '',
        'deadline' => !empty($edit['deadline']) ? date('Y-m-d\TH:i', strtotime($edit['deadline'])) : '',
        'assignment_mode' => (string) ($edit['assignment_mode'] ?? 'all'),
        'sections' => is_array($edit['task_sections'] ?? null) ? array_values($edit['task_sections']) : [],
        'resubmission_policy' => (string) ($edit['resubmission_policy'] ?? 'disabled'),
        'max_preset' => $presetMatch,
        'max_file_size' => $curMax,
        'is_open' => !empty($edit['is_open']),
    ];
}

$msg = $_SESSION['message'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['message'], $_SESSION['error']);

$totalTasks = count($list);
$openTasks = 0;
$upcomingDeadlines = 0;
$totalSubs = 0;
foreach ($list as $row) {
    if (!empty($row['is_open'])) {
        $openTasks++;
    }
    if (!empty($row['deadline']) && !college_upload_deadline_has_passed($row['deadline'])) {
        $upcomingDeadlines++;
    }
    $totalSubs += (int) ($row['submission_count'] ?? 0);
}

$pageTitle = 'Upload Tasks';
$adminHeroIcon = 'cloud-arrow-up';
$adminHeroTitle = 'Upload Tasks';
$adminHeroSubtitle = 'Manage file-upload assignments separate from examinations. Target students by section and control open/lock schedules.';
$adminHeroActions = '<button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="putNewTaskBtn"><i class="bi bi-plus-lg"></i> New task</button>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <div class="examination-page-shell">
    <div class="examination-kpi-grid mb-4">
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Total tasks</div><div class="examination-kpi-card__value"><?php echo (int) $totalTasks; ?></div></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Open tasks</div><div class="examination-kpi-card__value"><?php echo (int) $openTasks; ?></div></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Submissions</div><div class="examination-kpi-card__value"><?php echo (int) $totalSubs; ?></div></div>
      <div class="examination-kpi-card"><div class="examination-kpi-card__label">Upcoming deadlines</div><div class="examination-kpi-card__value"><?php echo (int) $upcomingDeadlines; ?></div></div>
    </div>

    <?php if ($msg): ?>
      <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?php echo h($msg); ?></span></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($err); ?></span></div>
    <?php endif; ?>

    <div class="rounded-xl overflow-hidden page-table students-table-shell">
      <div class="examination-table-card-head flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-bold m-0 flex items-center gap-2"><i class="bi bi-kanban"></i> Upload tasks</h2>
        <span class="text-xs font-bold uppercase tracking-wider opacity-60"><?php echo (int) $totalTasks; ?> listed</span>
      </div>

      <form method="get" class="students-toolbar page-filter px-4 py-3 border-b border-[var(--admin-border)]">
        <div class="students-toolbar__search flex-1 min-w-[200px]">
          <input type="search" name="q" value="<?php echo h($searchQ); ?>" placeholder="Search tasks..." autocomplete="off" class="w-full" aria-label="Search upload tasks">
        </div>
        <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-search"></i> Search</button>
        <?php if ($searchQ !== ''): ?>
          <a href="professor_upload_tasks" class="admin-btn admin-btn--ghost admin-btn--sm">Clear</a>
        <?php endif; ?>
      </form>

      <div class="students-table-scroll">
        <?php if ($totalTasks === 0): ?>
          <div class="examination-empty-box">
            <div class="inline-flex h-14 w-14 items-center justify-center rounded-2xl border text-2xl mb-4 opacity-80">
              <i class="bi bi-inbox"></i>
            </div>
            <p class="font-semibold m-0"><?php echo $searchQ !== '' ? 'No tasks match your search' : 'No upload tasks yet'; ?></p>
            <p class="text-sm mt-1 mb-4 opacity-70"><?php echo $searchQ !== '' ? 'Try another keyword or clear the search.' : 'Create a task to assign file uploads to eligible college students.'; ?></p>
            <?php if ($searchQ === ''): ?>
              <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="putEmptyNewTaskBtn"><i class="bi bi-plus-lg"></i> New task</button>
            <?php else: ?>
              <a href="professor_upload_tasks" class="admin-btn admin-btn--secondary admin-btn--sm">Clear search</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <table class="w-full text-left admin-students-table students-table--compact min-w-[880px] upload-tasks-table">
            <thead>
              <tr>
                <th scope="col">Task</th>
                <th scope="col" class="hidden md:table-cell">Audience / Section</th>
                <th scope="col" class="hidden sm:table-cell">Opens</th>
                <th scope="col" class="hidden sm:table-cell">Closes</th>
                <th scope="col" class="text-right">Submissions</th>
                <th scope="col">Status</th>
                <th scope="col" class="student-actions-head">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($list as $t):
                $state = (string) ($t['window_state'] ?? 'draft');
                $subc = (int) ($t['submission_count'] ?? 0);
                $eligible = (int) ($t['eligible_count'] ?? 0);
                $stateBadge = match ($state) {
                    'open' => 'admin-badge--success',
                    'upcoming' => 'admin-badge--info',
                    'locked' => 'admin-badge--warning',
                    default => 'admin-badge--neutral',
                };
                $tidRow = (int) ($t['task_id'] ?? 0);
                ?>
              <tr>
                <td>
                  <div class="font-bold"><?php echo h($t['title']); ?></div>
                </td>
                <td class="hidden md:table-cell text-sm">
                  <div>College Students</div>
                  <div class="text-xs opacity-70 mt-0.5"><?php echo h((string) ($t['section_summary'] ?? 'All sections')); ?></div>
                </td>
                <td class="hidden sm:table-cell text-sm whitespace-nowrap"><?php echo !empty($t['open_at']) ? h(date('M j, g:i A', strtotime($t['open_at']))) : 'Immediate'; ?></td>
                <td class="hidden sm:table-cell text-sm whitespace-nowrap"><?php echo h(date('M j, g:i A', strtotime($t['deadline']))); ?></td>
                <td class="text-right text-sm whitespace-nowrap tabular-nums"><?php echo $subc; ?> / <?php echo $eligible; ?></td>
                <td><span class="admin-badge <?php echo h($stateBadge); ?>"><?php echo h(college_upload_task_status_label($state)); ?></span></td>
                <td class="student-action-cell">
                  <div class="student-action-cluster">
                    <a href="professor_upload_task_monitor?task_id=<?php echo $tidRow; ?>" class="admin-btn admin-btn--secondary admin-btn--sm admin-btn--view"><i class="bi bi-eye"></i> View</a>
                    <div class="admin-student-action-menu-wrap" data-admin-student-action-menu>
                      <button type="button" class="admin-student-action-menu-trigger admin-student-action-menu-trigger--icon" data-action-menu-trigger aria-expanded="false" aria-haspopup="true" aria-label="More actions for <?php echo h($t['title']); ?>">
                        <i class="bi bi-three-dots" aria-hidden="true"></i>
                      </button>
                      <div class="admin-student-action-menu" data-action-menu-list role="menu">
                        <button type="button" class="admin-student-action-item js-edit-upload-task" role="menuitem"
                          data-id="<?php echo $tidRow; ?>"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</button>
                        <button type="button" class="admin-student-action-item admin-student-action-item--danger js-delete-upload-task" role="menuitem"
                          data-id="<?php echo $tidRow; ?>"
                          data-title="<?php echo h($t['title']); ?>"
                          data-submissions="<?php echo $subc; ?>"><i class="bi bi-trash" aria-hidden="true"></i> Delete</button>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div id="putTaskFormModalOverlay" class="admin-modal-overlay" aria-hidden="true">
    <section class="admin-modal admin-modal--approve admin-modal--form" role="dialog" aria-modal="true" aria-labelledby="putTaskModalTitle">
      <form method="post" id="putTaskForm" class="admin-modal-form">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="task_id" id="putTaskId" value="0">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-cloud-arrow-up"></i></span>
          <div>
            <h3 id="putTaskModalTitle" class="admin-modal__title">Create Upload Task</h3>
            <p id="putTaskModalDesc" class="admin-modal__desc">Students can submit only while the task is open between the scheduled open and close times.</p>
          </div>
        </div>
        <div class="admin-modal-form__body">
          <div class="examination-form-section">
            <h4 class="examination-form-section__title">Task information</h4>
            <div class="admin-modal__field">
              <label for="put-title">Title</label>
              <input id="put-title" type="text" name="title" required placeholder="e.g. Research paper upload">
            </div>
            <div class="admin-modal__field">
              <label for="put-inst">Instructions</label>
              <textarea id="put-inst" name="instructions" rows="3" placeholder="What should students upload?"></textarea>
            </div>
          </div>

          <div class="examination-form-section">
            <h4 class="examination-form-section__title">Target audience</h4>
            <div class="admin-modal__field">
              <label for="put-audience">Display to</label>
              <select id="put-audience" disabled>
                <option>College Students</option>
              </select>
            </div>
            <div class="examination-audience-sections">
              <div class="admin-modal__field examination-audience-sections__mode">
                <label for="put-assign-mode">Sections</label>
                <select id="put-assign-mode" name="assignment_mode">
                  <option value="all">All sections</option>
                  <option value="sections">Specific section(s)</option>
                </select>
              </div>
              <div id="put-sections-wrap" class="examination-section-picker hidden" aria-live="polite">
                <p class="examination-section-picker__label" id="put-sections-label">Select sections</p>
                <?php if ($sectionOptions === []): ?>
                  <p class="examination-section-picker__empty">No active sections. <a class="underline font-semibold" href="professor_college_sections">Create sections</a> first.</p>
                <?php else: ?>
                  <div class="examination-section-picker__scroll" tabindex="0" role="group" aria-labelledby="put-sections-label">
                    <div class="examination-section-picker__grid">
                      <?php foreach ($sectionOptions as $secOpt): ?>
                        <label class="examination-section-option">
                          <input type="checkbox" name="sections[]" value="<?php echo h($secOpt); ?>" class="put-section-cb examination-section-option__input">
                          <span class="examination-section-option__box" aria-hidden="true"></span>
                          <span class="examination-section-option__text"><?php echo h($secOpt); ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="examination-form-section">
            <h4 class="examination-form-section__title">Availability</h4>
            <div class="admin-modal__field">
              <label for="put-open">Open date/time</label>
              <input id="put-open" type="datetime-local" name="open_at">
              <p class="examination-form-hint m-0">Optional. Leave blank to open immediately when published.</p>
            </div>
            <div class="admin-modal__field">
              <label for="put-dead">Due / lock date/time</label>
              <input id="put-dead" type="datetime-local" name="deadline" required>
            </div>
          </div>

          <div class="examination-form-section">
            <h4 class="examination-form-section__title">Submission settings</h4>
            <div class="admin-modal__field">
              <label for="put-resub">Resubmission</label>
              <select id="put-resub" name="resubmission_policy">
                <option value="disabled">Disabled — one submission only</option>
                <option value="allowed">Allowed — students may resubmit while open</option>
                <option value="request_only">Request only — professor approves resubmission</option>
              </select>
            </div>
            <div class="admin-modal__field">
              <label for="put-preset">Maximum file size</label>
              <select id="put-preset" name="max_file_preset" class="put-size-preset">
                <option value="5242880">5 MB</option>
                <option value="10485760" selected>10 MB</option>
                <option value="20971520">20 MB</option>
                <option value="52428800">50 MB</option>
                <option value="custom">Custom (bytes)</option>
              </select>
              <div class="put-custom-size mt-2 hidden">
                <input type="number" name="max_file_size" id="put-max-custom" min="1024" max="52428800" value="10485760">
              </div>
              <p class="examination-form-hint m-0">Hard cap 50 MB. PDF, JPG, and PNG only (enforced server-side).</p>
            </div>
            <div class="admin-modal__field">
              <label class="flex items-center gap-2 cursor-pointer font-semibold text-sm m-0">
                <input type="checkbox" name="is_open" id="put-open-pub" value="1" class="rounded border-slate-300" checked>
                Publish to eligible students
              </label>
            </div>
          </div>
        </div>
        <div id="putFormError" class="admin-modal__error px-1" role="alert"></div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="putTaskFormCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--ok" id="putTaskFormSubmit"><i class="bi bi-check2-circle"></i> <span id="putTaskFormSubmitLabel">Create task</span></button>
        </div>
      </form>
    </section>
  </div>

  <div id="putTaskDeleteModalOverlay" class="admin-modal-overlay" aria-hidden="true">
    <section class="admin-modal admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="putDeleteTitle">
      <form method="post" id="putTaskDeleteForm">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="task_id" id="putDeleteTaskId" value="">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-trash"></i></span>
          <div>
            <h3 id="putDeleteTitle" class="admin-modal__title">Delete upload task?</h3>
            <p id="putDeleteDesc" class="admin-modal__desc"></p>
          </div>
        </div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="putDeleteCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--danger">Delete</button>
        </div>
      </form>
    </section>
  </div>

  <script>
  (function () {
    var editPayload = <?php echo json_encode($editPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var tasksById = <?php
      $taskMap = [];
      foreach ($list as $row) {
          $curMax = (int) ($row['max_file_size'] ?? 10485760);
          $presetMatch = in_array($curMax, [5242880, 10485760, 20971520, 52428800], true) ? (string) $curMax : 'custom';
          $taskMap[(int) ($row['task_id'] ?? 0)] = [
              'task_id' => (int) ($row['task_id'] ?? 0),
              'title' => (string) ($row['title'] ?? ''),
              'instructions' => (string) ($row['instructions'] ?? ''),
              'open_at' => !empty($row['open_at']) ? date('Y-m-d\TH:i', strtotime($row['open_at'])) : '',
              'deadline' => !empty($row['deadline']) ? date('Y-m-d\TH:i', strtotime($row['deadline'])) : '',
              'assignment_mode' => (string) ($row['assignment_mode'] ?? 'all'),
              'sections' => college_upload_load_task_sections($conn, (int) ($row['task_id'] ?? 0)),
              'resubmission_policy' => (string) ($row['resubmission_policy'] ?? 'disabled'),
              'max_preset' => $presetMatch,
              'max_file_size' => $curMax,
              'is_open' => !empty($row['is_open']),
          ];
      }
      echo json_encode($taskMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    ?>;

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

    function syncSizePreset() {
      var sel = document.querySelector('.put-size-preset');
      var custom = document.querySelector('.put-custom-size');
      if (sel && custom) custom.classList.toggle('hidden', sel.value !== 'custom');
    }

    function syncSectionsWrap() {
      var mode = document.getElementById('put-assign-mode');
      var wrap = document.getElementById('put-sections-wrap');
      if (mode && wrap) wrap.classList.toggle('hidden', mode.value !== 'sections');
    }

    function resetTaskForm() {
      document.getElementById('putTaskId').value = '0';
      document.getElementById('put-title').value = '';
      document.getElementById('put-inst').value = '';
      document.getElementById('put-open').value = '';
      document.getElementById('put-dead').value = '';
      document.getElementById('put-assign-mode').value = 'all';
      document.getElementById('put-resub').value = 'disabled';
      document.getElementById('put-preset').value = '10485760';
      document.getElementById('put-max-custom').value = '10485760';
      document.getElementById('put-open-pub').checked = true;
      document.querySelectorAll('.put-section-cb').forEach(function (cb) { cb.checked = false; });
      document.getElementById('putTaskModalTitle').textContent = 'Create Upload Task';
      document.getElementById('putTaskFormSubmitLabel').textContent = 'Create task';
      syncSectionsWrap();
      syncSizePreset();
    }

    function fillTaskForm(data) {
      if (!data) return;
      document.getElementById('putTaskId').value = String(data.task_id || 0);
      document.getElementById('put-title').value = data.title || '';
      document.getElementById('put-inst').value = data.instructions || '';
      document.getElementById('put-open').value = data.open_at || '';
      document.getElementById('put-dead').value = data.deadline || '';
      document.getElementById('put-assign-mode').value = data.assignment_mode === 'sections' ? 'sections' : 'all';
      document.getElementById('put-resub').value = data.resubmission_policy || 'disabled';
      document.getElementById('put-preset').value = data.max_preset || '10485760';
      document.getElementById('put-max-custom').value = String(data.max_file_size || 10485760);
      document.getElementById('put-open-pub').checked = !!data.is_open;
      var selected = Array.isArray(data.sections) ? data.sections : [];
      document.querySelectorAll('.put-section-cb').forEach(function (cb) {
        cb.checked = selected.indexOf(cb.value) !== -1;
      });
      document.getElementById('putTaskModalTitle').textContent = 'Edit Upload Task';
      document.getElementById('putTaskFormSubmitLabel').textContent = 'Save task';
      syncSectionsWrap();
      syncSizePreset();
    }

    function openCreateModal() {
      resetTaskForm();
        var errEl = document.getElementById('putFormError');
        if (errEl) errEl.textContent = '';
        openOverlay('putTaskFormModalOverlay');
      document.getElementById('put-title').focus();
    }

    function openEditModal(taskId) {
      var data = tasksById[taskId] || null;
      if (!data) {
        window.location.href = 'professor_upload_tasks?edit=' + encodeURIComponent(taskId);
        return;
      }
      fillTaskForm(data);
        var errEl = document.getElementById('putFormError');
        if (errEl) errEl.textContent = '';
        openOverlay('putTaskFormModalOverlay');
      document.getElementById('put-title').focus();
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
      if (trigger) {
        var wrap = trigger.closest('[data-admin-student-action-menu]');
        if (wrap) {
          var menu = wrap._adminActionMenu || wrap.querySelector('[data-action-menu-list]');
          if (menu) {
            e.preventDefault();
            e.stopPropagation();
            var wasOpen = menu.classList.contains('open');
            closeAllMenus();
            if (!wasOpen) {
              if (menu.parentElement !== document.body) document.body.appendChild(menu);
              wrap._adminActionMenu = menu;
              var rect = trigger.getBoundingClientRect();
              menu.style.visibility = 'hidden';
              menu.classList.add('open');
              var mw = menu.offsetWidth || 220;
              var mh = menu.offsetHeight || 200;
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
            }
            return;
          }
        }
      }
      if (!e.target.closest('[data-admin-student-action-menu]') && !e.target.closest('.admin-student-action-menu')) {
        closeAllMenus();
      }
    }, true);

    document.querySelectorAll('.js-edit-upload-task').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        openEditModal(parseInt(btn.getAttribute('data-id') || '0', 10));
      });
    });

    document.querySelectorAll('.js-delete-upload-task').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        var title = btn.getAttribute('data-title') || 'this task';
        var subs = parseInt(btn.getAttribute('data-submissions') || '0', 10);
        document.getElementById('putDeleteTaskId').value = btn.getAttribute('data-id') || '';
        document.getElementById('putDeleteDesc').innerHTML =
          'This will permanently delete <strong>' + title.replace(/</g, '&lt;') + '</strong> and all associated submissions.'
          + (subs > 0 ? ' <strong>' + subs + '</strong> student submission(s) will be removed.' : '')
          + ' This cannot be undone.';
        openOverlay('putTaskDeleteModalOverlay');
      });
    });

    var newBtn = document.getElementById('putNewTaskBtn');
    var emptyBtn = document.getElementById('putEmptyNewTaskBtn');
    if (newBtn) newBtn.addEventListener('click', openCreateModal);
    if (emptyBtn) emptyBtn.addEventListener('click', openCreateModal);

    var cancelForm = document.getElementById('putTaskFormCancel');
    if (cancelForm) cancelForm.addEventListener('click', function () { closeOverlay('putTaskFormModalOverlay'); });

    var cancelDel = document.getElementById('putDeleteCancel');
    if (cancelDel) cancelDel.addEventListener('click', function () { closeOverlay('putTaskDeleteModalOverlay'); });

    document.querySelectorAll('.admin-modal-overlay').forEach(function (overlay) {
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeOverlay(overlay.id);
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.admin-modal-overlay.is-open').forEach(function (el) { closeOverlay(el.id); });
      }
    });

    var presetSel = document.querySelector('.put-size-preset');
    if (presetSel) presetSel.addEventListener('change', syncSizePreset);
    var modeSel = document.getElementById('put-assign-mode');
    if (modeSel) modeSel.addEventListener('change', syncSectionsWrap);

    var taskForm = document.getElementById('putTaskForm');
    if (taskForm) {
      taskForm.addEventListener('submit', function (e) {
        var openVal = document.getElementById('put-open').value;
        var closeVal = document.getElementById('put-dead').value;
        if (openVal && closeVal && new Date(openVal).getTime() >= new Date(closeVal).getTime()) {
          e.preventDefault();
          var errBox = document.getElementById('putFormError');
          if (errBox) errBox.textContent = 'Close time must be after the open time.';
        }
      });
    }

    syncSectionsWrap();
    syncSizePreset();

    if (editPayload) {
      fillTaskForm(editPayload);
        var errEl = document.getElementById('putFormError');
        if (errEl) errEl.textContent = '';
        openOverlay('putTaskFormModalOverlay');
      if (window.history && window.history.replaceState) {
        window.history.replaceState({}, '', 'professor_upload_tasks');
      }
    }
  })();
  </script>
</body>
</html>
