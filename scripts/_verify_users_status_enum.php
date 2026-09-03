<?php
/**
 * CLI-safe users.status ENUM check (avoid php -r on Windows PowerShell).
 *
 * Run:
 *   C:\xampp\php\php.exe scripts/_verify_users_status_enum.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

$res = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'status'");
if (!$res) {
    fwrite(STDERR, 'SHOW COLUMNS failed: ' . mysqli_error($conn) . "\n");
    exit(1);
}
$row = mysqli_fetch_assoc($res);
if (!$row) {
    fwrite(STDERR, "users.status column not found.\n");
    exit(1);
}

$type = (string) ($row['Type'] ?? '');
echo "Field: " . ($row['Field'] ?? '') . "\n";
echo "Type:  {$type}\n";
echo "Null:  " . ($row['Null'] ?? '') . "\n";
echo "Key:   " . ($row['Key'] ?? '') . "\n";
echo "Default: " . ($row['Default'] ?? '') . "\n";

$expected = ['pending', 'approved', 'rejected', 'archived'];
$missing = [];
foreach ($expected as $v) {
    if (stripos($type, "'" . $v . "'") === false) {
        $missing[] = $v;
    }
}

if ($missing !== []) {
    echo "FAIL missing enum values: " . implode(', ', $missing) . "\n";
    exit(1);
}

echo 'Database status: ' . implode(' | ', $expected) . "\n";
echo "PASS\n";
exit(0);
