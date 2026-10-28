<?php
/**
 * Phase 1 college regular exam client heartbeat (detect / alert only).
 * Does not touch attempt last_seen_at, save, submit, timeout, or attempt-row locking.
 */
declare(strict_types=1);

require_once __DIR__ . '/examination_schema_gate.php';

function college_exam_client_heartbeat_interval_seconds(): int
{
    return 15;
}

function college_exam_client_stale_seconds(): int
{
    return 45;
}

function college_exam_client_id_pepper(): string
{
    $env = getenv('EREVIEW_CLIENT_ID_PEPPER');
    if (is_string($env) && $env !== '') {
        return $env;
    }

    return 'ereview.college_exam_client.v1';
}

function college_exam_client_heartbeat_hash(string $raw): string
{
    return hash_hmac('sha256', $raw, college_exam_client_id_pepper());
}

function college_exam_client_id_valid(string $raw): bool
{
    $len = strlen($raw);

    return $len >= 16 && $len <= 128 && (bool)preg_match('/^[A-Za-z0-9_-]+$/', $raw);
}

function college_exam_client_heartbeat_ddl_clients(): string
{
    return "CREATE TABLE IF NOT EXISTS `college_exam_attempt_clients` (
      `client_row_id` bigint(20) NOT NULL AUTO_INCREMENT,
      `attempt_id` int(11) NOT NULL,
      `user_id` int(11) NOT NULL,
      `exam_id` int(11) NOT NULL,
      `instance_id_hash` char(64) NOT NULL,
      `browser_scope_hash` char(64) NOT NULL,
      `php_session_hash` char(64) DEFAULT NULL,
      `client_label` varchar(16) NOT NULL DEFAULT 'unknown',
      `first_seen_at` datetime NOT NULL,
      `last_heartbeat_at` datetime NOT NULL,
      PRIMARY KEY (`client_row_id`),
      UNIQUE KEY `uq_ceac_attempt_instance` (`attempt_id`,`instance_id_hash`),
      KEY `idx_ceac_exam_hb` (`exam_id`,`last_heartbeat_at`),
      KEY `idx_ceac_attempt_hb` (`attempt_id`,`last_heartbeat_at`),
      KEY `idx_ceac_user_hb` (`user_id`,`last_heartbeat_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

function college_exam_client_heartbeat_ddl_alerts(): string
{
    return "CREATE TABLE IF NOT EXISTS `college_exam_concurrent_alerts` (
      `alert_id` bigint(20) NOT NULL AUTO_INCREMENT,
      `attempt_id` int(11) NOT NULL,
      `user_id` int(11) NOT NULL,
      `exam_id` int(11) NOT NULL,
      `status` varchar(32) NOT NULL DEFAULT 'pending_review',
      `instance_count` int(11) NOT NULL DEFAULT 0,
      `browser_scope_count` int(11) NOT NULL DEFAULT 0,
      `peak_instance_count` int(11) NOT NULL DEFAULT 0,
      `peak_scope_count` int(11) NOT NULL DEFAULT 0,
      `first_detected_at` datetime NOT NULL,
      `last_detected_at` datetime NOT NULL,
      PRIMARY KEY (`alert_id`),
      UNIQUE KEY `uq_cecalert_attempt` (`attempt_id`),
      KEY `idx_cecalert_exam_status` (`exam_id`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

function college_exam_client_heartbeat_ensure_schema(mysqli $conn): bool
{
    if (!ereview_schema_ensure_enabled()) {
        return college_exam_client_heartbeat_tables_ready($conn);
    }
    @mysqli_query($conn, college_exam_client_heartbeat_ddl_clients());
    @mysqli_query($conn, college_exam_client_heartbeat_ddl_alerts());

    return college_exam_client_heartbeat_tables_ready($conn);
}

function college_exam_client_heartbeat_tables_ready(mysqli $conn): bool
{
    return ereview_schema_object_ready_cached(
        $conn,
        'college_exam_attempt_clients_v1',
        static function (mysqli $c): bool {
            $q = @mysqli_query($c, "SHOW TABLES LIKE 'college_exam_attempt_clients'");
            $okClients = $q && mysqli_fetch_row($q);
            if ($q) {
                mysqli_free_result($q);
            }
            if (!$okClients) {
                return false;
            }
            $q2 = @mysqli_query($c, "SHOW TABLES LIKE 'college_exam_concurrent_alerts'");
            $okAlerts = $q2 && mysqli_fetch_row($q2);
            if ($q2) {
                mysqli_free_result($q2);
            }

            return (bool)$okAlerts;
        }
    );
}

function college_exam_client_heartbeat_origin_ok(): bool
{
    $site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site === 'cross-site') {
        return false;
    }
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    if ($host === '') {
        return true;
    }
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
        $raw = trim((string)($_SERVER[$header] ?? ''));
        if ($raw === '') {
            continue;
        }
        $parts = parse_url($raw);
        $h = strtolower((string)($parts['host'] ?? ''));
        if ($h === '') {
            return false;
        }
        if ($h !== $host) {
            return false;
        }
    }

    return true;
}

function college_exam_client_label_normalize(string $raw): string
{
    $v = strtolower(trim($raw));
    if ($v === 'desktop' || $v === 'mobile') {
        return $v;
    }

    return 'unknown';
}

/**
 * Count active clients for one attempt (no attempt-row lock).
 *
 * @return array{instances:int,scopes:int}
 */
function college_exam_client_active_counts(mysqli $conn, int $attemptId, int $staleSeconds): array
{
    $out = ['instances' => 0, 'scopes' => 0];
    $staleSeconds = max(15, min(300, $staleSeconds));
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(DISTINCT instance_id_hash) AS instances, COUNT(DISTINCT browser_scope_hash) AS scopes
         FROM college_exam_attempt_clients
         WHERE attempt_id=? AND last_heartbeat_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)'
    );
    if (!$stmt) {
        return $out;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $attemptId, $staleSeconds);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $out['instances'] = (int)($row['instances'] ?? 0);
    $out['scopes'] = (int)($row['scopes'] ?? 0);

    return $out;
}

/**
 * @return array<int,array{instances:int,scopes:int}>
 */
function college_exam_client_active_counts_for_exam(mysqli $conn, int $examId): array
{
    $examId = (int)$examId;
    if ($examId <= 0 || !college_exam_client_heartbeat_tables_ready($conn)) {
        return [];
    }
    $stale = college_exam_client_stale_seconds();
    $stmt = mysqli_prepare(
        $conn,
        'SELECT attempt_id,
                COUNT(DISTINCT instance_id_hash) AS instances,
                COUNT(DISTINCT browser_scope_hash) AS scopes
         FROM college_exam_attempt_clients
         WHERE exam_id=? AND last_heartbeat_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
         GROUP BY attempt_id'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'ii', $examId, $stale);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $aid = (int)($row['attempt_id'] ?? 0);
        if ($aid > 0) {
            $out[$aid] = [
                'instances' => (int)($row['instances'] ?? 0),
                'scopes' => (int)($row['scopes'] ?? 0),
            ];
        }
    }
    mysqli_stmt_close($stmt);

    return $out;
}

/**
 * Upsert heartbeat + optional pending overlap alert. Never locks college_exam_attempts.
 *
 * @return array<string,mixed>
 */
function college_exam_client_heartbeat_process(
    mysqli $conn,
    int $userId,
    int $attemptId,
    string $instanceIdRaw,
    string $browserScopeRaw,
    ?string $phpSessionId = null,
    string $clientLabel = 'unknown'
): array {
    $interval = college_exam_client_heartbeat_interval_seconds();
    $stale = college_exam_client_stale_seconds();
    $base = [
        'ok' => false,
        'restricted' => false,
        'proof' => false,
        'heartbeat_interval_seconds' => $interval,
        'stale_seconds' => $stale,
    ];
    if ($userId <= 0 || $attemptId <= 0) {
        $base['error'] = 'Invalid request';

        return $base;
    }
    if (!college_exam_client_id_valid($instanceIdRaw) || !college_exam_client_id_valid($browserScopeRaw)) {
        $base['error'] = 'Invalid client identifiers';

        return $base;
    }
    if (!college_exam_client_heartbeat_tables_ready($conn)) {
        $base['error'] = 'Unavailable';

        return $base;
    }

    $peek = mysqli_prepare(
        $conn,
        "SELECT attempt_id, user_id, exam_id, status FROM college_exam_attempts WHERE attempt_id=? LIMIT 1"
    );
    if (!$peek) {
        $base['error'] = 'Lookup failed';

        return $base;
    }
    mysqli_stmt_bind_param($peek, 'i', $attemptId);
    mysqli_stmt_execute($peek);
    $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($peek));
    mysqli_stmt_close($peek);
    if (!$attempt) {
        $base['error'] = 'Attempt not found';
        $base['http'] = 404;

        return $base;
    }
    if ((int)($attempt['user_id'] ?? 0) !== $userId) {
        $base['error'] = 'Forbidden';
        $base['http'] = 403;

        return $base;
    }
    if ((string)($attempt['status'] ?? '') !== 'in_progress') {
        $base['error'] = 'Attempt not active';

        return $base;
    }

    $examId = (int)($attempt['exam_id'] ?? 0);
    $instanceHash = college_exam_client_heartbeat_hash($instanceIdRaw);
    $scopeHash = college_exam_client_heartbeat_hash($browserScopeRaw);
    $sessionHash = '';
    if (is_string($phpSessionId) && $phpSessionId !== '') {
        $sessionHash = hash_hmac('sha256', $phpSessionId, college_exam_client_id_pepper());
    }
    $label = college_exam_client_label_normalize($clientLabel);
    $nowSql = date('Y-m-d H:i:s');

    $startedTxn = mysqli_begin_transaction($conn);
    if (!$startedTxn) {
        $base['error'] = 'Transaction failed';

        return $base;
    }
    try {
        $upd = mysqli_prepare(
            $conn,
            'INSERT INTO college_exam_attempt_clients
                (attempt_id, user_id, exam_id, instance_id_hash, browser_scope_hash, php_session_hash, client_label, first_seen_at, last_heartbeat_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                last_heartbeat_at = VALUES(last_heartbeat_at),
                browser_scope_hash = VALUES(browser_scope_hash),
                php_session_hash = VALUES(php_session_hash),
                client_label = VALUES(client_label)'
        );
        if (!$upd) {
            throw new RuntimeException('upsert prepare failed');
        }
        mysqli_stmt_bind_param(
            $upd,
            'iiissssss',
            $attemptId,
            $userId,
            $examId,
            $instanceHash,
            $scopeHash,
            $sessionHash,
            $label,
            $nowSql,
            $nowSql
        );
        if (!mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            throw new RuntimeException('upsert failed');
        }
        mysqli_stmt_close($upd);

        $counts = college_exam_client_active_counts($conn, $attemptId, $stale);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $base['error'] = 'Heartbeat failed';

        return $base;
    }

    if ($counts['instances'] >= 2) {
        $alert = mysqli_prepare(
            $conn,
            'INSERT INTO college_exam_concurrent_alerts
                (attempt_id, user_id, exam_id, status, instance_count, browser_scope_count, peak_instance_count, peak_scope_count, first_detected_at, last_detected_at)
             VALUES (?, ?, ?, \'pending_review\', ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                last_detected_at = VALUES(last_detected_at),
                instance_count = VALUES(instance_count),
                browser_scope_count = VALUES(browser_scope_count),
                peak_instance_count = GREATEST(peak_instance_count, VALUES(instance_count)),
                peak_scope_count = GREATEST(peak_scope_count, VALUES(browser_scope_count)),
                status = \'pending_review\''
        );
        if ($alert) {
            $inst = $counts['instances'];
            $scopes = $counts['scopes'];
            mysqli_stmt_bind_param(
                $alert,
                str_repeat('i', 7) . 'ss',
                $attemptId,
                $userId,
                $examId,
                $inst,
                $scopes,
                $inst,
                $scopes,
                $nowSql,
                $nowSql
            );
            mysqli_stmt_execute($alert);
            mysqli_stmt_close($alert);
        }
    }

    $overlap = $counts['instances'] >= 2;

    return [
        'ok' => true,
        'attempt_id' => $attemptId,
        'active_instances' => $counts['instances'],
        'active_browser_scopes' => $counts['scopes'],
        'overlap' => $overlap,
        'restricted' => false,
        'proof' => false,
        'heartbeat_interval_seconds' => $interval,
        'stale_seconds' => $stale,
    ];
}
