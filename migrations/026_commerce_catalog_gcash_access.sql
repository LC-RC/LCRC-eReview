-- =====================================================
-- 026 - Commerce catalog + GCash access schema (Phase 3)
-- Additive only. NO package/price seeds.
--
-- Architecture notes (Phase 2 approved):
-- - access_scope=full_lms (e.g. Self-Paced, Pure Online, Hybrid):
--   grants entire LMS; package_content_items NOT required.
--   Distinctions use duration + package_feature_items (Live/Zoom, Onsite, …).
-- - access_scope=mapped: package_content_items REQUIRED at fulfill time.
-- - OCR/AI (later phases) = receipt verification, NOT GCash API confirmation.
-- - Free Access never creates payment rows.
-- =====================================================
USE `ereview`;

-- ---------- users ----------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'enrollment_path'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `enrollment_path` ENUM(''package'',''by_topic'',''free_access'') NULL DEFAULT NULL AFTER `review_type`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- lessons ----------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lessons' AND COLUMN_NAME = 'price_centavos'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `lessons`
    ADD COLUMN `price_centavos` INT NULL DEFAULT NULL AFTER `description`,
    ADD COLUMN `access_duration_value` INT NULL DEFAULT NULL AFTER `price_centavos`,
    ADD COLUMN `access_duration_unit` ENUM(''day'',''month'') NULL DEFAULT NULL AFTER `access_duration_value`,
    ADD COLUMN `is_purchasable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `access_duration_unit`,
    ADD COLUMN `purchasable_updated_at` DATETIME NULL DEFAULT NULL AFTER `is_purchasable`,
    ADD KEY `idx_lesson_purchasable` (`is_purchasable`, `subject_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- sellable_packages ----------
