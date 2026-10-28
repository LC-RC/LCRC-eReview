-- Reverse 049 extras that this SQL file owns: drop college_student_number_seq.
-- UNIQUE rollback is best-effort and guarded (this MySQL has no DROP INDEX IF EXISTS).
--
-- Risks:
-- - Drops uq_users_student_number only if that exact index name exists.
--   If UNIQUE was created under another name, it remains.
-- - Restores idx_users_student_number (prefix 16) only if NO index remains on
--   users.student_number. If a unique or other index remains, the prefix KEY
--   is not added (avoids duplicate-index errors).
-- - Does not drop users.student_number (column predates 049).
-- - Sequence last_n values are destroyed with the table. Allocated numbers
--   on users.student_number are not cleared.
--
-- Prefer restoring a backup if UNIQUE must be removed on a live copy.
-- Independent of committed migration 058.

SET @c := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'uq_users_student_number'
);
SET @sql := IF(@c > 0, 'ALTER TABLE `users` DROP INDEX `uq_users_student_number`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'student_number'
);
SET @sql := IF(
  @c = 0,
  'ALTER TABLE `users` ADD KEY `idx_users_student_number` (`student_number` (16))',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS `college_student_number_seq`;
