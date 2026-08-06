-- Durable email resume links for payment proof upload (Remind → Upload Proof).
-- Raw token is emailed; only SHA-256 hash is stored.

CREATE TABLE IF NOT EXISTS `payment_checkout_links` (
  `link_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `payment_id` INT NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`link_id`),
  UNIQUE KEY `uq_payment_checkout_links_token` (`token_hash`),
  KEY `idx_payment_checkout_links_user` (`user_id`),
  KEY `idx_payment_checkout_links_payment` (`payment_id`),
  KEY `idx_payment_checkout_links_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
