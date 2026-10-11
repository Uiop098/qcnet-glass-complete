<?php
// linkpreview.php — fetches a URL server-side and returns a small
// title/description/image preview, since browsers can't read the
// content of an arbitrary cross-origin page directly (CORS). Requires
// a valid session (any role) so this can't be used as an open proxy by
// random visitors. See fetch_link_preview()/url_is_safe_to_fetch() in
// common.php for the SSRF protections (public-IP-only, no redirects,
// http/https on 80/443 only).

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
$url = isset($_GET['url']) ? (string)$_GET['url'] : '';

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session']);
    exit;
}
if ($url === '' || strlen($url) > 2000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid url.']);
    exit;
}

$preview = fetch_link_preview($url);
echo json_encode(['ok' => true, 'preview' => $preview]);
