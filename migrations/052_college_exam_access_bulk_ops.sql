-- College Examination: audit log for explicit activate/deactivate of exam access.
-- Does not change users.status. Does not delete users.
-- Application code fails closed if these tables are missing.
-- Do not run until approved.

CREATE TABLE IF NOT EXISTS `college_exam_access_bulk_ops` (
  `op_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `action` varchar(32) NOT NULL,
  `actor_user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`op_id`),
  KEY `idx_ceabo_created` (`created_at`),
  KEY `idx_ceabo_actor` (`actor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `college_exam_access_bulk_op_rows` (
  `row_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `op_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(32) NOT NULL,
  `school_snapshot` varchar(150) DEFAULT NULL,
  `prior_access` varchar(16) NOT NULL,
  `new_access` varchar(16) NOT NULL,
  PRIMARY KEY (`row_id`),
  KEY `idx_ceabor_op` (`op_id`),
  KEY `idx_ceabor_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
