<?php
// poll.php — v35, PART 2: "Polls". New message type ("poll") alongside
// existing text/file types. Poll state (question, options, votes) lives
// in the room config keyed by poll id; a lightweight "poll" chat message
// is also posted so it appears in the normal message flow and survives
// the 24h history the same way any other message does. Voting/closing
// only touch the config (live via normal polling refresh).

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

if ($session['role'] === 'member' && is_banned($config, $session['user'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'banned', 'message' => 'You have been removed from this chat by the host.']);
    exit;
}

if ($action === 'create') {
    if (!rate_limit_allow('poll_create', get_client_ip(), 5, 300)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many polls created — please wait a bit.']);
        exit;
    }
    $question = isset($body['question']) ? (string)$body['question'] : '';
    $options = isset($body['options']) && is_array($body['options']) ? $body['options'] : [];
    $options = array_slice($options, 0, 10);

    if (trim($question) === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A poll needs a question.']);
        exit;
    }
    $nonEmptyOptions = array_values(array_filter($options, function ($o) { return trim((string)$o) !== ''; }));
    if (count($nonEmptyOptions) < 2) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A poll needs at least 2 options.']);
        exit;
    }

    $poll = create_poll($config, $question, $nonEmptyOptions, $session['user']);
    write_config($room, $config);

    $newMsg = append_message($room, [
        'type' => 'poll',
        'user' => $session['user'],
        'role' => $session['role'],
        'text' => '',
        'pollId' => $poll['id'],
        'reactions' => [],
    ]);

    echo json_encode(['ok' => true, 'poll' => $poll, 'message' => $newMsg]);
    exit;
}

if ($action === 'vote') {
    $pollId = isset($body['pollId']) ? (string)$body['pollId'] : '';
    $optionId = isset($body['optionId']) ? (string)$body['optionId'] : '';
    $poll = cast_poll_vote($config, $pollId, $session['user'], $optionId);
    if (!$poll) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Could not cast that vote (poll may be closed or option invalid).']);
        exit;
    }
    write_config($room, $config);
    echo json_encode(['ok' => true, 'poll' => $poll, 'results' => poll_results($poll)]);
    exit;
}

if ($action === 'close') {
    if (!is_effective_host($config, $session)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can close a poll.']);
        exit;
    }
    $pollId = isset($body['pollId']) ? (string)$body['pollId'] : '';
    if (!close_poll($config, $pollId)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Poll not found.']);
        exit;
    }
    write_config($room, $config);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
