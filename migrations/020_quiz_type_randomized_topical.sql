-- Add modern quiz type classification for quizzers pages
-- Safe to re-run.

ALTER TABLE `quizzes`
  MODIFY COLUMN `quiz_type` ENUM('randomized','topical','pre-test','post-test','mock') NOT NULL DEFAULT 'randomized';

UPDATE `quizzes`
SET `quiz_type` = CASE
  WHEN LOWER(TRIM(COALESCE(`quiz_type`, ''))) = 'topical' THEN 'topical'
  WHEN LOWER(TRIM(COALESCE(`quiz_type`, ''))) IN ('pre-test','post-test','mock','', 'randomized') THEN 'randomized'
  ELSE 'randomized'
END;

ALTER TABLE `quizzes`
  MODIFY COLUMN `quiz_type` ENUM('randomized','topical') NOT NULL DEFAULT 'randomized';
