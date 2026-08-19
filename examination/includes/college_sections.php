<?php
declare(strict_types=1);

/**
 * Centralized College Examination sections (master catalog).
 * Assignment tables still store section_value strings that must match users.section.
 */

function college_sections_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    @mysqli_query(
        $conn,
        "CREATE TABLE IF NOT EXISTS `college_sections` (
          `section_id` INT NOT NULL AUTO_INCREMENT,
          `section_name` VARCHAR(100) NOT NULL,
          `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          `created_by` INT NULL DEFAULT NULL,
          `updated_by` INT NULL DEFAULT NULL,
          PRIMARY KEY (`section_id`),
          UNIQUE KEY `uq_college_sections_name` (`section_name`),
          KEY `idx_college_sections_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

function college_sections_normalize_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

    return mb_substr($name, 0, 100);
}

function college_sections_is_valid_name(string $name): bool
{
    $name = college_sections_normalize_name($name);

    return $name !== '' && mb_strlen($name) <= 100;
}

/**
 * @return list<array{section_id:int,section_name:string,status:string}>
 */
function college_sections_list(mysqli $conn, bool $activeOnly = false): array
{
    college_sections_ensure_schema($conn);
    $sql = 'SELECT section_id, section_name, status, updated_at FROM college_sections';
    if ($activeOnly) {
        $sql .= " WHERE status='active'";
    }
    $sql .= ' ORDER BY section_name ASC';
    $out = [];
    $q = @mysqli_query($conn, $sql);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $out[] = [
                'section_id' => (int) ($row['section_id'] ?? 0),
                'section_name' => (string) ($row['section_name'] ?? ''),
                'status' => (string) ($row['status'] ?? 'active'),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }
        mysqli_free_result($q);
    }

    return $out;
}

/**
 * @return list<string>
 */
function college_sections_active_names(mysqli $conn): array
{
    $names = [];
    foreach (college_sections_list($conn, true) as $row) {
        $n = trim($row['section_name']);
        if ($n !== '') {
            $names[] = $n;
        }
    }

    return $names;
}

function college_sections_find_by_name(mysqli $conn, string $name): ?array
{
    college_sections_ensure_schema($conn);
    $name = college_sections_normalize_name($name);
    if ($name === '') {
        return null;
    }
    $st = mysqli_prepare($conn, 'SELECT section_id, section_name, status FROM college_sections WHERE section_name=? LIMIT 1');
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 's', $name);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    return $row ?: null;
}

function college_sections_find_by_id(mysqli $conn, int $sectionId): ?array
{
    college_sections_ensure_schema($conn);
    if ($sectionId <= 0) {
        return null;
    }
    $st = mysqli_prepare($conn, 'SELECT section_id, section_name, status FROM college_sections WHERE section_id=? LIMIT 1');
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'i', $sectionId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);

    return $row ?: null;
}

/**
 * Resolve a posted section_id or section_name to a canonical active name.
 * Returns null if invalid / inactive / missing.
 */
function college_sections_resolve_active_name(mysqli $conn, $sectionIdOrName): ?string
{
    if (is_int($sectionIdOrName) || (is_string($sectionIdOrName) && ctype_digit($sectionIdOrName))) {
        $row = college_sections_find_by_id($conn, (int) $sectionIdOrName);
        if ($row && ($row['status'] ?? '') === 'active') {
            return (string) $row['section_name'];
        }

        return null;
    }
    $name = college_sections_normalize_name((string) $sectionIdOrName);
    $row = college_sections_find_by_name($conn, $name);
    if ($row && ($row['status'] ?? '') === 'active') {
        return (string) $row['section_name'];
    }

    return null;
}

/**
 * @return array{ok:bool,error?:string,section_id?:int}
 */
function college_sections_create(mysqli $conn, string $name, int $actorId = 0): array
{
    college_sections_ensure_schema($conn);
    $name = college_sections_normalize_name($name);
    if (!college_sections_is_valid_name($name)) {
        return ['ok' => false, 'error' => 'Section name is required (max 100 characters).'];
    }
    if (college_sections_find_by_name($conn, $name)) {
        return ['ok' => false, 'error' => 'That section already exists.'];
    }
    $st = mysqli_prepare(
        $conn,
        "INSERT INTO college_sections (section_name, status, created_by, updated_by) VALUES (?, 'active', NULLIF(?, 0), NULLIF(?, 0))"
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'Could not create section.'];
    }
    $by = max(0, $actorId);
    mysqli_stmt_bind_param($st, 'sii', $name, $by, $by);
    if (!mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);

        return ['ok' => false, 'error' => 'Could not create section (duplicate or DB error).'];
    }
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($st);

    return ['ok' => true, 'section_id' => $id];
}

/**
 * @return array{ok:bool,error?:string}
 */
