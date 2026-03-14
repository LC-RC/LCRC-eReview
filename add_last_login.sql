-- Add last login tracking to users table.
-- Run once. If you get "Duplicate column", columns already exist.
ALTER TABLE `users` ADD COLUMN `last_login_at` datetime DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `last_login_ip` varchar(45) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `last_login_user_agent` varchar(500) DEFAULT NULL;
