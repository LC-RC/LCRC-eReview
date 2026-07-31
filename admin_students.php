<?php
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/profile_avatar.php';
require_once __DIR__ . '/includes/commerce_student_admin.php';
require_once __DIR__ . '/includes/url_helpers.php';

$csrf = generateCSRFToken();
$nowSql = date('Y-m-d H:i:s');

$view = $_GET['view'] ?? 'students';
if (!in_array($view, ['students', 'deleted'], true)) { $view = 'students'; }

$tab = $_GET['tab'] ?? 'enrolled';
if (!in_array($tab, ['enrolled','pending','expired','rejected','all'], true)) { $tab = 'enrolled'; }

$q = trim($_GET['q'] ?? '');
$dq = trim($_GET['dq'] ?? '');
$page = sanitizeInt($_GET['page'] ?? 1, 1);
$perPage = 10;
$offset = ($page - 1) * $perPage;

$like = '%' . $q . '%';
$searchSql = "(full_name LIKE ? OR email LIKE ?)";
$whereMap = [
  'enrolled' => "role='student' AND status='approved' AND access_end IS NOT NULL AND access_end >= ?",
  'pending'  => "role='student' AND status='pending'",
  'expired'  => "role='student' AND status='approved' AND access_end IS NOT NULL AND access_end < ?",
  'rejected' => "role='student' AND status='rejected'",
  'all'      => "role='student'",
];
$tabWhere = $whereMap[$tab];

$hasProfilePicture = false;
$hasUseDefaultAvatar = false;
$hasIsOnline = false;
$hasLastSeenAt = false;
$hasLastLogoutAt = false;
$hasLastLoginAt = false;
$cp1 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'profile_picture'");
if ($cp1 && mysqli_fetch_assoc($cp1)) $hasProfilePicture = true;
$cp2 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'use_default_avatar'");
if ($cp2 && mysqli_fetch_assoc($cp2)) $hasUseDefaultAvatar = true;
$cp3 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'is_online'");
if ($cp3 && mysqli_fetch_assoc($cp3)) $hasIsOnline = true;
$cp4 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'last_seen_at'");
if ($cp4 && mysqli_fetch_assoc($cp4)) $hasLastSeenAt = true;
$cp5 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'last_logout_at'");
if ($cp5 && mysqli_fetch_assoc($cp5)) $hasLastLogoutAt = true;
$cp6 = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'last_login_at'");
if ($cp6 && mysqli_fetch_assoc($cp6)) $hasLastLoginAt = true;

// Prioritize active users at the top of the table.
// Active = recent in-app activity within 2 minutes (last_seen/last_login),
// with legacy fallback to is_online only when activity timestamps do not exist.
$presenceOrderExpr = '0';
if ($hasLastSeenAt) {
  $presenceOrderExpr = "(CASE WHEN last_seen_at IS NOT NULL AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 1 ELSE 0 END)";
} elseif ($hasLastLoginAt) {
  $presenceOrderExpr = "(CASE WHEN last_login_at IS NOT NULL AND last_login_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 1 ELSE 0 END)";
} elseif ($hasIsOnline) {
  $presenceOrderExpr = "(CASE WHEN is_online = 1 THEN 1 ELSE 0 END)";
}
if ($hasLastLogoutAt) {
  $activityCol = $hasLastSeenAt ? 'last_seen_at' : ($hasLastLoginAt ? 'last_login_at' : null);
  $logoutIdleCond = $activityCol !== null ? "($activityCol IS NULL OR $activityCol <= last_logout_at)" : '1=1';
  $presenceOrderExpr = "(CASE WHEN last_logout_at IS NOT NULL AND $logoutIdleCond THEN 0 ELSE $presenceOrderExpr END)";
}
$orderBySql = "$presenceOrderExpr DESC, created_at DESC";

if (in_array($tab, ['enrolled','expired'], true)) {
  $countSql = "SELECT COUNT(*) AS total FROM users WHERE $tabWhere AND $searchSql";
  $stmt = mysqli_prepare($conn, $countSql);
  mysqli_stmt_bind_param($stmt, 'sss', $nowSql, $like, $like);
} else {
  $countSql = "SELECT COUNT(*) AS total FROM users WHERE $tabWhere AND $searchSql";
  $stmt = mysqli_prepare($conn, $countSql);
  mysqli_stmt_bind_param($stmt, 'ss', $like, $like);
}
mysqli_stmt_execute($stmt);
$countRes = mysqli_stmt_get_result($stmt);
$countRow = mysqli_fetch_assoc($countRes);
$total = (int)($countRow['total'] ?? 0);
mysqli_stmt_close($stmt);

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$selectCols = "user_id, full_name, email, review_type, school, school_other, payment_proof, status, access_start, access_end, access_months, created_at";
$hasEnrollmentPath = false;
$cep = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'enrollment_path'");
if ($cep && mysqli_fetch_assoc($cep)) {
  $hasEnrollmentPath = true;
  $selectCols .= ", enrollment_path";
}
if ($hasProfilePicture) $selectCols .= ", profile_picture";
if ($hasUseDefaultAvatar) $selectCols .= ", use_default_avatar";
if ($hasIsOnline) $selectCols .= ", is_online";
if ($hasLastSeenAt) $selectCols .= ", last_seen_at";
if ($hasLastLogoutAt) $selectCols .= ", last_logout_at";
if ($hasLastLoginAt) $selectCols .= ", last_login_at";
if (in_array($tab, ['enrolled','expired'], true)) {
  $sql = "SELECT $selectCols FROM users WHERE $tabWhere AND $searchSql ORDER BY $orderBySql LIMIT ? OFFSET ?";
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($stmt, 'sssii', $nowSql, $like, $like, $perPage, $offset);
} else {
  $sql = "SELECT $selectCols FROM users WHERE $tabWhere AND $searchSql ORDER BY $orderBySql LIMIT ? OFFSET ?";
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($stmt, 'ssii', $like, $like, $perPage, $offset);
}
mysqli_stmt_execute($stmt);
$studentsRes = mysqli_stmt_get_result($stmt);
$studentRows = [];
while ($studentsRes && ($sr = mysqli_fetch_assoc($studentsRes))) {
  $studentRows[] = $sr;
}
$badgeUserIds = [];
foreach ($studentRows as $srRow) {
  $badgeUserIds[] = (int) ($srRow['user_id'] ?? 0);
}
$studentBadgeMap = commerce_admin_students_dashboard_rows($conn, $badgeUserIds);

$getCount = function(string $where, bool $needsNow) use ($conn, $nowSql, $like, $searchSql) : int {
  $sql = "SELECT COUNT(*) AS total FROM users WHERE $where AND $searchSql";
  $stmt = mysqli_prepare($conn, $sql);
  if ($needsNow) {
    mysqli_stmt_bind_param($stmt, 'sss', $nowSql, $like, $like);
  } else {
    mysqli_stmt_bind_param($stmt, 'ss', $like, $like);
  }
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $row = mysqli_fetch_assoc($res);
  mysqli_stmt_close($stmt);
  return (int)($row['total'] ?? 0);
};

$counts = [
  'enrolled' => $getCount($whereMap['enrolled'], true),
  'pending'  => $getCount($whereMap['pending'], false),
  'expired'  => $getCount($whereMap['expired'], true),
  'rejected' => $getCount($whereMap['rejected'], false),
  'all'      => $getCount($whereMap['all'], false),
];

$deletedLogs = [];
$hasDeletedLogTable = false;
$checkLogTbl = @mysqli_query($conn, "SHOW TABLES LIKE 'deleted_users_log'");
if ($checkLogTbl && mysqli_fetch_row($checkLogTbl)) {
  $hasDeletedLogTable = true;
}
if ($hasDeletedLogTable && $view === 'deleted') {
  $hasLogSchool = false;
  $hasLogReviewType = false;
  $hasLogAccessRange = false;
  $hasLogReason = false;
  $lc1 = @mysqli_query($conn, "SHOW COLUMNS FROM deleted_users_log LIKE 'deleted_school'");
  if ($lc1 && mysqli_fetch_assoc($lc1)) $hasLogSchool = true;
  $lc2 = @mysqli_query($conn, "SHOW COLUMNS FROM deleted_users_log LIKE 'deleted_review_type'");
  if ($lc2 && mysqli_fetch_assoc($lc2)) $hasLogReviewType = true;
  $lc3 = @mysqli_query($conn, "SHOW COLUMNS FROM deleted_users_log LIKE 'deleted_access_range'");
  if ($lc3 && mysqli_fetch_assoc($lc3)) $hasLogAccessRange = true;
  $lc4 = @mysqli_query($conn, "SHOW COLUMNS FROM deleted_users_log LIKE 'deletion_reason'");
  if ($lc4 && mysqli_fetch_assoc($lc4)) $hasLogReason = true;

  $logSelect = "SELECT log_id, deleted_user_id, deleted_name, deleted_email, " .
            ($hasLogSchool ? "deleted_school" : "'' AS deleted_school") . ", " .
            ($hasLogReviewType ? "deleted_review_type" : "'' AS deleted_review_type") . ", " .
            ($hasLogAccessRange ? "deleted_access_range" : "'' AS deleted_access_range") . ", " .
            "deleted_by_admin_name, " .
            ($hasLogReason ? "deletion_reason" : "'' AS deletion_reason") . ", deleted_at
             FROM deleted_users_log";
  if ($dq !== '') {
    $logStmt = mysqli_prepare($conn, $logSelect . " WHERE deleted_name LIKE ? OR deleted_email LIKE ? ORDER BY deleted_at DESC, log_id DESC LIMIT 100");
    if ($logStmt) {
      $dqLike = '%' . $dq . '%';
      mysqli_stmt_bind_param($logStmt, 'ss', $dqLike, $dqLike);
      mysqli_stmt_execute($logStmt);
      $logRes = mysqli_stmt_get_result($logStmt);
      if ($logRes) {
        while ($lr = mysqli_fetch_assoc($logRes)) {
          $deletedLogs[] = $lr;
        }
      }
      mysqli_stmt_close($logStmt);
    }
  } else {
    $logRes = @mysqli_query($conn, $logSelect . " ORDER BY deleted_at DESC, log_id DESC LIMIT 100");
    if ($logRes) {
      while ($lr = mysqli_fetch_assoc($logRes)) {
        $deletedLogs[] = $lr;
      }
    }
  }
}

