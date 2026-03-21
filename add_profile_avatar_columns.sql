-- Adds profile avatar support for registration and student UI.
-- Safe to run multiple times (MySQL 8+ supports IF NOT EXISTS for ADD COLUMN).

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL AFTER payment_proof,
  ADD COLUMN IF NOT EXISTS use_default_avatar TINYINT(1) NOT NULL DEFAULT 1 AFTER profile_picture;

ALTER TABLE pending_registrations
  ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL AFTER payment_proof,
  ADD COLUMN IF NOT EXISTS use_default_avatar TINYINT(1) NOT NULL DEFAULT 1 AFTER profile_picture;

