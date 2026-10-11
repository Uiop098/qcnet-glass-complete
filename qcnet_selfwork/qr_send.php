<?php
// qr_send.php — "Scan to send a file" (chat-bar QR option).
//
// Called by an already-logged-in chat participant (has a normal room
// session token) when they tap the QR button next to sticker/GIF/mic.
// Issues a short-lived, single-use token and hands back the absolute
// URL that gets encoded into the QR code. That URL points at
// qr_upload.php — a completely separate, unauthenticated page that
// trusts nothing but the token itself, expires it after a short window, and
// accepts exactly one file before the token is burned. See
// qr_upload.php and the QR token helpers in common.php for the rest of
// the flow.

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
$replyToId = isset($body['replyTo']) ? intval($body['replyTo']) : 0;

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}

// Same shape of checks as every other "about to add a message" endpoint
// (upload.php, gif.php, poll.php) — a banned/muted member shouldn't be
// able to mint a QR handoff any more than they could tap the attach icon.
$room = $session['room'];
$config = read_config($room);
if (!$config) $config = default_config();
if ($session['role'] === 'member' && is_banned($config, $session['user'])) {
    remove_sessions_for_user($room, $session['user']);
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'banned', 'message' => 'You have been removed from this chat by the host.']);
    exit;
}
if ($session['role'] !== 'super') {
    $remaining = mute_remaining_ms($config, $session['user']);
    if ($remaining > 0) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'muted',
            'message' => 'You are muted for ' . ceil($remaining / 1000) . ' more second(s).',
            'mutedRemainingMs' => $remaining,
        ]);
        exit;
    }
}

// Generating a QR code is cheap for the requester but each one is a
// live short-lived door into the room's file storage, so it's rate
// limited separately (and tighter) than ordinary messages.
if (!rate_limit_allow('qr_send', get_client_ip(), 8, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many QR codes requested — please wait a bit.']);
    exit;
}

$issued = create_qr_upload_token($room, $session['user'], $session['role'], $replyToId);

// Build an absolute URL so it still resolves when scanned from a
// completely different device on camera, not just relative to this tab.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$uploadUrl = $scheme . '://' . $host . $basePath . '/qr_upload.php?t=' . $issued['token'];

echo json_encode([
    'ok' => true,
    'token' => $issued['token'],
    'url' => $uploadUrl,
    'expiresAt' => $issued['expiresAt'] * 1000, // ms, to match the rest of the app's ts fields
    'ttlSeconds' => $GLOBALS['qrTokenTtlSeconds'],
]);
