<?php
/**
 * One-time migration: create login_attempts table for rate limiting.
 * Run once via browser (http://localhost/Ereview/migrate_login_attempts.php) or CLI: php migrate_login_attempts.php
 * Delete this file after use for security.
 */

require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$sql = "CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL COMMENT 'IPv4 or IPv6',
  `attempt_count` int(11) NOT NULL DEFAULT 0,
  `first_attempt_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`),
  KEY `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

if (mysqli_query($conn, $sql)) {
    echo "OK: login_attempts table created or already exists. Rate limiting is enabled.\n";
} else {
    echo "ERROR: " . mysqli_error($conn) . "\n";
    exit(1);
}
