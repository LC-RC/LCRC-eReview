<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_sections.php';
require_once dirname(__DIR__, 2) . '/includes/profile_avatar.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
require_once dirname(__DIR__, 2) . '/includes/url_helpers.php';
require_once dirname(__DIR__) . '/includes/college_school_assignment.php';
require_once dirname(__DIR__) . '/includes/college_schools.php';
require_once dirname(__DIR__) . '/includes/college_student_number_display.php';

$pageTitle = 'Students';
$csrf = generateCSRFToken();
college_sections_ensure_schema($conn);

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$sectionFilter = trim((string) ($_GET['section'] ?? ''));
$schoolFilter = trim((string) ($_GET['school'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'created_desc');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPageRaw = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($perPageRaw, [10, 25, 50, 100], true) ? $perPageRaw : 25;

$validStatus = ['all', 'archived'];
$validSort = ['created_desc', 'created_asc', 'name_asc', 'name_desc'];
if (!in_array($statusFilter, $validStatus, true)) {
    $statusFilter = 'all';
}
if (!in_array($sort, $validSort, true)) {
    $sort = 'created_desc';
}

$isStudentTab = true;
$reviewTypeSql = 'undergrad';

$sectionOptions = college_sections_active_names($conn);

function pcs_build_list_where(
    string $reviewType,
    string $statusFilter,
    string $search,
    string $sectionFilter,
    string $schoolFilter,
    bool $isStudentTab,
    bool $platformColsReady,
    bool $schoolAssignmentReady
): array {
    // Roster = native college accounts OR eReview students already granted exam access
    // (active/suspended). "Not enabled" LMS students stay on admin_students only.
    $where = ['review_type=?'];
    $types = 's';
    $params = [$reviewType];
    if ($platformColsReady) {
        $where[] = "(role='college_student' OR (role='student' AND college_examination_access IN ('active','suspended')))";
    } else {
        $where[] = "role='college_student'";
    }

    if ($statusFilter === 'all') {
        $where[] = "status<>'archived'";
    } elseif ($statusFilter !== '') {
        $where[] = 'status=?';
        $types .= 's';
        $params[] = $statusFilter;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(full_name LIKE ? OR email LIKE ? OR section LIKE ? OR student_number LIKE ? OR school LIKE ?)';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($isStudentTab && $sectionFilter !== '') {
        if ($sectionFilter === '__none__') {
            $where[] = "(section IS NULL OR TRIM(section)='')";
        } else {
            $where[] = 'TRIM(section)=?';
            $types .= 's';
            $params[] = $sectionFilter;
        }
    }
    if ($schoolFilter !== '') {
        if ($schoolFilter === '__unmapped__') {
            if ($schoolAssignmentReady) {
                $where[] = '(college_school_assigned_at IS NULL OR TRIM(COALESCE(school,\'\'))=\'\')';
            }
        } else {
            if ($schoolAssignmentReady) {
                $where[] = 'college_school_assigned_at IS NOT NULL AND TRIM(school)=?';
                $types .= 's';
                $params[] = $schoolFilter;
            } else {
                $where[] = '1=0';
            }
        }
    }

    return [$where, $types, $params];
}

function pcs_sort_sql(string $sort): string
{
    return match ($sort) {
        'created_asc' => 'created_at ASC, user_id ASC',
        'name_asc' => 'full_name ASC, user_id ASC',
        'name_desc' => 'full_name DESC, user_id DESC',
        default => 'created_at DESC, user_id DESC',
    };
}

$platformColsReady = ereview_platform_access_columns_ready($conn);
$schoolAssignmentReady = college_school_assignment_schema_ready($conn);
[$whereParts, $whereTypes, $whereParams] = pcs_build_list_where(
    $reviewTypeSql,
    $statusFilter,
    $search,
    $sectionFilter,
    $schoolFilter,
    $isStudentTab,
    $platformColsReady,
    $schoolAssignmentReady
);
$whereSql = implode(' AND ', $whereParts);

$statsStudents = ['total' => 0, 'archived' => 0];
$statsRosterSql = $platformColsReady
    ? "(role='college_student' OR (role='student' AND college_examination_access IN ('active','suspended')))"
    : "role='college_student'";
$statsQ = mysqli_query(
    $conn,
    "SELECT status, COUNT(*) AS c
     FROM users
     WHERE review_type='undergrad' AND {$statsRosterSql}
     GROUP BY status"
);
if ($statsQ) {
    while ($sr = mysqli_fetch_assoc($statsQ)) {
        $st = (string) ($sr['status'] ?? '');
        $c = (int) ($sr['c'] ?? 0);
        if ($st === 'archived') {
            $statsStudents['archived'] += $c;
        } else {
            $statsStudents['total'] += $c;
        }
    }
    mysqli_free_result($statsQ);
}

if ($sectionOptions === []) {
    $legacyQ = mysqli_query(
        $conn,
        "SELECT DISTINCT TRIM(section) AS sec FROM users
         WHERE review_type='undergrad' AND section IS NOT NULL AND TRIM(section) <> ''
         ORDER BY sec ASC"
    );
    if ($legacyQ) {
        while ($lr = mysqli_fetch_assoc($legacyQ)) {
            $sec = trim((string) ($lr['sec'] ?? ''));
            if ($sec !== '') {
                $sectionOptions[] = $sec;
            }
        }
        mysqli_free_result($legacyQ);
    }
}

$schoolOptions = [];
$schoolOptSql = $platformColsReady
    ? "(role='college_student' OR (role='student' AND college_examination_access IN ('active','suspended')))"
    : "role='college_student'";
$schoolAssignedSql = ' AND college_school_assigned_at IS NOT NULL AND school IS NOT NULL AND TRIM(school) <> \'\'';
if ($schoolAssignmentReady) {
    $schoolOptSt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT TRIM(school) AS school_label
         FROM users
         WHERE review_type=? AND {$schoolOptSql}
           {$schoolAssignedSql}
         ORDER BY school_label ASC"
    );
    if ($schoolOptSt) {
        mysqli_stmt_bind_param($schoolOptSt, 's', $reviewTypeSql);
        mysqli_stmt_execute($schoolOptSt);
        $schoolRes = mysqli_stmt_get_result($schoolOptSt);
        while ($sr = mysqli_fetch_assoc($schoolRes)) {
            $label = trim((string) ($sr['school_label'] ?? ''));
            if ($label !== '') {
                $schoolOptions[] = $label;
            }
        }
        mysqli_stmt_close($schoolOptSt);
    }
}

$countSt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM users WHERE {$whereSql}");
$total = 0;
if ($countSt) {
    mysqli_stmt_bind_param($countSt, $whereTypes, ...$whereParams);
    mysqli_stmt_execute($countSt);
    $countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($countSt));
    $total = (int) ($countRow['c'] ?? 0);
    mysqli_stmt_close($countSt);
}

$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$list = [];
$schoolSelectCols = $schoolAssignmentReady ? ', college_school_assigned_at, college_school_assigned_by' : '';
$listSql = "SELECT user_id, full_name, email, status, created_at, section, school, student_number, profile_picture, use_default_avatar, review_type, role, college_examination_access{$schoolSelectCols}
            FROM users WHERE {$whereSql} ORDER BY " . pcs_sort_sql($sort) . ' LIMIT ? OFFSET ?';
$listSt = mysqli_prepare($conn, $listSql);
if ($listSt) {
    $listTypes = $whereTypes . 'ii';
    $listParams = array_merge($whereParams, [$perPage, $offset]);
    mysqli_stmt_bind_param($listSt, $listTypes, ...$listParams);
    mysqli_stmt_execute($listSt);
    $lres = mysqli_stmt_get_result($listSt);
    while ($r = mysqli_fetch_assoc($lres)) {
        $list[] = $r;
    }
    mysqli_stmt_close($listSt);
}

$activeStats = $statsStudents;

function students_page_query(array $overrides = []): string
{
    global $search, $statusFilter, $sectionFilter, $schoolFilter, $sort, $page, $perPage;
    $params = array_merge([
        'q' => $search !== '' ? $search : null,
        'status' => $statusFilter !== 'all' ? $statusFilter : null,
        'section' => $sectionFilter !== '' ? $sectionFilter : null,
        'school' => $schoolFilter !== '' ? $schoolFilter : null,
        'sort' => $sort !== 'created_desc' ? $sort : null,
        'page' => $page > 1 ? $page : null,
        'per_page' => $perPage !== 25 ? $perPage : null,
    ], $overrides);

    return '?' . http_build_query(array_filter($params, static fn ($v) => $v !== null && $v !== ''));
}

$flashMessage = $_SESSION['message'] ?? null;
unset($_SESSION['message']);

$adminLoadStudentsCss = true;
$professorFeatureHero = true;
$adminHeroIcon = 'people';
$adminHeroEyebrow = 'Student management';
$adminHeroTitle = 'Students';
$adminHeroSubtitle = 'Manage College Examination students.';
$lmsHeroVariant = 'navy';
$adminHeroMeta = '<span class="lms-status lms-status--info">' . (int) $statsStudents['total'] . ' student' . ((int) $statsStudents['total'] === 1 ? '' : 's') . '</span>';
$adminBreadcrumbs = [['Dashboard', 'professor_admin_dashboard'], ['Students']];
$adminHeroActions = '<a class="admin-btn admin-btn--primary admin-btn--sm rounded-xl" href="professor_create_college_student"><i class="bi bi-person-plus"></i> Register Student</a>'
    . '<a class="admin-btn admin-btn--secondary admin-btn--sm rounded-xl" href="professor_college_schools"><i class="bi bi-building"></i> Manage Schools</a>';

$statusChipMeta = [
    'all' => ['All', 'bi-collection', (int) $activeStats['total']],
    'archived' => ['Archived', 'bi-archive', (int) ($activeStats['archived'] ?? 0)],
];

$hasActiveFilters = ($search !== '' || $sectionFilter !== '' || $schoolFilter !== '' || $sort !== 'created_desc' || $statusFilter !== 'all');
$schoolFilterLabel = $schoolFilter === '__unmapped__'
    ? 'Unassigned (no staff-assigned school)'
    : $schoolFilter;
