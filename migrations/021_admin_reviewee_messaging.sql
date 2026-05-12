-- Admin <-> Reviewee messaging (no reviewee-to-reviewee)
-- Run on ereview DB before using messaging module.

CREATE TABLE IF NOT EXISTS `admin_reviewee_messages` (
  `message_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thread_student_id` INT NOT NULL,
  `sender_id` INT NOT NULL,
  `sender_role` VARCHAR(32) NOT NULL,
  `recipient_id` INT NOT NULL,
  `recipient_role` VARCHAR(32) NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`message_id`),
  KEY `idx_arm_thread_created` (`thread_student_id`, `created_at`),
  KEY `idx_arm_recipient_unread` (`recipient_role`, `recipient_id`, `read_at`),
  KEY `idx_arm_sender` (`sender_id`, `sender_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

