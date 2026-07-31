-- =====================================================
-- 027 - Enrollment selection fields (Phase 4)
-- Additive only. Stores enrollment_path + catalog selection
-- on pending_registrations and users for post-verify flow.
-- Does NOT create payments or fulfill access.
-- =====================================================
USE `ereview`;

-- pending_registrations
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pending_registrations' AND COLUMN_NAME = 'enrollment_path');
SET @sql := IF(@c = 0,
  'ALTER TABLE `pending_registrations`
     ADD COLUMN `enrollment_path` ENUM(''package'',''by_topic'',''free_access'') NULL DEFAULT NULL AFTER `review_type`,
     ADD COLUMN `selected_package_id` INT NULL DEFAULT NULL AFTER `enrollment_path`,
     ADD COLUMN `selected_lesson_ids_json` JSON NULL AFTER `selected_package_id`,
     ADD COLUMN `free_access_note` TEXT NULL AFTER `selected_lesson_ids_json`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users selection staging (paid checkout later; free_access uses free_access_requests)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'selected_package_id');
SET @sql := IF(@c = 0,
  'ALTER TABLE `users`
     ADD COLUMN `selected_package_id` INT NULL DEFAULT NULL AFTER `enrollment_path`,
     ADD COLUMN `selected_lesson_ids_json` JSON NULL AFTER `selected_package_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