$pageTitle = 'Students';
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard'], ['Students'] ];
$mk = function(string $t, int $p = 1) use ($q) : string {
  $params = ['view' => 'students', 'tab' => $t, 'q' => $q, 'page' => $p];
  return 'admin_students?' . http_build_query($params);
};
$studentsViewUrl = 'admin_students?' . http_build_query(['view' => 'students', 'tab' => $tab, 'q' => $q, 'page' => $page]);
$deletedViewUrl = 'admin_students?' . http_build_query(array_filter(['view' => 'deleted', 'dq' => $dq], static function ($v) {
  return $v !== '' && $v !== null;
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    .student-avatar-cell {
      position: relative;
      width: 2rem;
      height: 2rem;
      flex: 0 0 2rem;
      margin: 0;
      border-radius: 9999px;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: visible;
    }
    .student-avatar-media {
      width: 100%;
      height: 100%;
      border-radius: 9999px;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #334155;
      color: #fff;
      font-weight: 700;
      font-size: 0.72rem;
      border: 1.5px solid rgba(255,255,255,0.75);
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.2);
      text-transform: uppercase;
      line-height: 1;
    }
    .student-avatar-media img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .student-avatar-status-dot {
      position: absolute;
      right: -1px;
      bottom: -1px;
      width: 0.55rem;
      height: 0.55rem;
      border-radius: 9999px;
      border: 1.5px solid rgba(255,255,255,0.9);
      z-index: 2;
    }
    .student-avatar-status-dot--active {
      background: #22c55e;
      box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.28), 0 0 12px rgba(34, 197, 94, 0.85);
    }
    .student-avatar-status-dot--inactive {
      background: #9ca3af;
      box-shadow: 0 0 0 2px rgba(148, 163, 184, 0.24);
    }
    .admin-students-table thead th {
      font-size: 0.78rem;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      white-space: nowrap;
    }
    .admin-students-table tbody tr {
      transition: background-color 0.22s ease, transform 0.22s ease, box-shadow 0.22s ease;
    }
    .admin-students-table tbody tr:hover {
      box-shadow: inset 3px 0 0 rgba(52, 211, 153, 0.75);
      transform: none;
    }
    .admin-students-table tbody tr.student-row-priority-moved {
      animation: studentRowPriorityMove 520ms cubic-bezier(.2,.7,.2,1);
    }
    @keyframes studentRowPriorityMove {
      0% {
        transform: translateY(-4px);
        box-shadow: inset 4px 0 0 rgba(34, 197, 94, 0.9), 0 0 0 2px rgba(34, 197, 94, 0.18);
        background: linear-gradient(90deg, rgba(34, 197, 94, 0.18) 0%, rgba(16, 185, 129, 0.08) 100%);
      }
      100% {
        transform: translateY(0);
        box-shadow: none;
        background: transparent;
      }
    }
    .student-name {
      font-weight: 650;
      color: var(--admin-text, #ffffff);
      font-size: 0.86rem;
      line-height: 1.25;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      text-align: left;
    }
    .student-email {
      color: var(--admin-text, #ffffff);
      font-weight: 500;
      font-size: 0.8rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      letter-spacing: 0;
      display: block;
      text-align: left;
    }
    .student-email-cell {
      text-align: left;
      min-width: 0;
    }
    .student-extend-form {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      background: linear-gradient(160deg, rgba(249, 115, 22, 0.18) 0%, rgba(194, 65, 12, 0.12) 100%);
      border: 1px solid rgba(251, 146, 60, 0.52);
      border-radius: 0.62rem;
      padding: 0.22rem;
    }
    .student-extend-form input {
      width: 4.9rem;
      border: 1px solid rgba(254, 215, 170, 0.55);
      background: rgba(255,255,255,0.16);
      border-radius: 0.48rem;
      font-size: 0.74rem;
      font-weight: 700;
      color: #ffedd5;
      padding: 0.3rem 0.34rem;
      text-align: center;
    }
    .student-extend-form input:focus {
      outline: none;
      background: rgba(255,255,255,0.24);
      border-color: rgba(255, 237, 213, 0.9);
      box-shadow: 0 0 0 2px rgba(251, 146, 60, 0.25);
    }
    .student-extend-form input::placeholder { color: rgba(255, 237, 213, 0.84); }
    .student-extend-form select.student-extend-unit {
      width: 100%;
      border: 1px solid rgba(254, 215, 170, 0.55);
      background: rgba(255,255,255,0.16);
      border-radius: 0.48rem;
      font-size: 0.74rem;
      font-weight: 700;
      color: #ffedd5;
      padding: 0.3rem 0.34rem;
    }
    .student-pending-form {
      display: inline-flex;
      align-items: center;
      gap: 0.38rem;
      background: rgba(15, 23, 42, 0.22);
      border: 1px solid rgba(148, 163, 184, 0.32);
      border-radius: 0.66rem;
      padding: 0.22rem;
    }
    .student-pending-month-input {
      width: 4.9rem;
      border: 1px solid rgba(148, 163, 184, 0.45);
      background: rgba(255,255,255,0.12);
      border-radius: 0.48rem;
      font-size: 0.74rem;
      font-weight: 700;
      color: #f8fafc;
      padding: 0.3rem 0.34rem;
      text-align: center;
    }
    .student-pending-month-input::placeholder { color: rgba(226, 232, 240, 0.85); }
    .student-pending-month-input:focus {
      outline: none;
      border-color: rgba(147, 197, 253, 0.9);
      box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
      background: rgba(255,255,255,0.18);
    }
    .student-action-cell {
      text-align: right;
      vertical-align: middle;
      width: 64px;
      min-width: 64px;
      max-width: 64px;
    }
    .student-actions-head {
      text-align: right !important;
      width: 64px;
      min-width: 64px;
      max-width: 64px;
    }
    /* Consolidated row actions (aligned with professor exams pattern, admin dark theme) */
    .admin-student-action-menu-wrap {
      position: relative;
      display: flex;
      justify-content: flex-end;
      width: 100%;
    }
    .admin-student-action-menu-wrap.is-open {
      z-index: 120;
    }
    .admin-student-action-menu-trigger {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.4rem 0.75rem;
      border-radius: 0.58rem;
      border: 1px solid rgba(96, 165, 250, 0.5);
      background: linear-gradient(180deg, rgba(37, 99, 235, 0.38) 0%, rgba(29, 78, 216, 0.22) 100%);
      color: #e0f2fe;
      font-size: 0.74rem;
      font-weight: 700;
      line-height: 1.2;
      cursor: pointer;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
      transition: transform 0.15s ease, border-color 0.2s ease, background 0.2s ease, color 0.2s ease;
    }
    .admin-student-action-menu-trigger:hover {
      transform: translateY(-1px);
      border-color: rgba(147, 197, 253, 0.65);
      background: linear-gradient(180deg, rgba(59, 130, 246, 0.45) 0%, rgba(37, 99, 235, 0.3) 100%);
      color: #ffffff;
    }
    .admin-student-action-menu-trigger:focus-visible {
      outline: none;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.35);
    }
    .admin-student-action-menu {
      position: fixed;
      z-index: 1300;
      min-width: 208px;
      max-width: min(260px, calc(100vw - 1.5rem));
      padding: 0.35rem;
      border-radius: 0.62rem;
      border: 1px solid rgba(148, 163, 184, 0.38);
      background: #0f172a;
      box-shadow:
        0 0 0 1px rgba(0, 0, 0, 0.35),
        0 18px 48px rgba(0, 0, 0, 0.55);
      display: none;
    }
    .admin-student-action-menu.open {
      display: block;
    }
    .admin-student-action-item {
      display: flex;
      align-items: center;
      gap: 0.45rem;
      width: 100%;
      padding: 0.5rem 0.55rem;
      border-radius: 0.48rem;
      font-size: 0.8rem;
      font-weight: 600;
      color: #e2e8f0;
      text-decoration: none;
      border: 0;
      background: transparent;
      text-align: left;
      cursor: pointer;
      line-height: 1.25;
      transition: background 0.15s ease, color 0.15s ease;
    }
    .admin-student-action-item i {
      font-size: 1rem;
      opacity: 0.92;
    }
    .admin-student-action-item:hover {
      background: rgba(59, 130, 246, 0.22);
      color: #f8fafc;
    }
    .admin-student-action-item--disabled {
      opacity: 0.42;
      cursor: not-allowed;
      pointer-events: none;
    }
    .admin-student-action-item--reject {
      color: #fecaca;
    }
    .admin-student-action-item--reject:hover {
      background: rgba(239, 68, 68, 0.22);
      color: #fef2f2;
    }
    .admin-student-action-item--approve {
      color: #bbf7d0;
    }
    .admin-student-action-item--approve:hover {
      background: rgba(34, 197, 94, 0.2);
      color: #dcfce7;
    }
    .admin-student-action-item--extend {
      color: #fed7aa;
    }
    .admin-student-action-item--extend:hover {
      background: rgba(249, 115, 22, 0.22);
      color: #ffedd5;
    }
    .admin-student-action-item--danger {
      color: #fca5a5;
    }
    .admin-student-action-item--danger:hover {
      background: rgba(220, 38, 38, 0.28);
      color: #fecaca;
    }
    .admin-student-action-menu .student-pending-form,
    .admin-student-action-menu .student-extend-form {
      display: flex;
      flex-direction: column;
      align-items: stretch;
      gap: 0.45rem;
      padding: 0.45rem;
      margin: 0.35rem 0 0;
      border-top: 1px solid rgba(51, 65, 85, 0.85);
      background: rgba(15, 23, 42, 0.85);
      border-radius: 0.5rem;
    }
    .admin-student-action-menu .student-pending-month-input,
    .admin-student-action-menu .student-extend-form input[type="number"] {
      width: 100%;
      box-sizing: border-box;
    }
    .admin-student-action-menu form.admin-student-action-menu-reject-form {
      margin: 0.25rem 0 0;
      padding: 0;
    }
    .admin-student-action-item--section {
      margin-top: 0.35rem;
      padding-top: 0.55rem;
      border-top: 1px solid rgba(51, 65, 85, 0.9);
    }
    /* Access column: controlled width via colgroup / admin-students.css */
    .admin-students-table .admin-students-access-col {
      width: 18%;
      max-width: none;
      min-width: 0;
    }
    .access-cell {
      vertical-align: middle;
    }
    .access-window {
      display: inline-flex;
      flex-direction: column;
      align-items: stretch;
      gap: 0.32rem;
      text-align: left;
      padding: 0.32rem 0.5rem 0.38rem;
      border-radius: 0.55rem;
      border: 1px solid rgba(148, 163, 184, 0.32);
      background: linear-gradient(165deg, rgba(30, 41, 59, 0.45) 0%, rgba(15, 23, 42, 0.35) 100%);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
      width: max-content;
      max-width: none;
    }
    .access-window__headline {
      display: flex;
      align-items: center;
      gap: 0.35rem;
      min-width: min-content;
      line-height: 1.2;
    }
    .access-window__hourglass {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      width: 1.15rem;
      height: 1.15rem;
      transform-origin: 50% 50%;
      backface-visibility: hidden;
      will-change: transform;
      animation: adminAccessHourglassSpin 6s linear infinite;
    }
    .access-window__hourglass i {
      font-size: 0.78rem;
      color: rgba(186, 230, 253, 0.95);
      display: block;
      line-height: 1;
    }
    .access-window__headline-inner {
      display: flex;
      align-items: baseline;
      flex-wrap: nowrap;
      gap: 0.28rem;
      flex: 0 1 auto;
      min-width: min-content;
      font-size: 0.72rem;
      font-weight: 700;
      color: #f1f5f9;
    }
    .access-window__kw {
      flex-shrink: 0;
      color: rgba(186, 230, 253, 0.88);
      font-weight: 700;
      letter-spacing: 0.02em;
    }
    .access-window__pipe {
      flex-shrink: 0;
      color: rgba(148, 163, 184, 0.65);
      font-weight: 600;
      padding: 0 0.05rem;
    }
    .access-window__dates {
      flex: 0 0 auto;
      font-variant-numeric: tabular-nums;
      white-space: nowrap;
      overflow: visible;
    }
    .access-window__dates time {
      font-variant-numeric: tabular-nums;
    }
    .access-window__dash {
      margin: 0 0.12rem;
      color: rgba(148, 163, 184, 0.85);
      font-weight: 600;
    }
    .access-window__track {
      position: relative;
      height: 5px;
      border-radius: 999px;
      background: rgba(15, 23, 42, 0.72);
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.07);
    }
    .access-window__fill {
      display: block;
      height: 100%;
      border-radius: inherit;
      position: relative;
      overflow: hidden;
      background: linear-gradient(90deg, #22d3ee, #2563eb);
      transition: width 0.45s ease;
    }
    .access-window__fill::after {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: inherit;
      background: linear-gradient(
        100deg,
        transparent 0%,
        rgba(255, 255, 255, 0) 35%,
        rgba(255, 255, 255, 0.38) 50%,
        rgba(255, 255, 255, 0) 65%,
        transparent 100%
      );
      background-size: 220% 100%;
      animation: adminAccessBarShimmer 2.2s ease-in-out infinite;
      pointer-events: none;
    }
    .access-window__meta {
      font-size: 0.6rem;
      font-weight: 500;
      letter-spacing: 0.02em;
      color: rgba(148, 163, 184, 0.72);
      line-height: 1.2;
    }
    @keyframes adminAccessHourglassSpin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
    @keyframes adminAccessBarShimmer {
      0% { background-position: 120% 0; }
      100% { background-position: -120% 0; }
    }
    .access-window--ending .access-window__hourglass {
      animation-duration: 3s;
    }
    .access-window--expired .access-window__hourglass {
      animation: none;
    }
    .access-window--active .access-window__fill {
      background: linear-gradient(90deg, #38bdf8, #2563eb);
    }
    .access-window--ending .access-window__fill {
      background: linear-gradient(90deg, #fbbf24, #ea580c);
    }
    .access-window--ending .access-window__meta {
      color: rgba(253, 230, 138, 0.55);
    }
    .access-window--expired .access-window__fill {
      background: linear-gradient(90deg, #94a3b8, #64748b);
    }
    .access-window--expired .access-window__meta {
      color: rgba(252, 165, 165, 0.55);
    }
    .access-window--upcoming .access-window__fill {
      background: linear-gradient(90deg, #a78bfa, #6366f1);
    }
    .access-window--upcoming .access-window__meta {
      color: rgba(233, 213, 255, 0.55);
    }
    .access-window--partial .access-window__meta {
      color: rgba(148, 163, 184, 0.65);
      font-weight: 500;
      font-size: 0.58rem;
    }
    .access-window--empty {
      border-style: dashed;
      border-color: rgba(148, 163, 184, 0.35);
      background: rgba(15, 23, 42, 0.25);
    }
    .access-window__headline--empty .access-window__empty-icon {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 1.15rem;
      height: 1.15rem;
    }
    .access-window__headline--empty .access-window__empty-icon i {
      font-size: 0.78rem;
      color: rgba(186, 230, 253, 0.72);
      display: block;
      line-height: 1;
    }
    .access-window--empty .access-window__kw {
      color: rgba(226, 232, 240, 0.72);
    }
    .access-window--empty .access-window__dates {
      color: rgba(148, 163, 184, 0.75);
      font-weight: 600;
    }
    @media (prefers-reduced-motion: reduce) {
      .access-window__hourglass {
        animation: none !important;
      }
      .access-window__fill::after {
        animation: none !important;
      }
    }

    .admin-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(2, 6, 23, 0.52);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1200;
      backdrop-filter: blur(2px);
      padding: 1rem;
    }
    .admin-modal-overlay.is-open { display: flex; }
    .admin-modal {
      width: min(100%, 30rem);
      border-radius: 0.95rem;
      background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
      border: 1px solid rgba(148, 163, 184, 0.32);
      box-shadow: 0 26px 60px rgba(15, 23, 42, 0.38);
      padding: 1rem 1rem 1.15rem;
      animation: adminModalIn 0.2s ease forwards;
    }
    .admin-modal--danger {
      border-top: 4px solid #dc2626;
    }
    .admin-modal__hero {
      display: flex;
      align-items: flex-start;
      gap: 0.7rem;
      margin-bottom: 0.2rem;
    }
    .admin-modal__hero-icon {
      width: 2.1rem;
      height: 2.1rem;
      border-radius: 0.65rem;
      background: rgba(220, 38, 38, 0.12);
      color: #b91c1c;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.12rem;
      flex-shrink: 0;
      margin-top: 0.1rem;
    }
    @keyframes adminModalIn {
      from { opacity: 0; transform: translateY(10px) scale(0.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .admin-modal__title {
      margin: 0;
      font-size: 1.08rem;
      font-weight: 800;
      color: #111827;
    }
    .admin-modal__desc {
      margin: 0.45rem 0 0;
      color: #4b5563;
      font-size: 0.86rem;
      line-height: 1.45;
    }
    .admin-modal__warn {
      margin-top: 0.7rem;
      border: 1px solid rgba(239, 68, 68, 0.24);
      background: rgba(254, 242, 242, 0.8);
      color: #991b1b;
      border-radius: 0.62rem;
      padding: 0.54rem 0.62rem;
      font-size: 0.78rem;
      font-weight: 600;
      display: flex;
      gap: 0.45rem;
      align-items: flex-start;
    }
    .admin-modal__field {
      margin-top: 0.72rem;
    }
    .admin-modal__field label {
      display: block;
      margin-bottom: 0.3rem;
      font-size: 0.76rem;
      color: #374151;
      font-weight: 700;
    }
    .admin-modal__field input {
      width: 100%;
      border: 1px solid #cbd5e1;
      border-radius: 0.62rem;
      padding: 0.55rem 0.66rem;
      font-size: 0.86rem;
      color: #111827;
      background: #fff;
    }
    .admin-modal__field select {
      width: 100%;
      border: 1px solid #cbd5e1;
      border-radius: 0.62rem;
      padding: 0.55rem 0.66rem;
      font-size: 0.86rem;
      color: #111827;
      background: #fff;
      appearance: none;
      -webkit-appearance: none;
      padding-right: 0.9rem;
      background-image: none;
    }
    .admin-modal__field input:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18);
    }
    .admin-modal__field select:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18);
    }
    .admin-modal__error {
      margin-top: 0.45rem;
      color: #b91c1c;
      font-size: 0.78rem;
      font-weight: 700;
      min-height: 1.05rem;
    }
    .admin-modal__actions {
      margin-top: 0.88rem;
      display: flex;
      justify-content: flex-end;
      gap: 0.48rem;
    }
    .admin-modal__btn {
      border-radius: 0.62rem;
      font-weight: 700;
      font-size: 0.8rem;
      padding: 0.48rem 0.78rem;
      border: 1px solid transparent;
      cursor: pointer;
      transition: all 0.18s ease;
    }
    .admin-modal__btn--ghost {
      border-color: #cbd5e1;
      color: #334155;
      background: #fff;
    }
    .admin-modal__btn--ghost:hover { background: #f8fafc; }
    .admin-modal__btn--danger {
      background: #dc2626;
      border-color: #dc2626;
      color: #fff;
    }
    .admin-modal__btn--danger:hover { background: #b91c1c; border-color: #b91c1c; }
    .admin-modal__btn--ok {
      background: #2563eb;
      border-color: #2563eb;
      color: #fff;
    }
    .admin-modal__btn--ok:hover { background: #1d4ed8; border-color: #1d4ed8; }
    .admin-feedback-modal { width: min(100%, 24rem); }
    .admin-feedback-icon {
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
      margin-bottom: 0.5rem;
    }
    .admin-feedback-icon--error { background: #fef2f2; color: #b91c1c; }
    .admin-feedback-icon--success { background: #ecfdf5; color: #047857; }
    .admin-feedback-icon--pulse {
      animation: approvePulse 0.7s ease-out;
    }
    @keyframes approvePulse {
      0% { transform: scale(0.7); opacity: 0.5; }
      70% { transform: scale(1.08); opacity: 1; }
      100% { transform: scale(1); }
    }
    .deleted-log-table td, .deleted-log-table th {
      white-space: nowrap;
      font-size: 0.78rem;
    }
    /* Modal refresh v2 */
    .admin-modal-overlay {
      background: radial-gradient(circle at 20% 10%, rgba(30, 64, 175, 0.22) 0%, rgba(2, 6, 23, 0.78) 42%, rgba(2, 6, 23, 0.9) 100%);
      backdrop-filter: blur(6px);
      z-index: 1400;
    }
    .admin-modal {
      width: min(100%, 32rem);
      border-radius: 1rem;
      background: linear-gradient(180deg, rgba(15, 23, 42, 0.96) 0%, rgba(10, 15, 30, 0.97) 100%);
      border: 1px solid rgba(148, 163, 184, 0.28);
      box-shadow: 0 26px 70px rgba(2, 6, 23, 0.68);
      color: #e2e8f0;
      padding: 1.05rem 1.05rem 1.15rem;
    }
    .admin-modal__title { color: #f8fafc; font-size: 1.12rem; }
    .admin-modal__desc { color: rgba(226, 232, 240, 0.86); }
    .admin-modal__hero-icon { box-shadow: inset 0 1px 0 rgba(255,255,255,0.14); }
    .admin-modal__hero-icon--approve {
      background: rgba(16, 185, 129, 0.16);
      color: #34d399;
    }
    .admin-modal__warn {
      border-color: rgba(248, 113, 113, 0.34);
      background: rgba(127, 29, 29, 0.24);
      color: #fecaca;
    }
    .admin-modal__field label { color: rgba(226, 232, 240, 0.92); }
    .admin-modal__field input,
    .admin-modal__field select {
      border-color: rgba(148, 163, 184, 0.34);
      background: rgba(15, 23, 42, 0.7);
      color: #f8fafc;
    }
    .admin-modal__field input::placeholder { color: rgba(203, 213, 225, 0.7); }
    .admin-modal__field select {
      background-image: none;
    }
    .admin-modal__field input:focus,
    .admin-modal__field select:focus {
      border-color: rgba(96, 165, 250, 0.8);
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.22);
    }
    .admin-modal__btn {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      border-radius: 0.66rem;
      padding: 0.5rem 0.86rem;
      font-size: 0.81rem;
      letter-spacing: 0.01em;
    }
    .admin-modal__btn--ghost {
      border-color: rgba(148, 163, 184, 0.45);
      color: #e2e8f0;
      background: rgba(15, 23, 42, 0.7);
    }
    .admin-modal__btn--ghost:hover { background: rgba(30, 41, 59, 0.88); }
    .admin-modal__btn--ok {
      background: linear-gradient(145deg, #2563eb 0%, #1d4ed8 100%);
      border-color: rgba(96, 165, 250, 0.7);
      color: #eff6ff;
    }
    .admin-modal__btn--ok:hover {
      background: linear-gradient(145deg, #1d4ed8 0%, #1e40af 100%);
    }
    .admin-modal__btn--danger {
      background: linear-gradient(145deg, #dc2626 0%, #b91c1c 100%);
      border-color: rgba(248, 113, 113, 0.7);
      color: #fff1f2;
    }
    .admin-modal__btn--danger:hover {
      background: linear-gradient(145deg, #b91c1c 0%, #991b1b 100%);
    }
    .admin-feedback-modal {
      text-align: center;
      width: min(100%, 25rem);
    }
    .admin-feedback-icon {
      width: 2.8rem;
      height: 2.8rem;
      border-radius: 0.8rem;
      margin-bottom: 0.65rem;
    }
    .admin-loading-modal {
      text-align: center;
      width: min(100%, 22rem);
    }
    .admin-loading-ring {
      width: 2.7rem;
      height: 2.7rem;
      border-radius: 999px;
      border: 3px solid rgba(148, 163, 184, 0.35);
      border-top-color: #60a5fa;
      display: inline-block;
      animation: adminSpin 0.75s linear infinite;
      margin-bottom: 0.7rem;
    }
    @keyframes adminSpin { to { transform: rotate(360deg); } }

    .student-select-col { width: 2.75rem; text-align: center; vertical-align: middle; }
    .student-select-col input[type=checkbox] {
      width: 1.1rem; height: 1.1rem; accent-color: #2563eb; cursor: pointer;
    }
    html[data-admin-theme="light"] .student-select-col input[type=checkbox] {
      accent-color: #1d4ed8;
    }
    html[data-admin-theme="light"] .students-bulk-bar {
      border-color: rgba(22, 163, 74, 0.35);
      background: linear-gradient(145deg, #ecfdf5 0%, #f8fafc 100%);
      box-shadow: 0 10px 28px rgba(15, 23, 42, 0.1);
    }
    html[data-admin-theme="light"] .students-bulk-bar__count { color: #166534; }
    html[data-admin-theme="light"] .students-bulk-bar__hint { color: #475569; }
    .students-bulk-bar {
      position: sticky; bottom: 0.85rem; z-index: 40;
      display: none; flex-wrap: wrap; align-items: center; gap: 0.65rem;
      margin-top: 0.85rem; padding: 0.85rem 1rem;
      border-radius: 0.9rem; border: 1px solid rgba(52, 211, 153, 0.45);
      background: linear-gradient(145deg, rgba(6, 78, 59, 0.96) 0%, rgba(15, 23, 42, 0.97) 100%);
      box-shadow: 0 10px 28px rgba(0, 0, 0, 0.35);
    }
    .students-bulk-bar.is-visible { display: flex; }
    .students-bulk-bar__count { font-weight: 800; color: #a7f3d0; font-size: 0.88rem; }
    .students-bulk-bar__hint { color: rgba(226, 232, 240, 0.75); font-size: 0.78rem; flex: 1; min-width: 10rem; }
    .students-bulk-bar .admin-modal__btn { margin: 0; }
    .admin-modal--approve {
      width: min(100%, 34rem);
      max-height: min(90vh, 42rem);
      overflow: auto;
    }
    .approve-access-box {
      margin: 0.75rem 0 0.25rem;
      padding: 0.85rem;
      border-radius: 0.75rem;
      border: 1px solid rgba(148, 163, 184, 0.28);
      background: rgba(15, 23, 42, 0.55);
    }
    .approve-access-box .sca-tree {
      max-height: 14rem; overflow-y: auto; padding-right: 0.25rem;
    }
    .approve-access-box .sca-tree details {
      border: 1px solid rgba(148, 163, 184, 0.28); border-radius: 0.55rem;
      margin-bottom: 0.4rem; padding: 0.3rem 0.55rem; background: rgba(30, 41, 59, 0.75);
    }
    .approve-access-box .sca-tree summary {
      cursor: pointer; font-weight: 700; color: #e2e8f0; list-style: none; font-size: 0.84rem;
    }
    .approve-access-box .sca-tree summary::-webkit-details-marker { display: none; }
    .approve-access-box .sca-tree label {
      display: flex; align-items: center; gap: 0.4rem; padding: 0.2rem 0 0.2rem 0.85rem;
      font-size: 0.8rem; color: #cbd5e1; cursor: pointer; border-radius: 0.35rem;
    }
    .approve-access-box .sca-tree label:hover { background: rgba(51, 65, 85, 0.65); }
    .approve-access-box .sca-tree input[type=checkbox] { accent-color: #34d399; width: 0.95rem; height: 0.95rem; }
    .approve-access-box .text-gray-100 { color: #f1f5f9 !important; }
    .approve-access-box .text-gray-500 { color: #94a3b8 !important; }
    .approve-access-box .sca-tree-hint { color: #94a3b8; }
    .approve-access-box .sca-subject-summary { display: flex; align-items: center; gap: 0.4rem; }
    .approve-access-box .sca-chevron {
      width: 0.5rem; height: 0.5rem; border-right: 2px solid #94a3b8; border-bottom: 2px solid #94a3b8;
      transform: rotate(-45deg); flex-shrink: 0;
    }
    .approve-access-box details[open] > summary .sca-chevron { transform: rotate(45deg); }
    .approve-access-box .sca-subject-summary__meta {
      font-size: 0.65rem; font-weight: 700; color: #cbd5e1; background: rgba(51, 65, 85, 0.9);
      border-radius: 999px; padding: 0.1rem 0.4rem; margin-left: auto;
    }
    .approve-access-box .sca-grant-all {
      align-items: flex-start !important; margin: 0.35rem 0 0.45rem; padding: 0.45rem 0.55rem !important;
      border: 1px solid rgba(52, 211, 153, 0.35); border-radius: 0.5rem; background: rgba(6, 78, 59, 0.35);
    }
    .approve-access-box .sca-grant-all__title { display: block; font-weight: 800; color: #a7f3d0; font-size: 0.8rem; }
    .approve-access-box .sca-grant-all__sub { display: block; font-size: 0.7rem; color: #86efac; }
    .approve-access-box .sca-topic-list { border-left: 2px solid rgba(148, 163, 184, 0.35); margin-left: 0.3rem; padding-left: 0.35rem; }
    .approve-access-box .sca-topic-list__head { color: #94a3b8; font-size: 0.68rem; font-weight: 800; text-transform: uppercase; margin: 0.3rem 0 0.2rem; }
    .approve-access-box .sca-topic-check { color: #f1f5f9 !important; font-weight: 600; }
    .approve-access-customize {
      margin-top: 0.55rem; font-size: 0.78rem; color: #93c5fd; cursor: pointer; background: none; border: none; padding: 0;
      text-decoration: underline; text-underline-offset: 2px;
    }
    .approve-access-customize:hover { color: #bfdbfe; }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-students-page">
  <?php include 'admin_sidebar.php'; ?>

  <?php
    $adminHeroIcon = 'people';
    $adminHeroTitle = 'Students';
    $adminHeroSubtitle = 'Manage registered and enrolled reviewees.';
    $adminHeroActions = '<a class="admin-btn admin-btn--primary admin-btn--sm" href="admin_student_access"><i class="bi bi-plus-lg"></i> New Student</a>';
    include __DIR__ . '/includes/components/admin_page_hero.php';
  ?>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2">
      <i class="bi bi-check-circle-fill"></i>
      <span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <div class="students-page-shell">
    <nav class="students-view-tabs" aria-label="Students sections">
      <a href="<?php echo h($studentsViewUrl); ?>" class="students-view-tab <?php echo $view === 'students' ? 'is-active' : ''; ?>">Students</a>
      <a href="<?php echo h($deletedViewUrl); ?>" class="students-view-tab <?php echo $view === 'deleted' ? 'is-active' : ''; ?>">Deleted Users Log</a>
    </nav>

    <?php if ($view === 'deleted'): ?>
      <div class="students-toolbar page-filter">
        <form method="GET" class="students-toolbar__search">
          <input type="hidden" name="view" value="deleted">
          <div class="students-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="dq" value="<?php echo h($dq); ?>" placeholder="Search deleted users…" aria-label="Search deleted users">
          </div>
          <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm">Filter</button>
          <?php if ($dq !== ''): ?>
            <a href="admin_students?view=deleted" class="students-clear-link">Clear</a>
          <?php endif; ?>
        </form>
        <span class="students-toolbar__meta"><?php echo count($deletedLogs); ?> record<?php echo count($deletedLogs) === 1 ? '' : 's'; ?></span>
      </div>

      <div class="rounded-xl overflow-hidden page-table students-table-shell">
        <div class="students-table-scroll">
          <table class="w-full text-left deleted-log-table students-table--compact">
            <thead>
              <tr>
                <th>User ID</th>
                <th>Name</th>
                <th class="col-hide-mobile">Email</th>
                <th class="col-hide-tablet">School</th>
                <th class="col-hide-tablet">Review</th>
                <th class="col-hide-mobile">Access</th>
                <th class="col-hide-tablet">Deleted By</th>
                <th>Reason</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($deletedLogs)): ?>
                <tr>
                  <td colspan="9" class="students-empty-cell">
                    <?php echo $hasDeletedLogTable ? 'No deleted users logged yet.' : 'Deleted users log is not available.'; ?>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($deletedLogs as $dl): ?>
                  <?php
                    $deletedAccessLabel = (string)($dl['deleted_access_range'] ?? '-');
                    if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*(?:->|-)\s*(\d{4}-\d{2}-\d{2})$/', $deletedAccessLabel, $m)) {
                      $sTs = strtotime($m[1]);
                      $eTs = strtotime($m[2]);
                      if ($sTs && $eTs) {
                        $deletedAccessLabel = date('M j, Y', $sTs) . ' – ' . date('M j, Y', $eTs);
                      }
                    } elseif (preg_match('/^([A-Za-z]+\s+\d{1,2},\s+\d{4})\s*-\s*([A-Za-z]+\s+\d{1,2},\s+\d{4})$/', $deletedAccessLabel, $m2)) {
                      $deletedAccessLabel = $m2[1] . ' – ' . $m2[2];
                    }
                  ?>
                  <tr>
                    <td class="font-semibold"><?php echo (int)$dl['deleted_user_id']; ?></td>
                    <td><?php echo h($dl['deleted_name']); ?></td>
                    <td class="col-hide-mobile"><?php echo h($dl['deleted_email']); ?></td>
                    <td class="col-hide-tablet"><?php echo h((string)($dl['deleted_school'] ?? '-')); ?></td>
                    <td class="col-hide-tablet"><?php echo h((string)($dl['deleted_review_type'] ?? '-')); ?></td>
                    <td class="col-hide-mobile"><?php echo h($deletedAccessLabel); ?></td>
                    <td class="col-hide-tablet"><?php echo h($dl['deleted_by_admin_name']); ?></td>
                    <td><?php echo h((string)($dl['deletion_reason'] ?? '-')); ?></td>
                    <td class="whitespace-nowrap"><?php echo !empty($dl['deleted_at']) ? h(date('M j, Y g:i A', strtotime((string)$dl['deleted_at']))) : '-'; ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php else: ?>

      <div class="students-toolbar page-filter">
        <nav class="students-status-chips" aria-label="Filter by status">
          <?php
            $statusChips = [
              'enrolled' => ['Enrolled', 'bi-check2-circle'],
              'pending' => ['Needs review', 'bi-hourglass-split'],
              'expired' => ['Expired', 'bi-calendar-x'],
              'rejected' => ['Rejected', 'bi-x-circle'],
              'all' => ['All', 'bi-collection'],
            ];
            foreach ($statusChips as $key => $meta):
          ?>
            <a href="<?php echo h($mk($key, 1)); ?>" class="students-status-chip <?php echo $tab === $key ? 'is-active' : ''; ?>">
              <i class="bi <?php echo h($meta[1]); ?>" aria-hidden="true"></i>
              <span><?php echo h($meta[0]); ?></span>
              <span class="students-status-chip__count"><?php echo (int)$counts[$key]; ?></span>
            </a>
          <?php endforeach; ?>
        </nav>
        <form method="GET" class="students-toolbar__search">
          <input type="hidden" name="view" value="students">
          <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
          <div class="students-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="q" value="<?php echo h($q); ?>" placeholder="Search students…" aria-label="Search students">
          </div>
          <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-funnel"></i> Filter</button>
          <?php if ($q !== ''): ?>
            <a href="<?php echo h($mk($tab, 1)); ?>" class="students-clear-link">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="rounded-xl overflow-hidden page-table students-table-shell">
        <div class="students-table-meta">
          <span>
            <?php if ($total > 0): ?>
              <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $total); ?> of <?php echo (int)$total; ?>
            <?php else: ?>
              0 students
            <?php endif; ?>
          </span>
          <span class="students-table-meta__hint hidden md:inline">Enrollment, payment, proof, access, and account at a glance · Review opens payment/FAR when needed</span>
        </div>
        <div class="students-table-scroll">
          <table class="w-full text-left admin-students-table students-table--compact students-table--aligned students-table--commerce">
            <colgroup>
              <col class="col-check">
              <col class="col-student">
              <col class="col-enrollment">
              <col class="col-payment">
              <col class="col-proof">
              <col class="col-commerce-access">
              <col class="col-account-status">
              <col class="col-actions">
            </colgroup>
            <thead>
              <tr>
                <th class="student-select-col" scope="col">
                  <input type="checkbox" id="studentSelectAll" class="admin-bulk-check"
                         title="Select all actionable students on this page (legacy pending or Repair Activation only)"
                         aria-label="Select all actionable students on this page">
                </th>
                <th class="col-student" scope="col">Student</th>
                <th class="col-enrollment" scope="col">Enrollment</th>
                <th class="col-payment" scope="col">Payment</th>
                <th class="col-proof" scope="col">Proof</th>
                <th class="col-commerce-access" scope="col">Access</th>
                <th class="col-account-status" scope="col">Account</th>
                <th class="col-actions student-actions-head" scope="col">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($total === 0): ?>
                <?php
                  $emptyHint = 'Try changing the filter or clearing search.';
                  if ($tab === 'pending') $emptyHint = 'Students requiring review or still awaiting payment/verification will appear here.';
                  elseif ($tab === 'enrolled') $emptyHint = 'Active students with an approved login account will appear here.';
                  elseif ($tab === 'expired') $emptyHint = 'Students whose account window has ended will appear here.';
                  elseif ($tab === 'rejected') $emptyHint = 'Rejected registrations will appear here.';
                ?>
                <tr>
                  <td colspan="8" class="students-empty-cell">
                    <div class="font-semibold">No students found</div>
                    <p class="text-sm mt-1 mb-0"><?php echo h($emptyHint); ?></p>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($studentRows as $row): ?>
                  <?php
                    $schoolLabel = $row['school'] === 'Other' && !empty($row['school_other']) ? $row['school_other'] : $row['school'];
                    $dash = $studentBadgeMap[(int) $row['user_id']] ?? [];
                    $badgeInfo = $dash;
                    $enrollPathRow = (string) ($row['enrollment_path'] ?? ($dash['enrollment_path'] ?? ''));
                    $isCommerceRow = commerce_admin_is_commerce_enrollment_path($enrollPathRow);
                    $commerceProofUrl = (string) ($dash['proof_url'] ?? '');
                    $hasCommerceProof = !empty($dash['has_proof']);
                    $proofUi = (string) ($dash['proof_ui'] ?? ($hasCommerceProof ? 'View Proof' : (!empty($dash['is_free_access']) ? 'N/A' : 'Not Uploaded')));
                    $primaryActionHref = (string) ($dash['action_href'] ?? ('admin_student_view?id=' . (int) $row['user_id']));
                    $primaryActionLabel = (string) ($dash['action_label'] ?? 'View');
                    $payTone = (string) ($dash['payment_tone'] ?? 'neutral');
                    $accessTone = (string) ($dash['access_tone'] ?? 'none');
                    $showRepairActivation = !empty($dash['show_repair_activation']) || !empty($dash['activation_required']);
                    $accountUi = (string) ($dash['account_label'] ?? commerce_admin_label_account_status((string) $row['status']));
                    $fulfilledUi = (string) ($dash['fulfilled_ui'] ?? '');
                    $enrollAmt = (string) ($dash['enrollment_amount_display'] ?? '—');
                    $enrollLab = (string) ($dash['enrollment_label'] ?? '—');
                    $enrollCombined = $enrollLab;
                    if ($enrollAmt !== '' && $enrollAmt !== '—' && strpos($enrollLab, '₱') === false) {
                        $enrollCombined = $enrollLab . ' · ' . $enrollAmt;
                    }
                    $hasAccessRange = !empty($row['access_start']) || !empty($row['access_end']);
                    $accessStartTs = !empty($row['access_start']) ? strtotime((string)$row['access_start']) : false;
                    $accessEndTs = !empty($row['access_end']) ? strtotime((string)$row['access_end']) : false;
                    $accessStartShort = ($accessStartTs !== false) ? date('M j, Y', $accessStartTs) : '?';
                    $accessEndShort = ($accessEndTs !== false) ? date('M j, Y', $accessEndTs) : '?';
                    $accessWindowTone = 'partial';
                    $accessWindowPct = null;
                    $accessWindowMeta = '';
                    $nowTs = time();
                    if ($accessStartTs !== false && $accessEndTs !== false && $accessEndTs > $accessStartTs) {
                      $totalSec = $accessEndTs - $accessStartTs;
                      if ($nowTs < $accessStartTs) {
                        $accessWindowPct = 0.0;
                        $accessWindowTone = 'upcoming';
                        $d = (int) ceil(($accessStartTs - $nowTs) / 86400);
                        $accessWindowMeta = $d <= 0 ? 'Starts today' : ('Starts in ' . $d . 'd');
                      } elseif ($nowTs > $accessEndTs) {
                        $accessWindowPct = 100.0;
                        $accessWindowTone = 'expired';
                        $d = (int) floor(($nowTs - $accessEndTs) / 86400);
                        $accessWindowMeta = $d <= 0 ? 'Ended today' : ('Ended ' . $d . 'd ago');
                      } else {
                        $elapsed = $nowTs - $accessStartTs;
                        $accessWindowPct = round(min(100, max(0, ($elapsed / $totalSec) * 100)), 1);
                        $d = (int) ceil(($accessEndTs - $nowTs) / 86400);
                        $accessWindowMeta = $d <= 0 ? 'Ends today' : ($d . ' days left');
                        $accessWindowTone = ($d <= 7) ? 'ending' : 'active';
                      }
                    } elseif ($hasAccessRange) {
                      $accessWindowMeta = 'Incomplete dates';
                      $accessWindowTone = 'partial';
                    }
                    $statusClass = strtolower((string)$row['status']);
                    $badgeClass = $statusClass === 'approved' ? 'bg-green-100 text-green-800' : ($statusClass === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800');
                    $hasProof = !empty($row['payment_proof']);
                    $isExpired = ($statusClass === 'approved' && !empty($row['access_end']) && strtotime($row['access_end']) < time());
                    $avatarPath = ereview_avatar_public_path($row['profile_picture'] ?? '');
                    $useDefaultAvatar = $hasUseDefaultAvatar ? !empty($row['use_default_avatar']) : true;
                    $avatarInitial = ereview_avatar_initial($row['full_name'] ?? 'U');
                    $isSessionActive = false;
                    $recentThresholdTs = time() - (2 * 60);
                    if ($hasLastSeenAt && !empty($row['last_seen_at'])) {
                      $lastSeenTs = strtotime((string)$row['last_seen_at']);
                      if ($lastSeenTs !== false && $lastSeenTs >= $recentThresholdTs) $isSessionActive = true;
                    } elseif ($hasLastLoginAt && !empty($row['last_login_at'])) {
                      $lastLoginTs = strtotime((string)$row['last_login_at']);
                      if ($lastLoginTs !== false && $lastLoginTs >= $recentThresholdTs) $isSessionActive = true;
                    } elseif (!$hasLastSeenAt && !$hasLastLoginAt && $hasIsOnline && !empty($row['is_online'])) {
                      $isSessionActive = true;
                    }
                    if ($hasLastLogoutAt && !empty($row['last_logout_at'])) {
                      $lastLogoutTs = strtotime((string)$row['last_logout_at']);
                      $lastSeenTs2 = (!empty($row['last_seen_at']) ? strtotime((string)$row['last_seen_at']) : false);
                      if ($lastLogoutTs !== false && ($lastSeenTs2 === false || $lastSeenTs2 <= $lastLogoutTs)) {
                        $isSessionActive = false;
                      }
                    }
                    $reviewType = strtolower((string)($row['review_type'] ?? ''));
                    $reviewLabel = $reviewType === 'undergrad' ? 'Undergrad' : 'Reviewee';
                    $activityTs = false;
                    if ($hasLastSeenAt && !empty($row['last_seen_at'])) {
                      $activityTs = strtotime((string)$row['last_seen_at']);
                    } elseif ($hasLastLoginAt && !empty($row['last_login_at'])) {
                      $activityTs = strtotime((string)$row['last_login_at']);
                    }
                    $activityLabel = '—';
                    if ($isSessionActive) {
                      $activityLabel = 'Active now';
                    } elseif ($activityTs !== false) {
                      $diff = time() - $activityTs;
                      if ($diff < 3600) $activityLabel = max(1, (int)floor($diff / 60)) . 'm ago';
                      elseif ($diff < 86400) $activityLabel = (int)floor($diff / 3600) . 'h ago';
                      elseif ($diff < 86400 * 7) $activityLabel = (int)floor($diff / 86400) . 'd ago';
                      else $activityLabel = date('M j, Y', $activityTs);
                    }
                    $accessScan = $hasAccessRange
                      ? ($accessEndTs !== false ? $accessEndShort : $accessStartShort)
                      : 'No access';
                    $accessFull = $hasAccessRange ? ($accessStartShort . ' – ' . $accessEndShort) : 'No access set';
                    $createdLabel = !empty($row['created_at']) ? date('M j, Y', strtotime((string)$row['created_at'])) : '—';
                  ?>
                  <tr
                    data-user-id="<?php echo (int)$row['user_id']; ?>"
                    data-student-name="<?php echo h($row['full_name']); ?>"
                    data-approvable="<?php echo ((!$isCommerceRow && $row['status'] !== 'approved') || $showRepairActivation) ? '1' : '0'; ?>"
                    data-enrollment-path="<?php echo h($enrollPathRow); ?>"
                    data-commerce-enrollment="<?php echo $isCommerceRow ? '1' : '0'; ?>"
                    data-repair-activation="<?php echo $showRepairActivation ? '1' : '0'; ?>"
                    data-drawer-name="<?php echo h($row['full_name']); ?>"
                    data-drawer-email="<?php echo h($row['email']); ?>"
                    data-drawer-school="<?php echo h($schoolLabel ?: 'Not set'); ?>"
                    data-drawer-review="<?php echo h($reviewLabel); ?>"
                    data-drawer-status="<?php echo h($accountUi); ?>"
                    data-drawer-access="<?php echo h($accessFull); ?>"
                    data-drawer-access-meta="<?php echo h($accessWindowMeta); ?>"
                    data-drawer-activity="<?php echo h($activityLabel); ?>"
                    data-drawer-created="<?php echo h($createdLabel); ?>"
                    data-drawer-proof="<?php echo h($proofUi); ?>"
                    data-drawer-enrollment="<?php echo h($enrollCombined); ?>"
                    data-drawer-payment="<?php echo h((string) ($dash['payment_ui'] ?? '—')); ?>"
                    data-drawer-commerce-access="<?php echo h((string) ($dash['access_ui'] ?? 'None')); ?>"
                    data-drawer-account="<?php echo h($accountUi); ?>"
                    data-drawer-avatar="<?php echo h(($avatarPath !== '' && !$useDefaultAvatar) ? $avatarPath : ''); ?>"
                    data-drawer-initial="<?php echo h($avatarInitial); ?>"
                  >
                    <td class="student-select-col">
                      <?php if ((!$isCommerceRow && $row['status'] !== 'approved') || $showRepairActivation): ?>
                        <input type="checkbox" class="js-student-select admin-bulk-check" value="<?php echo (int)$row['user_id']; ?>" aria-label="Select <?php echo h($row['full_name']); ?>">
                      <?php else: ?>
                        <span class="admin-bulk-check-na" title="Not bulk-selectable (paid flow auto-activates; use Payment Verification for proofs)">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="col-student">
                      <div class="student-cell">
                        <span class="student-avatar-cell" aria-hidden="true">
                          <span class="student-avatar-media">
                            <?php if ($avatarPath !== '' && !$useDefaultAvatar): ?>
                              <img src="<?php echo h($avatarPath); ?>" alt="" loading="lazy">
                            <?php else: ?>
                              <?php echo h($avatarInitial); ?>
                            <?php endif; ?>
                          </span>
                          <span data-status-dot class="student-avatar-status-dot <?php echo $isSessionActive ? 'student-avatar-status-dot--active' : 'student-avatar-status-dot--inactive'; ?>"></span>
                        </span>
                        <div class="student-cell__text">
                          <div class="student-name" title="<?php echo h($row['full_name']); ?>"><?php echo h($row['full_name']); ?></div>
                          <div class="student-meta" title="<?php echo h($row['email']); ?>"><?php echo h($row['email']); ?></div>
                        </div>
                      </div>
                    </td>
                    <td class="col-enrollment">
                      <div class="font-semibold text-sm text-slate-800" title="<?php echo h($enrollCombined); ?>"><?php echo h($enrollCombined); ?></div>
                    </td>
                    <td class="col-payment">
                      <span class="commerce-pill commerce-pill--<?php echo h($payTone); ?>">
                        <?php if ($payTone === 'verified'): ?><i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                        <?php elseif ($payTone === 'review'): ?><i class="bi bi-hourglass-split" aria-hidden="true"></i>
                        <?php elseif ($payTone === 'rejected'): ?><i class="bi bi-x-circle-fill" aria-hidden="true"></i>
                        <?php elseif ($payTone === 'awaiting'): ?><i class="bi bi-clock" aria-hidden="true"></i>
                        <?php endif; ?>
                        <?php echo h((string) ($dash['payment_ui'] ?? '—')); ?>
                      </span>
                      <?php if ($fulfilledUi !== ''): ?>
                        <div class="text-[10px] text-emerald-400/90 mt-0.5 font-semibold"><?php echo h($fulfilledUi); ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="col-proof">
                      <?php if (!empty($dash['is_free_access']) || $proofUi === 'N/A'): ?>
                        <span class="text-slate-400 text-sm">N/A</span>
                      <?php elseif ($hasCommerceProof && !empty($dash['payment_id'])): ?>
                        <a class="commerce-proof-link" data-admin-proof
                           data-proof-title="Proof · <?php echo h($row['full_name']); ?>"
                           href="<?php echo h(ereview_url('payment_proof_file') . '?payment_id=' . (int) $dash['payment_id']); ?>"
                           title="View commerce payment proof">
                          <i class="bi bi-eye" aria-hidden="true"></i> View Proof
                        </a>
                      <?php else: ?>
                        <span class="text-slate-400 text-sm">Not Uploaded</span>
                      <?php endif; ?>
                    </td>
                    <td class="col-commerce-access">
                      <span class="commerce-pill commerce-pill--access-<?php echo h($accessTone); ?>">
                        <?php if ($accessTone === 'granted'): ?><i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                        <?php elseif ($accessTone === 'pending'): ?><i class="bi bi-hourglass-split" aria-hidden="true"></i>
                        <?php elseif ($accessTone === 'none' || $accessTone === 'revoked'): ?><i class="bi bi-x-circle" aria-hidden="true"></i>
                        <?php endif; ?>
                        <?php echo h((string) ($dash['access_ui'] ?? 'None')); ?>
                      </span>
                    </td>
                    <td class="col-account-status">
                      <span class="commerce-pill <?php echo $accountUi === 'Active' ? 'commerce-pill--verified' : ($accountUi === 'Rejected' ? 'commerce-pill--rejected' : 'commerce-pill--awaiting'); ?>">
                        <?php if ($accountUi === 'Active'): ?><i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                        <?php else: ?><i class="bi bi-hourglass-split" aria-hidden="true"></i>
                        <?php endif; ?>
                        <?php echo h($accountUi); ?>
                      </span>
                    </td>
                    <td class="student-action-cell col-actions">
                      <div class="flex flex-wrap items-center gap-2 justify-end">
                        <a class="admin-btn admin-btn--<?php echo ($primaryActionLabel === 'Review') ? 'primary' : 'secondary'; ?> admin-btn--sm"
                           href="<?php echo h($primaryActionHref); ?>">
                          <?php echo h($primaryActionLabel); ?>
                        </a>
                        <div class="admin-student-action-menu-wrap" data-admin-student-action-menu>
                          <button type="button" class="admin-student-action-menu-trigger admin-student-action-menu-trigger--icon" data-action-menu-trigger aria-expanded="false" aria-haspopup="true" aria-label="More actions for <?php echo h($row['full_name']); ?>">
                            <i class="bi bi-three-dots" aria-hidden="true"></i>
                          </button>
                          <div class="admin-student-action-menu" data-action-menu-list role="menu">
                            <a role="menuitem" class="admin-student-action-item" href="admin_student_view?id=<?php echo (int)$row['user_id']; ?>"><i class="bi bi-person-badge" aria-hidden="true"></i> Student detail</a>
                            <?php if (!empty($dash['payment_id'])): ?>
                              <a role="menuitem" class="admin-student-action-item" href="<?php echo h(ereview_url('admin_commerce_payments') . '?id=' . (int) $dash['payment_id']); ?>"><i class="bi bi-credit-card" aria-hidden="true"></i> View payment</a>
                            <?php endif; ?>
                            <?php if ($hasCommerceProof && !empty($dash['payment_id'])): ?>
                              <a role="menuitem" class="admin-student-action-item" data-admin-proof
                                 data-proof-title="Proof · <?php echo h($row['full_name']); ?>"
                                 href="<?php echo h(ereview_url('payment_proof_file') . '?payment_id=' . (int) $dash['payment_id']); ?>"><i class="bi bi-receipt" aria-hidden="true"></i> View Proof</a>
                            <?php elseif (!$isCommerceRow && $hasProof): ?>
                              <a role="menuitem" class="admin-student-action-item" href="admin_payment_proof?user_id=<?php echo (int)$row['user_id']; ?>" target="_blank" rel="noopener"><i class="bi bi-receipt" aria-hidden="true"></i> Legacy payment proof</a>
                            <?php endif; ?>
                            <a role="menuitem" class="admin-student-action-item" href="<?php echo h(ereview_url('admin_commerce_grants') . '?user_id=' . (int) $row['user_id']); ?>"><i class="bi bi-journal-text" aria-hidden="true"></i> Grant ledger</a>
                            <a role="menuitem" class="admin-student-action-item" href="admin_student_access?user_id=<?php echo (int)$row['user_id']; ?>"><i class="bi bi-shield-lock" aria-hidden="true"></i> Manual / Administrative Access</a>
                            <?php if ($isCommerceRow && $showRepairActivation): ?>
                              <button type="button" class="admin-student-action-item admin-student-action-item--approve js-approve-one-btn" role="menuitem" data-user-id="<?php echo (int)$row['user_id']; ?>" data-student-name="<?php echo h($row['full_name']); ?>" data-commerce-enrollment="1">
                                <i class="bi bi-wrench" aria-hidden="true"></i> Repair Activation
                              </button>
                            <?php elseif (!$isCommerceRow && $row['status'] !== 'approved'): ?>
                              <button type="button" class="admin-student-action-item admin-student-action-item--approve js-approve-one-btn" role="menuitem" data-user-id="<?php echo (int)$row['user_id']; ?>" data-student-name="<?php echo h($row['full_name']); ?>" data-commerce-enrollment="0">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i> Approve
                              </button>
                            <?php endif; ?>
                            <?php if ($row['status'] !== 'approved'): ?>
                              <form class="admin-student-action-menu-reject-form" action="reject" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                                <button type="submit" class="admin-student-action-item admin-student-action-item--reject" role="menuitem"><i class="bi bi-x-circle" aria-hidden="true"></i> Reject</button>
                              </form>
                            <?php else: ?>
                              <form class="student-extend-form" action="extend_access" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                                <input type="hidden" name="mode" value="extend">
                                <input type="hidden" name="return_to" value="admin_students?<?php echo h(http_build_query(['view' => 'students', 'tab' => $tab, 'q' => $q, 'page' => $page])); ?>">
                                <label class="sr-only" for="extend-duration-<?php echo (int)$row['user_id']; ?>">Duration to extend</label>
                                <input id="extend-duration-<?php echo (int)$row['user_id']; ?>" type="number" min="1" name="duration_value" placeholder="+ Amount" required title="Amount to add">
                                <select name="duration_unit" class="student-extend-unit" aria-label="Duration unit" title="Days, months, or years">
                                  <option value="day">Days</option>
                                  <option value="month" selected>Months</option>
                                  <option value="year">Years</option>
                                </select>
                                <button type="submit" class="admin-student-action-item admin-student-action-item--extend" role="menuitem"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Extend / edit window</button>
                              </form>
                              <a role="menuitem" class="admin-student-action-item" href="admin_student_view?id=<?php echo (int)$row['user_id']; ?>#account-window-edit"><i class="bi bi-pencil-square" aria-hidden="true"></i> Edit account window</a>
                            <?php endif; ?>
                            <button type="button" class="admin-student-action-item admin-student-action-item--danger admin-student-action-item--section js-delete-student-btn" role="menuitem" data-user-id="<?php echo (int)$row['user_id']; ?>" data-user-name="<?php echo h($row['full_name']); ?>" data-user-email="<?php echo h($row['email']); ?>">
                              <i class="bi bi-trash" aria-hidden="true"></i> Delete
                            </button>
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

        <?php if ($totalPages > 1): ?>
          <nav class="students-pagination" aria-label="Student pagination">
            <ul>
              <?php if ($page > 1): ?>
                <li><a href="<?php echo h($mk($tab, $page - 1)); ?>">Previous</a></li>
              <?php endif; ?>
              <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li><a href="<?php echo h($mk($tab, $i)); ?>" class="<?php echo $i === $page ? 'is-active' : ''; ?>"><?php echo $i; ?></a></li>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
                <li><a href="<?php echo h($mk($tab, $page + 1)); ?>">Next</a></li>
              <?php endif; ?>
            </ul>
          </nav>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (isset($stmt) && $stmt instanceof mysqli_stmt) { mysqli_stmt_close($stmt); } ?>

  <div id="studentDetailsDrawer" class="student-drawer" aria-hidden="true">
    <div class="student-drawer__backdrop" data-drawer-close></div>
    <aside class="student-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="studentDrawerTitle">
      <header class="student-drawer__header">
        <div class="student-drawer__identity">
          <span class="student-drawer__avatar" id="studentDrawerAvatar" aria-hidden="true">U</span>
          <div class="min-w-0">
            <h2 id="studentDrawerTitle" class="student-drawer__title">Student</h2>
            <p class="student-drawer__email" id="studentDrawerEmail">—</p>
          </div>
        </div>
        <button type="button" class="student-drawer__close" data-drawer-close aria-label="Close details"><i class="bi bi-x-lg"></i></button>
      </header>
      <div class="student-drawer__body">
        <section class="student-drawer__section">
          <h3>Enrollment &amp; Commerce</h3>
          <dl class="student-drawer__dl">
            <div><dt>Enrollment</dt><dd id="studentDrawerEnrollment">—</dd></div>
            <div><dt>Payment</dt><dd id="studentDrawerPayment">—</dd></div>
            <div><dt>Proof</dt><dd id="studentDrawerProof">—</dd></div>
            <div><dt>Commerce access</dt><dd id="studentDrawerCommerceAccess">—</dd></div>
            <div><dt>Account</dt><dd id="studentDrawerAccount">—</dd></div>
          </dl>
        </section>
        <section class="student-drawer__section">
          <h3>Profile</h3>
          <dl class="student-drawer__dl">
            <div><dt>Login status</dt><dd id="studentDrawerStatus">—</dd></div>
            <div><dt>School</dt><dd id="studentDrawerSchool">—</dd></div>
            <div><dt>Review type</dt><dd id="studentDrawerReview">—</dd></div>
            <div><dt>Registered</dt><dd id="studentDrawerCreated">—</dd></div>
          </dl>
        </section>
        <section class="student-drawer__section">
          <h3>Account window (login)</h3>
          <dl class="student-drawer__dl">
            <div><dt>Window</dt><dd id="studentDrawerAccess">—</dd></div>
            <div><dt>Timeline</dt><dd id="studentDrawerAccessMeta">—</dd></div>
            <div><dt>Last activity</dt><dd id="studentDrawerActivity">—</dd></div>
          </dl>
          <p class="text-xs opacity-70 mt-2 mb-0">Commerce grant dates are on the student detail / grant ledger. Manual / Administrative Access is separate from purchased access.</p>
        </section>
      </div>
      <footer class="student-drawer__footer">
        <a id="studentDrawerFullLink" href="admin_students" class="admin-btn admin-btn--secondary admin-btn--sm">Open full page</a>
        <a id="studentDrawerAccessLink" href="admin_student_access" class="admin-btn admin-btn--secondary admin-btn--sm">Manual / Admin Access</a>
      </footer>
    </aside>
  </div>

  <div id="studentsBulkBar" class="students-bulk-bar" aria-live="polite">
    <span class="students-bulk-bar__count"><span id="studentsBulkCount">0</span> selected</span>
    <span class="students-bulk-bar__hint">Only rows with a checkbox are bulk-selectable (legacy pending or Repair Activation). Paid students awaiting payment review are handled in Payment Verification — not here.</span>
    <button type="button" id="studentsBulkClearBtn" class="admin-modal__btn admin-modal__btn--ghost">Clear</button>
    <button type="button" id="studentsBulkApproveBtn" class="admin-modal__btn admin-modal__btn--ok"><i class="bi bi-check2-circle"></i> Continue</button>
  </div>
</div>
</main>
<div id="approveConfirmModalOverlay" class="admin-modal-overlay" aria-hidden="true">
  <section class="admin-modal admin-modal--approve" role="dialog" aria-modal="true" aria-labelledby="approveConfirmTitle" x-data="approveAccessPicker()" x-init="init()">
    <div class="admin-modal__hero">
      <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-patch-check"></i></span>
      <div>
        <h3 id="approveConfirmTitle" class="admin-modal__title">Repair Activation</h3>
        <p class="admin-modal__desc" id="approveConfirmDesc">Exceptional repair only when commerce access is already granted but login is still pending.</p>
        <p class="admin-modal__desc"><strong id="approveConfirmStudentName">Student</strong></p>
      </div>
    </div>
    <div class="admin-modal__field">
      <label for="approveConfirmMonths">Account window</label>
      <div style="display:flex;gap:0.5rem;align-items:center;">
        <input type="number" id="approveConfirmMonths" min="1" max="3660" value="6" required style="flex:1;">
        <select id="approveConfirmUnit" aria-label="Duration unit" style="min-width:7rem;">
          <option value="day">Days</option>
          <option value="month" selected>Months</option>
          <option value="year">Years</option>
        </select>
      </div>
    </div>
    <div id="approveCommerceNotice" class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 mb-2" hidden>
      Commerce enrollment detected. This activates <strong>login only</strong>. Paid content comes from payment fulfillment; Free Access comes from FAR approval. The SCA picker is not used.
    </div>
    <div class="approve-access-box" id="approveLegacyScaBox">
      <p class="text-xs font-semibold text-slate-300 mb-1">Manual / Administrative Access (legacy students only)</p>
      <?php
        $scaTreeScope = 'approve';
        require __DIR__ . '/includes/admin_sca_permission_tree.php';
      ?>
      <p class="text-xs text-gray-500 m-0 mt-2" x-show="loadingCatalog">Loading content catalog…</p>
      <p class="text-xs m-0 mt-2" style="color:#a7f3d0;" x-text="'Access: ' + activePermCount"></p>
    </div>
    <div id="approveConfirmError" class="admin-modal__error"></div>
    <div class="admin-modal__actions">
      <button type="button" id="approveConfirmCancelBtn" class="admin-modal__btn admin-modal__btn--ghost">Cancel</button>
      <button type="button" id="approveConfirmSubmitBtn" class="admin-modal__btn admin-modal__btn--ok"><i class="bi bi-check2-circle"></i> Confirm activate</button>
    </div>
  </section>
</div>

<div id="approveSuccessModalOverlay" class="admin-modal-overlay" aria-hidden="true">
  <section class="admin-modal admin-feedback-modal" role="dialog" aria-modal="true" aria-labelledby="approveSuccessTitle">
    <span class="admin-feedback-icon admin-feedback-icon--success admin-feedback-icon--pulse"><i class="bi bi-check-circle-fill"></i></span>
    <h3 id="approveSuccessTitle" class="admin-modal__title">Student Approved</h3>
    <p id="approveSuccessMessage" class="admin-modal__desc">The student has been successfully approved.</p>
    <p class="admin-modal__desc" style="margin-top:0.35rem;">Where do you want to go next?</p>
    <div class="admin-modal__actions" style="flex-wrap:wrap; justify-content:center;">
      <button type="button" id="approveSuccessStayBtn" class="admin-modal__btn admin-modal__btn--ghost"><i class="bi bi-hourglass-split"></i> Stay on Pending</button>
      <button type="button" id="approveSuccessContinueBtn" class="admin-modal__btn admin-modal__btn--ok"><i class="bi bi-people"></i> Go to Enrolled</button>
    </div>
  </section>
</div>
<div id="actionLoadingModalOverlay" class="admin-modal-overlay" aria-hidden="true">
  <section class="admin-modal admin-feedback-modal admin-loading-modal" role="dialog" aria-modal="true" aria-label="Processing">
    <span class="admin-loading-ring" aria-hidden="true"></span>
    <h3 class="admin-modal__title" id="actionLoadingTitle">Processing request...</h3>
    <p class="admin-modal__desc" id="actionLoadingMessage">Please wait while we complete this action.</p>
  </section>
</div>
<div id="deleteStudentModalOverlay" class="admin-modal-overlay" aria-hidden="true">
  <section class="admin-modal admin-modal--danger" role="dialog" aria-modal="true" aria-labelledby="deleteStudentModalTitle">
    <div class="admin-modal__hero">
      <span class="admin-modal__hero-icon"><i class="bi bi-shield-exclamation"></i></span>
      <div>
        <h3 id="deleteStudentModalTitle" class="admin-modal__title">Delete Student Account</h3>
        <p class="admin-modal__desc">You are about to permanently delete <strong id="deleteStudentModalName">this student</strong>.</p>
      </div>
    </div>
    <div class="admin-modal__warn">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span>This action is irreversible and will remove the account permanently. Enter your admin password to continue.</span>
    </div>
    <form id="deleteStudentForm">
      <input type="hidden" id="deleteStudentUserId" value="">
      <div class="admin-modal__field">
        <label for="deleteStudentReason">Reason for deletion</label>
        <select id="deleteStudentReason" required>
          <option value="">Select a reason...</option>
          <option value="duplicate">Duplicate account</option>
          <option value="fraud">Fraud or invalid registration</option>
          <option value="request">Requested by user</option>
          <option value="inactive">Inactive or abandoned account</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="admin-modal__field" id="deleteStudentReasonOtherWrap" style="display:none;">
        <label for="deleteStudentReasonOther">Specify reason</label>
        <input id="deleteStudentReasonOther" type="text" maxlength="220" placeholder="Type the specific deletion reason">
      </div>
      <div class="admin-modal__field">
        <label for="deleteStudentAdminPassword">Admin Password</label>
        <input id="deleteStudentAdminPassword" type="password" autocomplete="current-password" required placeholder="Enter admin password">
      </div>
      <div id="deleteStudentModalError" class="admin-modal__error"></div>
      <div class="admin-modal__actions">
        <button type="button" id="deleteStudentCancelBtn" class="admin-modal__btn admin-modal__btn--ghost">Cancel</button>
        <button type="submit" id="deleteStudentConfirmBtn" class="admin-modal__btn admin-modal__btn--danger">Confirm Delete</button>
      </div>
    </form>
  </section>
</div>

<div id="deleteFeedbackModalOverlay" class="admin-modal-overlay" aria-hidden="true">
  <section class="admin-modal admin-feedback-modal" role="dialog" aria-modal="true" aria-labelledby="deleteFeedbackTitle">
    <span id="deleteFeedbackIcon" class="admin-feedback-icon admin-feedback-icon--error"><i class="bi bi-x-octagon-fill"></i></span>
    <h3 id="deleteFeedbackTitle" class="admin-modal__title">Delete status</h3>
    <p id="deleteFeedbackMessage" class="admin-modal__desc">Message</p>
    <div class="admin-modal__actions">
      <button type="button" id="deleteFeedbackCloseBtn" class="admin-modal__btn admin-modal__btn--ok">OK</button>
    </div>
  </section>
</div>
<script>
  (function () {
    var POLL_MS = 10000;
    var tableBody = document.querySelector('.admin-students-table tbody');
    var rows = Array.prototype.slice.call(document.querySelectorAll('tr[data-user-id]'));
    var initialOrder = {};
    if (!rows.length) return;
    rows.forEach(function (row, idx) {
      var id = row.getAttribute('data-user-id');
      if (id) initialOrder[id] = idx;
    });

    function refreshRows() {
      rows = Array.prototype.slice.call(document.querySelectorAll('tr[data-user-id]'));
      return rows;
    }

    function ids() {
      refreshRows();
      return rows.map(function (r) { return r.getAttribute('data-user-id'); }).filter(Boolean);
    }

    function applyPresence(presenceMap) {
      refreshRows();
      var beforeOrder = rows.map(function (row) { return row.getAttribute('data-user-id') || ''; });
      rows.forEach(function (row) {
        var id = row.getAttribute('data-user-id');
        var dot = row.querySelector('[data-status-dot]');
        if (!id || !dot) return;
        var active = !!presenceMap[id];
        row.setAttribute('data-presence-active', active ? '1' : '0');
        dot.classList.toggle('student-avatar-status-dot--active', active);
        dot.classList.toggle('student-avatar-status-dot--inactive', !active);
        dot.title = active ? 'Session active' : 'Session inactive';
      });
      if (!tableBody) return;
      var sortedRows = rows.slice().sort(function (a, b) {
        var aId = a.getAttribute('data-user-id') || '';
        var bId = b.getAttribute('data-user-id') || '';
        var aActive = a.getAttribute('data-presence-active') === '1' ? 1 : 0;
        var bActive = b.getAttribute('data-presence-active') === '1' ? 1 : 0;
        if (aActive !== bActive) return bActive - aActive; // active first
        var aOrder = Object.prototype.hasOwnProperty.call(initialOrder, aId) ? initialOrder[aId] : 999999;
        var bOrder = Object.prototype.hasOwnProperty.call(initialOrder, bId) ? initialOrder[bId] : 999999;
        return aOrder - bOrder;
      });
      sortedRows.forEach(function (row) { tableBody.appendChild(row); });
      var afterOrder = sortedRows.map(function (row) { return row.getAttribute('data-user-id') || ''; });
      sortedRows.forEach(function (row, idx) {
        var id = row.getAttribute('data-user-id') || '';
        if (!id) return;
        if (beforeOrder[idx] !== id) {
          row.classList.remove('student-row-priority-moved');
          void row.offsetWidth; // restart animation when row moves repeatedly
          row.classList.add('student-row-priority-moved');
          setTimeout(function () {
            row.classList.remove('student-row-priority-moved');
          }, 560);
        }
      });
    }

    function pollOnce() {
      var idList = ids();
      if (!idList.length) return;
      fetch('admin_students_presence?ids=' + encodeURIComponent(idList.join(',')), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
      })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.ok || !data.presence) return;
        applyPresence(data.presence);
      })
      .catch(function () {});
    }

    pollOnce();
    setInterval(pollOnce, POLL_MS);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) pollOnce();
    });
  })();

  document.addEventListener('alpine:init', function () {
    Alpine.data('approveAccessPicker', function () {
      return {
        catalog: { subjects: [], preboard_subjects: [], preweek_units: [], test_bank: [] },
        permissions: [{ content_type: 'full_lms', content_id: 0 }],
        loadingCatalog: false,
        get permissionListKey() { return 'permissions'; },
        get activePermissionList() { return this.permissions || []; },
        get hasFullLms() {
          return this.activePermissionList.some(function (p) {
            return p.content_type === 'full_lms' && Number(p.content_id) === 0;
          });
        },
        get activePermCount() {
          if (this.hasFullLms) return 'Full LMS';
          var n = this.activePermissionList.length;
          return n === 0 ? 'None selected' : n + ' item' + (n === 1 ? '' : 's');
        },
        async init() {
          this.loadingCatalog = true;
          try {
            var res = await fetch('admin_student_access_api?action=catalog', { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return {}; });
            if (res.ok && data.ok && data.catalog) {
              this.catalog = data.catalog;
            }
          } catch (e) { /* keep empty catalog */ }
          this.loadingCatalog = false;
        },
        isChecked: function (type, id) {
          return this.activePermissionList.some(function (p) {
            return p.content_type === type && Number(p.content_id) === Number(id);
          });
        },
        toggle: function (type, id, on) {
          this.permissions = this.permissions.filter(function (p) {
            return !(p.content_type === type && Number(p.content_id) === Number(id));
          });
          if (on) this.permissions.push({ content_type: type, content_id: Number(id) });
        },
        toggleFullLms: function (on) {
          this.permissions = this.permissions.filter(function (p) { return p.content_type !== 'full_lms'; });
          if (on) this.permissions.push({ content_type: 'full_lms', content_id: 0 });
        },
        resetDefaults: function () {
          this.permissions = [{ content_type: 'full_lms', content_id: 0 }];
        },
        exportAccess: function () {
          return {
            grant_full_lms: this.hasFullLms ? '1' : '0',
            permissions: JSON.stringify(this.hasFullLms ? [{ content_type: 'full_lms', content_id: 0 }] : this.permissions)
          };
        }
      };
    });
  });

  (function () {
    var csrf = <?php echo json_encode($csrf); ?>;
    var confirmOverlay = document.getElementById('approveConfirmModalOverlay');
    var successOverlay = document.getElementById('approveSuccessModalOverlay');
    var confirmName = document.getElementById('approveConfirmStudentName');
    var confirmError = document.getElementById('approveConfirmError');
    var confirmCancel = document.getElementById('approveConfirmCancelBtn');
    var confirmSubmit = document.getElementById('approveConfirmSubmitBtn');
    var confirmMonths = document.getElementById('approveConfirmMonths');
    var successMsg = document.getElementById('approveSuccessMessage');
    var successContinue = document.getElementById('approveSuccessContinueBtn');
    var successStay = document.getElementById('approveSuccessStayBtn');
    var loadingOverlay = document.getElementById('actionLoadingModalOverlay');
    var loadingTitle = document.getElementById('actionLoadingTitle');
    var loadingMessage = document.getElementById('actionLoadingMessage');
    var bulkBar = document.getElementById('studentsBulkBar');
    var bulkCount = document.getElementById('studentsBulkCount');
    var bulkClear = document.getElementById('studentsBulkClearBtn');
    var bulkApprove = document.getElementById('studentsBulkApproveBtn');
    var selectAll = document.getElementById('studentSelectAll');
    var pendingUserIds = [];
    var pendingCommerceOnly = false;
    var enrolledUrl = 'admin_students?tab=enrolled&q=&page=1';
    var pendingUrl = 'admin_students?tab=pending&q=&page=1';
    var commerceNotice = document.getElementById('approveCommerceNotice');
    var legacyScaBox = document.getElementById('approveLegacyScaBox');
    var approveTitle = document.getElementById('approveConfirmTitle');
    var approveDesc = document.getElementById('approveConfirmDesc');

    function allSelectBoxes() {
      return Array.prototype.slice.call(document.querySelectorAll('.js-student-select'));
    }
    function selectedCheckboxes() {
      return Array.prototype.slice.call(document.querySelectorAll('.js-student-select:checked'));
    }

    function syncBulkBar() {
      var selected = selectedCheckboxes();
      var all = allSelectBoxes();
      var n = selected.length;
      if (bulkCount) bulkCount.textContent = String(n);
      if (bulkBar) bulkBar.classList.toggle('is-visible', n > 0);
      if (selectAll) {
        selectAll.disabled = all.length === 0;
        selectAll.checked = all.length > 0 && selected.length === all.length;
        selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
        selectAll.title = all.length === 0
          ? 'No actionable students on this page (only legacy pending or Repair Activation get checkboxes)'
          : ('Select all ' + all.length + ' actionable student(s) on this page');
      }
    }

    // Select-all must work even if approve modal markup is missing.
    allSelectBoxes().forEach(function (cb) {
      cb.addEventListener('change', syncBulkBar);
    });
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        var on = !!selectAll.checked;
        allSelectBoxes().forEach(function (cb) { cb.checked = on; });
        selectAll.indeterminate = false;
        syncBulkBar();
      });
    }
    if (bulkClear) {
      bulkClear.addEventListener('click', function () {
        allSelectBoxes().forEach(function (cb) { cb.checked = false; });
        syncBulkBar();
      });
    }
    syncBulkBar();

    if (!confirmOverlay || !confirmSubmit) return;

    function getPicker() {
      var root = confirmOverlay.querySelector('[x-data]');
      if (!root || !window.Alpine) return null;
      try { return Alpine.$data(root); } catch (e) { return null; }
    }

    function rowIsCommerce(userId) {
      var tr = document.querySelector('tr[data-user-id="' + String(userId) + '"]');
      return !!(tr && tr.getAttribute('data-commerce-enrollment') === '1');
    }

    function openConfirm(userIds, label) {
      pendingUserIds = (userIds || []).map(function (id) { return Number(id); }).filter(function (id) { return id > 0; });
      if (pendingUserIds.length === 0) return;
      pendingCommerceOnly = pendingUserIds.every(function (id) { return rowIsCommerce(id); });
      if (confirmName) confirmName.textContent = label || (pendingUserIds.length + ' students');
      if (confirmError) confirmError.textContent = '';
      if (confirmMonths && (!confirmMonths.value || Number(confirmMonths.value) < 1)) confirmMonths.value = '6';
      if (approveTitle) approveTitle.textContent = pendingCommerceOnly ? 'Repair Activation' : 'Approve account';
      if (approveDesc) {
        approveDesc.textContent = pendingCommerceOnly
          ? 'Exceptional repair only: commerce access is already granted but login is still pending. This does not fulfill payments.'
          : 'Set enrollment months and optional Manual / Administrative Access for legacy students.';
      }
      if (commerceNotice) commerceNotice.hidden = !pendingCommerceOnly;
      if (legacyScaBox) legacyScaBox.style.display = pendingCommerceOnly ? 'none' : '';
      var picker = getPicker();
      if (picker && typeof picker.resetDefaults === 'function') picker.resetDefaults();
      confirmOverlay.classList.add('is-open');
      confirmOverlay.setAttribute('aria-hidden', 'false');
      if (confirmMonths) setTimeout(function () { confirmMonths.focus(); }, 40);
    }
    function closeConfirm() {
      confirmOverlay.classList.remove('is-open');
      confirmOverlay.setAttribute('aria-hidden', 'true');
      if (confirmError) confirmError.textContent = '';
    }
    function openSuccess(message) {
      if (successMsg) successMsg.textContent = message || 'The student has been successfully approved.';
      successOverlay.classList.add('is-open');
      successOverlay.setAttribute('aria-hidden', 'false');
    }
    function showLoading(title, message) {
      if (!loadingOverlay) return;
      if (loadingTitle) loadingTitle.textContent = title || 'Processing request...';
      if (loadingMessage) loadingMessage.textContent = message || 'Please wait while we complete this action.';
      loadingOverlay.classList.add('is-open');
      loadingOverlay.setAttribute('aria-hidden', 'false');
    }
    function hideLoading() {
      if (!loadingOverlay) return;
      loadingOverlay.classList.remove('is-open');
      loadingOverlay.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('.js-approve-one-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = Number(btn.getAttribute('data-user-id') || 0);
        var name = btn.getAttribute('data-student-name') || 'this student';
        openConfirm([id], name);
      });
    });

    if (bulkApprove) {
      bulkApprove.addEventListener('click', function () {
        var selected = selectedCheckboxes();
        if (selected.length === 0) return;
        var ids = selected.map(function (cb) { return Number(cb.value); });
        openConfirm(ids, selected.length + ' student' + (selected.length === 1 ? '' : 's'));
      });
    }

    if (confirmCancel) confirmCancel.addEventListener('click', closeConfirm);
    confirmOverlay.addEventListener('click', function (e) {
      if (e.target === confirmOverlay) closeConfirm();
    });
    if (successContinue) {
      successContinue.addEventListener('click', function () {
        window.location.href = enrolledUrl;
      });
    }
    if (successStay) {
      successStay.addEventListener('click', function () {
        window.location.href = pendingUrl;
      });
    }

    var confirmUnit = document.getElementById('approveConfirmUnit');
    confirmSubmit.addEventListener('click', function () {
      if (pendingUserIds.length === 0) return;
      var months = confirmMonths ? Number(confirmMonths.value) : 0;
      var unit = confirmUnit ? String(confirmUnit.value || 'month') : 'month';
      if (!months || months < 1) {
        if (confirmError) confirmError.textContent = 'Enter a valid duration.';
        return;
      }
      var access = { grant_full_lms: '0', permissions: '[]' };
      if (!pendingCommerceOnly) {
        var picker = getPicker();
        access = picker && typeof picker.exportAccess === 'function'
          ? picker.exportAccess()
          : { grant_full_lms: '1', permissions: JSON.stringify([{ content_type: 'full_lms', content_id: 0 }]) };
        if (access.grant_full_lms !== '1') {
          try {
            var perms = JSON.parse(access.permissions || '[]');
            if (!Array.isArray(perms) || perms.length === 0) {
              if (confirmError) confirmError.textContent = 'Select Full LMS or at least one content item for legacy students.';
              return;
            }
          } catch (e) {
            if (confirmError) confirmError.textContent = 'Select Full LMS or at least one content item for legacy students.';
            return;
          }
        }
      }

      confirmSubmit.disabled = true;
      confirmSubmit.innerHTML = '<i class="bi bi-hourglass-split"></i> Activating...';
      if (confirmError) confirmError.textContent = '';
      closeConfirm();
      showLoading(
        pendingUserIds.length > 1 ? 'Activating accounts...' : 'Activating account...',
        pendingCommerceOnly
          ? 'Setting login window only. Commerce content access is unchanged.'
          : 'Setting account window and manual access.'
      );

      var formData = new FormData();
      formData.append('csrf_token', csrf);
      formData.append('ajax', '1');
      formData.append('duration_value', String(months));
      formData.append('duration_unit', unit);
      formData.append('months', String(months));
      formData.append('grant_full_lms', access.grant_full_lms);
      formData.append('permissions', access.permissions);
      formData.append('return_to', 'admin_students?tab=enrolled&q=&page=1');
      if (pendingUserIds.length === 1) {
        formData.append('user_id', String(pendingUserIds[0]));
      } else {
        formData.append('user_ids', JSON.stringify(pendingUserIds));
      }

      fetch('activate_user', {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          hideLoading();
          openConfirm(pendingUserIds, confirmName ? confirmName.textContent : '');
          if (confirmError) confirmError.textContent = (data && data.error) ? data.error : 'Activation failed. Please try again.';
          return;
        }
        hideLoading();
        openSuccess(data.message || 'Account activated successfully.');
      })
      .catch(function () {
        hideLoading();
        openConfirm(pendingUserIds, confirmName ? confirmName.textContent : '');
        if (confirmError) confirmError.textContent = 'Request failed. Please check your connection and try again.';
      })
      .finally(function () {
        confirmSubmit.disabled = false;
        confirmSubmit.innerHTML = '<i class="bi bi-check2-circle"></i> Confirm activate';
      });
    });

    syncBulkBar();
  })();

  (function () {
    var csrf = <?php echo json_encode($csrf); ?>;
    var deleteUrl = 'admin_student_delete';
    var modalOverlay = document.getElementById('deleteStudentModalOverlay');
    var feedbackOverlay = document.getElementById('deleteFeedbackModalOverlay');
    var nameEl = document.getElementById('deleteStudentModalName');
    var userIdEl = document.getElementById('deleteStudentUserId');
    var reasonEl = document.getElementById('deleteStudentReason');
    var reasonOtherWrap = document.getElementById('deleteStudentReasonOtherWrap');
    var reasonOtherEl = document.getElementById('deleteStudentReasonOther');
    var passEl = document.getElementById('deleteStudentAdminPassword');
    var errEl = document.getElementById('deleteStudentModalError');
    var form = document.getElementById('deleteStudentForm');
    var cancelBtn = document.getElementById('deleteStudentCancelBtn');
    var confirmBtn = document.getElementById('deleteStudentConfirmBtn');
    var feedbackTitle = document.getElementById('deleteFeedbackTitle');
    var feedbackMsg = document.getElementById('deleteFeedbackMessage');
    var feedbackIcon = document.getElementById('deleteFeedbackIcon');
    var feedbackClose = document.getElementById('deleteFeedbackCloseBtn');
    var loadingOverlay = document.getElementById('actionLoadingModalOverlay');
    var loadingTitle = document.getElementById('actionLoadingTitle');
    var loadingMessage = document.getElementById('actionLoadingMessage');

    if (!modalOverlay || !form) return;

    function openModal(btn) {
      var uid = btn.getAttribute('data-user-id') || '';
      var uname = btn.getAttribute('data-user-name') || 'this student';
      userIdEl.value = uid;
      nameEl.textContent = uname;
      if (reasonEl) reasonEl.value = '';
      if (reasonOtherEl) reasonOtherEl.value = '';
      if (reasonOtherWrap) reasonOtherWrap.style.display = 'none';
      passEl.value = '';
      errEl.textContent = '';
      modalOverlay.classList.add('is-open');
      modalOverlay.setAttribute('aria-hidden', 'false');
      setTimeout(function () { passEl.focus(); }, 40);
    }

    function closeModal() {
      modalOverlay.classList.remove('is-open');
      modalOverlay.setAttribute('aria-hidden', 'true');
      errEl.textContent = '';
    }

    function showFeedback(type, title, message) {
      feedbackTitle.textContent = title || 'Notification';
      feedbackMsg.textContent = message || '';
      if (type === 'success') {
        feedbackIcon.className = 'admin-feedback-icon admin-feedback-icon--success admin-feedback-icon--pulse';
        feedbackIcon.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
      } else {
        feedbackIcon.className = 'admin-feedback-icon admin-feedback-icon--error';
        feedbackIcon.innerHTML = '<i class="bi bi-x-octagon-fill"></i>';
      }
      feedbackOverlay.classList.add('is-open');
      feedbackOverlay.setAttribute('aria-hidden', 'false');
    }

    function hideFeedback() {
      feedbackOverlay.classList.remove('is-open');
      feedbackOverlay.setAttribute('aria-hidden', 'true');
    }
    function showLoading(title, message) {
      if (!loadingOverlay) return;
      if (loadingTitle) loadingTitle.textContent = title || 'Processing request...';
      if (loadingMessage) loadingMessage.textContent = message || 'Please wait while we complete this action.';
      loadingOverlay.classList.add('is-open');
      loadingOverlay.setAttribute('aria-hidden', 'false');
    }
    function hideLoading() {
      if (!loadingOverlay) return;
      loadingOverlay.classList.remove('is-open');
      loadingOverlay.setAttribute('aria-hidden', 'true');
    }

    function removeRow(userId) {
      var row = document.querySelector('tr[data-user-id="' + String(userId) + '"]');
      if (!row) return;
      row.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
      row.style.opacity = '0';
      row.style.transform = 'translateY(-6px)';
      setTimeout(function () {
        if (row && row.parentNode) row.parentNode.removeChild(row);
      }, 220);
    }

    document.querySelectorAll('.js-delete-student-btn').forEach(function (btn) {
      btn.addEventListener('click', function () { openModal(btn); });
    });
    if (reasonEl) {
      reasonEl.addEventListener('change', function () {
        if (!reasonOtherWrap) return;
        if (reasonEl.value === 'other') {
          reasonOtherWrap.style.display = '';
          if (reasonOtherEl) reasonOtherEl.focus();
        } else {
          reasonOtherWrap.style.display = 'none';
          if (reasonOtherEl) reasonOtherEl.value = '';
        }
      });
    }

    cancelBtn.addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', function (e) {
      if (e.target === modalOverlay) closeModal();
    });
    if (feedbackClose) {
      feedbackClose.addEventListener('click', function () {
        hideFeedback();
        window.location.reload();
      });
    }
    feedbackOverlay.addEventListener('click', function (e) {
      if (e.target === feedbackOverlay) hideFeedback();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeModal();
        hideFeedback();
      }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var uid = parseInt(userIdEl.value || '0', 10);
      var password = (passEl.value || '').trim();
      var reason = reasonEl ? String(reasonEl.value || '').trim() : '';
      var reasonOther = reasonOtherEl ? String(reasonOtherEl.value || '').trim() : '';
      if (!uid) {
        errEl.textContent = 'Invalid user selected.';
        return;
      }
      if (!reason) {
        errEl.textContent = 'Please select a deletion reason.';
        if (reasonEl) reasonEl.focus();
        return;
      }
      if (reason === 'other' && !reasonOther) {
        errEl.textContent = 'Please provide the specific reason.';
        if (reasonOtherEl) reasonOtherEl.focus();
        return;
      }
      if (!password) {
        errEl.textContent = 'Admin password is required.';
        passEl.focus();
        return;
      }

      errEl.textContent = '';
      confirmBtn.disabled = true;
      confirmBtn.textContent = 'Deleting...';
      closeModal();
      showLoading('Deleting student account...', 'Securing audit log and processing deletion.');

      var body = new URLSearchParams();
      body.set('csrf_token', csrf);
      body.set('user_id', String(uid));
      body.set('admin_password', password);
      body.set('delete_reason', reason);
      body.set('delete_reason_other', reasonOther);

      fetch(deleteUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          hideLoading();
          var msg = (data && data.error) ? data.error : 'Delete failed. Please try again.';
          if ((data && data.code) === 'INVALID_PASSWORD' || msg.toLowerCase().indexOf('incorrect password') !== -1) {
            showFeedback('error', 'Incorrect password', 'Incorrect password. Please try again with your admin password.');
          } else {
            showFeedback('error', 'Delete failed', msg);
          }
          return;
        }
        hideLoading();
        removeRow(uid);
        showFeedback('success', 'User successfully deleted', 'The selected student account was permanently removed.');
      })
      .catch(function () {
        hideLoading();
        showFeedback('error', 'Request failed', 'Check your connection and try again.');
      })
      .finally(function () {
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Confirm Delete';
      });
    });
  })();

  (function () {
    var wraps = document.querySelectorAll('[data-admin-student-action-menu]');
    if (!wraps.length) return;
    var menuPairs = [];
    function positionMenu(wrap, menu) {
      var trigger = wrap.querySelector('[data-action-menu-trigger]');
      if (!trigger || !menu) return;
      var rect = trigger.getBoundingClientRect();
      var menuWidth = 220;
      var wasOpen = menu.classList.contains('open');
      if (!wasOpen) {
        menu.style.visibility = 'hidden';
        menu.classList.add('open');
      }
      var mw = menu.offsetWidth || menuWidth;
      if (!wasOpen) {
        menu.classList.remove('open');
        menu.style.visibility = '';
      }
      var left = Math.min(window.innerWidth - mw - 10, Math.max(10, rect.right - mw));
      var top = rect.bottom + 6;
      var menuHeight = menu.offsetHeight || 280;
      var spaceBelow = window.innerHeight - rect.bottom;
      if (spaceBelow < menuHeight + 12) {
        var need = menuHeight + 16 - spaceBelow;
        if (need > 0) {
          window.scrollBy({ top: need, behavior: 'smooth' });
        }
        top = Math.max(10, window.innerHeight - menuHeight - 10);
      }
      menu.style.left = left + 'px';
      menu.style.top = top + 'px';
    }
    function closeAllMenus() {
      menuPairs.forEach(function (pair) {
        var menu = pair.menu;
        var wrap = pair.wrap;
        var tr = wrap.querySelector('[data-action-menu-trigger]');
        if (menu) menu.classList.remove('open');
        wrap.classList.remove('is-open');
        if (tr) tr.setAttribute('aria-expanded', 'false');
      });
    }
    wraps.forEach(function (wrap) {
      var trigger = wrap.querySelector('[data-action-menu-trigger]');
      var menu = wrap.querySelector('[data-action-menu-list]');
      if (!trigger || !menu) return;
      document.body.appendChild(menu);
      menuPairs.push({ wrap: wrap, menu: menu });
      trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        var wasOpen = menu.classList.contains('open');
        closeAllMenus();
        if (wasOpen) return;
        positionMenu(wrap, menu);
        menu.classList.add('open');
        wrap.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
      });
      menu.addEventListener('click', function (e) {
        e.stopPropagation();
      });
    });
    window.addEventListener('resize', closeAllMenus);
    window.addEventListener('scroll', closeAllMenus, true);
    document.addEventListener('click', closeAllMenus);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeAllMenus();
    });
  })();

  (function () {
    var drawer = document.getElementById('studentDetailsDrawer');
    if (!drawer) return;
    var titleEl = document.getElementById('studentDrawerTitle');
    var emailEl = document.getElementById('studentDrawerEmail');
    var avatarEl = document.getElementById('studentDrawerAvatar');
    var statusEl = document.getElementById('studentDrawerStatus');
    var schoolEl = document.getElementById('studentDrawerSchool');
    var reviewEl = document.getElementById('studentDrawerReview');
    var createdEl = document.getElementById('studentDrawerCreated');
    var accessEl = document.getElementById('studentDrawerAccess');
    var accessMetaEl = document.getElementById('studentDrawerAccessMeta');
    var activityEl = document.getElementById('studentDrawerActivity');
    var proofEl = document.getElementById('studentDrawerProof');
    var enrollEl = document.getElementById('studentDrawerEnrollment');
    var paymentEl = document.getElementById('studentDrawerPayment');
    var commerceAccessEl = document.getElementById('studentDrawerCommerceAccess');
    var accountEl = document.getElementById('studentDrawerAccount');
    var fullLink = document.getElementById('studentDrawerFullLink');
    var accessLink = document.getElementById('studentDrawerAccessLink');

    function setText(el, value) {
      if (el) el.textContent = value || '—';
    }

    function openFromRow(row) {
      if (!row) return;
      var id = row.getAttribute('data-user-id') || '';
      var name = row.getAttribute('data-drawer-name') || 'Student';
      var email = row.getAttribute('data-drawer-email') || '—';
      var avatar = row.getAttribute('data-drawer-avatar') || '';
      var initial = row.getAttribute('data-drawer-initial') || 'U';
      setText(titleEl, name);
      setText(emailEl, email);
      setText(statusEl, row.getAttribute('data-drawer-status'));
      setText(schoolEl, row.getAttribute('data-drawer-school'));
      setText(reviewEl, row.getAttribute('data-drawer-review'));
      setText(createdEl, row.getAttribute('data-drawer-created'));
      setText(accessEl, row.getAttribute('data-drawer-access'));
      setText(accessMetaEl, row.getAttribute('data-drawer-access-meta') || '—');
      setText(activityEl, row.getAttribute('data-drawer-activity'));
      setText(proofEl, row.getAttribute('data-drawer-proof'));
      setText(enrollEl, row.getAttribute('data-drawer-enrollment'));
      setText(paymentEl, row.getAttribute('data-drawer-payment'));
      setText(commerceAccessEl, row.getAttribute('data-drawer-commerce-access'));
      setText(accountEl, row.getAttribute('data-drawer-account'));
      if (fullLink) fullLink.href = 'admin_student_view?id=' + encodeURIComponent(id);
      if (accessLink) accessLink.href = 'admin_student_access?user_id=' + encodeURIComponent(id);
      if (avatarEl) {
        if (avatar) {
          avatarEl.innerHTML = '<img src="' + avatar.replace(/"/g, '&quot;') + '" alt="">';
        } else {
          avatarEl.textContent = initial;
        }
      }
      drawer.classList.add('is-open');
      drawer.setAttribute('aria-hidden', 'false');
      document.body.classList.add('student-drawer-open');
      document.querySelectorAll('.admin-student-action-menu.open').forEach(function (m) {
        m.classList.remove('open');
      });
      document.querySelectorAll('[data-admin-student-action-menu].is-open').forEach(function (w) {
        w.classList.remove('is-open');
      });
    }

    function closeDrawer() {
      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('student-drawer-open');
    }

    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.js-student-drawer-open');
      if (!btn) return;
      e.preventDefault();
      var uid = btn.getAttribute('data-user-id');
      var row = uid ? document.querySelector('tr[data-user-id="' + uid + '"]') : btn.closest('tr');
      openFromRow(row);
    });
    drawer.querySelectorAll('[data-drawer-close]').forEach(function (el) {
      el.addEventListener('click', closeDrawer);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && drawer.classList.contains('is-open')) closeDrawer();
    });
  })();
</script>
<?php include __DIR__ . '/includes/components/admin_proof_modal.php'; ?>
</body>
</html>
