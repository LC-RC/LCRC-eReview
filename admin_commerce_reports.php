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
$adminHeroEyebrow = 'Reporting & analytics';
$adminHeroTitle = 'Reports';
$adminHeroSubtitle = 'Read-only payment, verification, grant, and Free Access summaries. Free Access is never included in GMV.';
$adminHeroActions = '<a class="admin-btn admin-btn--ghost admin-btn--sm inline-flex h-10 items-center gap-2 rounded-xl px-3.5 text-sm font-semibold" href="'
    . h(ereview_url('admin_commerce_payments')) . '">Payment Verification</a>'
    . '<a class="admin-btn admin-btn--ghost admin-btn--sm inline-flex h-10 items-center gap-2 rounded-xl px-3.5 text-sm font-semibold" href="'
    . h(ereview_url('admin_commerce_free_access')) . '">Free Access</a>';

$self = ereview_url('admin_commerce_reports');
$filterScope = (!empty($f['date_from']) && !empty($f['date_to']))
    ? ((string) $f['date_from'] . ' – ' . (string) $f['date_to'])
    : 'All-time';
$needsReviewCount = (int) ($p['needs_review'] ?? 0);

function p85_h_money(int $centavos): string
{
    return '₱' . commerce_reports_centavos_to_php($centavos);
}

