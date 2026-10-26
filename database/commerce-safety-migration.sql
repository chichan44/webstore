-- Digital Store - Commerce Safety Upgrade
-- Fitur: reservasi stok, webhook idempotency, sistem garansi, maintenance mode.
-- Aplikasi juga menjalankan migrasi kompatibel secara otomatis lewat includes/helpers.php.

-- 1) Reservasi stok digital instan
ALTER TABLE `product_stocks`
  MODIFY COLUMN `status` enum('available','reserved','sold','void') NOT NULL DEFAULT 'available';
ALTER TABLE `product_stocks` ADD COLUMN IF NOT EXISTS `reserved_order_id` int(11) DEFAULT NULL AFTER `status`;
ALTER TABLE `product_stocks` ADD COLUMN IF NOT EXISTS `reserved_order_item_id` int(11) DEFAULT NULL AFTER `reserved_order_id`;
ALTER TABLE `product_stocks` ADD COLUMN IF NOT EXISTS `reserved_at` datetime DEFAULT NULL AFTER `reserved_order_item_id`;
ALTER TABLE `product_stocks` ADD COLUMN IF NOT EXISTS `reserved_until` datetime DEFAULT NULL AFTER `reserved_at`;

-- Produk manual tidak membutuhkan counter reservasi tambahan. Kuantitas yang masih pending
-- dihitung langsung dari order_items + orders sehingga otomatis kembali tersedia saat order expired/cancelled.

-- 2) Sistem garansi produk + snapshot garansi per pesanan
ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `warranty_label` varchar(100) NOT NULL DEFAULT '7 Hari' AFTER `description`;
ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `warranty_days` int NOT NULL DEFAULT 7 AFTER `warranty_label`;

UPDATE `products`
SET `warranty_days`=CASE
  WHEN LOWER(`warranty_label`) LIKE '%tanpa garansi%' THEN 0
  WHEN LOWER(`warranty_label`) REGEXP '[0-9]+[[:space:]]*(hari|day)' THEN CAST(`warranty_label` AS UNSIGNED)
  WHEN LOWER(`warranty_label`) REGEXP '[0-9]+[[:space:]]*(minggu|week)' THEN CAST(`warranty_label` AS UNSIGNED)*7
  WHEN LOWER(`warranty_label`) REGEXP '[0-9]+[[:space:]]*(bulan|month)' THEN CAST(`warranty_label` AS UNSIGNED)*30
  WHEN LOWER(`warranty_label`) REGEXP '[0-9]+[[:space:]]*(tahun|year)' THEN CAST(`warranty_label` AS UNSIGNED)*365
  ELSE `warranty_days`
END;
ALTER TABLE `order_items` ADD COLUMN IF NOT EXISTS `warranty_label` varchar(100) DEFAULT NULL AFTER `product_name`;
ALTER TABLE `order_items` ADD COLUMN IF NOT EXISTS `warranty_days` int NOT NULL DEFAULT 0 AFTER `warranty_label`;
ALTER TABLE `order_items` ADD COLUMN IF NOT EXISTS `warranty_started_at` datetime DEFAULT NULL AFTER `warranty_days`;
ALTER TABLE `order_items` ADD COLUMN IF NOT EXISTS `warranty_expires_at` datetime DEFAULT NULL AFTER `warranty_started_at`;

UPDATE `order_items` oi
JOIN `products` p ON p.id=oi.product_id
SET oi.warranty_label=COALESCE(NULLIF(oi.warranty_label,''),p.warranty_label),
    oi.warranty_days=CASE WHEN oi.warranty_days>0 THEN oi.warranty_days ELSE COALESCE(p.warranty_days,0) END
WHERE oi.warranty_label IS NULL OR oi.warranty_label='' OR oi.warranty_days=0;

UPDATE `order_items` oi
JOIN `orders` o ON o.id=oi.order_id
SET oi.warranty_started_at=COALESCE(oi.warranty_started_at,o.delivered_at,o.paid_at,o.created_at),
    oi.warranty_expires_at=CASE WHEN oi.warranty_days>0 THEN COALESCE(oi.warranty_expires_at,DATE_ADD(COALESCE(o.delivered_at,o.paid_at,o.created_at),INTERVAL oi.warranty_days DAY)) ELSE NULL END
WHERE o.status IN ('paid','delivered') AND oi.warranty_started_at IS NULL;

-- 3) Idempotency callback pembayaran
CREATE TABLE IF NOT EXISTS `payment_webhook_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(40) NOT NULL DEFAULT 'tokopay',
  `event_key` char(64) NOT NULL,
  `invoice` varchar(80) DEFAULT NULL,
  `reference` varchar(190) DEFAULT NULL,
  `event_status` varchar(80) DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `payload_hash` char(64) DEFAULT NULL,
  `process_status` enum('processing','processed','failed') NOT NULL DEFAULT 'processing',
  `attempts` int NOT NULL DEFAULT 1,
  `first_seen_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `processed_at` datetime DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_event` (`provider`,`event_key`),
  KEY `idx_webhook_invoice` (`invoice`),
  KEY `idx_webhook_reference` (`reference`),
  KEY `idx_webhook_process` (`process_status`,`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Maintenance Mode (tidak mengubah tabel; memakai tabel settings yang sudah ada)
INSERT INTO `settings` (`key`,`value`) VALUES ('maintenance_mode','0')
ON DUPLICATE KEY UPDATE `value`=`value`;
INSERT INTO `settings` (`key`,`value`) VALUES ('maintenance_message','Checkout sedang dalam pemeliharaan. Silakan coba kembali beberapa saat lagi.')
ON DUPLICATE KEY UPDATE `value`=`value`;
