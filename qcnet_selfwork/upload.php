<?php
// upload.php — handles file uploads. Regular members are limited to a
// safe whitelist of file types; super users can upload virtually any
// file type EXCEPT a small blocklist of server-executable script
// extensions (kept even for super users, purely so nobody can
// accidentally drop a live script onto the server via chat).

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

$room = isset($_POST['room']) ? $_POST['room'] : '';
$token = isset($_POST['token']) ? $_POST['token'] : '';
$caption = isset($_POST['caption']) ? trim($_POST['caption']) : '';
$replyToId = isset($_POST['replyTo']) ? intval($_POST['replyTo']) : 0;
$isVoice = isset($_POST['kind']) && $_POST['kind'] === 'voice';

$session = get_session($token);
if (!$session || $session['room'] !== safe_room_name($room)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}

if (!rate_limit_allow('upload', get_client_ip(), 10, 30)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many uploads — please wait a bit and try again.']);
    exit;
}

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
        echo json_encode([
            'ok' => false,
            'error' => 'muted',
            'message' => 'You are muted for ' . ceil($remaining / 1000) . ' more second(s).',
            'mutedRemainingMs' => $remaining,
        ]);
        exit;
    }
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = isset($_FILES['file']) ? $_FILES['file']['error'] : 'no file';
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Upload failed (code: ' . $err . ')']);
    exit;
}

$isSuper = ($session['role'] === 'super');

// Regular members: whitelist of common safe types.
// NOTE (v35 security fix): 'svg' intentionally removed from this list.
// SVG is XML and can carry <script>/on* handlers; combined with this
// app's CSP (script-src 'unsafe-inline', required for index.html's
// inline <script>), a member-uploaded SVG opened directly in a new tab
// could execute JS in this site's origin. Super users can still upload
// svg (their uploads bypass this whitelist) since that's a trusted role.
$memberAllowedExtensions = array_merge([
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf',
    'mp3', 'wav', 'ogg', 'm4a', 'aac',
    'mp4', 'webm', 'mov', 'mkv', 'avi',
    'zip', 'rar', '7z',
], code_and_text_exts());
$memberAllowedExtensions = array_values(array_unique($memberAllowedExtensions));

// Blocked for EVERYONE, including super users — server-executable script
// types that could be dangerous if the host ever executes files by
// extension. This is the only upload restriction super users still hit.
$dangerousExtensions = [
    'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar',
    'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com',
    'asp', 'aspx', 'jsp', 'htaccess', 'ini',
];

$maxFileSizeBytes = $isSuper ? (200 * 1024 * 1024) : (25 * 1024 * 1024);

$file = $_FILES['file'];
if ($file['size'] > $maxFileSizeBytes) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'File too large (max ' . round($maxFileSizeBytes / 1024 / 1024) . ' MB)']);
    exit;
}

$origName = basename($file['name']);
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if (in_array($ext, $dangerousExtensions, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '.' . $ext . ' files are blocked for server safety, even for super users.']);
    exit;
}

// Regular members/hosts also get through if a super user has temporarily
// unlocked this specific extension via the "Extended file uploads"
// toggle (☰ > Manage room, super only — see EXTENDED FILE UPLOADS in
// README). extra_uploads_is_active() auto-expires the toggle if its
// optional timer has run out, so this always reflects current state.
$extraUploadsWasActive = !empty($config['extraUploads']['enabled']);
$viaExtraUploads = !$isSuper && extra_upload_extension_allowed($config, $ext);
if ($extraUploadsWasActive && !extra_uploads_is_active($config)) {
    // Toggle just auto-expired as a side effect of the check above — persist that.
    write_config($room, $config);
}

if (!$isSuper && !in_array($ext, $memberAllowedExtensions, true) && !$viaExtraUploads) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'File type .' . $ext . ' is not allowed for regular members.']);
    exit;
}

// The extension is just a label the uploader's file happened to have —
// confirm the content actually looks like that format before it ever
// touches disk. Catches a mismatched/disguised file (e.g. a script or
// HTML payload renamed to .jpg) that the extension checks above can't.
if (!verify_file_format($file['tmp_name'], $ext)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "This file's content doesn't match its .$ext extension, so it was rejected."]);
    exit;
}

$safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
$safeBase = substr($safeBase, 0, 60) ?: 'file';
$uniqueName = $safeBase . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . ($ext !== '' ? '.' . $ext : '');

$destDir = room_files_dir($room);
$destPath = $destDir . '/' . $uniqueName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save file on server']);
    exit;
}

$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
$audioExts = ['mp3', 'wav', 'ogg', 'm4a', 'aac'];
$videoExts = ['mp4', 'webm', 'mov', 'mkv', 'avi'];

if ($isVoice) {
    // Recorded voice note — MediaRecorder's container extension varies
    // by browser (webm, mp4, ogg...); always render it as audio.
    $category = 'audio';
} elseif (in_array($ext, $imageExts, true)) {
    $category = 'image';
} elseif (in_array($ext, $audioExts, true)) {
    $category = 'audio';
} elseif (in_array($ext, $videoExts, true)) {
    $category = 'video';
} elseif (in_array($ext, code_and_text_exts(), true)) {
    $category = 'code';
} else {
    $category = 'file';
}

$fileUrl = 'files/' . rawurlencode(safe_room_name($room)) . '/' . rawurlencode($uniqueName);
$replySnippet = $replyToId > 0 ? build_reply_snippet(find_message($room, $replyToId)) : null;

$fileFields = [
    'type' => 'file',
    'user' => $session['user'],
    'role' => $session['role'],
    'text' => $caption,
    'fileName' => $origName,
    'fileUrl' => $fileUrl,
    'fileSize' => $file['size'],
    'fileExt' => $ext,
    'fileCategory' => $category,
    'isVoiceMessage' => $isVoice,
    'replyTo' => $replySnippet,
    'reactions' => [],
    // Cosmetic only — records whether the room's encryption toggle was
    // on at send time, so the UI can label the file accordingly. The
    // file's own bytes are NOT encrypted; only message text is (see
    // MESSAGE ENCRYPTION in README). Stored per-file rather than read
    // from the room's current state at render time so a file's label
    // doesn't change retroactively if encryption is toggled later.
    'sentWhileEncrypted' => !empty($config['encEnabled']),
];

if (!empty($config['requireFileApproval']) && $session['role'] === 'member') {
    // Approval required: the file is already safely on disk (validated
    // above like any other upload) but doesn't become a chat message
    // yet — it waits for a host/super to approve or deny it from the
    // Manage room panel. See admin.php's approve_file_request /
    // deny_file_request and FILE APPROVAL REQUESTS in README.
    $request = add_file_request($config, $fileFields);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'pending' => true, 'request' => $request]);
    exit;
}

$newMsg = append_message($room, $fileFields);

echo json_encode(['ok' => true, 'message' => $newMsg]);
