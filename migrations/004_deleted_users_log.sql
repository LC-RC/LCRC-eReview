CREATE TABLE IF NOT EXISTS `deleted_users_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `deleted_user_id` int(11) NOT NULL,
  `deleted_name` varchar(180) NOT NULL,
  `deleted_email` varchar(180) NOT NULL,
  `deleted_by_admin_id` int(11) NOT NULL,
  `deleted_by_admin_name` varchar(180) NOT NULL,
  `deleted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_deleted_users_deleted_at` (`deleted_at`),
  KEY `idx_deleted_users_admin` (`deleted_by_admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
