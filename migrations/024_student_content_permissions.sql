-- =====================================================
-- 024 - Per-student LMS content permissions
-- =====================================================
USE `ereview`;

CREATE TABLE IF NOT EXISTS `student_content_permissions` (
  `permission_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `content_type` enum(
    'full_lms',
    'subject',
    'lesson',
    'quiz',
    'video',
    'handout',
    'preboard_subject',
    'preboard_set',
    'preweek_unit',
    'preweek_topic',
    'test_bank'
  ) NOT NULL,
  `content_id` int(11) NOT NULL DEFAULT 0,
  `access_level` varchar(32) NOT NULL DEFAULT 'view',
  `granted_by` int(11) DEFAULT NULL,
  `granted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `uq_student_content_perm` (`user_id`, `content_type`, `content_id`),
  KEY `idx_scp_user` (`user_id`),
  KEY `idx_scp_type_id` (`content_type`, `content_id`),
  CONSTRAINT `fk_scp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Preserve access for students already approved before granular permissions.
INSERT INTO `student_content_permissions` (`user_id`, `content_type`, `content_id`, `access_level`, `granted_by`)
SELECT u.user_id, 'full_lms', 0, 'view', NULL
FROM `users` u
WHERE u.role = 'student'
  AND u.status = 'approved'
  AND NOT EXISTS (
    SELECT 1 FROM `student_content_permissions` p
    WHERE p.user_id = u.user_id
  );
