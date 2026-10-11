<?php
session_start();

// Pull in the shared, properly-built rate limiter / IP blocklist (get_client_ip(),
// rate_limit_allow(), is_ip_blocked(), block_ip()) that the chat engine already
// uses via common.php. Previously the dashboard side had NO brute-force
// protection at all — admin login, user login, and password-reset OTP guesses
// were completely unthrottled, while this exact protection already existed
// in the codebase and just wasn't reused here. Point it at its own storage
// folder so dashboard rate-limit/blocklist data doesn't mix with chat data.
if (!isset($GLOBALS['dataDir'])) { $GLOBALS['dataDir'] = __DIR__ . '/data_dash/security'; }
require_once __DIR__ . '/firewall.php';

function sanitize_input($data) {
    if (is_array($data)) { foreach ($data as $key => $value) { $data[$key] = sanitize_input($value); } } 
    else { $data = htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8'); }
    return $data;
}
// Password fields are exempted from HTML-escaping: they're only ever run
// through password_hash()/password_verify(), never rendered as HTML, so
// escaping them just silently corrupts any password containing & < > " '
// (registration would hash the escaped version, and a differently-escaped
// login attempt could then fail to match). Every other field still goes
// through sanitize_input() as before, since dash_script.js does render
// stored fields (names, notes, etc.) back into the page.
$_GET = sanitize_input($_GET);
$raw_password_fields = ['password' => $_POST['password'] ?? null, 'new_password' => $_POST['new_password'] ?? null];
$_POST = sanitize_input($_POST);
foreach ($raw_password_fields as $key => $val) { if ($val !== null) $_POST[$key] = $val; }

$user_ip = get_client_ip();
if (is_ip_blocked($user_ip)) { header('HTTP/1.0 403 Forbidden'); die(json_encode(["error" => "Access denied by firewall."])); }

// Shared by every file that builds a Cashfree return_url from the Host
// header. $_SERVER['HTTP_HOST'] is client-supplied; on a vhost that doesn't
// reject unrecognized Host headers, trusting it blindly lets an attacker
// steer a payer's post-payment redirect at an arbitrary domain. This rejects
// anything that isn't a plausible bare hostname (no schemes, paths, or
// injected characters) and falls back to SERVER_NAME otherwise.
function safe_host() {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (preg_match('/^[a-zA-Z0-9.\-]+(:\d+)?$/', $host)) return $host;
    return $_SERVER['SERVER_NAME'] ?? 'localhost';
}
?>