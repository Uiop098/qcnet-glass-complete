<?php
// read.php — records the highest message id the caller has seen, for
// read receipts. Cheap, like typing.php. Called whenever the client
// renders new messages while the tab is visible.

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

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session']);
    exit;
}
if ($messageId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No message specified.']);
    exit;
}

$room = $session['room'];
$config = read_config($room);
if (!$config) $config = default_config();

mark_read($config, $session['user'], $session['role'], $messageId);
write_config($room, $config);

echo json_encode(['ok' => true]);
