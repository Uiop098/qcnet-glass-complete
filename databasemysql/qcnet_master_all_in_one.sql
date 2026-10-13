-- =============================================================================
-- QCNet Universal Business Platform & QuickChat Hardened Engine
-- Master All-In-One Unified Database Installation Script
-- =============================================================================
-- Generated: 2026-10-13
-- Target DBMS: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+
-- Character Set: utf8mb4 / Collation: utf8mb4_unicode_ci
-- Architecture: Multi-Tenant Enterprise Core, Realtime Chat, CRM, Billing,
--               Theme Engine, Caching, Asynchronous Queues, and Security Center
-- =============================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================================
-- QCNet Universal Business Platform - Database Initialization
-- File: 00_init_database.sql
-- Compatibility: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+
-- ============================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Create production and local databases if not exist
CREATE DATABASE IF NOT EXISTS `qcnet_db`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `qcnet_db`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- QCNet Universal Business Platform - Database Migration 01
-- File: 01_quickchat_core.sql
-- Module: QuickChat Core & Realtime Engine Schema
-- Compatibility: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+
-- ============================================================

USE `qcnet_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. Rooms Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rooms` (
    `id`                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `room`                VARCHAR(190) NOT NULL,
    `title`               VARCHAR(255) NOT NULL DEFAULT '',
    `pin_hash`            VARCHAR(255) NULL,
    `host_password_hash`  VARCHAR(255) NULL,
    `enc_enabled`         TINYINT(1) NOT NULL DEFAULT 0,
    `enc_salt`            VARCHAR(255) NULL,
    `enc_super_recovery`  TEXT NULL,
    `is_premium`          TINYINT(1) NOT NULL DEFAULT 0,
    `premium_owner`       VARCHAR(190) NULL,
    `premium_tier`        VARCHAR(20) NULL,
    `pinned_message_id`   BIGINT UNSIGNED NULL,
    `created_at`          BIGINT UNSIGNED NOT NULL,
    `last_activity`       BIGINT UNSIGNED NULL,
    `pending_delete_at`   BIGINT UNSIGNED NULL,
    `extra_json`          LONGTEXT NULL,
    UNIQUE KEY `uniq_room` (`room`),
    KEY `idx_last_activity` (`last_activity`),
    KEY `idx_premium_owner` (`premium_owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Messages Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
    `id`                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `room_id`             BIGINT UNSIGNED NOT NULL,
    `local_id`            BIGINT UNSIGNED NOT NULL,
    `user`                VARCHAR(190) NOT NULL,
    `role`                VARCHAR(20)  NOT NULL DEFAULT 'member',
    `type`                VARCHAR(20)  NOT NULL DEFAULT 'text',
    `text`                MEDIUMTEXT NULL,
    `file_url`            VARCHAR(500) NULL,
    `file_name`           VARCHAR(255) NULL,
    `file_size`           BIGINT UNSIGNED NULL,
    `file_category`       VARCHAR(20) NULL,
    `file_ext`            VARCHAR(20) NULL,
    `is_voice_message`    TINYINT(1) NOT NULL DEFAULT 0,
    `sent_while_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
    `sticker_id`          VARCHAR(50) NULL,
    `sticker_emoji`       VARCHAR(20) NULL,
    `poll_id`             VARCHAR(50) NULL,
    `reply_to_json`       TEXT NULL,
    `reactions_json`      TEXT NULL,
    `edited`              TINYINT(1) NOT NULL DEFAULT 0,
    `edited_at`           BIGINT UNSIGNED NULL,
    `deleted`             TINYINT(1) NOT NULL DEFAULT 0,
    `ts`                  BIGINT UNSIGNED NOT NULL,
    `expires_at`          BIGINT UNSIGNED NULL,
    FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_room_local` (`room_id`, `local_id`),
    KEY `idx_room_ts` (`room_id`, `ts`),
    KEY `idx_user` (`user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Sessions Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
    `token`       VARCHAR(64) NOT NULL PRIMARY KEY,
    `room`        VARCHAR(190) NOT NULL,
    `user`        VARCHAR(190) NOT NULL,
    `role`        VARCHAR(20) NOT NULL,
    `tier`        VARCHAR(20) NULL,
    `created_at`  BIGINT UNSIGNED NOT NULL,
    `expires_at`  BIGINT UNSIGNED NOT NULL,
    KEY `idx_expires` (`expires_at`),
    KEY `idx_room_user` (`room`, `user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Pending Orders Table (Payment Verification Staging)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_orders` (
    `order_id`       VARCHAR(190) NOT NULL PRIMARY KEY,
    `email`          VARCHAR(190) NOT NULL,
    `name`           VARCHAR(190) NOT NULL,
    `phone`          VARCHAR(50) NULL,
    `dob`            VARCHAR(20) NULL,
    `google_id`      VARCHAR(100) NULL,
    `amount`         DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    `status`         VARCHAR(30) NOT NULL DEFAULT 'PENDING',
    `registered`     TINYINT(1) NOT NULL DEFAULT 0,
    `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`     BIGINT UNSIGNED NOT NULL,
    KEY `idx_email` (`email`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. OAuth States Table (CSRF & Intent Protection)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `oauth_states` (
    `state_token` VARCHAR(64) NOT NULL PRIMARY KEY,
    `intent`      VARCHAR(30) NOT NULL,
    `room`        VARCHAR(190) NULL,
    `expires_at`  BIGINT UNSIGNED NOT NULL,
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. Dash Tenants Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dash_tenants` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`      VARCHAR(190) NOT NULL,
    `admin_username` VARCHAR(190) NOT NULL,
    `password_hash`  VARCHAR(255) NOT NULL,
    `display_name`   VARCHAR(190) NULL,
    `created_at`     BIGINT UNSIGNED NOT NULL,
    `extra_json`     LONGTEXT NULL,
    UNIQUE KEY `uniq_tenant` (`tenant_id`),
    KEY `idx_admin_user` (`admin_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. Dash Users Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dash_users` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     VARCHAR(190) NOT NULL,
    `username`      VARCHAR(190) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `name`          VARCHAR(190) NULL,
    `created_at`    BIGINT UNSIGNED NOT NULL,
    `extra_json`    LONGTEXT NULL,
    UNIQUE KEY `uniq_tenant_user` (`tenant_id`, `username`),
    FOREIGN KEY (`tenant_id`) REFERENCES `dash_tenants`(`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. File Links Table (Cross-domain Large Files)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `file_links` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `link_token`    VARCHAR(255) NOT NULL,
    `origin_domain` VARCHAR(255) NOT NULL,
    `local_path`    VARCHAR(500) NOT NULL,
    `file_name`     VARCHAR(255) NOT NULL,
    `file_size`     BIGINT UNSIGNED NOT NULL,
    `mime_type`     VARCHAR(100) NOT NULL,
    `room_id`       BIGINT UNSIGNED NULL,
    `created_at`    BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY `uniq_link_token` (`link_token`(191)),
    KEY `idx_room` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. File Blobs Table (Direct Encrypted Database Storage)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `file_blobs` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `file_name`      VARCHAR(255) NOT NULL,
    `file_size`      BIGINT UNSIGNED NOT NULL,
    `mime_type`      VARCHAR(100) NOT NULL,
    `data_encrypted` LONGBLOB NOT NULL,
    `iv`             VARBINARY(16) NOT NULL,
    `auth_tag`       VARBINARY(16) NOT NULL,
    `room_id`        BIGINT UNSIGNED NULL,
    `created_at`     BIGINT UNSIGNED NOT NULL,
    KEY `idx_room` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. Secure Tables (Encrypted Key-Value Store)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `secure_tables` (
    `path_key`       VARCHAR(500) NOT NULL PRIMARY KEY,
    `data_encrypted` LONGTEXT NOT NULL,
    `updated_at`     BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. QR Tokens Table (Temporary Upload & Room Join Tokens)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `qr_tokens` (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `token`      VARCHAR(64) NOT NULL,
    `kind`       ENUM('upload', 'join') NOT NULL DEFAULT 'upload',
    `room`       VARCHAR(190) NOT NULL,
    `created_by` VARCHAR(190) NULL,
    `created_at` BIGINT UNSIGNED NOT NULL,
    `expires_at` BIGINT UNSIGNED NOT NULL,
    `used_at`    BIGINT UNSIGNED NULL,
    UNIQUE KEY `uniq_token` (`token`),
    KEY `idx_expires` (`expires_at`),
    KEY `idx_room_kind` (`room`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- QCNet Universal Business Platform - Database Migration 02
-- File: 02_universal_business_platform.sql
-- Module: Universal Multi-Tenant Business Platform, E-Commerce & CRM
-- Compatibility: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+
-- ============================================================

USE `qcnet_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. Multi-Tenant Organizations / Businesses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenants` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`      VARCHAR(100) NOT NULL,
    `company_name`   VARCHAR(255) NOT NULL,
    `domain`         VARCHAR(190) NULL,
    `plan`           ENUM('starter', 'professional', 'enterprise') NOT NULL DEFAULT 'starter',
    `status`         ENUM('active', 'suspended', 'trial', 'cancelled') NOT NULL DEFAULT 'active',
    `currency`       VARCHAR(10) NOT NULL DEFAULT 'INR',
    `logo_url`       VARCHAR(500) NULL,
    `settings_json`  LONGTEXT NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_tenant_slug` (`tenant_id`),
    UNIQUE KEY `uniq_domain` (`domain`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Platform Users (Admins, Staff, Customers)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`             VARCHAR(100) NOT NULL DEFAULT 'main',
    `username`              VARCHAR(100) NOT NULL,
    `email`                 VARCHAR(190) NOT NULL,
    `password_hash`         VARCHAR(255) NOT NULL,
    `full_name`             VARCHAR(190) NOT NULL DEFAULT '',
    `phone`                 VARCHAR(50) NULL,
    `dob`                   DATE NULL,
    `role`                  ENUM('super_admin', 'tenant_admin', 'manager', 'staff', 'customer') NOT NULL DEFAULT 'staff',
    `status`                ENUM('active', 'inactive', 'suspended', 'pending') NOT NULL DEFAULT 'active',
    `twofa_enabled`         TINYINT(1) NOT NULL DEFAULT 0,
    `twofa_secret`          VARCHAR(64) NULL,
    `twofa_recovery_codes`  TEXT NULL,
    `google_id`             VARCHAR(100) NULL,
    `email_verified_at`     TIMESTAMP NULL,
    `last_login_at`         TIMESTAMP NULL,
    `last_login_ip`         VARCHAR(45) NULL,
    `metadata_json`         LONGTEXT NULL,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_tenant_username` (`tenant_id`, `username`),
    UNIQUE KEY `uniq_tenant_email` (`tenant_id`, `email`),
    KEY `idx_email` (`email`),
    KEY `idx_google_id` (`google_id`),
    KEY `idx_role` (`role`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Roles System
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`   VARCHAR(100) NOT NULL DEFAULT 'main',
    `slug`        VARCHAR(100) NOT NULL,
    `name`        VARCHAR(190) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_system`   TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_tenant_slug` (`tenant_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Permissions Master Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `permissions` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug`        VARCHAR(100) NOT NULL,
    `module`      VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    UNIQUE KEY `uniq_permission_slug` (`slug`),
    KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. Role Permissions Pivot Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id`       BIGINT UNSIGNED NOT NULL,
    `permission_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. User Roles Pivot Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_roles` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `role_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`user_id`, `role_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. Product Categories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`   VARCHAR(100) NOT NULL DEFAULT 'main',
    `parent_id`   BIGINT UNSIGNED NULL,
    `name`        VARCHAR(190) NOT NULL,
    `slug`        VARCHAR(190) NOT NULL,
    `description` TEXT NULL,
    `image_url`   VARCHAR(500) NULL,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `status`      ENUM('active', 'hidden') NOT NULL DEFAULT 'active',
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_tenant_cat_slug` (`tenant_id`, `slug`),
    FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. Products & Services Catalog
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`       VARCHAR(100) NOT NULL DEFAULT 'main',
    `category_id`     BIGINT UNSIGNED NULL,
    `sku`             VARCHAR(100) NULL,
    `name`            VARCHAR(255) NOT NULL,
    `slug`            VARCHAR(255) NOT NULL,
    `description`     LONGTEXT NULL,
    `price`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `sale_price`      DECIMAL(12,2) NULL,
    `cost_price`      DECIMAL(12,2) NULL,
    `stock_qty`       INT NOT NULL DEFAULT 0,
    `track_inventory` TINYINT(1) NOT NULL DEFAULT 1,
    `is_digital`      TINYINT(1) NOT NULL DEFAULT 0,
    `status`          ENUM('active', 'draft', 'archived', 'out_of_stock') NOT NULL DEFAULT 'active',
    `images_json`     LONGTEXT NULL,
    `metadata_json`   LONGTEXT NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_tenant_product_slug` (`tenant_id`, `slug`),
    KEY `idx_tenant_sku` (`tenant_id`, `sku`),
    KEY `idx_category` (`category_id`),
    KEY `idx_status` (`status`),
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. Customers CRM
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`    VARCHAR(100) NOT NULL DEFAULT 'main',
    `user_id`      BIGINT UNSIGNED NULL,
    `first_name`   VARCHAR(100) NOT NULL,
    `last_name`    VARCHAR(100) NOT NULL DEFAULT '',
    `email`        VARCHAR(190) NOT NULL,
    `phone`        VARCHAR(50) NULL,
    `company`      VARCHAR(190) NULL,
    `address`      VARCHAR(255) NULL,
    `city`         VARCHAR(100) NULL,
    `state`        VARCHAR(100) NULL,
    `postal_code`  VARCHAR(20) NULL,
    `country`      VARCHAR(100) NOT NULL DEFAULT 'India',
    `total_spent`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `orders_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `notes`        TEXT NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_tenant_customer_email` (`tenant_id`, `email`),
    KEY `idx_phone` (`phone`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. Store & Business Orders
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
    `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_number`          VARCHAR(100) NOT NULL,
    `tenant_id`             VARCHAR(100) NOT NULL DEFAULT 'main',
    `customer_id`           BIGINT UNSIGNED NULL,
    `subtotal`              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_amount`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `discount_amount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_amount`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency`              VARCHAR(10) NOT NULL DEFAULT 'INR',
    `status`                ENUM('pending', 'processing', 'completed', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
    `payment_status`        ENUM('unpaid', 'paid', 'partially_paid', 'failed', 'refunded') NOT NULL DEFAULT 'unpaid',
    `payment_method`        VARCHAR(50) NOT NULL DEFAULT 'cashfree',
    `payment_gateway_ref`   VARCHAR(190) NULL,
    `shipping_status`       ENUM('pending', 'shipped', 'delivered', 'returned') NOT NULL DEFAULT 'pending',
    `shipping_address_json` LONGTEXT NULL,
    `billing_address_json`  LONGTEXT NULL,
    `notes`                 TEXT NULL,
    `placed_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_order_number` (`order_number`),
    KEY `idx_tenant_order` (`tenant_id`, `status`),
    KEY `idx_customer` (`customer_id`),
    KEY `idx_placed_at` (`placed_at`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. Order Line Items
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_items` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id`      BIGINT UNSIGNED NOT NULL,
    `product_id`    BIGINT UNSIGNED NULL,
    `product_name`  VARCHAR(255) NOT NULL,
    `sku`           VARCHAR(100) NULL,
    `unit_price`    DECIMAL(12,2) NOT NULL,
    `quantity`      INT UNSIGNED NOT NULL DEFAULT 1,
    `total_price`   DECIMAL(12,2) NOT NULL,
    `metadata_json` LONGTEXT NULL,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL,
    KEY `idx_order_item` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12. Invoices & Billing
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoices` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(100) NOT NULL,
    `order_id`       BIGINT UNSIGNED NULL,
    `tenant_id`      VARCHAR(100) NOT NULL DEFAULT 'main',
    `customer_id`    BIGINT UNSIGNED NULL,
    `issue_date`     DATE NOT NULL,
    `due_date`       DATE NOT NULL,
    `paid_at`        TIMESTAMP NULL,
    `subtotal`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `status`         ENUM('draft', 'sent', 'paid', 'overdue', 'void') NOT NULL DEFAULT 'draft',
    `pdf_url`        VARCHAR(500) NULL,
    `notes`          TEXT NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_invoice_number` (`invoice_number`),
    KEY `idx_tenant_invoice` (`tenant_id`, `status`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13. Discount Coupons & Vouchers
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coupons` (
    `id`                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`           VARCHAR(100) NOT NULL DEFAULT 'main',
    `code`                VARCHAR(50) NOT NULL,
    `discount_type`       ENUM('fixed', 'percent') NOT NULL DEFAULT 'percent',
    `discount_value`      DECIMAL(10,2) NOT NULL,
    `min_order_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `max_discount_amount` DECIMAL(10,2) NULL,
    `usage_limit`         INT UNSIGNED NULL,
    `used_count`          INT UNSIGNED NOT NULL DEFAULT 0,
    `starts_at`           TIMESTAMP NULL,
    `expires_at`          TIMESTAMP NULL,
    `status`              ENUM('active', 'expired', 'disabled') NOT NULL DEFAULT 'active',
    `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_tenant_coupon` (`tenant_id`, `code`),
    KEY `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 14. Persistent Shopping Carts
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `carts` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     VARCHAR(100) NOT NULL DEFAULT 'main',
    `session_token` VARCHAR(64) NOT NULL,
    `user_id`       BIGINT UNSIGNED NULL,
    `items_json`    LONGTEXT NOT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `expires_at`    TIMESTAMP NOT NULL,
    UNIQUE KEY `uniq_session` (`session_token`),
    KEY `idx_user` (`user_id`),
    KEY `idx_expires` (`expires_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 15. UI / UX Theme Engine & Preferences
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_theme_settings` (
    `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`             VARCHAR(100) NOT NULL,
    `theme_mode`            ENUM('dark', 'light', 'cyberpunk', 'glassmorphic', 'nord', 'high_contrast') NOT NULL DEFAULT 'dark',
    `accent_color`          VARCHAR(30) NOT NULL DEFAULT '#8b5cf6',
    `secondary_color`       VARCHAR(30) NOT NULL DEFAULT '#6366f1',
    `bg_gradient`           VARCHAR(255) NULL,
    `glass_opacity`         DECIMAL(3,2) NOT NULL DEFAULT 0.85,
    `glass_blur_px`         INT NOT NULL DEFAULT 16,
    `motion_level`          ENUM('full', 'reduced', 'none') NOT NULL DEFAULT 'full',
    `font_family`           VARCHAR(100) NOT NULL DEFAULT 'Inter, system-ui, sans-serif',
    `custom_css`            LONGTEXT NULL,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_tenant_theme` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- QCNet Universal Business Platform - Database Migration 03
-- File: 03_caching_queues_and_scaling.sql
-- Module: Caching Layer, Asynchronous Queue Workers & Cluster Scaling
-- Compatibility: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+
-- ============================================================

USE `qcnet_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. Database-Backed High-Performance Cache Layer
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cache_items` (
    `cache_key`   VARCHAR(190) NOT NULL PRIMARY KEY,
    `cache_value` MEDIUMTEXT NOT NULL,
    `tags`        VARCHAR(255) NULL,
    `expires_at`  BIGINT UNSIGNED NOT NULL,
    `created_at`  BIGINT UNSIGNED NOT NULL,
    KEY `idx_expires` (`expires_at`),
    KEY `idx_tags` (`tags`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Asynchronous Background Jobs Queue
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jobs` (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `queue`        VARCHAR(100) NOT NULL DEFAULT 'default',
    `payload`      LONGTEXT NOT NULL,
    `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `reserved_at`  BIGINT UNSIGNED NULL,
    `available_at` BIGINT UNSIGNED NOT NULL,
    `created_at`   BIGINT UNSIGNED NOT NULL,
    KEY `idx_queue_reserved_available` (`queue`, `reserved_at`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Dead-Letter Queue (Failed Jobs Archive)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid`       VARCHAR(64) NOT NULL,
    `connection` VARCHAR(50) NOT NULL DEFAULT 'database',
    `queue`      VARCHAR(100) NOT NULL DEFAULT 'default',
    `payload`    LONGTEXT NOT NULL,
    `exception`  LONGTEXT NOT NULL,
    `failed_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_uuid` (`uuid`),
    KEY `idx_queue` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Job Batches (Parallel Async Job Orchestration)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `job_batches` (
    `id`             VARCHAR(64) NOT NULL PRIMARY KEY,
    `name`           VARCHAR(190) NOT NULL,
    `total_jobs`     INT NOT NULL,
    `pending_jobs`   INT NOT NULL,
    `failed_jobs`    INT NOT NULL,
    `failed_job_ids` LONGTEXT NULL,
    `options`        LONGTEXT NULL,
    `cancelled_at`   BIGINT UNSIGNED NULL,
    `created_at`     BIGINT UNSIGNED NOT NULL,
    `finished_at`    BIGINT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. Cluster & Multi-Node Scaling Registry
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scaling_nodes` (
    `id`                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `node_identifier`    VARCHAR(100) NOT NULL,
    `ip_address`         VARCHAR(45) NOT NULL,
    `port`               INT UNSIGNED NOT NULL DEFAULT 8080,
    `role`               ENUM('master', 'worker', 'edge', 'websocket') NOT NULL DEFAULT 'worker',
    `status`             ENUM('online', 'degraded', 'offline', 'draining') NOT NULL DEFAULT 'online',
    `cpu_usage_pct`      DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `memory_usage_mb`    INT UNSIGNED NOT NULL DEFAULT 0,
    `active_connections` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_heartbeat_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `registered_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_node_identifier` (`node_identifier`),
    KEY `idx_status_heartbeat` (`status`, `last_heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. Event Webhooks (Outbound Integrations)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `webhooks` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     VARCHAR(100) NOT NULL DEFAULT 'main',
    `name`          VARCHAR(190) NOT NULL,
    `target_url`    VARCHAR(500) NOT NULL,
    `secret`        VARCHAR(100) NOT NULL,
    `events_json`   LONGTEXT NOT NULL,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `failure_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_tenant_active` (`tenant_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. Webhook Delivery Logs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `webhook_id`    BIGINT UNSIGNED NOT NULL,
    `event_name`    VARCHAR(100) NOT NULL,
    `payload_json`  LONGTEXT NOT NULL,
    `response_code` INT NULL,
    `response_body` TEXT NULL,
    `duration_ms`   INT UNSIGNED NOT NULL DEFAULT 0,
    `attempt`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `status`        ENUM('success', 'failed', 'retrying') NOT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`webhook_id`) REFERENCES `webhooks`(`id`) ON DELETE CASCADE,
    KEY `idx_webhook_status` (`webhook_id`, `status`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- QCNet Universal Business Platform - Database Migration 04
-- File: 04_security_center_and_audit.sql
-- Module: Security Center, Threat Detector, IP Firewall & Audit Logs
-- Compatibility: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+
-- ============================================================

USE `qcnet_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. System Audit Logs & Tamper-Evident Activity Trail
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`     VARCHAR(100) NOT NULL DEFAULT 'main',
    `actor_type`    ENUM('admin', 'user', 'system', 'api') NOT NULL,
    `actor_id`      VARCHAR(100) NULL,
    `actor_name`    VARCHAR(190) NOT NULL,
    `action`        VARCHAR(100) NOT NULL,
    `resource_type` VARCHAR(100) NULL,
    `resource_id`   VARCHAR(100) NULL,
    `details_json`  LONGTEXT NULL,
    `ip_address`    VARCHAR(45) NOT NULL,
    `user_agent`    VARCHAR(255) NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_tenant_action` (`tenant_id`, `action`),
    KEY `idx_actor` (`actor_type`, `actor_name`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Security Threat & Intrusion Detection Incidents
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `security_threats` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `threat_level`    ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    `threat_type`     VARCHAR(100) NOT NULL,
    `source_ip`       VARCHAR(45) NOT NULL,
    `request_uri`     VARCHAR(500) NOT NULL,
    `request_method`  VARCHAR(10) NOT NULL,
    `payload_excerpt` TEXT NULL,
    `action_taken`    ENUM('logged', 'throttled', 'blocked', 'banned') NOT NULL DEFAULT 'blocked',
    `resolved`        TINYINT(1) NOT NULL DEFAULT 0,
    `resolved_by`     VARCHAR(100) NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_threat_level` (`threat_level`),
    KEY `idx_source_ip` (`source_ip`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Firewall Blocked IP Addresses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blocked_ips` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address`    VARCHAR(45) NOT NULL,
    `reason`        VARCHAR(255) NOT NULL,
    `blocked_by`    VARCHAR(100) NOT NULL DEFAULT 'system_firewall',
    `blocked_until` TIMESTAMP NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_ip` (`ip_address`),
    KEY `idx_blocked_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Rate Limiting Buckets
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ip_rate_limits` (
    `id`               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address`       VARCHAR(45) NOT NULL,
    `action_key`       VARCHAR(100) NOT NULL,
    `hits`             INT UNSIGNED NOT NULL DEFAULT 1,
    `window_starts_at` BIGINT UNSIGNED NOT NULL,
    `expires_at`       BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY `uniq_ip_action_window` (`ip_address`, `action_key`, `window_starts_at`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. Login Forensics & Authentication History
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_history` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`        BIGINT UNSIGNED NULL,
    `username`       VARCHAR(100) NOT NULL,
    `tenant_id`      VARCHAR(100) NOT NULL DEFAULT 'main',
    `ip_address`     VARCHAR(45) NOT NULL,
    `user_agent`     VARCHAR(255) NULL,
    `status`         ENUM('success', 'failed', 'challenge_2fa') NOT NULL,
    `failure_reason` VARCHAR(255) NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_username` (`username`),
    KEY `idx_ip` (`ip_address`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. API Access Tokens (Machine-to-Machine & Personal Tokens)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_id`      VARCHAR(100) NOT NULL DEFAULT 'main',
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `name`           VARCHAR(100) NOT NULL,
    `token_hash`     VARCHAR(64) NOT NULL,
    `abilities_json` TEXT NULL,
    `last_used_at`   TIMESTAMP NULL,
    `expires_at`     TIMESTAMP NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_token_hash` (`token_hash`),
    KEY `idx_user_tokens` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- QCNet Universal Business Platform - Database Migration 05
-- File: 05_seed_and_demo_data.sql
-- Module: Default System Roles, Permissions, Admin & Demo Data
-- Compatibility: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+
-- ============================================================

USE `qcnet_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. Core Permissions
-- ------------------------------------------------------------
INSERT INTO `permissions` (`slug`, `module`, `description`) VALUES
('users.view',        'users',       'View user list and user details'),
('users.create',      'users',       'Create new user accounts'),
('users.edit',        'users',       'Update user profiles and roles'),
('users.delete',      'users',       'Deactivate or delete users'),
('tenants.manage',    'tenants',     'Create and configure tenant portals'),
('products.view',     'catalog',     'View catalog products and categories'),
('products.manage',   'catalog',     'Create, edit, and delete products'),
('orders.view',       'orders',      'View orders, invoices, and transactions'),
('orders.manage',     'orders',      'Process orders, refunds, and status updates'),
('reports.view',      'analytics',   'View business metrics, KPIs, and charts'),
('reports.export',    'analytics',   'Export financial and operational reports'),
('security.view',     'security',    'Access Security Center and threat logs'),
('security.manage',   'security',    'Manage firewall, IP bans, and rate limits'),
('settings.manage',   'settings',    'Update system configurations and themes'),
('chat.manage',       'quickchat',   'Moderate rooms, purge messages, ban users')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- ------------------------------------------------------------
-- 2. System Roles
-- ------------------------------------------------------------
INSERT INTO `roles` (`tenant_id`, `slug`, `name`, `description`, `is_system`) VALUES
('main', 'super_admin',  'Super Administrator',  'Unrestricted access across all tenants and subsystems', 1),
('main', 'tenant_admin', 'Tenant Administrator', 'Complete management of assigned tenant portal', 1),
('main', 'store_manager','Store Manager',        'Manage products, inventory, orders, and customers', 1),
('main', 'support_agent','Support Agent',        'Access orders, customer assistance, and chat rooms', 1),
('main', 'member',       'General Member',       'Standard customer or authenticated chat participant', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ------------------------------------------------------------
-- 3. Map Super Admin to All Permissions
-- ------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.slug = 'super_admin' AND r.tenant_id = 'main';

-- ------------------------------------------------------------
-- 4. Default Main Tenant
-- ------------------------------------------------------------
INSERT INTO `tenants` (`tenant_id`, `company_name`, `domain`, `plan`, `status`, `currency`, `settings_json`) VALUES
('main', 'QCNet Enterprise Global', 'qcnet.local', 'enterprise', 'active', 'INR',
 '{"timezone":"Asia/Kolkata","support_email":"support@qcnet.local","enable_2fa":true,"maintenance_mode":false}')
ON DUPLICATE KEY UPDATE `company_name` = VALUES(`company_name`);

-- ------------------------------------------------------------
-- 5. Seed Default Super Admin User (xbot / #123xbot)
-- ------------------------------------------------------------
INSERT INTO `users` (`tenant_id`, `username`, `email`, `password_hash`, `full_name`, `role`, `status`, `twofa_enabled`, `email_verified_at`) VALUES
('main', 'xbot', 'admin@qcnet.local', '$2y$12$CDFipyxSkFGA9B.W4BX9V.1bV8fHMmehXVMISjOBgD6ZLBMJ05R1y', 'Master System Administrator', 'super_admin', 'active', 0, NOW())
ON DUPLICATE KEY UPDATE `role` = 'super_admin';

-- Assign super_admin role
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`)
SELECT u.id, r.id
FROM `users` u, `roles` r
WHERE u.username = 'xbot' AND u.tenant_id = 'main' AND r.slug = 'super_admin' AND r.tenant_id = 'main';

-- Sync with dash_tenants and dash_users for QuickChat backward compatibility
INSERT INTO `dash_tenants` (`tenant_id`, `admin_username`, `password_hash`, `display_name`, `created_at`) VALUES
('main', 'xbot', '$2y$12$CDFipyxSkFGA9B.W4BX9V.1bV8fHMmehXVMISjOBgD6ZLBMJ05R1y', 'QCNet Enterprise Global', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`);

INSERT INTO `dash_users` (`tenant_id`, `username`, `password_hash`, `name`, `created_at`) VALUES
('main', 'xbot', '$2y$12$CDFipyxSkFGA9B.W4BX9V.1bV8fHMmehXVMISjOBgD6ZLBMJ05R1y', 'Master System Administrator', UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ------------------------------------------------------------
-- 6. Seed Default Theme Engine Settings
-- ------------------------------------------------------------
INSERT INTO `tenant_theme_settings` (`tenant_id`, `theme_mode`, `accent_color`, `secondary_color`, `glass_opacity`, `glass_blur_px`, `motion_level`, `font_family`) VALUES
('main', 'dark', '#8b5cf6', '#6366f1', 0.85, 16, 'full', 'Inter, system-ui, sans-serif')
ON DUPLICATE KEY UPDATE `accent_color` = VALUES(`accent_color`);

-- ------------------------------------------------------------
-- 7. Seed Demo Catalog & Categories
-- ------------------------------------------------------------
INSERT INTO `categories` (`tenant_id`, `name`, `slug`, `description`, `sort_order`, `status`) VALUES
('main', 'Enterprise Cloud Services', 'enterprise-cloud', 'High security cloud hosting and communication nodes', 1, 'active'),
('main', 'Security Addons', 'security-addons', 'End-to-end encryption keys and dedicated firewall proxies', 2, 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `products` (`tenant_id`, `category_id`, `sku`, `name`, `slug`, `description`, `price`, `sale_price`, `stock_qty`, `is_digital`, `status`)
SELECT 'main', c.id, 'QCN-ENT-001', 'QCNet Custom Business Portal License', 'qcnet-business-portal-license', 'Full enterprise white-label portal license with unlimited users and encrypted chat engine.', 1.00, 1.00, 9999, 1, 'active'
FROM `categories` c WHERE c.slug = 'enterprise-cloud' AND c.tenant_id = 'main'
ON DUPLICATE KEY UPDATE `price` = VALUES(`price`);

INSERT INTO `products` (`tenant_id`, `category_id`, `sku`, `name`, `slug`, `description`, `price`, `sale_price`, `stock_qty`, `is_digital`, `status`)
SELECT 'main', c.id, 'QCN-SEC-002', 'Zero-Knowledge Cryptographic Vault', 'zero-knowledge-vault', 'Military grade hardware-backed key derivation and blob storage partition.', 299.00, 249.00, 500, 1, 'active'
FROM `categories` c WHERE c.slug = 'security-addons' AND c.tenant_id = 'main'
ON DUPLICATE KEY UPDATE `price` = VALUES(`price`);

-- ------------------------------------------------------------
-- 8. Seed Default Chat Rooms
-- ------------------------------------------------------------
INSERT INTO `rooms` (`room`, `title`, `pin_hash`, `enc_enabled`, `is_premium`, `created_at`, `last_activity`) VALUES
('general', 'Main Public Community', NULL, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('support', '24/7 Enterprise Client Helpdesk', NULL, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('vip_lounge', 'VIP Encrypted Confidential Lounge', NULL, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

SET FOREIGN_KEY_CHECKS = 1;
SET FOREIGN_KEY_CHECKS = 1;
