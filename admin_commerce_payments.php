<?php
/**
 * Admin Commerce — Payment verification + manual review (Phase 6/7) + paid revoke (Phase 8.3).
 * Manual Approve/Reject for needs_review and OCR-failed (with proof).
 * Approve uses the same fulfill path as auto_verified (grants + SCA + auto login activation).
 * Revoke Access targets purchase grants only; payment ledger stays immutable.
 */
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/commerce_catalog.php';
require_once __DIR__ . '/includes/commerce_payment.php';
require_once __DIR__ . '/includes/commerce_fulfillment.php';
require_once __DIR__ . '/includes/commerce_revoke.php';
require_once __DIR__ . '/includes/commerce_student_admin.php';
require_once __DIR__ . '/includes/url_helpers.php';

if (!commerce_schema_ready($conn)) {
    $_SESSION['error'] = 'Commerce schema is not installed.';
    header('Location: admin_dashboard');
    exit;
}

$csrf = generateCSRFToken();
$pageTitle = 'Commerce — Payment Verification';
$adminId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: ' . ereview_url('admin_commerce_payments'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $pid = (int) ($_POST['payment_id'] ?? 0);
    $note = (string) ($_POST['review_note'] ?? '');
    $filterReturn = strtolower(trim((string) ($_POST['return_filter'] ?? 'all')));
    $allowedReturn = ['all', 'pending', 'auto_verified', 'needs_review', 'failed', 'processing', 'not_started', 'manually_approved', 'manually_rejected'];
    if (!in_array($filterReturn, $allowedReturn, true)) {
        $filterReturn = 'all';
    }
    $listUrl = ereview_url('admin_commerce_payments') . ($filterReturn === 'all' ? '' : ('?v=' . rawurlencode($filterReturn)));

    // Bulk approve / reject — same commerce_manual_* path per payment (no algorithm shortcut).
    if ($action === 'bulk_approve' || $action === 'bulk_reject') {
        if ($adminId <= 0) {
            $_SESSION['error'] = 'Invalid admin session.';
            header('Location: ' . $listUrl);
            exit;
        }
        $rawIds = $_POST['payment_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $ids = [];
        foreach ($rawIds as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            $_SESSION['error'] = 'Select at least one reviewable payment.';
            header('Location: ' . $listUrl);
            exit;
        }
        if (count($ids) > 50) {
            $_SESSION['error'] = 'Bulk limit is 50 payments at a time.';
            header('Location: ' . $listUrl);
            exit;
        }
        $okN = 0;
        $failN = 0;
        $failSamples = [];
        foreach ($ids as $bulkPid) {
            $r = ($action === 'bulk_approve')
                ? commerce_manual_approve_payment($conn, $bulkPid, $adminId, $note)
                : commerce_manual_reject_payment($conn, $bulkPid, $adminId, $note);
            if (!empty($r['ok'])) {
                $okN++;
            } else {
                $failN++;
                if (count($failSamples) < 5) {
                    $failSamples[] = '#' . $bulkPid . ':' . (string) ($r['error'] ?? 'error');
                }
            }
        }
        $verb = $action === 'bulk_approve' ? 'approved' : 'rejected';
        $msg = $okN . ' payment(s) ' . $verb . '.';
        if ($failN > 0) {
            $msg .= ' ' . $failN . ' skipped/failed' . ($failSamples !== [] ? (' (' . implode(', ', $failSamples) . ')') : '') . '.';
        }
        if ($okN > 0) {
            $_SESSION['message'] = $msg;
        } else {
            $_SESSION['error'] = $msg !== '' ? $msg : 'No payments were updated.';
        }
        header('Location: ' . $listUrl);
        exit;
    }

    if ($pid <= 0 || $adminId <= 0) {
        $_SESSION['error'] = 'Invalid payment or admin session.';
        header('Location: ' . ereview_url('admin_commerce_payments'));
        exit;
    }
    if ($action === 'approve') {
        $r = commerce_manual_approve_payment($conn, $pid, $adminId, $note);
        if (!empty($r['ok'])) {
            $fOk = !empty($r['fulfill']['ok']);
            $act = $r['fulfill']['activation'] ?? [];
            $actNote = '';
            if ($fOk) {
                if (!empty($act['activated'])) {
                    $actNote = ' Student login was activated automatically.';
                } elseif (!empty($act['already_approved'])) {
                    $actNote = ' Student login was already active.';
                } elseif (isset($act['ok']) && empty($act['ok'])) {
                    $actNote = ' Login activation needs attention (payment remains fulfilled).';
                }
            }
            $_SESSION['message'] = $fOk
                ? 'Payment manually approved and fulfilled.' . $actNote
                : 'Payment manually approved (paid). Fulfillment pending retry: ' . (string) ($r['fulfill']['error'] ?? 'unknown');
        } else {
            $_SESSION['error'] = 'Approve failed: ' . (string) ($r['error'] ?? 'unknown');
        }
        header('Location: ' . ereview_url('admin_commerce_payments') . '?id=' . $pid);
        exit;
    }
    if ($action === 'reject') {
        $r = commerce_manual_reject_payment($conn, $pid, $adminId, $note);
        if (!empty($r['ok'])) {
            $_SESSION['message'] = 'Payment manually rejected. No access was granted.';
        } else {
            $_SESSION['error'] = 'Reject failed: ' . (string) ($r['error'] ?? 'unknown');
        }
        header('Location: ' . ereview_url('admin_commerce_payments') . '?id=' . $pid);
        exit;
    }
    if ($action === 'revoke_access') {
        $reason = (string) ($_POST['revoke_reason'] ?? '');
        $r = commerce_revoke_payment_grants($conn, $pid, $adminId, $reason);
        if (!empty($r['ok'])) {
            $n = (int) ($r['revoked_count'] ?? 0);
            $_SESSION['message'] = $n > 0
                ? ('Paid access revoked for ' . $n . ' grant(s). Payment record was not changed.')
                : 'No active purchase grants to revoke (already revoked or expired). Payment record was not changed.';
        } else {
            $_SESSION['error'] = 'Revoke failed: ' . (string) ($r['error'] ?? 'unknown');
        }
        header('Location: ' . ereview_url('admin_commerce_payments') . '?id=' . $pid);
        exit;
    }
    $_SESSION['error'] = 'Unknown action.';
    header('Location: ' . ereview_url('admin_commerce_payments'));
    exit;
}

