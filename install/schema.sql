SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(190) NOT NULL,
  `value` mediumtext NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(160) DEFAULT NULL,
  `role` varchar(80) NOT NULL DEFAULT 'Administrator',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `icon` varchar(64) NOT NULL DEFAULT '',
  `sort_order` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_slug` (`slug`),
  KEY `idx_category_status_sort` (`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned DEFAULT NULL,
  `name` varchar(190) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `description` mediumtext NULL,
  `product_terms` mediumtext NULL,
  /* LEGACY (variant-level pricing 2026-10-22): no longer written from the admin form.
     Kept for backward compatibility / fallback in product_variant_warranty_* helpers. */
  `warranty_label` varchar(100) NOT NULL DEFAULT '7 Hari',
  `warranty_days` int NOT NULL DEFAULT 7,
  `delivery_type` enum('instant','manual') NOT NULL DEFAULT 'instant',
  `manual_stock` int NOT NULL DEFAULT 0,
  `price` decimal(14,2) NOT NULL DEFAULT 0,
  `original_price` decimal(14,2) NOT NULL DEFAULT 0,
  `rating` decimal(2,1) NOT NULL DEFAULT 5.0,
  `is_flash_sale` tinyint(1) NOT NULL DEFAULT 0,
  `flash_price` decimal(14,2) NOT NULL DEFAULT 0,
  `flash_ends_at` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `telegram_status` tinyint(1) NOT NULL DEFAULT 1,
  `telegram_sort_order` int NOT NULL DEFAULT 0,
  `thumbnail` varchar(500) DEFAULT NULL,
  `sold_count` int NOT NULL DEFAULT 0,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_slug` (`slug`),
  KEY `idx_product_category` (`category_id`),
  KEY `idx_product_public` (`status`,`is_archived`),
  KEY `idx_product_telegram` (`telegram_status`,`telegram_sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `promos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `type` enum('percent','fixed') NOT NULL DEFAULT 'fixed',
  `value` decimal(14,2) NOT NULL DEFAULT 0,
  `min_purchase` decimal(14,2) NOT NULL DEFAULT 0,
  `max_discount` decimal(14,2) NOT NULL DEFAULT 0,
  `usage_limit` int NOT NULL DEFAULT 0,
  `used_count` int NOT NULL DEFAULT 0,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_promo_code` (`code`),
  KEY `idx_promo_status_period` (`status`,`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `banners` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(180) DEFAULT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `button_text` varchar(100) DEFAULT NULL,
  `button_url` varchar(500) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_banner_status_sort` (`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `content` mediumtext NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_page_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice` varchar(80) NOT NULL,
  `sales_channel` varchar(24) NOT NULL DEFAULT 'website',
  `telegram_bot_id` bigint unsigned DEFAULT NULL,
  `telegram_user_id` bigint DEFAULT NULL,
  `telegram_chat_id` bigint DEFAULT NULL,
  `telegram_invoice_message_id` bigint DEFAULT NULL,
  `buyer_name` varchar(190) NOT NULL,
  `buyer_email` varchar(190) NOT NULL,
  `buyer_whatsapp` varchar(80) DEFAULT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0,
  `discount` decimal(14,2) NOT NULL DEFAULT 0,
  `telegram_unique_code` int unsigned NOT NULL DEFAULT 0,
  `promo_id` int unsigned DEFAULT NULL,
  `promo_counted_at` datetime DEFAULT NULL,
  `total` decimal(14,2) NOT NULL DEFAULT 0,
  `payment_fee` decimal(14,2) NOT NULL DEFAULT 0,
  `payment_total` decimal(14,2) NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `payment_provider` varchar(40) NOT NULL DEFAULT 'qris_static',
  `payment_reference` varchar(180) DEFAULT NULL,
  `payment_url` text NULL,
  `qris_payload` mediumtext NULL,
  `access_password_hash` varchar(255) DEFAULT NULL,
  `access_password_admin_enc` text NULL,
  `payment_expires_at` datetime DEFAULT NULL,
  `telegram_delivery_status` varchar(24) NOT NULL DEFAULT 'not_applicable',
  `telegram_delivery_attempts` int NOT NULL DEFAULT 0,
  `telegram_last_error` text NULL,
  `telegram_delivered_at` datetime DEFAULT NULL,
  `telegram_expired_notified_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_invoice` (`invoice`),
  KEY `idx_order_status_created` (`status`,`created_at`),
  KEY `idx_order_payment_reference` (`payment_reference`),
  KEY `idx_order_payment_provider` (`payment_provider`,`payment_reference`),
  KEY `idx_order_telegram_user` (`telegram_user_id`,`created_at`),
  KEY `idx_telegram_expired_notice` (`sales_channel`,`status`,`payment_expires_at`,`telegram_expired_notified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `product_name` varchar(190) NOT NULL,
  `warranty_label` varchar(100) DEFAULT NULL,
  `warranty_days` int NOT NULL DEFAULT 0,
  `warranty_started_at` datetime DEFAULT NULL,
  `warranty_expires_at` datetime DEFAULT NULL,
  `qty` int NOT NULL DEFAULT 1,
  `price` decimal(14,2) NOT NULL DEFAULT 0,
  `total` decimal(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_order_item_order` (`order_id`),
  KEY `idx_order_item_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_stocks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `content` mediumtext NOT NULL,
  `status` enum('available','reserved','sold','void') NOT NULL DEFAULT 'available',
  `reserved_order_id` int unsigned DEFAULT NULL,
  `reserved_order_item_id` int unsigned DEFAULT NULL,
  `reserved_at` datetime DEFAULT NULL,
  `reserved_until` datetime DEFAULT NULL,
  `sold_order_id` int unsigned DEFAULT NULL,
  `sold_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stock_product_status` (`product_id`,`status`),
  KEY `idx_stock_reservation` (`reserved_order_id`,`reserved_order_item_id`,`status`),
  KEY `idx_stock_reserved_until` (`status`,`reserved_until`),
  KEY `idx_stock_sold_order` (`sold_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `delivered_stocks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_item_id` int unsigned NOT NULL,
  `stock_id` int unsigned DEFAULT NULL,
  `content` mediumtext NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_delivered_order_item` (`order_item_id`),
  KEY `idx_delivered_stock` (`stock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(80) NOT NULL,
  `username` varchar(120) NOT NULL,
  `attempts` int NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_attempt` (`ip`,`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_access_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(80) NOT NULL,
  `lookup_key` varchar(190) NOT NULL,
  `action` varchar(40) NOT NULL DEFAULT 'password',
  `attempts` int NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_access_attempt` (`ip`,`lookup_key`,`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_rate_limits` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bucket` varchar(80) NOT NULL,
  `ip` varchar(80) NOT NULL,
  `identity_hash` varchar(190) NOT NULL,
  `attempts` int NOT NULL DEFAULT 0,
  `reset_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rate_limit` (`bucket`,`ip`,`identity_hash`),
  KEY `idx_reset_at` (`reset_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_audit_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice` varchar(80) DEFAULT NULL,
  `reference` varchar(190) DEFAULT NULL,
  `provider` varchar(40) NOT NULL DEFAULT 'tokopay',
  `event_status` varchar(80) DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `ip` varchar(80) DEFAULT NULL,
  `valid_signature` tinyint(1) NOT NULL DEFAULT 0,
  `message` varchar(255) DEFAULT NULL,
  `payload` mediumtext NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_invoice` (`invoice`),
  KEY `idx_audit_reference` (`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_notification_reads` (
  `admin_id` int unsigned NOT NULL,
  `last_read_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `last_error` text NULL,
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
  `state_data` longtext NULL,
  `last_ui_message_ids` longtext NULL,
  `keyboard_message_id` bigint DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `started_at` datetime DEFAULT NULL,
  `last_interaction_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_telegram_bot_user` (`bot_id`,`telegram_user_id`),
  KEY `idx_telegram_bot_chat` (`bot_id`,`chat_id`),
  KEY `idx_telegram_bot_active` (`bot_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `payload` longtext NULL,
  `error_message` text NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_telegram_log_bot` (`bot_id`,`created_at`),
  KEY `idx_telegram_log_user` (`telegram_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_delivery_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `channel` varchar(20) NOT NULL,
  `recipient` varchar(190) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `attempts` int NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `last_error` text NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_delivery_channel` (`order_id`,`channel`),
  KEY `idx_order_delivery_work` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_delivery_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `bot_id` bigint unsigned DEFAULT NULL,
  `telegram_user_id` bigint DEFAULT NULL,
  `chat_id` bigint DEFAULT NULL,
  `status` enum('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` int NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `last_error` text NULL,
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
  `image_url` text NULL,
  `button_text` varchar(80) DEFAULT NULL,
  `button_url` text NULL,
  `status` enum('draft','queued','sending','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  `total_count` int NOT NULL DEFAULT 0,
  `sent_count` int NOT NULL DEFAULT 0,
  `failed_count` int NOT NULL DEFAULT 0,
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
  `attempts` int NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `last_error` text NULL,
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
  `attempts` int NOT NULL DEFAULT 0,
  `next_attempt_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `last_error` text NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_telegram_migration_user` (`old_bot_id`,`new_bot_id`,`telegram_user_id`),
  UNIQUE KEY `uq_telegram_migration_token` (`token_hash`),
  KEY `idx_telegram_migration_work` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product variants + reviews (2026-10-04 product page overhaul)
-- Runtime equivalent: includes/helpers.php ensure_product_variant_columns(),
-- ensure_product_reviews_table(), ensure_product_default_variants().
-- Product variants + reviews (2026-10-04 product page overhaul; variant-level pricing 2026-10-22)
-- Runtime equivalent: includes/helpers.php ensure_product_variant_columns(),
-- ensure_variant_level_pricing_backfill(), ensure_product_reviews_table(),
-- ensure_product_default_variants().
CREATE TABLE IF NOT EXISTS `product_variants` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `discount_pct` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `flash_discount_pct` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `warranty_label` VARCHAR(100) NOT NULL DEFAULT '',
  `warranty_days` INT NOT NULL DEFAULT 0,
  `price_delta` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `original_price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `manual_stock` INT NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NULL, `updated_at` DATETIME NULL,
  KEY `idx_variant_product` (`product_id`,`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_reviews` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `comment` TEXT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `ip` VARCHAR(80) NOT NULL DEFAULT '',
  `created_at` DATETIME NULL, `moderated_at` DATETIME NULL,
  `order_id` INT UNSIGNED NULL,
  `order_item_id` INT UNSIGNED NULL,
  KEY `idx_review_product_status` (`product_id`,`status`,`created_at`),
  KEY `idx_review_order` (`order_id`),
  UNIQUE KEY `uq_review_order_item` (`order_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- product_stocks.variant_id / order_items.variant_id+variant_name are added by the
-- runtime ensure_* helpers (db_column_exists guards) on existing installs; fresh
-- installs get them from the CREATE statements above plus the runtime ALTER path.
-- Backfill (runs automatically via ensure_product_default_variants):
--   1) products with zero variants -> "Default" variant
--   2) manual products: Default.manual_stock = products.manual_stock
--   3) instant products: untagged product_stocks rows tagged to Default
--   4) legacy manual order_items tagged to Default variant
