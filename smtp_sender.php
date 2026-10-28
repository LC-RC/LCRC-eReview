<?php
/**
 * Pure-PHP SMTP sender (no Composer). Supports TLS for Gmail.
 * Used for password reset, verification, and magic-link emails when config/mail_config is set.
 */

/**
 * Returns true if config has real SMTP credentials (not sample placeholders).
 */
function isMailConfigValid($config) {
    if (!is_array($config)) return false;
    $u = trim($config['smtp_username'] ?? '');
    $p = str_replace(' ', '', $config['smtp_password'] ?? '');
    if ($u === '' || $p === '') return false;
    if (stripos($u, 'your-gmail') !== false || stripos($p, 'your-16-char') !== false) return false;
    return true;
}

/**
 * TLS options for SMTP: verify certificate and hostname. Never disable verification.
 *
 * @return array<string,mixed>
 */
function smtp_ssl_context_options(string $peerName): array
{
    $opts = [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
        'peer_name' => $peerName,
        'SNI_enabled' => true,
    ];
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
        $opts['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    }
    $cafile = (string) ini_get('openssl.cafile');
    if ($cafile !== '' && is_file($cafile)) {
        $opts['cafile'] = $cafile;
    }
    $capath = (string) ini_get('openssl.capath');
    if ($capath !== '' && is_dir($capath)) {
        $opts['capath'] = $capath;
    }

    return $opts;
}

function smtp_create_ssl_context(string $peerName)
{
    return stream_context_create(['ssl' => smtp_ssl_context_options($peerName)]);
}

/**
 * STARTTLS with certificate + hostname verification.
 *
 * @param resource $sock
 * @param callable(string):void $log
 */
function smtp_enable_starttls($sock, string $peerName, callable $log): bool
{
    foreach (smtp_ssl_context_options($peerName) as $key => $value) {
        if (!@stream_context_set_option($sock, 'ssl', $key, $value)) {
            $log('TLS context option failed');

            return false;
        }
    }
    $tlsMethod = defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')
        ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
        : STREAM_CRYPTO_METHOD_TLS_CLIENT;
    if (!@stream_socket_enable_crypto($sock, true, $tlsMethod)) {
        $openssl = function_exists('openssl_error_string') ? trim((string) openssl_error_string()) : '';
        $openssl = preg_replace('#https?://\S+#i', '[url]', $openssl) ?? $openssl;
        $log(
            'TLS certificate verification failed. Set php.ini openssl.cafile to a current CA bundle (cacert.pem) '
            . 'and ensure smtp_host matches the server certificate. '
            . ($openssl !== '' ? $openssl : 'If the host is correct, use smtp_port 465 with smtp_secure=ssl.')
        );

        return false;
    }
    $log('TLS OK (peer verified)');

    return true;
}

/**
 * Send an email via SMTP (e.g. Gmail). Returns true on success, false on failure.
 * @param string   $toEmail   Recipient address
 * @param string   $subject   Subject line
 * @param string   $bodyPlain Plain text body
 * @param string   $fromEmail Sender address
 * @param string   $fromName  Sender display name
 * @param array    $config    ['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username', 'smtp_password']
 * @param array|null $debugLog If provided, each SMTP step and response is appended (for debugging).
 * @return bool
 */
function sendMailSmtp($toEmail, $subject, $bodyPlain, $fromEmail, $fromName, $config, &$debugLog = null) {
    $host = $config['smtp_host'] ?? '';
    $port = (int) ($config['smtp_port'] ?? 587);
    $secure = strtolower($config['smtp_secure'] ?? 'tls');
    $username = trim($config['smtp_username'] ?? '');
    $password = str_replace(' ', '', $config['smtp_password'] ?? '');

    $log = function ($msg) use (&$debugLog) {
        if ($debugLog !== null) {
            $debugLog[] = $msg;
        }
    };

    if ($host === '' || $username === '' || $password === '') {
        $log('Missing smtp_host, smtp_username, or smtp_password');
        return false;
    }

    $errno = 0;
    $errstr = '';
    $useImplicitSsl = ($port === 465 || $secure === 'ssl');
    $sslCtx = smtp_create_ssl_context($host);

    if ($useImplicitSsl) {
        $sock = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $sslCtx
        );
    } else {
        $sock = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );
    }

    if (!is_resource($sock)) {
        $log("Connect FAIL: $errstr ($errno)");
        return false;
    }
    $log("Connected to $host:$port" . ($useImplicitSsl ? ' (SSL)' : ''));

    stream_set_timeout($sock, 15);
    $read = function () use ($sock, $log, &$debugLog) {
        $line = @fgets($sock, 512);
        $out = $line !== false ? trim($line) : '';
        if ($debugLog !== null && $out !== '') {
            $log('S: ' . $out);
        }
        return $out;
    };
    $send = function ($cmd) use ($sock, $log, &$debugLog) {
        if ($debugLog !== null) {
            $safe = strpos($cmd, 'AUTH LOGIN') === 0 ? $cmd : (preg_match('/^[A-Z]+ [A-Za-z0-9+\/=]+$/', $cmd) ? substr($cmd, 0, 20) . '...' : $cmd);
            $log('C: ' . $safe);
        }
        return @fwrite($sock, $cmd . "\r\n") !== false;
    };

    $response = $read();
    if (strpos($response, '220') !== 0) {
        $log("Expected 220, got: $response");
        fclose($sock);
        return false;
    }

    if (!$send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'))) {
        fclose($sock);
        return false;
    }
    while (($line = $read()) !== '') {
        if (strpos($line, '250 ') === 0) break;
    }

    if (!$useImplicitSsl && $secure === 'tls' && $port == 587) {
        if (!$send('STARTTLS')) {
            fclose($sock);
            return false;
        }
        $response = $read();
        if (strpos($response, '220') !== 0) {
            $log("STARTTLS expected 220, got: $response");
            fclose($sock);
            return false;
        }
        if (!smtp_enable_starttls($sock, $host, $log)) {
            fclose($sock);
            return false;
        }
        if (!$send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'))) {
            fclose($sock);
            return false;
        }
        while (($line = $read()) !== '') {
            if (strpos($line, '250 ') === 0) break;
        }
    }

    if (!$send('AUTH LOGIN')) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send(base64_encode($username))) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send(base64_encode($password))) {
        fclose($sock);
        return false;
    }
    $authResp = $read();
    if (strpos($authResp, '235') !== 0) {
        $log("AUTH FAIL (use App Password, not normal password): $authResp");
        fclose($sock);
        return false;
    }
    $log('AUTH OK');

    $fromAddr = $fromEmail;
    if ($fromName !== '') {
        $fromAddr = $fromName . ' <' . $fromEmail . '>';
    }
    if (!$send('MAIL FROM:<' . $fromEmail . '>')) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send('RCPT TO:<' . $toEmail . '>')) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send('DATA')) {
        fclose($sock);
        return false;
    }
    $read();

    $headers = 'From: ' . $fromAddr . "\r\n";
    $headers .= 'To: ' . $toEmail . "\r\n";
    $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= 'Subject: ' . $subject . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $headers .= "\r\n";
    $body = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $bodyPlain);
    $body = str_replace("\r\n.", "\r\n..", $body);
    $data = $headers . $body . "\r\n.\r\n";

    if (!fwrite($sock, $data)) {
        $log('DATA write failed');
        fclose($sock);
        return false;
    }
    $dataResp = $read();
    $send('QUIT');
    fclose($sock);

    if (strpos($dataResp, '250') !== 0) {
        $log("DATA response: $dataResp");
        return false;
    }
    $log('Mail sent OK');
    return true;
}

