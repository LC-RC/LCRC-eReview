-- College Student + Professor Admin roles and college module tables
-- Run once in MySQL Workbench or mysql CLI. Safe to re-run if tables exist (CREATE IF NOT EXISTS).

USE `ereview`;

-- Extend user roles (ignore error if already applied)
ALTER TABLE `users` MODIFY COLUMN `role`
  ENUM('admin','student','college_student','professor_admin')
  NOT NULL DEFAULT 'student';

-- College exams (quizzes) — separate from LMS `quizzes` table
CREATE TABLE IF NOT EXISTS `college_exams` (
  `exam_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `time_limit_seconds` int(11) NOT NULL DEFAULT 3600 COMMENT '0 = no per-session timer',
  `available_from` datetime DEFAULT NULL,
  `deadline` datetime DEFAULT NULL COMMENT 'Last moment to start or submit (enforced in app)',
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`exam_id`),
  KEY `idx_college_exams_published` (`is_published`),
  KEY `idx_college_exams_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `college_exam_questions` (
  `question_id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `choice_a` text DEFAULT NULL,
  `choice_b` text DEFAULT NULL,
  `choice_c` text DEFAULT NULL,
  `choice_d` text DEFAULT NULL,
  `correct_answer` varchar(1) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`question_id`),
  KEY `idx_college_q_exam` (`exam_id`),
  CONSTRAINT `fk_college_q_exam` FOREIGN KEY (`exam_id`) REFERENCES `college_exams` (`exam_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `college_exam_attempts` (
  `attempt_id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
  `score` decimal(5,2) DEFAULT NULL,
  `correct_count` int(11) DEFAULT NULL,
  `total_count` int(11) DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`attempt_id`),
  UNIQUE KEY `uq_college_attempt_user_exam` (`user_id`,`exam_id`),
  KEY `idx_college_attempt_exam` (`exam_id`),
  CONSTRAINT `fk_college_attempt_exam` FOREIGN KEY (`exam_id`) REFERENCES `college_exams` (`exam_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_college_attempt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `college_exam_answers` (
  `answer_id` int(11) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `selected_answer` varchar(1) DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `answered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`answer_id`),
  UNIQUE KEY `uq_college_answer_attempt_q` (`attempt_id`,`question_id`),
  KEY `idx_college_ans_q` (`question_id`),
  CONSTRAINT `fk_college_ans_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `college_exam_attempts` (`attempt_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_college_ans_question` FOREIGN KEY (`question_id`) REFERENCES `college_exam_questions` (`question_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `college_upload_tasks` (
  `task_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `instructions` text DEFAULT NULL,
  `deadline` datetime NOT NULL,
  `max_file_size` int(11) NOT NULL DEFAULT 10485760,
  `allowed_extensions` varchar(255) NOT NULL DEFAULT 'pdf,doc,docx,png,jpg,jpeg',
  `is_open` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`task_id`),
  KEY `idx_college_upload_deadline` (`deadline`),
  KEY `idx_college_upload_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `college_submissions` (
  `submission_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `file_path` varchar(512) NOT NULL DEFAULT '',
  `file_name` varchar(255) NOT NULL DEFAULT '',
  `file_size` bigint(20) DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` varchar(32) NOT NULL DEFAULT 'submitted',
  PRIMARY KEY (`submission_id`),
  UNIQUE KEY `uq_college_submission_task_user` (`task_id`,`user_id`),
  KEY `idx_college_sub_user` (`user_id`),
  CONSTRAINT `fk_college_sub_task` FOREIGN KEY (`task_id`) REFERENCES `college_upload_tasks` (`task_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_college_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
