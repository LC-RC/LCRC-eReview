-- Online presence columns for live session indicator in admin_students.php
-- Run once on DB:
--   mysql -u <user> -p <database> < add_online_presence_columns.sql

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_online` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_login_user_agent`,
  ADD COLUMN IF NOT EXISTS `last_seen_at` DATETIME NULL AFTER `is_online`,
  ADD COLUMN IF NOT EXISTS `last_logout_at` DATETIME NULL AFTER `last_seen_at`;

