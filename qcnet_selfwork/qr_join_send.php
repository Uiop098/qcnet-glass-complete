<?php
// qr_join_send.php — "Scan to join" (header-bar QR option).
//
// Called by a host, co-host, or super who taps the invite-QR button at
// the top of the room. Issues a 30-minute, MULTI-use token: anyone who
// scans it during that window lands on qr_join.php, picks a display
// name, and is dropped straight into the room as a member — no PIN.
//
// The 30-minute window is a hard cutoff, not just "how long the code
// can be scanned": every session created from it is tagged with that
// same deadline (see create_session's $extra / get_session's
// qrJoinExpires check in common.php), so everyone who joined via this
// code is signed out together the moment it lapses, regardless of when
// each of them actually joined.

require_once __DIR__ . '/common.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$token = isset($body['token']) ? $body['token'] : '';

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}

$room = $session['room'];
$config = read_config($room);
if (!$config) $config = default_config();

// Bypassing the room PIN is a real privilege — restricted to whoever
// can already manage the room (host, co-host, or super), same test
// used for other room-management actions.
if (!is_effective_host($config, $session)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'Only the host can generate a join code for this room.']);
    exit;
}

if (!rate_limit_allow('qr_join_send', get_client_ip(), 10, 300)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many join codes requested — please wait a bit.']);
    exit;
}

$issued = create_join_token($room, $session['user']);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$joinUrl = $scheme . '://' . $host . $basePath . '/qr_join.php?j=' . $issued['token'];

echo json_encode([
    'ok' => true,
    'token' => $issued['token'],
    'url' => $joinUrl,
    'expiresAt' => $issued['expiresAt'] * 1000,
    'ttlSeconds' => $GLOBALS['joinTokenTtlSeconds'],
]);
