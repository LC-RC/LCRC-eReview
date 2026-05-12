-- Messaging enhancements: attachments + admin pinned threads.

CREATE TABLE IF NOT EXISTS `admin_reviewee_message_attachments` (
  `attachment_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` INT NOT NULL,
  `thread_student_id` INT NOT NULL,
  `uploader_id` INT NOT NULL,
  `storage_token` CHAR(64) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `orig_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `size_bytes` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`),
  UNIQUE KEY `uniq_msg_attachment_token` (`storage_token`),
  KEY `idx_msg_attachment_message` (`message_id`),
  KEY `idx_msg_attachment_thread` (`thread_student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_reviewee_pinned_threads` (
  `admin_user_id` INT NOT NULL,
  `thread_student_id` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_user_id`,`thread_student_id`),
  KEY `idx_pinned_thread` (`thread_student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
