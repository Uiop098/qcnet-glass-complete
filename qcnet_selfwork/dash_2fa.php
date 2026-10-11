<?php
// dash_2fa.php — Time-based One-Time Password (RFC 6238), implemented from
// scratch in pure PHP (HMAC-SHA1, no Composer/external libraries needed —
// works on InfinityFree same as everything else in this project).
// Compatible with Google Authenticator, Authy, 1Password, etc.

define('TOTP_SECRET_BYTES', 20);   // 160-bit secret, standard for TOTP
define('TOTP_PERIOD', 30);         // seconds per code
define('TOTP_DIGITS', 6);
define('TOTP_WINDOW', 1);          // accept 1 step early/late (clock drift)

function totp_base32_encode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = ''; foreach (str_split($data) as $c) { $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); }
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $output .= $alphabet[bindec($chunk)];
    }
    return $output;
}

function totp_base32_decode($b32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $pos = strpos($alphabet, $c);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) $bytes .= chr(bindec($byte)); }
    return $bytes;
}

function totp_generate_secret() { return totp_base32_encode(random_bytes(TOTP_SECRET_BYTES)); }

function totp_code($base32Secret, $timestamp = null) {
    $timestamp = $timestamp ?? time();
    $key = totp_base32_decode($base32Secret);
    $counter = intdiv($timestamp, TOTP_PERIOD);
    $counterBytes = pack('N*', 0, $counter); // 8-byte big-endian counter
    $hash = hash_hmac('sha1', $counterBytes, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $binary = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($binary % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

// Checks the submitted code against the current step and a small window
// either side, to tolerate normal clock drift between server and phone.
function totp_verify($base32Secret, $code, $window = TOTP_WINDOW) {
    $code = preg_replace('/\s+/', '', (string)$code);
    if (!preg_match('/^\d{' . TOTP_DIGITS . '}$/', $code)) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($base32Secret, $now + ($i * TOTP_PERIOD)), $code)) return true;
    }
    return false;
}

function totp_otpauth_uri($base32Secret, $accountLabel, $issuer = 'BizManage Dashboard') {
    $label = rawurlencode($issuer . ':' . $accountLabel);
    $params = http_build_query(['secret' => $base32Secret, 'issuer' => $issuer, 'digits' => TOTP_DIGITS, 'period' => TOTP_PERIOD], '', '&', PHP_QUERY_RFC3986);
    return "otpauth://totp/{$label}?{$params}";
}

// A handful of single-use recovery codes, shown once at setup time, in case
// the user loses their authenticator device. Stored as password_hash()es,
// same as everything else that shouldn't be recoverable in plaintext.
function totp_generate_recovery_codes($count = 8) {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
    return $codes;
}

// ---------------- Cross-system 2FA bridge ----------------
// The Business Dashboard (dash_auth.php, PHP $_SESSION) and QuickChat
// (auth.php, its own file-based session tokens) are two separate login
// systems that happen to share the same account (a tenant admin's
// dashboard login also works as their QuickChat Super User login). Without
// this, a 2FA-enabled account would be asked to re-verify a second time
// the moment they moved from one system to the other via the Hamburger
// menu link — once here is enough. This is a short-lived (matches a
// typical session lifetime), per-account marker on disk, checked by both
// dash_auth.php and auth.php before deciding whether to challenge for a
// code, and refreshed every time either system verifies one.
define('TWOFA_BRIDGE_TTL_SECONDS', 12 * 60 * 60); // 12 hours

function twofa_bridge_file($username) {
    $dir = __DIR__ . '/data_dash/temp';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir . '/2fa_bridge_' . md5(strtolower($username)) . '.json';
}

function mark_2fa_verified($username) {
    file_put_contents(twofa_bridge_file($username), json_encode(['username' => $username, 'expires' => time() + TWOFA_BRIDGE_TTL_SECONDS]));
}

function is_2fa_recently_verified($username) {
    $f = twofa_bridge_file($username);
    if (!file_exists($f)) return false;
    $d = json_decode(@file_get_contents($f), true);
    if (!$d || time() > ($d['expires'] ?? 0)) { @unlink($f); return false; }
    return true;
}
?>
