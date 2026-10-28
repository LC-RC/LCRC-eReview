<?php
declare(strict_types=1);

/** users.school is varchar(150) in migrations/001_full_schema.sql */
const COLLEGE_SCHOOL_MAX_LEN = 150;

function college_school_assignment_columns_ready(mysqli $conn): bool
{
    $a = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'college_school_assigned_at'");
    $okA = $a && mysqli_fetch_assoc($a);
    if ($a) {
        mysqli_free_result($a);
    }
    $b = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'college_school_assigned_by'");
    $okB = $b && mysqli_fetch_assoc($b);
    if ($b) {
        mysqli_free_result($b);
    }

    return (bool) $okA && (bool) $okB;
}

function college_school_catalog_ready(mysqli $conn): bool
{
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'college_schools'");
    $ok = $r && mysqli_fetch_row($r);
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool) $ok;
}

function college_school_log_ready(mysqli $conn): bool
{
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'college_school_assignment_log'");
    $ok = $r && mysqli_fetch_row($r);
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool) $ok;
}

function college_school_assignment_schema_ready(mysqli $conn): bool
{
    return college_school_assignment_columns_ready($conn)
        && college_school_catalog_ready($conn)
        && college_school_log_ready($conn);
}

function college_school_normalize_name(string $raw): string
{
    return trim($raw);
}

function college_school_validate_name(string $normalized): ?string
{
    if ($normalized === '') {
        return 'Enter a school name. Empty values cannot be assigned.';
    }
    $len = function_exists('mb_strlen') ? mb_strlen($normalized) : strlen($normalized);
    if ($len > COLLEGE_SCHOOL_MAX_LEN) {
        return 'School name must be at most ' . COLLEGE_SCHOOL_MAX_LEN . ' characters.';
    }

    return null;
}

function college_school_row_is_staff_assigned(array $row): bool
{
    $school = trim((string) ($row['school'] ?? ''));
    $assignedAt = trim((string) ($row['college_school_assigned_at'] ?? ''));

    return $school !== '' && $assignedAt !== '' && $assignedAt !== '0000-00-00 00:00:00';
}

/**
 * @return list<string>
 */
function college_schools_catalog_names(mysqli $conn): array
{
    if (!college_school_catalog_ready($conn)) {
        return [];
    }
    $out = [];
    $q = mysqli_query($conn, 'SELECT school_name FROM college_schools ORDER BY school_name ASC');
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $n = trim((string) ($row['school_name'] ?? ''));
            if ($n !== '') {
                $out[] = $n;
            }
        }
        mysqli_free_result($q);
    }

    return $out;
}

function college_schools_catalog_ensure(mysqli $conn, string $name, int $actorUserId): bool
{
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO college_schools (school_name, created_at, created_by) VALUES (?, NOW(), ?)
         ON DUPLICATE KEY UPDATE school_name = school_name'
    );
    if (!$ins) {
        return false;
    }
    mysqli_stmt_bind_param($ins, 'si', $name, $actorUserId);
    $ok = mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);

    return $ok;
}

/**
 * Assign/correct school. Does not change status, exam access, section, or student number.
 *
 * @return array{ok:bool, error?:string, log_id?:int, school?:string}
 */
