<?php
declare(strict_types=1);

/**
 * Regular/diagnostic examination archive and restore.
 * Unpublishes on archive so student start URLs fail closed. Restore returns Draft.
 * Does not delete attempts, answers, questions, or scores.
 */

require_once __DIR__ . '/academic_record_protection.php';
require_once __DIR__ . '/examination_assignment.php';

function examination_archive_column_ready(mysqli $conn, string $table): bool
{
    static $cache = [];
    $table = $table === 'diagnostic_batches' ? 'diagnostic_batches' : 'college_exams';
    if (!empty($cache[$table])) {
        return true;
    }
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE 'archived_at'");
    $ok = (bool) ($r && mysqli_fetch_assoc($r));
    if ($r) {
        mysqli_free_result($r);
    }
    if ($ok) {
        $cache[$table] = true;
    }

    return $ok;
}

function examination_archive_tables_ready(mysqli $conn): bool
{
    return examination_archive_column_ready($conn, 'college_exams')
        && examination_archive_column_ready($conn, 'diagnostic_batches');
}

function examination_archive_log_ready(mysqli $conn): bool
{
    return academic_record_table_exists($conn, 'examination_archive_log');
}

function examination_row_is_archived(array $row): bool
{
    return examination_record_is_archived($row);
}

function examination_archive_not_archived_sql(string $tableAlias = ''): string
{
    $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';

    return '(' . $prefix . 'archived_at IS NULL OR CAST(' . $prefix . 'archived_at AS CHAR) IN (\'\',\'0000-00-00 00:00:00\'))';
}

function examination_archive_log_event(
    mysqli $conn,
    string $examType,
    int $sourceId,
    int $actorId,
    string $action,
    string $detail = ''
): void {
    if (!examination_archive_log_ready($conn)) {
        return;
    }
    $examType = examination_normalize_exam_type($examType);
    $action = preg_replace('/[^a-z_]/', '', strtolower($action)) ?? '';
    if ($examType === '' || $sourceId <= 0 || $action === '') {
        return;
    }
    $detail = function_exists('mb_substr') ? mb_substr($detail, 0, 255) : substr($detail, 0, 255);
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    $st = mysqli_prepare(
        $conn,
        'INSERT INTO examination_archive_log (exam_type, source_id, actor_user_id, action, detail, created_at) VALUES (?,?,?,?,?,?)'
    );
    if (!$st) {
        return;
    }
    mysqli_stmt_bind_param($st, 'siisss', $examType, $sourceId, $actorId, $action, $detail, $now);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
}

/**
 * @return array{table:string, id_col:string}|null
 */
function examination_archive_table_map(string $examType): ?array
{
    $examType = examination_normalize_exam_type($examType);
    if ($examType === 'diagnostic') {
        return ['table' => 'diagnostic_batches', 'id_col' => 'batch_id'];
    }
    if ($examType === 'regular') {
        return ['table' => 'college_exams', 'id_col' => 'exam_id'];
    }

    return null;
}

/**
 * @return array{ok:bool,error?:string,title?:string,already?:bool,protected_count?:int,archived_at?:string}
 */
function examination_archive_apply(mysqli $conn, string $examType, int $sourceId, int $professorId): array
{
    $map = examination_archive_table_map($examType);
    if ($map === null || $sourceId <= 0 || $professorId <= 0) {
        return ['ok' => false, 'error' => 'Invalid examination.'];
    }
    if (!examination_archive_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'Examination archive is not installed. Apply migration 058_examination_archive.sql.'];
    }

    $table = $map['table'];
    $idCol = $map['id_col'];
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Could not start archive transaction.'];
    }
    try {
        $st = mysqli_prepare(
            $conn,
            "SELECT * FROM `{$table}` WHERE `{$idCol}`=? AND created_by=? LIMIT 1 FOR UPDATE"
        );
        if (!$st) {
            throw new RuntimeException('Could not lock examination.');
        }
        mysqli_stmt_bind_param($st, 'ii', $sourceId, $professorId);
        if (!mysqli_stmt_execute($st)) {
            mysqli_stmt_close($st);
            throw new RuntimeException('Could not lock examination.');
        }
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);
        if (!$row) {
            throw new RuntimeException('Examination not found.');
        }
        $title = (string) ($row['title'] ?? '');
        if (examination_row_is_archived($row)) {
            mysqli_commit($conn);

            return [
                'ok' => true,
                'already' => true,
                'title' => $title,
                'archived_at' => (string) ($row['archived_at'] ?? ''),
            ];
        }

        $deps = academic_record_exam_dependency_counts($conn, $examType, $sourceId);
        $wasPublished = !empty($row['is_published']) ? 1 : 0;
        $upd = mysqli_prepare(
            $conn,
            "UPDATE `{$table}`
             SET archived_at=NOW(), archived_by=?, archived_was_published=?, is_published=0
             WHERE `{$idCol}`=? AND created_by=? LIMIT 1"
        );
        if (!$upd) {
            throw new RuntimeException('Could not archive examination.');
        }
        mysqli_stmt_bind_param($upd, 'iiii', $professorId, $wasPublished, $sourceId, $professorId);
        if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
            mysqli_stmt_close($upd);
            throw new RuntimeException('Could not archive examination.');
        }
        mysqli_stmt_close($upd);
        $detail = 'was_published=' . $wasPublished . ' protected=' . (int) ($deps['total'] ?? 0);
        examination_archive_log_event($conn, $examType, $sourceId, $professorId, 'archive', $detail);
        mysqli_commit($conn);

        return [
            'ok' => true,
            'title' => $title,
            'protected_count' => (int) ($deps['total'] ?? 0),
        ];
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Restore to Draft. Never republishes. Schedule columns are not changed.
 *
 * @return array{ok:bool,error?:string,title?:string,already?:bool,was_published?:bool}
 */
