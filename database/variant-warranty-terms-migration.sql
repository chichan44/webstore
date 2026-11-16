-- Variant warranty-terms migration (2026-11-13)
-- Adds per-variant "Syarat & Ketentuan" and "Ketentuan Garansi" text used by the
-- Ketentuan Garansi page (product -> varian -> S&K/garansi drill-down).
-- Runtime equivalent: includes/helpers.php ensure_product_variant_columns() —
-- wired into ensure_product_extra_columns() so every entry point migrates automatically.
-- This file is for manual/reference use on existing installs.

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_variants' AND column_name='terms_and_conditions');
SET @sql := IF(@col=0, 'ALTER TABLE product_variants ADD terms_and_conditions MEDIUMTEXT NULL AFTER warranty_days', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_variants' AND column_name='warranty_terms');
SET @sql := IF(@col=0, 'ALTER TABLE product_variants ADD warranty_terms MEDIUMTEXT NULL AFTER terms_and_conditions', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
