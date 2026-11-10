-- Multi-currency migration (IDR / USD)
-- Runtime equivalent: includes/helpers.php ensure_product_variant_columns() (price_usd),
-- ensure_order_security_columns() (orders.currency) — wired into every entry point
-- so the schema migrates automatically. This file is for manual/reference use.

-- 1. New columns (guarded — skip any that already exist)

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_variants' AND column_name='price_usd');
SET @sql := IF(@col=0, 'ALTER TABLE product_variants ADD price_usd DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER price', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='currency');
SET @sql := IF(@col=0, 'ALTER TABLE orders ADD currency VARCHAR(3) NOT NULL DEFAULT ''IDR'' AFTER payment_provider', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Settings seed (site_currency: 'IDR' default, switchable to 'USD' in admin/settings.php)

INSERT INTO `settings`(`key`,`value`) VALUES('site_currency','IDR')
  ON DUPLICATE KEY UPDATE `key`=`key`;

-- 3. Notes
-- product_variants.price     = harga reguler IDR (== harga coret), discount_pct/flash_discount_pct = % off.
-- product_variants.price_usd = harga reguler USD; persentase diskon yang sama berlaku untuk kedua mata uang.
-- orders.currency            = mata uang saat checkout ('IDR'/'USD'); order lama dianggap 'IDR'.
-- Saat site_currency = 'USD': harga ditampilkan/dijual dari price_usd dan pembayaran memakai
-- gateway USD (NowPayments/Crypto). Saat 'IDR': harga IDR dan gateway IDR (Tokopay/Casaku/QRIS).
