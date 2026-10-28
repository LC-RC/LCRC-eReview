-- Phase 1: overlapping exam-client heartbeat registry (detect/alert only).
-- No foreign keys onto college_exam_attempts (existing cleanup/delete paths stay intact).
-- Do not run from student take/AJAX hot paths.
--
-- Independent of committed migration 058 (examination archive).
-- Apply this file for schema; do not infer order from 058's number.
--
-- Runtime duplicate: examination/includes/college_exam_client_heartbeat.php
-- CREATE TABLE IF NOT EXISTS for these two tables when EREVIEW_ENSURE_SCHEMA
-- (or CLI migrate_examination_schema.php) is enabled. Student request paths
-- must not run that ensure gate. After DOWN, a later ensure recreates empty tables.

CREATE TABLE IF NOT EXISTS `college_exam_attempt_clients` (
  `client_row_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `instance_id_hash` char(64) NOT NULL,
  `browser_scope_hash` char(64) NOT NULL,
  `php_session_hash` char(64) DEFAULT NULL,
  `client_label` varchar(16) NOT NULL DEFAULT 'unknown',
  `first_seen_at` datetime NOT NULL,
  `last_heartbeat_at` datetime NOT NULL,
  PRIMARY KEY (`client_row_id`),
  UNIQUE KEY `uq_ceac_attempt_instance` (`attempt_id`,`instance_id_hash`),
  KEY `idx_ceac_exam_hb` (`exam_id`,`last_heartbeat_at`),
  KEY `idx_ceac_attempt_hb` (`attempt_id`,`last_heartbeat_at`),
  KEY `idx_ceac_user_hb` (`user_id`,`last_heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `college_exam_concurrent_alerts` (
  `alert_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending_review',
  `instance_count` int(11) NOT NULL DEFAULT 0,
  `browser_scope_count` int(11) NOT NULL DEFAULT 0,
  `peak_instance_count` int(11) NOT NULL DEFAULT 0,
  `peak_scope_count` int(11) NOT NULL DEFAULT 0,
  `first_detected_at` datetime NOT NULL,
  `last_detected_at` datetime NOT NULL,
  PRIMARY KEY (`alert_id`),
  UNIQUE KEY `uq_cecalert_attempt` (`attempt_id`),
  KEY `idx_cecalert_exam_status` (`exam_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
