-- Phase 8.1 hardening: Free Access grant idempotency (defense-in-depth)
-- UNIQUE (free_access_request_id, content_type, content_id)
-- MySQL 8: multiple NULL free_access_request_id values remain allowed (purchase grants).
-- Do NOT edit migration 026.
--
-- Pre-check: aborts if duplicate non-NULL FAR grant groups already exist.
-- Does not delete or modify grant/request data.

DELIMITER //

DROP PROCEDURE IF EXISTS `_028_far_grant_idempotency_precheck` //

CREATE PROCEDURE `_028_far_grant_idempotency_precheck`()
BEGIN
  DECLARE v_dup_groups INT DEFAULT 0;

  SELECT COUNT(*) INTO v_dup_groups
  FROM (
    SELECT free_access_request_id, content_type, content_id
    FROM access_grants
    WHERE free_access_request_id IS NOT NULL
    GROUP BY free_access_request_id, content_type, content_id
    HAVING COUNT(*) > 1
  ) AS d;

  IF v_dup_groups > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ABORT 028: duplicate free_access grant groups exist; resolve before adding uq_grant_free_req_content';
  END IF;
END //

DELIMITER ;

CALL `_028_far_grant_idempotency_precheck`();
DROP PROCEDURE IF EXISTS `_028_far_grant_idempotency_precheck`;

-- Add unique index if missing (idempotent re-run safe).
SET @idx_028 := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'access_grants'
    AND index_name = 'uq_grant_free_req_content'
);

SET @sql_028 := IF(
  @idx_028 = 0,
  'ALTER TABLE `access_grants` ADD UNIQUE KEY `uq_grant_free_req_content` (`free_access_request_id`, `content_type`, `content_id`)',
  'SELECT ''uq_grant_free_req_content already exists'' AS info'
);

PREPARE stmt_028 FROM @sql_028;
EXECUTE stmt_028;
DEALLOCATE PREPARE stmt_028;
