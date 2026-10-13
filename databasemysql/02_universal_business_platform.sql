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
