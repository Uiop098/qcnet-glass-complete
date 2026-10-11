<?php
// auth.php — handles:
//   action=member       : join a room with its PIN
//   action=host         : log in as host (first login for a room SETS
//                         the host password and, optionally, the PIN)
//   action=super_login  : verify super credentials, returns a
//                         "master" token used to browse rooms
//   action=super_join   : master token + chosen room -> room-bound
//                         session (as Super, or anonymously as Host)

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/dash_2fa.php';

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
$action = isset($body['action']) ? $body['action'] : '';
$fwIp = get_client_ip();

function clean_username($raw) {
    return mb_substr(trim((string)$raw), 0, 40);
}

if ($action === 'member') {
    if (!rate_limit_allow('login_member', $fwIp, 12, 600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many join attempts — please wait a few minutes and try again.']);
        exit;
    }
    $room = isset($body['room']) ? $body['room'] : '';
    $pin = trim((string)($body['pin'] ?? ''));
    $username = clean_username($body['username'] ?? '');

    if ($room === '' || $pin === '' || $username === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Room, PIN, and username are all required.']);
        exit;
    }
    if (in_array(strtolower($username), ['host'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'That username is reserved. Please choose another.']);
        exit;
    }

    $config = read_config($room);
    if (!$config) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'This room hasn\'t been set up yet. Ask the host to log in first.']);
        exit;
    }
    if (empty($config['pinHash'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'The host hasn\'t set a PIN for this room yet.']);
        exit;
    }
    if (!hash_equals($config['pinHash'], hash('sha256', $pin))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Incorrect PIN.']);
        exit;
    }
    if (is_banned($config, $username)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You have been removed from this chat by the host.']);
        exit;
    }
    if (is_ip_banned($config, $fwIp)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'This device has been blocked from this chat.']);
        exit;
    }
    record_user_ip($config, $username, $fwIp);
    write_config($room, $config);

    $token = create_session($room, $username, 'member');
    $response = ['ok' => true, 'token' => $token, 'role' => 'member', 'user' => $username, 'room' => safe_room_name($room)];
    if (!empty($body['remember'])) {
        $response['rememberToken'] = create_remember_token($room, $username, 'member');
    }
    echo json_encode($response);
    exit;
}