function college_school_assign_for_user(mysqli $conn, int $userId, string $rawSchool, int $actorUserId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid student.'];
    }
    if (!college_school_assignment_schema_ready($conn)) {
        return ['ok' => false, 'error' => 'School assignment tables are not installed. Apply migration 053 first.'];
    }
    $name = college_school_normalize_name($rawSchool);
    $err = college_school_validate_name($name);
    if ($err !== null) {
        return ['ok' => false, 'error' => $err];
    }

    $lock = mysqli_prepare(
        $conn,
        'SELECT user_id, role, school, college_school_assigned_at, college_school_assigned_by, college_examination_access
         FROM users WHERE user_id=? LIMIT 1 FOR UPDATE'
    );
    if (!$lock) {
        return ['ok' => false, 'error' => 'Could not load student.'];
    }
    mysqli_stmt_bind_param($lock, 'i', $userId);
    if (!mysqli_stmt_execute($lock)) {
        mysqli_stmt_close($lock);

        return ['ok' => false, 'error' => 'Could not load student.'];
    }
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
    mysqli_stmt_close($lock);
    if (!$row) {
        return ['ok' => false, 'error' => 'Student not found.'];
    }
    $role = (string) ($row['role'] ?? '');
    $onRoster = ($role === 'college_student');
    if ($role === 'student' && function_exists('ereview_user_college_examination_access_value')) {
        $access = ereview_user_college_examination_access_value($row);
        $onRoster = in_array($access, ['active', 'suspended'], true);
    }
    if (!$onRoster) {
        return ['ok' => false, 'error' => 'School can only be assigned on the College Examination roster.'];
    }

    $priorSchool = (string) ($row['school'] ?? '');
    $priorAt = $row['college_school_assigned_at'] ?? null;
    $priorBy = isset($row['college_school_assigned_by']) ? (int) $row['college_school_assigned_by'] : null;
    if ($priorBy !== null && $priorBy <= 0) {
        $priorBy = null;
    }
    $already = college_school_row_is_staff_assigned($row) && trim($priorSchool) === $name;
    if ($already) {
        return ['ok' => true, 'log_id' => 0, 'school' => $name];
    }

    $catalogRow = null;
    $hasArchiveCol = function_exists('college_schools_archive_columns_ready') && college_schools_archive_columns_ready($conn);
    $catSql = $hasArchiveCol
        ? 'SELECT school_id, school_name, archived_at FROM college_schools WHERE school_name=? LIMIT 1'
        : 'SELECT school_id, school_name FROM college_schools WHERE school_name=? LIMIT 1';
    $catSt = mysqli_prepare($conn, $catSql);
    if ($catSt) {
        mysqli_stmt_bind_param($catSt, 's', $name);
        mysqli_stmt_execute($catSt);
        $catalogRow = mysqli_fetch_assoc(mysqli_stmt_get_result($catSt));
        mysqli_stmt_close($catSt);
    }
    if (!$catalogRow) {
        return ['ok' => false, 'error' => 'Choose a school from the directory (Manage Schools). New names cannot be typed here.'];
    }
    $archivedAt = trim((string) ($catalogRow['archived_at'] ?? ''));
    if ($archivedAt !== '' && !preg_match('/^0000-00-00/', $archivedAt)) {
        return ['ok' => false, 'error' => 'That school is archived. Restore it in Manage Schools before assigning.'];
    }
    $catalogSchoolId = (int) ($catalogRow['school_id'] ?? 0);

    $log = mysqli_prepare(
        $conn,
        'INSERT INTO college_school_assignment_log
            (user_id, actor_user_id, action, prior_school, new_school, prior_assigned_at, prior_assigned_by, created_at)
         VALUES (?, ?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, 0), NOW())'
    );
    if (!$log) {
        return ['ok' => false, 'error' => 'Could not write school assignment audit.'];
    }
    $action = college_school_row_is_staff_assigned($row) ? 'correct' : 'assign';
    $priorAtBind = is_string($priorAt) && trim($priorAt) !== '' ? (string) $priorAt : '';
    $priorBySql = $priorBy ?? 0;
    mysqli_stmt_bind_param(
        $log,
        'iissssi',
        $userId,
        $actorUserId,
        $action,
        $priorSchool,
        $name,
        $priorAtBind,
        $priorBySql
    );
    if (!mysqli_stmt_execute($log)) {
        mysqli_stmt_close($log);

        return ['ok' => false, 'error' => 'Could not write school assignment audit.'];
    }
    mysqli_stmt_close($log);
    $logId = (int) mysqli_insert_id($conn);

    $hasSchoolFk = false;
    $fkCol = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'college_school_id'");
    if ($fkCol && mysqli_fetch_assoc($fkCol)) {
        $hasSchoolFk = true;
    }
    if ($fkCol) {
        mysqli_free_result($fkCol);
    }
    if ($hasSchoolFk && $catalogSchoolId > 0) {
        $upd = mysqli_prepare(
            $conn,
            'UPDATE users
             SET school=?, college_school_assigned_at=NOW(), college_school_assigned_by=?, college_school_id=?
             WHERE user_id=? LIMIT 1'
        );
        if (!$upd) {
            return ['ok' => false, 'error' => 'Could not save school assignment.'];
        }
        mysqli_stmt_bind_param($upd, 'siii', $name, $actorUserId, $catalogSchoolId, $userId);
    } else {
        $upd = mysqli_prepare(
            $conn,
            'UPDATE users
             SET school=?, college_school_assigned_at=NOW(), college_school_assigned_by=?
             WHERE user_id=? LIMIT 1'
        );
        if (!$upd) {
            return ['ok' => false, 'error' => 'Could not save school assignment.'];
        }
        mysqli_stmt_bind_param($upd, 'sii', $name, $actorUserId, $userId);
    }
    if (!mysqli_stmt_execute($upd)) {
        mysqli_stmt_close($upd);

        return ['ok' => false, 'error' => 'Could not save school assignment.'];
    }
    mysqli_stmt_close($upd);

    return ['ok' => true, 'log_id' => $logId, 'school' => $name];
}

