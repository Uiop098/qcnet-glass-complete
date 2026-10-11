<?php
// mute.php — temporarily silences a user (the "spammer timer"). Sends
// with minutes <= 0 removes an existing mute early. Host and super
// users can both do this.

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
$targetUser = trim((string)($body['targetUser'] ?? ''));
$minutes = isset($body['minutes']) ? floatval($body['minutes']) : 0;

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}
$room = $session['room'];
$config = read_config($room);
if (!$config) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Room not found.']);
    exit;
}
if (!is_effective_host($config, $session)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can mute people.']);
    exit;
}
if ($targetUser === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No user specified.']);
    exit;
}
if (strtolower($targetUser) === strtolower($session['user'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'You can\'t mute yourself.']);
    exit;
}

mute_user($config, $targetUser, $minutes);
write_config($room, $config);

echo json_encode([
    'ok' => true,
    'targetUser' => $targetUser,
    'mutedForMinutes' => $minutes > 0 ? $minutes : 0,
]);
