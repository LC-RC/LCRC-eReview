<?php
/**
 * Email verification landing page. Token in URL; on success creates user and shows success page.
 * Package/By Topic: continues to GCash checkout via short-lived checkout session (Phase 5).
 * Free Access: no checkout.
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_verification.php';
require_once __DIR__ . '/includes/url_helpers.php';

$tokenRaw = '';
if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
    parse_str($_SERVER['QUERY_STRING'], $qs);
    $tokenRaw = isset($qs['token']) ? trim((string) $qs['token']) : '';
}
if ($tokenRaw === '') {
    $tokenRaw = trim($_GET['token'] ?? '');
}

$success = false;
$message = 'This verification link is invalid or has expired. Please register again.';
$checkoutReady = false;
$checkoutRecovery = false;
$enrollmentPath = '';
$userId = null;

if ($tokenRaw !== '') {
    $pending = validateVerificationToken($tokenRaw);
    if ($pending !== null) {
        $userId = completeVerificationAndCreateUser($pending);
        if ($userId !== null) {
            $success = true;
            $checkoutReady = !empty($_SESSION['checkout_payment_id'])
                && !empty($_SESSION['checkout_token'])
                && (int) ($_SESSION['checkout_user_id'] ?? 0) === (int) $userId;
            $recoveryCheck = null;
            if (!$checkoutReady && file_exists(__DIR__ . '/includes/commerce_payment.php')) {
                require_once __DIR__ . '/includes/commerce_payment.php';
                $recoveryCheck = commerce_validate_checkout_recovery_session(null);
                $checkoutRecovery = !empty($recoveryCheck['ok'])
                    && (int) ($recoveryCheck['user_id'] ?? 0) === (int) $userId;
            }
            if ($conn) {
                $epStmt = mysqli_prepare($conn, 'SELECT enrollment_path FROM users WHERE user_id = ? LIMIT 1');
                if ($epStmt) {
                    mysqli_stmt_bind_param($epStmt, 'i', $userId);
                    mysqli_stmt_execute($epStmt);
                    $epRes = mysqli_stmt_get_result($epStmt);
                    $epRow = $epRes ? mysqli_fetch_assoc($epRes) : null;
                    mysqli_stmt_close($epStmt);
                    $enrollmentPath = trim((string) ($epRow['enrollment_path'] ?? ''));
                }
            }
            if ($checkoutReady) {
                $message = 'Your account has been verified. Continue to payment to complete your enrollment.';
            } elseif ($checkoutRecovery) {
                $message = 'Your account has been verified. Use Continue to Payment to safely resume your checkout.';
            } elseif ($enrollmentPath === 'free_access') {
                $message = 'Your account has been verified. Free Access does not require payment—an administrator will review your request.';
            } else {
                $message = 'Your account has been verified. You may now sign in.';
            }
        } else {
            $message = 'Account creation failed. Please try registering again.';
        }
    }
}

$isPaidPath = ($enrollmentPath === 'package' || $enrollmentPath === 'by_topic');
$isFreeAccess = ($enrollmentPath === 'free_access');

$isJson = !empty($_GET['format']) && $_GET['format'] === 'json';
if ($isJson) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'checkout_ready' => $checkoutReady,
        'checkout_recovery' => $checkoutRecovery,
        'enrollment_path' => $enrollmentPath,
        'checkout_url' => $checkoutReady ? ereview_url('payment_checkout') : null,
        'checkout_resume_url' => $checkoutRecovery ? ereview_url('payment_checkout_resume') : null,
    ]);
    exit;
}

$pageTitle = $success ? 'Email verified' : 'Verification failed';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $success ? 'Email verified' : 'Verification failed'; ?> – LCRC eReview</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', Tahoma, sans-serif; background: #0b1220; color: #e5e7eb; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .card { max-width: 440px; width: 100%; background: linear-gradient(180deg, #111827 0%, #0f172a 100%); border: 1px solid rgba(255,255,255,0.06); border-radius: 1rem; padding: 2rem; text-align: center; }
    .card h1 { font-size: 1.25rem; margin: 0 0 0.5rem; color: #fff; }
    .card p { color: #94a3b8; font-size: 0.9375rem; line-height: 1.6; margin: 0 0 1.25rem; }
    .icon-wrap { width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 1.25rem; display: flex; align-items: center; justify-content: center; }
    .icon-wrap.success { background: rgba(34, 197, 94, 0.2); color: #22c55e; }
    .icon-wrap.error { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
    .icon-wrap i { font-size: 2rem; }
    a.btn { display: inline-block; background: #1F58C3; color: #fff; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 0.75rem; font-weight: 600; font-size: 0.875rem; transition: background 0.2s; }
    a.btn:hover { background: #1E40AF; }
    a.btn-secondary { display: inline-block; margin-top: 0.75rem; color: #93c5fd; font-size: 0.85rem; }
    .steps { list-style: none; margin: 0 0 1.25rem; padding: 0; display: flex; flex-wrap: wrap; gap: 0.45rem 0.65rem; justify-content: center; text-align: left; }
    .steps li { font-size: 0.72rem; font-weight: 600; color: #64748b; display: inline-flex; align-items: center; gap: 0.3rem; }
    .steps li.done { color: #86efac; }
    .steps li.current { color: #93c5fd; }
    .steps .n { width: 1.15rem; height: 1.15rem; border-radius: 999px; background: #334155; color: #e2e8f0; display: inline-flex; align-items: center; justify-content: center; font-size: 0.62rem; font-weight: 800; }
    .steps li.done .n { background: #15803d; color: #fff; }
    .steps li.current .n { background: #1F58C3; color: #fff; }
  </style>
</head>
<body>
  <div class="card">
    <?php if ($success): ?>
      <div class="icon-wrap success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></div>
      <h1>Email verified successfully!</h1>
      <?php if ($isPaidPath || $checkoutReady || $checkoutRecovery): ?>
        <ol class="steps" aria-label="Enrollment steps">
          <li class="done"><span class="n">1</span> Create Account</li>
          <li class="done"><span class="n">2</span> Verify Email</li>
          <li class="current"><span class="n">3</span> Payment</li>
          <li><span class="n">4</span> Access</li>
        </ol>
      <?php elseif ($isFreeAccess): ?>
        <ol class="steps" aria-label="Enrollment steps">
          <li class="done"><span class="n">1</span> Create Account</li>
          <li class="done"><span class="n">2</span> Verify Email</li>
          <li class="current"><span class="n">3</span> Admin review</li>
          <li><span class="n">4</span> Access</li>
        </ol>
      <?php endif; ?>
      <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
      <?php if ($checkoutReady): ?>
        <a href="<?php echo htmlspecialchars(ereview_url('payment_checkout'), ENT_QUOTES, 'UTF-8'); ?>" class="btn">Continue to Payment</a>
        <div><a class="btn-secondary" href="<?php echo htmlspecialchars(ereview_url('login'), ENT_QUOTES, 'UTF-8'); ?>">Sign in later</a></div>
      <?php elseif ($checkoutRecovery): ?>
        <a href="<?php echo htmlspecialchars(ereview_url('payment_checkout_resume'), ENT_QUOTES, 'UTF-8'); ?>" class="btn">Continue to Payment</a>
        <div><a class="btn-secondary" href="<?php echo htmlspecialchars(ereview_url('login'), ENT_QUOTES, 'UTF-8'); ?>">Sign in later</a></div>
      <?php elseif ($isFreeAccess): ?>
        <a href="<?php echo htmlspecialchars(ereview_url('login'), ENT_QUOTES, 'UTF-8'); ?>" class="btn">Sign in</a>
      <?php else: ?>
        <a href="<?php echo htmlspecialchars(ereview_url('login'), ENT_QUOTES, 'UTF-8'); ?>" class="btn">Sign in</a>
      <?php endif; ?>
    <?php else: ?>
      <div class="icon-wrap error"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></div>
      <h1>Verification failed</h1>
      <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
      <a href="<?php echo htmlspecialchars(ereview_url('registration'), ENT_QUOTES, 'UTF-8'); ?>" class="btn">Register again</a>
    <?php endif; ?>
  </div>
</body>
</html>
