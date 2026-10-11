<?php
// wallpaper_upload.php — lets a super user upload an image from their
// device to use as the ROOM'S wallpaper (stored on the server, so it
// persists and is visible to everyone — unlike a plain pasted URL,
// which depends on an external site staying up).

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

$token = isset($_POST['token']) ? $_POST['token'] : '';
$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}
if ($session['role'] !== 'super') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only a super user can set the room wallpaper.']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Upload failed.']);
    exit;
}

$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$file = $_FILES['file'];
$maxSize = 15 * 1024 * 1024; // 15 MB is plenty for a wallpaper image
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Image too large (max 15 MB).']);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $imageExts, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please upload a jpg, png, gif, or webp image.']);
    exit;
}

if (!verify_file_format($file['tmp_name'], $ext)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "This file's content doesn't match its .$ext extension, so it was rejected."]);
    exit;
}

$room = $session['room'];
$dir = room_wallpapers_dir($room);
$uniqueName = 'wallpaper_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
$destPath = $dir . '/' . $uniqueName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save the image on the server.']);
    exit;
}

$fileUrl = 'files/' . rawurlencode(safe_room_name($room)) . '/wallpapers/' . rawurlencode($uniqueName);
$css = "url('" . str_replace("'", '%27', $fileUrl) . "') center/cover no-repeat fixed";

$config = read_config($room);
if (!$config) $config = default_config();
$config['roomTheme']['background'] = $css;
write_config($room, $config);

echo json_encode(['ok' => true, 'roomTheme' => $config['roomTheme']]);
