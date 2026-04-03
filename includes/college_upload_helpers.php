<?php
/**
 * College assignment uploads — shared rules for professor + student pages.
 * Allowed: PDF + JPG/PNG only (no GIF/WebP/documents/archives).
 */

/**
 * Parse MySQL datetime string to Unix timestamp using app timezone (Asia/Manila via date_default_timezone).
 *
 * @return int|false
 */
function college_upload_deadline_to_timestamp(?string $deadlineSql)
{
    if ($deadlineSql === null || $deadlineSql === '') {
        return false;
    }
    $s = trim($deadlineSql);
    if ($s === '' || strpos($s, '0000-00-00') === 0) {
        return false;
    }
    $ts = strtotime($s);

    return $ts === false ? false : $ts;
}

/**
 * True when the deadline instant has passed (submissions no longer accepted).
 */
function college_upload_deadline_has_passed(?string $deadlineSql): bool
{
    $ts = college_upload_deadline_to_timestamp($deadlineSql);
    if ($ts === false) {
        return true;
    }

    return time() > $ts;
}

/**
 * True while students may still upload (inclusive of deadline second).
 */
function college_upload_deadline_allows_upload(?string $deadlineSql): bool
{
    $ts = college_upload_deadline_to_timestamp($deadlineSql);
    if ($ts === false) {
        return false;
    }

    return time() <= $ts;
}

/**
 * Short plain-text preview for task list tiles (strip idea of newlines).
 */
function college_upload_instruction_excerpt(?string $instructions, int $maxChars = 100): string
{
    if ($instructions === null || $instructions === '') {
        return '';
    }
    $t = preg_replace('/\s+/u', ' ', trim($instructions));
    if ($t === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($t) <= $maxChars) {
            return $t;
        }

        return rtrim(mb_substr($t, 0, $maxChars - 1)) . '…';
    }
    if (strlen($t) <= $maxChars) {
        return $t;
    }

    return rtrim(substr($t, 0, $maxChars - 3)) . '...';
}

/** @return list<string> */
function college_upload_allowed_extensions_list(): array
{
    return ['pdf', 'jpg', 'jpeg', 'png'];
}

function college_upload_allowed_extensions_csv(): string
{
    return implode(',', college_upload_allowed_extensions_list());
}

function college_upload_extension_is_allowed(string $ext): bool
{
    $ext = strtolower(trim($ext));

    return in_array($ext, college_upload_allowed_extensions_list(), true);
}

/**
 * Human label for UI (professor + student).
 */
function college_upload_allowed_types_label(): string
{
    return 'PDF, JPG, PNG';
}

/** @return list<string> */
function college_upload_allowed_mime_types(): array
{
    return [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];
}

/**
 * Validate uploaded file: extension + optional MIME (finfo).
 *
 * @return array{ok:bool,error?:string}
 */
function college_upload_validate_file(array $fileInfo, int $maxBytes): array
{
    if (empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if (($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. Please try again.'];
    }
    $size = (int)($fileInfo['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'Empty file.'];
    }
    if ($size > $maxBytes) {
        return ['ok' => false, 'error' => 'File is too large for this task.'];
    }

    $ext = strtolower(pathinfo((string)($fileInfo['name'] ?? ''), PATHINFO_EXTENSION));
    if (!college_upload_extension_is_allowed($ext)) {
        return ['ok' => false, 'error' => 'Only PDF and image files (JPG, PNG) are allowed.'];
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($fileInfo['tmp_name']);
        if ($mime !== false && !in_array($mime, college_upload_allowed_mime_types(), true)) {
            return ['ok' => false, 'error' => 'File type does not match an allowed PDF or image.'];
        }
    }

    return ['ok' => true];
}

/**
 * image | pdf | other (for inline preview UI).
 */
function college_upload_view_kind_from_filename(string $filename): string
{
    $ext = strtolower(pathinfo(trim($filename), PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return 'image';
    }
    if ($ext === 'pdf') {
        return 'pdf';
    }

    return 'other';
}

/**
 * Resolve DB file_path to an absolute path under uploads/college/ only.
 */
function college_upload_resolve_storage_path(string $projectRoot, string $relativeDbPath): ?string
{
    $rel = str_replace('\\', '/', trim($relativeDbPath));
    if ($rel === '' || strpos($rel, '..') !== false) {
        return null;
    }
    if (!preg_match('#^uploads/college/\d+/[^/]+$#', $rel)) {
        return null;
    }
    $full = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    $base = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'college');
    if ($full === false || $base === false || !is_file($full)) {
        return null;
    }
    $baseNorm = rtrim(str_replace('\\', '/', $base), '/');
    $fullNorm = str_replace('\\', '/', $full);
    if (stripos($fullNorm, $baseNorm) !== 0) {
        return null;
    }

    return $full;
}

/**
 * @return array{submission_id:int,task_id:int,user_id:int,file_path:string,file_name:string,created_by:int}|null
 */
function college_upload_fetch_submission_for_access(mysqli $conn, int $submissionId): ?array
{
    $submissionId = (int)$submissionId;
    if ($submissionId <= 0) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT s.submission_id, s.task_id, s.user_id, s.file_path, s.file_name, t.created_by
         FROM college_submissions s
         INNER JOIN college_upload_tasks t ON t.task_id = s.task_id
         WHERE s.submission_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $submissionId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        return null;
    }

    return [
        'submission_id' => (int)$row['submission_id'],
        'task_id' => (int)$row['task_id'],
        'user_id' => (int)$row['user_id'],
        'file_path' => (string)$row['file_path'],
        'file_name' => (string)$row['file_name'],
        'created_by' => (int)$row['created_by'],
    ];
}

function college_upload_user_can_read_submission(string $role, int $viewerUserId, array $submissionRow): bool
{
    if ($role === 'college_student') {
        return $viewerUserId === (int)$submissionRow['user_id'];
    }
    if ($role === 'professor_admin') {
        return $viewerUserId === (int)$submissionRow['created_by'];
    }

    return false;
}

function college_upload_mime_for_extension(string $ext): string
{
    $ext = strtolower(trim($ext));
    if ($ext === 'pdf') {
        return 'application/pdf';
    }
    if ($ext === 'jpg' || $ext === 'jpeg') {
        return 'image/jpeg';
    }
    if ($ext === 'png') {
        return 'image/png';
    }

    return 'application/octet-stream';
}

/**
 * Remove stored submission files for a task (best-effort).
 */
function college_upload_delete_task_files(mysqli $conn, int $taskId, string $projectRoot): void
{
    $taskId = (int)$taskId;
    if ($taskId <= 0) {
        return;
    }
    $q = mysqli_query($conn, 'SELECT file_path FROM college_submissions WHERE task_id=' . $taskId);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $rel = trim((string)($row['file_path'] ?? ''));
            if ($rel !== '' && strpos($rel, '..') === false) {
                $full = $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
                if (is_file($full)) {
                    @unlink($full);
                }
            }
        }
        mysqli_free_result($q);
    }
    $dir = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'college' . DIRECTORY_SEPARATOR . $taskId;
    if (is_dir($dir)) {
        $files = @scandir($dir);
        if (is_array($files)) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $p = $dir . DIRECTORY_SEPARATOR . $f;
                if (is_file($p)) {
                    @unlink($p);
                }
            }
        }
        @rmdir($dir);
    }
}
