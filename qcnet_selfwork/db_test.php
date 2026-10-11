<?php
// =============================================================================
// db_test.php — Stage 1 connection test
// =============================================================================
// Run this once after uploading db.php + db_config.php + running
// sql/schema.sql, to confirm the database is reachable and the tables
// exist, BEFORE any real app code depends on it (Stage 2).
//
// Visit: https://yourdomain.com/db_test.php
//
// DELETE THIS FILE once you've confirmed it works — it doesn't leak
// credentials, but there's no reason to leave a diagnostic endpoint
// reachable in production longer than needed.
// =============================================================================

header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$result = ['ok' => false, 'checks' => []];

// 1. Can we connect at all?
try {
    $pdo = db();
    $result['checks']['connection'] = 'ok';
} catch (Throwable $e) {
    $result['checks']['connection'] = 'FAILED: ' . $e->getMessage();
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// 2. Do the expected tables exist? (schema.sql must have been run already)
$expectedTables = ['rooms', 'messages', 'sessions', 'presence', 'dash_tenants', 'dash_users', 'file_links', 'file_blobs'];
$foundTables = [];
try {
    $rows = db_all('SHOW TABLES');
    foreach ($rows as $row) {
        $foundTables[] = array_values($row)[0];
    }
} catch (Throwable $e) {
    $result['checks']['show_tables'] = 'FAILED: ' . $e->getMessage();
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

$missing = array_diff($expectedTables, $foundTables);
if (empty($missing)) {
    $result['checks']['tables'] = 'ok — all ' . count($expectedTables) . ' expected tables found';
} else {
    $result['checks']['tables'] = 'MISSING: ' . implode(', ', $missing) . ' — did you run sql/schema.sql against this database?';
}

// 3. Can we actually write and read back a row? (round-trip test, cleaned
//    up immediately after — doesn't leave test data behind)
try {
    $testRoom = '__db_test_' . bin2hex(random_bytes(4));
    $id = db_insert(
        'INSERT INTO rooms (room, title, created_at, extra_json) VALUES (?, ?, ?, ?)',
        [$testRoom, 'DB Test Room', now_ms(), json_encode(['test' => true])]
    );
    $readBack = db_one('SELECT * FROM rooms WHERE id = ?', [$id]);
    db_exec('DELETE FROM rooms WHERE id = ?', [$id]); // clean up immediately

    if ($readBack && $readBack['room'] === $testRoom) {
        $result['checks']['write_read_roundtrip'] = 'ok';
    } else {
        $result['checks']['write_read_roundtrip'] = 'FAILED: wrote a row but could not read it back correctly';
    }
} catch (Throwable $e) {
    $result['checks']['write_read_roundtrip'] = 'FAILED: ' . $e->getMessage();
}

// 4. Is FILE_LINK_SECRET set to something other than the placeholder?
if (defined('FILE_LINK_SECRET') && FILE_LINK_SECRET !== 'CHANGE_ME_TO_A_RANDOM_64_CHAR_HEX_STRING_SHARED_ACROSS_ALL_DOMAINS') {
    $result['checks']['file_link_secret'] = 'ok — has been changed from the placeholder';
} else {
    $result['checks']['file_link_secret'] = 'WARNING: still the placeholder value — generate a real one before using cross-domain file links (Stage 3)';
}

$result['ok'] = empty($missing)
    && ($result['checks']['connection'] === 'ok')
    && ($result['checks']['write_read_roundtrip'] === 'ok');

echo json_encode($result, JSON_PRETTY_PRINT);