/**
 * Send an HTML email via SMTP. Same as sendMailSmtp but with Content-Type: text/html.
 * Used for registration verification and other HTML emails.
 */
function sendMailSmtpHtml($toEmail, $subject, $bodyHtml, $fromEmail, $fromName, $config, &$debugLog = null) {
    $host = $config['smtp_host'] ?? '';
    $port = (int) ($config['smtp_port'] ?? 587);
    $secure = strtolower($config['smtp_secure'] ?? 'tls');
    $username = trim($config['smtp_username'] ?? '');
    $password = str_replace(' ', '', $config['smtp_password'] ?? '');

    $log = function ($msg) use (&$debugLog) {
        if ($debugLog !== null) {
            $debugLog[] = $msg;
        }
    };

    if ($host === '' || $username === '' || $password === '') {
        $log('Missing smtp_host, smtp_username, or smtp_password');
        return false;
    }

    $errno = 0;
    $errstr = '';
    $useImplicitSsl = ($port === 465 || $secure === 'ssl');
    $sslCtx = smtp_create_ssl_context($host);

    if ($useImplicitSsl) {
        $sock = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $sslCtx
        );
    } else {
        $sock = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );
    }

    if (!is_resource($sock)) {
        $log("Connect FAIL: $errstr ($errno)");
        return false;
    }
    $log("Connected to $host:$port" . ($useImplicitSsl ? ' (SSL)' : ''));

    stream_set_timeout($sock, 15);
    $read = function () use ($sock, $log, &$debugLog) {
        $line = @fgets($sock, 512);
        $out = $line !== false ? trim($line) : '';
        if ($debugLog !== null && $out !== '') {
            $log('S: ' . $out);
        }
        return $out;
    };
    $send = function ($cmd) use ($sock, $log, &$debugLog) {
        if ($debugLog !== null) {
            $safe = strpos($cmd, 'AUTH LOGIN') === 0 ? $cmd : (preg_match('/^[A-Z]+ [A-Za-z0-9+\/=]+$/', $cmd) ? substr($cmd, 0, 20) . '...' : $cmd);
            $log('C: ' . $safe);
        }
        return @fwrite($sock, $cmd . "\r\n") !== false;
    };

    $response = $read();
    if (strpos($response, '220') !== 0) {
        $log("Expected 220, got: $response");
        fclose($sock);
        return false;
    }

    if (!$send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'))) {
        fclose($sock);
        return false;
    }
    while (($line = $read()) !== '') {
        if (strpos($line, '250 ') === 0) break;
    }

    if (!$useImplicitSsl && $secure === 'tls' && $port == 587) {
        if (!$send('STARTTLS')) {
            fclose($sock);
            return false;
        }
        $response = $read();
        if (strpos($response, '220') !== 0) {
            $log("STARTTLS expected 220, got: $response");
            fclose($sock);
            return false;
        }
        if (!smtp_enable_starttls($sock, $host, $log)) {
            fclose($sock);
            return false;
        }
        if (!$send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'))) {
            fclose($sock);
            return false;
        }
        while (($line = $read()) !== '') {
            if (strpos($line, '250 ') === 0) break;
        }
    }

    if (!$send('AUTH LOGIN')) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send(base64_encode($username))) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send(base64_encode($password))) {
        fclose($sock);
        return false;
    }
    $authResp = $read();
    if (strpos($authResp, '235') !== 0) {
        $log("AUTH FAIL (use App Password, not normal password): $authResp");
        fclose($sock);
        return false;
    }
    $log('AUTH OK');

    $fromAddr = $fromEmail;
    if ($fromName !== '') {
        $fromAddr = $fromName . ' <' . $fromEmail . '>';
    }
    if (!$send('MAIL FROM:<' . $fromEmail . '>')) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send('RCPT TO:<' . $toEmail . '>')) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send('DATA')) {
        fclose($sock);
        return false;
    }
    $read();

    $headers = 'From: ' . $fromAddr . "\r\n";
    $headers .= 'To: ' . $toEmail . "\r\n";
    $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= 'Subject: ' . $subject . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= "\r\n";
    $body = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $bodyHtml);
    $body = str_replace("\r\n.", "\r\n..", $body);
    $data = $headers . $body . "\r\n.\r\n";

    if (!fwrite($sock, $data)) {
        $log('DATA write failed');
        fclose($sock);
        return false;
    }
    $dataResp = $read();
    $send('QUIT');
    fclose($sock);

    if (strpos($dataResp, '250') !== 0) {
        $log("DATA response: $dataResp");
        return false;
    }
    $log('Mail sent OK');
    return true;
}

