-- Regular examination audience / assignment (mirrors diagnostic_batches 042 pattern)

ALTER TABLE `college_exams`
  ADD COLUMN IF NOT EXISTS `examinee_scope` ENUM('college_student','reviewee','both') NOT NULL DEFAULT 'college_student' AFTER `is_published`,
  ADD COLUMN IF NOT EXISTS `assignment_mode` ENUM('all','sections','users','sections_and_users') NOT NULL DEFAULT 'all' AFTER `examinee_scope`;

UPDATE `college_exams`
SET `examinee_scope` = 'college_student',
    `assignment_mode` = 'all'
WHERE `examinee_scope` IS NULL OR `assignment_mode` IS NULL;

CREATE TABLE IF NOT EXISTS `college_exam_sections` (
  `exam_section_id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `section_value` varchar(100) NOT NULL,
  PRIMARY KEY (`exam_section_id`),
  UNIQUE KEY `uq_college_exam_section` (`exam_id`,`section_value`),
  KEY `idx_ces_exam` (`exam_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `college_exam_users` (
  `exam_user_id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`exam_user_id`),
  UNIQUE KEY `uq_college_exam_user` (`exam_id`,`user_id`),
  KEY `idx_ceu_exam` (`exam_id`),
  KEY `idx_ceu_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
