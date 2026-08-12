-- CPA Playground: total exam timer + play style + navigation cursor.
-- Prefer running via app ensure_schema(); use these ALTERs if applying manually.
-- Skip any statement that errors with "Duplicate column".

ALTER TABLE `student_playground_sessions`
  ADD COLUMN `play_style` ENUM('playground','practice_exam') NOT NULL DEFAULT 'playground' AFTER `mode`;

ALTER TABLE `student_playground_sessions`
  ADD COLUMN `total_time_seconds` INT(11) NOT NULL DEFAULT 600 AFTER `seconds_per_question`;

ALTER TABLE `student_playground_sessions`
  ADD COLUMN `ends_at` DATETIME DEFAULT NULL AFTER `started_at`;

ALTER TABLE `student_playground_sessions`
  ADD COLUMN `current_ordinal` INT(11) NOT NULL DEFAULT 1 AFTER `answered_count`;
