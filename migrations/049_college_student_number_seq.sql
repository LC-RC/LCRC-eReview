-- College Examination: per-year student-number sequence table only.
--
-- THIS FILE IS NOT AUTHORITATIVE for users.student_number UNIQUE.
-- Do not DROP INDEX or ADD UNIQUE from this SQL. Bare ALTER is not idempotent
-- and idx_users_student_number may have a different name on some hosts.
-- This MySQL dialect does not support ADD COLUMN IF NOT EXISTS; index names
-- cannot be discovered portably in a single static ALTER.
--
-- Canonical apply path (dry-run, then --apply on a copy; never from a page GET):
--   C:\xampp\php\php.exe scripts\migrate_049_college_student_number.php
--   C:\xampp\php\php.exe scripts\migrate_049_college_student_number.php --apply
-- That installer: refuses if users.student_number is missing (does not invent it);
-- refuses if duplicate non-empty student_number values exist; drops every
-- non-unique index on that column by discovered name; adds
-- uq_users_student_number only if no unique index already exists; creates
-- college_student_number_seq if missing.
--
-- Format allocated by application: YYYY-NNN (N = 001..999).
-- Do NOT seed last_n from MAX(users.student_number) or user_id.
-- Multiple NULL student_number values remain allowed under UNIQUE.
--
-- Independent of committed migration 058. Yearly ID notes: 057 (no-op).
-- Backfill is CLI only: scripts/college_exam_id_backfill.php (not this file).

CREATE TABLE IF NOT EXISTS `college_student_number_seq` (
  `issue_year` smallint unsigned NOT NULL,
  `last_n` smallint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`issue_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
