<?php
define('CF_APP_ID', 'TEST_YOUR_CASHFREE_APP_ID');          // Replace with real Cashfree App ID
define('CF_SECRET_KEY', 'TEST_YOUR_CASHFREE_SECRET_KEY');  // Replace with real Cashfree Secret Key
define('CF_ENV', 'PROD');                                  // Set to PROD for live payments
define('CF_API_VERSION', '2023-08-01');
define('PANEL_PRICE_INR', 1.00);                           // 1 Rupee for testing

function cf_base_url() {
    return (CF_ENV === 'PROD') ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';
}
?>