<?php
require_once 'dash_firewall.php';
require_once 'dash_crypto.php';
require_once 'dash_audit.php';
header('Content-Type: application/json');

if (!isset($_SESSION['dash_admin']) && !isset($_SESSION['dash_user'])) die(json_encode(["error" => "Unauthorized access."]));

$is_admin = isset($_SESSION['dash_admin']);
$action = $_GET['action'] ?? '';
$tenant_id = $_SESSION['dash_tenant'] ?? 'main';
$dir = ($tenant_id === 'main') ? __DIR__ . '/data_dash' : __DIR__ . '/data_dash/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenant_id);
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$db_files = [
    'users' => $dir . '/users.json', 'payments' => $dir . '/payments.json', 
    'reminders' => $dir . '/reminders.json', 'links' => $dir . '/links.json',
    'products' => $dir . '/products.json', 'settings' => $dir . '/settings.json'
];
foreach ($db_files as $file) { if (!file_exists($file)) save_secure_table($file, []); }

switch ($action) {
    case 'get_dashboard_data':
        $users = get_secure_table($db_files['users']);
        $payments = get_secure_table($db_files['payments']);
        $products = get_secure_table($db_files['products']);
        
        $data = ["status" => "success", "links" => get_secure_table($db_files['links']), "reminders" => get_secure_table($db_files['reminders']), "products" => $products];
        
        if ($is_admin) {
            $data["users"] = $users; $data["payments"] = $payments;
            $data["settings"] = get_secure_table($db_files['settings']);
            
            $total_users = count($users);
            $total_revenue = 0; $received_count = 0;
            foreach ($payments as $p) { if (($p['status'] ?? '') === 'Received') { $total_revenue += floatval($p['amount'] ?? 0); $received_count++; } }
            $success_rate = count($payments) > 0 ? round(($received_count / count($payments)) * 100) : 100;
            $data["stats"] = [ "total_users" => $total_users, "total_revenue" => $total_revenue, "success_rate" => $success_rate, "received_count" => $received_count, "total_payments" => count($payments) ];

            // 14-day revenue trend for the overview sparkline — real data,
            // zero-padded for days without a received payment so the chart
            // always renders something coherent even for a brand-new business.
            $trend = [];
            for ($i = 13; $i >= 0; $i--) { $trend[date('Y-m-d', strtotime("-$i days"))] = 0; }
            foreach ($payments as $p) {
                if (($p['status'] ?? '') !== 'Received' || empty($p['created_at'])) continue;
                $day = date('Y-m-d', $p['created_at']);
                if (isset($trend[$day])) $trend[$day] += floatval($p['amount'] ?? 0);
            }
            $data["revenue_trend"] = array_values($trend);

            // Pending actions — derived from real conditions, not fabricated:
            // payments awaiting confirmation, and reminders due today or overdue.
            $pending = [];
            foreach ($payments as $p) { if (($p['status'] ?? '') === 'Pending') $pending[] = ['label' => 'Review payment — ₹' . ($p['amount'] ?? 0) . ' from @' . ($p['client'] ?? '?'), 'tag' => 'Payments']; }
            $today = date('Y-m-d');
            foreach (get_secure_table($db_files['reminders']) as $r) {
                if (!empty($r['due']) && $r['due'] <= $today) $pending[] = ['label' => ($r['due'] < $today ? 'Overdue: ' : 'Due today: ') . ($r['task'] ?? 'Reminder'), 'tag' => 'Alerts'];
            }
            $data["pending_actions"] = array_slice($pending, 0, 6);
            $data["recent_activity"] = get_activity($dir, 6, null);
        } else {
            $user_id = $_SESSION['dash_user']; 
            $my_payments = array_filter($payments, function($p) use ($user_id) { return ($p['client'] ?? '') === $user_id; });
            $data["payments"] = array_values($my_payments); 
            $data["my_profile"] = $users[$user_id] ?? [];
        }
        echo json_encode($data); break;
        
    case 'add_record':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$is_admin) die(json_encode(["error" => "Only administrators can add records."]));
            $table = $_POST['table'] ?? ''; if (!array_key_exists($table, $db_files)) die(json_encode(["error" => "Invalid table."]));
            // users.json is a keyed object ({username: {...}}), not a flat
            // array like payments/reminders/links/products — appending a
            // record here with $data[] would corrupt that structure, and
            // this path never hashes a password anyway. Adding a user goes
            // through dash_auth.php's create_pending invite-token flow instead.
            if ($table === 'users') die(json_encode(["error" => "Use the invite-token flow to add a user, not this endpoint."]));
            $data = get_secure_table($db_files[$table]); $new_record = ["id" => uniqid(), "created_at" => time()];
            foreach ($_POST as $key => $value) if ($key !== 'table') $new_record[$key] = $value;
            $data[] = $new_record; save_secure_table($db_files[$table], $data); echo json_encode(["status" => "success"]);
        }
        break;
        
    case 'save_settings':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
            // Previously this expected the client to have sent real AES
            // ciphertext (it hadn't — see dash_script.js's saveSettings()) and
            // tried to decrypt it with a fixed, key-derived IV, which is itself
            // a weak construction (a static IV reused across every save, taken
            // from the encryption key's own bytes, leaks patterns and is bad
            // practice even where a static IV is otherwise tolerable). This
            // always failed. Settings now arrive as plain POST fields over
            // HTTPS and get the same AES-256-CBC-with-random-IV at-rest
            // encryption as every other table, via save_secure_table().
            $settings_data = [
                "payment_mode" => $_POST['payment_mode'] ?? 'server',
                "cf_app_id" => $_POST['cf_app_id'] ?? '',
                "cf_env" => $_POST['cf_env'] ?? 'SANDBOX'
            ];
            // Keep the previously-saved secret key if the admin left the
            // password-style field blank (don't overwrite it with an empty string).
            $existing = get_secure_table($db_files['settings']);
            $new_secret = $_POST['cf_secret_key'] ?? '';
            $settings_data['cf_secret_key'] = $new_secret !== '' ? $new_secret : ($existing['cf_secret_key'] ?? '');

            save_secure_table($db_files['settings'], $settings_data);
            echo json_encode(["status" => "success"]);
        }
        break;
        
    case 'update_user':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
            $username = $_POST['username'] ?? ''; $users = get_secure_table($db_files['users']);
            if (isset($users[$username])) {
                $users[$username]['name'] = $_POST['name'] ?? $users[$username]['name']; $users[$username]['mobile'] = $_POST['mobile'] ?? '';
                $users[$username]['email'] = $_POST['email'] ?? ''; $users[$username]['address'] = $_POST['address'] ?? '';
                $users[$username]['tags'] = $_POST['tags'] ?? ''; $users[$username]['notes'] = $_POST['notes'] ?? ''; $users[$username]['admin_message'] = $_POST['admin_message'] ?? '';
                save_secure_table($db_files['users'], $users); echo json_encode(["status" => "success"]);
            } else echo json_encode(["error" => "User not found"]);
        }
        break;
        
    case 'update_my_profile':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_admin) {
            $user_id = $_SESSION['dash_user']; $users = get_secure_table($db_files['users']);
            if (isset($users[$user_id])) {
                $users[$user_id]['mobile'] = $_POST['mobile'] ?? ''; $users[$user_id]['email'] = $_POST['email'] ?? '';
                $users[$user_id]['address'] = $_POST['address'] ?? ''; save_secure_table($db_files['users'], $users); echo json_encode(["status" => "success"]);
            } else echo json_encode(["error" => "User not found"]);
        }
        break;
        
    case 'delete_user':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
            $username = $_POST['username'] ?? ''; $users = get_secure_table($db_files['users']);
            if (isset($users[$username])) { unset($users[$username]); save_secure_table($db_files['users'], $users); echo json_encode(["status" => "success"]); } 
            else echo json_encode(["error" => "User not found"]);
        }
        break;
}
?>