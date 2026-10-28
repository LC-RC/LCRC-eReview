-- Reverse 048. LOSSY: all heartbeat rows and concurrent-alert rows are deleted.
-- Does not touch college_exam_attempts, answers, scores, or questions.
--
-- After this file, empty tables may reappear if EREVIEW_ENSURE_SCHEMA=1 or
-- scripts/migrate_examination_schema.php runs college_exam_client_heartbeat
-- ensure DDL. Rollback of data is durable only if that ensure gate stays off
-- (or if recreating empty tables is accepted).
--
-- Independent of committed migration 058.

DROP TABLE IF EXISTS `college_exam_concurrent_alerts`;
DROP TABLE IF EXISTS `college_exam_attempt_clients`;
