-- Demo payment setting + buyer-only reviews (reference migration)
-- Runtime equivalents:
--   includes/helpers.php ensure_product_reviews_table() (order_id, order_item_id, unique order_item)
--   includes/payments.php checkout_demo_payment_enabled() / checkout_available_payment_methods()
--   admin/tokopay.php toggle saves checkout_demo_payment_enabled
-- Fresh installs also get these from install/schema.sql.

-- 1) Demo payment master switch (default off)
INSERT INTO settings (`key`,`value`)
VALUES ('checkout_demo_payment_enabled','0')
ON DUPLICATE KEY UPDATE `value`=`value`;

-- 2) product_reviews buyer linkage
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_reviews' AND column_name='order_id');
SET @sql := IF(@col=0, 'ALTER TABLE product_reviews ADD order_id INT UNSIGNED NULL AFTER moderated_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='product_reviews' AND column_name='order_item_id');
SET @sql := IF(@col=0, 'ALTER TABLE product_reviews ADD order_item_id INT UNSIGNED NULL AFTER order_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='product_reviews' AND index_name='uq_review_order_item');
SET @sql := IF(@idx=0, 'ALTER TABLE product_reviews ADD UNIQUE KEY uq_review_order_item (order_item_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='product_reviews' AND index_name='idx_review_order');
SET @sql := IF(@idx=0, 'ALTER TABLE product_reviews ADD KEY idx_review_order (order_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
