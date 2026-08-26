-- =====================================================
-- 039 - Student gamification Phase 1 (profile + events + achievements)
-- Additive only. Safe to re-run: CREATE TABLE IF NOT EXISTS.
--
-- DO NOT confuse with Playground session points (student_playground_*).
-- Career XP / calendar streak / achievement unlocks only.
-- Level and rank are derived from total_xp in application code.
-- Achievement catalog lives in PHP config (no catalog table in Phase 1).
-- =====================================================

CREATE TABLE IF NOT EXISTS `student_gamification_profile` (
  `user_id` INT(11) NOT NULL,
  `total_xp` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `current_streak_days` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `longest_streak_days` INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `last_qualifying_activity_date` DATE DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_sgp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_gamification_events` (
  `event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `source_table` VARCHAR(64) NOT NULL,
  `source_id` BIGINT UNSIGNED NOT NULL,
  `xp_delta` INT(11) NOT NULL DEFAULT 0,
  `meta_json` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `uq_sge_idempotent` (`event_type`, `source_table`, `source_id`, `user_id`),
  KEY `idx_sge_user_created` (`user_id`, `created_at`),
  KEY `idx_sge_type_created` (`event_type`, `created_at`),
  CONSTRAINT `fk_sge_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `student_achievements` (
  `user_id` INT(11) NOT NULL,
  `achievement_key` VARCHAR(64) NOT NULL,
  `unlocked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `achievement_key`),
  KEY `idx_sa_user_unlocked` (`user_id`, `unlocked_at`),
  CONSTRAINT `fk_sa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
