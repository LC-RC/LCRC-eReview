-- Important Concepts enhancements (safe if columns already exist via ensure_schema).
-- Run manually only if needed; student_cpa_review_ensure_schema() also adds these.

-- ALTER TABLE `student_important_items` ADD COLUMN `topic` VARCHAR(255) DEFAULT NULL AFTER `body`;
-- ALTER TABLE `student_important_items` ADD COLUMN `is_last_minute` TINYINT(1) NOT NULL DEFAULT 0 AFTER `lesson_id`;
-- ALTER TABLE `student_important_items` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
