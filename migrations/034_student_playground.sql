-- CPA Playground (solo sessions + daily challenge). References existing quiz_questions.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS

CREATE TABLE IF NOT EXISTS `student_playground_sessions` (
  `session_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `mode` ENUM('quick_play','subject_challenge','mixed_challenge','daily_challenge') NOT NULL,
  `subject_id` INT(11) DEFAULT NULL,
  `status` ENUM('in_progress','completed','abandoned') NOT NULL DEFAULT 'in_progress',
  `question_count` INT(11) NOT NULL DEFAULT 10,
  `seconds_per_question` INT(11) NOT NULL DEFAULT 20,
  `difficulty` ENUM('easy','mixed','hard') NOT NULL DEFAULT 'mixed',
  `seed` VARCHAR(64) NOT NULL DEFAULT '',
  `score` INT(11) NOT NULL DEFAULT 0,
  `correct_count` INT(11) NOT NULL DEFAULT 0,
  `wrong_count` INT(11) NOT NULL DEFAULT 0,
  `best_streak` INT(11) NOT NULL DEFAULT 0,
  `current_streak` INT(11) NOT NULL DEFAULT 0,
  `total_response_ms` BIGINT NOT NULL DEFAULT 0,
  `answered_count` INT(11) NOT NULL DEFAULT 0,
  `daily_key` CHAR(10) DEFAULT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `idx_spg_user_started` (`user_id`, `started_at`),
  KEY `idx_spg_user_daily` (`user_id`, `daily_key`),
  KEY `idx_spg_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_playground_items` (
  `item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `ordinal` INT(11) NOT NULL,
  `question_id` INT(11) NOT NULL,
  `quiz_id` INT(11) DEFAULT NULL,
  `subject_id` INT(11) DEFAULT NULL,
  `choice_order` VARCHAR(32) NOT NULL DEFAULT 'ABCD',
  `selected_answer` VARCHAR(5) DEFAULT NULL,
  `is_correct` TINYINT(1) DEFAULT NULL,
  `points` INT(11) NOT NULL DEFAULT 0,
  `response_ms` INT(11) DEFAULT NULL,
  `served_at` DATETIME DEFAULT NULL,
  `answered_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  UNIQUE KEY `uq_spgi_session_ordinal` (`session_id`, `ordinal`),
  UNIQUE KEY `uq_spgi_session_question` (`session_id`, `question_id`),
  KEY `idx_spgi_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Phase 3 scaffold (rooms) — unused by Phase 1/2 UI
CREATE TABLE IF NOT EXISTS `student_playground_rooms` (
  `room_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `host_user_id` INT(11) NOT NULL,
  `room_code` CHAR(6) NOT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `mode` ENUM('subject_challenge','mixed_challenge') NOT NULL DEFAULT 'mixed_challenge',
  `subject_id` INT(11) DEFAULT NULL,
  `question_count` INT(11) NOT NULL DEFAULT 20,
  `status` ENUM('lobby','live','finished') NOT NULL DEFAULT 'lobby',
  `seed` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` DATETIME DEFAULT NULL,
  `finished_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`room_id`),
  UNIQUE KEY `uq_spgr_code` (`room_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