/**
 * Send HTML email with optional file attachments (multipart/mixed).
 *
 * @param list<array{filename:string,content:string,mime?:string}> $attachments
 */
function sendMailSmtpHtmlWithAttachments(
    $toEmail,
    $subject,
    $bodyHtml,
    $fromEmail,
    $fromName,
    $config,
    $attachments = [],
    &$debugLog = null
) {
    if (!is_array($attachments) || $attachments === []) {
        return sendMailSmtpHtml($toEmail, $subject, $bodyHtml, $fromEmail, $fromName, $config, $debugLog);
    }

    $host = $config['smtp_host'] ?? '';
    $port = (int) ($config['smtp_port'] ?? 587);
    $secure = strtolower($config['smtp_secure'] ?? 'tls');
    $username = trim($config['smtp_username'] ?? '');
    $password = str_replace(' ', '', $config['smtp_password'] ?? '');

    $log = function ($msg) use (&$debugLog) {
        if ($debugLog !== null) {
            $debugLog[] = $msg;
        }
    };

    if ($host === '' || $username === '' || $password === '') {
        $log('Missing smtp_host, smtp_username, or smtp_password');
        return false;
    }

    $errno = 0;
    $errstr = '';
    $useImplicitSsl = ($port === 465 || $secure === 'ssl');
    $sslCtx = smtp_create_ssl_context($host);

    if ($useImplicitSsl) {
        $sock = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $sslCtx
        );
    } else {
        $sock = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );
    }

    if (!is_resource($sock)) {
        $log("Connect FAIL: $errstr ($errno)");
        return false;
    }
    $log("Connected to $host:$port" . ($useImplicitSsl ? ' (SSL)' : ''));

    stream_set_timeout($sock, 15);
    $read = function () use ($sock, $log, &$debugLog) {
        $line = @fgets($sock, 512);
        $out = $line !== false ? trim($line) : '';
        if ($debugLog !== null && $out !== '') {
            $log('S: ' . $out);
        }
        return $out;
    };
    $send = function ($cmd) use ($sock, $log, &$debugLog) {
        if ($debugLog !== null) {
            $safe = strpos($cmd, 'AUTH LOGIN') === 0 ? $cmd : (preg_match('/^[A-Z]+ [A-Za-z0-9+\/=]+$/', $cmd) ? substr($cmd, 0, 20) . '...' : $cmd);
            $log('C: ' . $safe);
        }
        return @fwrite($sock, $cmd . "\r\n") !== false;
    };

    $response = $read();
    if (strpos($response, '220') !== 0) {
        $log("Expected 220, got: $response");
        fclose($sock);
        return false;
    }

    if (!$send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'))) {
        fclose($sock);
        return false;
    }
    while (($line = $read()) !== '') {
        if (strpos($line, '250 ') === 0) {
            break;
        }
    }

    if (!$useImplicitSsl && $secure === 'tls' && $port == 587) {
        if (!$send('STARTTLS')) {
            fclose($sock);
            return false;
        }
        $response = $read();
        if (strpos($response, '220') !== 0) {
            $log("STARTTLS expected 220, got: $response");
            fclose($sock);
            return false;
        }
        if (!smtp_enable_starttls($sock, $host, $log)) {
            fclose($sock);
            return false;
        }
        if (!$send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'))) {
            fclose($sock);
            return false;
        }
        while (($line = $read()) !== '') {
            if (strpos($line, '250 ') === 0) {
                break;
            }
        }
    }

    if (!$send('AUTH LOGIN')) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send(base64_encode($username))) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send(base64_encode($password))) {
        fclose($sock);
        return false;
    }
    $authResp = $read();
    if (strpos($authResp, '235') !== 0) {
        $log("AUTH FAIL (use App Password, not normal password): $authResp");
        fclose($sock);
        return false;
    }
    $log('AUTH OK');

    $fromAddr = $fromEmail;
    if ($fromName !== '') {
        $fromAddr = $fromName . ' <' . $fromEmail . '>';
    }
    if (!$send('MAIL FROM:<' . $fromEmail . '>')) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send('RCPT TO:<' . $toEmail . '>')) {
        fclose($sock);
        return false;
    }
    $read();
    if (!$send('DATA')) {
        fclose($sock);
        return false;
    }
    $read();

    $boundary = '=_Ereview_' . bin2hex(random_bytes(12));
    $headers = 'From: ' . $fromAddr . "\r\n";
    $headers .= 'To: ' . $toEmail . "\r\n";
    $headers .= 'Subject: ' . $subject . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
    $headers .= "\r\n";

    $htmlBody = str_replace(["\r\n", "\n"], ["\n", "\r\n"], (string) $bodyHtml);
    $parts = '--' . $boundary . "\r\n";
    $parts .= "Content-Type: text/html; charset=UTF-8\r\n";
    $parts .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $parts .= $htmlBody . "\r\n";

    foreach ($attachments as $att) {
        if (!is_array($att)) {
            continue;
        }
        $fname = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($att['filename'] ?? 'attachment.bin')) ?: 'attachment.bin';
        $content = (string) ($att['content'] ?? '');
        if ($content === '') {
            continue;
        }
        $mime = trim((string) ($att['mime'] ?? 'application/octet-stream'));
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        $parts .= '--' . $boundary . "\r\n";
        $parts .= 'Content-Type: ' . $mime . '; name="' . $fname . '"' . "\r\n";
        $parts .= "Content-Transfer-Encoding: base64\r\n";
        $parts .= 'Content-Disposition: attachment; filename="' . $fname . '"' . "\r\n\r\n";
        $parts .= chunk_split(base64_encode($content)) . "\r\n";
    }
    $parts .= '--' . $boundary . "--\r\n";

    $body = str_replace("\r\n.", "\r\n..", $parts);
    $data = $headers . $body . "\r\n.\r\n";

    if (!fwrite($sock, $data)) {
        $log('DATA write failed');
        fclose($sock);
        return false;
    }
    $dataResp = $read();
    $send('QUIT');
    fclose($sock);

    if (strpos($dataResp, '250') !== 0) {
        $log("DATA response: $dataResp");
        return false;
    }
    $log('Mail sent OK (multipart)');
    return true;
}
