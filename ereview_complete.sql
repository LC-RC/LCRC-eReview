-- =====================================================
-- LCRC eReview LMS - Complete Database Setup
-- =====================================================
-- This file contains the complete database schema,
-- tables, indexes, and sample data for the LMS system.
-- Just copy and paste this entire file in phpMyAdmin.
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ereview`
-- Create database if not exists
--
CREATE DATABASE IF NOT EXISTS `ereview` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `ereview`;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--
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

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--
CREATE TABLE IF NOT EXISTS `subjects` (
  `subject_id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  PRIMARY KEY (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lessons`
--
CREATE TABLE IF NOT EXISTS `lessons` (
  `lesson_id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`lesson_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `lessons_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lesson_videos`
--
CREATE TABLE IF NOT EXISTS `lesson_videos` (
  `video_id` int(11) NOT NULL AUTO_INCREMENT,
  `lesson_id` int(11) NOT NULL,
  `video_title` varchar(200) DEFAULT NULL,
  `video_url` text NOT NULL,
  PRIMARY KEY (`video_id`),
  KEY `lesson_id` (`lesson_id`),
  CONSTRAINT `lesson_videos_ibfk_1` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`lesson_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lesson_handouts`
--
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

-- --------------------------------------------------------

--
-- Table structure for table `handout_annotations`
--
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

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--
CREATE TABLE IF NOT EXISTS `quizzes` (
  `quiz_id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `quiz_type` enum('pre-test','post-test','mock') NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`quiz_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `quizzes_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_questions`
--
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

-- --------------------------------------------------------

--
-- Table structure for table `quiz_answers`
--
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

-- --------------------------------------------------------

--
-- Dumping data for table `users`
--
INSERT INTO `users` (`user_id`, `full_name`, `review_type`, `school`, `school_other`, `payment_proof`, `email`, `password`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'System Administrator', 'reviewee', '', NULL, NULL, 'admin@ereview.ph', 'admin123', 'admin', 'approved', '2025-10-28 05:56:57', '2025-10-28 05:56:57'),
(2, 'Kezeah', 'reviewee', 'Other', 'Laguna University', 'uploads/proof_69005cbf305435.69039989.png', 'tkezeahmae@gmail.com', 'kezeahmae', 'student', 'pending', '2025-10-28 06:03:43', '2025-10-28 06:03:43')
ON DUPLICATE KEY UPDATE `user_id`=`user_id`;

-- --------------------------------------------------------

--
-- Performance Indexes for Optimization
-- These indexes help the LMS handle large amounts of data efficiently
--

-- Indexes for users table
-- Note: If index already exists, you may get an error. Just ignore it or drop the index first.
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_created_at ON users(created_at);
CREATE INDEX idx_users_role_status ON users(role, status);

-- Indexes for subjects table
CREATE INDEX idx_subjects_status ON subjects(status);
CREATE INDEX idx_subjects_name ON subjects(subject_name);

-- Indexes for lessons table
CREATE INDEX idx_lessons_subject_id ON lessons(subject_id);

-- Indexes for lesson_videos table
CREATE INDEX idx_lesson_videos_lesson_id ON lesson_videos(lesson_id);

-- Indexes for lesson_handouts table
CREATE INDEX idx_lesson_handouts_lesson_id ON lesson_handouts(lesson_id);
CREATE INDEX idx_lesson_handouts_handout_id ON lesson_handouts(handout_id);

-- Indexes for handout_annotations table
CREATE INDEX idx_handout_annotations_handout_id ON handout_annotations(handout_id);
CREATE INDEX idx_handout_annotations_student_id ON handout_annotations(student_id);
CREATE INDEX idx_handout_annotations_composite ON handout_annotations(handout_id, student_id);

-- Indexes for quizzes table
CREATE INDEX idx_quizzes_subject_id ON quizzes(subject_id);
CREATE INDEX idx_quizzes_quiz_type ON quizzes(quiz_type);

-- Indexes for quiz_questions table
CREATE INDEX idx_quiz_questions_quiz_id ON quiz_questions(quiz_id);
CREATE INDEX idx_quiz_questions_question_id ON quiz_questions(question_id);

-- Indexes for quiz_answers table
CREATE INDEX idx_quiz_answers_user_id ON quiz_answers(user_id);
CREATE INDEX idx_quiz_answers_question_id ON quiz_answers(question_id);
CREATE INDEX idx_quiz_answers_composite ON quiz_answers(user_id, question_id);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- =====================================================
-- Setup Complete!
-- =====================================================
-- Default Admin Login:
-- Email: admin@ereview.ph
-- Password: admin123
-- =====================================================
