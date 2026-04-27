-- Optional profile fields for the account page. Run once; ignore Duplicate column if re-applying.
ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(40) NULL DEFAULT NULL AFTER `email`;
ALTER TABLE `users` ADD COLUMN `profile_bio` VARCHAR(500) NULL DEFAULT NULL AFTER `phone`;
