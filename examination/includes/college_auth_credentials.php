<?php
declare(strict_types=1);

/**
 * College Examination password store.
 * Independent of users.password. Never copies or writes LMS hashes here.
 *
 * Prerequisite: apply migrations/060_college_auth_credentials.sql before
 * College verify, login, reset, or staff create. This helper never creates
 * the table at request time (MySQL DDL would commit an open transaction).
 */

const COLLEGE_AUTH_SCHEMA_MISSING =
    'College Examination sign-in is temporarily unavailable. Apply migration 060_college_auth_credentials.sql.';

function college_auth_credentials_schema_ready(mysqli $conn): bool
{
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'college_auth_credentials'");
    $ok = $r && mysqli_fetch_row($r);
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool) $ok;
}

function college_auth_unusable_lms_password_hash(): string
{
    return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
}

/**
 * @return array{user_id:int,password_hash:string}|null
 */
function college_auth_credentials_load(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0 || !college_auth_credentials_schema_ready($conn)) {
        return null;
    }
    $stmt = mysqli_prepare(
        $conn,
        'SELECT user_id, password_hash FROM college_auth_credentials WHERE user_id=? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        return null;
    }
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!is_array($row)) {
        return null;
    }

    return [
        'user_id' => (int) ($row['user_id'] ?? 0),
        'password_hash' => (string) ($row['password_hash'] ?? ''),
    ];
}

function college_auth_credentials_exists(mysqli $conn, int $userId): bool
{
    $row = college_auth_credentials_load($conn, $userId);

    return is_array($row) && ($row['password_hash'] ?? '') !== '';
}

/**
 * Insert or update a College password hash. $passwordHash must already be hashed.
 * Returns false when migration 060 is missing; callers must fail the request.
 */
function college_auth_credentials_put(mysqli $conn, int $userId, string $passwordHash): bool
{
    if ($userId <= 0 || $passwordHash === '' || !college_auth_credentials_schema_ready($conn)) {
        return false;
    }
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO college_auth_credentials (user_id, password_hash, created_at, updated_at)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), updated_at=VALUES(updated_at)'
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'isss', $userId, $passwordHash, $now, $now);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return (bool) $ok;
}

/**
 * Verify only against college_auth_credentials. Never falls back to users.password.
 */
function college_auth_credentials_verify(mysqli $conn, int $userId, string $password): bool
{
    if ($userId <= 0 || $password === '' || !college_auth_credentials_schema_ready($conn)) {
        return false;
    }
    $row = college_auth_credentials_load($conn, $userId);
    if ($row === null) {
        return false;
    }
    $stored = (string) ($row['password_hash'] ?? '');

    return $stored !== '' && password_verify($password, $stored);
}
