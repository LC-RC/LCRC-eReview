<?php
declare(strict_types=1);

/**
 * Fail-closed academic-record guards. Safe whether migration 002 CASCADE FKs exist or not.
 * Never DELETE users/exams/tasks when protected rows exist or when counts cannot be read.
 */

function academic_record_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];
    $table = preg_replace('/[^a-z0-9_]/', '', strtolower($table)) ?? '';
    if ($table === '') {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $r = @mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    $cache[$table] = (bool) ($r && mysqli_fetch_row($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return $cache[$table];
}

/**
 * Detect 002-style CASCADE from attempts/answers onto exams or users.
 *
 * @return array{present:bool, details:list<string>}
 */
function academic_record_cascade_fk_inventory(mysqli $conn): array
{
    $sql = "SELECT k.TABLE_NAME AS tbl, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS ref_tbl, r.DELETE_RULE AS del_rule
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
             AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME
             AND k.TABLE_NAME = r.TABLE_NAME
            WHERE k.TABLE_SCHEMA = DATABASE()
              AND k.REFERENCED_TABLE_NAME IN ('users','college_exams','college_exam_attempts','college_exam_questions','diagnostic_batches')
              AND r.DELETE_RULE = 'CASCADE'";
    $r = @mysqli_query($conn, $sql);
    $details = [];
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $details[] = $row['tbl'] . '.' . $row['col'] . ' -> ' . $row['ref_tbl'] . ' CASCADE';
        }
        mysqli_free_result($r);
    }

    return ['present' => $details !== [], 'details' => $details];
}

/**
 * @return array{ok:bool, count:int, error?:string}
 */
function academic_record_count_sql(mysqli $conn, string $sql): array
{
    $r = @mysqli_query($conn, $sql);
    if (!$r) {
        return ['ok' => false, 'count' => 0, 'error' => 'Could not count protected academic records.'];
    }
    $row = mysqli_fetch_assoc($r);
    mysqli_free_result($r);

    return ['ok' => true, 'count' => (int) ($row['c'] ?? 0)];
}

/**
 * @return array{ok:bool, total:int, breakdown:array<string,int>, error?:string}
 */
function academic_record_user_dependency_counts(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'total' => 0, 'breakdown' => [], 'error' => 'Invalid user.'];
    }
    $checks = [
        'college_exam_attempts' => academic_record_table_exists($conn, 'college_exam_attempts')
            ? 'SELECT COUNT(*) AS c FROM college_exam_attempts WHERE user_id=' . $userId
            : null,
        'college_exam_answers' => academic_record_table_exists($conn, 'college_exam_answers') && academic_record_table_exists($conn, 'college_exam_attempts')
            ? 'SELECT COUNT(*) AS c FROM college_exam_answers a INNER JOIN college_exam_attempts t ON t.attempt_id=a.attempt_id WHERE t.user_id=' . $userId
            : null,
        'diagnostic_attempts' => academic_record_table_exists($conn, 'diagnostic_attempts')
            ? 'SELECT COUNT(*) AS c FROM diagnostic_attempts WHERE user_id=' . $userId
            : null,
        'diagnostic_answers' => academic_record_table_exists($conn, 'diagnostic_answers') && academic_record_table_exists($conn, 'diagnostic_attempts')
            ? 'SELECT COUNT(*) AS c FROM diagnostic_answers a INNER JOIN diagnostic_attempts t ON t.attempt_id=a.attempt_id WHERE t.user_id=' . $userId
            : null,
        'college_submissions' => academic_record_table_exists($conn, 'college_submissions')
            ? 'SELECT COUNT(*) AS c FROM college_submissions WHERE user_id=' . $userId
            : null,
        'quiz_attempts' => academic_record_table_exists($conn, 'quiz_attempts')
            ? 'SELECT COUNT(*) AS c FROM quiz_attempts WHERE user_id=' . $userId
            : null,
        'quiz_answers' => academic_record_table_exists($conn, 'quiz_answers')
            ? 'SELECT COUNT(*) AS c FROM quiz_answers WHERE user_id=' . $userId
            : null,
        'student_achievements' => academic_record_table_exists($conn, 'student_achievements')
            ? 'SELECT COUNT(*) AS c FROM student_achievements WHERE user_id=' . $userId
            : null,
    ];
    $breakdown = [];
    $total = 0;
    foreach ($checks as $key => $sql) {
        if ($sql === null) {
            continue;
        }
        $got = academic_record_count_sql($conn, $sql);
        if (empty($got['ok'])) {
            return ['ok' => false, 'total' => 0, 'breakdown' => $breakdown, 'error' => $got['error'] ?? 'Dependency check failed.'];
        }
        $n = (int) $got['count'];
        $breakdown[$key] = $n;
        $total += $n;
    }

    return ['ok' => true, 'total' => $total, 'breakdown' => $breakdown];
}

/**
 * @return array{ok:bool, total:int, breakdown:array<string,int>, error?:string}
 */
