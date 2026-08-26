-- DOWN migration for 040 (seasons + leaderboard indexes)

DROP TABLE IF EXISTS `student_gamification_seasons`;

SET @idx_sgp := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'student_gamification_profile'
    AND INDEX_NAME = 'idx_sgp_total_xp_user'
);
SET @sql_sgp := IF(
  @idx_sgp > 0,
  'ALTER TABLE `student_gamification_profile` DROP INDEX `idx_sgp_total_xp_user`',
  'SELECT 1'
);
PREPARE stmt_sgp FROM @sql_sgp;
EXECUTE stmt_sgp;
DEALLOCATE PREPARE stmt_sgp;

SET @idx_sge := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'student_gamification_events'
    AND INDEX_NAME = 'idx_sge_created_user'
);
SET @sql_sge := IF(
  @idx_sge > 0,
  'ALTER TABLE `student_gamification_events` DROP INDEX `idx_sge_created_user`',
  'SELECT 1'
);
PREPARE stmt_sge FROM @sql_sge;
EXECUTE stmt_sge;
DEALLOCATE PREPARE stmt_sge;
