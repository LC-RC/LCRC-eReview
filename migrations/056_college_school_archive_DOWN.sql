-- Reverse 056 extras (archive columns/index + college_school_archive_log).
-- Matches 056 UP: drops by column/index name, not by AFTER position.
-- Does not drop college_schools or modify users.school.
-- Independent of committed migration 058.

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND INDEX_NAME = 'idx_college_schools_archived');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_schools` DROP KEY `idx_college_schools_archived`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'purge_eligible_at');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_schools` DROP COLUMN `purge_eligible_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'archived_by');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_schools` DROP COLUMN `archived_by`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'archived_at');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_schools` DROP COLUMN `archived_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS `college_school_archive_log`;
