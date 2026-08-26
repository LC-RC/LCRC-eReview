-- =====================================================
-- 040 - Student gamification Phase 3 (seasons + leaderboard indexes)
-- Additive only. Safe to re-run: CREATE TABLE IF NOT EXISTS.
-- Does NOT modify migration 039 tables beyond additive indexes.
-- =====================================================

CREATE TABLE IF NOT EXISTS `student_gamification_seasons` (
  `season_id` INT(11) NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(32) NOT NULL,
  `title` VARCHAR(120) NOT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `status` ENUM('scheduled','active','closed','archived') NOT NULL DEFAULT 'scheduled',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`season_id`),
  UNIQUE KEY `uq_sgs_slug` (`slug`),
  KEY `idx_sgs_status_starts` (`status`, `starts_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Performance indexes for leaderboard queries (additive).
-- Ignore duplicate-index errors if re-run manually after partial apply.

SET @idx_sgp := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'student_gamification_profile'
    AND INDEX_NAME = 'idx_sgp_total_xp_user'
);
SET @sql_sgp := IF(
  @idx_sgp = 0,
  'ALTER TABLE `student_gamification_profile` ADD INDEX `idx_sgp_total_xp_user` (`total_xp` DESC, `user_id` ASC)',
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
  @idx_sge = 0,
  'ALTER TABLE `student_gamification_events` ADD INDEX `idx_sge_created_user` (`created_at`, `user_id`)',
  'SELECT 1'
);
PREPARE stmt_sge FROM @sql_sge;
EXECUTE stmt_sge;
DEALLOCATE PREPARE stmt_sge;

-- MVP seed: one active season (August 2026, Asia/Manila convention).
INSERT INTO `student_gamification_seasons` (`slug`, `title`, `starts_at`, `ends_at`, `status`)
SELECT '2026-08', 'Season 1 — August 2026', '2026-08-01 00:00:00', '2026-09-01 00:00:00', 'active'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `student_gamification_seasons` WHERE `slug` = '2026-08'
);
