<?php
/**
 * Commerce student email notifications (Phase 8.4).
 *
 * Always invoked AFTER commerce COMMIT. SMTP failure never undoes access/payment state.
 * Reuses smtp_sender.php; does not rewrite mail config or SMTP stack.
 */

declare(strict_types=1);

require_once __DIR__ . '/../smtp_sender.php';
require_once __DIR__ . '/commerce_payment.php';

function commerce_notify_test_mode(): bool
{
    return defined('COMMERCE_NOTIFY_TEST_MODE') && COMMERCE_NOTIFY_TEST_MODE;
}

/**
 * @return array<string,mixed>|null
 */
function commerce_notify_load_mail_config(): ?array
{
    // Test harness may force a config (including null = invalid).
    if (commerce_notify_test_mode() && array_key_exists('commerce_test_mail_config', $GLOBALS)) {
        $forced = $GLOBALS['commerce_test_mail_config'];
        if ($forced === null || !is_array($forced) || !isMailConfigValid($forced)) {
            return null;
        }
        return $forced;
    }
    if (commerce_notify_test_mode()) {
        return [
            'smtp_host' => 'test',
            'smtp_username' => 'test@example.com',
            'smtp_password' => 'x',
            'from_email' => 'test@example.com',
            'from_name' => 'LCRC eReview',
        ];
    }
    $file = dirname(__DIR__) . '/config/mail_config.php';
    if (!is_file($file)) {
        return null;
    }
    $loaded = require $file;
    if (!is_array($loaded) || !isMailConfigValid($loaded)) {
        return null;
    }
    return $loaded;
}

/**
 * @return array{ok:bool,error?:string,user_id?:int,email?:string,full_name?:string,status?:string}
 */
function commerce_notify_load_student(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user'];
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT user_id, email, full_name, status, role FROM users WHERE user_id = ? LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'student_prepare_failed'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$row || (string) ($row['role'] ?? '') !== 'student') {
        return ['ok' => false, 'error' => 'student_not_found'];
    }
    $email = trim((string) ($row['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'student_email_invalid'];
    }
    return [
        'ok' => true,
        'user_id' => (int) $row['user_id'],
        'email' => $email,
        'full_name' => trim((string) ($row['full_name'] ?? 'Student')),
        'status' => strtolower(trim((string) ($row['status'] ?? ''))),
    ];
}

function commerce_notify_login_hint(): string
{
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        return $base . '/login';
    }
    return 'the LCRC eReview login page';
}

/**
 * @param array<string,mixed> $config
 * @return array{ok:bool,error?:string,sent?:bool}
 */
function commerce_notify_dispatch(string $toEmail, string $subject, string $html, string $plain, array $config): array
{
    if (commerce_notify_test_mode()) {
        $GLOBALS['commerce_test_notify_log'] = $GLOBALS['commerce_test_notify_log'] ?? [];
        $GLOBALS['commerce_test_notify_log'][] = [
            'to' => $toEmail,
            'subject' => $subject,
            'html' => $html,
            'plain' => $plain,
        ];
        $mock = $GLOBALS['commerce_test_notify_result'] ?? ['ok' => true];
        if (empty($mock['ok'])) {
            error_log('commerce_notify: TEST MODE simulated SMTP failure');
            return ['ok' => false, 'error' => (string) ($mock['error'] ?? 'smtp_test_fail'), 'sent' => false];
        }
        return ['ok' => true, 'sent' => true];
    }

    $fromEmail = (string) ($config['from_email'] ?? $config['smtp_username'] ?? '');
    $fromName = (string) ($config['from_name'] ?? 'LCRC eReview');
    if ($fromEmail === '') {
        return ['ok' => false, 'error' => 'from_email_missing', 'sent' => false];
    }

    $debug = [];
    $ok = sendMailSmtpHtml($toEmail, $subject, $html, $fromEmail, $fromName, $config, $debug);
    if (!$ok) {
        // Fallback plain text once.
        $ok = sendMailSmtp($toEmail, $subject, $plain, $fromEmail, $fromName, $config, $debug);
    }
    if (!$ok) {
        error_log('commerce_notify: SMTP send failed to student (details omitted)');
        return ['ok' => false, 'error' => 'smtp_send_failed', 'sent' => false];
    }
    return ['ok' => true, 'sent' => true];
}

function commerce_notify_wrap_html(string $title, string $innerHtml): string
{
    return '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#1e293b;line-height:1.5">'
        . '<h2 style="color:#0f172a">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>'
        . $innerHtml
        . '<p style="margin-top:24px;font-size:13px;color:#64748b">LCRC eReview Admin Team</p>'
        . '</body></html>';
}

/**
 * Stamp fulfillment email sent (only if still NULL).
 */
