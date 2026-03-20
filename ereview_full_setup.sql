-- =====================================================
-- LCRC eReview + Inventory - FULL DATABASE SETUP
-- =====================================================
-- Run this single file ONCE in MySQL Workbench or phpMyAdmin.
-- It will:
--   1. Create the `ereview` database with all LMS tables,
--      migrations, indexes, and seed data.
--   2. Create the `inventory_db` database used by the
--      internal inventory module.
--
-- It is written to be safe to re‑run:
--   - Tables use IF NOT EXISTS where possible
--   - Some ALTER TABLE statements may show
--     "Duplicate column" warnings if you re‑run. You can
--     safely ignore those.
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
/*!40101 SET NAMES utf8mb4 */;

-- --------------- EREVIEW DATABASE ---------------
CREATE DATABASE IF NOT EXISTS `ereview`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
USE `ereview`;

-- ================== BASE SCHEMA ==================
-- (Based on ereview_complete.sql)

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
  CONSTRAINT `lessons_ibfk_1` FOREIGN KEY (`subject_id`)
    REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `lesson_videos` (
  `video_id` int(11) NOT NULL AUTO_INCREMENT,
  `lesson_id` int(11) NOT NULL,
  `video_title` varchar(200) DEFAULT NULL,
  `video_url` text NOT NULL,
  PRIMARY KEY (`video_id`),
  KEY `lesson_id` (`lesson_id`),
  CONSTRAINT `lesson_videos_ibfk_1` FOREIGN KEY (`lesson_id`)
    REFERENCES `lessons` (`lesson_id`) ON DELETE CASCADE
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
  CONSTRAINT `lesson_handouts_ibfk_1` FOREIGN KEY (`lesson_id`)
    REFERENCES `lessons` (`lesson_id`) ON DELETE CASCADE
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
  CONSTRAINT `handout_annotations_ibfk_1` FOREIGN KEY (`handout_id`)
    REFERENCES `lesson_handouts` (`handout_id`) ON DELETE CASCADE,
  CONSTRAINT `handout_annotations_ibfk_2` FOREIGN KEY (`student_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `quizzes` (
  `quiz_id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `quiz_type` enum('pre-test','post-test','mock') NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`quiz_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `quizzes_ibfk_1` FOREIGN KEY (`subject_id`)
    REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE
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
  CONSTRAINT `quiz_questions_ibfk_1` FOREIGN KEY (`quiz_id`)
    REFERENCES `quizzes` (`quiz_id`) ON DELETE CASCADE
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
  CONSTRAINT `quiz_answers_ibfk_1` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `quiz_answers_ibfk_2` FOREIGN KEY (`question_id`)
    REFERENCES `quiz_questions` (`question_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Track quiz attempts per user/quiz
CREATE TABLE IF NOT EXISTS `quiz_attempts` (
  `attempt_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `status` enum('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
  `score` decimal(5,2) DEFAULT NULL,
  `correct_count` int(11) DEFAULT NULL,
  `total_count` int(11) DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`attempt_id`),
  KEY `idx_quiz_attempts_user_quiz` (`user_id`, `quiz_id`),
  KEY `idx_quiz_attempts_status` (`status`),
  CONSTRAINT `quiz_attempts_ibfk_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `quiz_attempts_ibfk_quiz` FOREIGN KEY (`quiz_id`)
    REFERENCES `quizzes` (`quiz_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed default admin
INSERT INTO `users`
  (`user_id`, `full_name`, `review_type`, `school`, `school_other`, `payment_proof`,
   `email`, `password`, `role`, `status`, `created_at`, `updated_at`)
VALUES
  (1, 'System Administrator', 'reviewee', '', NULL, NULL,
   'admin@ereview.ph', 'admin123', 'admin', 'approved',
   NOW(), NOW())
ON DUPLICATE KEY UPDATE `user_id` = `user_id`;

-- ================== LMS MIGRATIONS ==================
-- Email verification + pending registrations
ALTER TABLE `users`
  ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`;

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

-- Last login tracking
ALTER TABLE `users` ADD COLUMN `last_login_at` datetime DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `last_login_ip` varchar(45) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `last_login_user_agent` varchar(500) DEFAULT NULL;

-- Password reset tokens
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

-- Remember-me tokens
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

-- Login attempts (rate limiting)
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL COMMENT 'IPv4 or IPv6',
  `attempt_count` int(11) NOT NULL DEFAULT 0,
  `first_attempt_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`),
  KEY `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Performance indexes (safe to re‑run)
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
CREATE INDEX idx_handout_annotations_composite
  ON handout_annotations(handout_id, student_id);

CREATE INDEX idx_quizzes_subject_id ON quizzes(subject_id);
CREATE INDEX idx_quizzes_quiz_type ON quizzes(quiz_type);

CREATE INDEX idx_quiz_questions_quiz_id ON quiz_questions(quiz_id);
CREATE INDEX idx_quiz_questions_question_id ON quiz_questions(question_id);

CREATE INDEX idx_quiz_answers_user_id ON quiz_answers(user_id);
CREATE INDEX idx_quiz_answers_question_id ON quiz_answers(question_id);
CREATE INDEX idx_quiz_answers_composite
  ON quiz_answers(user_id, question_id);

-- --------------- INVENTORY DATABASE ---------------

CREATE DATABASE IF NOT EXISTS `inventory_db`;
USE `inventory_db`;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `products` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `category_id` int NOT NULL,
  `sku` varchar(50) NOT NULL UNIQUE,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `cost_price` decimal(12,2) DEFAULT 0.00,
  `selling_price` decimal(12,2) DEFAULT 0.00,
  `quantity` int NOT NULL DEFAULT 0,
  `reorder_level` int NOT NULL DEFAULT 5,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `products_fk_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`)
    ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS `stock_log` (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `product_id` int NOT NULL,
  `type` enum('in','out','adjust') NOT NULL,
  `quantity` int NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `stock_log_fk_product`
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
    ON DELETE CASCADE
);

INSERT INTO categories (name, description) VALUES
('Writing Instruments', 'Pens, pencils, markers, highlighters'),
('Paper & Notebooks', 'Bond paper, notebooks, pads, sticky notes'),
('Filing & Organization', 'Folders, binders, envelopes, clips'),
('Desk Supplies', 'Staplers, tape, scissors, hole punch'),
('Cleaning & Breakroom', 'Tissue, soap, trash bags')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- =====================================================
-- Full setup complete.
-- Default LMS admin:
--   Email:    admin@ereview.ph
--   Password: admin123
-- =====================================================

