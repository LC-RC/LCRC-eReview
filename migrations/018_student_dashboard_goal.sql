-- Optional weekly submission goal for student dashboard (0 = off)
ALTER TABLE `users`
  ADD COLUMN `weekly_activity_goal` TINYINT UNSIGNED NOT NULL DEFAULT 0
  COMMENT 'Target quiz+preboard submissions per ISO week; 0 means unset';
