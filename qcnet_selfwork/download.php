<?php
// download.php — the ONLY way chat files should be fetched. files/ is
// now locked with "Require all denied" (see common.php), so a raw URL
// to an uploaded file no longer works even if someone has it; this is
// the controlled endpoint OWASP's File Upload Cheat Sheet recommends
// in its place: authenticate the request, re-check the path stays
// inside the room's own folder, and set headers from our OWN safe
// lookup table rather than trusting anything stored or client-supplied.
//
// Query params:
//   token     session token (same one used everywhere else in the app)
//   room      room name — cross-checked against the session
//   path      the message's stored fileUrl, e.g. files/abc12/name.ext
//   name      (optional) original filename, cosmetic only — used for
//             the Content-Disposition filename, never for locating
//             the file on disk
//   download  if present, always forces a browser download prompt
//             (Content-Disposition: attachment) regardless of file
//             type; otherwise images/audio/video are sent "inline" so
//             they can render/play in the chat, and everything else
//             downloads by default

require_once __DIR__ . '/common.php';

header('Access-Control-Allow-Origin: *');

$token = isset($_GET['token']) ? $_GET['token'] : '';
$room = isset($_GET['room']) ? $_GET['room'] : '';
$path = isset($_GET['path']) ? $_GET['path'] : '';

$session = get_session($token);
if (!$session || $session['room'] !== safe_room_name($room)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}

$config = read_config($room);
if (!$config) $config = default_config();
if ($session['role'] === 'member' && is_banned($config, $session['user'])) {
    remove_sessions_for_user($room, $session['user']);
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'banned', 'message' => 'You have been removed from this chat by the host.']);
    exit;
}

if (!rate_limit_allow('download', get_client_ip(), 120, 30)) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many downloads — please wait a bit and try again.']);
    exit;
}

// --- Path validation ----------------------------------------------
// $path must be exactly "files/<this room's slug>/<...>". Reject
// anything else outright (fast path for the common "not even trying"
// case), then resolve it for real and require the result to still
// live inside filesDir — the actual defense, since string checks
// alone can be fooled by encoding tricks or symlink games.
$roomSlug = safe_room_name($room);
$expectedPrefix = 'files/' . $roomSlug . '/';
if ($path === '' || strpos($path, '..') !== false || strpos($path, "\0") !== false || strpos($path, $expectedPrefix) !== 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid file path.']);
    exit;
}

$fullPath = __DIR__ . '/' . $path;
$realPath = realpath($fullPath);
$realFilesDir = realpath($GLOBALS['filesDir']);

if ($realPath === false || $realFilesDir === false || strpos($realPath, $realFilesDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'File not found.']);
    exit;
}

$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mimeType = safe_download_mime($ext);

$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
$audioExts = ['mp3', 'wav', 'ogg', 'm4a', 'aac'];
$videoExts = ['mp4', 'webm', 'mov', 'mkv', 'avi'];
$isPreviewable = in_array($ext, $imageExts, true) || in_array($ext, $audioExts, true) || in_array($ext, $videoExts, true);
$forceDownload = isset($_GET['download']);
$inline = $isPreviewable && !$forceDownload;

// Best-effort extra layer for SVGs (XML, can carry a <script>): on
// hosts where this doesn't get overridden by the blanket CSP header
// in the root .htaccess (Apache's "Header always set" replaces same-
// named headers, including ones PHP already sent, so this isn't
// guaranteed to survive), it blocks script execution even if the file
// is somehow opened as its own document. The actual guarantee is on
// the client side: index.html never navigates to an SVG's inline URL
// directly — see the image click handler — so this is belt-and-braces
// on top of that, not the primary defense.
if ($ext === 'svg') {
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
}

$rawName = isset($_GET['name']) ? $_GET['name'] : basename($realPath);
$downloadName = preg_replace('/[\x00-\x1F\x7F]/', '', $rawName);
if ($downloadName === '') $downloadName = basename($realPath);

stream_file_with_range($realPath, $mimeType, $downloadName, $inline);
