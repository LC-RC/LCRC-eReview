-- Rollback for 059_college_password_reset_tokens.sql.
-- Drops only the College reset-token table. Does not touch LMS password_reset_tokens.

DROP TABLE IF EXISTS `college_password_reset_tokens`;
