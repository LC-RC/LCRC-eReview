<?php
/**
 * Grant expiry + safe commerce SCA reconciliation (Phase 8.2).
 *
 * Marks overdue active access_grants as expired (history kept).
 * Reconciles SCA using grant ledger provenance only (purchase / free_access).
 * Does NOT: revoke, emails, login, payment/OCR, replace-all SCA, delete grant history.
 */

declare(strict_types=1);

require_once __DIR__ . '/commerce_catalog.php';
require_once __DIR__ . '/student_content_access.php';

const COMMERCE_EXPIRE_RECONCILE_LOCK = 'ereview_commerce_expire_reconcile';

/**
 * @return list<array{content_type:string,content_id:int}>
 */
function commerce_grant_commerce_backed_keys(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT content_type, content_id
         FROM access_grants
         WHERE user_id = ?
           AND source IN ('purchase', 'free_access')"
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $raw = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $raw[] = [
            'content_type' => (string) ($row['content_type'] ?? ''),
            'content_id' => (int) ($row['content_id'] ?? 0),
        ];
    }
    mysqli_stmt_close($stmt);
    return sca_normalize_permission_payload($raw);
}

/**
 * Live coverage keys: purchase/free_access, active, ends_at > NOW().
 *
 * @return list<array{content_type:string,content_id:int}>
 */
function commerce_grant_live_coverage_keys(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $stmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT content_type, content_id
         FROM access_grants
         WHERE user_id = ?
           AND source IN ('purchase', 'free_access')
           AND status = 'active'
           AND ends_at > NOW()"
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $raw = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $raw[] = [
            'content_type' => (string) ($row['content_type'] ?? ''),
            'content_id' => (int) ($row['content_id'] ?? 0),
        ];
    }
    mysqli_stmt_close($stmt);
    return sca_normalize_permission_payload($raw);
}

/**
 * Reconcile one user's commerce-backed SCA keys against live grant coverage.
 * Safe for Phase 8.3 to call after revoke.
 *
 * @return array{ok:bool,error?:string,upserts:int,removals:int}
 */
function commerce_reconcile_user_commerce_sca(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user', 'upserts' => 0, 'removals' => 0];
    }
    if (!commerce_schema_ready($conn)) {
        return ['ok' => false, 'error' => 'schema_not_ready', 'upserts' => 0, 'removals' => 0];
    }

    $upserts = 0;
    $removals = 0;

    mysqli_begin_transaction($conn);
    try {
        $backed = commerce_grant_commerce_backed_keys($conn, $userId);
        $liveList = commerce_grant_live_coverage_keys($conn, $userId);
        $liveMap = [];
        foreach ($liveList as $k) {
            $liveMap[$k['content_type'] . ':' . (int) $k['content_id']] = $k;
        }

        foreach ($backed as $key) {
            $type = $key['content_type'];
            $cid = (int) $key['content_id'];
            $mapKey = $type . ':' . $cid;
            if (isset($liveMap[$mapKey])) {
                if (!sca_upsert_permissions($conn, $userId, [['content_type' => $type, 'content_id' => $cid]], null)) {
                    throw new RuntimeException('sca_upsert_failed:' . $mapKey);
                }
                $upserts++;
            } else {
                if (!sca_delete_permission_key($conn, $userId, $type, $cid)) {
                    throw new RuntimeException('sca_delete_failed:' . $mapKey);
                }
                $removals++;
            }
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('commerce_reconcile_user_commerce_sca user=' . $userId . ': ' . $e->getMessage());
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'upserts' => 0,
            'removals' => 0,
        ];
    }

    return ['ok' => true, 'upserts' => $upserts, 'removals' => $removals];
}

/**
 * Mark overdue active grants as expired. Never touches revoked. Never deletes.
 *
 * @return array{ok:bool,error?:string,expired_count:int,user_ids:list<int>,grant_ids:list<int>}
 */
