-- Reverse 054.
-- LOSSY: permanently deletes user_archive_snapshots (prior_status,
-- prior_college_examination_access, archive/restore actor timestamps).
-- Restore history cannot be used after this DROP. This does not un-archive
-- users or change users.status / college_examination_access.

DROP TABLE IF EXISTS `user_archive_snapshots`;
