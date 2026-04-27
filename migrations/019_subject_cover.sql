-- Subject card cover image (admin upload; shown on student_subjects cards)
ALTER TABLE `subjects`
  ADD COLUMN `subject_cover` VARCHAR(512) NULL DEFAULT NULL
  AFTER `description`;
