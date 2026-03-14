<?php
require_once 'auth.php';
requireRole('admin');

$userId = sanitizeInt($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: admin_dashboard.php');
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT user_id, full_name, email, review_type, school, school_other, payment_proof, role, status, access_start, access_end, access_months, created_at, updated_at FROM users WHERE user_id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user || $user['role'] !== 'student') {
    header('Location: admin_dashboard.php');
    exit;
}

$schoolLabel = $user['school'] === 'Other' && !empty($user['school_other']) ? $user['school_other'] : $user['school'];
$csrf = generateCSRFToken();
$pageTitle = 'Student Details - ' . $user['full_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-6 py-5 mb-5 flex flex-wrap justify-between items-center gap-4">
    <div>
      <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
        <i class="bi bi-person-badge"></i> Student Details
      </h1>
      <p class="text-gray-500 mt-1">ID: <?php echo (int)$user['user_id']; ?></p>
    </div>
    <div class="flex gap-2">
      <a href="admin_dashboard.php" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-400 text-gray-600 hover:bg-gray-400 hover:text-white transition inline-flex items-center gap-2"><i class="bi bi-arrow-left"></i> Back</a>
      <a href="admin_payment_proof.php?user_id=<?php echo (int)$user['user_id']; ?>" target="_blank" rel="noopener" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-primary text-primary hover:bg-primary hover:text-white transition inline-flex items-center gap-2"><i class="bi bi-receipt"></i> View Proof</a>
    </div>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-green-50 border border-green-200 flex items-center gap-2 text-green-800">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 flex items-center gap-2 text-red-800">
      <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
    <div class="lg:col-span-7">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5">
        <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-card-text"></i> Registration Info</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <div class="text-gray-500 text-sm">Full Name</div>
            <div class="font-semibold text-gray-800"><?php echo h($user['full_name']); ?></div>
          </div>
          <div>
            <div class="text-gray-500 text-sm">Email</div>
            <div class="font-semibold text-gray-800"><?php echo h($user['email']); ?></div>
          </div>
          <div>
            <div class="text-gray-500 text-sm">Review Type</div>
            <div class="font-semibold text-gray-800"><?php echo h($user['review_type']); ?></div>
          </div>
          <div>
            <div class="text-gray-500 text-sm">School</div>
            <div class="font-semibold text-gray-800"><?php echo h($schoolLabel); ?></div>
          </div>
          <div>
            <div class="text-gray-500 text-sm">Status</div>
            <?php $status = strtolower((string)$user['status']); $badgeClass = $status === 'approved' ? 'bg-green-100 text-green-800' : ($status === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'); ?>
            <span class="px-2.5 py-1 rounded-full text-xs font-medium <?php echo $badgeClass; ?>"><?php echo h($user['status']); ?></span>
          </div>
          <div>
            <div class="text-gray-500 text-sm">Registered</div>
            <div class="font-semibold text-gray-800"><?php echo h($user['created_at']); ?></div>
          </div>
          <div class="md:col-span-2">
            <div class="text-gray-500 text-sm">Payment Proof</div>
            <?php if (!empty($user['payment_proof'])): ?>
              <a href="admin_payment_proof.php?user_id=<?php echo (int)$user['user_id']; ?>" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm font-medium border-2 border-primary text-primary hover:bg-primary hover:text-white transition"><i class="bi bi-file-earmark"></i> Open proof in new tab</a>
            <?php else: ?>
              <div class="text-gray-500">No proof uploaded</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="lg:col-span-5">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5">
        <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-calendar-check"></i> Access</h2>
        <div class="space-y-2 mb-4">
          <div><span class="text-gray-500 text-sm">Start:</span> <span class="font-semibold text-gray-800"><?php echo $user['access_start'] ? h($user['access_start']) : '-'; ?></span></div>
          <div><span class="text-gray-500 text-sm">End:</span> <span class="font-semibold text-gray-800"><?php echo $user['access_end'] ? h($user['access_end']) : '-'; ?></span></div>
          <div><span class="text-gray-500 text-sm">Months:</span> <span class="font-semibold text-gray-800"><?php echo $user['access_months'] !== null ? (int)$user['access_months'] : '-'; ?></span></div>
        </div>
        <?php if (strtolower((string)$user['status']) !== 'approved'): ?>
          <form class="flex flex-wrap gap-2" action="activate_user.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
            <input type="number" min="1" max="24" name="months" class="input-custom w-28" placeholder="Months" required>
            <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-check2-circle"></i> Approve</button>
          </form>
          <form class="mt-2" action="reject.php" method="POST" onsubmit="return confirm('Reject this student?');">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
            <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold border-2 border-red-500 text-red-600 hover:bg-red-500 hover:text-white transition inline-flex items-center justify-center gap-2"><i class="bi bi-x-circle"></i> Reject</button>
          </form>
        <?php else: ?>
          <form class="flex flex-wrap gap-2" action="extend_access.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
            <input type="number" min="1" max="24" name="months" class="input-custom w-28" placeholder="+Months" required>
            <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-green-600 text-white hover:bg-green-700 transition inline-flex items-center gap-2"><i class="bi bi-plus-circle"></i> Extend</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
</body>
</html>
