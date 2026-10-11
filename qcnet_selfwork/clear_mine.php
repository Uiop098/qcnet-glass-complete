<?php
// clear_mine.php — v35, PART 3: "Clear my messages". Member-only, scoped
// to the caller's own username (case-insensitive match, same ownership
// pattern as edit_message()). Reduces the need to ask a host to clean
// up after yourself.

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

if (!rate_limit_allow('clear_mine', get_client_ip(), 5, 300)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Please wait before doing that again.']);
    exit;
}

$room = $session['room'];
$removed = clear_users_messages($room, $session['user']);

echo json_encode(['ok' => true, 'removed' => $removed]);
