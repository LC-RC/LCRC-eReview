ALTER TABLE `users`
  DROP COLUMN IF EXISTS `college_examination_enabled_by`,
  DROP COLUMN IF EXISTS `college_examination_enabled_at`,
  DROP COLUMN IF EXISTS `college_examination_access`;
