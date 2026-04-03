<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/profile_avatar.php';

$pageTitle = 'College students';
$csrf = generateCSRFToken();

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'all'); // all|approved|pending|rejected
$sort = (string)($_GET['sort'] ?? 'created_desc'); // created_desc|created_asc|name_asc|name_desc
$validStatus = ['all', 'approved', 'pending', 'rejected'];
$validSort = ['created_desc', 'created_asc', 'name_asc', 'name_desc'];
if (!in_array($statusFilter, $validStatus, true)) { $statusFilter = 'all'; }
if (!in_array($sort, $validSort, true)) { $sort = 'created_desc'; }

$list = [];
$q = mysqli_query($conn, "SELECT user_id, full_name, email, status, created_at, access_end, school, section, profile_picture, use_default_avatar FROM users WHERE role='college_student' ORDER BY created_at DESC");
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $hay = mb_strtolower((string)($r['full_name'] ?? '') . ' ' . (string)($r['email'] ?? '') . ' ' . (string)($r['school'] ?? '') . ' ' . (string)($r['section'] ?? ''));
            if (mb_strpos($hay, $needle) === false) {
                continue;
            }
        }
        $st = (string)($r['status'] ?? '');
        if ($statusFilter !== 'all' && $st !== $statusFilter) {
            continue;
        }
        $list[] = $r;
    }
    mysqli_free_result($q);
}

usort($list, static function ($a, $b) use ($sort) {
    $ca = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $cb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
    if ($sort === 'created_asc') { return $ca <=> $cb; }
    if ($sort === 'name_asc') { return strcasecmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? '')); }
    if ($sort === 'name_desc') { return strcasecmp((string)($b['full_name'] ?? ''), (string)($a['full_name'] ?? '')); }
    return $cb <=> $ca;
});

$countTotal = count($list);
$countApproved = 0;
$countPending = 0;
$countRejected = 0;
foreach ($list as $row) {
    $st = (string)($row['status'] ?? '');
    if ($st === 'approved') { $countApproved++; }
    elseif ($st === 'pending') { $countPending++; }
    elseif ($st === 'rejected') { $countRejected++; }
}

