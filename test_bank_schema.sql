-- Test Bank: review materials (question file + solution file) for students
-- Run this once to create the table and uploads folder is created by PHP if missing.

CREATE TABLE IF NOT EXISTS `test_bank` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `description` text,
  `question_file_path` varchar(512) NOT NULL DEFAULT '',
  `question_file_name` varchar(255) NOT NULL DEFAULT '',
  `solution_file_path` varchar(512) NOT NULL DEFAULT '',
  `solution_file_name` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
