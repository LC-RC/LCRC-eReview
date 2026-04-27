-- Persisted lesson video thumbnails for fast student Materials rendering
ALTER TABLE `lesson_videos`
  ADD COLUMN `thumbnail_url` TEXT NULL AFTER `video_url`,
  ADD COLUMN `thumbnail_source` VARCHAR(32) NULL DEFAULT NULL AFTER `thumbnail_url`,
  ADD COLUMN `thumbnail_updated_at` DATETIME NULL DEFAULT NULL AFTER `thumbnail_source`;

