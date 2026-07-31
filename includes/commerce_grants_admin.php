<?php
/**
 * Admin Grant Ledger (read-only) + Free Access post-approval revoke (Phase 9).
 *
 * Does NOT: paid grant revoke, create/edit grants, change FAR status, payments,
 * login/activation, or redesign Phase 8 reconcile/expiry algorithms.
 */

declare(strict_types=1);

require_once __DIR__ . '/commerce_catalog.php';
require_once __DIR__ . '/commerce_free_access.php';
require_once __DIR__ . '/commerce_revoke.php';
require_once __DIR__ . '/commerce_grant_expiry.php';

/** Max inclusive day span for ledger created_at filters. */
const COMMERCE_GRANTS_ADMIN_MAX_DATE_SPAN_DAYS = 366;

/** @var list<string> */
const COMMERCE_GRANTS_ADMIN_SOURCES = [
    'purchase',
    'free_access',
    'admin_manual',
    'complimentary',
    'extension',
];

/** @var list<string> */
const COMMERCE_GRANTS_ADMIN_STATUSES = [
    'active',
    'expired',
    'revoked',
];

/** Common SCA content_type strings used in commerce/tests. */
const COMMERCE_GRANTS_ADMIN_CONTENT_TYPES = [
    'full_lms',
    'subject',
    'lesson',
    'quiz',
    'video',
    'handout',
    'preboard_subject',
    'preboard_set',
    'preweek_unit',
    'preweek_topic',
    'test_bank',
];

const COMMERCE_GRANTS_ADMIN_DEFAULT_PER_PAGE = 25;
const COMMERCE_GRANTS_ADMIN_MAX_PER_PAGE = 100;

/**
 * @param array<string,mixed> $get
 * @return array{
 *   ok:bool,
 *   source:?string,
 *   status:?string,
 *   content_type:?string,
 *   student:string,
 *   payment_id:int,
 *   free_access_request_id:int,
 *   user_id:int,
 *   date_from:?string,
 *   date_to:?string,
 *   page:int,
 *   per_page:int,
 *   warnings:list<string>
 * }
 */
function commerce_grants_admin_parse_filters(array $get): array
{
    $warnings = [];

    $source = strtolower(trim((string) ($get['source'] ?? '')));
    if ($source === '' || $source === 'all') {
        $source = null;
    } elseif (!in_array($source, COMMERCE_GRANTS_ADMIN_SOURCES, true)) {
        $warnings[] = 'Unknown source filter ignored.';
        $source = null;
    }

    $status = strtolower(trim((string) ($get['status'] ?? '')));
    if ($status === '' || $status === 'all') {
        $status = null;
    } elseif (!in_array($status, COMMERCE_GRANTS_ADMIN_STATUSES, true)) {
        $warnings[] = 'Unknown status filter ignored.';
        $status = null;
    }

    $contentType = strtolower(trim((string) ($get['content_type'] ?? '')));
    if ($contentType === '' || $contentType === 'all') {
        $contentType = null;
    } elseif (!in_array($contentType, COMMERCE_GRANTS_ADMIN_CONTENT_TYPES, true)) {
        $warnings[] = 'Unknown content_type filter ignored.';
        $contentType = null;
    }

    $student = trim((string) ($get['student'] ?? ''));
    if (strlen($student) > 120) {
        $student = substr($student, 0, 120);
        $warnings[] = 'Student search truncated to 120 characters.';
    }

    $paymentId = (int) ($get['payment_id'] ?? 0);
    if ($paymentId < 0) {
        $paymentId = 0;
    }
    $farId = (int) ($get['free_access_request_id'] ?? 0);
    if ($farId < 0) {
        $farId = 0;
    }
    $userId = (int) ($get['user_id'] ?? 0);
    if ($userId < 0) {
        $userId = 0;
    }

    $dateFrom = null;
    $dateTo = null;
    $rawFrom = trim((string) ($get['date_from'] ?? ''));
    $rawTo = trim((string) ($get['date_to'] ?? ''));
    if ($rawFrom !== '' || $rawTo !== '') {
        if ($rawFrom === '' || $rawTo === '') {
            $warnings[] = 'Both date_from and date_to are required for date filter; dates ignored.';
        } else {
            $fromTs = strtotime($rawFrom . ' 00:00:00');
            $toTs = strtotime($rawTo . ' 23:59:59');
            if ($fromTs === false || $toTs === false) {
                $warnings[] = 'Invalid date_from/date_to; dates ignored.';
            } elseif ($fromTs > $toTs) {
                $warnings[] = 'date_from is after date_to; dates ignored.';
            } else {
                $spanDays = (int) floor(($toTs - $fromTs) / 86400) + 1;
                if ($spanDays > COMMERCE_GRANTS_ADMIN_MAX_DATE_SPAN_DAYS) {
                    $warnings[] = 'Date range exceeds ' . COMMERCE_GRANTS_ADMIN_MAX_DATE_SPAN_DAYS . ' days; dates ignored.';
                } else {
                    $dateFrom = date('Y-m-d', $fromTs);
                    $dateTo = date('Y-m-d', $toTs);
                }
            }
        }
    }

    $page = max(1, (int) ($get['page'] ?? 1));
    $perPage = (int) ($get['per_page'] ?? COMMERCE_GRANTS_ADMIN_DEFAULT_PER_PAGE);
    if ($perPage < 1) {
        $perPage = COMMERCE_GRANTS_ADMIN_DEFAULT_PER_PAGE;
    }
    if ($perPage > COMMERCE_GRANTS_ADMIN_MAX_PER_PAGE) {
        $perPage = COMMERCE_GRANTS_ADMIN_MAX_PER_PAGE;
    }

    return [
        'ok' => true,
        'source' => $source,
        'status' => $status,
        'content_type' => $contentType,
        'student' => $student,
        'payment_id' => $paymentId,
        'free_access_request_id' => $farId,
        'user_id' => $userId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'page' => $page,
        'per_page' => $perPage,
        'warnings' => $warnings,
    ];
}

