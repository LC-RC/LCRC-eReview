<?php
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/preboards_migrate.php';
require_once __DIR__ . '/includes/preboards_helpers.php';

// Ensure module table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preboards_subjects (
  preboards_subject_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_preboards_subject_name (subject_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$csrf = generateCSRFToken();

// Filters / pagination
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all'; // all|active|inactive
$page = sanitizeInt($_GET['page'] ?? 1, 1);
$perPage = 15;
$offset = ($page - 1) * $perPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_preboards_subjects#preboards-requests');
        exit;
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'decide_request') {
        $reqId = sanitizeInt($_POST['preboards_request_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $adminId = (int) getCurrentUserId();
        if (preboards_decide_request($conn, $reqId, $decision, $adminId)) {
            $_SESSION['message'] = 'Request ' . ($decision === 'approved' ? 'approved' : 'denied') . '.';
        } else {
            $_SESSION['error'] = 'Could not update that request. It may already be decided.';
        }
        header('Location: admin_preboards_subjects#preboards-requests');
        exit;
    }

    if ($action === 'decide_requests_bulk') {
        $decision = $_POST['decision'] ?? '';
        $idsRaw = $_POST['request_ids'] ?? [];
        if (!is_array($idsRaw)) {
            $idsRaw = [];
        }
        $ids = [];
        foreach ($idsRaw as $id) {
            $id = sanitizeInt($id);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        $adminId = (int) getCurrentUserId();
        $ok = 0;
        if (in_array($decision, ['approved', 'denied'], true) && !empty($ids)) {
            foreach ($ids as $reqId) {
                if (preboards_decide_request($conn, (int) $reqId, $decision, $adminId)) {
                    $ok++;
                }
            }
        }
        if ($ok > 0) {
            $_SESSION['message'] = $ok . ' request' . ($ok === 1 ? '' : 's') . ' ' . ($decision === 'approved' ? 'approved' : 'denied') . '.';
        } else {
            $_SESSION['error'] = 'No requests were updated. Select at least one pending request.';
        }
        header('Location: admin_preboards_subjects#preboards-requests');
        exit;
    }

    if ($action === 'delete') {
        $delId = sanitizeInt($_POST['preboards_subject_id'] ?? 0);
        if ($delId > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM preboards_subjects WHERE preboards_subject_id=?");
            mysqli_stmt_bind_param($stmt, 'i', $delId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Preboards deleted.';
        }
        header('Location: admin_preboards_subjects');
        exit;
    }

    $id = sanitizeInt($_POST['preboards_subject_id'] ?? 0);
    $name = trim($_POST['subject_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if ($name === '') {
        $_SESSION['error'] = 'Subject name is required.';
        header('Location: admin_preboards_subjects' . ($id > 0 ? ('?edit=' . $id) : ''));
        exit;
    }

    $dupStmt = mysqli_prepare(
        $conn,
        "SELECT preboards_subject_id FROM preboards_subjects WHERE LOWER(subject_name) = LOWER(?) AND preboards_subject_id <> ? LIMIT 1"
    );
    mysqli_stmt_bind_param($dupStmt, 'si', $name, $id);
    mysqli_stmt_execute($dupStmt);
    $dupRes = mysqli_stmt_get_result($dupStmt);
    $dupRow = $dupRes ? mysqli_fetch_assoc($dupRes) : null;
    mysqli_stmt_close($dupStmt);
    if ($dupRow) {
        $_SESSION['error'] = 'This preboards subject already exists.';
        header('Location: admin_preboards_subjects' . ($id > 0 ? ('?edit=' . $id) : ''));
        exit;
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    if ($id > 0) {
        $stmt = mysqli_prepare($conn, "UPDATE preboards_subjects SET subject_name=?, description=?, status=? WHERE preboards_subject_id=?");
        mysqli_stmt_bind_param($stmt, 'sssi', $name, $desc, $status, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Preboards updated.';
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO preboards_subjects (subject_name, description, status) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'sss', $name, $desc, $status);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['message'] = 'Preboards created.';
    }

    header('Location: admin_preboards_subjects');
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid = sanitizeInt($_GET['edit']);
    if ($eid > 0) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM preboards_subjects WHERE preboards_subject_id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $eid);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $edit = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }
}

$like = '%' . $q . '%';
if ($statusFilter === 'active' || $statusFilter === 'inactive') {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM preboards_subjects WHERE subject_name LIKE ? AND status=?");
    mysqli_stmt_bind_param($stmt, 'ss', $like, $statusFilter);
} else {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM preboards_subjects WHERE subject_name LIKE ?");
    mysqli_stmt_bind_param($stmt, 's', $like);
}
mysqli_stmt_execute($stmt);
$countRes = mysqli_stmt_get_result($stmt);
$countRow = mysqli_fetch_assoc($countRes);
$total = (int)($countRow['total'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));
mysqli_stmt_close($stmt);

if ($statusFilter === 'active' || $statusFilter === 'inactive') {
    $stmt = mysqli_prepare($conn, "
        SELECT * FROM preboards_subjects
        WHERE subject_name LIKE ? AND status=?
        ORDER BY subject_name ASC
        LIMIT ? OFFSET ?
    ");
    mysqli_stmt_bind_param($stmt, 'ssii', $like, $statusFilter, $perPage, $offset);
} else {
    $stmt = mysqli_prepare($conn, "
        SELECT * FROM preboards_subjects
        WHERE subject_name LIKE ?
        ORDER BY subject_name ASC
        LIMIT ? OFFSET ?
    ");
    mysqli_stmt_bind_param($stmt, 'sii', $like, $perPage, $offset);
}
mysqli_stmt_execute($stmt);
$subjects = mysqli_stmt_get_result($stmt);

$pendingRequestsInbox = preboards_list_pending_requests($conn);
$pendingRequestsCount = count($pendingRequestsInbox);
// Keep sidebar badge in sync with the inbox (avoids stale 120s cache / orphan pending rows).
preboards_sync_admin_pending_badge($pendingRequestsCount, $conn);

$pageTitle = 'Preboards';
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard'], ['Preboards'] ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-preboards-page" x-data="adminPreboardsSubjectsApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <div class="quiz-admin-hero rounded-xl px-5 py-5 mb-5 page-hero admin-glass-hero">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <div class="admin-page-header">
      <div class="min-w-0">
        <h1 class="admin-page-header__title flex flex-wrap items-center gap-3 m-0">
          <span class="quiz-admin-hero-icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></span>
          <span>Preboards</span>
          <?php if ($pendingRequestsCount > 0): ?>
            <a href="#preboards-requests" class="inline-flex items-center gap-1.5 text-sm font-semibold px-2.5 py-1 rounded-full no-underline"
               style="background:rgba(217,119,6,0.14);color:#b45309;border:1px solid rgba(217,119,6,0.28)">
              <i class="bi bi-inbox"></i> <?php echo (int)$pendingRequestsCount; ?> pending request<?php echo $pendingRequestsCount === 1 ? '' : 's'; ?>
            </a>
          <?php endif; ?>
        </h1>
        <p class="admin-page-header__subtitle">Manage preboard subjects, sets, questions, and student access requests.</p>
      </div>
      <div class="admin-page-header__actions">
        <?php if ($pendingRequestsCount > 0): ?>
          <a href="#preboards-requests" class="admin-btn admin-btn--secondary"><i class="bi bi-people"></i> View requests</a>
        <?php endif; ?>
        <button type="button" class="admin-btn admin-btn--primary" @click="openNewSubject()"><i class="bi bi-plus-lg"></i> New Preboard</button>
      </div>
    </div>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--success mb-5 flex items-center gap-2">
      <i class="bi bi-check-circle-fill shrink-0"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="quiz-admin-alert quiz-admin-alert--error mb-5 flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill shrink-0"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <?php if ($pendingRequestsCount > 0): ?>
  <section id="preboards-requests" class="quiz-admin-table-shell rounded-xl overflow-hidden mb-5" style="scroll-margin-top:1.25rem"
           x-data="{
             selected: {},
             allIds: [<?php echo implode(',', array_map(static fn($r) => (int)$r['preboards_request_id'], $pendingRequestsInbox)); ?>],
             get selectedCount() { return Object.keys(this.selected).filter((k) => this.selected[k]).length; },
             get allSelected() { return this.allIds.length > 0 && this.selectedCount === this.allIds.length; },
             toggleAll() {
               if (this.allSelected) { this.selected = {}; return; }
               const next = {};
               this.allIds.forEach((id) => { next[id] = true; });
               this.selected = next;
             },
             toggleOne(id) { this.selected[id] = !this.selected[id]; },
             submitBulk(decision) {
               if (this.selectedCount < 1) return;
               const verb = decision === 'approved' ? 'approve' : 'deny';
               if (!confirm(verb.charAt(0).toUpperCase() + verb.slice(1) + ' ' + this.selectedCount + ' selected request(s)?')) return;
               this.$refs.bulkDecision.value = decision;
               this.$refs.bulkForm.submit();
             }
           }">
    <div class="quiz-admin-table-head px-5 py-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <span class="font-semibold text-gray-100">Who requested access</span>
        <p class="text-sm text-gray-500 mt-0.5 mb-0">Select requests with checkboxes, then approve or deny in bulk.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="px-2.5 py-1 rounded-full text-sm font-semibold" style="background:rgba(217,119,6,0.14);color:#b45309;border:1px solid rgba(217,119,6,0.28)"><?php echo (int)$pendingRequestsCount; ?> pending</span>
        <button type="button" class="admin-btn admin-btn--primary text-sm" :disabled="selectedCount < 1" @click="submitBulk('approved')"
                :class="selectedCount < 1 ? 'opacity-50 cursor-not-allowed' : ''">
          <i class="bi bi-check2-all"></i> Approve selected <span x-show="selectedCount > 0" x-text="'(' + selectedCount + ')'"></span>
        </button>
        <button type="button" class="admin-btn admin-btn--secondary text-sm" :disabled="selectedCount < 1" @click="submitBulk('denied')"
                :class="selectedCount < 1 ? 'opacity-50 cursor-not-allowed' : ''">
          <i class="bi bi-x-lg"></i> Deny selected
        </button>
        <button type="button" class="admin-btn admin-btn--secondary text-sm"
                @click="
                  const next = {};
                  allIds.forEach((id) => { next[id] = true; });
                  selected = next;
                  submitBulk('approved');
                "
                title="Select all and approve">
          <i class="bi bi-lightning-charge"></i> Approve all
        </button>
      </div>
    </div>

    <form x-ref="bulkForm" method="POST" action="admin_preboards_subjects#preboards-requests" class="m-0">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="decide_requests_bulk">
      <input type="hidden" name="decision" value="" x-ref="bulkDecision">
      <template x-for="id in allIds" :key="id">
        <input type="hidden" name="request_ids[]" :value="id" x-bind:disabled="!selected[id]">
      </template>

      <div class="overflow-x-auto">
        <table class="quiz-admin-data-table w-full text-left">
          <thead>
            <tr>
              <th class="px-4 py-3 w-12">
                <label class="inline-flex items-center gap-2 cursor-pointer m-0" title="Select all">
                  <input type="checkbox" class="rounded border-gray-400" :checked="allSelected" @change="toggleAll()" aria-label="Select all requests">
                </label>
              </th>
              <th class="px-5 py-3 font-semibold">Student</th>
              <th class="px-5 py-3 font-semibold">Subject</th>
              <th class="px-5 py-3 font-semibold">Set</th>
              <th class="px-5 py-3 font-semibold">Type</th>
              <th class="px-5 py-3 font-semibold">Requested</th>
              <th class="px-5 py-3 font-semibold w-[140px]">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingRequestsInbox as $r): $rid = (int)$r['preboards_request_id']; ?>
              <tr class="quiz-admin-row" :class="selected[<?php echo $rid; ?>] ? 'bg-sky-500/5' : ''">
                <td class="px-4 py-3">
                  <input type="checkbox" class="rounded border-gray-400" :checked="!!selected[<?php echo $rid; ?>]" @change="toggleOne(<?php echo $rid; ?>)" aria-label="Select <?php echo h($r['full_name'] ?? 'request'); ?>">
                </td>
                <td class="px-5 py-3">
                  <div class="font-semibold text-gray-100"><?php echo h($r['full_name'] ?? ''); ?></div>
                  <div class="text-xs text-gray-500"><?php echo h($r['email'] ?? ''); ?></div>
                </td>
                <td class="px-5 py-3">
                  <a class="font-medium admin-link" href="admin_preboards_sets?preboards_subject_id=<?php echo (int)($r['preboards_subject_id'] ?? 0); ?>#preboards-requests"><?php echo h($r['subject_name'] ?? 'Subject'); ?></a>
                </td>
                <td class="px-5 py-3 font-semibold text-gray-100">Set <?php echo h($r['set_label'] ?? ''); ?></td>
                <td class="px-5 py-3 text-sm">
                  <?php if (($r['request_type'] ?? '') === 'open'): ?>
                    <span class="px-2 py-0.5 rounded-full bg-sky-500/15 text-sky-700 border border-sky-500/35 font-semibold">Access</span>
                  <?php else: ?>
                    <span class="px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-800 border border-amber-500/35 font-semibold">Retake</span>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3 text-sm text-gray-500"><?php echo preboards_format_datetime($r['requested_at'] ?? null); ?></td>
                <td class="px-5 py-3">
                  <div class="admin-row-actions">
                    <button type="button" class="admin-row-action admin-row-action--approve" title="Approve <?php echo h($r['full_name'] ?? ''); ?>"
                            @click="selected = { <?php echo $rid; ?>: true }; submitBulk('approved')"><i class="bi bi-check-lg"></i><span class="sr-only">Approve</span></button>
                    <button type="button" class="admin-row-action admin-row-action--deny" title="Deny <?php echo h($r['full_name'] ?? ''); ?>"
                            @click="selected = { <?php echo $rid; ?>: true }; submitBulk('denied')"><i class="bi bi-x-lg"></i><span class="sr-only">Deny</span></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <div class="rounded-xl shadow-card border p-5 mb-5 page-filter">
    <form method="GET" class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">
      <div class="lg:col-span-5">
        <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
        <div class="relative">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><i class="bi bi-search"></i></span>
          <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search preboards subject..." class="input-custom pl-10">
        </div>
      </div>
      <div class="lg:col-span-3">
        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
        <select name="status" class="input-custom">
          <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All status</option>
          <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
          <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
      </div>
      <div class="lg:col-span-4 flex flex-wrap gap-2 justify-end">
        <button type="submit" class="admin-outline-btn px-4 py-2.5 rounded-lg font-semibold border-2 transition inline-flex items-center gap-2">
          <i class="bi bi-funnel"></i> Apply
        </button>
        <button type="button" @click="openNewSubject()" class="admin-content-btn admin-content-btn--subject px-4 py-2.5 rounded-lg font-semibold border-2 transition inline-flex items-center gap-2">
          <i class="bi bi-plus-circle"></i> New Preboards
        </button>
      </div>
      <div class="lg:col-span-12">
        <p class="text-gray-500 text-sm">Showing <?php echo $total ? ($offset + 1) : 0; ?>-<?php echo min($offset + $perPage, $total); ?> of <?php echo $total; ?> subjects</p>
      </div>
    </form>
  </div>

  <div class="rounded-xl shadow-card border overflow-hidden page-table">
    <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-2">
      <div class="flex items-center gap-2">
        <span class="font-semibold text-gray-800">Preboards</span>
        <span class="px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-200 text-gray-700"><?php echo (int)$total; ?></span>
      </div>
      <p class="text-gray-500 text-sm hidden md:block m-0">These are shown to students under "Preboards".</p>
      <div class="text-gray-500 text-sm text-right">
        <?php if ($total > 0): ?>
          <span>Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $total); ?> of <?php echo $total; ?> preboards</span>
        <?php else: ?>
          <span>Showing 0-0 of 0 preboards</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="overflow-x-auto pl-3 pr-8">
      <table class="w-full text-left">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Name</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center">Status</th>
            <th class="px-5 py-3 font-semibold text-gray-700 text-center w-[300px]">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($total === 0): ?>
            <tr>
              <td colspan="3" class="px-5 py-12 text-center text-gray-500">
                <i class="bi bi-inbox text-4xl block mb-2"></i>
                <div class="font-semibold">No subjects found</div>
                <p class="text-sm mt-1">Create a new preboards to get started.</p>
                <button type="button" @click="openNewSubject()" class="mt-3 px-4 py-2 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2">
                  <i class="bi bi-plus-circle"></i> New Preboards
                </button>
              </td>
            </tr>
          <?php else: ?>
            <?php while ($s = mysqli_fetch_assoc($subjects)): ?>
              <tr class="border-b border-gray-100 hover:bg-gray-50/50">
                <td class="px-5 py-3 text-center">
                  <div class="font-semibold text-gray-800"><?php echo h($s['subject_name']); ?></div>
                  <?php if (!empty($s['description'])): ?>
                    <div class="text-gray-500 text-sm mt-0.5"><?php echo h(mb_strimwidth($s['description'], 0, 90, '...')); ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3 text-center">
                  <?php $st = strtolower((string)$s['status']); ?>
                  <span class="admin-status-pill inline-block px-2.5 py-1 rounded-full text-xs font-medium"><?php echo h($s['status']); ?></span>
                </td>
                <td class="px-5 py-3 text-center">
                  <div class="admin-row-actions" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
                    <a href="admin_preboards_sets?preboards_subject_id=<?php echo (int)$s['preboards_subject_id']; ?>" class="admin-row-action admin-row-action--sets" title="Manage sets"><i class="bi bi-collection"></i><span class="sr-only">Manage sets</span></a>
                    <a href="admin_preboards_monitor?preboards_subject_id=<?php echo (int)$s['preboards_subject_id']; ?>" class="admin-row-action admin-row-action--monitor" title="Monitoring"><i class="bi bi-bar-chart-line"></i><span class="sr-only">Monitoring</span></a>
                    <div class="admin-row-menu-wrap">
                      <button type="button" class="admin-row-action admin-row-action--more" :class="menuOpen ? 'is-open' : ''" :aria-expanded="menuOpen" title="More actions" @click.stop="menuOpen = !menuOpen"><i class="bi bi-three-dots"></i><span class="sr-only">More actions</span></button>
                      <div x-show="menuOpen" x-cloak @click.outside="menuOpen = false" class="admin-row-menu">
                        <button type="button"
                                class="admin-row-menu__item"
                                data-id="<?php echo (int)$s['preboards_subject_id']; ?>"
                                data-name="<?php echo h($s['subject_name'] ?? ''); ?>"
                                data-description="<?php echo h($s['description'] ?? ''); ?>"
                                data-status="<?php echo h($s['status'] ?? 'active'); ?>"
                                @click="menuOpen = false; openEditSubject($el.dataset.id, $el.dataset.name || '', $el.dataset.description || '', $el.dataset.status || 'active')">
                          <i class="bi bi-pencil"></i> Edit
                        </button>
                        <button type="button"
                                class="admin-row-menu__item admin-row-menu__item--danger"
                                data-id="<?php echo (int)$s['preboards_subject_id']; ?>"
                                data-name="<?php echo h($s['subject_name'] ?? ''); ?>"
                                @click="menuOpen = false; openDeleteSubject($el.dataset.id, $el.dataset.name || '')">
                          <i class="bi bi-trash"></i> Delete
                        </button>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php mysqli_stmt_close($stmt); ?>
    <?php if ($totalPages > 1): ?>
      <nav class="px-5 py-4 border-t border-gray-100 flex justify-center" aria-label="Preboards pagination">
        <ul class="flex flex-wrap items-center gap-1">
          <?php
            $baseParams = ['q' => $q, 'status' => $statusFilter];
            $mk = function ($p) use ($baseParams) {
              $params = $baseParams;
              $params['page'] = $p;
              return 'admin_preboards_subjects?' . http_build_query($params);
            };
          ?>
          <?php if ($page > 1): ?>
            <li><a href="<?php echo h($mk($page - 1)); ?>" class="px-3 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 transition">Previous</a></li>
          <?php endif; ?>
          <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <li>
              <a href="<?php echo h($mk($i)); ?>" class="px-3 py-2 rounded-lg border transition <?php echo $i === $page ? 'bg-primary border-primary text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-100'; ?>"><?php echo $i; ?></a>
            </li>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <li><a href="<?php echo h($mk($page + 1)); ?>" class="px-3 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 transition">Next</a></li>
          <?php endif; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>

  <!-- Create / Edit Modal (Alpine) -->
  <div x-show="subjectModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @keydown.escape.window="subjectModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="subjectModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal max-w-lg w-full max-h-[90vh] overflow-y-auto" @click.stop x-show="subjectModalOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
      <div class="p-5 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-xl font-bold text-gray-800 m-0" x-text="isEdit ? 'Edit Preboards' : 'New Preboards'"><i class="bi bi-clipboard-check mr-2"></i></h2>
        <button type="button" @click="subjectModalOpen = false" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_preboards_subjects" class="p-5">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="preboards_subject_id" :value="preboards_subject_id">

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <select name="subject_name" x-model="subject_name" required class="input-custom">
              <option value="">Select...</option>
              <option value="FAR">FAR</option>
              <option value="AFAR">AFAR</option>
              <option value="TAX">TAX</option>
              <option value="MAS">MAS</option>
              <option value="RFBT">RFBT</option>
              <option value="AUD Theory">AUD Theory</option>
              <option value="AUD Problems">AUD Problems</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea name="description" x-model="description" rows="4" placeholder="Optional notes for this subject" class="input-custom"></textarea>
          </div>
          <div x-show="isEdit">
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select name="status" x-model="status" class="input-custom">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <input type="hidden" name="status" x-bind:value="status" x-show="!isEdit">
          <div>
            <p class="text-sm text-gray-500">Inactive subjects won't appear to students.</p>
          </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" @click="subjectModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition inline-flex items-center gap-2"><i class="bi bi-save"></i> <span x-text="isEdit ? 'Update' : 'Create'"></span></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Delete Modal (Alpine) -->
  <div x-show="deleteModalOpen" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center p-4" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @keydown.escape.window="deleteModalOpen = false">
    <div class="absolute inset-0 bg-black/50" @click="deleteModalOpen = false"></div>
    <div class="relative bg-white rounded-xl shadow-modal max-w-md w-full p-5" @click.stop x-show="deleteModalOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold text-gray-800 m-0"><i class="bi bi-trash text-red-500 mr-2"></i> Delete Preboards</h2>
        <button type="button" @click="deleteModalOpen = false" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="POST" action="admin_preboards_subjects">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="preboards_subject_id" :value="delete_id">
        <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 mb-4">
          <div class="font-semibold">This will delete the preboards.</div>
          <div class="text-sm mt-1 text-amber-700">Name: <span class="font-semibold" x-text="delete_name"></span></div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="deleteModalOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border-2 border-gray-300 text-gray-700 hover:bg-gray-100 transition">Cancel</button>
          <button type="submit" class="px-4 py-2.5 rounded-lg font-semibold bg-red-600 text-white hover:bg-red-700 transition inline-flex items-center gap-2"><i class="bi bi-trash"></i> Delete</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function adminPreboardsSubjectsApp() {
      return {
        subjectModalOpen: false,
        deleteModalOpen: false,
        isEdit: false,
        preboards_subject_id: 0,
        subject_name: '',
        description: '',
        status: 'active',
        delete_id: 0,
        delete_name: '',
        editFromServer: <?php echo !empty($edit) ? json_encode(['id' => (int)$edit['preboards_subject_id'], 'name' => $edit['subject_name'] ?? '', 'description' => $edit['description'] ?? '', 'status' => $edit['status'] ?? 'active']) : 'null'; ?>,

        openNewSubject() {
          this.isEdit = false;
          this.preboards_subject_id = 0;
          this.subject_name = '';
          this.description = '';
          this.status = 'active';
          this.subjectModalOpen = true;
        },
        openEditSubject(id, name, description, status) {
          this.isEdit = true;
          this.preboards_subject_id = id;
          this.subject_name = name || '';
          this.description = description || '';
          this.status = (status === 'inactive') ? 'inactive' : 'active';
          this.subjectModalOpen = true;
        },
        openDeleteSubject(id, name) {
          this.delete_id = id;
          this.delete_name = name || '';
          this.deleteModalOpen = true;
        },
        initEditFromServer() {
          if (this.editFromServer) {
            this.openEditSubject(this.editFromServer.id, this.editFromServer.name, this.editFromServer.description, this.editFromServer.status);
          }
        }
      };
    }
  </script>
</main>
</body>
</html>

