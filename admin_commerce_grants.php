<?php
/**
 * Admin Commerce - Grant Ledger (Phase 9).
 * Read-only GET view of access_grants. No mutations, no OCR/proof exposure.
 */
require_once 'auth.php';
requireAdminPage();
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

$pageTitle = 'Commerce - Grant Ledger';
$dash = commerce_grants_admin_build_ledger($conn, $_GET);
$f = $dash['filters'];
$rows = $dash['rows'];
$total = (int) $dash['total'];
$totalPages = (int) $dash['total_pages'];
$page = (int) $f['page'];
$perPage = (int) $f['per_page'];

$adminBreadcrumbs = [['Dashboard', 'admin_dashboard'], ['Commerce'], ['Grant Ledger']];
$adminHeroIcon = 'journal-text';
$adminHeroEyebrow = 'Commerce';
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
<body class="font-sans antialiased admin-app admin-commerce-grants-page">
  <?php include 'admin_sidebar.php'; ?>
  <div class="w-full">
    <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

    <?php if (!empty($f['warnings'])): ?>
      <div class="admin-alert admin-alert--error mb-4">
        <?php foreach ($f['warnings'] as $w): ?>
          <div><?php echo h((string) $w); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="get" action="<?php echo h($self); ?>" class="admin-filter-panel admin-data-toolbar">
      <div class="admin-filter-panel__head">
        <h2 class="admin-filter-panel__title">Filters</h2>
        <span class="admin-filter-panel__hint">GET · read-only</span>
      </div>
      <div class="admin-filter-panel__grid">
        <div class="admin-filter-panel__span2">
          <label for="student">Student (name or email)</label>
          <input type="text" name="student" id="student" maxlength="120" class="admin-input"
                 value="<?php echo h((string) ($f['student'] ?? '')); ?>" placeholder="Search students">
        </div>
        <div>
          <label for="user_id">User ID</label>
          <input type="number" min="0" step="1" name="user_id" id="user_id" class="admin-input"
                 value="<?php echo (int) ($f['user_id'] ?? 0) > 0 ? (int) $f['user_id'] : ''; ?>" placeholder="Optional">
        </div>
        <div>
          <label for="source">Source</label>
          <select name="source" id="source" class="admin-input">
            <option value="all">All</option>
            <?php foreach (COMMERCE_GRANTS_ADMIN_SOURCES as $src): ?>
              <option value="<?php echo h($src); ?>" <?php echo ($f['source'] ?? '') === $src ? 'selected' : ''; ?>><?php echo h($src); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="status">Status</label>
          <select name="status" id="status" class="admin-input">
            <option value="all">All</option>
            <?php foreach (COMMERCE_GRANTS_ADMIN_STATUSES as $st): ?>
              <option value="<?php echo h($st); ?>" <?php echo ($f['status'] ?? '') === $st ? 'selected' : ''; ?>><?php echo h($st); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="content_type">Content type</label>
          <select name="content_type" id="content_type" class="admin-input">
            <option value="all">All</option>
            <?php foreach (COMMERCE_GRANTS_ADMIN_CONTENT_TYPES as $ct): ?>
              <option value="<?php echo h($ct); ?>" <?php echo ($f['content_type'] ?? '') === $ct ? 'selected' : ''; ?>><?php echo h($ct); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="payment_id">Payment ID</label>
          <input type="number" min="0" step="1" name="payment_id" id="payment_id" class="admin-input"
                 value="<?php echo (int) ($f['payment_id'] ?? 0) > 0 ? (int) $f['payment_id'] : ''; ?>" placeholder="Optional">
        </div>
        <div>
          <label for="free_access_request_id">FAR request ID</label>
          <input type="number" min="0" step="1" name="free_access_request_id" id="free_access_request_id" class="admin-input"
                 value="<?php echo (int) ($f['free_access_request_id'] ?? 0) > 0 ? (int) $f['free_access_request_id'] : ''; ?>" placeholder="Optional">
        </div>
        <div>
          <label for="date_from">Created from</label>
          <input type="date" name="date_from" id="date_from" class="admin-input"
                 value="<?php echo h((string) ($f['date_from'] ?? '')); ?>">
        </div>
        <div>
          <label for="date_to">Created to</label>
          <input type="date" name="date_to" id="date_to" class="admin-input"
                 value="<?php echo h((string) ($f['date_to'] ?? '')); ?>">
        </div>
        <div>
          <label for="per_page">Per page</label>
          <select name="per_page" id="per_page" class="admin-input">
            <?php foreach ([25, 50, 100] as $pp): ?>
              <option value="<?php echo $pp; ?>" <?php echo $perPage === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="admin-filter-panel__actions">
        <button type="submit" class="admin-btn admin-btn--primary">Apply filters</button>
        <a class="admin-btn admin-btn--ghost" href="<?php echo h($self); ?>">Reset</a>
      </div>
    </form>

    <div class="quiz-admin-table-shell rounded-2xl border border-white/80 bg-white/80 shadow-[0_8px_28px_rgba(15,23,42,0.05)] backdrop-blur-xl p-4 mb-4">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <div class="text-sm text-slate-500">
          Showing <?php echo $total > 0 ? (($page - 1) * $perPage + 1) : 0; ?>-<?php echo min($page * $perPage, $total); ?>
          of <strong class="text-slate-800"><?php echo $total; ?></strong> grant(s)
        </div>
        <div class="text-sm text-slate-500">Page <?php echo $page; ?> / <?php echo $totalPages; ?></div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm admin-data-table">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide bg-slate-100 text-slate-700 border-b border-slate-200">
              <th class="py-2.5 px-2 font-bold">ID</th>
              <th class="py-2.5 px-2 font-bold">Student</th>
              <th class="py-2.5 px-2 font-bold">Source</th>
              <th class="py-2.5 px-2 font-bold">Status</th>
              <th class="py-2.5 px-2 font-bold">Content</th>
              <th class="py-2.5 px-2 font-bold">Payment</th>
              <th class="py-2.5 px-2 font-bold">Item</th>
              <th class="py-2.5 px-2 font-bold">FAR</th>
              <th class="py-2.5 px-2 font-bold">Starts</th>
              <th class="py-2.5 px-2 font-bold">Ends</th>
              <th class="py-2.5 px-2 font-bold">Revoked</th>
              <th class="py-2.5 px-2 font-bold">Reason</th>
              <th class="py-2.5 px-2 font-bold">Created</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rows === []): ?>
              <tr>
                <td colspan="13" class="py-6 text-center text-slate-500">No grants match these filters.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $g): ?>
                <?php
                  $gSt = (string) $g['status'];
                  $gBadge = $gSt === 'active' ? 'admin-badge--success' : ($gSt === 'revoked' ? 'admin-badge--danger' : ($gSt === 'expired' ? 'admin-badge--neutral' : 'admin-badge--warning'));
                ?>
                <tr class="border-t border-slate-100 align-top">
                  <td class="py-2 px-2 font-semibold text-slate-900">#<?php echo (int) $g['grant_id']; ?></td>
                  <td class="py-2 px-2">
                    <div class="font-semibold text-slate-800"><?php echo h((string) ($g['full_name'] ?? '')); ?></div>
                    <div class="text-xs text-slate-500"><?php echo h((string) ($g['email'] ?? '')); ?></div>
                    <div class="text-xs text-slate-400">user #<?php echo (int) $g['user_id']; ?></div>
                  </td>
                  <td class="py-2 px-2"><span class="admin-badge admin-badge--info"><?php echo h((string) $g['source']); ?></span></td>
                  <td class="py-2 px-2"><span class="admin-badge <?php echo $gBadge; ?>"><?php echo h($gSt); ?></span></td>
                  <td class="py-2 px-2">
                    <?php echo h((string) $g['content_type']); ?>/<?php echo (int) $g['content_id']; ?>
                    <?php if (!empty($g['content_label'])): ?>
                      <div class="text-xs text-slate-500"><?php echo h((string) $g['content_label']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="py-2 px-2">
                    <?php if (!empty($g['payment_id'])): ?>
                      <a class="font-semibold text-blue-600 hover:underline" href="<?php echo h(ereview_url('admin_commerce_payments') . '?id=' . (int) $g['payment_id']); ?>">#<?php echo (int) $g['payment_id']; ?></a>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </td>
                  <td class="py-2 px-2"><?php echo !empty($g['payment_item_id']) ? '#' . (int) $g['payment_item_id'] : '-'; ?></td>
                  <td class="py-2 px-2">
                    <?php if (!empty($g['free_access_request_id'])): ?>
                      <a class="font-semibold text-blue-600 hover:underline" href="<?php echo h(ereview_url('admin_commerce_free_access') . '?id=' . (int) $g['free_access_request_id']); ?>">#<?php echo (int) $g['free_access_request_id']; ?></a>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </td>
                  <td class="py-2 px-2 whitespace-nowrap"><?php echo h((string) ($g['starts_at'] ?? '-')); ?></td>
                  <td class="py-2 px-2 whitespace-nowrap"><?php echo h((string) ($g['ends_at'] ?? '-')); ?></td>
                  <td class="py-2 px-2 whitespace-nowrap"><?php echo h((string) ($g['revoked_at'] ?? '-')); ?></td>
                  <td class="py-2 px-2 max-w-[12rem] break-words"><?php echo h((string) ($g['revoke_reason'] ?? '-')); ?></td>
                  <td class="py-2 px-2 whitespace-nowrap"><?php echo h((string) ($g['created_at'] ?? '-')); ?></td>
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
