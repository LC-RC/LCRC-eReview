<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';

$pageTitle = 'College students';
$csrf = generateCSRFToken();

$list = [];
$q = mysqli_query($conn, "SELECT user_id, full_name, email, status, created_at, access_end FROM users WHERE role='college_student' ORDER BY created_at DESC");
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $list[] = $r;
    }
    mysqli_free_result($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
</head>
<body class="font-sans antialiased">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <main class="admin-content max-w-7xl mx-auto w-full px-4 lg:px-6">
    <div class="mb-6">
      <div class="rounded-xl border border-green-200 bg-gradient-to-r from-green-50/70 via-white to-white shadow-sm overflow-hidden">
        <div class="p-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-green-600/10 border border-green-200 flex items-center justify-center shrink-0">
              <i class="bi bi-people text-green-700 text-xl"></i>
            </div>
            <div>
              <h1 class="text-2xl font-bold text-green-900 m-0 leading-tight">College students</h1>
              <p class="text-gray-600 mt-1 mb-0">Accounts with the college student role.</p>
            </div>
          </div>
          <a href="professor_create_college_student.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold bg-green-600 text-white hover:bg-green-700 transition-all duration-300 hover:-translate-y-0.5 shadow-sm hover:shadow-md">
            <i class="bi bi-person-plus"></i> Add student
          </a>
        </div>
      </div>
    </div>

    <div class="rounded-xl border border-green-200 bg-gradient-to-b from-green-50/55 to-white shadow-sm overflow-hidden">
      <table class="w-full text-sm text-left">
        <thead class="bg-green-50 text-green-800 font-semibold border-b border-gray-200">
          <tr>
            <th class="px-4 py-3">Name</th>
            <th class="px-4 py-3">Email</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3 hidden md:table-cell">Access end</th>
            <th class="px-4 py-3 hidden sm:table-cell">Created</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-green-100">
          <?php if (empty($list)): ?>
          <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">No college students yet. Create one to get started.</td></tr>
          <?php else: ?>
            <?php foreach ($list as $u): ?>
            <tr class="hover:bg-green-50/80 transition-colors">
              <td class="px-4 py-3 font-medium"><?php echo h($u['full_name']); ?></td>
              <td class="px-4 py-3"><?php echo h($u['email']); ?></td>
              <td class="px-4 py-3"><span class="inline-flex px-2 py-0.5 rounded-lg text-xs font-semibold bg-green-50 text-green-800 border border-green-200"><?php echo h($u['status']); ?></span></td>
              <td class="px-4 py-3 text-gray-600 hidden md:table-cell"><?php echo !empty($u['access_end']) ? h(date('Y-m-d', strtotime($u['access_end']))) : '—'; ?></td>
              <td class="px-4 py-3 text-gray-600 hidden sm:table-cell"><?php echo h(date('M j, Y', strtotime($u['created_at']))); ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
</body>
</html>
