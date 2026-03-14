-- Email verification flow: add column to users and create pending_registrations table.
-- Run once (e.g. in phpMyAdmin or via migration script).

-- 1. Add email_verified to users (existing users treated as verified).
-- Run only if the column does not exist yet (ignore error if already present).
ALTER TABLE `users` ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`;

-- 2. Pending registrations: store data until user clicks verification link
CREATE TABLE IF NOT EXISTS `pending_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(120) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `review_type` enum('reviewee','undergrad') NOT NULL DEFAULT 'reviewee',
  `school` varchar(150) NOT NULL,
  `school_other` varchar(150) DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `selector` varchar(32) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `idx_email` (`email`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
