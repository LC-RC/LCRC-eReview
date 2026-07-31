<?php
/**
 * Phase 5/6 — Submit GCash reference + payment proof, then run receipt verification.
 * Verification failure never rolls back a successful submission.
 * Does NOT fulfill access / SCA / activation.
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/url_helpers.php';
require_once __DIR__ . '/includes/commerce_payment.php';
require_once __DIR__ . '/includes/commerce_verification.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . ereview_url('payment_checkout'));
    exit;
}

if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
    $_SESSION['checkout_error'] = 'Invalid request. Please refresh and try again.';
    header('Location: ' . ereview_url('payment_checkout'));
    exit;
}

$auth = commerce_require_checkout_session($conn);
if (empty($auth['ok'])) {
    $_SESSION['checkout_error'] = $auth['error'] ?? 'Checkout session expired.';
    header('Location: ' . ereview_url('payment_checkout'));
    exit;
}

$payment = $auth['payment'];
$sessionPaymentId = (int) $payment['payment_id'];
$postedPaymentId = (int) ($_POST['payment_id'] ?? 0);
if ($postedPaymentId !== $sessionPaymentId) {
    $_SESSION['checkout_error'] = 'Payment mismatch. Please try again from your checkout page.';
    header('Location: ' . ereview_url('payment_checkout'));
    exit;
}

$userId = (int) ($_SESSION['checkout_user_id'] ?? 0);
$gcashRaw = (string) ($_POST['gcash_reference'] ?? '');
$file = isset($_FILES['payment_proof']) && is_array($_FILES['payment_proof']) ? $_FILES['payment_proof'] : null;

$result = commerce_submit_payment_proof_and_reference($conn, $sessionPaymentId, $userId, $gcashRaw, $file);
if (empty($result['ok'])) {
    $_SESSION['checkout_error'] = $result['error'] ?? 'Could not submit payment.';
    header('Location: ' . ereview_url('payment_checkout'));
    exit;
}

// Phase 6: sync verification AFTER successful submit. Never undo submission on OCR failure.
try {
    $verify = commerce_verify_payment($conn, $sessionPaymentId, []);
    $_SESSION['checkout_verify_decision'] = (string) ($verify['decision'] ?? '');
    if (!empty($verify['error']) && empty($verify['ok']) && ($verify['decision'] ?? '') === '') {
        $_SESSION['checkout_verify_decision'] = 'needs_review';
    }
} catch (Throwable $e) {
    error_log('payment_checkout_submit verification: ' . $e->getMessage());
    $_SESSION['checkout_verify_decision'] = 'needs_review';
}

$_SESSION['checkout_success'] = 1;
header('Location: ' . ereview_url('payment_checkout'));
exit;
