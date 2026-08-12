<?php
/**
 * Logged handout download redirect (lesson + preweek).
 */
require_once 'auth.php';
requireRole('student');
require_once __DIR__ . '/includes/student_activity.php';

$handoutId = sanitizeInt($_GET['handout_id'] ?? 0);
$preweekHandoutId = sanitizeInt($_GET['preweek_handout_id'] ?? 0);

$handout = null;
$lessonId = null;
$subjectId = null;

if ($preweekHandoutId > 0) {
    require_once __DIR__ . '/includes/preweek_migrate.php';
    $stmt = mysqli_prepare(
        $conn,
        'SELECT h.handout_title, h.file_path, h.allow_download, h.preweek_topic_id, t.subject_id
         FROM preweek_handouts h
         LEFT JOIN preweek_topics t ON t.preweek_topic_id = h.preweek_topic_id
         WHERE h.preweek_handout_id = ? LIMIT 1'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $preweekHandoutId);
        mysqli_stmt_execute($stmt);
        $handout = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);
    }
    if ($handout) {
        $subjectId = (int) ($handout['subject_id'] ?? 0) ?: null;
    }
} elseif ($handoutId > 0) {
    $stmt = mysqli_prepare(
        $conn,
        'SELECT h.handout_title, h.file_path, h.allow_download, h.lesson_id, l.subject_id
         FROM lesson_handouts h
         LEFT JOIN lessons l ON l.lesson_id = h.lesson_id
         WHERE h.handout_id = ? LIMIT 1'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $handoutId);
        mysqli_stmt_execute($stmt);
        $handout = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);
    }
    if ($handout) {
        $lessonId = (int) ($handout['lesson_id'] ?? 0) ?: null;
        $subjectId = (int) ($handout['subject_id'] ?? 0) ?: null;
    }
}

if (!$handout || empty($handout['file_path'])) {
    http_response_code(404);
    exit('Not Found');
}

if ((int) ($handout['allow_download'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Downloads are locked for this handout.');
}

$file = (string) $handout['file_path'];
$title = (string) ($handout['handout_title'] ?: 'Handout');
$userId = (int) getCurrentUserId();

student_activity_log_event($conn, $userId, 'handout_download', [
    'handout_id' => $handoutId > 0 ? $handoutId : $preweekHandoutId,
    'lesson_id' => $lessonId,
    'subject_id' => $subjectId,
    'page_key' => 'handout_download',
    'page_title' => $title,
    'page_url' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'meta' => ['preweek' => $preweekHandoutId > 0 ? 1 : 0],
]);
student_activity_touch_session($conn, $userId, [
    'page_key' => 'handout_download',
    'page_title' => $title,
    'page_url' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'subject_id' => $subjectId,
    'lesson_id' => $lessonId,
]);

$physicalPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($file, '/\\'));
$realBase = realpath(__DIR__);
$realFile = is_file($physicalPath) ? realpath($physicalPath) : false;
if ($realBase === false || $realFile === false || strncmp($realFile, $realBase, strlen($realBase)) !== 0) {
    // Fall back to relative URL redirect if file lives under web root aliases
    header('Location: ' . $file);
    exit;
}

$mime = 'application/octet-stream';
if (function_exists('mime_content_type')) {
    $detected = @mime_content_type($realFile);
    if (is_string($detected) && $detected !== '') {
        $mime = $detected;
    }
}
$basename = basename($realFile);
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $basename) . '"');
header('Content-Length: ' . (string) filesize($realFile));
header('X-Content-Type-Options: nosniff');
readfile($realFile);
exit;
