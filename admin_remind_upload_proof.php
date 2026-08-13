<?php
/**
 * Admin: Remind student to upload payment proof (email with durable checkout link).
 * Does NOT grant access or close the payment.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/commerce_catalog.php';
require_once __DIR__ . '/includes/commerce_payment.php';
require_once __DIR__ . '/includes/commerce_notifications.php';

$isAjax = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string) $_SERVER['HTTP_ACCEPT'], 'application/json'))
);

function admin_remind_upload_respond(bool $ok, string $message, array $extra = []): void
{
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
        exit;
    }
    if ($ok) {
        $_SESSION['message'] = $message;
    } else {
        $_SESSION['error'] = $message;
    }
    $returnTo = trim((string) ($_POST['return_to'] ?? 'admin_students'));
    if ($returnTo === '' || str_contains($returnTo, '://') || str_starts_with($returnTo, '//')) {
        $returnTo = 'admin_students';
    }
    header('Location: ' . ereview_url($returnTo));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_remind_upload_respond(false, 'Invalid method.');
}
if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
    admin_remind_upload_respond(false, 'Invalid request (CSRF).');
}
if (!commerce_schema_ready($conn)) {
    admin_remind_upload_respond(false, 'Commerce schema is not installed.');
}

$adminId = (int) ($_SESSION['user_id'] ?? 0);
$userId = (int) ($_POST['user_id'] ?? 0);
if ($userId <= 0 || $adminId <= 0) {
    admin_remind_upload_respond(false, 'Invalid student.');
}

$ust = mysqli_prepare($conn, "SELECT user_id, role, status, full_name, enrollment_path FROM users WHERE user_id = ? LIMIT 1");
if (!$ust) {
    admin_remind_upload_respond(false, 'Could not load student.');
}
mysqli_stmt_bind_param($ust, 'i', $userId);
mysqli_stmt_execute($ust);
$ures = mysqli_stmt_get_result($ust);
$user = $ures ? mysqli_fetch_assoc($ures) : null;
mysqli_stmt_close($ust);
if (!$user || (string) ($user['role'] ?? '') !== 'student') {
    admin_remind_upload_respond(false, 'Student not found.');
}
if (strtolower((string) ($user['status'] ?? '')) === 'rejected') {
    admin_remind_upload_respond(false, 'Rejected students cannot be reminded.');
}

$paymentId = (int) ($_POST['payment_id'] ?? 0);
$payment = null;
if ($paymentId > 0) {
    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment || (int) ($payment['user_id'] ?? 0) !== $userId) {
        admin_remind_upload_respond(false, 'Payment not found for this student.');
    }
} else {
    $payment = commerce_find_awaiting_proof_payment_for_user($conn, $userId);
}
if (!$payment) {
    admin_remind_upload_respond(false, 'No open payment awaiting proof for this student.');
}
$paymentId = (int) ($payment['payment_id'] ?? 0);
if ((string) ($payment['status'] ?? '') !== 'awaiting_proof') {
    admin_remind_upload_respond(false, 'Payment is not awaiting proof upload.');
}
if (trim((string) ($payment['proof_path'] ?? '')) !== '') {
    admin_remind_upload_respond(false, 'Proof was already uploaded. Review it in Payment Verification.');
}

$link = commerce_create_checkout_resume_link($conn, $userId, $paymentId, $adminId);
if (empty($link['ok'])) {
    admin_remind_upload_respond(false, 'Could not create upload link (' . (string) ($link['error'] ?? 'error') . ').');
}

$packageLabel = '';
$amountLabel = '';
$items = commerce_get_payment_items($conn, $paymentId);
if ($items !== []) {
    $names = [];
    $total = 0;
    foreach ($items as $it) {
        $n = trim((string) ($it['item_name'] ?? ''));
        if ($n !== '') {
            $names[] = $n;
        }
        $total += (int) ($it['line_total_centavos'] ?? $it['unit_amount_centavos'] ?? 0);
    }
    if ($names !== []) {
        $packageLabel = implode(', ', $names);
    }
    if ($total > 0) {
        $amountLabel = '₱' . number_format($total / 100, 2);
    }
}
if ($amountLabel === '') {
    $cents = (int) ($payment['expected_amount_centavos'] ?? $payment['amount_centavos'] ?? 0);
    if ($cents > 0) {
        $amountLabel = '₱' . number_format($cents / 100, 2);
    }
}
if ($packageLabel === '') {
    $path = (string) ($user['enrollment_path'] ?? $payment['purchase_type'] ?? '');
    $packageLabel = $path !== '' ? ucwords(str_replace('_', ' ', $path)) : 'Paid enrollment';
}

$sent = commerce_notify_upload_proof_reminder($conn, $userId, [
    'upload_url' => (string) ($link['url'] ?? ''),
    'expires_at' => (string) ($link['expires_at'] ?? ''),
    'payment' => $payment,
    'package_label' => $packageLabel,
    'amount_label' => $amountLabel,
]);

if (empty($sent['ok']) || empty($sent['sent'])) {
    admin_remind_upload_respond(false, 'Upload link created but email failed (' . (string) ($sent['error'] ?? 'smtp') . '). Check mail config.', [
        'payment_id' => $paymentId,
        'link_url' => (string) ($link['url'] ?? ''),
    ]);
}

$who = trim((string) ($user['full_name'] ?? 'Student'));
admin_remind_upload_respond(true, 'Reminder emailed to ' . $who . ' with a proof upload link (valid 7 days).', [
    'payment_id' => $paymentId,
    'user_id' => $userId,
    'expires_at' => (string) ($link['expires_at'] ?? ''),
]);
