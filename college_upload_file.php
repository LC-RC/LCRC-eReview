<?php
/**
 * Authenticated inline view/download of a college submission file.
 * GET s = submission_id. Allowed: owning college_student or professor who created the task.
 */
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/includes/college_upload_helpers.php';

$role = (string)($_SESSION['role'] ?? '');
$uid = (int)getCurrentUserId();
$sid = sanitizeInt($_GET['s'] ?? 0);

if ($sid <= 0 || ($role !== 'college_student' && $role !== 'professor_admin')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

$row = college_upload_fetch_submission_for_access($conn, $sid);
if (!$row || !college_upload_user_can_read_submission($role, $uid, $row)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

$full = college_upload_resolve_storage_path(__DIR__, $row['file_path']);
if ($full === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'File not found';
    exit;
}

$ext = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
if (!college_upload_extension_is_allowed($ext)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

$mime = college_upload_mime_for_extension($ext);
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file($full);
    if ($detected !== false && in_array($detected, college_upload_allowed_mime_types(), true)) {
        $mime = $detected;
    }
}

$dlName = preg_replace('/[^\p{L}\p{N}\._\- ]+/u', '_', (string)$row['file_name']);
if ($dlName === '' || $dlName === '_') {
    $dlName = 'submission.' . $ext;
}

$inline = ($_GET['download'] ?? '') !== '1';

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $dlName) . '"');
header('Content-Length: ' . (string)filesize($full));
readfile($full);
exit;
