<?php
/**
 * JSON: lesson_id => Vimeo thumbnail URL (async load from Materials tab).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/vimeo_helpers.php';
requireRole('student');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$subjectId = sanitizeInt($_GET['subject_id'] ?? 0);
if ($subjectId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'bad_subject']);
    exit;
}

$chk = mysqli_query($conn, 'SELECT subject_id FROM subjects WHERE subject_id=' . (int)$subjectId . ' LIMIT 1');
$sub = ($chk && mysqli_fetch_assoc($chk)) ? true : false;
if (!$sub) {
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

try {
    $thumbs = ereview_lesson_vimeo_thumbnails_for_subject($conn, $subjectId);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'server']);
    exit;
}

$out = [];
foreach ($thumbs as $lid => $url) {
    $out[(string)(int)$lid] = $url;
}

echo json_encode(['ok' => true, 'thumbs' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