CREATE TABLE IF NOT EXISTS `sellable_packages` (
  `package_id` INT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `price_centavos` INT NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'PHP',
  `duration_value` INT NOT NULL,
  `duration_unit` ENUM('day','month') NOT NULL,
  `access_scope` ENUM('full_lms','mapped') NOT NULL DEFAULT 'full_lms',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_purchasable` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`package_id`),
  UNIQUE KEY `uq_sellable_package_code` (`code`),
  KEY `idx_sellable_pkg_catalog` (`is_active`, `is_purchasable`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- package_content_items ----------
CREATE TABLE IF NOT EXISTS `package_content_items` (
  `package_content_item_id` BIGINT NOT NULL AUTO_INCREMENT,
  `package_id` INT NOT NULL,
  `content_type` VARCHAR(32) NOT NULL
    COMMENT 'SCA-compatible: full_lms|subject|lesson|quiz|video|handout|preboard_subject|preboard_set|preweek_unit|preweek_topic|test_bank',
  `content_id` INT NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`package_content_item_id`),
  UNIQUE KEY `uq_package_content` (`package_id`, `content_type`, `content_id`),
  KEY `idx_package_content_pkg` (`package_id`, `sort_order`),
  CONSTRAINT `fk_pci_package`
    FOREIGN KEY (`package_id`) REFERENCES `sellable_packages` (`package_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- package_feature_items ----------
CREATE TABLE IF NOT EXISTS `package_feature_items` (
  `package_feature_item_id` BIGINT NOT NULL AUTO_INCREMENT,
  `package_id` INT NOT NULL,
  `feature_key` VARCHAR(64) NOT NULL,
  `feature_label` VARCHAR(150) NOT NULL,
  `feature_description` TEXT NULL,
  `is_included` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`package_feature_item_id`),
  UNIQUE KEY `uq_package_feature` (`package_id`, `feature_key`),
  KEY `idx_package_feature_pkg` (`package_id`, `sort_order`),
  CONSTRAINT `fk_pfi_package`
    FOREIGN KEY (`package_id`) REFERENCES `sellable_packages` (`package_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- payment_settings (singleton) ----------
CREATE TABLE IF NOT EXISTS `payment_settings` (
  `setting_id` TINYINT NOT NULL,
  `gcash_account_name` VARCHAR(150) NOT NULL DEFAULT '',
  `gcash_number` VARCHAR(40) NOT NULL DEFAULT '',
  `gcash_qr_path` VARCHAR(255) NULL,
  `payment_instructions` TEXT NULL,
  `ocr_confidence_threshold` DECIMAL(5,2) NOT NULL DEFAULT 85.00,
  `receipt_max_age_days` INT NOT NULL DEFAULT 7,
  `vision_fallback_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` INT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  CONSTRAINT `fk_payment_settings_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `payment_settings` (`setting_id`, `gcash_account_name`, `gcash_number`)
SELECT 1, '', ''
WHERE NOT EXISTS (SELECT 1 FROM `payment_settings` WHERE `setting_id` = 1);

-- ---------- payments ----------
CREATE TABLE IF NOT EXISTS `payments` (
  `payment_id` BIGINT NOT NULL AUTO_INCREMENT,
  `payment_ref` VARCHAR(32) NOT NULL,
  `user_id` INT NOT NULL,
  `purchase_type` ENUM('package','by_topic') NOT NULL,
  `package_id` INT NULL,
  `expected_amount_centavos` INT NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'PHP',
  `payment_method` ENUM('gcash_qr') NOT NULL DEFAULT 'gcash_qr',
  `gcash_reference` VARCHAR(64) NULL,
  `gcash_reference_norm` VARCHAR(64) NULL,
  `proof_path` VARCHAR(255) NULL,
  `proof_mime` VARCHAR(64) NULL,
  `status` ENUM(
    'awaiting_proof','pending_verification','paid','rejected','cancelled','expired'
  ) NOT NULL DEFAULT 'awaiting_proof',
  `verification_status` ENUM(
    'not_started','processing','auto_verified','needs_review',
    'manually_approved','manually_rejected','failed'
  ) NOT NULL DEFAULT 'not_started',
  `verification_confidence` DECIMAL(5,2) NULL,
  `verification_summary` VARCHAR(500) NULL,
  `ocr_engine` VARCHAR(32) NULL,
  `ocr_raw_text` MEDIUMTEXT NULL,
  `ocr_extracted_json` JSON NULL,
  `detected_amount_centavos` INT NULL,
  `detected_reference` VARCHAR(64) NULL,
  `detected_paid_at` DATETIME NULL,
  `detected_recipient` VARCHAR(150) NULL,
  `matched_amount` TINYINT(1) NULL,
  `matched_reference` TINYINT(1) NULL,
  `matched_recipient` TINYINT(1) NULL,
  `matched_success_text` TINYINT(1) NULL,
  `matched_datetime_ok` TINYINT(1) NULL,
  `duplicate_reference` TINYINT(1) NOT NULL DEFAULT 0,
  `suspicious_flags_json` JSON NULL,
  `reviewed_by` INT NULL,
  `reviewed_at` DATETIME NULL,
  `review_note` TEXT NULL,
  `paid_at` DATETIME NULL,
  `fulfilled_at` DATETIME NULL,
  `fulfillment_email_sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `uq_payment_ref` (`payment_ref`),
  KEY `idx_payments_user_created` (`user_id`, `created_at`),
  KEY `idx_payments_status_ver` (`status`, `verification_status`),
  KEY `idx_payments_needs_review` (`verification_status`, `created_at`),
  KEY `idx_payments_ref_norm` (`gcash_reference_norm`),
  CONSTRAINT `fk_payments_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_package`
    FOREIGN KEY (`package_id`) REFERENCES `sellable_packages` (`package_id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_reviewed_by`
    FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- payment_items ----------
CREATE TABLE IF NOT EXISTS `payment_items` (
  `payment_item_id` BIGINT NOT NULL AUTO_INCREMENT,
  `payment_id` BIGINT NOT NULL,
  `line_no` INT NOT NULL,
  `item_type` ENUM('package','lesson') NOT NULL,
  `package_id` INT NULL,
  `lesson_id` INT NULL,
  `subject_id` INT NULL,
  `item_code` VARCHAR(64) NULL,
  `item_name` VARCHAR(200) NOT NULL,
  `subject_name` VARCHAR(150) NULL,
  `unit_amount_centavos` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `line_total_centavos` INT NOT NULL,
  `duration_value` INT NOT NULL,
  `duration_unit` ENUM('day','month') NOT NULL,
  `package_access_scope` ENUM('full_lms','mapped') NULL,
  `package_content_snapshot_json` JSON NULL,
  `package_features_snapshot_json` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_item_id`),
  UNIQUE KEY `uq_payment_line` (`payment_id`, `line_no`),
  KEY `idx_payment_items_payment` (`payment_id`),
  KEY `idx_payment_items_lesson` (`lesson_id`),
  CONSTRAINT `fk_payment_items_payment`
    FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_items_package`
    FOREIGN KEY (`package_id`) REFERENCES `sellable_packages` (`package_id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_items_lesson`
    FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`lesson_id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- payment_gcash_references ----------
CREATE TABLE IF NOT EXISTS `payment_gcash_references` (
  `gcash_reference_norm` VARCHAR(64) NOT NULL,
  `payment_id` BIGINT NOT NULL,
  `user_id` INT NOT NULL,
  `locked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`gcash_reference_norm`),
  UNIQUE KEY `uq_gcash_ref_payment` (`payment_id`),
  KEY `idx_gcash_ref_user` (`user_id`),
  CONSTRAINT `fk_gcash_ref_payment`
    FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_gcash_ref_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- payment_verification_attempts ----------
CREATE TABLE IF NOT EXISTS `payment_verification_attempts` (
  `attempt_id` BIGINT NOT NULL AUTO_INCREMENT,
  `payment_id` BIGINT NOT NULL,
  `engine` VARCHAR(32) NOT NULL,
  `confidence` DECIMAL(5,2) NULL,
  `raw_text` MEDIUMTEXT NULL,
  `extracted_json` JSON NULL,
  `decision` VARCHAR(32) NULL,
  `decision_reasons_json` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attempt_id`),
  KEY `idx_pva_payment_created` (`payment_id`, `created_at`),
  CONSTRAINT `fk_pva_payment`
    FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- free_access_requests ----------
CREATE TABLE IF NOT EXISTS `free_access_requests` (
  `request_id` BIGINT NOT NULL AUTO_INCREMENT,
  `request_ref` VARCHAR(32) NOT NULL,
  `user_id` INT NOT NULL,
  `status` ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `student_note` TEXT NULL,
  `admin_note` TEXT NULL,
  `requested_scope_hint` VARCHAR(255) NULL,
  `reviewed_by` INT NULL,
  `reviewed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `uq_free_access_request_ref` (`request_ref`),
  KEY `idx_far_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_far_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_far_reviewed_by`
    FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------- access_grants ----------
-- NO unique on (user_id, content_type, content_id) — stacking / repeat purchases allowed.
-- UNIQUE (payment_item_id, content_type, content_id) enforces per-line fulfill idempotency.
CREATE TABLE IF NOT EXISTS `access_grants` (
  `grant_id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `source` ENUM(
    'purchase','free_access','admin_manual','complimentary','extension'
  ) NOT NULL,
  `payment_id` BIGINT NULL,
  `payment_item_id` BIGINT NULL,
  `free_access_request_id` BIGINT NULL,
  `content_type` VARCHAR(32) NOT NULL
    COMMENT 'Same SCA type strings',
  `content_id` INT NOT NULL DEFAULT 0,
  `content_label` VARCHAR(200) NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `status` ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
  `granted_by` INT NULL,
  `revoked_at` DATETIME NULL,
  `revoke_reason` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`grant_id`),
  UNIQUE KEY `uq_grant_payment_item_content` (`payment_item_id`, `content_type`, `content_id`),
  KEY `idx_grants_user_active` (`user_id`, `status`, `ends_at`),
  KEY `idx_grants_payment` (`payment_id`),
  KEY `idx_grants_free_req` (`free_access_request_id`),
  CONSTRAINT `fk_grants_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_grants_payment`
    FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_grants_payment_item`
    FOREIGN KEY (`payment_item_id`) REFERENCES `payment_items` (`payment_item_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_grants_free_req`
    FOREIGN KEY (`free_access_request_id`) REFERENCES `free_access_requests` (`request_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_grants_granted_by`
    FOREIGN KEY (`granted_by`) REFERENCES `users` (`user_id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
