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
        <?php elseif ($commerceAccessTone !== 'active' && strtolower((string) ($user['status'] ?? '')) !== 'rejected'): ?>
          <div class="rounded-lg border border-sky-300 bg-sky-50 p-3 mb-4 text-sm text-sky-950">
            <div class="font-bold flex items-center gap-2"><i class="bi bi-key" aria-hidden="true"></i> Access: None</div>
            <p class="mt-1 mb-2 text-xs">Prefer <strong>Remind to upload</strong> when proof is missing. Quick Grant here is Full LMS (6 months). For by-topic, use Students → Grant Access.</p>
            <?php
              $panelLatest = is_array($commerce['latest_payment'] ?? null)
                  ? $commerce['latest_payment']
                  : (isset($latestPayment) && is_array($latestPayment) ? $latestPayment : []);
              $panelPayStatus = (string) ($panelLatest['status'] ?? '');
              $panelHasProof = trim((string) ($panelLatest['proof_path'] ?? '')) !== '';
              $panelPaymentId = (int) ($panelLatest['payment_id'] ?? 0);
              $panelNeedsRemind = $panelPaymentId > 0 && !$panelHasProof && $panelPayStatus === 'awaiting_proof';
            ?>
            <?php if (!empty($csrf) && $panelNeedsRemind): ?>
              <form method="post" action="<?php echo h(function_exists('ereview_url') ? ereview_url('admin_remind_upload_proof') : 'admin_remind_upload_proof'); ?>" class="flex flex-wrap items-end gap-2 mb-2"
                    data-admin-confirm-title="Remind to upload proof"
                    data-admin-confirm="Email this student a secure link to upload GCash proof? The link is valid for 7 days."
                    data-admin-confirm-ok="Send reminder"
                    data-admin-confirm-icon="<i class=&quot;bi bi-envelope&quot;></i>">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="user_id" value="<?php echo (int) ($user['user_id'] ?? 0); ?>">
                <input type="hidden" name="payment_id" value="<?php echo $panelPaymentId; ?>">
                <input type="hidden" name="return_to" value="admin_student_view?id=<?php echo (int) ($user['user_id'] ?? 0); ?>">
                <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-envelope"></i> Remind to upload</button>
              </form>
            <?php endif; ?>
            <?php if (!empty($csrf)): ?>
              <form method="post" action="<?php echo h(function_exists('ereview_url') ? ereview_url('admin_grant_access') : 'admin_grant_access'); ?>" class="flex flex-wrap items-end gap-2 js-panel-grant-form"
                    data-admin-confirm-title="Grant Access"
                    data-admin-confirm="Grant Full LMS access for the selected duration?"
                    data-admin-confirm-ok="Confirm grant"
                    data-admin-confirm-icon="<i class=&quot;bi bi-key&quot;></i>"
                    <?php if ($panelNeedsRemind): ?>data-needs-proof-override="1"<?php endif; ?>>
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                <input type="hidden" name="user_id" value="<?php echo (int) ($user['user_id'] ?? 0); ?>">
                <input type="hidden" name="activate_login" value="1">
                <input type="hidden" name="return_to" value="admin_student_view?id=<?php echo (int) ($user['user_id'] ?? 0); ?>">
                <label class="text-xs font-semibold">
                  Months
                  <input type="number" name="months" min="1" max="120" value="6" class="input-custom w-20 mt-0.5" required>
                </label>
                <?php if ($panelNeedsRemind): ?>
                  <label class="text-xs flex items-center gap-1">
                    <input type="checkbox" name="close_awaiting_without_proof" value="1" data-required="1">
                    Grant without proof (emergency)
                  </label>
                <?php endif; ?>
                <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-key"></i> Grant Access</button>
              </form>
              <script>
              (function () {
                var form = document.currentScript && document.currentScript.previousElementSibling;
                if (!form || !form.classList.contains('js-panel-grant-form')) {
                  form = document.querySelector('.js-panel-grant-form');
                }
                if (!form) return;
                form.addEventListener('submit', function (e) {
                  if (form.getAttribute('data-needs-proof-override') !== '1') return;
                  if (form.getAttribute('data-admin-confirm-accepted') === '1') return;
                  var np = form.querySelector('[name=close_awaiting_without_proof]');
                  if (np && np.type === 'checkbox' && !np.checked && np.dataset.required === '1') {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    if (window.adminUiDialog) {
                      window.adminUiDialog.notice({
                        type: 'info',
                        title: 'Proof still required',
                        message: 'Check Grant without proof (emergency), or use Remind to upload.'
                      });
                    }
                  }
                }, true);
              })();
              </script>
            <?php endif; ?>
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
              <div class="font-semibold text-gray-800"><?php echo h($commerce['package_name'] !== '' ? (string) $commerce['package_name'] : '-'); ?></div>
            </div>
            <?php if (($commerce['enrollment_path'] ?? '') === 'by_topic'): ?>
              <div class="md:col-span-2">
                <div class="text-gray-500">Selected topics (by subject)</div>
                <?php
                  $lessonGroups = is_array($commerce['lesson_groups'] ?? null) ? $commerce['lesson_groups'] : [];
                  $lessonItems = is_array($commerce['lesson_items'] ?? null) ? $commerce['lesson_items'] : [];
                  $lessonLabelsFallback = is_array($commerce['lesson_labels'] ?? null) ? $commerce['lesson_labels'] : [];
                ?>
                <?php if ($lessonGroups !== []): ?>
                  <div class="mt-2 space-y-3">
                    <?php foreach ($lessonGroups as $g): ?>
                      <div class="rounded-lg border border-slate-200 bg-slate-50/80 px-3 py-2">
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">
                          <?php echo h((string) ($g['subject_name'] ?? 'Subject')); ?>
                          <span class="font-semibold normal-case tracking-normal text-slate-400">
                            · <?php echo count($g['topics'] ?? []); ?> topic<?php echo count($g['topics'] ?? []) === 1 ? '' : 's'; ?>
                          </span>
                        </div>
                        <ul class="mb-0 space-y-1 list-none pl-0">
                          <?php foreach (($g['topics'] ?? []) as $li): ?>
                            <li class="font-semibold text-gray-800 text-sm">
                              <?php if (!empty($li['href'])): ?>
                                <a class="underline text-sky-700 hover:text-sky-900" href="<?php echo h((string) $li['href']); ?>" title="Open topic materials">
                                  <?php echo h((string) ($li['title'] ?? 'Topic')); ?>
                                </a>
                              <?php else: ?>
                                <?php echo h((string) ($li['title'] ?? 'Topic')); ?>
                              <?php endif; ?>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="mt-2 flex flex-wrap gap-3 text-xs">
                    <a class="font-semibold underline text-sky-700" href="<?php echo h(ereview_url('admin_commerce_topics')); ?>">By Topic pricing</a>
                    <a class="font-semibold underline text-sky-700" href="<?php echo h(ereview_url('admin_commerce_grants') . '?user_id=' . (int) ($user['user_id'] ?? 0)); ?>">Grant ledger</a>
                  </div>
                <?php elseif ($lessonItems !== []): ?>
                  <ul class="mt-1 mb-0 space-y-1 list-none pl-0">
                    <?php foreach ($lessonItems as $li): ?>
                      <li class="font-semibold text-gray-800"><?php echo h((string) ($li['title'] ?? 'Topic')); ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php elseif ($lessonLabelsFallback !== []): ?>
                  <div class="font-semibold text-gray-800"><?php echo h(implode(', ', $lessonLabelsFallback)); ?></div>
                <?php else: ?>
                  <div class="font-semibold text-gray-800">-</div>
                <?php endif; ?>
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
              <div>GCash reference: <?php echo h(($latestPayment['gcash_reference'] ?? '') !== '' ? $latestPayment['gcash_reference'] : '-'); ?></div>
              <div>Fulfillment: <strong><?php echo !empty($latestPayment['fulfilled']) ? 'Fulfilled' : 'Not fulfilled'; ?></strong>
                <?php if (!empty($latestPayment['fulfilled_at'])): ?> (<?php echo h((string) $latestPayment['fulfilled_at']); ?>)<?php endif; ?>
              </div>
            </div>

            <div class="text-xs uppercase font-semibold text-gray-500 mb-2">Proof</div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 mb-4 text-sm">
              <?php
                $proofUploaded = !empty($latestPayment['has_proof']);
                $vTone = (string) ($latestPayment['verification_status'] ?? '');
                $proofVerifyLabel = '-';
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
                <p class="text-xs text-amber-800 mt-1 mb-0">Payment may be fulfilled with access granted - login activation requires repair.</p>
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
          <div class="text-gray-500 text-xs mb-1">Legacy login account window - not the same as commerce grant dates</div>
          <div class="font-semibold text-gray-800">
            <?php
              $as = !empty($user['access_start']) ? (string) $user['access_start'] : '-';
              $ae = !empty($user['access_end']) ? (string) $user['access_end'] : '-';
              echo h($as . ' → ' . $ae);
            ?>
          </div>
        </div>

        <?php if (empty($isCommerceEnrollment) && !empty($legacyHasPaymentProof)): ?>
          <div class="text-xs text-gray-500 mb-2">Legacy registration proof (pre-commerce):</div>
          <a class="text-sm font-semibold underline text-sky-700" href="<?php echo h($legacyPaymentProofUrl); ?>" target="_blank" rel="noopener">View legacy proof</a>
        <?php endif; ?>
      </div>
