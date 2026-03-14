-- Add allow_download toggle column for lesson handouts
ALTER TABLE `lesson_handouts`
  ADD COLUMN `allow_download` TINYINT(1) NOT NULL DEFAULT 1 AFTER `file_size`;




