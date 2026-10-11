<?php
require_once 'dash_firewall.php';
require_once 'dash_crypto.php';
require_once 'email_otp.php';
require_once 'dash_2fa.php';
require_once 'dash_audit.php';
require_once 'dash_login_helpers.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$temp_dir = __DIR__ . '/data_dash/temp';
if (!is_dir($temp_dir)) @mkdir($temp_dir, 0755, true);

foreach (glob($temp_dir . '/otp_*.json') as $otp_file) {
    $otp_data = json_decode(@file_get_contents($otp_file), true);
    if ($otp_data && time() > $otp_data['expires']) { @unlink($otp_file); }
}
foreach (glob($temp_dir . '/2fa_pending_*.json') as $f) {
    $d = json_decode(@file_get_contents($f), true);
    if ($d && time() > $d['expires']) { @unlink($f); }
}

switch ($action) {
    case 'check_auth':
        if (isset($_SESSION['dash_admin'])) echo json_encode(["status" => "success", "role" => "admin", "user" => $_SESSION['dash_admin'], "tenant_id" => $_SESSION['dash_tenant'] ?? 'main']);
        elseif (isset($_SESSION['dash_user'])) echo json_encode(["status" => "success", "role" => "user", "user" => $_SESSION['dash_user'], "tenant_id" => $_SESSION['dash_tenant'] ?? 'main']);
        else echo json_encode(["error" => "Unauthorized"]);
        break;

    case 'admin_login':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $ip = get_client_ip();
            if (!rate_limit_allow('dash_admin_login', $ip, 8, 600)) { http_response_code(429); die(json_encode(["error" => "Too many login attempts. Please wait a few minutes."])); }
            $username = $_POST['username'] ?? ''; $password = $_POST['password'] ?? '';
            $master_file = __DIR__ . '/data_dash/master.json';
            if (!file_exists($master_file)) save_secure_table($master_file, ["xbot" => ["password_hash" => password_hash("#123xbot", PASSWORD_DEFAULT)]]);
            $masters = get_secure_table($master_file);
            if (isset($masters[$username]) && password_verify($password, $masters[$username]['password_hash'])) {
                if (!empty($masters[$username]['twofa_enabled']) && !is_2fa_recently_verified($username)) { echo json_encode(start_2fa_challenge('admin', $username, __DIR__ . '/data_dash', 'main', $masters[$username]['twofa_secret'], $masters[$username]['twofa_recovery_codes'] ?? [])); exit; }
                finalize_dash_login('admin', $username, __DIR__ . '/data_dash', 'main'); die(json_encode(["status" => "success"]));
            }
            $tenants = get_secure_table(__DIR__ . '/data_dash/tenants.json');
            if (isset($tenants[$username]) && password_verify($password, $tenants[$username]['password_hash'])) {
                if (($tenants[$username]['status'] ?? 'active') === 'deactivated') die(json_encode(["error" => "This admin account's access has been removed. Contact the default super admin."]));
                $tenant_id = $tenants[$username]['tenant_id'];
                $tdir = ($tenant_id === 'main') ? __DIR__ . '/data_dash' : __DIR__ . '/data_dash/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenant_id);
                if (!empty($tenants[$username]['twofa_enabled']) && !is_2fa_recently_verified($username)) { echo json_encode(start_2fa_challenge('admin', $username, $tdir, $tenant_id, $tenants[$username]['twofa_secret'], $tenants[$username]['twofa_recovery_codes'] ?? [])); exit; }
                finalize_dash_login('admin', $username, $tdir, $tenant_id); die(json_encode(["status" => "success"]));
            }
            echo json_encode(["error" => "Invalid Administrator credentials."]);
        }
        break;

    case 'user_login':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('dash_user_login', get_client_ip(), 10, 600)) { http_response_code(429); die(json_encode(["error" => "Too many login attempts. Please wait a few minutes."])); }
            $username = $_POST['username'] ?? ''; $password = $_POST['password'] ?? '';
            $all_dirs = array_merge([__DIR__ . '/data_dash'], glob(__DIR__ . '/data_dash/tenant_*', GLOB_ONLYDIR));
            foreach ($all_dirs as $dir) {
                $users = get_secure_table($dir . '/users.json');
                if (isset($users[$username]) && password_verify($password, $users[$username]['password_hash'])) {
                    if (($users[$username]['status'] ?? 'active') === 'deactivated') die(json_encode(["error" => "This account has been deactivated. Contact your admin to reactivate it."]));
                    $tenant_id = basename($dir); if ($tenant_id === 'data_dash') $tenant_id = 'main';
                    if (!empty($users[$username]['twofa_enabled']) && !is_2fa_recently_verified($username)) { echo json_encode(start_2fa_challenge('user', $username, $dir, $tenant_id, $users[$username]['twofa_secret'], $users[$username]['twofa_recovery_codes'] ?? [])); exit; }
                    finalize_dash_login('user', $username, $dir, $tenant_id); die(json_encode(["status" => "success"]));
                }
            }
            echo json_encode(["error" => "Invalid username or password."]);
        }
        break;

    case 'verify_2fa_login':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('dash_2fa_verify', get_client_ip(), 10, 600)) { http_response_code(429); die(json_encode(["error" => "Too many attempts. Please wait a few minutes."])); }
            $pendingToken = $_POST['pending_token'] ?? ''; $code = trim($_POST['code'] ?? '');
            $pf = $temp_dir . '/2fa_pending_' . preg_replace('/[^a-zA-Z0-9]/', '', $pendingToken) . '.json';
            if (!file_exists($pf)) die(json_encode(["error" => "Session expired. Please log in again."]));
            $pending = json_decode(file_get_contents($pf), true);
            if (time() > $pending['expires']) { @unlink($pf); die(json_encode(["error" => "Session expired. Please log in again."])); }
            // Recovery codes: a single-use fallback if the authenticator app
            // is unavailable. Checked alongside the live TOTP code.
            $usedRecovery = false;
            if (!totp_verify($pending['secret'], $code)) {
                $codes = $pending['recovery_codes'] ?? [];
                $idx = array_search(strtoupper($code), array_map('strtoupper', $codes), true);
                if ($idx === false) die(json_encode(["error" => "Incorrect code."]));
                $usedRecovery = true;
            }
            @unlink($pf);
            finalize_dash_login($pending['role'], $pending['username'], $pending['dir'], $pending['tenant_id']);
            mark_2fa_verified($pending['username']);
            if ($usedRecovery) {
                // Burn the used recovery code so it can't be replayed.
                $store_file = $pending['role'] === 'admin' ? ($pending['tenant_id'] === 'main' ? __DIR__ . '/data_dash/master.json' : __DIR__ . '/data_dash/tenants.json') : $pending['dir'] . '/users.json';
                $store = get_secure_table($store_file);
                if (isset($store[$pending['username']]['twofa_recovery_codes'])) {
                    $store[$pending['username']]['twofa_recovery_codes'] = array_values(array_diff($store[$pending['username']]['twofa_recovery_codes'], [$codes[$idx]]));
                    save_secure_table($store_file, $store);
                }
                log_activity($pending['dir'], $pending['username'], '2fa_recovery_used', 'Logged in using a 2FA recovery code.');
            }
            echo json_encode(["status" => "success"]);
        }
        break;


    case 'forgot_password_step1':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('dash_otp_request', get_client_ip(), 6, 600)) { http_response_code(429); die(json_encode(["error" => "Too many requests. Please wait a few minutes."])); }
            $email = strtolower(trim($_POST['email'] ?? '')); $dob = trim($_POST['dob'] ?? '');
            if (!$email || !$dob) die(json_encode(["error" => "Email and DOB required."]));
            
            $found_accounts = [];
            $all_dirs = array_merge([__DIR__ . '/data_dash'], glob(__DIR__ . '/data_dash/tenant_*', GLOB_ONLYDIR));
            foreach ($all_dirs as $dir) {
                $users = get_secure_table($dir . '/users.json');
                foreach ($users as $u => $data) {
                    if (strtolower($data['email'] ?? '') === $email && ($data['dob'] ?? '') === $dob) { $found_accounts[] = $u; }
                }
            }
            // Tenant admin accounts (added via a Dashboard purchase) previously
            // had no self-service password reset at all — only regular client
            // accounts (users.json) were searched here. tenants.json lives only
            // at the root data_dash level (see payment_api.php), never per-tenant.
            $tenants = get_secure_table(__DIR__ . '/data_dash/tenants.json');
            foreach ($tenants as $u => $data) {
                if (strtolower($data['email'] ?? '') === $email && ($data['dob'] ?? '') === $dob) { $found_accounts[] = $u; }
            }
            
            if (empty($found_accounts)) die(json_encode(["error" => "No matching accounts found for that Email and DOB."]));
            
            $otp_file = $temp_dir . '/otp_' . md5($email) . '.json';
            if (file_exists($otp_file)) {
                $existing = json_decode(file_get_contents($otp_file), true);
                if ($existing && time() < $existing['cooldown']) die(json_encode(["error" => "Please wait 5 minutes before requesting a new code."]));
            }
            
            // random_int() is CSPRNG-backed, unlike mt_rand() which is predictable
            // and unsuitable for anything security-sensitive like a reset code.
            $code = sprintf("%06d", random_int(100000, 999999));
            $otp_data = [ "code" => $code, "accounts" => $found_accounts, "expires" => time() + 180, "cooldown" => time() + 300, "attempts" => 0 ];
            file_put_contents($otp_file, json_encode($otp_data));
            
            // Routed through the multi-provider engine (email_otp.php) instead
            // of a raw mail() call — plain mail() is frequently disabled or
            // silently dropped on InfinityFree-style shared hosting, which was
            // the whole reason the fallback chain exists.
            if (!send_otp_email($email, $code)) die(json_encode(["error" => "Could not send the verification email. Please try again shortly."]));
            
            echo json_encode(["status" => "success", "message" => "A 6-digit code has been sent to your email. (Expires in 3 minutes)"]);
        }
        break;
        
    case 'forgot_password_step2':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // A 6-digit code is only 1,000,000 possibilities — with no attempt
            // limit, an attacker who knows the target's email could just script
            // through them inside the 3-minute window. Rate-limit by IP AND
            // lock the OTP itself out after 5 wrong guesses.
            if (!rate_limit_allow('dash_otp_verify', get_client_ip(), 15, 600)) { http_response_code(429); die(json_encode(["error" => "Too many attempts. Please wait a few minutes."])); }
            $email = strtolower(trim($_POST['email'] ?? '')); $code = trim($_POST['code'] ?? '');
            $otp_file = $temp_dir . '/otp_' . md5($email) . '.json';
            if (!file_exists($otp_file)) die(json_encode(["error" => "Code expired or not requested."]));
            $otp_data = json_decode(file_get_contents($otp_file), true);
            if (time() > $otp_data['expires']) { @unlink($otp_file); die(json_encode(["error" => "Code has expired. Request a new one."])); }
            if (($otp_data['attempts'] ?? 0) >= 5) { @unlink($otp_file); die(json_encode(["error" => "Too many incorrect attempts. Please request a new code."])); }
            if (!hash_equals($otp_data['code'], $code)) {
                $otp_data['attempts'] = ($otp_data['attempts'] ?? 0) + 1;
                file_put_contents($otp_file, json_encode($otp_data));
                die(json_encode(["error" => "Incorrect 6-digit code."]));
            }
            
            echo json_encode(["status" => "success", "accounts" => $otp_data['accounts']]);
        }
        break;

    case 'forgot_password_step3':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('dash_otp_verify', get_client_ip(), 15, 600)) { http_response_code(429); die(json_encode(["error" => "Too many attempts. Please wait a few minutes."])); }
            $email = strtolower(trim($_POST['email'] ?? '')); $code = trim($_POST['code'] ?? '');
            $username = trim($_POST['username'] ?? ''); $new_pass = trim($_POST['new_password'] ?? '');
            
            $otp_file = $temp_dir . '/otp_' . md5($email) . '.json';
            if (!file_exists($otp_file)) die(json_encode(["error" => "Session expired."]));
            $otp_data = json_decode(file_get_contents($otp_file), true);
            if (time() > $otp_data['expires']) { @unlink($otp_file); die(json_encode(["error" => "Session expired."])); }
            if (($otp_data['attempts'] ?? 0) >= 5) { @unlink($otp_file); die(json_encode(["error" => "Too many incorrect attempts. Please request a new code."])); }
            if (!hash_equals($otp_data['code'], $code) || !in_array($username, $otp_data['accounts'])) {
                $otp_data['attempts'] = ($otp_data['attempts'] ?? 0) + 1;
                file_put_contents($otp_file, json_encode($otp_data));
                die(json_encode(["error" => "Invalid request."]));
            }
            
            if (strlen($new_pass) < 6) die(json_encode(["error" => "New password must be at least 6 characters."]));
            $updated = false;
            $all_dirs = array_merge([__DIR__ . '/data_dash'], glob(__DIR__ . '/data_dash/tenant_*', GLOB_ONLYDIR));
            foreach ($all_dirs as $dir) {
                $users = get_secure_table($dir . '/users.json');
                if (isset($users[$username])) {
                    $users[$username]['password_hash'] = password_hash($new_pass, PASSWORD_DEFAULT);
                    save_secure_table($dir . '/users.json', $users);
                    $updated = true; break;
                }
            }
            if (!$updated) {
                // Tenant admin accounts live only in the root tenants.json,
                // not per-tenant users.json — same account list forgot_password_step1
                // now also searches.
                $tenants_file = __DIR__ . '/data_dash/tenants.json';
                $tenants = get_secure_table($tenants_file);
                if (isset($tenants[$username])) {
                    $tenants[$username]['password_hash'] = password_hash($new_pass, PASSWORD_DEFAULT);
                    save_secure_table($tenants_file, $tenants);
                    $updated = true;
                }
            }
            if ($updated) {
                @unlink($otp_file); echo json_encode(["status" => "success", "message" => "Password updated successfully. You may log in."]);
            } else die(json_encode(["error" => "Failed to update password."]));
        }
        break;

    case 'logout': session_destroy(); echo json_encode(["status" => "success"]); break;
    
    case 'create_pending':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_SESSION['dash_admin'])) die(json_encode(["error" => "Unauthorized. Admin only."]));
            $tenant_id = $_SESSION['dash_tenant'] ?? 'main';
            $dir = ($tenant_id === 'main') ? __DIR__ . '/data_dash' : __DIR__ . '/data_dash/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenant_id);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $pending_file = $dir . '/pending_users.json'; $pending = get_secure_table($pending_file);
            $current_time = time(); $pending = array_filter($pending, function($u) use ($current_time) { return $u['expires_at'] > $current_time; });
            // str_shuffle() is mt_rand()-backed, not a CSPRNG. random_int() is.
            $alphabet = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"; $token = '';
            for ($i = 0; $i < 8; $i++) { $token .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
            $pending[$token] = ["name" => $_POST['name'] ?? '', "dob" => $_POST['dob'] ?? '', "expires_at" => $current_time + (10 * 60)];
            save_secure_table($pending_file, $pending); echo json_encode(["status" => "success", "token" => $token]);
        }
        break;
        
    case 'redeem_admin_token':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('dash_redeem_admin_token', get_client_ip(), 10, 600)) { http_response_code(429); die(json_encode(["error" => "Too many attempts. Please wait a few minutes."])); }
            $token = strtoupper(trim($_POST['token'] ?? '')); $username = trim($_POST['username'] ?? ''); $password = trim($_POST['password'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9]+$/', $username) || !preg_match('/^[a-zA-Z0-9]+$/', $password)) die(json_encode(["error" => "Username and Password can only contain letters and numbers."]));
            if (strlen($password) < 6) die(json_encode(["error" => "Password must be at least 6 characters."]));

            $pending_file = __DIR__ . '/data_dash/pending_admins.json';
            $pending = get_secure_table($pending_file);
            if (!isset($pending[$token]) || $pending[$token]['expires_at'] < time()) die(json_encode(["error" => "Invalid or expired token."]));
            if (strtolower($pending[$token]['email']) !== $email) die(json_encode(["error" => "This token was issued for a different email address."]));
            // Same email-ownership check used for the paid registration path.
            if (!is_email_verified($email, 'admin_register')) die(json_encode(["error" => "Please verify your email address first."]));

            $tenants = get_secure_table(__DIR__ . '/data_dash/tenants.json');
            if (isset($tenants[$username])) die(json_encode(["error" => "Username already taken."]));
            $masters = get_secure_table(__DIR__ . '/data_dash/master.json');
            if (isset($masters[$username])) die(json_encode(["error" => "Username already taken."]));

            $tenant_id = 'tenant_' . substr(md5($username . time()), 0, 8);
            setup_tenant_database($tenant_id);
            $tenants[$username] = [
                "password_hash" => password_hash($password, PASSWORD_DEFAULT), "tenant_id" => $tenant_id,
                "business_name" => $pending[$token]['business_name'], "email" => $email, "status" => "active", "created_at" => time(),
            ];
            save_secure_table(__DIR__ . '/data_dash/tenants.json', $tenants);
            unset($pending[$token]); save_secure_table($pending_file, $pending);
            log_activity(__DIR__ . '/data_dash', $username, 'admin_registered_via_token', "Admin account \"$username\" created via invite token.");
            echo json_encode(["status" => "success", "message" => "Admin account created. You may now log in."]);
        }
        break;

    case 'complete_setup':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('dash_complete_setup', get_client_ip(), 10, 600)) { http_response_code(429); die(json_encode(["error" => "Too many attempts. Please wait a few minutes."])); }
            $token = strtoupper(trim($_POST['token'] ?? '')); $dob_attempt = $_POST['dob'] ?? ''; $username = $_POST['username'] ?? ''; $password = $_POST['password'] ?? '';
            $email = strtolower(trim($_POST['email'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9]+$/', $username) || !preg_match('/^[a-zA-Z0-9]+$/', $password)) die(json_encode(["error" => "Username and Password can only contain letters and numbers."]));
            if (strlen($password) < 6) die(json_encode(["error" => "Password must be at least 6 characters."]));
            if (!is_valid_email($email)) die(json_encode(["error" => "A valid email address is required."]));
            // Requires having gone through email_otp.php?action=send_otp then
            // verify_otp for purpose=user_register with this exact email
            // first — see the Verify Email step in setup_account.html.
            if (!is_email_verified($email, 'user_register')) die(json_encode(["error" => "Please verify your email address before completing setup."]));
            $found_tenant_dir = null; $found_pending_data = null;
            $all_dirs = array_merge([__DIR__ . '/data_dash'], glob(__DIR__ . '/data_dash/tenant_*', GLOB_ONLYDIR));
            foreach ($all_dirs as $dir) {
                $p_file = $dir . '/pending_users.json'; $pending = get_secure_table($p_file);
                if (isset($pending[$token])) { $found_tenant_dir = $dir; $found_pending_data = $pending; break; }
            }
            if (!$found_tenant_dir || $found_pending_data[$token]['dob'] !== $dob_attempt || $found_pending_data[$token]['expires_at'] < time()) die(json_encode(["error" => "Invalid token, expired, or incorrect Birth Date."]));
            foreach ($all_dirs as $dir) {
                $u = get_secure_table($dir . '/users.json');
                if (isset($u[$username])) die(json_encode(["error" => "Username already taken."]));
            }
            $users_file = $found_tenant_dir . '/users.json'; $users = get_secure_table($users_file);
            $users[$username] = [
                "name" => $found_pending_data[$token]['name'], "dob" => $found_pending_data[$token]['dob'], "password_hash" => password_hash($password, PASSWORD_DEFAULT),
                "address" => "", "mobile" => "", "email" => $email, "tags" => "", "notes" => "", "admin_message" => "", "created_at" => time()
            ];
            save_secure_table($users_file, $users); unset($found_pending_data[$token]);
            save_secure_table($found_tenant_dir . '/pending_users.json', $found_pending_data);
            echo json_encode(["status" => "success", "message" => "Account created successfully. You may now log in."]);
        }
        break;
}
?>