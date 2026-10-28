-- Reverse 055 extras only.
-- Does NOT drop college_schools or college_school_assignment_log (053/055 CREATE).
-- Does NOT modify users.school values.
--
-- LOSSY: removes assignment metadata (college_school_id, assigned_at, assigned_by).
-- Staff-assignment timestamps are not restored. Directory status/updated_* columns
-- are removed; catalog rows remain in college_schools.
-- Run 056 DOWN first if archive columns exist.
-- Independent of committed migration 058.
-- Client exit code may still be 0 after this file; read ereview_055_down_lossy.

SELECT '055 DOWN is LOSSY: will drop users.college_school_id, college_school_assigned_at, and college_school_assigned_by if those columns exist, then directory status/updated_* on college_schools if present. Catalog tables, assignment log, and users.school are not dropped. Assignment timestamps are not restored.' AS ereview_055_down_lossy;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'college_school_id');
SET @sql := IF(@c > 0, 'ALTER TABLE `users` DROP COLUMN `college_school_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'college_school_assigned_by');
SET @sql := IF(@c > 0, 'ALTER TABLE `users` DROP COLUMN `college_school_assigned_by`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'college_school_assigned_at');
SET @sql := IF(@c > 0, 'ALTER TABLE `users` DROP COLUMN `college_school_assigned_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND INDEX_NAME = 'idx_college_schools_status');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_schools` DROP KEY `idx_college_schools_status`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'updated_by');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_schools` DROP COLUMN `updated_by`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_schools` DROP COLUMN `updated_at`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'status');
SET @sql := IF(@c > 0, 'ALTER TABLE `college_schools` DROP COLUMN `status`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
