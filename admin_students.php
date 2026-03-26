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
  $sql = "SELECT $selectCols FROM users WHERE $tabWhere AND $searchSql ORDER BY created_at DESC LIMIT ? OFFSET ?";
  $stmt = mysqli_prepare($conn, $sql);
  mysqli_stmt_bind_param($stmt, 'sssii', $nowSql, $like, $like, $perPage, $offset);
} else {
  $sql = "SELECT $selectCols FROM users WHERE $tabWhere AND $searchSql ORDER BY created_at DESC LIMIT ? OFFSET ?";
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
    .admin-students-actions {
      display: flex;
      flex-wrap: nowrap;
      gap: 0.5rem;
      align-items: center;
      justify-content: flex-end;
      min-width: max-content;
      width: 100%;
    }
    .student-action-btn {
      --btn-bg: rgba(255, 255, 255, 0.06);
      --btn-bd: rgba(255, 255, 255, 0.16);
      --btn-fg: #e5e7eb;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.34rem;
      min-height: 2rem;
      padding: 0.38rem 0.74rem;
      border-radius: 0.66rem;
      border: 1px solid var(--btn-bd);
      background: var(--btn-bg);
      color: var(--btn-fg);
      font-size: 0.74rem;
      font-weight: 700;
      line-height: 1;
      text-decoration: none;
      cursor: pointer;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.08);
      transition: transform 0.15s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease, color 0.2s ease;
    }
    .student-action-btn i {
      font-size: 0.82rem;
      transition: transform 0.2s ease;
    }
    .student-action-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 16px rgba(2, 6, 23, 0.26);
    }
    .student-action-btn:hover i { transform: scale(1.08); }
    .student-action-btn:focus-visible {
      outline: none;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.22);
    }
    .student-action-btn--view {
      --btn-bg: linear-gradient(140deg, rgba(37, 99, 235, 0.28) 0%, rgba(29, 78, 216, 0.18) 100%);
      --btn-bd: rgba(96, 165, 250, 0.5);
      --btn-fg: #dbeafe;
    }
    .student-action-btn--proof {
      --btn-bg: linear-gradient(140deg, rgba(124, 58, 237, 0.28) 0%, rgba(91, 33, 182, 0.18) 100%);
      --btn-bd: rgba(167, 139, 250, 0.48);
      --btn-fg: #ede9fe;
    }
    .student-action-btn--extend {
      --btn-bg: linear-gradient(140deg, rgba(249, 115, 22, 0.25) 0%, rgba(194, 65, 12, 0.18) 100%);
      --btn-bd: rgba(253, 186, 116, 0.52);
      --btn-fg: #ffedd5;
    }
    .student-action-btn--approve {
      --btn-bg: linear-gradient(140deg, rgba(16, 185, 129, 0.26) 0%, rgba(5, 150, 105, 0.18) 100%);
      --btn-bd: rgba(110, 231, 183, 0.52);
      --btn-fg: #dcfce7;
    }
    .student-action-btn--reject {
      --btn-bg: linear-gradient(140deg, rgba(239, 68, 68, 0.26) 0%, rgba(185, 28, 28, 0.2) 100%);
      --btn-bd: rgba(252, 165, 165, 0.5);
      --btn-fg: #fee2e2;
    }
    .student-action-btn--delete {
      --btn-bg: linear-gradient(140deg, rgba(220, 38, 38, 0.26) 0%, rgba(153, 27, 27, 0.2) 100%);
      --btn-bd: rgba(252, 165, 165, 0.46);
      --btn-fg: #fee2e2;
    }
    .student-action-btn[disabled] {
      opacity: 0.5;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
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
      min-width: 34rem;
    }
    .student-actions-head {
      text-align: center !important;
    }
    .access-range {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      border-radius: 0.68rem;
      border: 1px solid rgba(148, 163, 184, 0.38);
      background: linear-gradient(180deg, rgba(30, 41, 59, 0.22) 0%, rgba(15, 23, 42, 0.18) 100%);
      padding: 0.34rem 0.5rem;
      color: #e2e8f0;
      font-size: 0.75rem;
      font-weight: 700;
      line-height: 1;
      white-space: nowrap;
    }
    .access-range__date {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.2rem 0.4rem;
      border-radius: 0.5rem;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: #ffffff;
    }
    .access-range__arrow {
      color: rgba(191, 219, 254, 0.95);
      font-size: 0.75rem;
    }
    .access-range--empty {
      color: rgba(226, 232, 240, 0.72);
      border-style: dashed;
      background: rgba(15, 23, 42, 0.12);
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
<body class="font-sans antialiased admin-app">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-5 py-5 mb-5">
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

  <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-5">
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

  <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-2">
      <div class="flex items-center gap-2">
        <span class="font-semibold text-gray-800">Students</span>
        <span class="px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-200 text-gray-700"><?php echo (int)$total; ?></span>
      </div>
      <p class="text-gray-500 text-sm hidden md:block m-0">Tip: Click <strong>View</strong> for details, approve pending, or extend access.</p>
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
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Access</th>
            <th class="px-5 py-3 font-semibold text-gray-700 w-[540px] student-actions-head">Actions</th>
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
                $statusClass = strtolower((string)$row['status']);
                $badgeClass = $statusClass === 'approved' ? 'bg-green-100 text-green-800' : ($statusClass === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800');
                $hasProof = !empty($row['payment_proof']);
                $isExpired = ($statusClass === 'approved' && !empty($row['access_end']) && strtotime($row['access_end']) < time());
                $avatarPath = ereview_avatar_public_path($row['profile_picture'] ?? '');
                $useDefaultAvatar = $hasUseDefaultAvatar ? !empty($row['use_default_avatar']) : true;
                $avatarInitial = ereview_avatar_initial($row['full_name'] ?? 'U');
                $isSessionActive = false;
                $recentThresholdTs = time() - (10 * 60);
                if ($hasIsOnline) {
                  $isSessionActive = !empty($row['is_online']);
                }
                if ($hasLastSeenAt && !empty($row['last_seen_at'])) {
                  $lastSeenTs = strtotime((string)$row['last_seen_at']);
                  if ($lastSeenTs !== false) {
                    $isSessionActive = $isSessionActive || ($lastSeenTs >= $recentThresholdTs);
                  }
                }
                if (!$hasLastSeenAt && $hasLastLoginAt && !empty($row['last_login_at'])) {
                  $lastLoginTs = strtotime((string)$row['last_login_at']);
                  if ($lastLoginTs !== false && $lastLoginTs >= $recentThresholdTs) {
                    $isSessionActive = true;
                  }
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
                <td class="px-5 py-3 text-center" title="<?php echo $access !== '-' && $isExpired ? 'Access ended' : ($access !== '-' ? 'Access period' : 'No access set'); ?>">
                  <?php if ($hasAccessRange): ?>
                    <span class="access-range">
                      <span class="access-range__date"><i class="bi bi-calendar3"></i> <?php echo h($accessStartLabel); ?></span>
                      <span class="access-range__arrow"><i class="bi bi-arrow-right"></i></span>
                      <span class="access-range__date"><i class="bi bi-calendar-check"></i> <?php echo h($accessEndLabel); ?></span>
                    </span>
                  <?php else: ?>
                    <span class="access-range access-range--empty"><i class="bi bi-dash-circle"></i> No access set</span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3 student-action-cell">
                  <div class="admin-students-actions">
                    <a href="admin_student_view.php?id=<?php echo (int)$row['user_id']; ?>" class="student-action-btn student-action-btn--view" title="View details, approve, or extend"><i class="bi bi-eye"></i> View</a>
                    <?php if ($hasProof): ?>
                      <a href="admin_payment_proof.php?user_id=<?php echo (int)$row['user_id']; ?>" target="_blank" rel="noopener" class="student-action-btn student-action-btn--proof" title="View payment proof"><i class="bi bi-receipt"></i> Proof</a>
                    <?php else: ?>
                      <button type="button" class="student-action-btn student-action-btn--proof" disabled title="No payment proof uploaded"><i class="bi bi-receipt"></i> Proof</button>
                    <?php endif; ?>

                    <?php if ($row['status'] !== 'approved'): ?>
                      <form class="student-pending-form js-approve-form" action="activate_user.php" method="POST" data-student-name="<?php echo h($row['full_name']); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                        <input type="hidden" name="return_to" value="<?php echo h($_SERVER['REQUEST_URI'] ?? 'admin_students.php'); ?>">
                        <input type="number" min="1" name="months" class="student-pending-month-input" placeholder="+Months" required>
                        <button type="submit" class="student-action-btn student-action-btn--approve"><i class="bi bi-check2-circle"></i> Approve</button>
                      </form>
                      <form class="inline-flex" action="reject.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                        <button type="submit" class="student-action-btn student-action-btn--reject"><i class="bi bi-x-circle"></i> Reject</button>
                      </form>
                    <?php else: ?>
                      <form class="student-extend-form" action="extend_access.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                        <input type="number" min="1" name="months" placeholder="+Months" required title="Add months">
                        <button type="submit" class="student-action-btn student-action-btn--extend"><i class="bi bi-calendar-plus"></i> Extend</button>
                      </form>
                    <?php endif; ?>
                    <button
                      type="button"
                      class="student-action-btn student-action-btn--delete js-delete-student-btn"
                      data-user-id="<?php echo (int)$row['user_id']; ?>"
                      data-user-name="<?php echo h($row['full_name']); ?>"
                      data-user-email="<?php echo h($row['email']); ?>"
                      title="Delete student permanently">
                      <i class="bi bi-trash"></i> Delete
                    </button>
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

  <section class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden mt-5">
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
    var rows = Array.prototype.slice.call(document.querySelectorAll('tr[data-user-id]'));
    if (!rows.length) return;

    function ids() {
      return rows.map(function (r) { return r.getAttribute('data-user-id'); }).filter(Boolean);
    }

    function applyPresence(presenceMap) {
      rows.forEach(function (row) {
        var id = row.getAttribute('data-user-id');
        var dot = row.querySelector('[data-status-dot]');
        if (!id || !dot) return;
        var active = !!presenceMap[id];
        dot.classList.toggle('student-avatar-status-dot--active', active);
        dot.classList.toggle('student-avatar-status-dot--inactive', !active);
        dot.title = active ? 'Session active' : 'Session inactive';
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
</script>
</body>
</html>
