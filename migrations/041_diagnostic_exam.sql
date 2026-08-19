-- Diagnostic Exam (Examination module) — isolated from college_exams
-- Apply manually; diagnostic_schema.php also idempotently ensures tables.

CREATE TABLE IF NOT EXISTS `diagnostic_subjects` (
  `subject_id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_code` varchar(32) NOT NULL,
  `subject_name` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`subject_id`),
  UNIQUE KEY `uq_diagnostic_subject_code` (`subject_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `diagnostic_batches` (
  `batch_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `time_limit_seconds` int(11) NOT NULL DEFAULT 3600,
  `available_from` datetime DEFAULT NULL,
  `deadline` datetime DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `shuffle_questions` tinyint(1) NOT NULL DEFAULT 0,
  `shuffle_choices` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`batch_id`),
  KEY `idx_diagnostic_batches_published` (`is_published`),
  KEY `idx_diagnostic_batches_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `diagnostic_batch_subjects` (
  `batch_subject_id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `questions_required` int(11) NOT NULL DEFAULT 0 COMMENT '0 = use all authored questions for this subject',
  PRIMARY KEY (`batch_subject_id`),
  UNIQUE KEY `uq_diagnostic_batch_subject` (`batch_id`,`subject_id`),
  KEY `idx_dbs_batch` (`batch_id`),
  KEY `idx_dbs_subject` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `diagnostic_batch_sections` (
  `batch_section_id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `section_value` varchar(100) NOT NULL,
  PRIMARY KEY (`batch_section_id`),
  UNIQUE KEY `uq_diagnostic_batch_section` (`batch_id`,`section_value`),
  KEY `idx_dbsct_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `diagnostic_questions` (
  `question_id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_type` varchar(16) NOT NULL DEFAULT 'mcq',
  `choice_a` text DEFAULT NULL,
  `choice_b` text DEFAULT NULL,
  `choice_c` text DEFAULT NULL,
  `choice_d` text DEFAULT NULL,
  `correct_answer` varchar(1) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`question_id`),
  KEY `idx_dq_batch_subject` (`batch_id`,`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `diagnostic_attempts` (
  `attempt_id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
  `score` decimal(5,2) DEFAULT NULL,
  `correct_count` int(11) DEFAULT NULL,
  `total_count` int(11) DEFAULT NULL,
  `subject_breakdown_json` longtext DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `ui_state_json` longtext DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `tab_switch_count` int(11) NOT NULL DEFAULT 0,
  `last_tab_switch_at` datetime DEFAULT NULL,
  PRIMARY KEY (`attempt_id`),
  UNIQUE KEY `uq_diagnostic_attempt_user_batch` (`user_id`,`batch_id`),
  KEY `idx_da_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `diagnostic_answers` (
  `answer_id` int(11) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `selected_answer` varchar(1) DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `answered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`answer_id`),
  UNIQUE KEY `uq_diagnostic_answer_attempt_q` (`attempt_id`,`question_id`),
  KEY `idx_dans_q` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `diagnostic_subjects` (`subject_code`, `subject_name`, `sort_order`, `is_active`) VALUES
('FAR', 'Financial Accounting and Reporting', 1, 1),
('AFAR', 'Advanced Financial Accounting and Reporting', 2, 1),
('AUD', 'Auditing', 3, 1),
('TAX', 'Taxation', 4, 1),
('MAS', 'Management Advisory Services', 5, 1),
('RFBT', 'Regulatory Framework for Business Transactions', 6, 1),
('MS', 'Management Services', 7, 1);
