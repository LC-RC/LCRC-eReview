<?php
/**
 * Read-only: check whether allowlisted load-test DBs exist. No writes.
 */
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_OFF);
$host = '127.0.0.1';
$user = 'root';
$pass = '';
$local = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db.local.php';
if (is_file($local)) {
    /** @noinspection PhpIncludeInspection */
    require $local;
    $host = isset($host) ? (string)$host : '127.0.0.1';
    $user = isset($user) ? (string)$user : 'root';
    $pass = isset($pass) ? (string)$pass : '';
}
$conn = @mysqli_connect($host, $user, $pass);
if (!$conn) {
    echo "mysql_connect=FAIL\n";
    echo 'error=' . mysqli_connect_error() . "\n";
    exit(0);
}
foreach (['ereview_loadtest', 'ereview_test', 'ereview'] as $db) {
    $dbEsc = mysqli_real_escape_string($conn, $db);
    $r = mysqli_query($conn, "SHOW DATABASES LIKE '{$dbEsc}'");
    $exists = $r && mysqli_num_rows($r) > 0;
    echo $db . '_exists=' . ($exists ? 'YES' : 'NO') . "\n";
    if ($r) {
        mysqli_free_result($r);
    }
}
mysqli_close($conn);
