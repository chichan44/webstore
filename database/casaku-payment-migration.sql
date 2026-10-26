-- Casaku Payment Gateway Upgrade
-- Aman dijalankan pada instalasi lama. Aplikasi juga menjalankan penambahan kolom/index kompatibel secara otomatis.

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `payment_provider` varchar(40) DEFAULT NULL AFTER `status`;

INSERT INTO `settings` (`key`,`value`) VALUES
('casaku_license_key',''),
('casaku_qr_id',''),
('casaku_package_ids',''),
('casaku_expired_minutes','15'),
('casaku_use_unique_code','1'),
('casaku_prefix','CSK'),
('casaku_webhook_secret',''),
('casaku_callback_ip_whitelist','0'),
('casaku_callback_ips','')
ON DUPLICATE KEY UPDATE `value`=`value`;

-- Index (payment_provider, payment_reference) dibuat otomatis oleh includes/helpers.php
-- bila belum ada, sehingga migrasi ini tetap aman pada variasi MySQL/MariaDB yang berbeda.
