<?php
// theme.php — lets a super user set the ROOM'S wallpaper/background and
// the glow color used for their own messages. This is room-wide and
// visible to everyone in the room — only a super user can change it,
// nobody else can override it locally.

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

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}

$room = $session['room'];
$config = read_config($room);
if (!$config) $config = default_config();

$isFullSuper = ($session['role'] === 'super');
$isLimitedCoSuper = has_limited_theme_access($config, $session);

if (!$isFullSuper && !$isLimitedCoSuper) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only a super user (or a co-super) can change the room theme.']);
    exit;
}

// Co-supers can only pick from these exact server-known presets — no
// custom colors, no pasted URLs, no uploads. This list intentionally
// matches what the client shows them; anything else is rejected.
$PRESET_WALLPAPERS = ['', '#1b1030', '#062a28', '#2a120f', '#eaf3fb'];
$PRESET_GLOW_COLORS = ['', '#d4af37', '#8b5cf6', '#2dd4bf', '#ff8a4c', '#2f6fd6'];

function clean_css_value($val) {
    $val = trim((string)$val);
    $val = str_ireplace(['javascript:', '<', '>', '{', '}', ';'], '', $val);
    // Plain image URLs get auto-wrapped into a full CSS background value.
    if (preg_match('#^https?://#i', $val)) {
        $val = "url('" . str_replace("'", '%27', $val) . "') center/cover no-repeat fixed";
    }
    return mb_substr($val, 0, 400);
}

$allowedSkins = [
    '', 'skeuomorphism', 'minimalism', 'flat', 'bauhaus', 'bold',
    'neumorphism', 'glassmorphism', 'motion', 'illustration', 'darkmode', 'monochromatic',
];

if (array_key_exists('background', $body)) {
    $bg = (string)$body['background'];
    if ($isLimitedCoSuper && !in_array($bg, $PRESET_WALLPAPERS, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Co-supers can only pick from preset wallpapers, not custom colors or images.']);
        exit;
    }
    $config['roomTheme']['background'] = clean_css_value($bg);
}
if (array_key_exists('glowColor', $body)) {
    $glow = (string)$body['glowColor'];
    if ($isLimitedCoSuper && !in_array($glow, $PRESET_GLOW_COLORS, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Co-supers can only pick from preset glow colors.']);
        exit;
    }
    $config['roomTheme']['glowColor'] = clean_css_value($glow);
}
if (array_key_exists('uiSkin', $body)) {
    $skin = (string)$body['uiSkin'];
    // Every co-super restriction on wallpaper/glow above still allows
    // full skin switching per the site's design — skins are always
    // preset-only for everyone anyway (see $allowedSkins), so no
    // additional co-super check is needed here.
    $config['roomTheme']['uiSkin'] = in_array($skin, $allowedSkins, true) ? $skin : '';
}

// Golden Glass / Platinum / Obsidian palette + message animation — full
// super only (not available to limited co-supers, same restriction
// level as anything that changes the base look for the whole room
// rather than just an accent color).
// NOTE: this list must stay in sync with the data-theme buttons in
// room.html's theme accordion AND the body[data-theme=...] blocks in
// glass-theme.css — a theme missing here will silently fail to force
// room-wide even though it still works as a personal choice.
$ALLOWED_PALETTES = ['', 'nocturne', 'aurora', 'ember', 'frost', 'sakura', 'ocean', 'forest', 'mono', 'sunset', 'neon', 'wine', 'mint', 'golden', 'platinum', 'obsidian'];
$ALLOWED_ANIMATIONS = ['', 'slideGlow', 'popSparkle', 'rainbowSweep', 'goldShimmer'];

if (array_key_exists('forcePalette', $body)) {
    if (!$isFullSuper) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a full super user can force the room palette for everyone.']);
        exit;
    }
    $palette = (string)$body['forcePalette'];
    $config['roomTheme']['forcePalette'] = in_array($palette, $ALLOWED_PALETTES, true) ? $palette : '';
}
if (array_key_exists('forceMsgAnimation', $body)) {
    if (!$isFullSuper) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a full super user can force message animations for everyone.']);
        exit;
    }
    $anim = (string)$body['forceMsgAnimation'];
    $config['roomTheme']['forceMsgAnimation'] = in_array($anim, $ALLOWED_ANIMATIONS, true) ? $anim : '';
}

write_config($room, $config);

echo json_encode(['ok' => true, 'roomTheme' => $config['roomTheme']]);
