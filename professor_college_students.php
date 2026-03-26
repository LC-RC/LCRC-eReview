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
  <style>
    .prof-page { background: linear-gradient(180deg, #eefaf3 0%, #e6f6ee 45%, #edf9f2 100%); min-height: 100%; }
    .dashboard-shell { padding-bottom: 1.5rem; color: #0f172a; }
    .prof-hero {
      border-radius: 0.75rem; border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #0f766e 0%, #0e9f6e 35%, #16a34a 75%, #15803d 100%);
      box-shadow: 0 14px 34px -20px rgba(5, 46, 22, 0.75), inset 0 1px 0 rgba(255,255,255,0.22);
    }
    .prof-icon { background: rgba(255,255,255,0.22); border: 1px solid rgba(255,255,255,0.34); color: #fff; }
    .prof-btn {
      border-radius: 9999px; transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
    }
    .prof-btn:hover { transform: translateY(-2px); box-shadow: 0 14px 24px -20px rgba(21,128,61,.85); }
    .section-title {
      display: flex; align-items: center; gap: .5rem; margin: 0 0 .85rem; padding: .45rem .65rem;
      border: 1px solid #d1fae5; border-radius: .62rem; background: linear-gradient(180deg,#f5fff9 0%,#fff 100%);
      color: #14532d; font-size: 1.03rem; font-weight: 800;
    }
    .section-title i {
      width: 1.55rem; height: 1.55rem; border-radius: .45rem; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #bbf7d0; background: #ecfdf3; color: #15803d; font-size: .83rem;
    }
    .table-card {
      border-radius: .75rem; border: 1px solid rgba(22,163,74,.22); overflow: hidden;
      background: linear-gradient(180deg, #f4fff8 0%, #fff 40%);
      box-shadow: 0 10px 28px -22px rgba(21,128,61,.58), 0 1px 0 rgba(255,255,255,.8) inset;
      transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .table-card:hover { transform: translateY(-2px); border-color: rgba(22,163,74,.38); box-shadow: 0 20px 34px -24px rgba(15,118,110,.4); }
    .table-head { background: linear-gradient(180deg, #edfff4 0%, #f6fff9 100%); }
    .table-head th { font-size: .78rem; text-transform: uppercase; letter-spacing: .01em; font-weight: 800; color: #166534; }
    .table-row { transition: background-color .2s ease; }
    .table-row:hover { background: #f4fff8; }
    .status-pill { border-radius: 9999px; }
    .dash-anim { opacity: 0; transform: translateY(12px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; }
    .delay-2 { animation-delay: .12s; }
    .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
    }
  </style>
</head>
<body class="font-sans antialiased prof-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <main class="dashboard-shell w-full max-w-none">
    <div class="mb-6 dash-anim delay-1">
      <div class="prof-hero overflow-hidden">
        <div class="p-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex items-start gap-3">
            <div class="prof-icon w-11 h-11 rounded-xl flex items-center justify-center shrink-0">
              <i class="bi bi-people text-xl"></i>
            </div>
            <div>
              <h1 class="text-2xl font-bold text-white m-0 leading-tight">College students</h1>
              <p class="text-white/90 mt-1 mb-0">Accounts with the college student role.</p>
            </div>
          </div>
          <a href="professor_create_college_student.php" class="prof-btn inline-flex items-center gap-2 px-4 py-2.5 font-semibold bg-white text-green-800 hover:bg-green-50 shadow-sm">
            <i class="bi bi-person-plus"></i> Add student
          </a>
        </div>
      </div>
    </div>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-mortarboard"></i> Student Directory</h2>
    <div class="table-card dash-anim delay-3">
      <table class="w-full text-sm text-left">
        <thead class="table-head border-b border-green-100">
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
            <tr class="table-row">
              <td class="px-4 py-3 font-medium"><?php echo h($u['full_name']); ?></td>
              <td class="px-4 py-3"><?php echo h($u['email']); ?></td>
              <td class="px-4 py-3"><span class="status-pill inline-flex px-2 py-0.5 text-xs font-semibold bg-green-50 text-green-800 border border-green-200"><?php echo h($u['status']); ?></span></td>
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
