<?php
declare(strict_types=1);

/**
 * College Examination student numbers: YYYY-NNN via per-year sequence.
 * Capture mysqli_insert_id() immediately after the sequence statement.
 */

function college_student_number_is_generated(?string $studentNumber): bool
{
    $sn = trim((string) $studentNumber);
    if (!preg_match('/^(\d{4})-(\d{3})$/', $sn, $m)) {
        return false;
    }
    $year = (int) $m[1];
    $n = (int) $m[2];

    return $year >= 2000 && $year <= 2100 && $n >= 1 && $n <= 999;
}

function college_student_number_is_legacy_allowed(?string $studentNumber): bool
{
    $sn = trim((string) $studentNumber);
    if ($sn === '') {
        return true;
    }
    if (college_student_number_is_generated($sn)) {
        return false;
    }

    return strlen($sn) <= 32 && (bool) preg_match('/^[A-Za-z0-9_-]+$/', $sn);
}

/**
 * Staff may set/clear legacy numbers only. Generated YYYY-NNN must not be changed or cleared.
 *
 * @return string|null Error message, or null if the proposed change is allowed.
 */
function college_student_number_staff_change_error(?string $current, ?string $proposed): ?string
{
    $current = trim((string) $current);
    $proposed = trim((string) $proposed);
    if (college_student_number_is_generated($current)) {
        if ($proposed === $current) {
            return null;
        }

        return 'This generated student number cannot be changed or cleared.';
    }
    if ($proposed !== '' && college_student_number_is_generated($proposed)) {
        return 'Staff cannot assign YYYY-NNN student numbers. Those are allocated only by college registration or college-student create.';
    }
    if ($proposed !== '' && !college_student_number_is_legacy_allowed($proposed)) {
        return 'Student number must be at most 32 characters and use only letters, digits, hyphen, or underscore.';
    }

    return null;
}

function college_student_number_issue_year(): int
{
    return (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y');
}

/**
 * Year used for backfill: Manila calendar year of users.created_at.
 * Returns null when created_at is missing so callers do not invent a year.
 */
function college_student_number_year_from_created_at(?string $createdAt): ?int
{
    $raw = trim((string) $createdAt);
    if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($raw, new DateTimeZone('Asia/Manila'));
    } catch (Exception $e) {
        return null;
    }
    $year = (int) $dt->format('Y');
    if ($year < 2000 || $year > 2100) {
        return null;
    }

    return $year;
}

function college_exam_id_display(?string $studentNumber): string
{
    return trim((string) $studentNumber);
}

/**
 * Public ranking label: College Exam ID only. Never falls back to user_id or full name.
 */
function college_exam_id_public_label(?string $studentNumber): string
{
    $sn = college_exam_id_display($studentNumber);

    return $sn !== '' ? $sn : 'Exam ID pending';
}

function college_student_number_seq_table_ready(mysqli $conn): bool
{
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'college_student_number_seq'");
    $ok = $r && mysqli_fetch_row($r);
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool) $ok;
}

function college_student_number_users_column_ready(mysqli $conn): bool
{
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'student_number'");
    $ok = $r && mysqli_fetch_assoc($r);
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool) $ok;
}

function college_student_number_tables_ready(mysqli $conn): bool
{
    return college_student_number_seq_table_ready($conn) && college_student_number_users_column_ready($conn);
}

/**
 * Allocate the next YYYY-NNN for the current Manila year.
 * Caller MUST already be inside a transaction. Do not call other AUTO_INCREMENT
 * inserts until after this function returns (insert_id is captured here).
 *
 * @return array{ok:bool, student_number?:string, n?:int, error?:string}
 */
function college_student_number_allocate_in_transaction(mysqli $conn, ?int $year = null): array
{
    if (!college_student_number_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'College student number sequence is not installed.'];
    }
    $year = $year ?? college_student_number_issue_year();
    if ($year < 2000 || $year > 2100) {
        return ['ok' => false, 'error' => 'Invalid College Exam ID year.'];
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO college_student_number_seq (issue_year, last_n) VALUES (?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE last_n = LAST_INSERT_ID(last_n + 1)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not allocate student number.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $year);
    try {
        $execOk = mysqli_stmt_execute($stmt);
    } catch (mysqli_sql_exception $e) {
        $errno = (int) $e->getCode();
        mysqli_stmt_close($stmt);

        return ['ok' => false, 'error' => 'Could not allocate student number.', 'errno' => $errno];
    }
    if (!$execOk) {
        $errno = mysqli_errno($conn);
        mysqli_stmt_close($stmt);

        return ['ok' => false, 'error' => 'Could not allocate student number.', 'errno' => $errno];
    }
    mysqli_stmt_close($stmt);
    // Capture immediately — any later INSERT (users, etc.) would steal insert_id.
    $n = (int) mysqli_insert_id($conn);
    if ($n < 1) {
        return ['ok' => false, 'error' => 'Could not capture student number sequence.'];
    }
    if ($n > 999) {
        return ['ok' => false, 'error' => 'Student number sequence for this year is exhausted.'];
    }

    return [
        'ok' => true,
        'n' => $n,
        'year' => $year,
        'student_number' => sprintf('%04d-%03d', $year, $n),
    ];
}

/**
 * Raise per-year sequence floors to match already-assigned YYYY-NNN values (idempotent).
 *
 * @return array{ok:bool, years?:array<int,int>, error?:string}
 */
function college_student_number_sync_seq_floors(mysqli $conn): array
{
    if (!college_student_number_tables_ready($conn)) {
        return ['ok' => false, 'error' => 'College student number sequence is not installed.'];
    }
    $floors = [];
    $res = mysqli_query($conn, "SELECT student_number FROM users WHERE student_number IS NOT NULL AND TRIM(student_number) <> ''");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $sn = trim((string) ($row['student_number'] ?? ''));
            if (!preg_match('/^(\d{4})-(\d{3})$/', $sn, $m)) {
                continue;
            }
            $year = (int) $m[1];
            $n = (int) $m[2];
            if ($year < 2000 || $year > 2100 || $n < 1) {
                continue;
            }
            $floors[$year] = max($floors[$year] ?? 0, $n);
        }
        mysqli_free_result($res);
    }
    foreach ($floors as $year => $n) {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO college_student_number_seq (issue_year, last_n) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE last_n = GREATEST(last_n, VALUES(last_n))'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Could not sync College Exam ID sequence.'];
        }
        mysqli_stmt_bind_param($stmt, 'ii', $year, $n);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return ['ok' => false, 'error' => 'Could not sync College Exam ID sequence.'];
        }
        mysqli_stmt_close($stmt);
    }

    return ['ok' => true, 'years' => $floors];
}
