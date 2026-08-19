-- College Examination platform access (separate from eReview access_grants).
-- Default none: existing role='student' eReview users are unchanged.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `college_examination_access` ENUM('none','active','suspended') NOT NULL DEFAULT 'none' AFTER `student_number`,
  ADD COLUMN IF NOT EXISTS `college_examination_enabled_at` DATETIME NULL AFTER `college_examination_access`,
  ADD COLUMN IF NOT EXISTS `college_examination_enabled_by` INT NULL AFTER `college_examination_enabled_at`;

-- Legacy examination-only accounts (do not touch role='student' rows).
UPDATE `users`
SET `college_examination_access` = 'active'
WHERE `role` = 'college_student'
  AND (`college_examination_access` IS NULL OR `college_examination_access` = 'none');
