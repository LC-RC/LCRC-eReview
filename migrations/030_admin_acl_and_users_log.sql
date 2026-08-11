-- =====================================================
-- 030 - Admin page ACL + broad users activity log
-- =====================================================
USE `ereview`;

CREATE TABLE IF NOT EXISTS `admin_page_permissions` (
  `permission_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page_key` varchar(64) NOT NULL,
  `granted_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `uq_admin_page_perm` (`user_id`, `page_key`),
  KEY `idx_app_user` (`user_id`),
  KEY `idx_app_key` (`page_key`),
  CONSTRAINT `fk_app_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `users_activity_log` (
  `log_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_email` varchar(120) DEFAULT NULL,
  `actor_role` varchar(32) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `target_user_id` int(11) DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_ual_created` (`created_at`),
  KEY `idx_ual_action` (`action`),
  KEY `idx_ual_actor` (`actor_user_id`),
  KEY `idx_ual_target` (`target_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
