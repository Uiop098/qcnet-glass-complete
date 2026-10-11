<?php
// translate.php — v35, PART 2: "Inline translation". Server-side proxy
// to the public LibreTranslate instance (same pattern as
// linkpreview.php — the browser can't call a cross-origin API directly,
// and this keeps the target service's URL out of client-side code).
// Best-effort: the public instance has no uptime guarantee.

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
$text = isset($_GET['text']) ? (string)$_GET['text'] : '';
$target = isset($_GET['target']) ? (string)$_GET['target'] : 'en';

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session']);
    exit;
}

if (!rate_limit_allow('translate', get_client_ip(), 20, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many translation requests — please slow down.']);
    exit;
}

$translated = translate_text($text, $target);
if ($translated === null) {
    echo json_encode(['ok' => false, 'error' => 'Translation is temporarily unavailable.']);
    exit;
}

echo json_encode(['ok' => true, 'translated' => $translated]);
