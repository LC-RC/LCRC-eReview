-- College Examination public registration: pending rows until email verification.
-- Separate from LMS `pending_registrations` (packages / GCash / review access).
-- Email is stored lowercased. UNIQUE(email) allows token rotation via upsert.
--
-- PRECHECK:
--   SHOW TABLES LIKE 'college_pending_registrations';

CREATE TABLE IF NOT EXISTS `college_pending_registrations` (
  `pending_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `school` varchar(255) NOT NULL,
  `section` varchar(128) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `use_default_avatar` tinyint(1) NOT NULL DEFAULT 1,
  `existing_user_id` int(11) DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`pending_id`),
  UNIQUE KEY `uq_cpr_email` (`email`),
  UNIQUE KEY `uq_cpr_token_hash` (`token_hash`),
  KEY `idx_cpr_expires` (`expires_at`),
  KEY `idx_cpr_existing_user` (`existing_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
