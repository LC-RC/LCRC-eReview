-- Centralized College Examination section master (canonical names for students + exam assignment).
-- Does NOT replace college_exam_sections / diagnostic_batch_sections (those remain exam/batch assignment).
-- Does NOT alter users.section type; new UI writes only canonical names from this table.

CREATE TABLE IF NOT EXISTS `college_sections` (
  `section_id` INT NOT NULL AUTO_INCREMENT,
  `section_name` VARCHAR(100) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT NULL DEFAULT NULL,
  `updated_by` INT NULL DEFAULT NULL,
  PRIMARY KEY (`section_id`),
  UNIQUE KEY `uq_college_sections_name` (`section_name`),
  KEY `idx_college_sections_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed from existing free-text values (safe, idempotent via IGNORE on unique name).
-- Separate inserts avoid collation mismatches across tables.
INSERT IGNORE INTO `college_sections` (`section_name`, `status`, `created_at`)
SELECT DISTINCT TRIM(`section`) AS section_name, 'active', NOW()
FROM `users`
WHERE `section` IS NOT NULL AND TRIM(`section`) <> '' AND CHAR_LENGTH(TRIM(`section`)) <= 100;

INSERT IGNORE INTO `college_sections` (`section_name`, `status`, `created_at`)
SELECT DISTINCT TRIM(`section_value`) AS section_name, 'active', NOW()
FROM `college_exam_sections`
WHERE `section_value` IS NOT NULL AND TRIM(`section_value`) <> '' AND CHAR_LENGTH(TRIM(`section_value`)) <= 100;

INSERT IGNORE INTO `college_sections` (`section_name`, `status`, `created_at`)
SELECT DISTINCT TRIM(`section_value`) AS section_name, 'active', NOW()
FROM `diagnostic_batch_sections`
WHERE `section_value` IS NOT NULL AND TRIM(`section_value`) <> '' AND CHAR_LENGTH(TRIM(`section_value`)) <= 100;
