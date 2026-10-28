<?php
declare(strict_types=1);

/**
 * College Examination school directory (active/inactive catalog).
 * users.school remains the display/legacy string. Optional users.college_school_id.
 */

require_once __DIR__ . '/college_school_assignment.php';

function college_schools_has_status_column(mysqli $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    if (!college_school_catalog_ready($conn)) {
        $cached = false;

        return false;
    }
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `college_schools` LIKE 'status'");
    $cached = (bool) ($r && mysqli_fetch_assoc($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return $cached;
}

function college_schools_archive_columns_ready(mysqli $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    if (!college_school_catalog_ready($conn)) {
        $cached = false;

        return false;
    }
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `college_schools` LIKE 'archived_at'");
    $cached = (bool) ($r && mysqli_fetch_assoc($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return $cached;
}

function college_schools_user_id_column_ready(mysqli $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'college_school_id'");
    $cached = (bool) ($r && mysqli_fetch_assoc($r));
    if ($r) {
        mysqli_free_result($r);
    }

    return $cached;
}

function college_schools_normalize_name(string $raw): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($len > COLLEGE_SCHOOL_MAX_LEN) {
        $name = function_exists('mb_substr')
            ? (string) mb_substr($name, 0, COLLEGE_SCHOOL_MAX_LEN)
            : substr($name, 0, COLLEGE_SCHOOL_MAX_LEN);
    }

    return $name;
}

function college_schools_is_valid_name(string $name): bool
{
    $name = college_schools_normalize_name($name);
    $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

    return $name !== '' && $len <= COLLEGE_SCHOOL_MAX_LEN;
}

/**
 * @param 'directory'|'archived'|'all' $scope
 * @return list<array<string,mixed>>
 */
function college_schools_list(mysqli $conn, bool $activeOnly = false, string $scope = 'directory'): array
{
    if (!college_school_catalog_ready($conn)) {
        return [];
    }
    $hasStatus = college_schools_has_status_column($conn);
    $hasArchive = college_schools_archive_columns_ready($conn);
    $sql = $hasStatus
        ? 'SELECT school_id, school_name, status'
        : "SELECT school_id, school_name, 'active' AS status";
    if ($hasArchive) {
        $sql .= ', archived_at, archived_by, purge_eligible_at';
    }
    $sql .= ' FROM college_schools';
    $wheres = [];
    if ($activeOnly && $hasStatus) {
        $wheres[] = "status='active'";
    }
    if ($hasArchive && $scope === 'directory') {
        $wheres[] = 'archived_at IS NULL';
    } elseif ($hasArchive && $scope === 'archived') {
        $wheres[] = 'archived_at IS NOT NULL';
    }
    if ($wheres !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $wheres);
    }
    $sql .= $scope === 'archived' ? ' ORDER BY archived_at DESC, school_name ASC' : ' ORDER BY school_name ASC';
    $out = [];
    $q = mysqli_query($conn, $sql);
    if (!$q) {
        return [];
    }
    while ($row = mysqli_fetch_assoc($q)) {
        $n = trim((string) ($row['school_name'] ?? ''));
        if ($n === '') {
            continue;
        }
        $item = [
            'school_id' => (int) ($row['school_id'] ?? 0),
            'school_name' => $n,
            'status' => $hasStatus ? (string) ($row['status'] ?? 'active') : 'active',
            'archived_at' => $hasArchive ? (string) ($row['archived_at'] ?? '') : '',
            'archived_by' => $hasArchive ? (int) ($row['archived_by'] ?? 0) : 0,
            'purge_eligible_at' => $hasArchive ? (string) ($row['purge_eligible_at'] ?? '') : '',
        ];
        $out[] = $item;
    }
    mysqli_free_result($q);

    return $out;
}

/**
 * @return list<array{school_id:int,school_name:string,status:string}>
 */
function college_schools_active_list(mysqli $conn): array
{
    return college_schools_list($conn, true);
}

function college_schools_find_by_id(mysqli $conn, int $schoolId): ?array
{
    if ($schoolId <= 0 || !college_school_catalog_ready($conn)) {
        return null;
    }
    $hasStatus = college_schools_has_status_column($conn);
    $hasArchive = college_schools_archive_columns_ready($conn);
    $sql = $hasStatus
        ? 'SELECT school_id, school_name, status'
        : "SELECT school_id, school_name, 'active' AS status";
    if ($hasArchive) {
        $sql .= ', archived_at, archived_by, purge_eligible_at';
    }
    $sql .= ' FROM college_schools WHERE school_id=? LIMIT 1';
    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'i', $schoolId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    if (!$row) {
        return null;
    }
    $row['school_id'] = (int) $row['school_id'];
    $row['school_name'] = trim((string) ($row['school_name'] ?? ''));
    $row['status'] = $hasStatus ? (string) ($row['status'] ?? 'active') : 'active';
    $row['archived_at'] = $hasArchive ? (string) ($row['archived_at'] ?? '') : '';
    $row['archived_by'] = $hasArchive ? (int) ($row['archived_by'] ?? 0) : 0;
    $row['purge_eligible_at'] = $hasArchive ? (string) ($row['purge_eligible_at'] ?? '') : '';

    return $row;
}

function college_schools_find_by_name(mysqli $conn, string $name): ?array
{
    $name = college_schools_normalize_name($name);
    if ($name === '' || !college_school_catalog_ready($conn)) {
        return null;
    }
    $hasStatus = college_schools_has_status_column($conn);
    $hasArchive = college_schools_archive_columns_ready($conn);
    $sql = $hasStatus
        ? 'SELECT school_id, school_name, status'
        : "SELECT school_id, school_name, 'active' AS status";
    if ($hasArchive) {
        $sql .= ', archived_at, archived_by, purge_eligible_at';
    }
    $sql .= ' FROM college_schools WHERE school_name=? LIMIT 1';
    $st = mysqli_prepare($conn, $sql);
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 's', $name);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    if (!$row) {
        return null;
    }
    $row['school_id'] = (int) $row['school_id'];
    $row['school_name'] = trim((string) ($row['school_name'] ?? ''));
    $row['status'] = $hasStatus ? (string) ($row['status'] ?? 'active') : 'active';
    $row['archived_at'] = $hasArchive ? (string) ($row['archived_at'] ?? '') : '';
    $row['archived_by'] = $hasArchive ? (int) ($row['archived_by'] ?? 0) : 0;
    $row['purge_eligible_at'] = $hasArchive ? (string) ($row['purge_eligible_at'] ?? '') : '';

    return $row;
}

/**
 * Public registration / student create: only an existing ACTIVE school id is valid.
 *
 * @return array{school_id:int,school_name:string,status:string}|null
 */
function college_schools_resolve_active_by_id(mysqli $conn, int $schoolId): ?array
{
    $row = college_schools_find_by_id($conn, $schoolId);
    if (!$row || ($row['status'] ?? '') !== 'active') {
        return null;
    }
    $archivedAt = trim((string) ($row['archived_at'] ?? ''));
    if ($archivedAt !== '' && !preg_match('/^0000-00-00/', $archivedAt)) {
        return null;
    }

    return $row;
}

/**
 * @return array{ok:bool,error?:string,school_id?:int}
 */
function college_schools_create(mysqli $conn, string $name, int $actorId = 0, string $status = 'active'): array
{
    if (!college_school_catalog_ready($conn)) {
        return ['ok' => false, 'error' => 'School directory is not installed. Apply migration 055.'];
    }
    $name = college_schools_normalize_name($name);
    if (!college_schools_is_valid_name($name)) {
        return ['ok' => false, 'error' => 'School name is required (max ' . COLLEGE_SCHOOL_MAX_LEN . ' characters).'];
    }
    if (college_schools_find_by_name($conn, $name)) {
        return ['ok' => false, 'error' => 'That school name already exists.'];
    }
    $status = strtolower(trim($status)) === 'inactive' ? 'inactive' : 'active';
    $by = max(0, $actorId);
    $hasStatus = college_schools_has_status_column($conn);
    if ($hasStatus) {
        $st = mysqli_prepare(
            $conn,
            'INSERT INTO college_schools (school_name, status, created_at, created_by, updated_by) VALUES (?, ?, NOW(), NULLIF(?, 0), NULLIF(?, 0))'
        );
        if (!$st) {
            return ['ok' => false, 'error' => 'Could not create school.'];
        }
        mysqli_stmt_bind_param($st, 'ssii', $name, $status, $by, $by);
    } else {
        $st = mysqli_prepare(
            $conn,
            'INSERT INTO college_schools (school_name, created_at, created_by) VALUES (?, NOW(), NULLIF(?, 0))'
        );
        if (!$st) {
            return ['ok' => false, 'error' => 'Could not create school.'];
        }
        mysqli_stmt_bind_param($st, 'si', $name, $by);
    }
    if (!mysqli_stmt_execute($st)) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($st);
        if ($errno === 1062) {
            return ['ok' => false, 'error' => 'That school name already exists.'];
        }

        return ['ok' => false, 'error' => 'Could not create school.'];
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($st);

    return ['ok' => true, 'school_id' => $id];
}

/**
 * Rename keeps users.school in sync for matching catalog rows. Unmatched legacy strings are left unchanged
 * unless they exactly equal the previous catalog name.
 *
 * @return array{ok:bool,error?:string}
 */
function college_schools_update(mysqli $conn, int $schoolId, string $name, string $status, int $actorId = 0): array
{
    if (!college_school_catalog_ready($conn)) {
        return ['ok' => false, 'error' => 'School directory is not installed. Apply migration 055.'];
    }
    $name = college_schools_normalize_name($name);
    $status = strtolower(trim($status)) === 'inactive' ? 'inactive' : 'active';
    if ($schoolId <= 0 || !college_schools_is_valid_name($name)) {
        return ['ok' => false, 'error' => 'Invalid school.'];
    }
    $existing = college_schools_find_by_id($conn, $schoolId);
    if (!$existing) {
        return ['ok' => false, 'error' => 'School not found.'];
    }
    $archivedAt = trim((string) ($existing['archived_at'] ?? ''));
    if ($archivedAt !== '' && !preg_match('/^0000-00-00/', $archivedAt)) {
        return ['ok' => false, 'error' => 'Restore this school before editing or changing status.'];
    }
    $dup = college_schools_find_by_name($conn, $name);
    if ($dup && (int) $dup['school_id'] !== $schoolId) {
        return ['ok' => false, 'error' => 'Another school already uses that name.'];
    }
    $oldName = (string) ($existing['school_name'] ?? '');
    $by = max(0, $actorId);
    $hasStatus = college_schools_has_status_column($conn);
    mysqli_begin_transaction($conn);
    try {
        if ($hasStatus) {
            $st = mysqli_prepare(
                $conn,
                'UPDATE college_schools SET school_name=?, status=?, updated_by=NULLIF(?, 0), updated_at=NOW() WHERE school_id=? LIMIT 1'
            );
            if (!$st) {
                throw new RuntimeException('Could not update school.');
            }
            mysqli_stmt_bind_param($st, 'ssii', $name, $status, $by, $schoolId);
        } else {
            $st = mysqli_prepare($conn, 'UPDATE college_schools SET school_name=? WHERE school_id=? LIMIT 1');
            if (!$st) {
                throw new RuntimeException('Could not update school.');
            }
            mysqli_stmt_bind_param($st, 'si', $name, $schoolId);
        }
        if (!mysqli_stmt_execute($st)) {
            $errno = mysqli_errno($conn);
            mysqli_stmt_close($st);
            if ($errno === 1062) {
                throw new RuntimeException('Another school already uses that name.');
            }
            throw new RuntimeException('Could not update school.');
        }
        mysqli_stmt_close($st);

        if ($oldName !== '' && $oldName !== $name) {
            if (college_schools_user_id_column_ready($conn)) {
                $u = mysqli_prepare($conn, 'UPDATE users SET school=? WHERE college_school_id=? OR school=?');
                if ($u) {
                    mysqli_stmt_bind_param($u, 'sis', $name, $schoolId, $oldName);
                    mysqli_stmt_execute($u);
                    mysqli_stmt_close($u);
                }
            } else {
                $u = mysqli_prepare($conn, 'UPDATE users SET school=? WHERE school=?');
                if ($u) {
                    mysqli_stmt_bind_param($u, 'ss', $name, $oldName);
                    mysqli_stmt_execute($u);
                    mysqli_stmt_close($u);
                }
            }
        }
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
function college_schools_set_status(mysqli $conn, int $schoolId, string $status, int $actorId = 0): array
{
    $row = college_schools_find_by_id($conn, $schoolId);
    if (!$row) {
        return ['ok' => false, 'error' => 'School not found.'];
    }

    return college_schools_update($conn, $schoolId, (string) $row['school_name'], $status, $actorId);
}

/**
 * Student counts keyed by school_id (name match plus optional FK).
 *
 * @return array<int,int>
 */
function college_schools_student_counts(mysqli $conn): array
{
    $counts = [];
    foreach (college_schools_list($conn, false, 'all') as $row) {
        $counts[(int) $row['school_id']] = 0;
    }
    if ($counts === []) {
        return $counts;
    }
    $hasFk = college_schools_user_id_column_ready($conn);
    $sql = $hasFk
        ? 'SELECT s.school_id, COUNT(u.user_id) AS c
           FROM college_schools s
           LEFT JOIN users u ON (u.college_school_id = s.school_id OR u.school = s.school_name)
           GROUP BY s.school_id'
        : 'SELECT s.school_id, COUNT(u.user_id) AS c
           FROM college_schools s
           LEFT JOIN users u ON u.school = s.school_name
           GROUP BY s.school_id';
    $q = @mysqli_query($conn, $sql);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $counts[(int) ($row['school_id'] ?? 0)] = (int) ($row['c'] ?? 0);
        }
        mysqli_free_result($q);
    }

    return $counts;
}

function college_schools_apply_user_fk(mysqli $conn, int $userId, int $schoolId): void
{
    if ($userId < 1 || $schoolId < 1 || !college_schools_user_id_column_ready($conn)) {
        return;
    }
    $st = mysqli_prepare($conn, 'UPDATE users SET college_school_id=? WHERE user_id=? LIMIT 1');
    if (!$st) {
        return;
    }
    mysqli_stmt_bind_param($st, 'ii', $schoolId, $userId);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
}
