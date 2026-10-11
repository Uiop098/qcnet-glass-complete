<?php
// dash_msg_bridge.php - lets the Dashboard embed the real QuickChat room
// UI (room.html, in an iframe) without the user ever seeing a room-name/PIN
// login step. It mints QuickChat sessions directly via create_session()
// (the same function auth.php uses after a normal login), for two flows:
//
//   1. Admin <-> Client "chat with admin" - a fixed, deterministic room per
//      client, auto-created on first use. The admin always appears as
//      "Admin" in it; the client sees their own name.
//   2. Client <-> Client peer chat - request/accept via a short-lived
//      token, same pattern as every other invite-token flow in this app.
//      Only between clients of the SAME business (tenant).
//
// FIX (403 bug): common.php overwrites $GLOBALS['dataDir'] to './data'
// at include-time AND calls is_ip_blocked() using that path. If the dashboard
// has been logging rate-limit hits to data_dash/security but common.php
// reads from data/ (a completely different blocklist), IP blocks may appear
// where none exist. Solution: set $GLOBALS['dataDir'] to the QuickChat data
// folder BEFORE requiring common.php so it reads the right blocklist,
// then restore it after so dashboard helpers still point at data_dash/.
// Also: dash_firewall.php must come FIRST (starts the session), and
// common.php's own is_ip_blocked() call uses whatever dataDir is set at
// require time - we set it to the real chat data dir so it reads the right
// list, not an empty/nonexistent one that could misfire.

//  1. Dashboard session + sanitization (must be first - starts session) 
require_once __DIR__ . '/dash_firewall.php';
require_once __DIR__ . '/dash_crypto.php';
require_once __DIR__ . '/dash_audit.php';

header('Content-Type: application/json');

