<?php
$sys_key_file = __DIR__ . '/data_dash/sys_key.php';
if (!file_exists($sys_key_file)) {
    if (!is_dir(__DIR__ . '/data_dash')) @mkdir(__DIR__ . '/data_dash', 0755, true);
    $random_key = bin2hex(random_bytes(32));
    file_put_contents($sys_key_file, "<?php define('DASH_ENC_KEY', hex2bin('$random_key')); ?>");
}
require_once $sys_key_file;

function dash_encrypt_data($data) {
    $json = json_encode($data, JSON_PRETTY_PRINT);
    $iv = openssl_random_pseudo_bytes(16);
    return base64_encode($iv . openssl_encrypt($json, 'aes-256-cbc', DASH_ENC_KEY, OPENSSL_RAW_DATA, $iv));
}

function dash_decrypt_data($content) {
    if (!$content) return [];
    $content = trim($content);
    if ($content === '') return [];
    if ($content[0] === '[' || $content[0] === '{') return json_decode($content, true) ?: [];
    $raw = base64_decode($content, true);
    if ($raw && strlen($raw) > 16) {
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $decrypted = openssl_decrypt($cipher, 'aes-256-cbc', DASH_ENC_KEY, OPENSSL_RAW_DATA, $iv);
        if ($decrypted) return json_decode($decrypted, true) ?: [];
    }
    return [];
}

function get_secure_table($path) {
    if (!file_exists($path)) return [];
    return dash_decrypt_data(@file_get_contents($path));
}

function save_secure_table($path, $data) {
    file_put_contents($path, dash_encrypt_data($data));
}
?>