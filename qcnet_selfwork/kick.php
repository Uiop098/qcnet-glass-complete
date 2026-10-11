<?php
// kick.php — bans a user from a room and immediately terminates any of
// their active sessions. Only callable by a host or super user.

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
    echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can remove people.']);
    exit;
}
if ($targetUser === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No user specified.']);
    exit;
}
if (strtolower($targetUser) === strtolower($session['user'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'You can\'t remove yourself.']);
    exit;
}

ban_user($config, $targetUser);
$knownIp = $config['userIps'][strtolower($targetUser)] ?? null;
if ($knownIp) ban_ip($config, $knownIp);
unset($config['presence'][strtolower($targetUser)]);
write_config($room, $config);
remove_sessions_for_user($room, $targetUser);
remove_remember_tokens_for_user($room, $targetUser);

echo json_encode(['ok' => true, 'removed' => $targetUser]);
