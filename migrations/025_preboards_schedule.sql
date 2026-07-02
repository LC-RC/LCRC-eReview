-- Preboard set scheduled open/close window (admin)
ALTER TABLE preboards_sets
  ADD COLUMN IF NOT EXISTS use_schedule TINYINT(1) NOT NULL DEFAULT 0 AFTER is_open,
  ADD COLUMN IF NOT EXISTS opens_at DATETIME NULL DEFAULT NULL AFTER use_schedule,
  ADD COLUMN IF NOT EXISTS closes_at DATETIME NULL DEFAULT NULL AFTER opens_at;
