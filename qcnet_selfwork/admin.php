<?php
// admin.php — small admin actions bundled together:
//   action=set_title            : host or super — rename the room (emoji ok)
//   action=reset_host_password  : super only — clears the host password
//                                  so the next host login sets a new one,
//                                  and kicks any current host session
//   action=unban                : host or super — lift a ban

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
$action = isset($body['action']) ? $body['action'] : '';

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}

$room = $session['room'];
$config = read_config($room);
if (!$config) $config = default_config();

if ($action === 'set_title') {
    if (!is_effective_host($config, $session)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can rename this room.']);
        exit;
    }
    $title = isset($body['title']) ? (string)$body['title'] : '';
    // Strip control characters/tags but keep emoji (multi-byte unicode is fine).
    $title = trim($title);
    $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title);
    $title = mb_substr($title, 0, 60);
    $config['title'] = $title;
    write_config($room, $config);
    echo json_encode(['ok' => true, 'title' => $title]);
    exit;
}

if ($action === 'reset_host_password') {
    if ($session['role'] !== 'super') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a super user can reset the host password.']);
        exit;
    }
    $config['hostPasswordHash'] = null;
    write_config($room, $config);
    remove_host_sessions($room);
    remove_host_remember_tokens($room);
    echo json_encode(['ok' => true, 'message' => 'Host password cleared. The next person to log in as host will set a new one.']);
    exit;
}

if ($action === 'unban') {
    if (!is_effective_host($config, $session)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can unban people.']);
        exit;
    }
    $targetUser = trim((string)($body['targetUser'] ?? ''));
    if ($targetUser === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No user specified.']);
        exit;
    }
    unban_user($config, $targetUser);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'unbanned' => $targetUser]);
    exit;
}

if ($action === 'unban_ip') {
    // Room-scoped IP unban — lifts an auto-ban set by kick.php for THIS
    // room only. Distinct from the site-wide firewall unblock_ip below.
    if (!is_effective_host($config, $session)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can unblock IPs.']);
        exit;
    }
    $ip = trim((string)($body['ip'] ?? ''));
    if ($ip === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No IP specified.']);
        exit;
    }
    unban_ip($config, $ip);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'unblocked' => $ip]);
    exit;
}

if ($action === 'ban_target') {
    // Super-only. Explicit ban of a user's IP, offered right next to
    // Mute/Kick in the presence list — separate from kick.php, which
    // removes the user AND auto-bans their IP for this room only.
    // This action lets a super user choose the scope up front:
    //   scope=room -> same as kick's auto-ban, but without removing
    //                 the user from the room right now (banned on next join)
    //   scope=site -> uses the site-wide firewall (block_ip), so the
    //                 IP can't reach ANY room, not just this one
    if ($session['role'] !== 'super') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a super user can ban an IP address.']);
        exit;
    }
    $targetUser = trim((string)($body['targetUser'] ?? ''));
    $scope = trim((string)($body['scope'] ?? 'room')); // 'room' or 'site'
    if ($targetUser === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No user specified.']);
        exit;
    }
    $knownIp = $config['userIps'][strtolower($targetUser)] ?? null;
    if (!$knownIp) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No known IP address for this user in this room.']);
        exit;
    }
    if ($scope === 'site') {
        $reason = 'Banned from room "' . $room . '" by super user ' . $session['user'];
        block_ip($knownIp, $reason, 0); // 0 = permanent; super can lift it from the firewall panel
        echo json_encode(['ok' => true, 'banned' => $targetUser, 'ip' => $knownIp, 'scope' => 'site']);
        exit;
    }
    // Default: room-scoped ban only (does not remove them from the room
    // right now — they simply can't rejoin once they leave/are kicked).
    ban_ip($config, $knownIp);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'banned' => $targetUser, 'ip' => $knownIp, 'scope' => 'room']);
    exit;
}

if ($action === 'pin_message') {
    if ($session['role'] !== 'super') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a super user can pin messages.']);
        exit;
    }
    $messageId = isset($body['messageId']) ? intval($body['messageId']) : 0;
    if ($messageId <= 0 || !find_message($room, $messageId)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Message not found.']);
        exit;
    }
    $config['pinnedMessageId'] = $messageId;
    write_config($room, $config);
    echo json_encode(['ok' => true, 'pinnedMessageId' => $messageId]);
    exit;
}

