<?php
// sticker.php — v35, PART 2: "Sticker packs". Sends one of the curated
// stickers (see sticker_catalog() in common.php) as a distinct message
// type, referencing the sticker ID rather than uploading a new file
// each time. GET returns the catalog for the picker UI.

require_once __DIR__ . '/common.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // extraStickers perk — GET has no session yet at this point in the
    // original code, so read premium status from the room query param
    // if present (room.html passes its own room name). Falls back to the
    // base catalog if room is missing/unknown, same as before this change.
    $roomParam = isset($_GET['room']) ? (string)$_GET['room'] : '';
    $roomConfig = $roomParam !== '' ? read_config($roomParam) : null;
    $includePremium = $roomConfig && !empty($roomConfig['isPremium']) && in_array('extraStickers', $roomConfig['premiumPerks'] ?? [], true);
    echo json_encode(['ok' => true, 'stickers' => sticker_catalog($includePremium)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$token = isset($body['token']) ? $body['token'] : '';
$stickerId = isset($body['stickerId']) ? (string)$body['stickerId'] : '';
$replyToId = isset($body['replyTo']) ? intval($body['replyTo']) : 0;

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
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
    remove_sessions_for_user($room, $session['user']);
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

$sticker = sticker_by_id($stickerId, !empty($config['isPremium']) && in_array('extraStickers', $config['premiumPerks'] ?? [], true));
if (!$sticker) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown sticker.']);
    exit;
}

$replySnippet = $replyToId > 0 ? build_reply_snippet(find_message($room, $replyToId)) : null;

$newMsg = append_message($room, [
    'type' => 'sticker',
    'user' => $session['user'],
    'role' => $session['role'],
    'text' => '',
    'stickerId' => $sticker['id'],
    'stickerEmoji' => $sticker['emoji'],
    'replyTo' => $replySnippet,
    'reactions' => [],
]);

echo json_encode(['ok' => true, 'message' => $newMsg]);
