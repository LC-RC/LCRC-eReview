<?php
/**
 * Database Configuration
 * Includes session configuration for secure session management
 *
 * Credentials: set via db.local.php (gitignored) on each machine/VPS.
 * Never commit real passwords in this file.
 */

// Include session configuration first
require_once __DIR__ . '/session_config.php';

$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'ereview';

// Machine/VPS-specific override (preferred). Copy from db.local.php.example.
if (is_file(__DIR__ . '/db.local.php')) {
    /** @noinspection PhpIncludeInspection */
    require __DIR__ . '/db.local.php';
}

$conn = @mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    http_response_code(500);
    // Avoid leaking host/user details publicly; check PHP/Apache error log on the server.
    die('Database connection failed. Check db.local.php credentials on this server.');
}

// Set charset to UTF-8
mysqli_set_charset($conn, 'utf8mb4');

// Match session_config.php (Asia/Manila) so DATETIME/TIMESTAMP comparisons in SQL stay consistent with PHP.
@mysqli_query($conn, "SET time_zone = '+08:00'");
?>
