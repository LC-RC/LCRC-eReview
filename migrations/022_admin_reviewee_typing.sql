-- Typing indicators for admin ↔ reviewee messaging (short-lived rows; polled by sync.php).
-- Run once on the server: mysql ... < migrations/022_admin_reviewee_typing.sql

CREATE TABLE IF NOT EXISTS `admin_reviewee_typing` (
  `thread_student_id` INT NOT NULL COMMENT 'Reviewee user_id for this thread',
  `user_id` INT NOT NULL COMMENT 'Who is typing',
  `touched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`thread_student_id`, `user_id`),
  KEY `idx_art_thread_touched` (`thread_student_id`, `touched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