$catalogSchoolRows = $schoolAssignmentReady ? college_schools_list($conn, false) : [];
$rangeStart = $total > 0 ? $offset + 1 : 0;
$rangeEnd = min($offset + $perPage, $total);
$apiUrl = ereview_url('professor_college_students_api');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page professor-admin prof-page--students<?php echo ereview_lms_premium_body_class(); ?>">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php $toastSuccess = ereview_lms_toast_take('success', is_string($flashMessage) ? $flashMessage : null); ?>
  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <?php if ($flashMessage !== null && $flashMessage !== '' && empty($toastSuccess)): ?>
    <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2" role="status">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h((string) $flashMessage); ?></span>
    </div>
  <?php endif; ?>

  <div id="pcsFlash" class="admin-flash" role="status" hidden></div>

  <div class="students-page-shell">
    <section class="lms-kpi-row" aria-label="Student summary">
      <article class="lms-kpi lms-kpi--total">
        <span class="lms-kpi__label"><i class="bi bi-mortarboard" aria-hidden="true"></i> Students</span>
        <span class="lms-kpi__value"><?php echo (int) $statsStudents['total']; ?></span>
        <span class="lms-kpi__hint">College Examination roster</span>
      </article>
      <article class="lms-kpi lms-kpi--wait">
        <span class="lms-kpi__label"><i class="bi bi-archive" aria-hidden="true"></i> Archived</span>
        <span class="lms-kpi__value"><?php echo (int) $statsStudents['archived']; ?></span>
        <span class="lms-kpi__hint">Hidden from the active roster</span>
      </article>
    </section>
    <section class="prof-workspace prof-workspace--roster lms-workspace">
    <div class="lms-workspace__chrome">
    <div class="prof-workspace__head lms-workspace__head">
      <div class="lms-workspace__heading">
        <div class="lms-workspace__title-row">
          <h2 class="prof-workspace__title">Students</h2>
          <span class="prof-count lms-count-badge"><?php echo (int) $total; ?> student<?php echo (int) $total === 1 ? '' : 's'; ?></span>
        </div>
        <p class="prof-workspace__sub">College Examination roster, sections, and account status.</p>
      </div>
    </div>
    </div>
    <div class="students-toolbar page-filter lms-toolbar">
      <nav class="students-status-chips" aria-label="Filter by status">
        <?php foreach ($statusChipMeta as $key => $meta): ?>
          <a href="<?php echo h(students_page_query(['status' => $key === 'all' ? null : $key, 'page' => null])); ?>" class="students-status-chip <?php echo $statusFilter === $key ? 'is-active' : ''; ?>">
            <i class="bi <?php echo h($meta[1]); ?>" aria-hidden="true"></i>
            <span><?php echo h($meta[0]); ?></span>
            <span class="students-status-chip__count"><?php echo (int) $meta[2]; ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
      <form method="get" class="students-toolbar__search">
        <?php if ($statusFilter !== 'all'): ?>
          <input type="hidden" name="status" value="<?php echo h($statusFilter); ?>">
        <?php endif; ?>
        <div class="students-search">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" id="searchQ" name="q" value="<?php echo h($search); ?>" placeholder="Name, email, College Exam ID, section, school" aria-label="Search accounts">
        </div>
        <?php if ($isStudentTab): ?>
          <select name="section" aria-label="Filter by section" class="admin-btn admin-btn--secondary admin-btn--sm">
            <option value="">All sections</option>
            <option value="__none__" <?php echo $sectionFilter === '__none__' ? 'selected' : ''; ?>>No section</option>
            <?php foreach ($sectionOptions as $sec): ?>
              <option value="<?php echo h($sec); ?>" <?php echo $sectionFilter === $sec ? 'selected' : ''; ?>><?php echo h($sec); ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <select name="school" aria-label="Filter by exact school" class="admin-btn admin-btn--secondary admin-btn--sm">
          <option value="">All schools</option>
          <option value="__unmapped__" <?php echo $schoolFilter === '__unmapped__' ? 'selected' : ''; ?>>Unassigned (not staff-assigned)</option>
          <?php foreach ($schoolOptions as $sch): ?>
            <option value="<?php echo h($sch); ?>" <?php echo ($schoolFilter !== '__unmapped__' && $schoolFilter === $sch) ? 'selected' : ''; ?>><?php echo h($sch); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="sort" aria-label="Sort order" class="admin-btn admin-btn--secondary admin-btn--sm">
          <option value="created_desc" <?php echo $sort === 'created_desc' ? 'selected' : ''; ?>>Recently created</option>
          <option value="created_asc" <?php echo $sort === 'created_asc' ? 'selected' : ''; ?>>Oldest created</option>
          <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A–Z</option>
          <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name Z–A</option>
        </select>
        <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-funnel"></i> Filter</button>
        <?php if ($hasActiveFilters): ?>
          <a href="<?php echo h(students_page_query(['q' => null, 'section' => null, 'school' => null, 'sort' => null, 'status' => null, 'page' => null])); ?>" class="students-clear-link">Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <div id="pcsBulkBar" class="students-bulk-bar<?php echo $isStudentTab ? '' : ' hidden'; ?>" aria-live="polite">
      <span class="students-bulk-bar__count"><span id="pcsBulkCount">0</span> selected</span>
      <span id="pcsBulkAllHint" class="students-bulk-bar__hint" hidden></span>
      <div class="students-bulk-bar__actions">
        <button type="button" id="pcsBulkClearBtn" class="admin-btn admin-btn--secondary admin-btn--sm">Clear</button>
        <?php if ($isStudentTab): ?>
          <button type="button" id="pcsBulkApproveBtn" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-check2-circle"></i> Approve</button>
          <button type="button" id="pcsBulkEnableBtn" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-clipboard-check"></i> Enable College Examination</button>
          <button type="button" id="pcsBulkSectionBtn" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-collection"></i> Assign Section</button>
          <button type="button" id="pcsBulkAssignSchoolBtn" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-building"></i> Assign school</button>
          <button type="button" id="pcsBulkActivateBtn" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-unlock"></i> Activate exam access</button>
          <button type="button" id="pcsBulkDeactivateBtn" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-slash-circle"></i> Deactivate exam access</button>
          <button type="button" id="pcsBulkRollbackBtn" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-arrow-counterclockwise"></i> Restore operation</button>
          <button type="button" id="pcsBulkDisableBtn" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-slash-circle"></i> Suspend LMS exam access</button>
          <button type="button" id="pcsBulkArchiveBtn" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-archive"></i> Archive</button>
          <button type="button" id="pcsBulkRestoreBtn" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
          <button type="button" id="pcsBulkRemoveBtn" class="admin-btn admin-btn--danger admin-btn--sm"><i class="bi bi-trash"></i> Remove / Delete</button>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($schoolFilter !== ''): ?>
      <div class="admin-flash admin-flash--info mb-3 p-3 rounded-xl flex items-start gap-2" role="status">
        <i class="bi bi-building" aria-hidden="true"></i>
        <div>
          <strong>School filter (exact staff-assigned name):</strong> <?php echo h($schoolFilterLabel); ?>
          <span class="block text-sm opacity-80 mt-1">This filter only changes who is listed. It does not change exam access. Legacy school text is not treated as assigned. Activate/deactivate still requires checked students — the school dropdown is not a bulk target.<?php echo $schoolAssignmentReady ? '' : ' Apply migration 053 to enable staff assignment.'; ?></span>
        </div>
      </div>
    <?php endif; ?>

    <div class="rounded-2xl page-table students-table-shell lms-table-wrap overflow-hidden">
      <div class="students-table-meta">
        <span>
          <?php if ($total > 0): ?>
            Showing <?php echo (int) $rangeStart; ?>–<?php echo (int) $rangeEnd; ?> of <?php echo (int) $total; ?> students
          <?php else: ?>
            0 students
          <?php endif; ?>
        </span>
        <form method="get" class="flex items-center gap-2 text-sm">
          <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?php echo h($search); ?>"><?php endif; ?>
          <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?php echo h($statusFilter); ?>"><?php endif; ?>
          <?php if ($isStudentTab && $sectionFilter !== ''): ?><input type="hidden" name="section" value="<?php echo h($sectionFilter); ?>"><?php endif; ?>
          <?php if ($schoolFilter !== ''): ?><input type="hidden" name="school" value="<?php echo h($schoolFilter); ?>"><?php endif; ?>
          <?php if ($sort !== 'created_desc'): ?><input type="hidden" name="sort" value="<?php echo h($sort); ?>"><?php endif; ?>
          <label for="pcsPerPage" class="student-meta whitespace-nowrap">Rows per page</label>
          <select id="pcsPerPage" name="per_page" class="admin-btn admin-btn--secondary admin-btn--sm" style="min-height:2rem;" onchange="this.form.submit()">
            <?php foreach ([10, 25, 50, 100] as $opt): ?>
              <option value="<?php echo $opt; ?>" <?php echo $perPage === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <?php if ($total === 0): ?>
        <div class="students-empty-cell lms-empty" style="padding:3rem 1.5rem;">
          <div class="lms-empty__title font-semibold text-lg mb-1">
            <?php echo $hasActiveFilters ? 'No students found' : 'No College Examination students yet'; ?>
          </div>
          <p class="lms-empty__text text-sm mt-1 mb-3 opacity-80">
            <?php if ($hasActiveFilters): ?>
              Try adjusting your search or filters.
            <?php else: ?>
              Add examinee accounts to assign them to examinations.
            <?php endif; ?>
          </p>
          <div class="flex flex-wrap gap-2 justify-center">
            <?php if ($hasActiveFilters): ?>
              <a href="<?php echo h(students_page_query(['q' => null, 'section' => null, 'school' => null, 'sort' => null, 'status' => null, 'page' => null])); ?>" class="admin-btn admin-btn--secondary admin-btn--sm">Clear filters</a>
            <?php endif; ?>
            <a href="professor_create_college_student" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-person-plus"></i> Register Student</a>
          </div>
        </div>
      <?php else: ?>
        <div class="students-table-scroll">
          <table class="w-full text-left admin-students-table students-table--compact pcs-students-table pex-list-table lms-table">
            <colgroup>
              <?php if ($isStudentTab): ?>
                <col class="pcs-col-check" style="width:3rem">
                <col class="pcs-col-account" style="width:34%">
                <col class="pcs-col-exam" style="width:12%">
                <col class="pcs-col-section" style="width:12%">
                <col class="pcs-col-status" style="width:10%">
                <col class="pcs-col-created" style="width:11%">
                <col class="pcs-col-actions" style="width:9.5rem">
              <?php else: ?>
                <col class="pcs-col-account" style="width:48%">
                <col class="pcs-col-status" style="width:14%">
                <col class="pcs-col-created" style="width:16%">
                <col class="pcs-col-actions" style="width:9.5rem">
              <?php endif; ?>
            </colgroup>
            <thead>
              <tr>
                <?php if ($isStudentTab): ?>
                <th class="student-select-col" scope="col">
                  <input type="checkbox" id="pcsSelectAll" class="admin-bulk-check" title="Select all visible students on this page" aria-label="Select all visible students on this page">
                </th>
                <?php endif; ?>
                <th scope="col" class="college-student-account-head">Account</th>
                <?php if ($isStudentTab): ?>
                  <th scope="col" class="pcs-col-exam">Exam access</th>
                  <th scope="col" class="pcs-col-section">Section</th>
                <?php endif; ?>
                <th scope="col" class="pcs-col-status">Status</th>
                <th scope="col" class="pcs-col-created">Created</th>
                <th scope="col" class="student-actions-head">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($list as $u): ?>
                <?php
                  $uid = (int) ($u['user_id'] ?? 0);
                  $avatarImgSrc = ereview_avatar_img_src((string) ($u['profile_picture'] ?? ''));
                  $useDefault = !empty($u['use_default_avatar']);
                  $initial = ereview_avatar_initial((string) ($u['full_name'] ?? ''));
                  $sectionTxt = trim((string) ($u['section'] ?? ''));
                  $emailTxt = trim((string) ($u['email'] ?? ''));
                  $role = (string) ($u['role'] ?? '');
                  $statusLower = strtolower((string) ($u['status'] ?? ''));
                  $collExAccessVal = function_exists('ereview_user_college_examination_access_value')
                      ? ereview_user_college_examination_access_value($u)
                      : 'none';
                  $hasCollEx = ereview_user_has_college_examination_access($conn, $uid, $u);
                  $canEnable = ($role === 'student' && !$hasCollEx && $collExAccessVal !== 'suspended' && $statusLower !== 'rejected' && $statusLower !== 'archived');
                  $canAssignSection = ($isStudentTab && (
                      $hasCollEx
                      || ($role === 'college_student' && in_array($statusLower, ['pending', 'approved'], true))
                  ));
                  $schoolTxt = trim((string) ($u['school'] ?? ''));
                  $schoolMapped = $schoolAssignmentReady
                      ? college_school_row_is_staff_assigned($u)
                      : false;
                  $canDisable = ($role === 'student' && $hasCollEx);
                  $canDeactivateExam = $statusLower !== 'archived' && $schoolMapped && (
                      ($role === 'college_student' && $collExAccessVal !== 'suspended')
                      || ($role === 'student' && $hasCollEx)
                  );
                  $canActivateExam = $statusLower !== 'archived' && $schoolMapped && $collExAccessVal === 'suspended';
                  // LMS-linked: remove exam access only. Native college: hard-delete account.
                  $canRemoveFromExam = ($statusLower !== 'archived' && $role === 'student' && in_array($collExAccessVal, ['active', 'suspended'], true));
                  $canDeleteNative = ($statusLower !== 'archived' && $role === 'college_student');
                  $canArchiveAccount = ($statusLower !== 'archived');
                  $canRestoreAccount = ($statusLower === 'archived');
                  $canRowDelete = ($canRemoveFromExam || $canDeleteNative);
                  $canApprove = ($role === 'college_student' && in_array($statusLower, ['pending', 'rejected'], true));
                  $approveNeedsSection = ($canApprove && $sectionTxt === '');
                  $statusBadge = match ($statusLower) {
                      'approved' => 'lms-status lms-status--ok',
                      'pending' => 'lms-status lms-status--warn',
                      'rejected' => 'lms-status',
                      'archived' => 'lms-status',
                      default => 'lms-status lms-status--info',
                  };
                  $createdFmt = !empty($u['created_at']) ? date('M j, Y', strtotime((string) $u['created_at'])) : '—';
                ?>
                <tr data-user-id="<?php echo $uid; ?>"<?php if ($canApprove): ?> data-approvable="1" data-has-section="<?php echo $sectionTxt !== '' ? '1' : '0'; ?>" data-student-name="<?php echo h((string) ($u['full_name'] ?? '')); ?>"<?php endif; ?>>
                  <?php if ($isStudentTab): ?>
                  <td class="student-select-col" data-label="Select">
                    <input type="checkbox"
                           class="js-pcs-select admin-bulk-check"
                           value="<?php echo $uid; ?>"
                           aria-label="Select <?php echo h((string) ($u['full_name'] ?? '')); ?>"
                           data-student-name="<?php echo h((string) ($u['full_name'] ?? '')); ?>"
                           <?php if ($canEnable): ?>data-enableable="1"<?php endif; ?>
                           <?php if ($canAssignSection): ?>data-sectionable="1"<?php endif; ?>
                           <?php if ($canDisable): ?>data-disableable="1"<?php endif; ?>
                           <?php if ($canActivateExam): ?>data-exam-activatable="1"<?php endif; ?>
                           <?php if ($canDeactivateExam): ?>data-exam-deactivatable="1"<?php endif; ?>
                           <?php if ($schoolMapped): ?>data-school-mapped="1"<?php endif; ?>
                           data-legacy-school="<?php echo h($schoolTxt); ?>"
                           data-school-assignable="1"
                           <?php if ($canRemoveFromExam): ?>data-removable="1"<?php endif; ?>
                           <?php if ($canDeleteNative): ?>data-deletable="1"<?php endif; ?>
                           <?php if ($canArchiveAccount): ?>data-archivable="1"<?php endif; ?>
                           <?php if ($canRestoreAccount): ?>data-restorable="1"<?php endif; ?>
                           <?php if ($canApprove): ?>data-approvable="1" data-has-section="<?php echo $sectionTxt !== '' ? '1' : '0'; ?>"<?php endif; ?>>
                  </td>
                  <?php endif; ?>
                  <td class="college-student-account-cell" data-label="Account">
                    <div class="student-cell college-student-account">
                      <span class="student-avatar-cell" aria-hidden="true">
                        <span class="student-avatar-media">
                          <?php if ($avatarImgSrc !== '' && !$useDefault): ?>
                            <img src="<?php echo h($avatarImgSrc); ?>" alt="" loading="lazy" decoding="async"
                                 onerror="this.hidden=true; var s=this.nextElementSibling; if(s){s.hidden=false;}">
                            <span class="student-avatar-initial" hidden><?php echo h($initial); ?></span>
                          <?php else: ?>
                            <span class="student-avatar-initial"><?php echo h($initial); ?></span>
                          <?php endif; ?>
                        </span>
                      </span>
                      <div class="student-cell__text">
                        <span class="student-name" title="<?php echo h((string) ($u['full_name'] ?? '')); ?>"><?php echo h((string) ($u['full_name'] ?? '')); ?></span>
                        <?php if ($emailTxt !== ''): ?>
                          <a href="mailto:<?php echo h($emailTxt); ?>" class="pcs-account-email" title="<?php echo h($emailTxt); ?>"><?php echo h($emailTxt); ?></a>
                        <?php endif; ?>
                        <?php echo college_exam_id_chip_html(trim((string) ($u['student_number'] ?? '')), ['copy' => true, 'compact' => true]); ?>
                        <?php if ($schoolMapped): ?>
                          <span class="admin-badge admin-badge--info" title="Staff-assigned school">Assigned: <?php echo h($schoolTxt); ?></span>
                        <?php else: ?>
                          <span class="admin-badge admin-badge--neutral" title="Not staff-assigned">Unassigned</span>
                          <?php if ($schoolTxt !== ''): ?>
                            <span class="student-meta">Legacy text: <?php echo h($schoolTxt); ?> (not verified)</span>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <?php if ($isStudentTab): ?>
                    <td class="pcs-col-exam" data-label="Exam access">
                      <?php if ($role === 'college_student' && $statusLower === 'pending'): ?>
                        <span class="commerce-pill commerce-pill--awaiting"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Pending approval</span>
                      <?php elseif ($role === 'college_student' && $statusLower === 'rejected'): ?>
                        <span class="commerce-pill commerce-pill--awaiting"><i class="bi bi-x-circle" aria-hidden="true"></i> Rejected</span>
                      <?php elseif ($hasCollEx): ?>
                        <span class="commerce-pill commerce-pill--verified"><i class="bi bi-check2-circle" aria-hidden="true"></i> Active</span>
                      <?php elseif ($collExAccessVal === 'suspended'): ?>
                        <span class="commerce-pill commerce-pill--awaiting"><i class="bi bi-slash-circle" aria-hidden="true"></i> Suspended</span>
                      <?php else: ?>
                        <span class="commerce-pill commerce-pill--awaiting"><i class="bi bi-dash-circle" aria-hidden="true"></i> Not enabled</span>
                      <?php endif; ?>
                    </td>
                    <td class="pcs-col-section" data-label="Section">
                      <?php if ($role === 'college_student'): ?>
                        <?php if ($sectionTxt !== ''): ?>
                          <span class="admin-badge admin-badge--info"><?php echo h($sectionTxt); ?></span>
                        <?php else: ?>
                          <span class="student-meta">Not set</span>
                        <?php endif; ?>
                      <?php elseif (!$hasCollEx && $collExAccessVal !== 'suspended'): ?>
                        <span class="student-meta">Not enabled</span>
                      <?php elseif ($sectionTxt !== ''): ?>
                        <span class="admin-badge admin-badge--info"><?php echo h($sectionTxt); ?></span>
                      <?php else: ?>
                        <span class="student-meta">Not set</span>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                  <td class="pcs-col-status" data-label="Status"><span class="<?php echo h($statusBadge); ?>"><?php echo h(ucfirst($statusLower)); ?></span></td>
                  <td class="pcs-col-created" data-label="Created"><span class="student-meta"><?php echo h($createdFmt); ?></span></td>
                  <td class="student-action-cell" data-label="Actions">
                    <div class="student-action-cluster">
                      <?php if ($canApprove): ?>
                        <button type="button"
                                class="admin-btn admin-btn--primary admin-btn--sm js-pcs-approve-one"
                                data-user-id="<?php echo $uid; ?>"
                                data-student-name="<?php echo h((string) ($u['full_name'] ?? '')); ?>"
                                data-has-section="<?php echo $sectionTxt !== '' ? '1' : '0'; ?>"
                                data-section="<?php echo h($sectionTxt); ?>"
                                title="<?php echo $approveNeedsSection ? 'Choose a section, then approve' : 'Approve so the student can sign in'; ?>">
                          <i class="bi bi-check2-circle" aria-hidden="true"></i>
                          <?php echo $approveNeedsSection ? 'Approve &amp; set section' : 'Approve'; ?>
                        </button>
                      <?php endif; ?>
                      <a class="admin-btn admin-btn--secondary admin-btn--sm admin-btn--view" href="professor_college_student_view?id=<?php echo $uid; ?>"><i class="bi bi-eye" aria-hidden="true"></i> View</a>
                      <div class="admin-student-action-menu-wrap" data-admin-student-action-menu>
                        <button type="button" class="admin-student-action-menu-trigger admin-student-action-menu-trigger--icon" data-action-menu-trigger aria-expanded="false" aria-haspopup="true" aria-label="More actions for <?php echo h((string) ($u['full_name'] ?? '')); ?>">
                          <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="admin-student-action-menu" data-action-menu-list role="menu">
                          <a role="menuitem" class="admin-student-action-item" href="professor_college_student_view?id=<?php echo $uid; ?>"><i class="bi bi-eye" aria-hidden="true"></i> View student</a>
                          <?php if ($canApprove): ?>
                            <button type="button" class="admin-student-action-item js-pcs-approve-one" role="menuitem" data-user-id="<?php echo $uid; ?>" data-student-name="<?php echo h((string) ($u['full_name'] ?? '')); ?>" data-has-section="<?php echo $sectionTxt !== '' ? '1' : '0'; ?>" data-section="<?php echo h($sectionTxt); ?>"><i class="bi bi-check2-circle" aria-hidden="true"></i> <?php echo $approveNeedsSection ? 'Approve & set section' : 'Approve account'; ?></button>
                          <?php endif; ?>
                          <a role="menuitem" class="admin-student-action-item" href="professor_college_student_view?id=<?php echo $uid; ?>#college-examination"><i class="bi bi-sliders" aria-hidden="true"></i> Manage access</a>
                          <button type="button" class="admin-student-action-item js-pcs-assign-school-one" role="menuitem" data-user-id="<?php echo $uid; ?>" data-legacy-school="<?php echo h($schoolTxt); ?>"><i class="bi bi-building" aria-hidden="true"></i> Assign school</button>
                          <?php if ($canEnable): ?>
                            <button type="button" class="admin-student-action-item js-pcs-enable-one" role="menuitem" data-user-id="<?php echo $uid; ?>" data-student-name="<?php echo h((string) ($u['full_name'] ?? '')); ?>"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Enable College Examination</button>
                          <?php endif; ?>
                          <?php if ($canActivateExam): ?>
                            <button type="button" class="admin-student-action-item js-pcs-activate-one" role="menuitem" data-user-id="<?php echo $uid; ?>"><i class="bi bi-unlock" aria-hidden="true"></i> Activate exam access</button>
                          <?php endif; ?>
                          <?php if ($canDeactivateExam): ?>
                            <button type="button" class="admin-student-action-item js-pcs-deactivate-one" role="menuitem" data-user-id="<?php echo $uid; ?>"><i class="bi bi-slash-circle" aria-hidden="true"></i> Deactivate exam access</button>
                          <?php endif; ?>
                          <?php if ($canDisable): ?>
                            <button type="button" class="admin-student-action-item js-pcs-disable-one" role="menuitem" data-user-id="<?php echo $uid; ?>" data-student-name="<?php echo h((string) ($u['full_name'] ?? '')); ?>"><i class="bi bi-slash-circle" aria-hidden="true"></i> Suspend College Examination</button>
                          <?php endif; ?>
                          <?php if ($canRemoveFromExam): ?>
                            <button type="button" class="admin-student-action-item admin-student-action-item--danger js-open-delete-student" role="menuitem"
                                    data-user-id="<?php echo $uid; ?>"
                                    data-student-name="<?php echo h((string) ($u['full_name'] ?? '')); ?>"
                                    data-remove-mode="unlink"
                                    data-is-reviewee="<?php echo $isStudentTab ? '0' : '1'; ?>"><i class="bi bi-trash" aria-hidden="true"></i> Remove from Examination</button>
                          <?php endif; ?>
                          <?php if ($canDeleteNative): ?>
                            <button type="button" class="admin-student-action-item admin-student-action-item--danger js-open-delete-student" role="menuitem"
                                    data-user-id="<?php echo $uid; ?>"
                                    data-student-name="<?php echo h((string) ($u['full_name'] ?? '')); ?>"
                                    data-remove-mode="delete"
                                    data-is-reviewee="<?php echo $isStudentTab ? '0' : '1'; ?>"><i class="bi bi-trash" aria-hidden="true"></i> Delete student</button>
                          <?php endif; ?>
                          <?php if ($canArchiveAccount): ?>
                            <button type="button" class="admin-student-action-item js-pcs-archive-one" role="menuitem" data-user-id="<?php echo $uid; ?>" data-student-name="<?php echo h((string) ($u['full_name'] ?? '')); ?>"><i class="bi bi-archive" aria-hidden="true"></i> Archive account</button>
                          <?php endif; ?>
                          <?php if ($canRestoreAccount): ?>
                            <button type="button" class="admin-student-action-item js-pcs-restore-one" role="menuitem" data-user-id="<?php echo $uid; ?>" data-student-name="<?php echo h((string) ($u['full_name'] ?? '')); ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore account</button>
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

        <?php if ($totalPages > 1): ?>
          <nav class="students-pagination" aria-label="Student pagination">
            <ul>
              <?php if ($page > 1): ?>
                <li><a href="<?php echo h(students_page_query(['page' => $page - 1])); ?>">Previous</a></li>
              <?php endif; ?>
              <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li><a href="<?php echo h(students_page_query(['page' => $i])); ?>" class="<?php echo $i === $page ? 'is-active' : ''; ?>"><?php echo $i; ?></a></li>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
                <li><a href="<?php echo h(students_page_query(['page' => $page + 1])); ?>">Next</a></li>
              <?php endif; ?>
            </ul>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    </section>
  </div>

  <?php if ($isStudentTab): ?>
  <div id="pcsApproveModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="pcsApproveTitle">
      <form id="pcsApproveForm">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-check2-circle"></i></span>
          <div>
            <h3 id="pcsApproveTitle" class="admin-modal__title">Approve student</h3>
            <p class="admin-modal__desc" id="pcsApproveDesc"><strong id="pcsApproveCount">0</strong> student(s) selected</p>
            <p class="admin-modal__desc" id="pcsApproveHint">Assign a section, then approve so the student can sign in.</p>
          </div>
        </div>
        <div class="admin-modal__field" id="pcsApproveSectionField">
          <label for="pcsApproveSection">Section</label>
          <select id="pcsApproveSection" name="section" required>
            <option value="">Select section</option>
            <?php foreach ($sectionOptions as $secOpt): ?>
              <option value="<?php echo h($secOpt); ?>"><?php echo h($secOpt); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="college-exam-modal-hint text-xs mt-1 mb-0 opacity-80">Required when the student has no section yet.</p>
        </div>
        <div id="pcsApproveError" class="admin-modal__error"></div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="pcsApproveCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--ok" id="pcsApproveSubmit"><i class="bi bi-check2-circle"></i> Approve</button>
        </div>
      </form>
    </section>
  </div>

  <div id="pcsEnableModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="pcsEnableTitle">
      <form id="pcsEnableForm">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-clipboard-check"></i></span>
          <div>
            <h3 id="pcsEnableTitle" class="admin-modal__title">Enable College Examination</h3>
            <p class="admin-modal__desc"><strong id="pcsEnableCount">0</strong> students selected</p>
            <p class="admin-modal__desc">Updates existing accounts only. Does not create duplicate users or modify eReview grants.</p>
          </div>
        </div>
        <div class="admin-modal__field">
          <label>College Examination Access</label>
          <input type="text" value="Enable" disabled>
        </div>
        <div class="admin-modal__field">
          <label for="pcsEnableSection">Section</label>
          <select id="pcsEnableSection" name="section">
            <option value="__none__">No section</option>
            <?php foreach ($sectionOptions as $secOpt): ?>
              <option value="<?php echo h($secOpt); ?>"><?php echo h($secOpt); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="college-exam-modal-hint text-xs mt-1 mb-0 opacity-80">Optional. Section can be assigned later.</p>
        </div>
        <p class="college-exam-modal-hint text-xs m-0 mb-2 opacity-80">Enabling login access does not automatically assign exams.</p>
        <div id="pcsEnableError" class="admin-modal__error"></div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="pcsEnableCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--ok" id="pcsEnableSubmit"><i class="bi bi-check2-circle"></i> Enable for <span id="pcsEnableSubmitCount">0</span> Students</button>
        </div>
      </form>
    </section>
  </div>

  <div id="pcsSectionModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="pcsSectionTitle">
      <form id="pcsSectionForm">
        <div class="admin-modal__hero">
          <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-collection"></i></span>
          <div>
            <h3 id="pcsSectionTitle" class="admin-modal__title">Assign Section</h3>
            <p class="admin-modal__desc"><strong id="pcsSectionCount">0</strong> students selected</p>
            <p class="admin-modal__desc">Applies one section to all selected students with College Examination access.</p>
          </div>
        </div>
        <div class="admin-modal__field">
          <label for="pcsSectionSelect">Section</label>
          <select id="pcsSectionSelect" name="section" required>
            <option value="__none__">No section</option>
            <?php foreach ($sectionOptions as $secOpt): ?>
              <option value="<?php echo h($secOpt); ?>"><?php echo h($secOpt); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="pcsSectionError" class="admin-modal__error"></div>
        <div class="admin-modal__actions">
          <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="pcsSectionCancel">Cancel</button>
          <button type="submit" class="admin-modal__btn admin-modal__btn--ok" id="pcsSectionSubmit"><i class="bi bi-check2"></i> Assign Section</button>
        </div>
      </form>
    </section>
  </div>

  <div id="pcsDisableModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="pcsDisableTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-slash-circle"></i></span>
        <div>
          <h3 id="pcsDisableTitle" class="admin-modal__title">Suspend College Examination?</h3>
          <p class="admin-modal__desc">This suspends College Examination login access for <strong id="pcsDisableCount">0</strong> selected eReview LMS student account(s) only. Native college accounts use Deactivate exam access. eReview LMS access is unchanged.</p>
        </div>
      </div>
      <div id="pcsDisableError" class="admin-modal__error"></div>
      <div class="admin-modal__actions">
        <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="pcsDisableCancel">Cancel</button>
        <button type="button" class="admin-modal__btn admin-modal__btn--danger" id="pcsDisableConfirm">Suspend access</button>
      </div>
    </section>
  </div>
  <div id="pcsArchiveModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="pcsArchiveTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-archive"></i></span>
        <div>
          <h3 id="pcsArchiveTitle" class="admin-modal__title">Archive student account?</h3>
          <p class="admin-modal__desc">Archives <strong id="pcsArchiveCount">0</strong> account(s). They cannot sign in to eReview or College Examination. The same user ID, email, grants, exam access flag, and history are kept. This is not unlink, suspend, or delete. School scoping is not applied (same as this roster).</p>
        </div>
      </div>
      <div id="pcsArchiveError" class="admin-modal__error"></div>
      <div class="admin-modal__actions">
        <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="pcsArchiveCancel">Cancel</button>
        <button type="button" class="admin-modal__btn admin-modal__btn--danger" id="pcsArchiveConfirm">Archive</button>
      </div>
    </section>
  </div>
  <div id="pcsRestoreModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog" role="dialog" aria-modal="true" aria-labelledby="pcsRestoreTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-arrow-counterclockwise"></i></span>
        <div>
          <h3 id="pcsRestoreTitle" class="admin-modal__title">Restore student account?</h3>
          <p class="admin-modal__desc">Restores <strong id="pcsRestoreCount">0</strong> archived account(s) to the status recorded at archive time. Does not create a new account, grant a new LMS subscription, or change College Examination access beyond what was stored.</p>
        </div>
      </div>
      <div id="pcsRestoreError" class="admin-modal__error"></div>
      <div class="admin-modal__actions">
        <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="pcsRestoreCancel">Cancel</button>
        <button type="button" class="admin-modal__btn admin-modal__btn--ok" id="pcsRestoreConfirm">Restore</button>
      </div>
    </section>
  </div>
  <div id="pcsAssignSchoolModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog" role="dialog" aria-modal="true" aria-labelledby="pcsAssignSchoolTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-building"></i></span>
        <div>
          <h3 id="pcsAssignSchoolTitle" class="admin-modal__title">Assign school</h3>
          <p class="admin-modal__desc">Staff must choose the school explicitly. Legacy text is shown for reference and is not treated as assigned until you save. This does not change approval or exam access.</p>
        </div>
      </div>
      <p class="text-sm mb-2" id="pcsAssignSchoolLegacy"></p>
      <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1" for="pcsAssignSchoolSelect">School directory</label>
      <select id="pcsAssignSchoolSelect" class="w-full mb-3">
        <option value=""><?php echo $catalogSchoolRows === [] ? 'No schools yet — open Manage Schools' : 'Select a school'; ?></option>
        <?php foreach ($catalogSchoolRows as $catRow): ?>
          <option value="<?php echo h((string) $catRow['school_name']); ?>"><?php echo h((string) $catRow['school_name']); ?><?php echo ($catRow['status'] ?? '') === 'inactive' ? ' (inactive)' : ''; ?></option>
        <?php endforeach; ?>
      </select>
      <p class="examination-form-hint">Add or rename schools in <a class="underline font-semibold" href="professor_college_schools">Manage Schools</a>. Registration only lists Active schools.</p>
      <div id="pcsAssignSchoolError" class="admin-modal__error"></div>
      <div class="admin-modal__actions">
        <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="pcsAssignSchoolCancel">Cancel</button>
        <button type="button" class="admin-modal__btn admin-modal__btn--ok" id="pcsAssignSchoolConfirm">Save assignment</button>
      </div>
    </section>
  </div>

  <div id="pcsActivateModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog" role="dialog" aria-modal="true" aria-labelledby="pcsActivateTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-unlock"></i></span>
        <div>
          <h3 id="pcsActivateTitle" class="admin-modal__title">Activate exam access?</h3>
          <p class="admin-modal__desc">Sets College Examination access to <strong>active</strong> for <strong id="pcsActivateCount">0</strong> selected student(s) that have a school on record. Does not change account status, school, section, student numbers, or exam history. Students without a school are rejected server-side.</p>
        </div>
      </div>
      <div id="pcsActivateError" class="admin-modal__error"></div>
      <div class="admin-modal__actions">
        <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="pcsActivateCancel">Cancel</button>
        <button type="button" class="admin-modal__btn admin-modal__btn--ok" id="pcsActivateConfirm">Activate selected</button>
      </div>
    </section>
  </div>

  <div id="pcsDeactivateModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="pcsDeactivateTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-slash-circle"></i></span>
        <div>
          <h3 id="pcsDeactivateTitle" class="admin-modal__title">Deactivate exam access?</h3>
          <p class="admin-modal__desc">Suspends College Examination access for <strong id="pcsDeactivateCount">0</strong> selected student(s) with a <strong>staff-assigned</strong> school. Accounts, numbers, and history are kept. Unassigned students are rejected. The school filter is not the target list — only checked IDs are sent.</p>
        </div>
      </div>
      <div id="pcsDeactivateError" class="admin-modal__error"></div>
      <div class="admin-modal__actions">
        <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="pcsDeactivateCancel">Cancel</button>
        <button type="button" class="admin-modal__btn admin-modal__btn--danger" id="pcsDeactivateConfirm">Deactivate selected</button>
      </div>
    </section>
  </div>

  <div id="pcsRollbackModalOverlay" class="admin-modal-overlay lms-dialog-overlay" aria-hidden="true">
    <section class="admin-modal lms-dialog" role="dialog" aria-modal="true" aria-labelledby="pcsRollbackTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-arrow-counterclockwise"></i></span>
        <div>
          <h3 id="pcsRollbackTitle" class="admin-modal__title">Restore prior values</h3>
          <p class="admin-modal__desc">Restore uses the audit id from a previous result. Exam access and school are restored to each record's exact prior value — not assumed Active.</p>
        </div>
      </div>
      <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1" for="pcsRollbackOpId">Exam access operation ID</label>
      <input type="number" id="pcsRollbackOpId" min="1" class="w-full mb-3" value="">
      <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1" for="pcsRollbackSchoolLogId">School assignment log ID</label>
      <input type="number" id="pcsRollbackSchoolLogId" min="1" class="w-full" value="">
      <div id="pcsRollbackError" class="admin-modal__error"></div>
      <div class="admin-modal__actions">
        <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="pcsRollbackCancel">Cancel</button>
        <button type="button" class="admin-modal__btn admin-modal__btn--ok" id="pcsRollbackSchoolConfirm">Restore school</button>
        <button type="button" class="admin-modal__btn admin-modal__btn--ok" id="pcsRollbackConfirm">Restore exam access</button>
      </div>
    </section>
  </div>
  <?php endif; ?>

  <div class="admin-modal-overlay lms-dialog-overlay" id="deleteStudentModal" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="deleteStudentTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-shield-exclamation"></i></span>
        <div>
          <h3 id="deleteStudentTitle" class="admin-modal__title">Remove from Examination?</h3>
          <p class="admin-modal__desc" id="deleteStudentDesc">This removes College Examination access for <strong id="deleteStudentNameDisplay"></strong>. Their eReview LMS account stays intact.</p>
        </div>
      </div>
      <form method="post" action="professor_college_student_delete" id="deleteStudentForm">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="user_id" id="deleteStudentUserId" value="">
        <input type="hidden" name="remove_mode" id="deleteStudentRemoveMode" value="unlink">
        <div class="admin-modal__actions">
          <button type="button" class="admin-btn admin-btn--secondary" id="deleteStudentCancel">Cancel</button>
          <button type="submit" class="admin-btn admin-btn--danger" id="deleteStudentConfirm">Remove</button>
        </div>
      </form>
    </section>
  </div>

  <div class="admin-modal-overlay lms-dialog-overlay" id="pcsBulkRemoveModalOverlay" aria-hidden="true">
    <section class="admin-modal lms-dialog admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="pcsBulkRemoveTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-trash"></i></span>
        <div>
          <h3 id="pcsBulkRemoveTitle" class="admin-modal__title">Remove / delete selected?</h3>
          <p class="admin-modal__desc" id="pcsBulkRemoveDesc">eReview LMS-linked students lose College Examination access only. Native college accounts are archived by default. Permanent delete is refused when examination attempts or submissions exist.</p>
        </div>
      </div>
      <div id="pcsBulkRemoveError" class="admin-modal__error"></div>
      <div class="admin-modal__actions">
        <button type="button" class="admin-btn admin-btn--secondary" id="pcsBulkRemoveCancel">Cancel</button>
        <button type="button" class="admin-btn admin-btn--danger" id="pcsBulkRemoveConfirm">Confirm</button>
      </div>
    </section>
  </div>

  <script>
  (function () {
    var csrf = <?php echo json_encode($csrf); ?>;
    var apiUrl = <?php echo json_encode($apiUrl); ?>;
    var visibleCount = <?php echo (int) count($list); ?>;

    function showFlash(type, message) {
      if (window.LmsPremiumToast) {
        if (type === 'error') window.LmsPremiumToast.error(message);
        else window.LmsPremiumToast.success(message);
        return;
      }
      var el = document.getElementById('pcsFlash');
      if (!el) return;
      el.hidden = false;
      el.className = 'admin-flash mb-3 p-3 rounded-xl flex items-center gap-2 ' + (type === 'error' ? 'admin-flash--error' : 'admin-flash--success');
      el.innerHTML = '<i class="bi bi-' + (type === 'error' ? 'exclamation-triangle-fill' : 'check-circle-fill') + '"></i><span></span>';
      el.querySelector('span').textContent = message;
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function allBoxes() {
      return Array.prototype.slice.call(document.querySelectorAll('.js-pcs-select'));
    }
    function selectedBoxes() {
      return Array.prototype.slice.call(document.querySelectorAll('.js-pcs-select:checked'));
    }
    function selectedIds() {
      return selectedBoxes().map(function (cb) { return cb.value; }).filter(Boolean);
    }

    var bulkBar = document.getElementById('pcsBulkBar');
    var bulkCount = document.getElementById('pcsBulkCount');
    var bulkAllHint = document.getElementById('pcsBulkAllHint');
    var selectAll = document.getElementById('pcsSelectAll');

    function syncBulkBar() {
      var selected = selectedBoxes();
      var all = allBoxes();
      var n = selected.length;
      if (bulkCount) bulkCount.textContent = String(n);
      if (bulkBar) bulkBar.classList.toggle('is-visible', n > 0);
      if (bulkAllHint) {
        var allVisible = all.length > 0 && n === all.length;
        bulkAllHint.hidden = !allVisible;
        bulkAllHint.textContent = allVisible ? ('All ' + all.length + ' visible students selected.') : '';
      }
      if (selectAll) {
        selectAll.disabled = all.length === 0;
        selectAll.checked = all.length > 0 && n === all.length;
        selectAll.indeterminate = n > 0 && n < all.length;
      }
      var enableBtn = document.getElementById('pcsBulkEnableBtn');
      if (enableBtn) {
        var enableN = selected.filter(function (cb) { return cb.getAttribute('data-enableable') === '1'; }).length;
        enableBtn.disabled = enableN === 0;
        enableBtn.title = enableN === 0 ? 'Select eReview students without College Examination access' : ('Enable College Examination for ' + enableN + ' selected student(s)');
      }
      var approveBtn = document.getElementById('pcsBulkApproveBtn');
      if (approveBtn) {
        var approveN = selected.filter(function (cb) { return cb.getAttribute('data-approvable') === '1'; }).length;
        approveBtn.disabled = approveN === 0;
        approveBtn.title = approveN === 0 ? 'Select pending/rejected college registrations to approve' : ('Approve ' + approveN + ' selected student(s)');
      }
      var sectionBtn = document.getElementById('pcsBulkSectionBtn');
      if (sectionBtn) {
        var secN = selected.filter(function (cb) { return cb.getAttribute('data-sectionable') === '1'; }).length;
        sectionBtn.disabled = secN === 0;
        sectionBtn.title = secN === 0 ? 'Select students with College Examination access' : ('Assign section to ' + secN + ' selected student(s)');
      }
      var disableBtn = document.getElementById('pcsBulkDisableBtn');
      if (disableBtn) {
        var disN = selected.filter(function (cb) { return cb.getAttribute('data-disableable') === '1'; }).length;
        disableBtn.disabled = disN === 0;
        disableBtn.title = disN === 0 ? 'Select eReview students with College Examination access' : ('Suspend College Examination for ' + disN + ' selected student(s)');
      }
      var activateBtn = document.getElementById('pcsBulkActivateBtn');
      if (activateBtn) {
        var actN = selected.filter(function (cb) { return cb.getAttribute('data-exam-activatable') === '1'; }).length;
        activateBtn.disabled = actN === 0;
        activateBtn.title = actN === 0 ? 'Select mapped students who are currently suspended' : ('Activate exam access for ' + actN + ' selected student(s)');
      }
      var assignSchoolBtn = document.getElementById('pcsBulkAssignSchoolBtn');
      if (assignSchoolBtn) {
        assignSchoolBtn.disabled = n !== 1;
        assignSchoolBtn.title = n !== 1 ? 'Select exactly one student to assign a school' : 'Assign school for the selected student';
      }
      var deactivateBtn = document.getElementById('pcsBulkDeactivateBtn');
      if (deactivateBtn) {
        var deactN = selected.filter(function (cb) { return cb.getAttribute('data-exam-deactivatable') === '1'; }).length;
        deactivateBtn.disabled = deactN === 0;
        deactivateBtn.title = deactN === 0 ? 'Select mapped students with exam access to deactivate' : ('Deactivate exam access for ' + deactN + ' selected student(s)');
      }
      var removeBtn = document.getElementById('pcsBulkRemoveBtn');
      if (removeBtn) {
        var remN = selected.filter(function (cb) {
          return cb.getAttribute('data-removable') === '1' || cb.getAttribute('data-deletable') === '1';
        }).length;
        removeBtn.disabled = remN === 0;
        removeBtn.title = remN === 0
          ? 'Select students to remove from Examination or delete native accounts'
          : ('Remove/delete ' + remN + ' selected student(s)');
      }
    }

    allBoxes().forEach(function (cb) { cb.addEventListener('change', syncBulkBar); });
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        var on = !!selectAll.checked;
        allBoxes().forEach(function (cb) { cb.checked = on; });
        selectAll.indeterminate = false;
        syncBulkBar();
      });
    }
    var bulkClear = document.getElementById('pcsBulkClearBtn');
    if (bulkClear) {
      bulkClear.addEventListener('click', function () {
        allBoxes().forEach(function (cb) { cb.checked = false; });
        syncBulkBar();
      });
    }
    syncBulkBar();

    function apiPost(action, payload, onOk, errEl) {
      var fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('action', action);
      Object.keys(payload || {}).forEach(function (k) {
        var v = payload[k];
        if (Array.isArray(v)) {
          fd.append(k, JSON.stringify(v));
        } else {
          fd.append(k, v);
        }
      });
      return fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (data) { return { okHttp: r.ok, data: data }; }); })
        .then(function (res) {
          var data = res.data || {};
          if (!res.okHttp || !data.ok) {
            if (errEl) errEl.textContent = (data && data.error) ? data.error : 'Request failed.';
            return null;
          }
          if (onOk) onOk(data);
          return data;
        })
        .catch(function () {
          if (errEl) errEl.textContent = 'Network error. Please try again.';
          return null;
        });
    }

    var pendingIds = [];
    var pendingApproveNeedsSection = false;

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

    function submitApprove(ids, sectionVal, errEl, submitBtn) {
      var payload = { user_ids: ids };
      if (sectionVal) payload.section = sectionVal;
      if (submitBtn) submitBtn.disabled = true;
      return apiPost('approve_college_students', payload, function (data) {
        closeOverlay('pcsApproveModalOverlay');
        showFlash('success', data.message || 'Student approved.');
        setTimeout(function () { window.location.reload(); }, 600);
      }, errEl).finally(function () {
        if (submitBtn) submitBtn.disabled = false;
      });
    }

    function openApproveModal(ids, needsSection, studentName) {
      pendingIds = ids.slice();
      pendingApproveNeedsSection = !!needsSection;
      var n = pendingIds.length;
      var countEl = document.getElementById('pcsApproveCount');
      if (countEl) countEl.textContent = String(n);
      var desc = document.getElementById('pcsApproveDesc');
      if (desc && studentName && n === 1) {
        desc.innerHTML = 'Approve <strong></strong>';
        desc.querySelector('strong').textContent = studentName;
      } else if (desc) {
        desc.innerHTML = '<strong id="pcsApproveCount">' + n + '</strong> student(s) selected';
      }
      var hint = document.getElementById('pcsApproveHint');
      var sectionField = document.getElementById('pcsApproveSectionField');
      var sectionSelect = document.getElementById('pcsApproveSection');
      if (pendingApproveNeedsSection) {
        if (hint) hint.textContent = 'This registration has no section yet. Choose a section, then approve.';
        if (sectionField) sectionField.hidden = false;
        if (sectionSelect) {
          sectionSelect.required = true;
          sectionSelect.value = '';
        }
        var titleEl = document.getElementById('pcsApproveTitle');
        if (titleEl) titleEl.textContent = 'Approve & set section';
        var submitBtnLabel = document.getElementById('pcsApproveSubmit');
        if (submitBtnLabel) submitBtnLabel.innerHTML = '<i class="bi bi-check2-circle"></i> Approve &amp; set section';
      } else {
        if (hint) hint.textContent = 'Section is already set. Confirm approval so the student can sign in to the College Examination portal.';
        if (sectionField) sectionField.hidden = true;
        if (sectionSelect) {
          sectionSelect.required = false;
          sectionSelect.value = '';
        }
        var titleEl2 = document.getElementById('pcsApproveTitle');
        if (titleEl2) titleEl2.textContent = 'Approve student';
        var submitBtnLabel2 = document.getElementById('pcsApproveSubmit');
        if (submitBtnLabel2) submitBtnLabel2.innerHTML = '<i class="bi bi-check2-circle"></i> Approve';
      }
      var err = document.getElementById('pcsApproveError');
      if (err) err.textContent = '';
      openOverlay('pcsApproveModalOverlay');
    }

    function startApprove(ids, boxesOrMeta) {
      ids = (ids || []).filter(Boolean);
      if (!ids.length) return;
      var needsSection = false;
      var studentName = '';
      if (Array.isArray(boxesOrMeta)) {
        boxesOrMeta.forEach(function (cb) {
          if (cb.getAttribute('data-has-section') !== '1') needsSection = true;
          if (!studentName) studentName = cb.getAttribute('data-student-name') || '';
        });
      } else if (boxesOrMeta && typeof boxesOrMeta === 'object') {
        needsSection = boxesOrMeta.hasSection !== true;
        studentName = boxesOrMeta.name || '';
      }
      // Direct approve when every selected student already has a section.
      if (!needsSection && ids.length === 1) {
        openApproveModal(ids, false, studentName);
        return;
      }
      openApproveModal(ids, needsSection, studentName);
    }

    var approveForm = document.getElementById('pcsApproveForm');
    if (approveForm) {
      approveForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (pendingIds.length === 0) return;
        var errEl = document.getElementById('pcsApproveError');
        var submitBtn = document.getElementById('pcsApproveSubmit');
        var sectionSelect = document.getElementById('pcsApproveSection');
        var sectionVal = '';
        if (pendingApproveNeedsSection) {
          sectionVal = (sectionSelect && sectionSelect.value) ? sectionSelect.value.trim() : '';
          if (!sectionVal) {
            if (errEl) errEl.textContent = 'Select a section before approving.';
            return;
          }
        }
        submitApprove(pendingIds, sectionVal, errEl, submitBtn);
      });
    }
    var approveCancel = document.getElementById('pcsApproveCancel');
    if (approveCancel) approveCancel.addEventListener('click', function () { closeOverlay('pcsApproveModalOverlay'); });
    var approveOverlay = document.getElementById('pcsApproveModalOverlay');
    if (approveOverlay) approveOverlay.addEventListener('click', function (e) { if (e.target === approveOverlay) closeOverlay('pcsApproveModalOverlay'); });

    var bulkApprove = document.getElementById('pcsBulkApproveBtn');
    if (bulkApprove) {
      bulkApprove.addEventListener('click', function () {
        var boxes = selectedBoxes().filter(function (cb) { return cb.getAttribute('data-approvable') === '1'; });
        var ids = boxes.map(function (cb) { return cb.value; });
        if (ids.length) startApprove(ids, boxes);
      });
    }
    document.querySelectorAll('.js-pcs-approve-one').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        startApprove([btn.getAttribute('data-user-id')], {
          hasSection: btn.getAttribute('data-has-section') === '1',
          name: btn.getAttribute('data-student-name') || ''
        });
      });
    });

    function openEnableModal(ids) {
      pendingIds = ids.slice();
      var n = pendingIds.length;
      ['pcsEnableCount', 'pcsEnableSubmitCount'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.textContent = String(n);
      });
      var err = document.getElementById('pcsEnableError');
      if (err) err.textContent = '';
      openOverlay('pcsEnableModalOverlay');
    }

    var enableForm = document.getElementById('pcsEnableForm');
    if (enableForm) {
      enableForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (pendingIds.length === 0) return;
        var errEl = document.getElementById('pcsEnableError');
        var submitBtn = document.getElementById('pcsEnableSubmit');
        if (submitBtn) { submitBtn.disabled = true; }
        apiPost('bulk_enable_college_examination', {
          user_ids: pendingIds,
          section: (document.getElementById('pcsEnableSection') || {}).value || '__none__',
          review_type: 'undergrad'
        }, function (data) {
          closeOverlay('pcsEnableModalOverlay');
          showFlash('success', data.message || 'College Examination enabled.');
          setTimeout(function () { window.location.reload(); }, 600);
        }, errEl).finally(function () {
          if (submitBtn) submitBtn.disabled = false;
        });
      });
    }
    var enableCancel = document.getElementById('pcsEnableCancel');
    if (enableCancel) enableCancel.addEventListener('click', function () { closeOverlay('pcsEnableModalOverlay'); });
    var enableOverlay = document.getElementById('pcsEnableModalOverlay');
    if (enableOverlay) enableOverlay.addEventListener('click', function (e) { if (e.target === enableOverlay) closeOverlay('pcsEnableModalOverlay'); });

    var bulkEnable = document.getElementById('pcsBulkEnableBtn');
    if (bulkEnable) {
      bulkEnable.addEventListener('click', function () {
        var ids = selectedBoxes().filter(function (cb) { return cb.getAttribute('data-enableable') === '1'; }).map(function (cb) { return cb.value; });
        if (ids.length) openEnableModal(ids);
      });
    }
    document.querySelectorAll('.js-pcs-enable-one').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        openEnableModal([btn.getAttribute('data-user-id')]);
      });
    });

    function openSectionModal(ids) {
      pendingIds = ids.slice();
      var el = document.getElementById('pcsSectionCount');
      if (el) el.textContent = String(pendingIds.length);
      var err = document.getElementById('pcsSectionError');
      if (err) err.textContent = '';
      openOverlay('pcsSectionModalOverlay');
    }
    var sectionForm = document.getElementById('pcsSectionForm');
    if (sectionForm) {
      sectionForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (pendingIds.length === 0) return;
        var errEl = document.getElementById('pcsSectionError');
        var submitBtn = document.getElementById('pcsSectionSubmit');
        if (submitBtn) submitBtn.disabled = true;
        apiPost('bulk_assign_section', {
          user_ids: pendingIds,
          section: (document.getElementById('pcsSectionSelect') || {}).value || '__none__'
        }, function (data) {
          closeOverlay('pcsSectionModalOverlay');
          showFlash('success', data.message || 'Section assigned.');
          setTimeout(function () { window.location.reload(); }, 600);
        }, errEl).finally(function () {
          if (submitBtn) submitBtn.disabled = false;
        });
      });
    }
    var sectionCancel = document.getElementById('pcsSectionCancel');
    if (sectionCancel) sectionCancel.addEventListener('click', function () { closeOverlay('pcsSectionModalOverlay'); });
    var sectionOverlay = document.getElementById('pcsSectionModalOverlay');
    if (sectionOverlay) sectionOverlay.addEventListener('click', function (e) { if (e.target === sectionOverlay) closeOverlay('pcsSectionModalOverlay'); });

    var bulkSection = document.getElementById('pcsBulkSectionBtn');
    if (bulkSection) {
      bulkSection.addEventListener('click', function () {
        var ids = selectedBoxes().filter(function (cb) { return cb.getAttribute('data-sectionable') === '1'; }).map(function (cb) { return cb.value; });
        if (ids.length) openSectionModal(ids);
      });
    }

    function openDisableModal(ids) {
      pendingIds = ids.slice();
      var el = document.getElementById('pcsDisableCount');
      if (el) el.textContent = String(pendingIds.length);
      var err = document.getElementById('pcsDisableError');
      if (err) err.textContent = '';
      openOverlay('pcsDisableModalOverlay');
    }
    var disableConfirm = document.getElementById('pcsDisableConfirm');
    if (disableConfirm) {
      disableConfirm.addEventListener('click', function () {
        if (pendingIds.length === 0) return;
        var errEl = document.getElementById('pcsDisableError');
        disableConfirm.disabled = true;
        apiPost('bulk_disable_college_examination', { user_ids: pendingIds }, function (data) {
          closeOverlay('pcsDisableModalOverlay');
          showFlash('success', data.message || 'College Examination suspended.');
          setTimeout(function () { window.location.reload(); }, 600);
        }, errEl).finally(function () {
          disableConfirm.disabled = false;
        });
      });
    }
    var disableCancel = document.getElementById('pcsDisableCancel');
    if (disableCancel) disableCancel.addEventListener('click', function () { closeOverlay('pcsDisableModalOverlay'); });
    var disableOverlay = document.getElementById('pcsDisableModalOverlay');
    if (disableOverlay) disableOverlay.addEventListener('click', function (e) { if (e.target === disableOverlay) closeOverlay('pcsDisableModalOverlay'); });

    function openArchiveModal(ids) {
      pendingIds = ids.slice();
      var el = document.getElementById('pcsArchiveCount');
      if (el) el.textContent = String(pendingIds.length);
      var err = document.getElementById('pcsArchiveError');
      if (err) err.textContent = '';
      openOverlay('pcsArchiveModalOverlay');
    }
    var archiveConfirm = document.getElementById('pcsArchiveConfirm');
    if (archiveConfirm) {
      archiveConfirm.addEventListener('click', function () {
        if (pendingIds.length === 0) return;
        var errEl = document.getElementById('pcsArchiveError');
        archiveConfirm.disabled = true;
        apiPost('bulk_archive_students', { user_ids: pendingIds, reason: 'inactive' }, function (data) {
          closeOverlay('pcsArchiveModalOverlay');
          showFlash('success', data.message || 'Students archived.');
          setTimeout(function () { window.location.reload(); }, 600);
        }, errEl).finally(function () {
          archiveConfirm.disabled = false;
        });
      });
    }
    var archiveCancel = document.getElementById('pcsArchiveCancel');
    if (archiveCancel) archiveCancel.addEventListener('click', function () { closeOverlay('pcsArchiveModalOverlay'); });
    var archiveOverlay = document.getElementById('pcsArchiveModalOverlay');
    if (archiveOverlay) archiveOverlay.addEventListener('click', function (e) { if (e.target === archiveOverlay) closeOverlay('pcsArchiveModalOverlay'); });
    var bulkArchive = document.getElementById('pcsBulkArchiveBtn');
    if (bulkArchive) {
      bulkArchive.addEventListener('click', function () {
        var ids = selectedBoxes().filter(function (cb) { return cb.getAttribute('data-archivable') === '1'; }).map(function (cb) { return cb.value; });
        if (ids.length) openArchiveModal(ids);
      });
    }
    document.querySelectorAll('.js-pcs-archive-one').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        openArchiveModal([btn.getAttribute('data-user-id')]);
      });
    });

    function openRestoreModal(ids) {
      pendingIds = ids.slice();
      var el = document.getElementById('pcsRestoreCount');
      if (el) el.textContent = String(pendingIds.length);
      var err = document.getElementById('pcsRestoreError');
      if (err) err.textContent = '';
      openOverlay('pcsRestoreModalOverlay');
    }
    var restoreConfirm = document.getElementById('pcsRestoreConfirm');
    if (restoreConfirm) {
      restoreConfirm.addEventListener('click', function () {
        if (pendingIds.length === 0) return;
        var errEl = document.getElementById('pcsRestoreError');
        restoreConfirm.disabled = true;
        apiPost('bulk_restore_students', { user_ids: pendingIds }, function (data) {
          closeOverlay('pcsRestoreModalOverlay');
          showFlash('success', data.message || 'Students restored.');
          setTimeout(function () { window.location.reload(); }, 600);
        }, errEl).finally(function () {
          restoreConfirm.disabled = false;
        });
      });
    }
    var restoreCancel = document.getElementById('pcsRestoreCancel');
    if (restoreCancel) restoreCancel.addEventListener('click', function () { closeOverlay('pcsRestoreModalOverlay'); });
    var restoreOverlay = document.getElementById('pcsRestoreModalOverlay');
    if (restoreOverlay) restoreOverlay.addEventListener('click', function (e) { if (e.target === restoreOverlay) closeOverlay('pcsRestoreModalOverlay'); });
    var bulkRestore = document.getElementById('pcsBulkRestoreBtn');
    if (bulkRestore) {
      bulkRestore.addEventListener('click', function () {
        var ids = selectedBoxes().filter(function (cb) { return cb.getAttribute('data-restorable') === '1'; }).map(function (cb) { return cb.value; });
        if (ids.length) openRestoreModal(ids);
      });
    }
    document.querySelectorAll('.js-pcs-restore-one').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        openRestoreModal([btn.getAttribute('data-user-id')]);
      });
    });

    var pendingAssignUserId = 0;
    function openAssignSchoolModal(userId, legacySchool) {
      pendingAssignUserId = Number(userId) || 0;
      var legacyEl = document.getElementById('pcsAssignSchoolLegacy');
      if (legacyEl) {
        legacyEl.textContent = legacySchool
          ? ('Current users.school text (not verified until you save): ' + legacySchool)
          : 'No school text on file.';
      }
      var err = document.getElementById('pcsAssignSchoolError');
      if (err) err.textContent = '';
      var selReset = document.getElementById('pcsAssignSchoolSelect');
      if (selReset) selReset.selectedIndex = 0;
      openOverlay('pcsAssignSchoolModalOverlay');
    }
    var assignSchoolConfirm = document.getElementById('pcsAssignSchoolConfirm');
    if (assignSchoolConfirm) {
      assignSchoolConfirm.addEventListener('click', function () {
        if (!pendingAssignUserId) return;
        var sel = document.getElementById('pcsAssignSchoolSelect');
        var school = (sel && sel.value) ? String(sel.value).trim() : '';
        var errEl = document.getElementById('pcsAssignSchoolError');
        if (!school) {
          if (errEl) errEl.textContent = 'Select a school from the directory.';
          return;
        }
        if (!window.confirm('Save staff school assignment "' + school + '" for user #' + pendingAssignUserId + '? This does not change exam access or account status.')) {
          return;
        }
        assignSchoolConfirm.disabled = true;
        apiPost('assign_school', { user_id: pendingAssignUserId, school: school }, function (data) {
          closeOverlay('pcsAssignSchoolModalOverlay');
          showFlash('success', data.message || 'School assigned.');
          setTimeout(function () { window.location.reload(); }, 800);
        }, errEl).finally(function () {
          assignSchoolConfirm.disabled = false;
        });
      });
    }
    var assignSchoolCancel = document.getElementById('pcsAssignSchoolCancel');
    if (assignSchoolCancel) assignSchoolCancel.addEventListener('click', function () { closeOverlay('pcsAssignSchoolModalOverlay'); });
    var assignSchoolOverlay = document.getElementById('pcsAssignSchoolModalOverlay');
    if (assignSchoolOverlay) assignSchoolOverlay.addEventListener('click', function (e) { if (e.target === assignSchoolOverlay) closeOverlay('pcsAssignSchoolModalOverlay'); });
    var bulkAssignSchool = document.getElementById('pcsBulkAssignSchoolBtn');
    if (bulkAssignSchool) {
      bulkAssignSchool.addEventListener('click', function () {
        var boxes = selectedBoxes();
        if (boxes.length !== 1) return;
        openAssignSchoolModal(boxes[0].value, boxes[0].getAttribute('data-legacy-school') || '');
      });
    }
    document.querySelectorAll('.js-pcs-assign-school-one').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        openAssignSchoolModal(btn.getAttribute('data-user-id'), btn.getAttribute('data-legacy-school') || '');
      });
    });

    function openActivateModal(ids) {
      pendingIds = ids.slice();
      var el = document.getElementById('pcsActivateCount');
      if (el) el.textContent = String(pendingIds.length);
      var err = document.getElementById('pcsActivateError');
      if (err) err.textContent = '';
      openOverlay('pcsActivateModalOverlay');
    }
    var activateConfirm = document.getElementById('pcsActivateConfirm');
    if (activateConfirm) {
      activateConfirm.addEventListener('click', function () {
        if (pendingIds.length === 0) return;
        var errEl = document.getElementById('pcsActivateError');
        activateConfirm.disabled = true;
        apiPost('bulk_activate_exam_access', { user_ids: pendingIds }, function (data) {
          closeOverlay('pcsActivateModalOverlay');
          showFlash('success', data.message || 'Exam access activated.');
          setTimeout(function () { window.location.reload(); }, 800);
        }, errEl).finally(function () {
          activateConfirm.disabled = false;
        });
      });
    }
    var activateCancel = document.getElementById('pcsActivateCancel');
    if (activateCancel) activateCancel.addEventListener('click', function () { closeOverlay('pcsActivateModalOverlay'); });
    var activateOverlay = document.getElementById('pcsActivateModalOverlay');
    if (activateOverlay) activateOverlay.addEventListener('click', function (e) { if (e.target === activateOverlay) closeOverlay('pcsActivateModalOverlay'); });
    var bulkActivate = document.getElementById('pcsBulkActivateBtn');
    if (bulkActivate) {
      bulkActivate.addEventListener('click', function () {
        var ids = selectedBoxes().filter(function (cb) { return cb.getAttribute('data-exam-activatable') === '1'; }).map(function (cb) { return cb.value; });
        if (ids.length) openActivateModal(ids);
      });
    }
    document.querySelectorAll('.js-pcs-activate-one').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        var id = btn.getAttribute('data-user-id');
        if (id) openActivateModal([id]);
      });
    });

    function openDeactivateModal(ids) {
      pendingIds = ids.slice();
      var el = document.getElementById('pcsDeactivateCount');
      if (el) el.textContent = String(pendingIds.length);
      var err = document.getElementById('pcsDeactivateError');
      if (err) err.textContent = '';
      openOverlay('pcsDeactivateModalOverlay');
    }
    var deactivateConfirm = document.getElementById('pcsDeactivateConfirm');
    if (deactivateConfirm) {
      deactivateConfirm.addEventListener('click', function () {
        if (pendingIds.length === 0) return;
        var errEl = document.getElementById('pcsDeactivateError');
        deactivateConfirm.disabled = true;
        apiPost('bulk_deactivate_exam_access', { user_ids: pendingIds }, function (data) {
          closeOverlay('pcsDeactivateModalOverlay');
          showFlash('success', data.message || 'Exam access deactivated.');
          setTimeout(function () { window.location.reload(); }, 800);
        }, errEl).finally(function () {
          deactivateConfirm.disabled = false;
        });
      });
    }
    var deactivateCancel = document.getElementById('pcsDeactivateCancel');
    if (deactivateCancel) deactivateCancel.addEventListener('click', function () { closeOverlay('pcsDeactivateModalOverlay'); });
    var deactivateOverlay = document.getElementById('pcsDeactivateModalOverlay');
    if (deactivateOverlay) deactivateOverlay.addEventListener('click', function (e) { if (e.target === deactivateOverlay) closeOverlay('pcsDeactivateModalOverlay'); });
    var bulkDeactivate = document.getElementById('pcsBulkDeactivateBtn');
    if (bulkDeactivate) {
      bulkDeactivate.addEventListener('click', function () {
        var ids = selectedBoxes().filter(function (cb) { return cb.getAttribute('data-exam-deactivatable') === '1'; }).map(function (cb) { return cb.value; });
        if (ids.length) openDeactivateModal(ids);
      });
    }
    document.querySelectorAll('.js-pcs-deactivate-one').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        var id = btn.getAttribute('data-user-id');
        if (id) openDeactivateModal([id]);
      });
    });

    var bulkRollback = document.getElementById('pcsBulkRollbackBtn');
    if (bulkRollback) {
      bulkRollback.addEventListener('click', function () {
        var err = document.getElementById('pcsRollbackError');
        if (err) err.textContent = '';
        openOverlay('pcsRollbackModalOverlay');
      });
    }
    var rollbackSchoolConfirm = document.getElementById('pcsRollbackSchoolConfirm');
    if (rollbackSchoolConfirm) {
      rollbackSchoolConfirm.addEventListener('click', function () {
        var logInput = document.getElementById('pcsRollbackSchoolLogId');
        var logId = logInput ? String(logInput.value || '').trim() : '';
        var errEl = document.getElementById('pcsRollbackError');
        if (!logId || Number(logId) < 1) {
          if (errEl) errEl.textContent = 'Enter a school assignment log ID from an assign/correct result.';
          return;
        }
        if (!window.confirm('Restore the exact prior school values from log #' + logId + '?')) {
          return;
        }
        rollbackSchoolConfirm.disabled = true;
        apiPost('rollback_school_assignment', { log_id: logId }, function (data) {
          closeOverlay('pcsRollbackModalOverlay');
          showFlash('success', data.message || 'School restored.');
          setTimeout(function () { window.location.reload(); }, 800);
        }, errEl).finally(function () {
          rollbackSchoolConfirm.disabled = false;
        });
      });
    }
    var rollbackConfirm = document.getElementById('pcsRollbackConfirm');
    if (rollbackConfirm) {
      rollbackConfirm.addEventListener('click', function () {
        var opInput = document.getElementById('pcsRollbackOpId');
        var opId = opInput ? String(opInput.value || '').trim() : '';
        var errEl = document.getElementById('pcsRollbackError');
        if (!opId || Number(opId) < 1) {
          if (errEl) errEl.textContent = 'Enter a valid operation ID from an activate/deactivate result.';
          return;
        }
        rollbackConfirm.disabled = true;
        apiPost('rollback_exam_access_op', { op_id: opId }, function (data) {
          closeOverlay('pcsRollbackModalOverlay');
          showFlash('success', data.message || 'Prior access restored.');
          setTimeout(function () { window.location.reload(); }, 800);
        }, errEl).finally(function () {
          rollbackConfirm.disabled = false;
        });
      });
    }
    var rollbackCancel = document.getElementById('pcsRollbackCancel');
    if (rollbackCancel) rollbackCancel.addEventListener('click', function () { closeOverlay('pcsRollbackModalOverlay'); });
    var rollbackOverlay = document.getElementById('pcsRollbackModalOverlay');
    if (rollbackOverlay) rollbackOverlay.addEventListener('click', function (e) { if (e.target === rollbackOverlay) closeOverlay('pcsRollbackModalOverlay'); });

    var bulkDisable = document.getElementById('pcsBulkDisableBtn');
    if (bulkDisable) {
      bulkDisable.addEventListener('click', function () {
        var ids = selectedBoxes().filter(function (cb) { return cb.getAttribute('data-disableable') === '1'; }).map(function (cb) { return cb.value; });
        if (ids.length) openDisableModal(ids);
      });
    }
    document.querySelectorAll('.js-pcs-disable-one').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeAllMenus();
        openDisableModal([btn.getAttribute('data-user-id')]);
      });
    });

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
      if (window.innerHeight - rect.bottom < mh + 12) top = Math.max(10, window.innerHeight - mh - 10);
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

    var deleteModal = document.getElementById('deleteStudentModal');
    var uidInput = document.getElementById('deleteStudentUserId');
    var nameEl = document.getElementById('deleteStudentNameDisplay');
    var modeInput = document.getElementById('deleteStudentRemoveMode');
    var titleEl = document.getElementById('deleteStudentTitle');
    var descEl = document.getElementById('deleteStudentDesc');
    var confirmBtn = document.getElementById('deleteStudentConfirm');
    if (deleteModal) {
      function openDelete(uid, name, mode) {
        mode = mode === 'delete' ? 'delete' : (mode === 'archive' ? 'archive' : 'unlink');
        if (uidInput) uidInput.value = String(uid);
        if (modeInput) modeInput.value = mode;
        if (nameEl) nameEl.textContent = name || 'this account';
        if (titleEl) {
          titleEl.textContent = mode === 'delete'
            ? 'Delete student account?'
            : (mode === 'archive' ? 'Archive student account?' : 'Remove from Examination?');
        }
        if (descEl) {
          descEl.innerHTML = mode === 'delete'
            ? ('Permanent delete of <strong id="deleteStudentNameDisplay"></strong> is allowed only when this native account has no attempts, answers, scores, or submissions. Otherwise the server archives instead.')
            : (mode === 'archive'
              ? ('Archive <strong id="deleteStudentNameDisplay"></strong>? Student ID and academic records stay preserved. This can be restored later.')
              : ('This removes College Examination access for <strong id="deleteStudentNameDisplay"></strong>. Their eReview LMS account stays intact.'));
          var nested = document.getElementById('deleteStudentNameDisplay');
          if (nested) nested.textContent = name || 'this account';
        }
        if (confirmBtn) confirmBtn.textContent = mode === 'delete' ? 'Delete student' : (mode === 'archive' ? 'Archive student' : 'Remove');
        deleteModal.classList.add('is-open');
        deleteModal.setAttribute('aria-hidden', 'false');
        closeAllMenus();
      }
      function closeDelete() {
        deleteModal.classList.remove('is-open');
        deleteModal.setAttribute('aria-hidden', 'true');
      }
      document.querySelectorAll('.js-open-delete-student').forEach(function (btn) {
        btn.addEventListener('click', function () {
          openDelete(
            btn.getAttribute('data-user-id'),
            btn.getAttribute('data-student-name'),
            btn.getAttribute('data-remove-mode') || 'unlink'
          );
        });
      });
      var deleteCancel = document.getElementById('deleteStudentCancel');
      if (deleteCancel) deleteCancel.addEventListener('click', closeDelete);
      deleteModal.addEventListener('click', function (e) { if (e.target === deleteModal) closeDelete(); });
    }

    var bulkRemoveBtn = document.getElementById('pcsBulkRemoveBtn');
    if (bulkRemoveBtn) {
      bulkRemoveBtn.addEventListener('click', function () {
        pendingIds = selectedBoxes()
          .filter(function (cb) {
            return cb.getAttribute('data-removable') === '1' || cb.getAttribute('data-deletable') === '1';
          })
          .map(function (cb) { return cb.value; });
        if (!pendingIds.length) return;
        var unlinkN = selectedBoxes().filter(function (cb) {
          return cb.getAttribute('data-removable') === '1' && pendingIds.indexOf(cb.value) !== -1;
        }).length;
        var deleteN = selectedBoxes().filter(function (cb) {
          return cb.getAttribute('data-deletable') === '1' && pendingIds.indexOf(cb.value) !== -1;
        }).length;
        var desc = document.getElementById('pcsBulkRemoveDesc');
        if (desc) {
          desc.textContent = 'Selected: ' + pendingIds.length
            + (unlinkN ? (' · ' + unlinkN + ' LMS-linked (exam access removed, account kept)') : '')
            + (deleteN ? (' · ' + deleteN + ' native college account(s) archived unless you later use an empty-account delete') : '')
            + '.';
        }
        var errEl = document.getElementById('pcsBulkRemoveError');
        if (errEl) errEl.textContent = '';
        openOverlay('pcsBulkRemoveModalOverlay');
      });
    }
    var bulkRemoveCancel = document.getElementById('pcsBulkRemoveCancel');
    if (bulkRemoveCancel) bulkRemoveCancel.addEventListener('click', function () { closeOverlay('pcsBulkRemoveModalOverlay'); });
    var bulkRemoveConfirm = document.getElementById('pcsBulkRemoveConfirm');
    if (bulkRemoveConfirm) {
      bulkRemoveConfirm.addEventListener('click', function () {
        var errEl = document.getElementById('pcsBulkRemoveError');
        bulkRemoveConfirm.disabled = true;
        apiPost('bulk_remove_from_examination', { user_ids: pendingIds }, function (data) {
          closeOverlay('pcsBulkRemoveModalOverlay');
          showFlash('success', data.message || 'Selected students updated.');
          setTimeout(function () { window.location.reload(); }, 600);
        }, errEl).finally(function () {
          bulkRemoveConfirm.disabled = false;
        });
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      ['pcsEnableModalOverlay', 'pcsSectionModalOverlay', 'pcsDisableModalOverlay', 'pcsArchiveModalOverlay', 'pcsRestoreModalOverlay', 'pcsActivateModalOverlay', 'pcsDeactivateModalOverlay', 'pcsAssignSchoolModalOverlay', 'pcsRollbackModalOverlay', 'pcsBulkRemoveModalOverlay', 'pcsApproveModalOverlay', 'deleteStudentModal'].forEach(function (id) {
        closeOverlay(id);
      });
      if (deleteModal) {
        deleteModal.classList.remove('is-open');
        deleteModal.setAttribute('aria-hidden', 'true');
      }
      closeAllMenus();
    });
  })();
  </script>
  <?php college_exam_id_copy_script(); ?>
</body>
</html>
