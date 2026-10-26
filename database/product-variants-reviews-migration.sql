-- Product variants + reviews migration (reference copy of runtime ensure_* helpers)
-- Runtime equivalent: includes/helpers.php ensure_product_variant_columns(),
-- ensure_product_reviews_table(), ensure_product_default_variants() — wired into
-- ensure_product_extra_columns() so every entry point migrates automatically.
-- This file is for manual/reference use on fresh or existing installs.

CREATE TABLE IF NOT EXISTS product_variants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  price_delta DECIMAL(14,2) NOT NULL DEFAULT 0,
  original_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  manual_stock INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL, updated_at DATETIME NULL,
  KEY idx_variant_product (product_id,status,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-variant slashed (compare-at) price. 0 = fall back to the product-level slash.
-- Existing installs only (fresh installs already get the column above):
-- ALTER TABLE product_variants ADD original_price DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER price_delta;

CREATE TABLE IF NOT EXISTS product_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
  comment TEXT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  ip VARCHAR(80) NOT NULL DEFAULT '',
  created_at DATETIME NULL, moderated_at DATETIME NULL,
  KEY idx_review_product_status (product_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guarded ALTERs — skip any that already exist on this install
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_stocks' AND column_name='variant_id');
SET @sql := IF(@col=0, 'ALTER TABLE product_stocks ADD variant_id INT UNSIGNED NULL AFTER product_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='product_stocks' AND index_name='idx_stock_variant');
SET @sql := IF(@idx=0, 'ALTER TABLE product_stocks ADD KEY idx_stock_variant (variant_id,status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='order_items' AND column_name='variant_id');
SET @sql := IF(@col=0, 'ALTER TABLE order_items ADD variant_id INT UNSIGNED NULL AFTER product_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='order_items' AND column_name='variant_name');
SET @sql := IF(@col=0, 'ALTER TABLE order_items ADD variant_name VARCHAR(120) NULL AFTER variant_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill (idempotent): every product gets at least one active variant.
-- 1) Products with zero variants -> INSERT "Default" variant
INSERT INTO product_variants(product_id,name,price_delta,manual_stock,sort_order,status,created_at,updated_at)
SELECT p.id,'Default',0,0,0,1,NOW(),NOW() FROM products p
WHERE NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id=p.id);

-- 2) Manual products -> Default variant manual_stock = products.manual_stock
UPDATE product_variants v JOIN products p ON p.id=v.product_id
SET v.manual_stock=p.manual_stock
WHERE v.name='Default' AND p.delivery_type='manual' AND v.manual_stock=0 AND p.manual_stock>0;

-- 3) Instant products -> tag untagged stock rows to Default variant
UPDATE product_stocks s JOIN products p ON p.id=s.product_id
JOIN product_variants v ON v.product_id=p.id AND v.name='Default'
SET s.variant_id=v.id WHERE s.variant_id IS NULL AND p.delivery_type='instant';

-- 4) Legacy manual order_items -> tag to Default variant (needed so mark_order_paid
--    can decrement variant stock for still-pending orders)
UPDATE order_items oi JOIN products p ON p.id=oi.product_id
JOIN product_variants v ON v.product_id=p.id AND v.name='Default'
SET oi.variant_id=v.id, oi.variant_name=COALESCE(NULLIF(oi.variant_name,''),'Default')
WHERE oi.variant_id IS NULL AND p.delivery_type='manual';
