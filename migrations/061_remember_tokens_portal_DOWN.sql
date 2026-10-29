-- Rollback for 061_remember_tokens_portal.sql.

ALTER TABLE `remember_tokens` DROP COLUMN `portal`;
