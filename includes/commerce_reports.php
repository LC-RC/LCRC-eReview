<?php
/**
 * Commerce reporting helpers (Phase 8.5).
 *
 * Read-only aggregates over existing tables. Never mutates commerce state.
 * Payment GMV uses payments.expected_amount_centavos only (never grants/items joins).
 */

declare(strict_types=1);

require_once __DIR__ . '/commerce_catalog.php';

/** Max inclusive day span for date_from → date_to filters. */
const COMMERCE_REPORTS_MAX_DATE_SPAN_DAYS = 366;

/** @var list<string> */
const COMMERCE_REPORTS_PAYMENT_STATUSES = [
    'awaiting_proof',
    'pending_verification',
    'paid',
    'rejected',
    'cancelled',
    'expired',
];

/** @var list<string> */
const COMMERCE_REPORTS_VERIFICATION_STATUSES = [
    'not_started',
    'processing',
    'auto_verified',
    'needs_review',
    'manually_approved',
    'manually_rejected',
    'failed',
];

/** @var list<string> */
const COMMERCE_REPORTS_PURCHASE_TYPES = ['package', 'by_topic'];

/**
 * Parse GET filters into a safe, allowlisted structure.
 *
 * @param array<string,mixed> $get
 * @return array{
 *   ok:bool,
 *   error?:string,
 *   date_from:?string,
 *   date_to:?string,
 *   status:?string,
 *   verification_status:?string,
 *   purchase_type:?string,
 *   student:string,
 *   payment_ref:string,
 *   package_id:int,
 *   lesson_id:int,
 *   warnings:list<string>
 * }
 */
function commerce_reports_parse_filters(array $get): array
{
    $warnings = [];
    $dateFrom = null;
    $dateTo = null;

    $rawFrom = trim((string) ($get['date_from'] ?? ''));
    $rawTo = trim((string) ($get['date_to'] ?? ''));
    if ($rawFrom !== '' || $rawTo !== '') {
        if ($rawFrom === '' || $rawTo === '') {
            $warnings[] = 'Both date_from and date_to are required when filtering by date; dates ignored.';
        } else {
            $fromTs = strtotime($rawFrom . ' 00:00:00');
            $toTs = strtotime($rawTo . ' 23:59:59');
            if ($fromTs === false || $toTs === false) {
                $warnings[] = 'Invalid date_from/date_to; dates ignored.';
            } elseif ($fromTs > $toTs) {
                $warnings[] = 'date_from is after date_to; dates ignored.';
            } else {
                $spanDays = (int) floor(($toTs - $fromTs) / 86400) + 1;
                if ($spanDays > COMMERCE_REPORTS_MAX_DATE_SPAN_DAYS) {
                    $warnings[] = 'Date range exceeds ' . COMMERCE_REPORTS_MAX_DATE_SPAN_DAYS . ' days; dates ignored.';
                } else {
                    $dateFrom = date('Y-m-d', $fromTs);
                    $dateTo = date('Y-m-d', $toTs);
                }
            }
        }
    }

    $status = strtolower(trim((string) ($get['status'] ?? '')));
    if ($status === '' || $status === 'all') {
        $status = null;
    } elseif (!in_array($status, COMMERCE_REPORTS_PAYMENT_STATUSES, true)) {
        $warnings[] = 'Unknown payment status filter ignored.';
        $status = null;
    }

    $vStatus = strtolower(trim((string) ($get['verification_status'] ?? $get['v'] ?? '')));
    if ($vStatus === '' || $vStatus === 'all') {
        $vStatus = null;
    } elseif (!in_array($vStatus, COMMERCE_REPORTS_VERIFICATION_STATUSES, true)) {
        $warnings[] = 'Unknown verification status filter ignored.';
        $vStatus = null;
    }

    $purchaseType = strtolower(trim((string) ($get['purchase_type'] ?? '')));
    if ($purchaseType === '' || $purchaseType === 'all') {
        $purchaseType = null;
    } elseif (!in_array($purchaseType, COMMERCE_REPORTS_PURCHASE_TYPES, true)) {
        $warnings[] = 'Unknown purchase type filter ignored.';
        $purchaseType = null;
    }

    $student = trim((string) ($get['student'] ?? ''));
    if (strlen($student) > 120) {
        $student = substr($student, 0, 120);
        $warnings[] = 'Student search truncated to 120 characters.';
    }

    $paymentRef = trim((string) ($get['payment_ref'] ?? ''));
    if (strlen($paymentRef) > 64) {
        $paymentRef = substr($paymentRef, 0, 64);
        $warnings[] = 'payment_ref truncated.';
    }

    $packageId = (int) ($get['package_id'] ?? 0);
    if ($packageId < 0) {
        $packageId = 0;
    }

    $lessonId = (int) ($get['lesson_id'] ?? 0);
    if ($lessonId < 0) {
        $lessonId = 0;
    }

    return [
        'ok' => true,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'status' => $status,
        'verification_status' => $vStatus,
        'purchase_type' => $purchaseType,
        'student' => $student,
        'payment_ref' => $paymentRef,
        'package_id' => $packageId,
        'lesson_id' => $lessonId,
        'warnings' => $warnings,
    ];
}

