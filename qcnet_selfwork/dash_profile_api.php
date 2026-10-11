<?php
// dash_profile_api.php — backs dash_profile.html. Adapted from the Member
// Profile plan's endpoints (get/update/change-password/deactivate/activity/
// metrics) to this project's flat-file storage and existing session model.
require_once 'dash_firewall.php';
require_once 'dash_crypto.php';
require_once 'email_otp.php';
require_once 'dash_2fa.php';
require_once 'dash_audit.php';
header('Content-Type: application/json');

if (!isset($_SESSION['dash_admin']) && !isset($_SESSION['dash_user'])) die(json_encode(["error" => "Unauthorized access."]));

$is_admin = isset($_SESSION['dash_admin']);
$username = $is_admin ? $_SESSION['dash_admin'] : $_SESSION['dash_user'];
$tenant_id = $_SESSION['dash_tenant'] ?? 'main';
$safe_tenant = preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenant_id);
$dir = ($tenant_id === 'main') ? __DIR__ . '/data_dash' : __DIR__ . '/data_dash/' . $safe_tenant;
$action = $_GET['action'] ?? '';

// Resolves which store holds this account's record — master.json,
// tenants.json, or this tenant's users.json — and returns [$file, $store].
function my_account_store($is_admin, $tenant_id, $dir) {
    if ($is_admin) {
        if ($tenant_id === 'main') { $f = __DIR__ . '/data_dash/master.json'; return [$f, get_secure_table($f)]; }
        $f = __DIR__ . '/data_dash/tenants.json'; return [$f, get_secure_table($f)];
    }
    $f = $dir . '/users.json'; return [$f, get_secure_table($f)];
}

