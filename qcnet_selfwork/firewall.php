<?php
// firewall.php — a self-contained, drop-in application-level firewall for
// PHP sites on hosts with no server/root access (e.g. free shared hosting).
//
// It gives you three things, all file-based (no database needed):
//   1. get_client_ip()      — reliably identify the visitor's IP
//   2. rate_limit_allow()   — brute-force / flood throttle (sliding window)
//   3. block_ip() / is_ip_blocked() / unblock_ip() / list_blocked_ips()
//                           — a manual IP blocklist, temporary or permanent
//
// HOW TO USE
//   require_once __DIR__ . '/firewall.php';
//   at the very top of every public-facing entry point (login, message
//   send, upload, etc.), then:
//
//     $ip = get_client_ip();
//     if (is_ip_blocked($ip)) { /* reject the request, e.g. HTTP 403 */ }
//     if (!rate_limit_allow('login', $ip, 8, 600)) { /* HTTP 429 */ }
//
// See FIREWALL_HOW_IT_WORKS.txt (in the same folder) for the full
// explanation of the algorithms and a worked integration example.
//
// This is an APPLICATION-level guard, not a network firewall — it can't
// stop a large volumetric flood from reaching your server at all (that
// needs something in front of the server, like Cloudflare). What it CAN
// do, on any PHP host, is stop brute-forcing and app-level spam/abuse
// from a given visitor, and let you manually cut off an abusive IP.

// ---------------------------------------------------------------------
// Storage location — uses its own /data folder next to this file by
// default. If you're dropping this into a project that already defines
// $GLOBALS['dataDir'] (pointing at a writable folder), it reuses that
// instead, so you don't end up with two separate data directories.
// ---------------------------------------------------------------------

if (!isset($GLOBALS['dataDir'])) {
    $GLOBALS['dataDir'] = __DIR__ . '/data';
}
if (!is_dir($GLOBALS['dataDir'])) {
    mkdir($GLOBALS['dataDir'], 0755, true);
}
// Block direct browser access to the data folder wherever .htaccess is honored.
$fwHtaccess = $GLOBALS['dataDir'] . '/.htaccess';
if (!file_exists($fwHtaccess)) {
    @file_put_contents($fwHtaccess, "Require all denied\ndeny from all\n");
}

function fw_now_ms() {
    return round(microtime(true) * 1000);
}

function fw_read_json_file($path) {
    if (!file_exists($path)) return null;
    $fp = fopen($path, 'r');
    if (!$fp) return null;
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return $data;
}

function fw_write_json_file($path, $data) {
    $fp = fopen($path, 'c');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

// ---------------------------------------------------------------------
// 1. IP detection
// ---------------------------------------------------------------------

// Works directly, and also behind Cloudflare (if you ever put a free
// Cloudflare plan in front of a custom domain) — checked in order of
// trustworthiness, falling back to the raw connection IP.
// v35 security fix — spoofable IP detection. X-Forwarded-For can be set
// by ANY client on their own request; trusting it with no real proxy in
// front let an attacker fake a new "IP" on every request, bypassing
// rate limiting and IP bans entirely. Default trusts ONLY REMOTE_ADDR
// (safe for plain shared hosting). If this site runs behind a real,
// proxied Cloudflare setup, set TRUST_CLOUDFLARE_IP to true — Cloudflare
// overwrites CF-Connecting-IP itself so it can be trusted in that one
// case. X-Forwarded-For is never trusted either way.
if (!defined('TRUST_CLOUDFLARE_IP')) {
    define('TRUST_CLOUDFLARE_IP', false);
}

function get_client_ip() {
    if (TRUST_CLOUDFLARE_IP && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
        return $_SERVER['REMOTE_ADDR'];
    }
    return '0.0.0.0';
}

// ---------------------------------------------------------------------
// 2. Rate limiting — sliding window log, one shared JSON file.
// Fails OPEN (allows the request) if the file can't be opened, so a
// storage hiccup never takes your whole site down.
// ---------------------------------------------------------------------

function fw_rate_limit_file() {
    return $GLOBALS['dataDir'] . '/ratelimit.json';
}

// Returns true if $ip is still under $maxAttempts within the last
// $windowSeconds for this $bucket, and records this attempt. Returns
// false (and does NOT record it) if they've already hit the limit.
//
//   $bucket         a short label for what's being limited, e.g. 'login',
//                   'send_message', 'upload' — each bucket is tracked
//                   separately per IP.
//   $maxAttempts    how many are allowed inside the window.
//   $windowSeconds  the size of the sliding window, in seconds.
function rate_limit_allow($bucket, $ip, $maxAttempts, $windowSeconds) {
    $fp = fopen(fw_rate_limit_file(), 'c+');
    if (!$fp) return true; // fail open
    flock($fp, LOCK_EX);
    $content = stream_get_contents($fp);
    $data = json_decode($content, true);
    if (!is_array($data)) $data = [];

    $now = time();
    $key = $bucket . ':' . $ip;
    $hits = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];

    // Drop any timestamps that have aged out of the window — this is
    // what makes it a *sliding* window rather than a fixed one.
    $hits = array_values(array_filter($hits, function ($ts) use ($now, $windowSeconds) {
        return ($now - $ts) < $windowSeconds;
    }));

    $allowed = count($hits) < $maxAttempts;
    if ($allowed) {
        $hits[] = $now;
    }
    $data[$key] = $hits;

    // Light housekeeping so the file doesn't grow forever on a busy site.
    foreach ($data as $k => $v) {
        if (empty($v)) unset($data[$k]);
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $allowed;
}

// ---------------------------------------------------------------------
// 3. IP blocklist — a manual, decisive block independent of any bucket
// or username (usernames are trivial for an attacker to change; an IP
// is a bit stickier). Supports temporary or permanent blocks.
// ---------------------------------------------------------------------

function fw_ip_blocklist_file() {
    return $GLOBALS['dataDir'] . '/ip_blocklist.json';
}

function is_ip_blocked($ip) {
    $list = fw_read_json_file(fw_ip_blocklist_file());
    if (!is_array($list) || !isset($list[$ip])) return false;
    $entry = $list[$ip];
    if (!empty($entry['until']) && $entry['until'] < fw_now_ms()) return false; // expired
    return true;
}

// $durationSeconds = 0 means permanent (until manually unblocked).
function block_ip($ip, $reason = '', $durationSeconds = 0) {
    $list = fw_read_json_file(fw_ip_blocklist_file());
    if (!is_array($list)) $list = [];
    $list[$ip] = [
        'reason' => $reason,
        'blockedAt' => fw_now_ms(),
        'until' => $durationSeconds > 0 ? fw_now_ms() + ($durationSeconds * 1000) : null,
    ];
    fw_write_json_file(fw_ip_blocklist_file(), $list);
}

function unblock_ip($ip) {
    $list = fw_read_json_file(fw_ip_blocklist_file());
    if (!is_array($list)) $list = [];
    unset($list[$ip]);
    fw_write_json_file(fw_ip_blocklist_file(), $list);
}

// For an admin UI: every currently-active block (already-expired ones are
// skipped, and get cleaned up automatically the next time block/unblock
// is called on that same file).
function list_blocked_ips() {
    $list = fw_read_json_file(fw_ip_blocklist_file());
    if (!is_array($list)) return [];
    $now = fw_now_ms();
    $out = [];
    foreach ($list as $ip => $entry) {
        if (!empty($entry['until']) && $entry['until'] < $now) continue;
        $out[] = array_merge(['ip' => $ip], $entry);
    }
    return $out;
}
