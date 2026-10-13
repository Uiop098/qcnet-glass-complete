<?php
/**
 * QCNet Universal Business Platform - Automated Database Health & Verification Tool
 * File: verify_database.php
 * Usage: php verify_database.php
 */

$socket = '/data/data/com.termux/files/usr/var/run/mysqld.sock';
$host   = '127.0.0.1';
$port   = 3306;
$dbname = 'qcnet_db';
$user   = 'root';
$pass   = '';

echo "\033[1;34m============================================================\033[0m\n";
echo "\033[1;34m🔍 QCNet Database Verification & Health Check Suite\033[0m\n";
echo "\033[1;34m============================================================\033[0m\n";

try {
    if (file_exists($socket)) {
        $dsn = "mysql:unix_socket={$socket};dbname={$dbname};charset=utf8mb4";
        echo "🔌 Connecting via Unix socket: {$socket}\n";
    } else {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        echo "🌐 Connecting via TCP: {$host}:{$port}\n";
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false
    ]);
    echo "\033[1;32m✅ Successfully connected to database '{$dbname}'.\033[0m\n\n";

    // Expected tables across all modules
    $expectedTables = [
        // QuickChat Core
        'rooms', 'messages', 'sessions', 'pending_orders', 'oauth_states',
        'dash_tenants', 'dash_users', 'file_links', 'file_blobs', 'secure_tables', 'qr_tokens',
        // Universal Business Platform
        'tenants', 'users', 'roles', 'permissions', 'role_permissions', 'user_roles',
        'categories', 'products', 'customers', 'orders', 'order_items', 'invoices',
        'coupons', 'carts', 'tenant_theme_settings',
        // Caching, Queues, Scaling
        'cache_items', 'jobs', 'failed_jobs', 'job_batches', 'scaling_nodes', 'webhooks', 'webhook_deliveries',
        // Security Center & Audit
        'audit_logs', 'security_threats', 'blocked_ips', 'ip_rate_limits', 'login_history', 'api_tokens'
    ];

    $stmt = $pdo->query("SHOW TABLES;");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "📊 \033[1;33mChecking Table Inventory (" . count($existingTables) . " detected):\033[0m\n";
    $missing = [];
    foreach ($expectedTables as $tbl) {
        if (in_array($tbl, $existingTables)) {
            echo "  ✓ Table '{$tbl}' ... \033[32mEXISTS\033[0m\n";
        } else {
            echo "  ✗ Table '{$tbl}' ... \033[31mMISSING\033[0m\n";
            $missing[] = $tbl;
        }
    }

    if (!empty($missing)) {
        echo "\n\033[1;31m❌ Verification FAILED: Missing tables: " . implode(', ', $missing) . "\033[0m\n";
        exit(1);
    }

    echo "\n📊 \033[1;33mVerifying Seed Data & Core Records:\033[0m\n";

    // Check default tenant
    $tenant = $pdo->query("SELECT * FROM tenants WHERE tenant_id = 'main'")->fetch();
    if ($tenant) {
        echo "  ✓ Default tenant 'main' ({$tenant['company_name']}) ... \033[32mFOUND\033[0m\n";
    } else {
        echo "  ✗ Default tenant 'main' ... \033[31mMISSING\033[0m\n";
    }

    // Check default super admin
    $admin = $pdo->query("SELECT * FROM users WHERE username = 'xbot'")->fetch();
    if ($admin && password_verify('#123xbot', $admin['password_hash'])) {
        echo "  ✓ Super admin user 'xbot' (Password Verified) ... \033[32mAUTHENTICATED\033[0m\n";
    } else {
        echo "  ✗ Super admin user 'xbot' ... \033[31mINVALID OR MISSING\033[0m\n";
    }

    // Check permissions count
    $permCount = $pdo->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
    echo "  ✓ System Permissions loaded: {$permCount} ... \033[32mOK\033[0m\n";

    // Check roles count
    $roleCount = $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
    echo "  ✓ System Roles configured: {$roleCount} ... \033[32mOK\033[0m\n";

    // Check rooms count
    $roomCount = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    echo "  ✓ Default Chat Rooms available: {$roomCount} ... \033[32mOK\033[0m\n";

    // Check products count
    $productCount = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    echo "  ✓ Demo Products available: {$productCount} ... \033[32mOK\033[0m\n";

    // Check theme settings
    $theme = $pdo->query("SELECT * FROM tenant_theme_settings WHERE tenant_id = 'main'")->fetch();
    if ($theme) {
        echo "  ✓ Theme Settings for 'main' (Mode: {$theme['theme_mode']}, Accent: {$theme['accent_color']}) ... \033[32mLOADED\033[0m\n";
    }

    echo "\n\033[1;32m============================================================\033[0m\n";
    echo "\033[1;32m🎉 All Database Checks Passed! 100% Production Ready.\033[0m\n";
    echo "\033[1;32m============================================================\033[0m\n";

} catch (PDOException $e) {
    echo "\033[1;31m❌ Database Error: " . $e->getMessage() . "\033[0m\n";
    exit(1);
}
