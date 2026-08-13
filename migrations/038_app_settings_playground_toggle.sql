-- Site-wide app settings (module toggles, etc.)
CREATE TABLE IF NOT EXISTS `ereview_app_settings` (
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Default: CPA Playground enabled
INSERT INTO `ereview_app_settings` (`setting_key`, `setting_value`)
VALUES ('playground_enabled', '1')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
