-- Migration: tabel blog (kategori + artikel) untuk halaman Blog publik dan admin panel.
-- Jalankan sekali pada database toko; fungsi ensure_blog_tables() di includes/helpers.php
-- juga membuat/menambah kolom ini otomatis saat halaman blog diakses.

CREATE TABLE IF NOT EXISTS `blog_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `description` varchar(255) NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blog_category_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `excerpt` varchar(500) NULL,
  `content` mediumtext NULL,
  `cover_image` varchar(500) NULL,
  `cover_color` varchar(16) NOT NULL DEFAULT '#6366f1',
  `author` varchar(100) NOT NULL DEFAULT 'Admin',
  `read_time` int NOT NULL DEFAULT 3,
  `tags` varchar(255) NULL,
  `category_id` int NULL,
  `meta_title` varchar(180) NULL,
  `meta_description` varchar(300) NULL,
  `meta_keywords` varchar(300) NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blog_post_slug` (`slug`),
  KEY `idx_blog_post_status_pub` (`status`,`published_at`),
  KEY `idx_blog_post_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
