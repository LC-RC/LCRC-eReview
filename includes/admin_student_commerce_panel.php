<?php
/**
 * Enrollment & Commerce panel for admin_student_view.php
 * Expects: $commerce, $user, $isPaidPath, $isFreeAccess, $isCommerceEnrollment,
 *          $latestPayment, $legacyHasPaymentProof, $legacyPaymentProofUrl
 */
if (!isset($commerce) || !is_array($commerce)) {
    return;
}
?>
      <div class="rounded-xl shadow-card border p-5 page-table mt-5" id="enrollment-commerce-section">
        <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2"><i class="bi bi-bag-check"></i> Enrollment &amp; Commerce</h2>
        <?php
          $commerceAccessTone = (string) ($commerce['commerce_access']['tone'] ?? 'none');
          $accountPending = strtolower((string) ($user['status'] ?? '')) === 'pending';
          $activationRequired = $accountPending && $commerceAccessTone === 'active';
          $accountLabel = (string) ($commerce['account_label'] ?? commerce_admin_label_account_status((string) ($user['status'] ?? '')));
        ?>
        <?php if ($activationRequired): ?>
          <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 mb-4 text-sm text-amber-950">
            <div class="font-bold flex items-center gap-2"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Account: Pending Activation</div>
            <p class="mt-1 mb-0 text-xs">Commerce access is <strong>Granted</strong>, but login is still pending. Use <strong>Repair Activation</strong> only if auto-activation failed after fulfillment.</p>
          </div>
        <?php elseif (strtolower((string) ($user['status'] ?? '')) === 'approved' && $commerceAccessTone === 'active'): ?>
          <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-3 mb-4 text-sm text-emerald-950">
            <div class="font-bold flex items-center gap-2"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Account: Active</div>
            <p class="mt-1 mb-0 text-xs">Login is active. Commerce access is granted. Manual Approve is not required for this enrollment.</p>
          </div>
        <?php endif; ?>

        <div class="text-xs uppercase font-semibold text-gray-500 mb-2">Enrollment</div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm mb-4">
          <div>
            <div class="text-gray-500">Type</div>
            <div class="font-semibold text-gray-800"><?php echo h((string) $commerce['enrollment_label']); ?></div>
          </div>
          <?php if (!empty($isPaidPath)): ?>
            <div>
              <div class="text-gray-500">Package</div>
              <div class="font-semibold text-gray-800"><?php echo h($commerce['package_name'] !== '' ? (string) $commerce['package_name'] : '—'); ?></div>
            </div>
            <?php if (($commerce['enrollment_path'] ?? '') === 'by_topic'): ?>
              <div class="md:col-span-2">
                <div class="text-gray-500">Selected topics</div>
                <div class="font-semibold text-gray-800"><?php echo !empty($commerce['lesson_labels']) ? h(implode(', ', $commerce['lesson_labels'])) : '—'; ?></div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if (!empty($isFreeAccess)): ?>
          <div class="rounded-lg border border-emerald-200 bg-emerald-50/80 p-4 mb-4 text-sm">
            <div class="font-bold text-emerald-900 mb-2">Free Access</div>
            <div class="mb-1">Payment: <strong>N/A</strong> · Proof: <strong>N/A</strong></div>
            <?php if (!empty($commerce['far'])): ?>
              <?php $far = $commerce['far']; ?>
              <div>Request ID: <strong>#<?php echo (int) $far['request_id']; ?></strong>
                <?php if (($far['request_ref'] ?? '') !== ''): ?> (<?php echo h($far['request_ref']); ?>)<?php endif; ?>
              </div>
              <div>FAR status: <strong><?php echo h(ucfirst((string) $far['status'])); ?></strong></div>
              <?php if (!empty($commerce['active_far_grant'])): ?>
                <?php $fg = $commerce['active_far_grant']; ?>
                <div class="mt-2">Access: <strong>Granted</strong></div>
                <div>Grant start: <?php echo h((string) $fg['starts_at']); ?> · Grant end: <?php echo h((string) $fg['ends_at']); ?></div>
              <?php else: ?>
                <div class="mt-2">Access: <strong><?php echo h((string) ($commerce['commerce_access']['label'] ?? 'None')); ?></strong></div>
              <?php endif; ?>
              <div class="mt-1">Account: <strong><?php echo h($accountLabel); ?></strong></div>
              <div class="mt-3 flex flex-wrap gap-3">
                <a class="font-semibold underline text-sky-700" href="<?php echo h(ereview_url('admin_commerce_free_access') . '?id=' . (int) $far['request_id']); ?>">View Free Access Request</a>
                <a class="font-semibold underline text-sky-700" href="<?php echo h(ereview_url('admin_commerce_grants') . '?user_id=' . (int) $user['user_id']); ?>">View Grant Ledger</a>
              </div>
            <?php else: ?>
              <div>No Free Access request found for this student.</div>
            <?php endif; ?>
            <p class="text-xs text-emerald-800 mt-2 mb-0">Free Access is not a payment and does not use GCash proof.</p>
          </div>
        <?php endif; ?>

        <?php if (!empty($isPaidPath)): ?>
          <?php if (!empty($latestPayment)): ?>
            <div class="text-xs uppercase font-semibold text-gray-500 mb-2">Payment</div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 mb-4 text-sm space-y-1">
              <div>Payment ID: <strong>#<?php echo (int) $latestPayment['payment_id']; ?></strong> · <?php echo h($latestPayment['payment_ref']); ?></div>
              <div>Amount: ₱<?php echo h(commerce_centavos_to_pesos_display((int) $latestPayment['amount_centavos'])); ?></div>
              <div>Payment status: <strong><?php echo h(commerce_admin_label_payment_status($latestPayment['status'])); ?></strong></div>
              <div>Verification: <strong><?php echo h(commerce_admin_label_verification_status($latestPayment['verification_status'])); ?></strong></div>
              <div>GCash reference: <?php echo h(($latestPayment['gcash_reference'] ?? '') !== '' ? $latestPayment['gcash_reference'] : '—'); ?></div>
              <div>Fulfillment: <strong><?php echo !empty($latestPayment['fulfilled']) ? 'Fulfilled' : 'Not fulfilled'; ?></strong>
                <?php if (!empty($latestPayment['fulfilled_at'])): ?> (<?php echo h((string) $latestPayment['fulfilled_at']); ?>)<?php endif; ?>
              </div>
            </div>

            <div class="text-xs uppercase font-semibold text-gray-500 mb-2">Proof</div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 mb-4 text-sm">
              <?php
                $proofUploaded = !empty($latestPayment['has_proof']);
                $vTone = (string) ($latestPayment['verification_status'] ?? '');
                $proofVerifyLabel = '—';
                if (!$proofUploaded) {
                    $proofVerifyLabel = 'Not Uploaded';
                } elseif (in_array($vTone, ['auto_verified', 'manually_approved'], true)) {
                    $proofVerifyLabel = 'Verified';
                } elseif (in_array($vTone, ['manually_rejected', 'failed'], true)) {
                    $proofVerifyLabel = 'Rejected';
                } elseif ($vTone === 'needs_review') {
                    $proofVerifyLabel = 'Needs Review';
                } elseif ((string) ($latestPayment['status'] ?? '') === 'pending_verification') {
                    $proofVerifyLabel = 'Pending Verification';
                } else {
                    $proofVerifyLabel = 'Uploaded';
                }
              ?>
              <?php if ($proofUploaded): ?>
                <div><strong>Uploaded</strong> · <?php echo h($proofVerifyLabel); ?></div>
                <a class="font-semibold underline text-sky-700" data-admin-proof
                   data-proof-title="Payment proof"
                   href="<?php echo h(ereview_url('payment_proof_file') . '?payment_id=' . (int) $latestPayment['payment_id']); ?>">View Proof</a>
              <?php else: ?>
                <strong>Not Uploaded</strong>
              <?php endif; ?>
            </div>

            <div class="text-xs uppercase font-semibold text-gray-500 mb-2">Access</div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 mb-4 text-sm space-y-1">
              <?php if (!empty($commerce['active_purchase_grant'])): ?>
                <?php $pg = $commerce['active_purchase_grant']; ?>
                <div>Status: <strong>Granted</strong></div>
                <div>Grant start: <?php echo h((string) $pg['starts_at']); ?></div>
                <div>Grant end: <?php echo h((string) $pg['ends_at']); ?></div>
              <?php else: ?>
                <div>Status: <strong><?php echo h((string) ($commerce['commerce_access']['label'] ?? 'None')); ?></strong></div>
                <div class="text-xs text-gray-500">No active purchase grant window to display.</div>
              <?php endif; ?>
              <div class="mt-2 flex flex-wrap gap-3">
                <a class="font-semibold underline text-sky-700" href="<?php echo h(ereview_url('admin_commerce_payments') . '?id=' . (int) $latestPayment['payment_id']); ?>">View Payment</a>
                <a class="font-semibold underline text-sky-700" href="<?php echo h(ereview_url('admin_commerce_grants') . '?user_id=' . (int) $user['user_id']); ?>">View Commerce Grants</a>
              </div>
            </div>

            <div class="text-xs uppercase font-semibold text-gray-500 mb-2">Account</div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 mb-4 text-sm">
              <div>Login / account: <strong><?php echo h($accountLabel); ?></strong></div>
              <?php if ($activationRequired): ?>
                <p class="text-xs text-amber-800 mt-1 mb-0">Payment may be fulfilled with access granted — login activation requires repair.</p>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm mb-4">No payment record yet for this paid enrollment. Payment: Awaiting Payment · Proof: Not Uploaded · Access: None.</div>
          <?php endif; ?>

          <?php if (!empty($commerce['payments']) && count($commerce['payments']) > 1): ?>
            <div class="mb-4">
              <div class="text-xs uppercase font-semibold text-gray-500 mb-2">All payments</div>
              <div class="overflow-x-auto">
                <table class="w-full text-xs">
                  <thead>
                    <tr class="text-left text-gray-500">
                      <th class="py-1 pr-2">ID</th>
                      <th class="py-1 pr-2">Date</th>
                      <th class="py-1 pr-2">Type</th>
                      <th class="py-1 pr-2">Amount</th>
                      <th class="py-1 pr-2">Status</th>
                      <th class="py-1 pr-2">Verification</th>
                      <th class="py-1 pr-2">Fulfilled</th>
                      <th class="py-1">View</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($commerce['payments'] as $p): ?>
                      <tr class="border-t border-slate-100">
                        <td class="py-1 pr-2">#<?php echo (int) $p['payment_id']; ?></td>
                        <td class="py-1 pr-2 whitespace-nowrap"><?php echo h((string) $p['created_at']); ?></td>
                        <td class="py-1 pr-2"><?php echo h((string) $p['purchase_type']); ?></td>
                        <td class="py-1 pr-2">₱<?php echo h(commerce_centavos_to_pesos_display((int) $p['amount_centavos'])); ?></td>
                        <td class="py-1 pr-2"><?php echo h(commerce_admin_label_payment_status((string) $p['status'])); ?></td>
                        <td class="py-1 pr-2"><?php echo h(commerce_admin_label_verification_status((string) $p['verification_status'])); ?></td>
                        <td class="py-1 pr-2"><?php echo !empty($p['fulfilled']) ? 'Yes' : 'No'; ?></td>
                        <td class="py-1"><a class="underline font-semibold text-sky-700" href="<?php echo h(ereview_url('admin_commerce_payments') . '?id=' . (int) $p['payment_id']); ?>">Open</a></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <div class="text-xs uppercase font-semibold text-gray-500 mb-2 mt-2">Account window (login)</div>
        <div class="rounded-lg border border-dashed border-slate-300 p-3 text-sm mb-2">
          <div class="text-gray-500 text-xs mb-1">Legacy login account window — not the same as commerce grant dates</div>
          <div class="font-semibold text-gray-800">
            <?php
              $as = !empty($user['access_start']) ? (string) $user['access_start'] : '—';
              $ae = !empty($user['access_end']) ? (string) $user['access_end'] : '—';
              echo h($as . ' → ' . $ae);
            ?>
          </div>
        </div>

        <?php if (empty($isCommerceEnrollment) && !empty($legacyHasPaymentProof)): ?>
          <div class="text-xs text-gray-500 mb-2">Legacy registration proof (pre-commerce):</div>
          <a class="text-sm font-semibold underline text-sky-700" href="<?php echo h($legacyPaymentProofUrl); ?>" target="_blank" rel="noopener">View legacy proof</a>
        <?php endif; ?>
      </div>