if ($action === 'host') {
    if (!rate_limit_allow('login_host', $fwIp, 8, 600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many login attempts — please wait a few minutes and try again.']);
        exit;
    }
    $room = isset($body['room']) ? $body['room'] : '';
    $hostPassword = trim((string)($body['hostPassword'] ?? ''));
    $newPin = isset($body['newPin']) ? trim((string)$body['newPin']) : '';

    if ($room === '' || $hostPassword === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Room and host password are required.']);
        exit;
    }

    $config = read_config($room);
    $isNewRoom = false;
    if (!$config) {
        $config = default_config();
        $config['createdAt'] = now_ms();
        $isNewRoom = true;
    }

    if ($isNewRoom || empty($config['hostPasswordHash'])) {
        $config['hostPasswordHash'] = hash('sha256', $hostPassword);
    } else {
        if (!hash_equals($config['hostPasswordHash'], hash('sha256', $hostPassword))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Incorrect host password.']);
            exit;
        }
    }

    if ($newPin !== '') {
        $config['pinHash'] = hash('sha256', $newPin);
    }

    record_user_ip($config, 'Host', $fwIp);
    write_config($room, $config);

    $token = create_session($room, 'Host', 'host');
    $response = [
        'ok' => true,
        'token' => $token,
        'role' => 'host',
        'user' => 'Host',
        'room' => safe_room_name($room),
        'pinIsSet' => !empty($config['pinHash']),
    ];
    if (!empty($body['remember'])) {
        $response['rememberToken'] = create_remember_token($room, 'Host', 'host');
    }
    echo json_encode($response);
    exit;
}

if ($action === 'super_login') {
    if (!rate_limit_allow('login_super', $fwIp, 6, 600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many login attempts — please wait a few minutes and try again.']);
        exit;
    }
    $superUsername = trim((string)($body['superUsername'] ?? ''));
    $superPassword = (string)($body['superPassword'] ?? '');

    if ($superUsername === '' || $superPassword === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
        exit;
    }

    $superUsers = read_super_credentials();

    if (!isset($superUsers[$superUsername])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid super user credentials.']);
        exit;
    }

    // Dashboard-sourced accounts (master.json/tenants.json, bridged in via
    // read_super_credentials()) store bcrypt hashes; the legacy
    // super_users.php-seeded store uses SHA-256. Support both.
    $storedHash = $superUsers[$superUsername]['passwordHash'];
    $isValid = (strpos($storedHash, '$2y$') === 0)
        ? password_verify($superPassword, $storedHash)
        : hash_equals($storedHash, hash('sha256', $superPassword));

    if (!$isValid) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid super user credentials.']);
        exit;
    }

    $defaultName = $superUsers[$superUsername]['displayName'];
    $tier = $superUsers[$superUsername]['tier'] ?? 'master';

    // Same 2FA setup used on the Dashboard side (Profile page), now
    // enforced here too — previously logging into QuickChat with a
    // Dashboard account's password completely bypassed 2FA even when it
    // was enabled. is_2fa_recently_verified() is the cross-system bridge:
    // if this account already passed a 2FA challenge on the Dashboard (or
    // here) within the last 12 hours, it isn't asked again.
    if (!empty($superUsers[$superUsername]['twofaEnabled']) && !is_2fa_recently_verified($superUsername)) {
        $pendingToken = bin2hex(random_bytes(24));
        $tempDir = __DIR__ . '/data_dash/temp'; if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/2fa_pending_' . $pendingToken . '.json', json_encode([
            'context' => 'quickchat_super_login', 'username' => $superUsername, 'displayName' => $defaultName, 'tier' => $tier,
            'secret' => $superUsers[$superUsername]['twofaSecret'], 'recovery_codes' => $superUsers[$superUsername]['twofaRecoveryCodes'] ?? [],
            'expires' => time() + 300,
        ]));
        echo json_encode(['ok' => true, 'twofaRequired' => true, 'pendingToken' => $pendingToken]);
        exit;
    }

    $masterToken = create_session($GLOBALS['MASTER_ROOM'], $defaultName, 'super_master');
    // Stash the tier AND the raw login username on the session itself
    // (create_session only takes room/user/role, where 'user' is the
    // display name) — super_join() reads tier to decide room access, and
    // super_profile reads username to look up tenant email for the
    // tenant-tier landing page.
    $sessions = read_json_file(sessions_file()) ?: [];
    if (isset($sessions[$masterToken])) { $sessions[$masterToken]['tier'] = $tier; $sessions[$masterToken]['username'] = $superUsername; write_json_file(sessions_file(), $sessions); }
    echo json_encode(['ok' => true, 'masterToken' => $masterToken, 'defaultDisplayName' => $defaultName, 'superUsername' => $superUsername, 'tier' => $tier]);
    exit;
}

