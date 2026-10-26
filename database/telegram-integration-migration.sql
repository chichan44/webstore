-- Digital Store - Telegram Auto Order integration
-- Compatible with MariaDB 10.11 / PHP 8.3

ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `telegram_status` tinyint(1) NOT NULL DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `telegram_sort_order` int(11) NOT NULL DEFAULT 0 AFTER `telegram_status`;

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `sales_channel` varchar(24) NOT NULL DEFAULT 'website' AFTER `invoice`,
  ADD COLUMN IF NOT EXISTS `telegram_bot_id` bigint unsigned DEFAULT NULL AFTER `sales_channel`,
  ADD COLUMN IF NOT EXISTS `telegram_user_id` bigint DEFAULT NULL AFTER `telegram_bot_id`,
  ADD COLUMN IF NOT EXISTS `telegram_chat_id` bigint DEFAULT NULL AFTER `telegram_user_id`,
  ADD COLUMN IF NOT EXISTS `telegram_invoice_message_id` bigint DEFAULT NULL AFTER `telegram_chat_id`,
  ADD COLUMN IF NOT EXISTS `telegram_delivery_status` varchar(24) NOT NULL DEFAULT 'not_applicable' AFTER `telegram_invoice_message_id`,
  ADD COLUMN IF NOT EXISTS `telegram_delivery_attempts` int(11) NOT NULL DEFAULT 0 AFTER `telegram_delivery_status`,
  ADD COLUMN IF NOT EXISTS `telegram_last_error` text DEFAULT NULL AFTER `telegram_delivery_attempts`,
  ADD COLUMN IF NOT EXISTS `telegram_delivered_at` datetime DEFAULT NULL AFTER `telegram_last_error`,
  ADD COLUMN IF NOT EXISTS `payment_fee` decimal(14,2) NOT NULL DEFAULT 0 AFTER `total`,
  ADD COLUMN IF NOT EXISTS `payment_total` decimal(14,2) NOT NULL DEFAULT 0 AFTER `payment_fee`;

UPDATE `orders` SET `payment_total`=`total` WHERE `payment_total`=0;

ALTER TABLE `product_stocks`
  MODIFY COLUMN `status` enum('available','reserved','sold','void') NOT NULL DEFAULT 'available',
  ADD COLUMN IF NOT EXISTS `reserved_order_id` int(11) DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `reserved_order_item_id` int(11) DEFAULT NULL AFTER `reserved_order_id`,
  ADD COLUMN IF NOT EXISTS `reserved_at` datetime DEFAULT NULL AFTER `reserved_order_item_id`,
  ADD COLUMN IF NOT EXISTS `reserved_until` datetime DEFAULT NULL AFTER `reserved_at`;

CREATE INDEX IF NOT EXISTS `idx_stock_reservation` ON `product_stocks` (`reserved_order_id`,`reserved_order_item_id`,`status`);
CREATE INDEX IF NOT EXISTS `idx_stock_reserved_until` ON `product_stocks` (`status`,`reserved_until`);

