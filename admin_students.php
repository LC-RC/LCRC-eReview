<?php
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/profile_avatar.php';

$csrf = generateCSRFToken();
$nowSql = date('Y-m-d H:i:s');

$tab = $_GET['tab'] ?? 'enrolled';
if (!in_array($tab, ['enrolled','pending','expired','rejected','all'], true)) { $tab = 'enrolled'; }

$q = trim($_GET['q'] ?? '');
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
$students = mysqli_stmt_get_result($stmt);

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
if ($hasDeletedLogTable) {
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

  $logSql = "SELECT log_id, deleted_user_id, deleted_name, deleted_email, " .
            ($hasLogSchool ? "deleted_school" : "'' AS deleted_school") . ", " .
            ($hasLogReviewType ? "deleted_review_type" : "'' AS deleted_review_type") . ", " .
            ($hasLogAccessRange ? "deleted_access_range" : "'' AS deleted_access_range") . ", " .
            "deleted_by_admin_name, " .
            ($hasLogReason ? "deletion_reason" : "'' AS deletion_reason") . ", deleted_at
             FROM deleted_users_log
             ORDER BY deleted_at DESC, log_id DESC
             LIMIT 12";
  $logRes = @mysqli_query($conn, $logSql);
  if ($logRes) {
    while ($lr = mysqli_fetch_assoc($logRes)) {
      $deletedLogs[] = $lr;
    }
  }
}