if ($action === 'super_verify_2fa') {
    if (!rate_limit_allow('quickchat_2fa_verify', $fwIp, 10, 600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts — please wait a few minutes and try again.']);
        exit;
    }
    $pendingToken = preg_replace('/[^a-zA-Z0-9]/', '', (string)($body['pendingToken'] ?? ''));
    $code = trim((string)($body['code'] ?? ''));
    $pf = __DIR__ . '/data_dash/temp/2fa_pending_' . $pendingToken . '.json';
    if (!file_exists($pf)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Session expired. Please log in again.']); exit; }
    $pending = json_decode(file_get_contents($pf), true);
    if (($pending['context'] ?? '') !== 'quickchat_super_login' || time() > $pending['expires']) {
        @unlink($pf); http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Session expired. Please log in again.']); exit;
    }
    $usedRecovery = false;
    if (!totp_verify($pending['secret'], $code)) {
        $codes = $pending['recovery_codes'] ?? [];
        $idx = array_search(strtoupper($code), array_map('strtoupper', $codes), true);
        if ($idx === false) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Incorrect code.']); exit; }
        $usedRecovery = true;
    }
    @unlink($pf);
    $masterToken = create_session($GLOBALS['MASTER_ROOM'], $pending['displayName'], 'super_master');
    $sessions = read_json_file(sessions_file()) ?: [];
    if (isset($sessions[$masterToken])) { $sessions[$masterToken]['tier'] = $pending['tier']; $sessions[$masterToken]['username'] = $pending['username']; write_json_file(sessions_file(), $sessions); }
    mark_2fa_verified($pending['username']);
    if ($usedRecovery) {
        // Burn the used recovery code so it can't be replayed — mirrors
        // dash_auth.php's verify_2fa_login recovery-code handling.
        require_once __DIR__ . '/dash_crypto.php';
        $store_file = $pending['tier'] === 'master' ? __DIR__ . '/data_dash/master.json' : __DIR__ . '/data_dash/tenants.json';
        $store = get_secure_table($store_file);
        if (isset($store[$pending['username']]['twofa_recovery_codes'])) {
            $codes = $pending['recovery_codes'];
            $idx = array_search(strtoupper($code), array_map('strtoupper', $codes), true);
            $store[$pending['username']]['twofa_recovery_codes'] = array_values(array_diff($store[$pending['username']]['twofa_recovery_codes'], [$codes[$idx]]));
            save_secure_table($store_file, $store);
        }
    }
    echo json_encode(['ok' => true, 'masterToken' => $masterToken, 'defaultDisplayName' => $pending['displayName'], 'superUsername' => $pending['username'], 'tier' => $pending['tier']]);
    exit;
}

if ($action === 'change_super_password') {
    // Works from either the pre-room master session or a room-bound
    // super session, since password changes shouldn't require being
    // inside a specific room.
    $tok = isset($body['masterToken']) ? $body['masterToken'] : (isset($body['token']) ? $body['token'] : '');
    $session = get_session($tok);
    if (!$session || !in_array($session['role'], ['super_master', 'super'], true)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please log in again.']);
        exit;
    }
    $superUsername = trim((string)($body['superUsername'] ?? ''));
    $currentPassword = (string)($body['currentPassword'] ?? '');
    $newPassword = (string)($body['newPassword'] ?? '');

    if ($superUsername === '' || $currentPassword === '' || $newPassword === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Username, current password, and new password are all required.']);
        exit;
    }
    if (mb_strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'New password must be at least 6 characters.']);
        exit;
    }

    $store = read_super_credentials();
    if (!isset($store[$superUsername])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
        exit;
    }
    $storedHash = $store[$superUsername]['passwordHash'];
    $isValid = (strpos($storedHash, '$2y$') === 0)
        ? password_verify($currentPassword, $storedHash)
        : hash_equals($storedHash, hash('sha256', $currentPassword));
    if (!$isValid) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
        exit;
    }

    // Always upgrade changed passwords to bcrypt (the Dashboard standard).
    $store[$superUsername]['passwordHash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    write_super_credentials($store);

    // Also save it back to the Dashboard if this account is a tenant/master
    // (master.json is AES-256 encrypted at rest — see dash_crypto.php).
    if (file_exists(__DIR__ . '/data_dash/master.json')) {
        $masters = get_secure_table(__DIR__ . '/data_dash/master.json');
        if (isset($masters[$superUsername])) {
            $masters[$superUsername]['password_hash'] = $store[$superUsername]['passwordHash'];
            save_secure_table(__DIR__ . '/data_dash/master.json', $masters);
        }
    }
    $tenants_file = __DIR__ . '/data_dash/tenants.json';
    if (file_exists($tenants_file)) {
        $tenants = get_secure_table($tenants_file);
        if (isset($tenants[$superUsername])) {
            $tenants[$superUsername]['password_hash'] = $store[$superUsername]['passwordHash'];
            save_secure_table($tenants_file, $tenants);
        }
    }

    echo json_encode(['ok' => true, 'message' => 'Password changed. Use it next time you log in.']);
    exit;
}

if ($action === 'super_profile') {
    // Backs the tenant-tier landing page (tenant_super.html) — returns just
    // enough account info to display (username/tier/email), nothing else.
    // Master-tier accounts have no email on file (master.json only stores
    // a username + password hash), which is intentional: that account isn't
    // meant to be recoverable via a public email-based reset flow.
    $masterToken = isset($body['masterToken']) ? $body['masterToken'] : '';
    $master = get_session($masterToken);
    if (!$master || $master['role'] !== 'super_master') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Your super login expired. Please log in again.']);
        exit;
    }
    $tier = $master['tier'] ?? 'master';
    $email = '';
    if ($tier === 'tenant') {
        require_once __DIR__ . '/dash_crypto.php';
        $tenants = get_secure_table(__DIR__ . '/data_dash/tenants.json');
        // $master['user'] is the display name, not the login username — the
        // session doesn't carry the raw username separately from super_login,
        // so recover it by matching passwordHash identity isn't reliable;
        // instead super_login already stashes it — see below.
        $username = $master['username'] ?? '';
        if ($username !== '' && isset($tenants[$username])) $email = $tenants[$username]['email'] ?? '';
    }
    echo json_encode(['ok' => true, 'username' => $master['username'] ?? '', 'displayName' => $master['user'], 'tier' => $tier, 'email' => $email]);
    exit;
}

