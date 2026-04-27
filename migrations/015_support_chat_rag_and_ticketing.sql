-- =====================================================
-- 015 - Support chat RAG, handoff, ticketing, analytics
-- =====================================================

USE `ereview`;

CREATE TABLE IF NOT EXISTS `support_kb_articles` (
  `article_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `content` mediumtext NOT NULL,
  `keywords` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`article_id`),
  KEY `idx_support_kb_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_chat_sessions` (
  `session_id` varchar(64) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `requester_name` varchar(140) DEFAULT NULL,
  `requester_email` varchar(190) DEFAULT NULL,
  `source_page` varchar(120) DEFAULT 'home',
  `status` enum('open','handoff','closed') NOT NULL DEFAULT 'open',
  `handoff_requested` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_at` datetime DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `idx_support_chat_sessions_user` (`user_id`),
  KEY `idx_support_chat_sessions_status` (`status`),
  KEY `idx_support_chat_sessions_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_chat_messages` (
  `message_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL,
  `role` enum('user','assistant','agent','system') NOT NULL DEFAULT 'assistant',
  `message_text` mediumtext NOT NULL,
  `intent` varchar(60) DEFAULT 'unknown',
  `confidence_score` decimal(5,4) DEFAULT 0.0000,
  `matched_article_id` int(11) DEFAULT NULL,
  `needs_human` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`),
  KEY `idx_support_chat_messages_session` (`session_id`),
  KEY `idx_support_chat_messages_created` (`created_at`),
  KEY `idx_support_chat_messages_needs_human` (`needs_human`),
  CONSTRAINT `fk_support_chat_messages_session` FOREIGN KEY (`session_id`) REFERENCES `support_chat_sessions` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `ticket_id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `requester_name` varchar(140) DEFAULT NULL,
  `requester_email` varchar(190) DEFAULT NULL,
  `subject` varchar(250) NOT NULL,
  `status` enum('open','pending','resolved','closed') NOT NULL DEFAULT 'open',
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `assigned_to` int(11) DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ticket_id`),
  KEY `idx_support_tickets_session` (`session_id`),
  KEY `idx_support_tickets_status` (`status`),
  KEY `idx_support_tickets_created` (`created_at`),
  CONSTRAINT `fk_support_tickets_session` FOREIGN KEY (`session_id`) REFERENCES `support_chat_sessions` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_analytics_events` (
  `event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL,
  `event_type` varchar(60) NOT NULL,
  `payload_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `idx_support_events_session` (`session_id`),
  KEY `idx_support_events_type` (`event_type`),
  KEY `idx_support_events_created` (`created_at`),
  CONSTRAINT `fk_support_events_session` FOREIGN KEY (`session_id`) REFERENCES `support_chat_sessions` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `support_kb_articles` (`title`, `content`, `keywords`, `status`)
SELECT 'Packages and pricing',
       'LCRC eReview packages: 6-month access (PHP 1,500), 9-month access (PHP 2,000), and 14-month access (PHP 2,500). You can view details in the Packages section and start enrollment from the Register page.',
       'package,packages,pricing,price,plan,cost,enroll',
       'active'
WHERE NOT EXISTS (
  SELECT 1 FROM `support_kb_articles` WHERE `title` = 'Packages and pricing'
);

INSERT INTO `support_kb_articles` (`title`, `content`, `keywords`, `status`)
SELECT 'Registration flow',
       'To enroll: open Register, complete your details, upload payment proof, then wait for admin approval. Once approved, your account receives access based on your selected package.',
       'registration,register,enroll,approval,payment proof,access',
       'active'
WHERE NOT EXISTS (
  SELECT 1 FROM `support_kb_articles` WHERE `title` = 'Registration flow'
);

INSERT INTO `support_kb_articles` (`title`, `content`, `keywords`, `status`)
SELECT 'Support contact and escalation',
       'If your issue is unresolved, request live support in chat. The system creates a support ticket and forwards your transcript so the team can continue the conversation.',
       'support,live agent,handoff,ticket,escalation',
       'active'
WHERE NOT EXISTS (
  SELECT 1 FROM `support_kb_articles` WHERE `title` = 'Support contact and escalation'
);
