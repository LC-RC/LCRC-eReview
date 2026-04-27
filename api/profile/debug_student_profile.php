<?php
require_once __DIR__ . '/../../auth.php';
requireRole('student');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$sql = "SELECT user_id, full_name, review_type, school, school_other, payment_proof, email, role, status
        FROM users
        WHERE user_id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to prepare debug query']);
    exit;
}

mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Student row not found']);
    exit;
}

$reviewTypeRaw = strtolower(trim((string)($row['review_type'] ?? '')));
$reviewTypeDisplay = 'Not set';
if ($reviewTypeRaw === 'undergrad') {
    $reviewTypeDisplay = 'Undergrad';
} elseif ($reviewTypeRaw === 'reviewee') {
    $reviewTypeDisplay = 'Reviewee';
} elseif ($reviewTypeRaw !== '') {
    $reviewTypeDisplay = ucwords(str_replace('_', ' ', $reviewTypeRaw));
}

$schoolRaw = trim((string)($row['school'] ?? ''));
$schoolOtherRaw = trim((string)($row['school_other'] ?? ''));
$schoolDisplay = $schoolRaw;
if ($schoolRaw === 'Other' && $schoolOtherRaw !== '') {
    $schoolDisplay = $schoolOtherRaw;
}
if ($schoolDisplay === '' && $schoolOtherRaw !== '') {
    $schoolDisplay = $schoolOtherRaw;
}
if ($schoolDisplay === '') {
    $schoolDisplay = 'Not set';
}

$paymentProofRaw = trim((string)($row['payment_proof'] ?? ''));
$hasPaymentProof = $paymentProofRaw !== '';

echo json_encode([
    'ok' => true,
    'debug' => [
        'queried_at' => date('c'),
        'user_id' => (int)$row['user_id'],
        'raw' => [
            'full_name' => (string)($row['full_name'] ?? ''),
            'review_type' => (string)($row['review_type'] ?? ''),
            'school' => (string)($row['school'] ?? ''),
            'school_other' => (string)($row['school_other'] ?? ''),
            'payment_proof' => (string)($row['payment_proof'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'role' => (string)($row['role'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
        ],
        'display' => [
            'full_name' => trim((string)($row['full_name'] ?? '')) ?: 'Not set',
            'review_type' => $reviewTypeDisplay,
            'school' => $schoolDisplay,
            'proof_of_payment' => $hasPaymentProof ? 'Uploaded' : 'Not uploaded',
            'has_payment_proof' => $hasPaymentProof,
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

