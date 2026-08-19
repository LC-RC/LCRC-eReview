-- Rollback diagnostic audience extension

DROP TABLE IF EXISTS `diagnostic_batch_users`;

ALTER TABLE `diagnostic_batches`
  DROP COLUMN IF EXISTS `assignment_mode`,
  DROP COLUMN IF EXISTS `examinee_scope`,
  DROP COLUMN IF EXISTS `exam_type`;
