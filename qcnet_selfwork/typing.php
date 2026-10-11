<?php
// typing.php — marks the caller as "currently typing" for a few
// seconds. Called by the client every couple of seconds while the
// person has text in the message box. Very cheap on purpose.

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
    echo json_encode(['ok' => false, 'error' => 'invalid_session']);
    exit;
}

$room = $session['room'];
$config = read_config($room);
if (!$config) $config = default_config();

set_typing($config, $session['user'], $session['role']);
write_config($room, $config);

echo json_encode(['ok' => true]);