function academic_record_exam_dependency_counts(mysqli $conn, string $examType, int $sourceId): array
{
    $examType = function_exists('examination_normalize_exam_type')
        ? examination_normalize_exam_type($examType)
        : strtolower(trim($examType));
    if ($sourceId <= 0 || ($examType !== 'regular' && $examType !== 'diagnostic')) {
        return ['ok' => false, 'total' => 0, 'breakdown' => [], 'error' => 'Invalid examination.'];
    }
    if ($examType === 'diagnostic') {
        $checks = [
            'diagnostic_attempts' => academic_record_table_exists($conn, 'diagnostic_attempts')
                ? 'SELECT COUNT(*) AS c FROM diagnostic_attempts WHERE batch_id=' . $sourceId
                : null,
            'diagnostic_answers' => academic_record_table_exists($conn, 'diagnostic_answers') && academic_record_table_exists($conn, 'diagnostic_attempts')
                ? 'SELECT COUNT(*) AS c FROM diagnostic_answers a INNER JOIN diagnostic_attempts t ON t.attempt_id=a.attempt_id WHERE t.batch_id=' . $sourceId
                : null,
        ];
    } else {
        $checks = [
            'college_exam_attempts' => academic_record_table_exists($conn, 'college_exam_attempts')
                ? 'SELECT COUNT(*) AS c FROM college_exam_attempts WHERE exam_id=' . $sourceId
                : null,
            'college_exam_answers' => academic_record_table_exists($conn, 'college_exam_answers') && academic_record_table_exists($conn, 'college_exam_attempts')
                ? 'SELECT COUNT(*) AS c FROM college_exam_answers a INNER JOIN college_exam_attempts t ON t.attempt_id=a.attempt_id WHERE t.exam_id=' . $sourceId
                : null,
            'college_exam_attempt_questions' => academic_record_table_exists($conn, 'college_exam_attempt_questions') && academic_record_table_exists($conn, 'college_exam_attempts')
                ? 'SELECT COUNT(*) AS c FROM college_exam_attempt_questions q INNER JOIN college_exam_attempts t ON t.attempt_id=q.attempt_id WHERE t.exam_id=' . $sourceId
                : null,
        ];
    }
    $breakdown = [];
    $total = 0;
    foreach ($checks as $key => $sql) {
        if ($sql === null) {
            continue;
        }
        $got = academic_record_count_sql($conn, $sql);
        if (empty($got['ok'])) {
            return ['ok' => false, 'total' => 0, 'breakdown' => $breakdown, 'error' => $got['error'] ?? 'Dependency check failed.'];
        }
        $n = (int) $got['count'];
        $breakdown[$key] = $n;
        $total += $n;
    }

    return ['ok' => true, 'total' => $total, 'breakdown' => $breakdown];
}

/**
 * @return array{ok:bool, total:int, error?:string}
 */
function academic_record_upload_task_dependency_counts(mysqli $conn, int $taskId): array
{
    if ($taskId <= 0) {
        return ['ok' => false, 'total' => 0, 'error' => 'Invalid upload task.'];
    }
    if (!academic_record_table_exists($conn, 'college_submissions')) {
        return ['ok' => false, 'total' => 0, 'error' => 'Could not inspect upload submissions.'];
    }
    $got = academic_record_count_sql($conn, 'SELECT COUNT(*) AS c FROM college_submissions WHERE task_id=' . $taskId);

    return empty($got['ok'])
        ? ['ok' => false, 'total' => 0, 'error' => $got['error'] ?? 'Could not count submissions.']
        : ['ok' => true, 'total' => (int) $got['count']];
}

function academic_record_protected_refuse_message(string $entity, int $total): string
{
    return 'Cannot permanently delete this ' . $entity
        . ' because ' . $total . ' protected academic record'
        . ($total === 1 ? '' : 's')
        . ' exist. Archive instead. Attempts, answers, scores, and submissions are kept.';
}

function examination_record_is_archived(array $record): bool
{
    if (!empty($record['is_archived'])) {
        return true;
    }
    $at = trim((string) ($record['archived_at'] ?? ''));

    return $at !== '' && !str_starts_with($at, '0000-00-00');
}

/**
 * Lock attempt rows for this exam so a concurrent start cannot sneak in before delete.
 */
function academic_record_lock_exam_attempt_rows(mysqli $conn, string $examType, int $sourceId): bool
{
    $examType = strtolower(trim($examType));
    if ($sourceId <= 0) {
        return false;
    }
    if ($examType === 'diagnostic') {
        if (!academic_record_table_exists($conn, 'diagnostic_attempts')) {
            return false;
        }
        $r = @mysqli_query($conn, 'SELECT attempt_id FROM diagnostic_attempts WHERE batch_id=' . $sourceId . ' FOR UPDATE');
    } else {
        if (!academic_record_table_exists($conn, 'college_exam_attempts')) {
            return false;
        }
        $r = @mysqli_query($conn, 'SELECT attempt_id FROM college_exam_attempts WHERE exam_id=' . $sourceId . ' FOR UPDATE');
    }
    if (!$r) {
        return false;
    }
    mysqli_free_result($r);

    return true;
}

/**
 * LMS student unlink stays unlink. Native college_student defaults to archive.
 */
function academic_record_resolve_student_remove_mode(string $posted, string $role): string
{
    $posted = strtolower(trim($posted));
    $role = strtolower(trim($role));
    if ($role === 'student') {
        return 'unlink';
    }
    if ($role !== 'college_student') {
        return 'unlink';
    }
    if ($posted === 'delete') {
        return 'delete';
    }

    return 'archive';
}

function academic_record_user_hard_delete_block_reason(mysqli $conn, int $userId): ?string
{
    $deps = academic_record_user_dependency_counts($conn, $userId);
    if (empty($deps['ok'])) {
        return (string) ($deps['error'] ?? 'Could not verify academic records. Delete refused.');
    }
    if ((int) $deps['total'] > 0) {
        return academic_record_protected_refuse_message('student', (int) $deps['total']);
    }

    return null;
}
