<?php
/**
 * Idempotent installer for migration 049 (seq table + UNIQUE users.student_number).
 * Default: dry-run. Use --apply on a backup copy. Never call from a page GET.
 *
 *   C:\xampp\php\php.exe scripts\migrate_049_college_student_number.php
 *   C:\xampp\php\php.exe scripts\migrate_049_college_student_number.php --apply
 *
 * Backup first: mysqldump the users table (or full schema).
 * Rollback: migrations/049_college_student_number_seq_DOWN.sql drops
 * college_student_number_seq and removes UNIQUE only if the index name is
 * exactly uq_users_student_number. A differently named UNIQUE on
 * users.student_number is not dropped. Seq last_n is lost; allocated
 * users.student_number values are not cleared. Prefer a backup restore
 * if UNIQUE must be fully removed.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/db.php';

$apply = in_array('--apply', $argv, true);
echo ($apply ? 'APPLY' : 'DRY-RUN') . PHP_EOL;

$col = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'student_number'");
if (!$col || !mysqli_fetch_assoc($col)) {
    fwrite(STDERR, "users.student_number is missing. Stop. Do not invent the column from this installer.\n");
    exit(1);
}

$dup = mysqli_query(
    $conn,
    "SELECT student_number, COUNT(*) AS c FROM users
     WHERE student_number IS NOT NULL AND TRIM(student_number) <> ''
     GROUP BY student_number HAVING c > 1"
);
$dupN = $dup ? (int) mysqli_num_rows($dup) : 0;
if ($dupN > 0) {
    fwrite(STDERR, "Duplicate student_number values exist ({$dupN} groups). Stop before UNIQUE.\n");
    exit(1);
}
echo "precheck_duplicates=0\n";

$seqExists = false;
$t = mysqli_query($conn, "SHOW TABLES LIKE 'college_student_number_seq'");
if ($t && mysqli_fetch_row($t)) {
    $seqExists = true;
}
echo 'seq_table=' . ($seqExists ? 'present' : 'missing') . PHP_EOL;

$indexes = [];
$idx = mysqli_query($conn, "SHOW INDEX FROM users WHERE Column_name='student_number'");
while ($idx && ($row = mysqli_fetch_assoc($idx))) {
    $name = (string) $row['Key_name'];
    $indexes[$name] = [
        'non_unique' => (int) $row['Non_unique'],
        'sub_part' => $row['Sub_part'],
    ];
    echo 'index ' . $name . ' non_unique=' . $row['Non_unique'] . ' sub_part=' . (string) ($row['Sub_part'] ?? '') . PHP_EOL;
}

$hasUnique = false;
foreach ($indexes as $name => $meta) {
    if ((int) $meta['non_unique'] === 0) {
        $hasUnique = true;
    }
}

$drop = [];
foreach ($indexes as $name => $meta) {
    if ((int) $meta['non_unique'] === 1) {
        $drop[] = $name;
    }
}

if (!$apply) {
    echo 'would_create_seq=' . ($seqExists ? '0' : '1') . PHP_EOL;
    echo 'would_drop=' . json_encode($drop) . PHP_EOL;
    echo 'would_add_unique=' . ($hasUnique ? '0' : '1') . PHP_EOL;
    echo "idempotent_noop=" . (($seqExists && $hasUnique && $drop === []) ? '1' : '0') . PHP_EOL;
    exit(0);
}

if (!$seqExists) {
    $ok = mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `college_student_number_seq` (
      `issue_year` smallint unsigned NOT NULL,
      `last_n` smallint unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`issue_year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$ok) {
        fwrite(STDERR, 'Could not create college_student_number_seq: ' . mysqli_error($conn) . PHP_EOL);
        exit(1);
    }
    echo "created_seq=1\n";
}

foreach ($drop as $name) {
    $safe = '`' . str_replace('`', '', $name) . '`';
    $ok = mysqli_query($conn, "ALTER TABLE `users` DROP INDEX {$safe}");
    if (!$ok) {
        fwrite(STDERR, "Could not drop {$name}: " . mysqli_error($conn) . PHP_EOL);
        exit(1);
    }
    echo "dropped={$name}\n";
}

if (!$hasUnique) {
    $ok = mysqli_query($conn, 'ALTER TABLE `users` ADD UNIQUE KEY `uq_users_student_number` (`student_number`)');
    if (!$ok) {
        fwrite(STDERR, 'Could not add UNIQUE: ' . mysqli_error($conn) . PHP_EOL);
        exit(1);
    }
    echo "added_unique=uq_users_student_number\n";
}

echo "done\n";
