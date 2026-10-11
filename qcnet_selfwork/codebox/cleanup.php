<?php
/**
 * cleanup.php — sweeps data/projects/* for old, abandoned projects.
 * Meant to be hit periodically via a free hosting provider's webcron
 * feature, or opened manually in a browser now and then. Safe to call
 * as often as you like — it only ever deletes projects untouched for
 * CB_PROJECT_RETENTION_DAYS (see common.php).
 */

require __DIR__ . '/common.php';
header('Content-Type: text/plain; charset=utf-8');

if (!is_dir(CB_DATA_DIR)) {
    echo "Nothing to clean — no projects yet.\n";
    exit;
}

$checked = 0;
$deleted = 0;
$now = time();
$maxAge = CB_PROJECT_RETENTION_DAYS * 86400;

foreach (scandir(CB_DATA_DIR) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $dir = CB_DATA_DIR . '/' . $entry;
    if (!is_dir($dir)) continue;
    $checked++;

    $meta = cb_read_json($dir . '/meta.json', null);
    $lastActive = $meta['last_active'] ?? $meta['created_at'] ?? filemtime($dir);
    $age = $now - (int)$lastActive;

    if ($age > $maxAge) {
        cb_rrmdir($dir);
        $deleted++;
        echo "Deleted (inactive " . round($age / 86400) . "d): $entry\n";
    }
}

echo "\nDone. Checked $checked project(s), deleted $deleted.\n";
