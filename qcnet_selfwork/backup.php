<?php
// backup.php — v35, PART 3: "Auto-backup". Super-only, on-demand (no
// cron needed on shared hosting): zips every room's config + message
// history JSON in data/ into one downloadable archive. Deliberately
// excludes files/ (uploaded attachments) — those can be large, and are
// already the least critical thing to protect against loss compared to
// the actual chat data/settings; download individual files as needed.

require_once __DIR__ . '/common.php';

$masterToken = isset($_GET['masterToken']) ? $_GET['masterToken'] : '';
$session = get_session($masterToken);
// Restricted to the still-active master super-login session itself (not a
// room-joined 'super' session, which any tenant admin can legitimately hold
// after verifying just one room's password) AND to master tier specifically.
// This used to accept any 'super' or 'super_master' role regardless of
// tier — meaning a tenant admin who'd verified their way into just one of
// their own rooms could download a full backup of every room on the
// server, across every other tenant too.
$isSuper = $session && $session['role'] === 'super_master' && ($session['tier'] ?? 'master') === 'master';

if (!$isSuper) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'invalid_session', 'message' => 'Only the default super admin can download a backup.']);
    exit;
}

if (!rate_limit_allow('backup', get_client_ip(), 5, 300)) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'rate_limited', 'message' => 'Please wait before requesting another backup.']);
    exit;
}

$zipPath = build_data_backup_zip();
if (!$zipPath) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Backup could not be created (the ZipArchive PHP extension may not be available on this host).']);
    exit;
}

$stamp = date('Ymd_His');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="quickchat_backup_' . $stamp . '.zip"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);
@unlink($zipPath);
