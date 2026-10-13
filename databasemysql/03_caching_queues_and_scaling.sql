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
