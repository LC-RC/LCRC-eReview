-- My CPA Review Tools (student personal workspace)
-- Safe to re-run: CREATE TABLE IF NOT EXISTS

CREATE TABLE IF NOT EXISTS `student_notes` (
  `note_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `subject_id` INT(11) DEFAULT NULL,
  `lesson_id` INT(11) DEFAULT NULL,
  `question_id` INT(11) DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `content` MEDIUMTEXT NOT NULL,
  `tags` VARCHAR(500) DEFAULT NULL,
  `is_starred` TINYINT(1) NOT NULL DEFAULT 0,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`note_id`),
  KEY `idx_sn_user_updated` (`user_id`, `updated_at`),
  KEY `idx_sn_user_subject` (`user_id`, `subject_id`),
  KEY `idx_sn_user_lesson` (`user_id`, `lesson_id`),
  KEY `idx_sn_user_pinned` (`user_id`, `is_pinned`, `is_starred`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_bookmarks` (
  `bookmark_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `item_type` ENUM('lesson','handout','quiz','question','page') NOT NULL,
  `item_id` INT(11) NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `url` VARCHAR(500) DEFAULT NULL,
  `subject_id` INT(11) DEFAULT NULL,
  `lesson_id` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`bookmark_id`),
  UNIQUE KEY `uq_sb_user_item` (`user_id`, `item_type`, `item_id`),
  KEY `idx_sb_user_created` (`user_id`, `created_at`),
  KEY `idx_sb_user_subject` (`user_id`, `subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_favorites` (
  `favorite_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `item_type` ENUM('lesson','handout','subject') NOT NULL,
  `item_id` INT(11) NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `url` VARCHAR(500) DEFAULT NULL,
  `subject_id` INT(11) DEFAULT NULL,
  `lesson_id` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`favorite_id`),
  UNIQUE KEY `uq_sf_user_item` (`user_id`, `item_type`, `item_id`),
  KEY `idx_sf_user_created` (`user_id`, `created_at`),
  KEY `idx_sf_user_subject` (`user_id`, `subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_important_items` (
  `important_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `item_type` ENUM('lesson','note','quick_review','concept') NOT NULL,
  `item_id` INT(11) NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `body` TEXT DEFAULT NULL,
  `topic` VARCHAR(255) DEFAULT NULL,
  `subject_id` INT(11) DEFAULT NULL,
  `lesson_id` INT(11) DEFAULT NULL,
  `is_last_minute` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`important_id`),
  UNIQUE KEY `uq_si_user_item` (`user_id`, `item_type`, `item_id`),
  KEY `idx_si_user_created` (`user_id`, `created_at`),
  KEY `idx_si_user_subject` (`user_id`, `subject_id`),
  KEY `idx_si_user_last_minute` (`user_id`, `is_last_minute`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_mistake_notebook` (
  `mistake_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `question_id` INT(11) NOT NULL,
  `quiz_id` INT(11) DEFAULT NULL,
  `attempt_id` INT(11) DEFAULT NULL,
  `subject_id` INT(11) DEFAULT NULL,
  `lesson_id` INT(11) DEFAULT NULL,
  `selected_answer` VARCHAR(5) DEFAULT NULL,
  `correct_answer` VARCHAR(5) DEFAULT NULL,
  `explanation_snapshot` TEXT DEFAULT NULL,
  `personal_note` TEXT DEFAULT NULL,
  `is_reviewed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`mistake_id`),
  UNIQUE KEY `uq_smn_user_q_attempt` (`user_id`, `question_id`, `attempt_id`),
  KEY `idx_smn_user_reviewed` (`user_id`, `is_reviewed`),
  KEY `idx_smn_user_subject` (`user_id`, `subject_id`),
  KEY `idx_smn_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_quick_review` (
  `quick_id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `subject_id` INT(11) DEFAULT NULL,
  `lesson_id` INT(11) DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `content` TEXT NOT NULL,
  `tags` VARCHAR(500) DEFAULT NULL,
  `is_important` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`quick_id`),
  KEY `idx_sqr_user_updated` (`user_id`, `updated_at`),
  KEY `idx_sqr_user_subject` (`user_id`, `subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_cpa_activity_log` (
  `log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `entity_type` VARCHAR(64) DEFAULT NULL,
  `entity_id` INT(11) DEFAULT NULL,
  `summary` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_scal_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
