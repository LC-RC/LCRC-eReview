-- Reverse 053 CREATE-only objects.
-- NEVER drops or alters users.school.
-- Does NOT drop users.college_school_assigned_* or college_school_id (055 owns those).
--
-- MANUAL PRECONDITION: do not run this file if 055 or 056 has been applied.
-- Evidence that blocks DROP (any count > 0):
--   college_schools.status / updated_at / updated_by / archived_at
--   users.college_school_id
--   table college_school_archive_log
--
-- This MySQL has no portable guaranteed abort (SIGNAL in PREPARE is not relied on).
-- Guard: DROP runs only when @block = 0. If blocked, this file is a no-op besides
-- a one-row SELECT named ereview_053_down_skipped. Client exit code may still be 0.
-- That is intentional: never silently DROP catalog tables that 055/056 extended.
--
-- Leftover from an older 053 that used ADD COLUMN IF NOT EXISTS on users:
-- assigned_at/by may remain after this DOWN. Remove those only via 055 DOWN
-- or a manual ALTER after confirming college_school_id and status are absent.

SET @block :=
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'status')
  + (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'updated_at')
  + (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'updated_by')
  + (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_schools' AND COLUMN_NAME = 'archived_at')
  + (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'college_school_id')
  + (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'college_school_archive_log');

SET @sql := IF(
  @block > 0,
  'SELECT ''053 DOWN skipped: 055/056 schema or users.college_school_id present. college_schools not dropped.'' AS ereview_053_down_skipped',
  'DROP TABLE IF EXISTS `college_school_assignment_log`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  @block > 0,
  'SELECT 1',
  'DROP TABLE IF EXISTS `college_schools`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
