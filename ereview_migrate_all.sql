-- =====================================================
-- Ereview + Inventory - Run this entire file in MySQL Workbench
-- =====================================================
-- 1. Creates database `ereview` and all LMS tables + migrations
-- 2. Creates database `inventory_db` and inventory tables
-- If you re-run: ignore "Duplicate column", "Duplicate key", "already exists" errors.
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
/*!40101 SET NAMES utf8mb4 */;

-- --------------- EREVIEW DATABASE ---------------
CREATE DATABASE IF NOT EXISTS `ereview` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `ereview`;

-- Base tables (from ereview_complete.sql)
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `review_type` enum('reviewee','undergrad') NOT NULL DEFAULT 'reviewee',
  `school` varchar(150) NOT NULL,
  `school_other` varchar(150) DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `email` varchar(120) NOT NULL,
  `password` varchar(100) NOT NULL,
  `role` enum('admin','student') DEFAULT 'student',
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `access_start` datetime DEFAULT NULL,
  `access_end` datetime DEFAULT NULL,
  `access_months` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `subjects` (
  `subject_id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  PRIMARY KEY (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `lessons` (
  `lesson_id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`lesson_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `lessons_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `lesson_videos` (
  `video_id` int(11) NOT NULL AUTO_INCREMENT,
  `lesson_id` int(11) NOT NULL,
  `video_title` varchar(200) DEFAULT NULL,
  `video_url` text NOT NULL,
  PRIMARY KEY (`video_id`),
  KEY `lesson_id` (`lesson_id`),
  CONSTRAINT `lesson_videos_ibfk_1` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`lesson_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `lesson_handouts` (
  `handout_id` int(11) NOT NULL AUTO_INCREMENT,
  `lesson_id` int(11) NOT NULL,
  `handout_title` varchar(200) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `allow_download` tinyint(1) NOT NULL DEFAULT 1,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`handout_id`),
  KEY `lesson_id` (`lesson_id`),
  CONSTRAINT `lesson_handouts_ibfk_1` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`lesson_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `handout_annotations` (
  `annotation_id` int(11) NOT NULL AUTO_INCREMENT,
  `handout_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `annotation_type` varchar(50) NOT NULL DEFAULT 'text',
  `page_number` int(11) NOT NULL DEFAULT 1,
  `x` decimal(10,2) DEFAULT NULL,
  `y` decimal(10,2) DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `height` decimal(10,2) DEFAULT NULL,
  `content` mediumtext DEFAULT NULL,
  `color` varchar(20) DEFAULT '#000000',
  `font_size` int(11) DEFAULT 12,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`annotation_id`),
  KEY `handout_id` (`handout_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `handout_annotations_ibfk_1` FOREIGN KEY (`handout_id`) REFERENCES `lesson_handouts` (`handout_id`) ON DELETE CASCADE,
  CONSTRAINT `handout_annotations_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `quizzes` (
  `quiz_id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `quiz_type` enum('pre-test','post-test','mock') NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`quiz_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `quizzes_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `quiz_questions` (
  `question_id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `choice_a` text DEFAULT NULL,
  `choice_b` text DEFAULT NULL,
  `choice_c` text DEFAULT NULL,
  `choice_d` text DEFAULT NULL,
  `correct_answer` enum('A','B','C','D') NOT NULL,
  PRIMARY KEY (`question_id`),
  KEY `quiz_id` (`quiz_id`),
  CONSTRAINT `quiz_questions_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`quiz_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `quiz_answers` (
  `answer_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `selected_answer` enum('A','B','C','D') DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `answered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`answer_id`),
  KEY `user_id` (`user_id`),
  KEY `question_id` (`question_id`),
  CONSTRAINT `quiz_answers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `quiz_answers_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`question_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default admin user (ignore if already exists)
INSERT INTO `users` (`user_id`, `full_name`, `review_type`, `school`, `school_other`, `payment_proof`, `email`, `password`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'System Administrator', 'reviewee', '', NULL, NULL, 'admin@ereview.ph', 'admin123', 'admin', 'approved', NOW(), NOW())
ON DUPLICATE KEY UPDATE `user_id`=`user_id`;

-- Migrations: add columns to users (ignore "Duplicate column" if re-running)
ALTER TABLE `users` ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`;
ALTER TABLE `users` ADD COLUMN `last_login_at` datetime DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `last_login_ip` varchar(45) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `last_login_user_agent` varchar(500) DEFAULT NULL;

-- Migrations: extra tables (IF NOT EXISTS = safe to re-run)
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `selector` varchar(16) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `selector` varchar(16) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `attempt_count` int(11) NOT NULL DEFAULT 0,
  `first_attempt_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`),
  KEY `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pending_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(120) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `review_type` enum('reviewee','undergrad') NOT NULL DEFAULT 'reviewee',
  `school` varchar(150) NOT NULL,
  `school_other` varchar(150) DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `selector` varchar(32) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `idx_email` (`email`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `magic_link_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `selector` varchar(16) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional: ensure handout_annotations.content is mediumtext
ALTER TABLE `handout_annotations` MODIFY `content` mediumtext DEFAULT NULL;

-- Indexes (ignore "Duplicate key name" if re-running)
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_created_at ON users(created_at);
CREATE INDEX idx_users_role_status ON users(role, status);
CREATE INDEX idx_subjects_status ON subjects(status);
CREATE INDEX idx_subjects_name ON subjects(subject_name);
CREATE INDEX idx_lessons_subject_id ON lessons(subject_id);
CREATE INDEX idx_lesson_videos_lesson_id ON lesson_videos(lesson_id);
CREATE INDEX idx_lesson_handouts_lesson_id ON lesson_handouts(lesson_id);
CREATE INDEX idx_lesson_handouts_handout_id ON lesson_handouts(handout_id);
CREATE INDEX idx_handout_annotations_handout_id ON handout_annotations(handout_id);
CREATE INDEX idx_handout_annotations_student_id ON handout_annotations(student_id);
CREATE INDEX idx_handout_annotations_composite ON handout_annotations(handout_id, student_id);
CREATE INDEX idx_quizzes_subject_id ON quizzes(subject_id);
CREATE INDEX idx_quizzes_quiz_type ON quizzes(quiz_type);
CREATE INDEX idx_quiz_questions_quiz_id ON quiz_questions(quiz_id);
CREATE INDEX idx_quiz_questions_question_id ON quiz_questions(question_id);
CREATE INDEX idx_quiz_answers_user_id ON quiz_answers(user_id);
CREATE INDEX idx_quiz_answers_question_id ON quiz_answers(question_id);
CREATE INDEX idx_quiz_answers_composite ON quiz_answers(user_id, question_id);

-- --------------- INVENTORY DATABASE ---------------
CREATE DATABASE IF NOT EXISTS inventory_db DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE inventory_db;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  sku VARCHAR(50) UNIQUE NOT NULL,
  name VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
  cost_price DECIMAL(12,2) DEFAULT 0.00,
  selling_price DECIMAL(12,2) DEFAULT 0.00,
  quantity INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 5,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS stock_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  type ENUM('in', 'out', 'adjust') NOT NULL,
  quantity INT NOT NULL,
  reference VARCHAR(100) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

INSERT INTO categories (name, description)
SELECT a, b FROM (
  SELECT 'Writing Instruments' AS a, 'Pens, pencils, markers, highlighters' AS b
  UNION ALL SELECT 'Paper & Notebooks', 'Bond paper, notebooks, pads, sticky notes'
  UNION ALL SELECT 'Filing & Organization', 'Folders, binders, envelopes, clips'
  UNION ALL SELECT 'Desk Supplies', 'Staplers, tape, scissors, hole punch'
  UNION ALL SELECT 'Cleaning & Breakroom', 'Tissue, soap, trash bags'
) x
WHERE NOT EXISTS (SELECT 1 FROM categories LIMIT 1);

-- =====================================================
-- Done. Default Ereview admin: admin@ereview.ph / admin123
-- =====================================================
