-- Variant-level pricing migration (2026-10-22)
-- Runtime equivalent: includes/helpers.php ensure_product_variant_columns(),
-- ensure_variant_level_pricing_backfill() — wired into ensure_product_extra_columns()
-- so every entry point migrates automatically.
-- This file is for manual/reference use on existing installs.

-- 1. New per-variant columns (guarded — skip any that already exist)

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_variants' AND column_name='price');
SET @sql := IF(@col=0, 'ALTER TABLE product_variants ADD price DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_variants' AND column_name='discount_pct');
SET @sql := IF(@col=0, 'ALTER TABLE product_variants ADD discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER price', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_variants' AND column_name='flash_discount_pct');
SET @sql := IF(@col=0, 'ALTER TABLE product_variants ADD flash_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER discount_pct', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_variants' AND column_name='warranty_label');
SET @sql := IF(@col=0, 'ALTER TABLE product_variants ADD warranty_label VARCHAR(100) NOT NULL DEFAULT \'\' AFTER flash_discount_pct', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_variants' AND column_name='warranty_days');
SET @sql := IF(@col=0, 'ALTER TABLE product_variants ADD warranty_days INT NOT NULL DEFAULT 0 AFTER warranty_label', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Backfill from legacy product-level columns (idempotent — only touches rows where price=0)
--    price           = legacy compare-at (original_price) or legacy sale price
--    discount_pct    = % off to reach the non-flash legacy sale price
--    flash_discount_pct = % off to reach the legacy flash price (when set)
--    warranty_*      = copied from the product row

UPDATE product_variants v
  JOIN products p ON p.id = v.product_id
SET
  v.price = CASE
    WHEN p.original_price > 0 THEN p.original_price
    WHEN p.price > 0 THEN p.price
    ELSE 0
  END,
  v.discount_pct = CASE
    WHEN p.original_price > 0 AND p.price > 0 AND p.original_price > p.price
      THEN ROUND((1 - p.price / p.original_price) * 100, 2)
    WHEN p.original_price > 0 AND p.price > 0 AND p.original_price = p.price
      THEN 0
    ELSE 0
  END,
  v.flash_discount_pct = CASE
    WHEN p.flash_price > 0 AND p.original_price > 0 AND p.original_price > p.flash_price
      THEN ROUND((1 - p.flash_price / p.original_price) * 100, 2)
    WHEN p.flash_price > 0 AND p.original_price = 0 AND p.price > 0 AND p.price > p.flash_price
      THEN ROUND((1 - p.flash_price / p.price) * 100, 2)
    ELSE 0
  END,
  v.warranty_label = COALESCE(v.warranty_label, ''),
  v.warranty_days  = COALESCE(v.warranty_days, 0)
WHERE v.price = 0
  AND (p.price > 0 OR p.original_price > 0);

-- Copy product warranty onto variants that have none
UPDATE product_variants v
  JOIN products p ON p.id = v.product_id
SET v.warranty_label = COALESCE(NULLIF(p.warranty_label, ''), '7 Hari'),
    v.warranty_days  = COALESCE(p.warranty_days, 7)
WHERE (v.warranty_label = '' OR v.warranty_days = 0);

-- 3. Notes
-- products.price, products.original_price, products.flash_price,
-- products.manual_stock, products.warranty_label, products.warranty_days
-- are now LEGACY — no longer written from the admin form. They remain as
-- fallback for product_variant_warranty_* helpers and for any external tooling.
-- products.rating is a denormalized cache refreshed by product_refresh_rating().
