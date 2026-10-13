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
