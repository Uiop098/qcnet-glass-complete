<?php
require_once 'dash_firewall.php';
require_once 'cashfree_config.php';
require_once 'dash_crypto.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
if (!isset($_SESSION['dash_user'])) die(json_encode(["error" => "Please login to purchase."]));

$tenant_id = $_SESSION['dash_tenant'] ?? 'main';
$dir = ($tenant_id === 'main') ? __DIR__ . '/data_dash' : __DIR__ . '/data_dash/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenant_id);
$settings = get_secure_table($dir . '/settings.json');
$products = get_secure_table($dir . '/products.json');

$use_custom = ($settings['payment_mode'] ?? 'server') === 'self';
$app_id = $use_custom ? ($settings['cf_app_id'] ?? CF_APP_ID) : CF_APP_ID;
$secret_key = $use_custom ? ($settings['cf_secret_key'] ?? CF_SECRET_KEY) : CF_SECRET_KEY;
$env = $use_custom ? ($settings['cf_env'] ?? CF_ENV) : CF_ENV;
$cf_url = ($env === 'PROD') ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';

if ($action === 'buy_product') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rate_limit_allow('tenant_buy_product', get_client_ip(), 10, 600)) { http_response_code(429); die(json_encode(["error" => "Too many requests. Please wait a few minutes."])); }
        $product_id = $_POST['product_id'] ?? '';
        $target_product = null;
        foreach ($products as $p) { if (($p['id'] ?? '') === $product_id) { $target_product = $p; break; } }
        if (!$target_product) die(json_encode(["error" => "Product not found."]));

        $username = $_SESSION['dash_user'];
        $users = get_secure_table($dir . '/users.json');
        $user_data = $users[$username] ?? [];
        
        $order_id = 'prod_' . uniqid() . '_' . time();
        $payload = [
            "order_id" => $order_id, "order_amount" => floatval($target_product['price']), "order_currency" => "INR",
            "customer_details" => [
                "customer_id" => "cust_" . $username, 
                "customer_name" => $user_data['name'] ?? 'User', 
                "customer_email" => $user_data['email'] ?? 'test@example.com', 
                "customer_phone" => $user_data['mobile'] ?? '9999999999'
            ],
            "order_meta" => ["return_url" => "https://" . safe_host() . "/dash_user.html?order_id={order_id}"],
            "order_note" => "Purchase: " . $target_product['name']
        ];
        
        $ch = curl_init($cf_url . '/orders');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-client-id: ' . $app_id, 'x-client-secret: ' . $secret_key, 'x-api-version: ' . CF_API_VERSION]);
        $response = curl_exec($ch); curl_close($ch);
        
        $res_data = json_decode($response, true);
        if (isset($res_data['payment_session_id'])) {
            // Previously nothing was ever persisted here — the order existed
            // only inside Cashfree, so after payment there was no record for
            // the admin to see and no way to confirm the purchase actually
            // went through. Now saved as PENDING, and verify_product_order
            // (called when the user is redirected back) finalizes it.
            $orders_file = $dir . '/product_orders.json'; $orders = get_secure_table($orders_file);
            $orders[$order_id] = [
                "product_id" => $product_id, "product_name" => $target_product['name'], "amount" => floatval($target_product['price']),
                "username" => $username, "status" => "PENDING", "created_at" => time(), "credited" => false,
                "env" => $env,
            ];
            save_secure_table($orders_file, $orders);
            echo json_encode(["status" => "success", "payment_session_id" => $res_data['payment_session_id'], "order_id" => $order_id, "env" => $env]);
        } else {
            // Don't leak the raw gateway response (which can include the tenant's
            // own E2EE Cashfree key context in error detail) back to the browser.
            error_log('Cashfree buy_product failed for tenant ' . $tenant_id . ': ' . $response);
            echo json_encode(["error" => "Could not start the payment. Please try again in a moment."]);
        }
    }
}

if ($action === 'verify_product_order') {
    if (!rate_limit_allow('tenant_verify_order', get_client_ip(), 20, 600)) { http_response_code(429); die(json_encode(["error" => "Too many requests."])); }
    $order_id = $_GET['order_id'] ?? '';
    $orders_file = $dir . '/product_orders.json'; $orders = get_secure_table($orders_file);
    if (!isset($orders[$order_id])) die(json_encode(["error" => "Order not found."]));
    // Only the buyer (or an admin checking on their behalf) can verify it —
    // stops one user from probing/confirming another user's order id.
    if ($orders[$order_id]['username'] !== $_SESSION['dash_user']) die(json_encode(["error" => "This order does not belong to your account."]));
    if ($orders[$order_id]['credited']) { echo json_encode(["status" => "success", "already_credited" => true, "product_name" => $orders[$order_id]['product_name']]); exit; }

    $order_env = $orders[$order_id]['env'] ?? $env;
    $verify_url = ($order_env === 'PROD') ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';
    $ch = curl_init($verify_url . '/orders/' . urlencode($order_id));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-client-id: ' . $app_id, 'x-client-secret: ' . $secret_key, 'x-api-version: ' . CF_API_VERSION]);
    $response = curl_exec($ch); curl_close($ch);
    $res_data = json_decode($response, true);

    if (isset($res_data['order_status']) && $res_data['order_status'] === 'PAID') {
        $orders[$order_id]['status'] = 'PAID'; $orders[$order_id]['credited'] = true;
        save_secure_table($orders_file, $orders);

        // This is the part that makes the purchase actually visible to the
        // admin — a real payments.json entry, exactly like a payment the
        // admin recorded manually, so it shows up in their Payments table
        // as "Received" without any extra step on their end.
        $payments = get_secure_table($dir . '/payments.json');
        $payments[] = [
            "id" => uniqid(), "client" => $orders[$order_id]['username'], "amount" => $orders[$order_id]['amount'],
            "status" => "Received", "note" => "Product purchase: " . $orders[$order_id]['product_name'],
            "created_at" => time(),
        ];
        save_secure_table($dir . '/payments.json', $payments);

        require_once 'dash_audit.php';
        log_activity($dir, $orders[$order_id]['username'], 'product_purchase', 'Purchased "' . $orders[$order_id]['product_name'] . '" — ₹' . $orders[$order_id]['amount']);

        echo json_encode(["status" => "success", "product_name" => $orders[$order_id]['product_name'], "amount" => $orders[$order_id]['amount']]);
    } else {
        echo json_encode(["error" => "Payment not completed yet."]);
    }
}
?>