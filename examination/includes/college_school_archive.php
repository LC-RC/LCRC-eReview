<?php
declare(strict_types=1);

/**
 * School directory archive / restore / guarded purge.
 * Does not cascade-delete students, exams, scores, or assignment logs.
 */

require_once __DIR__ . '/college_schools.php';

const COLLEGE_SCHOOL_RETENTION_DAYS = 30;

function college_schools_archive_log_ready(mysqli $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'college_school_archive_log'");
    $cached = (bool) ($r && mysqli_fetch_row($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return $cached;
}

function college_schools_is_archived_row(array $row): bool
{
    $at = trim((string) ($row['archived_at'] ?? ''));

    return $at !== '' && !preg_match('/^0000-00-00/', $at);
}

function college_schools_log_archive_event(
    mysqli $conn,
    int $schoolId,
    string $schoolName,
    int $actorId,
    string $action,
    string $detail = ''
): void {
    if (!college_schools_archive_log_ready($conn)) {
        return;
    }
    $st = mysqli_prepare(
        $conn,
        'INSERT INTO college_school_archive_log (school_id, school_name, actor_user_id, action, detail, created_at)
         VALUES (NULLIF(?, 0), ?, ?, ?, NULLIF(?, \'\'), NOW())'
    );
    if (!$st) {
        return;
    }
    $sid = max(0, $schoolId);
    $by = max(0, $actorId);
    $name = college_schools_normalize_name($schoolName);
    mysqli_stmt_bind_param($st, 'isiss', $sid, $name, $by, $action, $detail);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
}

/**
 * @return array{students:int,pending:int,total:int}
 */
function college_schools_dependency_counts(mysqli $conn, int $schoolId, string $schoolName): array
{
    $students = 0;
    $name = college_schools_normalize_name($schoolName);
    if ($schoolId > 0) {
        $hasFk = college_schools_user_id_column_ready($conn);
        if ($hasFk) {
            $st = mysqli_prepare(
                $conn,
                'SELECT COUNT(*) AS c FROM users WHERE college_school_id=? OR school=?'
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'is', $schoolId, $name);
                mysqli_stmt_execute($st);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
                mysqli_stmt_close($st);
                $students = (int) ($row['c'] ?? 0);
            }
        } else {
            $st = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM users WHERE school=?');
            if ($st) {
                mysqli_stmt_bind_param($st, 's', $name);
                mysqli_stmt_execute($st);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
                mysqli_stmt_close($st);
                $students = (int) ($row['c'] ?? 0);
            }
        }
    }
    $pending = 0;
    $pt = @mysqli_query($conn, "SHOW TABLES LIKE 'college_pending_registrations'");
    if ($pt && mysqli_fetch_row($pt) && $name !== '') {
        mysqli_free_result($pt);
        $st = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM college_pending_registrations WHERE school=?');
        if ($st) {
            mysqli_stmt_bind_param($st, 's', $name);
            mysqli_stmt_execute($st);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
            mysqli_stmt_close($st);
            $pending = (int) ($row['c'] ?? 0);
        }
    } elseif ($pt) {
        mysqli_free_result($pt);
    }

    return [
        'students' => $students,
        'pending' => $pending,
        'total' => $students + $pending,
    ];
}

/**
 * @return array{eligible:bool,blocked:bool,days_remaining:int,purge_eligible_at:?string,reason:string,students:int,pending:int}
 */
function college_schools_purge_state(mysqli $conn, array $row): array
{
    $deps = college_schools_dependency_counts($conn, (int) ($row['school_id'] ?? 0), (string) ($row['school_name'] ?? ''));
    $eligibleAt = trim((string) ($row['purge_eligible_at'] ?? ''));
    $days = 0;
    $windowOpen = false;
    if ($eligibleAt !== '' && !preg_match('/^0000-00-00/', $eligibleAt)) {
        $ts = strtotime($eligibleAt);
        if ($ts !== false) {
            $days = (int) max(0, (int) ceil(($ts - time()) / 86400));
            $windowOpen = $ts <= time();
        }
    }
    $reason = '';
    $blocked = $deps['total'] > 0;
    if ($blocked) {
        $bits = [];
        if ($deps['students'] > 0) {
            $bits[] = $deps['students'] . ' linked student record' . ($deps['students'] === 1 ? '' : 's');
        }
        if ($deps['pending'] > 0) {
            $bits[] = $deps['pending'] . ' pending registration' . ($deps['pending'] === 1 ? '' : 's');
        }
        $reason = 'Retained: ' . implode(' and ', $bits) . '. Educational records are not cascade-deleted.';
    } elseif (!college_schools_is_archived_row($row)) {
        $reason = 'Not archived.';
    } elseif (!$windowOpen) {
        $reason = $days === 1
            ? 'Eligible for deletion in 1 day.'
            : ('Eligible for deletion in ' . $days . ' days.');
    }

    return [
        'eligible' => $windowOpen && !$blocked && college_schools_is_archived_row($row),
        'blocked' => $blocked,
        'days_remaining' => $days,
        'purge_eligible_at' => $eligibleAt !== '' ? $eligibleAt : null,
        'reason' => $reason,
        'students' => $deps['students'],
        'pending' => $deps['pending'],
    ];
}

/**
 * @return array{ok:bool,error?:string}
 */
function college_schools_archive(mysqli $conn, int $schoolId, int $actorId): array
{
    if (!college_schools_archive_columns_ready($conn)) {
        return ['ok' => false, 'error' => 'School archive is not installed. Apply migration 056_college_school_archive.sql.'];
    }
    $row = college_schools_find_by_id($conn, $schoolId);
    if (!$row) {
        return ['ok' => false, 'error' => 'School not found.'];
    }
    if (college_schools_is_archived_row($row)) {
        return ['ok' => true];
    }
    $by = max(0, $actorId);
    $days = COLLEGE_SCHOOL_RETENTION_DAYS;
    mysqli_begin_transaction($conn);
    try {
        $st = mysqli_prepare(
            $conn,
            'UPDATE college_schools
             SET archived_at=NOW(), archived_by=NULLIF(?, 0), purge_eligible_at=DATE_ADD(NOW(), INTERVAL ? DAY),
                 updated_by=NULLIF(?, 0), updated_at=NOW()
             WHERE school_id=? AND archived_at IS NULL
             LIMIT 1'
        );
        if (!$st) {
            throw new RuntimeException('Could not archive school.');
        }
        mysqli_stmt_bind_param($st, 'iiii', $by, $days, $by, $schoolId);
        if (!mysqli_stmt_execute($st) || mysqli_stmt_affected_rows($st) < 1) {
            mysqli_stmt_close($st);
            mysqli_rollback($conn);
            $again = college_schools_find_by_id($conn, $schoolId);
            if ($again && college_schools_is_archived_row($again)) {
                return ['ok' => true];
            }

            return ['ok' => false, 'error' => 'Could not archive school.'];
        }
        mysqli_stmt_close($st);
        college_schools_log_archive_event($conn, $schoolId, (string) $row['school_name'], $by, 'archive', 'Retention ' . $days . ' days');
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => true];
}

/**
 * @return array{ok:bool,error?:string}
 */
function college_schools_restore(mysqli $conn, int $schoolId, int $actorId): array
{
    if (!college_schools_archive_columns_ready($conn)) {
        return ['ok' => false, 'error' => 'School archive is not installed. Apply migration 056_college_school_archive.sql.'];
    }
    $row = college_schools_find_by_id($conn, $schoolId);
    if (!$row) {
        return ['ok' => false, 'error' => 'School not found.'];
    }
    if (!college_schools_is_archived_row($row)) {
        return ['ok' => true];
    }
    $by = max(0, $actorId);
    mysqli_begin_transaction($conn);
    try {
        $st = mysqli_prepare(
            $conn,
            'UPDATE college_schools
             SET archived_at=NULL, archived_by=NULL, purge_eligible_at=NULL,
                 updated_by=NULLIF(?, 0), updated_at=NOW()
             WHERE school_id=? AND archived_at IS NOT NULL
             LIMIT 1'
        );
        if (!$st) {
            throw new RuntimeException('Could not restore school.');
        }
        mysqli_stmt_bind_param($st, 'ii', $by, $schoolId);
        if (!mysqli_stmt_execute($st) || mysqli_stmt_affected_rows($st) < 1) {
            mysqli_stmt_close($st);
            mysqli_rollback($conn);
            $again = college_schools_find_by_id($conn, $schoolId);
            if ($again && !college_schools_is_archived_row($again)) {
                return ['ok' => true];
            }

            return ['ok' => false, 'error' => 'Could not restore school.'];
        }
        mysqli_stmt_close($st);
        college_schools_log_archive_event($conn, $schoolId, (string) $row['school_name'], $by, 'restore', '');
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => true];
}

/**
 * @return array{ok:bool,error?:string,retained?:bool,reason?:string}
 */
function college_schools_purge(mysqli $conn, int $schoolId, int $actorId): array
{
    if (!college_schools_archive_columns_ready($conn)) {
        return ['ok' => false, 'error' => 'School archive is not installed. Apply migration 056_college_school_archive.sql.'];
    }
    $by = max(0, $actorId);
    mysqli_begin_transaction($conn);
    try {
        $lock = mysqli_prepare(
            $conn,
            'SELECT school_id, school_name, archived_at, archived_by, purge_eligible_at
             FROM college_schools WHERE school_id=? LIMIT 1 FOR UPDATE'
        );
        if (!$lock) {
            throw new RuntimeException('Could not lock school for deletion.');
        }
        mysqli_stmt_bind_param($lock, 'i', $schoolId);
        mysqli_stmt_execute($lock);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
        mysqli_stmt_close($lock);
        if (!$row) {
            mysqli_rollback($conn);

            return ['ok' => false, 'error' => 'School not found.'];
        }
        $row['school_id'] = (int) $row['school_id'];
        $state = college_schools_purge_state($conn, $row);
        if ($state['blocked'] || !$state['eligible']) {
            $reason = $state['reason'] !== '' ? $state['reason'] : 'This school cannot be permanently deleted.';
            college_schools_log_archive_event($conn, $schoolId, (string) $row['school_name'], $by, 'purge_blocked', $reason);
            mysqli_commit($conn);

            return ['ok' => false, 'retained' => true, 'reason' => $reason, 'error' => $reason];
        }
        $name = (string) $row['school_name'];
        $st = mysqli_prepare(
            $conn,
            'DELETE FROM college_schools
             WHERE school_id=? AND archived_at IS NOT NULL AND purge_eligible_at IS NOT NULL AND purge_eligible_at<=NOW()
             LIMIT 1'
        );
        if (!$st) {
            throw new RuntimeException('Could not delete school.');
        }
        mysqli_stmt_bind_param($st, 'i', $schoolId);
        if (!mysqli_stmt_execute($st) || mysqli_stmt_affected_rows($st) < 1) {
            mysqli_stmt_close($st);
            throw new RuntimeException('School is not eligible for deletion.');
        }
        mysqli_stmt_close($st);
        college_schools_log_archive_event($conn, $schoolId, $name, $by, 'purge', 'Directory row removed; student records untouched.');
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => true];
}

/**
 * @return list<array<string,mixed>>
 */
function college_schools_purge_candidates(mysqli $conn): array
{
    $out = [];
    foreach (college_schools_list($conn, false, 'archived') as $row) {
        $state = college_schools_purge_state($conn, $row);
        if ($state['eligible']) {
            $row['purge_state'] = $state;
            $out[] = $row;
        }
    }

    return $out;
}
