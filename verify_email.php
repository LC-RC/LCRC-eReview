<?php
/**
 * Email verification landing page. Token in URL; on success creates user and shows success page.
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_verification.php';

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

if ($tokenRaw !== '') {
    $pending = validateVerificationToken($tokenRaw);
    if ($pending !== null) {
        $userId = completeVerificationAndCreateUser($pending);
        if ($userId !== null) {
            $success = true;
            $message = 'Your account has been successfully verified and created. You may now sign in.';
        } else {
            $message = 'Account creation failed. Please try registering again.';
        }
    }
}

$isJson = !empty($_GET['format']) && $_GET['format'] === 'json';
if ($isJson) {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
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
    .card { max-width: 420px; width: 100%; background: linear-gradient(180deg, #111827 0%, #0f172a 100%); border: 1px solid rgba(255,255,255,0.06); border-radius: 1rem; padding: 2rem; text-align: center; }
    .card h1 { font-size: 1.25rem; margin: 0 0 0.5rem; color: #fff; }
    .card p { color: #94a3b8; font-size: 0.9375rem; line-height: 1.6; margin: 0 0 1.5rem; }
    .icon-wrap { width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 1.25rem; display: flex; align-items: center; justify-content: center; }
    .icon-wrap.success { background: rgba(34, 197, 94, 0.2); color: #22c55e; }
    .icon-wrap.error { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
    .icon-wrap i { font-size: 2rem; }
    a.btn { display: inline-block; background: #1F58C3; color: #fff; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 0.75rem; font-weight: 600; font-size: 0.875rem; transition: background 0.2s; }
    a.btn:hover { background: #1E40AF; }
  </style>
</head>
<body>
  <div class="card">
    <?php if ($success): ?>
      <div class="icon-wrap success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></div>
      <h1>Email verified</h1>
      <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
      <a href="login.php" class="btn">Sign in</a>
    <?php else: ?>
      <div class="icon-wrap error"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></div>
      <h1>Verification failed</h1>
      <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
      <a href="registration.php" class="btn">Register again</a>
    <?php endif; ?>
  </div>
</body>
</html>
