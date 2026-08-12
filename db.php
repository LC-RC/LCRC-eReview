<?php
/**
 * Database Configuration
 * Includes session configuration for secure session management
 *
 * Default credentials = LMS / VPS (production).
 * Optional override: db.local.php (gitignored) for local XAMPP only.
 * Do not put a different password in this file just for local testing —
 * use db.local.php locally so VPS pulls keep working.
 */

// Include session configuration first
require_once __DIR__ . '/session_config.php';

$host = 'localhost';
$user = 'root';
// LMS / VPS MySQL password (production). Local machines: override in db.local.php.
$pass = '2429249_lcrc';
$db = 'ereview';

// Local-only override (gitignored). VPS should NOT create this unless needed.
if (is_file(__DIR__ . '/db.local.php')) {
    /** @noinspection PhpIncludeInspection */
    require __DIR__ . '/db.local.php';
}

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    http_response_code(500);
    die('Connection failed: ' . mysqli_connect_error());
}

// Set charset to UTF-8
mysqli_set_charset($conn, 'utf8mb4');

// Match session_config.php (Asia/Manila) so DATETIME/TIMESTAMP comparisons in SQL stay consistent with PHP.
@mysqli_query($conn, "SET time_zone = '+08:00'");
?>
