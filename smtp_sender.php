<?php
/**
 * Pure-PHP SMTP sender (no Composer). Supports TLS for Gmail.
 * Used for password reset, verification, and magic-link emails when config/mail_config.php is set.
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
    $sslCtx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ]);

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
        $tlsMethod = defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (!@stream_socket_enable_crypto($sock, true, $tlsMethod)) {
            $log('TLS handshake failed (try port 465 in config)');
            fclose($sock);
            return false;
        }
        $log('TLS OK');
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
    $sslCtx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ]);

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
        $tlsMethod = defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (!@stream_socket_enable_crypto($sock, true, $tlsMethod)) {
            $log('TLS handshake failed (try port 465 in config)');
            fclose($sock);
            return false;
        }
        $log('TLS OK');
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
