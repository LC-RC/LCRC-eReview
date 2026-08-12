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
    return $ok && $aff >= 0; // 0 means already stamped by race - still fine
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

    $durationPlain = '';
    $endsQ = mysqli_prepare(
        $conn,
        "SELECT MAX(ends_at) AS ends_at
         FROM access_grants
         WHERE payment_id = ?
           AND status = 'active'"
    );
    if ($endsQ) {
        mysqli_stmt_bind_param($endsQ, 'i', $paymentId);
        mysqli_stmt_execute($endsQ);
        $endsR = mysqli_stmt_get_result($endsQ);
        $endsRow = $endsR ? mysqli_fetch_assoc($endsR) : null;
        mysqli_stmt_close($endsQ);
        $endsRaw = trim((string) ($endsRow['ends_at'] ?? ''));
        if ($endsRaw !== '') {
            $endsTs = strtotime($endsRaw);
            if ($endsTs !== false) {
                $durationPlain = 'until ' . date('F j, Y', $endsTs);
            }
        }
    }

    $subject = 'LCRC eReview - Access granted (payment fulfilled)';
    $durationHtml = $durationPlain !== ''
        ? '<p><strong>Access duration:</strong> ' . htmlspecialchars($durationPlain, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';
    $durationPlainLine = $durationPlain !== '' ? ("Access duration: {$durationPlain}\n") : '';
    if ($approved) {
        $bodyHtml = "<p>Dear {$name},</p>"
            . '<p>Your payment has been <strong>fulfilled</strong> and LMS access has been <strong>granted</strong> for your purchase'
            . ($ref !== '' ? " (reference <strong>{$ref}</strong>)" : '') . '.</p>'
            . $durationHtml
            . '<p>You may sign in using your registered account:</p>'
            . "<p><a href=\"{$loginHint}\">{$loginHint}</a></p>";
        $plain = "Dear {$student['full_name']},\n\nYour payment has been fulfilled and LMS access has been granted"
            . ($payment['payment_ref'] ? " (reference {$payment['payment_ref']})" : '') . ".\n"
            . $durationPlainLine
            . "\nYou may sign in at: " . commerce_notify_login_hint() . "\n\nLCRC eReview Admin Team\n";
    } else {
        $bodyHtml = "<p>Dear {$name},</p>"
            . '<p>Your payment has been <strong>successfully processed and fulfilled</strong>'
            . ($ref !== '' ? " (reference <strong>{$ref}</strong>)" : '')
            . ', and access has been recorded for your purchase.</p>'
            . $durationHtml
            . '<p><strong>Important:</strong> Your student account login is still pending admin activation. '
            . 'Payment fulfillment does <em>not</em> automatically approve your login. '
            . 'You will receive a separate notice when your account is approved for sign-in.</p>';
        $plain = "Dear {$student['full_name']},\n\nYour payment has been successfully processed and fulfilled"
            . ($payment['payment_ref'] ? " (reference {$payment['payment_ref']})" : '') . ".\n"
            . $durationPlainLine
            . "\nImportant: Your student account login is still pending admin activation. "
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
    $subject = 'LCRC eReview - Payment not approved';
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

    $subject = 'LCRC eReview - Free Access approved';
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
 * Email student a durable link to upload GCash proof (Remind → Upload Proof flow).
 * Does not grant access. SMTP failure is best-effort only.
 *
 * @param array{upload_url:string,expires_at?:string,payment?:array<string,mixed>,amount_label?:string,package_label?:string}
 * @return array{ok:bool,error?:string,sent?:bool}
 */
function commerce_notify_upload_proof_reminder(mysqli $conn, int $userId, array $opts): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user', 'sent' => false];
    }
    $uploadUrl = trim((string) ($opts['upload_url'] ?? ''));
    if ($uploadUrl === '') {
        return ['ok' => false, 'error' => 'upload_url_missing', 'sent' => false];
    }

    $student = commerce_notify_load_student($conn, $userId);
    if (empty($student['ok'])) {
        return ['ok' => false, 'error' => $student['error'] ?? 'student_load_failed', 'sent' => false];
    }

    $config = commerce_notify_load_mail_config();
    if ($config === null) {
        error_log('commerce_notify: mail config missing/invalid; upload proof reminder not sent');
        return ['ok' => false, 'error' => 'mail_config_invalid', 'sent' => false];
    }

    $name = htmlspecialchars((string) $student['full_name'], ENT_QUOTES, 'UTF-8');
    $urlEsc = htmlspecialchars($uploadUrl, ENT_QUOTES, 'UTF-8');
    $expiresRaw = trim((string) ($opts['expires_at'] ?? ''));
    $expiresLabel = '';
    if ($expiresRaw !== '') {
        $ets = strtotime($expiresRaw);
        if ($ets !== false) {
            $expiresLabel = date('F j, Y g:i A', $ets);
        }
    }
    $packageLabel = trim((string) ($opts['package_label'] ?? ''));
    $amountLabel = trim((string) ($opts['amount_label'] ?? ''));
    $payment = is_array($opts['payment'] ?? null) ? $opts['payment'] : [];
    $ref = trim((string) ($payment['payment_ref'] ?? ''));

    $detailsHtml = '';
    $detailsPlain = '';
    if ($packageLabel !== '') {
        $detailsHtml .= '<li><strong>Enrollment:</strong> ' . htmlspecialchars($packageLabel, ENT_QUOTES, 'UTF-8') . '</li>';
        $detailsPlain .= "Enrollment: {$packageLabel}\n";
    }
    if ($amountLabel !== '') {
        $detailsHtml .= '<li><strong>Amount:</strong> ' . htmlspecialchars($amountLabel, ENT_QUOTES, 'UTF-8') . '</li>';
        $detailsPlain .= "Amount: {$amountLabel}\n";
    }
    if ($ref !== '') {
        $detailsHtml .= '<li><strong>Payment reference:</strong> ' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</li>';
        $detailsPlain .= "Payment reference: {$ref}\n";
    }

    $subject = 'LCRC eReview - Please upload your payment proof';
    $bodyHtml = "<p>Dear {$name},</p>"
        . '<p>Your registration is waiting for <strong>GCash payment proof</strong> before we can activate your LMS access.</p>';
    if ($detailsHtml !== '') {
        $bodyHtml .= '<ul>' . $detailsHtml . '</ul>';
    }
    $bodyHtml .= '<p>Please complete payment (if you have not yet) and upload your proof using this secure link:</p>'
        . "<p><a href=\"{$urlEsc}\" style=\"display:inline-block;padding:10px 16px;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:6px;font-weight:700\">Upload payment proof</a></p>"
        . "<p style=\"font-size:13px;color:#64748b\">Or copy this link:<br>{$urlEsc}</p>";
    if ($expiresLabel !== '') {
        $bodyHtml .= '<p style="font-size:13px;color:#64748b">This link expires on <strong>'
            . htmlspecialchars($expiresLabel, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
    }
    $bodyHtml .= '<p>After you upload, our team will review your payment and grant access. You will receive another email when access is ready.</p>';

    $plain = "Dear {$student['full_name']},\n\n"
        . "Your registration is waiting for GCash payment proof before we can activate your LMS access.\n"
        . $detailsPlain
        . "\nUpload your proof here:\n{$uploadUrl}\n"
        . ($expiresLabel !== '' ? ("\nThis link expires on {$expiresLabel}.\n") : '')
        . "\nAfter you upload, our team will review your payment and grant access.\n\nLCRC eReview Admin Team\n";

    $html = commerce_notify_wrap_html('Upload payment proof', $bodyHtml);
    return commerce_notify_dispatch((string) $student['email'], $subject, $html, $plain, $config);
}

/**
 * Notify student after administrative Grant Access (email + in-app message).
 * Safe to call when payment had no proof - SMTP/notification failure never undoes the grant.
 *
 * @param array{months?:int,scope?:string,no_proof?:bool,payment_closed?:bool,ends_at?:string,grant_id?:int} $opts
 * @return array{ok:bool,error?:string,sent?:bool,in_app?:bool}
 */
function commerce_notify_admin_manual_grant(mysqli $conn, int $userId, int $adminId = 0, array $opts = []): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user'];
    }

    $student = commerce_notify_load_student($conn, $userId);
    if (empty($student['ok'])) {
        return ['ok' => false, 'error' => $student['error'] ?? 'student_load_failed', 'sent' => false, 'in_app' => false];
    }

    // Refresh status after activation so login copy is accurate.
    $refreshed = commerce_notify_load_student($conn, $userId);
    if (!empty($refreshed['ok'])) {
        $student = $refreshed;
    }

    $months = max(1, (int) ($opts['months'] ?? 6));
    $scope = strtolower(trim((string) ($opts['scope'] ?? 'full_lms')));
    $scopeLabel = $scope === 'by_topic' ? 'selected topics' : 'Full LMS';
    $noProof = !empty($opts['no_proof']);
    $endsAtRaw = trim((string) ($opts['ends_at'] ?? ''));
    $endsLabel = '';
    if ($endsAtRaw !== '') {
        $endsTs = strtotime($endsAtRaw);
        if ($endsTs !== false) {
            $endsLabel = date('F j, Y', $endsTs);
        }
    }
    $durationPlain = $months . ' month' . ($months === 1 ? '' : 's');
    if ($endsLabel !== '') {
        $durationPlain .= ' (until ' . $endsLabel . ')';
    }
    $loginHint = htmlspecialchars(commerce_notify_login_hint(), ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars((string) $student['full_name'], ENT_QUOTES, 'UTF-8');
    $approved = ((string) ($student['status'] ?? '') === 'approved');

    $inApp = false;
    if (is_file(__DIR__ . '/notification_helpers.php')) {
        require_once __DIR__ . '/notification_helpers.php';
        if (function_exists('notifications_create_for_user')) {
            $title = 'Access granted';
            $msg = 'Your LMS access has been granted: ' . $scopeLabel . ' for ' . $durationPlain . '.';
            if ($noProof) {
                $msg .= ' Manual approval - payment proof was not required.';
            }
            if ($approved) {
                $msg .= ' You can sign in now.';
            } else {
                $msg .= ' Try signing in shortly if login is not active yet.';
            }
            $inApp = notifications_create_for_user(
                $conn,
                $userId,
                'student',
                $title,
                $msg,
                'login',
                'access_granted',
                $adminId > 0 ? $adminId : null
            );
        }
    }

    $config = commerce_notify_load_mail_config();
    if ($config === null) {
        error_log('commerce_notify: mail config missing/invalid; admin grant email not sent');
        return [
            'ok' => $inApp,
            'error' => 'mail_config_invalid',
            'sent' => false,
            'in_app' => $inApp,
        ];
    }

    $subject = 'LCRC eReview - Access granted';
    $bodyHtml = "<p>Dear {$name},</p>"
        . '<p>Good news - your <strong>LMS access has been granted</strong>.</p>'
        . '<ul>'
        . '<li><strong>Access type:</strong> ' . htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li><strong>Duration:</strong> ' . htmlspecialchars($durationPlain, ENT_QUOTES, 'UTF-8') . '</li>'
        . '</ul>';
    if ($noProof) {
        $bodyHtml .= '<p>This was a <strong>manual approval</strong>. A payment proof upload was not required for this activation.</p>';
    }
    if ($approved) {
        $bodyHtml .= '<p>Your account is active. You may sign in here:</p>'
            . "<p><a href=\"{$loginHint}\">{$loginHint}</a></p>";
        $plainExtra = 'Your account is active. Sign in at: ' . commerce_notify_login_hint() . "\n";
    } else {
        $bodyHtml .= '<p>Please try signing in shortly. If you cannot log in yet, contact LCRC eReview support.</p>';
        $plainExtra = "Please try signing in shortly. If you cannot log in yet, contact support.\n";
    }
    $plain = "Dear {$student['full_name']},\n\nYour LMS access has been granted.\n"
        . "Access type: {$scopeLabel}\n"
        . "Duration: {$durationPlain}\n"
        . ($noProof ? "Manual approval - payment proof was not required.\n" : '')
        . $plainExtra
        . "\nLCRC eReview Admin Team\n";
    $html = commerce_notify_wrap_html('Access granted', $bodyHtml);
    $sent = commerce_notify_dispatch((string) $student['email'], $subject, $html, $plain, $config);

    return [
        'ok' => !empty($sent['ok']) || $inApp,
        'error' => empty($sent['ok']) ? (string) ($sent['error'] ?? 'send_failed') : null,
        'sent' => !empty($sent['ok']),
        'in_app' => $inApp,
    ];
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
    $subject = 'LCRC eReview - Free Access not approved';
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