if ($action === 'unpin_message') {
    if ($session['role'] !== 'super') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a super user can unpin messages.']);
        exit;
    }
    $config['pinnedMessageId'] = null;
    write_config($room, $config);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'set_encryption') {
    if (!in_array($session['role'], ['host', 'super'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can change encryption for this room.']);
        exit;
    }
    $enabled = !empty($body['enabled']);
    if ($enabled) {
        // The client always sends the passphrase now (no per-room opt-out):
        // a verified super session can recover it automatically for any
        // room (see SUPER_MASTER_SECRET in common.php).
        $passphrase = isset($body['passphrase']) && $body['passphrase'] !== '' ? (string)$body['passphrase'] : null;
        enable_encryption($config, $passphrase);
    } else {
        disable_encryption($config);
    }
    write_config($room, $config);
    echo json_encode(['ok' => true, 'encEnabled' => $config['encEnabled'], 'encSalt' => $config['encSalt']]);
    exit;
}

// ---------------------------------------------------------------------
// Firewall admin actions — site-wide (not room-scoped), so restricted to
// super users only. See FIREWALL_HOW_IT_WORKS.txt for how blocking works.
// ---------------------------------------------------------------------

if ($action === 'block_ip') {
    if ($session['role'] !== 'super') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a super user can block an IP address.']);
        exit;
    }
    $targetIp = trim((string)($body['ip'] ?? ''));
    if ($targetIp === '' || !filter_var($targetIp, FILTER_VALIDATE_IP)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A valid IP address is required.']);
        exit;
    }
    $reason = isset($body['reason']) ? mb_substr(trim((string)$body['reason']), 0, 200) : '';
    $durationSeconds = isset($body['durationSeconds']) ? max(0, intval($body['durationSeconds'])) : 0; // 0 = permanent
    block_ip($targetIp, $reason, $durationSeconds);
    echo json_encode(['ok' => true, 'blocked' => $targetIp]);
    exit;
}

if ($action === 'unblock_ip') {
    if ($session['role'] !== 'super') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a super user can unblock an IP address.']);
        exit;
    }
    $targetIp = trim((string)($body['ip'] ?? ''));
    if ($targetIp === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No IP specified.']);
        exit;
    }
    unblock_ip($targetIp);
    echo json_encode(['ok' => true, 'unblocked' => $targetIp]);
    exit;
}

if ($action === 'list_blocked_ips') {
    if ($session['role'] !== 'super') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a super user can view the block list.']);
        exit;
    }
    echo json_encode(['ok' => true, 'blocked' => list_blocked_ips()]);
    exit;
}

if ($action === 'set_file_approval') {
    if (!in_array($session['role'], ['host', 'super'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can change this setting.']);
        exit;
    }
    $config['requireFileApproval'] = !empty($body['enabled']);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'requireFileApproval' => $config['requireFileApproval']]);
    exit;
}

if ($action === 'set_extra_uploads') {
    // Super-only: unlocks extra file types (beyond the normal member
    // whitelist) for hosts and members in THIS room, optionally for a
    // limited time. See EXTENDED FILE UPLOADS in README.
    if ($session['role'] !== 'super') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a super user can change extended file upload access.']);
        exit;
    }
    $enabled = !empty($body['enabled']);
    $allowAll = !empty($body['allowAll']);
    $minutes = isset($body['minutes']) ? max(0, intval($body['minutes'])) : 0;
    $catalog = extra_upload_catalog();
    $requested = isset($body['extensions']) && is_array($body['extensions']) ? $body['extensions'] : [];
    $extensions = [];
    foreach ($requested as $e) {
        $e = strtolower(trim((string)$e));
        if (in_array($e, $catalog, true) && !in_array($e, $extensions, true)) {
            $extensions[] = $e;
        }
    }
    $config['extraUploads'] = [
        'enabled' => $enabled,
        'expiresAt' => ($enabled && $minutes > 0) ? (now_ms() + $minutes * 60 * 1000) : 0,
        'allowAll' => $allowAll,
        'extensions' => $extensions,
    ];
    write_config($room, $config);
    echo json_encode(['ok' => true, 'extraUploads' => $config['extraUploads']]);
    exit;
}

if ($action === 'approve_file_request') {
    if (!in_array($session['role'], ['host', 'super'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can approve file requests.']);
        exit;
    }
    $requestId = isset($body['requestId']) ? (string)$body['requestId'] : '';
    $request = find_file_request($config, $requestId);
    if (!$request) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'That request no longer exists (it may have already been approved or denied).']);
        exit;
    }
    remove_file_request($config, $requestId);
    write_config($room, $config);
    unset($request['id'], $request['ts']);
    $newMsg = append_message($room, $request);
    echo json_encode(['ok' => true, 'message' => $newMsg]);
    exit;
}

if ($action === 'deny_file_request') {
    if (!in_array($session['role'], ['host', 'super'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can deny file requests.']);
        exit;
    }
    $requestId = isset($body['requestId']) ? (string)$body['requestId'] : '';
    $request = find_file_request($config, $requestId);
    if (!$request) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'That request no longer exists (it may have already been approved or denied).']);
        exit;
    }
    // The file was already saved to disk when it was uploaded (see
    // upload.php) — since it's never becoming a message, remove it
    // instead of leaving an orphaned file behind.
    if (!empty($request['fileUrl'])) {
        $path = __DIR__ . '/' . ltrim($request['fileUrl'], '/');
        $realPath = realpath($path);
        $realFilesDir = realpath($GLOBALS['filesDir']);
        if ($realPath !== false && $realFilesDir !== false && strpos($realPath, $realFilesDir . DIRECTORY_SEPARATOR) === 0) {
            @unlink($realPath);
        }
    }
    remove_file_request($config, $requestId);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'denied' => $requestId]);
    exit;
}

