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
$perPage = 20;
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
      <table class="w-full text-left">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Profile</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Name</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Email</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Status</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Access</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center w-[420px]">Actions</th>
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
              <td colspan="6" class="px-5 py-14 text-center text-gray-500">
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
                $access = '-';
                if (!empty($row['access_start']) || !empty($row['access_end'])) {
                  $access = ($row['access_start'] ? date('Y-m-d', strtotime($row['access_start'])) : '?') . ' → ' . ($row['access_end'] ? date('Y-m-d', strtotime($row['access_end'])) : '?');
                }
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
                  <div class="font-semibold text-gray-800"><?php echo h($row['full_name']); ?></div>
                  <div class="text-gray-500 text-sm"><?php echo h($schoolLabel); ?> • <?php echo h($row['review_type']); ?></div>
                </td>
                <td class="px-5 py-3 text-center"><?php echo h($row['email']); ?></td>
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
                <td class="px-5 py-3 text-center" title="<?php echo $access !== '-' && $isExpired ? 'Access ended' : ($access !== '-' ? 'Access period' : 'No access set'); ?>"><?php echo h($access); ?></td>
                <td class="px-5 py-3 text-center">
                  <div class="flex flex-wrap gap-2 items-center justify-center">
                    <a href="admin_student_view.php?id=<?php echo (int)$row['user_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-primary text-primary hover:bg-primary hover:text-white transition" title="View details, approve, or extend"><i class="bi bi-eye"></i> View</a>
                    <?php if ($hasProof): ?>
                      <a href="admin_payment_proof.php?user_id=<?php echo (int)$row['user_id']; ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition" title="View payment proof"><i class="bi bi-receipt"></i> Proof</a>
                    <?php else: ?>
                      <span class="text-gray-500 text-sm" title="No payment proof uploaded">No proof</span>
                    <?php endif; ?>

                    <?php if ($row['status'] !== 'approved'): ?>
                      <form class="inline-flex gap-2 ml-auto" action="activate_user.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                        <input type="hidden" name="return_to" value="<?php echo h($_SERVER['REQUEST_URI'] ?? 'admin_students.php'); ?>">
                        <input type="number" min="1" max="24" name="months" class="input-custom w-28 py-1.5 text-sm" placeholder="Months" required>
                        <button type="submit" class="px-3 py-1.5 rounded-lg text-sm font-semibold bg-primary text-white hover:bg-primary-dark transition">Approve</button>
                      </form>
                      <form class="inline-flex" action="reject.php" method="POST" onsubmit="return confirm('Reject this student?');">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                        <button type="submit" class="px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition">Reject</button>
                      </form>
                    <?php else: ?>
                      <form class="inline-flex gap-2 ml-auto" action="extend_access.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                        <input type="number" min="1" max="24" name="months" class="input-custom w-28 py-1.5 text-sm" placeholder="+Months" required>
                        <button type="submit" class="px-3 py-1.5 rounded-lg text-sm font-semibold bg-green-600 text-white hover:bg-green-700 transition">Extend</button>
                      </form>
                    <?php endif; ?>
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

  <?php mysqli_stmt_close($stmt); ?>
</div>
</main>
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
</script>
</body>
</html>
