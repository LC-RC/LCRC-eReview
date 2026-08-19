DROP TABLE IF EXISTS `college_upload_resubmission_requests`;
DROP TABLE IF EXISTS `college_upload_task_sections`;

ALTER TABLE `college_submissions`
  DROP COLUMN IF EXISTS `review_status`,
  DROP COLUMN IF EXISTS `is_latest`,
  DROP COLUMN IF EXISTS `submission_number`;

ALTER TABLE `college_upload_tasks`
  DROP COLUMN IF EXISTS `resubmission_policy`,
  DROP COLUMN IF EXISTS `assignment_mode`,
  DROP COLUMN IF EXISTS `examinee_scope`,
  DROP COLUMN IF EXISTS `open_at`;