function college_sections_update(mysqli $conn, int $sectionId, string $name, string $status, int $actorId = 0): array
{
    college_sections_ensure_schema($conn);
    $name = college_sections_normalize_name($name);
    $status = strtolower(trim($status)) === 'inactive' ? 'inactive' : 'active';
    if ($sectionId <= 0 || !college_sections_is_valid_name($name)) {
        return ['ok' => false, 'error' => 'Invalid section.'];
    }
    $existing = college_sections_find_by_id($conn, $sectionId);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Section not found.'];
    }
    $dup = college_sections_find_by_name($conn, $name);
    if ($dup && (int) $dup['section_id'] !== $sectionId) {
        return ['ok' => false, 'error' => 'Another section already uses that name.'];
    }
    $oldName = (string) ($existing['section_name'] ?? '');
    mysqli_begin_transaction($conn);
    try {
        $by = max(0, $actorId);
        $st = mysqli_prepare(
            $conn,
            'UPDATE college_sections SET section_name=?, status=?, updated_by=NULLIF(?, 0), updated_at=NOW() WHERE section_id=? LIMIT 1'
        );
        if (!$st) {
            throw new RuntimeException('Could not update section.');
        }
        mysqli_stmt_bind_param($st, 'ssii', $name, $status, $by, $sectionId);
        if (!mysqli_stmt_execute($st)) {
            mysqli_stmt_close($st);
            throw new RuntimeException('Update failed.');
        }
        mysqli_stmt_close($st);

        // Keep string-matched assignment + student profile in sync when renaming.
        if ($oldName !== '' && $oldName !== $name) {
            $u1 = mysqli_prepare($conn, 'UPDATE users SET section=? WHERE section=?');
            if ($u1) {
                mysqli_stmt_bind_param($u1, 'ss', $name, $oldName);
                mysqli_stmt_execute($u1);
                mysqli_stmt_close($u1);
            }
            $u2 = mysqli_prepare($conn, 'UPDATE college_exam_sections SET section_value=? WHERE section_value=?');
            if ($u2) {
                mysqli_stmt_bind_param($u2, 'ss', $name, $oldName);
                mysqli_stmt_execute($u2);
                mysqli_stmt_close($u2);
            }
            $u3 = mysqli_prepare($conn, 'UPDATE diagnostic_batch_sections SET section_value=? WHERE section_value=?');
            if ($u3) {
                mysqli_stmt_bind_param($u3, 'ss', $name, $oldName);
                mysqli_stmt_execute($u3);
                mysqli_stmt_close($u3);
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
function college_sections_set_status(mysqli $conn, int $sectionId, string $status, int $actorId = 0): array
{
    $row = college_sections_find_by_id($conn, $sectionId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Section not found.'];
    }

    return college_sections_update($conn, $sectionId, (string) $row['section_name'], $status, $actorId);
}

/**
 * Seed master from existing free-text values (idempotent).
 */
function college_sections_seed_from_existing(mysqli $conn): int
{
    college_sections_ensure_schema($conn);
    $inserted = 0;
    $queries = [
        "SELECT DISTINCT TRIM(section) AS section_name FROM users WHERE section IS NOT NULL AND TRIM(section) <> ''",
        "SELECT DISTINCT TRIM(section_value) AS section_name FROM college_exam_sections WHERE section_value IS NOT NULL AND TRIM(section_value) <> ''",
        "SELECT DISTINCT TRIM(section_value) AS section_name FROM diagnostic_batch_sections WHERE section_value IS NOT NULL AND TRIM(section_value) <> ''",
    ];
    foreach ($queries as $sql) {
        $q = @mysqli_query($conn, $sql);
        if (!$q) {
            continue;
        }
        while ($row = mysqli_fetch_assoc($q)) {
            $name = college_sections_normalize_name((string) ($row['section_name'] ?? ''));
            if ($name === '' || college_sections_find_by_name($conn, $name)) {
                continue;
            }
            $res = college_sections_create($conn, $name, 0);
            if (!empty($res['ok'])) {
                $inserted++;
            }
        }
        mysqli_free_result($q);
    }

    return $inserted;
}

/**
 * Student counts keyed by section_name (examinees only).
 *
 * @return array<string,int>
 */
function college_sections_student_counts(mysqli $conn): array
{
    $counts = [];
    $where = function_exists('ereview_sql_college_examinee_where')
        ? ereview_sql_college_examinee_where('u')
        : "((u.role='college_student') OR (u.role='student' AND u.college_examination_access='active')) AND u.status='approved'";
    $q = @mysqli_query(
        $conn,
        "SELECT TRIM(u.section) AS sec, COUNT(*) AS c
         FROM users u
         WHERE {$where} AND u.section IS NOT NULL AND TRIM(u.section) <> ''
         GROUP BY TRIM(u.section)"
    );
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $sec = trim((string) ($row['sec'] ?? ''));
            if ($sec !== '') {
                $counts[$sec] = (int) ($row['c'] ?? 0);
            }
        }
        mysqli_free_result($q);
    }

    return $counts;
}

/**
 * Reference counts for safe section deletion.
 *
 * @return array{students:int,exam_assignments:int,diagnostic_assignments:int,total:int}
 */
function college_sections_reference_counts(mysqli $conn, string $sectionName): array
{
    college_sections_ensure_schema($conn);
    $name = college_sections_normalize_name($sectionName);
    $out = ['students' => 0, 'exam_assignments' => 0, 'diagnostic_assignments' => 0, 'total' => 0];
    if ($name === '') {
        return $out;
    }

    $st = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM users WHERE section IS NOT NULL AND TRIM(section)=?");
    if ($st) {
        mysqli_stmt_bind_param($st, 's', $name);
        mysqli_stmt_execute($st);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        $out['students'] = (int) ($row['c'] ?? 0);
        mysqli_stmt_close($st);
    }

    $st2 = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM college_exam_sections WHERE section_value=?');
    if ($st2) {
        mysqli_stmt_bind_param($st2, 's', $name);
        mysqli_stmt_execute($st2);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st2));
        $out['exam_assignments'] = (int) ($row['c'] ?? 0);
        mysqli_stmt_close($st2);
    }

    $st3 = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM diagnostic_batch_sections WHERE section_value=?');
    if ($st3) {
        mysqli_stmt_bind_param($st3, 's', $name);
        mysqli_stmt_execute($st3);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st3));
        $out['diagnostic_assignments'] = (int) ($row['c'] ?? 0);
        mysqli_stmt_close($st3);
    }

    $out['total'] = $out['students'] + $out['exam_assignments'] + $out['diagnostic_assignments'];

    return $out;
}

