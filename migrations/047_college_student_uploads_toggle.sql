-- College Examination: student uploads module toggle (default OFF).
-- Uses existing ereview_app_settings (see migrations/038_app_settings_playground_toggle.sql).

INSERT INTO `ereview_app_settings` (`setting_key`, `setting_value`)
VALUES ('college_student_uploads_enabled', '0')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
