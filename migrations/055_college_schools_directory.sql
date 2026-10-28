-- Canonical College Examination school directory + assignment schema.
-- Optional predecessor: 053_college_school_assignment.sql (CREATE-only slim
-- college_schools + assignment log). If 053 already created those tables,
-- CREATE IF NOT EXISTS below is a no-op and the PREPARE ALTERs add directory
-- extras and users assignment columns. If 053 was skipped, this CREATE defines
-- the full directory table including status and updated_*.
--
-- Adds: college_schools.status, updated_at, updated_by, idx_college_schools_status;
-- users.college_school_assigned_at, college_school_assigned_by, college_school_id.
-- Does NOT copy, delete, or overwrite existing users.school text.
-- Unmatched legacy school strings remain on users.school.
-- Do not drop unique names. Similar labels are not merged.
-- Column adds use information_schema (this MySQL does not support ADD COLUMN IF NOT EXISTS).
-- Independent of committed migration 058 (examination archive on exams/batches).
-- Apply 056 after this file for school archive columns.

CREATE TABLE IF NOT EXISTS `college_schools` (
  `school_id` int(11) NOT NULL AUTO_INCREMENT,
  `school_name` varchar(150) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`school_id`),
  UNIQUE KEY `uq_college_schools_name` (`school_name`),
  KEY `idx_college_schools_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `college_school_assignment_log` (
  `log_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `actor_user_id` int(11) NOT NULL,
  `action` varchar(32) NOT NULL,
  `prior_school` varchar(150) DEFAULT NULL,
  `new_school` varchar(150) DEFAULT NULL,
  `prior_assigned_at` datetime DEFAULT NULL,
  `prior_assigned_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_csal_user` (`user_id`,`created_at`),
  KEY `idx_csal_actor` (`actor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'status');
SET @sql := IF(@c = 0, 'ALTER TABLE `college_schools` ADD COLUMN `status` enum(''active'',''inactive'') NOT NULL DEFAULT ''active'' AFTER `school_name`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `college_schools` ADD COLUMN `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_by`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'updated_by');
SET @sql := IF(@c = 0, 'ALTER TABLE `college_schools` ADD COLUMN `updated_by` int(11) DEFAULT NULL AFTER `updated_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND INDEX_NAME = 'idx_college_schools_status');
SET @sql := IF(@c = 0, 'ALTER TABLE `college_schools` ADD KEY `idx_college_schools_status` (`status`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'college_school_assigned_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `college_school_assigned_at` datetime DEFAULT NULL AFTER `school`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'college_school_assigned_by');
SET @sql := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `college_school_assigned_by` int(11) DEFAULT NULL AFTER `college_school_assigned_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'college_school_id');
SET @sql := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `college_school_id` int(11) DEFAULT NULL AFTER `college_school_assigned_by`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
