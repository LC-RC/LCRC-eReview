<?php
declare(strict_types=1);

/**
 * Database-backed rate limit for college public registration / resend.
 * Fail closed if the table is missing or any limiter query/insert fails.
 * Client IP is REMOTE_ADDR only (do not trust X-Forwarded-For).
 */

const COLLEGE_VERIFY_RATE_WINDOW_SECONDS = 3600;
const COLLEGE_VERIFY_RATE_MAX_PER_EMAIL = 5;
const COLLEGE_VERIFY_RATE_MAX_PER_IP = 10;

function college_verify_rate_limit_table_ready(mysqli $conn): bool
{
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'college_verify_rate_events'");
    $ok = $r && mysqli_fetch_row($r);
    if ($r) {
        mysqli_free_result($r);
    }

    return (bool) $ok;
}

function college_verify_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        return '0.0.0.0';
    }

    return $ip;
}

function college_verify_rate_hash(string $value): string
{
    return hash('sha256', $value);
}

/**
 * @return array{ok:bool, error?:string}
 */
function college_verify_rate_limit_consume(mysqli $conn, string $action, string $email): array
{
    if (!college_verify_rate_limit_table_ready($conn)) {
        return ['ok' => false, 'error' => 'unavailable'];
    }
    $action = strtolower(trim($action));
    if (!in_array($action, ['register', 'resend', 'resend_number'], true)) {
        return ['ok' => false, 'error' => 'unavailable'];
    }
    $emailNorm = strtolower(trim($email));
    if ($emailNorm === '') {
        return ['ok' => false, 'error' => 'unavailable'];
    }
    $ipHash = college_verify_rate_hash(college_verify_client_ip());
    $emailHash = college_verify_rate_hash($emailNorm);
    $since = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
        ->modify('-' . COLLEGE_VERIFY_RATE_WINDOW_SECONDS . ' seconds')
        ->format('Y-m-d H:i:s');

    $countStmt = static function (mysqli $conn, string $sql, string $hash, string $action, string $since): ?int {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'sss', $hash, $action, $since);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            return null;
        }
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!is_array($row) || !array_key_exists('c', $row)) {
            return null;
        }

        return (int) $row['c'];
    };

    $emailCount = $countStmt(
        $conn,
        'SELECT COUNT(*) AS c FROM college_verify_rate_events WHERE email_hash=? AND action=? AND created_at>=?',
        $emailHash,
        $action,
        $since
    );
    $ipCount = $countStmt(
        $conn,
        'SELECT COUNT(*) AS c FROM college_verify_rate_events WHERE ip_hash=? AND action=? AND created_at>=?',
        $ipHash,
        $action,
        $since
    );
    if ($emailCount === null || $ipCount === null) {
        return ['ok' => false, 'error' => 'unavailable'];
    }
    if ($emailCount >= COLLEGE_VERIFY_RATE_MAX_PER_EMAIL || $ipCount >= COLLEGE_VERIFY_RATE_MAX_PER_IP) {
        return ['ok' => false, 'error' => 'rate_limited'];
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO college_verify_rate_events (action, ip_hash, email_hash, created_at) VALUES (?, ?, ?, ?)'
    );
    if (!$ins) {
        return ['ok' => false, 'error' => 'unavailable'];
    }
    mysqli_stmt_bind_param($ins, 'ssss', $action, $ipHash, $emailHash, $now);
    $ok = mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);
    if (!$ok) {
        return ['ok' => false, 'error' => 'unavailable'];
    }

    return ['ok' => true];
}
