<?php
// edit.php — edits a message's text. Only the original sender can edit
// their own message (host/super get no bypass here), and only plain
// text messages are editable (not files). Marks the message "edited".

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
$text = isset($body['text']) ? trim((string)$body['text']) : '';

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}
if ($messageId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No message specified.']);
    exit;
}
if ($text === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Message text cannot be empty.']);
    exit;
}
// Same size rules as sending a new message (see api.php).
if (strpos($text, 'encv1:') === 0) {
    if (strlen($text) > 60000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Encrypted message too large.']);
        exit;
    }
} elseif (mb_strlen($text) > 20000) {
    $text = mb_substr($text, 0, 20000);
}

$room = $session['room'];

$config = read_config($room);
if ($config && $session['role'] !== 'super') {
    $remaining = mute_remaining_ms($config, $session['user']);
    if ($remaining > 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'muted', 'message' => 'You are muted and can\'t edit messages right now.', 'mutedRemainingMs' => $remaining]);
        exit;
    }
}

$updated = edit_message($room, $messageId, $session['user'], $text);
if ($updated === 'too_late') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'edit_window_expired', 'message' => 'You can only edit a message within 30 seconds of sending it.']);
    exit;
}
if (!$updated) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You can only edit your own text messages (and they must still exist).']);
    exit;
}

echo json_encode(['ok' => true, 'message' => $updated]);
