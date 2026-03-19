<?php
/**
 * Stream Test Bank file for inline viewing (no download link in UI).
 * Used inside iframe so students view documents in-page. Requires login (student or admin).
 */
ob_start();
require_once 'auth.php';
requireLogin();
if (!hasRole('student') && !hasRole('admin')) {
    ob_end_clean();
    http_response_code(403);
    exit('Forbidden');
}

$id = (int)($_GET['id'] ?? 0);
$typeParam = isset($_GET['type']) ? trim($_GET['type']) : '';
$type = '';
if ($typeParam === 'question' || $typeParam === '1' || $typeParam === 'q') {
    $type = 'question';
} elseif ($typeParam === 'solution' || $typeParam === '2' || $typeParam === 's' || $typeParam === 'solutions') {
    $type = 'solution';
}
if ($id <= 0 || $type === '') {
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Bad Request');
}

$conn = $conn ?? null;
if (!$conn) {
    require_once __DIR__ . '/db.php';
}
$stmt = mysqli_prepare($conn, "SELECT question_file_path, solution_file_path FROM test_bank WHERE id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$row) {
    ob_end_clean();
    http_response_code(404);
    exit('Not Found');
}

$path = $type === 'question' ? ($row['question_file_path'] ?? '') : ($row['solution_file_path'] ?? '');
if ($path === '' || $path === null) {
    ob_end_clean();
    http_response_code(404);
    exit('File not found');
}

$physicalPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
if (!file_exists($physicalPath) || !is_readable($physicalPath)) {
    ob_end_clean();
    http_response_code(404);
    exit('File not found');
}

$ext = strtolower(pathinfo($physicalPath, PATHINFO_EXTENSION));
$mimes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'txt'  => 'text/plain',
];
$contentType = $mimes[$ext] ?? 'application/octet-stream';

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="' . basename($physicalPath) . '"');
header('Content-Length: ' . filesize($physicalPath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($physicalPath);
exit;
