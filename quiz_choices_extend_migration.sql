-- Migration: support more than 4 choices per question (A through J = 10 max).
-- Run once. If you re-run, ignore "Duplicate column name" errors.

-- quiz_questions: add choice_e through choice_j
ALTER TABLE `quiz_questions` ADD COLUMN `choice_e` text DEFAULT NULL AFTER `choice_d`;
ALTER TABLE `quiz_questions` ADD COLUMN `choice_f` text DEFAULT NULL AFTER `choice_e`;
ALTER TABLE `quiz_questions` ADD COLUMN `choice_g` text DEFAULT NULL AFTER `choice_f`;
ALTER TABLE `quiz_questions` ADD COLUMN `choice_h` text DEFAULT NULL AFTER `choice_g`;
ALTER TABLE `quiz_questions` ADD COLUMN `choice_i` text DEFAULT NULL AFTER `choice_h`;
ALTER TABLE `quiz_questions` ADD COLUMN `choice_j` text DEFAULT NULL AFTER `choice_i`;

-- Allow correct_answer to be A-J (was enum('A','B','C','D'))
ALTER TABLE `quiz_questions` MODIFY `correct_answer` varchar(1) NOT NULL;

-- Allow selected_answer to be A-J (was enum('A','B','C','D'))
ALTER TABLE `quiz_answers` MODIFY `selected_answer` varchar(1) DEFAULT NULL;
