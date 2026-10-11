<?php
// export.php — lets the host or a super user download a room's chat
// history as .txt or .json. Encrypted messages export as their raw
// ciphertext envelope (encv1:...) — the server can't decrypt them
// without the key, same as anyone else without it.

require_once __DIR__ . '/common.php';

$token = isset($_GET['token']) ? $_GET['token'] : '';
$format = isset($_GET['format']) ? $_GET['format'] : 'txt';
if (!in_array($format, ['txt', 'json', 'csv'], true)) $format = 'txt';

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}
$room = $session['room'];
$config = read_config($room);
if (!in_array($session['role'], ['host', 'super'], true) && !($config && is_co_host($config, $session['user']))) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can export chat history.']);
    exit;
}

$messages = read_messages($room);
$roomLabel = $config && $config['title'] !== '' ? $config['title'] : $room;
$stamp = date('Ymd_His');

if ($format === 'csv') {
    // v35, PART 2 "Export room analytics as CSV" — a summary block
    // followed by the raw message rows, all in one CSV file.
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="quickchat_' . safe_room_name($room) . '_' . $stamp . '.csv"');
    $out = fopen('php://output', 'w');

    $activeUsers = $config ? active_presence($config) : [];
    fputcsv($out, ['Room', $roomLabel]);
    fputcsv($out, ['Exported At', date('Y-m-d H:i:s')]);
    fputcsv($out, ['Message Count', count($messages)]);
    fputcsv($out, ['Active Users (now)', count($activeUsers)]);
    fputcsv($out, ['Banned Users', $config ? count($config['bannedUsers']) : 0]);
    fputcsv($out, ['Banned IPs', $config ? count($config['bannedIps']) : 0]);
    fputcsv($out, []);
    fputcsv($out, ['Timestamp', 'User', 'Role', 'Type', 'Text/File', 'Edited']);
    foreach ($messages as $m) {
        $time = isset($m['ts']) ? date('Y-m-d H:i:s', intval($m['ts'] / 1000)) : '?';
        $content = (isset($m['type']) && $m['type'] === 'file')
            ? ($m['fileName'] ?? 'file')
            : ($m['text'] ?? '');
        fputcsv($out, [$time, $m['user'] ?? 'unknown', $m['role'] ?? '', $m['type'] ?? 'text', $content, !empty($m['edited']) ? 'yes' : 'no']);
    }
    fclose($out);
    exit;
}

if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="quickchat_' . safe_room_name($room) . '_' . $stamp . '.json"');
    echo json_encode(['room' => $room, 'title' => $roomLabel, 'exportedAt' => now_ms(), 'messages' => $messages], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="quickchat_' . safe_room_name($room) . '_' . $stamp . '.txt"');
echo "Chat export — {$roomLabel}\n";
echo "Exported: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 40) . "\n\n";
foreach ($messages as $m) {
    $time = isset($m['ts']) ? date('Y-m-d H:i:s', intval($m['ts'] / 1000)) : '?';
    $user = $m['user'] ?? 'unknown';
    if (isset($m['type']) && $m['type'] === 'file') {
        $line = '📎 ' . ($m['fileName'] ?? 'file') . (isset($m['text']) && $m['text'] !== '' ? ' — ' . $m['text'] : '');
    } else {
        $line = $m['text'] ?? '';
    }
    if (!empty($m['edited'])) $line .= ' (edited)';
    echo "[{$time}] {$user}: {$line}\n";
}