$pageTitle = 'Students';
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Students'] ];
$mk = function(string $t, int $p = 1) use ($q) : string {
  $params = ['tab' => $t, 'q' => $q, 'page' => $p];
  return 'admin_students.php?' . http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    .admin-students-page .page-hero {
      border: 1px solid #dbeafe;
      background: linear-gradient(135deg, #eff6ff 0%, #ffffff 70%);
      box-shadow: 0 12px 30px -22px rgba(37, 99, 235, 0.35);
    }
    .admin-students-page .page-filter,
    .admin-students-page .page-table,
    .admin-students-page .page-trashlog {
      border: 1px solid #dbeafe;
      box-shadow: 0 12px 28px -24px rgba(30, 64, 175, 0.3);
    }
    .student-avatar-cell {
      position: relative;
      width: 2.85rem;
      height: 2.85rem;
      margin-left: auto;
      margin-right: auto;
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
      font-size: 0.92rem;
      border: 2px solid rgba(255,255,255,0.85);
      box-shadow: 0 4px 14px rgba(15, 23, 42, 0.24);
      text-transform: uppercase;
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
      width: 0.9rem;
      height: 0.9rem;
      border-radius: 9999px;
      border: 2px solid rgba(255,255,255,0.9);
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
      background: linear-gradient(90deg, rgba(59, 130, 246, 0.06) 0%, rgba(14, 165, 233, 0.03) 100%);
      box-shadow: inset 4px 0 0 rgba(59, 130, 246, 0.75);
      transform: translateY(-1px);
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
      font-weight: 700;
      color: #ffffff;
      font-size: 0.94rem;
      line-height: 1.25;
      white-space: nowrap;
    }
    .table-meta-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 0.2rem 0.58rem;
      border: 1px solid transparent;
      white-space: nowrap;
    }
    .table-meta-pill--school {
      color: #ffffff;
      background: rgba(59, 130, 246, 0.2);
      border-color: rgba(147, 197, 253, 0.35);
    }
    .table-meta-pill--review {
      color: #ffffff;
      background: rgba(139, 92, 246, 0.24);
      border-color: rgba(196, 181, 253, 0.42);
    }
    .student-email {
      color: #ffffff;
      font-weight: 600;
      font-size: 0.84rem;
      white-space: nowrap;
      word-break: normal;
      letter-spacing: 0.01em;
    }
    .student-email-cell {
      min-width: 15.5rem;
      text-align: left;
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
      min-width: 9.5rem;
      width: 1%;
    }
    .student-actions-head {
      text-align: right !important;
      width: 9.5rem;
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
    /* Access column: wide enough for full "Enrollment window | MMM d, YYYY – MMM d, YYYY" (no ellipsis). */
    .admin-students-table .admin-students-access-col {
      min-width: 28rem;
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
  </style>
</head>
<body class="font-sans antialiased admin-app admin-students-page">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-5 py-5 mb-5 page-hero">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-people"></i> Students
    </h1>
    <p class="text-gray-500 mt-1">Manage enrollments and access — view by status, approve, or extend.</p>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-green-50 border border-green-200 flex items-center gap-2 text-green-800">
      <i class="bi bi-check-circle-fill"></i>
      <span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-5 page-filter">
    <p class="text-gray-500 text-sm mb-3">Filter by status</p>
    <div class="flex flex-wrap justify-between items-center gap-4 mb-4">
      <nav class="flex flex-wrap gap-2 student-filter-tabs" aria-label="Student tabs">
        <a href="<?php echo h($mk('enrolled', 1)); ?>" class="student-filter-tab student-filter-tab--enrolled inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium border-2 transition <?php echo $tab === 'enrolled' ? 'bg-primary text-white border-primary' : 'bg-gray-100 border-gray-200'; ?>">
          <span class="student-filter-tab__icon"><i class="bi bi-check2-circle"></i></span> Enrolled <span class="student-filter-tab__count px-2 py-0.5 rounded-full text-sm font-bold <?php echo $tab === 'enrolled' ? 'student-filter-tab__count--active' : ''; ?>"><?php echo (int)$counts['enrolled']; ?></span>
        </a>
        <a href="<?php echo h($mk('pending', 1)); ?>" class="student-filter-tab student-filter-tab--pending inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium border-2 transition <?php echo $tab === 'pending' ? 'bg-primary text-white border-primary' : 'bg-gray-100 border-gray-200'; ?>">
          <span class="student-filter-tab__icon"><i class="bi bi-hourglass-split"></i></span> Pending <span class="student-filter-tab__count px-2 py-0.5 rounded-full text-sm font-bold <?php echo $tab === 'pending' ? 'student-filter-tab__count--active' : ''; ?>"><?php echo (int)$counts['pending']; ?></span>
        </a>
        <a href="<?php echo h($mk('expired', 1)); ?>" class="student-filter-tab student-filter-tab--expired inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium border-2 transition <?php echo $tab === 'expired' ? 'bg-primary text-white border-primary' : 'bg-gray-100 border-gray-200'; ?>">
          <span class="student-filter-tab__icon"><i class="bi bi-calendar-x"></i></span> Expired <span class="student-filter-tab__count px-2 py-0.5 rounded-full text-sm font-bold <?php echo $tab === 'expired' ? 'student-filter-tab__count--active' : ''; ?>"><?php echo (int)$counts['expired']; ?></span>
        </a>
        <a href="<?php echo h($mk('rejected', 1)); ?>" class="student-filter-tab student-filter-tab--rejected inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium border-2 transition <?php echo $tab === 'rejected' ? 'bg-primary text-white border-primary' : 'bg-gray-100 border-gray-200'; ?>">
          <span class="student-filter-tab__icon"><i class="bi bi-x-circle"></i></span> Rejected <span class="student-filter-tab__count px-2 py-0.5 rounded-full text-sm font-bold <?php echo $tab === 'rejected' ? 'student-filter-tab__count--active' : ''; ?>"><?php echo (int)$counts['rejected']; ?></span>
        </a>
        <a href="<?php echo h($mk('all', 1)); ?>" class="student-filter-tab student-filter-tab--all inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium border-2 transition <?php echo $tab === 'all' ? 'bg-primary text-white border-primary' : 'bg-gray-100 border-gray-200'; ?>">
          <span class="student-filter-tab__icon"><i class="bi bi-collection"></i></span> All <span class="student-filter-tab__count px-2 py-0.5 rounded-full text-sm font-bold <?php echo $tab === 'all' ? 'student-filter-tab__count--active' : ''; ?>"><?php echo (int)$counts['all']; ?></span>
        </a>
      </nav>
      <form method="GET" class="flex flex-wrap gap-2 items-center">
        <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
        <div class="relative min-w-[280px]">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><i class="bi bi-search"></i></span>
          <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search name or email..." class="input-custom pl-10">
        </div>
        <button type="submit" class="student-apply-btn px-4 py-2.5 rounded-lg font-semibold border-2 border-primary text-primary hover:bg-primary hover:text-white transition inline-flex items-center gap-2" title="Apply filters"><i class="bi bi-funnel"></i> Apply</button>
        <?php if ($q !== ''): ?>
          <a href="admin_students.php?tab=<?php echo h($tab); ?>&page=1" class="text-gray-500 text-sm hover:text-gray-700 hover:underline">Clear search</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden page-table">
    <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-2">
      <div class="flex items-center gap-2">
        <span class="font-semibold text-gray-800">Students</span>
        <span class="px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-200 text-gray-700"><?php echo (int)$total; ?></span>
      </div>
      <p class="text-gray-500 text-sm hidden md:block m-0">Tip: Use <strong>Actions</strong> for view, proof, approve, extend, or delete.</p>
      <div class="text-gray-500 text-sm text-right">
        <?php if ($total > 0): ?>
          <span>Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $total); ?> of <?php echo $total; ?> students</span>
        <?php else: ?>
          <span>Showing 0-0 of 0 students</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="overflow-x-auto pl-3 pr-8">
      <table class="w-full text-left admin-students-table">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Profile</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Name</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">School</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Review Type</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-left">Email</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Status</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center admin-students-access-col">Access</th>
            <th class="px-5 py-3 font-semibold text-gray-700 student-actions-head">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($total === 0): ?>
            <?php
              $emptyHint = 'Try changing the tab or clearing search.';
              if ($tab === 'pending') $emptyHint = 'When students register, they’ll appear here for approval.';
              elseif ($tab === 'enrolled') $emptyHint = 'Approved students with active access will appear here.';
              elseif ($tab === 'expired') $emptyHint = 'Students whose access has ended will appear here.';
              elseif ($tab === 'rejected') $emptyHint = 'Rejected registrations will appear here.';
            ?>
            <tr>
              <td colspan="8" class="px-5 py-14 text-center text-gray-500">
                <i class="bi bi-people text-5xl block mb-3 opacity-50"></i>
                <div class="font-semibold text-gray-600">No students found</div>
                <p class="text-sm mt-1 max-w-sm mx-auto"><?php echo h($emptyHint); ?></p>
                <?php if ($q !== ''): ?>
                  <a href="admin_students.php?tab=<?php echo h($tab); ?>&page=1" class="inline-block mt-4 px-4 py-2 rounded-lg text-sm font-medium border-2 border-primary text-primary hover:bg-primary hover:text-white transition">Clear search</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php else: ?>
            <?php while ($row = mysqli_fetch_assoc($students)): ?>
              <?php
                $schoolLabel = $row['school'] === 'Other' && !empty($row['school_other']) ? $row['school_other'] : $row['school'];
                $hasAccessRange = !empty($row['access_start']) || !empty($row['access_end']);
                $accessStartLabel = !empty($row['access_start']) ? date('F j, Y', strtotime((string)$row['access_start'])) : '?';
                $accessEndLabel = !empty($row['access_end']) ? date('F j, Y', strtotime((string)$row['access_end'])) : '?';
                $access = $hasAccessRange ? ($accessStartLabel . ' → ' . $accessEndLabel) : '-';
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
                  if ($totalSec <= 0) {
                    $accessWindowMeta = 'Check date range';
                    $accessWindowTone = 'partial';
                  } elseif ($nowTs < $accessStartTs) {
                    $accessWindowPct = 0.0;
                    $accessWindowTone = 'upcoming';
                    $d = (int) ceil(($accessStartTs - $nowTs) / 86400);
                    $accessWindowMeta = $d <= 0 ? 'Starts today' : ('Starts in ' . $d . ' day' . ($d === 1 ? '' : 's'));
                  } elseif ($nowTs > $accessEndTs) {
                    $accessWindowPct = 100.0;
                    $accessWindowTone = 'expired';
                    $d = (int) floor(($nowTs - $accessEndTs) / 86400);
                    $accessWindowMeta = $d <= 0 ? 'Ended today' : ('Ended ' . $d . 'd ago');
                  } else {
                    $elapsed = $nowTs - $accessStartTs;
                    $accessWindowPct = round(min(100, max(0, ($elapsed / $totalSec) * 100)), 1);
                    $d = (int) ceil(($accessEndTs - $nowTs) / 86400);
                    if ($d <= 0) {
                      $accessWindowMeta = 'Ends today';
                      $accessWindowTone = 'ending';
                    } elseif ($d <= 7) {
                      $accessWindowMeta = $d . ' day' . ($d === 1 ? '' : 's') . ' left';
                      $accessWindowTone = 'ending';
                    } else {
                      $accessWindowMeta = $d . ' day' . ($d === 1 ? '' : 's') . ' left';
                      $accessWindowTone = 'active';
                    }
                  }
                } elseif ($accessStartTs !== false || $accessEndTs !== false) {
                  $accessWindowMeta = 'Complete start & end dates to see timeline';
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
                $recentThresholdTs = time() - (2 * 60); // 2 minutes idle window
                if ($hasLastSeenAt && !empty($row['last_seen_at'])) {
                  $lastSeenTs = strtotime((string)$row['last_seen_at']);
                  if ($lastSeenTs !== false && $lastSeenTs >= $recentThresholdTs) {
                    $isSessionActive = true;
                  }
                } elseif ($hasLastLoginAt && !empty($row['last_login_at'])) {
                  $lastLoginTs = strtotime((string)$row['last_login_at']);
                  if ($lastLoginTs !== false && $lastLoginTs >= $recentThresholdTs) {
                    $isSessionActive = true;
                  }
                } elseif (!$hasLastSeenAt && !$hasLastLoginAt && $hasIsOnline && !empty($row['is_online'])) {
                  // Legacy schema without timestamps: fall back to is_online flag.
                  $isSessionActive = true;
                }
                if ($hasLastLogoutAt && !empty($row['last_logout_at'])) {
                  $lastLogoutTs = strtotime((string)$row['last_logout_at']);
                  $lastSeenTs2 = (!empty($row['last_seen_at']) ? strtotime((string)$row['last_seen_at']) : false);
                  if ($lastLogoutTs !== false && ($lastSeenTs2 === false || $lastSeenTs2 <= $lastLogoutTs)) {
                    $isSessionActive = false;
                  }
                }
              ?>
              <tr class="border-b border-gray-100 hover:bg-gray-50/50" data-user-id="<?php echo (int)$row['user_id']; ?>">
                <td class="px-5 py-3 text-center">
                  <span class="student-avatar-cell" aria-hidden="true" title="<?php echo h($row['full_name']); ?>">
                    <span class="student-avatar-media">
                      <?php if ($avatarPath !== '' && !$useDefaultAvatar): ?>
                        <img src="<?php echo h($avatarPath); ?>" alt="<?php echo h($row['full_name']); ?> profile photo" class="w-full h-full object-cover" loading="lazy">
                      <?php else: ?>
                        <?php echo h($avatarInitial); ?>
                      <?php endif; ?>
                    </span>
                    <span data-status-dot class="student-avatar-status-dot <?php echo $isSessionActive ? 'student-avatar-status-dot--active' : 'student-avatar-status-dot--inactive'; ?>" title="<?php echo $isSessionActive ? 'Session active' : 'Session inactive'; ?>"></span>
                  </span>
                </td>
                <td class="px-5 py-3 text-center">
                  <div class="student-name"><?php echo h($row['full_name']); ?></div>
                </td>
                <td class="px-5 py-3 text-center">
                  <span class="table-meta-pill table-meta-pill--school" title="<?php echo h($schoolLabel); ?>">
                    <?php echo h($schoolLabel ?: 'Not set'); ?>
                  </span>
                </td>
                <td class="px-5 py-3 text-center">
                  <span class="table-meta-pill table-meta-pill--review">
                    <?php
                      $reviewType = strtolower((string)($row['review_type'] ?? ''));
                      echo h($reviewType === 'undergrad' ? 'Undergrad' : 'Reviewee');
                    ?>
                  </span>
                </td>
                <td class="px-5 py-3 student-email-cell">
                  <span class="student-email"><?php echo h($row['email']); ?></span>
                </td>
                <td class="px-5 py-3 text-center">
                  <?php
                    $statusTitle = $statusClass === 'approved' ? 'Approved – has active access' : ($statusClass === 'rejected' ? 'Registration rejected' : 'Awaiting approval');
                    if ($isExpired) $statusTitle .= ' (access period ended)';
                  ?>
                  <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium <?php echo $badgeClass; ?>" title="<?php echo h($statusTitle); ?>"><?php echo h($row['status']); ?></span>
                  <?php if ($isExpired): ?>
                    <span class="ml-1 inline-block px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800" title="Access period has ended">expired</span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3 text-center access-cell admin-students-access-col" title="<?php echo h($access !== '-' ? $access : 'No access set'); ?>">
                  <?php if ($hasAccessRange): ?>
                    <div class="access-window access-window--<?php echo h($accessWindowTone); ?>">
                      <div class="access-window__headline">
                        <span class="access-window__hourglass" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
                        <span class="access-window__headline-inner">
                          <span class="access-window__kw">Enrollment window</span>
                          <span class="access-window__pipe" aria-hidden="true">|</span>
                          <span class="access-window__dates">
                            <?php if ($accessStartTs !== false): ?>
                              <time datetime="<?php echo h(date('c', $accessStartTs)); ?>"><?php echo h($accessStartShort); ?></time>
                            <?php else: ?>
                              <span><?php echo h($accessStartShort); ?></span>
                            <?php endif; ?>
                            <span class="access-window__dash" aria-hidden="true">–</span>
                            <?php if ($accessEndTs !== false): ?>
                              <time datetime="<?php echo h(date('c', $accessEndTs)); ?>"><?php echo h($accessEndShort); ?></time>
                            <?php else: ?>
                              <span><?php echo h($accessEndShort); ?></span>
                            <?php endif; ?>
                          </span>
                        </span>
                      </div>
                      <?php if ($accessWindowPct !== null): ?>
                        <div class="access-window__track" role="presentation" aria-hidden="true">
                          <span class="access-window__fill" style="width: <?php echo htmlspecialchars((string) $accessWindowPct, ENT_QUOTES, 'UTF-8'); ?>%;"></span>
                        </div>
                      <?php endif; ?>
                      <?php if ($accessWindowMeta !== ''): ?>
                        <div class="access-window__meta"><?php echo h($accessWindowMeta); ?></div>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <div class="access-window access-window--empty">
                      <div class="access-window__headline access-window__headline--empty">
                        <span class="access-window__empty-icon" aria-hidden="true"><i class="bi bi-calendar-x"></i></span>
                        <span class="access-window__headline-inner">
                          <span class="access-window__kw">No access set</span>
                          <span class="access-window__pipe" aria-hidden="true">|</span>
                          <span class="access-window__dates">Set dates in student profile</span>
                        </span>
                      </div>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3 student-action-cell">
                  <div class="admin-student-action-menu-wrap" data-admin-student-action-menu>
                    <button type="button" class="admin-student-action-menu-trigger" data-action-menu-trigger aria-expanded="false" aria-haspopup="true" aria-label="Open actions for <?php echo h($row['full_name']); ?>">
                      <i class="bi bi-three-dots" aria-hidden="true"></i> Actions
                    </button>
                    <div class="admin-student-action-menu" data-action-menu-list role="menu">
                      <a role="menuitem" class="admin-student-action-item" href="admin_student_view.php?id=<?php echo (int)$row['user_id']; ?>"><i class="bi bi-eye" aria-hidden="true"></i> View</a>
                      <?php if ($hasProof): ?>
                        <a role="menuitem" class="admin-student-action-item" href="admin_payment_proof.php?user_id=<?php echo (int)$row['user_id']; ?>" target="_blank" rel="noopener"><i class="bi bi-receipt" aria-hidden="true"></i> Proof</a>
                      <?php else: ?>
                        <span class="admin-student-action-item admin-student-action-item--disabled" aria-disabled="true"><i class="bi bi-receipt" aria-hidden="true"></i> Proof (none)</span>
                      <?php endif; ?>

                      <?php if ($row['status'] !== 'approved'): ?>
                        <form class="student-pending-form js-approve-form" action="activate_user.php" method="POST" data-student-name="<?php echo h($row['full_name']); ?>">
                          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                          <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                          <input type="hidden" name="return_to" value="<?php echo h($_SERVER['REQUEST_URI'] ?? 'admin_students.php'); ?>">
                          <label class="sr-only" for="pending-months-<?php echo (int)$row['user_id']; ?>">Months of access</label>
                          <input id="pending-months-<?php echo (int)$row['user_id']; ?>" type="number" min="1" name="months" class="student-pending-month-input" placeholder="+ Months" required>
                          <button type="submit" class="admin-student-action-item admin-student-action-item--approve" role="menuitem"><i class="bi bi-check2-circle" aria-hidden="true"></i> Approve</button>
                        </form>
                        <form class="admin-student-action-menu-reject-form" action="reject.php" method="POST">
                          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                          <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                          <button type="submit" class="admin-student-action-item admin-student-action-item--reject" role="menuitem"><i class="bi bi-x-circle" aria-hidden="true"></i> Reject</button>
                        </form>
                      <?php else: ?>
                        <form class="student-extend-form" action="extend_access.php" method="POST">
                          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                          <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                          <label class="sr-only" for="extend-months-<?php echo (int)$row['user_id']; ?>">Months to extend</label>
                          <input id="extend-months-<?php echo (int)$row['user_id']; ?>" type="number" min="1" name="months" placeholder="+ Months" required title="Add months">
                          <button type="submit" class="admin-student-action-item admin-student-action-item--extend" role="menuitem"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Extend access</button>
                        </form>
                      <?php endif; ?>
                      <button
                        type="button"
                        class="admin-student-action-item admin-student-action-item--danger admin-student-action-item--section js-delete-student-btn"
                        role="menuitem"
                        data-user-id="<?php echo (int)$row['user_id']; ?>"
                        data-user-name="<?php echo h($row['full_name']); ?>"
                        data-user-email="<?php echo h($row['email']); ?>"
                        title="Delete student permanently">
                        <i class="bi bi-trash" aria-hidden="true"></i> Delete
                      </button>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="px-5 py-4 border-t border-gray-100 flex justify-center" aria-label="Student pagination">
        <ul class="flex flex-wrap items-center gap-1">
          <?php if ($page > 1): ?>
            <li><a href="<?php echo h($mk($tab, $page - 1)); ?>" class="px-3 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 transition">Previous</a></li>
          <?php endif; ?>
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <li>
              <a href="<?php echo h($mk($tab, $i)); ?>" class="px-3 py-2 rounded-lg border transition <?php echo $i === $page ? 'bg-primary border-primary text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-100'; ?>"><?php echo $i; ?></a>
            </li>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <li><a href="<?php echo h($mk($tab, $page + 1)); ?>" class="px-3 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 transition">Next</a></li>
          <?php endif; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>

  <section class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden mt-5 page-trashlog">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-2">
      <h2 class="text-base font-semibold text-gray-800 m-0">Deleted Users Log</h2>
      <span class="text-xs text-gray-500">Audit trail</span>
    </div>
    <div class="overflow-x-auto px-3 py-3">
      <table class="w-full text-left deleted-log-table">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-3 py-2 font-semibold text-gray-700">User ID</th>
            <th class="px-3 py-2 font-semibold text-gray-700">Name</th>
            <th class="px-3 py-2 font-semibold text-gray-700">Email</th>
            <th class="px-3 py-2 font-semibold text-gray-700">School</th>
            <th class="px-3 py-2 font-semibold text-gray-700">Review Type</th>
            <th class="px-3 py-2 font-semibold text-gray-700">Access</th>
            <th class="px-3 py-2 font-semibold text-gray-700">Deleted By</th>
            <th class="px-3 py-2 font-semibold text-gray-700">Reason of Deletion</th>
            <th class="px-3 py-2 font-semibold text-gray-700">Date Deleted</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($deletedLogs)): ?>
            <tr>
              <td colspan="9" class="px-3 py-8 text-center text-gray-500 text-sm">
                No deleted users logged yet.
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
                    $deletedAccessLabel = date('F j, Y', $sTs) . ' - ' . date('F j, Y', $eTs);
                  }
                } elseif (preg_match('/^([A-Za-z]+\s+\d{1,2},\s+\d{4})\s*-\s*([A-Za-z]+\s+\d{1,2},\s+\d{4})$/', $deletedAccessLabel, $m2)) {
                  $deletedAccessLabel = $m2[1] . ' - ' . $m2[2];
                }
              ?>
              <tr class="border-b border-gray-100">
                <td class="px-3 py-2 font-semibold text-gray-700"><?php echo (int)$dl['deleted_user_id']; ?></td>
                <td class="px-3 py-2 text-gray-800"><?php echo h($dl['deleted_name']); ?></td>
                <td class="px-3 py-2 text-gray-700"><?php echo h($dl['deleted_email']); ?></td>
                <td class="px-3 py-2 text-gray-700"><?php echo h((string)($dl['deleted_school'] ?? '-')); ?></td>
                <td class="px-3 py-2 text-gray-700"><?php echo h((string)($dl['deleted_review_type'] ?? '-')); ?></td>
                <td class="px-3 py-2 text-gray-700"><?php echo h($deletedAccessLabel); ?></td>
                <td class="px-3 py-2 text-gray-700"><?php echo h($dl['deleted_by_admin_name']); ?></td>
                <td class="px-3 py-2 text-gray-700"><?php echo h((string)($dl['deletion_reason'] ?? '-')); ?></td>
                <td class="px-3 py-2 text-gray-600"><?php echo !empty($dl['deleted_at']) ? h(date('M j, Y g:i A', strtotime((string)$dl['deleted_at']))) : '-'; ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php mysqli_stmt_close($stmt); ?>
