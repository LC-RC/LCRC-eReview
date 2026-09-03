<?php
/**
 * Transactional dry-run: Set/Extend window + grant sync (rolls back).
 * Does not leave lasting changes.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/admin_account_window.php';
require_once dirname(__DIR__) . '/includes/commerce_access_gate.php';

$uid = 0;
$res = mysqli_query(
    $conn,
    "SELECT u.user_id
     FROM users u
     INNER JOIN access_grants g ON g.user_id = u.user_id
       AND g.status='active' AND g.ends_at > NOW()
       AND g.source IN ('purchase','free_access','admin_manual')
     WHERE u.role='student' AND u.status='approved'
     ORDER BY u.user_id DESC LIMIT 1"
);
if ($res && ($row = mysqli_fetch_assoc($res))) {
    $uid = (int) $row['user_id'];
}
if ($uid <= 0) {
    fwrite(STDERR, "No approved student with active grant found for dry-run.\n");
    exit(1);
}

function snapshot(mysqli $conn, int $uid): array
{
    $u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_start, access_end, access_months, status FROM users WHERE user_id={$uid}")) ?: [];
    $g = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c, MAX(ends_at) AS max_end FROM access_grants
         WHERE user_id={$uid} AND status='active' AND ends_at > NOW()
           AND source IN ('purchase','free_access','admin_manual')"
    )) ?: [];
    return ['user' => $u, 'grants' => $g];
}

echo "Dry-run on user_id={$uid}\n";
$before = snapshot($conn, $uid);
echo "BEFORE access_end=" . ($before['user']['access_end'] ?? '-') . " grant_max=" . ($before['grants']['max_end'] ?? '-') . " grants=" . ($before['grants']['c'] ?? 0) . "\n";

mysqli_begin_transaction($conn);
try {
    // SET 1 hour
    $ok = mysqli_query(
        $conn,
        "UPDATE users SET access_start=NOW(), access_end=DATE_ADD(NOW(), INTERVAL 1 HOUR), access_months=1
         WHERE user_id={$uid} AND role='student' LIMIT 1"
    );
    if (!$ok) {
        throw new RuntimeException(mysqli_error($conn));
    }
    $end = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_end FROM users WHERE user_id={$uid}"))['access_end'] ?? '');
    $sync = admin_sync_access_grants_with_window($conn, $uid, 'set', [
        'duration_value' => 1,
        'interval_unit' => 'HOUR',
        'absolute_end' => $end,
    ]);
    $afterSet = snapshot($conn, $uid);
    echo "AFTER SET 1 HOUR access_end={$afterSet['user']['access_end']} grant_max={$afterSet['grants']['max_end']} sync=" . json_encode($sync) . "\n";
    $endTs = strtotime((string) $afterSet['user']['access_end']);
    $gTs = strtotime((string) $afterSet['grants']['max_end']);
    if ($endTs === false || $gTs === false || abs($endTs - $gTs) > 2) {
        throw new RuntimeException('Set window did not sync grant ends_at to access_end');
    }
    echo "OK  Set 1 hour: users.access_end matches grant max ends_at\n";

    // EXTEND 1 day from current end
    $ok = mysqli_query(
        $conn,
        "UPDATE users SET access_end=DATE_ADD(access_end, INTERVAL 1 DAY), access_months=IFNULL(access_months,0)+1
         WHERE user_id={$uid} AND role='student' LIMIT 1"
    );
    if (!$ok) {
        throw new RuntimeException(mysqli_error($conn));
    }
    $end2 = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT access_end FROM users WHERE user_id={$uid}"))['access_end'] ?? '');
    $sync2 = admin_sync_access_grants_with_window($conn, $uid, 'extend', [
        'duration_value' => 1,
        'interval_unit' => 'DAY',
        'absolute_end' => $end2,
    ]);
    $afterExt = snapshot($conn, $uid);
    echo "AFTER EXTEND 1 DAY access_end={$afterExt['user']['access_end']} grant_max={$afterExt['grants']['max_end']} sync=" . json_encode($sync2) . "\n";
    $setEnd = strtotime((string) $afterSet['user']['access_end']);
    $extEnd = strtotime((string) $afterExt['user']['access_end']);
    if ($extEnd - $setEnd < 86000) {
        throw new RuntimeException('Extend did not add ~1 day to access_end');
    }
    echo "OK  Extend 1 day advanced access_end\n";

    // ARCHIVE (soft)
    admin_ensure_user_status_archived($conn);
    mysqli_query($conn, "UPDATE users SET status='archived' WHERE user_id={$uid} LIMIT 1");
    $st = (string) (mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM users WHERE user_id={$uid}"))['status'] ?? '');
    if ($st !== 'archived') {
        throw new RuntimeException('Archive status not set');
    }
    $gate = commerce_student_can_login($conn, [
        'user_id' => $uid,
        'role' => 'student',
        'status' => 'archived',
        'access_end' => $afterExt['user']['access_end'],
    ]);
    if (!empty($gate['ok'])) {
        throw new RuntimeException('Archived student still allowed to login');
    }
    echo "OK  Archived student blocked from login\n";

    // Count related exam attempts still present (should remain — we didn't delete)
    $attempts = 0;
    $ar = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM college_exam_attempts WHERE user_id={$uid}");
    if ($ar && ($arow = mysqli_fetch_assoc($ar))) {
        $attempts = (int) $arow['c'];
    }
    echo "INFO college_exam_attempts still counted for user: {$attempts} (preserved; no DELETE)\n";

    mysqli_rollback($conn);
    echo "Rolled back — no lasting DB changes.\n";
} catch (Throwable $e) {
    mysqli_rollback($conn);
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

$afterRollback = snapshot($conn, $uid);
echo "AFTER ROLLBACK access_end=" . ($afterRollback['user']['access_end'] ?? '-') . " (should match BEFORE)\n";
if (($afterRollback['user']['access_end'] ?? '') !== ($before['user']['access_end'] ?? '')) {
    fwrite(STDERR, "WARNING: rollback mismatch — inspect manually\n");
    exit(1);
}
echo "All dry-run checks passed.\n";