/**
 * Build payments WHERE clause (payments grain only).
 * Lesson filter uses EXISTS (never joins payment_items into aggregates).
 *
 * @param array<string,mixed> $filters from commerce_reports_parse_filters()
 * @return array{sql:string,types:string,params:list<mixed>}
 */
function commerce_reports_payments_where(array $filters): array
{
    $parts = ['1=1'];
    $types = '';
    $params = [];

    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $parts[] = 'p.created_at >= ? AND p.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $types .= 'ss';
        $params[] = $filters['date_from'] . ' 00:00:00';
        $params[] = $filters['date_to'];
    }
    if (!empty($filters['status'])) {
        $parts[] = 'p.status = ?';
        $types .= 's';
        $params[] = $filters['status'];
    }
    if (!empty($filters['verification_status'])) {
        $parts[] = 'p.verification_status = ?';
        $types .= 's';
        $params[] = $filters['verification_status'];
    }
    if (!empty($filters['purchase_type'])) {
        $parts[] = 'p.purchase_type = ?';
        $types .= 's';
        $params[] = $filters['purchase_type'];
    }
    if (!empty($filters['payment_ref'])) {
        $parts[] = 'p.payment_ref LIKE ?';
        $types .= 's';
        $params[] = $filters['payment_ref'] . '%';
    }
    if ((int) ($filters['package_id'] ?? 0) > 0) {
        $parts[] = 'p.package_id = ?';
        $types .= 'i';
        $params[] = (int) $filters['package_id'];
    }
    if ((int) ($filters['lesson_id'] ?? 0) > 0) {
        $parts[] = 'EXISTS (
            SELECT 1 FROM payment_items pi
            WHERE pi.payment_id = p.payment_id
              AND pi.item_type = \'lesson\'
              AND pi.lesson_id = ?
            LIMIT 1
        )';
        $types .= 'i';
        $params[] = (int) $filters['lesson_id'];
    }
    if (trim((string) ($filters['student'] ?? '')) !== '') {
        $parts[] = 'EXISTS (
            SELECT 1 FROM users u
            WHERE u.user_id = p.user_id
              AND u.role = \'student\'
              AND (u.full_name LIKE ? OR u.email LIKE ?)
            LIMIT 1
        )';
        $like = '%' . commerce_reports_like_escape((string) $filters['student']) . '%';
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

function commerce_reports_like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * @param list<mixed> $params
 * @return array<string,mixed>|null
 */
function commerce_reports_fetch_assoc(mysqli $conn, string $sql, string $types, array $params): ?array
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return null;
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * @param list<mixed> $params
 * @return list<array<string,mixed>>
 */
