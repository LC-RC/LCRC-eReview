<?php
/**
 * College assignment uploads - shared rules for professor + student pages.
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

        return rtrim(mb_substr($t, 0, $maxChars - 1)) . '...';
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

/** @return int|false */
function college_upload_open_to_timestamp(?string $openSql)
{
    if ($openSql === null || trim($openSql) === '') {
        return false;
    }

    return college_upload_deadline_to_timestamp($openSql);
}

/**
 * Derived upload task window state: draft | upcoming | open | locked
 */
function college_upload_task_window_state(array $task): string
{
    if (empty($task['is_open'])) {
        return 'draft';
    }
    $now = time();
    $openTs = college_upload_open_to_timestamp($task['open_at'] ?? null);
    $closeTs = college_upload_deadline_to_timestamp($task['deadline'] ?? null);
    if ($openTs !== false && $now < $openTs) {
        return 'upcoming';
    }
    if ($closeTs !== false && $now > $closeTs) {
        return 'locked';
    }

    return 'open';
}

function college_upload_task_status_label(string $state): string
{
    return match ($state) {
        'draft' => 'Draft',
        'upcoming' => 'Upcoming',
        'open' => 'Open',
        'locked' => 'Locked',
        default => ucfirst($state),
    };
}

function college_upload_open_has_started(?string $openSql): bool
{
    $ts = college_upload_open_to_timestamp($openSql);
    if ($ts === false) {
        return true;
    }

    return time() >= $ts;
}

function college_upload_resubmission_policy_valid(string $policy): bool
{
    return in_array($policy, ['disabled', 'allowed', 'request_only'], true);
}

/** @return list<string> */
function college_upload_load_task_sections(mysqli $conn, int $taskId): array
{
    $taskId = (int) $taskId;
    if ($taskId <= 0) {
        return [];
    }
    $out = [];
    $st = mysqli_prepare($conn, 'SELECT section_value FROM college_upload_task_sections WHERE task_id=? ORDER BY section_value ASC');
    if (!$st) {
        return [];
    }
    mysqli_stmt_bind_param($st, 'i', $taskId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($row = mysqli_fetch_assoc($res)) {
        $v = trim((string) ($row['section_value'] ?? ''));
        if ($v !== '') {
            $out[] = $v;
        }
    }
    mysqli_stmt_close($st);

    return $out;
}

/**
 * @param list<string> $sections
 */
function college_upload_save_task_sections(mysqli $conn, int $taskId, array $sections): void
{
    $taskId = (int) $taskId;
    if ($taskId <= 0) {
        return;
    }
    $sectionsFile = __DIR__ . '/college_sections.php';
    if (is_file($sectionsFile)) {
        require_once $sectionsFile;
    }
    $clean = [];
    foreach ($sections as $sv) {
        $t = trim((string) $sv);
        if ($t === '') {
            continue;
        }
        if (function_exists('college_sections_resolve_active_name') && isset($conn)) {
            $canonical = college_sections_resolve_active_name($conn, $t);
            if ($canonical !== null) {
                $t = $canonical;
            }
        }
        if (!in_array($t, $clean, true)) {
            $clean[] = $t;
        }
    }
    mysqli_query($conn, 'DELETE FROM college_upload_task_sections WHERE task_id=' . $taskId);
    if ($clean === []) {
        return;
    }
    $ins = mysqli_prepare($conn, 'INSERT INTO college_upload_task_sections (task_id, section_value) VALUES (?, ?)');
    if (!$ins) {
        return;
    }
    foreach ($clean as $sec) {
        mysqli_stmt_bind_param($ins, 'is', $taskId, $sec);
        mysqli_stmt_execute($ins);
    }
    mysqli_stmt_close($ins);
}

function college_upload_section_summary(mysqli $conn, int $taskId, string $assignmentMode): string
{
    if ($assignmentMode !== 'sections') {
        return 'All sections';
    }
    $secs = college_upload_load_task_sections($conn, $taskId);
    if ($secs === []) {
        return 'All sections';
    }
    if (count($secs) <= 2) {
        return implode(', ', $secs);
    }

    return $secs[0] . ', ' . $secs[1] . ' +' . (count($secs) - 2);
}

/**
 * Whether a college examinee row matches task audience + sections.
 */
function college_upload_user_matches_task(mysqli $conn, int $userId, array $task, ?array $userRow = null): bool
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }
    if ($userRow === null) {
        $st = mysqli_prepare($conn, "SELECT user_id, role, status, review_type, section, college_examination_access FROM users WHERE user_id=? LIMIT 1");
        if (!$st) {
            return false;
        }
        mysqli_stmt_bind_param($st, 'i', $userId);
        mysqli_stmt_execute($st);
        $userRow = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);
    }
    if (!$userRow) {
        return false;
    }
    $role = (string) ($userRow['role'] ?? '');
    if (!in_array($role, ['college_student', 'student'], true)) {
        return false;
    }
    $status = strtolower(trim((string) ($userRow['status'] ?? '')));
    if ($status === 'rejected') {
        return false;
    }
    if ($role === 'student') {
        $access = strtolower(trim((string) ($userRow['college_examination_access'] ?? '')));
        if ($access !== 'active') {
            return false;
        }
    }
    $scope = strtolower(trim((string) ($task['examinee_scope'] ?? 'college_student')));
    $reviewType = strtolower(trim((string) ($userRow['review_type'] ?? '')));
    if ($scope === 'college_student' && $reviewType === 'reviewee') {
        return false;
    }
    $mode = strtolower(trim((string) ($task['assignment_mode'] ?? 'all')));
    if ($mode !== 'sections') {
        return true;
    }
    $userSection = trim((string) ($userRow['section'] ?? ''));
    if ($userSection === '') {
        return false;
    }
    $taskSections = college_upload_load_task_sections($conn, (int) ($task['task_id'] ?? 0));
    if ($taskSections === []) {
        return true;
    }

    return in_array($userSection, $taskSections, true);
}