</div>
</main>
<div id="approveConfirmModalOverlay" class="admin-modal-overlay" aria-hidden="true">
  <section class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="approveConfirmTitle">
    <div class="admin-modal__hero">
      <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-patch-check"></i></span>
      <div>
        <h3 id="approveConfirmTitle" class="admin-modal__title">Confirm Approval</h3>
        <p class="admin-modal__desc">Are you sure you want to approve this student?</p>
        <p class="admin-modal__desc"><strong id="approveConfirmStudentName">Student</strong></p>
      </div>
    </div>
    <div id="approveConfirmError" class="admin-modal__error"></div>
    <div class="admin-modal__actions">
      <button type="button" id="approveConfirmCancelBtn" class="admin-modal__btn admin-modal__btn--ghost">Cancel</button>
      <button type="button" id="approveConfirmSubmitBtn" class="admin-modal__btn admin-modal__btn--ok"><i class="bi bi-check2-circle"></i> Confirm</button>
    </div>
  </section>
</div>

<div id="approveSuccessModalOverlay" class="admin-modal-overlay" aria-hidden="true">
  <section class="admin-modal admin-feedback-modal" role="dialog" aria-modal="true" aria-labelledby="approveSuccessTitle">
    <span class="admin-feedback-icon admin-feedback-icon--success admin-feedback-icon--pulse"><i class="bi bi-check-circle-fill"></i></span>
    <h3 id="approveSuccessTitle" class="admin-modal__title">Student Approved</h3>
    <p id="approveSuccessMessage" class="admin-modal__desc">The student has been successfully approved.</p>
    <div class="admin-modal__actions">
      <button type="button" id="approveSuccessContinueBtn" class="admin-modal__btn admin-modal__btn--ok"><i class="bi bi-arrow-right-circle"></i> Go to Enrolled</button>
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
      fetch('admin_students_presence.php?ids=' + encodeURIComponent(idList.join(',')), {
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

  (function () {
    var confirmOverlay = document.getElementById('approveConfirmModalOverlay');
    var successOverlay = document.getElementById('approveSuccessModalOverlay');
    var confirmName = document.getElementById('approveConfirmStudentName');
    var confirmError = document.getElementById('approveConfirmError');
    var confirmCancel = document.getElementById('approveConfirmCancelBtn');
    var confirmSubmit = document.getElementById('approveConfirmSubmitBtn');
    var successMsg = document.getElementById('approveSuccessMessage');
    var successContinue = document.getElementById('approveSuccessContinueBtn');
    var loadingOverlay = document.getElementById('actionLoadingModalOverlay');
    var loadingTitle = document.getElementById('actionLoadingTitle');
    var loadingMessage = document.getElementById('actionLoadingMessage');
    var currentForm = null;
    var redirectUrl = 'admin_students.php?tab=enrolled&q=&page=1';

    if (!confirmOverlay || !confirmSubmit) return;

    function openConfirm(form) {
      currentForm = form;
      var studentName = form ? (form.getAttribute('data-student-name') || 'this student') : 'this student';
      confirmName.textContent = studentName;
      confirmError.textContent = '';
      confirmOverlay.classList.add('is-open');
      confirmOverlay.setAttribute('aria-hidden', 'false');
    }
    function closeConfirm() {
      confirmOverlay.classList.remove('is-open');
      confirmOverlay.setAttribute('aria-hidden', 'true');
      confirmError.textContent = '';
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

    document.querySelectorAll('.js-approve-form').forEach(function(form) {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        openConfirm(form);
      });
    });
    if (confirmCancel) confirmCancel.addEventListener('click', closeConfirm);
    confirmOverlay.addEventListener('click', function(e) {
      if (e.target === confirmOverlay) closeConfirm();
    });
    if (successContinue) {
      successContinue.addEventListener('click', function() {
        window.location.href = redirectUrl;
      });
    }

    confirmSubmit.addEventListener('click', function() {
      if (!currentForm) return;
      confirmSubmit.disabled = true;
      confirmSubmit.textContent = 'Approving...';
      confirmError.textContent = '';
      closeConfirm();
      showLoading('Approving student...', 'Finalizing approval and sending notification email.');

      var formData = new FormData(currentForm);
      formData.append('ajax', '1');

      fetch(currentForm.action || 'activate_user.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data || !data.ok) {
          hideLoading();
          openConfirm(currentForm);
          confirmError.textContent = (data && data.error) ? data.error : 'Approval failed. Please try again.';
          return;
        }
        redirectUrl = data.redirect_url || redirectUrl;
        hideLoading();
        openSuccess(data.message || 'Student approved successfully.');
      })
      .catch(function() {
        hideLoading();
        openConfirm(currentForm);
        confirmError.textContent = 'Request failed. Please check your connection and try again.';
      })
      .finally(function() {
        confirmSubmit.disabled = false;
        confirmSubmit.textContent = 'Confirm';
      });
    });
  })();

  (function () {
    var csrf = <?php echo json_encode($csrf); ?>;
    var deleteUrl = 'admin_student_delete.php';
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
</script>
</body>
</html>