function commerce_reports_fetch_all(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
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

function commerce_reports_centavos_to_php(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', ',');
}

/**
 * Payment summary + purchase breakdown + verification (payments grain).
 *
 * @param array<string,mixed> $filters
 * @return array{
 *   total:int,paid:int,awaiting_proof:int,pending_verification:int,rejected:int,
 *   cancelled:int,expired:int,needs_review:int,fulfilled:int,paid_unfulfilled:int,
 *   paid_gmv_centavos:int,
 *   package_count:int,by_topic_count:int,package_gmv_centavos:int,by_topic_gmv_centavos:int,
 *   v_not_started:int,v_processing:int,v_auto_verified:int,v_needs_review:int,
 *   v_failed:int,v_manually_approved:int,v_manually_rejected:int
 * }
 */
function commerce_reports_payment_metrics(mysqli $conn, array $filters): array
{
    $w = commerce_reports_payments_where($filters);
    $sql = "SELECT
              COUNT(*) AS total,
              SUM(CASE WHEN p.status = 'paid' THEN 1 ELSE 0 END) AS paid,
              SUM(CASE WHEN p.status = 'awaiting_proof' THEN 1 ELSE 0 END) AS awaiting_proof,
              SUM(CASE WHEN p.status = 'pending_verification' THEN 1 ELSE 0 END) AS pending_verification,
              SUM(CASE WHEN p.status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
              SUM(CASE WHEN p.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
              SUM(CASE WHEN p.status = 'expired' THEN 1 ELSE 0 END) AS expired,
              SUM(CASE WHEN p.verification_status = 'needs_review' THEN 1 ELSE 0 END) AS needs_review,
              SUM(CASE WHEN p.fulfilled_at IS NOT NULL THEN 1 ELSE 0 END) AS fulfilled,
              SUM(CASE WHEN p.status = 'paid' AND p.fulfilled_at IS NULL THEN 1 ELSE 0 END) AS paid_unfulfilled,
              COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.expected_amount_centavos ELSE 0 END), 0) AS paid_gmv_centavos,
              SUM(CASE WHEN p.purchase_type = 'package' THEN 1 ELSE 0 END) AS package_count,
              SUM(CASE WHEN p.purchase_type = 'by_topic' THEN 1 ELSE 0 END) AS by_topic_count,
              COALESCE(SUM(CASE WHEN p.status = 'paid' AND p.purchase_type = 'package' THEN p.expected_amount_centavos ELSE 0 END), 0) AS package_gmv_centavos,
              COALESCE(SUM(CASE WHEN p.status = 'paid' AND p.purchase_type = 'by_topic' THEN p.expected_amount_centavos ELSE 0 END), 0) AS by_topic_gmv_centavos,
              SUM(CASE WHEN p.verification_status = 'not_started' THEN 1 ELSE 0 END) AS v_not_started,
              SUM(CASE WHEN p.verification_status = 'processing' THEN 1 ELSE 0 END) AS v_processing,
              SUM(CASE WHEN p.verification_status = 'auto_verified' THEN 1 ELSE 0 END) AS v_auto_verified,
              SUM(CASE WHEN p.verification_status = 'needs_review' THEN 1 ELSE 0 END) AS v_needs_review,
              SUM(CASE WHEN p.verification_status = 'failed' THEN 1 ELSE 0 END) AS v_failed,
              SUM(CASE WHEN p.verification_status = 'manually_approved' THEN 1 ELSE 0 END) AS v_manually_approved,
              SUM(CASE WHEN p.verification_status = 'manually_rejected' THEN 1 ELSE 0 END) AS v_manually_rejected
            FROM payments p
            WHERE {$w['sql']}";
    $row = commerce_reports_fetch_assoc($conn, $sql, $w['types'], $w['params']);
    $keys = [
        'total', 'paid', 'awaiting_proof', 'pending_verification', 'rejected', 'cancelled', 'expired',
        'needs_review', 'fulfilled', 'paid_unfulfilled', 'paid_gmv_centavos',
        'package_count', 'by_topic_count', 'package_gmv_centavos', 'by_topic_gmv_centavos',
        'v_not_started', 'v_processing', 'v_auto_verified', 'v_needs_review',
        'v_failed', 'v_manually_approved', 'v_manually_rejected',
    ];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = (int) ($row[$k] ?? 0);
    }
    return $out;
}

/**
 * Optional secondary metric: verification attempt rows (not payments).
 *
 * @param array<string,mixed> $filters payment filters (joined via payment_id EXISTS)
 */
