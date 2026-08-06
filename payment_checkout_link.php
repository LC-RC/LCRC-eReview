<?php
/**
 * Public entry for durable payment-proof upload links from admin reminder emails.
 * Validates token → issues checkout session → redirects to payment_checkout.
 */
declare(strict_types=1);

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/commerce_payment.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$result = commerce_consume_checkout_resume_link($conn, $token);

if (!empty($result['ok'])) {
    ereview_redirect('payment_checkout');
}

$error = (string) ($result['error'] ?? 'Invalid or expired upload link.');
$pageTitle = 'Upload Payment Proof';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> · LCRC eReview</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f1f5f9; color: #0f172a; margin: 0; padding: 2rem 1rem; }
    .card { max-width: 28rem; margin: 3rem auto; background: #fff; border-radius: 0.75rem; padding: 1.5rem; box-shadow: 0 8px 24px rgba(15,23,42,.08); }
    h1 { font-size: 1.25rem; margin: 0 0 0.75rem; }
    p { margin: 0 0 1rem; line-height: 1.5; color: #334155; }
    a { color: #1d4ed8; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Upload link unavailable</h1>
    <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <p><a href="<?php echo htmlspecialchars(ereview_url('login'), ENT_QUOTES, 'UTF-8'); ?>">Go to login</a></p>
  </div>
</body>
</html>
