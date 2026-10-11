<?php
// report.php — lets any member flag a message for host/super review
// (v35, PART 2: "Report message"). Reports are surfaced in the admin
// Manage panel so a host doesn't have to be watching live to catch bad
// behavior. action=resolve marks a report handled (host/super only).

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
$action = isset($body['action']) ? $body['action'] : 'report';

$session = get_session($token);
if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Session expired or invalid. Please rejoin.']);
    exit;
}

$room = $session['room'];
$config = read_config($room);
if (!$config) $config = default_config();

if ($action === 'resolve') {
    if (!is_effective_host($config, $session)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the host or a super user can resolve reports.']);
        exit;
    }
    $reportId = isset($body['reportId']) ? (string)$body['reportId'] : '';
    if (!resolve_report($config, $reportId)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Report not found.']);
        exit;
    }
    write_config($room, $config);
    echo json_encode(['ok' => true]);
    exit;
}

// action=report (default)
if (!rate_limit_allow('report', get_client_ip(), 10, 300)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Too many reports — please slow down.']);
    exit;
}

$messageId = isset($body['messageId']) ? intval($body['messageId']) : 0;
$reason = isset($body['reason']) ? (string)$body['reason'] : '';

if ($messageId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No message specified.']);
    exit;
}

$msg = find_message($room, $messageId);
if (!$msg) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Message not found (it may have expired).']);
    exit;
}

$report = add_report($config, $messageId, $msg['user'] ?? 'unknown', $session['user'], $reason);
write_config($room, $config);

echo json_encode(['ok' => true, 'report' => $report]);
