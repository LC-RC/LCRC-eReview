-- College Examination public registration/resend rate-limit events.
-- Fail closed in application code if this table is missing or INSERT fails.
-- IP is hashed (SHA-256 of REMOTE_ADDR only; do not use X-Forwarded-For).
--
-- PRECHECK:
--   SHOW TABLES LIKE 'college_verify_rate_events';

CREATE TABLE IF NOT EXISTS `college_verify_rate_events` (
  `event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `action` varchar(32) NOT NULL,
  `ip_hash` char(64) NOT NULL,
  `email_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`event_id`),
  KEY `idx_cvr_ip_action_time` (`ip_hash`, `action`, `created_at`),
  KEY `idx_cvr_email_action_time` (`email_hash`, `action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