function commerce_expire_overdue_grants(mysqli $conn, int $limit = 500, int $onlyUserId = 0): array
{
    if (!commerce_schema_ready($conn)) {
        return ['ok' => false, 'error' => 'schema_not_ready', 'expired_count' => 0, 'user_ids' => [], 'grant_ids' => []];
    }
    $limit = max(1, min(5000, $limit));

    $sql = "SELECT grant_id, user_id
            FROM access_grants
            WHERE status = 'active'
              AND ends_at <= NOW()";
    if ($onlyUserId > 0) {
        $sql .= ' AND user_id = ' . (int) $onlyUserId;
    }
    $sql .= ' ORDER BY grant_id ASC LIMIT ' . (int) $limit;

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return [
            'ok' => false,
            'error' => 'select_failed:' . mysqli_error($conn),
            'expired_count' => 0,
            'user_ids' => [],
            'grant_ids' => [],
        ];
    }

    $grantIds = [];
    $userMap = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $gid = (int) ($row['grant_id'] ?? 0);
        $uid = (int) ($row['user_id'] ?? 0);
        if ($gid > 0) {
            $grantIds[] = $gid;
        }
        if ($uid > 0) {
            $userMap[$uid] = true;
        }
    }
    mysqli_free_result($res);

    if ($grantIds === []) {
        return ['ok' => true, 'expired_count' => 0, 'user_ids' => [], 'grant_ids' => []];
    }

    $idList = implode(',', array_map('intval', $grantIds));
    mysqli_begin_transaction($conn);
    try {
        $upd = mysqli_query(
            $conn,
            "UPDATE access_grants
             SET status = 'expired'
             WHERE grant_id IN ($idList)
               AND status = 'active'
               AND ends_at <= NOW()"
        );
        if (!$upd) {
            throw new RuntimeException('expire_update_failed:' . mysqli_error($conn));
        }
        $affected = (int) mysqli_affected_rows($conn);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('commerce_expire_overdue_grants: ' . $e->getMessage());
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'expired_count' => 0,
            'user_ids' => [],
            'grant_ids' => [],
        ];
    }

    return [
        'ok' => true,
        'expired_count' => $affected,
        'user_ids' => array_map('intval', array_keys($userMap)),
        'grant_ids' => $grantIds,
    ];
}

/**
 * Try non-blocking named lock. Returns true if acquired.
 */
function commerce_expire_try_lock(mysqli $conn): bool
{
    $name = COMMERCE_EXPIRE_RECONCILE_LOCK;
    $stmt = mysqli_prepare($conn, 'SELECT GET_LOCK(?, 0)');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_row($res) : null;
    mysqli_stmt_close($stmt);
    return $row !== null && (int) $row[0] === 1;
}

function commerce_expire_release_lock(mysqli $conn): void
{
    $name = COMMERCE_EXPIRE_RECONCILE_LOCK;
    $stmt = mysqli_prepare($conn, 'SELECT RELEASE_LOCK(?)');
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Expire overdue grants then reconcile affected users (or a single user).
 *
 * @return array{
 *   ok:bool,
 *   error?:string,
 *   locked?:bool,
 *   expired_grants:int,
 *   users_reconciled:int,
 *   sca_upserts:int,
 *   sca_removals:int,
 *   failures:list<array{user_id:int,error:string}>
 * }
 */
function commerce_expire_and_reconcile(mysqli $conn, int $limit = 500, int $onlyUserId = 0): array
{
    $out = [
        'ok' => true,
        'expired_grants' => 0,
        'users_reconciled' => 0,
        'sca_upserts' => 0,
        'sca_removals' => 0,
        'failures' => [],
    ];

    if (!commerce_schema_ready($conn)) {
        return array_merge($out, ['ok' => false, 'error' => 'schema_not_ready']);
    }

    $locked = commerce_expire_try_lock($conn);
    $out['locked'] = $locked;
    if (!$locked) {
        return array_merge($out, [
            'ok' => false,
            'error' => 'lock_not_acquired',
        ]);
    }

    try {
        $exp = commerce_expire_overdue_grants($conn, $limit, $onlyUserId);
        if (empty($exp['ok'])) {
            return array_merge($out, [
                'ok' => false,
                'error' => $exp['error'] ?? 'expire_failed',
            ]);
        }
        $out['expired_grants'] = (int) ($exp['expired_count'] ?? 0);

        $userIds = [];
        if ($onlyUserId > 0) {
            $userIds = [$onlyUserId];
        } else {
            foreach (($exp['user_ids'] ?? []) as $uid) {
                $uid = (int) $uid;
                if ($uid > 0) {
                    $userIds[$uid] = $uid;
                }
            }
            $userIds = array_values($userIds);
        }

        foreach ($userIds as $uid) {
            $r = commerce_reconcile_user_commerce_sca($conn, (int) $uid);
            if (empty($r['ok'])) {
                $out['failures'][] = [
                    'user_id' => (int) $uid,
                    'error' => (string) ($r['error'] ?? 'reconcile_failed'),
                ];
                $out['ok'] = false;
                continue;
            }
            $out['users_reconciled']++;
            $out['sca_upserts'] += (int) ($r['upserts'] ?? 0);
            $out['sca_removals'] += (int) ($r['removals'] ?? 0);
        }
    } finally {
        commerce_expire_release_lock($conn);
    }

    return $out;
}
