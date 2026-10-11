<?php
// api.php — chat backend: fetch messages, send text messages, and
// clear a room (host/super only). All requests require a valid
// session token issued by auth.php.

require_once __DIR__ . '/common.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

function require_valid_session($room, $token) {
    $session = get_session($token);
    if (!$session || $session['room'] !== safe_room_name($room)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
        exit;
    }
    return $session;
}

if ($method === 'GET') {
    $room = isset($_GET['room']) ? $_GET['room'] : 'default';
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    $since = isset($_GET['since']) ? intval($_GET['since']) : 0;

    $session = require_valid_session($room, $token);

    $config = read_config($room);
    if (!$config) $config = default_config();

    if ($session['role'] === 'member' && is_banned($config, $session['user'])) {
        remove_sessions_for_user($room, $session['user']);
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'banned', 'message' => 'You have been removed from this chat by the host.']);
        exit;
    }

    touch_presence($config, $session['user'], $session['role']);

    // Opportunistic background work — no cron on shared hosting, so this
    // piggybacks on the polling request every client already makes.
    process_due_scheduled_messages($room, $config);
    maybe_send_report_digest($config, $room);

    write_config($room, $config);

    $messages = read_messages($room);
    $allForSync = $messages; // capture before since-filtering, for reaction sync below
    if ($since > 0) {
        $messages = array_values(array_filter($messages, function ($m) use ($since) {
            return isset($m['id']) && $m['id'] > $since;
        }));
    }

    // Lightweight sync payload so reaction changes on already-rendered
    // messages reach other clients even though their message id isn't "new".
    $recentSync = array_map(function ($m) {
        return ['id' => $m['id'], 'reactions' => isset($m['reactions']) ? $m['reactions'] : []];
    }, array_slice($allForSync, -20));

    $isAdmin = in_array($session['role'], ['host', 'super'], true);
    $mutedRemaining = mute_remaining_ms($config, $session['user']);
    $pinnedMessage = !empty($config['pinnedMessageId']) ? find_message($room, $config['pinnedMessageId']) : null;

    // Auto-expire the extended-uploads toggle if its optional timer ran
    // out, and persist that so it doesn't keep showing as active.
    $extraUploadsWasEnabled = !empty($config['extraUploads']['enabled']);
    extra_uploads_is_active($config);
    if ($extraUploadsWasEnabled && empty($config['extraUploads']['enabled'])) {
        write_config($room, $config);
    }

    $response = [
        'ok' => true,
        'messages' => $messages,
        'title' => $config['title'],
        'roomTheme' => $config['roomTheme'],
        'isPremium' => !empty($config['isPremium']),
        'premiumPerks' => !empty($config['isPremium']) ? ($config['premiumPerks'] ?? []) : [],
        'typingUsers' => typing_users($config, $session['user']),
        'pinnedMessage' => $pinnedMessage,
        'recentSync' => $recentSync,
        'presence' => $isAdmin ? active_presence($config) : null,
        'bannedUsers' => $isAdmin ? $config['bannedUsers'] : null,
        'bannedIps' => $isAdmin ? $config['bannedIps'] : null,
        'mutedUsers' => $isAdmin ? $config['mutedUsers'] : null,
        'requireFileApproval' => !empty($config['requireFileApproval']),
        'fileRequests' => $isAdmin ? $config['fileRequests'] : null,
        // Sent to everyone (not just host/super) — a member's client
        // needs to know which extra extensions are currently allowed so
        // its file picker/error messages line up with what upload.php
        // will actually accept. See EXTENDED FILE UPLOADS in README.
        'extraUploads' => $config['extraUploads'],
        'readReceipts' => read_receipts($config, $session['user']),
        'slowModeSeconds' => (int)$config['slowModeSeconds'],
        'slowModeRemaining' => slow_mode_remaining_seconds($config, $session['user']),
        'coHosts' => $config['coHosts'],
        'isCoHost' => is_co_host($config, $session['user']),
        'coSupers' => $config['coSupers'],
        'hasLimitedThemeAccess' => has_limited_theme_access($config, $session),
        'reports' => $isAdmin ? $config['reports'] : null,
        'openReportCount' => $isAdmin ? open_report_count($config) : null,
        'scheduledMessages' => $isAdmin ? $config['scheduledMessages'] : null,
        'polls' => array_values($config['polls']),
        'bannedWords' => $isAdmin ? $config['bannedWords'] : null,
        'hostNotifyEmail' => $isAdmin ? $config['hostNotifyEmail'] : null,
        'autoCleanupDays' => $isAdmin ? $config['autoCleanupDays'] : null,
        'encEnabled' => !empty($config['encEnabled']),
        'encSalt' => $config['encSalt'],
        // Automatic bypass: a verified super session gets the room's actual
        // passphrase recovered server-side (if one was stored for recovery
        // when encryption was enabled) — no manual key entry needed. This
        // check is done here, server-side, based on the session's role,
        // which is the "automatic backend verification" for super access.
        'superRecoveredKey' => ($session['role'] === 'super' && !empty($config['encSuperRecovery']))
            ? super_decrypt_passphrase($config['encSuperRecovery'])
            : null,
        'you' => [
            'user' => $session['user'],
            'role' => $session['role'],
            'mutedRemainingMs' => $mutedRemaining,
        ],
    ];

    echo json_encode($response);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $room = isset($body['room']) ? $body['room'] : '';
    $token = isset($body['token']) ? $body['token'] : '';
    $text = isset($body['text']) ? trim($body['text']) : '';
    $replyToId = isset($body['replyTo']) ? intval($body['replyTo']) : 0;

    $session = require_valid_session($room, $token);

    if (!rate_limit_allow('send_message', get_client_ip(), 20, 10)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'You are sending messages too fast — please slow down.']);
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

    // Slow mode — members and hosts respect it; co-hosts/host/super do not
    // (same exemption pattern as mute, above).
    if (!is_effective_host($config, $session) && $session['role'] !== 'super') {
        $slowRemaining = slow_mode_remaining_seconds($config, $session['user']);
        if ($slowRemaining > 0) {
            http_response_code(429);
            echo json_encode([
                'ok' => false,
                'error' => 'slow_mode',
                'message' => 'Slow mode is on — please wait ' . $slowRemaining . 's before sending another message.',
                'slowModeRemaining' => $slowRemaining,
            ]);
            exit;
        }
    }

    $text = trim($text);
    if ($text === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Empty message']);
        exit;
    }

    // Word/link filter — checked before the encrypted-message branch since
    // encrypted envelopes (encv1:...) are opaque ciphertext to the server
    // and can't be scanned; the filter only ever applies to plain text.
    if (strpos($text, 'encv1:') !== 0) {
        $badWord = find_banned_word($config, $text);
        if ($badWord !== null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'blocked_word', 'message' => 'Your message contains a word or link that isn\'t allowed in this room.']);
            exit;
        }
    }
    if (strpos($text, 'encv1:') === 0) {
        if (strlen($text) > 60000) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Encrypted message too large.']);
            exit;
        }
    } elseif (mb_strlen($text) > 20000) {
        $text = mb_substr($text, 0, 20000);
    }

    $replySnippet = $replyToId > 0 ? build_reply_snippet(find_message($room, $replyToId)) : null;

    $newMsg = append_message($room, [
        'type' => 'text',
        'user' => $session['user'],
        'role' => $session['role'],
        'text' => $text,
        'replyTo' => $replySnippet,
        'reactions' => [],
    ]);

    // Auto-kick on spam pattern (PART 3) — 3+ identical messages in a row
    // from a member auto-mutes them briefly, same mechanism a host would
    // use manually. Exempt hosts/co-hosts/super.
    $isRepeatSpam = record_message_for_spam_tracking($config, $session['user'], $text);
    if ($isRepeatSpam && !is_effective_host($config, $session) && $session['role'] !== 'super') {
        mute_user($config, $session['user'], 2); // 2-minute cooldown
    }
    write_config($room, $config);

    echo json_encode(['ok' => true, 'message' => $newMsg]);
    exit;
}

if ($method === 'DELETE') {
    $room = isset($_GET['room']) ? $_GET['room'] : '';
    $token = isset($_GET['token']) ? $_GET['token'] : '';

    $session = require_valid_session($room, $token);
    $config = read_config($room);
    if (!$config) $config = default_config();

    if (!is_effective_host($config, $session)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can clear this chat.']);
        exit;
    }

    write_messages($room, []);
    $dir = room_files_dir($room);
    foreach (glob($dir . '/*') as $f) {
        if (is_file($f)) @unlink($f);
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
