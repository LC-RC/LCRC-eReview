<?php
/**
 * Phase 5/6 - GCash checkout (order summary + QR + proof + reference).
 * Authorized only via short-lived post-verification checkout session.
 * After submit, Phase 6 may auto-verify the receipt. Does not grant LMS access.
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/commerce_payment.php';

$auth = commerce_require_checkout_session($conn);
$verifyDecision = '';
if (empty($auth['ok'])) {
    $pageTitle = 'Checkout unavailable';
    $errorMsg = $auth['error'] ?? 'Checkout session expired.';
    $successSubmitted = false;
    $payment = null;
    $items = [];
    $settings = commerce_get_payment_settings($conn);
    $csrf = generateCSRFToken();
} else {
    $payment = $auth['payment'];
    $items = commerce_get_payment_items($conn, (int) $payment['payment_id']);
    $settings = commerce_get_payment_settings($conn);
    $csrf = generateCSRFToken();
    $pageTitle = 'GCash Checkout';
    $errorMsg = (string) ($_SESSION['checkout_error'] ?? '');
    $successSubmitted = !empty($_SESSION['checkout_success']);
    $verifyDecision = (string) ($_SESSION['checkout_verify_decision'] ?? '');
    unset($_SESSION['checkout_error'], $_SESSION['checkout_success'], $_SESSION['checkout_verify_decision']);
    $vStatus = (string) ($payment['verification_status'] ?? '');
    $pStatus = (string) ($payment['status'] ?? '');
    if ($pStatus === 'pending_verification' && !empty($payment['proof_path'])) {
        $successSubmitted = true;
        if ($verifyDecision === '' && $vStatus !== '' && $vStatus !== 'not_started') {
            $verifyDecision = $vStatus === 'auto_verified' ? 'auto_verified' : $vStatus;
        }
    }
    if ($pStatus === 'paid' && $vStatus === 'auto_verified') {
        $successSubmitted = true;
        if ($verifyDecision === '') {
            $verifyDecision = 'auto_verified';
        }
    }
}

$qrSrc = !empty($settings['gcash_qr_path']) ? ereview_url((string) $settings['gcash_qr_path']) : '';
$amountDisplay = $payment
    ? '₱' . commerce_centavos_to_pesos_display((int) $payment['expected_amount_centavos'])
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo h($pageTitle); ?> - LCRC eReview</title>
  <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: 'Segoe UI', Tahoma, sans-serif;
      background: linear-gradient(160deg, #0b1220 0%, #13233f 45%, #0f172a 100%);
      color: #e5e7eb;
      min-height: 100vh;
      padding: 24px 16px;
    }
    .wrap { max-width: 720px; margin: 0 auto; }
    .brand { text-align: center; margin-bottom: 1.25rem; }
    .brand .lcrc { color: #fff; font-weight: 800; font-size: 1.25rem; }
    .brand .er { color: #F59E0B; font-weight: 800; font-size: 1.25rem; }
    .card {
      background: linear-gradient(180deg, #111827 0%, #0f172a 100%);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 1rem;
      padding: 1.5rem;
      margin-bottom: 1rem;
    }
    h1 { margin: 0 0 0.35rem; font-size: 1.35rem; color: #fff; }
    .sub { color: #94a3b8; font-size: 0.9rem; margin: 0 0 1.25rem; line-height: 1.5; }
    .alert {
      border-radius: 0.75rem;
      padding: 0.85rem 1rem;
      margin-bottom: 1rem;
      font-size: 0.9rem;
      line-height: 1.45;
    }
    .alert-error { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.35); color: #fecaca; }
    .alert-ok { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.35); color: #bbf7d0; }
    .alert-warn { background: rgba(245,158,11,0.15); border: 1px solid rgba(245,158,11,0.35); color: #fde68a; }
    .row { display: flex; justify-content: space-between; gap: 1rem; padding: 0.55rem 0; border-bottom: 1px solid rgba(255,255,255,0.06); font-size: 0.9rem; }
    .row:last-child { border-bottom: 0; }
    .muted { color: #94a3b8; }
    .total { font-size: 1.15rem; font-weight: 800; color: #F59E0B; }
    .gcash-box {
      display: grid;
      gap: 1rem;
      grid-template-columns: 1fr;
    }
    @media (min-width: 640px) {
      .gcash-box { grid-template-columns: 180px 1fr; align-items: start; }
    }
    .qr {
      width: 180px; height: 180px; object-fit: contain;
      background: #fff; border-radius: 0.75rem; padding: 8px;
    }
    .qr-missing {
      width: 180px; height: 180px; border-radius: 0.75rem;
      border: 1px dashed rgba(255,255,255,0.2);
      display: flex; align-items: center; justify-content: center;
      color: #94a3b8; font-size: 0.8rem; text-align: center; padding: 12px;
    }
    label { display: block; font-size: 0.8rem; color: #cbd5e1; margin-bottom: 0.35rem; font-weight: 600; }
    input[type="text"], input[type="file"] {
      width: 100%;
      border-radius: 0.75rem;
      border: 1px solid rgba(255,255,255,0.12);
      background: #0b1220;
      color: #fff;
      padding: 0.75rem 0.9rem;
      font-size: 0.9rem;
      margin-bottom: 1rem;
    }
    input[type="file"] { padding: 0.55rem; }
    .btn {
      display: inline-block;
      width: 100%;
      text-align: center;
      background: #1F58C3;
      color: #fff;
      border: 0;
      border-radius: 0.75rem;
      padding: 0.85rem 1.25rem;
      font-weight: 700;
      font-size: 0.95rem;
      cursor: pointer;
    }
    .btn:hover { background: #1E40AF; }
    .btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .note { font-size: 0.8rem; color: #94a3b8; margin-top: 0.75rem; line-height: 1.45; }
    .pill {
      display: inline-block;
      font-size: 0.75rem;
      padding: 0.2rem 0.55rem;
      border-radius: 999px;
      background: rgba(31,88,195,0.25);
      color: #93c5fd;
      margin-bottom: 0.75rem;
    }
    .steps { list-style: none; margin: 0 0 1rem; padding: 0; display: flex; flex-wrap: wrap; gap: 0.45rem 0.65rem; }
    .steps li { font-size: 0.72rem; font-weight: 600; color: #64748b; display: inline-flex; align-items: center; gap: 0.3rem; }
    .steps li.done { color: #86efac; }
    .steps li.current { color: #93c5fd; }
    .steps .n { width: 1.15rem; height: 1.15rem; border-radius: 999px; background: #334155; color: #e2e8f0; display: inline-flex; align-items: center; justify-content: center; font-size: 0.62rem; font-weight: 800; }
    .steps li.done .n { background: #15803d; color: #fff; }
    .steps li.current .n { background: #1F58C3; color: #fff; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="brand"><span class="lcrc">LCRC</span> <span class="er">eReview</span></div>

    <?php if (!$payment): ?>
      <div class="card">
        <h1>Checkout unavailable</h1>
        <p class="sub"><?php echo h($errorMsg); ?></p>
        <a class="btn" href="<?php echo h(ereview_url('payment_checkout_resume')); ?>">Continue Payment</a>
        <p class="note" style="text-align:center;margin-top:0.75rem;"><a href="<?php echo h(ereview_url('login')); ?>" style="color:#93c5fd;">Go to sign in</a></p>
      </div>
    <?php else: ?>
      <div class="card">
        <ol class="steps" aria-label="Enrollment steps">
          <li class="done"><span class="n">1</span> Create Account</li>
          <li class="done"><span class="n">2</span> Verify Email</li>
          <li class="current"><span class="n">3</span> Payment</li>
          <li><span class="n">4</span> Access</li>
        </ol>
        <span class="pill">Payment ref <?php echo h((string) $payment['payment_ref']); ?></span>
        <h1>Complete GCash payment</h1>
        <p class="sub">Pay the amount below via GCash, then submit your reference number and proof. Receipt verification runs after you submit. LMS access is granted only after fulfillment (a later step) - this page never activates your account.</p>

        <?php if ($errorMsg !== ''): ?>
          <div class="alert alert-error" role="alert"><?php echo h($errorMsg); ?></div>
        <?php endif; ?>
        <?php if ($successSubmitted): ?>
          <?php if ($verifyDecision === 'auto_verified'): ?>
            <div class="alert alert-ok" role="status">Payment received and receipt verified. Your account remains pending until access is fulfilled - you will be notified when LMS access is ready.</div>
          <?php elseif ($verifyDecision === 'failed'): ?>
            <div class="alert alert-warn" role="status">Payment submitted. We could not read the receipt automatically; an administrator will review it. Your submission was saved.</div>
          <?php elseif ($verifyDecision === 'needs_review' || $verifyDecision === 'processing'): ?>
            <div class="alert alert-warn" role="status">Payment submitted and queued for review. Your submission was saved - access is not granted yet.</div>
          <?php else: ?>
            <div class="alert alert-ok" role="status">Payment submitted successfully and is pending verification.</div>
          <?php endif; ?>
        <?php endif; ?>

        <h2 style="font-size:1rem;margin:0 0 0.5rem;color:#fff;">Order summary</h2>
        <?php foreach ($items as $it): ?>
          <div class="row">
            <div>
              <div><?php echo h((string) $it['item_name']); ?></div>
              <div class="muted" style="font-size:0.8rem;">
                <?php
                  $bits = [];
                  if (!empty($it['subject_name'])) {
                      $bits[] = (string) $it['subject_name'];
                  }
                  $bits[] = (int) $it['duration_value'] . ' ' . (string) $it['duration_unit'] . ((int) $it['duration_value'] === 1 ? '' : 's');
                  if (!empty($it['package_access_scope'])) {
                      $bits[] = $it['package_access_scope'] === 'full_lms' ? 'Full LMS' : 'Mapped content';
                  }
                  echo h(implode(' · ', $bits));
                ?>
              </div>
            </div>
            <div>₱<?php echo h(commerce_centavos_to_pesos_display((int) $it['line_total_centavos'])); ?></div>
          </div>
        <?php endforeach; ?>
        <div class="row">
          <div class="muted">Total</div>
          <div class="total"><?php echo h($amountDisplay); ?></div>
        </div>
      </div>

      <div class="card">
        <h2 style="font-size:1rem;margin:0 0 0.75rem;color:#fff;">Pay with GCash</h2>
        <div class="gcash-box">
          <?php if ($qrSrc !== ''): ?>
            <img class="qr" src="<?php echo h($qrSrc); ?>" alt="GCash QR code">
          <?php else: ?>
            <div class="qr-missing">GCash QR not configured yet. Use the account details provided.</div>
          <?php endif; ?>
          <div>
            <div class="row"><span class="muted">Account name</span><span><?php echo h((string) ($settings['gcash_account_name'] ?? '')); ?></span></div>
            <div class="row"><span class="muted">GCash number</span><span><?php echo h((string) ($settings['gcash_number'] ?? '')); ?></span></div>
            <?php if (!empty($settings['payment_instructions'])): ?>
              <p class="note"><?php echo nl2br(h((string) $settings['payment_instructions'])); ?></p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php
        $showForm = ((string) ($payment['status'] ?? '') === 'awaiting_proof')
            || (!$successSubmitted && (string) ($payment['status'] ?? '') !== 'paid');
      ?>
      <?php if ($showForm): ?>
      <div class="card">
        <form action="<?php echo h(ereview_url('payment_checkout_submit')); ?>" method="POST" enctype="multipart/form-data" id="checkout-form">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="payment_id" value="<?php echo (int) $payment['payment_id']; ?>">

          <label for="gcash_reference">GCash reference number</label>
          <input type="text" name="gcash_reference" id="gcash_reference" required maxlength="64"
                 value="<?php echo h((string) ($payment['gcash_reference'] ?? '')); ?>"
                 autocomplete="off" placeholder="Enter the reference from your GCash receipt">

          <label for="payment_proof">Payment proof (JPG, PNG, WEBP, or PDF - max 5 MB)</label>
          <input type="file" name="payment_proof" id="payment_proof" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                 <?php echo empty($payment['proof_path']) ? 'required' : ''; ?>>

          <button type="submit" class="btn" id="checkout-submit">Submit Payment</button>
          <p class="note">Submitting does not verify payment or grant LMS access. Verification happens in a later step.</p>
        </form>
      </div>
      <?php else: ?>
      <div class="card">
        <p class="sub" style="margin:0;">You can close this page. An administrator will review your payment later. Signing in to the LMS still requires account approval.</p>
        <a class="btn" href="<?php echo h(ereview_url('login')); ?>" style="margin-top:1rem;">Go to sign in</a>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <script>
    (function () {
      var form = document.getElementById('checkout-form');
      if (!form) return;
      form.addEventListener('submit', function () {
        var btn = document.getElementById('checkout-submit');
        if (btn) { btn.disabled = true; btn.textContent = 'Submitting...'; }
      });
    })();
  </script>
</body>
</html>