$filter = strtolower(trim((string) ($_GET['v'] ?? 'all')));
$allowedFilters = ['all', 'pending', 'auto_verified', 'needs_review', 'failed', 'processing', 'not_started', 'manually_approved', 'manually_rejected'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}
$detailId = (int) ($_GET['id'] ?? 0);

$detail = null;
$detailItems = [];
$detailAttempts = [];
$detailGrants = [];
$detailActivePurchaseGrants = 0;
if ($detailId > 0) {
    $detail = commerce_get_payment($conn, $detailId);
    if ($detail) {
        $detailItems = commerce_get_payment_items($conn, $detailId);
        $detailGrants = commerce_revoke_list_payment_grants($conn, $detailId);
        $detailActivePurchaseGrants = commerce_revoke_count_active_purchase_grants($conn, $detailId);
        $ast = mysqli_prepare(
            $conn,
            'SELECT attempt_id, engine, confidence, decision, decision_reasons_json, created_at
             FROM payment_verification_attempts WHERE payment_id = ? ORDER BY attempt_id DESC LIMIT 50'
        );
        if ($ast) {
            mysqli_stmt_bind_param($ast, 'i', $detailId);
            mysqli_stmt_execute($ast);
            $ar = mysqli_stmt_get_result($ast);
            while ($ar && ($row = mysqli_fetch_assoc($ar))) {
                $detailAttempts[] = $row;
            }
            mysqli_stmt_close($ast);
        }
    }
}

$sql = "SELECT p.payment_id, p.payment_ref, p.user_id, p.purchase_type, p.expected_amount_centavos,
               p.status, p.verification_status, p.verification_confidence, p.verification_summary,
               p.detected_amount_centavos, p.detected_reference, p.detected_recipient, p.detected_paid_at,
               p.proof_path, p.gcash_reference, p.paid_at, p.fulfilled_at, p.updated_at,
               u.full_name, u.email
        FROM payments p
        LEFT JOIN users u ON u.user_id = p.user_id
        WHERE 1=1";
if ($filter === 'pending') {
    $sql .= " AND p.status = 'pending_verification'";
} elseif ($filter !== 'all') {
    $sql .= " AND p.verification_status = '" . mysqli_real_escape_string($conn, $filter) . "'";
}
$sql .= ' ORDER BY p.updated_at DESC LIMIT 200';

$rows = [];
$res = mysqli_query($conn, $sql);
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    mysqli_free_result($res);
}

