-- Add metadata columns to support remembered device management.
-- Safe to re-run: adds each column only if missing (MySQL 8.0 compatible).
USE `ereview`;

DELIMITER //
DROP PROCEDURE IF EXISTS add_remember_token_metadata_cols//
CREATE PROCEDURE add_remember_token_metadata_cols()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'remember_tokens' AND COLUMN_NAME = 'last_used_at'
  ) THEN
    ALTER TABLE `remember_tokens` ADD COLUMN `last_used_at` datetime NULL AFTER `created_at`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'remember_tokens' AND COLUMN_NAME = 'last_used_ip'
  ) THEN
    ALTER TABLE `remember_tokens` ADD COLUMN `last_used_ip` varchar(45) DEFAULT NULL AFTER `last_used_at`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'remember_tokens' AND COLUMN_NAME = 'last_used_user_agent'
  ) THEN
    ALTER TABLE `remember_tokens` ADD COLUMN `last_used_user_agent` varchar(500) DEFAULT NULL AFTER `last_used_ip`;
  END IF;
END//
DELIMITER ;

CALL add_remember_token_metadata_cols();
DROP PROCEDURE IF EXISTS add_remember_token_metadata_cols;
