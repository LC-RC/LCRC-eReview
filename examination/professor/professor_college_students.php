<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/college_schema.php';
require_once dirname(__DIR__) . '/includes/college_sections.php';
require_once dirname(__DIR__, 2) . '/includes/profile_avatar.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
require_once dirname(__DIR__, 2) . '/includes/url_helpers.php';

$pageTitle = 'Students';
$csrf = generateCSRFToken();
college_sections_ensure_schema($conn);

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$typeFilter = (string) ($_GET['type'] ?? 'college_student');
$sectionFilter = trim((string) ($_GET['section'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'created_desc');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPageRaw = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($perPageRaw, [10, 25, 50, 100], true) ? $perPageRaw : 25;

$validStatus = ['all', 'approved', 'pending', 'rejected'];
$validType = ['college_student', 'reviewee'];
$validSort = ['created_desc', 'created_asc', 'name_asc', 'name_desc'];
if (!in_array($statusFilter, $validStatus, true)) {
    $statusFilter = 'all';
}
if (!in_array($typeFilter, $validType, true)) {
    $typeFilter = 'college_student';
}
if (!in_array($sort, $validSort, true)) {
    $sort = 'created_desc';
}

$isStudentTab = ($typeFilter === 'college_student');
$reviewTypeSql = $isStudentTab ? 'undergrad' : 'reviewee';

$sectionOptions = college_sections_active_names($conn);

function pcs_build_list_where(
    string $reviewType,
    string $statusFilter,
    string $search,
    string $sectionFilter,
    bool $isStudentTab,
    bool $platformColsReady
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

    if ($statusFilter !== 'all') {
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
[$whereParts, $whereTypes, $whereParams] = pcs_build_list_where(
    $reviewTypeSql,
    $statusFilter,
    $search,
    $sectionFilter,
    $isStudentTab,
    $platformColsReady
);
$whereSql = implode(' AND ', $whereParts);

$statsStudents = ['total' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
$statsReviewees = ['total' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
$statsRosterSql = $platformColsReady
    ? "(role='college_student' OR (role='student' AND college_examination_access IN ('active','suspended')))"
    : "role='college_student'";
$statsQ = mysqli_query(
    $conn,
    "SELECT review_type, status, COUNT(*) AS c
     FROM users
     WHERE {$statsRosterSql}
     GROUP BY review_type, status"
);
if ($statsQ) {
    while ($sr = mysqli_fetch_assoc($statsQ)) {
        $rt = strtolower(trim((string) ($sr['review_type'] ?? '')));
        $st = (string) ($sr['status'] ?? '');
        $c = (int) ($sr['c'] ?? 0);
        $bucket = ($rt === 'undergrad') ? 'students' : 'reviewees';
        if ($bucket === 'students') {
            $statsStudents['total'] += $c;
            if ($st === 'approved') {
                $statsStudents['approved'] += $c;
            } elseif ($st === 'pending') {
                $statsStudents['pending'] += $c;
            } elseif ($st === 'rejected') {
                $statsStudents['rejected'] += $c;
            }
        } else {
            $statsReviewees['total'] += $c;
            if ($st === 'approved') {
                $statsReviewees['approved'] += $c;
            } elseif ($st === 'pending') {
                $statsReviewees['pending'] += $c;
            } elseif ($st === 'rejected') {
                $statsReviewees['rejected'] += $c;
            }
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
$listSql = "SELECT user_id, full_name, email, status, created_at, section, student_number, profile_picture, use_default_avatar, review_type, role, college_examination_access
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

$activeStats = $isStudentTab ? $statsStudents : $statsReviewees;

function students_page_query(array $overrides = []): string
{
    global $search, $statusFilter, $typeFilter, $sectionFilter, $sort, $page, $perPage;
    $params = array_merge([
        'q' => $search !== '' ? $search : null,
        'status' => $statusFilter !== 'all' ? $statusFilter : null,
        'type' => $typeFilter !== 'college_student' ? $typeFilter : null,
        'section' => ($typeFilter === 'college_student' && $sectionFilter !== '') ? $sectionFilter : null,
        'sort' => $sort !== 'created_desc' ? $sort : null,
        'page' => $page > 1 ? $page : null,
        'per_page' => $perPage !== 25 ? $perPage : null,
    ], $overrides);

    return '?' . http_build_query(array_filter($params, static fn ($v) => $v !== null && $v !== ''));
}

$flashMessage = $_SESSION['message'] ?? null;
unset($_SESSION['message']);

$adminLoadStudentsCss = true;
$adminHeroIcon = 'people';
$adminHeroTitle = 'Students';
$adminHeroSubtitle = 'Examination roster only: native college accounts and eReview students with exam access. Enable new access from LMS Admin → Students.';
$adminHeroActions = '<a class="admin-btn admin-btn--primary admin-btn--sm" href="professor_create_college_student"><i class="bi bi-person-plus"></i> Add Student</a>'
    . '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="professor_create_reviewee"><i class="bi bi-person-badge"></i> Add Reviewee</a>';

$statusChipMeta = [
    'all' => ['All', 'bi-collection', (int) $activeStats['total']],
    'approved' => ['Approved', 'bi-check2-circle', (int) $activeStats['approved']],
    'pending' => ['Pending', 'bi-hourglass-split', (int) $activeStats['pending']],
    'rejected' => ['Rejected', 'bi-x-circle', (int) $activeStats['rejected']],
];

$hasActiveFilters = ($search !== '' || $sectionFilter !== '' || $sort !== 'created_desc' || $statusFilter !== 'all');
$rangeStart = $total > 0 ? $offset + 1 : 0;
$rangeEnd = min($offset + $perPage, $total);
$apiUrl = ereview_url('professor_college_students_api');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

  <?php if ($flashMessage !== null && $flashMessage !== ''): ?>
    <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2" role="status">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h((string) $flashMessage); ?></span>
    </div>
  <?php endif; ?>

  <div id="pcsFlash" class="admin-flash mb-3 p-3 rounded-xl flex items-center gap-2" role="status" hidden></div>

  <div class="students-page-shell">
    <nav class="students-view-tabs" aria-label="Examinee groups">
      <a href="<?php echo h(students_page_query(['type' => 'college_student', 'section' => null, 'page' => null])); ?>" class="students-view-tab <?php echo $isStudentTab ? 'is-active' : ''; ?>">
        <i class="bi bi-mortarboard" aria-hidden="true"></i> Students <span class="students-status-chip__count"><?php echo (int) $statsStudents['total']; ?></span>
      </a>
      <a href="<?php echo h(students_page_query(['type' => 'reviewee', 'section' => null, 'page' => null])); ?>" class="students-view-tab <?php echo !$isStudentTab ? 'is-active' : ''; ?>">
        <i class="bi bi-person-badge" aria-hidden="true"></i> Reviewees <span class="students-status-chip__count"><?php echo (int) $statsReviewees['total']; ?></span>
      </a>
    </nav>

    <div class="students-toolbar page-filter">
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
        <input type="hidden" name="type" value="<?php echo h($typeFilter); ?>">
        <?php if ($statusFilter !== 'all'): ?>
          <input type="hidden" name="status" value="<?php echo h($statusFilter); ?>">
        <?php endif; ?>
        <div class="students-search">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" id="searchQ" name="q" value="<?php echo h($search); ?>" placeholder="Search students, email, section..." aria-label="Search accounts">
        </div>
        <?php if ($isStudentTab): ?>
          <select name="section" aria-label="Filter by section" class="admin-btn admin-btn--secondary admin-btn--sm" style="min-height:2.25rem;">
            <option value="">All sections</option>
            <option value="__none__" <?php echo $sectionFilter === '__none__' ? 'selected' : ''; ?>>No section</option>
            <?php foreach ($sectionOptions as $sec): ?>
              <option value="<?php echo h($sec); ?>" <?php echo $sectionFilter === $sec ? 'selected' : ''; ?>><?php echo h($sec); ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <select name="sort" aria-label="Sort order" class="admin-btn admin-btn--secondary admin-btn--sm" style="min-height:2.25rem;">
          <option value="created_desc" <?php echo $sort === 'created_desc' ? 'selected' : ''; ?>>Recently created</option>
          <option value="created_asc" <?php echo $sort === 'created_asc' ? 'selected' : ''; ?>>Oldest created</option>
          <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A–Z</option>
          <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name Z–A</option>
        </select>
        <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-funnel"></i> Filter</button>
        <?php if ($hasActiveFilters): ?>
          <a href="<?php echo h(students_page_query(['q' => null, 'section' => null, 'sort' => null, 'status' => null, 'page' => null])); ?>" class="students-clear-link">Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <div id="pcsBulkBar" class="students-bulk-bar<?php echo $isStudentTab ? '' : ' hidden'; ?>" aria-live="polite">
      <span class="students-bulk-bar__count"><span id="pcsBulkCount">0</span> selected</span>
      <span id="pcsBulkAllHint" class="students-bulk-bar__hint" hidden></span>
      <div class="students-bulk-bar__actions">
        <button type="button" id="pcsBulkClearBtn" class="admin-modal__btn admin-modal__btn--ghost">Clear</button>
        <?php if ($isStudentTab): ?>
          <button type="button" id="pcsBulkEnableBtn" class="admin-modal__btn admin-modal__btn--ok"><i class="bi bi-clipboard-check"></i> Enable College Examination</button>
          <button type="button" id="pcsBulkSectionBtn" class="admin-modal__btn admin-modal__btn--ghost"><i class="bi bi-collection"></i> Assign Section</button>
          <button type="button" id="pcsBulkDisableBtn" class="admin-modal__btn admin-modal__btn--ghost"><i class="bi bi-slash-circle"></i> Suspend College Examination</button>
          <button type="button" id="pcsBulkRemoveBtn" class="admin-modal__btn admin-modal__btn--danger"><i class="bi bi-trash"></i> Remove / Delete</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="rounded-xl page-table students-table-shell">
      <div class="students-table-meta">
        <span>
          <?php if ($total > 0): ?>
            Showing <?php echo (int) $rangeStart; ?>–<?php echo (int) $rangeEnd; ?> of <?php echo (int) $total; ?> <?php echo $isStudentTab ? 'students' : 'reviewees'; ?>
          <?php else: ?>
            0 <?php echo $isStudentTab ? 'students' : 'reviewees'; ?>
          <?php endif; ?>
        </span>
        <form method="get" class="flex items-center gap-2 text-sm">
          <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?php echo h($search); ?>"><?php endif; ?>
          <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?php echo h($statusFilter); ?>"><?php endif; ?>
          <input type="hidden" name="type" value="<?php echo h($typeFilter); ?>">
          <?php if ($isStudentTab && $sectionFilter !== ''): ?><input type="hidden" name="section" value="<?php echo h($sectionFilter); ?>"><?php endif; ?>
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
        <div class="students-empty-cell" style="padding:3rem 1.5rem;">
          <div class="font-semibold text-lg mb-1">
            <?php echo $hasActiveFilters ? 'No students found' : ($isStudentTab ? 'No College Examination students yet' : 'No reviewees yet'); ?>
          </div>
          <p class="text-sm mt-1 mb-3 opacity-80">
            <?php if ($hasActiveFilters): ?>
              Try adjusting your search or filters.
            <?php else: ?>
              Add examinee accounts to assign them to examinations.
            <?php endif; ?>
          </p>
          <div class="flex flex-wrap gap-2 justify-center">
            <?php if ($hasActiveFilters): ?>
              <a href="<?php echo h(students_page_query(['q' => null, 'section' => null, 'sort' => null, 'status' => null, 'page' => null])); ?>" class="admin-btn admin-btn--secondary admin-btn--sm">Clear filters</a>
            <?php endif; ?>
            <?php if ($isStudentTab): ?>
              <a href="professor_create_college_student" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-person-plus"></i> Add Student</a>
            <?php else: ?>
              <a href="professor_create_reviewee" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-person-badge"></i> Add Reviewee</a>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="students-table-scroll">
          <table class="w-full text-left admin-students-table students-table--compact pcs-students-table pex-list-table">
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
                  <th scope="col" class="pcs-col-exam">College Exam</th>
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
                  $canEnable = ($role === 'student' && !$hasCollEx && $collExAccessVal !== 'suspended' && $statusLower !== 'rejected');
                  $canAssignSection = ($isStudentTab && $hasCollEx);
                  $canDisable = ($role === 'student' && $hasCollEx);
                  // LMS-linked: remove exam access only. Native college: hard-delete account.
                  $canRemoveFromExam = ($role === 'student' && in_array($collExAccessVal, ['active', 'suspended'], true));
                  $canDeleteNative = ($role === 'college_student');
                  $canRowDelete = ($canRemoveFromExam || $canDeleteNative);
                  $statusBadge = match ($statusLower) {
                      'approved' => 'admin-badge--success',
                      'pending' => 'admin-badge--warning',
                      'rejected' => 'admin-badge--danger',
                      default => 'admin-badge--neutral',
                  };
                  $createdFmt = !empty($u['created_at']) ? date('M j, Y', strtotime((string) $u['created_at'])) : '—';
                ?>
                <tr data-user-id="<?php echo $uid; ?>">
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
                           <?php if ($canRemoveFromExam): ?>data-removable="1"<?php endif; ?>
                           <?php if ($canDeleteNative): ?>data-deletable="1"<?php endif; ?>>
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
                        <span class="student-meta college-student-account__id">User #<?php echo $uid; ?></span>
                      </div>
                    </div>
                  </td>
                  <?php if ($isStudentTab): ?>
                    <td class="pcs-col-exam" data-label="College Exam">
                      <?php if ($hasCollEx): ?>
                        <span class="commerce-pill commerce-pill--verified"><i class="bi bi-check2-circle" aria-hidden="true"></i> Active</span>
                      <?php elseif ($collExAccessVal === 'suspended'): ?>
                        <span class="commerce-pill commerce-pill--awaiting"><i class="bi bi-slash-circle" aria-hidden="true"></i> Suspended</span>
                      <?php else: ?>
                        <span class="commerce-pill commerce-pill--awaiting"><i class="bi bi-dash-circle" aria-hidden="true"></i> Not enabled</span>
                      <?php endif; ?>
                    </td>
                    <td class="pcs-col-section" data-label="Section">
                      <?php if (!$hasCollEx && $collExAccessVal !== 'suspended'): ?>
                        <span class="student-meta">Not enabled</span>
                      <?php elseif ($sectionTxt !== ''): ?>
                        <span class="admin-badge admin-badge--info"><?php echo h($sectionTxt); ?></span>
                      <?php else: ?>
                        <span class="student-meta">Not set</span>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                  <td class="pcs-col-status" data-label="Status"><span class="admin-badge <?php echo h($statusBadge); ?>"><?php echo h(ucfirst($statusLower)); ?></span></td>
                  <td class="pcs-col-created" data-label="Created"><span class="student-meta"><?php echo h($createdFmt); ?></span></td>
                  <td class="student-action-cell" data-label="Actions">
                    <div class="student-action-cluster">
                      <a class="admin-btn admin-btn--secondary admin-btn--sm admin-btn--view" href="professor_college_student_view?id=<?php echo $uid; ?>"><i class="bi bi-eye" aria-hidden="true"></i> View</a>
                      <div class="admin-student-action-menu-wrap" data-admin-student-action-menu>
                        <button type="button" class="admin-student-action-menu-trigger admin-student-action-menu-trigger--icon" data-action-menu-trigger aria-expanded="false" aria-haspopup="true" aria-label="More actions for <?php echo h((string) ($u['full_name'] ?? '')); ?>">
                          <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="admin-student-action-menu" data-action-menu-list role="menu">
                          <a role="menuitem" class="admin-student-action-item" href="professor_college_student_view?id=<?php echo $uid; ?>"><i class="bi bi-eye" aria-hidden="true"></i> View student</a>
                          <a role="menuitem" class="admin-student-action-item" href="professor_college_student_view?id=<?php echo $uid; ?>#college-examination"><i class="bi bi-sliders" aria-hidden="true"></i> Manage access</a>
                          <?php if ($canEnable): ?>
                            <button type="button" class="admin-student-action-item js-pcs-enable-one" role="menuitem" data-user-id="<?php echo $uid; ?>" data-student-name="<?php echo h((string) ($u['full_name'] ?? '')); ?>"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Enable College Examination</button>
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
  </div>

  <?php if ($isStudentTab): ?>
  <div id="pcsEnableModalOverlay" class="admin-modal-overlay" aria-hidden="true">
    <section class="admin-modal admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="pcsEnableTitle">
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
        <div class="admin-modal__field">
          <label for="pcsEnableReviewType">Review Type</label>
          <select id="pcsEnableReviewType" name="review_type">
            <option value="undergrad">Undergrad</option>
            <option value="reviewee">Reviewee</option>
          </select>
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

  <div id="pcsSectionModalOverlay" class="admin-modal-overlay" aria-hidden="true">
    <section class="admin-modal admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="pcsSectionTitle">
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

  <div id="pcsDisableModalOverlay" class="admin-modal-overlay" aria-hidden="true">
    <section class="admin-modal admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="pcsDisableTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-slash-circle"></i></span>
        <div>
          <h3 id="pcsDisableTitle" class="admin-modal__title">Suspend College Examination?</h3>
          <p class="admin-modal__desc">This suspends College Examination login access for <strong id="pcsDisableCount">0</strong> selected eReview student account(s). eReview LMS access is unchanged.</p>
        </div>
      </div>
      <div id="pcsDisableError" class="admin-modal__error"></div>
      <div class="admin-modal__actions">
        <button type="button" class="admin-modal__btn admin-modal__btn--ghost" id="pcsDisableCancel">Cancel</button>
        <button type="button" class="admin-modal__btn admin-modal__btn--danger" id="pcsDisableConfirm">Suspend access</button>
      </div>
    </section>
  </div>
  <?php endif; ?>

  <div class="admin-modal-overlay" id="deleteStudentModal" aria-hidden="true">
    <section class="admin-modal admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="deleteStudentTitle">
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

  <div class="admin-modal-overlay" id="pcsBulkRemoveModalOverlay" aria-hidden="true">
    <section class="admin-modal admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="pcsBulkRemoveTitle">
      <div class="admin-modal__hero">
        <span class="admin-modal__hero-icon" aria-hidden="true"><i class="bi bi-trash"></i></span>
        <div>
          <h3 id="pcsBulkRemoveTitle" class="admin-modal__title">Remove / delete selected?</h3>
          <p class="admin-modal__desc" id="pcsBulkRemoveDesc">eReview LMS-linked students lose College Examination access only. Native college accounts are permanently deleted.</p>
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
          review_type: (document.getElementById('pcsEnableReviewType') || {}).value || 'undergrad'
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
        mode = mode === 'delete' ? 'delete' : 'unlink';
        if (uidInput) uidInput.value = String(uid);
        if (modeInput) modeInput.value = mode;
        if (nameEl) nameEl.textContent = name || 'this account';
        if (titleEl) titleEl.textContent = mode === 'delete' ? 'Delete student account?' : 'Remove from Examination?';
        if (descEl) {
          descEl.innerHTML = mode === 'delete'
            ? ('This permanently deletes the native college examination account for <strong id="deleteStudentNameDisplay"></strong>. Linked examination attempts and uploads may be removed. This cannot be undone.')
            : ('This removes College Examination access for <strong id="deleteStudentNameDisplay"></strong>. Their eReview LMS account stays intact.');
          var nested = document.getElementById('deleteStudentNameDisplay');
          if (nested) nested.textContent = name || 'this account';
        }
        if (confirmBtn) confirmBtn.textContent = mode === 'delete' ? 'Delete student' : 'Remove';
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
            + (deleteN ? (' · ' + deleteN + ' native college account(s) permanently deleted') : '')
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
      ['pcsEnableModalOverlay', 'pcsSectionModalOverlay', 'pcsDisableModalOverlay', 'pcsBulkRemoveModalOverlay', 'deleteStudentModal'].forEach(function (id) {
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
</body>
</html>