/**
 * Student-visible published tasks (is_open=1), filtered by eligibility in PHP.
 *
 * @return list<array<string,mixed>>
 */
function college_upload_list_for_student(mysqli $conn, int $userId): array
{
    $rows = [];
    $q = mysqli_query($conn, 'SELECT * FROM college_upload_tasks WHERE is_open=1 ORDER BY deadline ASC');
    if (!$q) {
        return [];
    }
    while ($task = mysqli_fetch_assoc($q)) {
        if (!college_upload_user_matches_task($conn, $userId, $task)) {
            continue;
        }
        $rows[] = $task;
    }
    mysqli_free_result($q);

    return $rows;
}

/** @return array<string,mixed>|null */
function college_upload_fetch_task_for_student(mysqli $conn, int $taskId, int $userId): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT * FROM college_upload_tasks WHERE task_id=? AND is_open=1 LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $taskId);
    mysqli_stmt_execute($stmt);
    $task = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$task || !college_upload_user_matches_task($conn, $userId, $task)) {
        return null;
    }

    return $task;
}

/** @return array<string,mixed>|null */
function college_upload_latest_submission(mysqli $conn, int $taskId, int $userId): ?array
{
    $st = mysqli_prepare($conn, 'SELECT * FROM college_submissions WHERE task_id=? AND user_id=? AND is_latest=1 ORDER BY submission_id DESC LIMIT 1');
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'ii', $taskId, $userId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function college_upload_submission_history(mysqli $conn, int $taskId, int $userId): array
{
    $out = [];
    $st = mysqli_prepare($conn, 'SELECT * FROM college_submissions WHERE task_id=? AND user_id=? ORDER BY submission_number DESC, submission_id DESC');
    if (!$st) {
        return [];
    }
    mysqli_stmt_bind_param($st, 'ii', $taskId, $userId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = $row;
    }
    mysqli_stmt_close($st);

    return $out;
}

/** @return array<string,mixed>|null */
function college_upload_active_resubmission_request(mysqli $conn, int $taskId, int $userId): ?array
{
    $st = mysqli_prepare(
        $conn,
        "SELECT * FROM college_upload_resubmission_requests
         WHERE task_id=? AND user_id=? AND status IN ('pending','approved')
         ORDER BY request_id DESC LIMIT 1"
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'ii', $taskId, $userId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    return $row ?: null;
}

/**
 * @return array{ok:bool,error?:string,code?:string}
 */
function college_upload_can_student_submit(mysqli $conn, int $userId, array $task): array
{
    if (empty($task['is_open'])) {
        return ['ok' => false, 'error' => 'This task is not available.', 'code' => 'draft'];
    }
    if (!college_upload_user_matches_task($conn, $userId, $task)) {
        return ['ok' => false, 'error' => 'You are not eligible for this task.', 'code' => 'ineligible'];
    }
    if (!college_upload_open_has_started($task['open_at'] ?? null)) {
        return ['ok' => false, 'error' => 'This task has not opened yet.', 'code' => 'upcoming'];
    }
    if (college_upload_deadline_has_passed($task['deadline'] ?? null)) {
        return ['ok' => false, 'error' => 'This task is closed.', 'code' => 'locked'];
    }
    $latest = college_upload_latest_submission($conn, (int) ($task['task_id'] ?? 0), $userId);
    if ($latest === null) {
        return ['ok' => true];
    }
    $policy = strtolower(trim((string) ($task['resubmission_policy'] ?? 'disabled')));
    if ($policy === 'allowed') {
        return ['ok' => true];
    }
    if ($policy === 'request_only') {
        $req = college_upload_active_resubmission_request($conn, (int) ($task['task_id'] ?? 0), $userId);
        if ($req && ($req['status'] ?? '') === 'approved') {
            return ['ok' => true];
        }

        return ['ok' => false, 'error' => 'Resubmission requires professor approval.', 'code' => 'resubmit_blocked'];
    }

    return ['ok' => false, 'error' => 'You have already submitted for this task.', 'code' => 'already_submitted'];
}

/**
 * @return array{ok:bool,error?:string,submission_id?:int}
 */
function college_upload_record_submission(mysqli $conn, int $taskId, int $userId, string $relPath, string $fileName, int $fileSize): array
{
    $taskId = (int) $taskId;
    $userId = (int) $userId;
    $latest = college_upload_latest_submission($conn, $taskId, $userId);
    $nextNum = $latest ? ((int) ($latest['submission_number'] ?? 0) + 1) : 1;
    mysqli_begin_transaction($conn);
    try {
        if ($latest) {
            $oldId = (int) ($latest['submission_id'] ?? 0);
            $clr = mysqli_prepare($conn, 'UPDATE college_submissions SET is_latest=0 WHERE submission_id=? AND user_id=? LIMIT 1');
            mysqli_stmt_bind_param($clr, 'ii', $oldId, $userId);
            mysqli_stmt_execute($clr);
            mysqli_stmt_close($clr);
        }
        $ins = mysqli_prepare(
            $conn,
            "INSERT INTO college_submissions (task_id, user_id, submission_number, file_path, file_name, file_size, status, is_latest, review_status)
             VALUES (?, ?, ?, ?, ?, ?, 'submitted', 1, 'submitted')"
        );
        if (!$ins) {
            throw new RuntimeException('insert failed');
        }
        mysqli_stmt_bind_param($ins, 'iiissi', $taskId, $userId, $nextNum, $relPath, $fileName, $fileSize);
        if (!mysqli_stmt_execute($ins)) {
            mysqli_stmt_close($ins);
            throw new RuntimeException('insert failed');
        }
        $newId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        $req = college_upload_active_resubmission_request($conn, $taskId, $userId);
        if ($req && ($req['status'] ?? '') === 'approved') {
            $rid = (int) ($req['request_id'] ?? 0);
            $used = mysqli_prepare($conn, "UPDATE college_upload_resubmission_requests SET status='used', resolved_at=NOW() WHERE request_id=? LIMIT 1");
            if ($used) {
                mysqli_stmt_bind_param($used, 'i', $rid);
                mysqli_stmt_execute($used);
                mysqli_stmt_close($used);
            }
        }
        mysqli_commit($conn);

        return ['ok' => true, 'submission_id' => $newId];
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => 'Could not save submission.'];
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function college_upload_professor_request_resubmission(mysqli $conn, int $taskId, int $userId, int $professorId): array
{
    $latest = college_upload_latest_submission($conn, $taskId, $userId);
    if (!$latest) {
        return ['ok' => false, 'error' => 'Student has no submission yet.'];
    }
    $existing = college_upload_active_resubmission_request($conn, $taskId, $userId);
    if ($existing && ($existing['status'] ?? '') === 'pending') {
        return ['ok' => false, 'error' => 'A resubmission request is already pending.'];
    }
    $sid = (int) ($latest['submission_id'] ?? 0);
    $st = mysqli_prepare(
        $conn,
        "INSERT INTO college_upload_resubmission_requests (task_id, user_id, submission_id, status, requested_by)
         VALUES (?, ?, ?, 'pending', ?)"
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'Could not create request.'];
    }
    mysqli_stmt_bind_param($st, 'iiii', $taskId, $userId, $sid, $professorId);
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    if ($latest && $ok) {
        $upd = mysqli_prepare($conn, "UPDATE college_submissions SET review_status='reviewed' WHERE submission_id=? LIMIT 1");
        if ($upd) {
            mysqli_stmt_bind_param($upd, 'i', $sid);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
    }

    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Could not create request.'];
}

/**
 * @return array{ok:bool,error?:string}
 */
function college_upload_professor_resolve_resubmission(mysqli $conn, int $requestId, int $professorId, string $decision): array
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'Invalid decision.'];
    }
    $st = mysqli_prepare(
        $conn,
        "UPDATE college_upload_resubmission_requests r
         INNER JOIN college_upload_tasks t ON t.task_id=r.task_id
         SET r.status=?, r.resolved_by=?, r.resolved_at=NOW()
         WHERE r.request_id=? AND r.status='pending' AND t.created_by=? LIMIT 1"
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'Update failed.'];
    }
    mysqli_stmt_bind_param($st, 'siii', $decision, $professorId, $requestId, $professorId);
    mysqli_stmt_execute($st);
    $aff = mysqli_stmt_affected_rows($st);
    mysqli_stmt_close($st);

    return $aff > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'Request not found or already resolved.'];
}

function college_upload_resubmission_label(mysqli $conn, int $taskId, int $userId): string
{
    $req = college_upload_active_resubmission_request($conn, $taskId, $userId);
    if (!$req) {
        return 'No request';
    }

    return match ((string) ($req['status'] ?? '')) {
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        default => 'No request',
    };
}

function college_upload_count_eligible_students(mysqli $conn, array $task): int
{
    $count = 0;
    $q = mysqli_query($conn, "SELECT user_id, role, status, review_type, section, college_examination_access FROM users WHERE role IN ('college_student','student')");
    if (!$q) {
        return 0;
    }
    while ($u = mysqli_fetch_assoc($q)) {
        if (college_upload_user_matches_task($conn, (int) ($u['user_id'] ?? 0), $task, $u)) {
            $count++;
        }
    }
    mysqli_free_result($q);

    return $count;
}

function college_upload_count_latest_submissions(mysqli $conn, int $taskId): int
{
    $taskId = (int) $taskId;
    $row = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM college_submissions WHERE task_id=' . $taskId . ' AND is_latest=1'));

    return (int) ($row['c'] ?? 0);
}
