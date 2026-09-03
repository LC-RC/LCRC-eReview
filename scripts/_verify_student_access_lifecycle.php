<?php
/**
 * Backend verification for account-window duration + archive lifecycle.
 * Run: C:\xampp\php\php.exe scripts/_verify_student_access_lifecycle.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/admin_account_window.php';
require_once dirname(__DIR__) . '/includes/commerce_access_gate.php';

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('ASSERT FAIL: ' . $msg);
    }
    echo "OK  $msg\n";
}

echo "=== Duration unit helpers ===\n";
assert_true(admin_normalize_duration_unit('Hours') === 'hour', 'normalize Hours');
assert_true(admin_normalize_duration_unit('day') === 'day', 'normalize day');
assert_true(admin_normalize_duration_unit('months') === 'month', 'normalize months');
assert_true(admin_normalize_duration_unit('y') === 'year', 'normalize y');
assert_true(admin_sql_interval_unit('hour') === 'HOUR', 'SQL HOUR');
assert_true(admin_sql_interval_unit('day') === 'DAY', 'SQL DAY');
assert_true(admin_sql_interval_unit('month') === 'MONTH', 'SQL MONTH');
assert_true(admin_sql_interval_unit('year') === 'YEAR', 'SQL YEAR');
assert_true(admin_validate_duration(0, 'day') !== null, 'reject zero duration');
assert_true(admin_validate_duration(1, 'hour') === null, 'accept 1 hour');

echo "\n=== MySQL INTERVAL math (server NOW) ===\n";
$cases = [
    ['HOUR', 1],
    ['DAY', 1],
    ['MONTH', 1],
    ['YEAR', 1],
];
foreach ($cases as [$unit, $n]) {
    $sql = "SELECT DATE_ADD(NOW(), INTERVAL {$n} {$unit}) AS ends, NOW() AS starts";
    $res = mysqli_query($conn, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    assert_true(!empty($row['ends']) && !empty($row['starts']), "INTERVAL {$n} {$unit} returns timestamps");
    $startTs = strtotime((string) $row['starts']);
    $endTs = strtotime((string) $row['ends']);
    assert_true($endTs > $startTs, "INTERVAL {$n} {$unit} end > start");
    echo "     {$unit}: {$row['starts']} → {$row['ends']}\n";
}

echo "\n=== users.status archived enum ===\n";
assert_true(admin_ensure_user_status_archived($conn), 'ensure archived enum');
$col = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'status'");
$colRow = $col ? mysqli_fetch_assoc($col) : null;
$type = strtolower((string) ($colRow['Type'] ?? ''));
assert_true(strpos($type, "'archived'") !== false, 'status enum contains archived');

echo "\n=== Find a safe test student (read-only probe) ===\n";
$probe = mysqli_query(
    $conn,
    "SELECT u.user_id, u.email, u.status, u.access_end,
            (SELECT COUNT(*) FROM access_grants g
              WHERE g.user_id = u.user_id AND g.status='active' AND g.ends_at > NOW()) AS active_grants
     FROM users u
     WHERE u.role='student' AND u.status IN ('approved','pending')
     ORDER BY u.user_id DESC
     LIMIT 3"
);
while ($probe && ($p = mysqli_fetch_assoc($probe))) {
    echo '  user_id=' . $p['user_id']
        . ' status=' . $p['status']
        . ' access_end=' . ($p['access_end'] ?: '-')
        . ' active_grants=' . $p['active_grants']
        . ' email=' . $p['email'] . "\n";
}

echo "\n=== commerce_student_can_login blocks archived ===\n";
$fake = ['user_id' => 0, 'role' => 'student', 'status' => 'archived', 'access_end' => date('Y-m-d H:i:s', time() + 86400)];
$gate = commerce_student_can_login($conn, $fake);
assert_true(empty($gate['ok']) && (($gate['error_type'] ?? '') === 'archived'), 'archived status blocked');

echo "\nAll verification checks passed.\n";
