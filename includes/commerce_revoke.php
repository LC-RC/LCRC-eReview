<?php
/**
 * Paid access revoke (Phase 8.3).
 *
 * Payment-level revoke of source=purchase access_grants only.
 * Does NOT: mutate payments/items/OCR/GCash, revoke Free Access, delete history,
 * change login/activation, or call replace-all SCA.
 */

declare(strict_types=1);

require_once __DIR__ . '/commerce_catalog.php';
require_once __DIR__ . '/commerce_payment.php';
require_once __DIR__ . '/commerce_grant_expiry.php';

/**
 * Build revoke_reason stored on grants (admin id prefix + admin text).
 */
function commerce_revoke_format_reason(int $adminId, string $reason): string
{
    $reason = trim($reason);
    if (strlen($reason) > 220) {
        $reason = substr($reason, 0, 220);
    }
    $prefix = '[admin#' . $adminId . '] ';
    $full = $prefix . $reason;
    if (strlen($full) > 255) {
        $full = substr($full, 0, 255);
    }
    return $full;
}

/**
 * Validate revoke reason (admin-facing text before prefix).
 *
 * @return array{ok:bool,error?:string,reason?:string}
 */
function commerce_revoke_validate_reason(string $raw): array
{
    $reason = trim($raw);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'revoke_reason_required'];
    }
    if (strlen($reason) > 255) {
        return ['ok' => false, 'error' => 'revoke_reason_too_long'];
    }
    return ['ok' => true, 'reason' => $reason];
}

/**
 * Load purchase grants for a payment (any status).
 *
 * @return list<array<string,mixed>>
 */
function commerce_revoke_list_payment_grants(mysqli $conn, int $paymentId): array
{
    if ($paymentId <= 0) {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT grant_id, user_id, source, payment_id, payment_item_id, free_access_request_id,
                content_type, content_id, content_label, starts_at, ends_at, status,
                revoked_at, revoke_reason, granted_by, created_at
         FROM access_grants
         WHERE payment_id = ?
           AND source = 'purchase'
         ORDER BY grant_id ASC"
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $paymentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Count active purchase grants for a payment.
 */
function commerce_revoke_count_active_purchase_grants(mysqli $conn, int $paymentId): int
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) FROM access_grants
         WHERE payment_id = ?
           AND source = 'purchase'
           AND status = 'active'"
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $paymentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $n = (int) (mysqli_fetch_row($res)[0] ?? 0);
    mysqli_stmt_close($stmt);
    return $n;
}

/**
 * Revoke all active purchase grants for a paid payment, then reconcile SCA.
 *
 * @return array{
 *   ok:bool,
 *   skipped?:bool,
 *   error?:string,
 *   payment?:array<string,mixed>,
 *   user_id?:int,
 *   revoked_count?:int,
 *   reconcile?:array<string,mixed>
 * }
 */
function commerce_revoke_payment_grants(
    mysqli $conn,
    int $paymentId,
    int $adminId,
    string $reason
): array {
    if ($paymentId <= 0 || $adminId <= 0) {
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

    $payment = commerce_get_payment($conn, $paymentId);
    if (!$payment) {
        return ['ok' => false, 'error' => 'payment_not_found'];
    }
    if ((string) ($payment['status'] ?? '') !== 'paid') {
        return ['ok' => false, 'error' => 'not_paid', 'payment' => $payment];
    }

    $userId = (int) ($payment['user_id'] ?? 0);
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'missing_user', 'payment' => $payment];
    }

    $purchaseGrants = commerce_revoke_list_payment_grants($conn, $paymentId);
    if ($purchaseGrants === []) {
        return ['ok' => false, 'error' => 'no_purchase_grants', 'payment' => $payment];
    }

    // Ensure all listed purchase grants belong to this payment's user.
    foreach ($purchaseGrants as $g) {
        if ((int) ($g['user_id'] ?? 0) !== $userId) {
            return ['ok' => false, 'error' => 'grant_user_mismatch', 'payment' => $payment];
        }
    }

    $revokedCount = 0;
    mysqli_begin_transaction($conn);
    try {
        // Serialize concurrent revokes on the same payment.
        $lock = mysqli_prepare(
            $conn,
            'SELECT payment_id, user_id, status FROM payments WHERE payment_id = ? LIMIT 1 FOR UPDATE'
        );
        if (!$lock) {
            throw new RuntimeException('payment_lock_prepare_failed');
        }
        mysqli_stmt_bind_param($lock, 'i', $paymentId);
        if (!mysqli_stmt_execute($lock)) {
            mysqli_stmt_close($lock);
            throw new RuntimeException('payment_lock_failed');
        }
        $lres = mysqli_stmt_get_result($lock);
        $locked = $lres ? mysqli_fetch_assoc($lres) : null;
        mysqli_stmt_close($lock);
        if (!$locked) {
            throw new RuntimeException('payment_missing_under_lock');
        }
        if ((string) ($locked['status'] ?? '') !== 'paid') {
            throw new RuntimeException('not_paid_under_lock');
        }
        if ((int) ($locked['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('user_mismatch_under_lock');
        }

        $upd = mysqli_prepare(
            $conn,
            "UPDATE access_grants SET
                status = 'revoked',
                revoked_at = NOW(),
                revoke_reason = ?
             WHERE payment_id = ?
               AND source = 'purchase'
               AND status = 'active'"
        );
        if (!$upd) {
            throw new RuntimeException('revoke_prepare_failed');
        }
        mysqli_stmt_bind_param($upd, 'si', $storedReason, $paymentId);
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
        error_log('commerce_revoke_payment_grants: ' . $e->getMessage());
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'payment' => commerce_get_payment($conn, $paymentId) ?: $payment,
        ];
    }

    $recon = commerce_reconcile_user_commerce_sca($conn, $userId);
    if (empty($recon['ok'])) {
        return [
            'ok' => false,
            'error' => 'grants_revoked_but_reconcile_failed:' . (string) ($recon['error'] ?? 'unknown'),
            'payment' => commerce_get_payment($conn, $paymentId) ?: $payment,
            'user_id' => $userId,
            'revoked_count' => $revokedCount,
            'reconcile' => $recon,
        ];
    }

    require_once __DIR__ . '/commerce_access_gate.php';
    $demote = commerce_student_demote_if_no_active_grant($conn, $userId);

    return [
        'ok' => true,
        'skipped' => $revokedCount === 0,
        'error' => $revokedCount === 0 ? 'already_revoked_or_no_active' : null,
        'payment' => commerce_get_payment($conn, $paymentId) ?: $payment,
        'user_id' => $userId,
        'revoked_count' => $revokedCount,
        'reconcile' => $recon,
        'account_demoted' => !empty($demote['demoted']),
    ];
}
