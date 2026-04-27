-- =====================================================
-- 017 - Chat UX: CSAT, KB backlog, session panel metrics
-- =====================================================
USE `ereview`;

CREATE TABLE IF NOT EXISTS `support_chat_csat` (
  `csat_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL,
  `rating` tinyint(4) NOT NULL COMMENT '1-5',
  `comment` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`csat_id`),
  KEY `idx_csat_session` (`session_id`),
  KEY `idx_csat_created` (`created_at`),
  CONSTRAINT `fk_csat_session` FOREIGN KEY (`session_id`) REFERENCES `support_chat_sessions` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_kb_backlog` (
  `backlog_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL,
  `sample_question` varchar(500) NOT NULL,
  `intent` varchar(60) DEFAULT 'unknown',
  `confidence` decimal(5,4) DEFAULT NULL,
  `status` enum('pending','reviewed','added_to_kb','dismissed') NOT NULL DEFAULT 'pending',
  `notes` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`backlog_id`),
  KEY `idx_backlog_status` (`status`),
  KEY `idx_backlog_created` (`created_at`),
  CONSTRAINT `fk_backlog_session` FOREIGN KEY (`session_id`) REFERENCES `support_chat_sessions` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DELIMITER //

DROP PROCEDURE IF EXISTS add_column_if_not_exists//
CREATE PROCEDURE add_column_if_not_exists(
  IN p_table VARCHAR(64),
  IN p_column VARCHAR(64),
  IN p_definition TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END//

CALL add_column_if_not_exists('support_chat_sessions', 'panel_open_count', 'INT UNSIGNED NOT NULL DEFAULT 0')//
CALL add_column_if_not_exists('support_chat_sessions', 'last_panel_open_at', 'DATETIME NULL')//
CALL add_column_if_not_exists('support_chat_sessions', 'last_panel_close_at', 'DATETIME NULL')//

DROP PROCEDURE IF EXISTS add_column_if_not_exists//

DELIMITER ;

INSERT IGNORE INTO `support_chat_sessions` (`session_id`, `source_page`, `status`) VALUES ('system-staff-audit', 'system', 'closed');
