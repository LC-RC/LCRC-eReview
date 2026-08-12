<?php
/**
 * Admin Commerce - Reports (Phase 8.5).
 * Read-only GET dashboard. No mutations, no OCR/proof exposure, no Chart.js.
 */
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/commerce_catalog.php';
require_once __DIR__ . '/includes/commerce_reports.php';
require_once __DIR__ . '/includes/url_helpers.php';

if (!commerce_schema_ready($conn)) {
    $_SESSION['error'] = 'Commerce schema is not installed.';
    header('Location: admin_dashboard');
    exit;
}

// GET-only: reject accidental POSTs without mutating.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: ' . ereview_url('admin_commerce_reports'));
    exit;
}

$pageTitle = 'Commerce - Reports';
$dash = commerce_reports_build_dashboard($conn, $_GET);
$f = $dash['filters'];
$p = $dash['payments'];
$g = $dash['grants'];
$far = $dash['far'];

$adminBreadcrumbs = [['Dashboard', 'admin_dashboard'], ['Commerce'], ['Reports']];
$adminHeroIcon = 'bar-chart-line';
$adminHeroTitle = 'Commerce Reports';
$adminHeroSubtitle = 'Read-only payment, verification, grant, and Free Access summaries. Free Access is never included in GMV.';
$adminHeroActions = '<a class="admin-outline-btn px-3 py-2 rounded-xl text-sm font-semibold" href="'
    . h(ereview_url('admin_commerce_payments')) . '">Payment Verification</a>'
    . ' <a class="admin-outline-btn px-3 py-2 rounded-xl text-sm font-semibold" href="'
    . h(ereview_url('admin_commerce_free_access')) . '">Free Access</a>';

$self = ereview_url('admin_commerce_reports');

