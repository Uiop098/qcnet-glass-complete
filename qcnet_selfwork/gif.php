<?php
// gif.php — v35, PART 2: "GIF picker". Server-side proxy to Tenor's
// free-tier search API (same reasoning as translate.php: keeps the
// third-party API key server-side, never exposed to the client). The
// client shows results in a picker; a selected GIF is sent as a normal
// file-type message using its URL, not re-uploaded.

require_once __DIR__ . '/common.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // action=send — posts a previously-searched GIF as a chat message.
    // The URL is re-validated server-side against Tenor's media host so
    // a client can't smuggle an arbitrary external URL into a message
    // (this is the only way external URLs ever get rendered directly,
    // so it's deliberately locked down to one trusted CDN pattern).
    $body = json_decode(file_get_contents('php://input'), true);
    $token = isset($body['token']) ? $body['token'] : '';
    $gifUrl = isset($body['gifUrl']) ? (string)$body['gifUrl'] : '';
    $replyToId = isset($body['replyTo']) ? intval($body['replyTo']) : 0;

    $session = get_session($token);
    if (!$session) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
        exit;
    }
    if (!preg_match('#^https://[a-z0-9]+\.tenor\.com/#i', $gifUrl)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid GIF URL.']);
        exit;
    }
    if (!rate_limit_allow('send_message', get_client_ip(), 20, 10)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'You are sending messages too fast — please slow down.']);
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
    if ($session['role'] !== 'super') {
        $remaining = mute_remaining_ms($config, $session['user']);
        if ($remaining > 0) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'muted', 'message' => 'You are muted for ' . ceil($remaining / 1000) . ' more second(s).', 'mutedRemainingMs' => $remaining]);
            exit;
        }
    }

    $replySnippet = $replyToId > 0 ? build_reply_snippet(find_message($room, $replyToId)) : null;
    $newMsg = append_message($room, [
        'type' => 'gif',
        'user' => $session['user'],
        'role' => $session['role'],
        'text' => '',
        'gifUrl' => $gifUrl,
        'replyTo' => $replySnippet,
        'reactions' => [],
    ]);
    echo json_encode(['ok' => true, 'message' => $newMsg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$q = isset($_GET['q']) ? (string)$_GET['q'] : '';
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session']);
    exit;
}

if (!rate_limit_allow('gif_search', get_client_ip(), 30, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many GIF searches — please slow down.']);
    exit;
}

$errorOut = null;
$results = search_gifs($q, 16, $errorOut);
echo json_encode(['ok' => true, 'results' => $results, 'warning' => $errorOut]);
