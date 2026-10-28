-- School directory archive / 30-day purge eligibility.
-- Prerequisite: canonical 055 directory schema. college_schools must exist AND
-- college_schools.status must exist. A 053 CREATE-only slim table is not enough.
-- If either check fails, this file does not create college_school_archive_log
-- and does not ALTER. Apply 055 first.
-- Does NOT copy, delete, or rewrite users.school values.
-- Archived rows stay in college_schools (unique name retained) until an explicit purge.
-- Column adds use information_schema (this MySQL does not support ADD COLUMN IF NOT EXISTS).
-- Columns are added without AFTER so this does not depend on updated_by existing.
-- Independent of committed migration 058 (exam/batch archived_at is a different table).

SET @t := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools'
);
SET @s := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'status'
);
SET @ready := IF(@t > 0 AND @s > 0, 1, 0);

SET @sql := IF(
  @ready = 0,
  'SELECT ''056 skipped: college_schools or college_schools.status missing. Archive log not created. Apply 055 first.'' AS ereview_056_requires_055',
  'CREATE TABLE IF NOT EXISTS `college_school_archive_log` (
  `log_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `school_id` int(11) DEFAULT NULL,
  `school_name` varchar(150) NOT NULL,
  `actor_user_id` int(11) NOT NULL,
  `action` varchar(32) NOT NULL,
  `detail` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_csal2_school` (`school_id`,`created_at`),
  KEY `idx_csal2_action` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'archived_at');
SET @sql := IF(@ready = 1 AND @c = 0, 'ALTER TABLE `college_schools` ADD COLUMN `archived_at` datetime DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'archived_by');
SET @sql := IF(@ready = 1 AND @c = 0, 'ALTER TABLE `college_schools` ADD COLUMN `archived_by` int(11) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'purge_eligible_at');
SET @sql := IF(@ready = 1 AND @c = 0, 'ALTER TABLE `college_schools` ADD COLUMN `purge_eligible_at` datetime DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND INDEX_NAME = 'idx_college_schools_archived');
SET @sql := IF(@ready = 1 AND @c = 0, 'ALTER TABLE `college_schools` ADD KEY `idx_college_schools_archived` (`archived_at`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
