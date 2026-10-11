<?php
// dashboard.php — v35, PART 2: "Simple dashboard" (analytics, admin-
// facing). Super-only endpoint aggregating stats across every room:
// total rooms, currently-active users, messages today, etc.

require_once __DIR__ . '/common.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$masterToken = isset($_GET['masterToken']) ? $_GET['masterToken'] : '';
$master = get_session($masterToken);
// site_dashboard_stats() aggregates across every room on the server —
// master tier only, same reasoning as backup.php. This used to also
// accept any already-joined 'super' role session (any tier), which let a
// tenant admin see aggregate stats for every OTHER tenant's rooms too.
if (!$master || $master['role'] !== 'super_master' || ($master['tier'] ?? 'master') !== 'master') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Only the default super admin can view this dashboard.']);
    exit;
}

sweep_pending_deletes();
echo json_encode(['ok' => true, 'stats' => site_dashboard_stats()]);
