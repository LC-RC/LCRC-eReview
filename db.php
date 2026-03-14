<?php
/**
 * Database Configuration
 * Includes session configuration for secure session management
 */

// Include session configuration first
require_once __DIR__ . '/session_config.php';

$host = "localhost";
$user = "root"; // change if may password
$pass = "2429249_lms***";
$db = "ereview";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset to UTF-8
mysqli_set_charset($conn, "utf8mb4");

// Set connection timeout
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
?>