function examination_archive_restore(mysqli $conn, string $examType, int $sourceId, int $professorId): array
{
    $map = examination_archive_table_map($examType);
    if ($map === null || $sourceId <= 0 || $professorId <= 0) {
        return ['ok' => false, 'error' => 'Invalid examination.'];
    }
    if (!examination_archive_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'Examination archive is not installed. Apply migration 058_examination_archive.sql.'];
    }

    $table = $map['table'];
    $idCol = $map['id_col'];
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'error' => 'Could not start restore transaction.'];
    }
    try {
        $st = mysqli_prepare(
            $conn,
            "SELECT * FROM `{$table}` WHERE `{$idCol}`=? AND created_by=? LIMIT 1 FOR UPDATE"
        );
        if (!$st) {
            throw new RuntimeException('Could not lock examination.');
        }
        mysqli_stmt_bind_param($st, 'ii', $sourceId, $professorId);
        if (!mysqli_stmt_execute($st)) {
            mysqli_stmt_close($st);
            throw new RuntimeException('Could not lock examination.');
        }
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);
        if (!$row) {
            throw new RuntimeException('Examination not found.');
        }
        $title = (string) ($row['title'] ?? '');
        $wasPublished = !empty($row['archived_was_published']);
        if (!examination_row_is_archived($row)) {
            mysqli_commit($conn);

            return ['ok' => true, 'already' => true, 'title' => $title, 'was_published' => $wasPublished];
        }

        $upd = mysqli_prepare(
            $conn,
            "UPDATE `{$table}`
             SET archived_at=NULL, archived_by=NULL, is_published=0
             WHERE `{$idCol}`=? AND created_by=? LIMIT 1"
        );
        if (!$upd) {
            throw new RuntimeException('Could not restore examination.');
        }
        mysqli_stmt_bind_param($upd, 'ii', $sourceId, $professorId);
        if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
            mysqli_stmt_close($upd);
            throw new RuntimeException('Could not restore examination.');
        }
        mysqli_stmt_close($upd);
        examination_archive_log_event(
            $conn,
            $examType,
            $sourceId,
            $professorId,
            'restore',
            $wasPublished ? 'restored_as_draft was_published=1' : 'restored_as_draft was_published=0'
        );
        mysqli_commit($conn);

        return ['ok' => true, 'title' => $title, 'was_published' => $wasPublished];
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Permanent delete only when no protected academic rows exist. Fail closed.
 *
 * @return array{ok:bool,error?:string,title?:string}
 */
/** Caller must already hold a transaction and FOR UPDATE on the exam row. */
function examination_archive_delete_block_reason(mysqli $conn, string $examType, int $sourceId): ?string
{
    if (!academic_record_lock_exam_attempt_rows($conn, $examType, $sourceId)) {
        return 'Could not lock academic records. Permanent deletion is refused.';
    }
    $deps = academic_record_exam_dependency_counts($conn, $examType, $sourceId);
    if (empty($deps['ok'])) {
        return (string) ($deps['error'] ?? 'Could not verify academic records. Delete refused.');
    }
    if ((int) $deps['total'] > 0) {
        return academic_record_protected_refuse_message('examination', (int) $deps['total']);
    }

    return null;
}
