<?php
// schedule.php — v35, PART 2: "Scheduled messages". Host/co-host/super
// specifies text + a future timestamp; it's stored in the room config
// and posted opportunistically the next time anyone polls the room (see
// process_due_scheduled_messages() in common.php, called from api.php).

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
$action = isset($body['action']) ? $body['action'] : 'create';

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}

$room = $session['room'];
$config = read_config($room);
if (!$config) $config = default_config();

if (!is_effective_host($config, $session)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can schedule messages.']);
    exit;
}

if ($action === 'create') {
    $text = isset($body['text']) ? trim((string)$body['text']) : '';
    $postAt = isset($body['postAt']) ? intval($body['postAt']) : 0; // ms epoch

    if ($text === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Message text cannot be empty.']);
        exit;
    }
    if ($postAt <= now_ms()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Scheduled time must be in the future.']);
        exit;
    }
    if (count($config['scheduledMessages']) >= 50) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Too many pending scheduled messages (limit 50). Cancel some first.']);
        exit;
    }

    $item = add_scheduled_message($config, mb_substr($text, 0, 20000), $session['user'], $session['role'], $postAt);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'scheduled' => $item]);
    exit;
}

if ($action === 'cancel') {
    $id = isset($body['id']) ? (string)$body['id'] : '';
    remove_scheduled_message($config, $id);
    write_config($room, $config);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
