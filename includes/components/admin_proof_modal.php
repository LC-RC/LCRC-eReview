<?php
/**
 * Shared admin payment-proof viewer (modal).
 * Include once near end of admin pages that link to payment_proof_file.
 * Triggers: any element with [data-admin-proof] and href or data-proof-url.
 */
if (!empty($GLOBALS['admin_proof_modal_rendered'])) {
    return;
}
$GLOBALS['admin_proof_modal_rendered'] = true;

require_once __DIR__ . '/../url_helpers.php';
$proofModalJs = __DIR__ . '/../../assets/js/admin-proof-modal.js';
$proofModalJsUrl = ereview_url('assets/js/admin-proof-modal.js')
    . (is_file($proofModalJs) ? ('?v=' . filemtime($proofModalJs)) : '');
?>
<div id="adminProofModal" class="admin-proof-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="adminProofTitle">
  <button type="button" class="admin-proof-modal__backdrop" data-admin-proof-close aria-label="Close proof viewer"></button>
  <div class="admin-proof-modal__panel">
    <div class="admin-proof-modal__head">
      <h2 id="adminProofTitle" class="admin-proof-modal__title">Payment proof</h2>
      <div class="admin-proof-modal__actions">
        <a id="adminProofOpenTab" class="admin-proof-modal__link" href="#" target="_blank" rel="noopener">Open in new tab</a>
        <button type="button" class="admin-proof-modal__close" data-admin-proof-close aria-label="Close">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </div>
    </div>
    <div class="admin-proof-modal__body">
      <p id="adminProofLoading" class="admin-proof-modal__loading">Loading proof…</p>
      <p id="adminProofError" class="admin-proof-modal__error" hidden>Could not display this proof. Use <strong>Open in new tab</strong>, or re-upload if the file is missing.</p>
      <img id="adminProofImg" class="admin-proof-modal__img" alt="Payment proof" hidden>
      <iframe id="adminProofFrame" class="admin-proof-modal__frame" title="Payment proof" hidden></iframe>
    </div>
  </div>
</div>
<script src="<?php echo h($proofModalJsUrl); ?>" defer></script>
