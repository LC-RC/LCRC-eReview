-- Upload task targeting, scheduling, resubmission, and submission history.
-- Run after 045_college_sections.sql

ALTER TABLE `college_upload_tasks`
  ADD COLUMN `open_at` datetime DEFAULT NULL AFTER `instructions`,
  ADD COLUMN `examinee_scope` varchar(32) NOT NULL DEFAULT 'college_student' AFTER `is_open`,
  ADD COLUMN `assignment_mode` varchar(32) NOT NULL DEFAULT 'all' AFTER `examinee_scope`,
  ADD COLUMN `resubmission_policy` varchar(32) NOT NULL DEFAULT 'disabled' AFTER `assignment_mode`;

CREATE TABLE IF NOT EXISTS `college_upload_task_sections` (
  `task_section_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `section_value` varchar(100) NOT NULL,
  PRIMARY KEY (`task_section_id`),
  UNIQUE KEY `uq_upload_task_section` (`task_id`,`section_value`),
  KEY `idx_uts_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `college_submissions`
  ADD COLUMN `submission_number` int(11) NOT NULL DEFAULT 1 AFTER `user_id`,
  ADD COLUMN `is_latest` tinyint(1) NOT NULL DEFAULT 1 AFTER `status`,
  ADD COLUMN `review_status` varchar(32) NOT NULL DEFAULT 'submitted' AFTER `is_latest`;

ALTER TABLE `college_submissions` DROP INDEX `uq_college_submission_task_user`;

ALTER TABLE `college_submissions`
  ADD KEY `idx_cs_task_user_latest` (`task_id`,`user_id`,`is_latest`);

CREATE TABLE IF NOT EXISTS `college_upload_resubmission_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `requested_by` int(11) NOT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`request_id`),
  KEY `idx_resub_task_user` (`task_id`,`user_id`),
  KEY `idx_resub_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `college_submissions` SET `submission_number` = 1, `is_latest` = 1, `review_status` = COALESCE(NULLIF(TRIM(`review_status`), ''), 'submitted');
