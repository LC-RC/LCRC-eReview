-- College Examination: CREATE-only compatibility for school catalog + assignment log.
-- SUPERSEDED for user columns and directory extras by 055_college_schools_directory.sql.
--
-- Creates the slim college_schools table (id, name, created_at, created_by) and
-- college_school_assignment_log when missing. CREATE IF NOT EXISTS is a no-op if
-- 055 already created the richer college_schools definition.
--
-- Does NOT alter users. Does NOT add college_school_assigned_at/by or
-- college_school_id. Does NOT copy users.school into assignment metadata.
-- Catalog names are exact unique strings; similar labels are not merged.
-- Collation matches users.school (utf8mb4_general_ci in migrations/001_full_schema.sql).
--
-- This host does not support ADD COLUMN IF NOT EXISTS; user-column adds belong
-- only in 055 (information_schema PREPARE). Do not copy 044's IF NOT EXISTS here.
--
-- Optional: skip this file and apply 055 only (055 CREATE includes the log +
-- full directory columns). Independent of committed migration 058.

CREATE TABLE IF NOT EXISTS `college_schools` (
  `school_id` int(11) NOT NULL AUTO_INCREMENT,
  `school_name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`school_id`),
  UNIQUE KEY `uq_college_schools_name` (`school_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `college_school_assignment_log` (
  `log_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `actor_user_id` int(11) NOT NULL,
  `action` varchar(32) NOT NULL,
  `prior_school` varchar(150) DEFAULT NULL,
  `new_school` varchar(150) DEFAULT NULL,
  `prior_assigned_at` datetime DEFAULT NULL,
  `prior_assigned_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_csal_user` (`user_id`,`created_at`),
  KEY `idx_csal_actor` (`actor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
