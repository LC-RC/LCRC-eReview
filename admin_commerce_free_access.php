<?php
/**
 * Admin Commerce — Free Access approval queue (Phase 8.1).
 * Approve grants full_lms via access_grants (source=free_access). No payments/OCR.
 * Does NOT change users.status / login activation.
 */
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/commerce_catalog.php';
require_once __DIR__ . '/includes/commerce_free_access.php';
require_once __DIR__ . '/includes/commerce_grants_admin.php';
require_once __DIR__ . '/includes/url_helpers.php';

if (!commerce_schema_ready($conn)) {
    $_SESSION['error'] = 'Commerce schema is not installed.';
    header('Location: admin_dashboard');
    exit;
}

$csrf = generateCSRFToken();
$pageTitle = 'Commerce — Free Access';
$adminId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: ' . ereview_url('admin_commerce_free_access'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $rid = (int) ($_POST['request_id'] ?? 0);
    $note = (string) ($_POST['admin_note'] ?? '');
    if ($rid <= 0 || $adminId <= 0) {
        $_SESSION['error'] = 'Invalid request or admin session.';
        header('Location: ' . ereview_url('admin_commerce_free_access'));
        exit;
    }

    if ($action === 'approve') {
        $durCheck = commerce_far_validate_duration_months($_POST['duration_months'] ?? '');
        if (empty($durCheck['ok'])) {
            $_SESSION['error'] = (string) ($durCheck['error'] ?? 'Invalid duration.');
            header('Location: ' . ereview_url('admin_commerce_free_access') . '?id=' . $rid);
            exit;
        }
        $r = commerce_far_approve($conn, $rid, $adminId, (int) $durCheck['months'], $note);
        if (!empty($r['ok'])) {
            $act = $r['activation'] ?? [];
            $actNote = !empty($act['activated'])
                ? ' Student login was activated automatically.'
                : (!empty($act['already_approved'])
                    ? ' Student login was already active.'
                    : (empty($act['ok'])
                        ? ' Login activation needs attention (commerce grant is active).'
                        : ''));
            $_SESSION['message'] = !empty($r['skipped'])
                ? 'Free Access already approved (no duplicate grant created).' . $actNote
                : 'Free Access approved: full LMS grant created for '
                    . (int) $durCheck['months']
                    . ' month(s).' . $actNote;
        } else {
            $_SESSION['error'] = 'Approve failed: ' . (string) ($r['error'] ?? 'unknown');
        }
        header('Location: ' . ereview_url('admin_commerce_free_access') . '?id=' . $rid);
        exit;
    }

    if ($action === 'reject') {
        $r = commerce_far_reject($conn, $rid, $adminId, $note);
        if (!empty($r['ok'])) {
            $_SESSION['message'] = !empty($r['skipped'])
                ? 'Free Access request was already rejected.'
                : 'Free Access request rejected. No access was granted.';
        } else {
            $_SESSION['error'] = 'Reject failed: ' . (string) ($r['error'] ?? 'unknown');
        }
        header('Location: ' . ereview_url('admin_commerce_free_access') . '?id=' . $rid);
        exit;
    }

    if ($action === 'revoke_access') {
        $reason = (string) ($_POST['revoke_reason'] ?? '');
        $r = commerce_far_revoke_access($conn, $rid, $adminId, $reason);
        if (!empty($r['ok'])) {
            if (!empty($r['skipped'])) {
                $_SESSION['message'] = 'Free Access grant already revoked or not active. FAR request remains approved. Login status was not changed.';
            } else {
                $_SESSION['message'] = 'Free Access grant revoked. FAR request remains approved. Payment ledger and login status were not changed.';
            }
        } else {
            $err = (string) ($r['error'] ?? 'unknown');
            if (strpos($err, 'grants_revoked_but_reconcile_failed') === 0) {
                $uid = (int) ($r['user_id'] ?? 0);
                $_SESSION['error'] = 'Free Access grant was revoked, but SCA reconciliation failed'
                    . ($uid > 0 ? (' for user #' . $uid . '. Repair with: php scripts/commerce_expire_reconcile.php --user_id=' . $uid) : '.')
                    . ' FAR request remains approved.';
            } else {
                $_SESSION['error'] = 'Revoke failed: ' . $err;
            }
        }
        header('Location: ' . ereview_url('admin_commerce_free_access') . '?id=' . $rid);
        exit;
    }

    $_SESSION['error'] = 'Unknown action.';
    header('Location: ' . ereview_url('admin_commerce_free_access'));
    exit;
}