function commerce_grants_admin_like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * @param array<string,mixed> $filters
 * @return array{sql:string,types:string,params:list<mixed>}
 */
function commerce_grants_admin_where(array $filters): array
{
    $parts = ['1=1'];
    $types = '';
    $params = [];

    if (!empty($filters['source'])) {
        $parts[] = 'g.source = ?';
        $types .= 's';
        $params[] = $filters['source'];
    }
    if (!empty($filters['status'])) {
        $parts[] = 'g.status = ?';
        $types .= 's';
        $params[] = $filters['status'];
    }
    if (!empty($filters['content_type'])) {
        $parts[] = 'g.content_type = ?';
        $types .= 's';
        $params[] = $filters['content_type'];
    }
    if ((int) ($filters['payment_id'] ?? 0) > 0) {
        $parts[] = 'g.payment_id = ?';
        $types .= 'i';
        $params[] = (int) $filters['payment_id'];
    }
    if ((int) ($filters['free_access_request_id'] ?? 0) > 0) {
        $parts[] = 'g.free_access_request_id = ?';
        $types .= 'i';
        $params[] = (int) $filters['free_access_request_id'];
    }
    if ((int) ($filters['user_id'] ?? 0) > 0) {
        $parts[] = 'g.user_id = ?';
        $types .= 'i';
        $params[] = (int) $filters['user_id'];
    }
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $parts[] = 'g.created_at >= ? AND g.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $types .= 'ss';
        $params[] = $filters['date_from'] . ' 00:00:00';
        $params[] = $filters['date_to'];
    }
    if (trim((string) ($filters['student'] ?? '')) !== '') {
        $parts[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
        $like = '%' . commerce_grants_admin_like_escape((string) $filters['student']) . '%';
        $types .= 'ss';
        $params[] = $like;
        $params[] = $like;
    }

    return [
        'sql' => implode(' AND ', $parts),
        'types' => $types,
        'params' => $params,
    ];
}

/**
 * @param array<string,mixed> $filters
 */
function commerce_grants_admin_count(mysqli $conn, array $filters): int
{
    $w = commerce_grants_admin_where($filters);
    $sql = "SELECT COUNT(*) AS c
            FROM access_grants g
            INNER JOIN users u ON u.user_id = g.user_id
            WHERE {$w['sql']}";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return 0;
    }
    if ($w['types'] !== '') {
        mysqli_stmt_bind_param($stmt, $w['types'], ...$w['params']);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return 0;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (int) ($row['c'] ?? 0);
}

/**
 * @param array<string,mixed> $filters
 * @return list<array<string,mixed>>
 */