function commerce_reports_verification_attempt_count(mysqli $conn, array $filters): int
{
    $w = commerce_reports_payments_where($filters);
    $sql = "SELECT COUNT(*) AS c
            FROM payment_verification_attempts a
            WHERE EXISTS (
              SELECT 1 FROM payments p
              WHERE p.payment_id = a.payment_id AND {$w['sql']}
              LIMIT 1
            )";
    $row = commerce_reports_fetch_assoc($conn, $sql, $w['types'], $w['params']);
    return (int) ($row['c'] ?? 0);
}

/**
 * Grant health (access_grants grain). Not filtered by payment date (ledger snapshot).
 * Optional: when payment filters are active, restrict purchase grants to matching payments.
 *
 * @param array<string,mixed> $filters
 * @return array{
 *   purchase_active:int,purchase_expired:int,purchase_revoked:int,purchase_overdue_active:int,
 *   free_access_active:int,free_access_expired:int,free_access_revoked:int,free_access_overdue_active:int
 * }
 */
function commerce_reports_grant_metrics(mysqli $conn, array $filters): array
{
    // Grant metrics are global ledger health. Payment filters do not rewrite grant definitions.
    // Optional coupling: if payment filters present, purchase grants can be scoped via payment_id EXISTS.
    $scopePurchase = commerce_reports_filters_affect_payments($filters);
    $types = '';
    $params = [];
    $purchaseScopeSql = '1=1';
    if ($scopePurchase) {
        $w = commerce_reports_payments_where($filters);
        $purchaseScopeSql = "EXISTS (
            SELECT 1 FROM payments p
            WHERE p.payment_id = g.payment_id AND {$w['sql']}
            LIMIT 1
        )";
        $types = $w['types'];
        $params = $w['params'];
    }

    $sql = "SELECT
              SUM(CASE WHEN g.source = 'purchase' AND g.status = 'active' AND g.ends_at > NOW() THEN 1 ELSE 0 END) AS purchase_active,
              SUM(CASE WHEN g.source = 'purchase' AND g.status = 'expired' THEN 1 ELSE 0 END) AS purchase_expired,
              SUM(CASE WHEN g.source = 'purchase' AND g.status = 'revoked' THEN 1 ELSE 0 END) AS purchase_revoked,
              SUM(CASE WHEN g.source = 'purchase' AND g.status = 'active' AND g.ends_at <= NOW() THEN 1 ELSE 0 END) AS purchase_overdue_active,
              SUM(CASE WHEN g.source = 'free_access' AND g.status = 'active' AND g.ends_at > NOW() THEN 1 ELSE 0 END) AS free_access_active,
              SUM(CASE WHEN g.source = 'free_access' AND g.status = 'expired' THEN 1 ELSE 0 END) AS free_access_expired,
              SUM(CASE WHEN g.source = 'free_access' AND g.status = 'revoked' THEN 1 ELSE 0 END) AS free_access_revoked,
              SUM(CASE WHEN g.source = 'free_access' AND g.status = 'active' AND g.ends_at <= NOW() THEN 1 ELSE 0 END) AS free_access_overdue_active
            FROM access_grants g
            WHERE (
              (g.source = 'purchase' AND ({$purchaseScopeSql}))
              OR g.source = 'free_access'
            )";
    $row = commerce_reports_fetch_assoc($conn, $sql, $types, $params);
    return [
        'purchase_active' => (int) ($row['purchase_active'] ?? 0),
        'purchase_expired' => (int) ($row['purchase_expired'] ?? 0),
        'purchase_revoked' => (int) ($row['purchase_revoked'] ?? 0),
        'purchase_overdue_active' => (int) ($row['purchase_overdue_active'] ?? 0),
        'free_access_active' => (int) ($row['free_access_active'] ?? 0),
        'free_access_expired' => (int) ($row['free_access_expired'] ?? 0),
        'free_access_revoked' => (int) ($row['free_access_revoked'] ?? 0),
        'free_access_overdue_active' => (int) ($row['free_access_overdue_active'] ?? 0),
    ];
}

/**
 * @param array<string,mixed> $filters
 */
