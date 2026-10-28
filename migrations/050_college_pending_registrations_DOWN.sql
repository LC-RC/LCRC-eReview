-- Reverse 050.
-- LOSSY: permanently deletes all pending college-registration rows (emails,
-- password hashes, token hashes, school/section snapshots, unused verify
-- tokens). In-flight sign-ups cannot be completed after this DROP.
-- Does not drop LMS pending_registrations or users. Does not alter users.school.

DROP TABLE IF EXISTS `college_pending_registrations`;