function p85_status_mod(string $status): string
{
    $s = strtolower($status);
    if (in_array($s, ['paid', 'approved', 'auto_verified', 'manually_approved', 'fulfilled', 'active'], true)) {
        return 'ok';
    }
    if (in_array($s, ['needs_review', 'pending_verification', 'awaiting_proof', 'processing', 'pending'], true)) {
        return 'warn';
    }
    return 'muted';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-commerce-reports-page">
  <?php include 'admin_sidebar.php'; ?>
  <div class="w-full cr-workspace">
    <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

    <?php if (!empty($f['warnings'])): ?>
      <div class="admin-alert admin-alert--error mb-4">
        <?php foreach ($f['warnings'] as $w): ?>
          <div><?php echo h((string) $w); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="get" action="<?php echo h($self); ?>" class="cr-filter admin-data-toolbar">
      <div class="cr-filter__head">
        <h2 class="cr-filter__title">Filters</h2>
        <span class="cr-filter__scope"><?php echo h($filterScope); ?></span>
      </div>
      <div class="cr-filter__grid">
        <div class="admin-filter-field">
          <label for="date_from">Date from</label>
          <input type="date" name="date_from" id="date_from" class="admin-input"
                 value="<?php echo h((string) ($f['date_from'] ?? '')); ?>">
        </div>
        <div class="admin-filter-field">
          <label for="date_to">Date to</label>
          <input type="date" name="date_to" id="date_to" class="admin-input"
                 value="<?php echo h((string) ($f['date_to'] ?? '')); ?>">
        </div>
        <div class="admin-filter-field">
          <label for="status">Payment status</label>
          <select name="status" id="status" class="admin-input">
            <option value="all">All</option>
            <?php foreach (COMMERCE_REPORTS_PAYMENT_STATUSES as $st): ?>
              <option value="<?php echo h($st); ?>" <?php echo ($f['status'] ?? '') === $st ? 'selected' : ''; ?>><?php echo h($st); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-filter-field">
          <label for="verification_status">Verification</label>
          <select name="verification_status" id="verification_status" class="admin-input">
            <option value="all">All</option>
            <?php foreach (COMMERCE_REPORTS_VERIFICATION_STATUSES as $st): ?>
              <option value="<?php echo h($st); ?>" <?php echo ($f['verification_status'] ?? '') === $st ? 'selected' : ''; ?>><?php echo h($st); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-filter-field">
          <label for="purchase_type">Purchase type</label>
          <select name="purchase_type" id="purchase_type" class="admin-input">
            <option value="all">All</option>
            <?php foreach (COMMERCE_REPORTS_PURCHASE_TYPES as $pt): ?>
              <option value="<?php echo h($pt); ?>" <?php echo ($f['purchase_type'] ?? '') === $pt ? 'selected' : ''; ?>><?php echo h($pt); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-filter-field">
          <label for="package_id">Package</label>
          <select name="package_id" id="package_id" class="admin-input">
            <option value="0">All packages</option>
            <?php foreach ($dash['packages'] as $pkg): ?>
              <option value="<?php echo (int) $pkg['package_id']; ?>" <?php echo ((int) ($f['package_id'] ?? 0) === (int) $pkg['package_id']) ? 'selected' : ''; ?>>
                <?php echo h($pkg['name'] . ' (' . $pkg['code'] . ')'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-filter-field">
          <label for="lesson_id">By Topic lesson ID</label>
          <input type="number" min="0" step="1" name="lesson_id" id="lesson_id" class="admin-input"
                 value="<?php echo (int) ($f['lesson_id'] ?? 0) > 0 ? (int) $f['lesson_id'] : ''; ?>" placeholder="Optional">
        </div>
        <div class="admin-filter-field">
          <label for="payment_ref">Payment ref</label>
          <input type="text" name="payment_ref" id="payment_ref" maxlength="64" class="admin-input"
                 value="<?php echo h((string) ($f['payment_ref'] ?? '')); ?>" placeholder="Prefix match">
        </div>
        <div class="admin-filter-field cr-filter__student">
          <label for="student">Student</label>
          <input type="text" name="student" id="student" maxlength="120" class="admin-input"
                 value="<?php echo h((string) ($f['student'] ?? '')); ?>" placeholder="Search students">
        </div>
      </div>
      <div class="cr-filter__actions">
        <a class="admin-btn admin-btn--ghost" href="<?php echo h($self); ?>">Reset</a>
        <button type="submit" class="admin-btn admin-btn--primary"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button>
      </div>
    </form>

    <div class="cr-summary-head">
      <h2 class="cr-summary-head__title">Report summary</h2>
      <p class="cr-summary-head__lede">Based on the current filters</p>
    </div>

    <div class="cr-kpi-grid" role="group" aria-label="Payment summary">
      <?php
        $kpis = [
          ['Paid GMV', p85_h_money((int) $p['paid_gmv_centavos']), 'Revenue from paid payments only', 'bi-cash-stack', 'gmv'],
          ['Paid Payments', (string) (int) $p['paid'], 'status = paid', 'bi-receipt', 'paid'],
          ['Fulfilled', (string) (int) $p['fulfilled'], 'fulfilled_at set', 'bi-check2-circle', 'fulfilled'],
          ['Paid Unfulfilled', (string) (int) $p['paid_unfulfilled'], 'paid + fulfilled_at NULL', 'bi-hourglass-split', 'unfulfilled'],
          ['Needs Review', (string) $needsReviewCount, 'verification_status', 'bi-exclamation-triangle', 'review'],
        ];
        foreach ($kpis as $kpi):
          $kpiTone = $kpi[4];
          $kpiAlert = ($kpiTone === 'review' && $needsReviewCount > 0);
      ?>
        <article class="cr-kpi cr-kpi--<?php echo h($kpiTone); ?><?php echo $kpiAlert ? ' is-alert' : ''; ?>">
          <div class="cr-kpi__top">
            <span class="cr-kpi__icon" aria-hidden="true"><i class="bi <?php echo h($kpi[3]); ?>"></i></span>
            <span class="cr-kpi__label"><?php echo h($kpi[0]); ?></span>
          </div>
          <div class="cr-kpi__value"><?php echo h($kpi[1]); ?></div>
          <p class="cr-kpi__desc"><?php echo h($kpi[2]); ?></p>
          <span class="cr-kpi__accent" aria-hidden="true"></span>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="cr-detail-grid">
      <div class="admin-data-surface cr-panel">
        <div class="admin-data-header">
          <div class="admin-data-header__title">Payment status</div>
        </div>
        <div class="admin-data-scroll">
          <table class="admin-table admin-data-table cr-metric-table">
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
                <tr>
                  <td><?php echo h($label); ?></td>
                  <td class="text-right font-semibold tabular-nums"><?php echo (int) $val; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="admin-data-surface cr-panel">
        <div class="admin-data-header">
          <div class="admin-data-header__title">Verification (payments)</div>
        </div>
        <div class="admin-data-scroll">
          <table class="admin-table admin-data-table cr-metric-table">
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
                <tr>
                  <td><?php echo h($label); ?></td>
                  <td class="text-right font-semibold tabular-nums"><?php echo (int) $val; ?></td>
                </tr>
              <?php endforeach; ?>
              <tr>
                <td>Verification Attempts <span class="cr-note">(not payments)</span></td>
                <td class="text-right font-semibold tabular-nums"><?php echo (int) $dash['verification_attempts']; ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="admin-data-surface cr-panel">
        <div class="admin-data-header">
          <div>
            <div class="admin-data-header__title">Package vs By Topic</div>
            <div class="admin-data-header__meta">GMV = SUM(expected_amount_centavos) for status=paid. Never from grants or item joins.</div>
          </div>
        </div>
        <div class="admin-data-scroll">
          <table class="admin-table admin-data-table">
            <thead>
              <tr>
                <th>Type</th>
                <th class="text-right">Payments</th>
                <th class="text-right">Paid GMV</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Package</td>
                <td class="text-right font-semibold tabular-nums"><?php echo (int) $p['package_count']; ?></td>
                <td class="text-right font-semibold tabular-nums"><?php echo h(p85_h_money((int) $p['package_gmv_centavos'])); ?></td>
              </tr>
              <tr>
                <td>By Topic</td>
                <td class="text-right font-semibold tabular-nums"><?php echo (int) $p['by_topic_count']; ?></td>
                <td class="text-right font-semibold tabular-nums"><?php echo h(p85_h_money((int) $p['by_topic_gmv_centavos'])); ?></td>
              </tr>
              <tr>
                <td class="font-semibold">Paid GMV (all)</td>
                <td class="text-right tabular-nums"><?php echo (int) $p['paid']; ?></td>
                <td class="text-right font-semibold tabular-nums"><?php echo h(p85_h_money((int) $p['paid_gmv_centavos'])); ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="admin-data-surface cr-panel">
        <div class="admin-data-header">
          <div>
            <div class="admin-data-header__title">Free Access requests</div>
            <div class="admin-data-header__meta">Free Access never contributes to GMV.</div>
          </div>
        </div>
        <div class="admin-data-scroll">
          <table class="admin-table admin-data-table cr-metric-table">
            <tbody>
              <?php foreach (['Pending' => $far['pending'], 'Approved' => $far['approved'], 'Rejected' => $far['rejected'], 'Cancelled' => $far['cancelled']] as $label => $val): ?>
                <tr>
                  <td><?php echo h($label); ?></td>
                  <td class="text-right font-semibold tabular-nums"><?php echo (int) $val; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="cr-panel__foot">
          <a class="admin-data-action" href="<?php echo h(ereview_url('admin_commerce_free_access')); ?>">Open Free Access queue →</a>
        </div>
      </div>
    </div>

    <div class="admin-data-surface cr-panel">
      <div class="admin-data-header">
        <div>
          <div class="admin-data-header__title">Grant health (not revenue)</div>
          <div class="admin-data-header__meta">Counts from access_grants. Overdue Active = status active but ends_at ≤ NOW() (scheduler/reconcile lag).</div>
        </div>
      </div>
      <div class="cr-grant-split">
        <div class="admin-data-scroll">
          <table class="admin-table admin-data-table cr-metric-table">
            <thead>
              <tr>
                <th>Purchase grants</th>
                <th class="text-right">Count</th>
              </tr>
            </thead>
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
                <tr>
                  <td><?php echo h($label); ?></td>
                  <td class="text-right font-semibold tabular-nums"><?php echo (int) $val; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="admin-data-scroll">
          <table class="admin-table admin-data-table cr-metric-table">
            <thead>
              <tr>
                <th>Free Access grants</th>
                <th class="text-right">Count</th>
              </tr>
            </thead>
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
                <tr>
                  <td><?php echo h($label); ?></td>
                  <td class="text-right font-semibold tabular-nums"><?php echo (int) $val; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="admin-data-surface">
      <div class="admin-data-header">
        <div class="admin-data-header__title">Recent payments (max 20)</div>
      </div>
      <?php if ($dash['recent_payments'] === []): ?>
        <div class="admin-empty-state">
          <span class="admin-empty-state__icon" aria-hidden="true"><i class="bi bi-search"></i></span>
          <h3 class="admin-empty-state__title">No payments match filters.</h3>
        </div>
      <?php else: ?>
      <div class="admin-data-scroll">
        <table class="admin-table admin-data-table">
          <thead>
            <tr>
              <th>Ref</th>
              <th>Student</th>
              <th>Type</th>
              <th>Status</th>
              <th>Verification</th>
              <th class="text-right">Amount</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dash['recent_payments'] as $row): ?>
              <tr>
                <td>
                  <a class="font-semibold" href="<?php echo h(ereview_url('admin_commerce_payments') . '?id=' . (int) $row['payment_id']); ?>">
                    <?php echo h((string) $row['payment_ref']); ?>
                  </a>
                </td>
                <td>
                  <div class="admin-data-person__name"><?php echo h((string) ($row['full_name'] ?? '')); ?></div>
                  <div class="admin-data-person__email"><?php echo h((string) ($row['email'] ?? '')); ?></div>
                </td>
                <td><?php echo h((string) $row['purchase_type']); ?></td>
                <td>
                  <span class="admin-data-status admin-data-status--<?php echo h(p85_status_mod((string) $row['status'])); ?>">
                    <span class="admin-data-status__dot" aria-hidden="true"></span>
                    <?php echo h((string) $row['status']); ?>
                  </span>
                </td>
                <td>
                  <span class="admin-data-status admin-data-status--<?php echo h(p85_status_mod((string) $row['verification_status'])); ?>">
                    <span class="admin-data-status__dot" aria-hidden="true"></span>
                    <?php echo h((string) $row['verification_status']); ?>
                  </span>
                </td>
                <td class="text-right font-semibold tabular-nums"><?php echo h(p85_h_money((int) $row['expected_amount_centavos'])); ?></td>
                <td class="whitespace-nowrap"><?php echo h((string) $row['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="admin-data-surface">
      <div class="admin-data-header">
        <div class="admin-data-header__title">Recent Free Access requests (max 20)</div>
      </div>
      <?php if ($dash['recent_far'] === []): ?>
        <div class="admin-empty-state">
          <span class="admin-empty-state__icon" aria-hidden="true"><i class="bi bi-search"></i></span>
          <h3 class="admin-empty-state__title">No Free Access requests.</h3>
        </div>
      <?php else: ?>
      <div class="admin-data-scroll">
        <table class="admin-table admin-data-table">
          <thead>
            <tr>
              <th>Ref</th>
              <th>Student</th>
              <th>Status</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dash['recent_far'] as $row): ?>
              <tr>
                <td>
                  <a class="font-semibold" href="<?php echo h(ereview_url('admin_commerce_free_access') . '?id=' . (int) $row['request_id']); ?>">
                    <?php echo h((string) $row['request_ref']); ?>
                  </a>
                </td>
                <td>
                  <div class="admin-data-person__name"><?php echo h((string) ($row['full_name'] ?? '')); ?></div>
                  <div class="admin-data-person__email"><?php echo h((string) ($row['email'] ?? '')); ?></div>
                </td>
                <td>
                  <span class="admin-data-status admin-data-status--<?php echo h(p85_status_mod((string) $row['status'])); ?>">
                    <span class="admin-data-status__dot" aria-hidden="true"></span>
                    <?php echo h((string) $row['status']); ?>
                  </span>
                </td>
                <td class="whitespace-nowrap"><?php echo h((string) $row['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>
</main>
</body>
</html>