/**
 * Latest log (any action) must be the one being reversed, including same-name re-assign.
 */
function college_school_rollback_stale_error(int $requestedLogId, int $latestLogIdForUser): ?string
{
    if ($requestedLogId < 1) {
        return 'Invalid school assignment log id.';
    }
    if ($latestLogIdForUser !== $requestedLogId) {
        return 'That school assignment log is not the latest change for this student. Rollback the latest log (#'
            . $latestLogIdForUser . ') first.';
    }

    return null;
}

/**
 * Restore exact prior school text and prior assigned_at/by from a log row.
 *
 * @return array{ok:bool, error?:string, log_id?:int}
 */
function college_school_rollback_log(mysqli $conn, int $logId, int $actorUserId): array
{
    if ($logId < 1) {
        return ['ok' => false, 'error' => 'Invalid school assignment log id.'];
    }
    if (!college_school_assignment_schema_ready($conn)) {
        return ['ok' => false, 'error' => 'School assignment tables are not installed. Apply migration 053 first.'];
    }
    $st = mysqli_prepare(
        $conn,
        'SELECT log_id, user_id, action, prior_school, new_school, prior_assigned_at, prior_assigned_by
         FROM college_school_assignment_log WHERE log_id=? LIMIT 1'
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'Could not load school assignment log.'];
    }
    mysqli_stmt_bind_param($st, 'i', $logId);
    mysqli_stmt_execute($st);
    $log = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    if (!$log) {
        return ['ok' => false, 'error' => 'School assignment log #' . $logId . ' was not found.'];
    }
    if ((string) ($log['action'] ?? '') === 'rollback') {
        return ['ok' => false, 'error' => 'That log entry is already a rollback.'];
    }
    $userId = (int) ($log['user_id'] ?? 0);
    $restoreSchool = (string) ($log['prior_school'] ?? '');
    $restoreAt = $log['prior_assigned_at'] ?? null;
    $restoreBy = isset($log['prior_assigned_by']) ? (int) $log['prior_assigned_by'] : 0;
    if ($restoreBy <= 0) {
        $restoreBy = null;
    }

    $lock = mysqli_prepare(
        $conn,
        'SELECT user_id, school, college_school_assigned_at, college_school_assigned_by
         FROM users WHERE user_id=? LIMIT 1 FOR UPDATE'
    );
    if (!$lock) {
        return ['ok' => false, 'error' => 'Could not load student for rollback.'];
    }
    mysqli_stmt_bind_param($lock, 'i', $userId);
    mysqli_stmt_execute($lock);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
    mysqli_stmt_close($lock);
    if (!$row) {
        return ['ok' => false, 'error' => 'Student #' . $userId . ' was not found.'];
    }

    $latestSt = mysqli_prepare(
        $conn,
        'SELECT MAX(log_id) AS max_id FROM college_school_assignment_log WHERE user_id=?'
    );
    if (!$latestSt) {
        return ['ok' => false, 'error' => 'Could not verify school assignment log order.'];
    }
    mysqli_stmt_bind_param($latestSt, 'i', $userId);
    mysqli_stmt_execute($latestSt);
    $latestRow = mysqli_fetch_assoc(mysqli_stmt_get_result($latestSt));
    mysqli_stmt_close($latestSt);
    $latestLogId = (int) ($latestRow['max_id'] ?? 0);
    $staleErr = college_school_rollback_stale_error($logId, $latestLogId);
    if ($staleErr !== null) {
        return ['ok' => false, 'error' => $staleErr];
    }

    $curSchool = (string) ($row['school'] ?? '');
    $curAt = $row['college_school_assigned_at'] ?? null;
    $curBy = isset($row['college_school_assigned_by']) ? (int) $row['college_school_assigned_by'] : 0;
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO college_school_assignment_log
            (user_id, actor_user_id, action, prior_school, new_school, prior_assigned_at, prior_assigned_by, created_at)
         VALUES (?, ?, \'rollback\', ?, ?, NULLIF(?, \'\'), NULLIF(?, 0), NOW())'
    );
    if (!$ins) {
        return ['ok' => false, 'error' => 'Could not write rollback audit.'];
    }
    $newSchoolSnap = $restoreSchool;
    $curBySql = $curBy > 0 ? $curBy : 0;
    $curAtSql = is_string($curAt) && trim((string) $curAt) !== '' ? (string) $curAt : '';
    mysqli_stmt_bind_param($ins, 'iisssi', $userId, $actorUserId, $curSchool, $newSchoolSnap, $curAtSql, $curBySql);
    if (!mysqli_stmt_execute($ins)) {
        mysqli_stmt_close($ins);

        return ['ok' => false, 'error' => 'Could not write rollback audit.'];
    }
    mysqli_stmt_close($ins);
    $newLogId = (int) mysqli_insert_id($conn);

    $restoreAtSql = is_string($restoreAt) && trim((string) $restoreAt) !== '' ? $restoreAt : null;
    if ($restoreAtSql === null) {
        $upd = mysqli_prepare(
            $conn,
            'UPDATE users SET school=?, college_school_assigned_at=NULL, college_school_assigned_by=NULL WHERE user_id=? LIMIT 1'
        );
        if (!$upd) {
            return ['ok' => false, 'error' => 'Could not restore school.'];
        }
        mysqli_stmt_bind_param($upd, 'si', $restoreSchool, $userId);
    } else {
        $upd = mysqli_prepare(
            $conn,
            'UPDATE users SET school=?, college_school_assigned_at=?, college_school_assigned_by=? WHERE user_id=? LIMIT 1'
        );
        if (!$upd) {
            return ['ok' => false, 'error' => 'Could not restore school.'];
        }
        $by = $restoreBy ?? 0;
        mysqli_stmt_bind_param($upd, 'ssii', $restoreSchool, $restoreAtSql, $by, $userId);
    }
    if (!mysqli_stmt_execute($upd)) {
        mysqli_stmt_close($upd);

        return ['ok' => false, 'error' => 'Could not restore school.'];
    }
    mysqli_stmt_close($upd);

    return ['ok' => true, 'log_id' => $newLogId, 'user_id' => $userId];
}