function commerce_reports_filters_affect_payments(array $filters): bool
{
    if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        return true;
    }
    if (!empty($filters['status']) || !empty($filters['verification_status']) || !empty($filters['purchase_type'])) {
        return true;
    }
    if (trim((string) ($filters['student'] ?? '')) !== '' || trim((string) ($filters['payment_ref'] ?? '')) !== '') {
        return true;
    }
    if ((int) ($filters['package_id'] ?? 0) > 0 || (int) ($filters['lesson_id'] ?? 0) > 0) {
        return true;
    }
    return false;
}

/**
 * FAR status counts (independent of payments / GMV).
 *
 * @return array{pending:int,approved:int,rejected:int,cancelled:int,total:int}
 */
function commerce_reports_far_metrics(mysqli $conn): array
{
    $sql = "SELECT
              SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
              SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
              SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
              SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
              COUNT(*) AS total
            FROM free_access_requests";
    $row = commerce_reports_fetch_assoc($conn, $sql, '', []);
    return [
        'pending' => (int) ($row['pending'] ?? 0),
        'approved' => (int) ($row['approved'] ?? 0),
        'rejected' => (int) ($row['rejected'] ?? 0),
        'cancelled' => (int) ($row['cancelled'] ?? 0),
        'total' => (int) ($row['total'] ?? 0),
    ];
}

/**
 * Recent payments (max 20). No OCR/proof fields.
 *
 * @param array<string,mixed> $filters
 * @return list<array<string,mixed>>
 */
function commerce_reports_recent_payments(mysqli $conn, array $filters, int $limit = 20): array
{
    $limit = max(1, min(20, $limit));
    $w = commerce_reports_payments_where($filters);
    $sql = "SELECT p.payment_id, p.payment_ref, p.user_id, p.purchase_type, p.status,
                   p.verification_status, p.expected_amount_centavos, p.paid_at, p.fulfilled_at, p.created_at,
                   u.full_name, u.email
            FROM payments p
            LEFT JOIN users u ON u.user_id = p.user_id
            WHERE {$w['sql']}
            ORDER BY p.created_at DESC, p.payment_id DESC
            LIMIT {$limit}";
    // LIMIT is int-bound above; not user input.
    return commerce_reports_fetch_all($conn, $sql, $w['types'], $w['params']);
}

/**
 * Recent FAR requests (max 20).
 *
 * @return list<array<string,mixed>>
 */
function commerce_reports_recent_far(mysqli $conn, int $limit = 20): array
{
    $limit = max(1, min(20, $limit));
    $sql = "SELECT r.request_id, r.request_ref, r.user_id, r.status, r.created_at, r.reviewed_at,
                   u.full_name, u.email
            FROM free_access_requests r
            INNER JOIN users u ON u.user_id = r.user_id
            ORDER BY r.created_at DESC, r.request_id DESC
            LIMIT {$limit}";
    return commerce_reports_fetch_all($conn, $sql, '', []);
}

/**
 * Package dropdown options for filters.
 *
 * @return list<array{package_id:int,name:string,code:string}>
 */
function commerce_reports_package_options(mysqli $conn): array
{
    $rows = commerce_reports_fetch_all(
        $conn,
        'SELECT package_id, name, code FROM sellable_packages ORDER BY sort_order ASC, name ASC',
        '',
        []
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'package_id' => (int) ($r['package_id'] ?? 0),
            'name' => (string) ($r['name'] ?? ''),
            'code' => (string) ($r['code'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Full dashboard payload.
 *
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function commerce_reports_build_dashboard(mysqli $conn, array $get): array
{
    $filters = commerce_reports_parse_filters($get);
    $payments = commerce_reports_payment_metrics($conn, $filters);
    $grants = commerce_reports_grant_metrics($conn, $filters);
    $far = commerce_reports_far_metrics($conn);
    $attempts = commerce_reports_verification_attempt_count($conn, $filters);

    return [
        'filters' => $filters,
        'payments' => $payments,
        'grants' => $grants,
        'far' => $far,
        'verification_attempts' => $attempts,
        'recent_payments' => commerce_reports_recent_payments($conn, $filters, 20),
        'recent_far' => commerce_reports_recent_far($conn, 20),
        'packages' => commerce_reports_package_options($conn),
    ];
}
