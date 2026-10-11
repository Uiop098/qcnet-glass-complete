<?php
// search.php — v35, PART 2: "Message search". Case-insensitive substring
// search across the room's stored (already 24h-expiry-limited) message
// history. Anyone in the room can search.

require_once __DIR__ . '/common.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$token = isset($_GET['token']) ? $_GET['token'] : '';
$room = isset($_GET['room']) ? $_GET['room'] : '';
$q = isset($_GET['q']) ? (string)$_GET['q'] : '';

$session = get_session($token);
if (!$session || $session['room'] !== safe_room_name($room)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}

if (!rate_limit_allow('search', get_client_ip(), 30, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many searches — please slow down.']);
    exit;
}

if (mb_strlen(trim($q)) < 2) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

$results = search_messages($room, $q, 50);
echo json_encode(['ok' => true, 'results' => $results]);