switch ($action) {

    case 'get_profile':
        list($file, $store) = my_account_store($is_admin, $tenant_id, $dir);
        if (!isset($store[$username])) die(json_encode(["error" => "Account not found."]));
        $acct = $store[$username];
        $business_name = 'Main Panel';
        if ($tenant_id !== 'main') {
            $tenants = get_secure_table(__DIR__ . '/data_dash/tenants.json');
            foreach ($tenants as $u => $t) { if (($t['tenant_id'] ?? '') === $tenant_id) { $business_name = $t['business_name'] ?? ($t['name'] . "'s Business"); break; } }
        }
        $payments = get_secure_table($dir . '/payments.json');
        $my_payment_count = 0; $my_total_paid = 0;
        foreach ($payments as $p) { if (($p['client'] ?? '') === $username && ($p['status'] ?? '') === 'Received') { $my_payment_count++; $my_total_paid += floatval($p['amount'] ?? 0); } }

        echo json_encode([
            "status" => "success",
            "profile" => [
                "username" => $username, "name" => $acct['name'] ?? $username, "role" => $is_admin ? ($tenant_id === 'main' ? 'Master Admin' : 'Business Admin') : 'Client',
                "email" => $acct['email'] ?? '', "mobile" => $acct['mobile'] ?? '', "address" => $acct['address'] ?? '', "dob" => $acct['dob'] ?? '',
                "tags" => $acct['tags'] ?? '', "status" => $acct['status'] ?? 'active',
                "created_at" => $acct['created_at'] ?? null, "last_login_at" => $acct['last_login_at'] ?? null, "last_login_ip" => $acct['last_login_ip'] ?? null,
                "twofa_enabled" => !empty($acct['twofa_enabled']), "business_name" => $business_name,
            ],
            "metrics" => ["payments_made" => $my_payment_count, "total_paid" => $my_total_paid],
            "activity" => get_activity($dir, 15, $username),
        ]);
        break;

    case 'update_profile':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('dash_profile_update', get_client_ip(), 15, 600)) { http_response_code(429); die(json_encode(["error" => "Too many attempts. Please wait a few minutes."])); }
            list($file, $store) = my_account_store($is_admin, $tenant_id, $dir);
            if (!isset($store[$username])) die(json_encode(["error" => "Account not found."]));
            $name = trim($_POST['name'] ?? ''); $mobile = trim($_POST['mobile'] ?? ''); $address = trim($_POST['address'] ?? '');
            $newEmail = strtolower(trim($_POST['email'] ?? ''));
            $currentEmail = strtolower($store[$username]['email'] ?? '');
            if ($newEmail !== '' && $newEmail !== $currentEmail) {
                // Changing email requires proving ownership of the NEW address
                // first — same OTP engine used at registration/payment.
                if (!is_valid_email($newEmail)) die(json_encode(["error" => "Enter a valid email address."]));
                if (!is_email_verified($newEmail, 'profile_email_change')) die(json_encode(["error" => "Please verify your new email address first."]));
            } else { $newEmail = $currentEmail; }
            if ($name !== '') $store[$username]['name'] = $name;
            $store[$username]['mobile'] = $mobile; $store[$username]['address'] = $address; $store[$username]['email'] = $newEmail;
            save_secure_table($file, $store);
            log_activity($dir, $username, 'profile_update', 'Updated profile details.' . ($newEmail !== $currentEmail ? ' Email changed.' : ''));
            echo json_encode(["status" => "success"]);
        }
        break;

    case 'change_password':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('dash_profile_password', get_client_ip(), 10, 600)) { http_response_code(429); die(json_encode(["error" => "Too many attempts. Please wait a few minutes."])); }
            $current = $_POST['current_password'] ?? ''; $new = trim($_POST['new_password'] ?? '');
            if (strlen($new) < 6) die(json_encode(["error" => "New password must be at least 6 characters."]));
            list($file, $store) = my_account_store($is_admin, $tenant_id, $dir);
            if (!isset($store[$username]) || !password_verify($current, $store[$username]['password_hash'])) die(json_encode(["error" => "Current password is incorrect."]));
            $store[$username]['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            save_secure_table($file, $store);
            log_activity($dir, $username, 'password_change', 'Changed account password.');
            echo json_encode(["status" => "success"]);
        }
        break;

    case 'deactivate_account':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Admin accounts are excluded — deactivating the only admin on a
            // tenant would lock the whole business out with no way back in
            // (the "last-admin protection" principle from the plan). Clients
            // can self-deactivate; an admin has to remove a client via the
            // Contacts table instead, which is a deliberate, visible action.
            if ($is_admin) die(json_encode(["error" => "Admin accounts can't be self-deactivated. Ask another admin, or remove the account from Contacts if you're a client's admin."]));
            $confirmPassword = $_POST['password'] ?? '';
            $file = $dir . '/users.json'; $store = get_secure_table($file);
            if (!isset($store[$username]) || !password_verify($confirmPassword, $store[$username]['password_hash'])) die(json_encode(["error" => "Incorrect password."]));
            $store[$username]['status'] = 'deactivated';
            save_secure_table($file, $store);
            log_activity($dir, $username, 'deactivated', 'Account self-deactivated.');
            session_destroy();
            echo json_encode(["status" => "success", "message" => "Your account has been deactivated."]);
        }
        break;

    // ---- 2FA setup (works the same for admin and user accounts) ----
    case 'setup_2fa':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $secret = totp_generate_secret();
            $_SESSION['pending_2fa_secret'] = $secret; // held in session only until confirmed
            echo json_encode(["status" => "success", "secret" => $secret, "otpauth_uri" => totp_otpauth_uri($secret, $username)]);
        }
        break;

    case 'confirm_2fa':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $code = trim($_POST['code'] ?? '');
            $secret = $_SESSION['pending_2fa_secret'] ?? '';
            if (!$secret) die(json_encode(["error" => "Start 2FA setup first."]));
            if (!totp_verify($secret, $code)) die(json_encode(["error" => "Incorrect code. Check your authenticator app and try again."]));
            list($file, $store) = my_account_store($is_admin, $tenant_id, $dir);
            $recovery = totp_generate_recovery_codes();
            $store[$username]['twofa_enabled'] = true;
            $store[$username]['twofa_secret'] = $secret;
            $store[$username]['twofa_recovery_codes'] = $recovery;
            save_secure_table($file, $store);
            unset($_SESSION['pending_2fa_secret']);
            log_activity($dir, $username, '2fa_enabled', 'Enabled two-factor authentication.');
            echo json_encode(["status" => "success", "recovery_codes" => $recovery]);
        }
        break;

    case 'disable_2fa':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            list($file, $store) = my_account_store($is_admin, $tenant_id, $dir);
            if (!isset($store[$username]) || !password_verify($password, $store[$username]['password_hash'])) die(json_encode(["error" => "Incorrect password."]));
            $store[$username]['twofa_enabled'] = false;
            unset($store[$username]['twofa_secret'], $store[$username]['twofa_recovery_codes']);
            save_secure_table($file, $store);
            log_activity($dir, $username, '2fa_disabled', 'Disabled two-factor authentication.');
            echo json_encode(["status" => "success"]);
        }
        break;

    default: http_response_code(400); echo json_encode(["error" => "Unknown action."]); break;
}
?>
