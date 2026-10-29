-- Remember-me portal scope (lms | college).
-- Existing rows default to lms. Safe to re-run.
--
-- PRECHECK:
--   SHOW COLUMNS FROM remember_tokens LIKE 'portal';
--
-- Prerequisite for scoped College vs LMS remember-me.
-- Do not ALTER remember_tokens from request-time PHP.

USE `ereview`;

DELIMITER //
DROP PROCEDURE IF EXISTS add_remember_tokens_portal_col//
CREATE PROCEDURE add_remember_tokens_portal_col()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'remember_tokens' AND COLUMN_NAME = 'portal'
  ) THEN
    ALTER TABLE `remember_tokens`
      ADD COLUMN `portal` varchar(16) NOT NULL DEFAULT 'lms';
  END IF;
END//
DELIMITER ;

CALL add_remember_tokens_portal_col();
DROP PROCEDURE IF EXISTS add_remember_tokens_portal_col;