$filter = strtolower(trim((string) ($_GET['v'] ?? 'pending')));
$allowedFilters = ['all', 'pending', 'approved', 'rejected', 'cancelled'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'pending';
}
$detailId = (int) ($_GET['id'] ?? 0);

$detail = null;
$detailGrant = null;
$detailReviewer = null;
if ($detailId > 0) {
    $detail = commerce_far_get_request($conn, $detailId);
    if ($detail) {
        $detailGrant = commerce_far_existing_full_lms_grant($conn, $detailId);
        $revBy = (int) ($detail['reviewed_by'] ?? 0);
        if ($revBy > 0) {
            $rs = mysqli_prepare($conn, 'SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1');
            if ($rs) {
                mysqli_stmt_bind_param($rs, 'i', $revBy);
                mysqli_stmt_execute($rs);
                $rr = mysqli_stmt_get_result($rs);
                $detailReviewer = $rr ? mysqli_fetch_assoc($rr) : null;
                mysqli_stmt_close($rs);
            }
        }
    }
}

$sql = "SELECT r.request_id, r.request_ref, r.user_id, r.status, r.student_note, r.admin_note,
               r.reviewed_by, r.reviewed_at, r.created_at, r.updated_at,
               u.full_name, u.email, u.status AS user_status,
               rev.full_name AS reviewer_name
        FROM free_access_requests r
        INNER JOIN users u ON u.user_id = r.user_id
        LEFT JOIN users rev ON rev.user_id = r.reviewed_by
        WHERE 1=1";
if ($filter !== 'all') {
    $sql .= " AND r.status = '" . mysqli_real_escape_string($conn, $filter) . "'";
}
$sql .= ' ORDER BY r.created_at DESC LIMIT 200';

$rows = [];
$res = mysqli_query($conn, $sql);
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    mysqli_free_result($res);
}

