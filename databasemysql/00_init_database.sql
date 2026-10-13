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
