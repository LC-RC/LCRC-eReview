<?php
require_once 'auth.php';
requireRole('admin');

$userId = sanitizeInt($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    exit('Bad Request');
}

$stmt = mysqli_prepare($conn, "SELECT payment_proof FROM users WHERE user_id=? AND role='student' LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$row || empty($row['payment_proof'])) {
    http_response_code(404);
    exit('Not Found');
}

$relative = (string)$row['payment_proof'];

// Only allow files inside uploads/
if (strpos($relative, 'uploads/') !== 0) {
    http_response_code(403);
    exit('Forbidden');
}

$physicalPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
if (!file_exists($physicalPath) || !is_file($physicalPath)) {
    http_response_code(404);
    exit('Not Found');
}

$mime = function_exists('mime_content_type') ? mime_content_type($physicalPath) : 'application/octet-stream';
$filename = basename($physicalPath);

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($physicalPath));
header('Content-Disposition: inline; filename="' . $filename . '"');

readfile($physicalPath);
exit;

