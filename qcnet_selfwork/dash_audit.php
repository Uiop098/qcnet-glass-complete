<?php
// dash_audit.php — lightweight activity log, adapted from the Member
// Profile plan's "audit_logs" MySQL table to this project's flat-file
// storage. One capped, AES-256-encrypted JSON file per tenant
// (data_dash/activity_log.json or data_dash/<tenant>/activity_log.json),
// newest entries first, capped at MAX_ACTIVITY_ENTRIES so it can't grow
// unbounded on a long-lived tenant.

define('MAX_ACTIVITY_ENTRIES', 200);

function activity_log_file($dir) { return $dir . '/activity_log.json'; }

// $actor: username string. $action: short machine tag ('login', 'profile_update',
// 'password_change', 'email_change', '2fa_enabled', '2fa_disabled', 'logo_update',
// 'business_profile_update', 'deactivated', 'role_saved', 'role_deleted').
// $summary: human-readable one-liner shown in the UI.
function log_activity($dir, $actor, $action, $summary) {
    $file = activity_log_file($dir);
    $log = get_secure_table($file);
    if (!is_array($log)) $log = [];
    array_unshift($log, [
        'id' => uniqid('act_', true),
        'actor' => $actor,
        'action' => $action,
        'summary' => $summary,
        'ip' => get_client_ip(),
        'ts' => time(),
    ]);
    if (count($log) > MAX_ACTIVITY_ENTRIES) $log = array_slice($log, 0, MAX_ACTIVITY_ENTRIES);
    save_secure_table($file, $log);
}

// $limit caps how many entries the caller gets back; $onlyActor, if set,
// filters to one user's own actions (used by the Member Profile page so a
// regular client only sees their own activity, not the whole tenant's).
function get_activity($dir, $limit = 20, $onlyActor = null) {
    $log = get_secure_table(activity_log_file($dir));
    if (!is_array($log)) return [];
    if ($onlyActor !== null) $log = array_values(array_filter($log, function ($e) use ($onlyActor) { return ($e['actor'] ?? '') === $onlyActor; }));
    return array_slice($log, 0, $limit);
}
?>
