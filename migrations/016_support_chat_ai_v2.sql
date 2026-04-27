-- =====================================================
-- 016 - Support chat AI v2: KB fields, memory, versions, settings
-- =====================================================
USE `ereview`;

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

CALL add_column_if_not_exists('support_kb_articles', 'short_answer', 'MEDIUMTEXT NULL AFTER `content`')//
CALL add_column_if_not_exists('support_kb_articles', 'approved_phrases', 'MEDIUMTEXT NULL COMMENT ''One phrase per line; bot may quote verbatim''')//
CALL add_column_if_not_exists('support_kb_articles', 'article_banned_topics', 'VARCHAR(500) NULL COMMENT ''Optional: comma-separated; blocks answers from this article context''')//
CALL add_column_if_not_exists('support_kb_articles', 'last_reviewed_at', 'DATETIME NULL')//
CALL add_column_if_not_exists('support_kb_articles', 'reviewed_by_user_id', 'INT(11) NULL')//

CALL add_column_if_not_exists('support_chat_sessions', 'last_topic', 'VARCHAR(40) NULL COMMENT ''packages|payment|registration|access|content|general''')//
CALL add_column_if_not_exists('support_chat_sessions', 'package_interest', 'VARCHAR(32) NULL COMMENT ''e.g. 6-month, 9-month, 14-month''')//
CALL add_column_if_not_exists('support_chat_sessions', 'detected_language', 'VARCHAR(12) NULL COMMENT ''en|tl|mixed''')//
CALL add_column_if_not_exists('support_chat_sessions', 'conversation_flow', 'VARCHAR(32) NULL COMMENT ''sales|billing_support|technical_access|general''')//

CALL add_column_if_not_exists('support_tickets', 'category', 'VARCHAR(40) NULL COMMENT ''sales|billing_support|technical_access|general'' AFTER `subject`')//
CALL add_column_if_not_exists('support_tickets', 'transcript_excerpt', 'MEDIUMTEXT NULL')//

DROP PROCEDURE IF EXISTS add_column_if_not_exists//

DELIMITER ;

CREATE TABLE IF NOT EXISTS `support_chat_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` mediumtext NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `support_chat_settings` (`setting_key`, `setting_value`)
SELECT 'global_banned_topics', ''
WHERE NOT EXISTS (SELECT 1 FROM `support_chat_settings` WHERE `setting_key` = 'global_banned_topics');

CREATE TABLE IF NOT EXISTS `support_kb_article_versions` (
  `version_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `article_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` mediumtext NOT NULL,
  `short_answer` mediumtext DEFAULT NULL,
  `keywords` varchar(500) DEFAULT NULL,
  `approved_phrases` mediumtext DEFAULT NULL,
  `article_banned_topics` varchar(500) DEFAULT NULL,
  `edited_by_user_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version_id`),
  KEY `idx_kb_versions_article` (`article_id`),
  CONSTRAINT `fk_kb_versions_article` FOREIGN KEY (`article_id`) REFERENCES `support_kb_articles` (`article_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
