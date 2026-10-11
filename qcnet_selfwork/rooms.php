<?php
// rooms.php — GET lists every room that exists (running or past) for
// the super-user room browser. POST performs management actions on a
// room directly from that browser (no need to join it first):
// delete_room, set_host_password, set_pin. All require a valid super
// "master" token (issued by auth.php action=super_login).

require_once __DIR__ . '/common.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $masterToken = isset($body['masterToken']) ? $body['masterToken'] : '';
    $master = get_session($masterToken);
    if (!$master || $master['role'] !== 'super_master') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Your super login expired. Please log in again.']);
        exit;
    }
    // This global room browser (list every room, delete any room, reset any
    // room's PIN/host password without ever knowing them) is for the single
    // default super admin only. A tenant admin's Super User login used to
    // reach every one of these actions the same way the master account does
    // — meaning any paying tenant could silently delete or take over any
    // OTHER tenant's rooms. Tenant-tier accounts manage their own rooms by
    // joining them (auth.php action=super_join, which already requires that
    // room's actual password) and using in-room host controls instead.
    if (($master['tier'] ?? 'master') !== 'master') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'This room browser is only available to the default super admin. Join a specific room with its password instead.']);
        exit;
    }

    $room = trim((string)($body['room'] ?? ''));
    $action = isset($body['action']) ? $body['action'] : '';
    if ($room === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No room specified.']);
        exit;
    }

    if ($action === 'delete_room') {
        // v35: grace-period delete instead of instant (PART 3 "Undo delete").
        // The room is marked pending and actually purged ~10s later by
        // sweep_pending_deletes() (called below on every room list load).
        if (!mark_room_pending_delete($room)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Room not found.']);
            exit;
        }
        echo json_encode(['ok' => true, 'pendingDelete' => safe_room_name($room), 'graceMs' => $GLOBALS['undoDeleteGraceMs']]);
        exit;
    }

    if ($action === 'undo_delete_room') {
        if (!undo_room_delete($room)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Nothing to undo (the room may already be gone, or was never pending deletion).']);
            exit;
        }
        echo json_encode(['ok' => true, 'restored' => safe_room_name($room)]);
        exit;
    }

    $config = read_config($room);
    if (!$config) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Room not found.']);
        exit;
    }

    if ($action === 'set_host_password') {
        $newPassword = trim((string)($body['newPassword'] ?? ''));
        if ($newPassword === '' || strlen($newPassword) < 4) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'New password must be at least 4 characters.']);
            exit;
        }
        $config['hostPasswordHash'] = hash('sha256', $newPassword);
        write_config($room, $config);
        remove_host_sessions($room);
        remove_host_remember_tokens($room);
        echo json_encode(['ok' => true, 'message' => 'Host password updated. Existing host sessions were logged out.']);
        exit;
    }

    if ($action === 'set_pin') {
        $newPin = trim((string)($body['newPin'] ?? ''));
        if ($newPin === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'New PIN cannot be empty.']);
            exit;
        }
        $config['pinHash'] = hash('sha256', $newPin);
        write_config($room, $config);
        echo json_encode(['ok' => true, 'message' => 'Room PIN updated.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

$masterToken = isset($_GET['masterToken']) ? $_GET['masterToken'] : '';
$master = get_session($masterToken);

if (!$master || $master['role'] !== 'super_master') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Your super login expired. Please log in again.']);
    exit;
}
if (($master['tier'] ?? 'master') !== 'master') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'The room list is only available to the default super admin.']);
    exit;
}

sweep_pending_deletes();
echo json_encode(['ok' => true, 'rooms' => list_rooms()]);
