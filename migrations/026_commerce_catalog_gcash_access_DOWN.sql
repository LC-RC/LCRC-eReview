-- =====================================================
-- 026 DOWN - Commerce catalog rollback (SAFE)
-- Aborts if any commerce data rows exist.
-- =====================================================
USE `ereview`;

DROP PROCEDURE IF EXISTS `ereview_026_down_safe`;

DELIMITER //
CREATE PROCEDURE `ereview_026_down_safe`()
BEGIN
  DECLARE v_count BIGINT DEFAULT 0;
  DECLARE v_msg VARCHAR(512);

  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments') THEN
    SELECT COUNT(*) INTO @c FROM `payments`; SET v_count = v_count + @c;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_items') THEN
    SELECT COUNT(*) INTO @c FROM `payment_items`; SET v_count = v_count + @c;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_gcash_references') THEN
    SELECT COUNT(*) INTO @c FROM `payment_gcash_references`; SET v_count = v_count + @c;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_verification_attempts') THEN
    SELECT COUNT(*) INTO @c FROM `payment_verification_attempts`; SET v_count = v_count + @c;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'free_access_requests') THEN
    SELECT COUNT(*) INTO @c FROM `free_access_requests`; SET v_count = v_count + @c;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'access_grants') THEN
    SELECT COUNT(*) INTO @c FROM `access_grants`; SET v_count = v_count + @c;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sellable_packages') THEN
    SELECT COUNT(*) INTO @c FROM `sellable_packages`; SET v_count = v_count + @c;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'package_content_items') THEN
    SELECT COUNT(*) INTO @c FROM `package_content_items`; SET v_count = v_count + @c;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'package_feature_items') THEN
    SELECT COUNT(*) INTO @c FROM `package_feature_items`; SET v_count = v_count + @c;
  END IF;

  IF v_count > 0 THEN
    SET v_msg = CONCAT(
      '026 DOWN blocked: ', v_count,
      ' commerce row(s) exist. Refuse automatic drop to protect audit/payment history.'
    );
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
  END IF;

  SET FOREIGN_KEY_CHECKS = 0;
  DROP TABLE IF EXISTS `access_grants`;
  DROP TABLE IF EXISTS `free_access_requests`;
  DROP TABLE IF EXISTS `payment_verification_attempts`;
  DROP TABLE IF EXISTS `payment_gcash_references`;
  DROP TABLE IF EXISTS `payment_items`;
  DROP TABLE IF EXISTS `payments`;
  DROP TABLE IF EXISTS `package_feature_items`;
  DROP TABLE IF EXISTS `package_content_items`;
  DROP TABLE IF EXISTS `sellable_packages`;
  DROP TABLE IF EXISTS `payment_settings`;
  SET FOREIGN_KEY_CHECKS = 1;

  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lessons' AND COLUMN_NAME = 'price_centavos'
  ) THEN
    ALTER TABLE `lessons`
      DROP KEY `idx_lesson_purchasable`,
      DROP COLUMN `purchasable_updated_at`,
      DROP COLUMN `is_purchasable`,
      DROP COLUMN `access_duration_unit`,
      DROP COLUMN `access_duration_value`,
      DROP COLUMN `price_centavos`;
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'enrollment_path'
  ) THEN
    ALTER TABLE `users` DROP COLUMN `enrollment_path`;
  END IF;
END//
DELIMITER ;

CALL `ereview_026_down_safe`();
DROP PROCEDURE IF EXISTS `ereview_026_down_safe`;
