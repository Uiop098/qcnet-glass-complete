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
