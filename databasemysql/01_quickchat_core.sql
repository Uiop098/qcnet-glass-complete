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
