-- Diagnostic Exam audience / assignment (Examination module only)
-- Extends diagnostic_batches; adds explicit user assignments.

ALTER TABLE `diagnostic_batches`
  ADD COLUMN IF NOT EXISTS `exam_type` ENUM('diagnostic') NOT NULL DEFAULT 'diagnostic' AFTER `batch_id`,
  ADD COLUMN IF NOT EXISTS `examinee_scope` ENUM('college_student','reviewee','both') NOT NULL DEFAULT 'college_student' AFTER `exam_type`,
  ADD COLUMN IF NOT EXISTS `assignment_mode` ENUM('all','sections','users','sections_and_users') NOT NULL DEFAULT 'sections' AFTER `examinee_scope`;

CREATE TABLE IF NOT EXISTS `diagnostic_batch_users` (
  `batch_user_id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`batch_user_id`),
  UNIQUE KEY `uq_diagnostic_batch_user` (`batch_id`,`user_id`),
  KEY `idx_dbu_batch` (`batch_id`),
  KEY `idx_dbu_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