function commerce_grants_admin_list(mysqli $conn, array $filters): array
{
    $w = commerce_grants_admin_where($filters);
    $perPage = max(1, min(COMMERCE_GRANTS_ADMIN_MAX_PER_PAGE, (int) ($filters['per_page'] ?? COMMERCE_GRANTS_ADMIN_DEFAULT_PER_PAGE)));
    $page = max(1, (int) ($filters['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT g.grant_id, g.user_id, g.source, g.status, g.content_type, g.content_id,
                   g.content_label, g.payment_id, g.payment_item_id, g.free_access_request_id,
                   g.starts_at, g.ends_at, g.revoked_at, g.revoke_reason, g.created_at,
                   u.full_name, u.email
            FROM access_grants g
            INNER JOIN users u ON u.user_id = g.user_id
            WHERE {$w['sql']}
            ORDER BY g.grant_id DESC
            LIMIT ? OFFSET ?";
    $types = $w['types'] . 'ii';
    $params = array_merge($w['params'], [$perPage, $offset]);

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return [];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * @param array<string,mixed> $get
 * @return array{filters:array,total:int,rows:list,total_pages:int}
 */
function commerce_grants_admin_build_ledger(mysqli $conn, array $get): array
{
    $filters = commerce_grants_admin_parse_filters($get);
    $total = commerce_grants_admin_count($conn, $filters);
    $perPage = (int) $filters['per_page'];
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    if ((int) $filters['page'] > $totalPages) {
        $filters['page'] = $totalPages;
    }
    $rows = commerce_grants_admin_list($conn, $filters);
    return [
        'filters' => $filters,
        'total' => $total,
        'rows' => $rows,
        'total_pages' => $totalPages,
    ];
}

/**
 * Revoke active Free Access grant for an approved FAR. FAR status stays approved.
 *
 * @return array{
 *   ok:bool,
 *   skipped?:bool,
 *   error?:string,
 *   request?:array,
 *   grant?:?array,
 *   user_id?:int,
 *   revoked_count?:int,
 *   reconcile?:array
 * }
 */
function commerce_far_revoke_access(
    mysqli $conn,
    int $requestId,
    int $adminId,
    string $reason
): array {
    if ($requestId <= 0 || $adminId <= 0) {
        return ['ok' => false, 'error' => 'invalid_ids'];
    }
    if (!commerce_schema_ready($conn)) {
        return ['ok' => false, 'error' => 'schema_not_ready'];
    }

    $vr = commerce_revoke_validate_reason($reason);
    if (empty($vr['ok'])) {
        return ['ok' => false, 'error' => $vr['error'] ?? 'invalid_reason'];
    }
    $storedReason = commerce_revoke_format_reason($adminId, (string) $vr['reason']);

    $req = commerce_far_get_request($conn, $requestId);
    if (!$req) {
        return ['ok' => false, 'error' => 'request_not_found'];
    }
    if ((string) ($req['status'] ?? '') !== 'approved') {
        return ['ok' => false, 'error' => 'far_not_approved', 'request' => $req];
    }

    $userId = (int) ($req['user_id'] ?? 0);
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'missing_user', 'request' => $req];
    }

    $grant = commerce_far_existing_full_lms_grant($conn, $requestId);
    if (!$grant) {
        return ['ok' => false, 'error' => 'grant_not_found', 'request' => $req];
    }
    if ((string) ($grant['source'] ?? '') !== 'free_access') {
        return ['ok' => false, 'error' => 'not_free_access_grant', 'request' => $req, 'grant' => $grant];
    }
    if ((int) ($grant['user_id'] ?? 0) !== $userId) {
        return ['ok' => false, 'error' => 'grant_user_mismatch', 'request' => $req, 'grant' => $grant];
    }
    if ((int) ($grant['free_access_request_id'] ?? 0) !== $requestId) {
        return ['ok' => false, 'error' => 'grant_far_mismatch', 'request' => $req, 'grant' => $grant];
    }

    $grantId = (int) ($grant['grant_id'] ?? 0);
    if ($grantId <= 0) {
        return ['ok' => false, 'error' => 'invalid_grant_id', 'request' => $req];
    }

    if ((string) ($grant['status'] ?? '') === 'revoked') {
        return [
            'ok' => true,
            'skipped' => true,
            'error' => 'already_revoked',
            'request' => $req,
            'grant' => $grant,
            'user_id' => $userId,
            'revoked_count' => 0,
        ];
    }
    if ((string) ($grant['status'] ?? '') !== 'active') {
        return [
            'ok' => false,
            'error' => 'grant_not_active',
            'request' => $req,
            'grant' => $grant,
        ];
    }

    $revokedCount = 0;
    mysqli_begin_transaction($conn);
    try {
        $lock = mysqli_prepare(
            $conn,
            "SELECT grant_id, user_id, source, status, free_access_request_id
             FROM access_grants
             WHERE grant_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        if (!$lock) {
            throw new RuntimeException('grant_lock_prepare_failed');
        }
        mysqli_stmt_bind_param($lock, 'i', $grantId);
        if (!mysqli_stmt_execute($lock)) {
            mysqli_stmt_close($lock);
            throw new RuntimeException('grant_lock_failed');
        }
        $lres = mysqli_stmt_get_result($lock);
        $locked = $lres ? mysqli_fetch_assoc($lres) : null;
        mysqli_stmt_close($lock);
        if (!$locked) {
            throw new RuntimeException('grant_missing_under_lock');
        }
        if ((string) ($locked['source'] ?? '') !== 'free_access') {
            throw new RuntimeException('not_free_access_under_lock');
        }
        if ((int) ($locked['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('user_mismatch_under_lock');
        }
        if ((int) ($locked['free_access_request_id'] ?? 0) !== $requestId) {
            throw new RuntimeException('far_mismatch_under_lock');
        }
        if ((string) ($locked['status'] ?? '') === 'revoked') {
            mysqli_commit($conn);
            $fresh = commerce_far_existing_full_lms_grant($conn, $requestId);
            return [
                'ok' => true,
                'skipped' => true,
                'error' => 'already_revoked',
                'request' => commerce_far_get_request($conn, $requestId) ?: $req,
                'grant' => $fresh,
                'user_id' => $userId,
                'revoked_count' => 0,
            ];
        }
        if ((string) ($locked['status'] ?? '') !== 'active') {
            throw new RuntimeException('grant_not_active_under_lock');
        }

        $upd = mysqli_prepare(
            $conn,
            "UPDATE access_grants SET
                status = 'revoked',
                revoked_at = NOW(),
                revoke_reason = ?
             WHERE grant_id = ?
               AND source = 'free_access'
               AND status = 'active'
               AND free_access_request_id = ?
               AND user_id = ?
             LIMIT 1"
        );
        if (!$upd) {
            throw new RuntimeException('revoke_prepare_failed');
        }
        mysqli_stmt_bind_param($upd, 'siii', $storedReason, $grantId, $requestId, $userId);
        if (!mysqli_stmt_execute($upd)) {
            $err = mysqli_error($conn);
            mysqli_stmt_close($upd);
            throw new RuntimeException('revoke_execute_failed:' . $err);
        }
        $revokedCount = (int) mysqli_stmt_affected_rows($upd);
        mysqli_stmt_close($upd);

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('commerce_far_revoke_access: ' . $e->getMessage());
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'request' => commerce_far_get_request($conn, $requestId) ?: $req,
            'grant' => commerce_far_existing_full_lms_grant($conn, $requestId),
        ];
    }

    if ($revokedCount < 1) {
        $fresh = commerce_far_existing_full_lms_grant($conn, $requestId);
        return [
            'ok' => true,
            'skipped' => true,
            'error' => 'already_revoked_or_no_active',
            'request' => commerce_far_get_request($conn, $requestId) ?: $req,
            'grant' => $fresh,
            'user_id' => $userId,
            'revoked_count' => 0,
        ];
    }

    $recon = commerce_reconcile_user_commerce_sca($conn, $userId);
    $freshGrant = commerce_far_existing_full_lms_grant($conn, $requestId);
    $freshReq = commerce_far_get_request($conn, $requestId) ?: $req;

    if (empty($recon['ok'])) {
        return [
            'ok' => false,
            'error' => 'grants_revoked_but_reconcile_failed:' . (string) ($recon['error'] ?? 'unknown'),
            'request' => $freshReq,
            'grant' => $freshGrant,
            'user_id' => $userId,
            'revoked_count' => $revokedCount,
            'reconcile' => $recon,
        ];
    }

    return [
        'ok' => true,
        'skipped' => false,
        'request' => $freshReq,
        'grant' => $freshGrant,
        'user_id' => $userId,
        'revoked_count' => $revokedCount,
        'reconcile' => $recon,
    ];
}