/** @var array<int, list<string>> $paymentTopicLabelsById */
$paymentTopicLabelsById = [];
if ($rows !== []) {
    $topicPayIds = [];
    foreach ($rows as $rr) {
        if ((string) ($rr['purchase_type'] ?? '') === 'by_topic') {
            $pid = (int) ($rr['payment_id'] ?? 0);
            if ($pid > 0) {
                $topicPayIds[$pid] = $pid;
            }
        }
    }
    if ($topicPayIds !== []) {
        $pin = implode(',', array_map('intval', array_values($topicPayIds)));
        $iq = mysqli_query(
            $conn,
            "SELECT payment_id, lesson_id, item_name
             FROM payment_items
             WHERE payment_id IN ($pin)
             ORDER BY payment_id ASC, line_no ASC"
        );
        $needLessonIds = [];
        while ($iq && ($ir = mysqli_fetch_assoc($iq))) {
            $pid = (int) ($ir['payment_id'] ?? 0);
            $name = trim((string) ($ir['item_name'] ?? ''));
            $lid = (int) ($ir['lesson_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if (!isset($paymentTopicLabelsById[$pid])) {
                $paymentTopicLabelsById[$pid] = [];
            }
            if ($name !== '') {
                $paymentTopicLabelsById[$pid][] = $name;
            } elseif ($lid > 0) {
                $paymentTopicLabelsById[$pid][] = '__lesson__' . $lid;
                $needLessonIds[$lid] = $lid;
            }
        }
        $lessonTitles = [];
        if ($needLessonIds !== [] && function_exists('commerce_admin_lesson_meta_map')) {
            $meta = commerce_admin_lesson_meta_map($conn, array_values($needLessonIds));
            foreach ($meta as $lid => $m) {
                $lessonTitles[(int) $lid] = (string) ($m['title'] ?? ('Lesson #' . (int) $lid));
            }
        } elseif ($needLessonIds !== []) {
            $lin = implode(',', array_map('intval', array_values($needLessonIds)));
            $lq = mysqli_query($conn, "SELECT lesson_id, title FROM lessons WHERE lesson_id IN ($lin)");
            while ($lq && ($lr = mysqli_fetch_assoc($lq))) {
                $lessonTitles[(int) $lr['lesson_id']] = (string) ($lr['title'] ?? ('Lesson #' . (int) $lr['lesson_id']));
            }
        }
        foreach ($paymentTopicLabelsById as $pid => $labels) {
            $resolved = [];
            foreach ($labels as $lab) {
                if (strpos($lab, '__lesson__') === 0) {
                    $lid = (int) substr($lab, strlen('__lesson__'));
                    $resolved[] = $lessonTitles[$lid] ?? ('Lesson #' . $lid);
                } else {
                    $resolved[] = $lab;
                }
            }
            $paymentTopicLabelsById[$pid] = $resolved;
        }
    }
}

function commerce_admin_vstatus_label(string $v): string
{
    switch ($v) {
        case 'auto_verified':
            return 'AUTO VERIFIED';
        case 'needs_review':
            return 'NEEDS REVIEW';
        case 'manually_approved':
            return 'MANUALLY APPROVED';
        case 'manually_rejected':
            return 'MANUALLY REJECTED';
        case 'failed':
            return 'FAILED';
        case 'processing':
            return 'PROCESSING';
        case 'not_started':
            return 'NOT STARTED';
        default:
            return strtoupper($v !== '' ? $v : 'UNKNOWN');
    }
}

$adminBreadcrumbs = [['Dashboard', 'admin_dashboard'], ['Commerce'], ['Payment Verification']];
$adminHeroIcon = 'receipt';
$adminHeroTitle = 'Payment Verification';
$adminHeroSubtitle = 'OCR results and manual review. Approve failed/needs-review payments with proof — same fulfillment path as auto-verified.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-commerce-payments-page">
  <?php include 'admin_sidebar.php'; ?>
  <div class="w-full">
    <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

    <?php if (!empty($_SESSION['message'])): ?>
      <div class="admin-flash admin-flash--success mb-4"><?php echo h($_SESSION['message']); unset($_SESSION['message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
      <div class="admin-flash admin-flash--error mb-4"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <div class="admin-filter-chips flex flex-wrap gap-2 text-sm mb-4" role="tablist" aria-label="Payment filters">
      <?php
        $tabs = [
          'all' => 'All',
          'needs_review' => 'Needs review',
          'auto_verified' => 'Auto verified',
          'manually_approved' => 'Manually approved',
          'manually_rejected' => 'Manually rejected',
          'failed' => 'Failed',
          'pending' => 'Pending payment',
          'not_started' => 'Not started',
          'processing' => 'Processing',
        ];
        foreach ($tabs as $key => $label):
          $active = $filter === $key;
          $href = ereview_url('admin_commerce_payments') . ($key === 'all' ? '' : '?v=' . rawurlencode($key));
      ?>
        <a class="admin-filter-chip <?php echo $active ? 'is-active' : ''; ?>"
           href="<?php echo h($href); ?>"><?php echo h($label); ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($detail): ?>
      <?php
        $vLab = commerce_admin_vstatus_label((string) $detail['verification_status']);
        $canReview = commerce_payment_is_manual_reviewable($detail);
      ?>
      <div class="quiz-admin-table-shell rounded-2xl p-5 sm:p-6 mb-5 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-bold"><?php echo h((string) $detail['payment_ref']); ?></h2>
            <p class="text-sm opacity-70">#<?php echo (int) $detail['payment_id']; ?> · payment status: <strong><?php echo h((string) $detail['status']); ?></strong></p>
            <p class="text-sm mt-1"><span class="font-semibold"><?php echo h($vLab); ?></span>
              <?php if ($detail['verification_confidence'] !== null && $detail['verification_confidence'] !== ''): ?>
                · conf <?php echo h((string) $detail['verification_confidence']); ?>
              <?php endif; ?>
            </p>
          </div>
          <a class="admin-btn admin-btn--secondary px-3 py-2 text-sm font-semibold" href="<?php echo h(ereview_url('admin_commerce_payments') . ($filter === 'all' ? '' : '?v=' . rawurlencode($filter))); ?>">Back to list</a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div>
            <div class="text-xs uppercase opacity-60 font-semibold mb-1">Student</div>
            <?php
              $u = null;
              $uq = mysqli_prepare(
                  $conn,
                  'SELECT full_name, email, status, enrollment_path, selected_package_id, selected_lesson_ids_json
                   FROM users WHERE user_id = ? LIMIT 1'
              );
              if ($uq) {
                  $uid = (int) $detail['user_id'];
                  mysqli_stmt_bind_param($uq, 'i', $uid);
                  mysqli_stmt_execute($uq);
                  $ur = mysqli_stmt_get_result($uq);
                  $u = $ur ? mysqli_fetch_assoc($ur) : null;
                  mysqli_stmt_close($uq);
              }
              $enrollPath = (string) ($u['enrollment_path'] ?? '');
              $enrollPathLabel = $enrollPath === 'package' ? 'Package'
                  : ($enrollPath === 'by_topic' ? 'By Topic'
                  : ($enrollPath === 'free_access' ? 'Free Access'
                  : ($enrollPath !== '' ? $enrollPath : '—')));
              $pkgLabel = '';
              $selPkgId = (int) ($u['selected_package_id'] ?? 0);
              if ($selPkgId > 0) {
                  $pq = mysqli_prepare($conn, 'SELECT name FROM sellable_packages WHERE package_id = ? LIMIT 1');
                  if ($pq) {
                      mysqli_stmt_bind_param($pq, 'i', $selPkgId);
                      mysqli_stmt_execute($pq);
                      $pr = mysqli_stmt_get_result($pq);
                      $prow = $pr ? mysqli_fetch_assoc($pr) : null;
                      mysqli_stmt_close($pq);
                      $pkgLabel = (string) ($prow['name'] ?? '');
                  }
              }
              $topicLabels = [];
              $topicGroupsDetail = [];
              if ($enrollPath === 'by_topic' && !empty($u['selected_lesson_ids_json'])) {
                  $safeIds = commerce_admin_parse_lesson_ids_json((string) $u['selected_lesson_ids_json']);
                  if ($safeIds !== []) {
                      $metaMap = commerce_admin_lesson_meta_map($conn, $safeIds);
                      $grouped = commerce_admin_group_topics_by_subject($safeIds, $metaMap);
                      $topicLabels = $grouped['flat_labels'];
                      $topicGroupsDetail = $grouped['groups'];
                  }
              }
            ?>
            <div><?php echo h((string) ($u['full_name'] ?? '')); ?></div>
            <div class="opacity-70"><?php echo h((string) ($u['email'] ?? '')); ?></div>
            <?php
              $acctUi = commerce_admin_label_account_status((string) ($u['status'] ?? ''));
              $vStatusUi = commerce_admin_label_verification_status((string) ($detail['verification_status'] ?? ''));
              $payUiLabel = commerce_admin_label_payment_status((string) ($detail['status'] ?? ''));
            ?>
            <div class="opacity-70">Account: <strong><?php echo h($acctUi); ?></strong></div>
            <?php if (!empty($detail['user_id'])): ?>
              <div class="mt-2">
                <a class="font-semibold underline text-sky-300" href="<?php echo h(ereview_url('admin_student_view') . '?id=' . (int) $detail['user_id']); ?>">View Student</a>
              </div>
            <?php endif; ?>
          </div>
          <div>
            <div class="text-xs uppercase opacity-60 font-semibold mb-1">Purchase</div>
            <div><?php echo h((string) $detail['purchase_type']); ?> · ₱<?php echo h(commerce_centavos_to_pesos_display((int) $detail['expected_amount_centavos'])); ?></div>
            <div class="opacity-70">Payment: <strong><?php echo h($payUiLabel); ?></strong></div>
            <div class="opacity-70">Verification: <strong><?php echo h($vStatusUi); ?></strong></div>
            <div class="opacity-70">GCash ref: <?php echo h((string) ($detail['gcash_reference'] ?? '—')); ?></div>
            <div class="opacity-70">paid_at: <?php echo h((string) ($detail['paid_at'] ?? 'NULL')); ?></div>
            <div class="opacity-70">fulfilled_at: <?php echo h((string) ($detail['fulfilled_at'] ?? 'NULL')); ?></div>
            <div class="opacity-70">Fulfillment: <strong><?php echo !empty($detail['fulfilled_at']) ? 'Fulfilled' : 'Pending'; ?></strong></div>
          </div>
          <div class="md:col-span-2">
            <div class="text-xs uppercase opacity-60 font-semibold mb-1">Enrollment</div>
            <div>Path: <strong><?php echo h($enrollPathLabel); ?></strong></div>
            <?php if ($enrollPath === 'package'): ?>
              <div class="opacity-70">Package: <?php echo h($pkgLabel !== '' ? $pkgLabel : '—'); ?></div>
            <?php elseif ($enrollPath === 'by_topic'): ?>
              <?php if ($topicGroupsDetail !== []): ?>
                <div class="opacity-70 mb-1">Topics by subject:</div>
                <div class="space-y-2">
                  <?php foreach ($topicGroupsDetail as $tg): ?>
                    <div class="rounded-lg border border-white/10 bg-white/5 px-3 py-2">
                      <div class="text-xs font-bold uppercase opacity-60"><?php echo h((string) $tg['subject_name']); ?></div>
                      <div class="text-sm mt-0.5"><?php echo h(implode(', ', $tg['topics'])); ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="opacity-70">Topics: <?php echo h($topicLabels !== [] ? implode(', ', $topicLabels) : '—'); ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php
          $acctSt = strtolower((string) ($u['status'] ?? ''));
          $paySt = (string) ($detail['status'] ?? '');
          $vSt = (string) ($detail['verification_status'] ?? '');
          $fulfilled = !empty($detail['fulfilled_at']);
          $verifiedPaid = ($paySt === 'paid' && in_array($vSt, ['auto_verified', 'manually_approved'], true));
          $grantTonePay = 'none';
          if (!empty($detail['user_id']) && function_exists('commerce_admin_grant_access_summary')) {
              $gs = commerce_admin_grant_access_summary($conn, (int) $detail['user_id']);
              $grantTonePay = (string) ($gs['tone'] ?? 'none');
          }
        ?>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
          <div class="rounded-xl border border-white/10 px-3 py-2">
            <div class="text-xs uppercase opacity-60 font-semibold">Payment</div>
            <div class="font-semibold mt-0.5"><?php echo h($verifiedPaid ? 'Verified' : $payUiLabel); ?></div>
          </div>
          <div class="rounded-xl border border-white/10 px-3 py-2">
            <div class="text-xs uppercase opacity-60 font-semibold">Fulfillment</div>
            <div class="font-semibold mt-0.5"><?php echo $fulfilled ? 'Fulfilled' : 'Pending'; ?></div>
          </div>
          <div class="rounded-xl border border-white/10 px-3 py-2">
            <div class="text-xs uppercase opacity-60 font-semibold">Access</div>
            <div class="font-semibold mt-0.5"><?php echo h($grantTonePay === 'active' ? 'Granted' : ($grantTonePay === 'none' ? 'None' : ucfirst($grantTonePay))); ?></div>
          </div>
          <div class="rounded-xl border border-white/10 px-3 py-2">
            <div class="text-xs uppercase opacity-60 font-semibold">Account</div>
            <div class="font-semibold mt-0.5"><?php echo h($acctUi); ?></div>
          </div>
        </div>
        <?php if ($fulfilled && $acctSt === 'pending'): ?>
          <div class="rounded-xl border border-amber-400/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
            Payment fulfilled — account activation requires repair. Use student <strong>Repair Activation</strong> (exception only; normal paid flow auto-activates).
            <?php if (!empty($detail['user_id'])): ?>
              <div class="mt-2">
                <a class="font-semibold underline text-amber-50" href="<?php echo h(ereview_url('admin_student_view') . '?id=' . (int) $detail['user_id']); ?>">Open student Repair Activation</a>
              </div>
            <?php endif; ?>
          </div>
        <?php elseif ($acctSt === 'approved' && $fulfilled && $verifiedPaid): ?>
          <div class="rounded-xl border border-emerald-400/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
            Payment verified, fulfilled, access granted, and account is Active. Manual activation is not part of the normal paid flow.
          </div>
        <?php elseif ($acctSt === 'approved' && (!$fulfilled || $paySt !== 'paid')): ?>
          <div class="rounded-xl border border-rose-400/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
            Account is Active, but commerce payment/access is not fulfilled.
          </div>
        <?php endif; ?>

        <div>
          <div class="text-xs uppercase opacity-60 font-semibold mb-2">Line items</div>
          <ul class="text-sm space-y-1">
            <?php foreach ($detailItems as $it): ?>
              <li><?php echo h((string) $it['item_name']); ?>
                · ₱<?php echo h(commerce_centavos_to_pesos_display((int) $it['line_total_centavos'])); ?>
                · <?php echo (int) $it['duration_value']; ?> <?php echo h((string) $it['duration_unit']); ?>(s)
                <?php if (!empty($it['package_access_scope'])): ?>
                  · <?php echo h((string) $it['package_access_scope']); ?>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div>
            <div class="text-xs uppercase opacity-60 font-semibold mb-1">OCR / detected</div>
            <div>Amount: <?php echo $detail['detected_amount_centavos'] !== null ? '₱' . h(commerce_centavos_to_pesos_display((int) $detail['detected_amount_centavos'])) : '—'; ?></div>
            <div>Reference: <?php echo h((string) ($detail['detected_reference'] ?? '—')); ?></div>
            <div>Recipient: <?php echo h((string) ($detail['detected_recipient'] ?? '—')); ?></div>
            <div>Paid at: <?php echo h((string) ($detail['detected_paid_at'] ?? '—')); ?></div>
            <div class="mt-2">Matched: amt=<?php echo (int) ($detail['matched_amount'] ?? 0); ?>
              ref=<?php echo (int) ($detail['matched_reference'] ?? 0); ?>
              recip=<?php echo (int) ($detail['matched_recipient'] ?? 0); ?>
              success=<?php echo (int) ($detail['matched_success_text'] ?? 0); ?>
              datetime=<?php echo (int) ($detail['matched_datetime_ok'] ?? 0); ?></div>
            <div class="mt-1 opacity-80">Flags: <?php echo h((string) ($detail['suspicious_flags_json'] ?? '[]')); ?></div>
            <div class="mt-1"><?php echo h((string) ($detail['verification_summary'] ?? '')); ?></div>
          </div>
          <div>
            <div class="text-xs uppercase opacity-60 font-semibold mb-1">Proof</div>
            <?php if (!empty($detail['proof_path'])): ?>
              <a class="text-sky-300 underline" data-admin-proof
                 data-proof-title="Proof · <?php echo h((string) $detail['payment_ref']); ?>"
                 href="<?php echo h(ereview_url('payment_proof_file') . '?payment_id=' . (int) $detail['payment_id']); ?>">View proof</a>
            <?php else: ?>
              <span class="opacity-50">No proof</span>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($detailAttempts !== []): ?>
          <div>
            <div class="text-xs uppercase opacity-60 font-semibold mb-2">Verification attempts</div>
            <div class="overflow-x-auto">
              <table class="w-full text-xs">
                <thead><tr class="opacity-60 text-left"><th class="py-1 pr-2">#</th><th class="py-1 pr-2">Engine</th><th class="py-1 pr-2">Conf</th><th class="py-1 pr-2">Decision</th><th class="py-1">When</th></tr></thead>
                <tbody>
                  <?php foreach ($detailAttempts as $a): ?>
                    <tr class="border-t border-white/5">
                      <td class="py-1 pr-2"><?php echo (int) $a['attempt_id']; ?></td>
                      <td class="py-1 pr-2"><?php echo h((string) $a['engine']); ?></td>
                      <td class="py-1 pr-2"><?php echo h((string) ($a['confidence'] ?? '')); ?></td>
                      <td class="py-1 pr-2"><?php echo h((string) $a['decision']); ?></td>
                      <td class="py-1"><?php echo h((string) $a['created_at']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <div class="border-t border-white/10 pt-4 space-y-3">
          <div class="text-xs uppercase opacity-60 font-semibold">Grants / Access</div>
          <div class="text-sm">
            <a class="underline font-semibold" href="<?php echo h(ereview_url('admin_commerce_grants') . '?payment_id=' . (int) $detail['payment_id']); ?>">Open Grant Ledger for this payment</a>
            <?php if (!empty($detail['user_id'])): ?>
              · <a class="underline font-semibold" href="<?php echo h(ereview_url('admin_commerce_grants') . '?user_id=' . (int) $detail['user_id']); ?>">Ledger by student</a>
            <?php endif; ?>
          </div>
          <?php if ($detailGrants === []): ?>
            <p class="text-sm opacity-70">No purchase access grants linked to this payment.</p>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full text-xs">
                <thead>
                  <tr class="opacity-60 text-left">
                    <th class="py-1 pr-2">Grant</th>
                    <th class="py-1 pr-2">Content</th>
                    <th class="py-1 pr-2">Source</th>
                    <th class="py-1 pr-2">Status</th>
                    <th class="py-1 pr-2">Starts</th>
                    <th class="py-1 pr-2">Ends</th>
                    <th class="py-1 pr-2">Revoked</th>
                    <th class="py-1">Reason</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($detailGrants as $g): ?>
                    <tr class="border-t border-white/5 align-top">
                      <td class="py-1 pr-2">#<?php echo (int) $g['grant_id']; ?></td>
                      <td class="py-1 pr-2"><?php echo h((string) ($g['content_label'] ?: ($g['content_type'] . ':' . $g['content_id']))); ?></td>
                      <td class="py-1 pr-2"><?php echo h((string) $g['source']); ?></td>
                      <td class="py-1 pr-2 font-semibold"><?php echo h((string) $g['status']); ?></td>
                      <td class="py-1 pr-2 whitespace-nowrap"><?php echo h((string) $g['starts_at']); ?></td>
                      <td class="py-1 pr-2 whitespace-nowrap"><?php echo h((string) $g['ends_at']); ?></td>
                      <td class="py-1 pr-2 whitespace-nowrap"><?php echo h((string) ($g['revoked_at'] ?? '—')); ?></td>
                      <td class="py-1 max-w-xs"><?php echo h((string) ($g['revoke_reason'] ?? '—')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if ($detailActivePurchaseGrants > 0): ?>
            <form method="post" class="space-y-3 max-w-xl">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="payment_id" value="<?php echo (int) $detail['payment_id']; ?>">
              <label class="block text-xs font-semibold uppercase opacity-70" for="revoke_reason">Revoke reason <span class="text-rose-300">required</span></label>
              <textarea class="input-custom w-full" name="revoke_reason" id="revoke_reason" rows="2" maxlength="255" required placeholder="Why is paid access being revoked?"></textarea>
              <button type="submit" name="action" value="revoke_access"
                      class="admin-outline-btn px-4 py-2.5 rounded-xl font-semibold"
                      onclick="return confirm('Revoke paid LMS access for this payment? Payment history will be kept; Free Access grants are not affected.');">
                Revoke Access
              </button>
              <p class="text-xs opacity-60">Revokes active purchase grants for this payment only. Payment stays paid. SCA is reconciled so overlapping purchase or Free Access coverage is preserved.</p>
            </form>
          <?php elseif ($detailGrants !== []): ?>
            <p class="text-sm opacity-70">No active purchase grants remain to revoke.</p>
          <?php endif; ?>
        </div>

        <?php if ($canReview): ?>
          <form method="post" class="border-t border-white/10 pt-4 space-y-3">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="payment_id" value="<?php echo (int) $detail['payment_id']; ?>">
            <label class="block text-xs font-semibold uppercase opacity-70">Review note (optional)</label>
            <textarea class="input-custom w-full" name="review_note" rows="2" maxlength="2000" placeholder="Why approve or reject…"></textarea>
            <div class="flex flex-wrap gap-2">
              <button type="submit" name="action" value="approve" class="admin-btn admin-btn--primary px-4 py-2.5">Approve</button>
              <button type="submit" name="action" value="reject" class="admin-btn admin-btn--secondary px-4 py-2.5" onclick="return confirm('Reject this payment? No LMS access will be granted.');">Reject</button>
            </div>
            <p class="text-xs opacity-60">Approve sets manually_approved + paid, then runs the same fulfillment as auto_verified (grants, SCA, auto login activation). Use this when OCR failed but the receipt is valid. Reject sets manually_rejected + rejected with no grants/SCA.</p>
          </form>
        <?php elseif ((string) ($detail['status'] ?? '') === 'pending_verification' && empty($detail['proof_path'])): ?>
          <p class="text-sm opacity-70 border-t border-white/10 pt-4">Manual Approve is unavailable until proof is uploaded.</p>
        <?php elseif (in_array((string) ($detail['verification_status'] ?? ''), ['manually_rejected'], true)): ?>
          <p class="text-sm opacity-70 border-t border-white/10 pt-4">This payment was manually rejected. Create a new checkout if the student needs to pay again.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php
      $reviewableCount = 0;
      foreach ($rows as $rr) {
          if (commerce_payment_is_manual_reviewable($rr)) {
              $reviewableCount++;
          }
      }
    ?>
    <form method="post" id="paymentsBulkForm" class="quiz-admin-table-shell rounded-2xl overflow-hidden">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="return_filter" value="<?php echo h($filter); ?>">
      <input type="hidden" name="action" id="paymentsBulkAction" value="bulk_approve">
      <?php if ($reviewableCount > 0): ?>
        <div class="payments-bulk-toolbar flex flex-wrap items-center gap-3 px-4 py-3 border-b border-white/10 bg-white/5">
          <span class="text-sm font-semibold">Bulk review</span>
          <span class="text-xs opacity-70" id="paymentsSelectedCount"><?php echo (int) $reviewableCount; ?> reviewable on page · max 50</span>
          <div class="flex-1 min-w-[8rem]"></div>
          <input type="text" name="review_note" class="input-custom text-sm w-full sm:w-64" maxlength="2000" placeholder="Optional shared note">
          <button type="submit" class="admin-btn admin-btn--primary px-3 py-2 text-sm"
                  onclick="return paymentsBulkSubmit('bulk_approve');">
            Bulk Approve
          </button>
          <button type="submit" class="admin-btn admin-btn--secondary px-3 py-2 text-sm"
                  onclick="return paymentsBulkSubmit('bulk_reject');">
            Bulk Reject
          </button>
        </div>
      <?php else: ?>
        <div class="px-4 py-3 border-b border-white/10 text-xs opacity-70">
          No reviewable payments on this filter. Checkboxes appear only for Needs Review / OCR Failed (with proof) while still pending verification.
        </div>
      <?php endif; ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm admin-bulk-table">
          <thead>
            <tr class="text-left text-xs uppercase opacity-60 border-b border-white/10">
              <th class="px-3 py-3 w-12">
                <?php if ($reviewableCount > 0): ?>
                  <input type="checkbox" id="paymentsSelectAll" class="admin-bulk-check"
                         title="Select all reviewable on this page"
                         aria-label="Select all reviewable payments on this page">
                <?php endif; ?>
              </th>
              <th class="px-3 py-3">Payment</th>
              <th class="px-3 py-3">User</th>
              <th class="px-3 py-3">Type</th>
              <th class="px-3 py-3">Amount</th>
              <th class="px-3 py-3">Verification</th>
              <th class="px-3 py-3">Summary</th>
              <th class="px-3 py-3">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rows === []): ?>
              <tr><td colspan="8" class="px-3 py-8 text-center opacity-60">No payments match this filter.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php $rowReviewable = commerce_payment_is_manual_reviewable($r); ?>
                <tr class="border-b border-white/5 align-top<?php echo $rowReviewable ? ' is-selectable' : ''; ?>">
                  <td class="px-3 py-3">
                    <?php if ($rowReviewable): ?>
                      <input type="checkbox" class="js-payment-select admin-bulk-check" name="payment_ids[]" value="<?php echo (int) $r['payment_id']; ?>" aria-label="Select payment <?php echo (int) $r['payment_id']; ?>">
                    <?php else: ?>
                      <span class="admin-bulk-check-na" title="Not reviewable">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-3">
                    <div class="font-semibold"><?php echo h((string) $r['payment_ref']); ?></div>
                    <div class="text-xs opacity-60"><?php echo h((string) $r['status']); ?>
                      · fulfilled <?php echo !empty($r['fulfilled_at']) ? h((string) $r['fulfilled_at']) : 'NULL'; ?></div>
                  </td>
                  <td class="px-3 py-3">
                    <div><?php echo h((string) ($r['full_name'] ?? '')); ?></div>
                    <div class="text-xs opacity-60"><?php echo h((string) ($r['email'] ?? '')); ?></div>
                  </td>
                  <td class="px-3 py-3">
                    <?php
                      $ptype = (string) ($r['purchase_type'] ?? '');
                      $topicLabs = ($ptype === 'by_topic')
                          ? ($paymentTopicLabelsById[(int) $r['payment_id']] ?? [])
                          : [];
                      $topicFull = $topicLabs !== [] ? implode(', ', $topicLabs) : '';
                      $topicShort = $topicLabs !== [] ? commerce_admin_format_topics_short($topicLabs, 2) : '';
                    ?>
                    <div class="font-semibold"><?php echo h($ptype); ?></div>
                    <?php if ($topicShort !== ''): ?>
                      <div class="text-xs opacity-70 mt-0.5 max-w-[14rem] leading-snug" title="<?php echo h($topicFull); ?>">
                        <?php echo h($topicShort); ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-3">₱<?php echo h(commerce_centavos_to_pesos_display((int) $r['expected_amount_centavos'])); ?></td>
                  <td class="px-3 py-3">
                    <div class="font-semibold"><?php echo h(commerce_admin_vstatus_label((string) $r['verification_status'])); ?></div>
                    <?php if ($r['verification_confidence'] !== null && $r['verification_confidence'] !== ''): ?>
                      <div class="text-xs opacity-70">conf <?php echo h((string) $r['verification_confidence']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-3 text-xs max-w-xs"><?php echo h((string) ($r['verification_summary'] ?? '')); ?></td>
                  <td class="px-3 py-3 whitespace-nowrap">
                    <div class="payments-row-actions">
                      <a class="payments-action-link" href="<?php echo h(ereview_url('admin_commerce_payments') . '?id=' . (int) $r['payment_id'] . ($filter !== 'all' ? '&v=' . rawurlencode($filter) : '')); ?>">Open</a>
                      <?php if ($rowReviewable): ?>
                        <?php if (!empty($r['proof_path'])): ?>
                          <a class="payments-action-link" data-admin-proof
                             data-proof-title="Proof · <?php echo h((string) $r['payment_ref']); ?>"
                             href="<?php echo h(ereview_url('payment_proof_file') . '?payment_id=' . (int) $r['payment_id']); ?>">Proof</a>
                        <?php endif; ?>
                        <button type="submit"
                                class="admin-btn admin-btn--primary"
                                onclick="return paymentsQuickApprove(<?php echo (int) $r['payment_id']; ?>);">
                          Approve
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </form>
    <p class="text-xs opacity-60 mt-3 mb-0">
      Happy path after Approve: paid → fulfill → access grant → SCA → auto login activation.
      Bulk Approve uses the same path per payment (not a shortcut). Check proof first when unsure.
    </p>
  </div>
</div>
</main>
<script>
(function () {
  var form = document.getElementById('paymentsBulkForm');
  if (!form) return;
  var selectAll = document.getElementById('paymentsSelectAll');
  var actionInput = document.getElementById('paymentsBulkAction');
  var countEl = document.getElementById('paymentsSelectedCount');
  var reviewableTotal = form.querySelectorAll('.js-payment-select').length;

  function allBoxes() {
    return Array.prototype.slice.call(form.querySelectorAll('.js-payment-select'));
  }
  function selectedBoxes() {
    return Array.prototype.slice.call(form.querySelectorAll('.js-payment-select:checked'));
  }
  function syncSelectAll() {
    var all = allBoxes();
    var selected = selectedBoxes();
    if (countEl) {
      countEl.textContent = selected.length + ' selected · ' + reviewableTotal + ' reviewable on page · max 50';
    }
    if (!selectAll) return;
    selectAll.disabled = all.length === 0;
    selectAll.checked = all.length > 0 && selected.length === all.length;
    selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      var on = !!selectAll.checked;
      allBoxes().forEach(function (cb) { cb.checked = on; });
      selectAll.indeterminate = false;
      syncSelectAll();
    });
  }
  allBoxes().forEach(function (cb) {
    cb.addEventListener('change', syncSelectAll);
  });
  syncSelectAll();

  window.paymentsBulkSubmit = function (action) {
    var boxes = selectedBoxes();
    if (boxes.length === 0) {
      alert('Select at least one reviewable payment (checkbox column). Already verified/rejected rows cannot be selected.');
      return false;
    }
    if (boxes.length > 50) {
      alert('Bulk limit is 50 payments. Uncheck some rows.');
      return false;
    }
    var msg = action === 'bulk_reject'
      ? ('Reject ' + boxes.length + ' payment(s)? No LMS access will be granted.')
      : ('Approve ' + boxes.length + ' payment(s)? Each will be fulfilled and login activated when possible.');
    if (!confirm(msg)) return false;
    if (actionInput) actionInput.value = action;
    return true;
  };
  window.paymentsQuickApprove = function (paymentId) {
    if (!confirm('Approve payment #' + paymentId + '? This fulfills access and activates login when possible.')) {
      return false;
    }
    allBoxes().forEach(function (cb) { cb.checked = false; });
    form.querySelectorAll('input[name="payment_id"]').forEach(function (el) { el.remove(); });
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'payment_id';
    hidden.value = String(paymentId);
    form.appendChild(hidden);
    if (actionInput) actionInput.value = 'approve';
    syncSelectAll();
    return true;
  };
})();
</script>
<?php include __DIR__ . '/includes/components/admin_proof_modal.php'; ?>
</body>
</html>