function commerce_notify_stamp_fulfillment_email(mysqli $conn, int $paymentId): bool
{
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE payments SET fulfillment_email_sent_at = NOW()
         WHERE payment_id = ?
           AND fulfilled_at IS NOT NULL
           AND fulfillment_email_sent_at IS NULL
         LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $paymentId);
    $ok = mysqli_stmt_execute($stmt);
    $aff = $ok ? (int) mysqli_stmt_affected_rows($stmt) : 0;
    mysqli_stmt_close($stmt);
    return $ok && $aff >= 0; // 0 means already stamped by race — still fine
}

/**
 * @return array{ok:bool,skipped?:bool,error?:string,sent?:bool}
 */
function commerce_notify_payment_fulfilled(mysqli $conn, int $paymentId): array
{
    if ($paymentId <= 0) {
        return ['ok' => false, 'error' => 'invalid_payment_id'];
    }
    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment) {
        return ['ok' => false, 'error' => 'payment_not_found'];
    }
    if (empty($payment['fulfilled_at'])) {
        return ['ok' => false, 'error' => 'not_fulfilled'];
    }
    if (!empty($payment['fulfillment_email_sent_at'])) {
        return ['ok' => true, 'skipped' => true, 'sent' => false, 'error' => 'already_sent'];
    }
    if ((string) ($payment['status'] ?? '') !== 'paid') {
        return ['ok' => false, 'error' => 'not_paid'];
    }

    $student = commerce_notify_load_student($conn, (int) ($payment['user_id'] ?? 0));
    if (empty($student['ok'])) {
        return ['ok' => false, 'error' => $student['error'] ?? 'student_load_failed'];
    }

    $config = commerce_notify_load_mail_config();
    if ($config === null) {
        error_log('commerce_notify: mail config missing/invalid; fulfillment email not sent');
        return ['ok' => false, 'error' => 'mail_config_invalid', 'sent' => false];
    }

    $name = htmlspecialchars((string) $student['full_name'], ENT_QUOTES, 'UTF-8');
    $ref = htmlspecialchars((string) ($payment['payment_ref'] ?? ''), ENT_QUOTES, 'UTF-8');
    $loginHint = htmlspecialchars(commerce_notify_login_hint(), ENT_QUOTES, 'UTF-8');
    $approved = ((string) $student['status'] === 'approved');

    $subject = 'LCRC eReview — Payment fulfilled';
    if ($approved) {
        $bodyHtml = "<p>Dear {$name},</p>"
            . '<p>Your payment has been <strong>fulfilled</strong> and LMS access has been granted for your purchase'
            . ($ref !== '' ? " (reference <strong>{$ref}</strong>)" : '') . '.</p>'
            . '<p>You may sign in using your registered account:</p>'
            . "<p><a href=\"{$loginHint}\">{$loginHint}</a></p>";
        $plain = "Dear {$student['full_name']},\n\nYour payment has been fulfilled and LMS access has been granted"
            . ($payment['payment_ref'] ? " (reference {$payment['payment_ref']})" : '') . ".\n\n"
            . "You may sign in at: " . commerce_notify_login_hint() . "\n\nLCRC eReview Admin Team\n";
    } else {
        $bodyHtml = "<p>Dear {$name},</p>"
            . '<p>Your payment has been <strong>successfully processed and fulfilled</strong>'
            . ($ref !== '' ? " (reference <strong>{$ref}</strong>)" : '')
            . ', and access has been recorded for your purchase.</p>'
            . '<p><strong>Important:</strong> Your student account login is still pending admin activation. '
            . 'Payment fulfillment does <em>not</em> automatically approve your login. '
            . 'You will receive a separate notice when your account is approved for sign-in.</p>';
        $plain = "Dear {$student['full_name']},\n\nYour payment has been successfully processed and fulfilled"
            . ($payment['payment_ref'] ? " (reference {$payment['payment_ref']})" : '') . ".\n\n"
            . "Important: Your student account login is still pending admin activation. "
            . "Payment fulfillment does not automatically approve your login.\n\n"
            . "LCRC eReview Admin Team\n";
    }

    $html = commerce_notify_wrap_html('Payment fulfilled', $bodyHtml);
    $sent = commerce_notify_dispatch((string) $student['email'], $subject, $html, $plain, $config);
    if (empty($sent['ok'])) {
        return ['ok' => false, 'error' => $sent['error'] ?? 'send_failed', 'sent' => false];
    }
    commerce_notify_stamp_fulfillment_email($conn, $paymentId);
    return ['ok' => true, 'sent' => true];
}

/**
 * Send pending fulfillment emails (already fulfilled, email not stamped). Never fulfills.
 *
 * @return array{ok:bool,processed:int,sent:int,skipped:int,failed:int,errors:list<string>}
 */