if ($action === 'super_join') {
    if (!rate_limit_allow('super_join', $fwIp, 15, 600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts — please wait a few minutes and try again.']);
        exit;
    }
    $masterToken = isset($body['masterToken']) ? $body['masterToken'] : '';
    $room = trim((string)($body['room'] ?? ''));
    $roomPassword = trim((string)($body['roomPassword'] ?? ''));
    $displayName = clean_username($body['displayName'] ?? '');
    $asHost = !empty($body['asHost']);

    $master = get_session($masterToken);
    if (!$master || $master['role'] !== 'super_master') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Your super login expired. Please log in again.']);
        exit;
    }

    if ($room === '') $room = 'common';
    $tier = $master['tier'] ?? 'master';

    $config = read_config($room);
    $isNewRoom = false;

    if ($tier === 'master') {
        // The single default super admin keeps unrestricted access — can
        // enter or silently create any room, no password needed.
        if (!$config) {
            $config = default_config();
            $config['createdAt'] = now_ms();
            write_config($room, $config);
            $isNewRoom = true;
        }
    } else {
        // A tenant admin's Super User login is NOT the same as the master
        // account — without this check, every paying tenant would silently
        // have host-level control over every other tenant's rooms too. They
        // have to prove they actually know this specific room's
        // password/PIN, exactly like a regular member or host would.
        if (!$config) {
            if ($roomPassword === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'room_password_required', 'message' => 'Enter the room name and a password to create or join it.']);
                exit;
            }
            $config = default_config();
            $config['createdAt'] = now_ms();
            $config['pinHash'] = hash('sha256', $roomPassword);
            write_config($room, $config);
            $isNewRoom = true;
        } else {
            if (empty($config['pinHash'])) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'This room has no password set yet. Ask its host to set one first.']);
                exit;
            }
            if ($roomPassword === '' || !hash_equals($config['pinHash'], hash('sha256', $roomPassword))) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'incorrect_room_password', 'message' => 'Incorrect room name or password.']);
                exit;
            }
        }
    }

    if ($asHost) {
        $token = create_session($room, 'Host', 'host');
        echo json_encode(['ok' => true, 'token' => $token, 'role' => 'host', 'user' => 'Host', 'room' => safe_room_name($room), 'isNewRoom' => $isNewRoom]);
        exit;
    }

    $user = $displayName !== '' ? $displayName : $master['user'];
    $token = create_session($room, $user, 'super');
    echo json_encode(['ok' => true, 'token' => $token, 'role' => 'super', 'user' => $user, 'room' => safe_room_name($room), 'isNewRoom' => $isNewRoom]);
    exit;
}

