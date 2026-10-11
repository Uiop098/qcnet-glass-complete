<?php
// react.php — toggles an emoji reaction on a message. Anyone in the
// room (member, host, or super) can react. One active reaction per
// person per message — picking a different emoji replaces the old one.

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
$messageId = isset($body['messageId']) ? intval($body['messageId']) : 0;
$emoji = isset($body['emoji']) ? (string)$body['emoji'] : '';

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}

// Only allow a small fixed set of emoji to keep storage/rendering simple and safe.
$allowedEmoji = ['👍', '❤️', '😂', '😮', '😢', '🔥'];
if (!in_array($emoji, $allowedEmoji, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported reaction.']);
    exit;
}
if ($messageId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing message.']);
    exit;
}

$room = $session['room'];
$updated = toggle_reaction($room, $messageId, $session['user'], $emoji);

if (!$updated) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Message not found (it may have expired).']);
    exit;
}

echo json_encode(['ok' => true, 'message' => $updated]);
