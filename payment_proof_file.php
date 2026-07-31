<?php
/**
 * Serve payment proof for checkout session owner OR admin (read-only Phase 6).
 * Does not grant LMS access. Path must stay under uploads/payment_proofs/.
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/commerce_payment.php';

$payment = null;

$checkout = commerce_require_checkout_session($conn);
if (!empty($checkout['ok'])) {
    $payment = $checkout['payment'];
} elseif (!empty($_SESSION['user_id']) && function_exists('hasRole') && hasRole('admin')) {
    $pid = (int) ($_GET['payment_id'] ?? 0);
    if ($pid <= 0) {
        http_response_code(400);
        exit('Bad Request');
    }
    $payment = commerce_get_payment($conn, $pid);
    if (!$payment) {
        http_response_code(404);
        exit('Not Found');
    }
} else {
    http_response_code(403);
    exit('Forbidden');
}

$relative = (string) ($payment['proof_path'] ?? '');
if ($relative === '' || strpos($relative, COMMERCE_PROOF_DIR_REL . '/') !== 0) {
    http_response_code(404);
    exit('Not Found');
}

if (strpos($relative, '..') !== false || preg_match('#^[a-z0-9_./-]+$#i', $relative) !== 1) {
    http_response_code(403);
    exit('Forbidden');
}

$physicalPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
$proofRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, COMMERCE_PROOF_DIR_REL));
$realFile = realpath($physicalPath);
if ($proofRoot === false || $realFile === false || strncmp($realFile, $proofRoot, strlen($proofRoot)) !== 0 || !is_file($realFile)) {
    http_response_code(404);
    exit('Not Found');
}

$mime = (string) ($payment['proof_mime'] ?? '');
if ($mime === '' && function_exists('mime_content_type')) {
    $mime = (string) mime_content_type($realFile);
}
if ($mime === '') {
    $mime = 'application/octet-stream';
}

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($realFile));
header('Content-Disposition: inline; filename="' . basename($realFile) . '"');
readfile($realFile);
exit;
