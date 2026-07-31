<?php
/**
 * Admin Commerce — Grant Ledger (Phase 9).
 * Read-only GET view of access_grants. No mutations, no OCR/proof exposure.
 */
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/commerce_catalog.php';
require_once __DIR__ . '/includes/commerce_grants_admin.php';
require_once __DIR__ . '/includes/url_helpers.php';

if (!commerce_schema_ready($conn)) {
    $_SESSION['error'] = 'Commerce schema is not installed.';
    header('Location: admin_dashboard');
    exit;
}

// GET-only: reject accidental POSTs without mutating.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: ' . ereview_url('admin_commerce_grants'));
    exit;
}

$pageTitle = 'Commerce — Grant Ledger';
$dash = commerce_grants_admin_build_ledger($conn, $_GET);
$f = $dash['filters'];
$rows = $dash['rows'];
$total = (int) $dash['total'];
$totalPages = (int) $dash['total_pages'];
$page = (int) $f['page'];
$perPage = (int) $f['per_page'];

$adminBreadcrumbs = [['Dashboard', 'admin_dashboard'], ['Commerce'], ['Grant Ledger']];
$adminHeroIcon = 'journal-text';
$adminHeroTitle = 'Grant Ledger';
$adminHeroSubtitle = 'Read-only access_grants inspection. Sources and statuses are display-only; use FAR or Payment pages for revoke actions.';
$adminHeroActions = '<a class="admin-outline-btn px-3 py-2 rounded-xl text-sm font-semibold" href="'
    . h(ereview_url('admin_commerce_free_access')) . '">Free Access</a>'
    . ' <a class="admin-outline-btn px-3 py-2 rounded-xl text-sm font-semibold" href="'
    . h(ereview_url('admin_commerce_payments')) . '">Payments</a>'
    . ' <a class="admin-outline-btn px-3 py-2 rounded-xl text-sm font-semibold" href="'
    . h(ereview_url('admin_commerce_reports')) . '">Reports</a>';

$self = ereview_url('admin_commerce_grants');

/**
 * @param array<string,mixed> $filters
 */
