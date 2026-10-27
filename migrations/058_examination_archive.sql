-- Examination archive metadata for regular (college_exams) and diagnostic (diagnostic_batches).
-- Does not change attempts, answers, scores, or questions.
-- Does not add foreign keys. Do not add ON DELETE CASCADE for academic attempts.
-- Existing exams remain active (archived_at NULL).
-- Apply on a copy first. Do not run from a page GET.
--
-- PRECHECK:
--   SHOW COLUMNS FROM college_exams LIKE 'archived_at';
--   SHOW COLUMNS FROM diagnostic_batches LIKE 'archived_at';

CREATE TABLE IF NOT EXISTS `examination_archive_log` (
  `log_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `exam_type` varchar(32) NOT NULL,
  `source_id` int(11) NOT NULL,
  `actor_user_id` int(11) NOT NULL,
  `action` varchar(32) NOT NULL,
  `detail` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_eal_exam` (`exam_type`,`source_id`,`created_at`),
  KEY `idx_eal_action` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_exams' AND COLUMN_NAME = 'archived_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `college_exams` ADD COLUMN `archived_at` datetime DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_exams' AND COLUMN_NAME = 'archived_by');
SET @sql := IF(@c = 0, 'ALTER TABLE `college_exams` ADD COLUMN `archived_by` int(11) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_exams' AND COLUMN_NAME = 'archived_was_published');
SET @sql := IF(@c = 0, 'ALTER TABLE `college_exams` ADD COLUMN `archived_was_published` tinyint(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_exams' AND INDEX_NAME = 'idx_college_exams_archived');
SET @sql := IF(@c = 0, 'ALTER TABLE `college_exams` ADD KEY `idx_college_exams_archived` (`archived_at`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diagnostic_batches' AND COLUMN_NAME = 'archived_at');
SET @sql := IF(@c = 0, 'ALTER TABLE `diagnostic_batches` ADD COLUMN `archived_at` datetime DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diagnostic_batches' AND COLUMN_NAME = 'archived_by');
SET @sql := IF(@c = 0, 'ALTER TABLE `diagnostic_batches` ADD COLUMN `archived_by` int(11) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diagnostic_batches' AND COLUMN_NAME = 'archived_was_published');
SET @sql := IF(@c = 0, 'ALTER TABLE `diagnostic_batches` ADD COLUMN `archived_was_published` tinyint(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diagnostic_batches' AND INDEX_NAME = 'idx_diagnostic_batches_archived');
SET @sql := IF(@c = 0, 'ALTER TABLE `diagnostic_batches` ADD KEY `idx_diagnostic_batches_archived` (`archived_at`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
