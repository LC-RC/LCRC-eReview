<?php
require_once 'auth.php';
requireRole('admin');

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
        header('Location: admin_preboards_subjects.php');
        exit;
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $delId = sanitizeInt($_POST['preboards_subject_id'] ?? 0);
        if ($delId > 0) {
            $stmt = mysqli_prepare($conn, "DELETE FROM preboards_subjects WHERE preboards_subject_id=?");
            mysqli_stmt_bind_param($stmt, 'i', $delId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['message'] = 'Preboards deleted.';
        }
        header('Location: admin_preboards_subjects.php');
        exit;
    }

    $id = sanitizeInt($_POST['preboards_subject_id'] ?? 0);
    $name = trim($_POST['subject_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if ($name === '') {
        $_SESSION['error'] = 'Subject name is required.';
        header('Location: admin_preboards_subjects.php' . ($id > 0 ? ('?edit=' . $id) : ''));
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
        header('Location: admin_preboards_subjects.php' . ($id > 0 ? ('?edit=' . $id) : ''));
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

    header('Location: admin_preboards_subjects.php');
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

$pageTitle = 'Preboards';
$adminBreadcrumbs = [ ['Dashboard', 'admin_dashboard.php'], ['Preboards'] ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    .admin-preboards-page .page-hero {
      border: 1px solid #dbeafe;
      background: linear-gradient(135deg, #eff6ff 0%, #ffffff 70%);
      box-shadow: 0 12px 30px -22px rgba(37, 99, 235, 0.35);
    }
    .admin-preboards-page .page-hero h1 { color: #0f2f6b; }
    .admin-preboards-page .page-filter,
    .admin-preboards-page .page-table {
      border: 1px solid #dbeafe;
      box-shadow: 0 12px 28px -24px rgba(30, 64, 175, 0.3);
    }
    .admin-preboards-page .page-table thead th {
      text-transform: uppercase;
      letter-spacing: .02em;
      font-size: .78rem;
    }
    .admin-preboards-page .page-table tbody tr { transition: background-color .2s ease, transform .2s ease; }
    .admin-preboards-page .page-table tbody tr:hover { background: #f8fbff; transform: translateY(-1px); }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-preboards-page" x-data="adminPreboardsSubjectsApp()" x-init="initEditFromServer()">
  <?php include 'admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-5 py-5 mb-5 page-hero">
    <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-clipboard-check"></i> Preboards
    </h1>
    <p class="text-gray-500 mt-1">Add and manage preboards (student layout mirrors Subjects).</p>
  </div>

  <?php if (isset($_SESSION['message'])): ?>
    <div class="admin-flash admin-flash--success mb-5 p-4 rounded-xl flex items-center gap-2">
      <i class="bi bi-check-circle-fill"></i><span><?php echo h($_SESSION['message']); ?></span>
      <?php unset($_SESSION['message']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="admin-flash admin-flash--error mb-5 p-4 rounded-xl flex items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($_SESSION['error']); ?></span>
      <?php unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-5 page-filter">
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

  <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden page-table">
    <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap justify-between items-center gap-2">
      <div class="flex items-center gap-2">
        <span class="font-semibold text-gray-800">Preboards</span>
        <span class="px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-200 text-gray-700"><?php echo (int)$total; ?></span>
      </div>
      <p class="text-gray-500 text-sm hidden md:block m-0">These are shown to students under “Preboards”.</p>
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
                    <div class="text-gray-500 text-sm mt-0.5"><?php echo h(mb_strimwidth($s['description'], 0, 90, '…')); ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-5 py-3 text-center">
                  <?php $st = strtolower((string)$s['status']); ?>
                  <span class="admin-status-pill inline-block px-2.5 py-1 rounded-full text-xs font-medium"><?php echo h($s['status']); ?></span>
                </td>
                <td class="px-5 py-3 text-center">
                  <div class="inline-block text-left w-[220px]" x-data="{ expanded: false }">
                    <div class="flex flex-col gap-2">
                      <a href="admin_preboards_sets.php?preboards_subject_id=<?php echo (int)$s['preboards_subject_id']; ?>" class="admin-action-btn admin-action-btn--lessons flex items-center justify-center gap-2 w-full px-3 py-1.5 rounded-lg text-sm font-medium border-2 transition"><i class="bi bi-collection"></i> Manage sets</a>
                      <button type="button" @click="expanded = !expanded" class="flex items-center justify-center gap-1 w-full py-1 rounded-md text-xs text-gray-500 border border-gray-200 hover:border-gray-300 hover:text-gray-600 hover:bg-gray-50 transition" :aria-expanded="expanded" title="More actions">
                        <i class="bi text-sm" :class="expanded ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                        <span class="opacity-80">More</span>
                      </button>
                    </div>
                    <div x-show="expanded" x-cloak x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="flex flex-col gap-2 mt-2">
                    <button type="button"
                            data-id="<?php echo (int)$s['preboards_subject_id']; ?>"
                            data-name="<?php echo h($s['subject_name'] ?? ''); ?>"
                            data-description="<?php echo h($s['description'] ?? ''); ?>"
                            data-status="<?php echo h($s['status'] ?? 'active'); ?>"
                            @click="expanded = false; openEditSubject($el.dataset.id, $el.dataset.name || '', $el.dataset.description || '', $el.dataset.status || 'active')"
                            class="admin-outline-btn flex items-center justify-center gap-2 w-full px-3 py-1.5 rounded-lg text-sm font-medium border-2 transition"><i class="bi bi-pencil"></i> Edit</button>
                    <button type="button"
                            data-id="<?php echo (int)$s['preboards_subject_id']; ?>"
                            data-name="<?php echo h($s['subject_name'] ?? ''); ?>"
                            @click="expanded = false; openDeleteSubject($el.dataset.id, $el.dataset.name || '')"
                            class="admin-action-btn admin-action-btn--danger flex items-center justify-center gap-2 w-full px-3 py-1.5 rounded-lg text-sm font-medium border-2 transition"><i class="bi bi-trash"></i> Delete</button>
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
              return 'admin_preboards_subjects.php?' . http_build_query($params);
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
      <form method="POST" action="admin_preboards_subjects.php" class="p-5">
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
      <form method="POST" action="admin_preboards_subjects.php">
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