function commerce_notify_send_pending_fulfillment_emails(mysqli $conn, int $limit = 50): array
{
    $limit = max(1, min(500, $limit));
    $out = ['ok' => true, 'processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

    $sql = "SELECT p.payment_id
            FROM payments p
            INNER JOIN users u ON u.user_id = p.user_id AND u.role = 'student'
            WHERE p.fulfilled_at IS NOT NULL
              AND p.fulfillment_email_sent_at IS NULL
              AND p.status = 'paid'
              AND u.email IS NOT NULL
              AND u.email <> ''
            ORDER BY p.payment_id ASC
            LIMIT " . (int) $limit;
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return ['ok' => false, 'processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => ['select_failed']];
    }

    while ($row = mysqli_fetch_assoc($res)) {
        $pid = (int) ($row['payment_id'] ?? 0);
        $out['processed']++;
        $r = commerce_notify_payment_fulfilled($conn, $pid);
        if (!empty($r['ok']) && !empty($r['sent'])) {
            $out['sent']++;
        } elseif (!empty($r['ok']) && !empty($r['skipped'])) {
            $out['skipped']++;
        } else {
            $out['failed']++;
            $out['ok'] = false;
            $out['errors'][] = 'payment_id=' . $pid . ':' . (string) ($r['error'] ?? 'failed');
        }
    }
    mysqli_free_result($res);
    return $out;
}

/**
 * @return array{ok:bool,skipped?:bool,error?:string,sent?:bool}
 */
function commerce_notify_payment_rejected(mysqli $conn, int $paymentId): array
{
    if ($paymentId <= 0) {
        return ['ok' => false, 'error' => 'invalid_payment_id'];
    }
    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment) {
        return ['ok' => false, 'error' => 'payment_not_found'];
    }
    if ((string) ($payment['status'] ?? '') !== 'rejected'
        || (string) ($payment['verification_status'] ?? '') !== 'manually_rejected') {
        return ['ok' => false, 'error' => 'not_rejected'];
    }

    $student = commerce_notify_load_student($conn, (int) ($payment['user_id'] ?? 0));
    if (empty($student['ok'])) {
        return ['ok' => false, 'error' => $student['error'] ?? 'student_load_failed'];
    }
    $config = commerce_notify_load_mail_config();
    if ($config === null) {
        error_log('commerce_notify: mail config missing/invalid; rejection email not sent');
        return ['ok' => false, 'error' => 'mail_config_invalid', 'sent' => false];
    }

    $name = htmlspecialchars((string) $student['full_name'], ENT_QUOTES, 'UTF-8');
    $ref = htmlspecialchars((string) ($payment['payment_ref'] ?? ''), ENT_QUOTES, 'UTF-8');
    $subject = 'LCRC eReview — Payment not approved';
    $bodyHtml = "<p>Dear {$name},</p>"
        . '<p>We reviewed your payment submission'
        . ($ref !== '' ? " (reference <strong>{$ref}</strong>)" : '')
        . ' and it was <strong>not approved</strong>.</p>'
        . '<p>No LMS access was granted for this payment. If you believe this was a mistake, please contact LCRC eReview support.</p>';
    $plain = "Dear {$student['full_name']},\n\nYour payment submission"
        . ($payment['payment_ref'] ? " (reference {$payment['payment_ref']})" : '')
        . " was not approved.\n\nNo LMS access was granted for this payment.\n\nLCRC eReview Admin Team\n";
    $html = commerce_notify_wrap_html('Payment not approved', $bodyHtml);
    return commerce_notify_dispatch((string) $student['email'], $subject, $html, $plain, $config);
}

/**
 * @return array{ok:bool,skipped?:bool,error?:string,sent?:bool}
 */
