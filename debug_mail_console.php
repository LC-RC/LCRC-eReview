<?php
// debug_mail_console.php
// Opens in browser and logs detailed SMTP/mail debug info to the JavaScript console.
// Usage:
//   http(s)://your-domain/debug_mail_console.php?to=test@example.com&url=https://your-domain/verify_email.php?token=TEST

header('Content-Type: text/html; charset=UTF-8');

$toEmail = $_GET['to'] ?? '';
$verificationUrl = $_GET['url'] ?? '';

if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
  $payload = [
    'ok' => false,
    'error' => 'Invalid or missing "to" parameter. Example: ?to=test@example.com',
  ];
  $json = json_encode($payload);
  echo '<!doctype html><html><body>';
  echo '<script>console.log("MailDebug", ' . $json . ');</script>';
  echo '<div style="font-family:system-ui;padding:16px;color:#b91c1c;">Invalid "to" parameter. Open console.</div>';
  echo '</body></html>';
  exit;
}

// Default verificationUrl so the HTML template has something to show.
if ($verificationUrl === '') {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $verificationUrl = $scheme . '://' . $host . '/verify_email.php?token=TEST';
}

require_once __DIR__ . '/smtp_sender.php';

$configFile = __DIR__ . '/config/mail_config.php';
$result = [
  'ok' => true,
  'to' => $toEmail,
  'verificationUrl' => $verificationUrl,
  'time' => date('c'),
  'configLoaded' => false,
  'tcpConnect' => null,
  'strictValid' => null,
  'looksConfigured' => null,
  'smtpHtml' => null,
  'smtpPlain' => null,
  'mailFallback' => null,
  'debug' => [
    'smtpHtmlLog' => [],
    'smtpPlainLog' => [],
  ],
];

if (!file_exists($configFile)) {
  $result['ok'] = false;
  $result['error'] = 'Missing config/mail_config.php';
} else {
  $config = require $configFile;
  $result['configLoaded'] = true;

  $host = $config['smtp_host'] ?? '';
  $port = (int)($config['smtp_port'] ?? 587);
  $secure = strtolower($config['smtp_secure'] ?? 'tls');
  $username = $config['smtp_username'] ?? '';
  $fromEmail = $config['from_email'] ?? $username;
  $fromName = $config['from_name'] ?? 'LCRC eReview';

  $result['strictValid'] = function_exists('isMailConfigValid') ? isMailConfigValid($config) : 'n/a';
  $result['looksConfigured'] = (!empty($host) && !empty($username) && !empty($config['smtp_password'] ?? ''));

  // TCP connectivity test.
  if (!empty($host) && $port > 0) {
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
    if (is_resource($socket)) {
      $result['tcpConnect'] = 'OK';
      fclose($socket);
    } else {
      $result['tcpConnect'] = 'FAIL';
      $result['tcpConnectError'] = $errstr;
      $result['tcpConnectErrno'] = $errno;
    }
  } else {
    $result['tcpConnect'] = 'SKIPPED (missing smtp_host/port)';
  }

  $subject = 'Debug mail - eReview';
  $html = '<p>Mail debug from browser.</p><p>To: ' . htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8') . '</p>';
  $html .= '<p>Verification URL:</p><a href="' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '</a>';

  $plain = "Mail debug from browser.\nTo: {$toEmail}\nVerification URL: {$verificationUrl}\n";

  // SMTP HTML attempt (captures debug log)
  $debugLogHtml = [];
  $okHtml = sendMailSmtpHtml($toEmail, $subject, $html, $fromEmail, $fromName, $config, $debugLogHtml);
  $result['smtpHtml'] = (bool)$okHtml;
  $result['debug']['smtpHtmlLog'] = $debugLogHtml;

  // SMTP plain attempt
  $debugLogPlain = [];
  $okPlain = sendMailSmtp($toEmail, $subject, $plain, $fromEmail, $fromName, $config, $debugLogPlain);
  $result['smtpPlain'] = (bool)$okPlain;
  $result['debug']['smtpPlainLog'] = $debugLogPlain;

  // PHP mail() fallback (quick signal)
  $headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'From: ' . $fromName . ' <' . ($fromEmail ?: $toEmail) . '>',
  ];
  $result['mailFallback'] = (bool)mail($toEmail, $subject, $html, implode("\r\n", $headers));
}

$json = json_encode($result);
echo '<!doctype html><html><head><meta charset="utf-8"><title>Mail Debug</title></head><body>';
echo '<script>';
echo 'console.log("MailDebug", ' . $json . ');';
echo '</script>';
echo '<div style="font-family:system-ui;padding:16px;">Open browser console to view <b>MailDebug</b> output.</div>';
echo '</body></html>';