// ---------------------------------------------------------------------
// v35 additions
// ---------------------------------------------------------------------

if ($action === 'set_slow_mode') {
    if (!is_effective_host($config, $session)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can change slow mode.']);
        exit;
    }
    $seconds = isset($body['seconds']) ? max(0, intval($body['seconds'])) : 0;
    $config['slowModeSeconds'] = min($seconds, 3600);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'slowModeSeconds' => $config['slowModeSeconds']]);
    exit;
}

if ($action === 'add_banned_word') {
    if (!is_effective_host($config, $session)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can edit the word filter.']);
        exit;
    }
    $word = isset($body['word']) ? (string)$body['word'] : '';
    add_banned_word($config, $word);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'bannedWords' => $config['bannedWords']]);
    exit;
}

if ($action === 'remove_banned_word') {
    if (!is_effective_host($config, $session)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can edit the word filter.']);
        exit;
    }
    $word = isset($body['word']) ? (string)$body['word'] : '';
    remove_banned_word($config, $word);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'bannedWords' => $config['bannedWords']]);
    exit;
}

if ($action === 'add_co_host') {
    if (!in_array($session['role'], ['host', 'super'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can add a co-host.']);
        exit;
    }
    $username = isset($body['username']) ? (string)$body['username'] : '';
    if (trim($username) === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No username specified.']);
        exit;
    }
    add_co_host($config, $username);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'coHosts' => $config['coHosts']]);
    exit;
}

if ($action === 'remove_co_host') {
    if (!in_array($session['role'], ['host', 'super'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can remove a co-host.']);
        exit;
    }
    $username = isset($body['username']) ? (string)$body['username'] : '';
    remove_co_host($config, $username);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'coHosts' => $config['coHosts']]);
    exit;
}

if ($action === 'add_co_super') {
    // Super-only (not host) — granting theme powers is a super-tier
    // decision, unlike co-host which a host can also hand out.
    if ($session['role'] !== 'super') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a super user can add a co-super.']);
        exit;
    }
    $username = isset($body['username']) ? (string)$body['username'] : '';
    if (trim($username) === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No username specified.']);
        exit;
    }
    add_co_super($config, $username);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'coSupers' => $config['coSupers']]);
    exit;
}

if ($action === 'remove_co_super') {
    if ($session['role'] !== 'super') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only a super user can remove a co-super.']);
        exit;
    }
    $username = isset($body['username']) ? (string)$body['username'] : '';
    remove_co_super($config, $username);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'coSupers' => $config['coSupers']]);
    exit;
}

if ($action === 'set_host_notify_email') {
    if (!in_array($session['role'], ['host', 'super'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can set the notification email.']);
        exit;
    }
    $email = isset($body['email']) ? trim((string)$body['email']) : '';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'That does not look like a valid email address.']);
        exit;
    }
    $config['hostNotifyEmail'] = $email;
    write_config($room, $config);
    echo json_encode(['ok' => true, 'hostNotifyEmail' => $config['hostNotifyEmail']]);
    exit;
}

if ($action === 'set_auto_cleanup') {
    if (!in_array($session['role'], ['host', 'super'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can change auto-cleanup.']);
        exit;
    }
    $days = isset($body['days']) ? max(0, intval($body['days'])) : 0;
    $config['autoCleanupDays'] = min($days, 3650);
    write_config($room, $config);
    echo json_encode(['ok' => true, 'autoCleanupDays' => $config['autoCleanupDays']]);
    exit;
}

if ($action === 'save_template') {
    if (!in_array($session['role'], ['host', 'super'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can save a room template.']);
        exit;
    }
    $name = isset($body['name']) ? (string)$body['name'] : '';
    if (!save_template($name, $config)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A template name is required.']);
        exit;
    }
    echo json_encode(['ok' => true, 'templates' => read_templates()]);
    exit;
}

if ($action === 'apply_template') {
    if (!in_array($session['role'], ['host', 'super'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can apply a room template.']);
        exit;
    }
    $name = isset($body['name']) ? (string)$body['name'] : '';
    if (!apply_template($config, $name)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Template not found.']);
        exit;
    }
    write_config($room, $config);
    echo json_encode(['ok' => true, 'roomTheme' => $config['roomTheme'], 'slowModeSeconds' => $config['slowModeSeconds'], 'bannedWords' => $config['bannedWords'], 'requireFileApproval' => $config['requireFileApproval']]);
    exit;
}

if ($action === 'list_templates') {
    echo json_encode(['ok' => true, 'templates' => array_values(read_templates())]);
    exit;
}

if ($action === 'delete_template') {
    if (!in_array($session['role'], ['host', 'super'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can delete a room template.']);
        exit;
    }
    $name = isset($body['name']) ? (string)$body['name'] : '';
    delete_template($name);
    echo json_encode(['ok' => true, 'templates' => read_templates()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