function p85_h_money(int $centavos): string
{
    return '₱' . commerce_reports_centavos_to_php($centavos);
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
      <div class="text-xs uppercase opacity-60 font-semibold">Filters (GET · all-time by default)</div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
        <div>
          <label class="block text-xs opacity-70 mb-1" for="date_from">Date from (created_at)</label>
          <input type="date" name="date_from" id="date_from" class="admin-input w-full px-3 py-2 rounded-xl"
                 value="<?php echo h((string) ($f['date_from'] ?? '')); ?>">
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="date_to">Date to</label>
          <input type="date" name="date_to" id="date_to" class="admin-input w-full px-3 py-2 rounded-xl"
                 value="<?php echo h((string) ($f['date_to'] ?? '')); ?>">
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="status">Payment status</label>
          <select name="status" id="status" class="admin-input w-full px-3 py-2 rounded-xl">
            <option value="all">All</option>
            <?php foreach (COMMERCE_REPORTS_PAYMENT_STATUSES as $st): ?>
              <option value="<?php echo h($st); ?>" <?php echo ($f['status'] ?? '') === $st ? 'selected' : ''; ?>><?php echo h($st); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="verification_status">Verification</label>
          <select name="verification_status" id="verification_status" class="admin-input w-full px-3 py-2 rounded-xl">
            <option value="all">All</option>
            <?php foreach (COMMERCE_REPORTS_VERIFICATION_STATUSES as $st): ?>
              <option value="<?php echo h($st); ?>" <?php echo ($f['verification_status'] ?? '') === $st ? 'selected' : ''; ?>><?php echo h($st); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="purchase_type">Purchase type</label>
          <select name="purchase_type" id="purchase_type" class="admin-input w-full px-3 py-2 rounded-xl">
            <option value="all">All</option>
            <?php foreach (COMMERCE_REPORTS_PURCHASE_TYPES as $pt): ?>
              <option value="<?php echo h($pt); ?>" <?php echo ($f['purchase_type'] ?? '') === $pt ? 'selected' : ''; ?>><?php echo h($pt); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="package_id">Package</label>
          <select name="package_id" id="package_id" class="admin-input w-full px-3 py-2 rounded-xl">
            <option value="0">All packages</option>
            <?php foreach ($dash['packages'] as $pkg): ?>
              <option value="<?php echo (int) $pkg['package_id']; ?>" <?php echo ((int) ($f['package_id'] ?? 0) === (int) $pkg['package_id']) ? 'selected' : ''; ?>>
                <?php echo h($pkg['name'] . ' (' . $pkg['code'] . ')'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="lesson_id">By Topic lesson ID</label>
          <input type="number" min="0" step="1" name="lesson_id" id="lesson_id" class="admin-input w-full px-3 py-2 rounded-xl"
                 value="<?php echo (int) ($f['lesson_id'] ?? 0) > 0 ? (int) $f['lesson_id'] : ''; ?>" placeholder="Optional">
        </div>
        <div>
          <label class="block text-xs opacity-70 mb-1" for="payment_ref">Payment ref</label>
          <input type="text" name="payment_ref" id="payment_ref" maxlength="64" class="admin-input w-full px-3 py-2 rounded-xl"
                 value="<?php echo h((string) ($f['payment_ref'] ?? '')); ?>" placeholder="Prefix match">
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs opacity-70 mb-1" for="student">Student (name or email)</label>
          <input type="text" name="student" id="student" maxlength="120" class="admin-input w-full px-3 py-2 rounded-xl"
                 value="<?php echo h((string) ($f['student'] ?? '')); ?>" placeholder="Search students">
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="admin-primary-btn px-4 py-2 rounded-xl text-sm font-semibold">Apply filters</button>
        <a class="admin-outline-btn px-4 py-2 rounded-xl text-sm font-semibold" href="<?php echo h($self); ?>">Reset (all-time)</a>
      </div>
    </form>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
      <?php
        $kpis = [
          ['Paid GMV', p85_h_money((int) $p['paid_gmv_centavos']), 'Revenue from paid payments only'],
          ['Paid Payments', (string) (int) $p['paid'], 'status = paid'],
          ['Fulfilled', (string) (int) $p['fulfilled'], 'fulfilled_at set'],
          ['Paid Unfulfilled', (string) (int) $p['paid_unfulfilled'], 'paid + fulfilled_at NULL'],
          ['Needs Review', (string) (int) $p['needs_review'], 'verification_status'],
        ];
        foreach ($kpis as $kpi):
      ?>
        <div class="quiz-admin-table-shell rounded-2xl p-4">
          <div class="text-xs uppercase opacity-60 font-semibold"><?php echo h($kpi[0]); ?></div>
          <div class="text-xl font-bold mt-1"><?php echo h($kpi[1]); ?></div>
          <div class="text-xs opacity-50 mt-1"><?php echo h($kpi[2]); ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
      <div class="quiz-admin-table-shell rounded-2xl p-4 sm:p-5">
        <h2 class="text-sm font-bold uppercase opacity-70 mb-3">Payment status</h2>
        <table class="w-full text-sm">
          <tbody>
            <?php
              $statusRows = [
                'Total' => $p['total'],
                'Paid' => $p['paid'],
                'Awaiting Proof' => $p['awaiting_proof'],
                'Pending Verification' => $p['pending_verification'],
                'Rejected' => $p['rejected'],
                'Cancelled' => $p['cancelled'],
                'Expired' => $p['expired'],
                'Fulfilled' => $p['fulfilled'],
                'Paid Unfulfilled' => $p['paid_unfulfilled'],
              ];
              foreach ($statusRows as $label => $val):
            ?>
              <tr class="border-t border-white/10">
                <td class="py-2"><?php echo h($label); ?></td>
                <td class="py-2 text-right font-semibold"><?php echo (int) $val; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="quiz-admin-table-shell rounded-2xl p-4 sm:p-5">
        <h2 class="text-sm font-bold uppercase opacity-70 mb-3">Verification (payments)</h2>
        <table class="w-full text-sm">
          <tbody>
            <?php
              $vRows = [
                'Not Started' => $p['v_not_started'],
                'Processing' => $p['v_processing'],
                'Auto Verified' => $p['v_auto_verified'],
                'Needs Review' => $p['v_needs_review'],
                'Failed' => $p['v_failed'],
                'Manually Approved' => $p['v_manually_approved'],
                'Manually Rejected' => $p['v_manually_rejected'],
              ];
              foreach ($vRows as $label => $val):
            ?>
              <tr class="border-t border-white/10">
                <td class="py-2"><?php echo h($label); ?></td>
                <td class="py-2 text-right font-semibold"><?php echo (int) $val; ?></td>
              </tr>
            <?php endforeach; ?>
            <tr class="border-t border-white/10">
              <td class="py-2 opacity-80">Verification Attempts <span class="text-xs opacity-50">(not payments)</span></td>
              <td class="py-2 text-right font-semibold"><?php echo (int) $dash['verification_attempts']; ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
      <div class="quiz-admin-table-shell rounded-2xl p-4 sm:p-5">
        <h2 class="text-sm font-bold uppercase opacity-70 mb-3">Package vs By Topic</h2>
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left opacity-60 text-xs uppercase">
              <th class="py-2">Type</th>
              <th class="py-2 text-right">Payments</th>
              <th class="py-2 text-right">Paid GMV</th>
            </tr>
          </thead>
          <tbody>
            <tr class="border-t border-white/10">
              <td class="py-2">Package</td>
              <td class="py-2 text-right font-semibold"><?php echo (int) $p['package_count']; ?></td>
              <td class="py-2 text-right font-semibold"><?php echo h(p85_h_money((int) $p['package_gmv_centavos'])); ?></td>
            </tr>
            <tr class="border-t border-white/10">
              <td class="py-2">By Topic</td>
              <td class="py-2 text-right font-semibold"><?php echo (int) $p['by_topic_count']; ?></td>
              <td class="py-2 text-right font-semibold"><?php echo h(p85_h_money((int) $p['by_topic_gmv_centavos'])); ?></td>
            </tr>
            <tr class="border-t border-white/10">
              <td class="py-2 font-semibold">Paid GMV (all)</td>
              <td class="py-2 text-right"><?php echo (int) $p['paid']; ?></td>
              <td class="py-2 text-right font-semibold"><?php echo h(p85_h_money((int) $p['paid_gmv_centavos'])); ?></td>
            </tr>
          </tbody>
        </table>
        <p class="text-xs opacity-50 mt-3">GMV = SUM(expected_amount_centavos) for status=paid. Never from grants or item joins.</p>
      </div>

      <div class="quiz-admin-table-shell rounded-2xl p-4 sm:p-5">
        <h2 class="text-sm font-bold uppercase opacity-70 mb-3">Free Access requests</h2>
        <table class="w-full text-sm">
          <tbody>
            <?php foreach (['Pending' => $far['pending'], 'Approved' => $far['approved'], 'Rejected' => $far['rejected'], 'Cancelled' => $far['cancelled']] as $label => $val): ?>
              <tr class="border-t border-white/10">
                <td class="py-2"><?php echo h($label); ?></td>
                <td class="py-2 text-right font-semibold"><?php echo (int) $val; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="text-xs opacity-50 mt-3">Free Access never contributes to GMV.
          <a class="underline" href="<?php echo h(ereview_url('admin_commerce_free_access')); ?>">Open Free Access queue</a>
        </p>
      </div>
    </div>

    <div class="quiz-admin-table-shell rounded-2xl p-4 sm:p-5 mb-5">
      <h2 class="text-sm font-bold uppercase opacity-70 mb-1">Grant health (not revenue)</h2>
      <p class="text-xs opacity-50 mb-3">Counts from access_grants. Overdue Active = status active but ends_at ≤ NOW() (scheduler/reconcile lag).</p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
        <div>
          <div class="text-xs uppercase opacity-60 font-semibold mb-2">Purchase grants</div>
          <table class="w-full">
            <tbody>
              <?php
                $pg = [
                  'Active' => $g['purchase_active'],
                  'Expired' => $g['purchase_expired'],
                  'Revoked' => $g['purchase_revoked'],
                  'Overdue Active' => $g['purchase_overdue_active'],
                ];
                foreach ($pg as $label => $val):
              ?>
                <tr class="border-t border-white/10">
                  <td class="py-2"><?php echo h($label); ?></td>
                  <td class="py-2 text-right font-semibold"><?php echo (int) $val; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div>
          <div class="text-xs uppercase opacity-60 font-semibold mb-2">Free Access grants</div>
          <table class="w-full">
            <tbody>
              <?php
                $fg = [
                  'Active' => $g['free_access_active'],
                  'Expired' => $g['free_access_expired'],
                  'Revoked' => $g['free_access_revoked'],
                  'Overdue Active' => $g['free_access_overdue_active'],
                ];
                foreach ($fg as $label => $val):
              ?>
                <tr class="border-t border-white/10">
                  <td class="py-2"><?php echo h($label); ?></td>
                  <td class="py-2 text-right font-semibold"><?php echo (int) $val; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="quiz-admin-table-shell rounded-2xl p-4 sm:p-5 mb-5 overflow-x-auto">
      <h2 class="text-sm font-bold uppercase opacity-70 mb-3">Recent payments (max 20)</h2>
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase opacity-60">
            <th class="py-2 pr-2">Ref</th>
            <th class="py-2 pr-2">Student</th>
            <th class="py-2 pr-2">Type</th>
            <th class="py-2 pr-2">Status</th>
            <th class="py-2 pr-2">Verification</th>
            <th class="py-2 pr-2 text-right">Amount</th>
            <th class="py-2">Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($dash['recent_payments'] === []): ?>
            <tr><td colspan="7" class="py-3 opacity-60">No payments match filters.</td></tr>
          <?php else: ?>
            <?php foreach ($dash['recent_payments'] as $row): ?>
              <tr class="border-t border-white/10">
                <td class="py-2 pr-2">
                  <a class="underline font-semibold" href="<?php echo h(ereview_url('admin_commerce_payments') . '?id=' . (int) $row['payment_id']); ?>">
                    <?php echo h((string) $row['payment_ref']); ?>
                  </a>
                </td>
                <td class="py-2 pr-2">
                  <div><?php echo h((string) ($row['full_name'] ?? '')); ?></div>
                  <div class="text-xs opacity-60"><?php echo h((string) ($row['email'] ?? '')); ?></div>
                </td>
                <td class="py-2 pr-2"><?php echo h((string) $row['purchase_type']); ?></td>
                <td class="py-2 pr-2"><?php echo h((string) $row['status']); ?></td>
                <td class="py-2 pr-2"><?php echo h((string) $row['verification_status']); ?></td>
                <td class="py-2 pr-2 text-right"><?php echo h(p85_h_money((int) $row['expected_amount_centavos'])); ?></td>
                <td class="py-2 whitespace-nowrap"><?php echo h((string) $row['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="quiz-admin-table-shell rounded-2xl p-4 sm:p-5 mb-5 overflow-x-auto">
      <h2 class="text-sm font-bold uppercase opacity-70 mb-3">Recent Free Access requests (max 20)</h2>
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase opacity-60">
            <th class="py-2 pr-2">Ref</th>
            <th class="py-2 pr-2">Student</th>
            <th class="py-2 pr-2">Status</th>
            <th class="py-2">Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($dash['recent_far'] === []): ?>
            <tr><td colspan="4" class="py-3 opacity-60">No Free Access requests.</td></tr>
          <?php else: ?>
            <?php foreach ($dash['recent_far'] as $row): ?>
              <tr class="border-t border-white/10">
                <td class="py-2 pr-2">
                  <a class="underline font-semibold" href="<?php echo h(ereview_url('admin_commerce_free_access') . '?id=' . (int) $row['request_id']); ?>">
                    <?php echo h((string) $row['request_ref']); ?>
                  </a>
                </td>
                <td class="py-2 pr-2">
                  <div><?php echo h((string) ($row['full_name'] ?? '')); ?></div>
                  <div class="text-xs opacity-60"><?php echo h((string) ($row['email'] ?? '')); ?></div>
                </td>
                <td class="py-2 pr-2"><?php echo h((string) $row['status']); ?></td>
                <td class="py-2 whitespace-nowrap"><?php echo h((string) $row['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</main>
</body>
</html>
