-- College Examination dedicated password-reset tokens.
-- Hashed, single-use, expiring. Independent of LMS password_reset_tokens.
--
-- PRECHECK:
--   SHOW TABLES LIKE 'college_password_reset_tokens';

CREATE TABLE IF NOT EXISTS `college_password_reset_tokens` (
  `reset_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`reset_id`),
  UNIQUE KEY `uq_cprt_token_hash` (`token_hash`),
  KEY `idx_cprt_user` (`user_id`),
  KEY `idx_cprt_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
