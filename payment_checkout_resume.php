<?php
/**
 * Phase 5 — Continue Payment / checkout recovery.
 *
 * Re-issues a checkout session for the SAME browser post-verify recovery handle.
 * Does NOT use payment_ref as auth. Does NOT grant LMS access. Free Access blocked.
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/commerce_payment.php';

$csrf = generateCSRFToken();
$error = '';
$recovery = commerce_validate_checkout_recovery_session(null);
$recoveryOk = !empty($recovery['ok']);
$recoveryToken = (string) ($_SESSION['checkout_recovery_token'] ?? '');
$recoveryReason = (string) ($_SESSION['checkout_recovery_reason'] ?? '');

// If checkout session already valid, send straight to checkout.
$existing = commerce_require_checkout_session($conn);
if (!empty($existing['ok'])) {
    header('Location: ' . ereview_url('payment_checkout'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $posted = (string) ($_POST['recovery_token'] ?? '');
        $result = commerce_resume_checkout_from_recovery($conn, $posted);
        if (!empty($result['ok'])) {
            header('Location: ' . ereview_url('payment_checkout'));
            exit;
        }
        $error = $result['error'] ?? 'Could not continue payment checkout.';
        $recovery = commerce_validate_checkout_recovery_session(null);
        $recoveryOk = !empty($recovery['ok']);
        $recoveryToken = (string) ($_SESSION['checkout_recovery_token'] ?? '');
        $recoveryReason = (string) ($_SESSION['checkout_recovery_reason'] ?? '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Continue Payment – LCRC eReview</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', Tahoma, sans-serif; background: #0b1220; color: #e5e7eb; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .card { max-width: 440px; width: 100%; background: linear-gradient(180deg, #111827 0%, #0f172a 100%); border: 1px solid rgba(255,255,255,0.06); border-radius: 1rem; padding: 2rem; text-align: center; }
    h1 { font-size: 1.25rem; margin: 0 0 0.5rem; color: #fff; }
    p { color: #94a3b8; font-size: 0.9375rem; line-height: 1.6; margin: 0 0 1.25rem; }
    .alert { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.35); color: #fecaca; border-radius: 0.75rem; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.875rem; text-align: left; }
    .btn { display: inline-block; width: 100%; background: #1F58C3; color: #fff; border: 0; text-decoration: none; padding: 0.85rem 1.25rem; border-radius: 0.75rem; font-weight: 700; font-size: 0.9rem; cursor: pointer; }
    .btn:hover { background: #1E40AF; }
    a.secondary { display: inline-block; margin-top: 0.85rem; color: #93c5fd; font-size: 0.85rem; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Continue payment</h1>
    <?php if ($error !== ''): ?>
      <div class="alert" role="alert"><?php echo h($error); ?></div>
    <?php endif; ?>
    <?php if ($recoveryOk): ?>
      <p><?php echo h($recoveryReason !== '' ? $recoveryReason : 'Your account is verified. Continue to set up or resume GCash checkout for your selected package or topics.'); ?></p>
      <p>This does not sign you into the LMS. Your account remains pending until an administrator completes later verification.</p>
      <form method="POST" action="<?php echo h(ereview_url('payment_checkout_resume')); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="recovery_token" value="<?php echo h($recoveryToken); ?>">
        <button type="submit" class="btn">Continue to payment</button>
      </form>
    <?php else: ?>
      <p>No active payment recovery session was found. Use your email verification link again, or contact support if checkout still fails.</p>
      <a class="btn" href="<?php echo h(ereview_url('login')); ?>">Go to sign in</a>
    <?php endif; ?>
    <a class="secondary" href="<?php echo h(ereview_url('login')); ?>">Sign in later</a>
  </div>
</body>
</html>
