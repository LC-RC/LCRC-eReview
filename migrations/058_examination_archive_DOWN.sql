-- Reverse 058. Does not drop exams, attempts, answers, or questions.

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_exams' AND INDEX_NAME = 'idx_college_exams_archived');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_exams` DROP KEY `idx_college_exams_archived`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_exams' AND COLUMN_NAME = 'archived_was_published');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_exams` DROP COLUMN `archived_was_published`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_exams' AND COLUMN_NAME = 'archived_by');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_exams` DROP COLUMN `archived_by`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_exams' AND COLUMN_NAME = 'archived_at');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_exams` DROP COLUMN `archived_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diagnostic_batches' AND INDEX_NAME = 'idx_diagnostic_batches_archived');
SET @sql := IF(@c > 0, 'ALTER TABLE `diagnostic_batches` DROP KEY `idx_diagnostic_batches_archived`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diagnostic_batches' AND COLUMN_NAME = 'archived_was_published');
SET @sql := IF(@c > 0, 'ALTER TABLE `diagnostic_batches` DROP COLUMN `archived_was_published`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diagnostic_batches' AND COLUMN_NAME = 'archived_by');
SET @sql := IF(@c > 0, 'ALTER TABLE `diagnostic_batches` DROP COLUMN `archived_by`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diagnostic_batches' AND COLUMN_NAME = 'archived_at');
SET @sql := IF(@c > 0, 'ALTER TABLE `diagnostic_batches` DROP COLUMN `archived_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS `examination_archive_log`;
