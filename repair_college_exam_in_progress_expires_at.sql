-- One-off: recalculate expires_at for in-progress college exam attempts so they match
-- min(started_at + time_limit_seconds, deadline) (same rules as college_exam_compute_expires_at at start).
--
-- Run once after deploying the deadline/timer fixes, e.g.:
--   mysql -u USER -p DATABASE < repair_college_exam_in_progress_expires_at.sql
-- Or paste into phpMyAdmin / MySQL client.
--
-- Optional preview (rows that would change):
-- SELECT a.attempt_id, a.exam_id, a.user_id, a.started_at, a.expires_at AS old_expires,
--   CASE
--     WHEN e.time_limit_seconds > 0 AND e.deadline IS NOT NULL AND TRIM(CAST(e.deadline AS CHAR)) <> '' THEN
--       LEAST(DATE_ADD(a.started_at, INTERVAL e.time_limit_seconds SECOND), CAST(e.deadline AS DATETIME))
--     WHEN e.time_limit_seconds > 0 THEN DATE_ADD(a.started_at, INTERVAL e.time_limit_seconds SECOND)
--     WHEN e.deadline IS NOT NULL AND TRIM(CAST(e.deadline AS CHAR)) <> '' THEN CAST(e.deadline AS DATETIME)
--     ELSE NULL
--   END AS new_expires
-- FROM college_exam_attempts a
-- INNER JOIN college_exams e ON e.exam_id = a.exam_id
-- WHERE a.status = 'in_progress' AND a.started_at IS NOT NULL;

UPDATE college_exam_attempts a
INNER JOIN college_exams e ON e.exam_id = a.exam_id
SET a.expires_at = CASE
  WHEN e.time_limit_seconds > 0 AND e.deadline IS NOT NULL AND TRIM(CAST(e.deadline AS CHAR)) <> '' THEN
    LEAST(
      DATE_ADD(a.started_at, INTERVAL e.time_limit_seconds SECOND),
      CAST(e.deadline AS DATETIME)
    )
  WHEN e.time_limit_seconds > 0 THEN
    DATE_ADD(a.started_at, INTERVAL e.time_limit_seconds SECOND)
  WHEN e.deadline IS NOT NULL AND TRIM(CAST(e.deadline AS CHAR)) <> '' THEN
    CAST(e.deadline AS DATETIME)
  ELSE
    NULL
END
WHERE a.status = 'in_progress'
  AND a.started_at IS NOT NULL;
