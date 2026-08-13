<?php
/**
 * Database bootstrap (safe to commit / pull).
 *
 * Credentials live ONLY in db.local.php (gitignored).
 * That file is never pushed, so local/VPS passwords cannot overwrite each other.
 *
 * Setup (once per machine):
 *   copy db.local.php.example db.local.php
 *   then edit the password for that machine.
 */

require_once __DIR__ . '/session_config.php';

$dbLocal = __DIR__ . '/db.local.php';
if (!is_file($dbLocal)) {
    http_response_code(500);
    die(
        'Missing db.local.php. Copy db.local.php.example to db.local.php ' .
        'and set the MySQL credentials for this server. Do not commit db.local.php.'
    );
}

/** @noinspection PhpIncludeInspection */
require $dbLocal;

$host = isset($host) ? (string) $host : 'localhost';
$user = isset($user) ? (string) $user : 'root';
$pass = isset($pass) ? (string) $pass : '';
$db = isset($db) ? (string) $db : 'ereview';

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    http_response_code(500);
    die('Connection failed: ' . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');
@mysqli_query($conn, "SET time_zone = '+08:00'");
?>
