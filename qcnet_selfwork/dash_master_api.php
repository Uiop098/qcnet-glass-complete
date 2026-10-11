<?php
// dash_master_api.php — the single default super user's cross-tenant view:
// every admin, every user, which user belongs to which admin, adding a new
// admin via an invite token (parallel to how client users are invited),
// and removing an admin's access. Master tier only — this is deliberately
// not exposed to tenant admins, who only ever see their own business.
require_once 'dash_firewall.php';
require_once 'dash_crypto.php';
require_once 'dash_audit.php';
require_once 'email_otp.php';
header('Content-Type: application/json');

if (!isset($_SESSION['dash_admin']) || ($_SESSION['dash_tenant'] ?? '') !== 'main') {
    die(json_encode(["error" => "Only the default super admin can access this."]));
}
$my_username = $_SESSION['dash_admin'];
$action = $_GET['action'] ?? '';
$root_dir = __DIR__ . '/data_dash';
$pending_admins_file = $root_dir . '/pending_admins.json';

function tenant_dir_for($tenant_id) {
    return $tenant_id === 'main' ? __DIR__ . '/data_dash' : __DIR__ . '/data_dash/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenant_id);
}

switch ($action) {

    case 'get_overview':
        $tenants = get_secure_table($root_dir . '/tenants.json');
        $out = [];
        foreach ($tenants as $username => $t) {
            $tdir = tenant_dir_for($t['tenant_id'] ?? '');
            $users = get_secure_table($tdir . '/users.json');
            $payments = get_secure_table($tdir . '/payments.json');
            $revenue = 0; foreach ($payments as $p) { if (($p['status'] ?? '') === 'Received') $revenue += floatval($p['amount'] ?? 0); }
            $out[] = [
                "username" => $username, "business_name" => $t['business_name'] ?? ($t['name'] ?? $username) . "'s Business",
                "email" => $t['email'] ?? '', "tenant_id" => $t['tenant_id'] ?? '', "status" => $t['status'] ?? 'active',
                "user_count" => count($users), "revenue" => $revenue, "created_at" => $t['created_at'] ?? null,
                "twofa_enabled" => !empty($t['twofa_enabled']),
            ];
        }
        $masters = get_secure_table($root_dir . '/master.json');
        $master_info = [];
        foreach ($masters as $u => $m) { $master_info[] = ["username" => $u, "twofa_enabled" => !empty($m['twofa_enabled'])]; }
        echo json_encode(["status" => "success", "admins" => $out, "master_admins" => $master_info, "total_users" => array_sum(array_column($out, 'user_count'))]);
        break;

    case 'get_tenant_users':
        $tenant_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['tenant_id'] ?? '');
        $users = get_secure_table(tenant_dir_for($tenant_id) . '/users.json');
        $out = [];
        foreach ($users as $u => $data) {
            $out[] = ["username" => $u, "name" => $data['name'] ?? '', "email" => $data['email'] ?? '', "status" => $data['status'] ?? 'active', "created_at" => $data['created_at'] ?? null];
        }
        echo json_encode(["status" => "success", "users" => $out]);
        break;

    case 'create_admin_token':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('master_create_admin_token', get_client_ip(), 15, 600)) { http_response_code(429); die(json_encode(["error" => "Too many requests."])); }
            $business_name = trim($_POST['business_name'] ?? ''); $email = strtolower(trim($_POST['email'] ?? ''));
            if ($business_name === '' || !is_valid_email($email)) die(json_encode(["error" => "Business name and a valid email are required."]));
            $pending = get_secure_table($pending_admins_file);
            $now = time(); $pending = array_filter($pending, function ($p) use ($now) { return $p['expires_at'] > $now; });
            $alphabet = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"; $token = '';
            for ($i = 0; $i < 8; $i++) { $token .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
            $pending[$token] = ["business_name" => $business_name, "email" => $email, "created_by" => $my_username, "expires_at" => $now + (24 * 60 * 60)];
            save_secure_table($pending_admins_file, $pending);
            log_activity($root_dir, $my_username, 'admin_token_created', "Created an admin invite token for \"$business_name\".");
            echo json_encode(["status" => "success", "token" => $token]);
        }
        break;

    case 'set_admin_status':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $target = trim($_POST['username'] ?? ''); $status = $_POST['status'] === 'deactivated' ? 'deactivated' : 'active';
            $tenants = get_secure_table($root_dir . '/tenants.json');
            if (!isset($tenants[$target])) die(json_encode(["error" => "Admin not found."]));
            // A deliberate soft action, not a destructive delete — their
            // business data (users, payments, chat rooms) stays intact and
            // this can be reversed. Blocks their login either way.
            $tenants[$target]['status'] = $status;
            save_secure_table($root_dir . '/tenants.json', $tenants);
            log_activity($root_dir, $my_username, 'admin_status_changed', ($status === 'deactivated' ? "Removed access for admin \"$target\"." : "Restored access for admin \"$target\"."));
            echo json_encode(["status" => "success"]);
        }
        break;

    default: http_response_code(400); echo json_encode(["error" => "Unknown action."]); break;
}
?>
