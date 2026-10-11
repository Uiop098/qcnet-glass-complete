<?php
require_once 'dash_firewall.php';
require_once 'cashfree_config.php';
require_once 'dash_crypto.php';
require_once 'email_otp.php';
require_once 'dash_login_helpers.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$dir = __DIR__ . '/data_dash';
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$orders_file = $dir . '/orders.json';
$tenants_file = $dir . '/tenants.json';

foreach ([$orders_file, $tenants_file] as $f) {
    if (!file_exists($f)) save_secure_table($f, []);
}

// orders.json/tenants.json hold buyer PII (name/email/phone/DOB) and, for
// tenants.json, every tenant admin's bcrypt password hash — these are now
// routed through the same AES-256 at-rest encryption as every other table
// instead of plain json_encode()/json_decode(), which left them fully
// readable if the file was ever exposed (misconfigured host, backup leak, etc).
function get_json($path) { return get_secure_table($path); }
function save_json($path, $data) { save_secure_table($path, $data); }
// safe_host() now lives in dash_firewall.php (shared with tenant_payment_api.php).

switch ($action) {
    case 'create_order':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('pay_create_order', get_client_ip(), 6, 600)) { http_response_code(429); die(json_encode(["error" => "Too many requests. Please wait a few minutes."])); }
            $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? ''); $dob = trim($_POST['dob'] ?? '');
            if (!$name || !$email || !$phone || !$dob) die(json_encode(["error" => "All fields required."]));
            // Requires having gone through email_otp.php?action=send_otp then
            // verify_otp for purpose=payment with this exact email first —
            // see the Verify Email step on pay.html, before the Pay button.
            if (!is_email_verified($email, 'payment')) die(json_encode(["error" => "Please verify your email address before paying."]));
            $order_id = 'order_' . uniqid() . '_' . time(); $customer_id = 'cust_' . substr(md5($email), 0, 10);
            $payload = [
                "order_id" => $order_id, "order_amount" => PANEL_PRICE_INR, "order_currency" => "INR",
                "customer_details" => ["customer_id" => $customer_id, "customer_name" => $name, "customer_email" => $email, "customer_phone" => $phone],
                "order_meta" => ["return_url" => "https://" . safe_host() . "/pay.html?order_id={order_id}"],
                "order_note" => "Custom Admin Panel"
            ];
            $ch = curl_init(cf_base_url() . '/orders');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-client-id: ' . CF_APP_ID, 'x-client-secret: ' . CF_SECRET_KEY, 'x-api-version: ' . CF_API_VERSION]);
            $response = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            
            $res_data = json_decode($response, true);
            if ($http_code === 200 && isset($res_data['payment_session_id'])) {
                $orders = get_json($orders_file);
                // email_verified is stamped here (not time-limited like the
                // is_email_verified() marker, which only lasts 20 minutes) so
                // that when Cashfree redirects straight back after payment,
                // complete_admin_registration doesn't ask for the code a
                // second time — the email was already proven a moment ago in
                // the same flow. It's only asked again on the separate
                // "Verify & Resume" path, for someone returning after closing
                // the tab or refreshing mid-payment.
                $orders[$order_id] = ["name" => $name, "email" => strtolower($email), "phone" => $phone, "dob" => $dob, "amount" => PANEL_PRICE_INR, "status" => "PENDING", "created_at" => time(), "registered" => false, "email_verified" => true];
                save_json($orders_file, $orders);
                echo json_encode(["status" => "success", "payment_session_id" => $res_data['payment_session_id'], "order_id" => $order_id]);
            } else {
                // Don't leak the raw Cashfree API response to the client — it can
                // include internal error detail. Log it server-side instead.
                error_log('Cashfree create_order failed: ' . $response);
                echo json_encode(["error" => "Could not start the payment. Please try again in a moment."]);
            }
        }
        break;
    case 'verify_order':
        if (!rate_limit_allow('pay_verify_order', get_client_ip(), 20, 600)) { http_response_code(429); die(json_encode(["error" => "Too many requests. Please wait a few minutes."])); }
        $order_id = $_GET['order_id'] ?? ''; $orders = get_json($orders_file);
        if (!isset($orders[$order_id])) die(json_encode(["error" => "Order not found."]));
        $ch = curl_init(cf_base_url() . '/orders/' . urlencode($order_id));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-client-id: ' . CF_APP_ID, 'x-client-secret: ' . CF_SECRET_KEY, 'x-api-version: ' . CF_API_VERSION]);
        $response = curl_exec($ch); curl_close($ch); $res_data = json_decode($response, true);
        if (isset($res_data['order_status']) && $res_data['order_status'] === 'PAID') {
            $orders[$order_id]['status'] = 'PAID'; save_json($orders_file, $orders);
            echo json_encode(["status" => "success", "message" => "Payment verified.", "email" => $orders[$order_id]['email'], "dob" => $orders[$order_id]['dob']]);
        } else echo json_encode(["error" => "Payment not completed yet."]);
        break;
    case 'resume_setup':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('pay_resume_setup', get_client_ip(), 10, 600)) { http_response_code(429); die(json_encode(["error" => "Too many attempts. Please wait a few minutes."])); }
            $email = strtolower(trim($_POST['email'] ?? '')); $dob = trim($_POST['dob'] ?? '');
            $orders = get_json($orders_file); $matched_order = null; $matched_order_id = null;
            foreach ($orders as $oid => $o) {
                if (strtolower($o['email']) === $email && $o['dob'] === $dob && $o['status'] === 'PAID') {
                    if ($o['registered']) die(json_encode(["error" => "This account is already registered."]));
                    $matched_order = $o; $matched_order_id = $oid; break;
                }
            }
            if ($matched_order) echo json_encode(["status" => "success", "order_id" => $matched_order_id, "name" => $matched_order['name'], "email" => $matched_order['email']]);
            else echo json_encode(["error" => "No pending paid order found for this Email and DOB."]);
        }
        break;
    case 'complete_admin_registration':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!rate_limit_allow('pay_complete_registration', get_client_ip(), 10, 600)) { http_response_code(429); die(json_encode(["error" => "Too many attempts. Please wait a few minutes."])); }
            $order_id = trim($_POST['order_id'] ?? ''); $username = trim($_POST['username'] ?? ''); $password = trim($_POST['password'] ?? '');
            $via = trim($_POST['via'] ?? 'direct'); // 'direct' = came straight back from Cashfree; 'resume' = Verify & Resume tab
            if (!preg_match('/^[a-zA-Z0-9]+$/', $username) || !preg_match('/^[a-zA-Z0-9]+$/', $password)) die(json_encode(["error" => "Username and Password must be alphanumeric."]));
            if (strlen($password) < 6) die(json_encode(["error" => "Password must be at least 6 characters."]));
            $orders = get_json($orders_file); $tenants = get_json($tenants_file);
            if (!isset($orders[$order_id])) die(json_encode(["error" => "Verification failed."]));
            if ($orders[$order_id]['registered']) die(json_encode(["error" => "Account already provisioned."]));
            // Normal flow: Cashfree redirects straight back to pay.html right
            // after payment, in the same browser session — the email was
            // already proven moments ago at create_order time (see the
            // email_verified flag stamped on the order there), so don't ask
            // again. Only the "Verify & Resume" path (someone returning after
            // closing the tab or refreshing mid-payment, possibly much later
            // or in a different browser) requires a fresh code.
            if ($via === 'resume') {
                if (!is_email_verified($orders[$order_id]['email'], 'admin_register')) die(json_encode(["error" => "Please verify your email address to finish setting up your admin account."]));
            } elseif (empty($orders[$order_id]['email_verified'])) {
                // Safety net for older orders created before this flag existed.
                die(json_encode(["error" => "Please verify your email address to finish setting up your admin account."]));
            }
            if (isset($tenants[$username])) die(json_encode(["error" => "Username already taken."]));
            $tenant_id = 'tenant_' . substr(md5($username . time()), 0, 8); setup_tenant_database($tenant_id);
            $tenants[$username] = ["tenant_id" => $tenant_id, "name" => $orders[$order_id]['name'], "email" => $orders[$order_id]['email'], "phone" => $orders[$order_id]['phone'], "dob" => $orders[$order_id]['dob'], "password_hash" => password_hash($password, PASSWORD_DEFAULT), "created_at" => time()];
            save_json($tenants_file, $tenants);
            $orders[$order_id]['registered'] = true; $orders[$order_id]['tenant_id'] = $tenant_id; save_json($orders_file, $orders);
            echo json_encode(["status" => "success", "message" => "Admin Panel provisioned successfully!"]);
        }
        break;
    default: http_response_code(405); echo json_encode(["error" => "Direct access is not allowed."]); break;
}
?>