$flashMessage = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
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
    .toolbar-sticky { position: sticky; top: .6rem; z-index: 45; }
    .toolbar-wrap { display:flex; flex-direction:column; gap:.8rem; }
    .toolbar-top { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:.75rem; align-items:center; }
    .search-sort-form { display:flex; flex-wrap:wrap; gap:.55rem; align-items:center; }
    .search-input { flex:1 1 320px; min-width:220px; border:1px solid #ccefdc; border-radius:.65rem; background:#fff; padding:.58rem .72rem; font-size:.86rem; color:#14532d; }
    .sort-select { border:1px solid #ccefdc; border-radius:.65rem; background:#fff; padding:.56rem .68rem; font-size:.84rem; color:#14532d; font-weight:700; }
    .apply-btn-prof{
      display:inline-flex;align-items:center;gap:.38rem;padding:.52rem .95rem;border-radius:.62rem;border:1px solid #15803d;
      background:linear-gradient(135deg,#16a34a 0%,#15803d 100%);color:#fff;font-size:.82rem;font-weight:800;
      box-shadow:0 10px 18px -16px rgba(21,128,61,.9);transition:transform .2s ease,box-shadow .2s ease,background-color .2s ease,border-color .2s ease;
    }
    .apply-btn-prof:hover{transform:translateY(-1px);border-color:#166534;background:linear-gradient(135deg,#15803d 0%,#166534 100%);}
    .chip { display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .7rem; border-radius:999px; border:1px solid #cceddc; background:#fff; color:#14532d; font-size:.76rem; font-weight:800; transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease; }
    .chip:hover { transform:translateY(-1px); border-color:#86efac; box-shadow:0 8px 16px -16px rgba(20,83,45,.8); }
    .chip.is-active { background:#15803d; color:#fff; border-color:#15803d; }
    .counter-row { display:flex; flex-wrap:wrap; gap:.45rem; }
    .counter-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .62rem; border-radius:.55rem; border:1px solid #cceddc; background:#fff; color:#14532d; font-size:.74rem; font-weight:800; }
    .table-head { background: linear-gradient(180deg, #edfff4 0%, #f6fff9 100%); }
    .table-head th { font-size: .78rem; text-transform: uppercase; letter-spacing: .01em; font-weight: 800; color: #166534; }
    .table-row { transition: background-color .2s ease; }
    .table-row:hover { background: #f4fff8; }
    .status-pill { border-radius: 9999px; }
    .avatar-cell { display:flex; align-items:center; gap:.58rem; min-width:180px; }
    .avatar-thumb { width:34px; height:34px; border-radius:999px; border:1px solid #bbf7d0; object-fit:cover; background:#ecfdf3; flex-shrink:0; }
    .avatar-initial { width:34px; height:34px; border-radius:999px; border:1px solid #bbf7d0; background:#ecfdf3; color:#166534; display:inline-flex; align-items:center; justify-content:center; font-size:.76rem; font-weight:900; flex-shrink:0; }
    .meta-sub { font-size:.72rem; color:#64748b; line-height:1.2; margin-top:.04rem; }
    .dash-anim { opacity: 0; transform: translateY(12px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; }
    .delay-2 { animation-delay: .12s; }
    .delay-3 { animation-delay: .18s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .dash-anim { opacity: 1; transform: none; animation: none; }
    }
    @media (max-width: 980px) {
      .toolbar-top { grid-template-columns: 1fr; }
      .table-card { overflow-x: auto; }
    }
    .flash-banner {
      border-radius: .65rem; border: 1px solid #86efac; background: linear-gradient(180deg,#ecfdf5 0%,#f0fdf4 100%);
      color: #14532d; padding: .75rem 1rem; font-size: .88rem; font-weight: 700; margin-bottom: 1rem;
    }
    .avatar-wrap { position: relative; width: 40px; height: 40px; flex-shrink: 0; }
    .avatar-wrap .avatar-thumb { width: 40px; height: 40px; }
    .avatar-wrap .avatar-initial { width: 40px; height: 40px; display: none; }
    .avatar-wrap.show-fallback .avatar-thumb { display: none !important; }
    .avatar-wrap.show-fallback .avatar-initial { display: inline-flex !important; }
    .cell-chip {
      display: inline-flex; align-items: center; gap: .4rem; max-width: 100%;
      padding: .38rem .62rem; border-radius: .55rem; font-size: .8rem; font-weight: 700;
      border: 1px solid transparent; line-height: 1.25;
    }
    .cell-chip-section { background: linear-gradient(180deg,#eff6ff 0%,#f8fafc 100%); border-color: #bfdbfe; color: #1e40af; }
    .cell-chip-school { background: linear-gradient(180deg,#f5f3ff 0%,#faf5ff 100%); border-color: #ddd6fe; color: #5b21b6; }
    .cell-chip-email {
      background: linear-gradient(180deg,#ecfdf5 0%,#f0fdf4 100%); border-color: #a7f3d0; color: #14532d;
    }
    .cell-chip-email a { color: inherit; text-decoration: none; word-break: break-all; }
    .cell-chip-email a:hover { text-decoration: underline; color: #15803d; }
    .cell-chip i { opacity: .85; flex-shrink: 0; }
    .cell-empty { color: #94a3b8; font-weight: 600; font-size: .8rem; }
    .action-btns { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
    .btn-view-prof {
      display: inline-flex; align-items: center; gap: .28rem; padding: .36rem .62rem; border-radius: .5rem;
      font-size: .76rem; font-weight: 800; border: 1px solid #166534; background: #fff; color: #166534;
      text-decoration: none; transition: background .15s ease, color .15s ease;
    }
    .btn-view-prof:hover { background: #166534; color: #fff; }
    .btn-del-prof {
      display: inline-flex; align-items: center; gap: .28rem; padding: .36rem .62rem; border-radius: .5rem;
      font-size: .76rem; font-weight: 800; border: 1px solid #fecaca; background: #fff; color: #b91c1c;
      cursor: pointer; transition: background .15s ease, border-color .15s ease;
    }
    .btn-del-prof:hover { background: #fef2f2; border-color: #f87171; }
    .modal-del-backdrop {
      position: fixed; inset: 0; z-index: 2000; display: none; align-items: center; justify-content: center;
      background: rgba(15,23,42,.5); padding: 1rem; backdrop-filter: blur(2px);
    }
    .modal-del-backdrop.is-open { display: flex; }
    .modal-del-panel {
      width: 100%; max-width: 420px; border-radius: .85rem; border: 1px solid #e2e8f0;
      background: #fff; box-shadow: 0 24px 48px -24px rgba(15,23,42,.35); padding: 1.25rem;
    }
    .modal-del-panel h3 { margin: 0 0 .5rem; font-size: 1.1rem; font-weight: 800; color: #0f172a; }
    .modal-del-panel p { margin: 0 0 1rem; font-size: .88rem; color: #475569; line-height: 1.45; }
    .modal-del-actions { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: flex-end; }
    .modal-del-cancel {
      padding: .5rem 1rem; border-radius: .5rem; border: 1px solid #e2e8f0; background: #fff; font-weight: 700; font-size: .85rem; color: #334155;
    }
    .modal-del-confirm {
      padding: .5rem 1rem; border-radius: .5rem; border: 1px solid #b91c1c; background: #dc2626; font-weight: 800; font-size: .85rem; color: #fff;
    }
  </style>
</head>
<body class="font-sans antialiased prof-page">
  <?php include __DIR__ . '/professor_admin_sidebar.php'; ?>

  <main class="dashboard-shell w-full max-w-none">
    <?php if ($flashMessage !== null && $flashMessage !== ''): ?>
      <div class="flash-banner dash-anim delay-1" role="status"><?php echo h((string)$flashMessage); ?></div>
    <?php endif; ?>
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
    <div class="table-card toolbar-sticky dash-anim delay-2 p-4 mb-4">
      <div class="toolbar-wrap">
        <div class="toolbar-top">
          <form method="get" class="search-sort-form">
            <input type="text" name="q" value="<?php echo h($search); ?>" class="search-input" placeholder="Search by name, email, section, or school...">
            <select name="sort" class="sort-select">
              <option value="created_desc" <?php echo $sort === 'created_desc' ? 'selected' : ''; ?>>Recently created</option>
              <option value="created_asc" <?php echo $sort === 'created_asc' ? 'selected' : ''; ?>>Oldest created</option>
              <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
              <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
            </select>
            <button class="apply-btn-prof" type="submit"><i class="bi bi-search"></i> Apply</button>
          </form>
          <div class="counter-row">
            <span class="counter-chip"><i class="bi bi-grid"></i> Total: <?php echo (int)$countTotal; ?></span>
            <span class="counter-chip"><i class="bi bi-check-circle"></i> Approved: <?php echo (int)$countApproved; ?></span>
            <span class="counter-chip"><i class="bi bi-clock-history"></i> Pending: <?php echo (int)$countPending; ?></span>
            <span class="counter-chip"><i class="bi bi-x-circle"></i> Rejected: <?php echo (int)$countRejected; ?></span>
          </div>
        </div>
        <div class="flex flex-wrap gap-2">
          <?php
            $tabs = ['all' => ['All', 'bi-grid'], 'approved' => ['Approved', 'bi-check-circle'], 'pending' => ['Pending', 'bi-clock-history'], 'rejected' => ['Rejected', 'bi-x-circle']];
            foreach ($tabs as $k => $tab):
              $url = '?status=' . urlencode($k) . '&sort=' . urlencode($sort) . '&q=' . urlencode($search);
          ?>
            <a href="<?php echo h($url); ?>" class="chip <?php echo $statusFilter === $k ? 'is-active' : ''; ?>"><i class="bi <?php echo h($tab[1]); ?>"></i> <?php echo h($tab[0]); ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="table-card dash-anim delay-3">
      <table class="w-full text-sm text-left min-w-[1180px]">
        <thead class="table-head border-b border-green-100">
          <tr>
            <th class="px-4 py-3">Profile</th>
            <th class="px-4 py-3">Name</th>
            <th class="px-4 py-3">Section</th>
            <th class="px-4 py-3">School</th>
            <th class="px-4 py-3">Email</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3">Access end</th>
            <th class="px-4 py-3">Created</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-green-100">
          <?php if (empty($list)): ?>
          <tr><td colspan="9" class="px-4 py-10 text-center text-gray-500">No college students yet. Create one to get started.</td></tr>
          <?php else: ?>
            <?php foreach ($list as $u): ?>
            <tr class="table-row">
              <?php
                $avatarImgSrc = ereview_avatar_img_src((string)($u['profile_picture'] ?? ''));
                $useDefault = !empty($u['use_default_avatar']);
                $initial = ereview_avatar_initial((string)($u['full_name'] ?? ''));
                $sectionTxt = trim((string)($u['section'] ?? ''));
                $schoolTxt = trim((string)($u['school'] ?? ''));
                $emailTxt = trim((string)($u['email'] ?? ''));
                $statusLower = strtolower((string)($u['status'] ?? ''));
                $statusClass = $statusLower === 'approved'
                  ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
                  : ($statusLower === 'pending' ? 'bg-amber-50 text-amber-900 border-amber-200' : 'bg-red-50 text-red-800 border-red-200');
              ?>
              <td class="px-4 py-3">
                <div class="avatar-cell">
                  <div class="avatar-wrap<?php echo ($avatarImgSrc === '' || $useDefault) ? ' show-fallback' : ''; ?>" data-avatar-wrap>
                    <?php if ($avatarImgSrc !== '' && !$useDefault): ?>
                      <img src="<?php echo h($avatarImgSrc); ?>" alt="" class="avatar-thumb" width="40" height="40" loading="lazy" decoding="async"
                           onerror="this.closest('[data-avatar-wrap]') && this.closest('[data-avatar-wrap]').classList.add('show-fallback');">
                    <?php endif; ?>
                    <span class="avatar-initial" aria-hidden="true"><?php echo h($initial); ?></span>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3 font-medium">
                <?php echo h($u['full_name']); ?>
                <div class="meta-sub">ID: <?php echo (int)$u['user_id']; ?></div>
              </td>
              <td class="px-4 py-3">
                <?php if ($sectionTxt !== ''): ?>
                  <span class="cell-chip cell-chip-section"><i class="bi bi-layers"></i> <?php echo h($sectionTxt); ?></span>
                <?php else: ?>
                  <span class="cell-empty">—</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <?php if ($schoolTxt !== ''): ?>
                  <span class="cell-chip cell-chip-school"><i class="bi bi-building"></i> <?php echo h($schoolTxt); ?></span>
                <?php else: ?>
                  <span class="cell-empty">—</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <?php if ($emailTxt !== ''): ?>
                  <span class="cell-chip cell-chip-email"><i class="bi bi-envelope"></i> <a href="mailto:<?php echo h($emailTxt); ?>"><?php echo h($emailTxt); ?></a></span>
                <?php else: ?>
                  <span class="cell-empty">—</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3"><span class="status-pill inline-flex px-2 py-0.5 text-xs font-semibold border <?php echo h($statusClass); ?>"><?php echo h($u['status']); ?></span></td>
              <td class="px-4 py-3 text-gray-600"><?php echo !empty($u['access_end']) ? h(date('M j, Y', strtotime($u['access_end']))) : '—'; ?></td>
              <td class="px-4 py-3 text-gray-600"><?php echo h(date('M j, Y', strtotime($u['created_at']))); ?></td>
              <td class="px-4 py-3 text-right">
                <div class="action-btns justify-end">
                  <a class="btn-view-prof" href="professor_college_student_view.php?id=<?php echo (int)$u['user_id']; ?>"><i class="bi bi-eye"></i> View</a>
                  <button type="button" class="btn-del-prof js-open-delete-student"
                    data-user-id="<?php echo (int)$u['user_id']; ?>"
                    data-student-name="<?php echo h($u['full_name']); ?>"><i class="bi bi-trash"></i> Delete</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="modal-del-backdrop" id="deleteStudentModal" role="dialog" aria-modal="true" aria-labelledby="deleteStudentTitle">
      <div class="modal-del-panel">
        <h3 id="deleteStudentTitle">Remove college student?</h3>
        <p>This permanently deletes the account for <strong id="deleteStudentNameDisplay"></strong>. Exam attempts and uploads linked to this account may be removed by the database. This cannot be undone.</p>
        <form method="post" action="professor_college_student_delete.php" id="deleteStudentForm">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="user_id" id="deleteStudentUserId" value="">
          <div class="modal-del-actions">
            <button type="button" class="modal-del-cancel" id="deleteStudentCancel">Cancel</button>
            <button type="submit" class="modal-del-confirm">Delete student</button>
          </div>
        </form>
      </div>
    </div>
    <script>
    (function () {
      var modal = document.getElementById('deleteStudentModal');
      var form = document.getElementById('deleteStudentForm');
      var uidInput = document.getElementById('deleteStudentUserId');
      var nameEl = document.getElementById('deleteStudentNameDisplay');
      var cancel = document.getElementById('deleteStudentCancel');
      if (!modal || !form) return;
      function openModal(uid, name) {
        uidInput.value = String(uid);
        nameEl.textContent = name || 'this student';
        modal.classList.add('is-open');
      }
      function closeModal() {
        modal.classList.remove('is-open');
      }
      document.querySelectorAll('.js-open-delete-student').forEach(function (btn) {
        btn.addEventListener('click', function () {
          openModal(btn.getAttribute('data-user-id'), btn.getAttribute('data-student-name'));
        });
      });
      if (cancel) cancel.addEventListener('click', closeModal);
      modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
      });
    })();
    </script>
</main>
</body>
</html>