CREATE TABLE IF NOT EXISTS `telegram_bots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bot_name` varchar(120) NOT NULL,
  `bot_username` varchar(120) DEFAULT NULL,
  `bot_user_id` bigint DEFAULT NULL,
  `token_enc` text NOT NULL,
  `token_hint` varchar(24) DEFAULT NULL,
  `webhook_secret` varchar(180) NOT NULL,
  `webhook_key` varchar(80) NOT NULL,
  `status` enum('active','inactive','disabled') NOT NULL DEFAULT 'inactive',
  `last_test_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_telegram_bot_username` (`bot_username`),
  UNIQUE KEY `uq_telegram_webhook_key` (`webhook_key`),
  KEY `idx_telegram_bot_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `telegram_user_id` bigint NOT NULL,
  `first_name` varchar(160) DEFAULT NULL,
  `last_name` varchar(160) DEFAULT NULL,
  `username` varchar(120) DEFAULT NULL,
  `language_code` varchar(16) DEFAULT NULL,
  `buyer_email` varchar(190) DEFAULT NULL,
  `buyer_whatsapp` varchar(80) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_telegram_user_id` (`telegram_user_id`),
  KEY `idx_telegram_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_bot_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bot_id` bigint unsigned NOT NULL,
  `telegram_user_id` bigint NOT NULL,
  `chat_id` bigint NOT NULL,
  `state` varchar(80) DEFAULT NULL,
  `state_data` longtext DEFAULT NULL,
  `last_ui_message_ids` longtext DEFAULT NULL,
  `keyboard_message_id` bigint DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `started_at` datetime DEFAULT NULL,
  `last_interaction_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_telegram_bot_user` (`bot_id`,`telegram_user_id`),
  KEY `idx_telegram_bot_chat` (`bot_id`,`chat_id`),
  KEY `idx_telegram_bot_active` (`bot_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `telegram_bot_users` ADD COLUMN IF NOT EXISTS `last_ui_message_ids` longtext DEFAULT NULL AFTER `state_data`;
ALTER TABLE `telegram_bot_users` ADD COLUMN IF NOT EXISTS `keyboard_message_id` bigint DEFAULT NULL AFTER `last_ui_message_ids`;

CREATE TABLE IF NOT EXISTS `telegram_updates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bot_id` bigint unsigned NOT NULL,
  `update_id` bigint NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_telegram_update` (`bot_id`,`update_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bot_id` bigint unsigned DEFAULT NULL,
  `direction` enum('in','out','system') NOT NULL DEFAULT 'system',
  `event_type` varchar(80) DEFAULT NULL,
  `telegram_user_id` bigint DEFAULT NULL,
  `chat_id` bigint DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_telegram_log_bot` (`bot_id`,`created_at`),
  KEY `idx_telegram_log_user` (`telegram_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_delivery_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `bot_id` bigint unsigned DEFAULT NULL,
  `telegram_user_id` bigint DEFAULT NULL,
  `chat_id` bigint DEFAULT NULL,
  `status` enum('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_telegram_delivery_order` (`order_id`),
  KEY `idx_telegram_delivery_work` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_broadcasts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bot_id` bigint unsigned NOT NULL,
  `title` varchar(180) DEFAULT NULL,
  `message` text NOT NULL,
  `image_url` text DEFAULT NULL,
  `button_text` varchar(80) DEFAULT NULL,
  `button_url` text DEFAULT NULL,
  `status` enum('draft','queued','sending','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  `total_count` int(11) NOT NULL DEFAULT 0,
  `sent_count` int(11) NOT NULL DEFAULT 0,
  `failed_count` int(11) NOT NULL DEFAULT 0,
  `scheduled_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_telegram_broadcast_status` (`status`,`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_broadcast_recipients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `broadcast_id` bigint unsigned NOT NULL,
  `telegram_user_id` bigint NOT NULL,
  `chat_id` bigint NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_telegram_broadcast_recipient` (`broadcast_id`,`telegram_user_id`),
  KEY `idx_telegram_broadcast_recipient_work` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_migration_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `old_bot_id` bigint unsigned NOT NULL,
  `new_bot_id` bigint unsigned NOT NULL,
  `telegram_user_id` bigint NOT NULL,
  `chat_id` bigint NOT NULL,
  `token_hash` char(64) NOT NULL,
  `token_enc` text NOT NULL,
  `status` enum('pending','sent','failed','used') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_telegram_migration_user` (`old_bot_id`,`new_bot_id`,`telegram_user_id`),
  UNIQUE KEY `uq_telegram_migration_token` (`token_hash`),
  KEY `idx_telegram_migration_work` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`,`value`) VALUES
('telegram_welcome_message','Selamat datang di {store}, {name}!\n\nPilih menu di bawah untuk melihat produk dan melakukan pembelian otomatis.'),
('telegram_help_message','1. Pilih List Produk.\n2. Pilih produk dan jumlah.\n3. Pindai QRIS yang dikirim bot.\n4. Produk dikirim otomatis setelah pembayaran berhasil.'),
('telegram_info_message','Bot auto-order resmi. Pastikan username bot sesuai informasi pada website resmi.'),
('telegram_button_products','📦 List Produk'),
('telegram_button_orders','📜 Riwayat'),
('telegram_button_help','❓ Cara Order'),
('telegram_button_info','ℹ️ Informasi'),
('telegram_button_website','🌐 Website')
ON DUPLICATE KEY UPDATE `value`=`value`;