function commerce_notify_far_approved(mysqli $conn, int $requestId, int $durationMonths): array
{
    if ($requestId <= 0) {
        return ['ok' => false, 'error' => 'invalid_request_id'];
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT r.request_id, r.request_ref, r.status, r.user_id, g.starts_at, g.ends_at
         FROM free_access_requests r
         LEFT JOIN access_grants g ON g.free_access_request_id = r.request_id
           AND g.content_type = 'full_lms' AND g.content_id = 0
         WHERE r.request_id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'far_prepare_failed'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $requestId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $far = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$far || (string) ($far['status'] ?? '') !== 'approved') {
        return ['ok' => false, 'error' => 'not_approved'];
    }

    $student = commerce_notify_load_student($conn, (int) ($far['user_id'] ?? 0));
    if (empty($student['ok'])) {
        return ['ok' => false, 'error' => $student['error'] ?? 'student_load_failed'];
    }
    $config = commerce_notify_load_mail_config();
    if ($config === null) {
        error_log('commerce_notify: mail config missing/invalid; FAR approval email not sent');
        return ['ok' => false, 'error' => 'mail_config_invalid', 'sent' => false];
    }

    $months = max(1, $durationMonths);
    $name = htmlspecialchars((string) $student['full_name'], ENT_QUOTES, 'UTF-8');
    $ref = htmlspecialchars((string) ($far['request_ref'] ?? ''), ENT_QUOTES, 'UTF-8');
    $loginHint = htmlspecialchars(commerce_notify_login_hint(), ENT_QUOTES, 'UTF-8');
    $approved = ((string) $student['status'] === 'approved');
    $ends = trim((string) ($far['ends_at'] ?? ''));
    $endsLabel = $ends !== '' ? date('F j, Y', strtotime($ends)) : ($months . ' month(s) from approval');

    $subject = 'LCRC eReview — Free Access approved';
    $bodyHtml = "<p>Dear {$name},</p>"
        . '<p>Your Free Access request'
        . ($ref !== '' ? " (<strong>{$ref}</strong>)" : '')
        . ' has been <strong>approved</strong>.</p>'
        . "<p>Approved duration: <strong>{$months} month(s)</strong>"
        . ($ends !== '' ? " (access recorded through approximately <strong>" . htmlspecialchars($endsLabel, ENT_QUOTES, 'UTF-8') . '</strong>)' : '')
        . '.</p>'
        . '<p>Full LMS access has been recorded for your Free Access grant.</p>';
    if ($approved) {
        $bodyHtml .= '<p>You may sign in using your registered account:</p>'
            . "<p><a href=\"{$loginHint}\">{$loginHint}</a></p>";
        $plainExtra = "You may sign in at: " . commerce_notify_login_hint() . "\n";
    } else {
        $bodyHtml .= '<p><strong>Important:</strong> Free Access approval does <em>not</em> automatically approve your student login account. '
            . 'Admin activation is still required before you can sign in. You will be notified separately when your account is approved.</p>';
        $plainExtra = "Important: Free Access approval does not automatically approve your student login account. "
            . "Admin activation is still required before you can sign in.\n";
    }
    $plain = "Dear {$student['full_name']},\n\nYour Free Access request"
        . ($far['request_ref'] ? " ({$far['request_ref']})" : '')
        . " has been approved.\n\nApproved duration: {$months} month(s).\n"
        . $plainExtra . "\nLCRC eReview Admin Team\n";
    $html = commerce_notify_wrap_html('Free Access approved', $bodyHtml);
    return commerce_notify_dispatch((string) $student['email'], $subject, $html, $plain, $config);
}

/**
 * @return array{ok:bool,skipped?:bool,error?:string,sent?:bool}
 */
function commerce_notify_far_rejected(mysqli $conn, int $requestId): array
{
    if ($requestId <= 0) {
        return ['ok' => false, 'error' => 'invalid_request_id'];
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT request_id, request_ref, status, user_id FROM free_access_requests WHERE request_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'far_prepare_failed'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $requestId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $far = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$far || (string) ($far['status'] ?? '') !== 'rejected') {
        return ['ok' => false, 'error' => 'not_rejected'];
    }

    $student = commerce_notify_load_student($conn, (int) ($far['user_id'] ?? 0));
    if (empty($student['ok'])) {
        return ['ok' => false, 'error' => $student['error'] ?? 'student_load_failed'];
    }
    $config = commerce_notify_load_mail_config();
    if ($config === null) {
        error_log('commerce_notify: mail config missing/invalid; FAR rejection email not sent');
        return ['ok' => false, 'error' => 'mail_config_invalid', 'sent' => false];
    }

    $name = htmlspecialchars((string) $student['full_name'], ENT_QUOTES, 'UTF-8');
    $ref = htmlspecialchars((string) ($far['request_ref'] ?? ''), ENT_QUOTES, 'UTF-8');
    $subject = 'LCRC eReview — Free Access not approved';
    $bodyHtml = "<p>Dear {$name},</p>"
        . '<p>Your Free Access request'
        . ($ref !== '' ? " (<strong>{$ref}</strong>)" : '')
        . ' was <strong>not approved</strong>.</p>'
        . '<p>No Free Access LMS grant was created for this request.</p>';
    $plain = "Dear {$student['full_name']},\n\nYour Free Access request"
        . ($far['request_ref'] ? " ({$far['request_ref']})" : '')
        . " was not approved.\n\nNo Free Access LMS grant was created.\n\nLCRC eReview Admin Team\n";
    $html = commerce_notify_wrap_html('Free Access not approved', $bodyHtml);
    return commerce_notify_dispatch((string) $student['email'], $subject, $html, $plain, $config);
}
