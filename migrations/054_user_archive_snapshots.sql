-- Archive/restore prior-status snapshots for unified learner inactivity.
-- Application code fails closed if this table is missing (read-only SHOW TABLES).
-- Do not CREATE this table from request handlers. Apply this migration separately.
-- Do not run until approved.

CREATE TABLE IF NOT EXISTS `user_archive_snapshots` (
  `snapshot_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `prior_status` varchar(32) NOT NULL,
  `prior_college_examination_access` varchar(32) NOT NULL DEFAULT 'none',
  `reason` varchar(255) DEFAULT NULL,
  `archived_by` int(11) NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `restored_at` datetime DEFAULT NULL,
  `restored_by` int(11) DEFAULT NULL,
  `restore_status` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`snapshot_id`),
  KEY `idx_uas_user` (`user_id`),
  KEY `idx_uas_open` (`user_id`, `restored_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
