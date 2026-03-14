<?php
/**
 * DEBUG: Password reset email – run this in the browser to see exactly where sending fails.
 * Open: http://localhost/Ereview/debug_password_reset_mail.php?email=monzalesvinceivan@gmail.com
 * DELETE this file after fixing the issue (security).
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$log = [];
function debugLog($msg, $isError = false) {
    global $log;
    $log[] = ['ts' => date('H:i:s'), 'msg' => $msg, 'error' => $isError];
}

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
if ($email === '') {
    $email = 'monzalesvinceivan@gmail.com'; // default for testing
}

$log[] = ['ts' => date('H:i:s'), 'msg' => '=== Password reset email debug ===', 'error' => false];
$log[] = ['ts' => date('H:i:s'), 'msg' => 'Recipient: ' . ($email ?: '(none)'), 'error' => false];

// 1. Config
$configFile = __DIR__ . '/config/mail_config.php';
if (!file_exists($configFile)) {
    debugLog('FAIL: config/mail_config.php not found.', true);
} else {
    debugLog('OK: config file found.');
    $config = require $configFile;
    if (!is_array($config)) {
        debugLog('FAIL: config must return an array.', true);
    } else {
        $host = $config['smtp_host'] ?? '';
        $port = (int) ($config['smtp_port'] ?? 587);
        $user = $config['smtp_username'] ?? '';
        $pass = $config['smtp_password'] ?? '';
        debugLog('  smtp_host: ' . ($host === '' ? '(EMPTY – set to smtp.gmail.com)' : $host) . ($host === '' ? ' ← LIKELY CAUSE' : ''));
        if ($host === '') debugLog('  → Emails will NOT send when smtp_host is empty.', true);
        debugLog('  smtp_port: ' . $port);
        debugLog('  smtp_username: ' . ($user ?: '(empty)'));
        debugLog('  smtp_password: ' . ($pass ? '***set***' : '(EMPTY)'));
        if ($pass === '') debugLog('  → App Password is required for Gmail.', true);
    }
}

// 2. Database + token
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/password_reset.php';

$resetUrl = null;
$resetUrl = createPasswordResetToken($email);
if ($resetUrl === null) {
    debugLog('FAIL: No user found for this email (or token creation failed). Check that the account exists.', true);
} else {
    debugLog('OK: Reset token created.');
    debugLog('  Reset URL (first 80 chars): ' . substr($resetUrl, 0, 80) . '...');
}

// 3. SMTP send with step-by-step logging
if ($resetUrl && file_exists($configFile)) {
    $config = require $configFile;
    if (is_array($config) && !empty($config['smtp_username'])) {
        $host = $config['smtp_host'] ?? '';
        $port = (int) ($config['smtp_port'] ?? 587);
        $secure = strtolower($config['smtp_secure'] ?? 'tls');
        $username = $config['smtp_username'];
        $password = $config['smtp_password'] ?? '';
        $fromEmail = $config['from_email'] ?? $username;
        $fromName = $config['from_name'] ?? 'LCRC eReview';

        if ($host === '' || $password === '') {
            debugLog('SKIP SMTP: smtp_host or smtp_password is empty. Fix config/mail_config.php', true);
        } else {
            require_once __DIR__ . '/smtp_sender.php';
            $subject = 'Reset your password – LCRC eReview (debug test)';
            $body = "Test. Reset link: " . $resetUrl . "\r\n";
            debugLog('Attempting SMTP send to ' . $host . ':' . $port . ' ...');
            $smtpLog = [];
            $ok = sendMailSmtp($email, $subject, $body, $fromEmail, $fromName, $config, $smtpLog);
            foreach ($smtpLog as $line) {
                debugLog('  SMTP: ' . $line, strpos($line, 'FAIL') !== false || strpos($line, 'AUTH FAIL') !== false);
            }
            if ($ok) {
                debugLog('SUCCESS: Email sent. Check inbox/spam for: ' . $email);
            } else {
                debugLog('FAIL: sendMailSmtp returned false. Check SMTP lines above for the exact error.', true);
            }
        }
    }
}

// Output
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Password reset mail – debug</title>
  <style>
    body { font-family: Consolas, monospace; font-size: 14px; background: #1e1e1e; color: #d4d4d4; padding: 20px; }
    h1 { font-size: 18px; color: #4ec9b0; }
    .log { background: #252526; border: 1px solid #3c3c3c; border-radius: 8px; padding: 16px; margin: 16px 0; }
    .log-line { margin: 4px 0; padding: 2px 0; }
    .log-line.error { color: #f48771; }
    .log-line.ok { color: #4ec9b0; }
    form { margin: 16px 0; }
    input[type=email] { padding: 8px 12px; width: 280px; border: 1px solid #3c3c3c; border-radius: 4px; background: #252526; color: #d4d4d4; }
    button { padding: 8px 16px; background: #0e639c; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    button:hover { background: #1177bb; }
    .warn { background: #3c2a1a; border-left: 4px solid #f59e0b; padding: 12px; margin: 12px 0; }
  </style>
</head>
<body>
  <h1>Password reset email – debug log</h1>
  <p>Use this page to see why the reset email is not arriving. <strong>Delete this file after fixing.</strong></p>
  <form method="get" action="">
    <label>Test email: </label>
    <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="you@example.com">
    <button type="submit">Run debug</button>
  </form>
  <div class="log">
    <?php foreach ($log as $entry): ?>
      <div class="log-line <?php echo $entry['error'] ? 'error' : 'ok'; ?>">
        [<?php echo htmlspecialchars($entry['ts']); ?>] <?php echo nl2br(htmlspecialchars($entry['msg'])); ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="warn">
    If send still fails: ensure Gmail <strong>2-Step Verification</strong> is ON and you use an <strong>App Password</strong> (not your normal password).<br>
    <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener" style="color:#4ec9b0;">Create App Password</a>
  </div>
</body>
</html>
