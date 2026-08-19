DROP TABLE IF EXISTS `college_exam_users`;
DROP TABLE IF EXISTS `college_exam_sections`;

ALTER TABLE `college_exams`
  DROP COLUMN IF EXISTS `assignment_mode`,
  DROP COLUMN IF EXISTS `examinee_scope`;