if ($action === 'create_premium_room') {
    // Premium/Super room creation.
    //   - Default super admin (tier === 'master'): unlimited premium rooms.
    //   - Tenant super (any other tier): exactly ONE active premium room at
    //     a time. Creating a new one deletes their previous premium room
    //     first (not just un-marks it — fully removed, matching how the
    //     rest of this app treats room deletion).
    // Perks themselves are the same set for both tiers (see
    // premium_room_perks() in common.php) — only the room-count limit differs.
    //
    // Wrapped in try/catch so any unexpected error still returns valid
    // JSON (with the real message in the server error log) instead of a
    // blank/HTML response that breaks res.json() on the client
    // ("Bad response from server." in glass-app.js apiPost/apiGet).
    try {
    if (!rate_limit_allow('create_premium_room', $fwIp, 10, 600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts — please wait a few minutes and try again.']);
        exit;
    }
    $masterToken = isset($body['masterToken']) ? $body['masterToken'] : '';
    $master = get_session($masterToken);
    if (!$master || $master['role'] !== 'super_master') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Your super login expired. Please log in again.']);
        exit;
    }
    $tier = $master['tier'] ?? 'master';
    $ownerUsername = strtolower($master['user']);

    $room = trim((string)($body['room'] ?? ''));
    $title = trim((string)($body['title'] ?? ''));
    $roomPassword = trim((string)($body['roomPassword'] ?? ''));
    $requestedPerks = is_array($body['perks'] ?? null) ? $body['perks'] : [];

    if ($room === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A room name is required.']);
        exit;
    }
    if ($tier !== 'master' && $roomPassword === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A room password is required.']);
        exit;
    }
    if (read_config($room)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'A room with that name already exists. Choose a different name.']);
        exit;
    }

    // Enforce the tenant one-premium-room limit — find and delete their
    // existing premium room (if any) before creating the new one.
    if ($tier !== 'master') {
        foreach (list_rooms() as $r) {
            $existing = read_config($r['room']);
            if ($existing && !empty($existing['isPremium']) && ($existing['premiumOwner'] ?? '') === $ownerUsername) {
                // Premium rooms replace immediately — no undo-grace window
                // like the manual room-browser delete (which is intentionally
                // reversible for accidental clicks). This is an explicit
                // "create new" action, so the old one is superseded right away.
                delete_room($r['room']);
                break; // a tenant can only ever have one, so first match is the only match
            }
        }
    }

    $validPerkKeys = array_keys(premium_room_perks());
    $perks = array_values(array_intersect($requestedPerks, $validPerkKeys));
    if (empty($perks)) {
        // Default: turn everything on if the creator didn't pick specific perks
        $perks = $validPerkKeys;
    }

    $config = default_config();
    $config['createdAt'] = now_ms();
    $config['title'] = $title !== '' ? $title : ($tier === 'master' ? 'Super Premium Room' : 'Premium Room');
    $config['isPremium'] = true;
    $config['premiumOwner'] = $ownerUsername;
    $config['premiumTier'] = $tier === 'master' ? 'master' : 'tenant';
    $config['roomTheme'] = ['background' => '', 'glowColor' => '#ffd700', 'uiSkin' => 'glassmorphism'];
    $config['premiumPerks'] = $perks; // stored on the config so room.html/settings.html can read which perks are active
    if ($tier !== 'master') {
        $config['pinHash'] = hash('sha256', $roomPassword);
    }
    write_config($room, $config);

    $token = create_session($room, $master['user'], 'super');
    echo json_encode([
        'ok' => true,
        'token' => $token,
        'role' => 'super',
        'user' => $master['user'],
        'room' => safe_room_name($room),
        'isPremium' => true,
        'premiumTier' => $config['premiumTier'],
        'perks' => $perks,
    ]);
    exit;
    } catch (Throwable $e) {
        error_log('create_premium_room fatal: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'server_error', 'message' => 'Something went wrong creating the room. Please try again.']);
        exit;
    }
}

if ($action === 'remember_login') {
    if (!rate_limit_allow('remember_login', $fwIp, 20, 600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts — please wait a few minutes and try again.']);
        exit;
    }
    $rememberToken = isset($body['rememberToken']) ? $body['rememberToken'] : '';
    $result = consume_remember_token($rememberToken);

    if (!$result) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'remember_expired', 'message' => 'Your saved login expired. Please log in again.']);
        exit;
    }

    // Re-check ban status — a kicked member's remember token is deleted
    // at kick time (see kick.php), but this covers the same-session edge
    // case where a ban happens between requests.
    $config = read_config($result['room']);
    if ($result['role'] === 'member' && $config && is_banned($config, $result['user'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You have been removed from this chat by the host.']);
        exit;
    }

    $token = create_session($result['room'], $result['user'], $result['role']);
    echo json_encode([
        'ok' => true,
        'token' => $token,
        'role' => $result['role'],
        'user' => $result['user'],
        'room' => $result['room'],
        'rememberToken' => $result['newToken'], // rotated — client must overwrite its stored copy
    ]);
    exit;
}

if ($action === 'remember_logout') {
    $rememberToken = isset($body['rememberToken']) ? $body['rememberToken'] : '';
    revoke_remember_token($rememberToken);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
