ALTER TABLE `deleted_users_log`
  ADD COLUMN `deleted_school` varchar(180) NULL AFTER `deleted_email`,
  ADD COLUMN `deleted_review_type` varchar(80) NULL AFTER `deleted_school`,
  ADD COLUMN `deleted_access_range` varchar(80) NULL AFTER `deleted_review_type`,
  ADD COLUMN `deletion_reason` varchar(255) NULL AFTER `deleted_by_admin_name`;
ALTER TABLE `deleted_users_log`
  ADD COLUMN `deleted_school` varchar(180) NULL AFTER `deleted_email`,
  ADD COLUMN `deleted_review_type` varchar(80) NULL AFTER `deleted_school`,
  ADD COLUMN `deleted_access_range` varchar(80) NULL AFTER `deleted_review_type`,
  ADD COLUMN `deletion_reason` varchar(255) NULL AFTER `deleted_by_admin_name`;
