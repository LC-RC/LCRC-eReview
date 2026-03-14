<?php
require_once 'auth.php';
requireRole('admin');

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
$mk = function(string $t, int $p = 1) use ($q) : string {
  $params = ['tab' => $t, 'q' => $q, 'page' => $p];
  return 'admin_students.php?' . http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5">
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-people"></i> Students
    </h1>
    <p class="text-gray-500 mt-1">Track enrollment status, approvals, access, and expirations.</p>
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
    <div class="flex flex-wrap justify-between items-center gap-4 mb-4">
      <nav class="flex flex-wrap gap-2" aria-label="Student tabs">
        <a href="<?php echo h($mk('enrolled', 1)); ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition <?php echo $tab === 'enrolled' ? 'bg-primary text-white border-2 border-primary' : 'bg-gray-100 text-primary border-2 border-gray-200 hover:bg-gray-200'; ?>">
          <i class="bi bi-check2-circle"></i> Enrolled <span class="px-2 py-0.5 rounded-full text-sm font-bold <?php echo $tab === 'enrolled' ? 'bg-white/20' : 'bg-gray-200 text-gray-700'; ?>"><?php echo (int)$counts['enrolled']; ?></span>
        </a>
        <a href="<?php echo h($mk('pending', 1)); ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition <?php echo $tab === 'pending' ? 'bg-primary text-white border-2 border-primary' : 'bg-gray-100 text-primary border-2 border-gray-200 hover:bg-gray-200'; ?>">
          <i class="bi bi-hourglass-split"></i> Pending <span class="px-2 py-0.5 rounded-full text-sm font-bold <?php echo $tab === 'pending' ? 'bg-white/20' : 'bg-gray-200 text-gray-700'; ?>"><?php echo (int)$counts['pending']; ?></span>
        </a>
        <a href="<?php echo h($mk('expired', 1)); ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition <?php echo $tab === 'expired' ? 'bg-primary text-white border-2 border-primary' : 'bg-gray-100 text-primary border-2 border-gray-200 hover:bg-gray-200'; ?>">
          <i class="bi bi-calendar-x"></i> Expired <span class="px-2 py-0.5 rounded-full text-sm font-bold <?php echo $tab === 'expired' ? 'bg-white/20' : 'bg-gray-200 text-gray-700'; ?>"><?php echo (int)$counts['expired']; ?></span>
        </a>
        <a href="<?php echo h($mk('rejected', 1)); ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition <?php echo $tab === 'rejected' ? 'bg-primary text-white border-2 border-primary' : 'bg-gray-100 text-primary border-2 border-gray-200 hover:bg-gray-200'; ?>">
          <i class="bi bi-x-circle"></i> Rejected <span class="px-2 py-0.5 rounded-full text-sm font-bold <?php echo $tab === 'rejected' ? 'bg-white/20' : 'bg-gray-200 text-gray-700'; ?>"><?php echo (int)$counts['rejected']; ?></span>
        </a>
        <a href="<?php echo h($mk('all', 1)); ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg font-medium transition <?php echo $tab === 'all' ? 'bg-primary text-white border-2 border-primary' : 'bg-gray-100 text-primary border-2 border-gray-200 hover:bg-gray-200'; ?>">
          <i class="bi bi-collection"></i> All <span class="px-2 py-0.5 rounded-full text-sm font-bold <?php echo $tab === 'all' ? 'bg-white/20' : 'bg-gray-200 text-gray-700'; ?>"><?php echo (int)$counts['all']; ?></span>
        </a>
      </nav>
      <form method="GET" class="flex flex-wrap gap-2 items-center">
        <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
        <div class="relative min-w-[280px]">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><i class="bi bi-search"></i></span>
          <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search name or email..." class="input-custom pl-10">
        </div>
        <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-primary text-primary hover:bg-primary hover:text-white transition inline-flex items-center gap-2"><i class="bi bi-funnel"></i> Apply</button>
      </form>
    </div>
    <p class="text-gray-500 text-sm">Showing <?php echo $total ? ($offset + 1) : 0; ?>-<?php echo min($offset + $perPage, $total); ?> of <?php echo $total; ?> students</p>
  </div>

  <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-left">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-5 py-3 font-semibold text-gray-700">Name</th>
            <th class="px-5 py-3 font-semibold text-gray-700">Email</th>
            <th class="px-5 py-3 font-semibold text-gray-700">Status</th>
            <th class="px-5 py-3 font-semibold text-gray-700">Access</th>
            <th class="px-5 py-3 font-semibold text-gray-700 w-[420px]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($total === 0): ?>
            <tr>
              <td colspan="5" class="px-5 py-12 text-center text-gray-500">
                <i class="bi bi-inbox text-4xl block mb-2"></i>
                <div class="font-semibold">No students found</div>
                <p class="text-sm mt-1">Try changing the tab or clearing search.</p>
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
              ?>
              <tr class="border-b border-gray-100 hover:bg-gray-50/50">
                <td class="px-5 py-3">
                  <div class="font-semibold text-gray-800"><?php echo h($row['full_name']); ?></div>
                  <div class="text-gray-500 text-sm"><?php echo h($schoolLabel); ?> • <?php echo h($row['review_type']); ?></div>
                </td>
                <td class="px-5 py-3"><?php echo h($row['email']); ?></td>
                <td class="px-5 py-3">
                  <span class="px-2.5 py-1 rounded-full text-xs font-medium <?php echo $badgeClass; ?>"><?php echo h($row['status']); ?></span>
                  <?php if ($isExpired): ?>
                    <span class="ml-1 px-2.5 py-1 rounded-full text-xs font-medium bg-gray-200 text-gray-700">expired</span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3"><?php echo h($access); ?></td>
                <td class="px-5 py-3">
                  <div class="flex flex-wrap gap-2 items-center">
                    <a href="admin_student_view.php?id=<?php echo (int)$row['user_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-eye"></i> View</a>
                    <?php if ($hasProof): ?>
                      <a href="admin_payment_proof.php?user_id=<?php echo (int)$row['user_id']; ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition"><i class="bi bi-receipt"></i> Proof</a>
                    <?php else: ?>
                      <span class="text-gray-500 text-sm">No proof</span>
                    <?php endif; ?>

                    <?php if ($row['status'] !== 'approved'): ?>
                      <form class="inline-flex gap-2 ml-auto" action="activate_user.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
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
</main>
</body>
</html>