$adminBreadcrumbs = [['Dashboard', 'admin_dashboard'], ['Commerce'], ['Free Access']];
$adminHeroIcon = 'gift';
$adminHeroTitle = 'Free Access';
$adminHeroSubtitle = 'Approve or reject Free Access requests. Approval grants full LMS access for a chosen duration; it does not activate the student login account.';
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

    <?php if (!empty($_SESSION['message'])): ?>
      <div class="admin-alert admin-alert--success mb-4"><?php echo h($_SESSION['message']); unset($_SESSION['message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
      <div class="admin-alert admin-alert--error mb-4"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <div class="flex flex-wrap gap-2 text-sm mb-4">
      <?php
        $tabs = [
          'pending' => 'Pending',
          'approved' => 'Approved',
          'rejected' => 'Rejected',
          'cancelled' => 'Cancelled',
          'all' => 'All',
        ];
        foreach ($tabs as $key => $label):
          $active = $filter === $key;
          $href = ereview_url('admin_commerce_free_access') . ($key === 'pending' ? '' : '?v=' . rawurlencode($key));
      ?>
        <a class="px-3 py-1.5 rounded-lg border <?php echo $active ? 'border-sky-400 bg-sky-500/20 font-semibold' : 'border-white/10 opacity-80 hover:opacity-100'; ?>"
           href="<?php echo h($href); ?>"><?php echo h($label); ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($detail): ?>
      <?php $canAct = ((string) $detail['status'] === 'pending'); ?>
      <div class="quiz-admin-table-shell rounded-2xl p-5 sm:p-6 mb-5 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-bold"><?php echo h((string) $detail['request_ref']); ?></h2>
            <p class="text-sm opacity-70">#<?php echo (int) $detail['request_id']; ?> · status: <strong><?php echo h((string) $detail['status']); ?></strong></p>
          </div>
          <a class="admin-outline-btn px-3 py-2 rounded-xl text-sm font-semibold"
             href="<?php echo h(ereview_url('admin_commerce_free_access') . ($filter === 'pending' ? '' : '?v=' . rawurlencode($filter))); ?>">Back to list</a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div>
            <div class="text-xs uppercase opacity-60 font-semibold mb-1">Student</div>
            <div><?php echo h((string) ($detail['full_name'] ?? '')); ?></div>
            <div class="opacity-70"><?php echo h((string) ($detail['email'] ?? '')); ?></div>
            <div class="opacity-70">account status: <?php echo h((string) ($detail['user_status'] ?? '')); ?> (unchanged by Free Access)</div>
            <?php if (!empty($detail['user_id'])): ?>
              <div class="mt-2">
                <a class="font-semibold underline text-sky-300" href="<?php echo h(ereview_url('admin_student_view') . '?id=' . (int) $detail['user_id']); ?>">View Student</a>
              </div>
            <?php endif; ?>
          </div>
          <div>
            <div class="text-xs uppercase opacity-60 font-semibold mb-1">Request</div>
            <div>Submitted: <?php echo h((string) ($detail['created_at'] ?? '')); ?></div>
            <div class="mt-2 whitespace-pre-wrap"><?php echo h((string) ($detail['student_note'] ?? '—')); ?></div>
          </div>
        </div>

        <?php if (!$canAct): ?>
          <div class="text-sm border-t border-white/10 pt-4 space-y-1">
            <div>Final status: <strong><?php echo h((string) $detail['status']); ?></strong></div>
            <div>Reviewer: <?php echo h((string) ($detailReviewer['full_name'] ?? ($detail['reviewed_by'] ? '#' . (int) $detail['reviewed_by'] : '—'))); ?></div>
            <div>Reviewed at: <?php echo h((string) ($detail['reviewed_at'] ?? '—')); ?></div>
            <div>Admin note: <?php echo h((string) ($detail['admin_note'] ?? '—')); ?></div>
            <div class="mt-2">
              <a class="underline text-sm font-semibold" href="<?php echo h(ereview_url('admin_commerce_grants') . '?free_access_request_id=' . (int) $detail['request_id']); ?>">Open Grant Ledger for this FAR</a>
            </div>
            <?php if ($detailGrant): ?>
              <?php
                $canRevokeFar = ((string) ($detail['status'] ?? '') === 'approved'
                    && (string) ($detailGrant['source'] ?? '') === 'free_access'
                    && (string) ($detailGrant['status'] ?? '') === 'active');
              ?>
              <div class="mt-4 border-t border-white/10 pt-4 space-y-2">
                <div class="text-xs uppercase opacity-60 font-semibold">Access / Grant</div>
                <div>Source: <strong><?php echo h((string) $detailGrant['source']); ?></strong></div>
                <div>Status: <strong><?php echo h((string) $detailGrant['status']); ?></strong></div>
                <div>Starts: <?php echo h((string) ($detailGrant['starts_at'] ?? '—')); ?></div>
                <div>Ends: <?php echo h((string) ($detailGrant['ends_at'] ?? '—')); ?></div>
                <div>Revoked at: <?php echo h((string) ($detailGrant['revoked_at'] ?? '—')); ?></div>
                <div>Revoke reason: <?php echo h((string) ($detailGrant['revoke_reason'] ?? '—')); ?></div>
                <?php if ($canRevokeFar): ?>
                  <form method="post" class="mt-3 space-y-3 max-w-xl">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="request_id" value="<?php echo (int) $detail['request_id']; ?>">
                    <div>
                      <label class="block text-xs uppercase opacity-60 font-semibold mb-1" for="revoke_reason">Revoke reason <span class="text-rose-300">required</span></label>
                      <textarea name="revoke_reason" id="revoke_reason" rows="2" maxlength="255" required
                                class="admin-input w-full px-3 py-2 rounded-xl"
                                placeholder="Why is Free Access being revoked?"></textarea>
                    </div>
                    <button type="submit" name="action" value="revoke_access"
                            class="admin-outline-btn px-4 py-2 rounded-xl text-sm font-semibold"
                            onclick="return confirm('Revoke Free Access grant for this request? The FAR decision stays approved; purchase grants and login status are not changed.');">
                      Revoke Access
                    </button>
                    <p class="text-xs opacity-60">Revokes the active free_access grant only. FAR status remains approved. SCA is reconciled so overlapping coverage is preserved.</p>
                  </form>
                <?php elseif ((string) ($detail['status'] ?? '') === 'approved'): ?>
                  <p class="text-sm opacity-70 mt-2">No active Free Access grant to revoke.</p>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <form method="post" class="border-t border-white/10 pt-4 space-y-3 max-w-xl">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="request_id" value="<?php echo (int) $detail['request_id']; ?>">
            <div>
              <label class="block text-xs uppercase opacity-60 font-semibold mb-1" for="duration_months">Duration (months) <span class="text-rose-300">required</span></label>
              <div class="flex items-center gap-2">
                <input type="number" min="1" max="120" step="1" required name="duration_months" id="duration_months"
                       class="admin-input w-28 px-3 py-2 rounded-xl" placeholder="6" value="">
                <span class="text-sm opacity-80">months</span>
              </div>
              <p class="text-xs opacity-60 mt-1">Grant starts now and ends after the selected calendar months. Full LMS access only.</p>
            </div>
            <div>
              <label class="block text-xs uppercase opacity-60 font-semibold mb-1" for="admin_note">Admin Note</label>
              <textarea name="admin_note" id="admin_note" rows="2" class="admin-input w-full px-3 py-2 rounded-xl" placeholder="Optional"></textarea>
            </div>
            <div class="flex flex-wrap gap-2">
              <button type="submit" name="action" value="approve" class="admin-primary-btn px-4 py-2 rounded-xl text-sm font-semibold">Approve</button>
              <button type="submit" name="action" value="reject" class="admin-outline-btn px-4 py-2 rounded-xl text-sm font-semibold"
                      formnovalidate
                      onclick="return confirm('Reject this Free Access request? No access will be granted.');">Reject</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="quiz-admin-table-shell rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase opacity-60 border-b border-white/10">
              <th class="px-4 py-3">Request</th>
              <th class="px-4 py-3">Student</th>
              <th class="px-4 py-3">Email</th>
              <th class="px-4 py-3">Student note</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Submitted</th>
              <th class="px-4 py-3">Reviewed</th>
              <th class="px-4 py-3">Reviewer</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rows === []): ?>
              <tr><td colspan="9" class="px-4 py-6 opacity-70">No Free Access requests in this filter.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr class="border-b border-white/5 align-top">
                  <td class="px-4 py-3 font-semibold"><?php echo h((string) $row['request_ref']); ?></td>
                  <td class="px-4 py-3"><?php echo h((string) $row['full_name']); ?></td>
                  <td class="px-4 py-3 opacity-80"><?php echo h((string) $row['email']); ?></td>
                  <td class="px-4 py-3 max-w-xs">
                    <div class="line-clamp-2 opacity-80"><?php echo h((string) ($row['student_note'] ?? '')); ?></div>
                  </td>
                  <td class="px-4 py-3"><?php echo h((string) $row['status']); ?></td>
                  <td class="px-4 py-3 whitespace-nowrap opacity-80"><?php echo h((string) $row['created_at']); ?></td>
                  <td class="px-4 py-3 whitespace-nowrap opacity-80"><?php echo h((string) ($row['reviewed_at'] ?? '—')); ?></td>
                  <td class="px-4 py-3 opacity-80"><?php echo h((string) ($row['reviewer_name'] ?? '—')); ?></td>
                  <td class="px-4 py-3">
                    <a class="text-sky-300 underline"
                       href="<?php echo h(ereview_url('admin_commerce_free_access') . '?id=' . (int) $row['request_id'] . ($filter === 'pending' ? '' : '&v=' . rawurlencode($filter))); ?>">Open</a>
                  </td>
                </tr>
                <?php if ((string) $row['status'] === 'pending'): ?>
                <tr class="border-b border-white/5 bg-white/[0.02]">
                  <td colspan="9" class="px-4 py-3">
                    <form method="post" class="flex flex-wrap items-end gap-3">
                      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                      <input type="hidden" name="request_id" value="<?php echo (int) $row['request_id']; ?>">
                      <div>
                        <label class="block text-xs opacity-60 mb-1">Duration (months)</label>
                        <input type="number" min="1" max="120" step="1" required name="duration_months"
                               class="admin-input w-24 px-2 py-1.5 rounded-lg" placeholder="6">
                      </div>
                      <div class="flex-1 min-w-[12rem]">
                        <label class="block text-xs opacity-60 mb-1">Admin Note</label>
                        <input type="text" name="admin_note" class="admin-input w-full px-2 py-1.5 rounded-lg" placeholder="Optional">
                      </div>
                      <button type="submit" name="action" value="approve" class="admin-primary-btn px-3 py-1.5 rounded-lg text-sm font-semibold">Approve</button>
                      <button type="submit" name="action" value="reject" class="admin-outline-btn px-3 py-1.5 rounded-lg text-sm font-semibold"
                              formnovalidate
                              onclick="return confirm('Reject this Free Access request?');">Reject</button>
                    </form>
                  </td>
                </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