function p9_ledger_qs(array $filters, array $overrides = []): string
{
    $q = array_merge([
        'student' => (string) ($filters['student'] ?? ''),
        'source' => (string) ($filters['source'] ?? ''),
        'status' => (string) ($filters['status'] ?? ''),
        'content_type' => (string) ($filters['content_type'] ?? ''),
        'payment_id' => (int) ($filters['payment_id'] ?? 0) > 0 ? (string) (int) $filters['payment_id'] : '',
        'free_access_request_id' => (int) ($filters['free_access_request_id'] ?? 0) > 0 ? (string) (int) $filters['free_access_request_id'] : '',
        'user_id' => (int) ($filters['user_id'] ?? 0) > 0 ? (string) (int) $filters['user_id'] : '',
        'date_from' => (string) ($filters['date_from'] ?? ''),
        'date_to' => (string) ($filters['date_to'] ?? ''),
        'page' => (string) (int) ($filters['page'] ?? 1),
        'per_page' => (string) (int) ($filters['per_page'] ?? COMMERCE_GRANTS_ADMIN_DEFAULT_PER_PAGE),
    ], $overrides);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === '0' || $v === null) {
            unset($q[$k]);
        }
    }
    return http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app">
  <?php include 'admin_sidebar.php'; ?>
  <div class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto w-full">
    <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

    <?php if (!empty($f['warnings'])): ?>
      <div class="admin-alert admin-alert--error mb-4">
        <?php foreach ($f['warnings'] as $w): ?>
          <div><?php echo h((string) $w); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="get" action="<?php echo h($self); ?>" class="quiz-admin-table-shell rounded-2xl p-4 sm:p-5 mb-5 space-y-3">
      <div class="text-xs uppercase opacity-60 font-semibold">Filters (GET · read-only)</div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
        <div class="sm:col-span-2">
          <label class="block text-xs opacity-70 mb-1" for="student">Student (name or email)</label>
          <input type="text" name="student" id="student" maxlength="120" class="admin-input w-full px-3 py-2 rounded-xl"
                 value="<?php echo h((string) ($f['student'] ?? '')); ?>" placeholder="Search students">
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="user_id">User ID</label>
          <input type="number" min="0" step="1" name="user_id" id="user_id" class="admin-input w-full px-3 py-2 rounded-xl"
                 value="<?php echo (int) ($f['user_id'] ?? 0) > 0 ? (int) $f['user_id'] : ''; ?>" placeholder="Optional">
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="source">Source</label>
          <select name="source" id="source" class="admin-input w-full px-3 py-2 rounded-xl">
            <option value="all">All</option>
            <?php foreach (COMMERCE_GRANTS_ADMIN_SOURCES as $src): ?>
              <option value="<?php echo h($src); ?>" <?php echo ($f['source'] ?? '') === $src ? 'selected' : ''; ?>><?php echo h($src); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="status">Status</label>
          <select name="status" id="status" class="admin-input w-full px-3 py-2 rounded-xl">
            <option value="all">All</option>
            <?php foreach (COMMERCE_GRANTS_ADMIN_STATUSES as $st): ?>
              <option value="<?php echo h($st); ?>" <?php echo ($f['status'] ?? '') === $st ? 'selected' : ''; ?>><?php echo h($st); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="content_type">Content type</label>
          <select name="content_type" id="content_type" class="admin-input w-full px-3 py-2 rounded-xl">
            <option value="all">All</option>
            <?php foreach (COMMERCE_GRANTS_ADMIN_CONTENT_TYPES as $ct): ?>
              <option value="<?php echo h($ct); ?>" <?php echo ($f['content_type'] ?? '') === $ct ? 'selected' : ''; ?>><?php echo h($ct); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="payment_id">Payment ID</label>
          <input type="number" min="0" step="1" name="payment_id" id="payment_id" class="admin-input w-full px-3 py-2 rounded-xl"
                 value="<?php echo (int) ($f['payment_id'] ?? 0) > 0 ? (int) $f['payment_id'] : ''; ?>" placeholder="Optional">
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="free_access_request_id">FAR request ID</label>
          <input type="number" min="0" step="1" name="free_access_request_id" id="free_access_request_id" class="admin-input w-full px-3 py-2 rounded-xl"
                 value="<?php echo (int) ($f['free_access_request_id'] ?? 0) > 0 ? (int) $f['free_access_request_id'] : ''; ?>" placeholder="Optional">
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="date_from">Created from</label>
          <input type="date" name="date_from" id="date_from" class="admin-input w-full px-3 py-2 rounded-xl"
                 value="<?php echo h((string) ($f['date_from'] ?? '')); ?>">
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="date_to">Created to</label>
          <input type="date" name="date_to" id="date_to" class="admin-input w-full px-3 py-2 rounded-xl"
                 value="<?php echo h((string) ($f['date_to'] ?? '')); ?>">
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="per_page">Per page</label>
          <select name="per_page" id="per_page" class="admin-input w-full px-3 py-2 rounded-xl">
            <?php foreach ([25, 50, 100] as $pp): ?>
              <option value="<?php echo $pp; ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="admin-primary-btn px-4 py-2 rounded-xl text-sm font-semibold">Apply filters</button>
        <a class="admin-outline-btn px-4 py-2 rounded-xl text-sm font-semibold" href="<?php echo h($self); ?>">Reset</a>
      </div>
    </form>

    <div class="quiz-admin-table-shell rounded-2xl p-4 sm:p-5 mb-5">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <div class="text-sm opacity-70">
          Showing <?php echo $total > 0 ? (($page - 1) * $perPage + 1) : 0; ?>–<?php echo min($page * $perPage, $total); ?>
          of <strong><?php echo $total; ?></strong> grant(s)
        </div>
        <div class="text-sm opacity-70">Page <?php echo $page; ?> / <?php echo $totalPages; ?></div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase opacity-60 border-b border-white/10">
              <th class="py-2 pr-2">ID</th>
              <th class="py-2 pr-2">Student</th>
              <th class="py-2 pr-2">Source</th>
              <th class="py-2 pr-2">Status</th>
              <th class="py-2 pr-2">Content</th>
              <th class="py-2 pr-2">Payment</th>
              <th class="py-2 pr-2">Item</th>
              <th class="py-2 pr-2">FAR</th>
              <th class="py-2 pr-2">Starts</th>
              <th class="py-2 pr-2">Ends</th>
              <th class="py-2 pr-2">Revoked</th>
              <th class="py-2 pr-2">Reason</th>
              <th class="py-2">Created</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rows === []): ?>
              <tr>
                <td colspan="13" class="py-6 text-center opacity-60">No grants match these filters.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $g): ?>
                <tr class="border-t border-white/10 align-top">
                  <td class="py-2 pr-2 font-semibold">#<?php echo (int) $g['grant_id']; ?></td>
                  <td class="py-2 pr-2">
                    <div class="font-semibold"><?php echo h((string) ($g['full_name'] ?? '')); ?></div>
                    <div class="text-xs opacity-60"><?php echo h((string) ($g['email'] ?? '')); ?></div>
                    <div class="text-xs opacity-50">user #<?php echo (int) $g['user_id']; ?></div>
                  </td>
                  <td class="py-2 pr-2"><?php echo h((string) $g['source']); ?></td>
                  <td class="py-2 pr-2"><?php echo h((string) $g['status']); ?></td>
                  <td class="py-2 pr-2">
                    <?php echo h((string) $g['content_type']); ?>/<?php echo (int) $g['content_id']; ?>
                    <?php if (!empty($g['content_label'])): ?>
                      <div class="text-xs opacity-50"><?php echo h((string) $g['content_label']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="py-2 pr-2">
                    <?php if (!empty($g['payment_id'])): ?>
                      <a class="underline" href="<?php echo h(ereview_url('admin_commerce_payments') . '?id=' . (int) $g['payment_id']); ?>">#<?php echo (int) $g['payment_id']; ?></a>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                  <td class="py-2 pr-2"><?php echo !empty($g['payment_item_id']) ? '#' . (int) $g['payment_item_id'] : '—'; ?></td>
                  <td class="py-2 pr-2">
                    <?php if (!empty($g['free_access_request_id'])): ?>
                      <a class="underline" href="<?php echo h(ereview_url('admin_commerce_free_access') . '?id=' . (int) $g['free_access_request_id']); ?>">#<?php echo (int) $g['free_access_request_id']; ?></a>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                  <td class="py-2 pr-2 whitespace-nowrap"><?php echo h((string) ($g['starts_at'] ?? '—')); ?></td>
                  <td class="py-2 pr-2 whitespace-nowrap"><?php echo h((string) ($g['ends_at'] ?? '—')); ?></td>
                  <td class="py-2 pr-2 whitespace-nowrap"><?php echo h((string) ($g['revoked_at'] ?? '—')); ?></td>
                  <td class="py-2 pr-2 max-w-[12rem] break-words"><?php echo h((string) ($g['revoke_reason'] ?? '—')); ?></td>
                  <td class="py-2 whitespace-nowrap"><?php echo h((string) ($g['created_at'] ?? '—')); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="flex flex-wrap gap-2 mt-4">
          <?php if ($page > 1): ?>
            <a class="admin-outline-btn px-3 py-1.5 rounded-xl text-sm font-semibold"
               href="<?php echo h($self . '?' . p9_ledger_qs($f, ['page' => (string) ($page - 1)])); ?>">Previous</a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
            <a class="admin-outline-btn px-3 py-1.5 rounded-xl text-sm font-semibold"
               href="<?php echo h($self . '?' . p9_ledger_qs($f, ['page' => (string) ($page + 1)])); ?>">Next</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</main>
</body>
</html>
