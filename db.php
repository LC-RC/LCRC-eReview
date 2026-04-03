<?php
/**
 * Database Configuration
 * Includes session configuration for secure session management
 */

// Include session configuration first
require_once __DIR__ . '/session_config.php';

$host = "localhost";
$user = "root";
// Must match MySQL root password (MySQL Workbench / XAMPP). Default XAMPP = empty string ""
$pass = "2429249_lcrc";
$db = "ereview";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset to UTF-8
mysqli_set_charset($conn, "utf8mb4");

// Match session_config.php (Asia/Manila) so DATETIME/TIMESTAMP comparisons in SQL stay consistent with PHP.
@mysqli_query($conn, "SET time_zone = '+08:00'");

// Set connection timeout
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
?>