/**
 * @return array<string,int>
 */
function college_sections_exam_assignment_counts(mysqli $conn): array
{
    $counts = [];
    $q = @mysqli_query($conn, 'SELECT TRIM(section_value) AS sec, COUNT(*) AS c FROM college_exam_sections GROUP BY TRIM(section_value)');
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $sec = trim((string) ($row['sec'] ?? ''));
            if ($sec !== '') {
                $counts[$sec] = (int) ($row['c'] ?? 0);
            }
        }
        mysqli_free_result($q);
    }

    return $counts;
}

/**
 * @return array<string,int>
 */
function college_sections_diagnostic_assignment_counts(mysqli $conn): array
{
    $counts = [];
    $q = @mysqli_query($conn, 'SELECT TRIM(section_value) AS sec, COUNT(*) AS c FROM diagnostic_batch_sections GROUP BY TRIM(section_value)');
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $sec = trim((string) ($row['sec'] ?? ''));
            if ($sec !== '') {
                $counts[$sec] = (int) ($row['c'] ?? 0);
            }
        }
        mysqli_free_result($q);
    }

    return $counts;
}

/**
 * Parse POSTed section value: __none__ / empty => null (clear), else canonical active name.
 *
 * @return array{ok:bool,error?:string,section?:?string}
 */
function college_sections_parse_optional_post(mysqli $conn, string $raw): array
{
    $section = trim($raw);
    if ($section === '' || $section === '__none__') {
        return ['ok' => true, 'section' => null];
    }
    if (mb_strlen($section) > 100) {
        return ['ok' => false, 'error' => 'Section must be at most 100 characters.'];
    }
    $canonical = college_sections_resolve_active_name($conn, $section);
    if ($canonical === null) {
        return ['ok' => false, 'error' => 'Select an active section from the College Examination Sections list, or choose No section.'];
    }

    return ['ok' => true, 'section' => $canonical];
}

/**
 * @return array{ok:bool,error?:string,references?:array<string,int>}
 */
function college_sections_delete(mysqli $conn, int $sectionId, int $actorId = 0): array
{
    college_sections_ensure_schema($conn);
    $row = college_sections_find_by_id($conn, $sectionId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Section not found.'];
    }
    $name = (string) ($row['section_name'] ?? '');
    $refs = college_sections_reference_counts($conn, $name);
    if ($refs['total'] > 0) {
        return ['ok' => false, 'error' => 'Section cannot be deleted because it is currently in use.', 'references' => $refs];
    }

    $st = mysqli_prepare($conn, 'DELETE FROM college_sections WHERE section_id=? LIMIT 1');
    if (!$st) {
        return ['ok' => false, 'error' => 'Could not delete section.'];
    }
    mysqli_stmt_bind_param($st, 'i', $sectionId);
    if (!mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);

        return ['ok' => false, 'error' => 'Delete failed.'];
    }
    $aff = mysqli_stmt_affected_rows($st);
    mysqli_stmt_close($st);
    if ($aff < 1) {
        return ['ok' => false, 'error' => 'Section not found.'];
    }

    return ['ok' => true];
}
