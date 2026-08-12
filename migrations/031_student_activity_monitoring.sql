-- Student activity monitoring (events, video progress, sessions, quiz proctoring columns)
-- Safe to re-run: IF NOT EXISTS / information_schema guards where needed.

CREATE TABLE IF NOT EXISTS `student_content_events` (
  `event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `subject_id` INT(11) DEFAULT NULL,
  `lesson_id` INT(11) DEFAULT NULL,
  `quiz_id` INT(11) DEFAULT NULL,
  `video_id` INT(11) DEFAULT NULL,
  `handout_id` INT(11) DEFAULT NULL,
  `attempt_id` INT(11) DEFAULT NULL,
  `page_key` VARCHAR(120) DEFAULT NULL,
  `page_title` VARCHAR(255) DEFAULT NULL,
  `page_url` VARCHAR(500) DEFAULT NULL,
  `meta_json` JSON DEFAULT NULL,
  `session_token` VARCHAR(64) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `idx_sce_user_created` (`user_id`, `created_at`),
  KEY `idx_sce_type_created` (`event_type`, `created_at`),
  KEY `idx_sce_session` (`session_token`),
  KEY `idx_sce_page_created` (`page_key`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_video_progress` (
  `progress_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `video_id` INT(11) NOT NULL,
  `lesson_id` INT(11) DEFAULT NULL,
  `subject_id` INT(11) DEFAULT NULL,
  `position_sec` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `max_position_sec` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `duration_sec` DECIMAL(10,2) DEFAULT NULL,
  `percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `watch_seconds` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `is_playing` TINYINT(1) NOT NULL DEFAULT 0,
  `completed` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`progress_id`),
  UNIQUE KEY `uq_svp_user_video` (`user_id`, `video_id`),
  KEY `idx_svp_updated` (`updated_at`),
  KEY `idx_svp_lesson` (`lesson_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_sessions` (
  `session_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `session_token` VARCHAR(64) NOT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` DATETIME DEFAULT NULL,
  `current_page_key` VARCHAR(120) DEFAULT NULL,
  `current_page_title` VARCHAR(255) DEFAULT NULL,
  `current_page_url` VARCHAR(500) DEFAULT NULL,
  `subject_id` INT(11) DEFAULT NULL,
  `lesson_id` INT(11) DEFAULT NULL,
  `quiz_id` INT(11) DEFAULT NULL,
  `video_id` INT(11) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `uq_ss_token` (`session_token`),
  KEY `idx_ss_user_active` (`user_id`, `is_active`, `last_seen_at`),
  KEY `idx_ss_live` (`is_active`, `last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `quiz_attempts` (
  `attempt_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `quiz_id` INT(11) NOT NULL,
  `status` ENUM('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
  `score` DECIMAL(5,2) DEFAULT NULL,
  `correct_count` INT(11) DEFAULT NULL,
  `total_count` INT(11) DEFAULT NULL,
  `started_at` DATETIME NOT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`attempt_id`),
  KEY `idx_quiz_attempts_user_quiz` (`user_id`, `quiz_id`),
  KEY `idx_quiz_attempts_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Proctoring / live columns on quiz_attempts (ignore errors if already present via app ensure)
SET @db := DATABASE();
SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='quiz_attempts' AND COLUMN_NAME='last_seen_at'),
    'SELECT 1',
    'ALTER TABLE quiz_attempts ADD COLUMN last_seen_at DATETIME DEFAULT NULL, ADD COLUMN tab_switch_count INT NOT NULL DEFAULT 0, ADD COLUMN last_tab_switch_at DATETIME DEFAULT NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='quiz_answers' AND COLUMN_NAME='attempt_id'),
    'SELECT 1',
    'ALTER TABLE quiz_answers ADD COLUMN attempt_id INT(11) DEFAULT NULL AFTER question_id, ADD KEY idx_quiz_answers_attempt (attempt_id)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
