<?php
// dash_login_helpers.php — shared helpers for finishing a login (session,
// last-login stamp, activity log, 2FA challenge). Split out from
// dash_auth.php specifically because that file has a top-level
// switch($action) that would execute unwanted code if `require`'d directly
// from anywhere else.

// Called when password/OAuth verification succeeds but the account has
// 2FA enabled — instead of issuing a session immediately, stash a
// short-lived pending record (5 min) keyed by a random token, and hand
// that token back to the client so it can call verify_2fa_login with the
// TOTP code.
function start_2fa_challenge($role, $username, $dir, $tenant_id, $secret, $recoveryCodes) {
    $temp_dir = __DIR__ . '/data_dash/temp';
    if (!is_dir($temp_dir)) @mkdir($temp_dir, 0755, true);
    $token = bin2hex(random_bytes(24));
    $pf = $temp_dir . '/2fa_pending_' . $token . '.json';
    file_put_contents($pf, json_encode([
        'role' => $role, 'username' => $username, 'dir' => $dir, 'tenant_id' => $tenant_id,
        'secret' => $secret, 'recovery_codes' => $recoveryCodes ?? [], 'expires' => time() + 300,
    ]));
    return ['status' => '2fa_required', 'pending_token' => $token];
}

// Finishes a successful login: sets the session, stamps last_login_at/ip
// on the account record, and writes an activity-log entry. Shared by
// password logins and post-2FA-verification so both behave identically.
function finalize_dash_login($role, $username, $dir, $tenant_id) {
    if ($role === 'admin') {
        $master_file = __DIR__ . '/data_dash/master.json';
        $masters = get_secure_table($master_file);
        if (isset($masters[$username])) {
            $masters[$username]['last_login_at'] = time(); $masters[$username]['last_login_ip'] = get_client_ip();
            save_secure_table($master_file, $masters);
        } else {
            $tenants_file = __DIR__ . '/data_dash/tenants.json';
            $tenants = get_secure_table($tenants_file);
            if (isset($tenants[$username])) {
                $tenants[$username]['last_login_at'] = time(); $tenants[$username]['last_login_ip'] = get_client_ip();
                save_secure_table($tenants_file, $tenants);
            }
        }
    } else {
        $users_file = $dir . '/users.json'; $users = get_secure_table($users_file);
        if (isset($users[$username])) {
            $users[$username]['last_login_at'] = time(); $users[$username]['last_login_ip'] = get_client_ip();
            save_secure_table($users_file, $users);
        }
    }
    $_SESSION['dash_' . $role] = $username; $_SESSION['dash_tenant'] = $tenant_id;
    log_activity($dir, $username, 'login', 'Logged in.');
}

// Provisions a brand-new tenant's data directory — used both when a
// payment completes (payment_api.php) and when a master-admin-issued
// invite token is redeemed (dash_auth.php's redeem_admin_token).
function setup_tenant_database($tenant_id) {
    $tenant_dir = __DIR__ . '/data_dash/' . $tenant_id;
    if (!is_dir($tenant_dir)) @mkdir($tenant_dir, 0755, true);
    $tables = ['users.json', 'payments.json', 'reminders.json', 'links.json', 'pending_users.json', 'products.json', 'settings.json'];
    foreach ($tables as $tbl) {
        if (!file_exists("$tenant_dir/$tbl")) save_secure_table("$tenant_dir/$tbl", []);
    }
    $tenant_files_dir = __DIR__ . '/files_chat/' . $tenant_id;
    if (!is_dir($tenant_files_dir)) @mkdir($tenant_files_dir, 0755, true);
}
?>
