-- Dedicated College Examination credentials.
-- Independent of users.password (LMS). One row per user_id.
-- Do not backfill or copy existing users.password hashes.
-- Apply this before College verify, login, reset, or staff create.
-- Request-time PHP must not CREATE this table (DDL would commit open transactions).
--
-- PRECHECK:
--   SHOW TABLES LIKE 'college_auth_credentials';

CREATE TABLE IF NOT EXISTS `college_auth_credentials` (
  `user_id` int(11) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_cac_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