//  2. Auth check - must happen before common.php is loaded 
// dash_firewall.php already called session_start(); $_SESSION is live.
if (!isset($_SESSION['dash_admin']) && !isset($_SESSION['dash_user'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized access.']));
}

$is_admin    = isset($_SESSION['dash_admin']);
$my_username = $is_admin ? $_SESSION['dash_admin'] : $_SESSION['dash_user'];
$tenant_id   = $_SESSION['dash_tenant'] ?? 'main';
$safe_tenant = preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenant_id);
$dir         = ($tenant_id === 'main')
             ? __DIR__ . '/data_dash'
             : __DIR__ . '/data_dash/' . $safe_tenant;

//  3. Load common.php with dataDir pointing at the real QuickChat store 
// common.php sets $GLOBALS['dataDir'] = __DIR__.'/data' at the top and
// immediately calls is_ip_blocked() using that path. We pre-set it to the
// same value so common.php's built-in IP check reads the real chat blocklist
// (not an empty folder), preventing spurious 403s. create_session() and
// room helpers also use this path correctly.
$GLOBALS['dataDir']  = __DIR__ . '/data';
$GLOBALS['filesDir'] = __DIR__ . '/files';
require_once __DIR__ . '/common.php';
// common.php re-sets these to the same values so no restore needed.

$action = $_GET['action'] ?? '';

//  Helpers 
function tenant_admin_display($tenant_id, $dir) {
    return 'Admin';
}

function my_display_name($dir, $username, $is_admin) {
    if ($is_admin) return tenant_admin_display(null, $dir);
    $users = get_secure_table($dir . '/users.json');
    return $users[$username]['name'] ?? $username;
}

function ensure_chat_room($room) {
    $config = read_config($room);
    if (!$config) {
        $config             = default_config();
        $config['createdAt'] = now_ms();
        // PIN generated for schema consistency but never shown - access is
        // bridge-gated, not PIN-gated.
        $config['pinHash']  = hash('sha256', bin2hex(random_bytes(8)));
        write_config($room, $config);
    }
}

//  Action router 
switch ($action) {

    case 'get_admin_dm':
        // Client opens their support channel with this business's admin.
        if ($is_admin) {
            die(json_encode(['error' => 'Open a specific client\'s chat from Contacts instead.']));
        }
        $room = 'dm-' . $safe_tenant . '-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $my_username);
        ensure_chat_room($room);
        $display = my_display_name($dir, $my_username, false);
        $token   = create_session($room, $display, 'member');
        echo json_encode([
            'status'     => 'success',
            'token'      => $token,
            'room'       => safe_room_name($room),
            'role'       => 'member',
            'user'       => $display,
            'peer_label' => 'Admin',
        ]);
        break;

    case 'get_dm_with_user':
        // Admin opens a specific client's support channel.
        if (!$is_admin) {
            die(json_encode(['error' => 'Only the admin can open a client\'s chat this way.']));
        }
        $target = trim($_GET['username'] ?? '');
        $users  = get_secure_table($dir . '/users.json');
        if (!isset($users[$target])) {
            die(json_encode(['error' => 'User not found.']));
        }
        $room    = 'dm-' . $safe_tenant . '-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $target);
        ensure_chat_room($room);
        $display = tenant_admin_display($tenant_id, $dir);
        $token   = create_session($room, $display, 'host');
        echo json_encode([
            'status'     => 'success',
            'token'      => $token,
            'room'       => safe_room_name($room),
            'role'       => 'host',
            'user'       => $display,
            'peer_label' => $users[$target]['name'] ?? $target,
        ]);
        break;

    case 'request_peer_chat':
        if ($is_admin) {
            die(json_encode(['error' => 'Peer chat is between clients only.']));
        }
        if (!rate_limit_allow('chat_peer_request', get_client_ip(), 15, 600)) {
            http_response_code(429);
            die(json_encode(['error' => 'Too many requests.']));
        }
        $target = trim($_POST['username'] ?? '');
        $users  = get_secure_table($dir . '/users.json');
        if ($target === $my_username) {
            die(json_encode(['error' => 'You can\'t request a chat with yourself.']));
        }
        if (!isset($users[$target])) {
            die(json_encode(['error' => 'No user with that username in this business.']));
        }
        $requests_file = $dir . '/peer_chat_requests.json';
        $requests      = get_secure_table($requests_file);
        $now           = time();
        // Prune expired requests
        $requests = array_filter($requests, function ($r) use ($now) {
            return ($r['expires_at'] ?? 0) > $now;
        });
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $token    = '';
        for ($i = 0; $i < 8; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $requests[$token] = [
            'from'       => $my_username,
            'to'         => $target,
            'expires_at' => $now + (24 * 60 * 60),
        ];
        save_secure_table($requests_file, $requests);
        echo json_encode(['status' => 'success', 'token' => $token]);
        break;

    case 'redeem_peer_token':
        if ($is_admin) {
            die(json_encode(['error' => 'Peer chat is between clients only.']));
        }
        if (!rate_limit_allow('chat_peer_redeem', get_client_ip(), 15, 600)) {
            http_response_code(429);
            die(json_encode(['error' => 'Too many attempts.']));
        }
        $token         = strtoupper(trim($_POST['token'] ?? ''));
        $requests_file = $dir . '/peer_chat_requests.json';
        $requests      = get_secure_table($requests_file);
        if (!isset($requests[$token]) || ($requests[$token]['expires_at'] ?? 0) < time()) {
            die(json_encode(['error' => 'Invalid or expired token.']));
        }
        $from = $requests[$token]['from'];
        if ($from === $my_username) {
            die(json_encode(['error' => 'You can\'t redeem your own request token.']));
        }
        $room = 'peer-' . $safe_tenant . '-' . bin2hex(random_bytes(6));
        ensure_chat_room($room);
        $chats_file = $dir . '/peer_chats.json';
        $chats      = get_secure_table($chats_file);
        $chats[$room] = ['participants' => [$from, $my_username], 'created_at' => time()];
        save_secure_table($chats_file, $chats);
        unset($requests[$token]);
        save_secure_table($requests_file, $requests);
        if (function_exists('log_activity')) {
            log_activity($dir, $my_username, 'peer_chat_started', "Started a chat with $from.");
        }
        echo json_encode(['status' => 'success', 'room' => $room]);
        break;

    case 'get_my_chats':
        if ($is_admin) {
            die(json_encode(['error' => 'Not applicable for admin accounts.']));
        }
        $users = get_secure_table($dir . '/users.json');
        $chats = get_secure_table($dir . '/peer_chats.json');
        $out   = [[
            'type'  => 'admin',
            'room'  => 'dm-' . $safe_tenant . '-' . $my_username,
            'label' => 'Admin',
            'icon'  => 'Admin',
        ]];
        foreach ($chats as $room => $c) {
            if (!in_array($my_username, $c['participants'] ?? [], true)) continue;
            $other  = ($c['participants'][0] === $my_username) ? $c['participants'][1] : $c['participants'][0];
            $out[]  = [
                'type'  => 'peer',
                'room'  => $room,
                'label' => $users[$other]['name'] ?? $other,
                'icon'  => 'Chat',
            ];
        }
        echo json_encode(['status' => 'success', 'chats' => $out]);
        break;

    case 'get_client_list':
        // Admin only - returns list of registered users for the chat sidebar.
        if (!$is_admin) {
            die(json_encode(['error' => 'Admin only.']));
        }
        $users = get_secure_table($dir . '/users.json');
        $list  = [];
        foreach ($users as $uname => $u) {
            $list[] = ['username' => $uname, 'name' => $u['name'] ?? $uname];
        }
        echo json_encode(['status' => 'success', 'clients' => $list]);
        break;

    case 'open_chat_session':
        // Re-issues a fresh session token for a chat the caller is already
        // part of - used every time the chat window is (re)opened.
        $room        = trim($_GET['room'] ?? '');
        if (empty($room)) {
            die(json_encode(['error' => 'Room not specified.']));
        }
        $is_admin_dm = (strpos($room, 'dm-' . $safe_tenant . '-') === 0);
        ensure_chat_room($room);

        if ($is_admin_dm) {
            $owner = substr($room, strlen('dm-' . $safe_tenant . '-'));
            if ($is_admin) {
                $display = tenant_admin_display($tenant_id, $dir);
                $token   = create_session($room, $display, 'host');
                $role    = 'host';
            } elseif ($owner === $my_username) {
                $display = my_display_name($dir, $my_username, false);
                $token   = create_session($room, $display, 'member');
                $role    = 'member';
            } else {
                die(json_encode(['error' => 'Not your chat.']));
            }
        } else {
            $chats = get_secure_table($dir . '/peer_chats.json');
            if (!isset($chats[$room]) || !in_array($my_username, $chats[$room]['participants'] ?? [], true)) {
                die(json_encode(['error' => 'Not your chat.']));
            }
            $display = my_display_name($dir, $my_username, false);
            $token   = create_session($room, $display, 'member');
            $role    = 'member';
        }
        echo json_encode([
            'status' => 'success',
            'token'  => $token,
            'room'   => safe_room_name($room),
            'role'   => $role,
            'user'   => $display,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
        break;
}
?>
