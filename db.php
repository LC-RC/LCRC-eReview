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

$conn = false;
$connectError = '';
try {
    $conn = mysqli_connect($host, $user, $pass, $db);
} catch (Throwable $e) {
    $conn = false;
    $connectError = $e->getMessage();
}
if (!$conn) {
    if ($connectError === '') {
        $connectError = (string)mysqli_connect_error();
    }
    $script = strtolower(basename(str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''))));
    $isExamAjax = ($script === 'college_exam_ajax.php');
    if ($isExamAjax) {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode([
            'ok' => false,
            'error' => 'Database unavailable',
            'retry' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(500);
    die('Connection failed: ' . $connectError);
}

mysqli_set_charset($conn, 'utf8mb4');
@mysqli_query($conn, "SET time_zone = '+08:00'");
?>
